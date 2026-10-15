<?php
/**
 * RevoAp front controller — bootstrap, routing and dispatch.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.3
 * @since    2026-05-05
 */
declare(strict_types=1);

// Composer autoloader if present; otherwise fall back to bundled PSR-4 loader.
$composer = __DIR__ . '/vendor/autoload.php';
if (is_file($composer)) {
    require $composer;
} else {
    require __DIR__ . '/src/autoload.php';
}

use App\Batch\BatchCapability;
use App\Cache\ModelResponseCache;
use App\Connectors\ConnectorRegistry;
use App\Consensus\ConsensusEngine;
use App\Consensus\NormalizationService;
use App\Core\AdminAuth;
use App\Core\Application;
use App\Core\Config;
use App\Core\DatabaseHealth;
use App\Core\Diagnostics;
use App\Cost\EconomyController;
use App\Helpers\TemplateHelper;
use App\History\HistoryService;
use App\History\SessionCompletionService;
use App\Http\Controllers\CouncilController;
use App\Http\Controllers\CreatorController;
use App\Http\Controllers\HistoryController;
use App\Http\Controllers\JobsController;
use App\Http\Controllers\LogsController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\DomainPresetsController;
use App\Http\Controllers\AnalyzerController;
use App\Http\Controllers\DocumentShareController;
use App\Http\Controllers\SkillDocsController;
use App\Http\Controllers\ExtensionsController;
use App\Http\Controllers\SecurityController;
use App\Http\Controllers\ResearchController;
use App\Http\Controllers\SkillDraftsController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\ShareController;
use App\Http\Controllers\SkillController;
use App\Http\Controllers\StatisticsController;
use App\Http\Controllers\SystemHealthController;
use App\Http\Controllers\StatsController;
use App\Http\Controllers\WorkerController;
use App\Http\Controllers\WorkerFeedController;
use App\Http\Controllers\ModelsController;
use App\Http\RequestPath;
use App\Models\ModelId;
use App\Http\Response;
use App\Api\ApiAuth;
use App\Api\ApiKeyController;
use App\Api\ApiKeyRepository;
use App\Api\ApiKeyService;
use App\Api\ApiRateLimiter;
use App\Api\ApiV1Controller;
use App\Api\FormatAdapter;
use App\Api\WebhookController;
use App\Api\WebhookDeliveryRepository;
use App\Api\WebhookRepository;
use App\Api\WebhookService;
use App\Extensions\MdViewerInstaller;
use App\Logs\LogsService;
use App\Pipeline\PipelineEngine;
use App\Pipeline\RunControl;
use App\Pipeline\StreamRunLock;
use App\Security\IpFirewall;
use App\Profiles\SystemPromptAssembler;
use App\Profiles\UserProfileRepository;
use App\DomainPresets\DomainPresetRepository;
use App\DomainPresets\DomainPresetSeeder;
use App\DomainPresets\DomainRouter;
use App\Analyzer\RequestAnalyzer;
use App\Skills\SkillAutoCreator;
use App\Skills\SkillLoader;
use App\Skills\SkillRepository;
use App\Skills\SkillRouter;
use App\Skills\SkillSelector;
use App\Statistics\StatisticsAggregator;
use App\Statistics\StatisticsService;
use App\Stats\StatsAggregator;
use App\Stats\StatsCollector;
use App\Jobs\JobAuditRepository;
use App\Jobs\JobRepository;
use App\Jobs\JobService;
use App\Jobs\WorkerHealthService;
use App\Models\ModelImporter;
use App\Models\ModelsActualizeSettings;
use App\Models\ModelsActualizer;
use App\Models\ModelsSnapshotStore;
use App\Settings\ConfigRepository;
use App\Settings\SystemHealthService;
use App\Worker\WorkerDiagnostics;
use App\Worker\WorkerJobFeed;
use App\Storage\ResponseRepository;
use App\Storage\SessionRepository;
use App\Storage\ShareRepository;
use App\Storage\StepRepository;
use App\Memory\ConnectorMemoryEmbedder;
use App\Memory\EmbeddingMemory;
use App\Memory\PdoMemoryRepository;
use App\Storage\Vector\VectorStoreFactory;

$app = new Application(__DIR__);
TemplateHelper::setTemplatesDir(__DIR__ . '/assets/templates');

// IP firewall — runs as early as possible, after Config is loaded by
// Application's constructor and before any controller resolves. Loopback
// (127.0.0.0/8 / ::1) is always allowed; the Security tab guarantees the
// admin's IP is auto-added to the whitelist before enforcement engages,
// so a misconfigured rule cannot lock the operator out from the host.
IpFirewall::enforce();

// `?creator` is a global override — handled before any routing.
if ($app->isCreatorRequest()) {
    echo (new CreatorController())->show();
    return;
}

// Resolve the request path the router should see. This normalizes direct
// hits to `/index.php` (and `/index.php/...`) so they behave the same as if
// Apache's mod_rewrite had transparently routed them through the front
// controller, and also strips any subdirectory prefix when the app is
// deployed below the document root.
$resolvedPath = RequestPath::resolve($_SERVER['REQUEST_URI'] ?? '/', $_SERVER['SCRIPT_NAME'] ?? '');

$db             = $app->database();
$pdo            = $app->pdo();
$connectors     = $app->connectors();
$responseCache  = ModelResponseCache::fromConfig($db, __DIR__);

// `?debug=1` (or `?DEBUG=1`) forces verbose front-end console logging (see
// app.log_to_console in config.php) and must still render the normal page:
// a query flag that replaces the whole UI with JSON is not a debugging aid,
// it is a trap. The sanitized diagnostic report is served where a machine
// asks for it - `/api/debug`, `?debug=report`, or `?debug=1` from any client
// that does not accept HTML, so existing curl/script callers keep working.
if ($app->isDebugRequest()) {
    $accept      = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
    $debugRaw    = $_GET['debug'] ?? null;
    $debugValue  = strtolower(trim((string)(is_scalar($debugRaw) ? $debugRaw : '')));
    $explicit    = in_array($debugValue, ['report', 'json'], true);
    $acceptsHtml = str_contains($accept, 'text/html') || str_contains($accept, 'application/xhtml');
    $wantsReport = $explicit || str_contains($accept, 'application/json') || !$acceptsHtml;

    if ($wantsReport) {
        echo Response::json(Diagnostics::buildReport($connectors, $pdo, $resolvedPath, $responseCache));
        return;
    }
}

$sessions       = new SessionRepository($db ?? $pdo);
$stepRepo       = new StepRepository($db ?? $pdo);
$responseRepo   = new ResponseRepository($db ?? $pdo);
// v1.8.47 - `$db ?? $pdo`, not `$pdo`. These three sit on the pipeline's
// hot path (skill resolution, local-profile read, default domain preset)
// and have no swallow-and-continue fallback, so a handle that has gone
// away must be replaceable rather than cached. An FPM request is short
// enough to usually get away with a raw PDO, but a long-lived worker
// reusing the same objects is not — and the web path runs the same
// `?stream` pipeline, where an idle gap between requests is enough for
// MySQL to reap the socket.
$skillRepo      = new SkillRepository($db ?? $pdo);

// Seed in-memory skill repository when running without DB.
if ($pdo === null) {
    $loader = new SkillLoader(__DIR__ . '/src/Skills/definitions');
    foreach ($loader->loadAll() as $skill) {
        $skillRepo->save($skill);
    }
} else {
    // v1.8.32 — best-effort upsert of the bundled system skills
    // (consensus + math + physics + ux + …) so the domain-routing
    // layer can resolve `mathematics-reasoning` / `physics-reasoning`
    // even on databases that were created before those JSONs shipped.
    try {
        $loader = new SkillLoader(__DIR__ . '/src/Skills/definitions');
        foreach ($loader->loadAll() as $skill) {
            if ($skillRepo->findBySlug($skill->slug) === null) {
                $skillRepo->save($skill);
            }
        }
    } catch (\Throwable) {
        // Non-fatal — the catalog can still be edited via the admin UI.
    }
}

$router         = $app->router();
$skillRouter    = new SkillRouter($skillRepo);
$autoCreator    = new SkillAutoCreator($skillRepo, $connectors);
$skillSelector  = new SkillSelector($skillRepo, $connectors, $autoCreator);
$normalizer     = new NormalizationService();
$consensus      = new ConsensusEngine();
$collector      = new StatsCollector();
$aggregator     = new StatsAggregator();

$pipeline       = new PipelineEngine(
    $connectors, $sessions, $stepRepo, $responseRepo, $skillRepo,
    $skillRouter, $autoCreator, $normalizer, $consensus, $collector,
    null, null, $responseCache, $skillSelector,
);

// Task 5 - wire the optional vector memory facade. All components
// degrade softly ("no memory") when the DB / embedder is unavailable;
// actual recall/store are additionally gated by pipeline.memory_top_k > 0
// inside the engine, so legacy behaviour is unchanged until enabled.
$pipeline->setMemory(new EmbeddingMemory(
    vectorStore: VectorStoreFactory::create($pdo),
    embedder:    new ConnectorMemoryEmbedder($connectors),
    repository:  new PdoMemoryRepository($pdo),
));
$runControl     = new RunControl(__DIR__);
$pipeline->setRunControl($runControl);
$streamLock     = new StreamRunLock(__DIR__);

$councilCtrl    = new CouncilController($pipeline, $sessions, $stepRepo, $connectors, $pdo, $runControl, $streamLock);
$skillCtrl      = new SkillController($skillRepo);
$statsCtrl      = new StatsController($sessions, $stepRepo, $responseRepo, $aggregator);
// R10 - the economy advice endpoint. Read-only: it answers what a proposed run
// would cost, how long it would take, and whether the batch queue is the better
// home for it. The decision core lives in src/Cost and is covered by tests.
$economyCtrl   = new EconomyController($db);
// R9 extension - the folded per-model usefulness (contributions and
// non-answers kept apart), read from the journal, shown in the Models tab.
$usefulnessCtrl = new \App\Models\UsefulnessController($db);

$settingsCtrl   = new SettingsController(__DIR__, $pdo, $db);
$logsCtrl       = new LogsController(new LogsService(__DIR__));
$extensionsCtrl = new ExtensionsController(new MdViewerInstaller(__DIR__));
$documentShareCtrl = new DocumentShareController(__DIR__, new MdViewerInstaller(__DIR__));
// Drafting a skill is an operator action: it spends a model call and, on
// confirmation, adds a skill - same admin protection as the rest of /api/*.
$skillDraftsCtrl = new SkillDraftsController($autoCreator, $skillRepo);
// Searching arXiv and analysing what it returns: an operator action, one
// public API call plus one model call, recorded in the research journal
// (best-effort - an unavailable database never fails a search).
$researchCtrl = new ResearchController(new \App\Research\PaperAnalyst($connectors), new \App\Research\ResearchJournal($pdo));
$securityCtrl   = new SecurityController(__DIR__);
// The job reader lets a session whose L1 is waiting on the Batch API read as
// "awaiting the provider" in History rather than as a stalled `running` row
// (see HistoryService::withBatchState). Constructed here rather than reusing
// the shared `$jobRepo` below because History is wired first and the wrapper
// is a thin holder, not a connection.
$historyService = new HistoryService($db ?? $pdo, new JobRepository($db ?? $pdo));
$completionSvc  = new SessionCompletionService(
    $sessions,
    $stepRepo,
    $responseRepo,
    $skillRepo,
    $pipeline,
    $streamLock,
);
$historyCtrl    = new HistoryController($historyService, $completionSvc);
$statisticsSvc  = new StatisticsService($db ?? $pdo, new StatisticsAggregator());
$statisticsCtrl = new StatisticsController($statisticsSvc);
$shareRepo      = new ShareRepository($db ?? $pdo);
$shareCtrl      = new ShareController($shareRepo, $sessions, $stepRepo, $historyService, $db ?? $pdo);

// v1.8.32 — profile, domain presets, analyzer wiring.
// v1.8.47 — the resilient wrapper (see the note on $skillRepo above).
$profileRepo     = new UserProfileRepository($db ?? $pdo);
$promptAssembler = new SystemPromptAssembler(__DIR__);
$profileCtrl     = new ProfileController($profileRepo);
$presetsRepo     = new DomainPresetRepository($db ?? $pdo);
// The Skills tab opens a skill's full description through the share
// publisher: one write path, one set of limits, one viewer URL.
$skillDocsCtrl = new SkillDocsController(__DIR__, $documentShareCtrl, $skillRepo, $presetsRepo);
$presetsRepo->seedDefaultsIfEmpty();
$domainRouter    = new DomainRouter();
$presetsCtrl     = new DomainPresetsController($presetsRepo);
$analyzer        = new RequestAnalyzer($connectors);
$analyzerCtrl    = new AnalyzerController($analyzer);

// v1.8.35 — Worker Architecture v2 (Phase 1): durable job layer.
// JobRepository persists `revoap_jobs` rows; JobService encapsulates
// session+job creation and intent transitions (pause/resume/cancel/
// run-consensus). The legacy /api/council/stream endpoint is untouched
// — Phase 1 ships the new surface alongside it for testing.
$jobRepo    = new JobRepository($db ?? $pdo);
// v1.8.40 — durable retry/audit timeline. JobAuditRepository persists one
// row in `revoap_job_events` per notable lifecycle transition so the
// History UI ("Retry & recovery" timeline) can render the complete story
// of a concrete job/session even when the worker crashed and the job was
// auto-recovered. The sink is attached to JobRepository so every
// mutation (create, acquire, status update, error, stale recovery, lock
// release) is captured. Best-effort writes — a missing migration 013 or
// any DB error never aborts the underlying job mutation.
$jobAuditRepo = new JobAuditRepository($db ?? $pdo);
$jobRepo->setAuditRepository($jobAuditRepo);
$jobService = new JobService(
    jobs:                $jobRepo,
    sessions:            $sessions,
    defaultUserResolver: static function () use ($profileRepo): ?int {
        try {
            $profile = $profileRepo->findByExternalId(UserProfileRepository::LOCAL_EXTERNAL_ID);
            $uid = $profile?->userId ?? 0;
            return $uid > 0 ? $uid : null;
        } catch (\Throwable) {
            return null;
        }
    },
);
// The Batch API cancel path needs its own collaborator: a batch job parked
// between polls cannot be cancelled through the generic status-intent route
// (see BatchCanceller). Constructed here so the controller stays a thin
// dispatcher.
$batchCanceller = new \App\Batch\BatchCanceller($jobRepo);
$jobsCtrl = new JobsController($jobService, $stepRepo, $jobAuditRepo, $batchCanceller);

// v1.8.43 - external REST API v1. Same pipeline/job services, but the
// front door is an API key (rv_*) instead of the operator UI session.
$apiKeyRepo = new ApiKeyRepository($pdo);
$apiKeySvc  = new ApiKeyService($apiKeyRepo);
$apiKeyCtrl = new ApiKeyController($apiKeySvc);
$apiLimiter = new ApiRateLimiter(__DIR__ . '/runtime/api-rate-limits');
// Per-IP protection for /v1/*: 120 requests/minute per client address,
// enforced before any key handling (brute-force backstop).
$apiAuth    = new ApiAuth($apiKeySvc, $apiLimiter, null, 120);
$apiV1      = new ApiV1Controller(
    $apiAuth,
    new FormatAdapter(),
    $jobService,
    $pipeline,
    $sessions,
    $stepRepo,
    $runControl,
    $streamLock,
    $statisticsSvc,
);

// v1.8.43 - outbound webhooks. Admin-managed subscriptions that receive
// signed run lifecycle events (run.completed / run.failed / run.cancelled).
// This controller only serves the admin CRUD under /api/webhooks; the
// actual dispatch + retry pump lives in bin/revoap-worker.php.
$webhookRepo         = new WebhookRepository($pdo);
$webhookDeliveryRepo = new WebhookDeliveryRepository($pdo);
$webhookSvc          = new WebhookService($webhookRepo);
$webhookCtrl         = new WebhookController($webhookSvc, $webhookDeliveryRepo);

// v1.8.38 — Worker Status diagnostics. Read-only snapshot of the
// `revoap_jobs` queue and the active worker pool, exposed under
// /api/health/worker.
$workerHealth = new WorkerHealthService($jobRepo, $db);
// v1.8.45 — header worker-status indicator feeds. Both are public
// (same trust level as /api/health/worker) and strictly additive: the
// job feed carries ids/statuses/timings only, the diagnostics feed
// masks connector identities before the self-test detail leaves the
// process. No existing health key changes shape or meaning.
$workerJobFeed   = new WorkerJobFeed($jobRepo, $stepRepo, $db ?? null);
$workerDiagFeed  = new WorkerDiagnostics($jobRepo, $workerHealth, $jobAuditRepo, $db ?? null);
$workerFeedCtrl  = new WorkerFeedController($workerJobFeed, $workerDiagFeed);
// v1.8.44 — Settings → Worker card. `worker/test` enqueues a token-free
// self-test job (kind=selftest — the worker runs App\Jobs\WorkerSelfTest
// instead of the LLM pipeline, so no provider call and no tokens are
// spent); `worker/debug` serves the operator diagnostic bundle behind an
// admin-OR-IP-whitelist gate.
$workerCtrl = new WorkerController(
    health:  $workerHealth,
    jobs:    $jobService,
    jobRepo: $jobRepo,
    logs:    new LogsService(__DIR__),
    pdo:     $pdo,
    audit:   $jobAuditRepo,
    rootDir: __DIR__,
);
// R7 - System health panel. Composes the services that already own each
// fact (worker code state, schema/migrations, writability, the persisted
// catalogue, the linked roles) and takes the verdicts in the pure
// App\Diagnostics\SystemHealth. Read-only: the fixes are the existing
// admin-gated endpoints, so there is no second repair API to keep safe.
$systemHealthSvc = new SystemHealthService(
    rootDir:      __DIR__,
    workerHealth: $workerHealth,
    pdo:          $pdo,
    db:           $db,
    presets:      $presetsRepo,
    config:       $pdo !== null ? new ConfigRepository($pdo) : null,
);
$systemHealthCtrl = new SystemHealthController($systemHealthSvc);

// v1.8.49 — Models tab. The model-facing settings (providers, catalogue,
// consensus presets, analyzer) moved out of Settings into their own tab,
// and with them the "Actualize linked models" action. The actualization
// itself runs in the worker (payload.kind=models_actualize); this process
// only enqueues it, reads the last run's status and restores a snapshot.
// The controller and the worker build the same service graph from the same
// repositories, so "what the API reports" and "what the worker does" cannot
// drift apart. Built here (not next to the other controllers) because the
// status endpoint reports worker availability, which needs $workerHealth.
$modelsStore       = new ModelsSnapshotStore($pdo !== null ? new ConfigRepository($pdo) : null);
// The section's own setting (`models.actualize.analyzer`) lives in the same
// `app_config` table as the snapshot, so one repository serves both — and
// both degrade to "unavailable" the same way on a host mid-migration.
$modelsPreferences = new ModelsActualizeSettings($pdo !== null ? new ConfigRepository($pdo) : null);
$modelsActualizer  = new ModelsActualizer(
    connectors:  $connectors,
    presets:     $presetsRepo,
    store:       $modelsStore,
    audit:       $jobAuditRepo,
    cache:       $responseCache,
    importer:    new ModelImporter(),
    preferences: $modelsPreferences,
);
$modelsCtrl = new ModelsController(
    jobs:        $jobService,
    jobRepo:     $jobRepo,
    store:       $modelsStore,
    actualizer:  $modelsActualizer,
    health:      $workerHealth,
    preferences: $modelsPreferences,
);
if (method_exists($pipeline, 'setSystemPromptAssembler')) {
    $pipeline->setSystemPromptAssembler($promptAssembler);
}
if (method_exists($pipeline, 'setUserProfileRepository')) {
    $pipeline->setUserProfileRepository($profileRepo);
}
if (method_exists($pipeline, 'setDomainRouter')) {
    $pipeline->setDomainRouter($domainRouter, $presetsRepo, $skillRepo);
}
if (method_exists($pipeline, 'setRequestAnalyzer')) {
    $pipeline->setRequestAnalyzer($analyzer);
}

// Routes
$router->get('/',                                  fn(): string => renderHome($connectors));
$router->post('/api/council/run',                  fn(): string => $councilCtrl->run());
// SSE pipeline stream. Long-lived; emits one event per pipeline step as
// it completes. Frontend uses EventSource('/api/council/stream?query=…').
$router->get ('/api/council/stream',               fn(): string => $councilCtrl->stream());
// Cooperative run control: pause / continue / reject the active session.
// Cooperative semantics — see CouncilController::control() for details.
// Public (matches the rest of /api/council/*); rate-limit at the proxy
// layer if exposed publicly.
$router->post('/api/council/control',              fn(): string => $councilCtrl->control());
$router->post('/api/council/economy/advice',     fn(): string => $economyCtrl->advice());

$router->get ('/api/council/session/{id}',         fn(array $p): string => $councilCtrl->getSession($p));
$router->get ('/api/council/session/{id}/events',  fn(array $p): string => $councilCtrl->getEvents($p));
$router->get ('/api/council/session/{id}/stats',   fn(array $p): string => $statsCtrl->show($p));

// v1.8.35 — Worker Architecture v2 (Phase 1). Durable job layer ships
// alongside the legacy synchronous /api/council/run + /api/council/stream
// for backward compatibility. The new endpoints enqueue rows in
// `revoap_jobs`; the CLI worker (bin/revoap-worker.php) dispatches
// the LLM pipeline outside of PHP-FPM. The replay endpoint streams
// persisted pipeline_events only and never invokes the pipeline itself.
$router->post('/api/council/jobs',                              fn(): string => $jobsCtrl->create());
$router->get ('/api/council/jobs/{id}',                         fn(array $p): string => $jobsCtrl->get($p));
$router->post('/api/council/jobs/{id}/pause',                   fn(array $p): string => $jobsCtrl->pause($p));
$router->post('/api/council/jobs/{id}/resume',                  fn(array $p): string => $jobsCtrl->resume($p));
$router->post('/api/council/jobs/{id}/cancel',                  fn(array $p): string => $jobsCtrl->cancel($p));
$router->post('/api/council/jobs/{id}/run-consensus',           fn(array $p): string => $jobsCtrl->runConsensus($p));
// v1.8.40 — durable retry/audit timeline. The timeline endpoint surfaces
// every notable lifecycle transition (created, acquired, stale.detected,
// stale.requeued, stale.exhausted, completed, failed, …) recorded in the
// `revoap_job_events` table so the History UI can render a complete
// retry & recovery audit trail for a concrete job or legacy session.
$router->get ('/api/council/jobs/{id}/timeline',                fn(array $p): string => $jobsCtrl->timeline($p));
$router->get ('/api/council/session/{id}/job-timeline',         fn(array $p): string => $jobsCtrl->sessionTimeline($p));
// v1.8.42 — server-side export of the durable audit timeline. The export
// endpoints share the v1.8.41 filter taxonomy (category / event_type /
// q / include_context) so curl, the CLI and external integrations can
// pull the same JSON or RFC-4180 CSV bundle the History UI downloads.
$router->get ('/api/council/jobs/{id}/timeline/export',         fn(array $p): string => $jobsCtrl->exportJobTimeline($p));
$router->get ('/api/council/session/{id}/job-timeline/export', fn(array $p): string => $jobsCtrl->exportSessionTimeline($p));
$router->get ('/api/council/session/{id}/events/stream',        fn(array $p): string => $jobsCtrl->eventsStream($p));

// -- External REST API v1 --------------------------------------------------
// POST /v1/runs starts a consensus run behind an API key (rv_*).
// Three modes: stream=true (SSE inside this request), async=true
// (default: 202 + polling URLs), async=false (synchronous).
$router->post('/v1/runs', fn(): string => $apiV1->createRun());
// Read endpoints for agents (Step 5): poll run state, fetch events,
// collect the result, cancel, discover models and query usage.
$router->get ('/v1/runs',              fn(): string => $apiV1->listRuns());
$router->get ('/v1/runs/{id}',         fn(array $p): string => $apiV1->getRun($p));
$router->get ('/v1/runs/{id}/events',  fn(array $p): string => $apiV1->getRunEvents($p));
$router->get ('/v1/runs/{id}/result',  fn(array $p): string => $apiV1->getRunResult($p));
$router->post('/v1/runs/{id}/cancel',  fn(array $p): string => $apiV1->cancelRun($p));
$router->get ('/v1/models',            fn(): string => $apiV1->models());
$router->get ('/v1/usage',             fn(): string => $apiV1->usage());

// List bundled consensus skills (the skill JSONs in src/Skills/definitions/
// whose tags include "consensus"). Drives the main page's "Consensus skill"
// dropdown so the user can pick between the lexical default and the
// semantic-component scoring backend without touching the admin Skills UI.
//
// v1.8.61 — each entry also carries the models the skill is BOUND to, so
// choosing a skill in that dropdown can switch the Chat sidebar's pool to the
// council that will really answer (see applyConsensusSkillBinding() in
// app.js). The link lives on the preset side (`domain_presets` rows carry
// `definition_json.skill_slug`) and both sides are matched by exact slug
// equality and nothing else: a skill no preset names gets `preset: null`,
// `models: []` and `uses_user_pool: true`, never another preset's models. See
// {@see \App\Skills\ConsensusSkillCatalog}. The payload is provider/model ids
// that `/api/connectors` already publishes, which is why the route stays
// public.
$router->get('/api/consensus/skills', static function () use ($presetsRepo, $skillRepo): string {
    $catalog = new \App\Skills\ConsensusSkillCatalog(__DIR__ . '/src/Skills/definitions');
    // v1.9.x (R-15) — the pool a skill with no preset of its own falls back
    // to. The catalog keeps answering `uses_user_pool: true` / `models: []`
    // for such a skill (it does not invent a binding), but the left-hand panel
    // still has to show a predictable pool: that pool is the Default preset.
    // It is published here as a sibling key, never inside a skill's `models`,
    // which stays reserved for the skill's OWN preset.
    $presets = $presetsRepo->all();
    // v1.9.x - a skill kept through the draft flow lives in the database.
    // The engine resolves it already; this makes it choosable.
    $skillList = \App\Skills\ConsensusSkillCatalog::mergeUserSkills(
        $catalog->list($presetsRepo->all()),
        $skillRepo->all(),
    );
    return \App\Http\Response::json([
        'consensus_skills' => $skillList,
        'default_pool'     => $catalog->defaultPool($presets),
        'default'          => 'semantic_consensus_reasoning',
    ]);
});

$router->get ('/api/skills',       fn(): string => $skillCtrl->index());
$router->post('/api/skills',       fn(): string => $skillCtrl->create());
// The literal path MUST come before /api/skills/{id}: registered after it,
// 'generator' is read as an id and this route never runs.
$router->get ('/api/skills/generator', fn(): string => $skillDraftsCtrl->generatorSettings());
$router->post('/api/skills/generator', fn(): string => $skillDraftsCtrl->saveGenerator());
$router->get ('/api/skills/selector',  fn(): string => $skillDraftsCtrl->selectorSettings());
$router->post('/api/skills/selector',  fn(): string => $skillDraftsCtrl->saveSelector());
    // arXiv research: search only, or search and analyse (item 9).
    $router->get ('/api/research/settings', fn(): string => $researchCtrl->settings());
    $router->post('/api/research/settings', fn(): string => $researchCtrl->saveSettings());
    $router->post('/api/research/search',   fn(): string => $researchCtrl->search());
    $router->post('/api/research/analyse',  fn(): string => $researchCtrl->analyse());
$router->get ('/api/research/history',  fn(): string => $researchCtrl->history());
$router->get ('/api/research/run/{id}', fn(array $p): string => $researchCtrl->run((int)($p['id'] ?? 0)));
$router->post('/api/research/run/delete',    fn(): string => $researchCtrl->deleteRun());
$router->post('/api/research/history/clear', fn(): string => $researchCtrl->clearHistory());
$router->post('/api/research/plan/status',   fn(): string => $researchCtrl->planStatus());
$router->post('/api/research/plan/check',    fn(): string => $researchCtrl->planCheck());
$router->get ('/api/skills/{id}',  fn(array $p): string => $skillCtrl->show($p));
$router->delete('/api/skills/{id}',fn(array $p): string => $skillCtrl->delete($p));

$router->get('/api/connectors', static function () use ($connectors): string {
    $out = [];
    $available = [];
    foreach ($connectors->all() as $c) {
        $name = $c->getProviderName();
        $models = activeModelsFor($name);
        $out[] = ['provider' => $name, 'models' => $models];
        foreach ($models as $m) {
            $available[] = ['provider' => $name, 'model' => $m];
        }
    }
    // Filter the default selection down to whatever is currently
    // available so the UI never preselects a model whose connector is
    // missing or whose entry was removed from config.models.
    $availableSet = [];
    foreach ($available as $row) {
        $availableSet[strtolower($row['provider'] . '|' . $row['model'])] = true;
    }
    $defaults = (array)Config::get('pipeline.default_selected_models', []);
    $defaultSelected = [];
    foreach ($defaults as $entry) {
        if (!is_array($entry)) { continue; }
        $p = strtolower((string)($entry['provider'] ?? ''));
        $m = (string)($entry['model'] ?? '');
        if ($p === '' || $m === '') { continue; }
        // A batch-only id is served by the asynchronous Batch API, so it can
        // never be a pool member: L1 dispatches synchronously. It stays in
        // `available_models` (the sidebar marks it and refuses to pick it),
        // but it is never seeded into the pool.
        if (ModelId::isBatchId($m)) { continue; }
        if (isset($availableSet[$p . '|' . $m])) {
            $defaultSelected[] = ['provider' => $p, 'model' => $m];
        }
    }
    // Backstop: if every preferred default is missing, fall back to a
    // maximally-different spread of the available models so the user gets a
    // working pool that is not dominated by one model family. The spread is
    // drawn from the synchronously callable ids only — the same reason.
    if ($defaultSelected === [] && $available !== []) {
        $callable = array_values(array_filter(
            $available,
            static fn(array $row): bool => !ModelId::isBatchId((string)$row['model']),
        ));
        $defaultSelected = pickDiverseModels($callable, 3);
    }
    $reasoningLevels = [];
    $cfgLevels = (array)Config::get('reasoning.levels', []);
    foreach (array_keys($cfgLevels) as $key) {
        $reasoningLevels[] = (string)$key;
    }
    return Response::json([
        'connectors'        => $out,
        'available_models'  => $available,
        'default_selected'  => $defaultSelected,
        'reasoning_levels'  => $reasoningLevels,
    ]);
});
// Health probe. The base envelope keeps the legacy `status`/`version`
// fields so existing monitors are not disturbed; an `admin` block reports
// whether the admin token is wired up (without exposing the token), and a
// `database` block reports configured/connected/schema_ready + the list
// of missing tables — all sanitized via DatabaseHealth so it is safe to
// expose publicly. `/api/health/database` returns the same `database`
// block on its own for callers who only want the DB status.
$router->get('/api/health', static fn(): string => Response::json([
    'status'         => 'ok',
    'version'        => Config::get('app.version'),
    'admin'          => AdminAuth::diagnostics(),
    'database'       => DatabaseHealth::snapshot($db ?? $pdo),
    // Config-source diagnostic block: per-file existence/loaded/error/mtime/size
    // for every dot-file the overlay layer inspects (.config.php, .url,
    // .sql.php, .admin.key) plus the resolved root basename. Surfacing
    // this on /api/health lets operators see at a glance why a
    // deployment file did or did not take effect — no values, only
    // metadata, so it is safe to expose publicly.
    'config_sources' => Config::get('app.diagnostics.config_sources', []),
    'root'           => Config::get('app.diagnostics.root', null),
    // v1.8.38 - worker diagnostics: counts by status, latest heartbeat,
    // running workers, oldest queued age, and the derived worker_status
    // classification with actionable recommendations.
    'worker'         => $workerHealth->snapshot(),
]));
$router->get('/api/health/database', static fn(): string => Response::json(
    DatabaseHealth::snapshot($db ?? $pdo)
));
$router->get('/api/health/admin', static fn(): string => Response::json(
    AdminAuth::diagnostics()
));
// R7 - System health panel: every check with its explanation and the
// action that answers it, plus the first-run checklist. Public, like the
// rest of /api/health/*, because a brand-new install must be able to read
// why it is not working before an admin key exists; the repairs it
// offers stay admin-gated where they always lived.
$router->get('/api/health/system', fn(): string => $systemHealthCtrl->index());
// v1.8.38 — Worker Status diagnostic. Same JSON envelope the worker's
// `--status` flag prints. `?stale_after=<sec>` overrides the heartbeat
// threshold for a single snapshot (clamped inside the service).
$router->get('/api/health/worker', static function () use ($workerHealth): string {
    $threshold = isset($_GET['stale_after']) && is_numeric($_GET['stale_after'])
        ? (int)$_GET['stale_after']
        : null;
    return Response::json($workerHealth->snapshot($threshold));
});
// v1.8.45 — public feeds for the header worker-status indicator. The
// first lists the ≤10 jobs in flight / recently finished (ids, statuses
// and timings only — never the query text, a session title, a user id
// or a result); the second serves the last self-test verdict with the
// connector identities masked, plus the problems derivable from the
// public snapshot above. Both keep working when Settings is disabled.
$router->get('/api/health/worker/jobs',        fn(): string => $workerFeedCtrl->jobs());
$router->get('/api/health/worker/diagnostics', fn(): string => $workerFeedCtrl->diagnostics());

// Tiny inline SVG favicon. Served with long max-age and ETag so browsers
// cache it after the first request. The bytes are intentionally trivial
// to keep the binary size small and avoid shipping any external asset.
$router->get('/favicon.ico',  static fn(): string => serveFavicon());
$router->get('/favicon.svg',  static fn(): string => serveFavicon());

// --- Settings (admin) ----------------------------------------------------
// Read-only public probe so the front-end can decide whether to render
// the Settings tab. Returns whether the admin token is configured and
// whether this browser is currently unlocked. Never exposes secrets.
$router->get ('/api/settings/status',         fn(): string => $settingsCtrl->status());
$router->post('/api/settings/unlock',         fn(): string => $settingsCtrl->unlock());
$router->post('/api/settings/logout',         fn(): string => $settingsCtrl->logout());
$router->get ('/api/settings',                fn(): string => $settingsCtrl->get());
$router->post('/api/settings/providers',      fn(): string => $settingsCtrl->saveProviders());
$router->post('/api/settings/providers/test', fn(): string => $settingsCtrl->testProvider());
$router->post('/api/settings/models/import',  fn(): string => $settingsCtrl->importModels());
$router->post('/api/settings/models',         fn(): string => $settingsCtrl->saveModels());
$router->post('/api/settings/database',       fn(): string => $settingsCtrl->saveDatabase());
$router->post('/api/settings/database/test',  fn(): string => $settingsCtrl->testDatabase());
$router->get ('/api/settings/migrations',     fn(): string => $settingsCtrl->listMigrations($pdo));
$router->post('/api/settings/migrations/apply',    fn(): string => $settingsCtrl->applyMigrations($pdo));
// v1.8.27: per-file apply endpoint. Body: {"name":"<file>.sql"}.
// Filename whitelisted against db/migrations/*.sql inside the manager;
// no path traversal possible. Always returns 200 with a structured
// body so the new per-row "Apply" UI button can render success /
// skipped / error without a 500-page intermission.
$router->post('/api/settings/migrations/apply-one', fn(): string => $settingsCtrl->applyMigrationOne($pdo));
$router->post('/api/settings/migrations/baseline', fn(): string => $settingsCtrl->baselineMigrations($pdo));
// v1.8.27: public version-status endpoint feeding the top banner.
// Returns no secrets and never raises a hard error so the banner can
// always decide whether to render. The destructive endpoints behind
// it stay admin-gated.
$router->get ('/api/settings/version-status', fn(): string => $settingsCtrl->versionStatus($pdo));
// v1.8.25: DB maintenance — check structure against db/schema.sql,
// recreate every app-defined table from db/schema.sql. The recreate
// endpoint is destructive and requires {"confirm":"RECREATE"} in the
// JSON body; both endpoints reuse the admin-token gate the rest of
// /api/settings/* uses.
$router->get ('/api/settings/database/check',     fn(): string => $settingsCtrl->checkDatabase($pdo));
$router->post('/api/settings/database/check',     fn(): string => $settingsCtrl->checkDatabase($pdo));
$router->post('/api/settings/database/recreate',  fn(): string => $settingsCtrl->recreateDatabase($pdo));
$router->get ('/api/settings/export',         fn(): string => $settingsCtrl->exportSchema($pdo));
// v1.8.47 — Settings → Cache. `cache` is a read-only snapshot of both
// cache backends (entry counts, bytes, oldest/newest, hit counter, top
// provider/model rows); `cache/purge` performs the same bulk
// invalidation as `php bin/cache-purge.php --yes` and requires
// {"confirm":"PURGE"} plus an optional {"older_than_days":N} (0 = all).
// Both reuse the admin-token gate the rest of /api/settings/* uses.
$router->get ('/api/settings/cache',          fn(): string => $settingsCtrl->cacheStats());
$router->post('/api/settings/cache/purge',    fn(): string => $settingsCtrl->purgeCache());
$router->post('/api/settings/runtime',        fn(): string => $settingsCtrl->saveRuntime());
// v1.8.45 — Settings → Prompt caching. Same admin gate and write path as
// `/api/settings/runtime`. The card also renders the documented capability
// table, which is read back through `GET /api/settings`
// (`runtime.prompt_cache.capabilities`) so it can never drift from the
// rules the request builder applies.
$router->post('/api/settings/prompt-cache',   fn(): string => $settingsCtrl->savePromptCache());
// v1.8.33: interface preferences — default theme. PUT-style save, so
// POST: the route used to be registered as GET while both the client
// (assets/js/settings.js) and the controller docblock called it with
// POST, which made every save a 404. There is no GET counterpart —
// the read path is `GET /api/settings`, which embeds the `interface`
// block via SettingsController::uiPreferences().
$router->post('/api/settings/interface',      fn(): string => $settingsCtrl->saveInterface());
// v1.8.44 — Settings → Worker card. `test` enqueues the token-free
// self-test job behind the same admin gate as the other mutating
// /api/settings/* routes; `debug` returns the operator diagnostic bundle
// and is gated on admin OR a whitelisted client IP (403 otherwise, with
// both remedies spelled out in the body).
$router->post('/api/worker/restart',            fn(): string => $workerCtrl->restart());
$router->post('/api/settings/worker/test',    fn(): string => $workerCtrl->test());
$router->get ('/api/settings/worker/debug',   fn(): string => $workerCtrl->debug());

// --- Profile (admin) -----------------------------------------------------
// Settings → Profile tab. v1.8.32. The Profile feature reuses the
// Settings admin gate (SettingsTab + admin token) so a single auth
// configuration covers both surfaces.
$router->get ('/api/profile',                 fn(): string => $profileCtrl->get());
$router->post('/api/profile',                 fn(): string => $profileCtrl->save());

// --- Domain model presets (admin) ----------------------------------------
// Settings → Domain Models tab. v1.8.32. Domain → model pool mapping for
// the auto-routing layer (Default / Mathematics / Physics + future).
$router->get ('/api/domain-presets',                 fn(): string => $presetsCtrl->index());
    // v1.9.x - export/import of the preset set (item 12). Registered before the
    // {slug} route so the literal path is not read as a preset named "export".
    $router->get ('/api/domain-presets/export',        fn(): string => $presetsCtrl->export());
    $router->post('/api/domain-presets/import',        fn(): string => $presetsCtrl->import());
$router->get ('/api/domain-presets/{slug}',          fn(array $p): string => $presetsCtrl->show($p));
$router->post('/api/domain-presets',                 fn(): string => $presetsCtrl->save());
$router->post('/api/domain-presets/{slug}/select',   fn(array $p): string => $presetsCtrl->select($p));

// --- Models tab: actualization (admin) -----------------------------------
// v1.8.49. `status` is the public tab probe (ModelsTab flag + admin state,
// no secrets) that reveals the tab button and drives the shared lock
// screen. `actualize` enqueues the worker job (payload.kind =
// models_actualize) and is idempotent — while a run is queued or in flight
// it returns that job instead of creating a second. `actualize/status` is
// what the tab polls for the four stages; `actualize/rollback` restores
// the pre-run snapshot (catalogue + presets + model pool).
// `actualize/settings` carries the section's own analyzer model
// (`models.actualize.analyzer`), which is deliberately NOT the Request
// Analyzer's setting: GET names both values, POST sets or clears it.
$router->get ('/api/models/status',              fn(): string => $modelsCtrl->status());
$router->post('/api/models/actualize',           fn(): string => $modelsCtrl->actualize());
$router->get ('/api/models/actualize/status',    fn(): string => $modelsCtrl->actualizeStatus());
$router->get ('/api/models/actualize/settings',  fn(): string => $modelsCtrl->actualizeSettings());
$router->post('/api/models/actualize/settings',  fn(): string => $modelsCtrl->saveActualizeSettings());
$router->post('/api/models/actualize/rollback',  fn(): string => $modelsCtrl->rollback());
$router->get ('/api/models/usefulness',          fn(): string => $usefulnessCtrl->report());

// --- Request analyzer (admin) --------------------------------------------
// Optional analyzer stage. v1.8.32. Toggles the pre-pipeline analyzer
// and exposes a classify probe useful for the auto-routing UI.
$router->get ('/api/analyzer/settings',  fn(): string => $analyzerCtrl->settings());
$router->post('/api/analyzer/settings',  fn(): string => $analyzerCtrl->saveSettings());
$router->post('/api/analyzer/classify',  fn(): string => $analyzerCtrl->classify());

// --- Logs (admin) --------------------------------------------------------
// Read-only public probe for the front-end. Returns whether the LogsTab
// flag is set in config.php and the admin state. The tail/list/clear
// endpoints are admin-protected AND additionally gated by LogsTab=true.
$router->get ('/api/logs/status', fn(): string => $logsCtrl->status());
$router->get ('/api/logs/list',   fn(): string => $logsCtrl->list());
$router->get ('/api/logs/tail',   fn(): string => $logsCtrl->tail());
$router->post('/api/logs/clear',  fn(): string => $logsCtrl->clear());

// --- Extensions (admin) ---------------------------------------------------
// Installs/updates the third-party MD.Viewer extension from GitHub into
// extensions/md-viewer/. The public probe (status) drives tab visibility and
// reports the local install state; the reachability probe inside it only runs
// for an unlocked admin. download/check/install require the admin token AND
// ExtensionsTab=true. The installer's API_KEY is read and written server-side
// only - it never reaches the browser; the settings endpoints answer with
// 'key set / not set' plus a mask. The subprocess that runs updater.php is
// started with a probed PHP CLI binary, not PHP_BINARY: under PHP-FPM that
// constant is php-fpm itself (it printed its usage and exited 64, so the
// installer never ran). See src/Extensions/PhpCliLocator.php.
$router->get ('/api/extensions/status',   fn(): string => $extensionsCtrl->status());
$router->post('/api/extensions/download', fn(): string => $extensionsCtrl->download());
$router->get ('/api/extensions/check',    fn(): string => $extensionsCtrl->check());
$router->post('/api/extensions/install',  fn(): string => $extensionsCtrl->install());
$router->get ('/api/extensions/settings',   fn(): string => $extensionsCtrl->settings());
$router->post('/api/extensions/settings',   fn(): string => $extensionsCtrl->saveSettings());
$router->post('/api/extensions/rotate-key', fn(): string => $extensionsCtrl->rotateKey());

// --- Webhooks (admin) -----------------------------------------------------
// Public probe so the API tab can decide whether to render the webhook
// management block. Protected endpoints require the admin token (the
// Settings gate doubles as the management surface gate, mirroring the
// Profile endpoints). Secrets are masked in listings and revealed only
// once on create/rotate.
$router->get   ('/api/webhooks/status',               fn(): string => $webhookCtrl->status());
$router->get   ('/api/webhooks',                      fn(): string => $webhookCtrl->index());
$router->post  ('/api/webhooks',                      fn(): string => $webhookCtrl->create());
$router->get   ('/api/webhooks/{id}',                 fn(array $p): string => $webhookCtrl->show($p));
$router->post  ('/api/webhooks/{id}',                 fn(array $p): string => $webhookCtrl->update($p));
$router->post  ('/api/webhooks/{id}/toggle',          fn(array $p): string => $webhookCtrl->toggle($p));
$router->delete('/api/webhooks/{id}',                 fn(array $p): string => $webhookCtrl->destroy($p));
$router->get   ('/api/webhooks/{id}/deliveries',      fn(array $p): string => $webhookCtrl->deliveries($p));

// --- API keys (admin, "API" tab) -----------------------------------------
// Public probe so the front-end can decide whether to render the API tab;
// every listing/mutation below requires BOTH the admin token AND
// ApiTab=true. The plaintext rv_* key appears exactly once per
// create/rotate response - listings never carry key material.
$router->get  ('/api/apikeys/status',        fn(): string => $apiKeyCtrl->status());
$router->get  ('/api/apikeys',               fn(): string => $apiKeyCtrl->index());
$router->post ('/api/apikeys',               fn(): string => $apiKeyCtrl->create());
$router->post ('/api/apikeys/{id}/rotate',   fn(array $p): string => $apiKeyCtrl->rotate($p));
$router->post ('/api/apikeys/{id}/revoke',   fn(array $p): string => $apiKeyCtrl->revoke($p));

// --- Security (admin) ----------------------------------------------------
// Public probe so the front-end can decide whether to render the Security
// tab. Protected endpoints require BOTH the admin token AND
// SecurityTab=true; otherwise they return 401 / 404 security_disabled.
$router->get ('/api/security/status',  fn(): string => $securityCtrl->status());
$router->post('/api/security/save',    fn(): string => $securityCtrl->save());
$router->post('/api/security/test-ip', fn(): string => $securityCtrl->testIp());
$router->post('/api/security/reset',   fn(): string => $securityCtrl->reset());

// --- History (admin) -----------------------------------------------------
// Public probe so the front-end can decide whether to render the History
// tab. Protected endpoints require BOTH the admin token AND
// HistoryTab=true; otherwise they return 401 / 404 history_disabled.
$router->get('/api/history/status',         fn(): string => $historyCtrl->status());
$router->get('/api/history/list',           fn(): string => $historyCtrl->list());
$router->get('/api/history/session/{id}',   fn(array $p): string => $historyCtrl->session($p));
// Finish an interrupted session: rebuild only the pipeline stages that
// never produced a result, reuse everything already persisted, then mark
// the session completed. Mutating POST — gated exactly like the rest of
// the History tab (HistoryTab flag + database/migration probes).
// `?dry_run=1` returns the plan without touching the database;
// `?force=1` overrides the "another worker still owns this session" guard.
$router->post('/api/history/session/{id}/complete', fn(array $p): string => $historyCtrl->complete($p));
// R8 - write the run out to share/ as Markdown plus a print-ready page
// (the page is how a PDF is produced here: open it and print). Same gates
// as the rest of the History tab; the file names come from the session id
// and its timestamp, never from run text.
$router->post('/api/history/session/{id}/export',   fn(array $p): string => $historyCtrl->export($p));

// --- Share (one document) ------------------------------------------------
// The Share button on a copyable block posts that block's topic and its
// Markdown here. The document lands in share/ next to index.php, and the
// answer carries the link to it: through MD.Viewer when that extension is
// installed, the plain file URL when it is not. Operator-owned - it writes
// to disk, so ApiAuth::isAdminPath() keeps external API keys off it.
$router->post('/api/share/document', fn(): string => $documentShareCtrl->create());
// The Skills tab: one skill's full description as a share document,
// opened in a new window (through MD.Viewer when it is installed).
$router->post('/api/consensus/skills/{slug}/document', fn(array $p): string => $skillDocsCtrl->document($p));
    // Draft a skill, review it, keep it (item 4): the draft writes nothing.
    $router->post('/api/skills/draft',         fn(): string => $skillDraftsCtrl->draft());
    $router->post('/api/skills/draft/confirm', fn(): string => $skillDraftsCtrl->confirm());
// GET is a wrong verb, not a missing route: answer 405 (Allow: POST).
$router->get ('/api/share/document', fn(): string => $documentShareCtrl->methodNotAllowed());

// --- Share (public, by-token) -------------------------------------------
// Public, read-only by possession of an opaque high-entropy token. The
// shared view never exposes model selection, settings, logs, security,
// admin controls, or the ability to run pipelines. Tokens are persisted
// in shared_sessions (migration 006) — random hex, not derived from
// session id.
$router->post('/api/share/session',          fn(): string => $shareCtrl->create());
// v1.8.24: GET /api/share/session with no token returns 405 method_not_allowed
// (Allow: POST) so the wrong verb gets an actionable diagnostic instead of a
// generic 404. The token-bearing GET below is unaffected.
$router->get ('/api/share/session',          fn(): string => $shareCtrl->methodNotAllowed());
$router->get ('/api/share/session/{token}',  fn(array $p): string => $shareCtrl->json($p));
$router->get ('/share/{token}',              fn(array $p): string => $shareCtrl->view($p));
$router->get ('/shared',                     fn(): string => $shareCtrl->view([]));

// --- Statistic (admin) ---------------------------------------------------
// Public probe so the front-end can decide whether to render the Statistic
// tab. Protected endpoints require BOTH the admin token AND
// StatisticTab=true; otherwise they return 401 / 404 statistics_disabled.
$router->get('/api/statistics/status',  fn(): string => $statisticsCtrl->status());
$router->get('/api/statistics/summary', fn(): string => $statisticsCtrl->summary());

// Aggregated tab visibility probe. Front-end uses it once at boot to
// decide which top-nav tabs to render. The `tabs` map drives navigation
// visibility (true => button is shown). Admin authentication state is
// surfaced alongside so the UI can render the correct empty state for
// each protected panel without having to call every per-tab status
// endpoint up-front. Backwards-compat: the old flat keys remain.
$router->get('/api/tabs', static fn(): string => Response::json([
    'tabs' => [
        'models'    => Config::isModelsTabEnabled(),
        'settings'  => Config::isSettingsTabEnabled(),
        'logs'      => Config::isLogsTabEnabled(),
        'security'  => Config::isSecurityTabEnabled(),
        'history'   => Config::isHistoryTabEnabled(),
        'statistic' => Config::isStatisticTabEnabled(),
        'api'       => Config::isApiTabEnabled(),
        'extensions' => Config::isExtensionsTabEnabled(),
    ],
    // v1.8.60 — `admin_protection` is the raw
    // `Protect_Settings_by_Admin_Key` flag (true = a key is required), and
    // `admin_unlocked` is the verdict the UI acts on: it is true whenever
    // the protection is off, so every lock screen and every "token
    // required" banner disappears on an install that asks for no key —
    // without a second decision anywhere in the front end.
    'admin_protection'  => \App\Core\AdminAuth::protectionEnabled(),
    'admin_configured'  => \App\Core\AdminAuth::isConfigured(),
    'admin_unlocked'    => \App\Core\AdminAuth::gateState()['unlocked'],
    'admin_diagnostics' => \App\Core\AdminAuth::diagnostics(),
    'models'    => Config::isModelsTabEnabled(),
    'settings'  => Config::isSettingsTabEnabled(),
    'logs'      => Config::isLogsTabEnabled(),
    'security'  => Config::isSecurityTabEnabled(),
    'history'   => Config::isHistoryTabEnabled(),
    'statistic' => Config::isStatisticTabEnabled(),
    'api'       => Config::isApiTabEnabled(),
    'extensions' => Config::isExtensionsTabEnabled(),
]));

// Lightweight diagnostic endpoint. Surfaces the runtime state an operator
// needs to debug "synthesis LLM unavailable" / 404 polling / connector
// configuration issues. Intentionally never exposes API keys. When
// app.debug=true the call also writes a single sanitized line to
// revoap-debug.log so operators can confirm the logger is wired up.
$router->get('/api/debug', static function () use ($connectors, $pdo, $resolvedPath, $responseCache): string {
    \App\Core\Logger::info('debug.report.requested', [
        'path'         => $resolvedPath,
        'storage_mode' => $pdo === null ? 'memory' : 'persistent',
        'connectors'   => count($connectors->all()),
    ]);
    return Response::json(Diagnostics::buildReport($connectors, $pdo, $resolvedPath, $responseCache));
});

// v1.8.22: tiny boot-config endpoint. The frontend logger reads this
// once at startup to decide whether to emit structured console logs.
// Only non-sensitive flags are surfaced (version, build id, the
// `log_to_console` flag and the resolved app.debug bool). No secrets,
// no model lists, no connector keys.
$router->get('/api/boot-config', static fn(): string => Response::json([
    'app' => [
        'name'           => (string)Config::get('app.name', 'RevoAp'),
        'version'        => (string)Config::get('app.version', '0.0.0'),
        'build_id'       => buildId(),
        'debug'          => Config::isDebug(),
        'log_to_console' => Config::isLogToConsoleEnabled(),
    ],
    // v1.8.37: surface the pipeline execution mode + worker warning timeout
    // so assets/js/app.js can route the Run button (and Pause/Continue/Reject/
    // Stop-and-Run-consensus buttons) to /api/council/jobs (job-mode, default)
    // versus the legacy /api/council/stream endpoint. Both endpoints stay
    // wired server-side; only the default UI path changes.
    'pipeline' => [
        'execution_mode'             => Config::pipelineExecutionMode(),
        'job_worker_warning_seconds' => Config::jobWorkerWarningSeconds(),

        // v1.9.1 - the arbiter the run will use unless its council preset
        // appoints another one, so the card under the question can name it
        // before L0_OPTIONS arrives. No secrets: a provider slug and a model id.
        'conflict_arbiter' => (Config::get('pipeline.conflict_arbiter.enabled', true) === false
            || (string)Config::get('pipeline.conflict_arbiter.model', '') === '')
            ? null
            : [
                'provider' => (string)Config::get('pipeline.conflict_arbiter.provider', ''),
                'model'    => (string)Config::get('pipeline.conflict_arbiter.model', ''),
                'role'     => 'conflict',
            ],
    ],
    // Batch API availability for the Chat toggle. `ready` is a capability
    // report, never a verdict about a model: it is true when the provider
    // connector is keyed and ext-curl exists, which is what a submitted
    // batch needs. A false here disables the toggle with the reason shown —
    // it does NOT mean any model lacks batch support (that question is
    // answered per id by /api/council/batch/models, and an unanswered
    // question is reported as "unknown", never as "unsupported").
    // R10 - the economy mode the interface shows and honours. Non-sensitive: a
    // level name and its thresholds; the advice itself is computed on request.
    'economy' => [
        'mode'               => \App\Cost\EconomyMode::fromConfig()->level(),
        'label'              => \App\Cost\EconomyMode::fromConfig()->label(),
        'cache_min_calls'    => \App\Cost\EconomyMode::minCacheCalls(),
        'cache_min_hit_rate' => \App\Cost\EconomyMode::minCacheHitRate(),
        'batch_min_seconds'  => \App\Cost\EconomyMode::minBatchSeconds(),
    ],
    'batch' => BatchCapability::fromEnvironment($connectors)->toArray(),
    // v1.8.60 — the admin gate's state, so the UI can decide between the
    // lock screen and the panel at boot time, and can say out loud when the
    // operator surface is NOT protected by a key
    // (`Protect_Settings_by_Admin_Key = 0`). Non-sensitive: a boolean, not
    // the token and not whether one exists.
    'admin' => [
        'protection' => \App\Core\AdminAuth::protectionEnabled(),
        'configured' => \App\Core\AdminAuth::isConfigured(),
        'unlocked'   => \App\Core\AdminAuth::gateState()['unlocked'],
        'key'        => \App\Core\AdminAuth::PROTECT_KEY,
    ],
]));

// Batch API capability for the Models tab / Chat toggle: the ids the
// provider itself lists as supporting background processing
// (`GET /batches/models`), plus the local readiness report. `supported`
// is only ever populated from a successful provider answer; a failed call
// yields `resolved: false` and the UI says "unknown", because marking a
// model unsupported on the strength of a dropped connection is how an
// operator ends up "fixing" something that was never broken.
$router->get('/api/council/batch/models', static function () use ($connectors): string {
    $capability = BatchCapability::fromEnvironment($connectors);
    if (!$capability->ready()) {
        return Response::json([
            'ok'         => false,
            'error'      => 'batch_unavailable',
            'message'    => $capability->reason(),
            'capability' => $capability->toArray(),
        ], 503);
    }
    $catalog = $capability->client()->listBatchModels();
    return Response::json([
        'ok'         => true,
        'resolved'   => $catalog->isResolved(),
        'detail'     => $catalog->detail(),
        'count'      => $catalog->count(),
        'models'     => $catalog->ids(),
        'capability' => $capability->toArray(),
        'note'       => $catalog->isResolved()
            ? 'The provider listed these ids as supporting background processing.'
            : 'The provider could not be asked, so batch support is unknown for every model. '
                . 'This is not a statement that any model lacks support.',
    ]);
});

$path = $resolvedPath;

// Static assets passthrough — when Apache rewrite isn't active.
if (str_starts_with($path, '/assets/')) {
    serveAsset($path);
    return;
}

// v1.9.x - a state-changing request from another site is refused before any
// route reads or writes anything. Same rule as MD.Viewer v3.11.0: see
// App\Http\OriginGuard for why neither header means "allowed".
$originViolation = \App\Http\OriginGuard::violation(
    (string)($_SERVER['REQUEST_METHOD'] ?? 'GET'),
    $path,
    $_SERVER,
);
if ($originViolation !== null) {
    \App\Core\Logger::warn('http.origin_refused', [
        'path'   => $path,
        'method' => (string)($_SERVER['REQUEST_METHOD'] ?? 'GET'),
        'reason' => $originViolation,
    ]);
    // Response::json() sets the status itself; passing it is what makes the
    // refusal a 403 rather than a 200 carrying an error body.
    echo Response::json([
        'ok'      => false,
        'error'   => 'forbidden_origin',
        'message' => 'Refused: ' . $originViolation . '.',
    ], 403);
    return;
}

try {
    $result = $router->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', $path);
} catch (\Throwable $e) {
    // v1.8.22: any uncaught exception from a route handler must NOT
    // bleed through as an HTML error page. /api/* routes are consumed
    // by `fetch().then(r => r.json())` and any HTML body produces the
    // dreaded "Unexpected token '<'" parser error in the chat status.
    \App\Core\Logger::error('router.dispatch.failed', [
        'path'    => $path,
        'class'   => (new \ReflectionClass($e))->getShortName(),
        'message' => \App\Core\Logger::sanitizeScalar($e->getMessage()),
    ]);
    if (str_starts_with($path, '/api/')) {
        http_response_code(500);
        echo Response::json([
            'error'   => 'internal_error',
            'message' => \App\Core\Logger::sanitizeScalar($e->getMessage()),
            'path'    => $path,
            'class'   => (new \ReflectionClass($e))->getShortName(),
        ], 500);
        return;
    }
    // Non-API path: surface HTML so the operator sees the error in the
    // browser the same way they always did.
    throw $e;
}
if ($result === null) {
    http_response_code(404);
    // v1.8.22: `/api/...` route misses must return JSON so that callers
    // doing `res.json()` never receive HTML and trip the "Unexpected
    // token '<', '<!DOCTYPE ...'" parse error.
    if (str_starts_with($path, '/api/')) {
        echo Response::json([
            'error'  => 'not_found',
            'path'   => $path,
            'method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
        ], 404);
        return;
    }
    echo Response::json(['error' => 'not found', 'path' => $path], 404);
    return;
}
echo $result;

/**
 * Render the SPA shell with the embedded chat and help sections.
 *
 * @version 1.1.0
 */
function renderHome(ConnectorRegistry $connectors): string
{
    global $pdo;
    $chat = TemplateHelper::render('chat.html');
    // v1.8.48: the Help panel is static markup rendered from its own
    // template and injected into the shell the same way the chat section
    // is. It is read-only and public by design — no admin token, no fetch,
    // no client-side rendering — so anyone who can open the app can read
    // it. The version and build id it prints are the same values the shell
    // bakes into <meta>, so the panel can never disagree with the header.
    $help = TemplateHelper::render('help.html', [
        'app_name'    => Config::get('app.name', 'RevoAp'),
        'app_version' => Config::get('app.version', '1.0.0'),
        'build_id'    => buildId(),
    ]);
    // v1.8.33: resolve the operator-configured default theme from the
    // `app_config` table and bake it into the SPA shell so the very
    // first paint already reflects it — no theme flicker on reload.
    // (The layout-width preference that shipped with it was retired:
    // the content column follows the window, so there is nothing to
    // bake in.)
    $ui = ['theme' => 'system'];
    if ($pdo instanceof PDO) {
        try { $ui = (new \App\Settings\ConfigRepository($pdo))->getUiPreferences(); }
        catch (\Throwable) { /* leave defaults */ }
    }
    $body = TemplateHelper::render('layout.html', [
        'title'          => Config::get('app.name', 'RevoAp'),
        'app_name'       => Config::get('app.name', 'RevoAp'),
        'app_version'    => Config::get('app.version', '1.0.0'),
        'asset_version'  => assetVersion(),
        'build_id'       => buildId(),
        'chat_section'   => $chat,
        'help_section'   => $help,
        // v1.8.22: serialise the runtime LogToConsole flag and app.debug
        // bool so assets/js/logger.js can decide whether to emit console
        // logs without an extra fetch. "true"/"false" so the value is
        // unambiguous in HTML.
        'log_to_console' => Config::isLogToConsoleEnabled() ? 'true' : 'false',
        'app_debug'      => Config::isDebug() ? 'true' : 'false',
        // v1.8.33: default theme.
        'ui_theme'       => $ui['theme'],
        // v1.8.37: pipeline execution mode baked into the first paint so
        // app.js can route the Run button to /api/council/jobs (default)
        // versus the legacy /api/council/stream without an extra fetch
        // round-trip on startup.
        'execution_mode'              => Config::pipelineExecutionMode(),
        'job_worker_warning_seconds'  => (string)Config::jobWorkerWarningSeconds(),
        // v1.8.60 — the admin gate's state, baked into the first paint so an
        // unprotected install (`Protect_Settings_by_Admin_Key = 0`) says so
        // in the header without waiting for a fetch. `admin_protection` is
        // the raw flag; `admin_protection_hidden` is the HTML attribute
        // value, kept here so the template stays free of conditionals.
        'admin_protection'        => \App\Core\AdminAuth::protectionEnabled() ? 'true' : 'false',
        'admin_protection_flag'   => \App\Core\AdminAuth::PROTECT_KEY,
        'admin_protection_hidden' => \App\Core\AdminAuth::protectionEnabled() ? 'hidden' : '',
    ]);
    return Response::html($body);
}

/**
 * Cache-busting token for static assets. Combines the app version with the
 * latest mtime of any file in /assets so that re-deploys (which touch the
 * source files) immediately invalidate browser caches without operators
 * having to bump app.version manually.
 *
 * @version 1.0.0
 */
function assetVersion(): string
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $version = (string)Config::get('app.version', '1.0.0');
    $latest  = 0;
    $base    = __DIR__ . '/assets';
    if (is_dir($base)) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->isFile()) {
                $m = (int)$file->getMTime();
                if ($m > $latest) { $latest = $m; }
            }
        }
    }
    return $cached = $version . '.' . ($latest > 0 ? (string)$latest : '0');
}

/**
 * Short build identifier. Reads HEAD from the working tree's .git/ when
 * available; otherwise falls back to the asset-mtime token. Never reads
 * any file outside the project root and never exposes filesystem paths.
 *
 * @version 1.0.0
 */
function buildId(): string
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $head = __DIR__ . '/.git/HEAD';
    if (is_file($head) && is_readable($head)) {
        $line = trim((string)file_get_contents($head));
        if (str_starts_with($line, 'ref: ')) {
            $ref = trim(substr($line, 5));
            $refFile = __DIR__ . '/.git/' . $ref;
            if (is_file($refFile) && is_readable($refFile)) {
                $sha = trim((string)file_get_contents($refFile));
                if ($sha !== '') {
                    return $cached = substr($sha, 0, 12);
                }
            }
        } elseif (preg_match('/^[0-9a-f]{40}$/i', $line) === 1) {
            return $cached = substr($line, 0, 12);
        }
    }
    return $cached = assetVersion();
}

/**
 * @version 1.0.0
 * @return array<int,string>
 */
function activeModelsFor(string $provider): array
{
    $out = [];
    foreach (Config::getActiveModels() as $m) {
        if ($m['provider'] === $provider) {
            $out[] = $m['model'];
        }
    }
    return $out;
}

/**
 * Tokenise a model id for diversity comparison: lowercase, strip a leading
 * "~" alias marker, keep only the segment after the last "/", then split
 * into [a-z0-9]+ runs. Empty results fall back to ["_"] so every row still
 * has a comparable token set. Mirrors diversityTokens() in
 * assets/js/model-selector.js.
 *
 * @return array<int,string>
 */
function diversityTokens(string $model): array
{
    $model = ltrim(strtolower(trim($model)), '~');
    if (str_contains($model, '/')) {
        $model = substr($model, strrpos($model, '/') + 1);
    }
    if (preg_match_all('/[a-z0-9]+/', $model, $m) === false || $m[0] === []) {
        return ['_'];
    }
    return $m[0];
}

/**
 * Jaccard distance between two token arrays: 1 - (|intersection| / |union|).
 * A guard keeps an empty union (the "_" fallback makes it unreachable) at
 * distance 0.
 *
 * @param array<int,string> $a
 * @param array<int,string> $b
 */
function diversityDistance(array $a, array $b): float
{
    $union = array_unique(array_merge($a, $b));
    if ($union === []) { return 0.0; }
    $bSet = array_fill_keys($b, true);
    $inter = 0;
    foreach ($a as $token) {
        if (isset($bSet[$token])) { $inter++; }
    }
    return 1.0 - ($inter / count($union));
}

/**
 * Farthest-first diversity picker over model ids, mirroring the client-side
 * pickDiverseModels() in assets/js/model-selector.js. Chooses `count` rows
 * whose models are maximally different from each other (Jaccard distance),
 * so the /api/connectors backstop never fills a fresh pool with near-identical
 * variants of one model family. Returns picks in selection order; ties
 * resolve to the earliest original index. `count >= count($rows)` returns
 * every row in original order.
 *
 * @param array<int,array{provider:string,model:string}> $rows
 * @return array<int,array{provider:string,model:string}>
 */
function pickDiverseModels(array $rows, int $count): array
{
    $total = count($rows);
    $n = max(0, $count);
    if ($n <= 0 || $total === 0) { return []; }
    if ($n >= $total) { return array_values($rows); }

    $tokens = [];
    foreach ($rows as $row) {
        $tokens[] = diversityTokens((string)($row['model'] ?? ''));
    }

    // Seed: the row with the maximum TOTAL distance to every other row
    // (ties -> earliest original index).
    $seed = 0;
    $bestTotal = -1.0;
    for ($i = 0; $i < $total; $i++) {
        $sum = 0.0;
        for ($j = 0; $j < $total; $j++) {
            if ($i === $j) { continue; }
            $sum += diversityDistance($tokens[$i], $tokens[$j]);
        }
        if ($sum > $bestTotal) { $bestTotal = $sum; $seed = $i; }
    }

    // Greedy: keep adding the row that maximises the MIN distance to the
    // already-selected rows (ties -> earliest original index).
    $selected = [$seed];
    $chosen = [$seed => true];
    while (count($selected) < $n) {
        $pick = -1;
        $bestMin = -1.0;
        for ($i = 0; $i < $total; $i++) {
            if (isset($chosen[$i])) { continue; }
            $min = INF;
            foreach ($selected as $s) {
                $d = diversityDistance($tokens[$i], $tokens[$s]);
                if ($d < $min) { $min = $d; }
            }
            if ($min > $bestMin) { $bestMin = $min; $pick = $i; }
        }
        if ($pick < 0) { break; }
        $selected[] = $pick;
        $chosen[$pick] = true;
    }

    $out = [];
    foreach ($selected as $i) { $out[] = $rows[$i]; }
    return $out;
}

/**
 * Emit a tiny inline SVG favicon. Used by both /favicon.ico and
 * /favicon.svg so the browser stops 404-ing on legacy hosts that still
 * request the .ico variant. No file is written to disk and no external
 * asset is fetched — the markup is shipped verbatim.
 *
 * @version 1.0.0
 */
function serveFavicon(): string
{
    $svg = '<?xml version="1.0" encoding="UTF-8"?>'
         . '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32">'
         . '<rect width="32" height="32" rx="6" fill="#0d1117"/>'
         . '<circle cx="16" cy="16" r="9" fill="none" stroke="#58a6ff" stroke-width="2.5"/>'
         . '<text x="16" y="20" text-anchor="middle" font-family="ui-sans-serif,system-ui,sans-serif" '
         . 'font-size="11" font-weight="700" fill="#e6edf3">OC</text>'
         . '</svg>';
    header('Content-Type: image/svg+xml');
    header('Cache-Control: public, max-age=86400');
    header('X-Content-Type-Options: nosniff');
    return $svg;
}

/**
 * Stream a static asset from /assets if the requested path resolves
 * inside it. Refuses traversal and unknown extensions.
 *
 * @version 1.0.0
 */
function serveAsset(string $path): void
{
    $base   = realpath(__DIR__ . '/assets');
    $target = realpath(__DIR__ . $path);
    if ($base === false || $target === false || !str_starts_with($target, $base) || !is_file($target)) {
        http_response_code(404);
        echo 'asset not found';
        return;
    }
    $ext  = strtolower(pathinfo($target, PATHINFO_EXTENSION));
    $mime = match ($ext) {
        'css'   => 'text/css; charset=utf-8',
        'js'    => 'text/javascript; charset=utf-8',
        'html'  => 'text/html; charset=utf-8',
        'json'  => 'application/json; charset=utf-8',
        'svg'   => 'image/svg+xml',
        'png'   => 'image/png',
        'jpg', 'jpeg' => 'image/jpeg',
        default => 'application/octet-stream',
    };
    header('Content-Type: ' . $mime);
    header('Cache-Control: public, max-age=3600');
    readfile($target);
}
