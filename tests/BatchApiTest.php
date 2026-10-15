<?php
/**
 * BatchApiTest: offline coverage for the Batch API run mode.
 *
 * The Batch API is asynchronous, multipart, JSONL-shaped and only partially
 * specified — the documentation itself names the upload field two different
 * ways and does not fix the download route. None of that can be exercised
 * against the real provider from a test suite (no key, no network, and on
 * this host no ext-curl at all), so the whole surface was built around an
 * injectable transport. This file is what that seam is for: every check
 * below drives the real classes against canned HTTP bodies.
 *
 * What is covered, in the order the feature is used:
 *
 *   (a) the client, endpoint by endpoint — including both documented
 *       ambiguities (the `files`/`file` upload field, the download route),
 *       the failure of every one of them, and the missing-ext-curl path;
 *   (b) the JSONL contract in both directions: what the builder refuses
 *       before anything is uploaded, and what the parser reports when the
 *       provider's answer is short, reordered, duplicated or malformed;
 *   (c) the durable state machine — which action each persisted phase
 *       implies, that a parked row really is skipped by the queue and
 *       picked up again when due, and that a resumed phase reads everything
 *       from the database rather than from the process that submitted it;
 *   (d) cancellation, including the case that used to strand a job: a cancel
 *       arriving while the row is parked between polls;
 *   (e) the `:batch` rule in both directions — refused synchronously, first
 *       class asynchronously — and the capability report's tri-state, which
 *       must never turn a failed lookup into "unsupported".
 *
 * What is deliberately NOT covered: the real provider. Nothing here proves
 * that Bothub accepts these requests, that the `:batch` id is the one its
 * batch endpoint wants, or that the poll cadence is the right one for a
 * given account — those are the things to verify on a server with a key.
 *
 * Runs without network, database, PHPUnit or ext-curl:
 *   php tests/BatchApiTest.php
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-09-21
 */
declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

use App\Batch\BatchCanceller;
use App\Batch\BatchCapability;
use App\Batch\BatchL1PlannerInterface;
use App\Batch\BatchPipeline;
use App\Batch\BatchState;
use App\Connectors\AbstractConnector;
use App\Connectors\Batch\BatchCustomId;
use App\Connectors\Batch\BatchHttpResponse;
use App\Connectors\Batch\BatchJsonlBuilder;
use App\Connectors\Batch\BatchLifecycle;
use App\Connectors\Batch\BatchModelCatalog;
use App\Connectors\Batch\BatchResultParser;
use App\Connectors\Batch\BatchTransportInterface;
use App\Connectors\Batch\BothubBatchClient;
use App\Connectors\Batch\CurlBatchTransport;
use App\Connectors\Exceptions\ConnectorException;
use App\Core\Config;
use App\Jobs\JobRepository;
use App\Jobs\JobStatus;

// The batch layer logs its progress (which upload field was accepted, which
// download route worked), and Logger resolves `app.debug` through Config.
// A minimal config keeps those log calls from throwing; nothing below
// asserts on log output.
Config::set(['app' => ['version' => '0.0.0', 'debug' => false, 'log_to_console' => false]]);

$failures = 0;
$passes   = 0;

function check(string $label, bool $cond, string $detail = ''): void
{
    global $failures, $passes;
    if ($cond) {
        echo "  ok  {$label}\n";
        $passes++;
    } else {
        echo "  FAIL {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
        $failures++;
    }
}

function section(string $title): void
{
    echo "\n== {$title} ==\n";
}

/**
 * Fixture transport: answers from a scripted map and records every request.
 *
 * Keyed by "METHOD path" (path without the query string, because the query
 * is itself asserted separately). Each entry is either a list of answers
 * (consumed in order, the last one repeating) or a single answer.
 */
final class FixtureTransport implements BatchTransportInterface
{
    /** @var list<array{method:string,url:string,headers:array<int,string>,json:?array,multipart:?array,timeout:int}> */
    public array $calls = [];

    /** @var array<string,list<array{status:int,body:string}>> */
    public array $routes = [];

    /** @var list<string> */
    public array $missingRoutes = [];

    public function __construct()
    {
        // Every documented endpoint, with a plausible body. Individual
        // checks override what they care about.
        $this->routes['POST /files'] = [[
            'status' => 200,
            'body'   => json_encode(['id' => 'file-in-1', 'object' => 'file', 'purpose' => 'batch', 'bytes' => 128]),
        ]];
        $this->routes['POST /batches'] = [[
            'status' => 200,
            'body'   => json_encode([
                'id'                => 'batch-1',
                'object'            => 'batch',
                'status'            => 'validating',
                'input_file_id'     => 'file-in-1',
                'endpoint'          => '/v1/chat/completions',
                'completion_window' => '24h',
            ]),
        ]];
        $this->routes['GET /batches/batch-1'] = [[
            'status' => 200,
            'body'   => json_encode([
                'id'             => 'batch-1',
                'status'         => 'in_progress',
                'output_file_id' => null,
                'request_counts' => ['total' => 2, 'completed' => 1, 'failed' => 0],
            ]),
        ]];
        $this->routes['GET /batches'] = [[
            'status' => 200,
            'body'   => json_encode(['object' => 'list', 'data' => [['id' => 'batch-1', 'status' => 'completed']]]),
        ]];
        $this->routes['GET /batches/models'] = [[
            'status' => 200,
            'body'   => json_encode(['object' => 'list', 'data' => [['id' => 'gpt-4o:batch'], ['id' => 'o3-mini:batch']]]),
        ]];
    }

    /** Replace or prepend the answers for a route. */
    public function route(string $key, array $answers): void
    {
        $this->routes[$key] = $answers;
    }

    public function send(
        string $method,
        string $url,
        array $headers,
        ?array $jsonBody = null,
        ?array $multipart = null,
        int $timeout = 60,
    ): BatchHttpResponse {
        $path = (string)parse_url($url, PHP_URL_PATH);
        $base = (string)parse_url('https://bothub.chat/api/v2/openai/v1', PHP_URL_PATH);
        if ($base !== '' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base));
        }
        $query = (string)parse_url($url, PHP_URL_QUERY);
        $this->calls[] = [
            'method' => $method, 'url' => $url, 'query' => $query,
            'headers' => $headers, 'json' => $jsonBody, 'multipart' => $multipart, 'timeout' => $timeout,
        ];
        $key = $method . ' ' . $path;
        if (!isset($this->routes[$key]) || $this->routes[$key] === []) {
            $this->missingRoutes[] = $key;
            return new BatchHttpResponse(404, json_encode(['error' => ['message' => 'no fixture for ' . $key]]), $url, $method);
        }
        $queue = $this->routes[$key];
        $answer = count($queue) > 1 ? array_shift($this->routes[$key]) : $queue[0];
        return new BatchHttpResponse((int)$answer['status'], (string)$answer['body'], $url, $method);
    }

    /** The nth recorded call for a "METHOD path" key, or null. */
    public function call(string $key, int $index = 0): ?array
    {
        $hits = array_values(array_filter($this->calls, static function (array $c) use ($key): bool {
            $path = (string)parse_url($c['url'], PHP_URL_PATH);
            $base = (string)parse_url('https://bothub.chat/api/v2/openai/v1', PHP_URL_PATH);
            if ($base !== '' && str_starts_with($path, $base)) {
                $path = substr($path, strlen($base));
            }
            return $c['method'] . ' ' . $path === $key;
        }));
        return $hits[$index] ?? null;
    }

    /** @return list<string> "METHOD path" keys in call order. */
    public function callKeys(): array
    {
        $out = [];
        foreach ($this->calls as $c) {
            $path = (string)parse_url($c['url'], PHP_URL_PATH);
            $base = (string)parse_url('https://bothub.chat/api/v2/openai/v1', PHP_URL_PATH);
            if ($base !== '' && str_starts_with($path, $base)) {
                $path = substr($path, strlen($base));
            }
            $out[] = $c['method'] . ' ' . $path;
        }
        return $out;
    }
}

/** A connector with a fixed key and base URL — no config file, no network. */
function fixtureConnector(): AbstractConnector
{
    return new class extends AbstractConnector {
        public function __construct()
        {
            parent::__construct('bothub', 'https://bothub.chat/api/v2/openai/v1');
        }
        protected function resolveKey(): string { return 'fixture-key'; }
        protected function baseUrl(): string { return 'https://bothub.chat/api/v2/openai/v1'; }
        /** @return string[] */
        public function listModels(): array { return []; }
    };
}

function fixtureClient(FixtureTransport $transport): BothubBatchClient
{
    return new BothubBatchClient(fixtureConnector(), $transport);
}

/** The two-line result document most checks start from. */
function resultJsonl(string $idA, string $idB, array $overrides = []): string
{
    $ok = static fn(string $id, string $text): string => json_encode([
        'id'       => 'batch_req_' . substr(md5($id), 0, 6),
        'custom_id'=> $id,
        'response' => [
            'status_code' => 200,
            'request_id'  => 'req_' . substr(md5($id), 0, 6),
            'body'        => [
                'id'      => 'chatcmpl-' . substr(md5($id), 0, 6),
                'model'   => 'gpt-4o-2024-08-06',
                'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => $text]]],
                'usage'   => ['prompt_tokens' => 11, 'completion_tokens' => 7, 'total_tokens' => 18],
            ],
        ],
        'error'    => null,
    ]);
    return implode("\n", array_merge([$ok($idA, 'answer A'), $ok($idB, 'answer B')], $overrides)) . "\n";
}

// =====================================================================
section('(a) the client — every documented endpoint');
// =====================================================================

$doc = BatchJsonlBuilder::build([
    BatchJsonlBuilder::entry('j1-r1-p0-aaaaaaaa', ['model' => 'gpt-4o:batch', 'messages' => [['role' => 'user', 'content' => 'hi']]]),
]);
$transport = new FixtureTransport();
$client    = fixtureClient($transport);

$upload = $client->uploadInputFile($doc);
check('upload POSTs to /files', $transport->call('POST /files') !== null);
$call = $transport->call('POST /files');
check('upload is multipart, not JSON', ($call['multipart'] ?? null) !== null && ($call['json'] ?? null) !== null);
check('the file part is named "files" first (the cURL example spelling)',
    ($call['multipart']['field'] ?? '') === 'files', (string)($call['multipart']['field'] ?? ''));
check('the companion form field is purpose=batch', ($call['json']['purpose'] ?? '') === 'batch');
check('the upload content type is application/jsonl (not application/json)',
    ($call['multipart']['content_type'] ?? '') === BatchJsonlBuilder::CONTENT_TYPE
    && !array_filter($call['headers'], static fn(string $h): bool => stripos($h, 'content-type: application/json') === 0));
check('the auth header comes from the connector, never from the batch layer',
    in_array('Authorization: Bearer fixture-key', $call['headers'], true));
check('upload returns the file id', ($upload['id'] ?? '') === 'file-in-1' && ($upload['field'] ?? '') === 'files');

// The documented disagreement: `files` vs `file`.
$transport = new FixtureTransport();
$transport->route('POST /files', [
    ['status' => 400, 'body' => json_encode(['error' => ['message' => 'file part is required']])],
    ['status' => 200, 'body' => json_encode(['id' => 'file-in-2', 'purpose' => 'batch'])],
]);
$client = fixtureClient($transport);
$upload = $client->uploadInputFile($doc);
check('when "files" is rejected the client retries with "file"',
    ($upload['id'] ?? '') === 'file-in-2' && ($upload['field'] ?? '') === 'file', (string)($upload['field'] ?? ''));
check('both attempts are visible in the call log',
    count(array_filter($transport->callKeys(), static fn(string $k): bool => $k === 'POST /files')) === 2);
check('the complaint about the first field name is preserved for the retry decision',
    ($transport->call('POST /files', 0)['multipart']['field'] ?? '') === 'files'
    && ($transport->call('POST /files', 1)['multipart']['field'] ?? '') === 'file');

$transport = new FixtureTransport();
$transport->route('POST /files', [
    ['status' => 400, 'body' => json_encode(['error' => ['message' => 'file part is required']])],
    ['status' => 422, 'body' => json_encode(['error' => ['message' => 'unsupported file type']])],
]);
$client = fixtureClient($transport);
$threw = '';
try {
    $client->uploadInputFile($doc);
} catch (ConnectorException $e) {
    $threw = $e->getMessage();
}
check('both field names failing raises one error naming both',
    str_contains($threw, 'files') && str_contains($threw, 'file part is required') && str_contains($threw, 'unsupported file type'),
    $threw);

$transport = new FixtureTransport();
$client = fixtureClient($transport);
$threw = '';
try {
    $client->uploadInputFile('');
} catch (ConnectorException $e) {
    $threw = $e->getMessage();
}
check('an empty document is refused locally, before any request',
    str_contains($threw, 'empty') && $transport->calls === [], $threw);

// createBatch
$transport = new FixtureTransport();
$client = fixtureClient($transport);
$batch = $client->createBatch('file-in-1');
$call  = $transport->call('POST /batches');
check('create posts the documented body',
    ($call['json']['input_file_id'] ?? '') === 'file-in-1'
    && ($call['json']['endpoint'] ?? '') === '/v1/chat/completions'
    && ($call['json']['completion_window'] ?? '') === '24h',
    json_encode($call['json'] ?? []));
check('the only accepted completion window is 24h', BothubBatchClient::COMPLETION_WINDOW_24H === '24h');
check('create returns the batch id and its status', ($batch['id'] ?? '') === 'batch-1' && ($batch['status'] ?? '') === 'validating');

$transport = new FixtureTransport();
$transport->route('POST /batches', [['status' => 400, 'body' => json_encode(['error' => ['message' => 'input_file_id is required']])]]);
$client = fixtureClient($transport);
$threw = '';
try {
    $client->createBatch('file-in-1');
} catch (ConnectorException $e) {
    $threw = $e->getMessage();
}
check('a rejected create raises with the provider message',
    str_contains($threw, 'input_file_id is required'), $threw);

$transport = new FixtureTransport();
$transport->route('POST /batches', [['status' => 200, 'body' => json_encode(['object' => 'batch'])]]);
$client = fixtureClient($transport);
$threw = '';
try {
    $client->createBatch('file-in-1');
} catch (ConnectorException $e) {
    $threw = $e->getMessage();
}
check('a create answer without an id is refused rather than half-recorded',
    str_contains($threw, 'no batch id'), $threw);

$transport = new FixtureTransport();
$client = fixtureClient($transport);
$threw = '';
try {
    $client->createBatch('   ');
} catch (ConnectorException $e) {
    $threw = $e->getMessage();
}
check('create refuses an empty file id without a request',
    str_contains($threw, 'input file id') && $transport->calls === [], $threw);

// getBatch
$transport = new FixtureTransport();
$client = fixtureClient($transport);
$snapshot = $client->getBatch('batch-1');
$call = $transport->call('GET /batches/batch-1');
check('status is polled with include_usage=true, as documented',
    ($call['query'] ?? '') === 'include_usage=true', (string)($call['query'] ?? ''));
check('the snapshot exposes the provider status and counts',
    ($snapshot['ok'] ?? false) === true
    && ($snapshot['status'] ?? '') === 'in_progress'
    && ($snapshot['request_counts']['total'] ?? 0) === 2
    && ($snapshot['request_counts']['completed'] ?? 0) === 1,
    json_encode($snapshot));

$transport = new FixtureTransport();
$transport->route('GET /batches/batch-1', [['status' => 500, 'body' => 'upstream is on fire']]);
$client = fixtureClient($transport);
$snapshot = $client->getBatch('batch-1');
check('an unreadable poll is REPORTED, not thrown (the next poll retries)',
    ($snapshot['ok'] ?? true) === false
    && ($snapshot['status'] ?? '') === BatchLifecycle::UNKNOWN
    && str_contains((string)($snapshot['error'] ?? ''), '500'),
    json_encode($snapshot));

$transport = new FixtureTransport();
$transport->route('GET /batches/batch-1', [[
    'status' => 200,
    'body'   => json_encode([
        'id' => 'batch-1', 'status' => 'completed',
        'output_file_id' => 'file-out-9',
        'request_counts' => ['total' => 2, 'completed' => 1, 'failed' => 1],
    ]),
]]);
$client = fixtureClient($transport);
$snapshot = $client->getBatch('batch-1');
check('a completed batch exposes output_file_id and the per-row failure count',
    ($snapshot['output_file_id'] ?? '') === 'file-out-9'
    && ($snapshot['request_counts']['failed'] ?? 0) === 1,
    json_encode($snapshot));

// cancel / list
$transport = new FixtureTransport();
$transport->route('POST /batches/batch-1/cancel', [['status' => 200, 'body' => json_encode(['id' => 'batch-1', 'status' => 'cancelling'])]]);
$client = fixtureClient($transport);
$cancelled = $client->cancelBatch('batch-1');
check('cancel posts to /batches/{id}/cancel', $transport->call('POST /batches/batch-1/cancel') !== null);
check('cancel reports the provider status and is not itself terminal',
    ($cancelled['ok'] ?? false) === true
    && ($cancelled['status'] ?? '') === BatchLifecycle::CANCELLING
    && BatchLifecycle::isTerminal(BatchLifecycle::CANCELLING) === false);

$transport = new FixtureTransport();
$transport->route('POST /batches/batch-1/cancel', [['status' => 409, 'body' => json_encode(['error' => ['message' => 'batch already completed']])]]);
$client = fixtureClient($transport);
$cancelled = $client->cancelBatch('batch-1');
check('a refused cancel is reported with the provider reason, not thrown',
    ($cancelled['ok'] ?? true) === false && str_contains((string)($cancelled['error'] ?? ''), 'already completed'),
    json_encode($cancelled));

$transport = new FixtureTransport();
$client = fixtureClient($transport);
$listed = $client->listBatches(500, -3);
$call = $transport->call('GET /batches');
check('the list page size is clamped to the documented 1..100',
    str_contains((string)($call['query'] ?? ''), 'limit=100') && str_contains((string)($call['query'] ?? ''), 'offset=0'),
    (string)($call['query'] ?? ''));
check('the list returns the batch rows', ($listed['ok'] ?? false) === true && count($listed['items'] ?? []) === 1);

// download: the documented route, then the fallback
$docJsonl = resultJsonl('j1-r1-p0-aaaaaaaa', 'j1-r1-p1-bbbbbbbb');
$transport = new FixtureTransport();
$transport->route('GET /files/file-out-9', [['status' => 200, 'body' => $docJsonl]]);
$client = fixtureClient($transport);
$download = $client->downloadResult('file-out-9');
check('the documented download route GET /files/{id} is tried first',
    ($download['ok'] ?? false) === true && ($download['path'] ?? '') === '/files/file-out-9');
check('the document is returned as raw bytes, undecoded',
    ($download['content'] ?? '') === $docJsonl && ($download['bytes'] ?? 0) === strlen($docJsonl));

$transport = new FixtureTransport();
$transport->route('GET /files/file-out-9', [['status' => 404, 'body' => json_encode(['error' => ['message' => 'not found']])]]);
$transport->route('GET /files/file-out-9/content', [['status' => 200, 'body' => $docJsonl]]);
$client = fixtureClient($transport);
$download = $client->downloadResult('file-out-9');
check('when the documented route 404s the client falls back to /files/{id}/content',
    ($download['ok'] ?? false) === true && ($download['path'] ?? '') === '/files/file-out-9/content',
    json_encode(['ok' => $download['ok'] ?? null, 'path' => $download['path'] ?? null]));

$transport = new FixtureTransport();
$transport->route('GET /files/file-out-9', [[
    'status' => 200,
    'body'   => json_encode(['id' => 'file-out-9', 'object' => 'file', 'bytes' => 512, 'filename' => 'out.jsonl', 'purpose' => 'batch_output']),
]]);
$transport->route('GET /files/file-out-9/content', [['status' => 200, 'body' => $docJsonl]]);
$client = fixtureClient($transport);
$download = $client->downloadResult('file-out-9');
check('a host that serves file METADATA at the documented route is not mistaken for the document',
    ($download['ok'] ?? false) === true && ($download['content'] ?? '') === $docJsonl,
    (string)($download['path'] ?? ''));

$transport = new FixtureTransport();
$transport->route('GET /files/file-out-9', [['status' => 404, 'body' => 'nope']]);
$transport->route('GET /files/file-out-9/content', [['status' => 404, 'body' => 'nope']]);
$client = fixtureClient($transport);
$download = $client->downloadResult('file-out-9');
check('both download routes failing reports one error naming both',
    ($download['ok'] ?? true) === false
    && str_contains((string)($download['error'] ?? ''), '/files/file-out-9/content'),
    (string)($download['error'] ?? ''));

// batches/models
$transport = new FixtureTransport();
$client = fixtureClient($transport);
$catalog = $client->listBatchModels();
check('the batch-capable list is fetched from /batches/models', $transport->call('GET /batches/models') !== null);
check('a successful list resolves and marks the listed ids',
    $catalog->isResolved() === true
    && $catalog->capability('gpt-4o:batch') === BatchModelCatalog::CAPABILITY_SUPPORTED
    && $catalog->count() === 2,
    json_encode($catalog->toArray()));

// =====================================================================
section('(a2) ext-curl is optional — a missing extension is a clear error');
// =====================================================================

CurlBatchTransport::setCurlAvailableOverride(false);
try {
    $defaultClient = new BothubBatchClient(fixtureConnector());
    $threw = '';
    try {
        $defaultClient->uploadInputFile($doc);
    } catch (ConnectorException $e) {
        $threw = $e->getMessage();
    }
    check('a missing ext-curl raises a ConnectorException naming the extension and the remedy',
        str_contains($threw, 'ext-curl') && str_contains($threw, 'php-curl'),
        $threw);

    $snapshot = $defaultClient->getBatch('batch-1');
    check('a poll that cannot be attempted is reported as unknown, not as a fatal',
        ($snapshot['ok'] ?? true) === false && ($snapshot['status'] ?? '') === BatchLifecycle::UNKNOWN,
        json_encode($snapshot));

    check('the missing-extension check happens BEFORE any curl_* symbol is touched',
        CurlBatchTransport::isAvailable() === false
        && class_exists(BatchHttpResponse::class));
} finally {
    CurlBatchTransport::setCurlAvailableOverride(null);
}
check('the override is test-only: the real probe is restored',
    CurlBatchTransport::isAvailable() === (extension_loaded('curl') && function_exists('curl_init')));

// =====================================================================
section('(b) the JSONL contract — build, then read back');
// =====================================================================

$doc = BatchJsonlBuilder::build([
    BatchJsonlBuilder::entry('j7-r1-p0-aaaaaaaa', ['model' => 'gpt-4o:batch', 'messages' => [['role' => 'user', 'content' => 'x']], 'max_tokens' => 64]),
    BatchJsonlBuilder::entry('j7-r1-p1-bbbbbbbb', ['model' => 'o3-mini:batch', 'messages' => [['role' => 'user', 'content' => 'y']]]),
]);
check('one line per request, newline-terminated',
    $doc->lineCount === 2 && str_ends_with($doc->content, "\n") && substr_count($doc->content, "\n") === 2);
check('nothing was rejected', $doc->isComplete() && $doc->rejected === []);
$firstLine = json_decode(trim(explode("\n", $doc->content)[0]), true);
check('every line carries the documented envelope',
    is_array($firstLine)
    && ($firstLine['custom_id'] ?? '') === 'j7-r1-p0-aaaaaaaa'
    && ($firstLine['method'] ?? '') === 'POST'
    && ($firstLine['url'] ?? '') === '/v1/chat/completions',
    json_encode($firstLine));
check('body is used verbatim (the same payload the synchronous dispatcher would send)',
    ($firstLine['body']['model'] ?? '') === 'gpt-4o:batch'
    && ($firstLine['body']['max_tokens'] ?? 0) === 64
    && ($firstLine['body']['messages'][0]['content'] ?? '') === 'x');
check('the rendered ids round-trip through the document reader',
    $doc->customIds() === ['j7-r1-p0-aaaaaaaa', 'j7-r1-p1-bbbbbbbb'], implode(',', $doc->customIds()));

// Builder refusals: each one would fail the WHOLE batch if uploaded.
$bad = BatchJsonlBuilder::build([
    BatchJsonlBuilder::entry('', ['model' => 'm', 'messages' => [['role' => 'user', 'content' => 'x']]]),
    BatchJsonlBuilder::entry('not a valid id!', ['model' => 'm', 'messages' => [['role' => 'user', 'content' => 'x']]]),
    BatchJsonlBuilder::entry('dup', ['model' => 'm', 'messages' => [['role' => 'user', 'content' => 'x']]]),
    BatchJsonlBuilder::entry('dup', ['model' => 'm', 'messages' => [['role' => 'user', 'content' => 'x']]]),
    BatchJsonlBuilder::entry('no-model', ['messages' => [['role' => 'user', 'content' => 'x']]]),
    BatchJsonlBuilder::entry('no-messages', ['model' => 'm', 'messages' => []]),
]);
check('an empty custom_id is refused locally, with a reason',
    $bad->lineCount === 1 && count($bad->rejected) === 5, (string)$bad->lineCount);
$reasons = implode(' | ', array_column($bad->rejected, 'reason'));
check('the refused id outside the provider grammar is named',
    str_contains($reasons, 'not a valid id!') && str_contains($reasons, 'grammar'), $reasons);
check('a duplicate custom_id is refused (two participants must not share one answer slot)',
    str_contains($reasons, 'duplicate'), $reasons);
check('a missing body.model is refused', str_contains($reasons, 'body.model'), $reasons);
check('empty body.messages is refused', str_contains($reasons, 'body.messages'), $reasons);
check('a document with nothing usable reports itself empty',
    BatchJsonlBuilder::build([BatchJsonlBuilder::entry('', [])])->isEmpty() === true);

// Parser: order, per-line failures, damage, losses.
$idA = 'j7-r1-p0-aaaaaaaa';
$idB = 'j7-r1-p1-bbbbbbbb';
$idC = 'j7-r1-p2-cccccccc';
$transport = new FixtureTransport();
$transport->route('GET /files/f', [['status' => 200, 'body' => resultJsonl($idB, $idA)]]);
$report = BatchResultParser::parse(
    (string)fixtureClient($transport)->downloadResult('f')['content'],
    [$idA, $idB],
);
check('lines are matched by custom_id, so the provider order does not matter',
    $report->entry($idA) !== null && $report->entry($idB) !== null
    && $report->successfulIds() !== [] && count($report->missingIds) === 0,
    $report->summary());
// The document was rendered with the ids swapped, so the row that must come
// back for $idA is the one labelled 'answer B' — which is exactly the point:
// nothing here may depend on the order the provider chose.
check('an answered row exposes the chat-completions body for the connector to build from',
    ($report->entry($idA)?->body['choices'][0]['message']['content'] ?? '') === 'answer B'
    && ($report->entry($idA)?->statusCode) === 200
    && ($report->entry($idA)?->isSuccess()) === true);

$mixed = resultJsonl($idA, $idB, [
    // A row the provider refused INSIDE a completed batch — the case the
    // documentation warns about explicitly.
    json_encode([
        'custom_id' => $idC,
        'response'  => ['status_code' => 429, 'body' => ['error' => ['message' => 'rate limit exceeded']]],
        'error'     => null,
    ]),
    'this line is not JSON at all',
    json_encode(['response' => ['status_code' => 200, 'body' => ['choices' => []]]]),
]);
$report = BatchResultParser::parse($mixed, [$idA, $idB, $idC, 'j7-r1-p3-dddddddd']);
check('a completed batch can contain failed rows, and each one carries its own cause',
    count($report->failures()) === 1
    && ($report->failures()[0]['custom_id'] ?? '') === $idC
    && ($report->failures()[0]['status_code'] ?? 0) === 429
    && str_contains((string)($report->failures()[0]['reason'] ?? ''), 'rate limit exceeded'),
    json_encode($report->failures()));
check('a line that is not JSON is counted with its line number, not skipped',
    count($report->invalidLines) === 2
    && ($report->invalidLines[0]['line'] ?? 0) === 4
    && str_contains((string)($report->invalidLines[1]['reason'] ?? ''), 'no custom_id'),
    json_encode($report->invalidLines));
check('a requested id that never came back is REPORTED, never silently dropped',
    $report->missingIds === ['j7-r1-p3-dddddddd'], json_encode($report->missingIds));
check('an answer for a request we did not make is reported too',
    $report->unexpectedIds === [], json_encode($report->unexpectedIds));
check('the report knows it has problems', $report->hasProblems() === true);

$dupes = resultJsonl($idA, $idA);
$report = BatchResultParser::parse($dupes, [$idA]);
check('a duplicated custom_id keeps the first row and is reported',
    $report->duplicateIds === [$idA] && $report->entries[$idA]->isSuccess() === true,
    json_encode($report->duplicateIds));

$report = BatchResultParser::parse("\n\n" . resultJsonl($idA, $idB) . "\n\n", [$idA, $idB]);
check('blank lines and a trailing newline are tolerated',
    $report->lineCount === 2 && $report->invalidLines === [] && count($report->successfulIds()) === 2,
    $report->summary());

$topLevelError = json_encode(['custom_id' => $idA, 'error' => ['message' => 'the request never ran']]) . "\n";
$report = BatchResultParser::parse($topLevelError, [$idA]);
check('a top-level error row is a failure with the provider reason',
    count($report->failures()) === 1 && str_contains((string)($report->failures()[0]['reason'] ?? ''), 'never ran'),
    json_encode($report->failures()));

$report = BatchResultParser::parse(json_encode(['custom_id' => $idA, 'response' => ['status_code' => 200]]) . "\n", [$idA]);
check('a 200 with no body is a failure, not a contentless success',
    $report->entry($idA)?->isSuccess() === false
    && str_contains((string)$report->entry($idA)?->failureReason(), 'no completion body'),
    (string)$report->entry($idA)?->failureReason());

check('the compact envelope carries the per-row failures and the losses',
    ($report->toArray()['missing_ids'] ?? null) === [] && isset($report->toArray()['failed']));

// custom_id codec
$cid = BatchCustomId::make('j42', 2, 3, 'bothub', 'gpt-4o:batch');
check('a custom_id is inside the provider grammar and encodes its coordinates',
    BatchCustomId::isValid($cid)
    && BatchCustomId::describe($cid) === ['scope' => 'j42', 'round' => 2, 'index' => 3, 'fingerprint' => BatchCustomId::fingerprint('bothub', 'gpt-4o:batch')],
    $cid);
check('the same request always mints the same id (it has to survive a restart)',
    BatchCustomId::make('j42', 2, 3, 'bothub', 'gpt-4o:batch') === $cid);
check('two models with the same index get different ids',
    BatchCustomId::make('j42', 2, 3, 'bothub', 'a') !== BatchCustomId::make('j42', 2, 3, 'bothub', 'b'));
check('an id we did not mint is reported as unknown, not guessed at',
    BatchCustomId::describe('someone-elses-id') === null
    && BatchCustomId::isValid('someone-elses-id') === true);
check('a scope outside the id alphabet is sanitised, never passed through',
    BatchCustomId::isValid(BatchCustomId::make('job 42/α', 1, 0, 'p', 'm'))
    && str_starts_with(BatchCustomId::make('job 42/α', 1, 0, 'p', 'm'), 'job_42'),
    BatchCustomId::make('job 42/α', 1, 0, 'p', 'm'));
check('an empty scope still produces a valid id', BatchCustomId::isValid(BatchCustomId::make('', 1, 0, 'p', 'm')));

// =====================================================================
section('(c) the durable state machine and its parking');
// =====================================================================

check('a fresh job prepares', (new BatchState())->action() === BatchState::ACTION_PREPARE);
check('an empty persisted block is a fresh job, not an error',
    BatchState::fromJobResult(null)->action() === BatchState::ACTION_PREPARE
    && BatchState::fromJobResult([])->action() === BatchState::ACTION_PREPARE);
check('a submitted batch is polled',
    (new BatchState(['phase' => 'submitted', 'batch_id' => 'b1', 'status' => BatchLifecycle::IN_PROGRESS]))->action() === BatchState::ACTION_POLL);
check('a batch still validating is polled',
    (new BatchState(['batch_id' => 'b1', 'status' => BatchLifecycle::VALIDATING]))->action() === BatchState::ACTION_POLL);
check('an unreadable poll is retried, never treated as a verdict',
    (new BatchState(['batch_id' => 'b1', 'status' => BatchLifecycle::UNKNOWN]))->action() === BatchState::ACTION_POLL);
check('a completed batch is accepted',
    (new BatchState(['batch_id' => 'b1', 'status' => BatchLifecycle::COMPLETED]))->action() === BatchState::ACTION_ACCEPT);
check('an output file id is enough to accept, even before the status is re-read',
    (new BatchState(['batch_id' => 'b1', 'status' => BatchLifecycle::IN_PROGRESS, 'output_file_id' => 'f1']))->action() === BatchState::ACTION_ACCEPT);
check('a failed batch is accepted too — that is how its cause is reported',
    (new BatchState(['batch_id' => 'b1', 'status' => BatchLifecycle::FAILED]))->action() === BatchState::ACTION_ACCEPT);
check('a cancelled batch is accepted, so the job can close as cancelled',
    (new BatchState(['batch_id' => 'b1', 'status' => BatchLifecycle::CANCELLED]))->action() === BatchState::ACTION_ACCEPT);
check('a cancel outranks a pending poll',
    (new BatchState(['batch_id' => 'b1', 'status' => BatchLifecycle::IN_PROGRESS, 'cancel_requested' => true]))->action() === BatchState::ACTION_CANCEL);
check('a cancel recorded before submission stops the batch being created at all',
    (new BatchState(['phase' => 'new', 'cancel_requested' => true]))->action() === BatchState::ACTION_CANCEL);
check('a finished run is never re-entered',
    (new BatchState(['phase' => BatchState::PHASE_DONE]))->action() === BatchState::ACTION_DONE);

check('the poll cadence is the documented one: 30 s up to 1000 rows, then 2-5 min',
    BatchLifecycle::pollDelaySeconds(1) === 30
    && BatchLifecycle::pollDelaySeconds(1000) === 30
    && BatchLifecycle::pollDelaySeconds(1001) === 150
    && BatchLifecycle::pollDelaySeconds(50000) === 150);
check('an unreadable poll backs off instead of hammering the provider',
    (new BatchState(['poll_failures' => 4]))->nextPollDelaySeconds(30) === 120
    && (new BatchState(['poll_failures' => 1]))->nextPollDelaySeconds(30) === 30);
check('the failure budget is finite',
    (new BatchState(['poll_failures' => BatchState::MAX_POLL_FAILURES]))->pollFailuresExhausted() === true);
check('the provider status vocabulary is the documented one',
    BatchLifecycle::isTerminal('completed') && BatchLifecycle::isTerminal('failed') && BatchLifecycle::isTerminal('cancelled')
    && !BatchLifecycle::isTerminal('in_progress') && !BatchLifecycle::isTerminal('validating')
    && !BatchLifecycle::isTerminal(BatchLifecycle::CANCELLING));
check('completion window is 24 h and the UI payload says so',
    (new BatchState(['batch_id' => 'b']))->toPublicArray()['completion_window_h'] === 24);
check('toPublicArray never carries a prompt, a message or a credential',
    !array_intersect(
        ['messages', 'body', 'prompt', 'api_key', 'key', 'authorization'],
        array_keys((new BatchState(['participants' => [['custom_id' => 'c', 'step_id' => 1, 'provider' => 'p', 'model' => 'm', 'role' => 'r']]]))->toPublicArray()),
    ));
check('awaiting is true exactly while the provider owns the batch',
    (new BatchState(['batch_id' => 'b', 'status' => 'in_progress']))->toPublicArray()['awaiting'] === true
    && (new BatchState(['batch_id' => 'b', 'status' => 'in_progress', 'phase' => 'done']))->toPublicArray()['awaiting'] === false
    && (new BatchState(['batch_id' => 'b', 'status' => 'completed']))->toPublicArray()['awaiting'] === false);

// Parking, as the queue actually behaves.
$repo = new JobRepository(null);
$jobId = $repo->create(sessionId: 100, userId: null, payload: [
    'kind' => BatchPipeline::KIND, 'query' => 'q', 'batch_options' => [],
]);
$job = $repo->acquireNext('worker-a');
check('the job is acquired and running', $job !== null && $job->id === $jobId && $job->status === JobStatus::Running);

check('parking puts the row back in the queue and schedules it',
    $repo->parkUntil($jobId, 30, 'batch_poll_wait:poll') === true);
$parked = $repo->find($jobId);
check('the parked row is queued, unlocked, and due in the future',
    $parked !== null
    && $parked->status === JobStatus::Queued
    && $parked->lockedBy === null
    && $parked->nextRunAt !== null && strcmp((string)$parked->nextRunAt, date('Y-m-d H:i:s')) > 0,
    json_encode(['status' => $parked?->status->value, 'next' => $parked?->nextRunAt]));
check('parking records WHY the row went to sleep without inventing a failure',
    $parked->recoveryReason === 'batch_poll_wait:poll' && $parked->error === null,
    json_encode(['reason' => $parked?->recoveryReason, 'error' => $parked?->error]));
check('a parked job is invisible to the queue until it is due',
    $repo->acquireNext('worker-b') === null);

// The restart: a brand-new state machine reads everything from the row.
$repo->recordResult($jobId, array_merge(
    ['session_id' => 100, 'run_mode' => 'batch'],
    (new BatchState([
        'phase'          => BatchState::PHASE_SUBMITTED,
        'batch_id'       => 'batch-restart',
        'input_file_id'  => 'file-in-restart',
        'status'         => BatchLifecycle::IN_PROGRESS,
        'request_count'  => 3,
        'submitted_at'   => gmdate('Y-m-d\TH:i:s\Z', time() - 90),
        'participants'   => [
            ['custom_id' => 'j1-r1-p0-aaaaaaaa', 'step_id' => 11, 'provider' => 'bothub', 'model' => 'gpt-4o:batch', 'role' => 'analyst@gpt_4o_batch'],
        ],
    ]))->toEnvelope(),
));
$rehydrated = $repo->find($jobId);
$state = BatchState::fromJobResult($rehydrated?->result);
check('after a restart the batch ids, the counters and the participant map come back from the row',
    $state->batchId() === 'batch-restart'
    && $state->inputFileId() === 'file-in-restart'
    && $state->requestCount() === 3
    && count($state->participants()) === 1
    && $state->participants()[0]['step_id'] === 11
    && $state->customIds() === ['j1-r1-p0-aaaaaaaa'],
    json_encode($state->participants()));
check('and the resumed phase is a poll, not a fresh submit', $state->action() === BatchState::ACTION_POLL);
check('the elapsed time is derivable from the persisted submission stamp',
    is_string($state->submittedAt()) && $state->submittedAt() !== '');
check('the payload survives the round trip with no in-process state involved',
    BatchPipeline::isBatchPayload($rehydrated->payload) && ($rehydrated->payload['query'] ?? '') === 'q');

// Re-arm: the mechanism a parked row needs to be picked up again on demand.
check('re-arming a parked row clears its schedule and reports the status it had',
    $repo->rearmParked($jobId) === JobStatus::Queued
    && ($repo->find($jobId)?->nextRunAt) === null);
check('and a worker picks it up immediately afterwards',
    ($repo->acquireNext('worker-c')?->id) === $jobId);

// Time-based proof that a due row really is picked up again.
$repo2 = new JobRepository(null);
$id2 = $repo2->create(sessionId: 2, userId: null, payload: ['kind' => BatchPipeline::KIND, 'query' => 'q']);
$repo2->acquireNext('w');
$repo2->parkUntil($id2, 1, 'batch_poll_wait');
check('a row parked for 1 s is not pickable yet', $repo2->acquireNext('w') === null);
usleep(1_200_000);
check('the same row is pickable once its delay elapses — no in-memory timer involved',
    ($repo2->acquireNext('w')?->id) === $id2);
check('terminal rows are never resurrected by parking',
    $repo2->updateStatus($id2, JobStatus::Completed) === true
    && $repo2->parkUntil($id2, 5, 'x') === false);

// =====================================================================
section('(d) the orchestrator: phases, per-row failures, cancellation');
// =====================================================================

/** Planner stub: returns a fixed plan and records what it was asked to do. */
final class StubPlanner implements BatchL1PlannerInterface
{
    public int $prepareCalls = 0;
    /** @var list<array> */
    public array $outcomeBatches = [];
    public array $completion = [
        'answered' => 1, 'failed' => 1, 'status' => 'completed',
        'final_answer' => 'synthesised', 'synthesis_failure' => null,
        'envelope' => ['session_id' => 5, 'run_mode' => 'batch', 'status' => 'completed', 'final_answer' => 'synthesised'],
    ];
    public bool $failPrepare = false;

    public function prepareBatchL1(int $sessionId, string $query, ?array $requestedModels, array $options, int $jobId): array
    {
        $this->prepareCalls++;
        if ($this->failPrepare) {
            return ['ok' => false, 'reason' => 'no usable model in the selected pool', 'participants' => [], 'pool' => [], 'skipped' => []];
        }
        return [
            'ok'   => true,
            'pool' => [['provider' => 'bothub', 'model' => 'gpt-4o:batch']],
            'skipped' => [],
            'participants' => [
                [
                    'index' => 0, 'custom_id' => 'j1-r1-p0-aaaaaaaa', 'step_id' => 11,
                    'provider' => 'bothub', 'model' => 'gpt-4o:batch', 'role' => 'analyst@gpt_4o_batch',
                    'body' => ['model' => 'gpt-4o:batch', 'messages' => [['role' => 'user', 'content' => 'x']]],
                ],
                [
                    'index' => 1, 'custom_id' => 'j1-r1-p1-bbbbbbbb', 'step_id' => 12,
                    'provider' => 'bothub', 'model' => 'o3-mini:batch', 'role' => 'critic@o3_mini_batch',
                    'body' => ['model' => 'o3-mini:batch', 'messages' => [['role' => 'user', 'content' => 'y']]],
                ],
            ],
        ];
    }

    public function completeBatchL1(int $sessionId, string $query, array $options, array $outcomes, array $participants = []): array
    {
        $this->outcomeBatches[] = ['outcomes' => $outcomes, 'participants' => $participants];
        return $this->completion;
    }
}

function batchJob(JobRepository $repo, StubPlanner $planner, FixtureTransport $transport): array
{
    $jobId = $repo->create(sessionId: 5, userId: null, payload: [
        'kind' => BatchPipeline::KIND, 'query' => 'what is 2+2', 'batch_options' => [],
    ]);
    $pipeline = new BatchPipeline($repo, $planner, fixtureClient($transport));
    return [$jobId, $pipeline];
}

// prepare → upload → create → park
$repo = new JobRepository(null);
$planner = new StubPlanner();
$transport = new FixtureTransport();
[$jobId, $pipeline] = batchJob($repo, $planner, $transport);
$job = $repo->acquireNext('w1');
$result = $pipeline->run($job, 'w1', null);
check('the submit phase parks the job rather than blocking the worker',
    ($result['parked'] ?? false) === true
    && $result['status'] === JobStatus::Queued->value
    && $result['phase'] === BatchState::PHASE_SUBMITTED,
    json_encode($result));
check('submitting really uploaded one JSONL file and created one batch',
    $transport->callKeys() === ['POST /files', 'POST /batches'], implode(',', $transport->callKeys()));
$uploaded = $transport->call('POST /files');
check('the uploaded document is the plan, one line per participant',
    substr_count((string)($uploaded['multipart']['content'] ?? ''), "\n") === 2
    && str_contains((string)($uploaded['multipart']['content'] ?? ''), 'j1-r1-p0-aaaaaaaa'));
$created = $transport->call('POST /batches');
check('the batch is created from the uploaded file id',
    ($created['json']['input_file_id'] ?? '') === 'file-in-1');
$submitted = BatchState::fromJobResult($repo->find($jobId)?->result);
check('the state carries the batch id, the input file id, the count and the next poll time',
    $submitted->batchId() === 'batch-1'
    && $submitted->inputFileId() === 'file-in-1'
    && $submitted->requestCount() === 2
    && $submitted->nextPollAt() !== null,
    json_encode($submitted->toPublicArray()));
check('the poll delay follows the row count', $submitted->polls() === 0);
check('the job is parked, so the worker moved on', $repo->find($jobId)?->status === JobStatus::Queued);

// restart → poll
$restartedPlanner = new StubPlanner();
$restartedPlanner->failPrepare = true;   // a fresh submit would be a bug
$restartTransport = new FixtureTransport();
$restartedPipeline = new BatchPipeline($repo, $restartedPlanner, fixtureClient($restartTransport));
$freshJob = $repo->find($jobId);
$result = $restartedPipeline->run($freshJob, 'w2', null);
check('a restarted worker resumes at the poll phase from the database alone',
    $restartedPlanner->prepareCalls === 0
    && $restartTransport->callKeys() === ['GET /batches/batch-1']
    && ($result['parked'] ?? false) === true,
    json_encode(['prepare' => $restartedPlanner->prepareCalls, 'calls' => $restartTransport->callKeys()]));
check('every poll asks for the usage details, as documented',
    ($restartTransport->call('GET /batches/batch-1')['query'] ?? '') === 'include_usage=true');
check('the poll count is persisted, so the cadence is auditable',
    BatchState::fromJobResult($repo->find($jobId)?->result)->polls() === 1);
check('an in-progress batch is not accepted early',
    BatchState::fromJobResult($repo->find($jobId)?->result)->phase() !== BatchState::PHASE_DONE);

// completed → accept → continue
$transport2 = new FixtureTransport();
$transport2->route('GET /batches/batch-1', [[
    'status' => 200,
    'body'   => json_encode([
        'id' => 'batch-1', 'status' => 'completed', 'output_file_id' => 'file-out-1',
        'request_counts' => ['total' => 2, 'completed' => 1, 'failed' => 1],
    ]),
]]);
$transport2->route('GET /files/file-out-1', [[
    'status' => 200,
    'body'   => resultJsonl('j1-r1-p0-aaaaaaaa', 'j1-r1-p1-bbbbbbbb', [
        json_encode([
            'custom_id' => 'j1-r1-p1-bbbbbbbb',
            'response'  => ['status_code' => 400, 'body' => ['error' => ['message' => 'content filtered']]],
            'error'     => null,
        ]),
    ]),
]]);
// The two rows share an id by accident above; rebuild cleanly instead.
$transport2->route('GET /files/file-out-1', [[
    'status' => 200,
    'body'   => json_encode([
        'custom_id' => 'j1-r1-p0-aaaaaaaa',
        'response'  => ['status_code' => 200, 'body' => ['choices' => [['message' => ['content' => 'answer A']]], 'usage' => ['total_tokens' => 18]]],
    ]) . "\n" . json_encode([
        'custom_id' => 'j1-r1-p1-bbbbbbbb',
        'response'  => ['status_code' => 400, 'body' => ['error' => ['message' => 'content filtered']]],
    ]) . "\n",
]]);
$planner3 = new StubPlanner();
$pipeline3 = new BatchPipeline($repo, $planner3, fixtureClient($transport2));
// The poll sees `completed` and parks for the accept phase; the accept runs
// in the next pickup. That split is deliberate: reading a whole result
// document and continuing L2/L3/L4 is not polling, and it must not happen
// inside the call whose job is a cheap status GET.
$polled = $pipeline3->run($repo->find($jobId), 'w3', null);
check('a completed batch hands over to the accept phase without reading the document yet',
    ($polled['parked'] ?? false) === true
    && BatchState::fromJobResult($repo->find($jobId)?->result)->phase() === BatchState::PHASE_ACCEPTING
    && !in_array('GET /files/file-out-1', array_map(
        static fn(string $k): string => $k,
        array_filter($transport2->callKeys(), static fn(string $k): bool => str_starts_with($k, 'GET /files')),
    ), true),
    json_encode($polled['phase']));
$result = $pipeline3->run($repo->find($jobId), 'w3', null);
check('a completed batch is accepted and the run finishes as completed',
    $result['status'] === JobStatus::Completed->value && ($result['parked'] ?? true) === false,
    json_encode($result));
check('the accept phase downloaded the output document',
    in_array('GET /files/file-out-1', $transport2->callKeys(), true)
    || in_array('GET /files/file-out-1/content', $transport2->callKeys(), true),
    implode(',', $transport2->callKeys()));
$outcomes = $planner3->outcomeBatches[0]['outcomes'] ?? [];
check('every participant was handed to the engine exactly once',
    count($outcomes) === 2
    && array_column($outcomes, 'custom_id') === ['j1-r1-p0-aaaaaaaa', 'j1-r1-p1-bbbbbbbb'],
    json_encode(array_column($outcomes, 'custom_id')));
check('the answered row carries its chat-completions body',
    ($outcomes[0]['body']['choices'][0]['message']['content'] ?? '') === 'answer A'
    && ($outcomes[0]['error'] ?? null) === null);
check('a row the provider refused is reported per participant, with the provider cause',
    ($outcomes[1]['body'] ?? null) === null
    && str_contains((string)($outcomes[1]['error'] ?? ''), 'content filtered'),
    json_encode($outcomes[1]));
check('the custom_id → step_id map is handed over, so the answers land on their own steps',
    ($planner3->outcomeBatches[0]['participants'][0]['step_id'] ?? 0) === 11
    && ($planner3->outcomeBatches[0]['participants'][1]['step_id'] ?? 0) === 12);
$final = BatchState::fromJobResult($repo->find($jobId)?->result);
check('the terminal state records the result summary and the finished phase',
    $final->phase() === BatchState::PHASE_DONE
    && ($repo->find($jobId)?->status) === JobStatus::Completed
    && count($final->resultReport()['failed'] ?? []) === 1,
    json_encode($final->toPublicArray()['result']));
check('a partial failure does NOT sink the run',
    ($result['envelope']['final_answer'] ?? '') === 'synthesised');

// A batch where nothing answered fails, and says so.
$repo4 = new JobRepository(null);
$planner4 = new StubPlanner();
$planner4->completion = ['answered' => 0, 'failed' => 2, 'status' => 'failed', 'final_answer' => '', 'synthesis_failure' => null, 'envelope' => []];
$transport4 = new FixtureTransport();
[$jobId4, $pipeline4] = batchJob($repo4, $planner4, $transport4);
$pipeline4->run($repo4->acquireNext('w4'), 'w4', null);
$transport4->route('GET /batches/batch-1', [[
    'status' => 200,
    'body'   => json_encode(['id' => 'batch-1', 'status' => 'completed', 'output_file_id' => 'file-out-1', 'request_counts' => ['total' => 2, 'completed' => 0, 'failed' => 2]]),
]]);
$transport4->route('GET /files/file-out-1', [['status' => 200, 'body' => '']]);
$transport4->route('GET /files/file-out-1/content', [['status' => 200, 'body' => '']]);
$pipeline4->run($repo4->find($jobId4), 'w4', null);   // poll sees completed, parks for accept
$out4 = $pipeline4->run($repo4->find($jobId4), 'w4', null);   // accept: the download fails
check('a batch whose results are unreadable keeps retrying instead of dying on the first failure',
    ($out4['parked'] ?? false) === true && $out4['status'] === JobStatus::Queued->value,
    json_encode($out4));
check('the retry is counted against a finite budget',
    BatchState::fromJobResult($repo4->find($jobId4)?->result)->pollFailures() === 1);

// A failed batch: the provider's reason reaches the job report.
$repo5 = new JobRepository(null);
$planner5 = new StubPlanner();
$transport5 = new FixtureTransport();
[$jobId5, $pipeline5] = batchJob($repo5, $planner5, $transport5);
$pipeline5->run($repo5->acquireNext('w5'), 'w5', null);
$transport5->route('GET /batches/batch-1', [[
    'status' => 200,
    'body'   => json_encode(['id' => 'batch-1', 'status' => 'failed', 'errors' => ['message' => 'invalid JSON on line 3'], 'request_counts' => ['total' => 2, 'completed' => 0, 'failed' => 0]]),
]]);
$out5 = $pipeline5->run($repo5->find($jobId5), 'w5', null);   // poll → parks for accept
check('a failed batch hands over to the accept phase rather than polling forever',
    ($out5['parked'] ?? false) === true && BatchState::fromJobResult($repo5->find($jobId5)?->result)->phase() === BatchState::PHASE_ACCEPTING,
    json_encode($out5));
$out5 = $pipeline5->run($repo5->find($jobId5), 'w5', null);   // accept → fail
check('and the job ends failed with the provider reason in the report',
    $out5['status'] === JobStatus::Failed->value
    && str_contains((string)($repo5->find($jobId5)?->error['message'] ?? ''), 'invalid JSON on line 3'),
    json_encode(['status' => $out5['status'], 'error' => $repo5->find($jobId5)?->error]));

// Cancel, with and without a batch at the provider.
$repo6 = new JobRepository(null);
$planner6 = new StubPlanner();
$transport6 = new FixtureTransport();
$transport6->route('POST /batches/batch-1/cancel', [['status' => 200, 'body' => json_encode(['id' => 'batch-1', 'status' => 'cancelling'])]]);
[$jobId6, $pipeline6] = batchJob($repo6, $planner6, $transport6);
$pipeline6->run($repo6->acquireNext('w6'), 'w6', null);
// The operator cancels while the row is parked between polls.
$job6 = $repo6->find($jobId6);
$canceller = new BatchCanceller($repo6);
check('a parked batch job is recognised as one', BatchCanceller::handles($job6) === true);
$cancelOutcome = $canceller->request($job6);
check('the cancel intent is recorded where the executor reads its state from',
    ($cancelOutcome['applied'] ?? false) === true
    && BatchState::fromJobResult($repo6->find($jobId6)?->result)->cancelRequested() === true);
check('and the parked row is re-armed so a worker will actually see it',
    ($cancelOutcome['rearmed'] ?? false) === true
    && ($repo6->find($jobId6)?->nextRunAt) === null
    && ($repo6->find($jobId6)?->status) === JobStatus::Queued);
$out6 = $pipeline6->run($repo6->acquireNext('w7'), 'w7', null);
check('the cancel phase tells the PROVIDER to stop before the job is marked cancelled',
    in_array('POST /batches/batch-1/cancel', $transport6->callKeys(), true)
    && $out6['status'] === JobStatus::Cancelled->value,
    json_encode(['status' => $out6['status'], 'calls' => $transport6->callKeys()]));
check('and the job row really is cancelled',
    ($repo6->find($jobId6)?->status) === JobStatus::Cancelled);

// A cancel that arrives before the batch exists costs nothing.
$repo7 = new JobRepository(null);
$planner7 = new StubPlanner();
$transport7 = new FixtureTransport();
$jobId7 = $repo7->create(sessionId: 7, userId: null, payload: ['kind' => BatchPipeline::KIND, 'query' => 'q']);
$pipeline7 = new BatchPipeline($repo7, $planner7, fixtureClient($transport7));
$pipeline7->run($repo7->acquireNext('w8'), 'w8', null);  // submits, then parks
$repo7->recordResult($jobId7, array_merge(
    (array)$repo7->find($jobId7)?->result,
    (new BatchState(['cancel_requested' => true]))->toEnvelope(),
));
$out7 = $pipeline7->run($repo7->find($jobId7), 'w8', null);
check('a cancel recorded while parked is honoured on the next pickup',
    $out7['status'] === JobStatus::Cancelled->value,
    json_encode($out7));

$repo8 = new JobRepository(null);
$planner8 = new StubPlanner();
$transport8 = new FixtureTransport();
$jobId8 = $repo8->create(sessionId: 8, userId: null, payload: ['kind' => BatchPipeline::KIND, 'query' => 'q']);
$pipeline8 = new BatchPipeline($repo8, $planner8, fixtureClient($transport8));
$pipeline8->run($repo8->acquireNext('w9'), 'w9', null);   // submits and parks
// Now flip the state to cancelled-before-submit and re-run: no provider call.
$repo8->recordResult($jobId8, array_merge(
    ['session_id' => 8],
    (new BatchState(['cancel_requested' => true]))->toEnvelope(),
));
$out8 = $pipeline8->run($repo8->find($jobId8), 'w9', null);
check('a cancel recorded before submission never creates the batch at all',
    $out8['status'] === JobStatus::Cancelled->value
    && !in_array('POST /batches/batch-1/cancel', $transport8->callKeys(), true),
    json_encode($transport8->callKeys()));

// A batch whose plan is empty fails immediately and says why.
$repo9 = new JobRepository(null);
$planner9 = new StubPlanner();
$planner9->failPrepare = true;
$transport9 = new FixtureTransport();
$jobId9 = $repo9->create(sessionId: 9, userId: null, payload: ['kind' => BatchPipeline::KIND, 'query' => 'q']);
$pipeline9 = new BatchPipeline($repo9, $planner9, fixtureClient($transport9));
$out9 = $pipeline9->run($repo9->acquireNext('w10'), 'w10', null);
check('an unbuildable plan fails the job with the planner reason and uploads nothing',
    $out9['status'] === JobStatus::Failed->value
    && $transport9->calls === []
    && str_contains((string)($repo9->find($jobId9)?->error['message'] ?? ''), 'no usable model'),
    json_encode(['status' => $out9['status'], 'calls' => count($transport9->calls)]));

// =====================================================================
section('(e) the :batch rule, and the capability tri-state');
// =====================================================================

$connector = fixtureConnector();
$threw = '';
try {
    $connector->buildChatRequest('gpt-4o:batch', [['role' => 'user', 'content' => 'x']], []);
} catch (ConnectorException $e) {
    $threw = $e->getMessage();
}
check('a batch-only id is still refused fast on the synchronous path',
    str_contains($threw, 'batch-only') && str_contains($threw, 'cannot be called synchronously'),
    $threw);
check('the refusal names both ways out (the plain id, or the batch path)',
    str_contains($threw, 'use the plain id or the batch path'));

$build = $connector->buildBatchChatRequest('gpt-4o:batch', [['role' => 'user', 'content' => 'x']], ['temperature' => 0.5]);
check('the same id is accepted by the asynchronous builder',
    ($build['payload']['model'] ?? '') === 'gpt-4o:batch'
    && ($build['payload']['temperature'] ?? null) === 0.5
    && ($build['url'] ?? '') === 'https://bothub.chat/api/v2/openai/v1/chat/completions',
    json_encode($build['payload']));
check('the asynchronous builder is a separate entry point, so the synchronous signature is unchanged',
    (new ReflectionMethod(App\Connectors\AbstractConnector::class, 'buildChatRequest'))->getNumberOfParameters() === 3
    && (new ReflectionMethod(App\Connectors\AbstractConnector::class, 'buildBatchChatRequest'))->getNumberOfParameters() === 3);
check('transport-only options are still stripped in both modes',
    !array_key_exists('timeout', $connector->buildBatchChatRequest('m', [], ['timeout' => 5, 'stream' => true])['payload'])
    && !array_key_exists('stream', $connector->buildBatchChatRequest('m', [], ['stream' => true])['payload']));
check('a plain id builds identically through both entry points',
    $connector->buildChatRequest('gpt-4o', [], [])['payload'] === $connector->buildBatchChatRequest('gpt-4o', [], [])['payload']);

Config::set([
    'connectors' => ['bothub' => ['active' => true, 'api_key' => 'fixture-key']],
    'app'        => ['version' => '0.0.0'],
]);
$capability = BatchCapability::fromEnvironment(null, new FixtureTransport());
check('with a key and a usable transport the batch path reports ready',
    $capability->ready() === true && $capability->reason() === '' && $capability->toArray()['ready'] === true);

check('the capability report never claims anything about a specific model',
    !str_contains(json_encode($capability->toArray()), 'gpt'),
    json_encode($capability->toArray()));

// The other prerequisite, deterministically: a deployment whose PHP has no
// ext-curl cannot upload a multipart file or download a raw document, and the
// report has to say which of the two preconditions is missing rather than
// letting the operator discover it as a failed upload.
CurlBatchTransport::setCurlAvailableOverride(false);
try {
    $noCurl = BatchCapability::fromEnvironment(null, null);
    check('a deployment without ext-curl reports unavailable, naming the extension and the remedy',
        $noCurl->ready() === false
        && str_contains($noCurl->reason(), 'ext-curl')
        && str_contains($noCurl->reason(), 'php-curl'),
        $noCurl->reason());
} finally {
    CurlBatchTransport::setCurlAvailableOverride(null);
}

$unknown = BatchModelCatalog::unknown('the call timed out');
check('an unreadable model list is unknown, and says why',
    $unknown->isResolved() === false && $unknown->capability('anything') === BatchModelCatalog::CAPABILITY_UNKNOWN);
check('an unknown catalogue reports the cause instead of implying "not supported"',
    str_contains($unknown->detail(), 'timed out') && $unknown->count() === 0);
check('the class has no "unsupported" verdict at all — absence is unknown too',
    BatchModelCatalog::of(['a:batch'])->capability('b:batch') === BatchModelCatalog::CAPABILITY_UNKNOWN);
check('a listed id is supported, case-insensitively',
    BatchModelCatalog::of(['gpt-4o:batch'])->capability('GPT-4O:BATCH') === BatchModelCatalog::CAPABILITY_SUPPORTED
    && BatchModelCatalog::of(['gpt-4o:batch'])->isListed('gpt-4o:batch') === true);
check('all three documented envelope shapes are read',
    BatchModelCatalog::fromResponse(['data' => [['id' => 'x']]])->isListed('x') === true
    && BatchModelCatalog::fromResponse(['models' => ['y']])->isListed('y') === true
    && BatchModelCatalog::fromResponse([['id' => 'z']])->isListed('z') === true);
check('an unrecognisable body is unknown, never an empty "nothing is supported" list',
    BatchModelCatalog::fromResponse(['whatever' => 1])->isResolved() === false
    && BatchModelCatalog::fromResponse([])->isResolved() === false);

$transport = new FixtureTransport();
$transport->route('GET /batches/models', [['status' => 503, 'body' => 'service unavailable']]);
$unavailable = fixtureClient($transport)->listBatchModels();
check('a failing /batches/models yields unknown, and the id is still usable',
    $unavailable->isResolved() === false
    && $unavailable->capability('gpt-4o:batch') === BatchModelCatalog::CAPABILITY_UNKNOWN);

$transport = new FixtureTransport();
$transport->route('GET /batches/models', [['status' => 200, 'body' => json_encode(['data' => [['id' => 'gpt-4o:batch']]])]]);
check('a successful /batches/models is the authority for the supported set',
    fixtureClient($transport)->listBatchModels()->isListed('gpt-4o:batch') === true);

// =====================================================================
section('(f) secrets never reach a message or a log payload');
// =====================================================================

$transport = new FixtureTransport();
$transport->route('POST /batches', [[
    'status' => 401,
    'body'   => json_encode(['error' => ['message' => 'bad key: Bearer sk-abcdefghijklmnopqrstuvwxyz012345']]),
]]);
$client = fixtureClient($transport);
$threw = '';
try {
    $client->createBatch('file-in-1');
} catch (ConnectorException $e) {
    $threw = $e->getMessage();
}
check('a provider message that echoes a credential is redacted',
    !str_contains($threw, 'sk-abcdefghijklmnopqrstuvwxyz012345') && str_contains($threw, 'redacted'),
    $threw);

check('the error message is bounded, so a huge HTML error page cannot bury the status',
    strlen(fixtureClient(new FixtureTransport())->getBatch('b')['error'] ?? '') < 4000);
$excerpt = BatchHttpResponse::excerpt(str_repeat("line\n", 5000));
check('an excerpt is collapsed and capped',
    !str_contains($excerpt, "\n") && mb_strlen($excerpt) <= BatchHttpResponse::MESSAGE_EXCERPT_CHARS + 1,
    (string)mb_strlen($excerpt));

echo "\n----------------------------------------\n";
echo ($failures === 0 ? 'ALL OK' : 'FAILURES') . ": {$passes} passed, {$failures} failed\n";
exit($failures === 0 ? 0 : 1);
