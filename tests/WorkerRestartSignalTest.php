<?php
/**
 * WorkerRestartSignalTest - the rule that makes a restart button honest (R2).
 *
 * There is one rule, and it is the reason this is a timestamp rather than a flag:
 * a request counts only for a process that started **before** it was written.
 * Without that, one click would keep every future worker exiting the moment it
 * started - a restart button that turns into an off switch.
 *
 * The storage half is exercised live instead (the endpoint writes, the worker
 * reads); what is pinned here is the decision, which is where a mistake would be
 * silent.
 *
 * Run: php tests/WorkerRestartSignalTest.php
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-09-23
 */
declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

use App\Jobs\WorkerRestartSignal;

$failures = 0;
$passes   = 0;

function check(string $label, bool $cond, string $detail = ''): void
{
    global $failures, $passes;
    if ($cond) {
        echo "  ok  {$label}\n";
        $passes++;
    } else {
        echo "  FAIL {$label}" . ($detail !== '' ? " - {$detail}" : '') . "\n";
        $failures++;
    }
}

$start = 1_700_000_000;

check('no request means no exit', WorkerRestartSignal::shouldExit(null, $start) === false);
check('a request older than the process is ignored - it belongs to a previous worker',
    WorkerRestartSignal::shouldExit($start - 60, $start) === false);
check('a request from the very second the process started is ignored',
    WorkerRestartSignal::shouldExit($start, $start) === false);
check('a request newer than the process stops it', WorkerRestartSignal::shouldExit($start + 1, $start) === true);
check('and a much newer one does too', WorkerRestartSignal::shouldExit($start + 86_400, $start) === true);
check('the decision does not depend on the clock, only on the two values',
    WorkerRestartSignal::shouldExit(2, 1) === true && WorkerRestartSignal::shouldExit(1, 1) === false);

$empty = new WorkerRestartSignal(null);
check('without a database the signal reads as "no request"', $empty->requestedAt() === null);
check('and asking for a restart there reports failure instead of pretending',
    $empty->request() === null);
check('the storage key is the documented one', WorkerRestartSignal::KEY === 'worker.restart_requested_at');

echo "\n=========================\n";
echo "Passes: {$passes}  Failures: {$failures}\n";
exit($failures === 0 ? 0 : 1);
