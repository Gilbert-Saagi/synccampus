<?php
declare(strict_types=1);

namespace SyncCampus\Controller;

use SyncCampus\Codes;
use SyncCampus\Db;
use SyncCampus\Engine\Rules;
use SyncCampus\Input;
use SyncCampus\HttpError;
use SyncCampus\Request;
use SyncCampus\Response;
use SyncCampus\Service\Concerns;
use SyncCampus\Throttle;
use SyncCampus\Time;

/**
 * Anonymous student endpoints. There are no student accounts: a concern is
 * opened again with its reference plus the private access code shown once
 * at submission.
 */
final class StudentController
{
    private const STAGES = ['new', 'assigned', 'in_progress', 'resolved', 'closed'];

    public function categories(): array
    {
        return array_map(static fn ($c) => [
            'id' => (int) $c['id'],
            'name' => $c['name'],
            'is_sensitive' => (bool) $c['is_sensitive'],
            // Used to pre-tick the impact boxes on the form; the student can change them.
            'academic' => in_array($c['name'], Rules::ACADEMIC_CATEGORIES, true),
            'financial' => in_array($c['name'], Rules::FINANCIAL_CATEGORIES, true),
        ], Db::all('SELECT id, name, is_sensitive FROM categories ORDER BY id'));
    }

    /**
     * Cabinet members with an active account and the kinds of concern they
     * handle, for the "who looks at this" panel. Names and areas only.
     */
    public function cabinet(): array
    {
        $rows = Db::all(
            "SELECT u.full_name, u.role, d.portfolio, d.categories_handled, d.campus
               FROM delegates d JOIN users u ON u.id = d.user_id
              WHERE u.is_active = TRUE AND d.show_public = TRUE AND u.role IN ('president', 'vice_guild', 'delegate')
              ORDER BY u.full_name"
        );
        return array_map(static fn ($r) => [
            'name' => $r['full_name'],
            'position' => $r['portfolio'],
            'is_leader' => in_array($r['role'], ['president', 'vice_guild'], true),
            'handles' => array_values(array_filter(
                array_map('trim', explode(',', $r['categories_handled'])),
                static fn ($c) => $c !== '' && strcasecmp($c, 'General') !== 0
            )),
            'campus' => $r['campus'],
        ], $rows);
    }

    public function submit(Request $request): Response
    {
        $in = $request->input();
        [$concern, $code, $matches] = Concerns::create([
            'title' => $in->text('title', 'Title', 5, 200),
            'description' => $in->text('description', 'Description', 10, 5000),
            'category_id' => $in->int('category_id', 'Category', 0, 0, PHP_INT_MAX),
            'campus' => $in->text('campus', 'Campus', 0, 60),
            'location' => $in->text('location', 'Location', 0, 120),
            'urgency' => $in->int('urgency', 'Urgency', 2, 1, 4),
            'students_affected' => $in->int('students_affected', 'Students affected', 1, 1, 5000),
            'safety_flag' => $in->bool('safety_flag'),
            'academic_impact' => $in->bool('academic_impact'),
            'financial_impact' => $in->bool('financial_impact'),
            'is_public' => $in->bool('is_public'),
        ]);

        return Response::created([
            'reference' => $concern['reference'],
            'access_code' => $code,
            'is_public' => (bool) $concern['is_public'],
            'note' => 'Save your access code now. It is shown once and cannot be recovered.',
            'similar_reports_found' => count($matches),
        ]);
    }

    public function track(Request $request): array
    {
        $concern = $this->unlock($request->input());
        $updates = Db::all(
            "SELECT author_label, message, visibility, created_at FROM concern_updates
              WHERE concern_id = ? AND visibility <> 'internal' ORDER BY id",
            [$concern['id']]
        );

        return [
            'reference' => $concern['reference'],
            'title' => $concern['title'],
            'description' => $concern['description'],
            'status' => $concern['status'],
            'category' => $concern['category'],
            'created_at' => Time::iso($concern['created_at']),
            'resolved_at' => Time::iso($concern['resolved_at']),
            'is_public' => (bool) $concern['is_public'],
            'reopened_count' => (int) $concern['reopened_count'],
            'updates' => array_map(static fn ($u) => [
                'from' => $u['author_label'],
                'message' => $u['message'],
                'visibility' => $u['visibility'],
                'at' => Time::iso($u['created_at']),
            ], $updates),
        ];
    }

    public function addInfo(Request $request): array
    {
        $in = $request->input();
        $concern = $this->unlock($in);
        if ($concern['status'] === 'closed') {
            throw new HttpError(400, 'This concern is closed. Submit a new concern if the problem has come back.');
        }
        $this->studentNote((int) $concern['id'], $in->text('message', 'Message', 2, 3000));
        return ['ok' => true];
    }

    /** The student confirms the fix, or reopens the concern if the problem is still there. */
    public function confirm(Request $request): array
    {
        $in = $request->input();
        $concern = $this->unlock($in);
        if ($concern['status'] !== 'resolved') {
            throw new HttpError(400, 'This concern has not been marked resolved yet');
        }
        $resolved = $in->bool('resolved');
        $comment = $in->text('comment', 'Comment', 0, 3000);
        $rating = $in->has('rating') ? $in->int('rating', 'Rating', 3, 1, 5) : null;

        Db::transaction(function () use ($concern, $resolved, $comment, $rating) {
            if ($resolved) {
                Db::run("UPDATE concerns SET status = 'closed', satisfaction_rating = ? WHERE id = ?", [$rating, $concern['id']]);
            } else {
                Db::run(
                    "UPDATE concerns SET status = 'in_progress', resolved_at = NULL, is_overdue = FALSE,
                            reopened_count = reopened_count + 1 WHERE id = ?",
                    [$concern['id']]
                );
                // Hand it back to whoever resolved it.
                $last = Db::value(
                    "SELECT MAX(id) FROM assignments WHERE concern_id = ? AND status = 'completed'",
                    [$concern['id']]
                );
                if ($last !== null) {
                    Db::run("UPDATE assignments SET status = 'active', completed_at = NULL WHERE id = ?", [$last]);
                }
            }
            if ($comment !== '') {
                $this->studentNote((int) $concern['id'], $comment);
            }
        });
        Concerns::rescore(Concerns::find((int) $concern['id']));

        return ['status' => $resolved ? 'closed' : 'in_progress'];
    }

    /** Concerns students chose to make public, with official public replies only. */
    public function publicFeed(Request $request): array
    {
        $limit = max(1, min(100, (int) $request->query('limit', '100')));
        $rows = Db::all(
            "SELECT c.id, c.reference, c.title, c.description, c.campus, c.status, c.created_at, k.name AS category
               FROM concerns c JOIN categories k ON k.id = c.category_id
              WHERE c.is_public = TRUE AND k.is_sensitive = FALSE
              ORDER BY c.created_at DESC LIMIT $limit"
        );

        $replies = [];
        if ($rows) {
            $ids = array_column($rows, 'id');
            $marks = implode(',', array_fill(0, count($ids), '?'));
            $updates = Db::all(
                "SELECT concern_id, author_label, message FROM concern_updates
                  WHERE visibility = 'public' AND concern_id IN ($marks) ORDER BY id",
                $ids
            );
            foreach ($updates as $u) {
                $replies[(int) $u['concern_id']][] = ['from' => $u['author_label'], 'message' => $u['message']];
            }
        }

        return array_map(static fn ($c) => [
            'reference' => $c['reference'],
            'title' => $c['title'],
            'description' => $c['description'],
            'campus' => $c['campus'],
            'category' => $c['category'],
            'status' => $c['status'],
            'created_at' => Time::iso($c['created_at']),
            'public_replies' => $replies[(int) $c['id']] ?? [],
        ], $rows);
    }

    /** Aggregate figures for the home page. No titles or private details. */
    public function stats(): array
    {
        try {
            Concerns::runEscalationChecksIfDue();
        } catch (\Throwable $e) {
            error_log('Escalation check failed: ' . $e->getMessage());
        }

        $all = Db::all(
            'SELECT c.status, c.is_overdue, c.campus, c.created_at, c.first_response_at, k.name AS category, k.is_sensitive
               FROM concerns c JOIN categories k ON k.id = c.category_id'
        );

        $stages = array_fill_keys(self::STAGES, 0);
        $done = $overdue = 0;
        $firstResponse = $topics = [];
        foreach ($all as $c) {
            $stages[$c['status']] = ($stages[$c['status']] ?? 0) + 1;
            $isDone = in_array($c['status'], ['resolved', 'closed'], true);
            $done += $isDone ? 1 : 0;
            $overdue += (!$isDone && $c['is_overdue']) ? 1 : 0;
            if ($c['first_response_at']) {
                $firstResponse[] = Time::hoursBetween($c['created_at'], $c['first_response_at']);
            }
            if (!$c['is_sensitive']) {
                $topics[$c['category']] = ($topics[$c['category']] ?? 0) + 1;
            }
        }
        arsort($topics);
        $topCategories = [];
        foreach (array_slice($topics, 0, 5) as $name => $count) {
            $topCategories[] = [$name, $count];
        }

        $total = count($all);
        $open = $total - $done;
        return [
            'total' => $total,
            'open' => $open,
            'resolved' => $done,
            'resolution_rate' => $total ? (int) round(100 * $done / $total) : 0,
            'avg_first_response_hrs' => $firstResponse ? round(array_sum($firstResponse) / count($firstResponse), 1) : null,
            'overdue_now' => $overdue,
            'overdue_pct' => $open ? (int) round(100 * $overdue / $open) : 0,
            'stages' => $stages,
            'top_categories' => $topCategories,
        ];
    }

    private function unlock(Input $in): array
    {
        $reference = strtoupper($in->text('reference', 'Reference', 1, 20));
        $code = $in->text('access_code', 'Access code', 1, 40);
        $key = 'concern:' . $reference;

        Throttle::check($key);
        $concern = Concerns::findByReference($reference);
        if ($concern === null || !hash_equals($concern['access_code_hash'], Codes::hashAccessCode($code))) {
            Throttle::fail($key);
            throw new HttpError(404, 'Reference or access code not recognised');
        }
        Throttle::clear($key);
        return $concern;
    }

    private function studentNote(int $concernId, string $message): void
    {
        Db::insert('concern_updates', [
            'concern_id' => $concernId,
            'author_user_id' => null,
            'author_label' => 'Student',
            'visibility' => 'private',
            'message' => $message,
            'created_at' => Time::now(),
        ]);
    }
}
