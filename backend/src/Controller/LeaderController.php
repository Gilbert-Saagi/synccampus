<?php
declare(strict_types=1);

namespace SyncCampus\Controller;

use SyncCampus\Auth;
use SyncCampus\Db;
use SyncCampus\Engine\Analytics;
use SyncCampus\HttpError;
use SyncCampus\Request;
use SyncCampus\Service\Concerns;
use SyncCampus\Service\DelegatePool;
use SyncCampus\Service\Performance;
use SyncCampus\Time;

/**
 * Guild President and Vice: triage, assignment and oversight.
 * System admins only manage accounts and cannot open student concerns.
 */
final class LeaderController
{
    public function queue(Request $request): array
    {
        $user = Auth::requireRole($request, Auth::LEADERS);
        Concerns::runEscalationChecksIfDue();
        Auth::audit($user['id'], 'view_queue', 'concern');

        $pool = new DelegatePool();
        $assignees = Concerns::activeAssignees();
        $groups = Concerns::groupSizes();
        $students = Concerns::groupStudents();
        return array_map(fn ($c) => $this->row($c, $pool, $assignees, $groups, $students), Concerns::open());
    }

    public function concern(Request $request): array
    {
        $user = Auth::requireRole($request, Auth::LEADERS);
        $concern = Concerns::find($request->id()) ?? throw new HttpError(404, 'Concern not found');
        Auth::audit($user['id'], 'view_concern', 'concern', (int) $concern['id']);

        $pool = new DelegatePool();
        $related = $concern['group_id']
            ? Db::all('SELECT reference, title FROM concerns WHERE group_id = ? AND id <> ? ORDER BY id', [$concern['group_id'], $concern['id']])
            : [];
        $updates = Db::all('SELECT author_label, message, visibility, created_at FROM concern_updates WHERE concern_id = ? ORDER BY id', [$concern['id']]);
        $escalations = Db::all('SELECT type, reason, triggered_at FROM escalation_events WHERE concern_id = ? ORDER BY id', [$concern['id']]);

        return $this->row($concern, $pool, Concerns::activeAssignees(), Concerns::groupSizes(), Concerns::groupStudents()) + [
            'description' => $concern['description'],
            'explanation' => Concerns::explain($concern),
            'recommendations' => $pool->recommend($concern),
            'related' => $related,
            'updates' => array_map(static fn ($u) => [
                'from' => $u['author_label'],
                'message' => $u['message'],
                'visibility' => $u['visibility'],
                'at' => Time::iso($u['created_at']),
            ], $updates),
            'escalations' => array_map(static fn ($e) => [
                'type' => $e['type'],
                'reason' => $e['reason'],
                'at' => Time::iso($e['triggered_at']),
            ], $escalations),
        ];
    }

    public function assign(Request $request): array
    {
        $user = Auth::requireRole($request, Auth::LEADERS);
        $concern = Concerns::find($request->id()) ?? throw new HttpError(404, 'Concern or delegate not found');
        if (!Concerns::isOpen($concern)) {
            throw new HttpError(400, 'Only open concerns can be assigned');
        }
        $delegateId = $request->input()->int('delegate_id', 'Delegate', 0, 1, PHP_INT_MAX);
        return Concerns::assign($concern, $delegateId, $user);
    }

    public function workload(Request $request): array
    {
        Auth::requireRole($request, Auth::LEADERS);
        return (new DelegatePool())->workload();
    }

    public function analytics(Request $request): array
    {
        Auth::requireRole($request, Auth::LEADERS);
        Concerns::runEscalationChecksIfDue();
        return Analytics::summarise(Concerns::where());
    }

    /** Delegate invitations, for the Team view of the Guild workspace. */
    public function invitations(Request $request): array
    {
        Auth::requireRole($request, Auth::LEADERS);
        $rows = Db::all(
            "SELECT i.id, i.invitee_name, i.invitee_email, i.portfolio, i.created_at, i.expires_at,
                    i.redeemed_at, i.revoked_at, u.full_name AS created_by
               FROM staff_invitations i JOIN users u ON u.id = i.created_by
              WHERE i.role = 'delegate' ORDER BY i.id DESC LIMIT 50"
        );
        return array_map(static fn ($i) => [
            'id' => (int) $i['id'],
            'invitee_name' => $i['invitee_name'],
            'invitee_email' => $i['invitee_email'],
            'portfolio' => $i['portfolio'],
            'created_by' => $i['created_by'],
            'created_at' => Time::iso($i['created_at']),
            'expires_at' => Time::iso($i['expires_at']),
            'status' => $i['redeemed_at'] ? 'used' : ($i['revoked_at'] ? 'cancelled'
                : (strtotime($i['expires_at'] . ' UTC') <= time() ? 'expired' : 'pending')),
        ], $rows);
    }

    public function revokeInvitation(Request $request): array
    {
        $user = Auth::requireRole($request, Auth::LEADERS);
        Db::run(
            "UPDATE staff_invitations SET revoked_at = ? WHERE id = ? AND role = 'delegate' AND redeemed_at IS NULL AND revoked_at IS NULL",
            [Time::now(), $request->id()]
        );
        Auth::audit($user['id'], 'revoke_invitation', 'staff_invitation', $request->id());
        return ['ok' => true];
    }

    public function performance(Request $request): array
    {
        Auth::requireRole($request, Auth::LEADERS);
        return Performance::all();
    }

    public function acknowledge(Request $request): array
    {
        $user = Auth::requireRole($request, Auth::LEADERS);
        $event = Db::one('SELECT id FROM escalation_events WHERE id = ?', [$request->id()]) ?? throw new HttpError(404, 'Escalation not found');
        Db::run('UPDATE escalation_events SET acknowledged_at = ? WHERE id = ? AND acknowledged_at IS NULL', [Time::now(), $event['id']]);
        Auth::audit($user['id'], 'acknowledge_escalation', 'escalation', (int) $event['id']);
        return ['ok' => true];
    }

    public function escalations(Request $request): array
    {
        Auth::requireRole($request, Auth::LEADERS);
        $rows = Db::all(
            'SELECT e.id, e.type, e.reason, e.triggered_at, e.acknowledged_at, c.reference, c.title
               FROM escalation_events e JOIN concerns c ON c.id = e.concern_id
              ORDER BY e.id DESC LIMIT 50'
        );
        return array_map(static fn ($e) => [
            'id' => (int) $e['id'],
            'acknowledged' => $e['acknowledged_at'] !== null,
            'concern' => $e['reference'],
            'title' => $e['title'],
            'type' => $e['type'],
            'reason' => $e['reason'],
            'at' => Time::iso($e['triggered_at']),
        ], $rows);
    }

    public function runEscalation(Request $request): array
    {
        Auth::requireRole($request, Auth::LEADERS);
        return Concerns::runEscalationChecks();
    }

    public function createHandover(Request $request): array
    {
        $user = Auth::requireRole($request, Auth::LEADERS);
        $term = mb_substr($request->query('term') ?: 'Current term', 0, 60);
        $snapshot = Concerns::handover($term, $user);

        Db::insert('handover_records', [
            'term_label' => $term,
            'snapshot' => json_encode($snapshot, JSON_UNESCAPED_UNICODE),
            'generated_by' => $user['id'],
            'generated_at' => Time::now(),
        ]);
        Auth::audit($user['id'], 'generate_handover', 'handover');
        return $snapshot;
    }

    public function handovers(Request $request): array
    {
        Auth::requireRole($request, Auth::STAFF);
        $rows = Db::all('SELECT id, term_label, snapshot, generated_at FROM handover_records ORDER BY id DESC LIMIT 20');
        return array_map(static fn ($h) => [
            'id' => (int) $h['id'],
            'term' => $h['term_label'],
            'generated_at' => Time::iso($h['generated_at']),
            'snapshot' => json_decode($h['snapshot'], true),
        ], $rows);
    }

    /** Concerns per month and per campus, for planning. */
    public function trends(Request $request): array
    {
        Auth::requireRole($request, Auth::LEADERS);
        $monthly = $campuses = [];
        foreach (Db::all('SELECT created_at, campus FROM concerns') as $c) {
            $month = substr($c['created_at'], 0, 7);
            $monthly[$month] = ($monthly[$month] ?? 0) + 1;
            $campus = $c['campus'] ?: 'Not stated';
            $campuses[$campus] = ($campuses[$campus] ?? 0) + 1;
        }
        ksort($monthly);
        arsort($campuses);

        $out = ['monthly' => [], 'by_campus' => []];
        foreach ($monthly as $month => $count) {
            $out['monthly'][] = ['month' => $month, 'count' => $count];
        }
        foreach ($campuses as $campus => $count) {
            $out['by_campus'][] = ['campus' => (string) $campus, 'count' => $count];
        }
        return $out;
    }

    private function row(array $c, DelegatePool $pool, array $assignees, array $groups, array $groupStudents): array
    {
        $assignedTo = $assignees[$c['id']] ?? null;
        return [
            'id' => (int) $c['id'],
            'reference' => $c['reference'],
            'title' => $c['title'],
            'category' => $c['category'],
            'campus' => $c['campus'],
            'location' => $c['location'],
            'students_affected' => (int) $c['students_affected'],
            'priority_score' => (float) $c['priority_score'],
            'priority_level' => $c['priority_level'],
            'status' => $c['status'],
            'is_overdue' => (bool) $c['is_overdue'],
            'age_hours' => round(Time::hoursSince($c['created_at']), 1),
            'related_reports' => Concerns::relatedCount($c, $groups),
            'group_students' => $c['group_id'] ? ($groupStudents[(int) $c['group_id']] ?? null) : null,
            'safety_flag' => (bool) $c['safety_flag'],
            'academic_impact' => (bool) $c['academic_impact'],
            'financial_impact' => (bool) $c['financial_impact'],
            'assigned_to' => $assignedTo,
            'recommended' => $assignedTo === null ? ($pool->recommend($c)[0] ?? null) : null,
        ];
    }
}
