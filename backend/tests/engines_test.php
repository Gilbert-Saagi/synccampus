<?php
declare(strict_types=1);

/*
 * Checks for the decision-support rules. No database needed.
 *
 *   php tests/engines_test.php
 */

use SyncCampus\Engine\Escalation;
use SyncCampus\Engine\Priority;
use SyncCampus\Engine\Recommender;
use SyncCampus\Engine\Rules;
use SyncCampus\Engine\Similarity;

require dirname(__DIR__) . '/src/bootstrap.php';

$failures = 0;
$count = 0;

function check(string $name, bool $passed): void
{
    global $failures, $count;
    $count++;
    if (!$passed) {
        $failures++;
    }
    echo ($passed ? '  ok    ' : '  FAIL  ') . $name . "\n";
}

echo "Priority score\n";
$weights = Rules::priorityWeights();
check('Q16 weights sum to 1 and follow the ranking', abs(array_sum($weights) - 1) < 1e-9
    && $weights['S'] > $weights['U'] && $weights['U'] > $weights['N'] && $weights['D'] > $weights['F']);

$all = Priority::fromFactors(['S' => 1, 'U' => 1, 'N' => 1, 'A' => 1, 'R' => 1, 'D' => 1, 'F' => 1]);
check('all factors at maximum give 100 / Critical', $all['score'] === 100.0 && $all['level'] === 'Critical');

$none = Priority::fromFactors(['S' => 0, 'U' => 0, 'N' => 0, 'A' => 0, 'R' => 0, 'D' => 0, 'F' => 0]);
check('all factors at zero give 0 / Low', $none['score'] === 0.0 && $none['level'] === 'Low');

$safety = Priority::fromFactors(['S' => 0.3, 'U' => 0.25, 'N' => 0.04, 'A' => 0, 'R' => 0, 'D' => 0, 'F' => 0], true);
check('safety flag lifts a low score to Critical', $safety['score'] === 80.0 && isset($safety['points']['safety_rule']));

$fresh = Priority::compute(0.55, 2, 1, 0, 0, false);
$repeated = Priority::compute(0.55, 2, 1, 0, 3, false);
check('repeated reports raise the score', $repeated['score'] > $fresh['score']);

$waiting = Priority::compute(0.55, 2, 1, 48, 0, false);
$cap = round(100 * $weights['D'], 2);
check('time open raises the score, capped at the response limit', abs(($waiting['score'] - $fresh['score']) - $cap) < 0.11);

$academic = Priority::compute(0.55, 2, 1, 0, 0, false, true);
$financial = Priority::compute(0.55, 2, 1, 0, 0, false, false, true);
check('academic impact outweighs financial impact (Q16)', $academic['score'] > $financial['score'] && $financial['score'] > $fresh['score']);

check('level bands', Priority::level(79.9) === 'High' && Priority::level(60) === 'High'
    && Priority::level(40) === 'Medium' && Priority::level(39.9) === 'Low');

echo "Related-concern detection\n";
$matches = Similarity::findRelated(
    'Wi-Fi not working in the library',
    [1 => 'The internet connection is down in the library', 2 => 'Broken chairs in lecture room C4'],
);
check('differently worded network complaints match', count($matches) === 1 && $matches[0]['id'] === 1);

$same = Similarity::findRelated('Exam timetable clash for year two', [7 => 'Exam timetable clash for year two']);
check('identical text is labelled a duplicate', ($same[0]['label'] ?? null) === 'duplicate');

check('no candidates means no matches', Similarity::findRelated('anything', []) === []);

check('synonyms and stems fold together',
    Similarity::tokenize('Exams fees wifi') === ['examination', 'tuition', 'network']);

echo "Delegate recommendation\n";
$delegates = [
    ['id' => 1, 'name' => 'ICT minister', 'portfolio' => '', 'categories_handled' => 'ICT / Internet', 'open_load' => 1, 'max_open_load' => 5, 'resolved_similar' => 2, 'is_available' => true],
    ['id' => 2, 'name' => 'General', 'portfolio' => '', 'categories_handled' => 'General', 'open_load' => 0, 'max_open_load' => 5, 'resolved_similar' => 0, 'is_available' => true],
    ['id' => 3, 'name' => 'Full', 'portfolio' => '', 'categories_handled' => 'ICT / Internet', 'open_load' => 5, 'max_open_load' => 5, 'resolved_similar' => 9, 'is_available' => true],
    ['id' => 4, 'name' => 'Away', 'portfolio' => '', 'categories_handled' => 'ICT / Internet', 'open_load' => 0, 'max_open_load' => 5, 'resolved_similar' => 0, 'is_available' => false],
];
$ranked = Recommender::rank($delegates, 'ICT / Internet');
check('area owner ranks first', $ranked[0]['delegate_id'] === 1);
check('full and unavailable delegates are excluded', array_column($ranked, 'delegate_id') === [1, 2]);
check('reasons explain the score', in_array('area owner', $ranked[0]['reasons'], true));

$light = Recommender::score('ICT / Internet', 'ICT / Internet', 0, 5, 0)['score'];
$busy = Recommender::score('ICT / Internet', 'ICT / Internet', 4, 5, 0)['score'];
check('lighter workload scores higher', $light > $busy);

$here = Recommender::score('ICT / Internet', 'ICT / Internet', 0, 5, 0, 'Main Campus', 'Main Campus')['score'];
$anywhere = Recommender::score('ICT / Internet', 'ICT / Internet', 0, 5, 0, '', 'Main Campus')['score'];
$elsewhere = Recommender::score('ICT / Internet', 'ICT / Internet', 0, 5, 0, 'City Campus', 'Main Campus')['score'];
check('Q18: same-campus delegate preferred', $here > $anywhere && $anywhere > $elsewhere);

echo "Escalation\n";
check('nothing before the reminder time', Escalation::evaluate('High', 11, false, false) === null);
check('reminder after 12h for High', Escalation::evaluate('High', 12, false, false) === 'reminder');
check('reminder is only sent once', Escalation::evaluate('High', 13, true, false) === null);
check('escalation after 24h for High', Escalation::evaluate('High', 24, true, false) === 'escalation');
check('escalation is only sent once', Escalation::evaluate('High', 99, true, true) === null);
check('Critical escalates after 6h', Escalation::evaluate('Critical', 6, false, false) === 'escalation');
check('Q22: Medium escalates at 3 days, Low at 1 week',
    Escalation::evaluate('Medium', 72, true, false) === 'escalation' && Escalation::evaluate('Low', 167, true, false) === null
    && Escalation::evaluate('Low', 168, true, false) === 'escalation');

echo "\n" . ($count - $failures) . "/$count passed\n";
exit($failures ? 1 : 0);
