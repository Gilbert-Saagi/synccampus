<?php
declare(strict_types=1);

namespace SyncCampus\Service;

use SyncCampus\Db;
use SyncCampus\Engine\Analytics;
use SyncCampus\Time;

/**
 * Delegate performance indicators (Q26–Q28), computed from recorded data
 * rather than opinion:
 *
 *   assigned          concerns ever assigned to the delegate
 *   open              currently held and not yet resolved
 *   resolved          assignments the delegate completed
 *   overdue           currently held concerns past their escalation time
 *   completion_rate   resolved / (resolved + open)
 *   avg_first_response_hrs  assignment -> delegate's first reply the student can see
 *   avg_resolution_hrs      assignment -> marked resolved
 *   escalated         held concerns that triggered an escalation
 *   reassigned_away   concerns moved from this delegate to someone else
 *   avg_satisfaction  student rating (1-5) on concerns the delegate resolved
 */
final class Performance
{
    public static function all(): array
    {
        $delegates = Db::all(
            "SELECT d.id, d.user_id, d.portfolio, u.full_name AS name
               FROM delegates d JOIN users u ON u.id = d.user_id
              WHERE u.role = 'delegate' AND u.is_active = TRUE ORDER BY u.full_name"
        );
        return array_map(static fn ($d) => self::forDelegate($d), $delegates);
    }

    public static function forUser(int $userId): ?array
    {
        $delegate = Db::one(
            'SELECT d.id, d.user_id, d.portfolio, u.full_name AS name FROM delegates d JOIN users u ON u.id = d.user_id WHERE d.user_id = ?',
            [$userId]
        );
        return $delegate === null ? null : self::forDelegate($delegate);
    }

    private static function forDelegate(array $d): array
    {
        $rows = Db::all(
            "SELECT a.concern_id, a.status, a.assigned_at, a.completed_at,
                    c.status AS concern_status, c.is_overdue, c.satisfaction_rating
               FROM assignments a JOIN concerns c ON c.id = a.concern_id
              WHERE a.delegate_id = ?",
            [$d['id']]
        );

        $concernIds = [];
        $open = $resolved = $overdue = $reassigned = 0;
        $firstResponse = $resolution = $ratings = [];
        foreach ($rows as $r) {
            $concernIds[(int) $r['concern_id']] = true;
            if ($r['status'] === 'reassigned') {
                $reassigned++;
                continue;
            }
            if ($r['status'] === 'active' && in_array($r['concern_status'], Analytics::OPEN_STATUSES, true)) {
                $open++;
                $overdue += $r['is_overdue'] ? 1 : 0;
            }
            if ($r['status'] === 'completed') {
                $resolved++;
                if ($r['completed_at']) {
                    $resolution[] = Time::hoursBetween($r['assigned_at'], $r['completed_at']);
                }
                if ($r['satisfaction_rating']) {
                    $ratings[] = (int) $r['satisfaction_rating'];
                }
            }
            $reply = Db::value(
                "SELECT MIN(created_at) FROM concern_updates
                  WHERE concern_id = ? AND author_user_id = ? AND visibility <> 'internal' AND created_at >= ?",
                [$r['concern_id'], $d['user_id'], $r['assigned_at']]
            );
            if ($reply !== null) {
                $firstResponse[] = Time::hoursBetween($r['assigned_at'], $reply);
            }
        }

        $escalated = 0;
        if ($concernIds) {
            $marks = implode(',', array_fill(0, count($concernIds), '?'));
            $escalated = (int) Db::value(
                "SELECT COUNT(DISTINCT concern_id) FROM escalation_events WHERE type = 'escalation' AND concern_id IN ($marks)",
                array_keys($concernIds)
            );
        }

        return [
            'delegate_id' => (int) $d['id'],
            'name' => $d['name'],
            'portfolio' => $d['portfolio'],
            'assigned' => count($concernIds),
            'open' => $open,
            'resolved' => $resolved,
            'overdue' => $overdue,
            'completion_rate' => ($resolved + $open) ? (int) round(100 * $resolved / ($resolved + $open)) : null,
            'avg_first_response_hrs' => Analytics::average($firstResponse),
            'avg_resolution_hrs' => Analytics::average($resolution),
            'escalated' => $escalated,
            'reassigned_away' => $reassigned,
            'avg_satisfaction' => Analytics::average($ratings),
        ];
    }
}
