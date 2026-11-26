<?php
declare(strict_types=1);

namespace SyncCampus\Engine;

/**
 * Weighted-sum priority score, 0-100.
 *
 *   score = 100 * sum(w_i * f_i)   for f in S, U, N, A, R, D, F
 *
 * Each factor is normalised to 0..1 first and the weights come from the
 * questionnaire ranking (see Rules), so the breakdown can be shown to leaders.
 * A concern flagged as a safety risk is never ranked below Critical.
 */
final class Priority
{
    public static function compute(
        float $baseSeverity,
        int $urgency,
        int $studentsAffected,
        float $hoursOpen,
        int $relatedReports,
        bool $safetyFlag,
        bool $academicImpact = false,
        bool $financialImpact = false,
    ): array {
        return self::fromFactors([
            'S' => min(max($baseSeverity, 0), 1),
            'U' => Rules::URGENCY_VALUES[$urgency] ?? 0.5,
            'N' => min($studentsAffected / Rules::STUDENTS_CAP, 1),
            'A' => $academicImpact ? 1 : 0,
            'R' => min($relatedReports / Rules::RELATED_CAP, 1),
            'D' => min(max($hoursOpen, 0) / Rules::RESPONSE_LIMIT_HOURS, 1),
            'F' => $financialImpact ? 1 : 0,
        ], $safetyFlag);
    }

    public static function fromFactors(array $factors, bool $safetyFlag = false): array
    {
        $points = [];
        foreach (Rules::priorityWeights() as $key => $weight) {
            $points[$key] = round($weight * ($factors[$key] ?? 0) * 100, 2);
        }
        $score = round(array_sum($points), 1);

        $criticalFloor = Rules::LEVEL_BANDS['Critical'];
        if ($safetyFlag && $score < $criticalFloor) {
            $score = (float) $criticalFloor;
            $points['safety_rule'] = 'raised to Critical band';
        }

        return [
            'score' => $score,
            'level' => self::level($score),
            'factors' => array_map(static fn ($v) => round((float) $v, 4), $factors),
            'points' => $points,
            'labels' => Rules::PRIORITY_RANKING,
        ];
    }

    public static function level(float $score): string
    {
        foreach (Rules::LEVEL_BANDS as $level => $floor) {
            if ($score >= $floor) {
                return $level;
            }
        }
        return 'Low';
    }
}
