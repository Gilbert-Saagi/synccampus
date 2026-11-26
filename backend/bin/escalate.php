<?php
declare(strict_types=1);

/*
 * Runs the reminder / escalation check once. Schedule it where the host
 * allows cron jobs, e.g. every 5 minutes:
 *
 *   php /path/to/backend/bin/escalate.php
 *
 * Without cron the same check still runs when the leader dashboard or the
 * home page statistics load (at most every five minutes).
 */

use SyncCampus\Service\Concerns;

require dirname(__DIR__) . '/src/bootstrap.php';

$fired = Concerns::runEscalationChecks();
foreach ($fired as $event) {
    echo "{$event['type']}: {$event['concern']} ({$event['reason']})\n";
}
echo count($fired) . " new reminder(s) or escalation(s).\n";
