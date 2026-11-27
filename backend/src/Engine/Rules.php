<?php
declare(strict_types=1);

namespace SyncCampus\Engine;

/**
 * Every tunable number used by the decision-support rules, in one place.
 * Values marked with a question number (Q16, Q22, ...) come from the
 * requirements questionnaire; update them here if the tallies change.
 */
final class Rules
{
    /*
     * Q16 – priority factors ranked from most to least important. Safety risk
     * ranked first, so it is applied as an override (a safety concern is never
     * below Critical) rather than as one weight among many. The remaining
     * factors get rank-sum weights: w = (n - rank + 1) / sum, n = 7.
     */
    public const PRIORITY_RANKING = [
        'S' => 'Severity of the problem',
        'U' => 'Urgency',
        'N' => 'Number of students affected',
        'A' => 'Academic impact',
        'R' => 'Repeated / similar reports',
        'D' => 'Duration of the problem',
        'F' => 'Financial impact',
    ];

    // Lowest score for each level, highest band first (Q17).
    public const LEVEL_BANDS = ['Critical' => 80, 'High' => 60, 'Medium' => 40, 'Low' => 0];

    public const URGENCY_VALUES = [1 => 0.25, 2 => 0.50, 3 => 0.75, 4 => 1.00];
    public const STUDENTS_CAP = 25;         // N = min(affected / 25, 1)
    public const RELATED_CAP = 4;           // R = min(related reports / 4, 1)
    public const RESPONSE_LIMIT_HOURS = 24; // D = min(hours open / 24, 1)

    // Categories that start with the academic / financial impact box ticked on the form.
    public const ACADEMIC_CATEGORIES = ['Academic issues', 'Examinations', 'Registration', 'Timetables'];
    public const FINANCIAL_CATEGORIES = ['Tuition / fees'];

    // Cosine similarity thresholds for related-concern detection (Q29).
    public const RELATED_THRESHOLD = 0.50;
    public const DUPLICATE_THRESHOLD = 0.80;
    public const SAME_LOCATION_BONUS = 0.05;
    public const RELATED_WINDOW_DAYS = 30;

    /*
     * Q18 – what a delegate recommendation considers: portfolio / category
     * Match, spare capacity (1 - Load), Experience with similar concerns and
     * the campus (Place) of the concern. Availability is a filter, not a weight.
     */
    public const DELEGATE_WEIGHTS = ['M' => 0.45, 'L' => 0.25, 'E' => 0.15, 'P' => 0.15];
    public const EXPERIENCE_CAP = 10;       // E = min(similar resolved / 10, 1)
    public const GENERAL_PORTFOLIO_MATCH = 0.4;
    public const ANY_CAMPUS_MATCH = 0.5;    // delegate covers every campus

    /*
     * Q22 – maximum time before escalation per level: Critical within 6 hours,
     * High within 24 hours, Medium within 2–3 days, Low within 1 week.
     * The delegate gets a reminder halfway there.
     */
    public const ESCALATION_HOURS = [
        'Critical' => ['reminder' => 3, 'escalate' => 6],
        'High' => ['reminder' => 12, 'escalate' => 24],
        'Medium' => ['reminder' => 36, 'escalate' => 72],
        'Low' => ['reminder' => 84, 'escalate' => 168],
    ];

    /** Weights derived from PRIORITY_RANKING. */
    public static function priorityWeights(): array
    {
        $n = count(self::PRIORITY_RANKING);
        $sum = $n * ($n + 1) / 2;
        $weights = [];
        $rank = 1;
        foreach (array_keys(self::PRIORITY_RANKING) as $factor) {
            $weights[$factor] = ($n - $rank + 1) / $sum;
            $rank++;
        }
        return $weights;
    }
}
