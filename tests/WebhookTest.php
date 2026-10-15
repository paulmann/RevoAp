<?php
/**
 * WebhookTest: offline unit checks for the webhook subsystem
 * (Task: REST API, Step 6 - webhooks).
 *
 * Runs without PHPUnit, a database or network access:
 * `php tests/WebhookTest.php`.
 *
 * Verified:
 *   - WebhookService validation (label/url/events/private-host guard)
 *     and automatic secret generation (wh_ prefix, 46 chars);
 *   - Webhook public arrays never leak the raw secret (***abcd hint);
 *   - WebhookDispatcher signs with HMAC-SHA256 over `t.body` and
 *     WebhookDispatcher::verifySignature accepts valid / rejects
 *     tampered / wrong-secret signatures;
 *   - 2xx -> success; non-2xx -> pending with backoff (60/300/900/1800);
 *   - pumpDue honours the injectable clock, re-sends due deliveries and
 *     gives up after max_attempts;
 *   - removed/disabled subscriptions stop retrying with giving_up;
 *   - dispatch is fail-open (exceptions never propagate);
 *   - createPending is idempotent per (webhook_id, event_id).
 *
 * NOTE: prints its report at the very end only.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.1.0
 * @since    2026-09-19
 */
declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

use App\Api\Webhook;
use App\Api\WebhookDelivery;
use App\Api\WebhookDeliveryRepository;
use App\Api\WebhookDispatcher;
use App\Api\WebhookRepository;
use App\Api\WebhookService;
use App\Core\Config;
use App\Core\Logger;

$failures = 0;
$passes   = 0;
$report   = [];

function checkWh(string $label, bool $cond, string $detail = ''): void
{
    global $failures, $passes, $report;
    if ($cond) {
        $report[] = "  ok  {$label}";
        $passes++;
    } else {
        $report[] = "  FAIL {$label}" . ($detail !== '' ? " - {$detail}" : '');
        $failures++;
    }
}

function cleanupWhDir(string $dir): void
{
    if (!is_dir($dir)) { return; }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($dir);
}

$work = sys_get_temp_dir() . '/revoap_webhook_' . bin2hex(random_bytes(4));
@mkdir($work, 0o777, true);
// NOTE: Config::set takes NESTED arrays, not dot-notation keys.
Config::set([
    'app'      => ['debug' => true],
    'logging'  => ['path' => $work],
    'webhooks' => [
        'allow_private_urls'    => false,
        'retry_backoff_seconds' => [60, 300, 900, 1800],
        'max_attempts'          => 4,
        'timeout_seconds'       => 5,
    ],
], __DIR__);
Logger::reset();

// --------------------------------------------------------------
// WebhookService: validation + secret generation
// --------------------------------------------------------------
$webhooks   = new WebhookRepository(null); // memory mode
$deliveries = new WebhookDeliveryRepository(null);
$service    = new WebhookService($webhooks);

$made = $service->create('CI build', 'https://example.com/hook', null, ['run.completed']);
checkWh('service.create returns a Webhook', $made instanceof Webhook);
checkWh('generated secret has wh_ prefix', str_starts_with($made->getSecret(), 'wh_'), $made->getSecret());
checkWh('generated secret length == 46', strlen($made->getSecret()) === 46, (string)strlen($made->getSecret()));
checkWh('public array masks the secret', ($made->toPublicArray()['secret_tip'] ?? '') !== $made->getSecret());
checkWh('public array secret_tip is ***abcd', ($made->toPublicArray()['secret_tip'] ?? '') === '***' . substr($made->getSecret(), -4));
checkWh('public array has no raw secret key', !array_key_exists('secret', $made->toPublicArray()));

$badEvents = false;
try { $service->create('x', 'https://example.com/', null, ['nope.event']); }
catch (InvalidArgumentException) { $badEvents = true; }
checkWh('unknown event rejected', $badEvents);

$noEvents = false;
try { $service->create('x', 'https://example.com/', null, []); }
catch (InvalidArgumentException) { $noEvents = true; }
checkWh('empty event list rejected', $noEvents);

$badUrl = false;
try { $service->create('x', 'ftp://example.com/', null, ['run.completed']); }
catch (InvalidArgumentException) { $badUrl = true; }
checkWh('non-http url rejected', $badUrl);

$privateUrl = false;
try { $service->create('x', 'http://localhost:8080/', null, ['run.completed']); }
catch (InvalidArgumentException) { $privateUrl = true; }
checkWh('loopback url rejected (SSRF guard)', $privateUrl);

$rotated = $service->update($made->id, 'renamed', 'https://example.com/other', null, ['run.completed', 'run.failed']);
checkWh('update regenerates the secret when null', $rotated !== null && $rotated->getSecret() !== $made->getSecret());
checkWh('update returns null for unknown id', $service->update(9999, 'x', 'https://example.com/', null, ['run.completed']) === null);

// Remove the scratch subscription so the dispatcher sections below see
// EXACTLY the subscriptions they create themselves.
checkWh('scratch subscription deleted', $webhooks->delete($made->id));

// --------------------------------------------------------------
// WebhookDispatcher: signing + delivery + retries
// --------------------------------------------------------------
// Timestamps use values near the REAL clock so verifySignature (which
// checks clock skew against time()) accepts them.
$baseNow    = time() - 120;
$dispatcher = new WebhookDispatcher($webhooks, $deliveries);
$sent       = [];
$dispatcher->setSender(function (string $url, array $headers, string $body) use (&$sent): array {
    $sigHeader = '';
    foreach ($headers as $h) {
        if (str_starts_with($h, WebhookDispatcher::SIGNATURE_HEADER . ':')) {
            $sigHeader = trim((string)substr($h, strlen(WebhookDispatcher::SIGNATURE_HEADER) + 1));
        }
    }
    $sent[] = ['url' => $url, 'headers' => $headers, 'body' => $body, 'signature' => $sigHeader];
    return ['code' => 200, 'body' => 'ok'];
});
$dispatcher->setNow(static fn(): int => $baseNow);

$hook = $service->create('receiver', 'https://example.com/inbox', 'wh_secrettestsecret000', ['run.completed', 'run.failed', 'run.cancelled']);

$dispatcher->setUuid(static fn(): string => '11111111-2222-4333-8444-555555555555');
$summary = $dispatcher->dispatch('run.completed', ['run_id' => 'run_7', 'final_answer' => 'ok']);
checkWh('dispatch sends to an active listener', $summary['sent'] === 1, json_encode($summary));
checkWh('one outgoing request was made', count($sent) === 1, (string)count($sent));
checkWh('request is a JSON POST to subscriber url', $sent[0]['url'] === 'https://example.com/inbox');
checkWh('has User-Agent header', in_array('User-Agent: RevoAp-Webhook/1.0', $sent[0]['headers'], true));

$payload = json_decode($sent[0]['body'], true);
checkWh('payload is valid JSON', is_array($payload));
checkWh('payload.event == run.completed', ($payload['event'] ?? '') === 'run.completed');
checkWh('payload.event_id is deterministic uuid', ($payload['event_id'] ?? '') === '11111111-2222-4333-8444-555555555555');
checkWh('payload.data carries the run data', ($payload['data']['run_id'] ?? '') === 'run_7');

$verified = WebhookDispatcher::verifySignature('wh_secrettestsecret000', $sent[0]['body'], $sent[0]['signature']);
checkWh('receiver can verify the signature', $verified, $sent[0]['signature']);
checkWh('tampered body fails verification', !WebhookDispatcher::verifySignature('wh_secrettestsecret000', $sent[0]['body'] . 'x', $sent[0]['signature']));
checkWh('wrong secret fails verification', !WebhookDispatcher::verifySignature('nope', $sent[0]['body'], $sent[0]['signature']));

// A second dispatch with a DIFFERENT event id creates a second row.
$dispatcher->setUuid(static fn(): string => '22222222-3333-4444-8555-666666666666');
$summary2 = $dispatcher->dispatch('run.completed', ['run_id' => 'run_7']);
checkWh('second dispatch is a new event and is sent', $summary2['sent'] === 1 && count($sent) === 2, (string)count($sent));
$recent = $deliveries->recent($hook->id);
checkWh('two distinct delivery rows exist', count($recent) === 2, (string)count($recent));
checkWh('both deliveries succeeded', array_reduce($recent, static fn(bool $c, WebhookDelivery $d): bool => $c && $d->status === WebhookDelivery::STATUS_SUCCESS, true));

// Retry/bounce path: a failing receiver.
// Fake timeline (UTC): N = 1_700_000_000 -> '2023-11-14 22:13:20'.
$fails = 0;
$retryDispatcher = new WebhookDispatcher($webhooks, $deliveries);
$retryDispatcher->setNow(static fn(): int => 1_700_000_000);
$retryDispatcher->setUuid(static fn(): string => '99999999-8888-4777-8666-555555555555');
$retryDispatcher->setSender(static function () use (&$fails): array {
    $fails++;
    return ['code' => 500, 'body' => 'boom'];
});
$rv = $retryDispatcher->dispatch('run.failed', ['run_id' => 'run_8', 'error' => 'x']);
checkWh('bounce counts as queued', $rv['queued'] === 1 && $rv['sent'] === 0, json_encode($rv));
$pendingRow = $deliveries->recent($hook->id)[0];
checkWh('bounced row is pending, attempt bumped to 2', $pendingRow->status === WebhookDelivery::STATUS_PENDING && $pendingRow->attempt === 2, (string)$pendingRow->attempt);
checkWh('next_retry_at is now + 60s', $pendingRow->nextRetryAt === gmdate('Y-m-d H:i:s', 1_700_000_000 + 60), (string)$pendingRow->nextRetryAt);

// pumpDue with the injectable clock: the row is NOT due yet at N.
$processed = $retryDispatcher->pumpDue();
checkWh('pumpDue skips not-yet-due rows', $processed === 0, (string)$processed);

// N + 100s: the row is due -> re-sent, attempt 3, backoff +300s.
$retryDispatcher->setNow(static fn(): int => 1_700_000_100);
$processed = $retryDispatcher->pumpDue();
checkWh('pumpDue re-sends due rows', $processed === 1, (string)$processed);
checkWh('sender was called again on retry', $fails === 2, (string)$fails);
$pendingRow = $deliveries->recent($hook->id)[0];
checkWh('row attempt bumped to 3 and still pending', $pendingRow->attempt === 3 && $pendingRow->status === WebhookDelivery::STATUS_PENDING, (string)$pendingRow->attempt);
checkWh('backoff grew to +300s', $pendingRow->nextRetryAt === gmdate('Y-m-d H:i:s', 1_700_000_100 + 300), (string)$pendingRow->nextRetryAt);

// N + 401s: another failed send -> attempt 4, backoff +900s.
$retryDispatcher->setNow(static fn(): int => 1_700_000_401);
$retryDispatcher->pumpDue();
$pendingRow = $deliveries->recent($hook->id)[0];
checkWh('attempt reached 4 and backoff is +900s', $pendingRow->attempt === 4 && $pendingRow->nextRetryAt === gmdate('Y-m-d H:i:s', 1_700_000_401 + 900), (string)$pendingRow->nextRetryAt);

// N + 401 + 900 + 1s: attempt 4 is sent once more, fails, then giving_up.
$retryDispatcher->setNow(static fn(): int => 1_700_000_401 + 900 + 1);
$retryDispatcher->pumpDue();
$pendingRow = $deliveries->recent($hook->id)[0];
checkWh('after max_attempts the row is giving_up', $pendingRow->status === WebhookDelivery::STATUS_GIVING_UP, $pendingRow->status);
checkWh('giving_up keeps the final attempt counter', $pendingRow->attempt === 4, (string)$pendingRow->attempt);
checkWh('the final (4th) send happened before giving_up', $fails === 4, (string)$fails);

// Removed subscription: its pending rows stop with giving_up.
$ghost = $service->create('ghost', 'https://example.com/ghost', 'wh_secret0000000000000000', ['run.completed']);
$retryDispatcher->setNow(static fn(): int => 1_700_000_000);
$retryDispatcher->dispatch('run.completed', ['run_id' => 'run_9']);
$webhooks->delete($ghost->id);
$retryDispatcher->setNow(static fn(): int => 1_700_000_100);
$retryDispatcher->pumpDue();
$ghostRows = array_values(array_filter(
    $deliveries->recent(null, 200),
    static fn(WebhookDelivery $d): bool => $d->webhookId === $ghost->id,
));
checkWh('removed subscription deliveries stop with giving_up', count($ghostRows) === 1 && $ghostRows[0]->status === WebhookDelivery::STATUS_GIVING_UP);

// Fail-open: a sender that throws must not break dispatch.
$crash = new WebhookDispatcher($webhooks, $deliveries);
$crash->setSender(static function (): array { throw new RuntimeException('network down'); });
$crash->setUuid(static fn(): string => '77777777-6666-4555-8444-333333333333');
$crash->setNow(static fn(): int => $baseNow);
$crashSummary = $crash->dispatch('run.cancelled', ['run_id' => 'run_10']);
checkWh('transport exception is fail-open', is_array($crashSummary) && ($crashSummary['failed'] ?? 0) === 1, json_encode($crashSummary));

// --------------------------------------------------------------
// WebhookDeliveryRepository: due + createPending idempotency
// --------------------------------------------------------------
$dueNow = gmdate('Y-m-d H:i:s', 1_900_000_000); // far future -> all pending rows due
$dueBefore = $deliveries->due($dueNow);
checkWh('due() returns only pending rows', array_reduce($dueBefore, static fn(bool $ok, WebhookDelivery $d): bool => $ok && $d->status === WebhookDelivery::STATUS_PENDING, true));

$deliveryA = $deliveries->createPending($hook->id, 'dup-event-id', 'run.completed', '{"a":1}', null);
$deliveryB = $deliveries->createPending($hook->id, 'dup-event-id', 'run.completed', '{"b":2}', null);
checkWh('createPending is idempotent per (webhook,event)', $deliveryA->id === $deliveryB->id && $deliveryA->payload === '{"a":1}');

// --------------------------------------------------------------
// WebhookRepository CRUD
// --------------------------------------------------------------
$all = $webhooks->all();
checkWh('all() lists exactly the receiver subscription', count($all) === 1 && $all[0]->id === $hook->id, (string)count($all));
$toggled = $webhooks->toggleActive($hook->id, false);
checkWh('toggleActive flips the flag', $toggled !== null && $toggled->active === false);
checkWh('inactive subscription is not dispatched to', $dispatcher->dispatch('run.completed', ['run_id' => 'run_11'])['sent'] === 0);
$webhooks->toggleActive($hook->id, true);
checkWh('findById returns the stored hook', $webhooks->findById($hook->id)?->label === 'receiver');
checkWh('delete removes the row', $webhooks->delete($hook->id) && $webhooks->findById($hook->id) === null);
checkWh('delete of unknown id is false', $webhooks->delete(99999) === false);

cleanupWhDir($work);
echo implode("\n", $report) . "\n";
echo ($failures === 0 ? 'ALL OK' : 'FAILURES') . ": {$passes} passed, {$failures} failed\n";
exit($failures === 0 ? 0 : 1);
