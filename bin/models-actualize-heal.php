<?php
/**
 * models-actualize-heal.php - close the runs that finished before v1.9.3.
 *
 * Every models-actualization run opens a council session (so the session
 * endpoints resolve while it works) and, before v1.9.3, nothing ever closed it:
 * the queue row went `completed` and the session stayed `running` for ever -
 * History kept a green "running" on a run that finished minutes after it
 * started, with no steps and no answer.
 *
 * The fix closes the session when the job ends. This command repairs the rows
 * that were already written: for every such session whose job is in a terminal
 * state it writes the run's own report as the Final answer and completes the
 * session, using the envelope the job already stores. Nothing is recomputed and
 * nothing is fetched - the text comes from `revoap_jobs.result_json`.
 *
 * It is a dry run unless `--yes` is passed.
 *
 *   php bin/models-actualize-heal.php            # show what would change
 *   php bin/models-actualize-heal.php --yes      # apply
 *
 * Exit codes: 0 = nothing to do or applied; 1 = a row could not be written;
 * 3 = the database is not reachable.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-09-27
 */
declare(strict_types=1);

$root     = dirname(__DIR__);
$composer = $root . '/vendor/autoload.php';
if (is_file($composer)) {
    require $composer;
} else {
    require $root . '/src/autoload.php';
}

use App\Core\Application;
use App\Core\Config;
use App\Models\ModelsActualizeReport;
use App\Storage\SessionRepository;

Config::load($root . '/config.php', $root);

$options = getopt('', ['yes', 'limit:', 'help']);
if (isset($options['help'])) {
    echo "models-actualize-heal.php - close models-actualization sessions left `running`.\n\n"
        . "  --yes        apply the changes (default: dry run)\n"
        . "  --limit=N    how many sessions to look at (default 100)\n";
    exit(0);
}

$apply = isset($options['yes']);
$limit = max(1, (int)($options['limit'] ?? 100));

try {
    $app      = new Application($root);
    $db       = $app->database();
    $pdo      = $db->run(static fn(PDO $p): PDO => $p);
    $sessions = new SessionRepository($db);
} catch (Throwable $e) {
    fwrite(STDERR, 'database unavailable: ' . $e->getMessage() . "\n");
    exit(3);
}

$sql = "SELECT s.id, s.query_text, j.id AS job_id, j.status AS job_status, j.result_json, j.error_json
        FROM council_sessions s
        JOIN revoap_jobs j ON j.session_id = s.id
        WHERE s.query_text = 'models actualize'
          AND s.status = 'running'
          AND j.status IN ('completed', 'failed', 'cancelled')
        ORDER BY s.id ASC
        LIMIT " . $limit;

$rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

echo ($apply ? 'Applying' : 'Dry run') . ': ' . count($rows) . " run(s) to close\n";

$changed = 0;
$errors  = 0;
foreach ($rows as $row) {
    $sessionId = (int)$row['id'];
    $jobId     = (int)$row['job_id'];
    $status    = (string)$row['job_status'];

    $envelope = json_decode((string)$row['result_json'], true);
    $ok       = $status === 'completed' && is_array($envelope) && ($envelope['ok'] ?? false) === true;

    if ($ok) {
        $markdown = ModelsActualizeReport::markdown($envelope);
        $duration = (int)($envelope['duration_ms'] ?? 0);
    } else {
        $error    = json_decode((string)$row['error_json'], true);
        $detail   = is_array($error) ? (string)($error['message'] ?? '') : '';
        if ($detail === '' && is_array($envelope)) {
            $detail = (string)(($envelope['error']['message'] ?? '') ?: ($envelope['message'] ?? ''));
        }
        $markdown = ModelsActualizeReport::failure(
            $detail !== '' ? $detail : 'The run ended as "' . $status . '" without a report.',
            is_array($envelope) ? (string)($envelope['error']['class'] ?? '') : '',
        );
        $duration = is_array($envelope) ? (int)($envelope['duration_ms'] ?? 0) : 0;
    }

    $firstLine = trim((string)strtok($markdown, "\n"));
    printf(
        "  session %-4d job %-4d %-10s -> %s  (%s)\n",
        $sessionId,
        $jobId,
        $status,
        $ok ? 'completed' : 'failed',
        $firstLine !== '' ? $firstLine : '-',
    );

    if (!$apply) {
        $changed++;
        continue;
    }
    try {
        $sessions->complete(
            id:              $sessionId,
            finalAnswer:     $markdown,
            consensusScore:  null,
            consensusStatus: ModelsActualizeReport::CONSENSUS_STATUS,
            totalTokens:     0,
            durationMs:      $duration,
            status:          $ok ? 'completed' : 'failed',
        );
        $changed++;
    } catch (Throwable $e) {
        $errors++;
        fwrite(STDERR, '  session ' . $sessionId . ': write failed - ' . $e->getMessage() . "\n");
    }
}

echo ($apply ? 'closed ' : 'would close ') . $changed . " session(s)"
    . ($errors > 0 ? ', ' . $errors . ' failed' : '') . "\n";

exit($errors === 0 ? 0 : 1);
