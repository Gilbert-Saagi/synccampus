<?php
declare(strict_types=1);

namespace SyncCampus\Engine;

/**
 * Related-concern detection with TF-IDF vectors and cosine similarity.
 *
 * Text is lower-cased, stop words are dropped, words are lightly stemmed and a
 * small synonym map folds common variants together ("wifi", "internet" and
 * "connection" all become "network"), so two students describing the same
 * problem in different words still match.
 */
final class Similarity
{
    private const STOP_WORDS = [
        'a', 'an', 'the', 'and', 'or', 'but', 'of', 'to', 'in', 'on', 'at', 'for', 'from', 'with', 'by',
        'is', 'are', 'was', 'were', 'be', 'been', 'it', 'its', 'this', 'that', 'these', 'those', 'i', 'we',
        'you', 'they', 'he', 'she', 'my', 'our', 'your', 'their', 'me', 'us', 'them', 'there', 'here', 'very',
        'not', 'no', 'yes', 'please', 'have', 'has', 'had', 'do', 'does', 'did', 'can', 'could', 'would',
        'should', 'will', 'just', 'so', 'as', 'if', 'than', 'then', 'too', 'also', 'about', 'into', 'out',
        'up', 'down', 'over', 'under', 'again', 'still',
    ];

    private const SYNONYMS = [
        'wifi' => 'network', 'internet' => 'network', 'connection' => 'network', 'connect' => 'network',
        'online' => 'network',
        'broken' => 'fault', 'damaged' => 'fault', 'faulty' => 'fault', 'failing' => 'fault', 'failed' => 'fault',
        'down' => 'fault', 'outage' => 'fault', 'working' => 'fault', 'work' => 'fault',
        'lecturer' => 'teacher', 'tutor' => 'teacher', 'instructor' => 'teacher',
        'fees' => 'tuition', 'fee' => 'tuition', 'payment' => 'tuition', 'paid' => 'tuition',
        'exam' => 'examination', 'exams' => 'examination', 'examinations' => 'examination', 'test' => 'examination',
    ];

    /**
     * @param array<int, string> $candidates concern id => text
     * @param array<int, string> $locations  concern id => location
     * @return list<array{id:int, score:float, label:string}> best match first
     */
    public static function findRelated(string $text, array $candidates, string $location = '', array $locations = []): array
    {
        if (!$candidates) {
            return [];
        }
        $vectors = self::vectors(array_merge([$text], array_values($candidates)));
        $target = array_shift($vectors);

        $matches = [];
        foreach (array_keys($candidates) as $i => $id) {
            $score = self::cosine($target, $vectors[$i]);
            if ($location !== '' && strcasecmp($locations[$id] ?? '', $location) === 0) {
                $score = min(1.0, $score + Rules::SAME_LOCATION_BONUS);
            }
            $label = match (true) {
                $score >= Rules::DUPLICATE_THRESHOLD => 'duplicate',
                $score >= Rules::RELATED_THRESHOLD => 'related',
                default => null,
            };
            if ($label !== null) {
                $matches[] = ['id' => $id, 'score' => round($score, 3), 'label' => $label];
            }
        }
        usort($matches, static fn ($a, $b) => $b['score'] <=> $a['score']);
        return $matches;
    }

    public static function tokenize(string $text): array
    {
        $text = str_replace(['wi-fi', 'wi fi'], 'wifi', strtolower($text));
        preg_match_all('/[a-z0-9]+/', $text, $m);

        $tokens = [];
        foreach ($m[0] as $word) {
            if (strlen($word) < 2 || in_array($word, self::STOP_WORDS, true)) {
                continue;
            }
            $stem = self::stem($word);
            $tokens[] = self::SYNONYMS[$word] ?? self::SYNONYMS[$stem] ?? $stem;
        }
        return $tokens;
    }

    public static function cosine(array $a, array $b): float
    {
        if (count($a) > count($b)) {
            [$a, $b] = [$b, $a];
        }
        $sum = 0.0;
        foreach ($a as $term => $weight) {
            $sum += $weight * ($b[$term] ?? 0.0);
        }
        return $sum;
    }

    /** Unit-length TF-IDF vectors (sublinear tf, smoothed idf) for each document. */
    private static function vectors(array $documents): array
    {
        $counts = array_map(static fn ($doc) => array_count_values(self::tokenize($doc)), $documents);

        $docFrequency = [];
        foreach ($counts as $terms) {
            foreach (array_keys($terms) as $term) {
                $docFrequency[$term] = ($docFrequency[$term] ?? 0) + 1;
            }
        }

        $n = count($documents);
        $vectors = [];
        foreach ($counts as $terms) {
            $vector = [];
            foreach ($terms as $term => $frequency) {
                $idf = log((1 + $n) / (1 + $docFrequency[$term])) + 1;
                $vector[$term] = (1 + log($frequency)) * $idf;
            }
            $norm = sqrt(array_sum(array_map(static fn ($x) => $x * $x, $vector))) ?: 1.0;
            $vectors[] = array_map(static fn ($x) => $x / $norm, $vector);
        }
        return $vectors;
    }

    private static function stem(string $word): string
    {
        foreach (['ing', 'ed', 'es', 's'] as $suffix) {
            if (strlen($word) > 4 && str_ends_with($word, $suffix)) {
                return substr($word, 0, -strlen($suffix));
            }
        }
        return $word;
    }
}
