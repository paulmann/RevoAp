<?php
/**
 * ModelBatchTest: offline coverage for `:batch` model identifiers.
 *
 * The operator-facing symptom this exists for: BotHub serves some models
 * through two surfaces, and spells the asynchronous one as a suffix on the
 * id — `gpt-4o:batch` (the upstream Batch API: upload JSONL → create →
 * poll → download). Treated as part of the model NAME, that suffix broke
 * three unrelated things at once:
 *
 *   1. version comparison — `gpt-4o:batch` tokenised to the line
 *      `gptobatch`, so it stopped being the same release as `gpt-4o`;
 *   2. synchronous dispatch — the id was sent to `/chat/completions`, where
 *      it is not a model the provider serves, and came back as a 404
 *      `MODEL_NOT_FOUND` that read like a typo in the operator's own
 *      configuration;
 *   3. the consensus pool — an id that can only be served asynchronously
 *      was imported and offered as a pool member, i.e. silently wired into
 *      L1.
 *
 * The checks below pin the four halves of the fix, each against the real
 * class rather than a paraphrase:
 *
 *   (a) the reading itself ({@see ModelId}), including the ids that must NOT
 *       be read as batch;
 *   (b) parity — one id with and without the suffix gives the same version,
 *       line and provider family ({@see ModelVersion},
 *       {@see ProviderFamily});
 *   (c) the fail-fast refusal in the connector, proven to happen BEFORE the
 *       API key is resolved and therefore before any request could be made;
 *   (d) the catalogue and the pool: such a row is kept, marked and reported
 *       instead of being dropped or activated.
 *
 * What is deliberately NOT covered: the asynchronous flow itself. The
 * Batch API (upload / create / poll / download) is out of scope, so nothing
 * here asserts anything about it.
 *
 * Runs without network, database or PHPUnit:
 *   php tests/ModelBatchTest.php
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

use App\Connectors\AbstractConnector;
use App\Connectors\BothubConnector;
use App\Connectors\Exceptions\ConnectorException;
use App\Connectors\Exceptions\ConnectorKeyNotFoundException;
use App\Connectors\ParallelChatDispatcher;
use App\Connectors\ParallelChatRequest;
use App\Core\Config;
use App\Models\ModelCatalogMerge;
use App\Models\ModelId;
use App\Models\ModelVersion;
use App\Models\ProviderFamily;

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

// =====================================================================
echo "== (a) the suffix is read as a MODE, not as part of the name ==\n";
// =====================================================================

// [id, isBatch, base, mode]
$reading = [
    // The documented shape, with and without a vendor prefix.
    ['gpt-4o:batch',                    true,  'gpt-4o',                   'batch'],
    ['openai/gpt-4o:batch',             true,  'openai/gpt-4o',            'batch'],
    ['bothub/deepseek/deepseek-v4:batch', true, 'bothub/deepseek/deepseek-v4', 'batch'],
    // Case and stray whitespace are spelling, not meaning.
    ['gpt-4o:BATCH',                    true,  'gpt-4o',                   'batch'],
    ['gpt-4o:Batch',                    true,  'gpt-4o',                   'batch'],
    ['  gpt-4o:batch  ',                true,  'gpt-4o',                   'batch'],
    ['gpt-4o :batch',                   true,  'gpt-4o',                   'batch'],
    // Plain synchronous ids are untouched.
    ['gpt-4o',                          false, 'gpt-4o',                   'sync'],
    ['openai/gpt-4o',                   false, 'openai/gpt-4o',            'sync'],
    ['',                                 false, '',                         'sync'],
    // `batch` that is NOT a trailing mode suffix must never be mistaken for
    // one: a word inside the name, a longer suffix, a bare token.
    ['batch-model',                     false, 'batch-model',              'sync'],
    ['openai/batch',                    false, 'openai/batch',             'sync'],
    ['gpt-4o:batch-preview',            false, 'gpt-4o:batch-preview',     'sync'],
    ['gpt-4o:batch2',                   false, 'gpt-4o:batch2',            'sync'],
    ['gpt-4o:batches',                  false, 'gpt-4o:batches',           'sync'],
    [':batch',                          false, ':batch',                   'sync'],
    ['batch',                           false, 'batch',                    'sync'],
];
foreach ($reading as [$id, $isBatch, $base, $mode]) {
    check(sprintf('isBatchId(%s) is %s', var_export($id, true), $isBatch ? 'true' : 'false'),
        ModelId::isBatchId($id) === $isBatch);
    check(sprintf('baseModelId(%s) → %s', var_export($id, true), $base),
        ModelId::baseModelId($id) === $base, ModelId::baseModelId($id));
    check(sprintf('modelMode(%s) → %s', var_export($id, true), $mode),
        ModelId::modelMode($id) === $mode, ModelId::modelMode($id));
}

check('the mode constants are the documented strings',
    ModelId::MODE_SYNC === 'sync' && ModelId::MODE_BATCH === 'batch'
    && ModelId::BATCH_SUFFIX === ':batch');
check('reading the base twice changes nothing (idempotent)',
    ModelId::baseModelId(ModelId::baseModelId('openai/gpt-4o:batch')) === 'openai/gpt-4o'
    && ModelId::baseModelId(ModelId::baseModelId('openai/gpt-4o')) === 'openai/gpt-4o');
check('a synchronous id has no pool rejection',
    ModelId::syncPoolRejection('openai/gpt-4o') === null);
check('a synchronous id has no call rejection',
    ModelId::syncCallRejection('openai/gpt-4o') === null);
$rejection = (string)ModelId::syncCallRejection('gpt-4o:batch');
check('the call rejection names the id, the real cause and both ways out',
    str_contains($rejection, 'gpt-4o:batch')
    && str_contains($rejection, 'batch-only identifier')
    && str_contains($rejection, 'asynchronous Batch API')
    && str_contains($rejection, 'cannot be called synchronously')
    && str_contains($rejection, 'use the plain id or the batch path'),
    $rejection);
$poolRejection = (string)ModelId::syncPoolRejection('gpt-4o:batch');
check('the pool rejection carries the model and the reason',
    str_contains($poolRejection, 'gpt-4o:batch')
    && str_contains($poolRejection, ModelId::BATCH_POOL_REASON),
    $poolRejection);

// =====================================================================
echo "\n== (b) versions and families ignore the suffix ==\n";
// =====================================================================

// The whole point of the parity: the same model with and without its mode
// suffix has to compare and group identically, or an actualization run
// stops seeing that the two are the same model.
$pairs = [
    ['gpt-4o:batch',                'gpt-4o'],
    ['openai/gpt-4o:batch',         'openai/gpt-4o'],
    ['gpt-4o:BATCH',                'gpt-4o'],
    ['openai/gpt-5.5:batch',        'openai/gpt-5.5'],
    ['anthropic/claude-sonnet-4.6:batch', 'anthropic/claude-sonnet-4.6'],
    ['gemini-2.5-flash-lite-preview-09-2025:batch', 'gemini-2.5-flash-lite-preview-09-2025'],
    ['deepseek-r1:batch',           'deepseek-r1'],
    ['~anthropic/claude-sonnet-latest:batch', '~anthropic/claude-sonnet-latest'],
];
foreach ($pairs as [$withSuffix, $plain]) {
    $a = ModelVersion::parse($withSuffix);
    $b = ModelVersion::parse($plain);
    check(sprintf('ModelVersion::parse(%s) equals parse(%s)', $withSuffix, $plain),
        $a === $b, json_encode(['with' => $a, 'without' => $b]));
    check(sprintf('ProviderFamily::of(%s) === of(%s)', $withSuffix, $plain),
        ProviderFamily::of($withSuffix) === ProviderFamily::of($plain),
        ProviderFamily::of($withSuffix) . ' vs ' . ProviderFamily::of($plain));
    check(sprintf('%s compares equal to %s', $withSuffix, $plain),
        ModelVersion::compare($withSuffix, $plain) === 0,
        (string)ModelVersion::compare($withSuffix, $plain));
}

// The concrete before/after, on the shape the version parser is documented
// with: `claude-sonnet-4.6` is line `claudesonnet`, version [4,6]. Left in
// the tokens, the suffix made the line `claudesonnetbatch` with the same
// tuple — a different "model" that is never comparable with the plain id,
// so an actualization run stopped versioning either of them.
check('the line of a suffixed id is the line of its base',
    ModelVersion::line('anthropic/claude-sonnet-4.6:batch') === 'claudesonnet'
    && ModelVersion::line('anthropic/claude-sonnet-4.6') === 'claudesonnet',
    ModelVersion::line('anthropic/claude-sonnet-4.6:batch'));
check('the suffix is not a token of the line any more',
    !str_contains(ModelVersion::line('gpt-4o:batch'), 'batch')
    && ModelVersion::line('gpt-4o:batch') === ModelVersion::line('gpt-4o'),
    ModelVersion::line('gpt-4o:batch'));
check('the version tuple of gpt-4o:batch is the tuple of gpt-4o',
    ModelVersion::tuple('gpt-4o:batch') === ModelVersion::tuple('gpt-4o'),
    json_encode(ModelVersion::tuple('gpt-4o:batch')));
check('the family of a suffixed id is the family of its base',
    ProviderFamily::of('bothub/gpt-4o:batch') === 'openai'
    && ProviderFamily::of('x/grok-4.20:batch') === 'xai'
    && ProviderFamily::of('z/qwen3-max:batch') === 'alibaba');

// The trap the parity creates: because both ids are now the same line and
// the same version, the "newest release on the line" rule can return the
// batch twin. That is why the pool-side callers filter candidates — the
// rule itself is expected to treat them as the same release.
$candidates = ['gpt-4o:batch', 'gpt-4o'];
check('the newest release on the line is found despite a batch twin',
    ModelVersion::newestOnSameLine($candidates, 'gpt-3.5-turbo') === null
    && ModelVersion::newestOnSameLine($candidates, 'gpt-4o') !== null,
    json_encode(ModelVersion::newestOnSameLine($candidates, 'gpt-4o')));
check('a batch twin is never reported as NEWER than its base',
    ModelVersion::compare('gpt-4o:batch', 'gpt-4o') === 0
    && ModelVersion::compare('gpt-4o:batch', 'gpt-4o:batch') === 0);

// =====================================================================
echo "\n== (c) a synchronous call refuses a batch id before anything else ==\n";
// =====================================================================

// The connector is a real one (BothubConnector), pointed at a throwaway
// root with NO api key and NO reachable base URL. That is the whole trick of
// this check: resolving the key would throw ConnectorKeyNotFoundException,
// so a batch refusal that names the mode proves the guard ran FIRST —
// nothing was resolved and nothing could have left the process.
$tmpRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'revoap-batch-' . bin2hex(random_bytes(4));
@mkdir($tmpRoot, 0o775, true);
Config::set([
    'connectors' => ['bothub' => ['active' => true, 'api_key' => '', 'base_url' => 'https://example.invalid/v1']],
], $tmpRoot);

$bothub = new BothubConnector();

$refused = null;
try {
    $bothub->buildChatRequest('gpt-4o:batch', [['role' => 'user', 'content' => 'hi']]);
} catch (\Throwable $e) {
    $refused = $e;
}
check('buildChatRequest refuses a batch id', $refused instanceof ConnectorException,
    $refused === null ? 'no exception' : get_class($refused));
check('the refusal is the batch rejection, not a key/transport error',
    $refused instanceof ConnectorException
    && !($refused instanceof ConnectorKeyNotFoundException)
    && str_contains($refused->getMessage(), 'batch-only identifier')
    && str_contains($refused->getMessage(), 'cannot be called synchronously'),
    $refused === null ? '' : get_class($refused) . ': ' . $refused->getMessage());
check('the refusal never claims a provider status (no 404 MODEL_NOT_FOUND)',
    $refused !== null && !str_contains($refused->getMessage(), '404')
    && !str_contains($refused->getMessage(), 'MODEL_NOT_FOUND'));

// chat() goes through buildChatRequest(), so it is refused identically.
$chatRefused = null;
try {
    $bothub->chat('gpt-4o:batch', [['role' => 'user', 'content' => 'hi']]);
} catch (\Throwable $e) {
    $chatRefused = $e;
}
check('chat() refuses it the same way', $chatRefused instanceof ConnectorException
    && str_contains($chatRefused->getMessage(), 'batch-only identifier'),
    $chatRefused === null ? 'no exception' : $chatRefused->getMessage());

// embed() is a synchronous endpoint too.
$embedRefused = null;
try {
    $bothub->embed('gpt-4o:batch', 'text');
} catch (\Throwable $e) {
    $embedRefused = $e;
}
check('embed() refuses it too', $embedRefused instanceof ConnectorException
    && str_contains($embedRefused->getMessage(), 'batch-only identifier'),
    $embedRefused === null ? 'no exception' : $embedRefused->getMessage());

// The plain id is NOT refused by the guard: it either builds a real
// request (when a key is resolvable) or fails later, on the missing key —
// never with the batch text.
$plainBuild = null;
$plainFailure = null;
try {
    $plainBuild = $bothub->buildChatRequest('gpt-4o', [['role' => 'user', 'content' => 'hi']]);
} catch (\Throwable $e) {
    $plainFailure = $e;
}
$plainUrl = is_array($plainBuild) ? (string)($plainBuild['url'] ?? '') : '';
check('the plain id passes the guard and builds a real chat request',
    ($plainBuild !== null
        && ($plainBuild['payload']['model'] ?? '') === 'gpt-4o'
        && str_contains($plainUrl, '/chat/completions'))
    || $plainFailure instanceof ConnectorKeyNotFoundException,
    $plainBuild !== null
        ? $plainUrl
        : ($plainFailure === null ? 'no result' : $plainFailure->getMessage()));
check('a plain id is not batch and never gets the batch text',
    ModelId::syncCallRejection('gpt-4o') === null);

// =====================================================================
echo "\n== (c2) one refused id does not take the L1 fan-out down with it ==\n";
// =====================================================================
//
// The pool is dispatched as ONE batch. A refusal that escaped as an
// exception would abort every model in it — which is a worse outcome than
// the failure it reports. The dispatcher therefore reports the refusal as
// that row's failure and keeps going.

/** A connector that does NOT override buildChatRequest, i.e. the real path. */
final class BatchGuardProbeConnector extends AbstractConnector
{
    public function __construct()
    {
        parent::__construct('batchguard', 'https://example.invalid/v1');
    }

    public function listModels(): array
    {
        return [];
    }
}

$probe = new BatchGuardProbeConnector();
$messages = [['role' => 'user', 'content' => 'hi']];
$refusedFirst = null;
$rows = (new ParallelChatDispatcher())->dispatch(
    [
        new ParallelChatRequest($probe, 'gpt-4o:batch', $messages, [], 'batch-a'),
        new ParallelChatRequest($probe, 'gpt-4o:batch', $messages, [], 'batch-b'),
    ],
    function (int $index, array $row) use (&$refusedFirst): void {
        if ($index === 0) { $refusedFirst = $row; }
    },
);
check('the batch is not aborted by the first refusal', count($rows) === 2, (string)count($rows));
check('each refused row is a failure of its own, tagged as it was asked',
    ($rows[0]['ok'] ?? true) === false && ($rows[0]['tag'] ?? '') === 'batch-a'
    && ($rows[1]['ok'] ?? true) === false && ($rows[1]['tag'] ?? '') === 'batch-b',
    json_encode($rows));
check('the row carries the mode explanation, not a provider error',
    str_contains((string)($rows[0]['error'] ?? ''), 'batch-only identifier')
    && !str_contains((string)($rows[0]['error'] ?? ''), 'MODEL_NOT_FOUND'),
    (string)($rows[0]['error'] ?? ''));
check('the per-row callback saw the refusal too, in order',
    is_array($refusedFirst) && ($refusedFirst['ok'] ?? true) === false
    && str_contains((string)($refusedFirst['error'] ?? ''), 'batch-only identifier'),
    json_encode($refusedFirst));

// =====================================================================
echo "\n== (d) catalogue rows and the pool mark it instead of hiding it ==\n";
// =====================================================================

$existing = [
    ['provider' => 'bothub', 'model' => 'gpt-4o', 'active' => true],
];
$merge = ModelCatalogMerge::mergeImported(
    $existing,
    [['id' => 'gpt-4o'], ['id' => 'gpt-4o:batch'], ['id' => 'claude-opus-5']],
    'bothub',
);

$byModel = [];
foreach ($merge['rows'] as $row) {
    $byModel[(string)$row['model']] = $row;
}
check('a batch-only id is catalogued, not silently dropped',
    isset($byModel['gpt-4o:batch']), implode(',', array_keys($byModel)));
check('the batch row is inactive and marked with the reason',
    ($byModel['gpt-4o:batch']['active'] ?? true) === false
    && ($byModel['gpt-4o:batch']['batch_only'] ?? false) === true
    && str_contains((string)($byModel['gpt-4o:batch']['sync_pool_reason'] ?? ''), ModelId::BATCH_POOL_REASON),
    json_encode($byModel['gpt-4o:batch'] ?? null));
check('the batch row is categorised apart from an ordinary import',
    ($byModel['gpt-4o:batch']['category'] ?? '') === 'batch'
    && ($byModel['claude-opus-5']['category'] ?? '') === 'imported');
check('the synchronous rows are not marked',
    !isset($byModel['claude-opus-5']['batch_only'])
    && !isset($byModel['gpt-4o']['batch_only']));
check('the import reports the batch rows separately',
    ($merge['batch'] ?? null) === ['bothub|gpt-4o:batch'],
    json_encode($merge['batch'] ?? null));
check('they are still counted as rows added to the catalogue',
    in_array('bothub|gpt-4o:batch', $merge['added'], true) && $merge['present'] === 1,
    json_encode(['added' => $merge['added'], 'present' => $merge['present']]));

// The operator's own Save: a batch row cannot be switched on by the form.
$submitted = [
    ['provider' => 'bothub', 'model' => 'gpt-4o:batch', 'active' => true],
    ['provider' => 'bothub', 'model' => 'gpt-4o', 'active' => true],
];
$saved = ModelCatalogMerge::mergeSubmitted($submitted, $existing);
$savedByModel = [];
foreach ($saved['rows'] as $row) {
    $savedByModel[(string)$row['model']] = $row;
}
check('a submitted batch row is forced inactive',
    ($savedByModel['gpt-4o:batch']['active'] ?? true) === false,
    json_encode($savedByModel['gpt-4o:batch'] ?? null));
check('and it keeps its marker and reason through the save',
    ($savedByModel['gpt-4o:batch']['batch_only'] ?? false) === true
    && str_contains((string)($savedByModel['gpt-4o:batch']['sync_pool_reason'] ?? ''), 'Batch API'));
check('the synchronous row beside it keeps the operator flag',
    ($savedByModel['gpt-4o']['active'] ?? false) === true);

// The persisted catalogue is what L1 reads, so a Save must not be able to
// put a batch id into the active set that `Config::getActiveModels()`
// returns: this walks the very rows the merge above decided to write.
$activeFromRows = [];
Config::set(['models' => $saved['rows']], $tmpRoot);
foreach ((array)Config::getActiveModels() as $row) {
    $activeFromRows[] = $row['provider'] . '|' . $row['model'];
}
check('the catalogue a save writes never puts a batch id in the active set L1 reads',
    !in_array('bothub|gpt-4o:batch', $activeFromRows, true)
    && in_array('bothub|gpt-4o', $activeFromRows, true),
    implode(', ', $activeFromRows));

echo "----------------------------------------\n";
echo ($failures === 0 ? 'ALL OK' : 'FAILURES') . ": {$passes} passed, {$failures} failed\n";
exit($failures === 0 ? 0 : 1);
