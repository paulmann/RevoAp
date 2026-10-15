<?php
/**
 * ModelsActualizeTest: offline coverage for the Models tab backend.
 *
 * Everything here runs without a browser, a network or a real database:
 * the live provider lists come from generated stub connectors, the
 * analyzer model answers from a canned string, the catalogue and the
 * presets live in a temporary project root, and the snapshot store runs on
 * an in-memory SQLite `app_config`.
 *
 * The five areas map onto the acceptance criteria:
 *
 *   (a) live-list parsing and catalogue reconciliation — what is new, what
 *       the provider no longer serves, and why an empty list must never
 *       mean "everything is stale";
 *   (b) pool selection — the analyzer answer is applied for
 *       mathematics/physics, while `default` is forced to span distinct
 *       provider families even when the model answers with one family;
 *   (c) write + rollback round-trip — state before is state after;
 *   (d) idempotent enqueue and the status contract, including the honest
 *       "no worker running" signal;
 *   (e) an analyzer failure writes nothing at all (all-or-nothing);
 *   (f) the *linked* models — the analyzer, the L4 synthesis model and the
 *       L3 arbiter — which live in flat config keys rather than in a preset
 *       or in the pool, follow the same conservative version rule
 *       ({@see App\Models\LinkedModels}), are snapshotted and rolled back
 *       with everything else, and are reported per role for the UI.
 *
 * Runs without PHPUnit:
 *   php tests/ModelsActualizeTest.php
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

// Env hygiene, exactly as SmokeTest does it: a developer's own admin token
// must not decide whether this suite passes.
unset($_ENV['REVOAP_ADMIN_TOKEN'], $_ENV['OPEN_CORUM_ADMIN_TOKEN']);
putenv('REVOAP_ADMIN_TOKEN');
putenv('OPEN_CORUM_ADMIN_TOKEN');

use App\Analyzer\RequestAnalyzer;
use App\Cache\ModelResponseCache;
use App\Connectors\ConnectorRegistry;
use App\Core\AdminAuth;
use App\Core\Config;
use App\Core\RuntimeConfig;
use App\DomainPresets\DomainPresetRepository;
use App\Http\Controllers\AnalyzerController;
use App\Http\Controllers\ModelsController;
use App\Jobs\Job;
use App\Jobs\JobAuditRepository;
use App\Jobs\JobRepository;
use App\Jobs\JobService;
use App\Jobs\JobStatus;
use App\Jobs\WorkerHealthService;
use App\Models\LinkedModels;
use App\Models\ModelCatalogDiff;
use App\Models\ModelId;
use App\Models\ModelImporter;
use App\Models\ModelsActualizer;
use App\Models\ModelsActualizeSettings;
use App\Models\ModelsSnapshotStore;
use App\Models\ModelVersion;
use App\Models\ProviderFamily;
use App\Settings\ConfigRepository;
use App\Storage\SessionRepository;

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

// ---------------------------------------------------------------------
// Fixture: a throwaway project root + generated stub connectors
// ---------------------------------------------------------------------
$tmp     = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'revoap-models-actualize-' . bin2hex(random_bytes(4));
$connDir = $tmp . DIRECTORY_SEPARATOR . 'Connectors';
$logDir  = $tmp . DIRECTORY_SEPARATOR . 'logs';
@mkdir($connDir, 0o775, true);
@mkdir($logDir, 0o775, true);

/** A full live catalogue, deliberately rich in provider families. */
const LIVE_CATALOG = [
    'openai/gpt-5.2',
    'openai/gpt-5.5',
    'openai/gpt-5.5-pro',
    'anthropic/claude-sonnet-4',
    'anthropic/claude-sonnet-4.6',
    'anthropic/claude-opus-4.5',
    'google/gemini-3.1-pro-preview',
    'deepseek/deepseek-v4-pro',
    'x-ai/grok-4.20',
    'qwen/qwen3.6-max-preview',
    'mistralai/mistral-large-3',
];

// The persisted catalogue before a run: two rows the provider still
// serves, one it has since retired, and one row whose provider never
// answered at all (which must NOT be called stale).
const PREVIOUS_CATALOG = [
    ['provider' => 'alpha',   'model' => 'openai/gpt-5.2',            'active' => true],
    ['provider' => 'alpha',   'model' => 'anthropic/claude-sonnet-4', 'active' => true],
    ['provider' => 'alpha',   'model' => 'alpha/retired-model',       'active' => false],
    ['provider' => 'silent',  'model' => 'silent/never-listed',       'active' => true],
];

/**
 * Stub provider. `$GLOBALS['stub_live_alpha']` is switched per scenario so
 * one class covers "catalogue changed" and "provider unreachable" (the
 * empty list a real connector returns on transport failure).
 */
$stubSource = <<<'PHP'
<?php
declare(strict_types=1);

namespace App\Connectors;

use App\Connectors\Exceptions\ConnectorException;
use App\Connectors\Exceptions\UnsupportedOperationException;

final class AlphaStubConnector implements ConnectorInterface
{
    public function chat(string $model, array $messages, array $options = []): ChatResponse
    {
        $GLOBALS['stub_chat_calls'][] = ['provider' => 'alpha', 'model' => $model, 'messages' => $messages];
        // `stub_content_queue` lets a scenario hand out a different answer
        // per call (the contract's one corrective retry needs exactly that);
        // when it is empty or absent, every call answers `stub_content`.
        $queue = $GLOBALS['stub_content_queue'] ?? null;
        $content = is_array($queue) && $queue !== []
            ? (string)array_shift($GLOBALS['stub_content_queue'])
            : (string)($GLOBALS['stub_content'] ?? '');
        $raw = ['model' => $model, 'choices' => [['message' => ['content' => $content], 'finish_reason' => 'stop']]];
        return new ChatResponse($content, $model, 'alpha', 5, 7, 12, 1.0, $raw);
    }

    public function embed(string $model, string $text): EmbedResponse
    {
        throw new UnsupportedOperationException('stub has no embeddings');
    }

    public function listModels(): array { return $GLOBALS['stub_live_alpha'] ?? []; }

    public function getProviderName(): string { return 'alpha'; }

    public function isAvailable(): bool { return true; }
}
PHP;
file_put_contents($connDir . DIRECTORY_SEPARATOR . 'AlphaStubConnector.php', $stubSource);

/** Same provider, but every analyzer call throws. */
$brokenSource = <<<'PHP'
<?php
declare(strict_types=1);

namespace App\Connectors;

use App\Connectors\Exceptions\ConnectorException;
use App\Connectors\Exceptions\UnsupportedOperationException;

final class AlphaStubConnector implements ConnectorInterface
{
    public function chat(string $model, array $messages, array $options = []): ChatResponse
    {
        $GLOBALS['stub_chat_calls'][] = ['provider' => 'alpha', 'model' => $model, 'messages' => $messages];
        throw new ConnectorException('analyzer provider is down');
    }

    public function embed(string $model, string $text): EmbedResponse
    {
        throw new UnsupportedOperationException('stub has no embeddings');
    }

    public function listModels(): array { return $GLOBALS['stub_live_alpha'] ?? []; }

    public function getProviderName(): string { return 'alpha'; }

    public function isAvailable(): bool { return true; }
}
PHP;

/** Write a project root whose config.php returns a real array. */
function writeConfigFile(string $root, array $config): void
{
    file_put_contents(
        $root . DIRECTORY_SEPARATOR . 'config.php',
        "<?php\ndeclare(strict_types=1);\nreturn " . var_export($config, true) . ";\n",
    );
}

/**
 * Install a fresh config on disk and in the static Config, and reset the
 * stub state. `runtime.json` is removed first so each scenario sees
 * exactly the config it declared — and so the writes under test start from
 * a known baseline.
 *
 * @param array<string,mixed> $overrides dot-notation overrides
 */
function configure(array $overrides = []): void
{
    global $tmp, $connDir, $stubSource;
    // Restore the working stub in case a previous scenario broke it.
    file_put_contents($connDir . DIRECTORY_SEPARATOR . 'AlphaStubConnector.php', $stubSource);

    $config = [
        'app'        => ['name' => 'RevoAp', 'version' => '1.8.49', 'debug' => true, 'timezone' => 'UTC'],
        'logging'    => ['path' => $tmp . DIRECTORY_SEPARATOR . 'logs'],
        'connectors' => ['alpha' => ['active' => true, 'api_key' => 'stub-key']],
        // All three linked models start on a release the live catalogue
        // already serves, so a scenario only sees a linked-model change when
        // it asks for one.
        'analyzer'   => ['enabled' => true, 'provider' => 'alpha', 'model' => 'openai/gpt-5.5'],
        // Catalogue rows carry the CONNECTOR name, not the vendor: the
        // vendor lives inside the model id (`alpha` serves
        // `openai/gpt-5.5`), which is how runtime.json and /api/connectors
        // already model it.
        'models'     => array_map(
            static fn(string $id): array => ['provider' => 'alpha', 'model' => $id, 'active' => true],
            LIVE_CATALOG,
        ),
        'pipeline'   => [
            'default_selected_models' => [
                ['provider' => 'alpha', 'model' => 'anthropic/claude-sonnet-4'],
            ],
            'synthesis_provider' => 'alpha',
            'synthesis_model'    => 'openai/gpt-5.5',
            'conflict_arbiter'   => [
                'enabled'  => true,
                'provider' => 'alpha',
                'model'    => 'anthropic/claude-sonnet-4.6',
            ],
        ],
    ];
    foreach ($overrides as $path => $value) {
        $segments = explode('.', $path);
        $cursor = &$config;
        foreach ($segments as $segment) {
            if (!isset($cursor[$segment]) || !is_array($cursor[$segment])) {
                $cursor[$segment] = [];
            }
            $cursor = &$cursor[$segment];
        }
        $cursor = $value;
    }
    writeConfigFile($tmp, $config);
    @unlink($tmp . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'runtime.json');
    RuntimeConfig::init($tmp);
    Config::set($config, $tmp);
    RuntimeConfig::init($tmp);

    $GLOBALS['stub_live_alpha']   = LIVE_CATALOG;
    $GLOBALS['stub_import']       = LIVE_CATALOG;
    $GLOBALS['stub_import_error'] = null;
    $GLOBALS['stub_content']      = '';
    $GLOBALS['stub_content_queue'] = [];
    $GLOBALS['stub_chat_calls']   = [];
}

/**
 * Canned analyzer answer for the next chat() call.
 *
 * `reason` and `arbiter` are filled in when a scenario does not name them:
 * the output contract ({@see ModelsActualizer::systemPrompt()} and its
 * parser) requires all three keys, and a scenario that omits one would be
 * testing the envelope by accident instead of the thing it is about. The
 * scenarios that ARE about a malformed answer set `$GLOBALS['stub_content']`
 * directly, so nothing here hides a contract violation from them.
 */
function stubAnswer(array $payload): void
{
    $models = is_array($payload['models'] ?? null) ? array_values($payload['models']) : [];
    if (!isset($payload['reason'])) {
        $payload['reason'] = 'fixture: the strongest model of each family in the catalogue.';
    }
    if (!isset($payload['arbiter']) && $models !== []) {
        $first = $models[0];
        $payload['arbiter'] = is_array($first)
            ? ['provider' => (string)($first['provider'] ?? ''), 'model' => (string)($first['model'] ?? '')]
            : ['provider' => 'alpha', 'model' => (string)$first];
    }
    $GLOBALS['stub_content'] = json_encode($payload, JSON_UNESCAPED_SLASHES);
}

/**
 * The import stage's fetcher.
 *
 * `$GLOBALS['stub_import']` is the catalogue the chosen provider answers
 * with (ids, or `{id,name}` rows); `$GLOBALS['stub_import_error']` is a
 * provider message that makes the fetch fail. One closure for both, so a
 * scenario can exercise "the import works, the live lists do not" — which is
 * a different failure from "the import itself failed".
 *
 * @return callable(string):array<string,mixed>
 */
function stubFetcher(): callable
{
    return static function (string $provider): array {
        $error = $GLOBALS['stub_import_error'] ?? null;
        if (is_string($error) && $error !== '') {
            return ['ok' => false, 'status' => 502, 'models' => [], 'error' => $error];
        }
        return ['ok' => true, 'status' => 200, 'models' => $GLOBALS['stub_import'] ?? []];
    };
}

/**
 * An actualizer wired the way the worker wires it: the stub importer for the
 * first stage, and — when a scenario passes one — the section's own
 * `models.actualize.analyzer` preference.
 */
function actualizerFor(
    ConnectorRegistry $connectors,
    DomainPresetRepository $presets,
    ModelsSnapshotStore $store,
    JobAuditRepository $audit,
    ?ModelResponseCache $cache = null,
    ?ModelImporter $importer = null,
    ?ModelsActualizeSettings $preferences = null,
): ModelsActualizer {
    return new ModelsActualizer(
        $connectors,
        $presets,
        $store,
        $audit,
        $cache,
        $importer ?? new ModelImporter(stubFetcher()),
        $preferences,
    );
}

/** @return array<int,array<string,mixed>> */
function stubCalls(): array
{
    return $GLOBALS['stub_chat_calls'] ?? [];
}

/**
 * Fresh service graph for one scenario: in-memory SQLite `app_config` for
 * the snapshot store, the generated connector directory, an in-memory job
 * queue and a preset repository seeded with the shipped presets.
 *
 * @return array<string,mixed>
 */
function services(bool $withStore = true): array
{
    global $connDir;
    $repo    = $withStore ? new ConfigRepository(new PDO('sqlite::memory:')) : null;
    $presets = new DomainPresetRepository(null);
    $presets->seedDefaultsIfEmpty();
    $audit   = new JobAuditRepository(null);
    $jobs    = new JobRepository(null);
    $jobs->setAuditRepository($audit);
    $store   = new ModelsSnapshotStore($repo);
    $preferences = new ModelsActualizeSettings($repo);
    return [
        'connectors' => new ConnectorRegistry($connDir),
        'presets'    => $presets,
        'store'      => $store,
        'audit'      => $audit,
        'jobs'       => $jobs,
        'preferences' => $preferences,
        'actualizer' => actualizerFor(
            new ConnectorRegistry($connDir),
            $presets,
            $store,
            $audit,
            null,
            null,
            $preferences,
        ),
    ];
}

/**
 * Unlock the admin gate for the endpoint-level checks.
 *
 * The Models endpoints are admin-gated (they rewrite presets), so a check
 * that calls a controller action directly has to present the same token the
 * browser carries in `oc_admin`.
 */
function unlockAdmin(): void
{
    global $tmp;
    $_ENV['REVOAP_ADMIN_TOKEN'] = 'test-admin-token';
    $_SERVER['HTTP_X_ADMIN_TOKEN'] = 'test-admin-token';
    AdminAuth::setRootDir($tmp);
}

/** A job row with a payload the actualizer understands. */
function jobFixture(JobRepository $jobs, array $selection = [], int $sessionId = 1): Job
{
    $id = $jobs->create(
        sessionId: $sessionId,
        userId: null,
        payload: [
            'kind'       => ModelsActualizer::KIND,
            'query'      => 'models actualize',
            'session_id' => $sessionId,
            'selection'  => $selection,
        ],
        priority: 10,
        maxAttempts: 1,
    );
    $job = $jobs->find($id);
    if ($job === null) {
        throw new RuntimeException('job fixture failed');
    }
    return $job;
}

/**
 * The linked models (analyzer / synthesis / arbiter) as they are
 * configured right now — the config-bound models an actualization also has
 * to keep current, and therefore part of "state before".
 *
 * @return array<string,string>
 */
function linkedState(): array
{
    return [
        'analyzer_provider'  => (string)Config::get('analyzer.provider', ''),
        'analyzer_model'     => (string)Config::get('analyzer.model', ''),
        'synthesis_provider' => (string)Config::get('pipeline.synthesis_provider', ''),
        'synthesis_model'    => (string)Config::get('pipeline.synthesis_model', ''),
        'arbiter_provider'   => (string)Config::get('pipeline.conflict_arbiter.provider', ''),
        'arbiter_model'      => (string)Config::get('pipeline.conflict_arbiter.model', ''),
    ];
}

/** Everything an actualization is allowed to change. */
function stateSignature(DomainPresetRepository $presets): array
{
    $presetState = [];
    foreach ($presets->all() as $preset) {
        $presetState[$preset->slug] = [
            'models'  => $preset->models,
            'arbiter' => $preset->arbiter,
        ];
    }
    return [
        'models'           => Config::get('models', []),
        'presets'          => $presetState,
        'default_selected' => Config::get('pipeline.default_selected_models', []),
        'linked'           => linkedState(),
    ];
}

/**
 * The planned or applied change for one linked role, or null.
 *
 * @param array<string,mixed> $plan {@see LinkedModels::plan()} or an envelope
 */
function linkedChange(array $plan, string $role): ?array
{
    foreach ((array)($plan['changes'] ?? $plan['linked'] ?? []) as $change) {
        if (is_array($change) && ($change['role'] ?? '') === $role) { return $change; }
    }
    return null;
}

/** All warnings of a plan/envelope as one searchable string. */
function warningText(array $envelope): string
{
    return implode(' | ', (array)($envelope['warnings'] ?? []));
}

/** @param array<int,array<string,mixed>> $rows */
function modelIds(array $rows): array
{
    return array_map(static fn(array $r): string => (string)$r['model'], $rows);
}

/** @return array<int,array<string,mixed>> */
function familiesOf(array $rows): array
{
    return array_values(array_unique(array_map(
        static fn(array $r): string => ProviderFamily::of((string)$r['model']),
        $rows,
    )));
}

// =====================================================================
echo "== (a) live-list parsing and catalogue reconciliation ==\n";
// =====================================================================

$normalised = ModelCatalogDiff::normalise(
    ['openai/gpt-5.5', 'openai/gpt-5.5', '', ['id' => 'anthropic/claude-sonnet-4.6'], ['nope' => 1], 42],
    'openrouter',
);
check('normalise deduplicates and drops unusable rows', count($normalised) === 2,
    'got ' . count($normalised));
check('normalise accepts both string ids and {id:…} maps',
    $normalised[0]['model'] === 'openai/gpt-5.5'
    && $normalised[1]['model'] === 'anthropic/claude-sonnet-4.6');
check('normalise lowercases the provider and keeps the id verbatim',
    $normalised[0]['provider'] === 'openrouter' && $normalised[0]['model'] === 'openai/gpt-5.5');

$diff = ModelCatalogDiff::compare(PREVIOUS_CATALOG, [
    'alpha' => [
        'openai/gpt-5.2',
        'openai/gpt-5.5',
        'anthropic/claude-sonnet-4',
        'anthropic/claude-sonnet-4.6',
    ],
]);
check('added lists exactly the new live models',
    modelIds($diff['added']) === ['openai/gpt-5.5', 'anthropic/claude-sonnet-4.6'],
    implode(', ', modelIds($diff['added'])));
check('a row the provider no longer serves is stale',
    modelIds($diff['stale']) === ['alpha/retired-model'], json_encode(modelIds($diff['stale'])));
check('a row whose provider never answered is NOT stale',
    !in_array('silent/never-listed', modelIds($diff['stale']), true), json_encode(modelIds($diff['stale'])));
check('unchanged carries the rows present on both sides', count($diff['unchanged']) === 2);
check('the counts describe both sides',
    $diff['live_count'] === 4 && $diff['catalog_count'] === 4, $diff['live_count'] . '/' . $diff['catalog_count']);

$diff2 = ModelCatalogDiff::compare(PREVIOUS_CATALOG, [
    'alpha' => ['openai/gpt-5.2', 'anthropic/claude-sonnet-4'],
]);
check('nothing new to add when the live list did not grow', $diff2['added'] === [],
    json_encode(modelIds($diff2['added'])));
check('the retired row is reported against the provider that answered',
    modelIds($diff2['stale']) === ['alpha/retired-model'], json_encode(modelIds($diff2['stale'])));

$diff3 = ModelCatalogDiff::compare(PREVIOUS_CATALOG, ['alpha' => []]);
check('an EMPTY live list marks nothing stale — an outage is not a deletion',
    $diff3['stale'] === [], json_encode($diff3['stale']));
check('an empty live list is reported as unavailable',
    $diff3['unavailable'] === ['alpha'], json_encode($diff3['unavailable']));
check('an outage adds nothing either',
    $diff3['added'] === [] && $diff3['unchanged'] === []);
check('an outage does not make a quiet provider\'s rows stale either',
    !in_array('silent/never-listed', modelIds($diff3['stale']), true));

// =====================================================================
echo "\n== (b1) provider-family heuristic ==\n";
// =====================================================================
$familyCases = [
    'anthropic/claude-sonnet-4.6'   => 'anthropic',
    'openai/gpt-5.5'                => 'openai',
    'gpt-5.5-pro'                   => 'openai',
    'chatgpt-4o-latest'             => 'openai',
    'google/gemini-3.1-pro-preview' => 'google',
    'gemma-3-27b-it'                => 'google',
    'qwen/qwen3.6-max-preview'      => 'alibaba',
    'deepseek/deepseek-v4-pro'      => 'deepseek',
    'x-ai/grok-4.20'                => 'xai',
    'meta-llama/llama-4-scout'      => 'meta',
    'mistralai/mistral-large-3'     => 'mistral',
    'z-ai/glm-4.6'                  => 'zhipu',
    'moonshotai/kimi-k2'            => 'moonshot',
    'cohere/command-r-plus'         => 'cohere',
    'nvidia/nemotron-4'             => 'nvidia',
    'microsoft/phi-4'               => 'microsoft',
    'amazon/nova-pro-v1'            => 'amazon',
    'perplexity/sonar-pro'          => 'perplexity',
    'some-vendor/model-x'           => 'some-vendor',
    'mystery-model'                 => 'unknown',
];
$familyMisses = [];
foreach ($familyCases as $model => $expected) {
    $got = ProviderFamily::of($model);
    if ($got !== $expected) { $familyMisses[] = "{$model}: {$got} != {$expected}"; }
}
check('family heuristic resolves every documented vendor', $familyMisses === [],
    implode(' | ', $familyMisses));
check('a real but unlisted vendor is its own family (not folded into unknown)',
    ProviderFamily::of('some-vendor/model-x') === 'some-vendor');
check('the alias marker does not hide the family',
    ProviderFamily::of('~anthropic/claude-sonnet-4.6') === 'anthropic');

// =====================================================================
echo "\n== (b2) assignment: the analyzer answer is applied ==\n";
// =====================================================================
configure();
$svc = services();
stubAnswer([
    'models'  => [
        ['provider' => 'alpha', 'model' => 'openai/gpt-5.5'],
        ['provider' => 'alpha', 'model' => 'deepseek/deepseek-v4-pro'],
    ],
    'arbiter' => ['provider' => 'alpha', 'model' => 'openai/gpt-5.5'],
]);
$envelope = $svc['actualizer']->run(jobFixture($svc['jobs']), 'test-worker');

check('a healthy run reports success', $envelope['stage'] === ModelsActualizer::STAGE_SUCCESS,
    (string)$envelope['stage'] . ' — ' . implode(' | ', $envelope['warnings']));
check('the analyzer was called once per preset', count(stubCalls()) === 3,
    count(stubCalls()) . ' call(s)');
check('every call used the configured analyzer provider/model',
    stubCalls()[0]['model'] === 'openai/gpt-5.5', (string)stubCalls()[0]['model']);

$mathPreset = $svc['presets']->findBySlug('mathematics');
check('the mathematics preset holds exactly the assigned models',
    modelIds($mathPreset->models) === ['openai/gpt-5.5', 'deepseek/deepseek-v4-pro'],
    implode(', ', modelIds($mathPreset->models)));
check('the mathematics arbiter comes from the answer',
    $mathPreset->arbiter['model'] === 'openai/gpt-5.5' && $mathPreset->arbiter['role'] === 'main');
check('mathematics keeps its linked skill', $mathPreset->skillSlug === 'mathematics-reasoning',
    (string)$mathPreset->skillSlug);
check('the assigned models are reported per preset, as connector/model',
    in_array('alpha/openai/gpt-5.5', $envelope['assigned']['mathematics'] ?? [], true),
    json_encode($envelope['assigned']));

$physicsPreset = $svc['presets']->findBySlug('physics');
check('physics is assigned from its own analyzer call',
    modelIds($physicsPreset->models) === ['openai/gpt-5.5', 'deepseek/deepseek-v4-pro']);

$catalogKeys = array_map(
    static fn(array $r): string => $r['provider'] . '|' . $r['model'],
    (array)Config::get('models', []),
);
check('a newly live model is appended to the catalogue',
    in_array('alpha|mistralai/mistral-large-3', $catalogKeys, true));
check('every pre-existing catalogue row survives the write',
    in_array('alpha|openai/gpt-5.2', $catalogKeys, true)
    && in_array('alpha|anthropic/claude-sonnet-4', $catalogKeys, true),
    implode(', ', array_slice($catalogKeys, 0, 5)));
check('assigned models are marked active',
    (bool)Config::get('models', [])[1]['active'] === true);

// =====================================================================
echo "\n== (b3) default is diversified across provider families ==\n";
// =====================================================================
configure();
$svc = services();
// The failure mode the rule exists for: the model answers with one family.
stubAnswer([
    'models' => [
        ['provider' => 'alpha', 'model' => 'openai/gpt-5.5'],
        ['provider' => 'alpha', 'model' => 'openai/gpt-5.2'],
        ['provider' => 'alpha', 'model' => 'openai/gpt-5.5-pro'],
    ],
    'arbiter' => ['provider' => 'alpha', 'model' => 'openai/gpt-5.5'],
]);
$envelope = $svc['actualizer']->run(jobFixture($svc['jobs']), 'test-worker');

$defaultPreset = $svc['presets']->findBySlug('default');
$defaultFamilies = familiesOf($defaultPreset->models);
check('the default pool no longer repeats one family',
    count($defaultFamilies) === count($defaultPreset->models), json_encode($defaultFamilies));
check('the pool spans at least three families',
    count($defaultFamilies) >= 3, json_encode($defaultFamilies));
check('the model\'s first pick survives diversification',
    $defaultPreset->models[0]['model'] === 'openai/gpt-5.5',
    json_encode(modelIds($defaultPreset->models)));
check('the duplicate drop is reported as a warning', (bool)array_filter(
    $envelope['warnings'],
    static fn(string $w): bool => str_contains($w, 'same provider famil'),
), implode(' | ', $envelope['warnings']));
check('the default arbiter stays the strongest general model',
    $defaultPreset->arbiter['model'] === 'openai/gpt-5.5');

echo "\n-- too few families in the live catalogue --\n";
$thinLive = [
    ['provider' => 'alpha', 'model' => 'openai/gpt-5.5'],
    ['provider' => 'alpha', 'model' => 'openai/gpt-5.2'],
];
$spread = $svc['actualizer']->diversify(
    [
        ['provider' => 'alpha', 'model' => 'openai/gpt-5.5'],
        ['provider' => 'alpha', 'model' => 'openai/gpt-5.2'],
    ],
    $thinLive,
    4,
);
check('with one family available the picks are kept, not silently dropped',
    count($spread['picks']) === 2, count($spread['picks']) . ' pick(s)');
check('the family shortage is reported', (bool)array_filter(
    $spread['warnings'],
    static fn(string $w): bool => str_contains($w, 'only spans 1 distinct provider family'),
), implode(' | ', $spread['warnings']));
check('diversify never invents a model that is not live',
    count(array_filter(
        $spread['picks'],
        static fn(array $r): bool => !in_array($r['model'], modelIds($thinLive), true),
    )) === 0);

echo "\n-- a hallucinated model is dropped, not written --\n";
configure();
$svc = services();
stubAnswer(['models' => [
    ['provider' => 'alpha', 'model' => 'openai/gpt-9-ultra'],   // does not exist
    ['provider' => 'alpha', 'model' => 'openai/gpt-5.5'],
]]);
$envelope = $svc['actualizer']->run(jobFixture($svc['jobs']), 'test-worker');
check('an unmatchable id never reaches a preset',
    !in_array('openai/gpt-9-ultra', modelIds($svc['presets']->findBySlug('mathematics')->models), true),
    json_encode(modelIds($svc['presets']->findBySlug('mathematics')->models)));

// =====================================================================
echo "\n== (c) the user pool is upgraded to the newest release ==\n";
// =====================================================================
configure();
$svc = services();
stubAnswer(['models' => [['provider' => 'alpha', 'model' => 'openai/gpt-5.5']]]);
$envelope = $svc['actualizer']->run(
    jobFixture($svc['jobs'], [['provider' => 'alpha', 'model' => 'anthropic/claude-sonnet-4']]),
    'test-worker',
);
check('the pool moved to the newest release on the same line',
    $envelope['selection'] === [['provider' => 'alpha', 'model' => 'anthropic/claude-sonnet-4.6']],
    json_encode($envelope['selection']));
check('the upgrade is reported',
    (bool)array_filter($envelope['warnings'], static fn(string $w): bool => str_contains($w, 'claude-sonnet-4 → anthropic/claude-sonnet-4.6')),
    implode(' | ', $envelope['warnings']));
check('a different line is never substituted for a newer one',
    ModelVersion::newestOnSameLine(['openai/gpt-5.5-pro', 'openai/gpt-4o'], 'openai/gpt-5.5') === null);
check('a release alias with no numeric version is its own line, so r2 never replaces r1',
    ModelVersion::line('deepseek/deepseek-r1') === 'deepseekr1'
    && ModelVersion::newestOnSameLine(['deepseek/deepseek-r1', 'deepseek/deepseek-r2'], 'deepseek/deepseek-r1') === 'deepseek/deepseek-r1');
check('the recorded server-side pool matches what the run produced',
    Config::get('pipeline.default_selected_models', []) === [['provider' => 'alpha', 'model' => 'anthropic/claude-sonnet-4.6']],
    json_encode(Config::get('pipeline.default_selected_models', [])));

echo "\n-- an empty pool is seeded, not left undefined --\n";
configure(['pipeline.default_selected_models' => []]);
$svc = services();
stubAnswer(['models' => [['provider' => 'alpha', 'model' => 'google/gemini-3.1-pro-preview']]]);
$envelope = $svc['actualizer']->run(jobFixture($svc['jobs'], []), 'test-worker');
check('an empty pool is seeded from the default preset',
    count($envelope['selection']) > 0, json_encode($envelope['selection']));
check('the seeding is disclosed',
    (bool)array_filter($envelope['warnings'], static fn(string $w): bool => str_contains($w, 'seeded from the "default" consensus pool')),
    implode(' | ', $envelope['warnings']));

// =====================================================================
echo "\n== (c2) write + rollback restores the previous state exactly ==\n";
// =====================================================================
configure();
$svc = services();
$before = stateSignature($svc['presets']);
stubAnswer(['models' => [
    ['provider' => 'alpha', 'model' => 'openai/gpt-5.5'],
    ['provider' => 'alpha', 'model' => 'google/gemini-3.1-pro-preview'],
]]);
$runEnvelope = $svc['actualizer']->run(
    jobFixture($svc['jobs'], [['provider' => 'alpha', 'model' => 'anthropic/claude-sonnet-4']]),
    'test-worker',
);
check('the run succeeded before being rolled back',
    $runEnvelope['stage'] === ModelsActualizer::STAGE_SUCCESS,
    (string)$runEnvelope['stage'] . ' — ' . implode(' | ', $runEnvelope['warnings']));
check('the run really changed the state', stateSignature($svc['presets']) !== $before);
check('a snapshot was stored',
    ($svc['store']->loadSnapshot()['taken_at'] ?? null) !== null);
$rollback = $svc['actualizer']->rollback();
check('rollback reports success', $rollback['ok'] === true, $rollback['message']);
$after = stateSignature($svc['presets']);
check('the catalogue is identical to the pre-run state', $after['models'] === $before['models'],
    json_encode(array_slice($after['models'], 0, 3)) . ' vs ' . json_encode(array_slice($before['models'], 0, 3)));
check('every preset is identical to the pre-run state', $after['presets'] === $before['presets']);
check('the recorded pool is identical to the pre-run state',
    $after['default_selected'] === $before['default_selected']);
check('rollback returns the pool the UI has to re-apply',
    $rollback['selection'] === [['provider' => 'alpha', 'model' => 'anthropic/claude-sonnet-4']],
    json_encode($rollback['selection']));
check('the status record reports the rollback',
    ($svc['store']->loadStatus()['stage'] ?? '') === 'rolled_back');

// =====================================================================
echo "\n== (c3) a failed write reports what ACTUALLY happened to the snapshot ==\n";
// =====================================================================
//
// The production contradiction this covers: one and the same card said
// "The snapshot could NOT be restored: Call to undefined method …" (the
// warning) and "Actualization failed while writing; the previous state was
// restored." (the summary). The summary was a constant, so the operator
// had no way to tell a clean rollback from a half-written configuration.
//
// Both branches are driven here through a real run:
//
//   * a write that cannot be ENCODED — the closest deterministic stand-in
//     for "the write itself failed" — while the restore, which writes the
//     pre-run rows, succeeds;
//   * a write target that cannot be replaced (a directory where the file
//     belongs), which the restore hits too, exactly as in the incident.

// -- branch 1: the write fails, the restore succeeds ------------------
configure();
$svc = services();
// A provider catalogue entry with a malformed byte: the run appends it to
// the catalogue, and json_encode() refuses the whole payload.
$GLOBALS['stub_live_alpha'] = array_merge(LIVE_CATALOG, ["openai/gpt-\xB1-broken"]);
stubAnswer(['models' => [['provider' => 'alpha', 'model' => 'openai/gpt-5.5']]]);
$restoredRun = $svc['actualizer']->run(jobFixture($svc['jobs']), 'test-worker');
$restoredMessage = (string)($restoredRun['message'] ?? '');
$restoredWarnings = implode(' | ', array_map('strval', (array)($restoredRun['warnings'] ?? [])));
check('a write that cannot be encoded ends in problems, not success',
    ($restoredRun['stage'] ?? '') === ModelsActualizer::STAGE_PROBLEMS, (string)($restoredRun['stage'] ?? ''));
check('it does not claim success', ($restoredRun['ok'] ?? true) === false);
check('the summary says the previous state was restored',
    str_contains($restoredMessage, 'the previous state was restored'), $restoredMessage);
check('the summary does not claim an unrestored configuration',
    !str_contains($restoredMessage, 'could NOT be restored'), $restoredMessage);
check('the warning says the same, in the same words',
    str_contains($restoredWarnings, 'The snapshot was restored, so the previous state is back.'),
    $restoredWarnings);
check('the warning never contradicts the summary',
    !str_contains($restoredWarnings, 'could NOT be restored'), $restoredWarnings);
check('the error detail records that the restore worked',
    ($restoredRun['error']['restore_ok'] ?? null) === true, json_encode($restoredRun['error'] ?? null));
check('no restore failure is invented', !isset($restoredRun['error']['restore_error']));
check('the failure names the file that could not be written',
    ($restoredRun['error']['target'] ?? '') === RuntimeConfig::FILE_RELATIVE,
    json_encode($restoredRun['error'] ?? null));
check('the run says which stage it died in',
    ($restoredRun['failed_stage'] ?? '') === ModelsActualizer::STAGE_ASSIGNING,
    (string)($restoredRun['failed_stage'] ?? ''));

// -- branch 2: neither the write nor the restore can land -------------
configure();
$svc = services();
// `runtime.json` is a directory, so the atomic rename can never succeed —
// for the run AND for the rollback that follows it. This is the incident's
// shape: the failure text and the recovery text have to agree.
$blocked = $tmp . DIRECTORY_SEPARATOR . RuntimeConfig::FILE_RELATIVE;
@unlink($blocked);
@mkdir($blocked, 0o775, true);
stubAnswer(['models' => [['provider' => 'alpha', 'model' => 'openai/gpt-5.5']]]);
$unrestoredRun = $svc['actualizer']->run(jobFixture($svc['jobs']), 'test-worker');
$unrestoredMessage = (string)($unrestoredRun['message'] ?? '');
$unrestoredWarnings = implode(' | ', array_map('strval', (array)($unrestoredRun['warnings'] ?? [])));
@rmdir($blocked);

check('a write against an unreplaceable target ends in problems',
    ($unrestoredRun['stage'] ?? '') === ModelsActualizer::STAGE_PROBLEMS, (string)($unrestoredRun['stage'] ?? ''));
check('the summary admits the previous state could NOT be restored',
    str_contains($unrestoredMessage, 'could NOT be restored'), $unrestoredMessage);
check('the summary does not claim the restore succeeded',
    !str_contains($unrestoredMessage, 'the previous state was restored'), $unrestoredMessage);
check('the warning admits it too',
    str_contains($unrestoredWarnings, 'The snapshot could NOT be restored'),
    $unrestoredWarnings);
check('the warning does not claim a restore that did not happen',
    !str_contains($unrestoredWarnings, 'The snapshot was restored'), $unrestoredWarnings);
check('the summary names the file the operator has to check',
    str_contains($unrestoredMessage, RuntimeConfig::FILE_RELATIVE), $unrestoredMessage);
check('the summary says the configuration may be inconsistent',
    str_contains($unrestoredMessage, 'may now be inconsistent'), $unrestoredMessage);
check('the error detail records the failed restore',
    ($unrestoredRun['error']['restore_ok'] ?? null) === false, json_encode($unrestoredRun['error'] ?? null));
check('the error detail names the restore error',
    trim((string)($unrestoredRun['error']['restore_error'] ?? '')) !== '',
    json_encode($unrestoredRun['error'] ?? null));
check('the restore error is the write failure the operator would see',
    str_contains((string)($unrestoredRun['error']['restore_error'] ?? ''), 'temporary file'),
    (string)($unrestoredRun['error']['restore_error'] ?? ''));
check('the failing stage is still reported',
    ($unrestoredRun['failed_stage'] ?? '') === ModelsActualizer::STAGE_ASSIGNING,
    (string)($unrestoredRun['failed_stage'] ?? ''));

// -- the stage a run died in, on the other abort paths ----------------
configure();
$svc = services();
// No provider answered: the run dies inside stage 1, so that is the row
// the card has to mark — not a blanket "every stage finished".
$GLOBALS['stub_live_alpha'] = [];
stubAnswer(['models' => [['provider' => 'alpha', 'model' => 'openai/gpt-5.5']]]);
$emptyRun = $svc['actualizer']->run(jobFixture($svc['jobs']), 'test-worker');
check('a run that never saw a live list reports problems',
    ($emptyRun['stage'] ?? '') === ModelsActualizer::STAGE_PROBLEMS, (string)($emptyRun['stage'] ?? ''));
check('and names stage 1 as the one it died in',
    ($emptyRun['failed_stage'] ?? '') === ModelsActualizer::STAGE_ANALYSING,
    (string)($emptyRun['failed_stage'] ?? ''));
check('a success carries no failing stage',
    !array_key_exists('failed_stage', $runEnvelope) || $runEnvelope['failed_stage'] === null,
    json_encode($runEnvelope['failed_stage'] ?? null));

echo "\n-- without a snapshot store, nothing is written --\n";
// The analyzer is pointed at a model the live catalogue supersedes, so the
// run *would* touch a linked model too: "nothing was written" has to cover
// the linked keys and not just the presets.
configure(['analyzer.model' => 'openai/gpt-5.2']);
$svc = services(false);
$GLOBALS['stub_live_alpha'] = array_merge(LIVE_CATALOG, ['openai/gpt-5.6']);
$before = stateSignature($svc['presets']);
stubAnswer(['models' => [['provider' => 'alpha', 'model' => 'openai/gpt-5.5']]]);
$envelope = $svc['actualizer']->run(jobFixture($svc['jobs']), 'test-worker');
check('the run reports problems', $envelope['stage'] === ModelsActualizer::STAGE_PROBLEMS,
    (string)$envelope['stage']);
check('no preset was touched', stateSignature($svc['presets']) === $before);
check('no linked model was touched either', linkedState() === $before['linked'],
    json_encode(linkedState()));
check('no linked model is reported as applied', ($envelope['linked'] ?? null) === [],
    json_encode($envelope['linked'] ?? null));
check('the missing rollback path is explained', (bool)array_filter(
    $envelope['warnings'],
    static fn(string $w): bool => str_contains($w, 'snapshot could not be stored'),
), implode(' | ', $envelope['warnings']));

// =====================================================================
echo "\n== (e) all-or-nothing when the analyzer fails ==\n";
// =====================================================================
configure();
$svc = services();
$before = stateSignature($svc['presets']);
file_put_contents($connDir . DIRECTORY_SEPARATOR . 'AlphaStubConnector.php', $brokenSource);
$svc['actualizer'] = actualizerFor(new ConnectorRegistry($connDir), $svc['presets'], $svc['store'], $svc['audit']);
$envelope = $svc['actualizer']->run(jobFixture($svc['jobs']), 'test-worker');
check('an analyzer failure ends in problems', $envelope['stage'] === ModelsActualizer::STAGE_PROBLEMS,
    (string)$envelope['stage']);
check('the failure is explained', (bool)array_filter(
    $envelope['warnings'],
    static fn(string $w): bool => str_contains($w, 'analyzer model could not produce a pool'),
), implode(' | ', $envelope['warnings']));
check('NOTHING was written: catalogue unchanged',
    Config::get('models', []) === $before['models']);
check('NOTHING was written: presets unchanged',
    stateSignature($svc['presets']) === $before);
check('the run still recorded a status for the UI',
    ($svc['store']->loadStatus()['stage'] ?? '') === ModelsActualizer::STAGE_PROBLEMS);
check('the recorded status names the stage that failed',
    ($svc['store']->loadStatus()['failed_stage'] ?? '') === ModelsActualizer::STAGE_ASSIGNING,
    json_encode($svc['store']->loadStatus()['failed_stage'] ?? null));
check('the status endpoint passes the failing stage to the card', (static function () use ($svc, $tmp): bool {
    global $dbgPayload;
    // The run is over, so the queue row has to say so — an unfinished row
    // is an active job, and the endpoint reports that instead of a verdict.
    foreach ($svc['jobs']->listRecent(10) as $recent) {
        $svc['jobs']->updateStatus($recent->id, JobStatus::Completed);
    }
    // The admin token is normally armed further down the file; this is the
    // first endpoint call above that point, so it arms it itself (the same
    // values the later block sets).
    $_ENV['REVOAP_ADMIN_TOKEN'] = 'test-admin-token';
    $_SERVER['HTTP_X_ADMIN_TOKEN'] = 'test-admin-token';
    AdminAuth::setRootDir($tmp);
    $controller = new ModelsController(
        new JobService($svc['jobs'], new SessionRepository(null)),
        $svc['jobs'],
        $svc['store'],
        $svc['actualizer'],
        null,
    );
    $payload = json_decode($controller->actualizeStatus(), true);
    $dbgPayload = $payload;
    return ($payload['failed_stage'] ?? null) === ModelsActualizer::STAGE_ASSIGNING
        && ($payload['stage'] ?? '') === ModelsActualizer::STAGE_PROBLEMS;
})(), json_encode($dbgPayload ?? null));

echo "\n-- an unparseable analyzer answer is a failure, not a guess --\n";
configure();
$svc = services();
$before = stateSignature($svc['presets']);
$GLOBALS['stub_content'] = 'Sure! Here is a lovely prose answer with no JSON at all.';
$envelope = $svc['actualizer']->run(jobFixture($svc['jobs']), 'test-worker');
check('a non-JSON answer ends in problems', $envelope['stage'] === ModelsActualizer::STAGE_PROBLEMS);
check('a non-JSON answer writes nothing', stateSignature($svc['presets']) === $before);
// The lenient "pull the first {...} span out of the prose" path that used to
// be asserted here is gone: the answer is a contract now, and §(i) below
// pins what replaced it — a rejection, one correction, then a refusal.
check('the run does not extract JSON out of prose any more',
    !method_exists(ModelsActualizer::class, 'extractJson')
    && str_contains(warningText($envelope), 'rejected after 2 attempt'),
    warningText($envelope));

echo "\n-- a provider outage during stage 1 writes nothing and says so --\n";
configure();
$svc = services();
$before = stateSignature($svc['presets']);
$GLOBALS['stub_live_alpha'] = [];
$envelope = $svc['actualizer']->run(jobFixture($svc['jobs']), 'test-worker');
check('no live models → problems', $envelope['stage'] === ModelsActualizer::STAGE_PROBLEMS,
    (string)$envelope['stage']);
check('no live models → nothing written', stateSignature($svc['presets']) === $before);
check('the outage is explained', (bool)array_filter(
    $envelope['warnings'],
    static fn(string $w): bool => str_contains($w, 'returned no model list'),
), implode(' | ', $envelope['warnings']));

// =====================================================================
echo "\n== (f) the linked models: analyzer, synthesis, arbiter ==\n";
// =====================================================================
//
// These three are not part of a preset and not part of the pool: they sit
// in flat config keys (`analyzer.model`, `pipeline.synthesis_model`,
// `pipeline.conflict_arbiter.model`) and were the blind spot of the first
// actualization implementation — the pools moved to a new release while the
// analyzer kept calling the retired one.

echo "\n-- the rules, decided offline --\n";
configure(['analyzer.model' => 'openai/gpt-5.5']);
$plan = LinkedModels::plan(['alpha' => LIVE_CATALOG]);
check('a catalogue that has not moved plans nothing', $plan['changes'] === [],
    json_encode($plan['changes']));
check('why nothing moved is spelled out', str_contains(warningText($plan), 'Analyzer model openai/gpt-5.5 is still served by "alpha"'),
    warningText($plan));
check('the pre-run state is handed to the caller for the snapshot',
    ($plan['previous']['analyzer']['provider'] ?? '') === 'alpha'
    && ($plan['previous']['analyzer']['model'] ?? '') === 'openai/gpt-5.5'
    && ($plan['previous']['arbiter']['model'] ?? '') === 'anthropic/claude-sonnet-4.6',
    json_encode($plan['previous']));

$plan = LinkedModels::plan(['alpha' => array_merge(LIVE_CATALOG, ['openai/gpt-5.6'])]);
check('the analyzer moves to the newest release on its line',
    (linkedChange($plan, LinkedModels::ROLE_ANALYZER)['model'] ?? '') === 'openai/gpt-5.6',
    json_encode(linkedChange($plan, LinkedModels::ROLE_ANALYZER)));
check('the old value is carried for the summary',
    (linkedChange($plan, LinkedModels::ROLE_ANALYZER)['previous_model'] ?? '') === 'openai/gpt-5.5');
check('the change is reported in the human-readable form',
    str_contains(warningText($plan), 'Analyzer model openai/gpt-5.5 → openai/gpt-5.6'));
check('the synthesis model moves by the same rule',
    (linkedChange($plan, LinkedModels::ROLE_SYNTHESIS)['model'] ?? '') === 'openai/gpt-5.6',
    json_encode(linkedChange($plan, LinkedModels::ROLE_SYNTHESIS)));
check('the role is labelled for the UI',
    (linkedChange($plan, LinkedModels::ROLE_SYNTHESIS)['label'] ?? '') === 'Synthesis');
check('a role that has not moved is not reported as a change',
    linkedChange($plan, LinkedModels::ROLE_ARBITER) === null, json_encode(linkedChange($plan, LinkedModels::ROLE_ARBITER)));

echo "\n-- a different line is a different model --\n";
configure(['analyzer.model' => 'openai/gpt-5.5-pro']);
$plan = LinkedModels::plan(['alpha' => array_merge(LIVE_CATALOG, ['openai/gpt-5.6'])]);
check('gpt-5.5-pro is never replaced by gpt-5.6',
    linkedChange($plan, LinkedModels::ROLE_ANALYZER) === null, json_encode(linkedChange($plan, LinkedModels::ROLE_ANALYZER)));
check('and it is reported as still served', str_contains(warningText($plan), 'Analyzer model openai/gpt-5.5-pro is still served'),
    warningText($plan));

configure(['analyzer.model' => 'deepseek/deepseek-r1']);
$plan = LinkedModels::plan(['alpha' => array_merge(LIVE_CATALOG, ['deepseek/deepseek-r1', 'deepseek/deepseek-r2'])]);
check('a release alias is never treated as a version',
    linkedChange($plan, LinkedModels::ROLE_ANALYZER) === null, json_encode(linkedChange($plan, LinkedModels::ROLE_ANALYZER)));
check('and the alias is reported as still served',
    str_contains(warningText($plan), 'Analyzer model deepseek/deepseek-r1 is still served'), warningText($plan));

echo "\n-- never downwards, never to another provider, never on an outage --\n";
configure(['analyzer.model' => 'openai/gpt-5.6']);
$plan = LinkedModels::plan(['alpha' => LIVE_CATALOG]);
check('a line with only older releases left is not a reason to downgrade',
    linkedChange($plan, LinkedModels::ROLE_ANALYZER) === null, json_encode(linkedChange($plan, LinkedModels::ROLE_ANALYZER)));
check('the refusal to downgrade is explained',
    str_contains(warningText($plan), 'only older releases on its line are live (newest: openai/gpt-5.5)'),
    warningText($plan));

configure(['analyzer.provider' => 'alpha', 'analyzer.model' => 'openai/gpt-5.5']);
$plan = LinkedModels::plan([
    'alpha' => ['openai/o3-mini', 'anthropic/claude-sonnet-4.6'],
    'beta'  => ['openai/gpt-5.6'],
]);
check('a model is never moved to another provider on its own',
    linkedChange($plan, LinkedModels::ROLE_ANALYZER) === null, json_encode(linkedChange($plan, LinkedModels::ROLE_ANALYZER)));
check('and that refusal is explained',
    str_contains(warningText($plan), 'never moved to another provider automatically'), warningText($plan));

configure(['analyzer.model' => 'openai/gpt-5.5']);
$plan = LinkedModels::plan(['alpha' => []]);
check('a provider that returns no list changes nothing at all', $plan['changes'] === [],
    json_encode($plan['changes']));
check('the outage is reported instead of being read as staleness',
    str_contains(warningText($plan), 'returned no live model list'), warningText($plan));

configure(['analyzer.provider' => 'ghost', 'analyzer.model' => 'openai/gpt-5.5']);
$plan = LinkedModels::plan(['alpha' => LIVE_CATALOG]);
check('a role whose provider was not queried is left alone', linkedChange($plan, LinkedModels::ROLE_ANALYZER) === null);
check('and the missing connector is named',
    str_contains(warningText($plan), 'provider "ghost" is not an active connector'), warningText($plan));

echo "\n-- a run applies every link and reports it per role --\n";
configure([
    'analyzer.model'                  => 'openai/gpt-5.5',
    'pipeline.synthesis_model'        => 'openai/gpt-5.5',
    'pipeline.conflict_arbiter.model' => 'anthropic/claude-sonnet-4',
]);
// A sibling override in the analyzer block, to prove the writes are merged
// by key rather than replaced wholesale.
RuntimeConfig::update(['analyzer.enabled' => true]);
Config::reload($tmp . DIRECTORY_SEPARATOR . 'config.php');
$svc = services();
$before = stateSignature($svc['presets']);
$GLOBALS['stub_live_alpha'] = array_merge(LIVE_CATALOG, ['openai/gpt-5.6']);
stubAnswer(['models' => [['provider' => 'alpha', 'model' => 'openai/gpt-5.5']]]);
$envelope = $svc['actualizer']->run(jobFixture($svc['jobs']), 'test-worker');

check('the run succeeds with three linked models in play',
    $envelope['stage'] === ModelsActualizer::STAGE_SUCCESS,
    (string)$envelope['stage'] . ' — ' . warningText($envelope));
check('the analyzer is current afterwards',
    Config::get('analyzer.model') === 'openai/gpt-5.6', (string)Config::get('analyzer.model'));
check('the synthesis model is current afterwards',
    Config::get('pipeline.synthesis_model') === 'openai/gpt-5.6', (string)Config::get('pipeline.synthesis_model'));
check('the arbiter is current afterwards',
    Config::get('pipeline.conflict_arbiter.model') === 'anthropic/claude-sonnet-4.6',
    (string)Config::get('pipeline.conflict_arbiter.model'));
check('no provider was rewritten',
    Config::get('analyzer.provider') === 'alpha'
    && Config::get('pipeline.synthesis_provider') === 'alpha'
    && Config::get('pipeline.conflict_arbiter.provider') === 'alpha');
check('the arbiter keeps its other settings',
    Config::get('pipeline.conflict_arbiter.enabled') === true);
check('all three roles are reported for the UI',
    array_map(static fn(array $c): string => (string)$c['role'], $envelope['linked']) === LinkedModels::roles(),
    json_encode($envelope['linked']));
check('every reported role names the old and the new value',
    count($envelope['linked']) === 3
    && (bool)array_filter($envelope['linked'], static fn(array $c): bool => ($c['previous_model'] ?? '') === '') === false
    && (linkedChange($envelope, LinkedModels::ROLE_ARBITER)['previous_model'] ?? '') === 'anthropic/claude-sonnet-4',
    json_encode($envelope['linked']));
check('the summary lines name the role, the old and the new model',
    str_contains(warningText($envelope), 'Analyzer model openai/gpt-5.5 → openai/gpt-5.6')
    && str_contains(warningText($envelope), 'Synthesis model openai/gpt-5.5 → openai/gpt-5.6')
    && str_contains(warningText($envelope), 'Arbiter model anthropic/claude-sonnet-4 → anthropic/claude-sonnet-4.6'),
    warningText($envelope));
$runtime = RuntimeConfig::all();
check('runtime.json carries the new analyzer model',
    ($runtime['analyzer']['model'] ?? '') === 'openai/gpt-5.6', json_encode($runtime['analyzer'] ?? null));
check('a sibling key of the analyzer block survives the write',
    ($runtime['analyzer']['enabled'] ?? null) === true, json_encode($runtime['analyzer'] ?? null));
check('runtime.json carries both pipeline models',
    ($runtime['pipeline']['synthesis_model'] ?? '') === 'openai/gpt-5.6'
    && ($runtime['pipeline']['conflict_arbiter']['model'] ?? '') === 'anthropic/claude-sonnet-4.6',
    json_encode($runtime['pipeline'] ?? null));
check('the pool written by the same run survives alongside them',
    ($runtime['pipeline']['default_selected_models'] ?? []) === [['provider' => 'alpha', 'model' => 'anthropic/claude-sonnet-4.6']],
    json_encode($runtime['pipeline']['default_selected_models'] ?? null));

echo "\n-- rollback puts the linked models back, exactly --\n";
$stored = $svc['store']->loadSnapshot() ?? [];
check('the snapshot covers the catalogue, the presets, the pool and the linked models',
    array_keys($stored) === ['taken_at', 'job_id', 'models', 'presets', 'selection', 'default_selected', 'linked'],
    json_encode(array_keys($stored)));
check('every linked role is recorded with its provider, model and override provenance',
    array_keys($stored['linked']['analyzer'] ?? []) === [
        'role', 'label', 'provider', 'model', 'provider_overridden', 'model_overridden',
    ]
    && ($stored['linked']['arbiter']['model'] ?? '') === 'anthropic/claude-sonnet-4',
    json_encode($stored['linked'] ?? null));
$rollback = $svc['actualizer']->rollback();
check('rollback reports success', $rollback['ok'] === true, $rollback['message']);
check('the analyzer is back on the pre-run model',
    Config::get('analyzer.model') === $before['linked']['analyzer_model'],
    (string)Config::get('analyzer.model'));
check('the synthesis model is back', Config::get('pipeline.synthesis_model') === $before['linked']['synthesis_model'],
    (string)Config::get('pipeline.synthesis_model'));
check('the arbiter is back', Config::get('pipeline.conflict_arbiter.model') === $before['linked']['arbiter_model'],
    (string)Config::get('pipeline.conflict_arbiter.model'));
check('the whole linked state is identical to the pre-run state',
    linkedState() === $before['linked'], json_encode(linkedState()));
$runtime = RuntimeConfig::all();
check('an override the run created is removed, not pinned to the default',
    !array_key_exists('model', $runtime['analyzer'] ?? []), json_encode($runtime['analyzer'] ?? null));
check('a sibling override from before the run is still there',
    ($runtime['analyzer']['enabled'] ?? null) === true, json_encode($runtime['analyzer'] ?? null));

echo "\n-- an override that existed before the run is restored as an override --\n";
configure(['analyzer.model' => 'shipped-default']);
RuntimeConfig::update(['analyzer.model' => 'openai/gpt-5.5']);
Config::reload($tmp . DIRECTORY_SEPARATOR . 'config.php');
$svc = services();
$GLOBALS['stub_live_alpha'] = array_merge(LIVE_CATALOG, ['openai/gpt-5.6']);
stubAnswer(['models' => [['provider' => 'alpha', 'model' => 'openai/gpt-5.5']]]);
$svc['actualizer']->run(jobFixture($svc['jobs']), 'test-worker');
check('the operator override was upgraded by the run',
    Config::get('analyzer.model') === 'openai/gpt-5.6', (string)Config::get('analyzer.model'));
$svc['actualizer']->rollback();
check('rollback returns the operator override, not the shipped default',
    Config::get('analyzer.model') === 'openai/gpt-5.5', (string)Config::get('analyzer.model'));
check('and it is still an override rather than a pinned value',
    RuntimeConfig::all()['analyzer']['model'] === 'openai/gpt-5.5',
    json_encode(RuntimeConfig::all()['analyzer'] ?? null));

// =====================================================================
echo "\n== (d) enqueue contract + idempotency ==\n";
// =====================================================================
configure();
$jobRepo    = new JobRepository(null);
$sessions   = new SessionRepository(null);
$jobService = new JobService($jobRepo, $sessions);

$created = $jobService->createModelsActualizeJob([['provider' => 'alpha', 'model' => 'openai/gpt-5.5']]);
check('enqueue returns the documented shape',
    isset($created['job_id'], $created['session_id'], $created['status'], $created['events_url'])
    && $created['status'] === 'queued', json_encode($created));
$first = $jobRepo->find($created['job_id']);
check('the payload carries the kind marker',
    ($first->payload['kind'] ?? '') === ModelsActualizer::KIND);
check('the payload carries the reported selection',
    ($first->payload['selection'] ?? []) === [['provider' => 'alpha', 'model' => 'openai/gpt-5.5']]);
check('max_attempts is pinned to 1 so a config write never auto-retries',
    $first->maxAttempts === 1);
check('the job is queued at the diagnostic priority', $first->priority === 10);
check('a session row exists so the timeline endpoint resolves',
    $created['session_id'] > 0
    && str_contains($created['events_url'], (string)$created['session_id']));

echo "\n-- controller: guard, idempotency, status, rollback --\n";
configure(['analyzer.model' => 'openai/gpt-5.2']);
$GLOBALS['stub_live_alpha'] = array_merge(LIVE_CATALOG, ['openai/gpt-5.6']);
$svc = services();
$_ENV['REVOAP_ADMIN_TOKEN'] = 'test-admin-token';
$_SERVER['HTTP_X_ADMIN_TOKEN'] = 'test-admin-token';
AdminAuth::setRootDir($tmp);

$controller = new ModelsController(
    new JobService($svc['jobs'], new SessionRepository(null)),
    $svc['jobs'],
    $svc['store'],
    $svc['actualizer'],
    new WorkerHealthService($svc['jobs']),
);

$statusBody = json_decode($controller->status(), true);
check('/api/models/status reports the tab flag',
    ($statusBody['tab_enabled'] ?? null) === true, json_encode($statusBody));
check('/api/models/status never leaks a secret',
    !isset($statusBody['token']) && !isset($statusBody['admin_token']));
check('/api/models/status reports snapshot availability honestly',
    ($statusBody['snapshot_available'] ?? null) === false);

$enqueued = json_decode($controller->actualize(), true);
check('POST /api/models/actualize returns a job id',
    isset($enqueued['job_id']) && ($enqueued['status'] ?? '') === 'queued', json_encode($enqueued));
check('the first call reports that it created the job',
    ($enqueued['reused'] ?? null) === false);

$again = json_decode($controller->actualize(), true);
check('POST /api/models/actualize is idempotent while a run is active',
    ($again['job_id'] ?? null) === $enqueued['job_id'] && ($again['reused'] ?? null) === true,
    json_encode($again));
check('no second job was created',
    count(array_filter(
        $svc['jobs']->listRecent(50),
        static fn(Job $j): bool => ($j->payload['kind'] ?? '') === ModelsActualizer::KIND,
    )) === 1);

$runningStatus = json_decode($controller->actualizeStatus(), true);
check('the status endpoint reports the run as active',
    ($runningStatus['active'] ?? null) === true);
check('the status endpoint reports stage 1 while the job is queued',
    // Stage 1 is the import: the worker has not touched the job yet, and
    // the row the card may paint as running is the one that runs first.
    ($runningStatus['stage'] ?? '') === ModelsActualizer::STAGE_IMPORTING,
    (string)($runningStatus['stage'] ?? ''));
check('the status endpoint names the job', ($runningStatus['job_id'] ?? null) === $enqueued['job_id']);
check('the status endpoint reports that no worker is alive',
    ($runningStatus['worker_available'] ?? null) === false, json_encode($runningStatus['worker_available']));

$blocked = json_decode($controller->rollback(), true);
check('rollback is refused while a run is active',
    ($blocked['ok'] ?? true) === false && ($blocked['error'] ?? '') === 'actualize_running',
    json_encode($blocked));

// Finish the run, then check the completed status shape and the rollback.
$svc['jobs']->updateStatus($enqueued['job_id'], JobStatus::Completed);
stubAnswer(['models' => [['provider' => 'alpha', 'model' => 'openai/gpt-5.5']]]);
$svc['actualizer']->run($svc['jobs']->find($enqueued['job_id']), 'test-worker');
$doneStatus = json_decode($controller->actualizeStatus(), true);
check('a finished run is no longer active', ($doneStatus['active'] ?? null) === false);
check('a finished run reports its stage',
    in_array($doneStatus['stage'] ?? '', [ModelsActualizer::STAGE_SUCCESS, ModelsActualizer::STAGE_PROBLEMS], true),
    (string)($doneStatus['stage'] ?? ''));
check('a finished run reports warnings as a list',
    is_array($doneStatus['warnings'] ?? null));
check('a finished run reports the assigned pools',
    is_array($doneStatus['assigned'] ?? null) && $doneStatus['assigned'] !== []);
check('a finished run reports the new pool for the sidebar',
    is_array($doneStatus['selection'] ?? null) && $doneStatus['selection'] !== []);
check('a finished run reports the linked models it updated, with the old value',
    (linkedChange($doneStatus, LinkedModels::ROLE_ANALYZER)['previous_model'] ?? '') === 'openai/gpt-5.2'
    && (linkedChange($doneStatus, LinkedModels::ROLE_ANALYZER)['model'] ?? '') === 'openai/gpt-5.6'
    && (linkedChange($doneStatus, LinkedModels::ROLE_ANALYZER)['label'] ?? '') === 'Analyzer',
    json_encode($doneStatus['linked'] ?? null));
check('a finished run reports its snapshot for the rollback button',
    ($doneStatus['snapshot']['available'] ?? null) === true);
check('a finished run reports its timings',
    ($doneStatus['started_at'] ?? null) !== null && ($doneStatus['finished_at'] ?? null) !== null);
check('the status carries the run identity the card binds its verdict to',
    ($doneStatus['job_id'] ?? null) === $enqueued['job_id'], json_encode($doneStatus['job_id'] ?? null));
check('a successful run reports no failing stage',
    array_key_exists('failed_stage', $doneStatus) && $doneStatus['failed_stage'] === null,
    json_encode($doneStatus['failed_stage'] ?? 'missing'));
check('the failing-stage key is always present (a card cannot read it as undefined)',
    array_key_exists('failed_stage', $doneStatus));

$rolled = json_decode($controller->rollback(), true);
check('rollback succeeds once the run is over', ($rolled['ok'] ?? false) === true, json_encode($rolled));

echo "\n-- a second run exists even while the previous verdict is on record --\n";
// The reported symptom in one line: press Actualize again and the card keeps
// answering with the run before. The status record still names the finished
// job, so the endpoint has to look PAST it for the job the queue now holds —
// otherwise the answer is `active: false` plus the old verdict, which is
// exactly what the operator saw, with the new job sitting in the queue.
configure();
$svcSecond = services();
stubAnswer(['models' => [['provider' => 'alpha', 'model' => 'openai/gpt-5.5']]]);
$firstJob = jobFixture($svcSecond['jobs']);
$firstEnvelope = $svcSecond['actualizer']->run($firstJob, 'test-worker');
$svcSecond['jobs']->updateStatus($firstJob->id, JobStatus::Completed);
$firstController = new ModelsController(
    new JobService($svcSecond['jobs'], new SessionRepository(null)),
    $svcSecond['jobs'],
    $svcSecond['store'],
    $svcSecond['actualizer'],
    null,
);
$verdictOnRecord = json_decode($firstController->actualizeStatus(), true);
check('the previous run is what the status answers with first',
    ($verdictOnRecord['job_id'] ?? null) === $firstJob->id
    && ($verdictOnRecord['active'] ?? true) === false,
    json_encode([$verdictOnRecord['job_id'] ?? null, $verdictOnRecord['active'] ?? null]));

$secondEnqueue = json_decode($firstController->actualize(), true);
check('the second press creates a new job', ($secondEnqueue['job_id'] ?? 0) !== $firstJob->id,
    json_encode($secondEnqueue));
$secondStatus = json_decode($firstController->actualizeStatus(), true);
check('the queued job is reported as the active run, not the finished one',
    ($secondStatus['active'] ?? false) === true && ($secondStatus['job_id'] ?? null) === $secondEnqueue['job_id'],
    json_encode([$secondStatus['active'] ?? null, $secondStatus['job_id'] ?? null]));
check('the queue itself is asked, so the answer says queued',
    ($secondStatus['job_status'] ?? '') === JobStatus::Queued->value,
    (string)($secondStatus['job_status'] ?? ''));
check('the finished run\'s verdict is not carried over to the new one',
    ($secondStatus['finished_at'] ?? null) === null
    && ($secondStatus['stage'] ?? '') !== ModelsActualizer::STAGE_SUCCESS,
    json_encode([$secondStatus['finished_at'] ?? null, $secondStatus['stage'] ?? null]));
check('the wait is measurable', is_int($secondStatus['queued_seconds'] ?? null),
    json_encode($secondStatus['queued_seconds'] ?? null));
check('once the queue is quiet the last recorded verdict is served again', (static function () use ($svcSecond, $firstJob, $firstEnvelope): bool {
    global $dbgQuiet;
    foreach ($svcSecond['jobs']->listRecent(10) as $recent) {
        $svcSecond['jobs']->updateStatus($recent->id, JobStatus::Completed);
    }
    $controller = new ModelsController(
        new JobService($svcSecond['jobs'], new SessionRepository(null)),
        $svcSecond['jobs'],
        $svcSecond['store'],
        $svcSecond['actualizer'],
        null,
    );
    $payload = json_decode($controller->actualizeStatus(), true);
    $dbgQuiet = ['payload' => $payload, 'first' => $firstJob->id, 'statuses' => array_map(
        static fn(Job $j): string => $j->id . ':' . $j->status->value,
        $svcSecond['jobs']->listRecent(10),
    )];
    return ($payload['active'] ?? true) === false
        && ($payload['job_id'] ?? null) === $firstJob->id
        && ($payload['stage'] ?? '') === $firstEnvelope['stage'];
})(), json_encode($dbgQuiet ?? null));

echo "\n-- guard: disabled tab, missing token --\n";
configure(['ModelsTab' => false, 'SettingsTab' => false]);
$svc2 = services();
$locked = new ModelsController(
    new JobService($svc2['jobs'], new SessionRepository(null)),
    $svc2['jobs'],
    $svc2['store'],
    $svc2['actualizer'],
    null,
);
$disabled = json_decode($locked->actualize(), true);
check('a disabled Models tab answers settings_disabled',
    ($disabled['error'] ?? '') === 'settings_disabled', json_encode($disabled));

// ModelsTab alone decides: the new endpoints must not stay reachable just
// because the old Settings tab is still on, since the flag is documented as
// disabling the endpoints too.
configure(['ModelsTab' => false, 'SettingsTab' => true]);
$svcDisabled = services();
$strict = new ModelsController(
    new JobService($svcDisabled['jobs'], new SessionRepository(null)),
    $svcDisabled['jobs'],
    $svcDisabled['store'],
    $svcDisabled['actualizer'],
    null,
);
$strictDisabled = json_decode($strict->actualize(), true);
check('ModelsTab=false disables the endpoints even with SettingsTab=true',
    ($strictDisabled['error'] ?? '') === 'settings_disabled', json_encode($strictDisabled));
check('a disabled tab creates no job',
    $svcDisabled['jobs']->listRecent(10) === [], json_encode(count($svcDisabled['jobs']->listRecent(10))));
$strictStatus = json_decode($strict->actualizeStatus(), true);
check('the status endpoint is disabled by the same flag',
    ($strictStatus['error'] ?? '') === 'settings_disabled', json_encode($strictStatus));

configure();
$svc3 = services();
$GLOBALS['stub_live_alpha'] = LIVE_CATALOG;
unset($_ENV['REVOAP_ADMIN_TOKEN'], $_SERVER['HTTP_X_ADMIN_TOKEN']);
putenv('REVOAP_ADMIN_TOKEN');
$noToken = new ModelsController(
    new JobService($svc3['jobs'], new SessionRepository(null)),
    $svc3['jobs'],
    $svc3['store'],
    $svc3['actualizer'],
    null,
);
$unauthorized = json_decode($noToken->actualize(), true);
check('without an admin token the enqueue is refused',
    in_array($unauthorized['error'] ?? '', ['unauthorized', 'admin_not_configured'], true),
    json_encode($unauthorized));

AdminAuth::setRootDir(null);

echo "\n-- stage events are written for the UI --\n";
configure();
$svc = services();
stubAnswer(['models' => [['provider' => 'alpha', 'model' => 'openai/gpt-5.5']]]);
$job = jobFixture($svc['jobs']);
$svc['actualizer']->run($job, 'test-worker');
$events = $svc['audit']->listByJobId($job->id, 50);
$stageEvents = array_values(array_filter(
    $events,
    static fn($e): bool => $e->eventType === ModelsActualizer::EVENT_STAGE,
));
$stagesSeen = array_map(static fn($e): string => (string)($e->context['stage'] ?? ''), $stageEvents);
check('stage transitions are published as job events', count($stageEvents) >= 3,
    implode(', ', $stagesSeen));
check('the import stage is published first',
    ($stagesSeen[0] ?? '') === ModelsActualizer::STAGE_IMPORTING, implode(', ', $stagesSeen));
check('the analysing stage is published next',
    in_array(ModelsActualizer::STAGE_ANALYSING, $stagesSeen, true), implode(', ', $stagesSeen));
check('the assigning stage is published after it',
    in_array(ModelsActualizer::STAGE_ASSIGNING, $stagesSeen, true), implode(', ', $stagesSeen));
check('the stages are published in the order the card shows',
    array_values(array_unique($stagesSeen)) === [
        ModelsActualizer::STAGE_IMPORTING,
        ModelsActualizer::STAGE_ANALYSING,
        ModelsActualizer::STAGE_ASSIGNING,
    ], implode(', ', $stagesSeen));
check('each assignment event names its preset',
    (bool)array_filter($stageEvents, static fn($e): bool => isset($e->context['slug']))
    || (bool)array_filter($stageEvents, static fn($e): bool => isset($e->context['added_count'])));

// =====================================================================
echo "\n== (g) batch-only identifiers never reach the synchronous pool ==\n";
// =====================================================================
//
// A `:batch` id is the asynchronous surface of the same model (Batch API:
// upload → create → poll → download). Because the suffix is NOT part of the
// name (see App\Models\ModelId), such an id shares its line and version
// with its synchronous twin — which is exactly why every pool-side rule
// below has to exclude it explicitly instead of trusting the version rule
// not to notice it.

echo "\n-- stage 1/2: a batch id the analyzer names is refused, with the reason --\n";
configure();
$svc = services();
// The live catalogue carries both surfaces of the same models.
$GLOBALS['stub_live_alpha'] = array_merge(LIVE_CATALOG, [
    'openai/gpt-5.5:batch',
    'anthropic/claude-sonnet-4.6:batch',
]);
stubAnswer([
    'models'  => [
        ['provider' => 'alpha', 'model' => 'openai/gpt-5.5:batch'],
        ['provider' => 'alpha', 'model' => 'deepseek/deepseek-v4-pro'],
    ],
    'arbiter' => ['provider' => 'alpha', 'model' => 'openai/gpt-5.5:batch'],
]);
$envelope = $svc['actualizer']->run(jobFixture($svc['jobs']), 'test-worker');
$warnings = warningText($envelope);

check('the run still succeeds around the batch id',
    $envelope['stage'] === ModelsActualizer::STAGE_SUCCESS, $warnings);
check('the batch id the analyzer named is not assigned to any preset',
    !str_contains(json_encode($envelope['assigned']), ':batch'),
    json_encode($envelope['assigned']));
check('the refusal is reported instead of being silent',
    str_contains($warnings, 'batch-only identifier')
    && str_contains($warnings, 'openai/gpt-5.5:batch'),
    $warnings);
$mathBatchPreset = $svc['presets']->findBySlug('mathematics');
check('the preset falls back to the synchronous models that were named',
    !in_array('openai/gpt-5.5:batch', modelIds($mathBatchPreset->models), true)
    && in_array('deepseek/deepseek-v4-pro', modelIds($mathBatchPreset->models), true),
    implode(', ', modelIds($mathBatchPreset->models)));
check('the arbiter is not the batch id either',
    ($mathBatchPreset->arbiter['model'] ?? '') !== 'openai/gpt-5.5:batch',
    (string)($mathBatchPreset->arbiter['model'] ?? ''));
check('for a batch-only id the family is the family of its base',
    ProviderFamily::of('openai/gpt-5.5:batch') === ProviderFamily::of('openai/gpt-5.5'));

echo "\n-- the catalogue keeps such a row, marked and inactive --\n";
$byModel = [];
foreach ((array)Config::get('models', []) as $row) {
    $byModel[(string)$row['model']] = $row;
}
check('the live batch row was appended to the catalogue, not dropped',
    isset($byModel['openai/gpt-5.5:batch']), implode(', ', array_keys($byModel)));
check('the batch row is written inactive',
    ($byModel['openai/gpt-5.5:batch']['active'] ?? true) === false,
    json_encode($byModel['openai/gpt-5.5:batch'] ?? null));
check('the batch row carries its marker and the reason',
    ($byModel['openai/gpt-5.5:batch']['batch_only'] ?? false) === true
    && str_contains((string)($byModel['openai/gpt-5.5:batch']['sync_pool_reason'] ?? ''), 'Batch API'),
    json_encode($byModel['openai/gpt-5.5:batch'] ?? null));
check('the run reports which of its rows can never be called',
    str_contains($warnings, 'catalogue row(s) are batch-only')
    && str_contains($warnings, 'alpha/openai/gpt-5.5:batch'),
    $warnings);
check('no batch id is in the active set L1 reads',
    !in_array('openai/gpt-5.5:batch', array_column(Config::getActiveModels(), 'model'), true),
    implode(', ', array_column(Config::getActiveModels(), 'model')));
check('its synchronous twin stays usable',
    in_array('openai/gpt-5.5', array_column(Config::getActiveModels(), 'model'), true));

echo "\n-- the pool: never carried over, never upgraded onto a batch twin --\n";
configure();
$svc = services();
$GLOBALS['stub_live_alpha'] = array_merge(LIVE_CATALOG, ['openai/gpt-5.5:batch']);
stubAnswer(['models' => [['provider' => 'alpha', 'model' => 'deepseek/deepseek-v4-pro']]]);
$envelope = $svc['actualizer']->run(
    jobFixture($svc['jobs'], [['provider' => 'alpha', 'model' => 'openai/gpt-5.5:batch']]),
    'test-worker',
);
$warnings = warningText($envelope);
$pool = (array)Config::get('pipeline.default_selected_models', []);
$poolModels = array_column($pool, 'model');
check('the pool no longer holds the batch id',
    !in_array('openai/gpt-5.5:batch', $poolModels, true), json_encode($pool));
check('the removal is reported with the reason',
    str_contains($warnings, 'batch-only model(s) from the pool'),
    $warnings);
check('the pool it fell back to is a synchronous one',
    $poolModels !== [] && array_filter($poolModels, static fn(string $m): bool => ModelId::isBatchId($m)) === [],
    json_encode($poolModels));

echo "\n-- a live batch twin is not chosen as the newest release --\n";
configure();
$svc = services();
// `gpt-5.6:batch` is on the picked model's own line (`gpt`, version [5,6])
// and NEWER than it, so the version rule alone would rewrite the pool entry
// to the batch id — the one outcome that guarantees a failing L1 call.
$GLOBALS['stub_live_alpha'] = array_merge(LIVE_CATALOG, ['openai/gpt-5.6:batch']);
stubAnswer(['models' => [['provider' => 'alpha', 'model' => 'openai/gpt-5.5']]]);
$envelope = $svc['actualizer']->run(
    jobFixture($svc['jobs'], [['provider' => 'alpha', 'model' => 'openai/gpt-5.5']]),
    'test-worker',
);
$poolModels = array_column((array)Config::get('pipeline.default_selected_models', []), 'model');
check('a pool entry is never rewritten to its batch twin',
    in_array('openai/gpt-5.5', $poolModels, true)
    && !in_array('openai/gpt-5.6:batch', $poolModels, true),
    json_encode($poolModels));
check('the pool is not reported as upgraded onto the batch twin',
    !str_contains(warningText($envelope), 'Upgraded to the newest release'),
    warningText($envelope));
check('without the candidate filter that id WOULD have won the line',
    ModelVersion::line('openai/gpt-5.6:batch') === ModelVersion::line('openai/gpt-5.5')
    && ModelVersion::compare('openai/gpt-5.6:batch', 'openai/gpt-5.5') > 0);

echo "\n-- the linked roles (analyzer / synthesis / arbiter) stay synchronous --\n";
configure();
$svc = services();
// `gpt-5.6:batch` is on the analyzer's own line (`gpt`, version [5,6]) and
// newer than the configured `gpt-5.5`, so the version rule on its own WOULD
// move the analyzer onto it — and every analyzer call after that would fail.
$GLOBALS['stub_live_alpha'] = array_merge(LIVE_CATALOG, ['openai/gpt-5.6:batch']);
stubAnswer(['models' => [['provider' => 'alpha', 'model' => 'deepseek/deepseek-v4-pro']]]);
$analyzerBefore = (string)Config::get('analyzer.model', '');
$envelope = $svc['actualizer']->run(jobFixture($svc['jobs']), 'test-worker');
$analyzerAfter = (string)Config::get('analyzer.model', '');
$linkedModels = array_column((array)($envelope['linked'] ?? []), 'model');

check('the analyzer is not moved onto a batch-only newer release',
    $analyzerAfter === $analyzerBefore && !ModelId::isBatchId($analyzerAfter),
    "{$analyzerBefore} → {$analyzerAfter}");
check('no linked role is moved onto a batch id',
    array_filter($linkedModels, static fn(string $m): bool => ModelId::isBatchId($m)) === [],
    json_encode($linkedModels));
check('the skipped batch candidate is reported',
    str_contains(warningText($envelope), 'batch-only identifier'),
    warningText($envelope));
check('without the filter that id WOULD have won the line',
    ModelVersion::line('openai/gpt-5.6:batch') === ModelVersion::line('openai/gpt-5.5')
    && ModelVersion::compare('openai/gpt-5.6:batch', 'openai/gpt-5.5') > 0,
    ModelVersion::line('openai/gpt-5.6:batch'));
check('a batch-only linked model is left as is rather than called',
    !ModelId::isBatchId((string)Config::get('analyzer.model', '')),
    (string)Config::get('analyzer.model', ''));

// =====================================================================
echo "\n== (g) the import stage: first, and loud when it fails ==\n";
// =====================================================================
//
// "Actualize linked models" starts by importing a provider catalogue,
// because every later stage reasons about *the* catalogue. Which provider
// is the operator's choice (`import_source` in the payload), the fetch is
// the same one the Models tab's "Import models" button performs, and a
// failure is fatal: assigning pools against a catalogue the provider may
// no longer serve is the outcome the step exists to prevent.

echo "\n-- the stage order and the payload marker --\n";
check('importing_models is the first stage constant',
    ModelsActualizer::STAGE_IMPORTING === 'importing_models');
check('the stage names read import → analyse → assign', [
    ModelsActualizer::STAGE_IMPORTING,
    ModelsActualizer::STAGE_ANALYSING,
    ModelsActualizer::STAGE_ASSIGNING,
] === ['importing_models', 'analysing_models', 'assigning_presets'],
    ModelsActualizer::STAGE_IMPORTING . '/' . ModelsActualizer::STAGE_ANALYSING);
check('the payload key is the documented one',
    ModelsActualizer::PAYLOAD_IMPORT_SOURCE === 'import_source');
check('only the two catalogue sources are accepted',
    ModelImporter::SOURCES === ['bothub', 'openrouter']);
check('a source is normalised, not guessed at',
    ModelImporter::normaliseSource(' OpenRouter ') === 'openrouter'
    && ModelImporter::normaliseSource('alpha') === null
    && ModelImporter::normaliseSource(7) === null);
check('the default source is BotHub when its key is configured',
    ModelImporter::defaultSource() === 'bothub', ModelImporter::defaultSource());

echo "\n-- the source travels in the job payload --\n";
configure();
$svc = services();
// Built on the scenario's own queue, so the job the service created is the
// job the assertions read back.
$jobs = new JobService($svc['jobs'], new SessionRepository(null));
$created = $jobs->createModelsActualizeJob([], 'openrouter');
$queued = $svc['jobs']->find((int)$created['job_id']);
check('the enqueued job carries the chosen source',
    ($queued->payload[ModelsActualizer::PAYLOAD_IMPORT_SOURCE] ?? null) === 'openrouter',
    json_encode($queued->payload[ModelsActualizer::PAYLOAD_IMPORT_SOURCE] ?? null));
check('an unknown source is not written into the payload',
    !isset($svc['jobs']->find((int)$jobs->createModelsActualizeJob([], 'nope')['job_id'])->payload[
        ModelsActualizer::PAYLOAD_IMPORT_SOURCE
    ]));
check('a job queued without a choice carries no source key at all',
    !array_key_exists(
        ModelsActualizer::PAYLOAD_IMPORT_SOURCE,
        $svc['jobs']->find((int)$jobs->createModelsActualizeJob([])['job_id'])->payload,
    ));
check('the run reads the payload and imports from it', (static function (): bool {
    global $connDir;
    // The fetcher records which source it was asked for, so "the payload
    // reached the worker" is checkable without a network.
    $asked = [];
    $importer = new ModelImporter(static function (string $provider) use (&$asked): array {
        $asked[] = $provider;
        return ['ok' => true, 'status' => 200, 'models' => ['openai/gpt-5.5']];
    });
    $presets = new DomainPresetRepository(null);
    $presets->seedDefaultsIfEmpty();
    $store  = new ModelsSnapshotStore(new ConfigRepository(new PDO('sqlite::memory:')));
    $audit  = new JobAuditRepository(null);
    $jobs2  = new JobRepository(null);
    $jobs2->setAuditRepository($audit);
    $run = new ModelsActualizer(new ConnectorRegistry($connDir), $presets, $store, $audit, null, $importer);
    stubAnswer(['models' => [['provider' => 'alpha', 'model' => 'openai/gpt-5.5']]]);
    $id = $jobs2->create(sessionId: 3, userId: null, payload: [
        'kind'       => ModelsActualizer::KIND,
        'session_id' => 3,
        'selection'  => [],
        ModelsActualizer::PAYLOAD_IMPORT_SOURCE => 'openrouter',
    ], priority: 10, maxAttempts: 1);
    $job = $jobs2->find($id);
    $run->run($job, 'test-worker');
    return $asked === ['openrouter'];
})());

echo "\n-- the import report: fetched / added / dropped --\n";
// The catalogue rows carry the CONNECTOR name, so a catalogue that the
// import will recognise has to be keyed to the import source — which is
// what a real install looks like after importing once from that provider.
configure(['models' => array_map(
    static fn(string $id): array => ['provider' => 'bothub', 'model' => $id, 'active' => true],
    LIVE_CATALOG,
)]);
$svc = services();
$GLOBALS['stub_import'] = array_merge(
    LIVE_CATALOG,
    ['openai/gpt-6-alpha', 'anthropic/claude-opus-5'],
);
stubAnswer(['models' => [['provider' => 'alpha', 'model' => 'openai/gpt-5.5']]]);
$envelope = $svc['actualizer']->run(jobFixture($svc['jobs']), 'test-worker');
$importReport = (array)($envelope['import'] ?? []);
check('the run succeeds', $envelope['stage'] === ModelsActualizer::STAGE_SUCCESS,
    (string)$envelope['stage'] . ' — ' . warningText($envelope));
check('the report names the source', ($importReport['source'] ?? '') === 'bothub',
    json_encode($importReport['source'] ?? null));
check('the report counts what the provider sent',
    ($importReport['fetched'] ?? 0) === count(LIVE_CATALOG) + 2, json_encode($importReport));
check('the report counts the new rows',
    ($importReport['added'] ?? []) === ['bothub|openai/gpt-6-alpha', 'bothub|anthropic/claude-opus-5'],
    json_encode($importReport['added'] ?? null));
check('the report counts what was already catalogued',
    ($importReport['duplicates'] ?? 0) === count(LIVE_CATALOG)
    && ($importReport['present'] ?? 0) === count(LIVE_CATALOG),
    json_encode($importReport));
check('nothing is reported as dropped when nothing was',
    ($importReport['truncated'] ?? -1) === 0 && ($importReport['unusable'] ?? -1) === 0,
    json_encode($importReport));
check('the imported rows are in the catalogue afterwards', (static function (): bool {
    $keys = array_map(
        static fn(array $r): string => $r['provider'] . '|' . $r['model'],
        (array)Config::get('models', []),
    );
    return in_array('bothub|openai/gpt-6-alpha', $keys, true);
})(), 'the import did not reach the write phase');
check('the run summary names the import',
    str_contains((string)($envelope['message'] ?? ''), 'Imported'), (string)($envelope['message'] ?? ''));

echo "\n-- the row cap is configuration, and what it leaves out is counted --\n";
// v1.8.60 — the cap lives in config (`models.catalog.max_rows`), not in a
// constant. The scenario pins it to 64 so 70 fresh rows genuinely do not
// fit, and asserts the *planning* stage's report — the import stage is
// deliberately uncapped now, because the run has to know which rows the
// pool references before it may decide which rows to drop.
configure(['models.catalog.max_rows' => 64]);
$svc = services();
// 70 rows against a 64-row cap: the catalogue starts at 11, so only part of
// the new rows can fit and the rest must be reported, not silently dropped.
$many = [];
for ($i = 0; $i < 70; $i++) { $many[] = sprintf('vendor/bulk-model-%02d', $i); }
$many[] = ['name' => 'no id at all'];            // dropped by the merge
$GLOBALS['stub_import'] = $many;
stubAnswer(['models' => [['provider' => 'alpha', 'model' => 'openai/gpt-5.5']]]);
$capped = $svc['actualizer']->run(jobFixture($svc['jobs']), 'test-worker');
$cappedReport = (array)($capped['import'] ?? []);
$cappedCatalog = (array)($capped['catalog'] ?? []);
check('the run still succeeds', $capped['stage'] === ModelsActualizer::STAGE_SUCCESS, (string)$capped['stage']);
check('the cap is read from config and reported as a count',
    ($cappedCatalog['limit'] ?? 0) === 64
    && ($cappedCatalog['dropped'] ?? 0) > 0
    && ($cappedCatalog['kept'] ?? 0) === 64,
    json_encode($cappedCatalog));
check('an entry with no id is counted as unusable',
    ($cappedReport['unusable'] ?? 0) === 1, json_encode($cappedReport));
check('fetched equals what was accounted for', (static function () use ($cappedReport, $cappedCatalog): bool {
    return (int)$cappedReport['fetched']
        === count((array)$cappedReport['added'])
            + (int)$cappedReport['duplicates']
            + (int)$cappedReport['truncated']
            + (int)$cappedReport['unusable']
        // …and the cap's own arithmetic: kept + dropped is the whole plan.
        && (int)$cappedCatalog['kept'] + (int)$cappedCatalog['dropped'] === 11 + 70;
})(), json_encode($cappedReport));
check('the truncation is disclosed in words, with the rule',
    str_contains(warningText($capped), 'left out')
    && str_contains(warningText($capped), 'capped at 64')
    && ($cappedCatalog['rule'] ?? '') === 'flagships_per_family',
    warningText($capped) . ' | ' . json_encode($cappedCatalog['rule'] ?? null));

echo "\n-- a failed import is a loud refusal, never a silent fallback --\n";
configure();
$svc = services();
$before = stateSignature($svc['presets']);
$GLOBALS['stub_import_error'] = 'API key required';
stubAnswer(['models' => [['provider' => 'alpha', 'model' => 'openai/gpt-5.5']]]);
$failed = $svc['actualizer']->run(jobFixture($svc['jobs']), 'test-worker');
check('a failed import ends in problems', $failed['stage'] === ModelsActualizer::STAGE_PROBLEMS,
    (string)$failed['stage']);
check('the stage it died in is the import', ($failed['failed_stage'] ?? '') === ModelsActualizer::STAGE_IMPORTING,
    (string)($failed['failed_stage'] ?? ''));
check('the provider\'s own reason is in the report',
    str_contains(warningText($failed), 'API key required'), warningText($failed));
check('the session says which source failed',
    str_contains((string)($failed['error']['message'] ?? ''), 'bothub'), json_encode($failed['error'] ?? null));
check('nothing was written', stateSignature($svc['presets']) === $before);
check('the analyzer was never asked', stubCalls() === [],
    'the run went on to assign against a catalogue it could not import');
check('the report still names the source it tried',
    ($failed['import']['source'] ?? '') === 'bothub', json_encode($failed['import'] ?? null));

echo "\n-- an empty catalogue is a failure too, not 'nothing new' --\n";
configure();
$svc = services();
$GLOBALS['stub_import'] = [];
stubAnswer(['models' => [['provider' => 'alpha', 'model' => 'openai/gpt-5.5']]]);
$emptyImport = $svc['actualizer']->run(jobFixture($svc['jobs']), 'test-worker');
check('an empty import ends in problems', $emptyImport['stage'] === ModelsActualizer::STAGE_PROBLEMS,
    (string)$emptyImport['stage']);
check('and says the catalogue came back empty',
    str_contains(warningText($emptyImport), 'empty catalogue'), warningText($emptyImport));
check('the analyzer was never asked', stubCalls() === []);

echo "\n-- the import stage is visible in the progress record --\n";
configure();
$svc = services();
$seen = [];
$progressByStage = [];
$job = jobFixture($svc['jobs']);
$GLOBALS['stub_import'] = array_merge(LIVE_CATALOG, ['openai/gpt-6-alpha']);
stubAnswer(['models' => [['provider' => 'alpha', 'model' => 'openai/gpt-5.5']]]);
$envelope = $svc['actualizer']->run($job, 'test-worker', static function () use ($svc, $job, &$seen, &$progressByStage): void {
    // The heartbeat runs at every stage transition, so this is the status
    // record as the Models tab's 3s poll would have read it mid-run.
    $status = $svc['store']->statusFor($job->id);
    if (!is_array($status)) { return; }
    $stage = (string)($status['stage'] ?? '');
    $seen[] = $stage;
    if (isset($status['progress']) && is_array($status['progress'])) {
        $progressByStage[$stage] = $status['progress'];
    }
});
check('the import stage was published to the status record',
    in_array(ModelsActualizer::STAGE_IMPORTING, $seen, true), implode(', ', $seen));
check('the stages were published in order', (static function () use ($seen): bool {
    $order = [ModelsActualizer::STAGE_IMPORTING, ModelsActualizer::STAGE_ANALYSING, ModelsActualizer::STAGE_ASSIGNING];
    $firstSeen = [];
    foreach (array_values(array_filter($seen, static fn(string $s): bool => in_array($s, $order, true))) as $stage) {
        if (!isset($firstSeen[$stage])) { $firstSeen[$stage] = count($firstSeen); }
    }
    return $firstSeen === array_flip($order);
})(), implode(', ', $seen));
check('the import stage carries its counters into the progress record',
    isset($progressByStage[ModelsActualizer::STAGE_IMPORTING]['fetched'],
        $progressByStage[ModelsActualizer::STAGE_IMPORTING]['added'],
        $progressByStage[ModelsActualizer::STAGE_IMPORTING]['truncated']),
    json_encode($progressByStage[ModelsActualizer::STAGE_IMPORTING] ?? null));
check('the import progress names the source',
    ($progressByStage[ModelsActualizer::STAGE_IMPORTING]['source'] ?? '') === 'bothub',
    json_encode($progressByStage[ModelsActualizer::STAGE_IMPORTING] ?? null));
unlockAdmin();
check('the status endpoint reports importing_models while it runs', (static function () use ($svc, &$dbgStatus): bool {
    $svc['store']->saveProgress(9, ModelsActualizer::STAGE_IMPORTING, 'Importing the model catalogue', []);
    $controller = new ModelsController(
        new JobService($svc['jobs'], new SessionRepository(null)),
        $svc['jobs'],
        $svc['store'],
        $svc['actualizer'],
        null,
        $svc['preferences'],
    );
    $payload = json_decode($controller->actualizeStatus(), true);
    $dbgStatus = $payload;
    // The job is still `queued`, so the queue row is the run in flight and
    // the stage shown is the one that will run first — not the verdict of
    // the run before it.
    return ($payload['stage'] ?? '') === ModelsActualizer::STAGE_IMPORTING
        && ($payload['active'] ?? false) === true;
})(), json_encode($dbgStatus ?? null));
check('a status row for another job does not leak into the active run',
    str_contains((string)($dbgStatus['message'] ?? ''), 'Importing') === false,
    json_encode($dbgStatus['message'] ?? null));
check('a queued run names the import as the stage that is next', (static function (): bool {
    // No status record yet: the worker has not touched the job, so the row
    // the card may paint as "running" is the first one.
    $method = new ReflectionMethod(ModelsController::class, 'stageOf');
    $method->setAccessible(true);
    return $method->invoke(null, []) === ModelsActualizer::STAGE_IMPORTING
        && $method->invoke(null, ['stage' => 'analysing_models']) === ModelsActualizer::STAGE_ANALYSING
        && $method->invoke(null, ['stage' => 'nonsense']) === ModelsActualizer::STAGE_IMPORTING;
})());

echo "\n-- the enqueue endpoint validates the source --\n";
configure();
unlockAdmin();
$svc = services();
$ctrl = new ModelsController(
    new JobService($svc['jobs'], new SessionRepository(null)),
    $svc['jobs'],
    $svc['store'],
    $svc['actualizer'],
    null,
    $svc['preferences'],
);
$body = json_decode($ctrl->actualize(['import_source' => 'openrouter']), true);
check('a valid source is accepted and reported',
    ($body['import_source'] ?? '') === 'openrouter', json_encode($body));
check('an unknown source is a 400', (static function () use ($svc): bool {
    // Its own queue: a job from the check above is still active on the
    // shared one, and "reusing it" would answer 200 before validation.
    $fresh = new JobRepository(null);
    $ctrl = new ModelsController(
        new JobService($fresh, new SessionRepository(null)),
        $fresh,
        $svc['store'],
        $svc['actualizer'],
        null,
        $svc['preferences'],
    );
    $out = json_decode($ctrl->actualize(['import_source' => 'gemini']), true);
    return ($out['error'] ?? '') === 'invalid_import_source'
        && str_contains((string)($out['message'] ?? ''), 'bothub, openrouter');
})());
check('nothing was enqueued for a rejected source',
    count((new JobRepository(null))->listRecent(5)) === 0, 'a job was queued for an invalid source');
check('the enqueue names which catalogue the worker will import',
    str_contains((string)($body['message'] ?? ''), 'openrouter'), (string)($body['message'] ?? ''));

// =====================================================================
echo "\n== (h) the section's own analyzer model ==\n";
// =====================================================================
//
// The Actualize card may pick the model that answers its assignment calls.
// That choice lives in its OWN key (`models.actualize.analyzer`) and is
// optional: when it is unset the section inherits `analyzer.provider` /
// `analyzer.model`, so the default behaviour is unchanged and picking a
// model here never re-points the Request Analyzer.

echo "\n-- the key, and what 'unset' means --\n";
configure();
$svc = services();
$prefs = $svc['preferences'];
check('the key is the documented one',
    ModelsActualizeSettings::KEY_ANALYZER === 'models.actualize.analyzer',
    ModelsActualizeSettings::KEY_ANALYZER);
check('an unset section has no value of its own', $prefs->section() === null);
check('an unset section inherits analyzer.provider / analyzer.model',
    $prefs->inherited() === ['provider' => 'alpha', 'model' => 'openai/gpt-5.5'],
    json_encode($prefs->inherited()));
check('the effective value is the inherited one',
    $prefs->resolve() === [
        'provider' => 'alpha', 'model' => 'openai/gpt-5.5',
        'source' => ModelsActualizeSettings::SOURCE_INHERITED, 'note' => null,
    ], json_encode($prefs->resolve()));

echo "\n-- a section value wins, and says so --\n";
check('the section value is stored', $prefs->save('alpha', 'openai/gpt-5.2'));
check('reading it back gives the stored pair',
    $prefs->section() === ['provider' => 'alpha', 'model' => 'openai/gpt-5.2'],
    json_encode($prefs->section()));
check('the effective value is the section one, flagged as such',
    $prefs->resolve() === [
        'provider' => 'alpha', 'model' => 'openai/gpt-5.2',
        'source' => ModelsActualizeSettings::SOURCE_SECTION, 'note' => null,
    ], json_encode($prefs->resolve()));
check('the analyzer.* keys were NOT touched',
    Config::get('analyzer.model') === 'openai/gpt-5.5', (string)Config::get('analyzer.model'));

echo "\n-- the run uses the section model and reports it --\n";
configure();
$svc = services();
$svc['preferences']->save('alpha', 'openai/gpt-5.2');
$svc['actualizer'] = actualizerFor(
    $svc['connectors'], $svc['presets'], $svc['store'], $svc['audit'], null, null, $svc['preferences'],
);
stubAnswer(['models' => [['provider' => 'alpha', 'model' => 'openai/gpt-5.5']]]);
$envelope = $svc['actualizer']->run(jobFixture($svc['jobs']), 'test-worker');
check('the run succeeded', $envelope['stage'] === ModelsActualizer::STAGE_SUCCESS, warningText($envelope));
check('the section model answered the calls',
    (stubCalls()[0]['model'] ?? '') === 'openai/gpt-5.2', (string)(stubCalls()[0]['model'] ?? ''));
check('the report says which model was used',
    ($envelope['analyzer']['model'] ?? '') === 'openai/gpt-5.2'
    && ($envelope['analyzer']['provider'] ?? '') === 'alpha',
    json_encode($envelope['analyzer'] ?? null));
check('the report says where the value came from',
    ($envelope['analyzer']['source'] ?? '') === ModelsActualizeSettings::SOURCE_SECTION,
    json_encode($envelope['analyzer'] ?? null));
check('the report counts the requests', ($envelope['analyzer']['requests'] ?? 0) === 3,
    json_encode($envelope['analyzer'] ?? null));
check('no correction was needed', ($envelope['analyzer']['corrections'] ?? -1) === 0,
    json_encode($envelope['analyzer'] ?? null));

echo "\n-- with no section value, the analyzer model answers and is reported as inherited --\n";
configure();
$svc = services();
stubAnswer(['models' => [['provider' => 'alpha', 'model' => 'openai/gpt-5.5']]]);
$inheritedRun = $svc['actualizer']->run(jobFixture($svc['jobs']), 'test-worker');
check('the analyzer model answered',
    (stubCalls()[0]['model'] ?? '') === 'openai/gpt-5.5', (string)(stubCalls()[0]['model'] ?? ''));
check('the report marks the value as inherited',
    ($inheritedRun['analyzer']['source'] ?? '') === ModelsActualizeSettings::SOURCE_INHERITED,
    json_encode($inheritedRun['analyzer'] ?? null));
check('the run still succeeds', $inheritedRun['stage'] === ModelsActualizer::STAGE_SUCCESS, warningText($inheritedRun));

echo "\n-- a batch-only section model is refused, with the reason --\n";
configure();
$svc = services();
check('saving a :batch id throws with the reason', (static function () use ($svc): bool {
    try {
        $svc['preferences']->save('alpha', 'openai/gpt-5.5:batch');
        return false;
    } catch (\InvalidArgumentException $e) {
        return str_contains($e->getMessage(), 'batch');
    }
})());
check('nothing was stored', $svc['preferences']->section() === null, json_encode($svc['preferences']->section()));
check('a stored batch id is ignored and the analyzer is inherited', (static function () use ($svc): bool {
    // Belt to the braces above: a value that predates the check, or one
    // written straight into app_config, must not break the run.
    $repo = new ReflectionProperty(ModelsActualizeSettings::class, 'config');
    $repo->setAccessible(true);
    $config = $repo->getValue($svc['preferences']);
    $config->set(ModelsActualizeSettings::KEY_ANALYZER, json_encode([
        'provider' => 'alpha', 'model' => 'openai/gpt-5.5:batch',
    ]), ModelsActualizeSettings::SCOPE);
    $choice = $svc['preferences']->resolve();
    return $choice['source'] === ModelsActualizeSettings::SOURCE_INHERITED
        && $choice['model'] === 'openai/gpt-5.5'
        && is_string($choice['note']) && str_contains($choice['note'], 'batch');
})());
check('clearing the section brings the inheritance back', (static function () use ($svc): bool {
    $svc['preferences']->save('alpha', 'openai/gpt-5.2');
    $cleared = $svc['preferences']->clear();
    return $cleared && $svc['preferences']->section() === null
        && $svc['preferences']->resolve()['source'] === ModelsActualizeSettings::SOURCE_INHERITED;
})());

echo "\n-- the endpoints for the section setting --\n";
configure();
unlockAdmin();
$svc = services();
$ctrl = new ModelsController(
    new JobService(new JobRepository(null), new SessionRepository(null)),
    $svc['jobs'],
    $svc['store'],
    $svc['actualizer'],
    null,
    $svc['preferences'],
);
$state = json_decode($ctrl->actualizeSettings(), true);
check('GET reports both key names so the card can explain the inheritance',
    ($state['source_key'] ?? '') === 'models.actualize.analyzer'
    && ($state['inherited_key'] ?? '') === 'analyzer.provider / analyzer.model',
    json_encode($state));
check('GET reports an unset section and an inherited effective value',
    array_key_exists('section', $state) && $state['section'] === null
    && ($state['source'] ?? '') === ModelsActualizeSettings::SOURCE_INHERITED
    && ($state['effective']['model'] ?? '') === 'openai/gpt-5.5',
    json_encode($state));
check('GET names the import sources and the default',
    ($state['import_sources'] ?? []) === ['bothub', 'openrouter']
    && ($state['default_source'] ?? '') === 'bothub',
    json_encode($state));
$saved = json_decode($ctrl->saveActualizeSettings(['provider' => 'alpha', 'model' => 'openai/gpt-5.2']), true);
check('POST stores the pair',
    ($saved['ok'] ?? false) === true && ($saved['source'] ?? '') === ModelsActualizeSettings::SOURCE_SECTION,
    json_encode($saved));
check('POST answers with the effective value', ($saved['effective']['model'] ?? '') === 'openai/gpt-5.2',
    json_encode($saved));
check('POST refuses a :batch id with the reason', (static function () use ($ctrl): bool {
    $out = json_decode($ctrl->saveActualizeSettings(['provider' => 'alpha', 'model' => 'openai/gpt-5.5:batch']), true);
    return ($out['ok'] ?? true) === false && ($out['error'] ?? '') === 'invalid_model'
        && str_contains((string)($out['message'] ?? ''), 'batch');
})());
check('POST refuses an empty pair rather than silently doing nothing', (static function () use ($ctrl): bool {
    $out = json_decode($ctrl->saveActualizeSettings(['provider' => '', 'model' => '']), true);
    return ($out['ok'] ?? true) === false && ($out['error'] ?? '') === 'invalid_model';
})());
check('POST refuses a request that asks for nothing', (static function () use ($ctrl): bool {
    $out = json_decode($ctrl->saveActualizeSettings([]), true);
    return ($out['ok'] ?? true) === false && ($out['error'] ?? '') === 'invalid_request';
})());
check('POST refuses reset together with a pair', (static function () use ($ctrl): bool {
    $out = json_decode($ctrl->saveActualizeSettings(['reset' => true, 'provider' => 'a', 'model' => 'b']), true);
    return ($out['ok'] ?? true) === false && ($out['error'] ?? '') === 'invalid_request';
})());
check('POST reset clears the section', (static function () use ($ctrl): bool {
    $out = json_decode($ctrl->saveActualizeSettings(['reset' => true]), true);
    return ($out['ok'] ?? false) === true
        && ($out['source'] ?? '') === ModelsActualizeSettings::SOURCE_INHERITED
        && array_key_exists('section', $out) && $out['section'] === null;
})());
check('the setting is invisible to the Request Analyzer', (static function (): bool {
    global $connDir;
    $ctrl = new AnalyzerController(new RequestAnalyzer(new ConnectorRegistry($connDir)));
    $out = json_decode($ctrl->settings(), true);
    return ($out['provider'] ?? '') === 'alpha' && ($out['model'] ?? '') === 'openai/gpt-5.5';
})());

// =====================================================================
echo "\n== (i) the assignment prompt is a contract ==\n";
// =====================================================================
//
// The prompt and the parser are one artefact: what the prompt promises, the
// parser enforces, and what the parser rejects the single corrective retry
// quotes back. These checks pin the parts an edit must not quietly drop.

/**
 * The worked example out of the system prompt, decoded.
 *
 * Located by its own opening rather than by "the first `{`": the prompt
 * quotes `"{"` in prose (the ban on text around the object), and taking the
 * first brace would read a sentence, not the example.
 *
 * @return array<string,mixed>|null
 */
function exampleJson(string $prompt): ?array
{
    $marker = '{' . chr(10) . '  "models": [';
    $start = strpos($prompt, $marker);
    if ($start === false) { return null; }
    $end = strpos($prompt, chr(10) . '}', $start);
    if ($end === false) { return null; }
    $decoded = json_decode(substr($prompt, $start, $end - $start + 2), true);
    return is_array($decoded) ? $decoded : null;
}

/** Read the prompt halves the run actually sent. */
function promptParts(): array
{
    $calls = stubCalls();
    if ($calls === []) { return ['', '']; }
    $messages = (array)($calls[0]['messages'] ?? []);
    return [
        (string)($messages[0]['content'] ?? ''),
        (string)($messages[1]['content'] ?? ''),
    ];
}

configure();
$svc = services();
stubAnswer(['models' => [['provider' => 'alpha', 'model' => 'openai/gpt-5.5']]]);
$svc['actualizer']->run(jobFixture($svc['jobs']), 'test-worker');
[$system, $user] = promptParts();

check('the system prompt was sent', $system !== '');
check('it carries a complete worked example', (static function () use ($system): bool {
    $decoded = exampleJson($system);
    return is_array($decoded)
        && count((array)($decoded['models'] ?? [])) >= 4
        && count((array)($decoded['models'] ?? [])) <= 8
        && isset($decoded['arbiter']['provider'], $decoded['arbiter']['model'])
        && is_string($decoded['reason'] ?? null);
})(), 'the example object could not be read back out of the prompt');
check('every example entry carries both keys', (static function () use ($system): bool {
    foreach ((array)(exampleJson($system)['models'] ?? []) as $row) {
        if (!isset($row['provider'], $row['model'])
            || !is_string($row['provider']) || !is_string($row['model'])) {
            return false;
        }
    }
    return (array)(exampleJson($system)['models'] ?? []) !== [];
})(), 'an example entry is missing provider or model');
check('the example is labelled as fiction, not as ids to use',
    str_contains($system, 'MADE UP') && str_contains($system, 'not an invitation to invent identifiers'),
    'the example is not fenced off from the catalogue');
check('the example cannot be mistaken for the live catalogue',
    !str_contains($user, 'nimbus-9-ultra'), 'a fictional id leaked into the catalogue');
check('fences are forbidden in words',
    str_contains($system, 'Never wrap it in ``` fences'), 'the fence ban is missing');
check('text before and after the object is forbidden in words',
    str_contains($system, 'Never write anything before the opening "{"')
    && str_contains($system, 'after the closing "}"'),
    'the "nothing around the object" rule is missing');
check('placeholders are forbidden in words',
    str_contains($system, 'Never use placeholders') && str_contains($system, 'TODO'),
    'the placeholder ban is missing');
check('verbatim ids are demanded in words',
    str_contains($system, 'copied from it, character for character')
    && str_contains($system, 'Copy every "provider" and "model" string verbatim'),
    'the verbatim rule is missing');
check('only the three documented keys are allowed',
    str_contains($system, 'exactly these three keys') && str_contains($system, '"reason"'),
    'the key list is missing');
check('4 to 8 models are asked for', str_contains($system, '4 to 8 entries'));
check('inventing an id or a family is forbidden',
    str_contains($system, 'Never invent a model id or a vendor family')
    && str_contains($system, 'is simply skipped'),
    'the anti-hallucination rule is missing');
check('the default preset is told to span vendor families',
    str_contains($system, 'DIFFERENT base providers'), 'the diversity rule is missing');
check('mathematics and physics are told capability beats diversity',
    str_contains($system, 'capability beats diversity')
    && str_contains($system, '"mathematics" and "physics"'),
    'the domain rule is missing');
check('the newest flagship and reasoning releases are preferred',
    str_contains($system, 'newest, most capable release')
    && str_contains($system, 'reasoning-tuned release over a chat-only one'),
    'the version rule is missing');
check('the preferred families are named',
    str_contains($system, 'Claude/Opus (Anthropic)') && str_contains($system, 'GLM (Zhipu)')
    && str_contains($system, 'DeepSeek'),
    'the family list is missing');
check('the user prompt restates the contract where the catalogue is',
    str_contains($user, 'Reply with ONE JSON object and nothing else')
    && str_contains($user, 'verbatim'),
    'the per-preset prompt does not restate the contract');
check('the user prompt still lists the live catalogue with families',
    str_contains($user, 'alpha|openai/gpt-5.5 (family: OpenAI / ChatGPT)'),
    substr($user, -400));

echo "\n-- the parser: what the contract refuses --\n";
$violations = [
    'a fenced object' => "Here you go:\n```json\n{\"models\":[{\"provider\":\"alpha\",\"model\":\"openai/gpt-5.5\"}],"
        . "\"arbiter\":{\"provider\":\"alpha\",\"model\":\"openai/gpt-5.5\"},\"reason\":\"r\"}\n```",
    'prose after the object' => '{"models":[{"provider":"alpha","model":"openai/gpt-5.5"}],'
        . '"arbiter":{"provider":"alpha","model":"openai/gpt-5.5"},"reason":"r"} — hope that helps!',
    'a bare prose answer' => 'I recommend gpt-5.5 and claude-opus-4.5.',
    'an empty answer' => '',
    'a truncated object' => '{"models":[{"provider":"alpha","model":"openai/gpt-5.5"}],',
];
$parseAnswer = new ReflectionMethod(ModelsActualizer::class, 'parseAnswer');
$parseAnswer->setAccessible(true);
foreach ($violations as $label => $answer) {
    $result = $parseAnswer->invoke(null, $answer);
    check('the parser rejects ' . $label, ($result['ok'] ?? true) === false && is_string($result['error'] ?? null));
}
check('a rejection explains that the answer must be one JSON object',
    str_contains((string)$parseAnswer->invoke(null, "Sure!\n{\"models\":[]}")['error'], 'not a single JSON object'));
$placeholder = $parseAnswer->invoke(null, json_encode([
    'models'  => [['provider' => 'alpha', 'model' => '...']],
    'arbiter' => ['provider' => 'alpha', 'model' => 'openai/gpt-5.5'],
    'reason'  => 'r',
]));
check('a "..." placeholder is refused with its own reason',
    ($placeholder['ok'] ?? true) === false && str_contains((string)$placeholder['error'], 'placeholder'),
    json_encode($placeholder));
$noReason = $parseAnswer->invoke(null, json_encode([
    'models'  => [['provider' => 'alpha', 'model' => 'openai/gpt-5.5']],
    'arbiter' => ['provider' => 'alpha', 'model' => 'openai/gpt-5.5'],
]));
check('a missing "reason" is refused', ($noReason['ok'] ?? true) === false
    && str_contains((string)$noReason['error'], '"reason"'), json_encode($noReason));
$noArbiter = $parseAnswer->invoke(null, json_encode([
    'models' => [['provider' => 'alpha', 'model' => 'openai/gpt-5.5']],
    'reason' => 'r',
]));
check('a missing "arbiter" is refused', ($noArbiter['ok'] ?? true) === false
    && str_contains((string)$noArbiter['error'], '"arbiter"'), json_encode($noArbiter));
$validAnswer = json_encode([
    'models'  => [['provider' => 'alpha', 'model' => 'openai/gpt-5.5']],
    'arbiter' => ['provider' => 'alpha', 'model' => 'openai/gpt-5.5'],
    'reason'  => 'r',
]);
check('the documented shape is accepted', ($parseAnswer->invoke(null, $validAnswer)['ok'] ?? false) === true);

echo "\n-- one correction, then a refusal --\n";
configure();
$svc = services();
$fenced = "Certainly!\n```json\n" . $validAnswer . "\n```";
// The first call gets the fenced answer, the second the clean one; the
// remaining presets answer cleanly from `stub_content`.
$GLOBALS['stub_content'] = $validAnswer;
$GLOBALS['stub_content_queue'] = [$fenced, $validAnswer];
$corrected = $svc['actualizer']->run(jobFixture($svc['jobs']), 'test-worker');
check('a corrected answer still completes the run',
    $corrected['stage'] === ModelsActualizer::STAGE_SUCCESS, warningText($corrected));
check('exactly one extra call was spent on the correction',
    count(stubCalls()) === 4, count(stubCalls()) . ' call(s)');
check('the run reports how many corrections it needed',
    ($corrected['analyzer']['corrections'] ?? 0) === 1, json_encode($corrected['analyzer'] ?? null));
check('the correction quotes the rejection verbatim', (static function (): bool {
    $call = stubCalls()[1] ?? null;
    $messages = (array)($call['messages'] ?? []);
    $last = (string)($messages[count($messages) - 1]['content'] ?? '');
    return str_contains($last, 'not a single JSON object')
        && ($messages[count($messages) - 2]['role'] ?? '') === 'assistant';
})());
check('the correction restates the contract',
    (static function (): bool {
        $call = stubCalls()[1] ?? null;
        $messages = (array)($call['messages'] ?? []);
        $last = (string)($messages[count($messages) - 1]['content'] ?? '');
        return str_contains($last, 'ONE JSON object and nothing else')
            && str_contains($last, 'no ``` fences')
            && str_contains($last, 'verbatim');
    })());
check('the correction asks for the documented shape without inventing ids',
    (static function (): bool {
        $call = stubCalls()[1] ?? null;
        $messages = (array)($call['messages'] ?? []);
        $last = (string)($messages[count($messages) - 1]['content'] ?? '');
        return str_contains($last, '{"models":[{"provider":string,"model":string}]')
            && !str_contains($last, 'nimbus');
    })());

echo "\n-- the refusal comes after two attempts, not after twenty --\n";
configure();
$svc = services();
$GLOBALS['stub_content'] = 'not json at all, and it will not become json';
$refused = $svc['actualizer']->run(jobFixture($svc['jobs']), 'test-worker');
check('the run refuses', $refused['stage'] === ModelsActualizer::STAGE_PROBLEMS, (string)$refused['stage']);
check('exactly two attempts were made for the first preset',
    count(stubCalls()) === ModelsActualizer::ANALYZER_ATTEMPTS, count(stubCalls()) . ' call(s)');
check('the refusal names the number of attempts',
    str_contains(warningText($refused), 'rejected after 2 attempt'), warningText($refused));
check('the refusal quotes the parser, not just "it failed"',
    str_contains((string)($refused['error']['message'] ?? ''), 'not a single JSON object'),
    json_encode($refused['error'] ?? null));
check('the refusal says which preset could not be answered',
    ($refused['error']['slug'] ?? '') === 'default', json_encode($refused['error'] ?? null));
check('the run did not go on to the other presets',
    count(stubCalls()) === 2, count(stubCalls()) . ' call(s) — later presets were asked anyway');

echo "\n-- an answer naming only unknown ids is still dropped, not trusted --\n";
configure();
$svc = services();
stubAnswer([
    'models'  => [['provider' => 'alpha', 'model' => 'openai/gpt-9-imaginary']],
    'arbiter' => ['provider' => 'alpha', 'model' => 'openai/gpt-9-imaginary'],
]);
$hallucinated = $svc['actualizer']->run(jobFixture($svc['jobs']), 'test-worker');
check('unknown ids end in problems rather than an empty pool',
    $hallucinated['stage'] === ModelsActualizer::STAGE_PROBLEMS, (string)$hallucinated['stage']);
check('the refusal says the ids were not in the catalogue',
    str_contains((string)($hallucinated['error']['message'] ?? ''), 'live catalogue'),
    json_encode($hallucinated['error'] ?? null));
check('inventing ids spends the one correction, like a broken shape does',
    count(stubCalls()) === ModelsActualizer::ANALYZER_ATTEMPTS, count(stubCalls()) . ' call(s)');
check('the correction tells the model to copy the ids verbatim',
    (static function (): bool {
        $call = stubCalls()[1] ?? null;
        $messages = (array)($call['messages'] ?? []);
        $last = (string)($messages[count($messages) - 1]['content'] ?? '');
        return str_contains($last, 'live catalogue') && str_contains($last, 'verbatim');
    })());
check('a MIXED answer is still resolved locally, not retried', (static function (): bool {
    // The documented tolerance, which the id rule must not replace: unknown
    // ids are dropped with a warning and the pool is built from the ones the
    // catalogue does serve. Only "nothing resolves" is a violation.
    configure();
    $svc = services();
    stubAnswer([
        'models'  => [
            ['provider' => 'alpha', 'model' => 'openai/gpt-5.5'],
            ['provider' => 'alpha', 'model' => 'openai/gpt-9-imaginary'],
        ],
        'arbiter' => ['provider' => 'alpha', 'model' => 'openai/gpt-5.5'],
    ]);
    $run = $svc['actualizer']->run(jobFixture($svc['jobs']), 'test-worker');
    return $run['stage'] === ModelsActualizer::STAGE_SUCCESS && count(stubCalls()) === 3;
})(), 'the mixed answer was retried or refused');
check('no preset was written', (static function () use ($svc): bool {
    foreach ($svc['presets']->all() as $preset) {
        foreach ($preset->models as $model) {
            if (str_contains((string)($model['model'] ?? ''), 'imaginary')) { return false; }
        }
    }
    return true;
})());

echo "\n-- the answer's own reason reaches the report --\n";
configure();
$svc = services();
stubAnswer([
    'models'  => [['provider' => 'alpha', 'model' => 'openai/gpt-5.5']],
    'arbiter' => ['provider' => 'alpha', 'model' => 'openai/gpt-5.5'],
    'reason'  => 'one flagship per family, arbiter is the strongest reasoner',
]);
$withReason = $svc['actualizer']->run(jobFixture($svc['jobs']), 'test-worker');
check('the run succeeds', $withReason['stage'] === ModelsActualizer::STAGE_SUCCESS, warningText($withReason));
check('every preset carries its reason',
    count((array)$withReason['reasons']) === 3
    && str_contains((string)($withReason['reasons']['default'] ?? ''), 'one flagship per family'),
    json_encode($withReason['reasons'] ?? null));

// =====================================================================
// v1.8.60 — staleness, the row cap against the referenced rows, and the
// post-actualization invariant.
//
// The incident these cover: the catalogue was capped at a hard-coded 64
// rows, so it could not represent the provider's live list; the pool, the
// presets and the L4 synthesis model were then pointed at models the
// catalogue did not hold; the run resolved an empty L1 pool, 404'd in L4 and
// still reported success. Three separate defects, checked one at a time.
// =====================================================================

echo "\n-- a model the provider no longer serves is marked stale, not left active --\n";
configure();
$svc = services();
// `alpha/retired-model` is in the persisted catalogue (as an inactive row the
// operator once had) but the provider answers without it — i.e. it is
// provably gone, not merely unlisted (an outage never marks anything).
RuntimeConfig::update(['models' => array_merge(
    (array)Config::get('models', []),
    [['provider' => 'alpha', 'model' => 'alpha/retired-model', 'active' => true]],
)]);
Config::reload($tmp . DIRECTORY_SEPARATOR . 'config.php');
$svc = services();
stubAnswer(['models' => [['provider' => 'alpha', 'model' => 'openai/gpt-5.5']]]);
$staleRun = $svc['actualizer']->run(jobFixture($svc['jobs']), 'test-worker');
$staleReport = (array)($staleRun['catalog'] ?? []);
check('the run succeeds', $staleRun['stage'] === ModelsActualizer::STAGE_SUCCESS, warningText($staleRun));
check('the retired row is reported as stale',
    ($staleReport['stale'] ?? 0) === 1
    && in_array('alpha/alpha/retired-model', (array)($staleReport['stale_ids'] ?? []), true),
    json_encode($staleReport));
$retired = null;
foreach ((array)Config::get('models', []) as $row) {
    if (($row['model'] ?? '') === 'alpha/retired-model') { $retired = $row; break; }
}
check('the stale row is written inactive, whatever the operator had set',
    is_array($retired) && ($retired['active'] ?? true) === false,
    json_encode($retired));
check('and it carries the marker and the reason, so the tab can say why',
    is_array($retired) && ($retired['stale'] ?? false) === true
    && str_contains((string)($retired['stale_reason'] ?? ''), 'did not list this model id'),
    json_encode($retired));
check('the staleness is disclosed in words', str_contains(warningText($staleRun), 'no longer served'),
    warningText($staleRun));
check('a row the provider still serves keeps its operator flag and no marker',
    (static function (): bool {
        foreach ((array)Config::get('models', []) as $row) {
            if (($row['model'] ?? '') === 'openai/gpt-5.5') {
                return ($row['active'] ?? false) === true && !isset($row['stale']);
            }
        }
        return false;
    })(), json_encode(Config::get('models', [])));

echo "\n-- the cap never drops a row the pool, a preset or a linked role references --\n";
// 11 live rows against a cap of 8. A blind slice would have kept the first 8
// in arrival order; what must survive is whatever the run is about to point
// at — including rows that sit at the END of the catalogue.
configure(['models.catalog.max_rows' => 8]);
$svc = services();
stubAnswer([
    'models'  => [['provider' => 'alpha', 'model' => 'anthropic/claude-sonnet-4']],
    'arbiter' => ['provider' => 'alpha', 'model' => 'openai/gpt-5.5'],
    'reason'  => 'the cap scenario',
]);
$narrow = $svc['actualizer']->run(jobFixture($svc['jobs']), 'test-worker');
$narrowReport = (array)($narrow['catalog'] ?? []);
check('the run succeeds under a cap that cannot hold the catalogue',
    $narrow['stage'] === ModelsActualizer::STAGE_SUCCESS, warningText($narrow));
check('the configured cap is applied',
    ($narrowReport['limit'] ?? 0) === 8 && ($narrowReport['kept'] ?? 0) === 8,
    json_encode($narrowReport));
check('the rows the cap left out are counted, and the rule is named',
    ($narrowReport['dropped'] ?? 0) > 0
    && count((array)($narrowReport['dropped_ids'] ?? [])) === (int)$narrowReport['dropped']
    && ($narrowReport['rule'] ?? '') === 'flagships_per_family',
    json_encode($narrowReport));
check('the surviving rows still span more than one provider family',
    (int)($narrowReport['families'] ?? 0) > 1, json_encode($narrowReport));
check('the cap is disclosed in words', str_contains(warningText($narrow), 'left out'),
    warningText($narrow));
check('every model the run is about to call is still a row of the catalogue', (static function () use ($narrow): bool {
    $keys = [];
    foreach ((array)Config::get('models', []) as $row) {
        $keys[strtolower((string)$row['provider']) . '|' . (string)$row['model']] = (bool)($row['active'] ?? false);
    }
    $wanted = [];
    foreach ((array)($narrow['selection'] ?? []) as $row) {
        $wanted[] = strtolower((string)$row['provider']) . '|' . (string)$row['model'];
    }
    foreach (['alpha|openai/gpt-5.5', 'alpha|anthropic/claude-sonnet-4.6'] as $linked) {
        $wanted[] = $linked;
    }
    foreach ($wanted as $key) {
        if (($keys[$key] ?? false) !== true) { return false; }
    }
    return true;
})(), json_encode([
    'pool'     => $narrow['selection'] ?? null,
    'kept'     => array_map(
        static fn(array $r): string => $r['provider'] . '|' . $r['model'],
        (array)Config::get('models', []),
    ),
]));
check('the invariant verdict is reported as checked-and-satisfied',
    (bool)($narrow['invariant']['ok'] ?? false) === true
    && (int)($narrow['invariant']['checked'] ?? 0) > 0,
    json_encode($narrow['invariant'] ?? null));

echo "\n-- a role pointing outside the catalogue is a refusal, with nothing written --\n";
configure(['pipeline.conflict_arbiter.model' => 'anthropic/claude-nonexistent']);
$svc = services();
$beforeRefusal = stateSignature($svc['presets']);
stubAnswer(['models' => [['provider' => 'alpha', 'model' => 'openai/gpt-5.5']]]);
$refused = $svc['actualizer']->run(jobFixture($svc['jobs']), 'test-worker');
check('the run ends in problems rather than in a self-contradicting success',
    $refused['stage'] === ModelsActualizer::STAGE_PROBLEMS, (string)$refused['stage']);
check('the failure is named as the invariant, not as a generic write error',
    ($refused['error']['reason'] ?? '') === 'catalog_invariant',
    json_encode($refused['error'] ?? null));
check('it names the role and the model that did not line up',
    str_contains((string)($refused['error']['invariant']['violations'][0]['message'] ?? ''), 'arbiter')
    && str_contains((string)($refused['error']['invariant']['violations'][0]['message'] ?? ''), 'anthropic/claude-nonexistent'),
    json_encode($refused['error']['invariant'] ?? null));
check('the summary says nothing was written',
    str_contains((string)($refused['message'] ?? ''), 'Nothing was written'),
    (string)($refused['message'] ?? ''));
check('nothing actually was', stateSignature($svc['presets']) === $beforeRefusal);
check('the refusal is still a verdict, not a crash: the stage is reported',
    ($refused['failed_stage'] ?? '') !== '', (string)($refused['failed_stage'] ?? ''));

// =====================================================================
// Clean up the fixture
// =====================================================================
$rm = static function (string $path) use (&$rm): void {
    if (is_dir($path)) {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') { continue; }
            $rm($path . DIRECTORY_SEPARATOR . $entry);
        }
        @rmdir($path);
        return;
    }
    @unlink($path);
};
$rm($tmp);

echo "\n=========================\n";
echo "Passes: {$passes}  Failures: {$failures}\n";
exit($failures === 0 ? 0 : 1);
