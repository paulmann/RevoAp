<?php
/**
 * ModelsController: HTTP surface of the Models tab.
 *
 * Endpoints (all admin-gated with the Settings gate; every one of them
 * answers 404 `settings_disabled` while `ModelsTab=false`):
 *
 *   GET  /api/models/status
 *        Public, no secrets. Reports `tab_enabled` (the ModelsTab flag) and
 *        the admin state, so the tab button and the lock screen can be
 *        rendered before a token exists. Same contract as
 *        /api/settings/status.
 *
 *   POST /api/models/actualize
 *        Enqueue the worker job that reconciles the linked models with the
 *        live provider catalogues (payload.kind = 'models_actualize').
 *        Body (all optional): {"selection":[{"provider","model"},…]} — the
 *        Chat sidebar's current pool, stored in the pre-run snapshot so a
 *        rollback restores exactly what the operator had — and
 *        {"import_source":"bothub"|"openrouter"}, the catalogue the run's
 *        first stage imports. The source is validated here and carried in
 *        the payload, because the import itself runs in the worker.
 *        Idempotent: while an actualization job is queued or running the
 *        call returns that job (200) instead of enqueuing a second (201).
 *
 *   GET  /api/models/actualize/status
 *        Stage, timing, result, warnings and snapshot availability of the
 *        most recent run, plus the pool the run produced, the linked models
 *        (analyzer / synthesis / arbiter) it brought up to date, what its
 *        import stage did and which model answered the assignment calls.
 *        The UI polls this while the tab is visible.
 *
 *   GET  /api/models/actualize/settings        (v1.8.53)
 *        The Actualize section's own settings: the analyzer model set for
 *        the section (null when unset), the inherited
 *        `analyzer.provider` / `analyzer.model`, and the value actually in
 *        effect. Read-only.
 *
 *   POST /api/models/actualize/settings
 *        Set that model (`{provider, model}`) or drop it (`{reset:true}`),
 *        so the section inherits the analyzer's again. A `:batch`
 *        identifier is refused with its reason — the assignment call is
 *        synchronous ({@see \App\Models\ModelId::syncCallRejection()}).
 *
 *   POST /api/models/actualize/rollback
 *        Restore the pre-run snapshot (catalogue + presets + pool) and
 *        return the pool the UI should re-apply.
 *
 * The long work (live fetch + analyzer calls) happens in the worker, never
 * inside a request: these endpoints only enqueue, read and restore.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-09-21
 */
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\AdminAuth;
use App\Core\Config;
use App\Core\Logger;
use App\Http\Response;
use App\Jobs\JobRepository;
use App\Jobs\JobService;
use App\Jobs\JobStatus;
use App\Jobs\WorkerHealthService;
use App\Models\ModelCatalogMerge;
use App\Models\ModelImporter;
use App\Models\ModelsActualizeSettings;
use App\Models\ModelsActualizer;
use App\Models\ModelsSnapshotStore;
use Throwable;

final class ModelsController
{
    public function __construct(
        private readonly JobService           $jobs,
        private readonly JobRepository        $jobRepo,
        private readonly ModelsSnapshotStore  $store,
        private readonly ModelsActualizer     $actualizer,
        private readonly ?WorkerHealthService $health = null,
        private readonly ?ModelsActualizeSettings $preferences = null,
    ) {}

    /**
     * The Actualize section's own settings, with the app_config-free
     * fallback: a deployment without that table has an unset section, so it
     * inherits `analyzer.*` exactly like a fresh install does.
     */
    private function preferences(): ModelsActualizeSettings
    {
        return $this->preferences ?? new ModelsActualizeSettings(null);
    }

    /**
     * Public tab probe. Mirrors SettingsController::status() so the Models
     * tab can be revealed and unlocked the same way. The snapshot
     * availability is read from the stored snapshot rather than probed
     * against the schema, so this unauthenticated endpoint performs no DDL.
     */
    public function status(): string
    {
        $snapshot = $this->store->loadSnapshot();
        return Response::json([
            'tab_enabled'        => Config::isModelsTabEnabled(),
            'admin_protection'   => AdminAuth::protectionEnabled(),
            'admin_configured'   => AdminAuth::isConfigured(),
            'admin_unlocked'     => AdminAuth::gateState()['unlocked'],
            'admin_diagnostics'  => AdminAuth::diagnostics(),
            'snapshot_available' => $snapshot !== null,
            'snapshot_taken_at'  => $snapshot['taken_at'] ?? null,
        ]);
    }

    /**
     * POST /api/models/actualize — enqueue (or reuse) the actualization job.
     *
     * @param array<string,mixed>|null $body Injected by the offline tests
     *        (the CLI SAPI has no `php://input`); null reads the request.
     */
    public function actualize(?array $body = null): string
    {
        if (($err = $this->guard()) !== null) {
            return $err;
        }
        if (!$this->jobRepo->tableAvailable()) {
            return Response::json([
                'ok'      => false,
                'error'   => 'job_table_missing',
                'message' => 'The durable job queue (revoap_jobs) is not available, so the worker has nothing to '
                    . 'pick up. Import db/schema.sql via Settings → Database → ReCreate DB first.',
            ], 503);
        }
        if (!$this->store->available()) {
            return Response::json([
                'ok'      => false,
                'error'   => 'snapshot_store_unavailable',
                'message' => 'The app_config table is not available, so this run could not be snapshotted (and '
                    . 'therefore could not be rolled back). Import db/schema.sql first.',
            ], 503);
        }

        $body ??= $this->readJsonBody();

        // Idempotency: a run that is queued or in flight is the answer, not
        // a reason to queue a second one — two concurrent runs would
        // rewrite the same presets and the later snapshot would overwrite
        // the earlier one, destroying the rollback path mid-flight.
        $active = $this->activeJob();
        if ($active !== null) {
            // Which catalogue the run in flight imports is part of the
            // answer: "reusing it" while the operator just picked the other
            // source would read as "my choice was ignored".
            $runningSource = ModelImporter::normaliseSource(
                $active->payload[ModelsActualizer::PAYLOAD_IMPORT_SOURCE] ?? null,
            ) ?? ModelImporter::defaultSource();
            $requested = ModelImporter::normaliseSource($body['import_source'] ?? null);
            return Response::json([
                'ok'            => true,
                'status'        => $active->status->value,
                'job_id'        => $active->id,
                'message'       => 'An actualization is already queued or running; reusing it. '
                    . sprintf('It imports from "%s"', $runningSource)
                    . ($requested !== null && $requested !== $runningSource
                        ? sprintf(', not from "%s" — wait for it to finish or cancel it.', $requested)
                        : '.'),
                'reused'        => true,
                'import_source' => $runningSource,
            ], 200);
        }

        $selection = $this->coerceSelection($body['selection'] ?? null);
        // The source is validated here, where a typo can still be answered
        // with a 400, and carried in the payload for the worker to act on.
        $source = $this->coerceImportSource($body['import_source'] ?? null);
        if ($source === false) {
            return Response::json([
                'ok'      => false,
                'error'   => 'invalid_import_source',
                'message' => 'Unknown import source; expected one of: '
                    . implode(', ', ModelImporter::SOURCES) . '.',
            ], 400);
        }

        try {
            $created = $this->jobs->createModelsActualizeJob($selection, $source);
        } catch (Throwable $e) {
            return Response::json([
                'ok'      => false,
                'error'   => 'enqueue_failed',
                'message' => Logger::sanitizeScalar($e->getMessage()),
            ], 500);
        }

        $effective = $source ?? ModelImporter::defaultSource();
        return Response::json([
            'ok'            => true,
            'status'        => JobStatus::Queued->value,
            'reused'        => false,
            'import_source' => $effective,
            'message'       => sprintf(
                'Actualization enqueued. The worker will import the %s catalogue, then assign models to the '
                    . 'consensuses.',
                $effective,
            ),
            ...$created,
        ], 201);
    }

    /**
     * GET /api/models/actualize/status — stage + result of the last run.
     *
     * v1.8.50 — the answer has to be trustworthy in three situations the
     * old version got wrong, all reported as "it says Analysing models,
     * then resets, and I cannot tell whether it worked":
     *
     *   1. **The stage is persisted per transition now**
     *      ({@see ModelsSnapshotStore::saveProgress()}), so an in-flight run
     *      reports `analysing_models` → `assigning_presets` instead of
     *      sitting on stage 1 for its whole life.
     *   2. **A terminal verdict is always present.** The envelope is
     *      written by {@see ModelsActualizer::finish()} on every path out
     *      of a run, including the ones that throw.
     *   3. **A job that failed anyway is reported as failed.** The old
     *      rescue branch below could never fire: it compared against a
     *      `job_status` key that no writer ever stored, so a crashed run
     *      surfaced as `stage=idle`, i.e. the initial card. The previous
     *      job is now read from the queue (`lastJob()`), its status and
     *      `error_json` are surfaced, and the failure text reaches the UI.
     */
    public function actualizeStatus(): string
    {
        if (($err = $this->guard()) !== null) {
            return $err;
        }

        $job = $this->activeJob();
        $last = $job ?? $this->lastJob();
        $stored = $this->store->loadStatus() ?? [];
        $snapshot = $this->store->loadSnapshot();
        // The stored record is only about the job it names; a status row
        // from an older run must not describe this one.
        if ($job !== null && (int)($stored['job_id'] ?? 0) !== $job->id) {
            $stored = [];
        }

        $payload = [
            'ok'               => true,
            'active'           => $job !== null,
            'stage'            => $job !== null ? self::stageOf($stored) : (string)($stored['stage'] ?? 'idle'),
            'job_id'           => $job !== null ? $job->id : ($stored['job_id'] ?? null),
            'job_status'       => $job !== null
                ? $job->status->value
                : (string)($stored['job_status'] ?? ($last !== null ? $last->status->value : '')),
            'message'          => (string)($stored['message'] ?? ''),
            'warnings'         => is_array($stored['warnings'] ?? null) ? array_values($stored['warnings']) : [],
            'error'            => is_array($stored['error'] ?? null) ? $stored['error'] : null,
            // v1.8.51 — the stage the last run died in, or null when the run
            // never published one. Additive: it lets the card paint the
            // failure on the row that actually broke instead of greening the
            // whole list on a `problems` verdict.
            'failed_stage'     => is_string($stored['failed_stage'] ?? null) ? $stored['failed_stage'] : null,
            'assigned'         => is_array($stored['assigned'] ?? null) ? $stored['assigned'] : [],
            // What the first stage imported, and which model the assignment
            // calls used. Additive: an older status row has neither key and
            // reports the empty shape, which is what the card checks for.
            'import'           => is_array($stored['import'] ?? null) ? $stored['import'] : self::emptyImport(),
            'analyzer'         => is_array($stored['analyzer'] ?? null) ? $stored['analyzer'] : $this->analyzerChoice(),
            'reasons'          => is_array($stored['reasons'] ?? null) ? $stored['reasons'] : [],
            // The linked models (analyzer / synthesis / arbiter) the last run
            // brought up to date, with their previous value. Additive: an
            // older status row simply has no `linked` key and reports [].
            'linked'           => is_array($stored['linked'] ?? null) ? array_values($stored['linked']) : [],
            'selection'        => is_array($stored['selection'] ?? null) ? $stored['selection'] : [],
            'diff'             => is_array($stored['diff'] ?? null) ? $stored['diff'] : [],
            'progress'         => is_array($stored['progress'] ?? null) ? $stored['progress'] : [],
            'started_at'       => $stored['started_at'] ?? null,
            'finished_at'      => $stored['finished_at'] ?? null,
            'updated_at'       => $stored['updated_at'] ?? null,
            'queued_seconds'   => $job !== null ? self::ageSeconds($job->createdAt) : null,
            'worker_available' => $this->workerAvailable(),
            'snapshot'         => [
                'available' => $snapshot !== null,
                'taken_at'  => $snapshot['taken_at'] ?? null,
                'job_id'    => $snapshot['job_id'] ?? null,
            ],
        ];

        // A job that died without an envelope (a killed worker, a fatal that
        // preceded recordFailure()) leaves no usable status. Report what the
        // queue knows — status and error text — so the UI cannot sit on
        // "analysing" forever, nor fall back to the untouched initial card.
        if ($job === null && $payload['stage'] === 'idle' && $last !== null) {
            if ($last->status === JobStatus::Failed || $last->status === JobStatus::Cancelled) {
                $payload['stage'] = ModelsActualizer::STAGE_PROBLEMS;
                $payload['message'] = $last->status === JobStatus::Cancelled
                    ? 'The last actualization was cancelled. Nothing was written.'
                    : 'The last actualization job failed before reporting a result. '
                        . 'See the error detail below and the worker log.';
                if (is_array($last->error)) {
                    $payload['error'] = $last->error;
                    $reason = (string)($last->error['message'] ?? '');
                    if ($reason !== '') {
                        $payload['message'] = 'The last actualization failed: ' . $reason;
                    }
                }
                $payload['job_id'] = $last->id;
                if (is_array($last->result) && ($last->result['stage'] ?? '') !== '') {
                    // The run recorded a verdict and still ended failed:
                    // prefer the verdict's own words.
                    $payload['stage'] = (string)$last->result['stage'];
                    if (($last->result['message'] ?? '') !== '') {
                        $payload['message'] = (string)$last->result['message'];
                    }
                    if (is_array($last->result['warnings'] ?? null)) {
                        $payload['warnings'] = array_values($last->result['warnings']);
                    }
                    // The failing stage travels with the verdict it belongs
                    // to, so the card can still point at the row that broke.
                    if (is_string($last->result['failed_stage'] ?? null)) {
                        $payload['failed_stage'] = $last->result['failed_stage'];
                    }
                }
            } elseif ($last->status === JobStatus::Queued || $last->status === JobStatus::ResumeRequested) {
                // The worker has not picked it up yet. The import is the
                // first row on the card, so that is the stage to name.
                $payload['stage'] = ModelsActualizer::STAGE_IMPORTING;
                $payload['message'] = 'Queued and waiting for a worker to pick it up.';
                $payload['job_id'] = $last->id;
            }
        }

        return Response::json($payload);
    }

    /**
     * Seconds since a `Y-m-d H:i:s` timestamp, or null when it cannot be
     * read. Used for the "queued for 4m — the worker has not picked this up"
     * message.
     *
     * Parsed in the process's default timezone on purpose: the queue stamps
     * rows with `date()` (see JobRepository), so the values are wall-clock
     * local time, while `time()` is absolute — the difference is therefore
     * correct in any zone. The status record's own `started_at` /
     * `finished_at` are `gmdate()` and are never fed to this helper.
     */
    private static function ageSeconds(?string $timestamp): ?int
    {
        if ($timestamp === null || trim($timestamp) === '') {
            return null;
        }
        try {
            $then = new \DateTimeImmutable($timestamp);
        } catch (\Throwable) {
            return null;
        }
        return max(0, time() - $then->getTimestamp());
    }

    /**
     * GET /api/models/actualize/settings — the section's own settings.
     *
     * Two facts, kept apart on purpose: `section` is what the operator set
     * for this section (null when they have not), `effective` is what a run
     * will actually call. The card prints both, because "I picked a model
     * here" and "the analyzer's model is being used" look identical in a
     * field that only shows one of them.
     */
    public function actualizeSettings(): string
    {
        if (($err = $this->guard()) !== null) {
            return $err;
        }
        return Response::json($this->settingsState());
    }

    /**
     * POST /api/models/actualize/settings — set or clear the section's
     * analyzer model.
     *
     * Body: `{"provider":"bothub","model":"gpt-5.6-sol"}` to set it, or
     * `{"reset":true}` to drop it and inherit `analyzer.*` again. The two
     * are mutually exclusive, and a request that does neither is a 400
     * rather than a silent no-op — an operator who pressed Save has to be
     * able to tell whether anything was saved.
     */
    public function saveActualizeSettings(?array $body = null): string
    {
        if (($err = $this->guard()) !== null) {
            return $err;
        }
        $body ??= $this->readJsonBody();
        $settings = $this->preferences();
        if (!$settings->available()) {
            return Response::json([
                'ok'      => false,
                'error'   => 'settings_store_unavailable',
                'message' => 'The app_config table is not available, so this setting cannot be stored. '
                    . 'The section keeps using analyzer.provider / analyzer.model. Import db/schema.sql first.',
            ], 503);
        }

        $reset = ($body['reset'] ?? false) === true;
        $hasPair = array_key_exists('provider', $body) || array_key_exists('model', $body);
        if ($reset && $hasPair) {
            return Response::json([
                'ok'      => false,
                'error'   => 'invalid_request',
                'message' => 'Send either a provider/model pair or "reset": true, not both.',
            ], 400);
        }
        if (!$reset && !$hasPair) {
            return Response::json([
                'ok'      => false,
                'error'   => 'invalid_request',
                'message' => 'Send {"provider":…,"model":…} to set the section model, or {"reset":true} to '
                    . 'inherit analyzer.provider / analyzer.model again.',
            ], 400);
        }

        try {
            $stored = $reset
                ? $settings->clear()
                : $settings->save((string)($body['provider'] ?? ''), (string)($body['model'] ?? ''));
        } catch (\InvalidArgumentException $e) {
            // The one refusal an operator can actually be told about: a
            // batch-only id in a role that is called synchronously.
            return Response::json([
                'ok'      => false,
                'error'   => 'invalid_model',
                'message' => Logger::sanitizeScalar($e->getMessage()),
            ], 400);
        }
        if (!$stored) {
            return Response::json([
                'ok'      => false,
                'error'   => 'settings_write_failed',
                'message' => 'The section setting could not be stored, so nothing changed.',
            ], 500);
        }

        return Response::json([
            'ok'      => true,
            'reset'   => $reset,
            'message' => $reset
                ? 'The section inherits analyzer.provider / analyzer.model again.'
                : 'The section now uses its own model.',
            ...$this->settingsState(),
        ]);
    }

    /**
     * The section-settings payload both endpoints answer with.
     *
     * @return array<string,mixed>
     */
    private function settingsState(): array
    {
        $settings = $this->preferences();
        $section  = $settings->section();
        $choice   = $settings->resolve();
        return [
            'ok'               => true,
            'section'          => $section,
            'inherited'        => $settings->inherited(),
            'effective'        => ['provider' => (string)$choice['provider'], 'model' => (string)$choice['model']],
            'source'           => (string)$choice['source'],
            'note'             => $choice['note'],
            'source_key'       => ModelsActualizeSettings::KEY_ANALYZER,
            'inherited_key'    => 'analyzer.provider / analyzer.model',
            'store_available'  => $settings->available(),
            // The sources the run's first stage may import from. Named by
            // the server so the selector cannot drift from what the worker
            // accepts.
            'import_sources'   => ModelImporter::SOURCES,
            'default_source'   => ModelImporter::defaultSource(),
        ];
    }

    /**
     * POST /api/models/actualize/rollback — restore the pre-run snapshot.
     */
    public function rollback(): string
    {
        if (($err = $this->guard()) !== null) {
            return $err;
        }
        if (!$this->store->available()) {
            return Response::json([
                'ok'      => false,
                'error'   => 'snapshot_store_unavailable',
                'message' => 'The app_config table is not available, so no snapshot can be read.',
            ], 503);
        }
        $job = $this->activeJob();
        if ($job !== null) {
            return Response::json([
                'ok'      => false,
                'error'   => 'actualize_running',
                'message' => 'An actualization is still running; rolling back now would race the worker.',
                'job_id'  => $job->id,
            ], 409);
        }

        $result = $this->actualizer->rollback();
        return Response::json($result, $result['ok'] === true ? 200 : 409);
    }

    // =================================================================
    // Helpers
    // =================================================================

    /**
     * The job currently holding the actualization slot, or null.
     *
     * Found by id first (`models.actualize.job_id`-style state lives in the
     * status record), then by scanning recent jobs for the payload marker —
     * the scan is what makes idempotency survive a process restart or a
     * second web node that never saw the enqueue.
     *
     * v1.8.51 — the scan no longer stops at the first job that is NOT
     * active. The status record names the job that produced it, and until a
     * worker picks the new job up that record is the PREVIOUS, terminal
     * run; stopping there meant a freshly enqueued job was reported as "no
     * active run", so the Models card answered with the old verdict and
     * `active: false` while the new job sat in the queue. Candidates are
     * ordered remembered-first then newest-first, so the first ACTIVE match
     * is still the most recent one.
     */
    private function activeJob(): ?\App\Jobs\Job
    {
        if (!$this->jobRepo->tableAvailable()) {
            return null;
        }
        $stored = $this->store->loadStatus() ?? [];
        $candidates = [];
        $remembered = (int)($stored['job_id'] ?? 0);
        if ($remembered > 0) {
            $candidates[] = $this->jobRepo->find($remembered);
        }
        foreach ($this->jobRepo->listRecent(50) as $job) {
            $candidates[] = $job;
        }
        foreach ($candidates as $job) {
            if ($job === null) { continue; }
            if (($job->payload['kind'] ?? '') !== ModelsActualizer::KIND) { continue; }
            if ($job->status->isActive()) {
                return $job;
            }
        }
        return null;
    }

    /**
     * The newest actualization job, whatever its status.
     *
     * `activeJob()` deliberately stops at the top of `listRecent()` and only
     * returns a running/queued row; the status endpoint also needs to know
     * how the *last* run ended, so a failed job can be reported as failed
     * instead of vanishing into "idle".
     */
    private function lastJob(): ?\App\Jobs\Job
    {
        if (!$this->jobRepo->tableAvailable()) {
            return null;
        }
        foreach ($this->jobRepo->listRecent(50) as $job) {
            if (($job->payload['kind'] ?? '') === ModelsActualizer::KIND) {
                return $job;
            }
        }
        return null;
    }

    /** Best-effort stage from the status record, for an in-flight job. */
    private static function stageOf(array $stored): string
    {
        $stage = (string)($stored['stage'] ?? '');
        if (in_array($stage, [
            ModelsActualizer::STAGE_IMPORTING,
            ModelsActualizer::STAGE_ANALYSING,
            ModelsActualizer::STAGE_ASSIGNING,
            ModelsActualizer::STAGE_SUCCESS,
            ModelsActualizer::STAGE_PROBLEMS,
        ], true)) {
            return $stage;
        }
        // Nothing written yet: the job is queued, so the import — the first
        // stage — is next. Reporting `analysing_models` here would tick past
        // a row that has not run.
        return ModelsActualizer::STAGE_IMPORTING;
    }

    /**
     * The empty import block, for a status row written before the import
     * stage existed. The card renders the counts only when there are any.
     *
     * @return array<string,mixed>
     */
    private static function emptyImport(): array
    {
        return [
            'source' => '', 'fetched' => 0, 'added' => [], 'duplicates' => 0,
            'present' => 0, 'truncated' => 0, 'unusable' => 0,
            // The cap the panel quotes is configuration (v1.8.60), so a
            // status row written before the import stage reads the same
            // number the next import will actually obey.
            'limit' => Config::catalogMaxRows(), 'batch' => [],
        ];
    }

    /**
     * The model the assignment calls run on, resolved from the section's
     * own key with `analyzer.*` as the fallback — the same value the worker
     * will use, so the card can name it before a run has ever happened.
     *
     * @return array<string,mixed>
     */
    private function analyzerChoice(): array
    {
        $choice = $this->preferences()->resolve();
        return [
            'provider' => (string)$choice['provider'],
            'model'    => (string)$choice['model'],
            'source'   => (string)$choice['source'],
            'note'     => $choice['note'],
        ];
    }

    /**
     * Is a worker actually polling the queue? Told honestly rather than
     * assumed: a job that no worker will ever pick up must not look like a
     * run in progress.
     */
    private function workerAvailable(): ?bool
    {
        if ($this->health === null) {
            return null;
        }
        try {
            $snapshot = $this->health->snapshot();
            if (array_key_exists('workers_alive', $snapshot) && (int)$snapshot['workers_alive'] > 0) {
                return true;
            }
            return (string)($snapshot['worker_status'] ?? '') === 'healthy';
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The requested import source: a normalised slug, null when the caller
     * sent nothing (the worker then applies the default), or `false` when
     * the caller sent something that is not a source we have.
     *
     * `false` and null are different answers on purpose: "no choice made"
     * must not be reported as "your choice was rejected".
     *
     * @return string|null|false
     */
    private function coerceImportSource(mixed $raw): string|null|false
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        return ModelImporter::normaliseSource($raw) ?? false;
    }

    /**
     * @return array<int,array{provider:string,model:string}>
     */
    private function coerceSelection(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        $seen = [];
        foreach ($raw as $row) {
            if (!is_array($row)) { continue; }
            $provider = strtolower(trim((string)($row['provider'] ?? '')));
            $model = trim((string)($row['model'] ?? ''));
            if ($provider === '' || $model === '') { continue; }
            if (mb_strlen($provider) > 64) { $provider = mb_substr($provider, 0, 64); }
            if (mb_strlen($model) > 191) { $model = mb_substr($model, 0, 191); }
            $key = $provider . '|' . $model;
            if (isset($seen[$key])) { continue; }
            $seen[$key] = true;
            $out[] = ['provider' => $provider, 'model' => $model];
            if (count($out) >= 32) { break; }
        }
        return $out;
    }

    /**
     * Models-tab + admin gate: the same two checks every mutating
     * /api/settings/* route performs (flag, then token), against the
     * `ModelsTab` flag.
     *
     * Deliberately NOT also requiring SettingsTab: these endpoints are new,
     * so nothing depends on them yet, and `ModelsTab = false` is documented
     * as disabling "the UI tab AND the matching backend endpoints". Being
     * lax here would leave an operator who switched the tab off with a live
     * surface that rewrites their presets.
     *
     * The pre-existing endpoints whose UI moved into the tab
     * (/api/domain-presets/*, /api/analyzer/*, /api/settings/providers,
     * /api/settings/models) keep their original SettingsTab gate untouched,
     * so no existing client contract changes.
     */
    private function guard(): ?string
    {
        if (!Config::isModelsTabEnabled()) {
            return Response::json([
                'error'   => 'settings_disabled',
                'message' => 'The Models tab is disabled in config.php (ModelsTab=false).',
            ], 404);
        }
        // v1.8.60 — the decision is taken once, in AdminAuth, so every guard
        // on this surface obeys `Protect_Settings_by_Admin_Key`: with the flag
        // at 0 the gate is open (no key configured, no key presented, nothing
        // to challenge), while at 1 — or absent — the historical 503 / 401
        // pair is returned unchanged.
        $gate = AdminAuth::gateState();
        if (!$gate['allowed']) {
            if (!$gate['configured']) {
                return Response::json([
                    'error'   => 'admin_not_configured',
                    'message' => SettingsController::adminNotConfiguredMessage('Models'),
                    'admin_diagnostics' => AdminAuth::diagnostics(),
                ], 503);
            }
            return Response::json(['error' => 'unauthorized', 'message' => 'admin token required'], 401);
        }
        return null;
    }

    /** @return array<string,mixed> */
    private function readJsonBody(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }
}
