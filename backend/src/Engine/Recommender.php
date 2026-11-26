<?php
declare(strict_types=1);

namespace SyncCampus\Engine;

/**
 * Ranks delegates for a concern.
 *
 *   score = 100 * (wM*M + wL*(1 - L) + wE*E + wP*P)
 *
 * M  1 when the concern's category is in the delegate's area, 0.4 for a
 *    "General" portfolio, otherwise 0.
 * L  current open assignments / maximum load.
 * E  similar concerns already resolved (capped at 10).
 * P  1 when the delegate covers the concern's campus, 0.5 when they cover
 *    every campus, otherwise 0.
 *
 * Unavailable delegates and anyone already at full load are left out. The
 * leader still makes the final decision and may override the suggestion.
 */
final class Recommender
{
    public static function categoryMatch(string $categoriesHandled, string $category): float
    {
        $areas = array_filter(array_map(static fn ($c) => strtolower(trim($c)), explode(',', $categoriesHandled)));
        if (in_array(strtolower($category), $areas, true)) {
            return 1.0;
        }
        return in_array('general', $areas, true) ? Rules::GENERAL_PORTFOLIO_MATCH : 0.0;
    }

    public static function campusMatch(string $delegateCampus, string $concernCampus): float
    {
        if ($delegateCampus === '') {
            return Rules::ANY_CAMPUS_MATCH;
        }
        return strcasecmp($delegateCampus, $concernCampus) === 0 ? 1.0 : 0.0;
    }

    public static function score(
        string $categoriesHandled,
        string $category,
        int $openLoad,
        int $maxLoad,
        int $resolvedSimilar,
        string $delegateCampus = '',
        string $concernCampus = '',
    ): array {
        $m = self::categoryMatch($categoriesHandled, $category);
        $l = $maxLoad > 0 ? min($openLoad / $maxLoad, 1) : 1;
        $e = min($resolvedSimilar / Rules::EXPERIENCE_CAP, 1);
        $p = self::campusMatch($delegateCampus, $concernCampus);
        $w = Rules::DELEGATE_WEIGHTS;

        $reasons = [$m == 1.0 ? 'area owner' : ($m > 0 ? 'general portfolio' : 'no area match'), "load $openLoad/$maxLoad"];
        if ($resolvedSimilar > 0) {
            $reasons[] = "$resolvedSimilar similar resolved";
        }
        $reasons[] = $p == 1.0 ? 'same campus' : ($p > 0 ? 'covers all campuses' : 'other campus');

        return [
            'score' => round(100 * ($w['M'] * $m + $w['L'] * (1 - $l) + $w['E'] * $e + $w['P'] * $p), 1),
            'factors' => ['M' => $m, 'L' => round($l, 2), 'E' => round($e, 2), 'P' => $p],
            'reasons' => $reasons,
        ];
    }

    /**
     * @param list<array{id:int, name:string, portfolio:string, categories_handled:string, campus?:string,
     *                   open_load:int, max_open_load:int, resolved_similar:int, is_available:bool}> $delegates
     */
    public static function rank(array $delegates, string $category, string $campus = ''): array
    {
        $ranked = [];
        foreach ($delegates as $d) {
            if (!$d['is_available'] || $d['open_load'] >= $d['max_open_load']) {
                continue;
            }
            $result = self::score(
                $d['categories_handled'], $category, $d['open_load'], $d['max_open_load'],
                $d['resolved_similar'], $d['campus'] ?? '', $campus,
            );
            $ranked[] = ['delegate_id' => $d['id'], 'name' => $d['name'], 'portfolio' => $d['portfolio']] + $result;
        }
        usort($ranked, static fn ($a, $b) => $b['score'] <=> $a['score']);
        return $ranked;
    }
}
