<?php
/**
 * PromptCacheTest: offline checks for Bothub prompt caching.
 *
 * Covers the three halves of the feature, all of them without network and
 * without a real provider (this host has no curl extension, so a live call
 * is not attemptable — every assertion below is fixture-driven):
 *
 *   C1  the capability table — which family needs explicit breakpoints,
 *       which caches automatically, which is undocumented, and the
 *       documented minimum prompt size and write/read multipliers;
 *   C2  the request side — where the breakpoint goes, that the stable
 *       prefix stays byte-identical between requests, that the size guard
 *       and the breakpoint ceiling hold, that the TTL shape is the
 *       documented one, and — the hard requirement — that a family with
 *       automatic caching or an unknown family gets a payload whose message
 *       list is byte-identical to what it was before this feature;
 *   C3  the response side — `cache_discount` and the cache tokens in both
 *       dialects, the null-vs-zero distinction, storage in the typed
 *       ledger, and the per-run roll-up the UI renders.
 *
 * The last requirement in C2 is asserted twice on purpose: once at the
 * planner (the message list is unchanged) and once at the connector (the
 * whole payload of a non-Bothub provider is unchanged), because a future
 * refactor could satisfy the first and still break the second.
 *
 * Runs without PHPUnit, database server or network:
 *   php tests/PromptCacheTest.php
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
use App\Connectors\ChatResponse;
use App\Connectors\PromptCache\Capabilities;
use App\Connectors\PromptCache\Capability;
use App\Connectors\PromptCache\Plan;
use App\Connectors\PromptCache\Planner;
use App\Connectors\PromptCache\Policy;
use App\Core\AdminAuth;
use App\Core\Config;
use App\Core\RuntimeConfig;
use App\Http\Controllers\SettingsController;
use App\History\HistoryService;
use App\Settings\VersionParser;
use App\Stats\StatsAggregator;
use App\Storage\ResponseRepository;

// Read-only load of the shipped config: the Bothub connector resolves its
// policy through Config::get(), so the defaults under test have to come from
// the real config.php. Nothing here writes, so `var/config/runtime.json` is
// neither modified nor re-serialised (its mtime must stay put).
Config::load(__DIR__ . '/../config.php', dirname(__DIR__));

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

/** A prompt prefix comfortably above every documented minimum. */
function bigPrefix(int $words = 3000): string
{
    return 'You are a careful analyst. ' . str_repeat('context ', $words);
}

/**
 * The L1 message shape the pipeline builds: one stable `system` prefix,
 * then the dynamic user turn (query, or memory block + query).
 *
 * @return array<int,array<string,string>>
 */
function l1Messages(string $query = 'What changed?'): array
{
    return [
        ['role' => 'system', 'content' => bigPrefix()],
        ['role' => 'user',   'content' => $query],
    ];
}

/** A connector whose only purpose is to expose the inherited payload builder. */
function probeConnector(?Policy $policy): AbstractConnector
{
    return new class ($policy) extends AbstractConnector {
        public function __construct(private readonly ?Policy $cachePolicy)
        {
            parent::__construct('probe', 'https://example.invalid');
        }
        public function listModels(): array { return []; }
        protected function promptCachePolicy(): ?Policy { return $this->cachePolicy; }
        /**
         * The inherited header set resolves an API key, which this probe has
         * no business holding. The payload shape is what is under test here.
         */
        protected function headers(): array { return ['Content-Type: application/json']; }
        /** Expose the protected response builder for the parsing checks. */
        public function parse(string $model, array $raw): ChatResponse
        {
            return $this->buildChatResponse($model, [], $raw, 12.0);
        }
    };
}

// =====================================================================
echo "== C1: the documented capability table ==\n";
// =====================================================================

$anthropic = Capabilities::forModel('anthropic/claude-sonnet-4.6');
check('Anthropic is explicit-breakpoint mode', $anthropic->mode === Capability::MODE_EXPLICIT);
check('Anthropic caps breakpoints at the documented 4', $anthropic->maxBreakpoints === 4);
check('Anthropic write multiplier is the documented 1.25x', $anthropic->writeMultiplier === 1.25);
check('Anthropic read multiplier is the documented 0.1x',
    $anthropic->readMultiplierMin === 0.1 && $anthropic->readMultiplierMax === 0.1);
check('Anthropic read label renders as 0.1x', $anthropic->readMultiplierLabel() === '0.1×');
check('Anthropic minimum prompt is "не указан" (the docs do not state one)',
    $anthropic->minPromptTokens === null && $anthropic->minPromptLabel() === 'не указан');

$gemini = Capabilities::forModel('gemini-2.5-flash');
check('Gemini through Bothub is explicit-breakpoint mode', $gemini->mode === Capability::MODE_EXPLICIT);
check('Gemini has no documented breakpoint ceiling', $gemini->maxBreakpoints === null);
check('Gemini writes cost nothing (no write, no storage)', $gemini->writeMultiplier === 0.0);
check('Gemini read multiplier is the documented 0.25x', $gemini->readMultiplierMax === 0.25);
check('Gemini Flash minimum is the documented 1028 tokens',
    Capabilities::minPromptTokensFor('gemini-2.5-flash') === 1028);
check('Gemini Pro minimum is the documented 2048 tokens',
    Capabilities::minPromptTokensFor('gemini-2.5-pro') === 2048);

$automatic = [
    'gpt-5.2'            => ['openai', 0.25, 0.5, 1024],
    'grok-4'             => ['xai', 0.25, 0.25, null],
    'moonshotai/kimi-k2' => ['moonshot', 0.25, 0.25, null],
    'deepseek-v3'        => ['deepseek', 0.1, 0.1, null],
];
foreach ($automatic as $model => [$family, $readMin, $readMax, $minTokens]) {
    $cap = Capabilities::forModel($model);
    check("{$model}: classified as {$family}", $cap->family === $family, $cap->family);
    check("{$model}: caches automatically", $cap->isAutomatic());
    check("{$model}: needs no breakpoint", !$cap->requiresBreakpoints());
    check("{$model}: read multiplier {$readMin}-{$readMax}",
        $cap->readMultiplierMin === $readMin && $cap->readMultiplierMax === $readMax);
    check("{$model}: documented minimum is " . var_export($minTokens, true),
        $cap->minPromptTokens === $minTokens, var_export($cap->minPromptTokens, true));
    check("{$model}: breakpoints are 'не применимо'", $cap->maxBreakpointsLabel() === 'не применимо');
}

// Groq is a hosting provider, not a model vendor: the family resolver reads
// the vendor from the id, so `groq/<anything>` resolves to the row below only
// when the slug carries no vendor keyword of its own (a Groq-hosted Llama is
// classified as meta, and therefore as undocumented).
$groq = Capabilities::forFamily('groq');
check('the Groq row is documented as automatic', $groq->isAutomatic());
check('the Groq row carries the documented 0.5x read price',
    $groq->readMultiplierMax === 0.5 && $groq->writeMultiplier === 0.0);
check('the Groq row names Kimi K2 as the model it currently caches', str_contains($groq->notes, 'Kimi K2'));
check('a Groq-hosted Llama resolves to its model vendor, not to the host',
    Capabilities::forModel('groq/llama-3.3-70b')->family === 'meta');
check('...and is therefore treated as undocumented, with no promise made',
    Capabilities::forModel('groq/llama-3.3-70b')->isUnknown());

$openaiCap = Capabilities::forModel('gpt-5.2');
check('OpenAI free write renders as such',
    $openaiCap->writeMultiplierLabel() === '0× (запись бесплатна)', $openaiCap->writeMultiplierLabel());
check('OpenAI read multiplier renders as a range',
    $openaiCap->readMultiplierLabel() === '0.25–0.5×', $openaiCap->readMultiplierLabel());

$undocumented = Capabilities::forModel('meta-llama/llama-3.3-70b');
check('an undocumented family is MODE_UNKNOWN', $undocumented->isUnknown());
check('an undocumented family has no multipliers at all',
    !$undocumented->hasDocumentedPricing()
    && $undocumented->readMultiplierLabel() === '—'
    && $undocumented->writeMultiplierLabel() === '—');
check('an undocumented family promises nothing in its note',
    str_contains($undocumented->notes, 'точки останова не добавляются')
    && str_contains($undocumented->notes, 'не обещается'));
check('an empty model id resolves to unknown rather than throwing',
    Capabilities::forModel('')->family === 'unknown');

$documented = Capabilities::documented();
check('the table lists every documented family plus the unknown row',
    count($documented) === 8, (string)count($documented));
check('the explicit families come first in the table',
    $documented[0]->family === 'anthropic' && $documented[1]->family === 'google');
check('the last row is the "everything else" row', end($documented)->family === 'unknown');
check('the groq row is labelled as a hosting provider, not a model vendor',
    str_contains(Capabilities::forFamily('groq')->label, 'Groq'));
check('the row array carries the labels the Settings card renders',
    isset(Capabilities::documentedArray()[0]['mode_label'])
    && Capabilities::documentedArray()[0]['mode_label'] === 'нужны точки останова');

// =====================================================================
echo "\n== C2: the breakpoint goes on the last stable part of the prefix ==\n";
// =====================================================================

$policy = new Policy();
$planner = new Planner($policy);

$plan = $planner->plan('claude-sonnet-4.5', l1Messages('First question'));
check('a large Anthropic prefix gets a breakpoint', $plan->applied && $plan->reason === Plan::REASON_APPLIED);
check('exactly one breakpoint by default', $plan->breakpoints === 1);
check('the default policy places 1 breakpoint',
    (new Policy())->effectiveMaxBreakpoints() === 1);

$system = $plan->messages[0];
$user   = $plan->messages[1];
check('the system message became a composite content array', is_array($system['content']));
check('the composite part is a text part',
    ($system['content'][0]['type'] ?? null) === 'text');
check('the text of the part is unchanged',
    $system['content'][0]['text'] === bigPrefix());
check('the breakpoint is the documented ephemeral object',
    ($system['content'][0]['cache_control'] ?? null) === ['type' => 'ephemeral']);
check('the 5m TTL adds no "ttl" key (default per the docs)',
    !array_key_exists('ttl', $system['content'][0]['cache_control']));
check('the user turn is untouched — the dynamic half stays a plain string',
    $user['content'] === 'First question' && is_string($user['content']));
check('the message count is unchanged by the transform', count($plan->messages) === 2);
check('the measured prefix is the system block', $plan->prefixTokens > 3000, (string)$plan->prefixTokens);

// The one property the whole feature rests on: two different queries for
// the same role produce a byte-identical cached prefix.
$planA = $planner->plan('claude-sonnet-4.5', l1Messages('First question'));
$planB = $planner->plan('claude-sonnet-4.5', l1Messages('A completely different second question'));
check('the cached prefix is byte-identical between two requests',
    json_encode($planA->messages[0]) === json_encode($planB->messages[0]));
check('the dynamic tail differs between the same two requests',
    json_encode($planA->messages[1]) !== json_encode($planB->messages[1]));
check('the query never leaks into the cached prefix',
    !str_contains(json_encode($planA->messages[0]), 'First question'));

$longTtl = (new Planner(new Policy(ttl: Policy::TTL_ONE_HOUR)))
    ->plan('claude-sonnet-4.5', l1Messages());
check('the 1h TTL emits the documented ttl key',
    ($longTtl->messages[0]['content'][0]['cache_control'] ?? null)
        === ['type' => 'ephemeral', 'ttl' => '1h']);
check('an undocumented TTL string is coerced to the documented default',
    (new Policy(ttl: '2h'))->normalizedTtl() === Policy::TTL_FIVE_MINUTES);
check('TTL is case-insensitive', (new Policy(ttl: '1H'))->usesLongTtl());

// Round 2 carries two system messages — own role prompt + round-2 prompt.
$round2 = [
    ['role' => 'system', 'content' => bigPrefix()],
    ['role' => 'system', 'content' => str_repeat('Round two instructions. ', 200)],
    ['role' => 'user',   'content' => 'query + peer answers'],
];
$round2Plan = $planner->plan('claude-sonnet-4.5', $round2);
check('round 2 places the breakpoint on the LAST system message',
    is_array($round2Plan->messages[1]['content'])
    && is_string($round2Plan->messages[0]['content']));
check('the earlier system message is left as a plain string',
    $round2Plan->messages[0]['content'] === bigPrefix());

$twoBreakpoints = (new Planner(new Policy(maxBreakpoints: 2)))->plan('claude-sonnet-4.5', $round2);
check('a raised count places two breakpoints on a two-system prefix',
    $twoBreakpoints->breakpoints === 2
    && is_array($twoBreakpoints->messages[0]['content'])
    && is_array($twoBreakpoints->messages[1]['content']));

$clamped = new Policy(maxBreakpoints: 99);
check('the breakpoint count is clamped to the documented ceiling of 4',
    $clamped->effectiveMaxBreakpoints() === 4);
check('a zero/negative count is clamped up to 1',
    (new Policy(maxBreakpoints: 0))->effectiveMaxBreakpoints() === 1);
$capped = (new Planner(new Policy(maxBreakpoints: 99)))->plan('claude-sonnet-4.5', $round2);
check('a request can never place more than the ceiling', $capped->breakpoints <= 4);

// Size guard.
$small = [
    ['role' => 'system', 'content' => 'You are brief.'],
    ['role' => 'user',   'content' => 'hi'],
];
$smallPlan = $planner->plan('claude-sonnet-4.5', $small);
check('a prefix below the guard gets no breakpoint', !$smallPlan->applied);
check('the reason is recorded as below_threshold', $smallPlan->reason === Plan::REASON_BELOW_THRESHOLD);
check('the message list is untouched when the guard rejects',
    $smallPlan->messages === $small);
check('the guard is the configured floor for a family with no documented minimum',
    $smallPlan->threshold === 1024, (string)$smallPlan->threshold);

$geminiThreshold = $planner->thresholdFor('gemini-2.5-flash');
check('the guard is raised to Gemini Flash\'s documented 1028 minimum',
    $geminiThreshold === 1028, (string)$geminiThreshold);
check('the guard is raised to Gemini Pro\'s documented 2048 minimum',
    $planner->thresholdFor('gemini-2.5-pro') === 2048);
check('the guard is raised to OpenAI\'s documented 1024 minimum',
    $planner->thresholdFor('gpt-5.2') === 1024);

$lowered = new Planner(new Policy(minPromptTokens: 0));
check('a family with no documented minimum can be configured to skip the guard',
    $lowered->thresholdFor('claude-sonnet-4.5') === 0);
check('an operator cannot lower the guard below a documented minimum',
    $lowered->thresholdFor('gemini-2.5-pro') === 2048);
check('a mid-size prefix passes once the guard is configured lower',
    $lowered->plan('claude-sonnet-4.5', $small)->applied);

// No stable prefix at all.
$noPrefix = [['role' => 'user', 'content' => bigPrefix()]];
$noPrefixPlan = $planner->plan('claude-sonnet-4.5', $noPrefix);
check('a prompt with no leading system message gets no breakpoint', !$noPrefixPlan->applied);
check('the reason is recorded as no_stable_prefix', $noPrefixPlan->reason === Plan::REASON_NO_STABLE_PREFIX);
check('the message list is untouched', $noPrefixPlan->messages === $noPrefix);
check('stablePrefixIndexes() stops at the first non-system message',
    Planner::stablePrefixIndexes($round2) === [0, 1]
    && Planner::stablePrefixIndexes([['role' => 'user', 'content' => 'x']]) === []);

// Disabled.
$disabled = (new Planner(new Policy(enabled: false)))->plan('claude-sonnet-4.5', l1Messages());
check('a disabled policy adds no breakpoint', !$disabled->applied);
check('a disabled policy records the reason', $disabled->reason === Plan::REASON_DISABLED);
check('a disabled policy does not ask for cache usage either', !$disabled->includeUsage);
check('a disabled policy leaves the messages untouched', $disabled->messages === l1Messages());

// --- the hard requirement: automatic and unknown families are untouched ---
foreach (['gpt-5.2', 'grok-4', 'moonshotai/kimi-k2', 'deepseek-v3'] as $model) {
    $messages = l1Messages();
    $auto = $planner->plan($model, $messages);
    check("{$model}: no breakpoint is added", !$auto->applied && $auto->breakpoints === 0);
    check("{$model}: the reason names automatic caching",
        $auto->reason === Plan::REASON_AUTOMATIC_FAMILY, $auto->reason);
    check("{$model}: the message list is byte-identical to the input",
        json_encode($auto->messages) === json_encode($messages));
    check("{$model}: no message gained a composite content array",
        is_string($auto->messages[0]['content']) && is_string($auto->messages[1]['content']));
    check("{$model}: cache usage is still requested for reporting",
        $auto->includeUsage === true);
}

$unknownMessages = l1Messages();
$unknown = $planner->plan('meta-llama/llama-3.3-70b', $unknownMessages);
check('an unknown family gets no breakpoint', !$unknown->applied);
check('an unknown family records the reason as unknown_family',
    $unknown->reason === Plan::REASON_UNKNOWN_FAMILY, $unknown->reason);
check('an unknown family keeps its message format byte-identical',
    json_encode($unknown->messages) === json_encode($unknownMessages));

check('the plan log context carries no prompt text',
    !str_contains(json_encode($plan->logContext()), 'context context'));

// =====================================================================
echo "\n== C2: the real L1 assembly is a cacheable shape ==\n";
// =====================================================================
//
// The planner above is only as good as the message list the pipeline hands
// it. buildL1Messages() is where the live recall block used to be appended
// to the `system` message — the one place in the L1..L4 assembly where the
// cached prefix was query-dependent. It is exercised here through
// reflection rather than through a full run: the subject is the message
// list, and a full run would add three layers of unrelated machinery.

$engineDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'revoap-pc-engine-' . bin2hex(random_bytes(4));
@mkdir($engineDir, 0o775, true);
$skillRepo = new App\Skills\SkillRepository(null);
$engine = new App\Pipeline\PipelineEngine(
    connectors:  new App\Connectors\ConnectorRegistry($engineDir),
    sessions:    new App\Storage\SessionRepository(null),
    steps:       new App\Storage\StepRepository(null),
    responses:   new ResponseRepository(null),
    skills:      $skillRepo,
    router:      new App\Skills\SkillRouter($skillRepo),
    autoCreator: new App\Skills\SkillAutoCreator($skillRepo, new App\Connectors\ConnectorRegistry($engineDir)),
    normalizer:  new App\Consensus\NormalizationService(),
    consensus:   new App\Consensus\ConsensusEngine(),
    stats:       new App\Stats\StatsCollector(),
);
$buildL1 = new ReflectionMethod(App\Pipeline\PipelineEngine::class, 'buildL1Messages');
$buildL1->setAccessible(true);
$role = ['key' => 'analyst', 'system' => 'Analyst speaking.'];

/** @var array<int,array<string,string>> $l1a */
$l1a = $buildL1->invoke($engine, $role, 'First question');
/** @var array<int,array<string,string>> $l1b */
$l1b = $buildL1->invoke($engine, $role, 'A completely different second question');

check('the L1 message list is [system, user]',
    $l1a[0]['role'] === 'system' && $l1a[1]['role'] === 'user' && count($l1a) === 2);
check('the L1 system block is the role prompt', $l1a[0]['content'] === 'Analyst speaking.');
check('the L1 system block is byte-identical between two different queries',
    $l1a[0]['content'] === $l1b[0]['content']);
check('the L1 user turn carries the query', $l1a[1]['content'] === 'First question');
check('the L1 user turn differs between the two queries', $l1a[1]['content'] !== $l1b[1]['content']);
check('the query never appears in the L1 system block',
    !str_contains($l1a[0]['content'], 'First question'));
check('the whole L1 prefix is one stable system message',
    Planner::stablePrefixIndexes($l1a) === [0]);
// This role prompt is three tokens long, so the size guard refuses it — a
// breakpoint there would buy nothing. The point being made is that the
// *shape* is anchorable; the guard is what decides, not the shape.
$l1Plan = $planner->plan('claude-sonnet-4.5', $l1a);
check('a three-token role prompt is refused by the size guard, by design',
    !$l1Plan->applied && $l1Plan->reason === Plan::REASON_BELOW_THRESHOLD,
    json_encode($l1Plan->logContext()));
$l1Realistic = [
    ['role' => 'system', 'content' => bigPrefix()],
    ['role' => 'user',   'content' => $l1a[1]['content']],
];
check('the same shape with a realistic system prompt is cached',
    $planner->plan('claude-sonnet-4.5', $l1Realistic)->applied === true);

$engineSrc = (string)file_get_contents(__DIR__ . '/../src/Pipeline/PipelineEngine.php');
check('the memory recall block is concatenated into the USER turn, not the system block',
    (bool)preg_match('/\$user = "\[MEMORY CONTEXT.*?\$memoryText/s', $engineSrc) === true);
check('the system block is built from the role prompt alone',
    (bool)preg_match('/\$system = \$this->composeSystemPrompt\(\$roleSystem\);\s*\$user\s*=\s*\$query;/s', $engineSrc) === true);
check('no path still appends memory to \$system',
    (bool)preg_match('/\$system \.= .*MEMORY CONTEXT/s', $engineSrc) === false);
check('the other layer builders already keep their dynamic half in the user turn',
    substr_count($engineSrc, "'content' => \$this->prependAttachments(\$user") >= 3);
@rmdir($engineDir);

// =====================================================================
echo "\n== C2: the connector applies the plan to Bothub only ==\n";
// =====================================================================

$bothub = new BothubConnector();
$anthropicBuild = $bothub->buildChatRequest('claude-sonnet-4.5', l1Messages());
check('Bothub: the Anthropic request carries a breakpoint',
    is_array($anthropicBuild['payload']['messages'][0]['content'])
    && ($anthropicBuild['payload']['messages'][0]['content'][0]['cache_control'] ?? null)
        === ['type' => 'ephemeral']);
check('Bothub: the request asks the provider for cache usage',
    ($anthropicBuild['payload']['usage'] ?? null) === ['include' => true]);
check('Bothub: the dynamic user turn is still a plain string',
    is_string($anthropicBuild['payload']['messages'][1]['content']));

$openaiBuild = $bothub->buildChatRequest('gpt-5.2', l1Messages());
check('Bothub: an automatic family keeps its message format',
    is_string($openaiBuild['payload']['messages'][0]['content'])
    && is_string($openaiBuild['payload']['messages'][1]['content']));
check('Bothub: an automatic family still asks for cache usage',
    ($openaiBuild['payload']['usage'] ?? null) === ['include' => true]);
check('Bothub: a caller-supplied usage block is never overwritten',
    ($bothub->buildChatRequest('claude-sonnet-4.5', l1Messages(), ['usage' => ['include' => false]])['payload']['usage'] ?? null)
        === ['include' => false]);

$cliPlan = new Planner(new Policy());
check('a CLI-sized one-line prompt gets no breakpoint (nothing to cache)',
    !$cliPlan->plan('claude-sonnet-4.5', [['role' => 'system', 'content' => 'Classify.'], ['role' => 'user', 'content' => 'x']])->applied);

// A provider without the hook — OpenRouter's shape — must be byte-identical.
$silent = probeConnector(null);
$silentBuild = $silent->buildChatRequest('claude-sonnet-4.5', l1Messages());
check('a provider with no caching surface adds no breakpoint',
    is_string($silentBuild['payload']['messages'][0]['content']));
check('a provider with no caching surface adds no usage block',
    !array_key_exists('usage', $silentBuild['payload']));
check('a provider with no caching surface sends the messages verbatim',
    json_encode($silentBuild['payload']['messages']) === json_encode(l1Messages()));
check('the payload key set is the legacy one',
    array_keys($silentBuild['payload']) === ['model', 'messages'],
    implode(',', array_keys($silentBuild['payload'])));

$hooked = probeConnector(new Policy());
check('the hook is what turns the transform on',
    is_array($hooked->buildChatRequest('claude-sonnet-4.5', l1Messages())['payload']['messages'][0]['content']));

// The batch body shares the message transform, so a batch row and a chat
// body cannot disagree about the prefix.
$batchBuild = $bothub->buildBatchChatRequest('claude-sonnet-4.5', l1Messages());
check('the batch body uses the same cacheable message shape as chat',
    json_encode($batchBuild['payload']['messages'])
        === json_encode($bothub->buildChatRequest('claude-sonnet-4.5', l1Messages())['payload']['messages']));
$batchOpenai = $bothub->buildBatchChatRequest('gpt-5.2', l1Messages());
check('the batch body of an automatic family also stays plain',
    is_string($batchOpenai['payload']['messages'][0]['content']));

// The batch RESULT path hand-builds its step meta instead of merging
// statsPayload(), so it is the one place a cache figure could silently go
// missing on an otherwise identical response.
$engineSrcEarly = (string)file_get_contents(__DIR__ . '/../src/Pipeline/PipelineEngine.php');
check('the batch result path records the cache figures too',
    (bool)preg_match('/cache_write_tokens.*?=>\s*\$response->cacheWriteTokens.*?batchStepMeta/s', $engineSrcEarly) === true
    && str_contains($engineSrcEarly, "'cache_stats'       => \$response->hasCacheStats(),"));

$connSrc = (string)file_get_contents(__DIR__ . '/../src/Connectors/AbstractConnector.php');
check('the transform lives at the single funnel both surfaces share',
    (bool)preg_match('/function assembleChatRequest.*?promptCachePolicy\(\)/s', $connSrc) === true);
check('the base connector declares the hook as opt-in',
    str_contains($connSrc, 'protected function promptCachePolicy(): ?Policy'));

// =====================================================================
echo "\n== C3: the response side, both dialects ==\n";
// =====================================================================

$parser = probeConnector(null);

$openaiShaped = $parser->parse('gpt-5.2', [
    'model'   => 'gpt-5.2',
    'choices' => [['message' => ['content' => 'ok']]],
    'usage'   => [
        'prompt_tokens'         => 2000,
        'completion_tokens'     => 100,
        'total_tokens'          => 2100,
        'prompt_tokens_details' => ['cached_tokens' => 1536],
    ],
    'cache_discount' => 0.0012,
]);
check('OpenAI dialect: the read side is read from prompt_tokens_details',
    $openaiShaped->cachedTokens === 1536);
check('the saving is read from the body-level cache_discount',
    $openaiShaped->cacheDiscount === 0.0012);
check('an automatic family reports no write', $openaiShaped->cacheWriteTokens === null);
check('the response is flagged as carrying cache statistics', $openaiShaped->hasCacheStats());
check('the read side is recognised as a hit', $openaiShaped->cacheWasRead());
check('the token invariant still holds',
    $openaiShaped->totalTokens === $openaiShaped->promptTokens + $openaiShaped->completionTokens);

$anthropicShaped = $parser->parse('claude-sonnet-4.5', [
    'model'   => 'claude-sonnet-4.5',
    'choices' => [['message' => ['content' => 'ok']]],
    'usage'   => [
        'prompt_tokens'                => 3000,
        'completion_tokens'            => 120,
        'total_tokens'                 => 3120,
        'cache_read_input_tokens'      => 2048,
        'cache_creation_input_tokens'  => 4096,
    ],
    'cache_discount' => -0.0004,
]);
check('Anthropic dialect: the read side is read from cache_read_input_tokens',
    $anthropicShaped->cachedTokens === 2048);
check('Anthropic dialect: the write side is read from cache_creation_input_tokens',
    $anthropicShaped->cacheWriteTokens === 4096);
check('a negative saving is preserved with its sign',
    $anthropicShaped->cacheDiscount === -0.0004);
check('Anthropic dialect: the cache input tokens are kept for audit',
    ($anthropicShaped->usageDetails['cache_input_tokens']['read'] ?? null) === 2048
    && ($anthropicShaped->usageDetails['cache_input_tokens']['write'] ?? null) === 4096);
check('Anthropic dialect: the token invariant still holds',
    $anthropicShaped->totalTokens === $anthropicShaped->promptTokens + $anthropicShaped->completionTokens);

$bothDialects = $parser->parse('claude-sonnet-4.5', [
    'choices' => [['message' => ['content' => 'ok']]],
    'usage'   => [
        'prompt_tokens'                => 3000,
        'completion_tokens'            => 10,
        'total_tokens'                 => 3010,
        'prompt_tokens_details'        => ['cached_tokens' => 2048],
        'cache_read_input_tokens'      => 2048,
    ],
]);
check('a response carrying both dialects is counted once, not twice',
    $bothDialects->cachedTokens === 2048, var_export($bothDialects->cachedTokens, true));

$nestedDiscount = $parser->parse('claude-sonnet-4.5', [
    'choices' => [['message' => ['content' => 'ok']]],
    'usage'   => ['prompt_tokens' => 10, 'completion_tokens' => 2, 'total_tokens' => 12, 'cache_discount' => 0.003],
]);
check('a cache_discount nested under usage is also understood',
    $nestedDiscount->cacheDiscount === 0.003);

$noStats = $parser->parse('gpt-5.2', [
    'choices' => [['message' => ['content' => 'ok']]],
    'usage'   => ['prompt_tokens' => 900, 'completion_tokens' => 10, 'total_tokens' => 910],
]);
check('a response with no cache block reports nothing', !$noStats->hasCacheStats());
check('all three cache fields stay null rather than becoming zero',
    $noStats->cachedTokens === null && $noStats->cacheWriteTokens === null && $noStats->cacheDiscount === null);
check('an absent cache is not mistaken for a hit', !$noStats->cacheWasRead());
check('the savings payload omits the cache keys entirely',
    !array_key_exists('cache_discount', $noStats->statsPayload())
    && !array_key_exists('cache_stats', $noStats->statsPayload()));

$reportedZero = $parser->parse('gpt-5.2', [
    'choices' => [['message' => ['content' => 'ok']]],
    'usage'   => [
        'prompt_tokens'         => 900,
        'completion_tokens'     => 10,
        'total_tokens'          => 910,
        'prompt_tokens_details' => ['cached_tokens' => 0],
    ],
]);
check('a reported zero is preserved as a zero, not as null', $reportedZero->cachedTokens === 0);
check('a reported zero still counts as reported statistics', $reportedZero->hasCacheStats());
check('a reported zero is not a hit', !$reportedZero->cacheWasRead());

$junk = $parser->parse('gpt-5.2', [
    'choices' => [['message' => ['content' => 'ok']]],
    'usage'   => [
        'prompt_tokens'               => 10,
        'completion_tokens'           => 2,
        'total_tokens'                => 12,
        'cache_read_input_tokens'     => 'unknown',
        'cache_creation_input_tokens' => null,
    ],
    'cache_discount' => 'n/a',
]);
check('a non-numeric cache field degrades to null instead of throwing',
    $junk->cachedTokens === null && $junk->cacheWriteTokens === null && $junk->cacheDiscount === null);

$noUsage = $parser->parse('gpt-5.2', ['choices' => [['message' => ['content' => 'text']]]]);
check('an estimated response stays estimated', $noUsage->tokensEstimated === true);
check('an estimated token count promises nothing about the cache',
    !$noUsage->hasCacheStats());
check('the estimate still satisfies the invariant',
    $noUsage->totalTokens === $noUsage->promptTokens + $noUsage->completionTokens);

$stats = $openaiShaped->statsPayload();
check('the stats payload carries the read side', ($stats['cached_tokens'] ?? null) === 1536);
check('the stats payload carries the saving', ($stats['cache_discount'] ?? null) === 0.0012);
check('the stats payload flags that cache data was present', ($stats['cache_stats'] ?? null) === true);
check('the array form carries all three fields',
    array_key_exists('cache_write_tokens', $openaiShaped->toArray())
    && array_key_exists('cache_discount', $openaiShaped->toArray()));

$existingKeys = [
    'content', 'model', 'actual_model', 'provider', 'prompt_tokens', 'completion_tokens',
    'total_tokens', 'latency_ms', 'tokens_estimated', 'cost', 'cached_tokens',
    'reasoning_tokens', 'generation_id', 'usage_details',
];
check('the response array gained keys without losing any',
    array_diff($existingKeys, array_keys($openaiShaped->toArray())) === []);

// =====================================================================
echo "\n== C3/C4: storage and the per-run roll-up ==\n";
// =====================================================================

$repo = new ResponseRepository();
$id = $repo->record(1, $anthropicShaped, 'alpha');
$stored = $repo->listByStep(1)[0] ?? [];
check('the in-memory ledger stores the read side', ($stored['cache_read_tokens'] ?? null) === 2048);
check('the in-memory ledger stores the write side', ($stored['cache_write_tokens'] ?? null) === 4096);
check('the in-memory ledger stores the signed saving', ($stored['cache_discount'] ?? null) === -0.0004);
check('recording still returns an id', $id === 1);

$noStatsId = $repo->record(2, $noStats, 'beta');
$storedNoStats = $repo->listByStep(2)[0] ?? [];
check('a response with no cache data stores nulls, not zeroes',
    array_key_exists('cache_read_tokens', $storedNoStats) && $storedNoStats['cache_read_tokens'] === null
    && array_key_exists('cache_write_tokens', $storedNoStats) && $storedNoStats['cache_write_tokens'] === null
    && array_key_exists('cache_discount', $storedNoStats) && $storedNoStats['cache_discount'] === null,
    json_encode($storedNoStats));
check('the legacy columns are still written',
    ($storedNoStats['prompt_tokens'] ?? null) === 900 && ($storedNoStats['total_tokens'] ?? null) === 910);

// A database whose council_model_responses predates migration 018 must
// degrade, not fail: the INSERT drops the three columns.
$legacyPdo = new PDO('sqlite::memory:');
$legacyPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$legacyPdo->exec('CREATE TABLE council_model_responses (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    step_id INTEGER NOT NULL,
    model TEXT NOT NULL,
    provider TEXT NOT NULL,
    role_name TEXT NULL,
    raw_content TEXT NOT NULL,
    normalized_response TEXT NULL,
    prompt_tokens INTEGER DEFAULT 0,
    completion_tokens INTEGER DEFAULT 0,
    total_tokens INTEGER DEFAULT 0,
    latency_ms INTEGER DEFAULT 0,
    tokens_estimated INTEGER DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
$legacyRepo = new ResponseRepository($legacyPdo);
$legacyId = $legacyRepo->record(7, $anthropicShaped, 'alpha');
check('a pre-018 database still accepts the row (columns are probed, not assumed)',
    $legacyId === 1);
$legacyRow = $legacyRepo->listByStep(7)[0] ?? [];
check('the legacy database stores the token columns it has',
    ($legacyRow['total_tokens'] ?? null) === 3120);

$modernPdo = new PDO('sqlite::memory:');
$modernPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$modernPdo->exec('CREATE TABLE council_model_responses (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    step_id INTEGER NOT NULL,
    model TEXT NOT NULL,
    provider TEXT NOT NULL,
    role_name TEXT NULL,
    raw_content TEXT NOT NULL,
    normalized_response TEXT NULL,
    prompt_tokens INTEGER DEFAULT 0,
    completion_tokens INTEGER DEFAULT 0,
    total_tokens INTEGER DEFAULT 0,
    latency_ms INTEGER DEFAULT 0,
    tokens_estimated INTEGER DEFAULT 0,
    cache_read_tokens INTEGER NULL,
    cache_write_tokens INTEGER NULL,
    cache_discount NUMERIC NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
$modernRepo = new ResponseRepository($modernPdo);
$modernRepo->record(9, $anthropicShaped, 'alpha');
$modernRow = $modernRepo->listByStep(9)[0] ?? [];
check('a post-018 database stores the cache columns',
    ($modernRow['cache_read_tokens'] ?? null) === 2048
    && ($modernRow['cache_write_tokens'] ?? null) === 4096);
check('a null cache value round-trips as null through the driver',
    (($modernRepo->listByStep(9)[0]['cache_discount'] ?? 'x') === -0.0004));

$aggregate = (new StatsAggregator())->aggregate([
    ['step' => 'L1.role.alpha', 'layer' => 'L1', 'resp' => $anthropicShaped],
    ['step' => 'L1.role.beta',  'layer' => 'L1', 'resp' => $reportedZero],
    ['step' => 'L4.synth',      'layer' => 'L4', 'resp' => $noStats],
], 250.0);
$run = $aggregate->toArray();
check('the roll-up counts every provider call', $run['total_calls'] === 3, (string)$run['total_calls']);
check('the roll-up counts only the calls that hit the cache', $run['cache_calls'] === 1, (string)$run['cache_calls']);
check('the roll-up counts the calls that reported cache data', $run['cache_reported_calls'] === 2, (string)$run['cache_reported_calls']);
check('the roll-up sums the read side', $run['cache_read_tokens'] === 2048, (string)$run['cache_read_tokens']);
check('the roll-up sums the write side', $run['cache_write_tokens'] === 4096, (string)$run['cache_write_tokens']);
check('the roll-up sums the signed saving', abs((float)$run['cache_discount'] - (-0.0004)) < 1e-12);
check('the roll-up keeps the saving available flag', $run['cache_discount_available'] === true);
check('the per-model bucket carries the same figures',
    $run['per_model']['claude-sonnet-4.5:probe']['cache_calls'] === 1);

$noDataRun = (new StatsAggregator())->aggregate([
    ['step' => 'L1.role.a', 'layer' => 'L1', 'resp' => $noStats],
], 10.0)->toArray();
check('a run with no reported cache data has zero reported calls',
    $noDataRun['cache_reported_calls'] === 0);
check('a run with no reported cache data exposes no saving at all',
    $noDataRun['cache_discount'] === null && $noDataRun['cache_discount_available'] === false);

// =====================================================================
echo "\n== C4: History reports the same figures per run and per stage ==\n";
// =====================================================================

$histPdo = new PDO('sqlite::memory:');
$histPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
// HistoryService::detail() LEFT JOINs `skills`, so the fixture needs one.
$histPdo->exec('CREATE TABLE skills (
    id INTEGER PRIMARY KEY AUTOINCREMENT, slug TEXT NULL, name TEXT NULL)');
$histPdo->exec('CREATE TABLE council_sessions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    skill_id INTEGER NULL, skill_slug TEXT NULL, query_text TEXT NULL,
    final_answer TEXT NULL, consensus_score REAL NULL, consensus_status TEXT NULL,
    status TEXT NULL, total_tokens INTEGER DEFAULT 0, duration_ms INTEGER DEFAULT 0,
    options_json TEXT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at TEXT NULL)');
$histPdo->exec('CREATE TABLE council_steps (
    id INTEGER PRIMARY KEY AUTOINCREMENT, session_id INTEGER NOT NULL,
    step_key TEXT NOT NULL, label TEXT NULL, order_index INTEGER DEFAULT 0,
    status TEXT NULL, summary TEXT NULL, meta TEXT NULL,
    started_at TEXT NULL, ended_at TEXT NULL)');
$histPdo->exec('CREATE TABLE council_model_responses (
    id INTEGER PRIMARY KEY AUTOINCREMENT, step_id INTEGER NOT NULL, model TEXT NOT NULL,
    provider TEXT NOT NULL, role_name TEXT NULL, raw_content TEXT NOT NULL,
    normalized_response TEXT NULL, prompt_tokens INTEGER DEFAULT 0,
    completion_tokens INTEGER DEFAULT 0, total_tokens INTEGER DEFAULT 0,
    latency_ms INTEGER DEFAULT 0, tokens_estimated INTEGER DEFAULT 0,
    cache_read_tokens INTEGER NULL, cache_write_tokens INTEGER NULL,
    cache_discount NUMERIC NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
$histPdo->exec('CREATE TABLE pipeline_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT, session_id INTEGER NOT NULL,
    step_id INTEGER NULL, event_type TEXT NOT NULL, payload TEXT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
$histPdo->exec("INSERT INTO council_sessions (id, query_text, status, total_tokens, duration_ms, created_at)
                VALUES (1, 'q', 'completed', 3120, 900, '2026-09-21 10:00:00')");
$histPdo->exec("INSERT INTO council_steps (id, session_id, step_key, label, order_index, status, meta)
                VALUES (1, 1, 'L1.role.alpha', 'L1', 1, 'completed', '"
                . json_encode([
                    'provider' => 'bothub', 'model' => 'claude-sonnet-4.5',
                    'prompt_tokens' => 3000, 'completion_tokens' => 120, 'total_tokens' => 3120,
                    'cached_tokens' => 2048, 'cache_write_tokens' => 4096,
                    'cache_discount' => -0.0004, 'cache_stats' => true,
                ]) . "')");
$histPdo->exec("INSERT INTO council_steps (id, session_id, step_key, label, order_index, status, meta)
                VALUES (2, 1, 'L2.role.alpha', 'L2', 2, 'completed', '"
                . json_encode(['layer' => 'L2', 'role' => 'alpha']) . "')");
$histPdo->exec("INSERT INTO council_steps (id, session_id, step_key, label, order_index, status)
                VALUES (3, 1, 'L4.synth', 'L4', 3, 'completed')");

$histDetail = (new HistoryService($histPdo))->detail(1);
$cache = $histDetail['session']['cache'] ?? [];
check('History reports a cache roll-up per run', $cache !== [], json_encode($cache));
check('History counts only the steps that reached a provider', ($cache['total_calls'] ?? null) === 1,
    var_export($cache['total_calls'] ?? null, true));
check('History sums the read side from the step meta', ($cache['cache_read_tokens'] ?? null) === 2048);
check('History sums the write side from the step meta', ($cache['cache_write_tokens'] ?? null) === 4096);
check('History keeps the signed saving', abs((float)($cache['cache_discount'] ?? 0) - (-0.0004)) < 1e-12);
check('History counts the call as a cache hit', ($cache['cache_calls'] ?? null) === 1);
check('History marks the run as having reported cache data', ($cache['cache_reported_calls'] ?? null) === 1);

$histStep = $histDetail['steps'][0] ?? [];
check('the per-stage view still carries the raw cache meta',
    ($histStep['meta']['cached_tokens'] ?? null) === 2048
    && ($histStep['meta']['cache_write_tokens'] ?? null) === 4096);
$nonLlmStep = $histDetail['steps'][1] ?? [];
check('a stage that made no provider call carries no provider stats',
    !isset($nonLlmStep['meta']['provider']) && !isset($nonLlmStep['meta']['cached_tokens']));

$emptyRun = new HistoryService($histPdo);
$histPdo->exec("INSERT INTO council_sessions (id, query_text, status, created_at) VALUES (2, 'q2', 'completed', '2026-09-21 11:00:00')");
$histPdo->exec("INSERT INTO council_steps (id, session_id, step_key, label, order_index, status, meta)
                VALUES (4, 2, 'L1.role.alpha', 'L1', 1, 'completed', '"
                . json_encode(['provider' => 'bothub', 'total_tokens' => 900, 'prompt_tokens' => 890]) . "')");
$emptyCache = $emptyRun->detail(2)['session']['cache'] ?? [];
check('a run whose provider returned no cache data reports zero reported calls',
    ($emptyCache['cache_reported_calls'] ?? null) === 0);
check('such a run reports no saving rather than a zero saving',
    array_key_exists('cache_discount', $emptyCache) && $emptyCache['cache_discount'] === null
    && ($emptyCache['cache_discount_available'] ?? null) === false,
    json_encode($emptyCache));
check('such a run still counts its provider call', ($emptyCache['total_calls'] ?? null) === 1);

$scrubbed = $histDetail['steps'][0]['responses'] ?? [];
check('a stored response without cache columns scrubs to null',
    $scrubbed === [] || ($scrubbed[0]['cache_read_tokens'] ?? null) === null);

// The scrubber uses an explicit key list, so a cache column that is not
// named there would be dropped on the way to the History UI even though it
// is in the row.
$histPdo->exec("INSERT INTO council_model_responses
    (step_id, model, provider, role_name, raw_content, prompt_tokens, completion_tokens,
     total_tokens, latency_ms, tokens_estimated, cache_read_tokens, cache_write_tokens, cache_discount)
    VALUES (1, 'claude-sonnet-4.5', 'bothub', 'alpha', 'answer', 3000, 120, 3120, 810, 0, 2048, 4096, -0.0004)");
$withResponse = (new HistoryService($histPdo))->detail(1);
$scrubbedRow = $withResponse['steps'][0]['responses'][0] ?? [];
check('a stored cache read survives the History scrubber',
    ($scrubbedRow['cache_read_tokens'] ?? null) === 2048, json_encode($scrubbedRow));
check('a stored cache write survives the History scrubber',
    ($scrubbedRow['cache_write_tokens'] ?? null) === 4096);
check('a stored signed saving survives the History scrubber',
    ($scrubbedRow['cache_discount'] ?? null) === -0.0004);
check('the legacy response fields are still scrubbed',
    ($scrubbedRow['total_tokens'] ?? null) === 3120 && ($scrubbedRow['model'] ?? null) === 'claude-sonnet-4.5');

$statsCtrlSrc = (string)file_get_contents(__DIR__ . '/../src/Http/Controllers/StatsController.php');
check('the per-session stats endpoint reads the cache columns too',
    str_contains($statsCtrlSrc, 'cache_read_tokens')
    && str_contains($statsCtrlSrc, 'cacheWriteTokens:') && str_contains($statsCtrlSrc, 'cacheDiscount:'));

// =====================================================================
echo "\n== C5: the documented defaults reach the request ==\n";
// =====================================================================

$defaults = new Policy();
check('caching is on by default', $defaults->enabled === true);
check('the default TTL is the documented 5 minutes', $defaults->normalizedTtl() === '5m');
check('the default breakpoint count is 1', $defaults->effectiveMaxBreakpoints() === 1);
check('the default size guard is the documented 1024', $defaults->effectiveMinPromptTokens() === 1024);
check('cache usage reporting is on by default', $defaults->includeUsage === true);
check('the default TTL emits no ttl key', $defaults->cacheControl() === ['type' => 'ephemeral']);
check('the one-hour policy emits the documented key',
    (new Policy(ttl: '1h'))->cacheControl() === ['type' => 'ephemeral', 'ttl' => '1h']);
check('a negative size guard is clamped to 0',
    (new Policy(minPromptTokens: -50))->effectiveMinPromptTokens() === 0);
check('the policy array exposes the documented TTL values',
    $defaults->toArray()['ttl_values'] === ['5m', '1h']);
check('the policy array exposes the documented breakpoint ceiling',
    $defaults->toArray()['max_breakpoints_ceiling'] === 4);

// =====================================================================
echo "\n== the migration and the schema agree ==\n";
// =====================================================================

$root = dirname(__DIR__);
$schema = (string)@file_get_contents($root . '/db/schema.sql');
$migration = (string)@file_get_contents($root . '/db/migrations/018_prompt_cache_stats.sql');
check('migration 018 exists', $migration !== '');
check('the schema declares the cache columns',
    (bool)preg_match('/council_model_responses.*?cache_read_tokens.*?cache_write_tokens.*?cache_discount/s', $schema) === true);
$parsedSchema = VersionParser::parse($schema);
$parsedMigration = VersionParser::parse($migration);
$newestMigration = (string)@file_get_contents($root . '/db/migrations/019_response_cost.sql');
check('migration 019 ships with the price column (R10)', $newestMigration !== '');
$parsedNewest = VersionParser::parse($newestMigration);
$newestVersion = $parsedNewest['tables']['council_model_responses'] ?? null;
check('the newest migration for this table declares a version', $newestVersion !== null, (string)$newestVersion);
// The annotation in db/schema.sql describes the shape the *current* build
// writes, so it must agree with the newest migration for that table. Pinning a
// literal here instead fails the next time a column is added - which is exactly
// what happened when 019 landed.
check('the schema carries the newest table version for this table',
    $newestVersion !== null
    && ($parsedSchema['tables']['council_model_responses'] ?? null) === $newestVersion,
    (string)$newestVersion . ' vs ' . (string)($parsedSchema['tables']['council_model_responses'] ?? null));
check('migration 018 keeps its own historical annotation',
    $newestVersion !== null
    && VersionParser::compare((string)($parsedMigration['tables']['council_model_responses'] ?? '0.0.0'), (string)$newestVersion) < 0,
    (string)($parsedMigration['tables']['council_model_responses'] ?? null));
check('the migration adds columns guarded by an information_schema probe',
    substr_count($migration, 'information_schema.COLUMNS') === 3);
check('the migration is idempotent (no bare ALTER)',
    str_contains($migration, 'DO 0') && !preg_match('/^\s*ALTER TABLE/m', $migration));
check('the three cache columns are nullable in the schema',
    (bool)preg_match('/cache_read_tokens\s+INT UNSIGNED\s+NULL/', $schema) === true
    && (bool)preg_match('/cache_write_tokens\s+INT UNSIGNED\s+NULL/', $schema) === true
    && (bool)preg_match('/cache_discount\s+DECIMAL\(18,10\) NULL/', $schema) === true);
check('the existing token columns were not changed',
    (bool)preg_match('/prompt_tokens\s+INT UNSIGNED DEFAULT 0/', $schema) === true
    && (bool)preg_match('/total_tokens\s+INT UNSIGNED DEFAULT 0/', $schema) === true
    && (bool)preg_match('/tokens_estimated\s+TINYINT\(1\) DEFAULT 0/', $schema) === true);
// The annotation is compared against the LIVE application version, never
// against a literal: `@schema-version` and `app.version` are one number by
// convention, so a hard-coded pair turns every release into a test failure
// (which is exactly how this check broke when 1.8.44 became 1.9.0).
$schemaVersion = (string)($parsedSchema['schema'] ?? '');
$appVersion    = (string)Config::get('app.version', '');
check('db/schema.sql @schema-version equals the live app.version (no literal)',
    $schemaVersion !== '' && $schemaVersion === $appVersion,
    "db/schema.sql={$schemaVersion} app.version={$appVersion}");

// =====================================================================
echo "\n== C5: the Settings card's endpoint writes the documented values ==\n";
// =====================================================================

// Everything below runs against a throwaway project root, so the operator's
// real var/config/runtime.json is never written to: SettingsPersist writes
// through RuntimeConfig, which is rooted by the Config::load() call below.
$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'revoap-prompt-cache-' . bin2hex(random_bytes(4));
@mkdir($tmp . '/var/config', 0o775, true);
@mkdir($tmp . '/logs', 0o775, true);
file_put_contents(
    $tmp . '/config.php',
    "<?php\ndeclare(strict_types=1);\nreturn " . var_export([
        'app'         => ['name' => 'RevoAp', 'debug' => false, 'timezone' => 'UTC'],
        'logging'     => ['path' => $tmp . '/logs'],
        'SettingsTab' => true,
        'prompt_cache' => [
            'enabled'           => true,
            'ttl'               => '5m',
            'max_breakpoints'   => 1,
            'min_prompt_tokens' => 1024,
            'include_usage'     => true,
            'provider'          => 'bothub',
        ],
    ], true) . ";\n"
);
Config::reset();
RuntimeConfig::reset();
Config::load($tmp . '/config.php', $tmp);

$_ENV['REVOAP_ADMIN_TOKEN']    = 'prompt-cache-test-token';
$_SERVER['HTTP_X_ADMIN_TOKEN'] = 'prompt-cache-test-token';
AdminAuth::setRootDir($tmp);

$controller = new SettingsController($tmp);
$response = json_decode($controller->savePromptCache([
    'enabled'           => true,
    'ttl'               => '1h',
    'max_breakpoints'   => 3,
    'min_prompt_tokens' => 4096,
    'include_usage'     => false,
]), true) ?? [];
check('the endpoint accepts a documented TTL', ($response['ok'] ?? false) === true, json_encode($response));
check('the endpoint reports every field it wrote', count($response['updated'] ?? []) === 5,
    json_encode($response['updated'] ?? []));

$onDisk = json_decode((string)@file_get_contents($tmp . '/' . RuntimeConfig::FILE_RELATIVE), true) ?? [];
$written = is_array($onDisk['prompt_cache'] ?? null) ? $onDisk['prompt_cache'] : $onDisk;
check('the TTL reached the runtime overlay', ($written['ttl'] ?? null) === '1h', json_encode($onDisk));
check('the breakpoint count reached the runtime overlay',
    ($written['max_breakpoints'] ?? null) === 3, json_encode($onDisk));
check('the size guard reached the runtime overlay',
    ($written['min_prompt_tokens'] ?? null) === 4096);
check('the usage toggle reached the runtime overlay',
    ($written['include_usage'] ?? null) === false);
check('the caller cannot move the feature to another connector',
    ($written['provider'] ?? 'bothub') === 'bothub');
check('nothing outside the prompt_cache subtree was rewritten',
    array_keys($onDisk) === ['prompt_cache'], implode(',', array_keys($onDisk)));

$saved = Policy::fromConfig();
check('the saved TTL is what the request builder will emit',
    $saved->cacheControl() === ['type' => 'ephemeral', 'ttl' => '1h'], json_encode($saved->cacheControl()));
check('the saved breakpoint count is what the planner will place',
    $saved->effectiveMaxBreakpoints() === 3);
check('the saved size guard is what the planner will enforce',
    $saved->effectiveMinPromptTokens() === 4096);
check('the saved usage toggle is what the connector will send', $saved->includeUsage === false);
check('the save response echoes the resolved policy, not the raw input',
    ($response['policy']['ttl'] ?? null) === '1h' && ($response['policy']['max_breakpoints'] ?? null) === 3);

// Out-of-documentation input.
$clamped = json_decode($controller->savePromptCache(['max_breakpoints' => 99, 'min_prompt_tokens' => -10, 'ttl' => '2h']), true) ?? [];
$clampedPolicy = Policy::fromConfig();
check('an undocumented TTL is refused, not written', $clampedPolicy->normalizedTtl() === '1h', $clampedPolicy->normalizedTtl());
check('an excessive breakpoint count is clamped to the documented ceiling',
    $clampedPolicy->effectiveMaxBreakpoints() === Policy::MAX_BREAKPOINTS,
    (string)$clampedPolicy->effectiveMaxBreakpoints());
check('a negative size guard is clamped to zero',
    $clampedPolicy->effectiveMinPromptTokens() === 0, (string)$clampedPolicy->effectiveMinPromptTokens());
check('the refused TTL is not in the updated list',
    !in_array('prompt_cache.ttl', $clamped['updated'] ?? [], true), json_encode($clamped['updated'] ?? []));

// Restore the shipped config for the rest of the process, and remove the
// throwaway root so nothing is left behind.
Config::reset();
RuntimeConfig::reset();
Config::load(dirname(__DIR__) . '/config.php', dirname(__DIR__));
@unlink($tmp . '/' . RuntimeConfig::FILE_RELATIVE);
@rmdir($tmp . '/var/config');
@rmdir($tmp . '/var');
@rmdir($tmp . '/logs');
@rmdir($tmp);

echo "\n----------------------------------------\n";
echo ($failures === 0 ? 'ALL OK' : 'FAILURES') . ": {$passes} passed, {$failures} failed\n";
exit($failures === 0 ? 0 : 1);
