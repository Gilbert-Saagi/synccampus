<?php
declare(strict_types=1);

namespace SyncCampus\Service;

use SyncCampus\Auth;
use SyncCampus\Codes;
use SyncCampus\Db;
use SyncCampus\Engine\Analytics;
use SyncCampus\Engine\Escalation;
use SyncCampus\Engine\Priority;
use SyncCampus\Engine\Rules;
use SyncCampus\Engine\Similarity;
use SyncCampus\HttpError;
use SyncCampus\Time;

/**
 * The concern workflow: submission -> related-issue detection -> priority
 * score -> assignment -> escalation -> handover.
 */
final class Concerns
{
    private const SELECT = 'SELECT c.*, k.name AS category, k.base_severity, k.is_sensitive,
                                   (SELECT MIN(a.assigned_at) FROM assignments a WHERE a.concern_id = c.id) AS first_assigned_at
                              FROM concerns c
                              JOIN categories k ON k.id = c.category_id';

    private const OPEN_SQL = "c.status IN ('new', 'assigned', 'in_progress')";

    // How often the escalation check may run as a side effect of page loads.
    private const AUTO_CHECK_SECONDS = 300;

    public static function find(int $id): ?array
    {
        return Db::one(self::SELECT . ' WHERE c.id = ?', [$id]);
    }

    public static function findByReference(string $reference): ?array
    {
        return Db::one(self::SELECT . ' WHERE c.reference = ?', [strtoupper(trim($reference))]);
    }

    public static function where(string $condition = '1 = 1', array $params = [], string $order = 'c.priority_score DESC, c.id'): array
    {
        return Db::all(self::SELECT . " WHERE $condition ORDER BY $order", $params);
    }

    public static function open(): array
    {
        return self::where(self::OPEN_SQL);
    }

    /**
     * Saves a new concern, links it to similar recent reports in the same
     * category, and scores every concern in the affected group.
     *
     * @return array{0: array, 1: string, 2: array} [concern, access code, matches]
     */
    public static function create(array $data): array
    {
        $category = Db::one('SELECT * FROM categories WHERE id = ?', [$data['category_id']]);
        if ($category === null) {
            throw new HttpError(400, 'Unknown category');
        }
        $code = Codes::accessCode();

        return Db::transaction(static function () use ($data, $category, $code) {
            $id = Db::insert('concerns', [
                'reference' => Codes::reference('SC', 'concerns'),
                'access_code_hash' => Codes::hashAccessCode($code),
                'title' => $data['title'],
                'description' => $data['description'],
                'category_id' => (int) $category['id'],
                'campus' => $data['campus'],
                'location' => $data['location'],
                'urgency' => $data['urgency'],
                'safety_flag' => $data['safety_flag'],
                'academic_impact' => $data['academic_impact'],
                'financial_impact' => $data['financial_impact'],
                'students_affected' => $data['students_affected'],
                // Sensitive categories (e.g. harassment) are always private.
                'is_public' => $data['is_public'] && !$category['is_sensitive'],
                'status' => 'new',
                'is_overdue' => false,
                'priority_score' => 0,
                'priority_level' => 'Low',
                'group_id' => null,
                'created_at' => Time::now(),
                'first_response_at' => null,
                'resolved_at' => null,
                'reopened_count' => 0,
                'satisfaction_rating' => null,
            ]);

            $matches = self::linkRelated($id, $data, (int) $category['id']);
            $concern = self::find($id);
            if (!$matches) {
                self::rescore($concern);
                $concern = self::find($id);
            }
            return [$concern, $code, $matches];
        });
    }

    private static function linkRelated(int $id, array $data, int $categoryId): array
    {
        $others = Db::all(
            "SELECT id, title, description, location, group_id FROM concerns
              WHERE category_id = ? AND id <> ? AND created_at >= ? AND status <> 'closed'",
            [$categoryId, $id, Time::now(-Rules::RELATED_WINDOW_DAYS * 86400)]
        );
        if (!$others) {
            return [];
        }

        $texts = $locations = $byId = [];
        foreach ($others as $o) {
            $texts[(int) $o['id']] = "{$o['title']}. {$o['description']}";
            $locations[(int) $o['id']] = $o['location'];
            $byId[(int) $o['id']] = $o;
        }
        $matches = Similarity::findRelated("{$data['title']}. {$data['description']}", $texts, $data['location'], $locations);
        if (!$matches) {
            return [];
        }

        $best = $byId[$matches[0]['id']];
        if ($best['group_id']) {
            $groupId = (int) $best['group_id'];
            Db::run('UPDATE related_groups SET reports_count = reports_count + 1 WHERE id = ?', [$groupId]);
        } else {
            $groupId = Db::insert('related_groups', [
                'master_concern_id' => (int) $best['id'],
                'reports_count' => 2,
                'created_at' => Time::now(),
            ]);
            Db::run('UPDATE concerns SET group_id = ? WHERE id = ?', [$groupId, $best['id']]);
        }
        Db::run('UPDATE concerns SET group_id = ? WHERE id = ?', [$groupId, $id]);

        // Repeated reports raise the priority of every concern in the group.
        $groups = self::groupSizes();
        $members = self::where('c.group_id = ?', [$groupId]);
        foreach ($members as $member) {
            self::rescore($member, $groups);
        }
        self::alertHolders($members, $id);
        return $matches;
    }

    /** Tells whoever already holds a concern in the group that another student reported the same problem. */
    private static function alertHolders(array $members, int $newId): void
    {
        $new = null;
        $total = 0;
        foreach ($members as $m) {
            $total += (int) $m['students_affected'];
            if ((int) $m['id'] === $newId) {
                $new = $m;
            }
        }
        $assignees = self::activeAssignees();
        foreach ($members as $m) {
            if ((int) $m['id'] === $newId || !isset($assignees[$m['id']])) {
                continue;
            }
            self::systemNote((int) $m['id'], 'internal', sprintf(
                'A related report was linked to this concern: %s "%s". Students affected across all linked reports: about %d.',
                $new['reference'], $new['title'], $total
            ));
        }
    }

    /** An automatic update, written as "SyncCampus" so it does not count as a staff response. */
    public static function systemNote(int $concernId, string $visibility, string $message): void
    {
        Db::insert('concern_updates', [
            'concern_id' => $concernId,
            'author_user_id' => null,
            'author_label' => 'SyncCampus',
            'visibility' => $visibility,
            'message' => $message,
            'created_at' => Time::now(),
        ]);
    }

    /** @return array<int, int> group id => students affected across every report in it */
    public static function groupStudents(): array
    {
        $totals = [];
        foreach (Db::all('SELECT group_id, SUM(students_affected) AS n FROM concerns WHERE group_id IS NOT NULL GROUP BY group_id') as $g) {
            $totals[(int) $g['group_id']] = (int) $g['n'];
        }
        return $totals;
    }

    /** @return array<int, int> group id => number of reports in it */
    public static function groupSizes(): array
    {
        $sizes = [];
        foreach (Db::all('SELECT id, reports_count FROM related_groups') as $g) {
            $sizes[(int) $g['id']] = (int) $g['reports_count'];
        }
        return $sizes;
    }

    public static function relatedCount(array $concern, ?array $groups = null): int
    {
        if (!$concern['group_id']) {
            return 0;
        }
        $groups ??= self::groupSizes();
        return max(($groups[(int) $concern['group_id']] ?? 1) - 1, 0);
    }

    public static function explain(array $concern, ?array $groups = null): array
    {
        return Priority::compute(
            (float) $concern['base_severity'],
            (int) $concern['urgency'],
            (int) $concern['students_affected'],
            Time::hoursSince($concern['created_at']),
            self::relatedCount($concern, $groups),
            (bool) $concern['safety_flag'],
            (bool) $concern['academic_impact'],
            (bool) $concern['financial_impact'],
        );
    }

    /** Recalculates and stores the score (it grows the longer a concern stays open). */
    public static function rescore(array $concern, ?array $groups = null): array
    {
        $result = self::explain($concern, $groups);
        Db::run(
            'UPDATE concerns SET priority_score = ?, priority_level = ? WHERE id = ?',
            [$result['score'], $result['level'], $concern['id']]
        );
        return $result;
    }

    /** @return array<int, string> concern id => name of the delegate currently holding it */
    public static function activeAssignees(): array
    {
        $rows = Db::all(
            "SELECT a.concern_id, u.full_name
               FROM assignments a
               JOIN delegates d ON d.id = a.delegate_id
               JOIN users u ON u.id = d.user_id
              WHERE a.status = 'active'"
        );
        return array_column($rows, 'full_name', 'concern_id');
    }

    public static function assign(array $concern, int $delegateId, array $by): array
    {
        $delegate = Db::one(
            "SELECT d.id FROM delegates d JOIN users u ON u.id = d.user_id
              WHERE d.id = ? AND u.role = 'delegate' AND u.is_active = TRUE",
            [$delegateId]
        );
        if ($delegate === null) {
            throw new HttpError(404, 'Concern or delegate not found');
        }

        $ranked = (new DelegatePool())->recommend($concern);
        $top = $ranked[0]['delegate_id'] ?? null;
        $score = 0.0;
        foreach ($ranked as $r) {
            if ($r['delegate_id'] === $delegateId) {
                $score = $r['score'];
            }
        }
        $overridden = $top !== null && $top !== $delegateId;

        $assignmentId = Db::transaction(static function () use ($concern, $delegateId, $by, $score, $overridden) {
            Db::run("UPDATE assignments SET status = 'reassigned' WHERE concern_id = ? AND status = 'active'", [$concern['id']]);
            $id = Db::insert('assignments', [
                'concern_id' => (int) $concern['id'],
                'delegate_id' => $delegateId,
                'recommended_score' => $score,
                'was_overridden' => $overridden,
                'assigned_by' => $by['id'],
                'assigned_at' => Time::now(),
                'status' => 'active',
                'completed_at' => null,
            ]);
            if ($concern['status'] === 'new') {
                Db::run("UPDATE concerns SET status = 'assigned' WHERE id = ?", [$concern['id']]);
            }
            Auth::audit($by['id'], 'assign', 'concern', (int) $concern['id']);
            return $id;
        });

        return ['assignment_id' => $assignmentId, 'was_overridden' => $overridden, 'recommended_score' => $score];
    }

    /**
     * Rescores every open concern and records a reminder or escalation when it
     * has been open longer than its priority level allows.
     */
    public static function runEscalationChecks(): array
    {
        $existing = [];
        foreach (Db::all('SELECT concern_id, type FROM escalation_events') as $e) {
            $existing[(int) $e['concern_id']][$e['type']] = true;
        }
        $groups = self::groupSizes();

        $fired = [];
        foreach (self::open() as $concern) {
            $id = (int) $concern['id'];
            $level = self::rescore($concern, $groups)['level'];
            $hours = Time::hoursSince($concern['created_at']);
            $action = Escalation::evaluate($level, $hours, isset($existing[$id]['reminder']), isset($existing[$id]['escalation']));
            if ($action === null) {
                continue;
            }
            $reason = sprintf('%s concern open %.1fh', $level, $hours);
            Db::insert('escalation_events', [
                'concern_id' => $id,
                'type' => $action,
                'reason' => $reason,
                'triggered_at' => Time::now(),
                'acknowledged_at' => null,
            ]);
            if ($action === 'escalation') {
                Db::run('UPDATE concerns SET is_overdue = TRUE WHERE id = ?', [$id]);
                // Q24: the student is told about the delay, not left guessing.
                self::systemNote($id, 'private', 'This concern is taking longer than expected. It has been marked overdue and escalated to the Guild President.');
            }
            $fired[] = ['concern' => $concern['reference'], 'type' => $action, 'reason' => $reason];
        }
        return $fired;
    }

    /**
     * PHP has no background worker, so the check also runs when dashboards
     * load, at most every few minutes. bin/escalate.php can be put on a cron
     * job for hosts that support one.
     */
    public static function runEscalationChecksIfDue(): void
    {
        $stamp = sys_get_temp_dir() . '/synccampus-escalation-' . md5(__DIR__) . '.stamp';
        if (is_file($stamp) && filemtime($stamp) > time() - self::AUTO_CHECK_SECONDS) {
            return;
        }
        touch($stamp);
        self::runEscalationChecks();
    }

    /** Snapshot of unfinished work for an incoming leadership team. */
    public static function handover(string $term, array $by): array
    {
        $assignees = self::activeAssignees();
        $lastNotes = [];
        $latest = Db::all('SELECT concern_id, message FROM concern_updates
                            WHERE id IN (SELECT MAX(id) FROM concern_updates GROUP BY concern_id)');
        foreach ($latest as $u) {
            $lastNotes[(int) $u['concern_id']] = $u['message'];
        }

        $concerns = array_map(static fn ($c) => [
            'reference' => $c['reference'],
            'title' => $c['title'],
            'category' => $c['category'],
            'priority' => $c['priority_level'],
            'status' => $c['status'],
            'overdue' => (bool) $c['is_overdue'],
            'assigned_to' => $assignees[$c['id']] ?? null,
            'created_at' => Time::iso($c['created_at']),
            'last_note' => $lastNotes[(int) $c['id']] ?? null,
        ], self::open());

        $projects = [];
        $items = Db::all("SELECT * FROM workspace_items WHERE kind = 'project' AND status <> 'completed' ORDER BY id");
        foreach ($items as $p) {
            $tasks = Db::all(
                "SELECT t.title, t.status, t.due_at, u.full_name AS assigned_to
                   FROM project_tasks t LEFT JOIN users u ON u.id = t.assigned_to_user_id
                  WHERE t.project_id = ? AND t.status <> 'completed' ORDER BY t.id",
                [$p['id']]
            );
            $projects[] = [
                'title' => $p['title'],
                'status' => $p['status'],
                'due_at' => Time::iso($p['due_at']),
                'tasks' => array_map(static fn ($t) => [
                    'title' => $t['title'],
                    'status' => $t['status'],
                    'assigned_to' => $t['assigned_to'],
                    'due_at' => Time::iso($t['due_at']),
                ], $tasks),
            ];
        }

        // Resolved history, so the new team can see what was already dealt with.
        $resolved = self::where("c.status IN ('resolved', 'closed')", [], 'c.resolved_at DESC, c.id DESC');
        $resolvedByCategory = [];
        foreach ($resolved as $c) {
            $resolvedByCategory[$c['category']] = ($resolvedByCategory[$c['category']] ?? 0) + 1;
        }
        arsort($resolvedByCategory);

        $roles = Db::all(
            "SELECT u.full_name AS name, u.role, d.portfolio, d.categories_handled, d.campus
               FROM delegates d JOIN users u ON u.id = d.user_id
              WHERE u.is_active = TRUE ORDER BY u.role, u.full_name"
        );

        $upcoming = Db::all(
            "SELECT title, kind, starts_at, location FROM workspace_items
              WHERE kind IN ('event', 'announcement') AND starts_at >= ? ORDER BY starts_at LIMIT 20",
            [Time::now()]
        );

        return [
            'term' => $term,
            'generated_by' => $by['full_name'],
            'generated_at' => Time::iso(Time::now()),
            'open_concerns' => $concerns,
            'count' => count($concerns),
            'active_projects' => $projects,
            'open_project_tasks' => array_sum(array_map(static fn ($p) => count($p['tasks']), $projects)),
            'resolved_history' => [
                'total' => count($resolved),
                'by_category' => (object) $resolvedByCategory,
                'recent' => array_map(static fn ($c) => [
                    'reference' => $c['reference'],
                    'title' => $c['title'],
                    'category' => $c['category'],
                    'resolved_at' => Time::iso($c['resolved_at']),
                ], array_slice($resolved, 0, 20)),
            ],
            'delegate_roles' => $roles,
            'performance' => Performance::all(),
            'upcoming_meetings' => array_map(static fn ($e) => [
                'title' => $e['title'],
                'kind' => $e['kind'],
                'starts_at' => Time::iso($e['starts_at']),
                'location' => $e['location'],
            ], $upcoming),
        ];
    }

    public static function isOpen(array $concern): bool
    {
        return in_array($concern['status'], Analytics::OPEN_STATUSES, true);
    }
}
