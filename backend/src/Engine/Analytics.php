<?php
declare(strict_types=1);

namespace SyncCampus\Engine;

use SyncCampus\Time;

/** Leadership accountability indicators computed from concern rows. */
final class Analytics
{
    public const OPEN_STATUSES = ['new', 'assigned', 'in_progress'];

    /**
     * @param list<array> $concerns rows with status, priority_level, is_overdue, category,
     *                              timestamps, first_assigned_at and satisfaction_rating
     */
    public static function summarise(array $concerns): array
    {
        $open = array_filter($concerns, static fn ($c) => in_array($c['status'], self::OPEN_STATUSES, true));
        $resolved = array_filter($concerns, static fn ($c) => !empty($c['resolved_at']));

        $assign = $firstResponse = $resolution = $ratings = [];
        $byCategory = [];
        $reopened = 0;
        foreach ($concerns as $c) {
            if (!empty($c['first_assigned_at'])) {
                $assign[] = Time::hoursBetween($c['created_at'], $c['first_assigned_at']);
            }
            if (!empty($c['first_response_at'])) {
                $firstResponse[] = Time::hoursBetween($c['created_at'], $c['first_response_at']);
            }
            if (!empty($c['resolved_at'])) {
                $resolution[] = Time::hoursBetween($c['created_at'], $c['resolved_at']);
            }
            if (!empty($c['satisfaction_rating'])) {
                $ratings[] = (int) $c['satisfaction_rating'];
            }
            if ((int) $c['reopened_count'] > 0) {
                $reopened++;
            }
            $byCategory[$c['category']] = ($byCategory[$c['category']] ?? 0) + 1;
        }
        arsort($byCategory);

        $total = count($concerns);
        return [
            'total' => $total,
            'open' => count($open),
            'critical_high' => count(array_filter($open, static fn ($c) => in_array($c['priority_level'], ['Critical', 'High'], true))),
            'overdue' => count(array_filter($open, static fn ($c) => (bool) $c['is_overdue'])),
            'avg_assign_hrs' => self::average($assign),
            'avg_first_response_hrs' => self::average($firstResponse),
            'avg_resolution_hrs' => self::average($resolution),
            'resolution_rate' => $total ? (int) round(100 * count($resolved) / $total) : 0,
            'reopen_rate' => $resolved ? (int) round(100 * $reopened / count($resolved)) : 0,
            'avg_satisfaction' => self::average($ratings),
            'ratings_count' => count($ratings),
            'by_category' => (object) $byCategory,
        ];
    }

    public static function average(array $values): ?float
    {
        return $values ? round(array_sum($values) / count($values), 1) : null;
    }
}
