<?php
/**
 * PipelineEngine: Orchestrates the RevoAp 4-layer council pipeline.
 *
 * Layers (forward-only, append-only):
 *  L0 — Skill routing (resolve or auto-create).
 *  L1 — Query distribution: each *selected model* answers in parallel.
 *  L2 — Normalization: enforce per-role schema/structure.
 *  L3 — Consensus analysis: pairwise agreement scoring.
 *  L4 — Synthesis: produce final answer with synthesis model.
 *
 * Loop detection halts the pipeline when the same step_key recurs.
 * No record is ever modified after creation.
 *
 * Major changes (1.1.0):
 *  - L1 is driven by the user-selected consensus pool, not by skill roles.
 *  - L1 calls run in parallel via ParallelChatDispatcher when available.
 *  - run() accepts `selected_models` and `reasoning` options.
 *  - An optional EventStreamer receives every event as it is emitted.
 *
 * Minor changes (1.8.1):
 *  - L4 synthesis target now falls back when the configured provider is
 *    inactive/unavailable: selected_models (preferring OpenRouter) →
 *    configured OpenRouter active model → first available active model.
 *  - Emits `L4_PROVIDER_FALLBACK` (event + trace) whenever the effective
 *    synthesis provider/model differs from the configured one.
 *
 * Major changes (1.8.16):
 *  - L1 → L2 → L3 is wrapped in a 1..max_consensus_rounds loop. When
 *    round-1 consensus is not `agreement` (or score below
 *    `pipeline.round2_score_threshold`), a second round is run with
 *    peer answers injected into each model's L1 prompt: each model
 *    receives the original query, its own round-1 answer, every other
 *    model's round-1 answer (labelled by role/provider/model) and the
 *    L3 verdict from round 1. The skill's `round2_system_prompt` (or
 *    the bundled one from consensus_reasoning.json) is appended as a
 *    second `system` message during round 2.
 *  - Emits `CONSENSUS_ROUND_STARTED`, `CONSENSUS_ROUND_COMPLETED`, and
 *    `CONSENSUS_ROUND_REPEAT` events. L1/L2/L3 event payloads and step
 *    `meta` JSON carry a `round` field. Step keys disambiguate
 *    round-2 with a `round2.` segment.
 *
 * Major changes (1.8.19):
 *  - Per-request `cycle_limit` option overrides the configured
 *    `pipeline.max_consensus_rounds` for one run. The effective limit
 *    is clamped to `pipeline.cycle_limit_min .. cycle_limit_max` before
 *    the loop starts; invalid values fall back to the config default.
 *    A `CONSENSUS_CYCLE_LIMIT_CLAMPED` event is emitted whenever a
 *    request asked for something outside the range.
 *  - Three new hard stops protect the run from runaway token burn:
 *      1. Hard ceiling: `cycle_limit_max` is a global maximum the
 *         loop can never exceed, independent of UI or config.
 *      2. No-new-L1 stop: a round that produces zero L1 results
 *         terminates the loop before another sweep is attempted.
 *      3. Stagnation stop: if the consensus score barely improves
 *         (delta below `consensus_stagnation_epsilon`) AND the status
 *         did not change, the loop stops early.
 *  - Emits `CONSENSUS_CYCLE_LIMIT_REACHED` (limit was hit without
 *    agreement) and `CONSENSUS_STAGNATION_DETECTED` (loop stopped
 *    early because scores plateaued) so the UI can render an explicit
 *    final state.
 *  - L1/L2/L3 event payloads and the PIPELINE_DONE event carry both
 *    `round` (current 1-based cycle) and `cycle_limit` (resolved
 *    effective limit) so the UI can render "Consensus Cycle X / Y".
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.8.34
 * @since    2026-05-05
 *
 * Major changes (1.8.34):
 *  - Domain auto-routing (mathematics / physics) no longer collapses
 *    the L1 model pool to zero when the preset's models are absent
 *    from the global active model catalogue. Preset selections are
 *    now resolved through a provider-authoritative path
 *    (resolveSelectionWithDiagnostics(.., providerAuthoritative=true))
 *    so any model whose provider connector is registered is accepted
 *    even if the model id is not in `config/models/openrouter.php`.
 *  - When a preset still yields zero usable models, run() walks an
 *    explicit fallback chain (original request → Default preset →
 *    pipeline.default_selected_models → active catalog) and emits an
 *    AUTO_ROUTING_NOTICE that records which fallback ran.
 *  - L0_OPTIONS carries a new `selection_diagnostic` payload (source,
 *    preset slug/name, rejected entries with reasons, fallback chain,
 *    accepted count) so the UI Raw tab can attribute every L0
 *    selection to its actual path.
 *  - L1_NO_AVAILABLE_MODELS, when it does fire, surfaces the
 *    requested domain, preset slug, candidate count, rejected
 *    entries, fallback chain, and the list of available providers —
 *    enough detail for an operator to tell at a glance whether the
 *    gap is a missing connector key, a stale preset, or a typo.
 *
 * Major changes (1.8.23):
 *  - When the council does not reach consensus (conflict / partial
 *    below threshold / cycle limit reached / stagnation / manual
 *    finalize), L4 now runs a final synthesis using the MAIN user
 *    selected model (first entry of selected_models in the user's
 *    order), instead of falling back to BotHub/default or the
 *    legacy arbiter. The L4 prompt includes the original query, the
 *    consensus status + score, the loop stop reason, and every
 *    collected L1 answer. The model is asked (RU/EN) to pick the
 *    most likely correct solution and produce a comprehensive
 *    answer, folding in reasonable nuances from the other answers
 *    and explicitly noting that the models did not fully agree.
 *  - Walks the selected list in user-supplied order on connector or
 *    network failure; once every selected model fails, falls
 *    through to the legacy arbiter / configured synthesis chain so
 *    PIPELINE_DONE is still emitted with whatever final content is
 *    available.
 *  - Emits L4_MAIN_MODEL_SYNTHESIS_STARTED before the first call
 *    (carrying synthesis_strategy=main_selected_model,
 *    selected_index=0, provider, model, reason) and includes the
 *    same metadata on the resulting L4_SYNTHESIS event.
 *  - Existing pipeline.conflict_arbiter still applies when no
 *    usable selected model is available, preserving the v1.8.x
 *    arbiter fallback for deployments that depend on it.
 *
 * Major changes (1.8.21):
 *  - Defense-in-depth loop invariant guard. Every iteration counts L1
 *    batches; if the count exceeds `cycle_limit + 1` the loop aborts
 *    with a `CONSENSUS_LOOP_GUARD_TRIGGERED` event and proceeds to
 *    final synthesis. This guards against unexpected re-entry paths
 *    (the v1.8.20 paste exhibited 10 phantom cycles caused by
 *    EventSource reconnect re-running /api/council/stream).
 *  - `run()` now also forwards `client_run_id` so downstream logs can
 *    correlate a duplicate-rejected stream with its original run.
 */
declare(strict_types=1);

namespace App\Pipeline;

use App\Batch\BatchL1PlannerInterface;
use App\Connectors\Batch\BatchCustomId;
use App\Cache\ModelResponseCache;
use App\Connectors\ChatResponse;
use App\Connectors\ConnectorRegistry;
use App\Connectors\Exceptions\ConnectorException;
use App\Connectors\ParallelChatDispatcher;
use App\Connectors\ParallelChatRequest;
use App\Consensus\ConsensusEngine;
use App\Consensus\ConsensusExplainer;
use App\Consensus\ConsensusResult;
use App\Consensus\NormalizationService;
use App\Core\Config;
use App\Core\ErrorDiagnostics;
use App\Core\Logger;
use App\Memory\EmbeddingMemory;
use App\Memory\MemoryItem;
use App\Helpers\StepKeyHelper;
use App\Helpers\TimeHelper;
use App\Skills\Skill;
use App\Skills\SkillAutoCreator;
use App\Skills\SkillRepository;
use App\Skills\SkillRouter;
use App\Skills\SkillSelector;
use App\Stats\SessionStats;
use App\Stats\StatsCollector;
use App\Storage\ResponseRepository;
use App\Storage\SessionRepository;
use App\Storage\StepRepository;

final class PipelineEngine implements BatchL1PlannerInterface
{
    /**
     * Run mode: ask ONE model and use its answer as the final answer.
     *
     * The run is deliberately single-threaded: exactly one L1 call is made
     * with the system prompt of the skill the run resolved, L2/L3/L4 are not
     * executed, and the model's own answer becomes `final_answer` verbatim -
     * no second model is asked to synthesise it. Because nothing was
     * compared, the run records NO consensus: `consensus_status = 'single'`
     * with `consensus_score = NULL` ("not measured" - never 0 and never the
     * trivial 1.0 the single-response path of the consensus engines would
     * otherwise report).
     *
     * Selected by `options.single_model === true`. The pool is respected but
     * never widened: auto-routing and model substitution are disabled so "one
     * model" means the model the operator picked, and the cycle limit is
     * ignored (there is no second round to run).
     */
    public const MODE_SINGLE_MODEL = 'single_model';

    /**
     * The consensus verdict stored for a run that computed none.
     *
     * Mirrors the value `council_sessions.consensus_status` accepts after
     * migration 022_single_model_consensus_status.sql. It is written only
     * when the column actually accepts it: a non-strict MySQL silently stores
     * an unknown ENUM value as `''`, so the engine checks first and degrades
     * to the consensus path with an explicit warning instead of leaving a
     * finished run with an empty verdict.
     */
    public const CONSENSUS_STATUS_SINGLE = 'single';

    /** @var string[] */
    private array $observedKeys = [];

    /** @var array<string,mixed>|null */
    private ?array $lastSynthesisStats = null;

    /**
     * v1.8.48 — why the final synthesis did NOT happen, or null when it did.
     *
     * `layer4Synthesis()` has always had a documented last resort: when no
     * connector is available, or the provider rejects the call, it returns
     * a locally assembled `fallbackSynthesis()` string so the run still
     * ends with *something*. What it never recorded was that the string is
     * not a synthesis — which meant `run()` went on to stamp the session
     * `completed` with a fallback body, and History showed a green
     * "completed" badge for a run whose L4 had failed (the reported
     * "L4 · Synthesis (failed) … recorded as completed").
     *
     * This property is the missing signal. It is set at every point that
     * returns a fallback and left null everywhere else, so
     * `synthesisFailed()` is exactly "the operator did not get a real
     * final answer" — never a guess derived from the text.
     *
     * @var array{reason:string,provider:?string,model:?string,message:?string,
     *             hint:?string,provider_code:?string,attempts:list<array<string,mixed>>}|null
     */
    private ?array $lastSynthesisFailure = null;

    /**
     * v1.8.48 — per-attempt failures of the main-selected-model
     * synthesis walk. Kept so the eventual failure record can name every
     * model that was tried, not just the last one.
     *
     * @var list<array<string,mixed>>
     */
    private array $lastSynthesisAttempts = [];

    /** @var array<int,array<string,mixed>> */
    private array $trace = [];

    private readonly ConsensusExplainer    $consensusExplainer;
    private readonly ParallelChatDispatcher $dispatcher;
    private ?ModelResponseCache $cache;
    private ?EmbeddingMemory $memory = null;

    /**
     * v1.8.32 — optional system-prompt assembler. When present, every
     * outbound `system` message routes through it so the base prompt
     * file + active profile addendum prefix the role/skill prompt.
     */
    private ?\App\Profiles\SystemPromptAssembler $promptAssembler = null;

    /** v1.8.32 — optional user-profile repository used by the assembler. */
    private ?\App\Profiles\UserProfileRepository $profileRepo = null;

    /** v1.8.32 — optional domain router for auto-routing math/physics. */
    private ?\App\DomainPresets\DomainRouter $domainRouter = null;
    private ?\App\DomainPresets\DomainPresetRepository $domainPresets = null;
    private ?SkillRepository $skillsForRouter = null;

    /** v1.8.32 — optional pre-pipeline request analyzer. */
    private ?\App\Analyzer\RequestAnalyzer $requestAnalyzer = null;

    /**
     * v1.8.32 — analyzer/domain routing metadata produced by the most
     * recent run. Surfaced to the SSE stream as a `routing_notice` event
     * and persisted into the session metadata.
     *
     * @var array<string,mixed>|null
     */
    private ?array $lastRoutingNotice = null;

    /**
     * v1.8.34 — diagnostic snapshot of the most recent selection resolution:
     * which source was used (request / domain_preset / request_fallback /
     * default_preset_fallback / config_default / active_catalog), the
     * rejected entries with reasons, the fallback chain attempted, and the
     * accepted count. Surfaced to the L0_OPTIONS payload so the UI Raw tab
     * can explain why a particular model set ran.
     *
     * @var array<string,mixed>|null
     */
    private ?array $lastSelectionDiagnostic = null;

    /**
     * v1.8.60 — the preflight verdict of the current run
     * ({@see self::preflightRun()}). Kept so the terminal verdict, the
     * envelope and the UI can all cite the same "what was checked" facts
     * instead of re-deriving them.
     *
     * @var array<string,mixed>|null
     */
    private ?array $lastPreflight = null;

    /**
     * v1.8.60 — how this run ended and why, from the run's own facts
     * (steps, events, resolved pool, answer body) rather than from prose.
     * {@see self::recordTerminalVerdict()} fills it; the terminal write and
     * the PIPELINE_DONE payload read it.
     *
     * @var array<string,mixed>
     */
    private array $terminalVerdict = [];

    /**
     * R9 extension - the share of corroborated content per participant in the
     * last consensus round, so the final card can say who contributed what.
     *
     * @var array<string,mixed>
     */
    private array $lastContribution = [];

    /**
     * v1.8.60 — the pool entries the request named that were refused, with
     * the reason each one was refused (`not_in_catalog` /
     * `connector_unavailable`). Recorded by
     * {@see self::resolveSelectedModels()} and quoted by the preflight, so a
     * refused run can name the models instead of only saying the pool came
     * back empty.
     *
     * @var array<int,array<string,mixed>>
     */
    private array $lastRequestRejections = [];

    /** Cooperative pause/reject control. Optional — null disables the feature. */
    private ?RunControl $runControl = null;

    private ?EventStreamer $streamer = null;
    /** @var array<string,mixed> */
    private array $runtimeOptions = [];
    private ?int $currentSessionId = null;
    /** @var array<int,array<string,mixed>> */
    private array $lastL1RequestOptions = [];
    /** Current consensus round number (1-based). Round 2+ adds peer context. */
    private int $currentRound = 1;

    /** Effective consensus cycle limit for the current run (resolved per run). */
    private int $currentCycleLimit = 1;

    /**
     * v1.8.23: resolved & validated selected-model list for the current run.
     * Populated by run() right after resolveSelectedModels() so the L4
     * main-model synthesizer can walk the same list (preserving user
     * order) the rest of the pipeline used for L1.
     *
     * @var array<int,array{provider:string,model:string}>
     */
    private array $resolvedSelectedModels = [];

    /**
     * Prompt versions this run works with (content fingerprints). Filled in
     * run() before the first model call and merged into every step's meta.
     *
     * @var array<string,mixed>
     */
    private array $promptVersions = [];

    /**
     * Provenance marker merged into the `meta` JSON of every step the
     * engine creates while it is finishing an interrupted session (see
     * {@see self::repairSession()}). Stays null during a normal run(), so
     * the normal path is byte-identical to the pre-repair behaviour.
     *
     * @var array<string,mixed>|null
     */
    private ?array $repairContext = null;

    public function __construct(
        private readonly ConnectorRegistry    $connectors,
        private readonly SessionRepository    $sessions,
        private readonly StepRepository       $steps,
        private readonly ResponseRepository   $responses,
        private readonly SkillRepository      $skills,
        private readonly SkillRouter          $router,
        private readonly SkillAutoCreator     $autoCreator,
        private readonly NormalizationService $normalizer,
        private readonly ConsensusEngine      $consensus,
        private readonly StatsCollector       $stats,
        ?ConsensusExplainer                   $consensusExplainer = null,
        ?ParallelChatDispatcher               $dispatcher          = null,
        ?ModelResponseCache                   $cache               = null,
        private readonly ?SkillSelector       $skillSelector       = null,
    ) {
        $this->consensusExplainer = $consensusExplainer ?? new ConsensusExplainer();
        $this->dispatcher         = $dispatcher          ?? new ParallelChatDispatcher();
        $this->cache              = $cache;
    }

    /** Inject (or replace) the model response cache. */
    public function setCache(?ModelResponseCache $cache): void
    {
        $this->cache = $cache;
    }

    /**
     * v1.8.46 — the cache as the CURRENT run is allowed to use it.
     *
     * `options.cache_mode = 'bypass'` opts a single run out of both the
     * lookup and the store, which is what an operator needs when a wrong
     * answer is suspected of being cached: the job layer reuses the cache
     * deliberately (see PipelineJobRunner::buildEngineOptions), so there
     * has to be a way to say "this run, fresh" without editing config.
     * Any other value (and the absent key) means the historical
     * behaviour: reuse and populate.
     */
    private function cacheForRun(): ?ModelResponseCache
    {
        if ($this->cache === null) {
            return null;
        }
        $mode = $this->runtimeOptions['cache_mode'] ?? 'reuse';
        if (is_string($mode) && strtolower($mode) === 'bypass') {
            return null;
        }
        return $this->cache;
    }

    /**
     * Task 5 - inject the optional vector memory facade. When null (the
     * historical default) the pipeline runs with "no memory"; when set,
     * recall/store are still config-gated so behaviour stays backwards
     * compatible until `memory.enabled` + `pipeline.memory_top_k` > 0.
     */
    public function setMemory(?EmbeddingMemory $memory): void
    {
        $this->memory = $memory;
    }

    public function setSystemPromptAssembler(?\App\Profiles\SystemPromptAssembler $a): void
    {
        $this->promptAssembler = $a;
    }

    public function setUserProfileRepository(?\App\Profiles\UserProfileRepository $r): void
    {
        $this->profileRepo = $r;
    }

    public function setDomainRouter(
        ?\App\DomainPresets\DomainRouter $router,
        ?\App\DomainPresets\DomainPresetRepository $repo = null,
        ?SkillRepository $skills = null,
    ): void {
        $this->domainRouter = $router;
        $this->domainPresets = $repo;
        $this->skillsForRouter = $skills;
    }

    public function setRequestAnalyzer(?\App\Analyzer\RequestAnalyzer $a): void
    {
        $this->requestAnalyzer = $a;
    }

    /**
     * Compose the final `system` message for the supplied role,
     * routing through SystemPromptAssembler when configured so the
     * base file + profile addendum prefix the role/skill prompt.
     * Falls back to the raw role prompt when no assembler is set so
     * the legacy behaviour is preserved.
     */
    private function composeSystemPrompt(string $rolePrompt): string
    {
        if ($this->promptAssembler === null) {
            return $rolePrompt;
        }
        $profile = null;
        if ($this->profileRepo !== null) {
            try {
                $profile = $this->profileRepo->findByExternalId(\App\Profiles\UserProfileRepository::LOCAL_EXTERNAL_ID);
            } catch (\Throwable) {
                $profile = null;
            }
        }
        return $this->promptAssembler->compose($profile, $rolePrompt);
    }

    /**
     * Routing notice snapshot for the most recent run. Surfaced by
     * CouncilController so the chat UI can render the "auto-routed to
     * Mathematics" banner.
     *
     * @return array<string,mixed>|null
     */
    public function lastRoutingNotice(): ?array
    {
        return $this->lastRoutingNotice;
    }

    public function cache(): ?ModelResponseCache
    {
        return $this->cache;
    }

    /** Inject (or replace) the cooperative run-control store. */
    public function setRunControl(?RunControl $control): void
    {
        $this->runControl = $control;
    }

    public function runControl(): ?RunControl
    {
        return $this->runControl;
    }

    /**
     * Cooperative checkpoint. Honors paused/rejected state for the
     * currently-running session, blocking on pause and throwing
     * PipelineRejectedException on reject. Safe to call between any two
     * pipeline steps; cheap when no control file exists.
     *
     * @throws PipelineRejectedException
     */
    private function checkpoint(int $sessionId, string $stage): void
    {
        if ($this->runControl === null) {
            return;
        }
        $state = $this->runControl->state($sessionId);
        if ($state === RunControl::STATE_REJECTED) {
            $this->emitEvent($sessionId, null, 'PIPELINE_REJECTED', [
                'stage'      => $stage,
                'session_id' => $sessionId,
                'reason'     => 'user_request',
            ]);
            throw new PipelineRejectedException('pipeline rejected by user at ' . $stage);
        }
        if ($state === RunControl::STATE_PAUSED) {
            $this->emitEvent($sessionId, null, 'PIPELINE_PAUSED', [
                'stage'      => $stage,
                'session_id' => $sessionId,
            ]);
            $resumed = $this->runControl->awaitResume($sessionId);
            if ($resumed === RunControl::STATE_REJECTED) {
                $this->emitEvent($sessionId, null, 'PIPELINE_REJECTED', [
                    'stage'      => $stage,
                    'session_id' => $sessionId,
                    'reason'     => 'rejected_during_pause',
                ]);
                throw new PipelineRejectedException('pipeline rejected by user during pause at ' . $stage);
            }
            // v1.8.20: pause→finalize transitions resume the pipeline so
            // the cycle-boundary check downstream can act on the
            // finalize state. We still emit PIPELINE_CONTINUED so the UI
            // un-greys the Run controls and exits "paused" mode.
            $this->emitEvent($sessionId, null, 'PIPELINE_CONTINUED', [
                'stage'      => $stage,
                'session_id' => $sessionId,
                'resumed_state' => $resumed,
            ]);
        }
        // v1.8.20: "finalize" is intentionally NOT acted on here — the
        // engine's consensus loop polls isManualFinalizeRequested() at the
        // cycle boundary and breaks out cleanly with whatever results
        // were collected. Treating it as reject would discard the run.
    }

    /**
     * v1.8.20: was the user-supplied "Stop and Run consensus" signal
     * raised for the running session? Used by the consensus cycle loop
     * to stop launching new rounds while still letting L4 synthesize a
     * final answer from whatever was already collected. Safe when
     * RunControl is unavailable (returns false).
     */
    private function isManualFinalizeRequested(int $sessionId): bool
    {
        return $this->runControl !== null
            && $this->runControl->isFinalizing($sessionId);
    }

    /**
     * v1.8.20: emit the pair of events that the UI uses to render
     * "Stop and Run consensus" — `CONSENSUS_MANUAL_FINALIZE_REQUESTED`
     * (informational, the user asked) and `PIPELINE_FINALIZING`
     * (terminal-for-collection, the engine is now heading to L4).
     */
    private function emitFinalizeRequested(int $sessionId, int $round, int $maxRounds, string $detail): void
    {
        $payload = [
            'session_id'  => $sessionId,
            'cycle'       => $round,
            'round'       => $round,
            'cycle_limit' => $maxRounds,
            'reason'      => 'user_request',
            'detail'      => $detail,
        ];
        $this->emitEvent($sessionId, null, 'CONSENSUS_MANUAL_FINALIZE_REQUESTED', $payload);
        $this->emitEvent($sessionId, null, 'CONSENSUS_MANUAL_FINALIZE_STARTED', $payload);
        $this->emitEvent($sessionId, null, 'PIPELINE_FINALIZING', $payload);
        Logger::info('pipeline.manual_finalize', [
            'session_id' => $sessionId,
            'cycle'      => $round,
            'cycle_limit'=> $maxRounds,
        ]);
    }

    /**
     * v1.8.20: prepend a single-line disclosure to the synthesised
     * final answer so the user sees that the run was manually
     * finalised before the consensus loop converged. The note cites
     * the cycle reached vs the configured limit and the number of
     * collected L1 results used by L4.
     */
    private function prependManualFinalizeNote(string $finalAnswer, int $cycle, int $cycleLimit, int $l1ResultCount): string
    {
        $cycle = max(1, $cycle);
        $cycleLimit = max($cycle, $cycleLimit);
        $resultsPhrase = $l1ResultCount === 1
            ? '1 model result'
            : ($l1ResultCount . ' model results');
        $note = '> Note: this run was manually finalised early via "Stop and Run consensus" '
            . 'after cycle ' . $cycle . ' of ' . $cycleLimit . '. '
            . 'The answer below was synthesised from the ' . $resultsPhrase . ' collected so far.';
        $body = ltrim($finalAnswer);
        return $body === '' ? $note : $note . "\n\n" . $body;
    }

    /**
     * Session id of the currently-executing run (or null between
     * runs). Used by the SSE controller to finalize running steps
     * when a fatal error escapes run().
     */
    public function currentSessionId(): ?int
    {
        return $this->currentSessionId;
    }

    /**
     * Attach an optional live event streamer. The pipeline will mirror
     * every appended event to it; the streamer is responsible for
     * sanitization and transport.
     */
    public function setEventStreamer(?EventStreamer $streamer): void
    {
        $this->streamer = $streamer;
    }

    /**
     * Run the full pipeline for a query.
     *
     * @param  array<string,mixed> $options Optional: 'selected_models' (array of {provider,model}),
     *                                      'reasoning' (string preset or array),
     *                                      'user_id' (legacy positional alternative).
     * @return array{session_id:int,final_answer:string,consensus:ConsensusResult,stats:SessionStats,steps:array<int,array<string,mixed>>,synthesis_stats:array<string,mixed>|null,selected_models:array<int,array<string,string>>}
     */
    public function run(string $query, ?int $userId = null, array $options = []): array
    {
        $started = TimeHelper::now();
        $this->trace = [];
        $this->observedKeys = [];
        $this->lastSynthesisStats = null;
        $this->lastSynthesisFailure = null;
        $this->lastSynthesisAttempts = [];
        $this->runtimeOptions = $options;
        $this->lastL1RequestOptions = [];
        $this->currentRound = 1;

        // Resolve final selected-model list.
        //
        // v1.8.60 — the pool the *caller* asked for is captured before
        // resolution, because "the request named four models and none of
        // them could be called" and "the caller named nothing and the
        // documented backstop ran" are different situations: the first is a
        // run that must be refused with the offending ids named, the second
        // is normal. `$requestedPool === null` means "the request supplied
        // no pool"; an empty array means "the request supplied an empty
        // one", which the backstop is free to fill.
        $requestedPool = isset($options['selected_models']) && is_array($options['selected_models'])
            ? array_values(array_filter($options['selected_models'], 'is_array'))
            : null;
        $requestedCount = $requestedPool === null ? 0 : count($requestedPool);
        // v1.9.5 - the Automatic Skill Selector entry. Such a run always
        // uses the Default configuration's models: what the selector picks
        // or builds must not depend on the composer's current pool. Auto
        // routing stays out of the way too - the selector IS the routing.
        $autoRequested = SkillSelector::isRequested($options);

        // Single-model mode. "One model, the skill's system prompts, no
        // consensus" is only true if the pool cannot be reshaped behind the
        // operator's back, so domain auto-routing is switched off here and
        // model substitution is skipped below - and the pool is NOT filled
        // from the default preset when the request named none.
        $singleModelMode = $this->singleModelModeRequested();
        // "The schema can carry the verdict" is part of whether the mode is
        // effective: a non-strict MySQL silently stores an unknown ENUM value
        // as an empty string, so a run whose verdict has nowhere to go would
        // finish with a blank consensus status. Probed once, and only for a
        // run that asked for the mode.
        $singleSchemaOk  = $singleModelMode && $this->consensusStatusSupportsSingle();
        if ($singleModelMode) {
            $options['lock_models']          = true;
            $options['disable_auto_routing'] = true;
        }
        if ($autoRequested) {
            $requestedPool = null;
            $requestedCount = 0;
            $options['lock_models'] = false;
            $options['disable_auto_routing'] = true;
        }
        $selectedModels = $this->resolveSelectedModels($requestedPool);
        if ($singleModelMode) {
            // One model means the first usable entry of the pool the operator
            // named - never the active catalogue, never a domain preset.
            $selectedModels = array_slice($selectedModels, 0, 1);
        }
        if ($autoRequested) {
            $autoPreset = $this->domainPresets?->findBySlug('default');
            $autoSelection = $autoPreset !== null
                ? $this->resolveSelectionWithDiagnostics($autoPreset->selectedModels(), 'preset', true)['accepted']
                : [];
            if ($autoSelection !== []) {
                $selectedModels = $autoSelection;
            }
        }

        // v1.8.32 — optional analyzer + domain auto-routing. When the
        // analyzer / router are configured AND the request did NOT lock
        // the model selection (options.lock_models !== true), the
        // engine swaps the selected pool and overrides the resolved
        // skill to the domain-specific one. Notice is surfaced over
        // SSE as a `routing_notice` event and persisted into the
        // session metadata so the UI can render an "auto-routed" banner.
        $routingNotice = null;
        $userLocked = (bool)($options['lock_models'] ?? false);
        $autoRoutingDisabled = (bool)($options['disable_auto_routing'] ?? false);
        $analyzerEnvelope = null;
        if ($this->requestAnalyzer !== null && !$autoRoutingDisabled) {
            try {
                $analyzerEnvelope = $this->requestAnalyzer->analyze($query);
            } catch (\Throwable) {
                $analyzerEnvelope = null;
            }
        } elseif ($this->domainRouter !== null && !$autoRoutingDisabled) {
            try {
                $heuristic = $this->domainRouter->classify($query);
                $analyzerEnvelope = [
                    'domain'           => $heuristic['domain'],
                    'confidence'       => $heuristic['confidence'],
                    'formula_expected' => $heuristic['formula_expected'],
                    'persona'          => '',
                    'suggested_skill'  => $heuristic['domain'] === 'mathematics' ? 'mathematics-reasoning'
                                          : ($heuristic['domain'] === 'physics' ? 'physics-reasoning' : null),
                    'suggested_preset' => $heuristic['domain'],
                    'signals'          => $heuristic['signals'],
                    'source'           => 'heuristic',
                    'reason'           => 'heuristic-only classification',
                    'model'            => null,
                    'provider'         => null,
                ];
            } catch (\Throwable) {
                $analyzerEnvelope = null;
            }
        }

        // v1.8.34 — Selection source bookkeeping for diagnostics. The
        // pipeline tries the domain preset first, then falls back to the
        // original request selection, then to the Default preset, then
        // to pipeline.default_selected_models / the active catalog. Each
        // step is recorded so AUTO_ROUTING_NOTICE / L0_OPTIONS /
        // L1_NO_AVAILABLE_MODELS can explain exactly what happened
        // without leaking secrets.
        $forcedSkillSlug = null;
        $selectionSource = 'request';
        $selectionRejected = [];
        $selectionPresetSlug = null;
        $selectionPresetName = null;
        $selectionFallbackChain = [];
        if ($analyzerEnvelope !== null
            && $this->domainPresets !== null
            && $analyzerEnvelope['domain'] !== 'default'
            && !$userLocked
        ) {
            $preset = $this->domainPresets->findBySlug((string)$analyzerEnvelope['suggested_preset']);
            if ($preset !== null) {
                $presetSelection = $preset->selectedModels();
                $candidateCount = count($presetSelection);
                $presetDiag = $this->resolveSelectionWithDiagnostics(
                    $presetSelection,
                    'preset',
                    true,
                );
                $selectionFallbackChain[] = [
                    'attempt'         => 'domain_preset',
                    'preset_slug'     => $preset->slug,
                    'preset_name'     => $preset->name,
                    'candidate_count' => $candidateCount,
                    'accepted_count'  => count($presetDiag['accepted']),
                    'rejected'        => $presetDiag['rejected'],
                ];
                if ($presetDiag['accepted'] !== []) {
                    $previous = $selectedModels;
                    $selectedModels = $presetDiag['accepted'];
                    $forcedSkillSlug = $preset->skillSlug ?? $analyzerEnvelope['suggested_skill'] ?? null;
                    $selectionSource = 'domain_preset';
                    $selectionPresetSlug = $preset->slug;
                    $selectionPresetName = $preset->name;
                    $selectionRejected = $presetDiag['rejected'];
                    $routingNotice = [
                        'auto_routed'      => true,
                        'domain'           => $analyzerEnvelope['domain'],
                        'preset_slug'      => $preset->slug,
                        'preset_name'      => $preset->name,
                        'skill_slug'       => $forcedSkillSlug,
                        'persona'          => (string)($analyzerEnvelope['persona'] ?? ''),
                        'confidence'       => (float)$analyzerEnvelope['confidence'],
                        'formula_expected' => (bool)($analyzerEnvelope['formula_expected'] ?? false),
                        'reason'           => (string)($analyzerEnvelope['reason'] ?? ''),
                        'source'           => (string)($analyzerEnvelope['source'] ?? 'heuristic'),
                        'previous_models'  => $previous,
                        'applied_models'   => $selectedModels,
                        'rejected_models'  => $presetDiag['rejected'],
                        'fallback_used'    => false,
                        'fallback_chain'   => $selectionFallbackChain,
                        'settings_anchor'  => '#oc-domain-models',
                    ];
                } else {
                    // Preset matched the query but yielded zero usable
                    // models — likely the v1.8.32/33 bug where preset
                    // entries weren't in the global active catalog. Try
                    // the original request selection (if any usable),
                    // then the Default preset, before giving up.
                    $fallbackApplied = null;
                    $fallbackPreset = null;
                    if ($selectedModels !== []) {
                        $fallbackApplied = $selectedModels;
                        $selectionSource = 'request_fallback';
                        $selectionFallbackChain[] = [
                            'attempt'         => 'original_request',
                            'accepted_count'  => count($selectedModels),
                        ];
                    } else {
                        $defaultPreset = $this->domainPresets->findBySlug('default');
                        if ($defaultPreset !== null) {
                            $defaultDiag = $this->resolveSelectionWithDiagnostics(
                                $defaultPreset->selectedModels(),
                                'preset',
                                true,
                            );
                            $selectionFallbackChain[] = [
                                'attempt'         => 'default_preset',
                                'preset_slug'     => $defaultPreset->slug,
                                'preset_name'     => $defaultPreset->name,
                                'accepted_count'  => count($defaultDiag['accepted']),
                                'rejected'        => $defaultDiag['rejected'],
                            ];
                            if ($defaultDiag['accepted'] !== []) {
                                $fallbackApplied = $defaultDiag['accepted'];
                                $fallbackPreset = $defaultPreset;
                                $selectionSource = 'default_preset_fallback';
                                $selectionRejected = $defaultDiag['rejected'];
                            }
                        }
                    }
                    if ($fallbackApplied !== null) {
                        $previous = $selectedModels;
                        $selectedModels = $fallbackApplied;
                        // Auto-route the skill (the user asked a math/
                        // physics question so the council should still
                        // wear that hat), but flag fallback so the UI
                        // can render an explicit notice.
                        $forcedSkillSlug = ($preset->skillSlug ?? $analyzerEnvelope['suggested_skill']) ?? null;
                        $selectionPresetSlug = $preset->slug;
                        $selectionPresetName = $preset->name;
                        $routingNotice = [
                            'auto_routed'      => true,
                            'domain'           => $analyzerEnvelope['domain'],
                            'preset_slug'      => $preset->slug,
                            'preset_name'      => $preset->name,
                            'skill_slug'       => $forcedSkillSlug,
                            'persona'          => (string)($analyzerEnvelope['persona'] ?? ''),
                            'confidence'       => (float)$analyzerEnvelope['confidence'],
                            'formula_expected' => (bool)($analyzerEnvelope['formula_expected'] ?? false),
                            'reason'           => (string)($analyzerEnvelope['reason'] ?? ''),
                            'source'           => (string)($analyzerEnvelope['source'] ?? 'heuristic'),
                            'previous_models'  => $previous,
                            'applied_models'   => $selectedModels,
                            'rejected_models'  => $selectionRejected,
                            'fallback_used'    => true,
                            'fallback_source'  => $selectionSource,
                            'fallback_preset'  => $fallbackPreset?->slug,
                            'fallback_chain'   => $selectionFallbackChain,
                            'settings_anchor'  => '#oc-domain-models',
                        ];
                    } else {
                        // Domain preset has zero usable models AND no
                        // fallback yielded usable models. Surface the
                        // routing notice so the UI can render an
                        // explicit "auto-routed but no models" banner;
                        // L1 will then emit L1_NO_AVAILABLE_MODELS with
                        // the full rejection trace.
                        $routingNotice = [
                            'auto_routed'      => false,
                            'domain'           => $analyzerEnvelope['domain'],
                            'preset_slug'      => $preset->slug,
                            'preset_name'      => $preset->name,
                            'skill_slug'       => $preset->skillSlug ?? $analyzerEnvelope['suggested_skill'] ?? null,
                            'persona'          => (string)($analyzerEnvelope['persona'] ?? ''),
                            'confidence'       => (float)$analyzerEnvelope['confidence'],
                            'formula_expected' => (bool)($analyzerEnvelope['formula_expected'] ?? false),
                            'reason'           => 'preset_yielded_zero_models',
                            'source'           => (string)($analyzerEnvelope['source'] ?? 'heuristic'),
                            'previous_models'  => $selectedModels,
                            'applied_models'   => $selectedModels,
                            'rejected_models'  => $presetDiag['rejected'],
                            'fallback_used'    => true,
                            'fallback_source'  => 'none',
                            'fallback_chain'   => $selectionFallbackChain,
                            'settings_anchor'  => '#oc-domain-models',
                        ];
                    }
                }
            }
        }
        $this->lastRoutingNotice = $routingNotice;
        $this->lastSelectionDiagnostic = [
            'source'           => $selectionSource,
            'preset_slug'      => $selectionPresetSlug,
            'preset_name'      => $selectionPresetName,
            // The preset path'own refusals when it ran, otherwise the
            // request path's — either way, "which entries did not make it".
            'rejected'         => $selectionRejected !== [] ? $selectionRejected : $this->lastRequestRejections,
            'fallback_chain'   => $selectionFallbackChain,
            'accepted_count'   => count($selectedModels),
            // v1.8.60 — what the request *asked* for, so a run that
            // resolved zero models out of a non-empty pool can say which
            // models were refused and why, instead of reporting an empty
            // pool as if nothing had been selected. Null when the caller
            // supplied no pool at all (the documented backstop paths).
            'requested_count'  => $requestedCount,
            'requested'        => $requestedPool,
        ];

        // v1.8.23: remember the resolved/validated list (in original user
        // order) so the L4 main-model synthesizer can walk it without
        // re-resolving against the catalog/connectors.
        $this->resolvedSelectedModels = $selectedModels;

        // L0 — skill resolution. v1.8.32 — domain auto-routing may force a
        // specific skill slug; if so, prefer it. v1.9.5 - the Automatic
        // Skill Selector entry resolves first: the skill this council runs
        // is the one it picks or builds.
        $autoPick = null;
        if ($autoRequested) {
            $resolved = $this->resolveSkillForRun($query);
            $skill    = $resolved['skill'];
            $autoPick = $resolved['auto'];
        } elseif ($forcedSkillSlug !== null && $this->skillsForRouter !== null) {
            $domainSkill = $this->skillsForRouter->findBySlug($forcedSkillSlug);
            $skill = $domainSkill ?? $this->resolveSkill($query);
        } else {
            $skill = $this->resolveSkill($query);
        }
        // v1.8.46 — Session reuse. Job mode
        // ({@see \App\Jobs\JobService::createJob()}) already created the
        // `council_sessions` row before the worker picked the job up and
        // published that id as `options.session_id` (see
        // PipelineJobRunner::buildEngineOptions). Every event this run
        // emits — and every step it persists — must land on THAT session,
        // because the browser follows
        // `/api/council/session/{id}/events/stream` for exactly that id and
        // nothing else. Ignoring the option here made the engine create a
        // second session, so the replay endpoint polled an empty id
        // forever while History showed the run on the other row.
        //
        // Honoured only when the caller supplies the option AND the row
        // actually exists; every legacy caller (POST /api/council/run,
        // GET /api/council/stream, POST /v1/runs) omits the key and keeps
        // the historical "engine creates the session" behaviour.
        $requestedSessionId = isset($options['session_id']) && is_numeric($options['session_id'])
            ? (int)$options['session_id']
            : 0;
        $sessionId = 0;
        if ($requestedSessionId > 0 && $this->sessions->find($requestedSessionId) !== null) {
            $sessionId = $requestedSessionId;
            Logger::info('pipeline.session.reused', [
                'session_id'    => $sessionId,
                'client_run_id' => $options['client_run_id'] ?? null,
            ]);
        }
        if ($sessionId <= 0) {
            $sessionId = $this->sessions->create($skill->id ?? 0, $query, $userId);
            if ($requestedSessionId > 0) {
                // The caller asked for a specific session that no longer
                // exists (pruned row / stale id). Falling back is correct
                // but worth surfacing.
                Logger::warn('pipeline.session.reuse_miss', [
                    'requested_session_id' => $requestedSessionId,
                    'assigned_session_id'  => $sessionId,
                ]);
            }
        }
        $this->currentSessionId = $sessionId;

        if ($routingNotice !== null) {
            $this->emitEvent($sessionId, null, 'AUTO_ROUTING_NOTICE', $routingNotice);
        }
        if ($analyzerEnvelope !== null) {
            $analyzerSnapshot = $analyzerEnvelope;
            unset($analyzerSnapshot['signals']); // keep payload compact
            $this->emitEvent($sessionId, null, 'REQUEST_ANALYZER', [
                'enabled' => $this->requestAnalyzer?->isEnabled() ?? false,
                'envelope' => $analyzerSnapshot,
            ]);
        }

        // Clear any stale run-control state from a prior session that
        // happened to recycle this id (memory-mode runs reset the
        // counter on each request). Best-effort.
        $this->runControl?->finalize($sessionId);

        // PIPELINE_STARTED carries the session id eagerly so the SSE
        // client can target /api/council/control before any model calls
        // even kick off. This is the very first event the engine emits
        // for the run.
        // v1.9.x - the plan travels with the first event so the interface can
        // show "step N of M" instead of only counting what it already drew.
        // The first cycle is K answers plus the question, L2, L3 and L4; later
        // cycles repeat L1-L4, which is why the ceiling is published too.
        // v1.9.x - a model the breaker already reports as broken is replaced
        // before anything is dispatched: the council keeps the size that was
        // asked for, and the substitution is published rather than silent.
        //
        // Single-model mode is exempt: its pool IS the request (one specific
        // model was named), and a swap would quietly turn "ask this model"
        // into "ask some other model". A model whose breaker is open then
        // fails the run and says so, which is the honest outcome.
        $substitution = $singleModelMode
            ? ['pool' => $selectedModels, 'replaced' => [], 'unfilled' => []]
            : \App\Pipeline\ModelSubstitution::plan(
                $selectedModels,
                $this->dispatcher->openModels(),
                \App\Core\Config::getActiveModels(),
            );
        if ($substitution['replaced'] !== [] || $substitution['unfilled'] !== []) {
            $selectedModels = $substitution['pool'];
            $this->emitEvent($sessionId, null, 'MODEL_SUBSTITUTED', [
                'replaced' => $substitution['replaced'],
                'unfilled' => $substitution['unfilled'],
                'reason'   => 'model_breaker_open',
            ]);
        }
        $planModels = count($selectedModels);
        // Single-model mode runs a shorter, fixed shape: L1 (one call), L2
        // (nothing), L3 (nothing), L4 (nothing). Publishing the council
        // arithmetic here would tell the interface to expect K + 4 steps for
        // a run that will only ever draw one.
        $planFirstCycle = $singleModelMode
            ? PipelinePlan::singleModelSteps()
            : PipelinePlan::firstCycleSteps($planModels);
        $planNextCycle  = $singleModelMode ? 0 : PipelinePlan::nextCycleSteps($planModels);
        $this->emitEvent($sessionId, null, 'PIPELINE_STARTED', [
            'session_id' => $sessionId,
            'skill'      => $skill->slug,
            'run_mode'   => $singleModelMode ? self::MODE_SINGLE_MODEL : null,
            'plan'       => [
                'models'            => $planModels,
                'steps_first_cycle' => $planFirstCycle,
                'next_cycle_steps'  => $planNextCycle,
                'cycles_max'        => $singleModelMode ? 1 : max(1, (int)$this->currentCycleLimit),
            ],
        ]);

        // v1.9.5 - the selector's decision, on the record: which skill was
        // picked or built, from where, and which role leads the council.
        if ($autoPick !== null) {
            $this->emitEvent($sessionId, null, 'SKILL_AUTO_SELECTED', [
                'skill'   => $skill->slug,
                'source'  => (string)($autoPick['source'] ?? ''),
                'created' => (bool)($autoPick['created'] ?? false),
                'role'    => $autoPick['role'] ?? null,
                'reason'  => mb_substr((string)($autoPick['reason'] ?? ''), 0, 400),
                'model'   => $autoPick['model'] ?? null,
                'papers'  => (int)($autoPick['papers'] ?? 0),
            ]);
        }

        // v1.9.x - the prompt versions the run is about to use. Content
        // fingerprints, not hand-kept numbers: a role prompt that changes gets
        // a new version by construction, and the step record shows which text
        // was in play when the run happened.
        $this->promptVersions = [
            'skill' => \App\Profiles\PromptFingerprint::ofAnything($skill),
            'roles' => [],
        ];
        foreach ($skill->roles() as $roleDef) {
            $roleName = (string)($roleDef['key'] ?? 'role');
            $this->promptVersions['roles'][$roleName] = \App\Profiles\PromptFingerprint::ofAnything($roleDef);
        }

        // History tab metadata — denormalised skill slug + a snapshot of
        // the runtime options so a session can be replayed and filtered
        // without joining other tables on every list query. Best-effort:
        // SessionRepository::setMetadata silently skips on hosts that
        // have not applied migration 004_history_session_metadata.sql.
        $sessionMeta = [
            'selected_models' => $selectedModels,
            'reasoning'       => $this->describeReasoning($options['reasoning'] ?? null),
            'parallel_l1'     => (bool)Config::get('pipeline.parallel_l1', true),
            'user_id'         => $userId,
        ];
        // v1.9.x - the names of the documents the operator attached, so the
        // History card can say which files a run read. Names only: the
        // contents belong to the run, not to the session catalogue.
        $attachmentNames = array_values(array_map(
            static fn(array $item): string => (string)$item['name'],
            $this->attachments(),
        ));
        if ($attachmentNames !== []) {
            $sessionMeta['attachments'] = $attachmentNames;
        }
        // v1.9.5 - what the Automatic Skill Selector decided, preserved with
        // the session so History can explain a run whose skill was not the
        // one the composer showed.
        if ($autoPick !== null) {
            $sessionMeta['auto_selector'] = [
                'source'  => (string)($autoPick['source'] ?? ''),
                'skill'   => $skill->slug,
                'created' => (bool)($autoPick['created'] ?? false),
                'role'    => $autoPick['role'] ?? null,
                'reason'  => mb_substr((string)($autoPick['reason'] ?? ''), 0, 400),
            ];
        }
        $this->sessions->setMetadata(
            $sessionId,
            $skill->slug !== '' ? $skill->slug : null,
            $sessionMeta,
        );

        Logger::info('pipeline.start', [
            'session_id'      => $sessionId,
            'query_chars'     => mb_strlen($query),
            'skill'           => $skill->slug,
            'selected_models' => array_map(
                static fn(array $m): string => (string)$m['provider'] . '/' . (string)$m['model'],
                $selectedModels,
            ),
            'parallel_l1'     => (bool)Config::get('pipeline.parallel_l1', true),
        ]);

        $skillSummary = $this->summarizeSkill($skill);
        $this->emitEvent($sessionId, null, 'L0_SKILL', $skillSummary);
        // L0_OPTIONS surfaces the runtime knobs (selected models, reasoning,
        // resolved consensus cycle limit) so the UI Raw tab can cite exactly
        // what the operator chose without probing each L1 step's meta.
        // v1.9.1 - who arbitrates. Named here, in the first event of the run,
        // so the UI can put it under the question before any model is called:
        // the preset that governed the selection owns the arbiter, and the
        // configured conflict arbiter is the documented fallback. `null` means
        // "this run has none", which the UI states instead of inventing one.
        $arbiterRow    = null;
        $arbiterSource = 'none';
        if ($selectionPresetSlug !== null && $this->domainPresets !== null) {
            $arbiterPreset = $this->domainPresets->findBySlug((string)$selectionPresetSlug);
            $presetArbiter = $arbiterPreset?->arbiter;
            if (is_array($presetArbiter) && (string)($presetArbiter['model'] ?? '') !== '') {
                $arbiterRow    = $presetArbiter;
                $arbiterSource = 'preset';
            }
        }
        if ($arbiterRow === null) {
            $configuredArbiter = Config::get('pipeline.conflict_arbiter', null);
            if (is_array($configuredArbiter)
                && ($configuredArbiter['enabled'] ?? true) !== false
                && (string)($configuredArbiter['model'] ?? '') !== '') {
                $arbiterRow = [
                    'provider' => (string)($configuredArbiter['provider'] ?? ''),
                    'model'    => (string)$configuredArbiter['model'],
                    'role'     => 'conflict',
                ];
                $arbiterSource = 'config';
            }
        }
        if (is_array($arbiterRow)) {
            $arbiterRow = [
                'provider' => (string)($arbiterRow['provider'] ?? ''),
                'model'    => (string)($arbiterRow['model'] ?? ''),
                'role'     => (string)($arbiterRow['role'] ?? 'conflict'),
            ];
        }
        $previewCycleResolution = $this->resolveCycleLimit($options['cycle_limit'] ?? null);
        $this->emitEvent($sessionId, null, 'L0_OPTIONS', [
            'selected_models' => $selectedModels,
            'reasoning'       => $this->describeReasoning($options['reasoning'] ?? null),
            'parallel_l1'     => (bool)Config::get('pipeline.parallel_l1', true),
            'cycle_limit'     => $previewCycleResolution['effective'],
            'cycle_limit_meta'=> [
                'requested' => $previewCycleResolution['requested'],
                'min'       => $previewCycleResolution['min'],
                'max'       => $previewCycleResolution['max'],
                'clamped'   => $previewCycleResolution['clamped'],
                'reason'    => $previewCycleResolution['reason'],
            ],
            // Single-model mode, on the record from the first event: which
            // mode was asked for, whether the schema can carry its verdict,
            // and that the cycle limit was ignored rather than honoured (the
            // clamped value above is still reported - it is what the run would
            // have used - but no second round will ever start).
            'run_mode'            => $singleModelMode ? self::MODE_SINGLE_MODEL : 'consensus',
            'single_model'        => [
                'requested'          => $singleModelMode,
                'effective'          => $singleModelMode && $singleSchemaOk,
                'schema_ok'          => $singleSchemaOk,
                'model'              => $singleModelMode ? ($selectedModels[0] ?? null) : null,
                'consensus_expected' => false,
                'cycles_ignored'     => $singleModelMode,
            ],
            // v1.8.34 — explain WHICH selection source produced the
            // resolved list (request / domain_preset / request_fallback /
            // default_preset_fallback / config_default / active_catalog)
            // so operators can debug auto-routing without grepping logs.
            // v1.9.1 - the arbiter travels with the participants: the card under
            // the question names both, and `arbiter_source` says whether the
            // name came from the run's preset or from the configuration.
            'arbiter'        => $arbiterRow,
            'arbiter_source' => $arbiterSource,            'selection_diagnostic' => $this->lastSelectionDiagnostic,
        ]);

        // v1.8.60 — the preflight. Every role this run is about to call has
        // to exist in the active catalogue *before* the run starts, so a
        // retired model is a refusal that names the role and the model, not
        // an HTTP 404 that arrives after eight minutes of model calls and
        // leaves a session marked `completed` behind it.
        $preflight = $this->preflightRun($selectedModels, $requestedPool, $requestedCount);
        $this->lastPreflight = $preflight;
        if ($preflight['notes'] !== []) {
            $this->emitEvent($sessionId, null, 'PIPELINE_PREFLIGHT', [
                'ok'      => $preflight['ok'],
                'notes'   => $preflight['notes'],
                'checked' => $preflight['facts'],
            ]);
        }
        if ($preflight['ok'] !== true) {
            $refusal = $preflight['message'];
            $this->recordTerminalVerdict([
                'status'   => 'failed',
                'reason'   => $preflight['reason'],
                'detail'   => $refusal,
                'role'     => $preflight['role'],
                'provider' => $preflight['provider'],
                'model'    => $preflight['model'],
                'stage'    => 'preflight',
            ]);
            $this->emitEvent($sessionId, null, 'PIPELINE_PREFLIGHT_FAILED', [
                'role'     => $preflight['role'],
                'provider' => $preflight['provider'],
                'model'    => $preflight['model'],
                'reason'   => $preflight['reason'],
                'detail'   => $refusal,
                'facts'    => $preflight['facts'],
            ]);
            if ($preflight['role'] === 'pool') {
                // The engine has always told the UI *this* way that a run
                // found nothing to call ({@see self::layer1Distribute()}),
                // and the refusal above is exactly that condition — caught
                // one stage earlier, before the doomed L2/L3/L4 round trip.
                // Same event, same payload shape, so every consumer that
                // already renders it keeps working.
                $diag = $this->lastSelectionDiagnostic ?? [];
                $this->emitEvent($sessionId, null, 'L1_NO_AVAILABLE_MODELS', [
                    'detail' => 'No usable models in the selected pool. Configure connectors with valid API keys '
                              . 'and pick at least one available model in the UI.',
                    'round'                => 0,
                    'requested_domain'     => $this->lastRoutingNotice['domain'] ?? null,
                    'preset_slug'          => $diag['preset_slug'] ?? null,
                    'preset_name'          => $diag['preset_name'] ?? null,
                    'selection_source'     => $diag['source'] ?? null,
                    'candidate_count'      => (int)($diag['requested_count'] ?? 0),
                    'rejected_models'      => $diag['rejected'] ?? [],
                    'fallback_chain'       => $diag['fallback_chain'] ?? [],
                    'available_providers'  => $this->availableProviderList(),
                    'settings_anchor'      => '#oc-domain-models',
                    'refused_by'           => 'preflight',
                    'refusal_reason'       => $preflight['reason'],
                ]);
                Logger::warn('L1 has no usable selected models', [
                    'selection_source' => $diag['source'] ?? null,
                    'rejected_count'   => is_array($diag['rejected'] ?? null) ? count($diag['rejected']) : 0,
                    'refused_by'       => 'preflight',
                ]);
            }
            Logger::warn('pipeline.preflight_failed', [
                'session_id' => $sessionId,
                'role'       => $preflight['role'],
                'provider'   => $preflight['provider'],
                'model'      => $preflight['model'],
                'reason'     => $preflight['reason'],
            ]);
            // Nothing was called and nothing was synthesised, so the session
            // must not carry an answer: an empty final_answer with a
            // `failed` status and a named reason is the honest record.
            $this->finalizeRunningSteps($sessionId, $refusal);
            $this->sessions->complete(
                id:             $sessionId,
                finalAnswer:    '',
                consensusScore: null,
                consensusStatus:'pending',
                totalTokens:    0,
                durationMs:     (int)round(TimeHelper::elapsedMs($started)),
                status:         'failed',
            );
            $this->emitEvent($sessionId, null, 'PIPELINE_DONE', [
                'session_id'      => $sessionId,
                'duration_ms'     => (int)round(TimeHelper::elapsedMs($started)),
                'consensus'       => null,
                'final_answer'    => '',
                'synthesis_stats' => null,
                'cycle'           => 0,
                'cycle_limit'     => $previewCycleResolution['effective'],
                'loop_stop_reason'=> 'preflight_failed',
                'status'          => 'failed',
                'synthesis_failed'=> false,
                'status_reason'   => $preflight['reason'],
                'status_detail'   => $refusal,
            ]);

            return [
                'session_id'        => $sessionId,
                'final_answer'      => '',
                'consensus'         => null,
                'stats'             => null,
                'steps'             => $this->steps->listStepsBySession($sessionId),
                'synthesis_stats'   => null,
                'selected_models'   => $selectedModels,
                'status'            => 'failed',
                'status_reason'     => $preflight['reason'],
                'status_detail'     => $refusal,
                'preflight'         => $preflight,
                'synthesis_failure' => null,
            ];
        }

        try {
            // ---- Single-model mode: one call, no consensus --------------
            // A run that deliberately has nothing to compare needs no cycle
            // ceiling, so one is not resolved here and no clamp event is
            // emitted: announcing a clamp for a limit nothing will use is
            // noise dressed as information. `$options['single_model']` stays in
            // place so repairSession() - which is handed the session's own
            // options - can still recognise the mode it is finishing.
            if ($singleModelMode && $singleSchemaOk) {
                $this->currentCycleLimit = 1;
                if ($this->isManualFinalizeRequested($sessionId)) {
                    // The answer already exists by the time anyone could press
                    // "Stop and Run consensus", so say the request was seen and
                    // had nothing left to do - rather than silently ignoring a
                    // control the operator believes they clicked.
                    $this->emitEvent($sessionId, null, 'SINGLE_MODEL_FINALIZE_IGNORED', [
                        'mode'   => self::MODE_SINGLE_MODEL,
                        'detail' => 'A finalize request arrived for a single-model run, which had already '
                            . 'produced its final answer from the one model it called; nothing was started.',
                    ]);
                }
                return $this->runSingleModel(
                    sessionId:      $sessionId,
                    skill:          $skill,
                    query:          $query,
                    selectedModels: $selectedModels,
                    started:        $started,
                );
            }

            $cycleResolution = $this->resolveCycleLimit($options['cycle_limit'] ?? null);
            $maxRounds = $cycleResolution['effective'];
            $this->currentCycleLimit = $maxRounds;
            $roundThreshold = (float)Config::get('pipeline.round2_score_threshold', 0.85);
            $stagnationEpsilon = (float)Config::get('pipeline.consensus_stagnation_epsilon', 0.01);
            if ($singleModelMode) {
                // The operator asked for one model and its answer, but the
                // verdict has nowhere to go: this database has not applied
                // db/migrations/022_single_model_consensus_status.sql, and a
                // non-strict MySQL would store 'single' as an empty string.
                // The run therefore keeps the consensus path - announced, not
                // silent - instead of finishing with a blank consensus status.
                $this->emitEvent($sessionId, null, 'SINGLE_MODEL_MODE_UNAVAILABLE', [
                    'mode'   => self::MODE_SINGLE_MODEL,
                    'reason' => 'schema_unsupported',
                    'detail' => 'Single-model mode was requested but this database does not accept the '
                        . "'single' consensus verdict, so the run would have stored a blank one. The "
                        . 'consensus pipeline ran instead; apply '
                        . 'db/migrations/022_single_model_consensus_status.sql to enable the mode.',
                    'migration' => 'db/migrations/022_single_model_consensus_status.sql',
                ]);
                Logger::warn('pipeline.single_model.degraded', [
                    'session_id' => $sessionId,
                    'reason'     => 'schema_unsupported',
                ]);
            }
            $cycleResolution = $this->resolveCycleLimit($options['cycle_limit'] ?? null);
            $maxRounds = $cycleResolution['effective'];
            $this->currentCycleLimit = $maxRounds;
            $roundThreshold = (float)Config::get('pipeline.round2_score_threshold', 0.85);
            $stagnationEpsilon = (float)Config::get('pipeline.consensus_stagnation_epsilon', 0.01);
            if ($cycleResolution['clamped']) {
                $this->emitEvent($sessionId, null, 'CONSENSUS_CYCLE_LIMIT_CLAMPED', [
                    'requested'   => $cycleResolution['requested'],
                    'effective'   => $maxRounds,
                    'min'         => $cycleResolution['min'],
                    'max'         => $cycleResolution['max'],
                    'reason'      => $cycleResolution['reason'],
                ]);
            }
            $consensus     = null;
            $l1Results     = [];
            $l2Normalized  = [];
            // Round-1 snapshots preserved for the round-2 prompt builder.
            /** @var array<int,array{role:array<string,mixed>,response:ChatResponse,step_id:int}> $round1L1 */
            $round1L1      = [];
            /** @var ConsensusResult|null $round1Consensus */
            $round1Consensus = null;

            // How the loop terminated. Drives the PIPELINE_DONE summary and
            // surfaces in the final answer card so users see whether the
            // run ended on agreement, the cycle limit, stagnation, or a
            // missing-L1 failure.
            $loopStopReason   = 'completed';
            $previousScore    = null;
            $previousStatus   = null;
            // v1.8.21: defense-in-depth invariant guard. Independent of
            // the `for` ceiling, count L1 batches and bail with a
            // diagnostic event if the count ever exceeds the contract.
            $l1BatchesRun  = 0;
            $l1GuardMax    = $maxRounds + 1; // allow one extra in-flight; tighter bound than `<= maxRounds`

            for ($round = 1; $round <= $maxRounds; $round++) {
                if ($l1BatchesRun >= $l1GuardMax) {
                    $this->emitEvent($sessionId, null, 'CONSENSUS_LOOP_GUARD_TRIGGERED', [
                        'round'         => $round,
                        'cycle'         => $round,
                        'cycle_limit'   => $maxRounds,
                        'l1_batches_run'=> $l1BatchesRun,
                        'guard_max'     => $l1GuardMax,
                        'detail'        => 'Loop invariant guard triggered — refusing to start another L1 batch. '
                            . 'This is a defense-in-depth stop; if you see it the cycle-limit logic above failed.',
                    ]);
                    Logger::warn('pipeline.loop_guard', [
                        'session_id'      => $sessionId,
                        'round'           => $round,
                        'l1_batches_run'  => $l1BatchesRun,
                        'guard_max'       => $l1GuardMax,
                    ]);
                    $loopStopReason = 'loop_guard_triggered';
                    $this->currentRound = max(1, $round - 1);
                    break;
                }
                $this->currentRound = $round;
                $this->observedKeys = []; // allow same logical step in next round (different key suffix)

                // v1.8.20: "Stop and Run consensus" requested BEFORE this
                // cycle has issued any model calls — break out and let L4
                // synthesise from results collected in earlier cycles.
                // For round 1 specifically, we still need at least one L1
                // result for synthesis to be meaningful; the engine will
                // attempt L4 anyway and synthesize from whatever it has.
                if ($round > 1 && $this->isManualFinalizeRequested($sessionId)) {
                    $this->emitFinalizeRequested(
                        sessionId: $sessionId,
                        round:     $round - 1,
                        maxRounds: $maxRounds,
                        detail:    'Stop and Run consensus pressed before cycle ' . $round
                            . ' started; finalising with results from cycle ' . ($round - 1) . '.',
                    );
                    $loopStopReason = 'manual_finalize';
                    // Step back one round so downstream events report the
                    // last *completed* cycle, not the one we skipped.
                    $this->currentRound = $round - 1;
                    break;
                }

                $this->emitEvent($sessionId, null, 'CONSENSUS_ROUND_STARTED', [
                    'round'                 => $round,
                    'cycle'                 => $round,
                    'cycle_limit'           => $maxRounds,
                    'max_rounds'            => $maxRounds,
                    'peer_answers_included' => $round > 1,
                ]);

                // Cooperative pre-L1 checkpoint. If the user pre-pressed
                // pause/reject before the first model call this honors it.
                $this->checkpoint($sessionId, 'pre_l1_r' . $round);

                $peerContext = null;
                if ($round > 1) {
                    $peerContext = [
                        'previous_l1'        => $round1L1,
                        'previous_consensus' => $round1Consensus,
                        'source_round'       => $round - 1,
                    ];
                }

                // L1 — distribute across selected models in parallel.
                $l1BatchesRun++;
                $l1Results = $this->layer1Distribute(
                    $sessionId, $skill, $query, $selectedModels, $round, $peerContext
                );
                if ($l1Results === []) {
                    $this->emitEvent($sessionId, null, 'L1_NO_RESULTS', [
                        'detail' => 'Every L1 model call failed — no provider returned a response. '
                            . 'Verify that connectors are active and at least one API key is configured.',
                        'round'  => $round,
                        'cycle'  => $round,
                        'cycle_limit' => $maxRounds,
                    ]);
                }

                $this->checkpoint($sessionId, 'pre_l2_r' . $round);

                // L2 — normalize
                $l2Normalized = $this->layer2Normalize($sessionId, $skill, $l1Results, $round);

                $this->checkpoint($sessionId, 'pre_l3_r' . $round);

                // L3 — consensus
                $consensus = $this->layer3Consensus($sessionId, $l2Normalized, $round);

                $this->emitEvent($sessionId, null, 'CONSENSUS_ROUND_COMPLETED', [
                    'round'             => $round,
                    'cycle'             => $round,
                    'cycle_limit'       => $maxRounds,
                    'consensus_status'  => $consensus->status,
                    'consensus_score'   => $consensus->score,
                    'will_repeat'       => false, // set true below if loop continues
                    'max_rounds'        => $maxRounds,
                ]);

                // Capture round-1 snapshots for use as peer context in round 2.
                if ($round === 1) {
                    $round1L1        = $l1Results;
                    $round1Consensus = $consensus;
                }

                // -------- Hard stops, in priority order ------------------
                // 0. v1.8.20 manual finalize wins over every other stop
                //    reason — the user explicitly asked to wrap up now.
                if ($this->isManualFinalizeRequested($sessionId)) {
                    $this->emitFinalizeRequested(
                        sessionId: $sessionId,
                        round:     $round,
                        maxRounds: $maxRounds,
                        detail:    'Stop and Run consensus pressed during cycle ' . $round
                            . '; using collected results.',
                    );
                    $loopStopReason = 'manual_finalize';
                    break;
                }
                // 1. Agreement above the threshold ends the loop cleanly.
                $agreement = strtolower((string)$consensus->status) === 'agreement'
                    && (float)$consensus->score >= $roundThreshold;
                if ($agreement) {
                    $loopStopReason = 'agreement';
                    break;
                }
                // 2. No L1 results means no future round can produce more.
                if ($l1Results === []) {
                    $loopStopReason = 'no_l1_results';
                    break;
                }
                // 3. Hard cycle-limit ceiling. Independent of UI/config.
                if ($round >= $maxRounds) {
                    $loopStopReason = 'cycle_limit_reached';
                    break;
                }
                // 4. Stagnation: tiny score delta + same status → bailing
                //    earlier saves tokens with no expected gain.
                if ($previousScore !== null && $previousStatus !== null && $stagnationEpsilon > 0.0) {
                    $delta = abs((float)$consensus->score - (float)$previousScore);
                    if ($delta < $stagnationEpsilon
                        && strtolower((string)$consensus->status) === strtolower((string)$previousStatus)
                    ) {
                        $this->emitEvent($sessionId, null, 'CONSENSUS_STAGNATION_DETECTED', [
                            'round'             => $round,
                            'cycle'             => $round,
                            'cycle_limit'       => $maxRounds,
                            'previous_score'    => $previousScore,
                            'current_score'     => $consensus->score,
                            'delta'             => $delta,
                            'epsilon'           => $stagnationEpsilon,
                            'consensus_status'  => $consensus->status,
                            'detail'            => 'Consensus score plateaued; stopping early to avoid token burn.',
                        ]);
                        Logger::info('pipeline.stagnation', [
                            'session_id' => $sessionId,
                            'round'      => $round,
                            'delta'      => $delta,
                            'epsilon'    => $stagnationEpsilon,
                        ]);
                        $loopStopReason = 'stagnation';
                        break;
                    }
                }
                $previousScore  = $consensus->score;
                $previousStatus = $consensus->status;
                // Re-emit a will_repeat=true notice so the UI can clearly mark
                // the boundary before the next round header arrives.
                $this->emitEvent($sessionId, null, 'CONSENSUS_ROUND_REPEAT', [
                    'round'             => $round,
                    'cycle'             => $round,
                    'cycle_limit'       => $maxRounds,
                    'next_round'        => $round + 1,
                    'consensus_status'  => $consensus->status,
                    'consensus_score'   => $consensus->score,
                    'reason'            => 'no_consensus',
                ]);
            }
            // Reset round to last completed value for downstream events.
            $this->currentRound = max(1, $this->currentRound);
            if ($consensus === null) {
                $consensus = new ConsensusResult(0.0, 'conflict', 'No rounds executed.');
            }
            // Emit a dedicated CONSENSUS_CYCLE_LIMIT_REACHED event so the UI
            // can render an explicit final state. We do NOT call models
            // again — the final answer falls through to layer4Synthesis
            // (arbiter when no agreement, normal synthesis when agreement).
            if ($loopStopReason === 'cycle_limit_reached'
                && strtolower((string)$consensus->status) !== 'agreement'
            ) {
                $this->emitEvent($sessionId, null, 'CONSENSUS_CYCLE_LIMIT_REACHED', [
                    'round'             => $this->currentRound,
                    'cycle'             => $this->currentRound,
                    'cycle_limit'       => $maxRounds,
                    'consensus_status'  => $consensus->status,
                    'consensus_score'   => $consensus->score,
                    'detail'            => 'Cycle limit reached without agreement — proceeding to final synthesis.',
                ]);
                Logger::info('pipeline.cycle_limit_reached', [
                    'session_id' => $sessionId,
                    'rounds_run' => $this->currentRound,
                    'limit'      => $maxRounds,
                    'score'      => $consensus->score,
                    'status'     => $consensus->status,
                ]);
            }

            $this->checkpoint($sessionId, 'pre_l4');

            if ($l1Results === []) {
                // v1.8.60 — nothing to synthesise, so nothing is called.
                //
                // With no L1 answer there is no L2 to normalise, no L3 to
                // score and no material for L4; the old code called L4
                // anyway, which is how session #21 spent a request on
                // `bothub/gpt-4o`, collected HTTP 404 MODEL_NOT_FOUND, and
                // stored the fallback body as a "completed" run. The body is
                // still built (it is the record of what did not happen) and
                // the failure is recorded, so the terminal verdict has the
                // fact it needs — but no provider is asked to answer a
                // question the run has already lost.
                $l4SkipDetail = 'No L1 model produced a usable answer, so there was nothing to synthesise. '
                    . 'L4 was not called.';
                $this->recordSynthesisFailure(
                    reason: 'no_l1_results',
                    provider: null,
                    model: null,
                    message: $l4SkipDetail,
                );
                $this->emitEvent($sessionId, null, 'L4_SKIPPED', [
                    'reason' => 'no_l1_results',
                    'detail' => $l4SkipDetail,
                ]);
                $finalAnswer = $this->fallbackSynthesis([], $l4SkipDetail);
            } else {
                // L4 — synthesis. The raw L1 chat responses are forwarded so
                // the conflict-resolution branch can prompt a dedicated
                // arbiter model with the full per-model answers when L3
                // reports conflict / no-consensus.
                $finalAnswer = $this->layer4Synthesis($sessionId, $skill, $query, $l2Normalized, $consensus, $l1Results);
            }

            // v1.8.20: when the user pressed "Stop and Run consensus"
            // we prepend a concise note so the Final Answer card makes
            // the early-termination explicit. The note is *added* —
            // never replacing the synthesised content — so downstream
            // share/history rendering still preserves the model output.
            if ($loopStopReason === 'manual_finalize') {
                $finalAnswer = $this->prependManualFinalizeNote(
                    $finalAnswer,
                    $this->currentRound,
                    $maxRounds,
                    is_array($l1Results) ? count($l1Results) : 0,
                );
            }

            // Task 5 - persist the query + final answer to vector memory
            // after a successful L4 synthesis (config-gated, fail-soft).
            $this->storeMemoryContext($sessionId, $query, $finalAnswer);

            $this->stats->finish();
            $sessionStats = $this->stats->getSessionStats();

            // v1.8.48 — "the run finished" is not "a final answer exists".
            // v1.8.60 — and the *whole* verdict is now read from the run's
            // facts, not from the one in-memory flag. The four conditions
            // (L4 step failed / no L1 answers / an empty pool resolved /
            // an empty or fallback answer) each force `failed`; see
            // {@see self::resolveTerminalVerdict()} for why the flag alone
            // was not enough (it missed a session whose L4 step was
            // `failed` on disk).
            $verdict = $this->resolveTerminalVerdict($sessionId, $finalAnswer, $selectedModels, $l1Results);
            $this->recordTerminalVerdict($verdict);
            $terminalStatus = $verdict['status'];
            $synthesisFailure = $this->lastSynthesisFailure;

            // The fallback body is not an answer. It is kept — it is the
            // only record of what the models said — but marked, so neither
            // the Final Answer card nor History can present it as the
            // run's result.
            if ($verdict['facts']['answer_is_fallback'] ?? false) {
                $finalAnswer = $this->markFallbackAnswer($finalAnswer);
            }

            if ($terminalStatus === 'failed') {
                // A failed run must not leave a step claiming to be running
                // either: the card renders those as an in-progress run.
                $this->finalizeRunningSteps($sessionId, $verdict['detail']);
            }

            $this->sessions->complete(
                id:             $sessionId,
                finalAnswer:    $finalAnswer,
                consensusScore: $consensus->score,
                consensusStatus:$consensus->status,
                totalTokens:    $sessionStats->totalTokens,
                durationMs:     (int)round(TimeHelper::elapsedMs($started)),
                status:         $terminalStatus,
            );

            if ($skill->id !== null) {
                $this->skills->incrementUseCount($skill->id);
            }

            Logger::info('pipeline.done', [
                'session_id'         => $sessionId,
                'duration_ms'        => (int)round(TimeHelper::elapsedMs($started)),
                'consensus_status'   => $consensus->status,
                'consensus_score'    => $consensus->score,
                'final_answer_chars' => mb_strlen($finalAnswer),
                'total_tokens'       => $sessionStats->totalTokens,
                'role_count'         => $sessionStats->roleCount,
                'status'             => $terminalStatus,
                'status_reason'      => $verdict['reason'],
                'synthesis_failed'   => $synthesisFailure !== null,
            ]);

            $this->emitEvent($sessionId, null, 'PIPELINE_DONE', [
                'session_id'      => $sessionId,
                'duration_ms'     => (int)round(TimeHelper::elapsedMs($started)),
                'consensus'       => $consensus->toArray(),
                'final_answer'    => $finalAnswer,
                'synthesis_stats' => $this->lastSynthesisStats,
                'stats'           => $sessionStats->toArray(),
                'cycle'           => $this->currentRound,
                'cycle_limit'     => $maxRounds,
                'loop_stop_reason'=> $loopStopReason,
                // Strictly additive: consumers that only read
                // `final_answer` keep working, while a client that wants to
                // render "this run failed" no longer has to sniff the text.
                'status'          => $terminalStatus,
                'synthesis_failed'=> $synthesisFailure !== null,
                // v1.8.60 — *why* it failed, in one machine-readable reason
                // and one human sentence, so the report can say "the pool
                // resolved to no callable model" instead of repeating the
                // provider's error text.
                'status_reason'   => $verdict['reason'],
                'status_detail'   => $verdict['detail'],
                'status_role'     => $verdict['role'],
                'status_facts'    => $verdict['facts'],
                // R11 - who answered and who did not, each gap with a
                // classified reason. Strictly informational: the terminal
                // status above keeps its own, stricter rules, so a partial
                // council still counts as an answer.
                'l1_outcome'      => $this->l1Outcome(),
                // R9 extension - who contributed what, in shares of the
                // corroborated total (raw breakdown lives in the L3 step).
                'contribution'    => $this->lastContribution,
                'preflight'       => $this->lastPreflight,
            ]);

            return [
                'session_id'      => $sessionId,
                'final_answer'    => $finalAnswer,
                'consensus'       => $consensus,
                'stats'           => $sessionStats,
                'steps'           => $this->steps->listStepsBySession($sessionId),
                'synthesis_stats' => $this->lastSynthesisStats,
                'selected_models' => $selectedModels,
                'status'          => $terminalStatus,
                'status_reason'   => $verdict['reason'],
                'status_detail'   => $verdict['detail'],
                'status_facts'    => $verdict['facts'],
                // R11 - who answered and who did not, each gap with a
                // classified reason. Strictly informational: the terminal
                // status above keeps its own, stricter rules, so a partial
                // council still counts as an answer.
                'l1_outcome'      => $this->l1Outcome(),
                // R9 extension - who contributed what, in shares of the
                // corroborated total (raw breakdown lives in the L3 step).
                'contribution'    => $this->lastContribution,
                'preflight'       => $this->lastPreflight,
                'synthesis_failure' => $synthesisFailure,
            ];
        } catch (PipelineRejectedException $e) {
            // User-requested abort — terminal but distinct from fatal.
            // The single-model path owns its own envelope: it has no consensus
            // to report and must terminalise the one L1 step it may have left
            // in flight, so it is handled before the shared branch below.
            if ($singleModelMode) {
                return $this->rejectedSingleRunEnvelope($sessionId, $e, $selectedModels, $started);
            }
            // The checkpoint() call has already emitted PIPELINE_REJECTED
            // (so the SSE client has its terminal frame). Here we only
            // need to finalize repository state — mark in-flight steps
            // failed and the session as failed so History still has a
            // row — and return a non-throwing rejected envelope.
            $reason = $e->getMessage();
            Logger::warn('pipeline.rejected', [
                'session_id' => $sessionId,
                'message'    => $reason,
            ]);
            $this->finalizeRunningSteps($sessionId, $reason);
            $this->sessions->markFailed($sessionId, (int)round(TimeHelper::elapsedMs($started)));
            // Do NOT rethrow — controller should not surface as 500.
            return [
                'session_id'      => $sessionId,
                'final_answer'    => '',
                'consensus'       => new ConsensusResult(0.0, 'rejected', $reason),
                'stats'           => $this->stats->getSessionStats(),
                'steps'           => $this->steps->listStepsBySession($sessionId),
                'synthesis_stats' => $this->lastSynthesisStats,
                'selected_models' => $selectedModels,
                'rejected'        => true,
            ];
        } catch (\Throwable $e) {
            $message = Logger::sanitizeScalar($e->getMessage());
            $payload = [
                'message' => $message,
                'class'   => (new \ReflectionClass($e))->getShortName(),
            ];
            $diagnostics = $this->classifyFatalError($e);
            if ($diagnostics !== null) {
                if (Config::isDebug()) {
                    $payload['diagnostics'] = $diagnostics;
                } else {
                    // Production: replace raw message with a terse, safe one.
                    $payload['message'] = ErrorDiagnostics::terse($diagnostics);
                }
            }
            Logger::error('pipeline.fatal', [
                'session_id' => $sessionId,
                'class'      => $payload['class'],
                'message'    => $payload['message'],
            ]);
            $this->finalizeRunningSteps($sessionId, (string)$payload['message']);
            $this->emitEvent($sessionId, null, 'PIPELINE_FATAL', $payload);
            $this->sessions->markFailed($sessionId, (int)round(TimeHelper::elapsedMs($started)));
            throw $e;
        } finally {
            // Best-effort cleanup of the run-control file so a future
            // session id reuse never inherits stale paused/rejected.
            if ($this->runControl !== null) {
                $this->runControl->finalize($sessionId);
            }
            $this->currentSessionId = null;
        }
    }

    /**
     * Mark every step still in `running` for the given session as failed
     * and emit a STEP_FAILED event for each so the UI can replace any
     * lingering placeholder cards. Best-effort — repository errors are
     * caught so the original fatal stays the user-visible cause.
     */
    /**
     * Single-model run: ONE model answers with the resolved skill's system
     * prompt, and its answer IS the final answer.
     *
     * Why it is a separate path rather than a flag inside the consensus loop:
     * every stage it would otherwise skip exists to compare *several* answers.
     * L2 (normalisation for comparison), L3 (scoring) and L4 (synthesis across
     * answers) have nothing to work on when there is one answer, and the loop
     * would either call a second model or report a consensus nobody measured -
     * the single-response branch of the consensus engines literally returns
     * `1.0 / agreement`, which is the opposite of honest.
     *
     * What it reuses instead of re-implementing: the same L0 skill resolution
     * and `resolveParticipantRole()` (so the system prompt is the skill's own
     * role prompt, composed through `composeSystemPrompt()` exactly as L1 does),
     * the same `layer1Distribute()` call (so the one call is cached, retried,
     * breaker-aware and accounted like every other L1 call), and the same
     * `resolveTerminalVerdict()` (so an empty or censored answer fails the run
     * rather than being dressed up as one).
     *
     * What it writes down: `consensus_status = 'single'` with
     * `consensus_score = NULL`. "Not measured" is not zero and not one, and the
     * audit trail must not imply a comparison that never ran.
     *
     * @param  array<int,array{provider:string,model:string}> $selectedModels already sliced to one entry
     * @return array<string,mixed>
     */
    private function runSingleModel(
        int $sessionId,
        Skill $skill,
        string $query,
        array $selectedModels,
        float $started,
    ): array {
        // Append-only contract: `step_key` is unique within a run and a repeat
        // means a loop. The consensus loop resets this before each round; a
        // single-model run starts its only round here. Without the reset the keys
        // observed while building L0 (skills, options, preflight) would make the
        // L1 step look like a repeat and `detectLoop()` would refuse it.
        $this->currentRound = 1;
        $this->observedKeys = [];

        // The participant and the system prompt it will carry. `array_slice`
        // is defensive: the caller already resolved a single entry, and an
        // empty pool here must behave like the consensus path does (L1 emits
        // L1_NO_AVAILABLE_MODELS and answers nothing) rather than fabricating
        // a participant with no model behind it.
        $one = array_slice($selectedModels, 0, 1);
        $role = $this->resolveParticipantRole($skill->roles(), 0, $skill);
        $roleKey    = (string)($role['key'] ?? 'participant') . '@'
            . strtolower(trim((string)preg_replace('/[^a-z0-9]+/i', '_', (string)($one[0]['model'] ?? 'model')), '_'));

        $this->emitEvent($sessionId, null, 'SINGLE_MODEL_MODE', [
            'mode'             => self::MODE_SINGLE_MODEL,
            'skill'            => $skill->slug,
            'role'             => $roleKey,
            'role_label'       => (string)($role['label'] ?? $roleKey),
            'model'            => $one[0]['model']  ?? null,
            'provider'         => $one[0]['provider'] ?? null,
            'consensus'        => false,
            'cycle_limit_used' => false,
            'detail'           => 'Single-model run: one model answers with the skill\'s own system prompt and its '
                . 'answer is the final answer. No consensus is computed - nothing was compared.',
        ]);

        $this->checkpoint($sessionId, 'pre_l1_single');

        // L1 — one call. Passing a single-element pool is what keeps this to
        // exactly one model call: layer1Distribute() builds one participant per
        // pool entry, so nothing here has to duplicate its dispatch, caching or
        // step bookkeeping.
        $l1Results = $this->layer1Distribute($sessionId, $skill, $query, $one, 1);

        $stepId     = (int)($l1Results[0]['step_id'] ?? 0);
        $response   = $l1Results[0]['response'] ?? null;
        $answer     = $response instanceof ChatResponse ? (string)$response->content : '';
        $hasAnswer  = $l1Results !== [] && trim($answer) !== '';

        // L2/L3/L4 are recorded as skipped, not omitted. The journal has to say
        // that these stages were considered and deliberately not run - an absent
        // row is indistinguishable from a crash, which is the same reason the
        // no-L1-answer path emits an explicit L4_SKIPPED.
        $skippedNote = 'Not run: single-model mode. One answer was collected, so there was nothing '
            . 'to compare or synthesise. L4 is not called - the answer is the model\'s own.';
        if ($hasAnswer) {
            // L2's meta contract is that it names the claims it extracted and
            // flags that no LLM was called (see Pipeline.md). A skipped L2 has
            // no claims, so the flag is carried without the count.
            $l2StepId = $this->steps->createStep($sessionId, 'L2.normalize.' . $roleKey, 'L2 · Normalization (skipped)', 101, $this->stepMeta([
                'layer'      => 'L2',
                'role'       => $roleKey,
                'round'      => 1,
                'note'       => $skippedNote,
                'claims_count' => 0,
                'llm_call'   => false,
                'single_model' => true,
            ]));
            $this->steps->completeStep($l2StepId, 'skipped', $skippedNote);
        }
        $l3StepId = $this->steps->createStep($sessionId, 'L3.consensus', 'L3 · Consensus (skipped)', 200, $this->stepMeta([
            'layer'        => 'L3',
            'round'        => 1,
            'note'         => $skippedNote,
            'consensus'    => null,
            'single_model' => true,
        ]));
        $this->steps->completeStep($l3StepId, 'skipped', $skippedNote);
        $l4StepId = $this->steps->createStep($sessionId, 'L4.synthesis', 'L4 · Synthesis (skipped)', 300, $this->stepMeta([
            'layer'        => 'L4',
            'note'         => $skippedNote,
            'single_model' => true,
            'model'        => $one[0]['model']  ?? null,
            'provider'     => $one[0]['provider'] ?? null,
        ]));
        $this->steps->completeStep($l4StepId, 'skipped', $skippedNote);
        $this->emitEvent($sessionId, null, 'L4_SKIPPED', [
            'reason'       => 'single_model',
            'detail'       => $skippedNote,
            'run_mode'     => self::MODE_SINGLE_MODEL,
            'single_model' => true,
        ]);

        $this->stats->finish();
        $sessionStats = $this->stats->getSessionStats();

        // An empty (or censored-to-empty) answer is a FAILED run, not a
        // fallback and not a reason to call a second model: the operator asked
        // one model one question and it did not answer. The verdict comes from
        // the same facts the consensus path reads.
        $verdict = $this->resolveTerminalVerdict($sessionId, $answer, $one, $l1Results);
        $this->recordTerminalVerdict($verdict);
        $terminalStatus = $verdict['status'];
        if ($terminalStatus === 'failed') {
            $this->finalizeRunningSteps($sessionId, $verdict['detail']);
        }

        // No consensus was computed, so the row carries no score and says so.
        // NULL is "not measured"; 0 would read as "measured, and they disagreed
        // completely". Declared here rather than in the caller so the write and
        // the events that explain it cannot drift apart.
        $this->sessions->complete(
            id:             $sessionId,
            finalAnswer:    $answer,
            consensusScore: null,
            consensusStatus: self::CONSENSUS_STATUS_SINGLE,
            totalTokens:    $sessionStats->totalTokens,
            durationMs:     (int)round(TimeHelper::elapsedMs($started)),
            status:         $terminalStatus,
        );

        if ($skill->id !== null) {
            $this->skills->incrementUseCount($skill->id);
        }

        Logger::info('pipeline.single_model.done', [
            'session_id'         => $sessionId,
            'duration_ms'        => (int)round(TimeHelper::elapsedMs($started)),
            'consensus_status'   => self::CONSENSUS_STATUS_SINGLE,
            'final_answer_chars' => mb_strlen($answer),
            'total_tokens'       => $sessionStats->totalTokens,
            'model'              => $one[0]['model'] ?? null,
            'status'             => $terminalStatus,
            'status_reason'      => $verdict['reason'],
        ]);

        $this->emitEvent($sessionId, null, 'PIPELINE_DONE', [
            'session_id'      => $sessionId,
            'duration_ms'     => (int)round(TimeHelper::elapsedMs($started)),
            // Null, not an envelope: there is no verdict to report. Clients that
            // render `consensus.score` keep working and now have a run mode that
            // tells them why it is null.
            'consensus'       => null,
            'final_answer'    => $answer,
            'synthesis_stats' => null,
            'stats'           => $sessionStats->toArray(),
            'cycle'           => 1,
            'cycle_limit'     => 1,
            'loop_stop_reason'=> 'single_model',
            'status'          => $terminalStatus,
            'synthesis_failed'=> false,
            'status_reason'   => $verdict['reason'],
            'status_detail'   => $verdict['detail'],
            'status_facts'    => $verdict['facts'],
            'run_mode'        => self::MODE_SINGLE_MODEL,
            'single_model'    => [
                'model'         => $one[0]['model']  ?? null,
                'provider'      => $one[0]['provider'] ?? null,
                'role'          => $roleKey,
                'role_label'    => (string)($role['label'] ?? $roleKey),
                'l1_step_id'    => $stepId,
                'consensus'     => false,
            ],
            // R11 - who answered and who did not, with classified reasons, in
            // the same shape the consensus path publishes.
            'l1_outcome'      => $this->l1Outcome(),
            'preflight'       => $this->lastPreflight,
        ]);

        // `consensus` is null on purpose, and the return contract documents
        // that: every legacy consumer reads `final_answer` (the answer is
        // there), and the API/History surfaces report the run mode.
        return [
            'session_id'        => $sessionId,
            'final_answer'      => $answer,
            'consensus'         => null,
            'stats'             => $sessionStats,
            'steps'             => $this->steps->listStepsBySession($sessionId),
            'synthesis_stats'   => null,
            'selected_models'   => $one,
            'status'            => $terminalStatus,
            'status_reason'     => $verdict['reason'],
            'status_detail'     => $verdict['detail'],
            'status_facts'      => $verdict['facts'],
            'run_mode'          => self::MODE_SINGLE_MODEL,
            'single_model'      => true,
            'l1_outcome'        => $this->l1Outcome(),
            'preflight'         => $this->lastPreflight,
            'synthesis_failure' => null,
        ];
    }

    /**
     * Terminalise this run's in-flight steps and return the rejected envelope.
     *
     * Shared by the consensus path and the single-model path: a user-requested
     * abort must leave the same record whichever run mode it interrupted. The
     * append-only rule allows a step row to move to a terminal status - it
     * forbids deleting or rewriting history, and "this step will never finish"
     * is new information about a row nobody else is going to update. Leaving it
     * `running` would make the interface show an in-progress run forever and
     * would let the single-model branch's L1 step look unfinished.
     */
    private function rejectedSingleRunEnvelope(
        int $sessionId,
        PipelineRejectedException $e,
        array $selectedModels,
        float $started,
    ): array {
        $reason = $e->getMessage();
        Logger::warn('pipeline.rejected', [
            'session_id' => $sessionId,
            'run_mode'   => self::MODE_SINGLE_MODEL,
            'message'    => $reason,
        ]);
        $this->finalizeRunningSteps($sessionId, $reason);
        $this->sessions->markFailed($sessionId, (int)round(TimeHelper::elapsedMs($started)));
        // Record the mode the run was in even though it produced no verdict: the
        // verdict itself stays `null` (nothing was measured), and the mode is
        // what tells an operator why there is none.
        $this->emitEvent($sessionId, null, 'PIPELINE_DONE', [
            'session_id'      => $sessionId,
            'consensus'       => null,
            'final_answer'    => '',
            'cycle'           => 1,
            'cycle_limit'     => 1,
            'loop_stop_reason'=> 'rejected',
            'status'          => 'failed',
            'status_reason'   => 'rejected',
            'status_detail'   => $reason,
            'run_mode'        => self::MODE_SINGLE_MODEL,
            'single_model'    => true,
        ]);
        return [
            'session_id'      => $sessionId,
            'final_answer'    => '',
            'consensus'       => null,
            'stats'           => $this->stats->getSessionStats(),
            'steps'           => $this->steps->listStepsBySession($sessionId),
            'synthesis_stats' => null,
            'selected_models' => $selectedModels,
            'status'          => 'failed',
            'status_reason'   => 'rejected',
            'status_detail'   => $reason,
            'run_mode'        => self::MODE_SINGLE_MODEL,
            'single_model'    => true,
            'rejected'        => true,
        ];
    }

    public function finalizeRunningSteps(int $sessionId, string $reason): int
    {
        try {
            $running = $this->steps->listRunningSteps($sessionId);
        } catch (\Throwable $e) {
            Logger::warn('finalizeRunningSteps list failed', ['error' => $e->getMessage()]);
            $running = [];
        }
        if ($running === []) {
            return 0;
        }
        try {
            $this->steps->failRunningSteps($sessionId, $reason);
        } catch (\Throwable $e) {
            Logger::warn('finalizeRunningSteps update failed', ['error' => $e->getMessage()]);
        }
        foreach ($running as $row) {
            $stepKey = (string)$row['step_key'];
            $layer   = explode('.', $stepKey)[0] ?? '';
            $eventType = $layer === 'L1' ? 'L1_FAILED'
                       : ($layer === 'L4' ? 'L4_FAILED' : 'STEP_FAILED');
            try {
                $this->emitEvent($sessionId, (int)$row['id'], $eventType, [
                    'role'    => $stepKey,
                    'label'   => (string)$row['label'],
                    'message' => $reason,
                    'reason'  => 'pipeline_fatal',
                ]);
            } catch (\Throwable $e) {
                Logger::warn('finalizeRunningSteps emit failed', ['error' => $e->getMessage()]);
            }
        }
        return count($running);
    }

    /**
     * Merge the active repair provenance marker into a step's meta
     * payload. Returns the payload untouched during a normal run()
     * (`$repairContext === null`), so this is a no-op for every path
     * that is not finishing an interrupted session.
     *
     * @param  array<string,mixed>|null $meta
     * @return array<string,mixed>|null
     */
    private function stepMeta(?array $meta): ?array
    {
        // v1.9.x - the run's plan and the prompt versions in play travel with
        // every step. Steps are what reaches the interface and the History
        // page, so a step counter and a prompt attribution need no extra
        // channel: what the operator reads is what the run recorded.
        $meta = array_merge($meta ?? [], ['plan' => $this->planSnapshot()]);
        if ($this->promptVersions !== []) {
            $meta['prompt_versions'] = $this->promptVersions;
        }
        if ($this->repairContext === null) {
            return $meta;
        }
        return array_merge($meta, $this->repairContext);
    }

    /**
     * The plan a step belongs to: how many steps the first cycle is expected
     * to take, how many a later cycle adds, and the cycle ceiling. Published
     * rather than guessed, so the interface can say "step N of M" honestly.
     *
     * @return array<string,int>
     */
    private function planSnapshot(): array
    {
        $models = count($this->resolvedSelectedModels);
        return [
            'models'            => $models,
            'steps_first_cycle' => PipelinePlan::firstCycleSteps($models),
            'next_cycle_steps'  => PipelinePlan::nextCycleSteps($models),
            'cycles_max'        => max(1, (int)$this->currentCycleLimit),
        ];
    }

    /**
     * Read-only preview of how the engine would resolve a requested
     * selected-model list (active-catalog + connector availability
     * gates). Used by the History "Finish session" dry-run so the
     * reported plan matches exactly what a real repair would dispatch.
     *
     * Side-effect free with respect to the database: the only state it
     * touches is the in-memory trace of this engine instance.
     *
     * @param  array<int,array<string,mixed>>|null $requested
     * @return array{accepted:array<int,array{provider:string,model:string}>,rejected:array<int,array{reason:string,provider:string,model:string}>,source:string,used_fallback:bool}
     */
    public function previewModelSelection(?array $requested): array
    {
        return $this->resolveSelectionWithDiagnostics($requested, 'request', false);
    }

    /**
     * Finish an interrupted session from its already-persisted results.
     *
     * Executes ONLY the stages the caller marked as missing, in pipeline
     * order (L1 → L2 → L3 → L4), feeding the persisted results back in as
     * input. Models whose L1 answer is already stored are never
     * re-queried — they are skipped by model key in layer1Distribute()
     * while keeping their original participant index (and therefore their
     * original `L1.role.<role>@<model>` step key) intact.
     *
     * Everything created here is tagged via {@see self::stepMeta()} with
     * `repair => true` plus the caller-supplied reason so reconstructed
     * steps stay distinguishable from the original run's steps.
     *
     * @param array{
     *   session_id:int,
     *   skill:Skill,
     *   query:string,
     *   options:array<string,mixed>,
     *   l1_results:array<int,array{role:array<string,mixed>,response:ChatResponse,step_id:int}>,
     *   l2_normalized:array<int,array{role:array<string,mixed>,normalized:array<string,mixed>}>,
     *   consensus:?ConsensusResult,
     *   plan:array{l1_missing:array<int,array{provider:string,model:string}>,l2_missing:array<int,string>,l3:bool,l4:bool,existing_final_answer:?string},
     *   reason:string,
     * } $request
     *
     * @return array{final_answer:string,consensus:ConsensusResult,executed:array<int,array<string,mixed>>,tokens:int,duration_ms:int,l1_results:array<int,array<string,mixed>>,synthesis_failure:array<string,mixed>|null}
     *
     * @throws \RuntimeException when the request is not executable (no L1
     *                           results at all, missing L3 verdict while
     *                           L4 must run, empty persisted final answer).
     */
    public function repairSession(array $request): array
    {
        $sessionId = (int)($request['session_id'] ?? 0);
        $skill     = $request['skill'] ?? null;
        $query     = (string)($request['query'] ?? '');
        $options   = is_array($request['options'] ?? null) ? $request['options'] : [];
        $plan      = is_array($request['plan'] ?? null) ? $request['plan'] : [];
        $reason    = (string)($request['reason'] ?? 'session_repair');
        if ($sessionId <= 0 || !$skill instanceof Skill) {
            throw new \RuntimeException('repairSession requires a session id and a resolved skill');
        }

        $started = TimeHelper::now();

        // Fresh per-run engine state — mirrors the reset block at the top
        // of run() so no state from an earlier pipeline bleeds in.
        $this->trace               = [];
        $this->observedKeys        = [];
        $this->lastSynthesisStats  = null;
        $this->lastSynthesisFailure = null;
        $this->lastSynthesisAttempts = [];
        $this->lastL1RequestOptions= [];
        $this->currentRound        = 1;
        $this->runtimeOptions      = $options;
        $this->currentSessionId    = $sessionId;
        // `batch` is additive: the batch orchestrator continues a run
        // through this same entry point, and the steps it creates must be
        // distinguishable from a repair an operator triggered by hand. The
        // repair bookkeeping stays exactly as it was — the difference is
        // only in what the UI can say about the run.
        $this->repairContext       = [
            'repair'        => true,
            'repair_reason' => $reason,
        ];
        if (($request['batch'] ?? false) === true) {
            $this->repairContext['run_mode'] = 'batch';
            $this->repairContext['batch']    = true;
        }
        if ($this->runControl !== null) {
            // A stale pause/reject signal left over from the crashed run
            // must not abort the repair before it starts.
            $this->runControl->finalize($sessionId);
        }

        $selectedModels = $this->previewModelSelection(
            isset($options['selected_models']) && is_array($options['selected_models'])
                ? $options['selected_models']
                : null
        )['accepted'];
        $this->resolvedSelectedModels = $selectedModels;
        $cycle = $this->resolveCycleLimit($options['cycle_limit'] ?? null);
        $this->currentCycleLimit = $cycle['effective'];

        $l1Results    = is_array($request['l1_results'] ?? null) ? array_values($request['l1_results']) : [];
        $l2Normalized = is_array($request['l2_normalized'] ?? null) ? array_values($request['l2_normalized']) : [];
        $consensus    = ($request['consensus'] ?? null) instanceof ConsensusResult ? $request['consensus'] : null;

        $executed = [];
        $tokensBefore = $this->stats->getSessionStats()->totalTokens;

        try {
            // ---- L1: dispatch ONLY the models that never answered -------
            $l1Missing = [];
            foreach (($plan['l1_missing'] ?? []) as $entry) {
                if (!is_array($entry)) { continue; }
                $p = strtolower(trim((string)($entry['provider'] ?? '')));
                $m = trim((string)($entry['model'] ?? ''));
                if ($p === '' || $m === '') { continue; }
                $l1Missing[] = ['provider' => $p, 'model' => $m];
            }
            if ($l1Missing !== [] && $selectedModels !== []) {
                // Skip every model that already has a stored L1 answer.
                // Keys are compared case-insensitively via the same
                // provider|model format used by the resolver.
                $skip = [];
                foreach ($l1Results as $row) {
                    $rk = (string)($row['role']['key'] ?? '');
                    $resp = $row['response'] ?? null;
                    if ($rk === '' || !$resp instanceof ChatResponse) { continue; }
                    // Requested model + provider-reported model are both
                    // registered so a provider that echoes a dated model
                    // id cannot cause a needless second call.
                    $skip[strtolower((string)$resp->provider) . '|' . (string)$resp->model] = true;
                    $skip[strtolower((string)$resp->provider) . '|' . (string)$resp->effectiveModel()] = true;
                }

                $this->emitEvent($sessionId, null, 'L1_REPAIR_DISPATCH', [
                    'round'        => 1,
                    'cycle'        => 1,
                    'cycle_limit'  => $this->currentCycleLimit,
                    'models'       => $l1Missing,
                    'skipped_count'=> count($skip),
                    'reason'       => $reason,
                ]);

                $fresh = $this->layer1Distribute($sessionId, $skill, $query, $selectedModels, 1, null, array_keys($skip));
                foreach ($fresh as $row) {
                    $l1Results[] = $row;
                }
                $executed[] = [
                    'layer'    => 'L1',
                    'status'   => $fresh === [] ? 'skipped' : 'completed',
                    'detail'   => $fresh === []
                        ? 'No model returned a usable answer for the missing L1 slots.'
                        : count($fresh) . ' model(s) answered during repair.',
                    'models'   => array_map(
                        static fn(array $r): array => [
                            'provider' => (string)($r['response']->provider ?? ''),
                            'model'    => (string)($r['response']->effectiveModel() ?? ''),
                            'step_id'  => (int)($r['step_id'] ?? 0),
                        ],
                        $fresh,
                    ),
                ];
            }

            if ($l1Results === []) {
                throw new \RuntimeException(
                    'No model answers are available for this session — there is nothing to build a '
                    . 'final answer from. Re-run the query instead.'
                );
            }

            // ---- L2: normalise every answer with no stored payload -------
            // The role set is derived from the data rather than from
            // $plan['l2_missing'] alone: an L1 answer collected a few lines
            // above is a brand-new role the caller could not have known
            // about when it built the plan, and it needs normalising too.
            $plannedL2 = [];
            foreach (($plan['l2_missing'] ?? []) as $roleKey) {
                if (is_string($roleKey) && $roleKey !== '') { $plannedL2[] = $roleKey; }
            }
            $normalizedRoles = [];
            foreach ($l2Normalized as $row) {
                $rk = (string)($row['role']['key'] ?? '');
                if ($rk !== '') { $normalizedRoles[$rk] = true; }
            }
            $pending = [];
            foreach ($l1Results as $row) {
                $rk = (string)($row['role']['key'] ?? '');
                if ($rk === '' || isset($normalizedRoles[$rk])) { continue; }
                $pending[] = $row;
            }
            if ($pending !== []) {
                $fresh = $this->layer2Normalize($sessionId, $skill, $pending, 1);
                foreach ($fresh as $row) {
                    $l2Normalized[] = $row;
                }
                $executed[] = [
                    'layer'  => 'L2',
                    'status' => 'completed',
                    'detail' => count($fresh) . ' role(s) normalised during repair.',
                    'roles'  => array_map(
                        static fn(array $r): string => (string)($r['role']['key'] ?? ''),
                        $fresh,
                    ),
                    'planned_roles' => $plannedL2,
                ];
            }
            if ($l2Normalized === []) {
                throw new \RuntimeException('Normalisation produced no usable claim sets — cannot compute consensus.');
            }

            // Re-align normalised rows with the L1 ordering so L3/L4 see
            // the participants in the same order the original run did.
            $byRole = [];
            foreach ($l2Normalized as $row) {
                $rk = (string)($row['role']['key'] ?? '');
                if ($rk !== '' && !isset($byRole[$rk])) { $byRole[$rk] = $row; }
            }
            $ordered = [];
            foreach ($l1Results as $row) {
                $rk = (string)($row['role']['key'] ?? '');
                if ($rk !== '' && isset($byRole[$rk])) {
                    $ordered[] = $byRole[$rk];
                    unset($byRole[$rk]);
                }
            }
            foreach ($byRole as $row) { $ordered[] = $row; }
            $l2Normalized = $ordered;

            // ---- L3: reuse the stored verdict, or compute it -------------
            if (($plan['l3'] ?? false) === true) {
                $consensus = $this->layer3Consensus($sessionId, $l2Normalized, 1);
                $executed[] = [
                    'layer'  => 'L3',
                    'status' => 'completed',
                    'detail' => 'Consensus recomputed during repair.',
                    'score'  => $consensus->score,
                    'verdict'=> $consensus->status,
                ];
            }
            if ($consensus === null) {
                throw new \RuntimeException('No consensus verdict is available and none could be computed.');
            }

            // ---- L4: reuse the stored synthesis, or run it ---------------
            $synthesisFailure = null;
            if (($plan['l4'] ?? false) === true) {
                $finalAnswer = $this->layer4Synthesis($sessionId, $skill, $query, $l2Normalized, $consensus, $l1Results);
                // v1.8.48 — the same distinction run() makes: a repair whose
                // L4 fell back to the apology text did NOT produce a final
                // answer, so it must not be reported as a completed stage
                // (the caller then leaves the session `failed` and keeps the
                // button available for another attempt).
                $synthesisFailure = $this->lastSynthesisFailure;
                $executed[] = [
                    'layer'  => 'L4',
                    'status' => $synthesisFailure === null ? 'completed' : 'failed',
                    'detail' => $synthesisFailure === null
                        ? 'Final answer synthesised during repair.'
                        : 'Synthesis failed again during repair: '
                            . (string)($synthesisFailure['message'] ?? 'unknown provider error'),
                    'chars'  => mb_strlen($finalAnswer),
                ];
            } else {
                $finalAnswer = (string)($plan['existing_final_answer'] ?? '');
                $executed[] = [
                    'layer'  => 'L4',
                    'status' => 'reused',
                    'detail' => 'Reused the synthesis already stored in the step journal.',
                    'chars'  => mb_strlen($finalAnswer),
                ];
            }
            if (trim($finalAnswer) === '') {
                throw new \RuntimeException('Synthesis produced an empty final answer.');
            }

            $this->stats->finish();
            $tokens = max(0, $this->stats->getSessionStats()->totalTokens - $tokensBefore);

            // v1.8.60 — the repair's own verdict, from the repair's own
            // facts. The journal is deliberately NOT consulted: it still
            // holds the failed L4 step of the attempt being repaired, and
            // using it here would mark a repair that just worked as failed.
            $verdict = $this->resolveTerminalVerdict($sessionId, $finalAnswer, $selectedModels, $l1Results, false);
            $this->recordTerminalVerdict($verdict);
            if ($verdict['facts']['answer_is_fallback'] ?? false) {
                $finalAnswer = $this->markFallbackAnswer($finalAnswer);
            }
            if ($verdict['status'] === 'failed') {
                $this->finalizeRunningSteps($sessionId, $verdict['detail']);
            }

            return [
                'final_answer' => $finalAnswer,
                'consensus'    => $consensus,
                'executed'     => $executed,
                'tokens'       => $tokens,
                'duration_ms'  => (int)round(TimeHelper::elapsedMs($started)),
                'l1_results'   => $l1Results,
                // Null on success; the failure record when L4 apologised.
                'synthesis_failure' => $synthesisFailure,
                // The terminal verdict this repair reached, so its caller
                // (a batch run, or the History repair endpoint) writes the
                // session status the engine actually decided instead of
                // re-deriving it from `synthesis_failure` alone.
                'status'        => $verdict['status'],
                'status_reason' => $verdict['reason'],
                'status_detail' => $verdict['detail'],
                'status_facts'  => $verdict['facts'],
            ];
        } finally {
            $this->repairContext    = null;
            $this->currentSessionId = null;
        }
    }

    // =================================================================
    // Asynchronous Batch API (BatchL1PlannerInterface)
    // =================================================================

    /**
     * Build the batchable L1 fan-out for a session.
     *
     * This is the asynchronous twin of the front half of
     * {@see self::layer1Distribute()} and deliberately calls the same
     * helpers, in the same order, so a batch run and a synchronous run of
     * the same query produce the same step keys, the same roles and the
     * same request bodies:
     *
     *   `resolveSelectionWithDiagnostics(…, allowBatchIds: true)`
     *     → the pool, this time accepting `:batch` ids
     *   `resolveParticipantRole()` + the `role@model` key
     *     → the same `L1.role.<role>@<model>` step key L1 uses
     *   `buildL1Messages()`                 → the same messages
     *   `AbstractConnector::buildBatchChatRequest()`
     *     → the same `body`, assembled by the same private method the
     *       synchronous dispatcher's `buildChatRequest()` uses. The two
     *       differ in exactly one way: the batch entry point does not run
     *       the `:batch` fail-fast guard, which exists to stop a batch-only
     *       id reaching `/chat/completions` — an endpoint it is not served
     *       by — and therefore must not apply here.
     *
     * What it does NOT do is dispatch. Nothing here touches the network:
     * the bodies go back to the caller, which renders them into the JSONL
     * document and submits it.
     *
     * ## Two behaviours worth stating
     *
     * 1. **The response cache is not consulted.** A synchronous L1 call
     *    short-circuits on a cache hit, and a batch could too — but a run
     *    the operator explicitly asked to submit as a batch is a request
     *    for the provider's asynchronous surface, and silently answering
     *    some participants from cache would make the batch's cost, timing
     *    and result set depend on which participants happened to be
     *    cached. Answers ARE stored on accept, so a later synchronous run
     *    still benefits.
     * 2. **Re-preparing reuses step rows.** The orchestrator can re-enter
     *    this method after a crash that happened before the batch was
     *    created; `custom_id`s are deterministic, and a step row that
     *    already exists for a step key is reused rather than duplicated, so
     *    the journal never gains two rows for one participant.
     *
     * @param array<int,array{provider?:string,model?:string}>|null $requestedModels
     * @param array<string,mixed> $options
     *
     * @return array{
     *     ok:bool,
     *     reason?:string,
     *     participants:list<array{custom_id:string,step_id:int,provider:string,model:string,role:string,index:int,body:array<string,mixed>}>,
     *     pool:list<array{provider:string,model:string}>,
     *     skipped:list<array{provider:string,model:string,reason:string}>
     * }
     */
    public function prepareBatchL1(int $sessionId, string $query, ?array $requestedModels, array $options, int $jobId): array
    {
        // Fresh per-run engine state, mirroring the reset block at the top
        // of run(): a worker process may have handled a synchronous run
        // before this one.
        $this->trace                = [];
        $this->observedKeys         = [];
        $this->lastSynthesisStats   = null;
        $this->lastSynthesisFailure = null;
        $this->lastSynthesisAttempts = [];
        $this->lastL1RequestOptions = [];
        $this->currentRound         = 1;
        $this->runtimeOptions       = $options;
        $this->currentSessionId     = $sessionId;

        // Skill resolution can fail in the same places the synchronous run
        // fails (an empty skill table falls through to the auto-creator, which
        // asks a model to draft one). A batch SUBMISSION that cannot work out
        // which skill it is running has produced nothing at the provider, so
        // the honest outcome is a described failure, not an exception escaping
        // into the worker as an opaque fault.
        try {
            $skill = $this->resolveSkillForRun($query)['skill'];
        } catch (\Throwable $e) {
            $reason = 'The skill for this query could not be resolved, so no batch was submitted: '
                . Logger::sanitizeScalar($e->getMessage());
            $this->emitEvent($sessionId, null, 'BATCH_L1_NO_AVAILABLE_MODELS', [
                'detail'   => $reason,
                'run_mode' => 'batch',
            ]);
            return ['ok' => false, 'reason' => $reason, 'participants' => [], 'pool' => [], 'skipped' => []];
        }
        $cycle = $this->resolveCycleLimit($options['cycle_limit'] ?? null);
        $this->currentCycleLimit = $cycle['effective'];

        $diag = $this->resolveSelectionWithDiagnostics($requestedModels, 'request', false, true);
        $selectedModels = $diag['accepted'];
        $this->resolvedSelectedModels = $selectedModels;
        $this->lastSelectionDiagnostic = $diag;

        if ($selectedModels === []) {
            $this->emitEvent($sessionId, null, 'BATCH_L1_NO_AVAILABLE_MODELS', [
                'detail'  => 'No usable models in the selected pool for a Batch API run. Configure a connector '
                           . 'with a valid API key and pick at least one model (a `:batch` id is allowed here).',
                'rejected'=> $diag['rejected'],
                'source'  => $diag['source'],
                'run_mode'=> 'batch',
            ]);
            return [
                'ok'           => false,
                'reason'       => 'No usable model in the selected pool: '
                    . (string)($diag['rejected'][0]['reason'] ?? 'the selection is empty'),
                'participants' => [],
                'pool'         => [],
                'skipped'      => [],
            ];
        }

        $skillRoles      = $skill->roles();
        $maxParticipants = max(1, (int)Config::get('pipeline.max_roles_per_skill', 12));
        $selectedModels  = array_slice($selectedModels, 0, $maxParticipants);
        $reasoningPayload = $this->reasoningPayload($options['reasoning'] ?? null);
        $scope = BatchCustomId::scope($jobId, $sessionId);

        // Step rows already on the session, keyed by step key: the
        // idempotency guard described above.
        $existingSteps = [];
        foreach ($this->steps->listStepsBySession($sessionId) as $row) {
            $key = is_array($row) ? (string)($row['step_key'] ?? '') : '';
            if ($key !== '' && !isset($existingSteps[$key])) {
                $existingSteps[$key] = (int)($row['id'] ?? 0);
            }
        }

        $participants = [];
        $pool         = [];
        $skipped      = [];
        $orderIndex   = 1;

        foreach ($selectedModels as $index => $entry) {
            $provider = (string)$entry['provider'];
            $model    = (string)$entry['model'];
            $pool[]   = ['provider' => $provider, 'model' => $model];

            $role = $this->resolveParticipantRole($skillRoles, $index, $skill);
            $modelTag = preg_replace('/[^a-z0-9]+/i', '_', $model) ?? 'model';
            $modelTag = strtolower(trim((string)$modelTag, '_'));
            $roleKey  = (string)($role['key'] ?? 'participant') . '@' . $modelTag;
            $role     = array_merge($role, [
                'key'        => $roleKey,
                'origin_key' => (string)($role['key'] ?? 'participant'),
            ]);
            $customId = BatchCustomId::make($scope, 1, (int)$index, $provider, $model);
            $stepKey  = StepKeyHelper::normalize("L1.role.{$roleKey}");

            if ($this->detectLoop($stepKey, $sessionId)) {
                $skipped[] = ['provider' => $provider, 'model' => $model, 'reason' => 'loop guard refused the step key'];
                continue;
            }

            $connector = $this->connectors->getByProvider($provider);
            if ($connector === null) {
                $reason = $this->describeMissingConnector($provider);
                // Recorded against the participant's own step, exactly as
                // the synchronous path does, so one unusable provider does
                // not remove the rest of the pool from the batch.
                $stepId = $existingSteps[$stepKey] ?? $this->steps->createStep(
                    $sessionId,
                    $stepKey,
                    "L1 · {$roleKey}",
                    $orderIndex++,
                    $this->stepMeta([
                        'layer' => 'L1', 'round' => 1, 'role' => $roleKey,
                        'origin_role' => (string)$role['origin_key'],
                        'model' => $model, 'provider' => $provider,
                        'run_mode' => 'batch', 'batch_custom_id' => $customId,
                    ]),
                );
                $this->failL1Step($sessionId, $stepId, ['role' => $role, 'model' => $model, 'provider' => $provider], $reason);
                $skipped[] = ['provider' => $provider, 'model' => $model, 'reason' => $reason];
                continue;
            }

            $messages = $this->buildL1Messages($role, $query);
            $requestOptions = ['temperature' => 0.5];
            if ($reasoningPayload !== null) {
                $requestOptions['reasoning'] = $reasoningPayload;
            }
            try {
                $build = $connector->buildBatchChatRequest($model, $messages, $requestOptions);
            } catch (\Throwable $e) {
                $stepId = $existingSteps[$stepKey] ?? $this->steps->createStep(
                    $sessionId,
                    $stepKey,
                    "L1 · {$roleKey}",
                    $orderIndex++,
                    $this->stepMeta([
                        'layer' => 'L1', 'round' => 1, 'role' => $roleKey,
                        'origin_role' => (string)$role['origin_key'],
                        'model' => $model, 'provider' => $provider,
                        'run_mode' => 'batch', 'batch_custom_id' => $customId,
                    ]),
                );
                $this->failL1Step(
                    $sessionId,
                    $stepId,
                    ['role' => $role, 'model' => $model, 'provider' => $provider],
                    Logger::sanitizeScalar($e->getMessage()),
                );
                $skipped[] = ['provider' => $provider, 'model' => $model, 'reason' => $e->getMessage()];
                continue;
            }

            // `lastL1RequestOptions` mirrors layer1Distribute()'s diagnostic
            // stash, with the payload omitted (it is only used for error
            // diagnostics and never persisted).
            $this->lastL1RequestOptions[(int)$index] = ErrorDiagnostics::filterRequestOptions(array_merge(
                $requestOptions,
                [
                    'model'       => $model,
                    'max_retries' => (int)Config::get("connectors.{$provider}.max_retries", 3),
                    'timeout'     => \App\Connectors\CallPolicy::timeoutFor((string)$provider),
                ],
            ));

            $stepId = $existingSteps[$stepKey] ?? null;
            if ($stepId === null) {
                $stepId = $this->steps->createStep(
                    $sessionId,
                    $stepKey,
                    "L1 · {$roleKey}",
                    $orderIndex++,
                    $this->stepMeta([
                        'layer' => 'L1', 'round' => 1, 'role' => $roleKey,
                        'origin_role' => (string)$role['origin_key'],
                        'model' => $model, 'provider' => $provider,
                        'peer_answers_included' => false,
                        'run_mode'    => 'batch',
                        'batch_custom_id' => $customId,
                    ]),
                );
            }
            // A distinct event type: the UI must not render a batch
            // participant as a live synchronous call, and the replay
            // endpoint has to be able to say "submitted, waiting".
            $this->emitEvent($sessionId, $stepId, 'BATCH_L1_STEP_SUBMITTED', [
                'role'       => $roleKey,
                'model'      => $model,
                'provider'   => $provider,
                'round'      => 1,
                'cycle'      => 1,
                'cycle_limit'=> $this->currentCycleLimit,
                'custom_id'  => $customId,
                'run_mode'   => 'batch',
                'detail'     => 'Queued for the provider\'s Batch API (results arrive asynchronously, in minutes to 24 h).',
            ]);

            $participants[] = [
                'index'     => (int)$index,
                'custom_id' => $customId,
                'step_id'   => $stepId,
                'provider'  => $provider,
                'model'     => $model,
                'role'      => $roleKey,
                'body'      => $build['payload'],
            ];
        }

        // Models the POOL RESOLVER dropped never reached the loop above, so
        // they have no step and no participant. They are still part of what
        // the operator selected, and a batch that quietly runs on two of three
        // chosen models is exactly the silent loss this feature is built to
        // avoid — so each one gets a failed step of its own plus an entry in
        // `skipped`, with the resolver's reason attached.
        foreach ($diag['rejected'] as $position => $row) {
            if (!is_array($row)) { continue; }
            $provider = (string)($row['provider'] ?? '');
            $model    = (string)($row['model'] ?? '');
            if ($provider === '' || $model === '') { continue; }
            $reason = sprintf(
                'the model was dropped from the batch pool before dispatch (%s)',
                (string)($row['reason'] ?? 'unknown'),
            );
            $pool[] = ['provider' => $provider, 'model' => $model];
            $role = $this->resolveParticipantRole($skillRoles, count($selectedModels) + (int)$position, $skill);
            $modelTag = strtolower(trim((string)(preg_replace('/[^a-z0-9]+/i', '_', $model) ?? 'model'), '_'));
            $roleKey  = (string)($role['key'] ?? 'participant') . '@' . $modelTag;
            $role     = array_merge($role, ['key' => $roleKey, 'origin_key' => (string)($role['key'] ?? 'participant')]);
            $stepKey  = StepKeyHelper::normalize("L1.role.{$roleKey}");
            if (isset($existingSteps[$stepKey]) || $this->detectLoop($stepKey, $sessionId)) {
                $skipped[] = ['provider' => $provider, 'model' => $model, 'reason' => $reason];
                continue;
            }
            $stepId = $this->steps->createStep(
                $sessionId,
                $stepKey,
                "L1 · {$roleKey}",
                $orderIndex++,
                $this->stepMeta([
                    'layer' => 'L1', 'round' => 1, 'role' => $roleKey,
                    'origin_role' => (string)$role['origin_key'],
                    'model' => $model, 'provider' => $provider,
                    'run_mode' => 'batch',
                ]),
            );
            $this->failL1Step($sessionId, $stepId, ['role' => $role, 'model' => $model, 'provider' => $provider], $reason);
            $skipped[] = ['provider' => $provider, 'model' => $model, 'reason' => $reason];
        }

        if ($participants === []) {
            return [
                'ok'           => false,
                'reason'       => 'No participant could be expressed as a batch request'
                    . ($skipped !== [] ? ': ' . (string)($skipped[0]['reason'] ?? '') : '.'),
                'participants' => [],
                'pool'         => $pool,
                'skipped'      => $skipped,
            ];
        }

        $this->traceEvent('BATCH_L1_PARTICIPANTS', [
            'run_mode'     => 'batch',
            'count'        => count($participants),
            'participants' => array_map(static fn(array $p): array => [
                'custom_id' => $p['custom_id'],
                'role'      => $p['role'],
                'provider'  => $p['provider'],
                'model'     => $p['model'],
            ], $participants),
        ]);

        return [
            'ok'           => true,
            'participants' => $participants,
            'pool'         => $pool,
            'skipped'      => $skipped,
        ];
    }

    /**
     * Write the accepted batch results into the journal and continue the
     * run synchronously from L2 onwards.
     *
     * ## What happens here, in order
     *
     * 1. Each participant's raw `body` becomes a {@see ChatResponse} through
     *    the connector's own `buildChatResponseFromRaw()` — the same call
     *    {@see \App\Connectors\ParallelChatDispatcher} makes for a
     *    synchronous answer — so token accounting, the provider-reported
     *    `actualModel`, and the cache store behave identically.
     * 2. The answer is written to `council_model_responses` and its step is
     *    closed via `completeStep()`, with the same meta shape the
     *    synchronous `$processOne` closure produces. A participant that
     *    failed is closed the same way with `failed` and its reason — one
     *    model refusing a batch row must look exactly like one model
     *    failing a synchronous call.
     * 3. L2/L3/L4 run through {@see self::repairSession()}, which is the
     *    engine's existing "finish this session from its persisted results"
     *    entry point. That is the honest boundary: those three layers
     *    consume L1's answers, so they cannot be submitted to the Batch API
     *    at all, and they run synchronously here.
     *
     * `custom_id` is not consulted: the orchestrator has already resolved it
     * to a participant (its `step_id`, provider, model and role), which is
     * what this method needs and what keeps it free of batch-protocol
     * knowledge.
     *
     * @param list<array{custom_id:string,error:?string,body?:array<string,mixed>,status_code?:int}> $outcomes
     *
     * @return array{
     *     answered:int,
     *     failed:int,
     *     status:string,
     *     final_answer:string,
     *     synthesis_failure:array<string,mixed>|null,
     *     envelope:array<string,mixed>
     * }
     */
    public function completeBatchL1(
        int $sessionId,
        string $query,
        array $options,
        array $outcomes,
        array $participants = [],
    ): array {
        $byCustomId = [];
        foreach ($participants as $row) {
            if (!is_array($row)) { continue; }
            $id = (string)($row['custom_id'] ?? '');
            if ($id !== '') { $byCustomId[$id] = $row; }
        }

        $answered = 0;
        $failed   = 0;
        $l1Results = [];
        $failures  = [];

        foreach ($outcomes as $outcome) {
            if (!is_array($outcome)) { continue; }
            $customId = (string)($outcome['custom_id'] ?? '');
            $meta     = $byCustomId[$customId] ?? null;
            if (!is_array($meta)) {
                // No step to write to: the orchestrator submitted a request
                // this engine did not create. Reported, never dropped
                // silently.
                $failures[] = ['custom_id' => $customId, 'reason' => 'no participant is registered for this custom_id'];
                $failed++;
                continue;
            }
            $stepId  = (int)($meta['step_id'] ?? 0);
            $roleKey = (string)($meta['role'] ?? 'participant');
            $provider = (string)($meta['provider'] ?? '');
            $model    = (string)($meta['model'] ?? '');
            $role = ['key' => $roleKey, 'origin_key' => $roleKey];

            $error = isset($outcome['error']) && is_string($outcome['error']) && $outcome['error'] !== ''
                ? $outcome['error']
                : null;
            $body = is_array($outcome['body'] ?? null) ? $outcome['body'] : null;

            if ($error !== null || $body === null) {
                $message = Logger::sanitizeScalar($error ?? 'the batch row returned no completion body');
                if ($stepId > 0) {
                    $this->steps->completeStep($stepId, 'failed', $message, $this->batchStepMeta($customId, $provider, $model));
                    $payload = [
                        'role'     => $roleKey,
                        'model'    => $model,
                        'provider' => $provider,
                        'message'  => $message,
                        'hint'     => null,
                        'round'    => 1,
                        'run_mode' => 'batch',
                        'custom_id'=> $customId,
                    ];
                    if (Config::isDebug()) {
                        $payload['diagnostics'] = ErrorDiagnostics::describeConnectorError(
                            new ConnectorException($message),
                            [
                                'provider' => $provider,
                                'model'    => $model,
                                'endpoint' => $this->endpointFor($provider),
                            ],
                        );
                    }
                    $this->emitEvent($sessionId, $stepId, 'L1_FAILED', $payload);
                }
                $failures[] = ['custom_id' => $customId, 'provider' => $provider, 'model' => $model, 'reason' => $message];
                $failed++;
                continue;
            }

            $connector = $this->connectors->getByProvider($provider);
            if ($connector === null) {
                $message = $this->describeMissingConnector($provider);
                if ($stepId > 0) {
                    $this->steps->completeStep($stepId, 'failed', $message, $this->batchStepMeta($customId, $provider, $model));
                    $this->emitEvent($sessionId, $stepId, 'L1_FAILED', [
                        'role' => $roleKey, 'model' => $model, 'provider' => $provider,
                        'message' => $message, 'hint' => null, 'round' => 1,
                        'run_mode' => 'batch', 'custom_id' => $customId,
                    ]);
                }
                $failures[] = ['custom_id' => $customId, 'provider' => $provider, 'model' => $model, 'reason' => $message];
                $failed++;
                continue;
            }

            // The batch answered asynchronously, so the wall-clock latency of
            // the *provider's* row is not observable from here; 0 is recorded
            // rather than a fabricated number. Token counts come from the
            // response's own `usage`, exactly as on the synchronous path.
            $response = $connector->buildChatResponseFromRaw($model, [], $body, 0.0);
            $stepKey  = StepKeyHelper::normalize("L1.role.{$roleKey}");
            if ($stepId > 0) {
                $this->responses->record($stepId, $response, $roleKey);
                $this->stats->record($stepKey, $response, 'L1');
                $this->steps->completeStep($stepId, 'completed', $response->content, array_merge(
                    [
                        'prompt_tokens'     => $response->promptTokens,
                        'completion_tokens' => $response->completionTokens,
                        'total_tokens'      => $response->totalTokens,
                        'tokens_estimated'  => $response->tokensEstimated,
                        'latency_ms'        => 0,
                        'reasoning'         => $response->reasoningTokens,
                        // Prompt cache. This path hand-builds its meta
                        // instead of merging statsPayload(), so the cache
                        // keys have to be named here too or a batch run
                        // would report no cache data at all while the
                        // synchronous path reports it — the same response
                        // body, read by two code paths, must not produce
                        // two different sets of numbers.
                        'cached_tokens'     => $response->cachedTokens,
                        'cache_write_tokens' => $response->cacheWriteTokens,
                        'cache_discount'    => $response->cacheDiscount,
                        'cache_stats'       => $response->hasCacheStats(),
                    ],
                    $this->batchStepMeta($customId, $provider, $model),
                ));
                $this->emitEvent($sessionId, $stepId, 'L1_RESPONSE', [
                    'role'        => $roleKey,
                    'model'       => $model,
                    'provider'    => $provider,
                    'content'     => $response->content,
                    'total_tokens'=> $response->totalTokens,
                    'cached_tokens'      => $response->cachedTokens,
                    'cache_write_tokens' => $response->cacheWriteTokens,
                    'cache_discount'     => $response->cacheDiscount,
                    'cache_stats'        => $response->hasCacheStats(),
                    'round'       => 1,
                    'cycle'       => 1,
                    'run_mode'    => 'batch',
                    'custom_id'   => $customId,
                    'final'       => false,
                    'cached'      => false,
                    // The answer arrived out of band: the UI shows it as a
                    // batch result rather than as a live call finishing.
                    'batch_result'=> true,
                ]);
            }
            $l1Results[] = [
                'role'     => array_merge($role, ['origin_key' => $roleKey]),
                'response' => $response,
                'step_id'  => $stepId,
            ];
            $answered++;
        }

        if ($failures !== []) {
            Logger::warn('batch.l1.partial_failures', [
                'session_id' => $sessionId,
                'answered'   => $answered,
                'failed'     => $failed,
                'failures'   => array_slice($failures, 0, 12),
            ]);
        }

        if ($l1Results === []) {
            // Nothing answered: there is no continuation to run, and saying
            // so here keeps the orchestrator's job report honest about which
            // stage gave up.
            $this->finalizeRunningSteps($sessionId, 'Every batch request failed; nothing to continue from.');
            $this->sessions->markFailed($sessionId, 0);
            $this->emitEvent($sessionId, null, 'BATCH_L1_NO_RESULTS', [
                'detail'   => 'No model answered the batch; the run cannot continue to L2.',
                'run_mode' => 'batch',
                'failures' => array_slice($failures, 0, 12),
            ]);
            return [
                'answered'          => 0,
                'failed'            => $failed,
                'status'            => 'failed',
                'final_answer'      => '',
                'synthesis_failure' => null,
                'envelope'          => [
                    'session_id' => $sessionId,
                    'run_mode'   => 'batch',
                    'status'     => 'failed',
                    'batch_l1'   => [
                        'answered' => 0,
                        'failed'   => $failed,
                        'failures' => array_slice($failures, 0, 12),
                    ],
                ],
            ];
        }

        // ---- L2 → L3 → L4, synchronously, from the accepted L1 results ----
        $skill = $this->resolveSkillForRun($query)['skill'];
        $started = TimeHelper::now();
        $repair = $this->repairSession([
            'session_id' => $sessionId,
            'skill'      => $skill,
            'query'      => $query,
            'options'    => $options,
            'l1_results' => $l1Results,
            'l2_normalized' => [],
            'consensus'  => null,
            'plan'       => [
                'l1_missing'           => [],
                'l2_missing'           => [],
                'l3'                   => true,
                'l4'                   => true,
                'existing_final_answer'=> null,
            ],
            'reason'     => 'batch_l1',
            'batch'      => true,
        ]);

        $synthesisFailure = $repair['synthesis_failure'] ?? null;
        $finalAnswer      = (string)($repair['final_answer'] ?? '');
        $consensus        = $repair['consensus'] ?? null;
        $durationMs       = (int)round(TimeHelper::elapsedMs($started));

        // v1.8.60 — the batch path decides its status from the same facts as
        // the synchronous one ({@see self::resolveTerminalVerdict()}): the
        // L1 answers reached here, the L4 step on the session, and the
        // answer body. The old rule read only `synthesis_failure`, so a
        // batch run whose L4 step failed on disk could still be stamped
        // `completed`.
        $batchVerdict = $this->resolveTerminalVerdict($sessionId, $finalAnswer, [], $l1Results);
        $this->recordTerminalVerdict($batchVerdict);
        $terminalStatus = $batchVerdict['status'];
        if ($batchVerdict['facts']['answer_is_fallback'] ?? false) {
            $finalAnswer = $this->markFallbackAnswer($finalAnswer);
        }
        if ($terminalStatus === 'failed') {
            $this->finalizeRunningSteps($sessionId, $batchVerdict['detail']);
        }

        $this->stats->finish();
        $sessionStats = $this->stats->getSessionStats();

        // The session row is finished here rather than inside
        // repairSession(), which deliberately leaves the decision to its
        // caller (see HistoryCompletionService). The batch run is that
        // caller.
        $this->sessions->complete(
            id:             $sessionId,
            finalAnswer:    $finalAnswer,
            consensusScore: $consensus instanceof ConsensusResult ? $consensus->score : null,
            consensusStatus:$consensus instanceof ConsensusResult ? $consensus->status : 'pending',
            totalTokens:    $sessionStats->totalTokens,
            durationMs:     $durationMs,
            status:         $terminalStatus,
        );

        $this->emitEvent($sessionId, null, 'PIPELINE_DONE', [
            'session_id'      => $sessionId,
            'duration_ms'     => $durationMs,
            'consensus'       => $consensus instanceof ConsensusResult ? $consensus->toArray() : null,
            'final_answer'    => $finalAnswer,
            'synthesis_stats' => $this->lastSynthesisStats,
            'stats'           => $sessionStats->toArray(),
            'cycle'           => 1,
            'cycle_limit'     => $this->currentCycleLimit,
            'loop_stop_reason'=> 'batch_l1',
            'status'          => $terminalStatus,
            'synthesis_failed'=> $synthesisFailure !== null,
            'status_reason'   => $batchVerdict['reason'],
            'status_detail'   => $batchVerdict['detail'],
            'status_facts'    => $batchVerdict['facts'],
            'run_mode'        => 'batch',
            'batch_l1'        => ['answered' => $answered, 'failed' => $failed],
        ]);

        Logger::info('pipeline.batch.done', [
            'session_id'         => $sessionId,
            'answered'           => $answered,
            'failed'             => $failed,
            'consensus_status'   => $consensus instanceof ConsensusResult ? $consensus->status : null,
            'final_answer_chars' => mb_strlen($finalAnswer),
            'total_tokens'       => $sessionStats->totalTokens,
            'status'             => $terminalStatus,
        ]);

        return [
            'answered'          => $answered,
            'failed'            => $failed,
            'status'            => $terminalStatus,
            'final_answer'      => $finalAnswer,
            'synthesis_failure' => $synthesisFailure,
            'envelope'          => [
                'session_id'      => $sessionId,
                'run_mode'        => 'batch',
                'final_answer'    => $finalAnswer,
                'selected_models' => $this->resolvedSelectedModels,
                'consensus'       => $consensus instanceof ConsensusResult ? $consensus->toArray() : null,
                'stats'           => $sessionStats->toArray(),
                'synthesis_stats' => $this->lastSynthesisStats,
                'status'          => $terminalStatus,
                'synthesis_failure' => $synthesisFailure,
                'batch_l1'        => [
                    'answered'  => $answered,
                    'failed'    => $failed,
                    'executed'  => $repair['executed'] ?? [],
                    'failures'  => array_slice($failures, 0, 12),
                ],
            ],
        ];
    }

    /**
     * The meta block every batch-written step carries. Kept in one place so
     * "which steps came from a batch" is a single grep, and so the batch
     * protocol stays out of the shared step meta written by the synchronous
     * paths.
     *
     * @return array<string,mixed>
     */
    private function batchStepMeta(string $customId, string $provider, string $model): array
    {
        return [
            'run_mode'        => 'batch',
            'batch_custom_id' => $customId,
            'batch_provider'  => $provider,
            'batch_model'     => $model,
        ];
    }

    /**
     * v1.9.5 - resolve the skill a run starts from. The Automatic Skill
     * Selector entry routes through SkillSelector; every other run keeps
     * the historical router/auto-creator path byte for byte.
     *
     * @return array{skill:Skill,auto:?array<string,mixed>}
     */
    private function resolveSkillForRun(string $query): array
    {
        if (SkillSelector::isRequested($this->runtimeOptions) && $this->skillSelector !== null) {
            try {
                $pick  = $this->skillSelector->select($query);
                $skill = $pick['skill'];
                if (is_string($pick['role'] ?? null) && $pick['role'] !== '') {
                    $skill = SkillSelector::withPreferredRole($skill, $pick['role']);
                }
                return ['skill' => $skill, 'auto' => $pick];
            } catch (\Throwable $e) {
                Logger::warn('skill.selector.failed', [
                    'message' => Logger::sanitizeScalar($e->getMessage()),
                ]);
            }
        }
        return ['skill' => $this->resolveSkill($query), 'auto' => null];
    }

    private function resolveSkill(string $query): Skill
    {
        $skill = $this->router->resolve($query);
        if ($skill !== null) {
            return $skill;
        }
        return $this->autoCreator->create($query);
    }

    /**
     * Is this run asking for single-model mode (`options.single_model`)?
     *
     * Strict on purpose: only literal `true`/`1` count, the same discipline the
     * tab flags follow. A string like "no", a non-empty array or a missing key
     * must never switch a run into a mode that skips consensus entirely.
     */
    private function singleModelModeRequested(): bool
    {
        $flag = $this->runtimeOptions['single_model'] ?? null;
        return $flag === true || $flag === 1 || $flag === '1';
    }

    /**
     * Does `council_sessions.consensus_status` accept the 'single' verdict?
     *
     * Checked against the live column rather than assumed, because MySQL in
     * non-strict mode accepts an unknown ENUM value and stores it as `''` -
     * which is how a completed run ends up with a blank consensus status
     * instead of an error. DDL ships in db/schema.sql plus migration
     * 022_single_model_consensus_status.sql; a database that has applied
     * neither is reported (and the run keeps the consensus path) instead of
     * being written a value it cannot hold.
     *
     * Memory mode (no PDO - the test/stub path) has no column to probe and no
     * ENUM constraint to violate, so it accepts: a stub must exercise the mode
     * rather than the probe. An unreadable column is a NO, not an assumption.
     */
    private function consensusStatusSupportsSingle(): bool
    {
        try {
            $pdo = $this->sessions->database()?->pdo();
            if ($pdo === null) {
                return true;
            }
            $stmt = $pdo->prepare(
                'SELECT COLUMN_TYPE FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME   = :table
                    AND COLUMN_NAME  = :column
                  LIMIT 1'
            );
            $stmt->execute(['table' => 'council_sessions', 'column' => 'consensus_status']);
            $type = $stmt->fetchColumn();
            $stmt->closeCursor();
            if (!is_string($type) || $type === '') {
                return false;
            }
            return str_contains(strtolower($type), self::CONSENSUS_STATUS_SINGLE);
        } catch (\Throwable $e) {
            // Includes a database that cannot even be reached: the probe is a
            // precondition check, not a step, so it must never be the reason a
            // run dies. Unknown is treated as "no".
            Logger::warn('pipeline.single_model.schema_probe_failed', [
                'message' => Logger::sanitizeScalar($e->getMessage()),
            ]);
            return false;
        }
    }

    /**
     * Validate the request's selected model list against the active model
     * catalog. Drops entries that don't exist, are inactive, or whose
     * provider connector is unavailable. Falls back to the configured
     * default pool when the request omits selection or has no usable
     * entries left after filtering.
     *
     * @param  array<int,array<string,mixed>>|null $requested
     * @return array<int,array{provider:string,model:string}>
     */
    private function resolveSelectedModels(?array $requested): array
    {
        $diag = $this->resolveSelectionWithDiagnostics($requested, 'request', false);
        // v1.8.60 — keep the refusals, not just the survivors. "Your pool is
        // empty" without the ids and the reason each one was refused is not
        // something an operator can act on, and this is the list the
        // preflight quotes back.
        $this->lastRequestRejections = $diag['rejected'];
        return $diag['accepted'];
    }

    /**
     * v1.8.34 — Richer selection resolver used for both regular request
     * selections and domain-preset selections. Returns the accepted /
     * rejected lists so the caller (run()) can decide whether to fall
     * back to another source instead of silently emptying the pool.
     *
     * When $providerAuthoritative is TRUE (the domain-preset case), the
     * resolver treats provider availability as the primary gate: any
     * model whose provider connector is registered is accepted, even
     * when the model id is not in the global active catalog. This is
     * the v1.8.34 behaviour change that prevents domain auto-routing
     * from collapsing the L1 pool to zero when the catalog drifts.
     *
     * When FALSE (the original behaviour for request-supplied lists),
     * the model id must be in the active catalog AND the provider must
     * be available — same contract as v1.8.33.
     *
     * @param  array<int,array<string,mixed>>|null $requested
     * @param  string $source One of 'request', 'preset', 'config_default'
     * @param  bool   $providerAuthoritative See description above
     * @return array{accepted:array<int,array{provider:string,model:string}>,rejected:array<int,array{reason:string,provider:string,model:string}>,source:string,used_fallback:bool}
     */
    private function resolveSelectionWithDiagnostics(
        ?array $requested,
        string $source,
        bool $providerAuthoritative,
        bool $allowBatchIds = false,
    ): array {
        $catalog = [];
        foreach (Config::getActiveModels() as $m) {
            $key = strtolower((string)$m['provider'] . '|' . (string)$m['model']);
            $catalog[$key] = ['provider' => (string)$m['provider'], 'model' => (string)$m['model']];
        }

        // Batch mode widens the catalogue, and it has to.
        //
        // A `:batch` id is never in the ACTIVE catalogue: the same rule that
        // makes it unusable for the synchronous pool
        // ({@see \App\Models\ModelCatalogMerge}) forces `active = false`
        // on its row. Requiring the active catalogue here would therefore
        // reject every single id this mode exists for, so the whole
        // catalogue is consulted instead — active or not — and, as a second
        // chance, a `:batch` spelling of a model whose PLAIN id is active is
        // accepted as the asynchronous spelling of a model we do serve.
        //
        // Note what is NOT relaxed: the provider connector still has to be
        // registered, because the batch still needs its base URL and key.
        // And none of this touches the synchronous path — `$allowBatchIds`
        // defaults to false, and every existing caller omits it.
        $batchCatalog = [];
        if ($allowBatchIds) {
            $all = Config::get('models', []);
            if (is_array($all)) {
                foreach ($all as $m) {
                    if (!is_array($m)) { continue; }
                    $provider = strtolower(trim((string)($m['provider'] ?? '')));
                    $model    = trim((string)($m['model'] ?? ''));
                    if ($provider === '' || $model === '') { continue; }
                    $batchCatalog[$provider . '|' . $model] = ['provider' => $provider, 'model' => $model];
                }
            }
        }

        $usedFallback = false;
        $candidates = [];
        if ($requested !== null) {
            foreach ($requested as $entry) {
                if (!is_array($entry)) { continue; }
                $p = strtolower(trim((string)($entry['provider'] ?? '')));
                $m = trim((string)($entry['model'] ?? ''));
                if ($p === '' || $m === '') { continue; }
                $candidates[] = ['provider' => $p, 'model' => $m];
            }
        }

        // Backstop: when the request supplies nothing usable, fall back to
        // pipeline.default_selected_models, then to the entire active set.
        // Preset sources never trigger this backstop — an empty preset
        // pool must surface so the caller can fall back to another
        // selection source (the original request or the Default preset).
        if ($candidates === [] && $source !== 'preset') {
            $defaults = Config::get('pipeline.default_selected_models', []);
            if (is_array($defaults)) {
                foreach ($defaults as $entry) {
                    if (!is_array($entry)) { continue; }
                    $p = strtolower(trim((string)($entry['provider'] ?? '')));
                    $m = trim((string)($entry['model'] ?? ''));
                    if ($p === '' || $m === '') { continue; }
                    $candidates[] = ['provider' => $p, 'model' => $m];
                }
            }
            if ($candidates !== []) {
                $usedFallback = true;
                $source = 'config_default';
            }
        }
        if ($candidates === [] && $source !== 'preset') {
            foreach ($catalog as $row) {
                $candidates[] = $row;
            }
            if ($candidates !== []) {
                $usedFallback = true;
                $source = 'active_catalog';
            }
        }

        $rejected = [];
        $accepted = [];
        $seen = [];
        foreach ($candidates as $row) {
            $key = $row['provider'] . '|' . $row['model'];
            if (isset($seen[$key])) { continue; }
            $seen[$key] = true;
            $inCatalog = isset($catalog[$key]);
            $providerAvailable = $this->connectors->hasProvider($row['provider']);
            if (!$providerAuthoritative) {
                // Request path — preserve the v1.8.33 contract: model
                // id must be in the global active catalog first, then
                // the provider connector has to be registered. Order
                // matters so failing tests / UI diagnostics still see
                // the canonical `not_in_catalog` reason for typos.
                if (!$inCatalog && $allowBatchIds) {
                    // Two ways a batch id becomes in-catalogue: its own row
                    // exists in the full catalogue (the normal case — the
                    // Models tab lists it as batch-only), or only the plain
                    // id does, in which case the `:batch` spelling is still
                    // the asynchronous surface of a model we serve.
                    $batchKey = strtolower($row['provider'] . '|' . $row['model']);
                    if (isset($batchCatalog[$batchKey])) {
                        $row = $batchCatalog[$batchKey];
                        $key = $batchKey;
                        $inCatalog = true;
                    } elseif (\App\Models\ModelId::isBatchId($row['model'])) {
                        $baseKey = strtolower($row['provider'] . '|' . \App\Models\ModelId::baseModelId($row['model']));
                        if (isset($catalog[$baseKey])) {
                            $inCatalog = true;
                        }
                    }
                }
                if (!$inCatalog) {
                    $rejected[] = ['reason' => 'not_in_catalog', 'provider' => $row['provider'], 'model' => $row['model']];
                    continue;
                }
                if (!$providerAvailable) {
                    $rejected[] = ['reason' => 'connector_unavailable', 'provider' => $row['provider'], 'model' => $row['model']];
                    continue;
                }
                // Which row is authoritative, in order: the active catalogue
                // (the historical behaviour), then a batch-only catalogue row,
                // then the caller's own spelling — which is the only one left
                // when a `:batch` id was accepted because its plain sibling is
                // active. Indexing `$catalog[$key]` unconditionally here was a
                // real defect: the batch id has no active row by construction,
                // so the lookup produced null and every consumer below read
                // `null['provider']`.
                $accepted[] = $catalog[$key]
                    ?? $batchCatalog[$key]
                    ?? ['provider' => $row['provider'], 'model' => $row['model']];
                continue;
            }
            // Provider-authoritative path (domain presets): the model
            // is accepted as a first-class preset pick even if the
            // global active catalog does not list it. The provider
            // connector still has to be registered so the dispatcher
            // can route the call.
            if (!$providerAvailable) {
                $rejected[] = ['reason' => 'connector_unavailable', 'provider' => $row['provider'], 'model' => $row['model']];
                continue;
            }
            $accepted[] = $inCatalog ? $catalog[$key] : ['provider' => $row['provider'], 'model' => $row['model']];
        }

        $this->traceEvent('SELECTED_MODELS_RESOLVED', [
            'accepted'      => $accepted,
            'rejected'      => $rejected,
            'source'        => $source,
            'used_fallback' => $usedFallback,
        ]);

        return [
            'accepted'      => $accepted,
            'rejected'      => $rejected,
            'source'        => $source,
            'used_fallback' => $usedFallback,
        ];
    }

    /**
     * @param array<int,array{provider:string,model:string}> $selectedModels
     * @param array{previous_l1:array<int,array<string,mixed>>,previous_consensus:?ConsensusResult,source_round:int}|null $peerContext
     * @param array<int,string> $skipModelKeys Optional `provider|model` keys (case-insensitive) that must NOT be
     *                                          dispatched. Used by repairSession() to reuse answers already stored
     *                                          for an interrupted run. Participants keep their original index — and
     *                                          therefore their original step key — because the full ordered model
     *                                          list is still walked; the skipped entries are simply not dispatched.
     * @return array<int,array{role:array<string,mixed>,response:ChatResponse,step_id:int}>
     */
    private function layer1Distribute(int $sessionId, Skill $skill, string $query, array $selectedModels, int $round = 1, ?array $peerContext = null, array $skipModelKeys = []): array
    {
        $skipIndex = [];
        foreach ($skipModelKeys as $key) {
            if (is_string($key) && $key !== '') {
                $skipIndex[strtolower($key)] = true;
            }
        }
        if ($selectedModels === []) {
            // v1.8.34 — enrich the no-models diagnostic with the full
            // selection trace so the UI can surface exactly which
            // domain preset was tried, which fallbacks ran, and which
            // candidate entries were rejected with their reasons. The
            // payload is structured so frontends can render a table
            // instead of a generic toast.
            $diag = $this->lastSelectionDiagnostic ?? [];
            $routing = $this->lastRoutingNotice ?? [];
            $this->emitEvent($sessionId, null, 'L1_NO_AVAILABLE_MODELS', [
                'detail' => 'No usable models in the selected pool. Configure connectors with valid API keys '
                          . 'and pick at least one available model in the UI.',
                'round'                => $round,
                'requested_domain'     => $routing['domain']     ?? null,
                'preset_slug'          => $routing['preset_slug'] ?? ($diag['preset_slug'] ?? null),
                'preset_name'          => $routing['preset_name'] ?? ($diag['preset_name'] ?? null),
                'selection_source'     => $diag['source']         ?? null,
                'candidate_count'      => is_array($diag['fallback_chain'] ?? null)
                    ? array_sum(array_map(static fn($r): int => (int)($r['candidate_count'] ?? $r['accepted_count'] ?? 0), $diag['fallback_chain']))
                    : 0,
                'rejected_models'      => $diag['rejected']       ?? [],
                'fallback_chain'       => $diag['fallback_chain'] ?? [],
                'available_providers'  => $this->availableProviderList(),
                'settings_anchor'      => '#oc-domain-models',
            ]);
            Logger::warn('L1 has no usable selected models', [
                'selection_source' => $diag['source'] ?? null,
                'preset_slug'      => $routing['preset_slug'] ?? null,
                'rejected_count'   => is_array($diag['rejected'] ?? null) ? count($diag['rejected']) : 0,
            ]);
            return [];
        }

        // Materialize a participant per selected model. When the skill
        // defines roles, reuse them in order; once exhausted, mint
        // synthetic "consensus" participants so each model gets a
        // distinct system prompt label.
        $skillRoles = $skill->roles();
        $maxParticipants = max(1, (int)Config::get('pipeline.max_roles_per_skill', 12));
        $selectedModels  = array_slice($selectedModels, 0, $maxParticipants);

        // v1.9.x - the breaker can trip DURING a run: a model that was fine when the
        // pool was assembled, or that answered the first round, may be open by the
        // time this round is dispatched. Replace it here, before the participant rows
        // are built, so the step cards carry the stand-in from the very start - the
        // council should not thin out as the cycles go on. Idempotent: after the
        // first replacement nothing in the pool is open, so a second call finds
        // nothing to do and emits nothing.
        $roundSubstitution = ModelSubstitution::plan(
            $selectedModels,
            $this->dispatcher->openModels(),
            Config::getActiveModels(),
        );
        if ($roundSubstitution['replaced'] !== [] || $roundSubstitution['unfilled'] !== []) {
            $selectedModels = $roundSubstitution['pool'];
            $this->emitEvent($sessionId, null, 'MODEL_SUBSTITUTED', [
                'replaced' => $roundSubstitution['replaced'],
                'unfilled' => $roundSubstitution['unfilled'],
                'round'    => $round,
                'reason'   => 'model_breaker_open_mid_run',
            ]);
        }

        $participants = [];
        foreach ($selectedModels as $i => $mm) {
            $role = $this->resolveParticipantRole($skillRoles, $i, $skill);
            // Disambiguate the role key per model so later logs/UI can
            // tell two GPT-style participants apart.
            $modelTag = preg_replace('/[^a-z0-9]+/i', '_', $mm['model']) ?? 'model';
            $modelTag = strtolower(trim((string)$modelTag, '_'));
            $roleKey  = (string)($role['key'] ?? 'participant') . '@' . $modelTag;
            $participants[] = [
                'role'      => array_merge($role, ['key' => $roleKey, 'origin_key' => (string)($role['key'] ?? 'participant')]),
                'provider'  => $mm['provider'],
                'model'     => $mm['model'],
            ];
        }

        $this->traceEvent('L1_PARTICIPANTS', [
            'round'        => $round,
            'count'        => count($participants),
            'participants' => array_map(static fn(array $p): array => [
                'role'     => (string)$p['role']['key'],
                'provider' => $p['provider'],
                'model'    => $p['model'],
            ], $participants),
        ]);

        // Index peer answers by origin role-key for fast lookup when
        // building round-2 prompts.
        $peersByOriginRole = [];
        if ($peerContext !== null && isset($peerContext['previous_l1']) && is_array($peerContext['previous_l1'])) {
            foreach ($peerContext['previous_l1'] as $row) {
                $rk = (string)($row['role']['key'] ?? '');
                if ($rk !== '') {
                    $peersByOriginRole[$rk] = $row;
                }
            }
        }
        // Round-2 system prompt resolved once. The skill file may define
        // it; if it does not (e.g. for auto-created generic skills), fall
        // back to the default consensus-reasoning skill's prompt loaded
        // from disk.
        $round2SystemPrompt = $round > 1 ? $this->resolveRound2SystemPrompt($skill) : null;

        // Pre-create step rows so the UI gets running placeholders with
        // model/provider already set, and each step can be updated as the
        // dispatcher returns each response. Round-2 steps carry a
        // `.round2.` segment in the step key so loop detection and DB
        // uniqueness are preserved across rounds.
        $orderBase = ($round - 1) * 1000;
        $orderIndex = $orderBase + 1;
        $byIndex = [];
        $roundSegment = $round > 1 ? "round{$round}." : '';
        foreach ($participants as $idx => $p) {
            if (isset($skipIndex[strtolower((string)$p['provider'] . '|' . (string)$p['model'])])) {
                // Session repair: this model already answered during the
                // interrupted run — reuse the stored response instead of
                // burning a second call. No step row is created here so the
                // append-only journal only gains the slots that were truly
                // missing.
                continue;
            }
            $roleKey = (string)$p['role']['key'];
            $stepKey = StepKeyHelper::normalize("L1.{$roundSegment}role.{$roleKey}");
            if ($this->detectLoop($stepKey, $sessionId)) {
                continue;
            }
            $stepLabel = $round > 1
                ? "L1 · {$roleKey} (round {$round})"
                : "L1 · {$roleKey}";
            $stepId = $this->steps->createStep($sessionId, $stepKey, $stepLabel, $orderIndex++, $this->stepMeta([
                'layer'    => 'L1',
                'round'    => $round,
                'role'     => $roleKey,
                'origin_role' => (string)($p['role']['origin_key'] ?? $roleKey),
                'model'    => $p['model'],
                'provider' => $p['provider'],
                'peer_answers_included' => $round > 1,
            ]));
            $this->emitEvent($sessionId, $stepId, 'L1_STEP_STARTED', [
                'role'        => $roleKey,
                // v1.9.x - the card says which role answered, so the payload carries
                // the human label next to the key: an operator reads "Domain Expert".
                'role_label'  => (string)($p['role']['label'] ?? $roleKey),
                'model'       => $p['model'],
                'provider'    => $p['provider'],
                'round'       => $round,
                'cycle'       => $round,
                'cycle_limit' => $this->currentCycleLimit,
                'peer_answers_included' => $round > 1,
            ]);
            $byIndex[$idx] = ['step_id' => $stepId, 'participant' => $p];
        }

        // Build dispatch requests.
        $reasoningPayload = $this->reasoningPayload($this->runtimeOptions['reasoning'] ?? null);
        $requests = [];
        $tagToIndex = [];
        $cacheRequests = [];
        $cachedResults = [];
        $useParallel = (bool)Config::get('pipeline.parallel_l1', true);
        foreach ($byIndex as $idx => $entry) {
            $p = $entry['participant'];
            $conn = $this->connectors->getByProvider($p['provider']);
            if ($conn === null) {
                $reason = $this->describeMissingConnector($p['provider']);
                $this->failL1Step($sessionId, $entry['step_id'], $p, $reason);
                continue;
            }
            if ($round > 1) {
                $messages = $this->buildL1Round2Messages(
                    $p['role'],
                    $query,
                    $peersByOriginRole,
                    $peerContext['previous_consensus'] ?? null,
                    $round2SystemPrompt,
                );
            } else {
                $messages = $this->buildL1Messages($p['role'], $query);
            }
            $options  = ['temperature' => 0.5];
            if ($reasoningPayload !== null) {
                $options['reasoning'] = $reasoningPayload;
            }
            if ($round > 1) {
                // Disambiguate the cache slot per round so a round-2 prompt
                // never collides with a round-1 cache entry for the same
                // provider+model+messages length.
                $options['consensus_round'] = $round;
            }
            // Cache lookup: if we already answered this exact query for
            // this provider+model+options, short-circuit the network
            // call and emit L1_RESPONSE with cache_hit=true. Skipped
            // entirely when the run sets options.cache_mode = 'bypass'.
            $hit = $this->cacheForRun()?->lookup($p['provider'], $p['model'], $messages, $options);
            if ($hit !== null) {
                $cachedResults[$idx] = ['hit' => $hit, 'entry' => $entry, 'messages' => $messages, 'options' => $options];
                continue;
            }
            $req = new ParallelChatRequest(
                connector: $conn,
                model:     $p['model'],
                messages:  $messages,
                options:   $options,
                tag:       $idx,
            );
            $requests[] = $req;
            $tagToIndex[count($requests) - 1] = $idx;
            $cacheRequests[$idx] = ['messages' => $messages, 'options' => $options];
            // Stash a sanitized copy for diagnostics on failure.
            $this->lastL1RequestOptions[$idx] = ErrorDiagnostics::filterRequestOptions(array_merge($options, [
                'model'       => $p['model'],
                'max_retries' => (int)Config::get("connectors.{$p['provider']}.max_retries", 3),
                'timeout'     => \App\Connectors\CallPolicy::timeoutFor((string)$p['provider']),
            ]));
        }

        // Process cached hits up-front so the UI sees them as "completed"
        // immediately while the network calls run.
        foreach ($cachedResults as $idx => $row) {
            $entry = $row['entry'];
            $p = $entry['participant'];
            $hit = $row['hit'];
            $resp = $hit['response'];
            $this->emitCachedL1Response($sessionId, $entry, $p, $resp, $hit);
        }

        if ($requests === []) {
            // Build the result list from cache hits only.
            return $this->buildL1Output($cachedResults, true);
        }

        // Aggregate L1 outputs in a slot-keyed map so each finished
        // request can be processed as soon as it returns. Cached entries
        // are pre-populated above; the dispatcher fills in the rest via
        // the per-completion callback so SSE clients see L1 cards
        // populate live instead of all-at-once.
        $out = [];
        foreach ($cachedResults as $row) {
            $out[] = ['role' => $row['entry']['participant']['role'], 'response' => $row['hit']['response'], 'step_id' => $row['entry']['step_id']];
        }

        $processOne = function (int $rowIdx, array $row) use (&$out, $sessionId, $byIndex, $tagToIndex, $cacheRequests): void {
            $idx = $tagToIndex[$rowIdx] ?? null;
            if ($idx === null || !isset($byIndex[$idx])) { return; }
            $entry = $byIndex[$idx];
            $p = $entry['participant'];
            $stepId  = $entry['step_id'];
            $roleKey = (string)$p['role']['key'];
            if (($row['ok'] ?? false) === true && isset($row['response']) && $row['response'] instanceof ChatResponse) {
                /** @var ChatResponse $resp */
                $resp = $row['response'];
                $stepKey = StepKeyHelper::normalize("L1.role.{$roleKey}");
                $this->responses->record($stepId, $resp, $roleKey);
                $this->stats->record($stepKey, $resp, 'L1');
                $cacheInfo = $this->storeChatInCache($p['provider'], $p['model'], $idx, $cacheRequests, $resp);
                $cacheMeta = ['cache_hit' => false];
                if ($cacheInfo !== null) {
                    $cacheMeta['cache_key']       = $cacheInfo['key']       ?? null;
                    $cacheMeta['cache_short_key'] = $cacheInfo['short_key'] ?? null;
                    $cacheMeta['cache_stored']    = (bool)($cacheInfo['stored'] ?? false);
                }
                $this->steps->completeStep(
                    $stepId,
                    'completed',
                    $resp->content,
                    array_merge(
                        ['layer' => 'L1', 'role' => $roleKey, 'round' => $this->currentRound, 'summary_preview' => mb_substr($resp->content, 0, 240)],
                        $resp->statsPayload(),
                        $this->extractReasoning($resp),
                        $cacheMeta,
                    ),
                );
                // Emit IMMEDIATELY so the SSE client paints the L1 card
                // for this model the moment its network call returns.
                $this->emitEvent($sessionId, $stepId, 'L1_RESPONSE', array_merge(
                    ['role' => $roleKey, 'round' => $this->currentRound, 'cycle' => $this->currentRound, 'cycle_limit' => $this->currentCycleLimit],
                    $resp->statsPayload(),
                    [
                        'tokens'  => $resp->totalTokens,
                        'content' => $resp->content,
                        'preview' => mb_substr($resp->content, 0, 240),
                    ],
                    $cacheMeta,
                ));
                Logger::info('L1.response', [
                    'session_id'    => $sessionId,
                    'role'          => $roleKey,
                    'provider'      => $p['provider'],
                    'model'         => $p['model'],
                    'cache_hit'     => false,
                    'content_chars' => mb_strlen($resp->content),
                    'tokens'        => $resp->totalTokens,
                ]);
                $out[] = ['role' => $p['role'], 'response' => $resp, 'step_id' => $stepId];
            } else {
                $message = (string)($row['error'] ?? 'unknown error');
                $hint = $this->diagnoseConnectorError($message, $p['provider'], $p['model']);
                $errorContext = ErrorDiagnostics::describeConnectorError(
                    new ConnectorException($message),
                    [
                        'provider'        => $p['provider'],
                        'model'           => $p['model'],
                        'endpoint'        => $this->endpointFor($p['provider']),
                        'request_options' => $this->lastL1RequestOptions[$idx] ?? [],
                    ],
                );
                if ($hint !== null) { $message .= ' — ' . $hint; }
                $this->steps->completeStep($stepId, 'failed', $message);
                $payload = [
                    'role'     => $roleKey,
                    'model'    => $p['model'],
                    'provider' => $p['provider'],
                    'message'  => $message,
                    'hint'     => $hint,
                    'round'    => $this->currentRound,
                ];
                if (Config::isDebug()) {
                    $payload['diagnostics'] = $errorContext;
                }
                $this->emitEvent($sessionId, $stepId, 'L1_FAILED', $payload);
                Logger::warn('L1 model failed', [
                    'role'     => $roleKey,
                    'model'    => $p['model'],
                    'provider' => $p['provider'],
                    'message'  => $message,
                ]);
            }
        };

        // Last-chance checkpoint before kicking off network calls. When
        // running in parallel the multi-curl call cannot be aborted from
        // outside, so pausing at this point is the latest the engine can
        // honor a `pause`/`reject` signal without leaking partial work.
        $this->checkpoint($sessionId, 'pre_l1_dispatch');

        if ($useParallel && count($requests) > 1) {
            // Per-completion callback drives per-model emission. The
            // dispatcher invokes it from inside curl_multi_info_read, so
            // events flush as soon as each network call lands.
            $this->dispatcher->dispatch($requests, $processOne);
        } else {
            // Sequential fallback: identical accounting, identical event
            // shape — and identical user-visible streaming because we
            // emit after each chat() call instead of buffering. We also
            // honor the run-control checkpoint between each call so a
            // pause/reject during the L1 sweep takes effect mid-batch.
            foreach ($requests as $i => $req) {
                $idx = $tagToIndex[$i] ?? null;
                if ($idx === null || !isset($byIndex[$idx])) { continue; }
                $this->checkpoint($sessionId, 'l1_iter');
                try {
                    $resp = $req->connector->chat($req->model, $req->messages, $req->options);
                    $processOne($i, ['ok' => true, 'response' => $resp, 'tag' => $idx]);
                } catch (ConnectorException $e) {
                    $processOne($i, ['ok' => false, 'error' => $e->getMessage(), 'tag' => $idx]);
                }
            }
        }
        return $out;
    }

    /**
     * Materialize the L1 output structure from cache-only entries (used
     * when every selected model was a cache hit, so no network requests
     * were dispatched).
     *
     * @param  array<int,array<string,mixed>> $cachedResults
     * @return array<int,array{role:array<string,mixed>,response:ChatResponse,step_id:int}>
     */
    private function buildL1Output(array $cachedResults, bool $cacheOnly): array
    {
        $out = [];
        foreach ($cachedResults as $row) {
            $out[] = [
                'role'     => $row['entry']['participant']['role'],
                'response' => $row['hit']['response'],
                'step_id'  => $row['entry']['step_id'],
            ];
        }
        return $out;
    }

    /**
     * Emit + persist a cache-hit L1 response. Mirrors the network branch
     * of layer1Distribute() so downstream consumers (UI, stats, repo)
     * see identical envelope shape — just with cache_hit=true.
     *
     * @param array<string,mixed> $entry
     * @param array<string,mixed> $participant
     * @param array<string,mixed> $hit
     */
    private function emitCachedL1Response(int $sessionId, array $entry, array $participant, ChatResponse $resp, array $hit): void
    {
        $stepId  = (int)$entry['step_id'];
        $roleKey = (string)$participant['role']['key'];
        $stepKey = StepKeyHelper::normalize("L1.role.{$roleKey}");
        $this->responses->record($stepId, $resp, $roleKey);
        $this->stats->record($stepKey, $resp, 'L1');
        $cacheMeta = [
            'cache_hit'       => true,
            'cache_key'       => (string)($hit['key'] ?? ''),
            'cache_short_key' => (string)($hit['short_key'] ?? ''),
            'cache_created_at'=> (string)($hit['created_at'] ?? ''),
            'cache_hits'      => (int)($hit['hits'] ?? 0),
        ];
        $this->steps->completeStep(
            $stepId,
            'completed',
            $resp->content,
            array_merge(
                ['layer' => 'L1', 'role' => $roleKey, 'round' => $this->currentRound, 'summary_preview' => mb_substr($resp->content, 0, 240)],
                $resp->statsPayload(),
                $this->extractReasoning($resp),
                $cacheMeta,
            ),
        );
        $this->emitEvent($sessionId, $stepId, 'L1_RESPONSE', array_merge(
            ['role' => $roleKey, 'round' => $this->currentRound, 'cycle' => $this->currentRound, 'cycle_limit' => $this->currentCycleLimit],
            $resp->statsPayload(),
            [
                'tokens'  => $resp->totalTokens,
                'content' => $resp->content,
                'preview' => mb_substr($resp->content, 0, 240),
            ],
            $cacheMeta,
        ));
        Logger::info('L1.response.cache_hit', [
            'session_id'    => $sessionId,
            'role'          => $roleKey,
            'provider'      => $participant['provider'] ?? '',
            'model'         => $participant['model'] ?? '',
            'cache_hit'     => true,
            'content_chars' => mb_strlen($resp->content),
        ]);
        $this->cache?->recordHit((string)($hit['key'] ?? ''));
    }

    /**
     * Best-effort cache write for a successful network response. Failures
     * are silent — caching never blocks the user-facing pipeline.
     *
     * @param array<int,array{messages:array<int,array{role:string,content:string}>,options:array<string,mixed>}> $cacheRequests
     * @return array<string,mixed>|null
     */
    private function storeChatInCache(string $provider, string $model, int $idx, array $cacheRequests, ChatResponse $resp): ?array
    {
        $cache = $this->cacheForRun();
        if ($cache === null) {
            return null;
        }
        $req = $cacheRequests[$idx] ?? null;
        if (!is_array($req)) {
            return null;
        }
        try {
            return $cache->store($provider, $model, $req['messages'], $req['options'], $resp);
        } catch (\Throwable $e) {
            Logger::warn('cache store failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * @param array<int,array<string,mixed>> $skillRoles
     * @return array<string,mixed>
     */
    private function resolveParticipantRole(array $skillRoles, int $index, Skill $skill): array
    {
        if (isset($skillRoles[$index]) && is_array($skillRoles[$index])) {
            return $skillRoles[$index];
        }
        if ($skillRoles !== []) {
            // Reuse the first role as a "consensus participant" template
            // so additional models get a coherent system prompt rather
            // than a generic placeholder.
            $template = $skillRoles[0];
            $key = 'consensus_' . ($index + 1);
            return array_merge($template, [
                'key'    => $key,
                'label'  => 'Consensus participant ' . ($index + 1),
                'system' => (string)($template['system'] ?? '')
                          . "\n\n[You are an additional consensus participant. Provide an independent, well-reasoned answer.]",
            ]);
        }
        return [
            'key'    => 'participant_' . ($index + 1),
            'label'  => 'Participant ' . ($index + 1),
            'system' => 'You are an independent consensus participant. Answer the user query thoroughly and honestly.',
        ];
    }

    /**
     * The attachments queued for this run (validated upstream).
     *
     * @return array<int,array{name:string,chars:int,content:string}>
     */
    private function attachments(): array
    {
        $items = $this->runtimeOptions['attachments'] ?? null;
        if (!is_array($items)) {
            return [];
        }
        $out = [];
        foreach ($items as $item) {
            if (is_array($item)
                && is_string($item['name'] ?? null)
                && is_string($item['content'] ?? null)
                && isset($item['chars'])) {
                $out[] = ['name' => $item['name'], 'chars' => (int)$item['chars'], 'content' => $item['content']];
            }
        }
        return $out;
    }

    /**
     * Prefix a user message with the attached documents, when there are any.
     *
     * The block is prepended everywhere a model reads the query - L1, the
     * round-2 revision, synthesis and the arbiter - so every stage sees the
     * same material, and it stays in the user message: the system prompt is
     * the cache prefix and must keep its bytes (see buildL1Messages).
     */
    private function prependAttachments(string $text): string
    {
        $block = QueryAttachments::renderBlock($this->attachments());
        return $block === '' ? $text : $block . "\n\n" . $text;
    }

    /**
     * L1 messages for one role: a stable `system` prefix, then the query.
     *
     * ## Why the memory block is in the user message
     *
     * The `system` message is the prompt-cache prefix. Bothub's caching
     * documentation requires the cached prefix to be **byte-identical
     * between requests** — anything that varies has to come after it — so
     * the two halves are separated by what they depend on:
     *
     *   system  ← role prompt + base file + profile addendum. A function of
     *             (role, skill, profile) only, so two runs of the same role
     *             produce the same bytes and the prefix is cacheable.
     *   user    ← memory recall + the query. Both are per-request, so both
     *             live after the breakpoint.
     *
     * Recall used to be appended to the system message, which made the
     * prefix unique per query and would have defeated caching on every L1
     * call (the L1 fan-out is where the prefix is reused most). The block
     * keeps its wording, its position *relative to the query* (memory
     * before question) and its gating — only the message it travels in
     * changed.
     *
     * @param  array<string,mixed> $role
     * @return array<int,array{role:string,content:string}>
     */
    private function buildL1Messages(array $role, string $query): array
    {
        $roleSystem = (string)($role['system'] ?? 'You are a helpful expert. Answer thoughtfully.');
        $system = $this->composeSystemPrompt($roleSystem);
        $user   = $query;
        $memoryText = $this->recallMemoryContext($query);
        if ($memoryText !== '') {
            $user = "[MEMORY CONTEXT - knowledge from earlier conversations. Use it when relevant; never claim it as your own reasoning; ignore it when it contradicts the current query.]\n"
                  . $memoryText
                  . "\n\n" . $query;
        }
        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user',   'content' => $this->prependAttachments($user)],
        ];
    }

    /**
     * Task 5 - recall cross-session memory context for the L1 prompt.
     *
     * Gated by `pipeline.memory_top_k` > 0 and a wired EmbeddingMemory;
     * every failure degrades to "no memory" with a warn entry so the
     * vector layer can never break a pipeline run.
     */
    private function recallMemoryContext(string $query): string
    {
        if ($this->memory === null || trim($query) === '') {
            return '';
        }
        $topK = (int)Config::get('pipeline.memory_top_k', 0);
        if ($topK <= 0) {
            return '';
        }
        try {
            $recall = $this->memory->recallContextGlobal($query, $topK);
            return is_array($recall) ? (string)($recall['text'] ?? '') : '';
        } catch (\Throwable $e) {
            Logger::warn('pipeline.memory_recall_failed', [
                'error' => Logger::sanitizeScalar($e->getMessage()),
            ]);
            return '';
        }
    }

    /**
     * Task 5 - persist the query and the successful L4 answer to memory.
     *
     * Same gate + degradation contract as recallMemoryContext(); called
     * only after a successful L4 synthesis per the task plan.
     */
    private function storeMemoryContext(int $sessionId, string $query, string $finalAnswer): void
    {
        if ($this->memory === null || trim($query) === '' || trim($finalAnswer) === '') {
            return;
        }
        $topK = (int)Config::get('pipeline.memory_top_k', 0);
        if ($topK <= 0) {
            return;
        }
        try {
            $this->memory->storeText($sessionId, $query, MemoryItem::TYPE_TEXT, [
                'role' => 'user_query',
                'session_id' => $sessionId,
            ]);
            $this->memory->storeFinalAnswer($sessionId, $finalAnswer, [
                'consensus_status' => 'stored_after_l4',
            ]);
        } catch (\Throwable $e) {
            Logger::warn('pipeline.memory_store_failed', [
                'error' => Logger::sanitizeScalar($e->getMessage()),
            ]);
        }
    }

    /**
     * Build the round-2 prompt for a single participant. The participant
     * receives:
     *   (a) the original user query;
     *   (b) its own previous round-1 answer, clearly labelled;
     *   (c) every OTHER model's previous round-1 answer, clearly labelled
     *       by role/provider/model;
     *   (d) the round-1 L3 consensus verdict and score;
     *   (e) the consensus-reasoning round-2 system prompt that instructs
     *       the model to revise or defend its answer using the other
     *       models' evidence without blindly copying.
     *
     * Two system messages are emitted (own role system + round-2 system
     * prompt) so connectors that collapse them into one prepend block can
     * keep their original semantics while still honouring the new
     * instruction set.
     *
     * @param  array<string,mixed> $role
     * @param  array<string,array{role:array<string,mixed>,response:ChatResponse,step_id:int}> $peersByOriginRole keyed by role.key
     * @return array<int,array{role:string,content:string}>
     */
    private function buildL1Round2Messages(
        array $role,
        string $query,
        array $peersByOriginRole,
        ?ConsensusResult $previousConsensus,
        ?string $round2SystemPrompt,
    ): array {
        $rawRoleSystem = (string)($role['system'] ?? 'You are a helpful expert. Answer thoughtfully.');
        $baseSystem = $this->composeSystemPrompt($rawRoleSystem);
        $ownRoleKey = (string)($role['key'] ?? '');
        $own = $peersByOriginRole[$ownRoleKey] ?? null;

        $userParts = [];
        $userParts[] = "Original user query:\n" . $query;

        if ($own !== null && isset($own['response']) && $own['response'] instanceof ChatResponse) {
            $userParts[] = "Your previous answer (round 1):\n" . trim($own['response']->content);
        }

        // Other-model answers — exclude self by role key. Numbered for
        // readability so the model can reference them as "answer N".
        $i = 0;
        $sections = [];
        foreach ($peersByOriginRole as $rk => $row) {
            if ($rk === $ownRoleKey) { continue; }
            if (!isset($row['response']) || !($row['response'] instanceof ChatResponse)) { continue; }
            $i++;
            $resp     = $row['response'];
            $provider = (string)$resp->provider;
            $model    = (string)$resp->effectiveModel();
            $sections[] = "### Other model answer {$i} — role \"{$rk}\" (provider: {$provider}, model: {$model})\n"
                        . trim($resp->content);
        }
        if ($sections !== []) {
            $userParts[] = "Other models' answers from round 1 (excluding your own):\n\n" . implode("\n\n", $sections);
        } else {
            $userParts[] = "Other models' answers from round 1 (excluding your own): (none available)";
        }

        if ($previousConsensus !== null) {
            $userParts[] = sprintf(
                "L3 consensus verdict after round 1: status = %s, score = %s.",
                $previousConsensus->status,
                number_format((float)$previousConsensus->score, 3),
            );
        }

        $userParts[] = "Task: revise OR defend your previous answer using the evidence from the other models' "
                     . "answers. Do NOT blindly copy any single peer answer. Cite which peer points changed your "
                     . "mind, which you rejected and why, and produce a final round-2 answer that is at least as "
                     . "rigorous as your round-1 attempt.";

        $messages = [
            ['role' => 'system', 'content' => $baseSystem],
        ];
        if (is_string($round2SystemPrompt) && $round2SystemPrompt !== '') {
            $messages[] = ['role' => 'system', 'content' => $round2SystemPrompt];
        }
        $messages[] = ['role' => 'user', 'content' => $this->prependAttachments(implode("\n\n", $userParts))];
        return $messages;
    }

    /**
     * Resolve the round-2 system prompt. Priority:
     *   1. `round2_system_prompt` key on the active skill definition.
     *   2. `round2_system_prompt` from the bundled consensus-reasoning
     *      skill on disk (so auto-created generic skills inherit it).
     *   3. null — caller falls back to the role's base system prompt only.
     */
    private function resolveRound2SystemPrompt(Skill $skill): ?string
    {
        $fromSkill = $skill->definition['round2_system_prompt'] ?? null;
        if (is_string($fromSkill) && trim($fromSkill) !== '') {
            return $fromSkill;
        }
        // Fallback to bundled definition.
        $defFile = __DIR__ . '/../Skills/definitions/consensus_reasoning.json';
        if (is_file($defFile)) {
            $body = @file_get_contents($defFile);
            if (is_string($body) && $body !== '') {
                $decoded = json_decode($body, true);
                if (is_array($decoded) && isset($decoded['round2_system_prompt'])
                    && is_string($decoded['round2_system_prompt'])
                    && trim($decoded['round2_system_prompt']) !== ''
                ) {
                    return (string)$decoded['round2_system_prompt'];
                }
            }
        }
        return null;
    }

    /**
     * @param array<string,mixed> $participant
     */
    private function failL1Step(int $sessionId, int $stepId, array $participant, string $reason): void
    {
        $roleKey = (string)$participant['role']['key'];
        $this->steps->completeStep($stepId, 'failed', $reason);
        $payload = [
            'role'     => $roleKey,
            'model'    => $participant['model'],
            'provider' => $participant['provider'],
            'message'  => $reason,
            'hint'     => null,
            'round'    => $this->currentRound,
        ];
        if (Config::isDebug()) {
            $payload['diagnostics'] = ErrorDiagnostics::describeConnectorError(
                new ConnectorException($reason),
                [
                    'provider' => $participant['provider'],
                    'model'    => $participant['model'],
                    'endpoint' => $this->endpointFor($participant['provider']),
                ],
            );
        }
        $this->emitEvent($sessionId, $stepId, 'L1_FAILED', $payload);
    }

    /**
     * @param  array<int,array{role:array<string,mixed>,response:ChatResponse,step_id:int}> $l1Results
     * @return array<int,array{role:array<string,mixed>,normalized:array<string,mixed>}>
     */
    private function layer2Normalize(int $sessionId, Skill $skill, array $l1Results, int $round = 1): array
    {
        $out = [];
        $orderIndex = ($round - 1) * 1000 + 100;
        $roundSegment = $round > 1 ? "round{$round}." : '';
        foreach ($l1Results as $row) {
            $roleKey = (string)($row['role']['key'] ?? '');
            $stepKey = StepKeyHelper::normalize("L2.{$roundSegment}normalize.{$roleKey}");
            if ($this->detectLoop($stepKey, $sessionId)) { return $out; }

            $stepLabel = $round > 1 ? "L2 · {$roleKey} (round {$round})" : "L2 · {$roleKey}";
            $stepId = $this->steps->createStep($sessionId, $stepKey, $stepLabel, $orderIndex++, $this->stepMeta([
                'layer' => 'L2',
                'round' => $round,
                'role'  => $roleKey,
            ]));
            $schema = is_array($row['role']['output_schema'] ?? null)
                ? $row['role']['output_schema']
                : ($skill->definition['output_schema'] ?? null);
            $sourceContent = $row['response']->content;
            $sourceLength  = mb_strlen($sourceContent);
            $normalized = $this->normalizer->normalize($sourceContent, is_array($schema) ? $schema : null);
            $this->responses->record($row['step_id'], $row['response'], $roleKey, $normalized);

            $claims     = $normalized['claims'];
            $claimCount = count($claims);
            $shape      = (string)$normalized['shape'];
            $shapeLabel = $shape === 'json' ? 'structured JSON' : 'plain text';
            $sourceModel = $row['response']->effectiveModel();
            $previewClaims = array_slice($claims, 0, 3);

            $detail = [];
            $detail[] = sprintf(
                'Source: role "%s" using model "%s" (provider %s). Raw answer length: %d chars.',
                $roleKey,
                $sourceModel,
                $row['response']->provider,
                $sourceLength,
            );
            $detail[] = sprintf(
                'Shape: %s. Strategy: %s. Produced %d claim%s for L3 consensus scoring.',
                $shapeLabel,
                $shape === 'json'
                    ? 'extracted JSON object and projected onto skill output_schema'
                    : 'split sentences on .!?\\n boundaries',
                $claimCount,
                $claimCount === 1 ? '' : 's',
            );
            if ($previewClaims !== []) {
                $previewLines = [];
                foreach ($previewClaims as $i => $c) {
                    $previewLines[] = sprintf('  %d) %s', $i + 1, mb_substr((string)$c, 0, 220));
                }
                $extra = $claimCount > count($previewClaims) ? sprintf(' (+ %d more)', $claimCount - count($previewClaims)) : '';
                $detail[] = "Preview claims:\n" . implode("\n", $previewLines) . $extra;
            }
            $detail[] = 'Downstream: L3 builds token bags per claim and computes pairwise Jaccard similarity '
                . 'across all roles to produce the agreement score.';
            $human = implode("\n\n", $detail);

            $this->steps->completeStep($stepId, 'completed', $human, [
                'layer'        => 'L2',
                'round'        => $round,
                'role'         => $roleKey,
                'shape'        => $shape,
                'claims_count' => $claimCount,
                'claims'       => $claims,
                'source'       => [
                    'model'         => $sourceModel,
                    'provider'      => $row['response']->provider,
                    'content_chars' => $sourceLength,
                ],
                'note'         => 'L2 is an internal normalization step — no LLM call is made.',
            ]);
            $this->emitEvent($sessionId, $stepId, 'L2_NORMALIZED', [
                'role'         => $roleKey,
                'round'        => $round,
                'cycle'        => $round,
                'cycle_limit'  => $this->currentCycleLimit,
                'shape'        => $shape,
                'claims'       => $claimCount,
                'claims_count' => $claimCount,
                'source_model' => $sourceModel,
                // Full normalization summary so the UI Response tab can
                // render the same detailed text the persisted step row
                // carries — eliminates the historical "(awaiting full
                // response from session events)" placeholder.
                'summary'      => $human,
                'content'      => $human,
                'explanation'  => $human,
                'preview'      => mb_substr($human, 0, 240),
                'strategy'     => $shape === 'json'
                    ? 'extracted JSON object and projected onto skill output_schema'
                    : 'split sentences on .!?\\n boundaries',
                'source'       => [
                    'model'         => $sourceModel,
                    'provider'      => $row['response']->provider,
                    'role'          => $roleKey,
                    'content_chars' => $sourceLength,
                ],
                'claim_previews' => array_map(
                    static fn($c): string => mb_substr((string)$c, 0, 220),
                    array_slice($claims, 0, 5),
                ),
            ]);
            $out[] = ['role' => $row['role'], 'normalized' => $normalized];
        }
        return $out;
    }

    /**
     * @param array<int,array{role:array<string,mixed>,normalized:array<string,mixed>}> $normalized
     */
    private function layer3Consensus(int $sessionId, array $normalized, int $round = 1): ConsensusResult
    {
        $roundSegment = $round > 1 ? "round{$round}." : '';
        $stepKey = StepKeyHelper::normalize("L3.{$roundSegment}consensus");
        if ($this->detectLoop($stepKey, $sessionId)) {
            return new ConsensusResult(0.0, 'conflict', 'Consensus skipped: loop detected.');
        }
        $orderIndex = ($round - 1) * 1000 + 200;
        $stepLabel  = $round > 1 ? "L3 · Consensus (round {$round})" : 'L3 · Consensus';
        $stepId = $this->steps->createStep($sessionId, $stepKey, $stepLabel, $orderIndex, $this->stepMeta(['layer' => 'L3', 'round' => $round]));
        $items = array_map(static fn(array $r): array => $r['normalized'], $normalized);
        $roleKeys = array_values(array_map(
            static fn(array $r): string => (string)($r['role']['key'] ?? ''),
            $normalized
        ));
        // The selected consensus skill can override the scoring mode for
        // this run. Recognised values: 'semantic_components' (default)
        // and 'lexical_jaccard' (legacy). Anything else falls back to
        // the engine's default mode.
        $mode = $this->resolveConsensusMode();
        $result = $this->consensus->computeWithMode($items, $mode);
        // R9 extension - name the contribution per participant.
        //
        // The consensus engine reports `per_item` keyed by the position of the
        // normalized response; the role key of that position is `role@model`, so
        // the breakdown can be attributed to the model the operator recognises.
        $contribution = [];
        $shareSum = 0.0;
        foreach ((array)($result->meta['per_item'] ?? []) as $row) {
            $idx = (int)($row['idx'] ?? -1);
            $role = is_array($normalized[$idx]['role'] ?? null) ? $normalized[$idx]['role'] : [];
            $model = (string)($role['model'] ?? '');
            if ($model === '') {
                $key = (string)($role['key'] ?? '');
                $at = strpos($key, '@');
                $model = $at === false ? $key : substr($key, $at + 1);
            }
            if ($model === '') {
                continue;
            }
            $share = (float)($row['share'] ?? 0.0);
            $shareSum += $share;
            $contribution[] = [
                'model'    => $model,
                'provider' => (string)($role['provider'] ?? ''),
                'role'     => (string)($role['key'] ?? ''),
                'share'    => round($share, 4),
                'ratio'    => round((float)($row['ratio'] ?? 0.0), 4),
            ];
        }
        usort($contribution, static fn(array $a, array $b): int => $b['share'] <=> $a['share']);
        // Shares are a slice of the corroborated total, so they add up to 1.0
        // when anything was corroborated at all; the sum is kept for the report
        // so a run with nothing shared says so instead of showing a flat zero.
        $this->lastContribution = [
            'scoring' => (string)($result->meta['scoring'] ?? ''),
            'sum'     => round($shareSum, 4),
            'models'  => $contribution,
        ];
        $explanation = $this->consensusExplainer->explain($result, $roleKeys);
        $this->steps->completeStep(
            $stepId,
            'completed',
            $explanation['text'],
            [
                'layer'       => 'L3',
                'round'       => $round,
                'consensus'   => $result->toArray(),
                // R9 extension - the named breakdown, so usefulness can be folded
                // across runs without re-deriving the role order from L1.
                'contribution' => $this->lastContribution,
                'explanation' => $explanation,
                'note'        => 'L3 is an internal consensus step — no LLM call is made.',
            ],
        );
        $this->emitEvent($sessionId, $stepId, 'L3_CONSENSUS', array_merge(
            $result->toArray(),
            [
                'explanation' => $explanation,
                'contribution' => $this->lastContribution,
                'round'       => $round,
                'cycle'       => $round,
                'cycle_limit' => $this->currentCycleLimit,
            ],
        ));
        return $result;
    }

    /**
     * Call the model for the final answer, streaming it when the deployment
     * allows.
     *
     * The delta callback is the caller's: only the caller knows which step and
     * which strategy this call belongs to, and the event has to say so.
     *
     * Falling back is deliberate and narrow. A stream that threw BEFORE any
     * delta reached the operator is invisible, so the buffered call is made
     * instead and the run behaves exactly as it did before streaming existed.
     * A stream that already delivered text must not be retried: the operator
     * has seen the beginning, and a second answer would duplicate it on screen.
     * Either way the exception travels on, so the existing failure handling
     * (attempt bookkeeping, L4 events, the arbiter chain) is untouched.
     *
     * @param callable(string):void $onDelta
     * @throws \App\Connectors\ConnectorException
     */
    private function modelCallForFinalAnswer(
        \App\Connectors\ConnectorInterface $conn,
        string $model,
        array $messages,
        array $options,
        callable $onDelta,
    ): \App\Connectors\ChatResponse {
        if (!$this->streamFinalAnswerEnabled($conn)) {
            return $conn->chat($model, $messages, $options);
        }

        $delivered = 0;
        try {
            return $conn->chatStream($model, $messages, $options, static function (string $delta) use ($onDelta, &$delivered): void {
                $delivered++;
                $onDelta($delta);
            });
        } catch (\Throwable $e) {
            if ($delivered > 0) {
                throw $e;
            }
            Logger::warn('L4.stream_fell_back', [
                'provider' => $conn->getProviderName(),
                'model'    => $model,
                'class'    => (new \ReflectionClass($e))->getShortName(),
                'message'  => Logger::sanitizeScalar($e->getMessage()),
            ]);
            return $conn->chat($model, $messages, $options);
        }
    }

    /**
     * Is streaming the final answer switched on for this deployment?
     *
     * The CONNECTOR INSTANCE is asked, not a class name: a connector that does
     * not extend AbstractConnector simply does not have the method, and calling
     * it would be a fatal in the middle of a run - the one place where a wrong
     * assumption costs the whole synthesis.
     */
    private function streamFinalAnswerEnabled(\App\Connectors\ConnectorInterface $conn): bool
    {
        if (!(bool)Config::get('pipeline.stream_final_answer', true)) {
            return false;
        }
        return method_exists($conn, 'chatStream');
    }

    /**
     * @param array<int,array{role:array<string,mixed>,normalized:array<string,mixed>}> $normalized
     * @param array<int,array{role:array<string,mixed>,response:ChatResponse,step_id:int}> $l1Results
     */
    private function layer4Synthesis(int $sessionId, Skill $skill, string $query, array $normalized, ConsensusResult $consensus, array $l1Results = []): string
    {
        $stepKey = 'L4.synthesis';
        if ($this->detectLoop($stepKey, $sessionId)) {
            return 'Synthesis skipped: loop detected.';
        }
        $configuredProvider = (string)Config::get('pipeline.synthesis_provider', 'bothub');
        $configuredModel    = (string)Config::get('pipeline.synthesis_model', 'openai/gpt-4o');
        $selectedModels     = isset($this->runtimeOptions['selected_models']) && is_array($this->runtimeOptions['selected_models'])
            ? $this->runtimeOptions['selected_models']
            : null;

        // v1.8.23: when L3 reports anything other than a clean agreement
        // (conflict, partial below threshold, cycle limit reached,
        // stagnation, manual finalize) we synthesise the final answer
        // through the MAIN user-selected model — the first entry of the
        // resolved selected_models list — instead of falling back to a
        // dedicated arbiter or the configured BotHub/default. The L4
        // prompt includes the original query, the consensus status, the
        // reason the loop stopped, and every collected model answer; the
        // model is asked (RU/EN) to pick the most likely correct
        // solution and produce a comprehensive answer that incorporates
        // reasonable nuances from the others. Walks the selected list in
        // order on connector/network failures; final fallback is a
        // deterministic local synthesis that clearly notes L4 failed.
        if ($this->shouldUseMainSelectedSynthesis($consensus) && $l1Results !== []) {
            $synth = $this->runMainSelectedSynthesis(
                $sessionId,
                $query,
                $consensus,
                $l1Results,
                $this->resolvedSelectedModels,
            );
            if ($synth !== null) {
                return $synth;
            }
        }

        // Legacy conflict-resolution branch: only reached when no usable
        // selected model is available (e.g. config-default pool only). The
        // arbiter remains opt-in via pipeline.conflict_arbiter so existing
        // deployments that rely on it keep working.
        if ($this->shouldUseConflictArbiter($consensus) && $l1Results !== []) {
            $arbiter = $this->runConflictArbiter($sessionId, $query, $consensus, $l1Results);
            if ($arbiter !== null) {
                return $arbiter;
            }
        }

        $resolution = $this->resolveSynthesisTarget($configuredProvider, $configuredModel, $selectedModels);
        $provider   = $resolution['provider'];
        $model      = $resolution['model'];
        $conn       = $resolution['connector'];

        $stepId = $this->steps->createStep($sessionId, $stepKey, 'L4 · Synthesis', 300, $this->stepMeta([
            'layer'               => 'L4',
            'model'               => $model,
            'provider'            => $provider,
            'configured_provider' => $configuredProvider,
            'configured_model'    => $configuredModel,
            'fallback_reason'     => $resolution['reason'],
        ]));

        // Emit fallback event whenever the configured target was not used.
        if ($conn !== null
            && (strtolower($configuredProvider) !== strtolower($provider) || $configuredModel !== $model)
        ) {
            $fallbackPayload = [
                'configured_provider' => $configuredProvider,
                'configured_model'    => $configuredModel,
                'effective_provider'  => $provider,
                'effective_model'     => $model,
                'reason'              => $resolution['reason'],
            ];
            $this->emitEvent($sessionId, $stepId, 'L4_PROVIDER_FALLBACK', $fallbackPayload);
            $this->traceEvent('L4_PROVIDER_FALLBACK', $fallbackPayload);
            Logger::warn('L4.provider.fallback', $fallbackPayload);
        }

        if ($conn === null) {
            $reason = $resolution['reason'] ?? $this->describeMissingConnector($configuredProvider);
            $this->steps->completeStep($stepId, 'failed', $reason);
            $payload = [
                'reason'              => 'no_connector',
                'provider'            => $configuredProvider,
                'configured_provider' => $configuredProvider,
                'configured_model'    => $configuredModel,
                'detail'              => $reason,
            ];
            if (Config::isDebug()) {
                $payload['diagnostics'] = ErrorDiagnostics::describeConnectorError(
                    new ConnectorException($reason),
                    ['provider' => $configuredProvider, 'model' => $configuredModel, 'endpoint' => $this->endpointFor($configuredProvider)],
                );
            }
            $this->emitEvent($sessionId, $stepId, 'L4_FAILED', $payload);
            $this->traceEvent('L4_FAILED', ['provider' => $configuredProvider, 'reason' => $reason]);
            $this->recordSynthesisFailure(
                reason:   'no_connector',
                provider: $configuredProvider,
                model:    $configuredModel,
                message:  $reason,
                hint:     $this->diagnoseConnectorError($reason, $configuredProvider, $configuredModel),
            );
            return $this->fallbackSynthesis($normalized, $reason);
        }
        $this->traceEvent('L4_SELECTED', [
            'provider'            => $provider,
            'model'               => $model,
            'configured_provider' => $configuredProvider,
            'configured_model'    => $configuredModel,
            'fallback_reason'     => $resolution['reason'],
        ]);
        $messages = $this->buildSynthesisMessages($skill, $query, $normalized, $consensus);
        try {
            $options = ['temperature' => 0.3];
            $reasoning = $this->reasoningPayload($this->runtimeOptions['reasoning'] ?? null);
            if ($reasoning !== null) {
                $options['reasoning'] = $reasoning;
            }
            $cacheMeta = ['cache_hit' => false];
            $resp = null;
            $hit  = $this->cacheForRun()?->lookup($provider, $model, $messages, $options);
            if ($hit !== null) {
                $resp = $hit['response'];
                $cacheMeta = [
                    'cache_hit'        => true,
                    'cache_key'        => (string)$hit['key'],
                    'cache_short_key'  => (string)$hit['short_key'],
                    'cache_created_at' => (string)($hit['created_at'] ?? ''),
                    'cache_hits'       => (int)($hit['hits'] ?? 0),
                ];
                $this->cache?->recordHit((string)$hit['key']);
            } else {
                // v1.9.x - the configured synthesis target is the normal path, so it
                // streams too: leaving it buffered would mean the feature only ever
                // appeared on the fallback path.
                $strategy = 'configured_target';
                $onDelta = function (string $delta) use ($sessionId, $stepId, $strategy): void {
                    $this->emitEvent($sessionId, $stepId, 'L4_SYNTHESIS_DELTA', [
                        'text'     => $delta,
                        'strategy' => $strategy,
                    ]);
                };
                $resp = $this->modelCallForFinalAnswer($conn, $model, $messages, $options, $onDelta);
                $cache = $this->cacheForRun();
                if ($cache !== null) {
                    try {
                        $info = $cache->store($provider, $model, $messages, $options, $resp);
                        $cacheMeta['cache_key']       = $info['key']       ?? null;
                        $cacheMeta['cache_short_key'] = $info['short_key'] ?? null;
                        $cacheMeta['cache_stored']    = (bool)($info['stored'] ?? false);
                        if (isset($info['refused'])) {
                            // Surfaced on the step so a provider returning
                            // an error envelope is visible instead of
                            // silently poisoning the cache.
                            $cacheMeta['cache_refused'] = (string)$info['refused'];
                        }
                    } catch (\Throwable $e) {
                        Logger::warn('cache store L4 failed', ['error' => $e->getMessage()]);
                    }
                }
            }
            $this->responses->record($stepId, $resp, 'synthesis');
            $this->stats->record($stepKey, $resp, 'L4');
            $this->steps->completeStep(
                $stepId,
                'completed',
                $resp->content,
                array_merge(
                    ['layer' => 'L4', 'summary_preview' => mb_substr($resp->content, 0, 240)],
                    $resp->statsPayload(),
                    $this->extractReasoning($resp),
                    $cacheMeta,
                ),
            );
            $this->emitEvent($sessionId, $stepId, 'L4_SYNTHESIS', array_merge(
                $resp->statsPayload(),
                [
                    'tokens'  => $resp->totalTokens,
                    // Full synthesis output. UI renders this verbatim in
                    // the Final Answer card; the preview field stays for
                    // compact contexts (history list).
                    'content' => $resp->content,
                    'preview' => mb_substr($resp->content, 0, 240),
                ],
                $cacheMeta,
            ));
            Logger::info('L4.synthesis', [
                'session_id'    => $sessionId,
                'provider'      => $provider,
                'model'         => $model,
                'cache_hit'     => (bool)($cacheMeta['cache_hit'] ?? false),
                'content_chars' => mb_strlen($resp->content),
                'tokens'        => $resp->totalTokens,
            ]);
            $this->lastSynthesisStats = array_merge($resp->statsPayload(), $cacheMeta);
            return $resp->content;
        } catch (ConnectorException $e) {
            // The step summary is what an operator reads in History, so it
            // gets the hint too — not just the raw provider body. Without
            // it a "MODEL_NOT_FOUND" was reported as a bare HTTP 404 with
            // no statement of which model or what to do about it.
            $providerErrorCode = ErrorDiagnostics::providerErrorCodeFromMessage($e->getMessage());
            $hint = $this->diagnoseConnectorError($e->getMessage(), $provider, $model);
            $message = $e->getMessage();
            if (!in_array($hint, [null, ''], true) && !str_contains($message, (string)$hint)) {
                $message .= ' — ' . $hint;
            }
            $this->steps->completeStep($stepId, 'failed', $message);
            $payload = [
                'reason'              => 'connector_error',
                'message'             => $message,
                'provider'            => $provider,
                'model'               => $model,
                'provider_error_code' => $providerErrorCode,
                'hint'                => $hint,
            ];
            if (Config::isDebug()) {
                $payload['diagnostics'] = ErrorDiagnostics::describeConnectorError($e, [
                    'provider' => $provider,
                    'model'    => $model,
                    'endpoint' => $this->endpointFor($provider),
                    'provider_error_code' => $providerErrorCode,
                    'provider_error_message' => ErrorDiagnostics::providerErrorMessageFromBody($e->getMessage()),
                    'request_options' => ErrorDiagnostics::filterRequestOptions(array_merge($options, [
                        'model'   => $model,
                        'timeout' => \App\Connectors\CallPolicy::timeoutFor((string)$provider),
                    ])),
                ]);
            }
            Logger::warn('L4.synthesis.failed', [
                'session_id'          => $sessionId,
                'provider'            => $provider,
                'model'               => $model,
                'provider_error_code' => $providerErrorCode,
                'message'             => $message,
            ]);
            $this->emitEvent($sessionId, $stepId, 'L4_FAILED', $payload);
            $this->recordSynthesisFailure(
                reason:   'connector_error',
                provider: $provider,
                model:    $model,
                message:  $e->getMessage(),
                hint:     $hint,
                code:     $providerErrorCode,
            );
            // The reason travels into the fallback body the operator reads,
            // so it is sanitized (a provider echoing an Authorization
            // header must not end up in the Final answer card) and capped.
            return $this->fallbackSynthesis($normalized, mb_substr(Logger::sanitizeScalar($message), 0, 300));
        }
    }

    /**
     * Build a structured L0 skill payload — slug, name, description,
     * resolved roles, synthesis prompt, schema, tags. The UI uses this to
     * render the skill card; full raw definition is also embedded so the
     * v1.8.34 — list provider slugs whose connectors are registered.
     * Used to enrich L1_NO_AVAILABLE_MODELS so operators can immediately
     * see whether a missing connector key explains the empty pool.
     *
     * @return array<int,string>
     */
    private function availableProviderList(): array
    {
        $out = [];
        foreach ($this->connectors->all() as $c) {
            $name = strtolower($c->getProviderName());
            if ($name !== '' && !in_array($name, $out, true)) {
                $out[] = $name;
            }
        }
        sort($out);
        return $out;
    }

    /**
     * Raw tab can show the original JSON.
     *
     * @return array<string,mixed>
     */
    private function summarizeSkill(Skill $skill): array
    {
        $roles = [];
        foreach ($skill->roles() as $r) {
            $key = (string)($r['key'] ?? '');
            $roles[] = [
                'key'    => $key,
                'label'  => (string)($r['label'] ?? $key),
                'system' => mb_substr((string)($r['system'] ?? ''), 0, 600),
            ];
        }
        $tags = $skill->definition['tags'] ?? [];
        if (!is_array($tags)) { $tags = []; }
        $hasSchema = is_array($skill->definition['output_schema'] ?? null);
        return [
            'skill_id'         => $skill->id,
            'slug'             => $skill->slug,
            'name'             => $skill->name,
            'description'      => $skill->description,
            'auto'             => $skill->id === null,
            'is_system'        => $skill->isSystem,
            'tags'             => array_values(array_map('strval', $tags)),
            'roles'            => $roles,
            'roles_count'      => count($roles),
            'synthesis_prompt' => mb_substr($skill->synthesisPrompt(), 0, 1200),
            'has_output_schema'=> $hasSchema,
            'definition'       => $skill->definition,
        ];
    }

    /**
     * Translate a reasoning preset name (or array) into the OpenRouter
     * `reasoning` request object. Returns null when reasoning is off or
     * unrecognized.
     *
     * @param  mixed $option
     * @return array<string,mixed>|null
     */
    private function reasoningPayload(mixed $option): ?array
    {
        if ($option === null) {
            return null;
        }
        if (is_string($option)) {
            $key = strtolower(trim($option));
            if ($key === '' || $key === 'off' || $key === 'none' || $key === '0') {
                return null;
            }
            $levels = Config::get('reasoning.levels', []);
            if (is_array($levels) && isset($levels[$key]) && is_array($levels[$key])) {
                $payload = $levels[$key];
            } else {
                return null;
            }
        } elseif (is_array($option)) {
            $payload = $option;
        } else {
            return null;
        }
        $exclude = Config::get('reasoning.exclude_default', false);
        if (!array_key_exists('exclude', $payload)) {
            $payload['exclude'] = (bool)$exclude;
        }
        return $payload;
    }

    /**
     * Resolve the effective consensus cycle limit for the current run.
     *
     * Priority:
     *   1. Per-request `cycle_limit` option (numeric, 1..max).
     *   2. `pipeline.max_consensus_rounds` from config (default 2).
     *
     * The effective value is hard-clamped to
     * `[pipeline.cycle_limit_min, pipeline.cycle_limit_max]` so even a
     * config tampered to a huge number cannot trigger runaway token
     * burn. Invalid scalars (non-numeric, < 1) fall back to the config
     * default. The returned `requested` field preserves what was asked
     * for so the UI can render a "clamped" notice when applicable.
     *
     * @return array{requested:mixed,effective:int,min:int,max:int,clamped:bool,reason:string}
     */
    private function resolveCycleLimit(mixed $option): array
    {
        $configDefault = (int)Config::get('pipeline.max_consensus_rounds', 2);
        $minLimit = max(1, (int)Config::get('pipeline.cycle_limit_min', 1));
        $maxLimit = max($minLimit, (int)Config::get('pipeline.cycle_limit_max', 5));

        $requestedRaw = $option;
        $requestedValid = false;
        $value = null;
        if (is_int($option) || (is_string($option) && $option !== '' && is_numeric($option))
            || is_float($option)
        ) {
            $value = (int)$option;
            $requestedValid = $value >= 1;
        } elseif (is_array($option) && isset($option['cycle_limit'])
            && (is_int($option['cycle_limit']) || (is_string($option['cycle_limit']) && is_numeric($option['cycle_limit'])))
        ) {
            $value = (int)$option['cycle_limit'];
            $requestedRaw = $value;
            $requestedValid = $value >= 1;
        }

        if (!$requestedValid) {
            $value = $configDefault;
        }
        $effective = max($minLimit, min($maxLimit, (int)$value));
        $configClamped = max($minLimit, min($maxLimit, $configDefault));
        // Always clamp the config default itself, in case an operator set
        // `max_consensus_rounds` outside the hard range. The engine never
        // exceeds `cycle_limit_max` regardless.
        if (!$requestedValid) {
            $effective = $configClamped;
        }

        $clamped = false;
        $reason  = 'within_range';
        if ($requestedValid && $value !== $effective) {
            $clamped = true;
            $reason  = $value > $maxLimit ? 'clamped_to_max' : 'clamped_to_min';
        } elseif (!$requestedValid && $option !== null) {
            $clamped = true;
            $reason  = 'invalid_request_using_default';
        } elseif ($option === null && $configDefault !== $configClamped) {
            // Config-level out-of-range is also a clamp event — surfaces
            // a deployment mistake to the operator without halting the run.
            $clamped = true;
            $reason  = 'config_default_clamped';
        }

        return [
            'requested' => $requestedRaw,
            'effective' => $effective,
            'min'       => $minLimit,
            'max'       => $maxLimit,
            'clamped'   => $clamped,
            'reason'    => $reason,
        ];
    }

    /** @return array<string,mixed> Description of the chosen reasoning option for the L0_OPTIONS event. */
    private function describeReasoning(mixed $option): array
    {
        $payload = $this->reasoningPayload($option);
        return [
            'requested' => is_scalar($option) ? (string)$option : (is_array($option) ? 'custom' : null),
            'effective' => $payload,
        ];
    }

    /** @return array<string,mixed> Reasoning fields extracted from a ChatResponse, when present. */
    private function extractReasoning(ChatResponse $resp): array
    {
        $out = [];
        $msg = $resp->rawResponse['choices'][0]['message'] ?? null;
        if (is_array($msg)) {
            if (isset($msg['reasoning']) && is_string($msg['reasoning']) && $msg['reasoning'] !== '') {
                $out['reasoning_text'] = $msg['reasoning'];
            } elseif (isset($msg['reasoning_details']) && is_array($msg['reasoning_details'])) {
                $out['reasoning_details'] = $msg['reasoning_details'];
            }
        }
        return $out;
    }

    /**
     * @param array<int,array{role:array<string,mixed>,normalized:array<string,mixed>}> $normalized
     * @return array<int,array{role:string,content:string}>
     */
    private function buildSynthesisMessages(Skill $skill, string $query, array $normalized, ConsensusResult $consensus): array
    {
        $system = trim($skill->synthesisPrompt());
        if ($system === '') {
            $system = 'You are the chief synthesizer. Combine role outputs into a balanced, actionable final answer.';
        }
        $system = $this->composeSystemPrompt($system);
        $bodyParts = [];
        foreach ($normalized as $r) {
            $key = (string)($r['role']['key'] ?? 'role');
            $bodyParts[] = "### {$key}\n" . $this->renderForSynthesis($r['normalized']);
        }
        $user = "User query:\n{$query}\n\nConsensus status: {$consensus->status} (score "
            . number_format($consensus->score, 3) . ").\n\nRole outputs:\n" . implode("\n\n", $bodyParts);
        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user',   'content' => $this->prependAttachments($user)],
        ];
    }

    /** @param array<string,mixed> $normalized */
    private function renderForSynthesis(array $normalized): string
    {
        if (($normalized['shape'] ?? '') === 'json' && is_array($normalized['data'] ?? null)) {
            return json_encode($normalized['data'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '';
        }
        return (string)($normalized['data'] ?? '');
    }

    /**
     * v1.8.23: true when the L3 verdict is not a clean agreement AND the
     * run has at least one usable user-selected model to drive synthesis.
     * Triggers the main-selected-model synthesizer in place of the
     * configured BotHub/default — the user's first selected model is
     * treated as the authoritative voice for combining model answers.
     */
    private function shouldUseMainSelectedSynthesis(ConsensusResult $consensus): bool
    {
        $status = strtolower((string)$consensus->status);
        if ($status === '' || $status === 'agreement' || $status === 'agree' || $status === 'consensus') {
            return false;
        }
        // Operators can disable the new branch via config if they want to
        // preserve the legacy arbiter behaviour for every run.
        $cfg = Config::get('pipeline.main_selected_synthesis', []);
        if (is_array($cfg) && array_key_exists('enabled', $cfg) && $cfg['enabled'] === false) {
            return false;
        }
        return $this->resolvedSelectedModels !== [];
    }

    /**
     * v1.8.23: synthesise the final answer using the FIRST user-selected
     * model. Walks the resolved selected list in user-supplied order;
     * the first available connector that successfully returns content
     * wins. Each attempt is persisted as its own L4 step row so history
     * + stats account for token usage. Emits L4_MAIN_MODEL_SYNTHESIS_STARTED
     * before the first call and L4_SYNTHESIS (with synthesis_strategy =
     * main_selected_model, selected_index, reason, provider, model) on
     * success. Returns null when every selected model fails — the caller
     * falls through to the legacy arbiter/configured-synthesis chain
     * which ultimately reaches fallbackSynthesis().
     *
     * @param array<int,array{provider:string,model:string}> $selectedModels
     * @param array<int,array{role:array<string,mixed>,response:ChatResponse,step_id:int}> $l1Results
     */
    private function runMainSelectedSynthesis(
        int $sessionId,
        string $query,
        ConsensusResult $consensus,
        array $l1Results,
        array $selectedModels,
    ): ?string {
        if ($selectedModels === []) {
            return null;
        }
        $reason = $this->describeNoConsensusReason($consensus);

        $candidates = [];
        foreach ($selectedModels as $idx => $entry) {
            $provider = strtolower(trim((string)($entry['provider'] ?? '')));
            $model    = trim((string)($entry['model'] ?? ''));
            if ($provider === '' || $model === '') {
                continue;
            }
            $conn = $this->connectors->getByProvider($provider);
            if ($conn === null) {
                continue;
            }
            $candidates[] = ['provider' => $provider, 'model' => $model, 'connector' => $conn, 'index' => $idx];
        }
        if ($candidates === []) {
            $this->traceEvent('L4_MAIN_SELECTED_UNAVAILABLE', [
                'detail' => 'No selected model has an available connector.',
                'selected_count' => count($selectedModels),
            ]);
            return null;
        }

        $this->emitEvent($sessionId, null, 'L4_MAIN_MODEL_SYNTHESIS_STARTED', [
            'synthesis_strategy' => 'main_selected_model',
            'selected_index'     => $candidates[0]['index'],
            'provider'           => $candidates[0]['provider'],
            'model'              => $candidates[0]['model'],
            'consensus_status'   => $consensus->status,
            'consensus_score'    => $consensus->score,
            'reason'             => $reason,
            'candidate_count'    => count($candidates),
        ]);

        $messages = $this->buildMainSelectedSynthesisMessages($query, $consensus, $l1Results, $reason);
        $options  = ['temperature' => 0.3];
        $reasoning = $this->reasoningPayload($this->runtimeOptions['reasoning'] ?? null);
        if ($reasoning !== null) {
            $options['reasoning'] = $reasoning;
        }

        $attemptErrors = [];
        $attemptIndex = 0;
        foreach ($candidates as $cand) {
            $attemptIndex++;
            $provider = $cand['provider'];
            $model    = $cand['model'];
            $conn     = $cand['connector'];
            $selIdx   = $cand['index'];

            $stepKey = StepKeyHelper::normalize($attemptIndex === 1
                ? 'L4.main_selected'
                : 'L4.main_selected.fallback' . $attemptIndex);
            $stepId  = $this->steps->createStep($sessionId, $stepKey, 'L4 · Main-model synthesis', 295 + $attemptIndex, $this->stepMeta([
                'layer'              => 'L4',
                'synthesis_strategy' => 'main_selected_model',
                'selected_index'     => $selIdx,
                'provider'           => $provider,
                'model'              => $model,
                'reason'             => $reason,
                'consensus'          => $consensus->toArray(),
                'attempt'            => $attemptIndex,
            ]));

            // v1.9.x - the operator watches the answer being written instead
            // of waiting for the whole synthesis. The strategy label travels with
            // every delta so the interface can attribute it.
            $strategy = 'main_selected_model';
            $onDelta = function (string $delta) use ($sessionId, $stepId, $strategy): void {
                $this->emitEvent($sessionId, $stepId, 'L4_SYNTHESIS_DELTA', [
                    'text'     => $delta,
                    'strategy' => $strategy,
                ]);
            };
            try {
                $resp = $this->modelCallForFinalAnswer($conn, $model, $messages, $options, $onDelta);
            } catch (ConnectorException $e) {
                $msg = $e->getMessage();
                $this->steps->completeStep($stepId, 'failed', $msg);
                $this->emitEvent($sessionId, $stepId, 'L4_MAIN_MODEL_ATTEMPT_FAILED', [
                    'synthesis_strategy' => 'main_selected_model',
                    'selected_index'     => $selIdx,
                    'provider'           => $provider,
                    'model'              => $model,
                    'attempt'            => $attemptIndex,
                    'message'            => $msg,
                ]);
                $attemptErrors[] = [
                    'provider' => $provider,
                    'model'    => $model,
                    'message'  => $msg,
                ];
                // v1.8.48 — keep the per-attempt failures where the final
                // failure record can find them. If a later candidate
                // succeeds this list is simply never published; if the whole
                // walk fails, the operator sees every model that was tried
                // rather than only the last error.
                $this->lastSynthesisAttempts = $attemptErrors;
                Logger::warn('L4.main_selected.attempt.failed', [
                    'session_id' => $sessionId,
                    'provider'   => $provider,
                    'model'      => $model,
                    'attempt'    => $attemptIndex,
                ]);
                continue;
            }

            $this->responses->record($stepId, $resp, 'synthesis');
            $this->stats->record($stepKey, $resp, 'L4');

            $disclaimer = $this->buildConflictDisclaimer($consensus);
            $finalContent = $disclaimer . "\n\n" . trim($resp->content);

            $cacheMeta = ['cache_hit' => false];
            $this->steps->completeStep(
                $stepId,
                'completed',
                $finalContent,
                array_merge(
                    [
                        'layer'              => 'L4',
                        'synthesis_strategy' => 'main_selected_model',
                        'selected_index'     => $selIdx,
                        'summary_preview'    => mb_substr($finalContent, 0, 240),
                        'reason'             => $reason,
                    ],
                    $resp->statsPayload(),
                    $this->extractReasoning($resp),
                    $cacheMeta,
                ),
            );
            $this->emitEvent($sessionId, $stepId, 'L4_SYNTHESIS', array_merge(
                $resp->statsPayload(),
                [
                    'tokens'             => $resp->totalTokens,
                    'content'            => $finalContent,
                    'preview'            => mb_substr($finalContent, 0, 240),
                    'synthesis_strategy' => 'main_selected_model',
                    'selected_index'     => $selIdx,
                    'provider'           => $provider,
                    'model'              => $model,
                    'reason'             => $reason,
                    'consensus_status'   => $consensus->status,
                    'consensus_score'    => $consensus->score,
                    'note'               => 'Models did not reach consensus — synthesised by the first selected model.',
                ],
                $cacheMeta,
            ));
            $this->lastSynthesisStats = array_merge($resp->statsPayload(), [
                'synthesis_strategy' => 'main_selected_model',
                'selected_index'     => $selIdx,
                'provider'           => $provider,
                'model'              => $model,
                'reason'             => $reason,
                'consensus_status'   => $consensus->status,
                'consensus_score'    => $consensus->score,
            ]);
            $this->traceEvent('L4_MAIN_MODEL_SYNTHESIS_SUCCESS', [
                'provider' => $provider,
                'model'    => $model,
                'attempt'  => $attemptIndex,
                'reason'   => $reason,
            ]);
            Logger::info('L4.main_selected.success', [
                'session_id'    => $sessionId,
                'provider'      => $provider,
                'model'         => $model,
                'attempt'       => $attemptIndex,
                'reason'        => $reason,
                'content_chars' => mb_strlen($finalContent),
                'tokens'        => $resp->totalTokens,
            ]);
            return $finalContent;
        }

        // Every selected model failed. Emit a single summary event so the
        // UI can render an explicit "L4 failed via main-selected" notice
        // before the caller falls through to legacy arbiter / configured
        // synthesis / fallbackSynthesis().
        $this->emitEvent($sessionId, null, 'L4_MAIN_MODEL_SYNTHESIS_FAILED', [
            'synthesis_strategy' => 'main_selected_model',
            'attempts'           => $attemptErrors,
            'reason'             => $reason,
            'detail'             => 'Every selected model failed during no-consensus synthesis. '
                . 'Falling back to legacy arbiter / configured synthesis chain.',
        ]);
        $this->traceEvent('L4_MAIN_MODEL_SYNTHESIS_FAILED', [
            'attempts' => $attemptErrors,
            'reason'   => $reason,
        ]);
        return null;
    }

    /**
     * v1.8.23: build the no-consensus synthesis prompt. Includes the
     * original user query, the consensus status/score, every collected
     * L1 model answer (labelled by role/provider/model), and a bilingual
     * instruction asking the model to pick the most likely correct
     * solution and produce a comprehensive answer that folds in
     * reasonable nuances from the others. The model is told to write in
     * the same language as the user; the disclaimer about absence of
     * consensus is added by the system around the answer.
     *
     * @param array<int,array{role:array<string,mixed>,response:ChatResponse,step_id:int}> $l1Results
     * @return array<int,array{role:string,content:string}>
     */
    private function buildMainSelectedSynthesisMessages(
        string $query,
        ConsensusResult $consensus,
        array $l1Results,
        string $reason,
    ): array {
        $system = "You are the lead synthesizer for a multi-model council.\n"
                . "Вы — ведущий синтезатор совета языковых моделей.\n\n"
                . "The council did NOT reach consensus. Read every model answer below "
                . "carefully and choose the most likely correct solution. Produce one "
                . "comprehensive final answer based on that solution, incorporating "
                . "reasonable nuances from the other answers where they add real value, "
                . "and explicitly note that the models did not fully agree.\n"
                . "Совет не достиг консенсуса. Внимательно прочтите каждый ответ ниже "
                . "и выберите наиболее вероятно правильное решение. Сформулируйте один "
                . "развёрнутый итоговый ответ на основе этого решения, аккуратно учтите "
                . "разумные нюансы из других ответов там, где они действительно полезны, "
                . "и явно укажите, что модели не пришли к полному согласию.\n\n"
                . "Reply in the same language the user used. Do not repeat the system "
                . "disclaimer about absence of consensus — it is wrapped around your "
                . "answer automatically. Do not invent facts not present in the answers.\n"
                . "Отвечайте на том же языке, что и пользователь. Не повторяйте системное "
                . "предупреждение об отсутствии консенсуса — оно будет добавлено вокруг "
                . "вашего ответа автоматически. Не выдумывайте факты, отсутствующие в "
                . "представленных ответах.";

        $sections = [];
        $i = 0;
        foreach ($l1Results as $row) {
            $i++;
            $resp     = $row['response'] ?? null;
            if (!$resp instanceof ChatResponse) {
                continue;
            }
            $roleKey  = (string)($row['role']['key'] ?? ('model_' . $i));
            $provider = (string)$resp->provider;
            $model    = (string)$resp->effectiveModel();
            $sections[] = "### Answer {$i} — role \"{$roleKey}\" (provider: {$provider}, model: {$model})\n"
                        . trim($resp->content);
        }
        $score = number_format((float)$consensus->score, 3);
        $userBody = "User query / Запрос пользователя:\n{$query}\n\n"
                  . "Consensus status / Статус консенсуса: {$consensus->status} "
                  . "(score / оценка: {$score}).\n"
                  . "Loop stop reason / Причина остановки цикла: {$reason}.\n\n"
                  . "Model answers / Ответы моделей:\n\n"
                  . implode("\n\n", $sections);

        $system = $this->composeSystemPrompt($system);

        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user',   'content' => $this->prependAttachments($userBody)],
        ];
    }

    /**
     * v1.8.23: map a ConsensusResult into the loop_stop_reason taxonomy
     * the L4 main-model synthesizer cites in its prompt and event
     * payloads. Falls back to "no_consensus" when no more specific
     * cue is available on the result.
     */
    private function describeNoConsensusReason(ConsensusResult $consensus): string
    {
        $status = strtolower((string)$consensus->status);
        if ($status === 'partial' || $status === 'partial_agreement') {
            return 'partial_below_threshold';
        }
        if ($status === 'conflict' || $status === 'disagreement') {
            return 'no_consensus';
        }
        if ($status === 'rejected') {
            return 'rejected';
        }
        return 'no_consensus';
    }

    /**
     * True when the L3 verdict is anything other than a clean agreement.
     * The conflict arbiter is the user-requested behaviour: when the
     * council does not reach consensus, route the raw L1 answers to a
     * dedicated reconciliation model (OpenRouter qwen/qwen-2.5-72b-instruct
     * by default) and produce a "best-of" combined answer instead of
     * letting the configured synthesis model pick a single role.
     */
    private function shouldUseConflictArbiter(ConsensusResult $consensus): bool
    {
        $status = strtolower((string)$consensus->status);
        if ($status === '' || $status === 'agreement' || $status === 'agree' || $status === 'consensus') {
            return false;
        }
        // Optional threshold: enable / tune via pipeline.conflict_arbiter
        // in config.php. Default ON, default threshold 0.7 — anything
        // below this score with status != agreement triggers the
        // arbiter even if a downstream taxonomy labels it "partial".
        $cfg = Config::get('pipeline.conflict_arbiter', []);
        if (is_array($cfg) && array_key_exists('enabled', $cfg) && $cfg['enabled'] === false) {
            return false;
        }
        $threshold = is_array($cfg) && isset($cfg['threshold']) ? (float)$cfg['threshold'] : 0.7;
        return ((float)$consensus->score) < $threshold;
    }

    /**
     * Drive the conflict-resolution arbiter. Returns the synthesized
     * final answer on success and null when the arbiter is unavailable
     * (caller falls back to the configured L4 synthesis chain). All
     * persistence still flows through the normal L4 step row so history
     * + statistics correctly account for the arbiter call.
     *
     * @param array<int,array{role:array<string,mixed>,response:ChatResponse,step_id:int}> $l1Results
     */
    private function runConflictArbiter(int $sessionId, string $query, ConsensusResult $consensus, array $l1Results): ?string
    {
        $arbiterCfg     = Config::get('pipeline.conflict_arbiter', []);
        $arbiterModel   = is_array($arbiterCfg) && isset($arbiterCfg['model'])
            ? (string)$arbiterCfg['model']
            : 'qwen/qwen-2.5-72b-instruct';
        $arbiterProvider = is_array($arbiterCfg) && isset($arbiterCfg['provider'])
            ? strtolower((string)$arbiterCfg['provider'])
            : 'openrouter';

        $conn = $this->connectors->getByProvider($arbiterProvider);
        if ($conn === null) {
            $this->traceEvent('L4_ARBITER_UNAVAILABLE', [
                'requested_provider' => $arbiterProvider,
                'requested_model'    => $arbiterModel,
                'reason'             => $this->describeMissingConnector($arbiterProvider),
            ]);
            return null;
        }

        $stepKey = 'L4.conflict_arbiter';
        $stepId  = $this->steps->createStep($sessionId, $stepKey, 'L4 · Conflict synthesis', 290, $this->stepMeta([
            'layer'    => 'L4',
            'provider' => $arbiterProvider,
            'model'    => $arbiterModel,
            'reason'   => 'no_consensus',
            'consensus' => $consensus->toArray(),
        ]));
        $this->emitEvent($sessionId, $stepId, 'L4_ARBITER_STARTED', [
            'provider'         => $arbiterProvider,
            'model'            => $arbiterModel,
            'consensus_status' => $consensus->status,
            'consensus_score'  => $consensus->score,
        ]);

        $messages = $this->buildArbiterMessages($query, $consensus, $l1Results);
        $options  = ['temperature' => 0.4];
        try {
            $resp = $conn->chat($arbiterModel, $messages, $options);
        } catch (ConnectorException $e) {
            $this->steps->completeStep($stepId, 'failed', $e->getMessage());
            $this->emitEvent($sessionId, $stepId, 'L4_ARBITER_FAILED', [
                'provider' => $arbiterProvider,
                'model'    => $arbiterModel,
                'message'  => $e->getMessage(),
            ]);
            $this->traceEvent('L4_ARBITER_FAILED', [
                'provider' => $arbiterProvider,
                'model'    => $arbiterModel,
                'message'  => $e->getMessage(),
            ]);
            return null;
        }

        $this->responses->record($stepId, $resp, 'arbiter');
        $this->stats->record($stepKey, $resp, 'L4');

        // Surface the no-consensus disclaimer above the arbiter output so
        // the Final Answer card always shows the user that this was a
        // best-of combined answer, not a unanimous one. Russian + neutral
        // English — both communities use the platform.
        $disclaimer = $this->buildConflictDisclaimer($consensus);
        $finalContent = $disclaimer . "\n\n" . trim($resp->content);

        $cacheMeta = ['cache_hit' => false];
        $this->steps->completeStep(
            $stepId,
            'completed',
            $finalContent,
            array_merge(
                ['layer' => 'L4', 'arbiter' => true, 'summary_preview' => mb_substr($finalContent, 0, 240)],
                $resp->statsPayload(),
                $this->extractReasoning($resp),
                $cacheMeta,
            ),
        );
        $this->emitEvent($sessionId, $stepId, 'L4_SYNTHESIS', array_merge(
            $resp->statsPayload(),
            [
                'tokens'      => $resp->totalTokens,
                'content'     => $finalContent,
                'preview'     => mb_substr($finalContent, 0, 240),
                'arbiter'     => true,
                'arbiter_provider' => $arbiterProvider,
                'arbiter_model'    => $arbiterModel,
                'consensus_status' => $consensus->status,
                'consensus_score'  => $consensus->score,
                'note' => 'Conflict synthesis via arbiter — models did not reach consensus.',
            ],
            $cacheMeta,
        ));
        $this->lastSynthesisStats = array_merge($resp->statsPayload(), [
            'arbiter'          => true,
            'arbiter_provider' => $arbiterProvider,
            'arbiter_model'    => $arbiterModel,
            'consensus_status' => $consensus->status,
            'consensus_score'  => $consensus->score,
        ]);
        Logger::info('L4.arbiter.success', [
            'session_id'    => $sessionId,
            'provider'      => $arbiterProvider,
            'model'         => $arbiterModel,
            'content_chars' => mb_strlen($finalContent),
            'tokens'        => $resp->totalTokens,
        ]);
        return $finalContent;
    }

    /**
     * Build the arbiter prompt. Russian + neutral phrasing per spec: it
     * is explicit about the no-consensus situation and asks the model to
     * combine the best parts of every L1 answer into the most accurate
     * final reply.
     *
     * @param array<int,array{role:array<string,mixed>,response:ChatResponse,step_id:int}> $l1Results
     * @return array<int,array{role:string,content:string}>
     */
    private function buildArbiterMessages(string $query, ConsensusResult $consensus, array $l1Results): array
    {
        $system = "You are an arbiter that reconciles disagreeing model answers.\n"
                . "Вы — арбитр, который примиряет расходящиеся ответы моделей.\n\n"
                . "The council of LLMs did NOT reach consensus on the user's query.\n"
                . "Совет языковых моделей НЕ достиг консенсуса по запросу пользователя.\n\n"
                . "Read every model answer below carefully. Combine the best, most accurate parts of each "
                . "into a single, well-reasoned final answer that maximizes correctness and usefulness. "
                . "Resolve direct contradictions by citing the most defensible position. Do not omit any "
                . "important fact present in only one answer if it is plausibly correct.\n"
                . "Внимательно прочтите каждый ответ ниже. Объедините лучшие, наиболее точные части каждого "
                . "ответа в единый, хорошо обоснованный итоговый ответ, максимизирующий правильность и "
                . "полезность. При прямых противоречиях указывайте наиболее обоснованную позицию. "
                . "Не упускайте важные факты, присутствующие лишь в одном ответе, если они правдоподобны.\n\n"
                . "Reply in the same language the user used. Do not include the disclaimer about absence "
                . "of consensus — it is added by the system around your answer.\n"
                . "Отвечайте на том же языке, что и пользователь. Не добавляйте предупреждение об отсутствии "
                . "консенсуса — оно будет добавлено системой автоматически.";

        $sections = [];
        $i = 0;
        foreach ($l1Results as $row) {
            $i++;
            $resp     = $row['response'];
            $roleKey  = (string)($row['role']['key'] ?? ('model_' . $i));
            $provider = (string)$resp->provider;
            $model    = (string)$resp->effectiveModel();
            $sections[] = "### Answer {$i} — role \"{$roleKey}\" (provider: {$provider}, model: {$model})\n"
                        . trim($resp->content);
        }
        $userBody = "User query / Запрос пользователя:\n{$query}\n\n"
                  . "Consensus status / Статус консенсуса: {$consensus->status} "
                  . "(score / оценка: " . number_format((float)$consensus->score, 3) . ").\n\n"
                  . "Raw L1 answers / Исходные ответы моделей L1:\n\n"
                  . implode("\n\n", $sections);

        $system = $this->composeSystemPrompt($system);

        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user',   'content' => $this->prependAttachments($userBody)],
        ];
    }

    /**
     * Bilingual disclaimer prepended to the arbiter answer. Makes it
     * explicit that the displayed text is a best-of combined answer, not
     * a unanimous synthesis — the user requested this be visible in the
     * Final Answer card.
     */
    private function buildConflictDisclaimer(ConsensusResult $consensus): string
    {
        $score = number_format((float)$consensus->score, 3);
        return "⚠️ The models did not reach consensus (status: {$consensus->status}, score: {$score}). "
             . "The text below is the most accurate combined answer based on every model's output.\n"
             . "⚠️ Модели не достигли консенсуса (статус: {$consensus->status}, оценка: {$score}). "
             . "Ниже — наиболее точный объединённый ответ на основе результатов всех моделей.";
    }

    /** @param array<int,array{role:array<string,mixed>,normalized:array<string,mixed>}> $normalized */
    private function fallbackSynthesis(array $normalized, ?string $reason = null): string
    {
        $header = 'Synthesis fallback (synthesis LLM unavailable)';
        if ($reason !== null && $reason !== '') {
            $header .= ': ' . $reason;
        } else {
            $header .= '.';
        }
        $lines = [$header];
        if ($normalized === []) {
            $lines[] = 'No role responses were available — check that at least one provider has a valid API key '
                . 'and that the pipeline.synthesis_provider config points to an active connector.';
            return implode("\n", $lines);
        }
        foreach ($normalized as $r) {
            $key = (string)($r['role']['key'] ?? 'role');
            $lines[] = "- {$key}: " . mb_substr($this->renderForSynthesis($r['normalized']), 0, 200);
        }
        return implode("\n", $lines);
    }

    /**
     * v1.8.48 — remember that the final synthesis did NOT happen.
     *
     * Called from every code path that returns a `fallbackSynthesis()`
     * body, and from nowhere else, so `synthesisFailed()` means exactly
     * "the operator got an apology instead of a synthesis". The caller
     * (`run()` / `repairSession()`) uses it to keep the session out of the
     * `completed` state and to tell History to offer a re-run of L4.
     *
     * Sanitized and capped: the fields are published in the History API
     * and rendered verbatim, and the message is a provider body.
     *
     * @param list<array<string,mixed>> $attempts
     */
    private function recordSynthesisFailure(
        string  $reason,
        ?string $provider,
        ?string $model,
        ?string $message = null,
        ?string $hint = null,
        ?string $code = null,
        array   $attempts = [],
    ): void {
        $clean = static fn(?string $v, int $cap = 400): ?string => $v === null || trim($v) === ''
            ? null
            : mb_substr(Logger::sanitizeScalar($v), 0, $cap);

        $this->lastSynthesisFailure = [
            'reason'        => $reason,
            'provider'      => $clean($provider, 120),
            'model'         => $clean($model, 200),
            'message'       => $clean($message),
            'hint'          => $clean($hint),
            'provider_code' => $clean($code, 60),
            'attempts'      => $attempts === [] ? $this->lastSynthesisAttempts : $attempts,
        ];
    }

    /**
     * Did the last `run()` / `repairSession()` end on a synthesis
     * fallback? Null-safe accessor for the private failure record — the
     * History "Finish session" path and the job runner read this through
     * the returned result array, not through the property.
     *
     * @return array<string,mixed>|null
     */
    public function lastSynthesisFailure(): ?array
    {
        return $this->lastSynthesisFailure;
    }

    /** True when the last synthesis returned an apology instead of an answer. */
    public function synthesisFailed(): bool
    {
        return $this->lastSynthesisFailure !== null;
    }

    /**
     * v1.8.60 — the preflight: can every role this run is about to call
     * actually be called?
     *
     * The incident this release is about ended in `bothub HTTP 404
     * MODEL_NOT_FOUND` from L4, after a full L1/L2/L3 cycle had already
     * run, and the session was then stamped `completed` with the fallback
     * body as its answer. Two things were missing and both are fixed here:
     * nothing checked the *configured* models against the catalogue before
     * calling them, and the resolved L4 target was taken from
     * `pipeline.synthesis_model` even when that id was in no catalogue row.
     *
     * Strictness is proportional to what a role is for:
     *
     *   * **the pool** — `$selectedModels === []` while the request named a
     *     non-empty pool is a refusal: there is nothing to ask. The
     *     offending ids and the reason each was refused (missing from the
     *     active catalogue / provider connector not registered) travel with
     *     it, because "your pool is empty" without the ids is not
     *     actionable.
     *   * **synthesis** — resolved through the same chain L4 uses
     *     ({@see self::resolveSynthesisTarget()}, now catalogue-aware). No
     *     target means no answer, so it is a refusal, naming the role, the
     *     configured provider and model, and pointing at Actualize.
     *   * **the analyzer and the arbiter** — only strict when the operator
     *     explicitly turned them on (`analyzer.enabled`,
     *     `pipeline.conflict_arbiter.enabled`). Both are fail-soft by
     *     design (the analyzer degrades to heuristic routing, the arbiter
     *     falls through to the configured synthesis), so refusing an
     *     otherwise working run because an *optional* helper is stale would
     *     be a worse failure than the one being fixed. When they are
     *     switched on and unservable, they are refused like the others —
     *     the operator asked for them.
     *
     * @param array<int,array{provider:string,model:string}> $selectedModels
     * @param array<int,array<string,mixed>>|null $requestedPool
     * @return array{ok:bool,reason:string,role:?string,provider:?string,model:?string,message:string,notes:array<int,string>,facts:array<string,mixed>}
     */
    private function preflightRun(array $selectedModels, ?array $requestedPool, int $requestedCount): array
    {
        $notes = [];
        $facts = [
            'pool_accepted'      => count($selectedModels),
            'pool_requested'     => $requestedCount,
            'catalog_active'     => count(Config::getActiveModels()),
            'synthesis_target'   => null,
            'arbiter_target'     => null,
            'analyzer_target'    => null,
        ];

        // ---- The pool -------------------------------------------------
        if ($selectedModels === []) {
            $rejected = array_values(array_filter(
                (array)($this->lastSelectionDiagnostic['rejected'] ?? []),
                'is_array',
            ));
            $named = [];
            foreach ($rejected as $row) {
                $named[] = sprintf(
                    '%s/%s (%s)',
                    (string)($row['provider'] ?? ''),
                    (string)($row['model'] ?? ''),
                    (string)($row['reason'] ?? 'rejected'),
                );
            }
            $poolWasNamed = $requestedCount > 0;
            $message = $poolWasNamed
                ? sprintf(
                    'No model in the selected pool can be called: all %d entr(ies) were refused — %s. '
                        . 'A pool entry must be an ACTIVE row of the model catalogue (Settings → Models) '
                        . 'and its provider connector must be registered. Run Actualize to refresh the '
                        . 'catalogue, then pick the models again in the sidebar.',
                    $requestedCount,
                    $named === [] ? 'no reason was recorded' : implode(', ', array_slice($named, 0, 8)),
                )
                : 'No model can be called: the active model catalogue is empty (or every row in it belongs to '
                    . 'a provider whose connector is not registered). Run Settings → Models → Actualize '
                    . 'to build the catalogue, then pick a pool.';
            $facts['pool_rejected'] = $named;
            return [
                'ok'       => false,
                'reason'   => $poolWasNamed ? 'pool_no_usable_models' : 'catalog_empty',
                'role'     => 'pool',
                'provider' => $named === [] ? null : (string)($rejected[0]['provider'] ?? ''),
                'model'    => $named === [] ? null : (string)($rejected[0]['model'] ?? ''),
                'message'  => $message,
                'notes'    => $notes,
                'facts'    => $facts,
            ];
        }
        if ($requestedCount > count($selectedModels)) {
            $notes[] = sprintf(
                'The pool the request named had %d entr(ies); %d of them resolved to a callable model.',
                $requestedCount,
                count($selectedModels),
            );
        }

        // ---- L4 synthesis --------------------------------------------
        $synthesis = $this->resolveSynthesisTarget(
            (string)Config::get('pipeline.synthesis_provider', 'bothub'),
            (string)Config::get('pipeline.synthesis_model', 'openai/gpt-4o'),
            $selectedModels,
        );
        $facts['synthesis_target'] = $synthesis['provider'] . '/' . $synthesis['model'];
        if (($synthesis['reason'] ?? null) !== null) {
            // A substitution happened: the configured model is not a
            // servable catalogue row and the chain moved on. Named, not
            // silent — the operator has to know their configured synthesis
            // model is not what will answer.
            $notes[] = sprintf(
                'The configured synthesis model is not an active catalogue row, so L4 will synthesise with '
                    . '"%s/%s" instead. Reasons — %s. Update pipeline.synthesis_model in Settings → Models.',
                (string)$synthesis['provider'],
                (string)$synthesis['model'],
                (string)$synthesis['reason'],
            );
        }
        if ($synthesis['connector'] === null) {
            return [
                'ok'       => false,
                'reason'   => 'synthesis_model_not_served',
                'role'     => 'synthesis',
                'provider' => (string)$synthesis['provider'],
                'model'    => (string)$synthesis['model'],
                'message'  => sprintf(
                    'model "%s/%s" is no longer served: it is not an active row of the model catalogue and '
                        . 'no other model in the pool can synthesis either (%s). Run Actualize and pick a '
                        . 'current synthesis model (Settings → Models → Synthesis).',
                    (string)$synthesis['provider'],
                    (string)$synthesis['model'],
                    (string)($synthesis['reason'] ?? 'no connector is registered for its provider'),
                ),
                'notes'    => $notes,
                'facts'    => $facts,
            ];
        }

        // ---- L3 conflict arbiter (only when explicitly enabled) --------
        $arbiterConfigured = Config::get('pipeline.conflict_arbiter.enabled', false) === true;
        if ($arbiterConfigured) {
            $arbiterProvider = strtolower(trim((string)Config::get('pipeline.conflict_arbiter.provider', '')));
            $arbiterModel    = trim((string)Config::get('pipeline.conflict_arbiter.model', ''));
            $facts['arbiter_target'] = $arbiterProvider . '/' . $arbiterModel;
            $arbiterIssue = $this->roleUnservable('arbiter', $arbiterProvider, $arbiterModel);
            if ($arbiterIssue !== null) {
                return [
                    'ok'       => false,
                    'reason'   => 'arbiter_model_not_served',
                    'role'     => 'arbiter',
                    'provider' => $arbiterProvider,
                    'model'    => $arbiterModel,
                    'message'  => $arbiterIssue,
                    'notes'    => $notes,
                    'facts'    => $facts,
                ];
            }
        }

        // ---- Request analyzer (only when explicitly enabled) -----------
        if (Config::get('analyzer.enabled', false) === true) {
            $analyzerProvider = strtolower(trim((string)Config::get(
                'analyzer.provider',
                \App\Analyzer\RequestAnalyzer::DEFAULT_PROVIDER,
            )));
            $analyzerModel = trim((string)Config::get(
                'analyzer.model',
                \App\Analyzer\RequestAnalyzer::DEFAULT_MODEL,
            ));
            $facts['analyzer_target'] = $analyzerProvider . '/' . $analyzerModel;
            $analyzerIssue = $this->roleUnservable('analyzer', $analyzerProvider, $analyzerModel);
            if ($analyzerIssue !== null) {
                return [
                    'ok'       => false,
                    'reason'   => 'analyzer_model_not_served',
                    'role'     => 'analyzer',
                    'provider' => $analyzerProvider,
                    'model'    => $analyzerModel,
                    'message'  => $analyzerIssue,
                    'notes'    => $notes,
                    'facts'    => $facts,
                ];
            }
        }

        foreach ($facts as $key => $value) {
            if ($value !== null && is_string($value) && $value !== '') {
                $notes[] = sprintf('%s: %s', $key, $value);
            }
        }

        return [
            'ok'       => true,
            'reason'   => '',
            'role'     => null,
            'provider' => null,
            'model'    => null,
            'message'  => '',
            'notes'    => $notes,
            'facts'    => $facts,
        ];
    }

    /**
     * Why a flat-config role (analyzer / arbiter) cannot be called, or null
     * when it can. Both are synchronous one-shot callers, so the same two
     * gates apply as for a pool participant: a registered connector, and an
     * active catalogue row.
     */
    private function roleUnservable(string $role, string $provider, string $model): ?string
    {
        if ($provider === '' || $model === '') {
            return sprintf(
                'The %s role is enabled but has no provider/model configured. Set it in Settings → Models → %s.',
                $role,
                ucfirst($role),
            );
        }
        if (Config::getActiveModels() === []) {
            return sprintf(
                'The %s role is enabled but the active model catalogue is empty, so "%s/%s" cannot be verified. '
                    . 'Run Settings → Models → Actualize first.',
                $role,
                $provider,
                $model,
            );
        }
        foreach (Config::getActiveModels() as $row) {
            if (strtolower((string)$row['provider']) === $provider && (string)$row['model'] === $model) {
                return $this->connectors->getByProvider($provider) === null
                    ? sprintf(
                        'The %s role is enabled and points at "%s/%s", but no connector is registered for '
                            . 'provider "%s". Configure connectors.%s in Settings → Providers.',
                        $role,
                        $provider,
                        $model,
                        $provider,
                        $provider,
                    )
                    : null;
            }
        }
        return sprintf(
            'model "%s/%s" is no longer served: the %s role is enabled and points at it, but it is not an '
                . 'active row of the model catalogue. Run Actualize and pick a current %s model '
                . '(Settings → Models → %s).',
            $provider,
            $model,
            $role,
            $role,
            ucfirst($role),
        );
    }

    /**
     * v1.8.60 — decide the run's terminal status from its own facts, and
     * remember the verdict.
     *
     * The rule the operator asked for: a run may only be called `completed`
     * when it actually answered. Four facts make that impossible, and every
     * one of them is read from what the run *did* — the persisted steps, the
     * persisted events, the resolved pool and the answer body — never from
     * matching prose in the answer, which is what made the old check
     * (a single in-memory flag) miss a run whose L4 step was `failed` on
     * disk:
     *
     *   (a) the L4 synthesis step ended `failed`;
     *   (b) no usable L1 answer was obtained (L1_NO_RESULTS /
     *       L1_NO_AVAILABLE_MODELS was emitted);
     *   (c) a non-empty pool resolved to zero callable models;
     *   (d) the final answer is empty or is the fallback body.
     *
     * Any one of them forces `failed`. `synthesis_failure` alone used to be
     * the trigger, and it is still recorded — but it is no longer the only
     * witness.
     *
     * @param int    $sessionId
     * @param string $finalAnswer
     * @param array<int,array{provider:string,model:string}> $selectedModels
     * @param array<int,mixed> $l1Results
     * @param bool   $inspectJournal Read the session's own steps and events.
     *        False during a *repair*: the journal there still holds the
     *        failed L4 step of the run that crashed, and the question a
     *        repair answers is "did THIS attempt produce an answer?", which
     *        the in-memory facts already say. Reading the journal would
     *        condemn a successful repair for the previous attempt's failure.
     * @return array{status:string,reason:string,detail:string,role:?string,provider:?string,model:?string,stage:string,facts:array<string,mixed>}
     */
    /**
     * The L1 outcome of the run: how many opinions arrived, and which models
     * produced none (R11).
     *
     * Built from the dispatcher's per-model record, so the report can say
     * "timed out twice" or "the provider no longer serves this model" instead of
     * leaving an unexplained gap in the council. A model dropped by the breaker
     * appears in both lists' reason - once as missing, once as dropped.
     *
     * @return array{arrived:int,missing:array<int,array<string,mixed>>,breaker:array<int,array<string,mixed>>}
     */
    private function l1Outcome(): array
    {
        if ($this->dispatcher === null) {
            return ['arrived' => 0, 'missing' => [], 'breaker' => []];
        }
        $arrived = 0;
        foreach ($this->dispatcher->outcomes() as $row) {
            if (($row['ok'] ?? false) === true) {
                $arrived++;
            }
        }

        return [
            'arrived' => $arrived,
            'missing' => $this->dispatcher->missing(),
            'breaker' => $this->dispatcher->breakerSummary(),
        ];
    }

    private function resolveTerminalVerdict(
        int $sessionId,
        string $finalAnswer,
        array $selectedModels,
        array $l1Results,
        bool $inspectJournal = true,
    ): array {
        $facts = [
            'l4_step_failed'      => false,
            'l1_no_results'       => false,
            'pool_resolved_empty' => false,
            'answer_empty'        => trim($finalAnswer) === '',
            'answer_is_fallback'  => $this->lastSynthesisFailure !== null,
            'l1_answers'          => count($l1Results),
            'pool_accepted'       => count($selectedModels),
        ];

        $steps = [];
        if ($inspectJournal) {
            try {
                $steps = $this->steps->listStepsBySession($sessionId);
            } catch (\Throwable $e) {
                // A step read that fails must not turn a good run into a failed
                // one: the remaining facts still decide.
                Logger::warn('pipeline.verdict.steps_unavailable', [
                    'session_id' => $sessionId,
                    'error'      => Logger::sanitizeScalar($e->getMessage()),
                ]);
            }
            foreach ($steps as $step) {
                if (!is_array($step)) { continue; }
                $layer = strtoupper(trim((string)($step['layer'] ?? '')));
                $key = strtoupper(trim((string)($step['step_key'] ?? '')));
                $isL4 = $layer === 'L4' || str_starts_with($key, 'L4');
                if ($isL4 && strtolower((string)($step['status'] ?? '')) === 'failed') {
                    $facts['l4_step_failed'] = true;
                    break;
                }
            }
        }

        $events = [];
        if ($inspectJournal) {
            try {
                $events = $this->steps->listEventsBySession($sessionId);
            } catch (\Throwable $e) {
                Logger::warn('pipeline.verdict.events_unavailable', [
                    'session_id' => $sessionId,
                    'error'      => Logger::sanitizeScalar($e->getMessage()),
                ]);
            }
            foreach ($events as $event) {
                if (!is_array($event)) { continue; }
                $type = strtoupper(trim((string)($event['event_type'] ?? '')));
                if ($type === 'L1_NO_RESULTS' || $type === 'L1_NO_AVAILABLE_MODELS') {
                    $facts['l1_no_results'] = true;
                    break;
                }
            }
        }
        // The in-memory equivalent, for the case where the event row could
        // not be written (a database hiccup must not launder a dead run into
        // a successful one).
        if ($l1Results === [] && $selectedModels === []) {
            $facts['l1_no_results'] = true;
        }

        $requestedCount = (int)($this->lastSelectionDiagnostic['requested_count'] ?? 0);
        $facts['pool_requested'] = $requestedCount;
        $facts['pool_resolved_empty'] = $selectedModels === [] && $requestedCount > 0;

        $reason = '';
        $detail = '';
        $role = null;
        if ($facts['pool_resolved_empty']) {
            $reason = 'pool_no_usable_models';
            $role = 'pool';
            $detail = 'The request named a model pool, but not one of its entries is an active catalogue row '
                . 'with a registered provider connector, so no model was asked anything.';
        } elseif ($facts['l1_no_results']) {
            $reason = 'l1_no_results';
            $role = 'pool';
            $detail = $facts['pool_accepted'] === 0
                ? 'No model was available for L1: the pool resolved to zero callable models.'
                : 'Every L1 model call failed — no provider returned a usable answer.';
        } elseif ($facts['l4_step_failed']) {
            $reason = 'l4_synthesis_failed';
            $role = 'synthesis';
            $failure = $this->lastSynthesisFailure;
            if ($failure === null) {
                $detail = 'The L4 synthesis step is recorded as failed on this session, so the run produced '
                    . 'no synthesised answer.';
            } else {
                $role = 'synthesis';
                $detail = 'The L4 synthesis call failed'
                    . (($failure['message'] ?? null) !== null ? ': ' . (string)$failure['message'] : '.');
            }
        } elseif ($facts['answer_is_fallback']) {
            $reason = 'l4_fallback_body';
            $role = 'synthesis';
            $detail = 'No synthesis was produced; the run stored the fallback notice instead of an answer.';
        } elseif ($facts['answer_empty']) {
            $reason = 'empty_final_answer';
            $role = 'synthesis';
            $detail = 'The run produced no final answer at all.';
        }

        $failure = $this->lastSynthesisFailure;
        return [
            'status'   => $reason === '' ? 'completed' : 'failed',
            'reason'   => $reason,
            'detail'   => $detail,
            'role'     => $role,
            'provider' => is_array($failure) ? ($failure['provider'] ?? null) : null,
            'model'    => is_array($failure) ? ($failure['model'] ?? null) : null,
            'stage'    => 'terminal',
            'facts'    => $facts,
        ];
    }

    /**
     * Remember the terminal verdict. The last write wins, so an abort path
     * that knows more than the generic one can replace it.
     *
     * @param array<string,mixed> $verdict
     */
    private function recordTerminalVerdict(array $verdict): void
    {
        $this->terminalVerdict = $verdict + ['facts' => []];
    }

    /**
     * What this run's verdict was, for callers that only get the array.
     *
     * @return array<string,mixed>
     */
    public function lastTerminalVerdict(): array
    {
        return $this->terminalVerdict;
    }

    /**
     * v1.8.60 — the fallback body is not an answer, and must not be stored
     * as one.
     *
     * The body is still useful (it lists which role answers were available),
     * so it is kept, but it is *marked*: the first line says what it is. A
     * consumer that renders `final_answer` — the Final Answer card, History,
     * a share link — can no longer present it as the run's result, and the
     * session row itself carries the marker rather than a body that looks
     * like prose.
     */
    private function markFallbackAnswer(string $answer): string
    {
        $marker = '⚠️ NO SYNTHESIS — the pipeline could not produce an answer. '
            . 'The text below is a fallback notice, not a result.';
        if (str_starts_with($answer, $marker)) {
            return $answer;
        }
        return $answer === '' ? $marker : $marker . "\n\n" . $answer;
    }

    /**
     * Resolve the effective L4 synthesis (provider, model, connector). When
     * the configured synthesis target is unavailable (provider inactive or
     * connector unloaded), walk a deterministic fallback chain so a single
     * inactive provider never breaks the whole pipeline:
     *   a) first selected model whose provider has an available connector
     *      (preferring openrouter when present);
     *   b) configured openrouter active model from config.models;
     *   c) first available active model from config.models;
     *   d) null connector — caller emits L4_FAILED + fallbackSynthesis().
     *
     * @param  array<int,array<string,mixed>>|null $selectedModels
     * @return array{provider:string,model:string,connector:?\App\Connectors\ConnectorInterface,reason:?string}
     */
    private function resolveSynthesisTarget(string $configuredProvider, string $configuredModel, ?array $selectedModels): array
    {
        $cfgProviderLower = strtolower($configuredProvider);
        // v1.8.60 — the configured target is only used when it is a
        // servable catalogue row. v1.8.59 returned it as soon as its
        // provider *connector* existed, which is how a retired slug
        // (`bothub/gpt-4o`, no longer in any catalogue row) became the L4
        // call and answered HTTP 404 MODEL_NOT_FOUND at the end of a run.
        // When the row is gone, the chain below substitutes an active model
        // and the caller reports the substitution — the operator still
        // learns their configured model is stale, but the run is not
        // wasted.
        $configuredAvailable = $this->connectorForCatalogRow($cfgProviderLower, $configuredModel);
        if ($configuredAvailable !== null) {
            return [
                'provider'  => $cfgProviderLower,
                'model'     => $configuredModel,
                'connector' => $configuredAvailable,
                'reason'    => null,
            ];
        }

        $reason = $this->describeSynthesisTargetUnavailable($configuredProvider, $configuredModel);

        // (a) Try selected models — prefer OpenRouter, then any available.
        // Every selected model is an active catalogue row by construction
        // (resolveSelectionWithDiagnostics), so the connector check is the
        // only one left to make.
        $selectedAvailable = [];
        if (is_array($selectedModels)) {
            foreach ($selectedModels as $entry) {
                if (!is_array($entry)) { continue; }
                $p = strtolower(trim((string)($entry['provider'] ?? '')));
                $m = trim((string)($entry['model'] ?? ''));
                if ($p === '' || $m === '') { continue; }
                $conn = $this->connectors->getByProvider($p);
                if ($conn !== null) {
                    $selectedAvailable[] = ['provider' => $p, 'model' => $m, 'connector' => $conn];
                }
            }
        }
        foreach ($selectedAvailable as $row) {
            if ($row['provider'] === 'openrouter') {
                return [
                    'provider'  => $row['provider'],
                    'model'     => $row['model'],
                    'connector' => $row['connector'],
                    'reason'    => $reason,
                ];
            }
        }
        if ($selectedAvailable !== []) {
            $row = $selectedAvailable[0];
            return [
                'provider'  => $row['provider'],
                'model'     => $row['model'],
                'connector' => $row['connector'],
                'reason'    => $reason,
            ];
        }

        // (b) Configured openrouter active model.
        $orConn = $this->connectors->getByProvider('openrouter');
        if ($orConn !== null) {
            foreach (Config::getActiveModels() as $m) {
                if (strtolower((string)$m['provider']) === 'openrouter') {
                    return [
                        'provider'  => 'openrouter',
                        'model'     => (string)$m['model'],
                        'connector' => $orConn,
                        'reason'    => $reason,
                    ];
                }
            }
        }

        // (c) First available active model from config.
        foreach (Config::getActiveModels() as $m) {
            $p = strtolower((string)$m['provider']);
            $conn = $this->connectors->getByProvider($p);
            if ($conn !== null) {
                return [
                    'provider'  => $p,
                    'model'     => (string)$m['model'],
                    'connector' => $conn,
                    'reason'    => $reason,
                ];
            }
        }

        // (d) Nothing available — caller will emit L4_FAILED + fallbackSynthesis().
        return [
            'provider'  => $cfgProviderLower,
            'model'     => $configuredModel,
            'connector' => null,
            'reason'    => $reason,
        ];
    }

    private function diagnoseConnectorError(string $message, string $provider, string $model): ?string
    {
        $lower = strtolower($message);
        if (str_contains($lower, '404')
            || str_contains($lower, 'no endpoints found')
            || str_contains($lower, 'model not found')
            || str_contains($lower, 'unknown model')
            || str_contains($lower, 'model_not_found')
            || str_contains($lower, 'invalid model')
        ) {
            $hint = sprintf(
                'Model "%s" was rejected by provider "%s". Verify the slug against the provider\'s '
                . '/models endpoint (OpenRouter: https://openrouter.ai/api/v1/models) and update '
                . 'config.models accordingly.',
                $model,
                $provider,
            );
            // v1.8.48 — say the one thing a MODEL_NOT_FOUND actually means.
            // The catalogue on this side is a snapshot; when the provider
            // answers with its own "no such model" code, the model is simply
            // not in that provider's live list any more (retired, renamed,
            // or never present for this account), and the fix is to refresh
            // the list rather than to keep re-running the same slug. Stated
            // as what the provider reported — never as a guessed cause.
            if (str_contains($lower, 'model_not_found')
                || str_contains($lower, 'model not found')
                || str_contains($lower, 'unknown model')
                || str_contains($lower, 'invalid model')
                || str_contains($lower, 'no endpoints found')
            ) {
                $hint .= sprintf(
                    ' The provider reports this model id as not available on this account, so the stored '
                    . 'catalogue is stale: refresh the live model list (Settings → Models → Actualize, or '
                    . 'POST /api/models/actualize for provider "%s") and re-check the slug before re-running.',
                    $provider,
                );
            }
            return $hint;
        }
        if (str_contains($lower, '401') || str_contains($lower, '403') || str_contains($lower, 'unauthorized')) {
            return sprintf(
                'Authentication rejected by provider "%s". Check connectors.%s.api_key or .%s.key file.',
                $provider, $provider, $provider,
            );
        }
        if (str_contains($lower, '429') || str_contains($lower, 'rate limit') || str_contains($lower, 'too many requests')) {
            return sprintf('Rate-limited by provider "%s". Reduce concurrency or upgrade your plan.', $provider);
        }
        return null;
    }

    /**
     * Best-effort endpoint URL for a provider, used to fill the
     * `endpoint_host`/`endpoint_path` fields of the connector diagnostic.
     * Reads `connectors.{provider}.base_url` and appends `/chat/completions`
     * — the only path the engine actually calls today. Returns the
     * configured URL even if it is not reachable; ErrorDiagnostics will
     * parse it without performing IO.
     */
    private function endpointFor(string $provider): ?string
    {
        $base = Config::get("connectors.{$provider}.base_url", null);
        if (!is_string($base) || $base === '') {
            return null;
        }
        return rtrim($base, '/') . '/chat/completions';
    }

    /**
     * Classify a fatal pipeline error into one of the structured
     * diagnostic categories. Returns null when the error is not
     * recognized (the generic message remains the user-visible one).
     *
     * @return array<string,mixed>|null
     */
    private function classifyFatalError(\Throwable $e): ?array
    {
        if ($e instanceof \PDOException) {
            return ErrorDiagnostics::describeDatabaseError($e, ['operation' => 'pipeline']);
        }
        if ($e instanceof ConnectorException) {
            return ErrorDiagnostics::describeConnectorError($e);
        }
        return null;
    }

    /**
     * v1.8.60 — the connector for a model, but only when the model is an
     * *active catalogue row*.
     *
     * The distinction is the whole point: `getByProvider()` answers "is this
     * provider wired up?", which is true for a provider that has retired the
     * model. A run that treats the two as the same thing calls the retired
     * slug and collects a 404.
     *
     * @return object|null The connector, or null when the row is missing or
     *         inactive, or the provider has no connector.
     */
    private function connectorForCatalogRow(string $provider, string $model): ?object
    {
        $provider = strtolower(trim($provider));
        $model = trim($model);
        if ($provider === '' || $model === '') {
            return null;
        }
        foreach (Config::getActiveModels() as $row) {
            if (strtolower((string)$row['provider']) === $provider && (string)$row['model'] === $model) {
                return $this->connectors->getByProvider($provider);
            }
        }
        return null;
    }

    /**
     * Why the configured synthesis target was not used, in one sentence a
     * reader can act on. Distinguishes "the provider is not wired up" (a
     * connector problem) from "the provider is fine but this model id is not
     * a catalogue row" (a stale-catalogue problem) — the two need different
     * fixes and used to produce the same 404.
     */
    private function describeSynthesisTargetUnavailable(string $provider, string $model): string
    {
        $providerLower = strtolower(trim($provider));
        $model = trim($model);
        if ($providerLower === '' || $model === '') {
            return 'the synthesis role has no provider/model configured (pipeline.synthesis_provider / '
                . 'pipeline.synthesis_model)';
        }
        if ($this->connectors->getByProvider($providerLower) !== null) {
            return sprintf(
                'model "%s/%s" is not an active row of the model catalogue (pipeline.synthesis_model points at a '
                    . 'model the catalogue does not hold, or the row is not active)',
                $providerLower,
                $model,
            );
        }
        return $this->describeMissingConnector($provider);
    }

    private function describeMissingConnector(string $provider): string
    {
        $providerCfg = Config::get("connectors.{$provider}", null);
        if (!is_array($providerCfg)) {
            return "No connector for provider '{$provider}': not configured in config.connectors.";
        }
        if (($providerCfg['active'] ?? false) !== true) {
            return "No connector for provider '{$provider}': marked inactive (set connectors.{$provider}.active=true).";
        }
        $hasKey = false;
        foreach ($this->connectors->discovered() as $c) {
            if (strtolower($c->getProviderName()) === strtolower($provider)) {
                $hasKey = $c->isAvailable();
                break;
            }
        }
        if (!$hasKey) {
            $envName = strtoupper($provider) . '_API_KEY';
            return "No connector for provider '{$provider}': API key missing (set connectors.{$provider}.api_key, "
                . "create a .{$provider}.key file at the project root, or export {$envName} as an environment variable).";
        }
        return "No active connector for provider '{$provider}'.";
    }

    /**
     * Read-only accessor for the trace events captured during the most
     * recent run. Sanitized on the way out.
     *
     * @return array<int,array<string,mixed>>
     */
    public function trace(): array
    {
        return Logger::sanitize($this->trace);
    }

    /** @param array<string,mixed> $payload */
    private function traceEvent(string $type, array $payload): void
    {
        $this->trace[] = ['type' => $type, 'payload' => $payload];
    }

    /** @param array<string,mixed> $payload */
    private function emitEvent(int $sessionId, ?int $stepId, string $eventType, array $payload): void
    {
        $this->steps->appendEvent($sessionId, $stepId, $eventType, $payload);
        if ($this->streamer !== null) {
            try {
                $this->streamer->emit($eventType, $payload, $stepId);
            } catch (\Throwable $e) {
                Logger::warn('streamer emit failed', ['type' => $eventType, 'message' => $e->getMessage()]);
            }
        }
    }

    /**
     * Resolve the consensus scoring mode for the active run.
     *
     * Priority:
     *   1. `runtimeOptions['consensus_skill']['scoring']` (skill-driven)
     *   2. `runtimeOptions['consensus_skill']` slug → bundled skill JSON
     *      (looks up `consensus_scoring` on the on-disk definition for
     *      semantic_consensus_reasoning.json / consensus_reasoning.json)
     *   3. ConsensusEngine::defaultMode() — Config-driven default.
     */
    private function resolveConsensusMode(): string
    {
        $opt = $this->runtimeOptions['consensus_skill'] ?? null;
        if (is_array($opt) && isset($opt['scoring']) && is_string($opt['scoring'])) {
            return strtolower(trim($opt['scoring']));
        }
        if (is_string($opt) && $opt !== '') {
            $slug = preg_replace('/[^a-z0-9_\-]/i', '', $opt) ?? '';
            if ($slug !== '') {
                $defFile = __DIR__ . '/../Skills/definitions/' . $slug . '.json';
                if (is_file($defFile)) {
                    $body = @file_get_contents($defFile);
                    if (is_string($body) && $body !== '') {
                        $decoded = json_decode($body, true);
                        if (is_array($decoded) && isset($decoded['consensus_scoring'])
                            && is_string($decoded['consensus_scoring'])
                        ) {
                            return strtolower(trim($decoded['consensus_scoring']));
                        }
                    }
                }
            }
        }
        return $this->consensus->defaultMode();
    }

    private function detectLoop(string $stepKey, int $sessionId): bool
    {
        if (in_array($stepKey, $this->observedKeys, true)) {
            $this->emitEvent($sessionId, null, 'LOOP_DETECTED', ['step_key' => $stepKey]);
            return true;
        }
        $this->observedKeys[] = $stepKey;
        return false;
    }
}
