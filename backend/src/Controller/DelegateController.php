<?php
declare(strict_types=1);

namespace SyncCampus\Controller;

use SyncCampus\Auth;
use SyncCampus\Db;
use SyncCampus\HttpError;
use SyncCampus\Request;
use SyncCampus\Service\Concerns;
use SyncCampus\Service\Performance;
use SyncCampus\Time;

/** Ministers and GRC delegates working on the concerns assigned to them. */
final class DelegateController
{
    public function myConcerns(Request $request): array
    {
        $user = Auth::requireRole($request, Auth::STAFF);
        $delegateId = Db::value('SELECT id FROM delegates WHERE user_id = ?', [$user['id']]);
        if ($delegateId === null) {
            return [];
        }
        Auth::audit($user['id'], 'view_assigned', 'concern');

        $concerns = Concerns::where(
            "c.id IN (SELECT concern_id FROM assignments WHERE delegate_id = ? AND status = 'active')",
            [$delegateId]
        );

        // Q20: a new assignment is flagged until the delegate has seen it once.
        $unseen = array_flip(array_map('intval', array_column(Db::all(
            "SELECT concern_id FROM assignments WHERE delegate_id = ? AND status = 'active' AND seen_at IS NULL",
            [$delegateId]
        ), 'concern_id')));
        Db::run("UPDATE assignments SET seen_at = ? WHERE delegate_id = ? AND status = 'active' AND seen_at IS NULL", [Time::now(), $delegateId]);

        $flags = [];
        foreach (Db::all('SELECT concern_id, type FROM escalation_events') as $e) {
            $flags[(int) $e['concern_id']][$e['type']] = true;
        }

        $history = [];
        if ($concerns) {
            $ids = array_column($concerns, 'id');
            $marks = implode(',', array_fill(0, count($ids), '?'));
            $updates = Db::all(
                "SELECT concern_id, author_label, message, visibility, created_at
                   FROM concern_updates WHERE concern_id IN ($marks) ORDER BY id",
                $ids
            );
            foreach ($updates as $u) {
                $history[(int) $u['concern_id']][] = [
                    'from' => $u['author_label'],
                    'message' => $u['message'],
                    'visibility' => $u['visibility'],
                    'at' => Time::iso($u['created_at']),
                ];
            }
        }

        return array_map(static fn ($c) => [
            'id' => (int) $c['id'],
            'is_new' => isset($unseen[(int) $c['id']]),
            'reminded' => isset($flags[(int) $c['id']]['reminder']),
            'escalated' => isset($flags[(int) $c['id']]['escalation']),
            'reference' => $c['reference'],
            'title' => $c['title'],
            'description' => $c['description'],
            'category' => $c['category'],
            'is_sensitive' => (bool) $c['is_sensitive'],
            'campus' => $c['campus'],
            'location' => $c['location'],
            'students_affected' => (int) $c['students_affected'],
            'priority_level' => $c['priority_level'],
            'priority_score' => (float) $c['priority_score'],
            'status' => $c['status'],
            'is_overdue' => (bool) $c['is_overdue'],
            'age_hours' => round(Time::hoursSince($c['created_at']), 1),
            'updates' => $history[(int) $c['id']] ?? [],
        ], $concerns);
    }

    /** Q28: a delegate can see their own performance figures. */
    public function myPerformance(Request $request): array
    {
        $user = Auth::requireRole($request, Auth::STAFF);
        return Performance::forUser($user['id']) ?? [];
    }

    /** Q18: delegates mark themselves away so they are not recommended. */
    public function setAvailability(Request $request): array
    {
        $user = Auth::requireRole($request, Auth::STAFF);
        $available = $request->input()->bool('available');
        Db::run('UPDATE delegates SET is_available = ? WHERE user_id = ?', [$available, $user['id']]);
        Auth::audit($user['id'], $available ? 'set_available' : 'set_away', 'delegate');
        return ['available' => $available];
    }

    public function availability(Request $request): array
    {
        $user = Auth::requireRole($request, Auth::STAFF);
        $value = Db::value('SELECT is_available FROM delegates WHERE user_id = ?', [$user['id']]);
        return ['available' => $value === null ? true : (bool) $value];
    }

    /** A reply to the student, a public reply, or an internal note, with an optional status change. */
    public function postUpdate(Request $request): array
    {
        $user = Auth::requireRole($request, Auth::STAFF);
        $concern = Concerns::find($request->id()) ?? throw new HttpError(404, 'Concern not found');

        $holdsIt = (bool) Db::value(
            "SELECT 1 FROM assignments a JOIN delegates d ON d.id = a.delegate_id
              WHERE a.concern_id = ? AND a.status = 'active' AND d.user_id = ?",
            [$concern['id'], $user['id']]
        );
        if (!$holdsIt && !in_array($user['role'], Auth::LEADERS, true)) {
            throw new HttpError(403, 'This concern is not assigned to you');
        }
        if ($concern['status'] === 'closed') {
            throw new HttpError(400, 'This concern is already closed');
        }

        $in = $request->input();
        $message = $in->text('message', 'Message', 2, 3000);
        $visibility = $in->choice('visibility', ['public', 'private', 'internal'], 'private', 'Invalid visibility');
        $status = $in->choice('status', ['in_progress', 'resolved'], null, 'Invalid status');
        if ($visibility === 'public' && $concern['is_sensitive']) {
            throw new HttpError(400, 'Sensitive concerns cannot have public replies');
        }

        $now = Time::now();
        Db::transaction(static function () use ($concern, $user, $message, $visibility, $status, $now) {
            Db::insert('concern_updates', [
                'concern_id' => (int) $concern['id'],
                'author_user_id' => $user['id'],
                'author_label' => $user['full_name'],
                'visibility' => $visibility,
                'message' => $message,
                'created_at' => $now,
            ]);
            // Response time is measured to the first reply the student can actually see.
            if ($visibility !== 'internal' && !$concern['first_response_at']) {
                Db::run('UPDATE concerns SET first_response_at = ? WHERE id = ?', [$now, $concern['id']]);
            }
            if ($status === 'in_progress') {
                Db::run("UPDATE concerns SET status = 'in_progress' WHERE id = ?", [$concern['id']]);
            } elseif ($status === 'resolved') {
                Db::run("UPDATE concerns SET status = 'resolved', resolved_at = ? WHERE id = ?", [$now, $concern['id']]);
                Db::run(
                    "UPDATE assignments SET status = 'completed', completed_at = ? WHERE concern_id = ? AND status = 'active'",
                    [$now, $concern['id']]
                );
            }
            Auth::audit($user['id'], 'update_concern', 'concern', (int) $concern['id']);
        });

        return ['status' => $status ?? $concern['status']];
    }
}
