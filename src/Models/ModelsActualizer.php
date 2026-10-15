<?php
/**
 * ModelsActualizer: bring the linked models back in line with reality.
 *
 * Runs in the worker (payload.kind = 'models_actualize', dispatched by
 * {@see \App\Jobs\PipelineJobRunner}) because it is a live catalogue fetch
 * plus several analyzer-model calls — far too long to hold an HTTP request
 * open, and it must survive a browser going away.
 *
 * Three stages, each published as a `revoap_job_events` row so the UI can
 * follow along:
 *
 *   1. `importing_models`  — fetch the provider catalogue the run works
 *      from ({@see ModelImporter}, the same implementation behind the
 *      Models tab's "Import models" button) and merge it into the
 *      persisted catalogue. Which provider is the operator's choice
 *      (`bothub` or `openrouter`, carried in the job payload as
 *      `import_source`), because the two catalogues disagree about ids
 *      and only the operator knows which one their pool calls. A failed
 *      import is fatal and loud: there is no "carry on with the old
 *      catalogue" path, because the rest of the run would then assign
 *      models against a list the provider may no longer serve.
 *   2. `analysing_models`  — fetch the live catalogues
 *      (`ConnectorInterface::listModels()` on every active connector) and
 *      diff them against the imported catalogue
 *      (`var/config/runtime.json` → `models[]`) with
 *      {@see ModelCatalogDiff}. Answers "what is new, what is gone".
 *   3. `assigning_presets`  — ask the analyzer model
 *      ({@see ModelsActualizeSettings}: the section's own
 *      `models.actualize.analyzer`, or `analyzer.provider` /
 *      `analyzer.model` when it is unset — never a hand-rolled curl) to
 *      pick the pool for each consensus preset. `mathematics` and
 *      `physics` are asked for the strongest models in their own domain;
 *      every other preset is asked for the strongest models *from
 *      different base providers*, and the answer is then checked locally
 *      ({@see self::diversify()}) because a model reply is a suggestion,
 *      not a guarantee. The answer is also parsed against a hard contract
 *      ({@see self::systemPrompt()}), with exactly one corrective retry
 *      before the run refuses.
 *   4. `success` / `problems` — write, or do not write at all.
 *
 * All-or-nothing
 * --------------
 * A half-applied rewrite (presets updated, catalogue not) would leave the
 * app internally inconsistent with no way back. So the run takes a
 * snapshot first (via {@see ModelsSnapshotStore}), refuses to start
 * writing when no snapshot could be stored, and restores that snapshot if
 * any write fails halfway. The same restore path backs the explicit
 * rollback endpoint. A failing analyzer call is fatal for the same
 * reason: the run reports `problems` and writes nothing, rather than
 * guessing a pool the operator never approved.
 *
 * What is deliberately *not* here: no pipeline model is called, only the
 * analyzer model, and never the models being assigned.
 *
 * Besides those three structures the run also brings the *linked* models
 * up to date — `analyzer.provider`/`analyzer.model`,
 * `pipeline.synthesis_model` and `pipeline.conflict_arbiter.model` — which
 * are bound to the same providers but live in flat config keys. That part
 * is {@see LinkedModels}: it follows the pool's conservative version rule
 * (newest release on the same line, only if it is in the live catalogue)
 * and is snapshotted and restored exactly like the catalogue and the
 * presets.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-09-21
 */
declare(strict_types=1);

namespace App\Models;

use App\Cache\ModelResponseCache;
use App\Connectors\ConnectorInterface;
use App\Connectors\ConnectorRegistry;
use App\Core\Config;
use App\Core\Logger;
use App\Core\RuntimeConfig;
use App\DomainPresets\DomainPreset;
use App\DomainPresets\DomainPresetRepository;
use App\Jobs\Job;
use App\Jobs\JobAuditRepository;

final class ModelsActualizer
{
    /** Payload marker that routes a job here (see PipelineJobRunner). */
    public const KIND = 'models_actualize';

    /**
     * Stage names, in order. Also the values the status endpoint emits.
     *
     * `importing_models` is first on purpose: every later stage reasons
     * about "the catalogue", and the whole point of the step is that the
     * catalogue is one the provider actually serves *today*.
     */
    public const STAGE_IMPORTING = 'importing_models';
    public const STAGE_ANALYSING = 'analysing_models';
    public const STAGE_ASSIGNING = 'assigning_presets';
    public const STAGE_SUCCESS   = 'success';
    public const STAGE_PROBLEMS  = 'problems';

    /**
     * Payload key carrying the operator's import source
     * (`bothub` / `openrouter`).
     */
    public const PAYLOAD_IMPORT_SOURCE = 'import_source';

    /** Audit event type for every stage transition. */
    public const EVENT_STAGE = 'models_actualize.stage';

    /**
     * How many times one preset's assignment may be asked for: the answer,
     * then exactly one correction. Not a retry loop — a model that
     * ignores the output contract twice is a model this run does not use,
     * and asking a third time would only spend tokens on the same refusal.
     */
    public const ANALYZER_ATTEMPTS = 2;

    /**
     * Descriptive catalogue fields a rewrite must carry over.
     *
     * Not part of a row's identity (that is provider + model) and not read
     * by the pipeline, but the Models tab renders them and the shipped
     * catalogue files ship them: `label` is the human name, `rank` the
     * curated order, `category` the provider's own grouping and `default`
     * the fresh-install pick. Held as one list so the write path
     * ({@see self::catalogRow()}) and the restore path
     * ({@see self::restore()}) cannot disagree about what "the same row"
     * means.
     */
    private const CATALOG_META_KEYS = ['label', 'rank', 'category', 'default'];

    /**
     * Legacy hard cap on catalogue rows.
     *
     * v1.8.60 — **no longer used**. The cap is configuration
     * (`models.catalog.max_rows`, read through
     * {@see \App\Core\Config::catalogMaxRows()`, `0` = no ceiling) and it is
     * applied by {@see CatalogTruncation}, which protects the rows the pool,
     * the presets and the linked roles reference instead of cutting the list
     * at an arbitrary position. Kept so a caller that referenced the
     * constant still resolves; do not use it to decide anything.
     *
     * @deprecated 1.8.60 Use {@see \App\Core\Config::catalogMaxRows()}.
     */
    public const MAX_CATALOG_ROWS = 64;

    /** Upper bound on models per preset pool, mirrors the preset editor. */
    public const MAX_MODELS_PER_PRESET = 12;

    /** Families a preset pool should span when it can. */
    public const DEFAULT_FAMILY_SPREAD = 4;

    /** Presets are updated in this order, so `default` is decided first. */
    private const PRESET_ORDER = ['default', 'mathematics', 'physics'];

    /**
     * Completion budget for an assignment answer. Raised from 1500 when the
     * output contract started demanding one complete JSON object with an
     * example-shaped payload: an answer cut off by the token limit is a
     * contract violation, and the corrective retry would be spent on a
     * truncation the run caused itself.
     */
    private const MAX_COMPLETION_TOKENS = 2000;

    /** Per-call transport budget for the analyzer request, in seconds. */
    private const ANALYZER_TIMEOUT_SECONDS = 60;

    /**
     * Set once when a progress record could not be stored, so the final
     * envelope carries it exactly once instead of per stage.
     */
    private bool $progressWriteFailed = false;

    /**
     * The last stage {@see self::publishStage()} announced, so a failing
     * run can name the stage it died in instead of leaving the UI to
     * guess (v1.8.51). `null` until the first stage is published — a run
     * that never got that far genuinely does not know.
     */
    private ?string $lastStage = null;

    /**
     * What this run did, kept for {@see self::finish()} so every exit path
     * — including the abort paths, which return from the middle of the run
     * — reports the same three facts without threading them through five
     * call sites as parameters:
     *
     *   * `importReport`  — the import stage's counters (source, fetched,
     *     added, dropped);
     *   * `analyzerUsage` — which model actually answered the assignment
     *     calls, where that value came from, and how many times it had to
     *     be asked;
     *   * `answerReasons` — the `reason` each preset's pool was chosen for.
     *
     * Reset at the start of every run: a worker reuses one instance for
     * many jobs, and a stale counter would attribute the previous run's
     * import to this one.
     *
     * @var array<string,mixed>
     */
    private array $importReport = [];

    /** @var array<string,mixed> */
    private array $analyzerUsage = [];

    /** @var array<string,string> */
    private array $answerReasons = [];

    /**
     * v1.8.60 — what the row cap did to this run's catalogue, and how many
     * rows were proved stale. Kept for {@see self::finish()} for the same
     * reason as the counters above: an abort path must be able to report the
     * cap decision without threading it through every return.
     *
     * @var array<string,mixed>
     */
    private array $catalogReport = [];

    /**
     * `provider|model` keys of the rows this run found missing from their
     * provider's live list. Reported so "N rows are no longer served" is a
     * fact on the card, not something the operator has to infer from a count.
     *
     * @var array<int,string>
     */
    private array $staleRowKeys = [];

    /**
     * The same rows as {@see self::$staleRowKeys}, in the `provider` /
     * `model` shape the report renders.
     *
     * @var array<int,array{provider:string,model:string}>
     */
    private array $staleRows = [];

    /**
     * The invariant verdict of this run
     * ({@see self::verifyCatalogInvariant()}), reported verbatim on failure.
     *
     * @var array{ok:bool,violations:array<int,array<string,string>>,checked:int}
     */
    private array $invariantReport = ['ok' => true, 'violations' => [], 'checked' => 0];

    /**
     * @param ?ModelImporter           $importer    shared catalogue fetch
     *        (default: the production {@see ModelImporter})
     * @param ?ModelsActualizeSettings $preferences the section's own
     *        analyzer choice; null means "inherit `analyzer.*`", which is
     *        exactly what an unset section means
     */
    public function __construct(
        private readonly ConnectorRegistry       $connectors,
        private readonly DomainPresetRepository  $presets,
        private readonly ModelsSnapshotStore     $store,
        private readonly ?JobAuditRepository     $audit = null,
        private readonly ?ModelResponseCache     $cache = null,
        private readonly ?ModelImporter          $importer = null,
        private readonly ?ModelsActualizeSettings $preferences = null,
    ) {}

    /**
     * The catalogue fetcher. Built on first use rather than injected when
     * the deployment did not wire one — {@see ModelImporter} holds no
     * state, so there is nothing to share between two instances.
     */
    private function importer(): ModelImporter
    {
        return $this->importer ?? new ModelImporter();
    }

    /**
     * The section's analyzer setting, with the app_config-free fallback:
     * a deployment whose `app_config` is missing still has an unset
     * section, so it inherits `analyzer.*` exactly like a fresh install.
     */
    private function preferences(): ModelsActualizeSettings
    {
        return $this->preferences ?? new ModelsActualizeSettings(null);
    }

    /**
     * Execute one actualization run.
     *
     * v1.8.50 — two changes to how a run reports itself, both driven by
     * "it spins on Analysing models and then resets, and I cannot tell
     * whether it worked":
     *
     *   1. Every stage is persisted (see {@see self::publishStage()}), not
     *      only the final envelope, so the 3s status poll can follow the
     *      run through `analysing_models` → `assigning_presets` → verdict.
     *   2. The whole body is wrapped: a throw from planning, from the
     *      snapshot or from the connector layer used to escape `run()`
     *      entirely, leaving no status record at all — which the status
     *      endpoint reports as "idle", i.e. the initial state. It now
     *      always ends in a terminal `problems` envelope carrying the
     *      error text.
     *
     * @param null|callable():void $heartbeat Invoked at every stage so a
     *        long run (a live catalogue fetch plus one analyzer call per
     *        preset) keeps its queue row fresh and is not declared stale by
     *        another worker's recovery sweep — which would requeue the job
     *        underneath the run that is still working.
     * @return array<string,mixed> envelope persisted as the job result
     */
    public function run(Job $job, string $workerId, ?callable $heartbeat = null): array
    {
        $started = microtime(true);
        try {
            return $this->runStages($job, $workerId, $started, $heartbeat);
        } catch (\Throwable $e) {
            $message = Logger::sanitizeScalar($e->getMessage());
            Logger::error('models.actualize.aborted', [
                'job_id' => $job->id,
                'error'  => $message,
                'class'  => (new \ReflectionClass($e))->getShortName(),
            ]);
            return $this->finish($job, $started, self::STAGE_PROBLEMS, [
                'The run aborted before it could finish: ' . $message,
            ], [], [], [], [
                'message' => 'Actualization failed and nothing was written: ' . $message,
                'error'   => [
                    'class'   => (new \ReflectionClass($e))->getShortName(),
                    'message' => $message,
                ],
            ]);
        }
    }

    /**
     * The stage body of {@see self::run()}. Split out so the wrapper above
     * can guarantee a terminal status record for anything that throws.
     *
     * @param null|callable():void $heartbeat
     * @return array<string,mixed>
     */
    private function runStages(Job $job, string $workerId, float $started, ?callable $heartbeat): array
    {
        $warnings = [];
        $assigned = [];
        $diff = [];
        $selection = [];
        $this->importReport = [];
        $this->analyzerUsage = [];
        $this->answerReasons = [];
        $this->catalogReport = [];
        $this->staleRowKeys = [];
        $this->staleRows = [];
        $this->invariantReport = ['ok' => true, 'violations' => [], 'checked' => 0];

        // The catalogue as it stands *before* anything is imported: this is
        // what the snapshot has to hold, so a rollback undoes the import
        // too. The imported catalogue is only a planning input until the
        // write phase, which keeps the run all-or-nothing.
        $catalogBeforeImport = $this->catalogRows();

        // ---- Stage 1: import the catalogue from the chosen source ----
        // v1.8.53 — the run starts here. Every later stage reasons about
        // "the catalogue", and this is what makes it one the provider
        // actually serves today; which provider is the operator's choice in
        // the job payload, because the fetch runs here, not in the request
        // that enqueued it.
        $source = self::importSource($job);
        $this->publishStage(
            $job,
            $workerId,
            self::STAGE_IMPORTING,
            sprintf('Importing the model catalogue from %s', $source),
            ['source' => $source],
            $heartbeat,
        );
        $import = $this->importCatalogue($source, $warnings);
        // On a failure the report is empty; the source still belongs in the
        // report, because "which provider did not answer" is the first thing
        // the operator needs to read.
        $this->importReport = $import['report'] === [] ? ['source' => $source] : $import['report'];
        if ($import['ok'] !== true) {
            // Loud, and specific: the provider's own words, or the missing
            // key, or the empty list. Carrying on with the catalogue that is
            // already persisted is exactly what the operator ruled out — the
            // rest of the run assigns models *from* this catalogue.
            $warnings[] = $import['error'];
            return $this->finish($job, $started, self::STAGE_PROBLEMS, $warnings, [], [], [], [
                'message' => sprintf(
                    'Actualization aborted: the model import from "%s" failed. Nothing was written.',
                    $source,
                ),
                'error'   => [
                    'stage'   => self::STAGE_IMPORTING,
                    'source'  => $source,
                    'message' => $import['error'],
                ],
            ]);
        }
        $catalog = $import['catalog'];
        $this->publishStage(
            $job,
            $workerId,
            self::STAGE_IMPORTING,
            sprintf(
                'Imported %d model(s) from %s: %d new row(s), %d already catalogued',
                (int)$import['report']['fetched'],
                $source,
                count((array)$import['report']['added']),
                (int)$import['report']['duplicates'],
            ),
            [
                'source'     => $source,
                'fetched'    => (int)$import['report']['fetched'],
                'added'      => count((array)$import['report']['added']),
                'duplicates' => (int)$import['report']['duplicates'],
                'truncated'  => (int)$import['report']['truncated'],
                'unusable'   => (int)$import['report']['unusable'],
                'rows'       => count($catalog),
            ],
            $heartbeat,
        );

        $this->publishStage($job, $workerId, self::STAGE_ANALYSING, 'Fetching live model lists', [], $heartbeat);

        // ---- Stage 2: live catalogue + diff --------------------------
        $live = $this->collectLiveLists($warnings);
        $diff = ModelCatalogDiff::compare($catalog, $live['lists']);
        foreach ($diff['unavailable'] as $provider) {
            $warnings[] = sprintf(
                'Provider "%s" returned no model list (transport failure or no public catalogue); its catalogue rows were left untouched.',
                $provider,
            );
        }
        $this->publishStage($job, $workerId, self::STAGE_ANALYSING, 'Live catalogue analysed', [
            'live_count'    => $diff['live_count'],
            'catalog_count' => $diff['catalog_count'],
            'added_count'   => count($diff['added']),
            'stale_count'   => count($diff['stale']),
            'unavailable'   => $diff['unavailable'],
        ], $heartbeat);

        if ($diff['live_count'] === 0) {
            $warnings[] = 'No provider returned a model list, so there is nothing to assign. '
                . 'Check that at least one connector is active and its API key is valid.';
            return $this->finish($job, $started, self::STAGE_PROBLEMS, $warnings, $assigned, $diff, [], [
                'message' => 'Actualization aborted: no live models were available. Nothing was written.',
            ]);
        }

        // ---- Stage 2: assign pools ----------------------------------
        $this->publishStage($job, $workerId, self::STAGE_ASSIGNING, 'Assigning models to consensuses', [], $heartbeat);
        $presetModels = $this->presets->all();
        if ($presetModels === []) {
            $warnings[] = 'No consensus presets are defined, so there was nothing to assign.';
        }

        $plans = [];
        foreach ($this->orderedSlugs($presetModels) as $slug) {
            $domain = ($slug === 'mathematics' || $slug === 'physics') ? $slug : 'default';
            $answer = $this->assignFor($slug, $domain, $diff['live']);
            if ($answer['ok'] !== true) {
                $warnings[] = sprintf(
                    'The analyzer model could not produce a pool for preset "%s"; nothing was written.',
                    $slug,
                );
                // Why it could not, in the parser's own words when there is
                // one. "It failed" and "it answered outside the contract
                // twice" are different problems — the first is a model that
                // is down, the second is a model that will not follow the
                // format, and only the second one is fixable by switching
                // the analyzer (see the Actualize section's model selector).
                $reason = $answer['error'];
                if (is_string($reason) && $reason !== '') {
                    $warnings[] = sprintf(
                        'The answer for "%s" was rejected after %d attempt(s); the last rejection was: %s',
                        $slug,
                        $answer['attempts'],
                        $reason,
                    );
                }
                return $this->finish($job, $started, self::STAGE_PROBLEMS, $warnings, $assigned, $diff, [], [
                    'message' => 'Actualization aborted: the analyzer model failed. Nothing was written.',
                    'error'   => [
                        'stage'       => self::STAGE_ASSIGNING,
                        'slug'        => $slug,
                        'attempts'    => $answer['attempts'],
                        'corrections' => $answer['corrections'],
                        'message'     => $reason === null || $reason === ''
                            ? 'The analyzer model could not be reached.'
                            : 'The analyzer answer did not satisfy the output contract: ' . $reason,
                    ],
                ]);
            }

            $plans[$slug] = $answer['plan'];
            $assigned[$slug] = array_map(
                static fn(array $row): string => $row['provider'] . '/' . $row['model'],
                $plans[$slug]['models'],
            );
            $reason = (string)($plans[$slug]['reason'] ?? '');
            if ($reason !== '') {
                $this->answerReasons[$slug] = $reason;
            }
            foreach ($plans[$slug]['warnings'] as $warning) {
                $warnings[] = $warning;
            }
            $this->publishStage($job, $workerId, self::STAGE_ASSIGNING, sprintf('Assigned %d model(s) to "%s"', count($plans[$slug]['models']), $slug), [
                'slug'     => $slug,
                'models'   => $assigned[$slug],
                'arbiter'  => $plans[$slug]['arbiter'],
                'reason'   => $reason,
                'attempts' => $answer['attempts'],
            ], $heartbeat);
        }

        // ---- The linked models: analyzer, synthesis, arbiter ---------
        // Not part of a preset and not part of the pool, but just as bound
        // to the live catalogue: leaving the analyzer on a retired model
        // would mean the next run assigns pools with a model the provider
        // no longer serves.
        //
        // v1.8.60 — planned BEFORE the catalogue, because the catalogue has
        // to activate whatever these three roles are about to be pointed at:
        // they are synchronous callers, so a linked model that exists only
        // as an inactive row is a guaranteed failure at the next run.
        $linked = LinkedModels::plan($live['lists']);
        $linkedChanges = $linked['changes'];
        foreach ($linked['warnings'] as $warning) {
            $warnings[] = $warning;
        }

        // ---- The catalogue this run is about to write ------------------
        // Planned BEFORE the pool, because the pool has to be validated
        // against the rows that will actually exist and be active. Planning
        // it after the fact is what let `pipeline.default_selected_models`
        // hold four models that appeared in none of the catalogue's rows.
        $plannedRows = $this->planCatalogRows($catalog, $diff, $plans, $linkedChanges);

        // ---- The user's pool: latest version of what is already picked
        $selectionPlan = $this->planSelection($job, $plans, $this->activeCatalogKeys($plannedRows));
        $selection = $selectionPlan['selection'];
        foreach ($selectionPlan['warnings'] as $warning) {
            $warnings[] = $warning;
        }

        // ---- Stage 3: write ------------------------------------------
        // The snapshot holds the catalogue as it was BEFORE the import, so
        // rolling this run back also rolls the import back. Snapshotting the
        // imported rows instead would leave a rollback that undoes
        // everything except the step the operator is most likely to want
        // undone (a wrong source, a catalogue they did not want filled).
        $snapshot = $this->buildSnapshot($job, $catalogBeforeImport, $presetModels, $selectionPlan['previous'], $linked['previous']);
        if (!$this->store->saveSnapshot($snapshot)) {
            $warnings[] = 'The pre-run snapshot could not be stored (app_config unavailable), '
                . 'so nothing was written — the run would not have been reversible.';
            return $this->finish($job, $started, self::STAGE_PROBLEMS, $warnings, $assigned, $diff, $selection, [
                'message' => 'Actualization aborted: no rollback path. Nothing was written.',
            ]);
        }
        $this->publishStage($job, $workerId, self::STAGE_ASSIGNING, 'Writing the catalogue, presets and pool', [
            'catalog_rows' => count($this->pendingAdded($catalog, $diff)),
            'presets'      => array_keys($plans),
        ], $heartbeat);

        // v1.8.60 — plan the catalogue and verify it holds together BEFORE
        // anything is written. The plan is pure; the verification is what
        // turns "the run produced a pool pointing at a row the catalogue
        // does not hold" from a silent broken install into a refusal with
        // nothing written.
        $catalogRows = $this->applyCatalogLimit($plannedRows, $plans, $selection, $linkedChanges);

        // The cap and the staleness proof are disclosures, not asides: both
        // changed the catalogue the operator is about to run on.
        $truncationWarning = CatalogTruncation::describe($this->catalogReport);
        if ($truncationWarning !== '') {
            $warnings[] = $truncationWarning;
        }
        $staleCount = (int)($this->catalogReport['stale'] ?? 0);
        if ($staleCount > 0) {
            $staleIds = (array)($this->catalogReport['stale_ids'] ?? []);
            $warnings[] = sprintf(
                '%d catalogue row(s) are no longer served by their provider and have been marked stale and '
                    . 'inactive, so nothing can dispatch to them: %s%s',
                $staleCount,
                implode(', ', array_slice($staleIds, 0, 8)),
                count($staleIds) > 8 ? ', …' : '',
            );
        }

        $invariant = $this->verifyCatalogInvariant($catalogRows, $plans, $selection, $linkedChanges);
        $this->invariantReport = $invariant;
        if ($invariant['ok'] !== true) {
            foreach ($invariant['violations'] as $violation) {
                $warnings[] = $violation['message'];
            }
            return $this->finish($job, $started, self::STAGE_PROBLEMS, $warnings, $assigned, $diff, $selection, [
                'message' => sprintf(
                    'Actualization aborted: the result would not hold together — %d reference(s) would point '
                        . 'at a model the catalogue does not hold or keeps inactive. Nothing was written. '
                        . 'First: %s',
                    count($invariant['violations']),
                    $invariant['violations'][0]['message'],
                ),
                'error'   => [
                    'stage'     => self::STAGE_ASSIGNING,
                    'reason'    => 'catalog_invariant',
                    'invariant' => $invariant,
                ],
            ]);
        }

        try {
            $batchRows = $this->batchOnlyRows($catalogRows);
            if ($batchRows !== []) {
                $warnings[] = sprintf(
                    '%d catalogue row(s) are batch-only identifiers and stay inactive: they are served through '
                        . 'the asynchronous Batch API, so the synchronous consensus pool cannot call them: %s.',
                    count($batchRows),
                    implode(', ', array_slice($batchRows, 0, 8))
                        . (count($batchRows) > 8 ? ', …' : ''),
                );
            }
            $this->writeCatalog($catalogRows);
            $this->writePresets($plans, $presetModels);
            $this->writeSelection($selection);
            // After writeSelection(): the pipeline keys are merged in by
            // dot-notation, so the pool write must not overwrite them (and
            // the pool write itself replaces the whole `pipeline` block).
            LinkedModels::apply($linkedChanges);
        } catch (\Throwable $e) {
            // Restore whatever the partial write left behind, so "something
            // failed" never means "the app is now inconsistent".
            $restoreError = null;
            try {
                $this->restore($snapshot);
            } catch (\Throwable $restoreFailure) {
                $restoreError = $restoreFailure->getMessage();
            }
            $safeRestoreError = $restoreError === null ? null : Logger::sanitizeScalar($restoreError);
            $detail = $e instanceof \App\Core\ConfigWriteException
                ? $e->details()
                : ['message' => Logger::sanitizeScalar($e->getMessage())];
            // v1.8.51 — one verdict, two writers. The warning and the
            // summary message used to be built independently, and the
            // summary always claimed a restore that may never have
            // happened: the operator read "The snapshot could NOT be
            // restored" and "the previous state was restored" in the same
            // card, which is exactly the contradiction that hid a broken
            // rollback path. Both now branch on the same fact.
            $restoreFailed = $safeRestoreError !== null;
            $warnings[] = 'Writing failed (' . Logger::sanitizeScalar($e->getMessage()) . '). '
                . ($restoreFailed
                    ? 'The snapshot could NOT be restored: ' . $safeRestoreError
                    : 'The snapshot was restored, so the previous state is back.');
            $detail['restore_ok'] = !$restoreFailed;
            if ($restoreFailed) {
                // The UI's error block renders this key, so "what broke"
                // and "what it left behind" are readable without parsing
                // the prose out of `warnings[]`.
                $detail['restore_error'] = $safeRestoreError;
            }
            Logger::error('models.actualize.write_failed', [
                'job_id'  => $job->id,
                'error'   => Logger::sanitizeScalar($e->getMessage()),
                'restore' => $restoreFailed ? $safeRestoreError : 'ok',
            ]);
            return $this->finish($job, $started, self::STAGE_PROBLEMS, $warnings, $assigned, $diff, $selection, [
                'message' => $restoreFailed
                    ? 'Actualization failed while writing, and the previous state could NOT be restored: '
                        . $safeRestoreError . ' The configuration may now be inconsistent — check '
                        . \App\Core\RuntimeConfig::FILE_RELATIVE . ' (and the consensus presets) against the '
                        . 'snapshot, and repair it before starting another run.'
                    : 'Actualization failed while writing; the previous state was restored.',
                'error'   => $detail,
            ], []);
        }

        return $this->finish($job, $started, self::STAGE_SUCCESS, $warnings, $assigned, $diff, $selection, [
            'message' => sprintf(
                'Imported %d model(s) from %s (%d new row(s)); actualized %d consensus preset(s); '
                    . 'the catalogue holds %d row(s)%s, and every model the pool, the presets and the '
                    . 'linked roles reference is one of them.',
                (int)$this->importReport['fetched'],
                (string)$this->importReport['source'],
                count((array)$this->importReport['added']),
                count($plans),
                // v1.8.60 — the rows actually written, not the number of
                // candidates: after a truncation those differ, and the old
                // sentence reported the candidate count.
                (int)($this->catalogReport['kept'] ?? count($catalogRows)),
                (int)($this->catalogReport['dropped'] ?? 0) > 0
                    ? sprintf(' (%d left out by the row cap)', (int)$this->catalogReport['dropped'])
                    : '',
            ),
        ], $linkedChanges);
    }

    /**
     * The import source this run uses: the operator's choice from the
     * payload, or the default when the enqueue named none (a job queued by
     * an older client, or by the API directly).
     *
     * An unusable value is refused *quietly at this level* — and loudly by
     * the controller that accepted the enqueue — because a run must not die
     * before its first stage over a typo when there is a documented
     * default. What never happens is guessing a source the operator did not
     * name: `defaultSource()` only ever answers one of the two sources the
     * UI offers.
     */
    private static function importSource(Job $job): string
    {
        $raw = $job->payload[self::PAYLOAD_IMPORT_SOURCE] ?? null;
        return ModelImporter::normaliseSource($raw) ?? ModelImporter::defaultSource();
    }

    /**
     * Undo the most recent actualization.
     *
     * Restores the catalogue, every touched preset, the recorded model
     * pool and the linked models (analyzer, synthesis, arbiter) from the
     * persisted snapshot, in that order.
     *
     * @return array{ok:bool,restored:bool,message:string,taken_at:?string,selection:array<int,array{provider:string,model:string}>}
     */
    public function rollback(): array
    {
        $snapshot = $this->store->loadSnapshot();
        if ($snapshot === null) {
            return [
                'ok' => false, 'restored' => false,
                'message' => 'No snapshot is available to roll back to.',
                'taken_at' => null, 'selection' => [],
            ];
        }
        try {
            $this->restore($snapshot);
        } catch (\Throwable $e) {
            return [
                'ok' => false, 'restored' => false,
                'message' => 'Rollback failed: ' . Logger::sanitizeScalar($e->getMessage()),
                'taken_at' => isset($snapshot['taken_at']) ? (string)$snapshot['taken_at'] : null,
                'selection' => [],
            ];
        }
        $status = $this->store->loadStatus() ?? [];
        $status['stage'] = 'rolled_back';
        $status['message'] = 'Rolled back to the snapshot taken at ' . (string)($snapshot['taken_at'] ?? 'unknown') . '.';
        $status['snapshot'] = ['available' => true, 'taken_at' => $snapshot['taken_at'] ?? null];
        $this->store->saveStatus($status);

        $selection = [];
        if (is_array($snapshot['selection'] ?? null)) {
            foreach ($snapshot['selection'] as $row) {
                if (is_array($row) && isset($row['provider'], $row['model'])) {
                    $selection[] = ['provider' => (string)$row['provider'], 'model' => (string)$row['model']];
                }
            }
        }
        return [
            'ok' => true, 'restored' => true,
            'message' => $status['message'],
            'taken_at' => isset($snapshot['taken_at']) ? (string)$snapshot['taken_at'] : null,
            'selection' => $selection,
        ];
    }

    // =================================================================
    // Stage 1 — import
    // =================================================================

    /**
     * Fetch the chosen provider's catalogue and merge it into the run's
     * catalogue baseline.
     *
     * Nothing is persisted here. The merged rows are a planning input; the
     * write phase writes them together with the presets and the pool, so a
     * run that fails later leaves no half-imported catalogue behind. The
     * operator asked for the import as the *first step of the run*, not as
     * a separate commit — and the run's rollback path
     * ({@see self::buildSnapshot()}) covers it either way.
     *
     * @param array<int,string> $warnings appended in place
     * @return array{ok:bool,source:string,error:string,report:array<string,mixed>,catalog:array<int,array<string,mixed>>}
     */
    private function importCatalogue(string $source, array &$warnings): array
    {
        $fetched = $this->importer()->fetch($source);
        if ($fetched['ok'] !== true) {
            return [
                'ok'      => false,
                'source'  => $source,
                'error'   => sprintf(
                    'The import from "%s" failed: %s',
                    $source,
                    (string)($fetched['error'] ?? 'the provider gave no reason.'),
                ),
                'report'  => [],
                'catalog' => [],
            ];
        }
        if ($fetched['models'] === []) {
            return [
                'ok'      => false,
                'source'  => $source,
                'error'   => sprintf(
                    'The import from "%s" returned an empty catalogue, so there is nothing to analyse or assign. '
                        . 'Check the provider key and the base URL, then try again.',
                    $source,
                ),
                'report'  => [],
                'catalog' => [],
            ];
        }

        $report = ModelImporter::mergeReport(
            (array)Config::get('models', []),
            $source,
            $fetched['models'],
            // v1.8.60 — unlimited HERE on purpose. The cap belongs to the
            // planning stage, where the rows the pool and the presets
            // reference are known: cutting the list at the import would
            // decide "which model survives" before the run knows what it is
            // about to point at, which is how the pool and the catalogue
            // drifted apart in the first place. `planCatalog()` applies the
            // configured cap, protects the referenced rows and ranks the
            // rest ({@see CatalogTruncation}). The import report still
            // counts everything the provider sent, so nothing is lost from
            // the numbers — `truncated` stays 0 and the cap decision is
            // reported under `catalog` instead.
            0,
        );
        $catalog = self::catalogRowsFrom($report['rows']);

        // Rows the merge could not take are named, not silently dropped.
        if ((int)$report['truncated'] > 0) {
            $warnings[] = sprintf(
                '%d imported model(s) were left out: the catalogue is capped at %d rows and already held %d.',
                (int)$report['truncated'],
                (int)$report['limit'],
                (int)$report['present'],
            );
        }
        if ((int)$report['unusable'] > 0) {
            $warnings[] = sprintf(
                '%d entr(ies) in the "%s" catalogue carried no usable model id and were skipped.',
                (int)$report['unusable'],
                $source,
            );
        }
        if ($report['batch'] !== []) {
            $warnings[] = sprintf(
                '%d imported row(s) are batch-only identifiers and stay inactive: they are served through the '
                    . 'asynchronous Batch API, so the synchronous consensus pool cannot call them: %s.',
                count($report['batch']),
                implode(', ', array_slice($report['batch'], 0, 8))
                    . (count($report['batch']) > 8 ? ', …' : ''),
            );
        }

        return [
            'ok'      => true,
            'source'  => $source,
            'error'   => '',
            'report'  => $report,
            'catalog' => $catalog,
        ];
    }

    // =================================================================
    // Stage 2 — live lists
    // =================================================================

    /**
     * Query every available connector's live model list.
     *
     * @param array<int,string> $warnings appended in place
     * @return array{lists:array<string,array<int,mixed>>,providers:array<int,string>}
     */
    private function collectLiveLists(array &$warnings): array
    {
        $lists = [];
        $providers = [];
        $connectors = [];
        try {
            $connectors = $this->connectors->all();
        } catch (\Throwable $e) {
            $warnings[] = 'Connector registry unavailable: ' . Logger::sanitizeScalar($e->getMessage());
            return ['lists' => [], 'providers' => []];
        }
        foreach ($connectors as $connector) {
            $provider = strtolower($connector->getProviderName());
            $providers[] = $provider;
            try {
                $list = $connector->listModels();
            } catch (\Throwable $e) {
                // listModels() is documented to swallow transport faults,
                // but a stub or a future connector may throw: treat it the
                // same way and keep going with the other providers.
                Logger::warn('models.actualize.list_models_failed', [
                    'provider' => $provider,
                    'error'    => Logger::sanitizeScalar($e->getMessage()),
                ]);
                $list = [];
            }
            $lists[$provider] = is_array($list) ? $list : [];
        }
        return ['lists' => $lists, 'providers' => $providers];
    }

    /** The persisted catalogue, normalised to provider/model/active rows. */
    private function catalogRows(): array
    {
        return self::catalogRowsFrom((array)Config::get('models', []));
    }

    /**
     * Normalise catalogue input rows.
     *
     * Descriptive fields a row may carry (`label`, `rank`, `category`,
     * `default`) are kept, not stripped. A run rewrites the whole list, and
     * a three-key rewrite is how every actualization used to empty the
     * Models tab's label column — the Import button preserves them, and
     * since the import is now the run's first step, dropping them here
     * would wipe the labels of every row the import had just added.
     *
     * @param  array<int,mixed> $rows
     * @return array<int,array<string,mixed>>
     */
    private static function catalogRowsFrom(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) { continue; }
            $provider = strtolower(trim((string)($row['provider'] ?? '')));
            $model    = trim((string)($row['model'] ?? ''));
            if ($provider === '' || $model === '') { continue; }
            $out[] = $row + [
                'provider' => $provider,
                'model'    => $model,
                'active'   => (bool)($row['active'] ?? false),
            ];
        }
        return $out;
    }

    // =================================================================
    // Stage 2 — assignment
    // =================================================================

    /**
     * Presets in a stable order: the known three first (in the order the
     * prompt thinks about them), then anything an operator added, sorted
     * by slug so a run is reproducible.
     *
     * @param array<int,DomainPreset> $presets
     * @return array<int,string>
     */
    private function orderedSlugs(array $presets): array
    {
        $present = [];
        foreach ($presets as $preset) {
            $present[$preset->slug] = true;
        }
        $ordered = [];
        foreach (self::PRESET_ORDER as $slug) {
            if (isset($present[$slug])) {
                $ordered[] = $slug;
                unset($present[$slug]);
            }
        }
        $rest = array_keys($present);
        sort($rest);
        return array_merge($ordered, $rest);
    }

    /**
     * Ask the analyzer model for one preset's pool and validate the answer.
     *
     * @param array<int,array{provider:string,model:string}> $live
     * @return array{ok:bool,plan:array<string,mixed>|null,error:?string,attempts:int,corrections:int}
     *         `ok=false` when the analyzer could not be reached, or when its
     *         answer broke the output contract on both attempts, or when the
     *         answer named no model that is actually available. `error`
     *         carries the parser's own words when there are any, so the run
     *         can distinguish "the model is down" from "the model will not
     *         follow the contract".
     */
    private function assignFor(string $slug, string $domain, array $live): array
    {
        $call = $this->callAnalyzer($slug, $domain, $live);
        if ($call['ok'] !== true || $call['answer'] === null) {
            return [
                'ok'          => false,
                'plan'        => null,
                'error'       => $call['error'],
                'attempts'    => $call['attempts'],
                'corrections' => $call['corrections'],
            ];
        }
        $raw = $call['answer'];
        $warnings = [];
        $resolved = [];
        // Ids the analyzer named that are batch-only. They are refused by
        // resolvePick() (never dispatched), and named in the report rather
        // than vanishing silently — the operator sees that the model was
        // seen and why it was not used.
        $batchNamed = [];
        foreach ((array)($raw['models'] ?? []) as $pick) {
            $named = self::namedModel($pick);
            if ($named !== null && ModelId::isBatchId($named)) { $batchNamed[$named] = true; }
            $row = $this->resolvePick($pick, $live);
            if ($row === null) { continue; }
            $key = ModelCatalogDiff::key($row['provider'], $row['model']);
            if (isset($resolved[$key])) { continue; }
            $resolved[$key] = [
                'provider' => $row['provider'],
                'model'    => $row['model'],
                'label'    => self::labelFor($row['model']),
                'active'   => true,
                'selected' => true,
            ];
        }
        $arbiterNamed = self::namedModel($raw['arbiter'] ?? null);
        if ($arbiterNamed !== null && ModelId::isBatchId($arbiterNamed)) { $batchNamed[$arbiterNamed] = true; }
        if ($batchNamed !== []) {
            $warnings[] = sprintf(
                'Ignored %d batch-only identifier(s) named for "%s" — they are served through the '
                    . 'asynchronous Batch API and cannot be called synchronously: %s.',
                count($batchNamed),
                $slug,
                implode(', ', array_keys($batchNamed)),
            );
        }
        if ($resolved === []) {
            $warnings[] = sprintf('The analyzer answer for "%s" named no model that is actually available.', $slug);
            return [
                'ok'          => false,
                'plan'        => null,
                'error'       => sprintf(
                    'the answer for "%s" named no model from the live catalogue (every id it used was unknown '
                        . 'or batch-only).',
                    $slug,
                ),
                'attempts'    => $call['attempts'],
                'corrections' => $call['corrections'],
            ];
        }

        $picks = array_values($resolved);
        if ($domain === 'default') {
            // "Strongest, but from different base providers" is the actual
            // requirement for every non-specialist preset, and a model
            // reply cannot be trusted to have honoured it.
            $spread = $this->diversify($picks, $live, max(self::DEFAULT_FAMILY_SPREAD, count($picks)));
            $picks = $spread['picks'];
            $warnings = array_merge($warnings, $spread['warnings']);
        }

        if (count($picks) > self::MAX_MODELS_PER_PRESET) {
            $picks = array_slice($picks, 0, self::MAX_MODELS_PER_PRESET);
        }

        $arbiter = $this->resolvePick($raw['arbiter'] ?? null, $live);
        if ($arbiter === null) {
            $first = $picks[0];
            $arbiter = ['provider' => $first['provider'], 'model' => $first['model']];
            $warnings[] = sprintf(
                'No usable arbiter was named for "%s"; defaulted to %s/%s.',
                $slug,
                $arbiter['provider'],
                $arbiter['model'],
            );
        }
        return [
            'ok'   => true,
            'error' => null,
            'attempts'    => $call['attempts'],
            'corrections' => $call['corrections'],
            'plan' => [
                'models'   => $picks,
                'arbiter'  => ['provider' => $arbiter['provider'], 'model' => $arbiter['model'], 'role' => 'main'],
                // The answer's own justification, carried into the report.
                // It is part of the output contract, so it is always there.
                'reason'   => (string)($raw['reason'] ?? ''),
                'warnings' => $warnings,
            ],
        ];
    }

    /**
     * The model id inside an analyzer pick (`{provider,model}` or a bare
     * string), or null when the pick names nothing. Used to report ids the
     * run refused — the one thing {@see self::resolvePick()} cannot do,
     * because a refusal and a hallucination look the same from its side.
     */
    private static function namedModel(mixed $pick): ?string
    {
        if (is_string($pick)) {
            $pick = ['model' => $pick];
        }
        if (!is_array($pick)) {
            return null;
        }
        $model = trim((string)($pick['model'] ?? ''));
        return $model === '' ? null : $model;
    }

    /**
     * Force a pool to span distinct base provider families.
     *
     * The analyzer's picks are kept in order, one per family; when a family
     * repeats, the stand-in is the strongest* remaining live model from a
     * family that is not represented yet — strongest by catalogue order,
     * which is the providers' own ranking. If the live catalogue simply
     * does not carry that many families, the remaining picks are kept and
     * a warning says so: padding a pool with a near-duplicate would be a
     * worse answer than an honest shorter spread.
     *
     * @param array<int,array<string,mixed>> $picks validated rows, in order
     * @param array<int,array{provider:string,model:string}> $live
     * @return array{picks:array<int,array<string,mixed>>,families:array<int,string>,warnings:array<int,string>}
     */
    public function diversify(array $picks, array $live, int $limit): array
    {
        $warnings = [];
        $keep = [];
        $families = [];
        $overflow = [];
        foreach ($picks as $row) {
            $family = ProviderFamily::of((string)$row['model']);
            if (isset($families[$family])) {
                $overflow[] = $row;
                continue;
            }
            $families[$family] = true;
            $keep[] = $row;
        }

        // Fill from the catalogue, preferring families nobody covers yet.
        if (count($keep) < $limit) {
            $candidates = [];
            foreach ($live as $row) {
                // A stand-in has to be dispatchable synchronously: a
                // batch-only id would fill the pool with a model L1 cannot
                // call at all.
                if (ModelId::isBatchId((string)$row['model'])) { continue; }
                $family = ProviderFamily::of((string)$row['model']);
                if (isset($families[$family])) { continue; }
                $candidates[$family] = $row;
            }
            foreach ($candidates as $family => $row) {
                if (count($keep) >= $limit) { break; }
                $families[$family] = true;
                $keep[] = [
                    'provider' => $row['provider'],
                    'model'    => $row['model'],
                    'label'    => self::labelFor($row['model']),
                    'active'   => true,
                    'selected' => true,
                ];
            }
        }

        if ($overflow !== []) {
            $duplicateFamilies = [];
            foreach ($overflow as $row) {
                $duplicateFamilies[] = ProviderFamily::of((string)$row['model']);
            }
            $unique = array_values(array_unique($duplicateFamilies));
            if (count($keep) < $limit) {
                // Not enough distinct families were available: keep the
                // extra picks rather than shipping a thinner pool.
                foreach ($overflow as $row) {
                    if (count($keep) >= $limit) { break; }
                    $keep[] = $row;
                }
                $warnings[] = sprintf(
                    'The live catalogue only spans %d distinct provider famil%s, so the pool repeats: %s.',
                    count($families),
                    count($families) === 1 ? 'y' : 'ies',
                    implode(', ', $unique),
                );
            } else {
                $warnings[] = sprintf(
                    'Dropped %d model(s) from the same provider famil%s to keep the pool diverse: %s.',
                    count($overflow),
                    count($unique) === 1 ? 'y' : 'ies',
                    implode(', ', $unique),
                );
            }
        }

        return [
            'picks'    => $keep,
            'families' => array_keys($families),
            'warnings' => $warnings,
        ];
    }

    /**
     * Validate one analyzer pick against the live catalogue.
     *
     * The analyzer answers with ids it saw in the prompt; a hallucinated or
     * half-remembered id is dropped, as is a provider that is not in the
     * live set at all, and so is a batch-only identifier — it is served by
     * the asynchronous Batch API and can never take part in a synchronous
     * pool ({@see ModelId}).
     *
     * @param mixed $pick
     * @param array<int,array{provider:string,model:string}> $live
     * @return array{provider:string,model:string}|null
     */
    private function resolvePick(mixed $pick, array $live): ?array
    {
        if (is_string($pick)) {
            $pick = ['model' => $pick];
        }
        if (!is_array($pick)) {
            return null;
        }
        $model = trim((string)($pick['model'] ?? ''));
        $provider = strtolower(trim((string)($pick['provider'] ?? '')));
        if ($model === '' || ModelId::isBatchId($model)) {
            return null;
        }
        foreach ($live as $row) {
            if ($row['model'] !== $model) { continue; }
            if ($provider !== '' && $row['provider'] !== $provider) { continue; }
            return ['provider' => $row['provider'], 'model' => $row['model']];
        }
        // The analyzer may name a model without repeating the provider —
        // accept it when exactly one live provider serves that id.
        if ($provider === '') {
            $matches = array_values(array_filter($live, static fn(array $r): bool => $r['model'] === $model));
            if (count($matches) === 1) {
                return ['provider' => $matches[0]['provider'], 'model' => $matches[0]['model']];
            }
        }
        return null;
    }

    /**
     * Call the analyzer model through the connector layer, and hold its
     * answer to the output contract.
     *
     * Which model answers comes from {@see ModelsActualizeSettings}: the
     * Actualize section's own `models.actualize.analyzer` when the operator
     * set one, and `analyzer.provider` / `analyzer.model` otherwise — so
     * the default behaviour is exactly what it was before the section had a
     * selector, and picking a model there never re-points the Request
     * Analyzer.
     *
     * The response cache is consulted first and written after, exactly as
     * the pipeline does it — an unchanged catalogue and an unchanged prompt
     * cost one call, not one per run. A *cached* answer that violates the
     * contract is not reused: it may have been stored before the contract
     * existed, and spending the run's single corrective retry on a stored
     * mistake would be blaming the model for the cache.
     *
     * Contract handling is deliberate about its budget: one answer, one
     * correction, then a refusal. The correction quotes the parser's own
     * rejection verbatim and restates the contract, because "invalid JSON"
     * tells a model nothing it can act on.
     *
     * @param array<int,array{provider:string,model:string}> $live
     * @return array{ok:bool,answer:array<string,mixed>|null,error:?string,attempts:int,corrections:int}
     *         `error=null` means "the analyzer could not be reached":
     *         there is no parser rejection to quote.
     */
    private function callAnalyzer(string $slug, string $domain, array $live): array
    {
        $choice   = $this->preferences()->resolve();
        $provider = (string)$choice['provider'];
        $model    = (string)$choice['model'];
        // Cumulative across the whole run: which model answered, where the
        // value came from, and how many calls it took *in total*. Resetting
        // the counters per preset would report the last preset's cost as the
        // run's, which is the number an operator comparing two models would
        // be reading.
        $this->analyzerUsage = [
            'provider'    => $provider,
            'model'       => $model,
            'source'      => (string)$choice['source'],
            'note'        => $choice['note'],
            'requests'    => (int)($this->analyzerUsage['requests'] ?? 0),
            'corrections' => (int)($this->analyzerUsage['corrections'] ?? 0),
            'attempts'    => (int)($this->analyzerUsage['attempts'] ?? 0),
        ];
        if (is_string($choice['note']) && $choice['note'] !== '') {
            Logger::warn('models.actualize.analyzer_override_note', ['note' => $choice['note']]);
        }
        try {
            $connector = $this->connectors->getByProvider($provider);
        } catch (\Throwable $e) {
            Logger::warn('models.actualize.connector_lookup_failed', [
                'provider' => $provider,
                'error'    => Logger::sanitizeScalar($e->getMessage()),
            ]);
            return self::analyzerFailure(null, 0, 0);
        }
        if ($connector === null) {
            Logger::warn('models.actualize.connector_missing', ['provider' => $provider, 'model' => $model]);
            return self::analyzerFailure(null, 0, 0);
        }

        $messages = [
            ['role' => 'system', 'content' => self::systemPrompt()],
            ['role' => 'user',   'content' => $this->assignmentPrompt($slug, $domain, $live)],
        ];
        $options = [
            'temperature' => 0,
            'max_tokens'  => self::MAX_COMPLETION_TOKENS,
            'timeout'     => self::ANALYZER_TIMEOUT_SECONDS,
        ];

        // ---- the cache: reuse only an answer that still satisfies the
        // contract, so a stored mistake cannot consume the retry budget.
        $cached = $this->cachedAnswer($provider, $model, $messages, $options);
        if ($cached !== null) {
            $parsed = self::parseAnswer($cached);
            if ($parsed['ok'] === true) {
                return ['ok' => true, 'answer' => $parsed['answer'], 'error' => null, 'attempts' => 0, 'corrections' => 0];
            }
            Logger::warn('models.actualize.cached_answer_rejected', [
                'provider' => $provider,
                'model'    => $model,
                'slug'     => $slug,
                'error'    => (string)$parsed['error'],
            ]);
        }

        // ---- live: the answer, then exactly one correction.
        $attempts    = 0;
        $corrections = 0;
        $lastError   = null;
        while ($attempts < self::ANALYZER_ATTEMPTS) {
            $attempts++;
            $content = $this->liveAnswer($connector, $provider, $model, $messages, $options);
            if ($content === null) {
                return self::analyzerFailure(null, $attempts, $corrections);
            }
            $parsed = self::parseAnswer($content);
            if ($parsed['ok'] === true) {
                // The identifiers are part of the contract, not just the
                // shape: an answer that names nothing the catalogue serves
                // is a refusal wearing the right braces. It spends the same
                // single correction, because "the model invented ids" is
                // fixable by saying so, and a run that is about to fail
                // anyway loses nothing by asking once more.
                $idError = $this->unknownIdsVerdict($parsed['answer'], $live);
                if ($idError === null) {
                    $this->countAnalyzerWork($attempts, $corrections);
                    return ['ok' => true, 'answer' => $parsed['answer'], 'error' => null, 'attempts' => $attempts, 'corrections' => $corrections];
                }
                $parsed = self::contractViolation($idError);
            }
            $lastError = (string)$parsed['error'];
            Logger::warn('models.actualize.answer_rejected', [
                'provider' => $provider,
                'model'    => $model,
                'slug'     => $slug,
                'attempt'  => $attempts,
                'error'    => $lastError,
            ]);
            if ($attempts >= self::ANALYZER_ATTEMPTS) {
                break;
            }
            // The correction: what was wrong (verbatim) and what the
            // contract asks for, then one more chance — no more.
            $messages[] = ['role' => 'assistant', 'content' => $content];
            $messages[] = ['role' => 'user', 'content' => self::correctivePrompt($lastError)];
            $corrections++;
        }
        $this->countAnalyzerWork($attempts, $corrections);
        return self::analyzerFailure($lastError, $attempts, $corrections);
    }

    /**
     * Fold one preset's attempts into the run's totals, so the report says
     * what the whole run cost rather than what its last preset cost.
     */
    private function countAnalyzerWork(int $attempts, int $corrections): void
    {
        $this->analyzerUsage['attempts']    = (int)($this->analyzerUsage['attempts'] ?? 0) + $attempts;
        $this->analyzerUsage['corrections'] = (int)($this->analyzerUsage['corrections'] ?? 0) + $corrections;
    }

    /**
     * Why an answer whose *shape* is fine still cannot be used, or null when
     * it can.
     *
     * Only one rule lives here, deliberately: "at least one named id is
     * actually served". A mixed answer is NOT a violation — {@see
     * self::resolvePick()} drops the ids that are not in the catalogue and
     * the report names them, which is the documented behaviour this must not
     * replace. An answer where *nothing* resolves is different in kind: the
     * model answered about a catalogue it does not have, and no local
     * tolerance can turn that into a pool.
     *
     * @param array<string,mixed> $answer
     * @param array<int,array{provider:string,model:string}> $live
     */
    private function unknownIdsVerdict(array $answer, array $live): ?string
    {
        $named = 0;
        foreach ((array)($answer['models'] ?? []) as $pick) {
            if (self::namedModel($pick) !== null) { $named++; }
            if ($this->resolvePick($pick, $live) !== null) { return null; }
        }
        // The arbiter is NOT a rescue here: a pool needs participants, and an
        // answer whose models all came from somewhere else is unusable even
        // if it guessed one name the catalogue serves.
        return sprintf(
            'none of the %d identifier(s) in "models" is in the live catalogue, so the answer describes models '
                . 'that are not being offered. Copy every provider and model verbatim from the catalogue in the '
                . 'message above; if a vendor family is not listed there, leave it out rather than inventing an '
                . 'id for it.',
            $named,
        );
    }

    /**
     * @return array{ok:bool,answer:null,error:?string,attempts:int,corrections:int}
     */
    private static function analyzerFailure(?string $error, int $attempts, int $corrections): array
    {
        return ['ok' => false, 'answer' => null, 'error' => $error, 'attempts' => $attempts, 'corrections' => $corrections];
    }

    /**
     * Read-only cache probe: the stored answer for this exact prompt, or
     * null. Never throws — a cache that misbehaves must not fail a run.
     *
     * @param array<int,array<string,mixed>> $messages
     * @param array<string,mixed>            $options
     */
    private function cachedAnswer(string $provider, string $model, array $messages, array $options): ?string
    {
        if ($this->cache === null) {
            return null;
        }
        try {
            $hit = $this->cache->lookup($provider, $model, $messages, $options);
        } catch (\Throwable $e) {
            Logger::warn('models.actualize.cache_lookup_failed', ['error' => Logger::sanitizeScalar($e->getMessage())]);
            return null;
        }
        if ($hit === null) {
            return null;
        }
        $content = (string)($hit['response']->content ?? '');
        return trim($content) === '' ? null : $content;
    }

    /**
     * One live call, stored in the response cache. Null when the provider
     * could not be reached — the caller distinguishes that from a rejected
     * answer, because the two need different sentences in the report.
     *
     * @param array<int,array<string,mixed>> $messages
     * @param array<string,mixed>            $options
     */
    private function liveAnswer(
        ConnectorInterface $connector,
        string $provider,
        string $model,
        array $messages,
        array $options,
    ): ?string {
        try {
            $response = $connector->chat($model, $messages, $options);
            if ($this->cache !== null) {
                $this->cache->store($provider, $model, $messages, $options, $response);
            }
        } catch (\Throwable $e) {
            Logger::warn('models.actualize.analyzer_failed', [
                'provider' => $provider,
                'model'    => $model,
                'error'    => Logger::sanitizeScalar($e->getMessage()),
            ]);
            return null;
        }
        $this->analyzerUsage['requests'] = (int)($this->analyzerUsage['requests'] ?? 0) + 1;
        return (string)$response->content;
    }

    /**
     * The assignment contract — the prompt and the parser are one artefact.
     *
     * This text is a *contract*, not a suggestion: what it promises, the
     * local parser ({@see self::parseAnswer()}) enforces, and what the
     * parser rejects, the corrective retry
     * ({@see self::correctivePrompt()}) quotes back verbatim. Changing one
     * without the other would make a correct answer look wrong, so both
     * live here and {@see ModelsActualizeTest} pins the parts that matter:
     * the worked example, the ban on fences and on any text around the
     * object, the demand that ids be copied verbatim, and the per-preset
     * pool rules.
     *
     * The example is drawn from a catalogue that does not exist, and the
     * prompt says so in the same breath (v1.8.53). An example built from
     * real ids would be an instruction to answer with *those* ids — which is
     * the one thing the contract forbids, and the failure it is there to
     * prevent. Before this version the prompt named the shape with `"..."` in
     * every field and carried no example at all, which is what "the analyzer
     * answers with prose I have to parse" was.
     */
    private static function systemPrompt(): string
    {
        return <<<'PROMPT'
You are RevoAp's model-assignment assistant. Your job is to choose which large language models form a
multi-model consensus pool, and to name the single model that arbitrates it.

## What you are given
A live catalogue of the models that are actually available right now, one per line, written as
`provider|model`. That catalogue is the only set of identifiers that exists. Every identifier you
return must be copied from it, character for character.

## Output contract — non-negotiable
1. Return ONE JSON object and nothing else. No greeting, no explanation, no summary, no apology.
2. Never wrap it in ``` fences, in ```json fences, or in any other markup.
3. Never write anything before the opening "{" or after the closing "}". Not one character.
4. Never use placeholders. "..." , "<model>", "TODO" and "same as above" are not identifiers.
5. Copy every "provider" and "model" string verbatim from the catalogue you were given.
6. Use exactly these three keys: "models", "arbiter", "reason". No others.
7. "models" holds 4 to 8 entries, as long as the catalogue can supply that many.
8. "reason" is one short sentence justifying this pool for this preset.

## The exact shape — an illustration only
The providers and ids below are MADE UP, to show the shape of the answer. They do not exist, they are
not in any catalogue, and they are not an invitation to invent identifiers. Copying them is wrong,
and so is answering with an id that is not in the catalogue you were actually given: a vendor family
the catalogue does not contain is simply skipped, never guessed.

{
  "models": [
    {"provider": "nimbus", "model": "nimbus-9-ultra"},
    {"provider": "helios", "model": "helios-4-opus"},
    {"provider": "vanta", "model": "vanta-reason-3.5"},
    {"provider": "kepler", "model": "kepler-5-pro-preview"},
    {"provider": "orchid", "model": "orchid-3-flagship"},
    {"provider": "borealis", "model": "borealis-r2"}
  ],
  "arbiter": {"provider": "helios", "model": "helios-4-opus"},
  "reason": "One flagship per vendor family, and the strongest general reasoner among them as arbiter."
}

Answer in exactly that shape, with the identifiers taken from the catalogue you were given and with
nothing around the object. That JSON object, alone, is the entire answer.

## How to choose
- STRONGLY prefer the newest, most capable release of each major vendor family that the catalogue
  actually contains: GPT/ChatGPT (OpenAI), Claude/Opus (Anthropic), Grok (xAI), Qwen (Alibaba),
  DeepSeek, Gemini (Google), Llama (Meta), Mistral and GLM (Zhipu).
- Prefer a current flagship over an older or lite variant of the same family, and prefer a
  reasoning-tuned release over a chat-only one.
- Never invent a model id or a vendor family. An id that is not in the catalogue is a wrong answer.
- For a "default" pool the models MUST come from DIFFERENT base providers — one model per vendor
  family. Two models from the same family are a wasted seat: independent opinions only exist when
  the vendors differ.
- For the "mathematics" and "physics" pools, capability beats diversity: choose the models that are
  strongest at that preset's own domain and let the spread take care of itself.
- "arbiter" is the single best model in your pool for checking the others' work.
PROMPT;
    }

    /**
     * The one corrective message a run may send.
     *
     * It quotes the parser's rejection verbatim — "invalid JSON" is not
     * something a model can act on, while "the answer starts with 'Sure!
     * Here is…'" is — and restates the contract in one paragraph. Sent
     * once; a second failure is a refusal, not a third attempt.
     */
    private static function correctivePrompt(string $error): string
    {
        return 'Your previous answer was rejected before it was used. The rejection was, verbatim: "'
            . $error . '". Answer the same question again, with the same catalogue, and change nothing '
            . 'else about the task. Reply with ONE JSON object and nothing else: no ``` fences, no '
            . 'comments, no text before the opening "{" or after the closing "}", no "..." or other '
            . 'placeholders. Every provider and model string must be copied verbatim from the catalogue '
            . 'in the first message. The shape is exactly '
            . '{"models":[{"provider":string,"model":string}],"arbiter":{"provider":string,"model":string},'
            . '"reason":string}, with 4 to 8 entries in "models".';
    }

    /**
     * The strict reading of an assignment answer.
     *
     * Strict on purpose: the whole point of a contract is that one shape is
     * accepted, so a fenced object, a sentence of commentary or a `...`
     * placeholder is reported as the violation it is — and the *reason* is
     * returned, because the retry has to quote it. Lenient extraction used
     * to be the rule here ("models wrap JSON in prose regardless"), which
     * is exactly why the prompt could not be tightened: nothing failed, so
     * nothing improved.
     *
     * What is deliberately NOT checked here: whether the ids exist. That is
     * {@see self::resolvePick()}'s job — a hallucinated id is dropped with
     * a warning rather than retried, because an id the catalogue does not
     * serve and a family the catalogue does not have are the same answer
     * ("not available"), and the local side is the one that knows the
     * catalogue.
     *
     * @return array{ok:bool,answer:array<string,mixed>|null,error:?string}
     */
    private static function parseAnswer(string $raw): array
    {
        $text = trim($raw);
        if ($text === '') {
            return self::contractViolation('the answer was empty.');
        }
        if (!str_starts_with($text, '{') || !str_ends_with($text, '}')) {
            return self::contractViolation(sprintf(
                'the answer is not a single JSON object — it begins with "%s". Remove every character '
                    . 'before the opening "{" and after the closing "}", including any ``` fence.',
                self::preview($text),
            ));
        }
        $decoded = json_decode($text, true);
        if (!is_array($decoded)) {
            return self::contractViolation('the answer is not valid JSON (' . json_last_error_msg() . ').');
        }

        $models = $decoded['models'] ?? null;
        if (!is_array($models) || $models === []) {
            return self::contractViolation('"models" must be a non-empty array of {"provider","model"} objects.');
        }
        foreach ($models as $index => $pick) {
            $pair = self::contractPair($pick);
            if ($pair !== null) {
                return self::contractViolation(sprintf(
                    '"models"[%d] %s. Every entry must be {"provider":string,"model":string}, copied from '
                        . 'the catalogue.',
                    (int)$index,
                    $pair,
                ));
            }
        }
        $arbiter = self::contractPair($decoded['arbiter'] ?? null);
        if ($arbiter !== null) {
            return self::contractViolation('"arbiter" ' . $arbiter . '. It must be one {"provider":string,'
                . '"model":string} object copied from the catalogue.');
        }
        $reason = $decoded['reason'] ?? null;
        if (!is_string($reason) || trim($reason) === '') {
            return self::contractViolation('"reason" must be a non-empty string.');
        }

        return ['ok' => true, 'answer' => $decoded, 'error' => null];
    }

    /**
     * Why one `{provider, model}` pair is unusable, or null when it is
     * fine. One rule, used for every pick and for the arbiter, so the two
     * cannot drift apart.
     */
    private static function contractPair(mixed $pick): ?string
    {
        if (!is_array($pick)) {
            return 'is missing';
        }
        foreach (['provider', 'model'] as $field) {
            $value = $pick[$field] ?? null;
            if (!is_string($value) || trim($value) === '') {
                return 'has no "' . $field . '" string';
            }
            if (self::looksLikePlaceholder($value)) {
                return 'uses the placeholder "' . trim($value) . '" instead of an identifier';
            }
        }
        return null;
    }

    /**
     * The shapes a model reaches for when it has not been given a real id:
     * an ellipsis, an angle-bracketed name, or one of the field labels
     * itself. Cheap to detect and the only defence that catches them before
     * they reach the pool as a "model".
     */
    private static function looksLikePlaceholder(string $value): bool
    {
        $value = trim($value);
        if ($value === '' || str_contains($value, '...')) {
            return true;
        }
        if (str_starts_with($value, '<') && str_ends_with($value, '>')) {
            return true;
        }
        return in_array(strtolower($value), [
            'todo', 'tbd', 'n/a', 'na', 'null', 'none',
            'provider', 'model', 'model_id', 'model-id', 'provider|model',
        ], true);
    }

    /**
     * @param  string $error
     * @return array{ok:bool,answer:null,error:string}
     */
    private static function contractViolation(string $error): array
    {
        return ['ok' => false, 'answer' => null, 'error' => $error];
    }

    /** A short, single-line look at the start of an answer, for the report. */
    private static function preview(string $text): string
    {
        $oneLine = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        $cut = mb_substr($oneLine, 0, 60);
        return mb_strlen($oneLine) > 60 ? $cut . '…' : $cut;
    }

    /**
     * @param array<int,array{provider:string,model:string}> $live
     */
    private function assignmentPrompt(string $slug, string $domain, array $live): string
    {
        $lines = [];
        foreach ($live as $row) {
            $family = ProviderFamily::of($row['model']);
            $lines[] = sprintf('%s|%s (family: %s)', $row['provider'], $row['model'], ProviderFamily::label($family));
        }
        $catalogue = implode("\n", $lines);
        if (strlen($catalogue) > 12000) {
            $catalogue = substr($catalogue, 0, 12000) . "\n… (truncated)";
        }

        $families = [];
        foreach (ProviderFamily::catalog() as $family => $label) {
            $families[] = $family . ' (' . $label . ')';
        }
        $familyList = implode(', ', $families);

        if ($domain === 'mathematics') {
            $instruction = 'This pool is for MATHEMATICS. Pick the models that are strongest at symbolic '
                . 'reasoning, multi-step algebra, proofs and exact arithmetic. Reasoning-tuned models '
                . 'outrank general chat models here. Diversity across providers is welcome but is NOT '
                . 'the priority — capability in mathematics is.';
        } elseif ($domain === 'physics') {
            $instruction = 'This pool is for PHYSICS. Pick the models that are strongest at quantitative '
                . 'physics, unit and dimensional analysis, conservation laws and limiting-case checks. '
                . 'Reasoning-tuned models outrank general chat models here. Diversity across providers '
                . 'is welcome but is NOT the priority — capability in physics is.';
        } else {
            $instruction = 'This pool is the DEFAULT one, used for everything that is not mathematics or '
                . 'physics. Pick the strongest general-purpose models, but they MUST come from DIFFERENT '
                . 'base providers — one model per vendor family. Independent opinions only exist when the '
                . 'vendors differ, so two models from the same family are a wasted seat.';
        }

        // v1.8.50 — name the current flagship of each family the catalogue
        // actually contains. The families are already listed below, but a
        // list of *vendor names* invites the model to answer with the
        // version it remembers (`grok-3`, `qwen2.5-max`) instead of the one
        // the provider serves today. Naming live ids removes the guesswork;
        // a family with no live row is not mentioned at all, so this can
        // never become an instruction to invent one.
        $highlights = self::preferredHighlights($live);
        $preferredLine = $highlights === []
            ? ''
            : "\nStrongest current release present for the preferred families — pick from these when they "
                . "fit the pool: " . implode(', ', $highlights) . ".\n";

        return $instruction . "\n\n"
            . 'Known provider families (for the diversity rule): ' . $familyList . ".\n"
            . 'Preferred when available: GPT/ChatGPT (OpenAI), Claude/Opus (Anthropic), Grok (xAI), '
            . 'Qwen (Alibaba), DeepSeek, Gemini (Google), Llama (Meta), Mistral, GLM (Zhipu). '
            . 'Prefer the newest flagship of a family over an older or lite variant of it.' . $preferredLine
            . "\n" . 'Live catalogue (choose ONLY from these):' . "\n" . $catalogue . "\n\n"
            . 'Preset slug: ' . $slug . "\n"
            . 'Also name an arbiter: the single best model to act as main/checker for this pool.' . "\n\n"
            // The contract is restated where the catalogue is, not only in
            // the system message: the two halves are read together, and a
            // model that has just been handed 60 ids is the one that starts
            // summarising instead of answering.
            . 'Reply with ONE JSON object and nothing else — no fences, no prose before or after it, no '
            . 'placeholders. Copy every provider and model from the list above, verbatim.';
    }

    /**
     * One "provider|model" per preferred family, taken from the live
     * catalogue.
     *
     * For each family keyword that appears in the live ids, the newest
     * release on the matching line wins ({@see ModelVersion}) — so a
     * catalogue holding `qwen3.6-max-preview` and `qwen3.5-plus` is
     * represented by one Qwen id, not by an arbitrary one.
     *
     * @param  array<int,array{provider:string,model:string}> $live
     * @return array<int,string>
     */
    private static function preferredHighlights(array $live): array
    {
        $keywords = [
            'gpt'      => 'OpenAI / GPT',
            'chatgpt'  => 'OpenAI / ChatGPT',
            'opus'     => 'Anthropic / Opus',
            'claude'   => 'Anthropic / Claude',
            'gemini'   => 'Google / Gemini',
            'grok'     => 'xAI / Grok',
            'qwen'     => 'Alibaba / Qwen',
            'deepseek' => 'DeepSeek',
            'llama'    => 'Meta / Llama',
            'mistral'  => 'Mistral',
            'glm'      => 'Zhipu / GLM',
            'astra'    => 'Astra',
        ];
        $out = [];
        $seen = [];
        foreach ($keywords as $keyword => $label) {
            $matches = [];
            foreach ($live as $row) {
                $model = (string)($row['model'] ?? '');
                // The highlights become the analyzer's shortlist, so a
                // batch-only id must not be listed as "the strongest model"
                // of a family: it could never be used for the pool.
                if ($model === '' || ModelId::isBatchId($model)) {
                    continue;
                }
                if (!str_contains(strtolower($model), $keyword)) {
                    continue;
                }
                $matches[] = $row;
            }
            if ($matches === []) {
                continue;
            }
            $best = null;
            foreach ($matches as $row) {
                if ($best === null) { $best = $row; continue; }
                $sameLine = ModelVersion::line((string)$row['model']) === ModelVersion::line((string)$best['model']);
                if ($sameLine && ModelVersion::compare((string)$row['model'], (string)$best['model']) > 0) {
                    $best = $row;
                }
            }
            $id = $best['provider'] . '|' . $best['model'];
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $out[] = $id . ' (' . $label . ')';
            if (count($out) >= 10) {
                break;
            }
        }
        return $out;
    }

    // =================================================================
    // Stage 3 — write
    // =================================================================

    /**
     * Decide the user's pool: every currently selected model is replaced by
     * the newest release on its own line, and models that are gone are
     * dropped in favour of their successor.
     *
     * v1.8.60 — `$activeKeys` is the set of rows that will be **active**
     * once this run has written the catalogue ({@see self::activeCatalogKeys()}).
     * A pool entry that is not in it is dropped with the reason instead of
     * being carried into the pool, because that is exactly the state the
     * incident left behind: a pool whose four entries appeared in none of
     * the catalogue's rows, so L1 resolved zero models and the run still
     * reported success. Passing an empty `$activeKeys` disables the filter
     * (the historical behaviour) for callers that have no catalogue plan.
     *
     * @param array<string,array<string,mixed>> $plans
     * @param array<string,bool> $activeKeys key => true; empty = do not filter
     * @return array{selection:array<int,array{provider:string,model:string}>,previous:array<int,array{provider:string,model:string}>,warnings:array<int,string>}
     */
    private function planSelection(Job $job, array $plans, array $activeKeys = []): array
    {
        $previous = [];
        foreach ($job->payload['selection'] ?? [] as $row) {
            if (is_array($row) && isset($row['provider'], $row['model'])) {
                $previous[] = [
                    'provider' => strtolower(trim((string)$row['provider'])),
                    'model'    => trim((string)$row['model']),
                ];
            }
        }
        if ($previous === []) {
            // The panel could not report its pool (or it was empty): fall
            // back to the server-side default so the run still records
            // what "the previous selection" was.
            foreach ((array)Config::get('pipeline.default_selected_models', []) as $row) {
                if (is_array($row) && isset($row['provider'], $row['model'])) {
                    $previous[] = [
                        'provider' => strtolower(trim((string)$row['provider'])),
                        'model'    => trim((string)$row['model']),
                    ];
                }
            }
        }

        $warnings = [];
        $live = [];
        // Deliberately the PERSISTED catalogue, not the imported one: the
        // pool may only carry models the operator has activated, and an
        // import appends its rows inactive (they are "what the provider
        // serves", not "what this install runs"). Upgrading a pool entry to
        // an inactive row would leave the sidebar referencing a model its
        // own catalogue filters out.
        foreach ((array)Config::get('models', []) as $row) {
            if (!is_array($row)) { continue; }
            $provider = strtolower(trim((string)($row['provider'] ?? '')));
            $model = trim((string)($row['model'] ?? ''));
            if ($provider === '' || $model === '') { continue; }
            $live[] = ['provider' => $provider, 'model' => $model];
        }

        $selection = [];
        $upgraded = [];
        $batchDropped = [];
        $uncatalogued = [];
        foreach ($previous as $row) {
            // A batch-only entry cannot be carried over: the pool is
            // dispatch-only, and keeping it would guarantee a failed L1
            // call on every query. It is dropped WITH the reason, not
            // silently.
            if (ModelId::isBatchId($row['model'])) {
                $batchDropped[] = $row['provider'] . '/' . $row['model'];
                continue;
            }
            $byProvider = array_values(array_filter(
                $live,
                static fn(array $r): bool => $r['provider'] === $row['provider'],
            ));
            $candidates = array_map(static fn(array $r): string => $r['model'], $byProvider);
            // The version rule reads the line, not the mode: after the
            // `:batch` suffix is stripped, `gpt-4o:batch` and `gpt-4o` are
            // the same release, so the newest release of the line could BE
            // the batch id. Candidates are therefore restricted to ids the
            // pool can actually call.
            $candidates = array_values(array_filter(
                $candidates,
                static fn(string $id): bool => !ModelId::isBatchId($id),
            ));
            $newest = ModelVersion::newestOnSameLine($candidates, $row['model']);
            $chosen = $newest ?? $row['model'];
            // The pool may only hold a model the catalogue will list as
            // active. A pool entry the provider has retired, that the
            // catalogue keeps inactive, or that the cap left out is dropped
            // here — naming it — rather than carried into a pool that L1
            // would resolve to nothing.
            if ($activeKeys !== []) {
                $key = ModelCatalogDiff::key($row['provider'], $chosen);
                if (!isset($activeKeys[$key])) {
                    $uncatalogued[] = $row['provider'] . '/' . $chosen;
                    continue;
                }
            }
            if ($newest !== null && $newest !== $row['model']) {
                $upgraded[] = $row['model'] . ' → ' . $newest;
            }
            $key = ModelCatalogDiff::key($row['provider'], $chosen);
            if (isset($selection[$key])) { continue; }
            $selection[$key] = ['provider' => $row['provider'], 'model' => $chosen];
        }

        if ($selection === []) {
            // Nothing to carry over: seed from the default pool so the Chat
            // sidebar is never left without a pool after an actualization.
            // The default pool is built from models the analyzer assigned
            // out of the live catalogue, so its rows are active by
            // construction; filtering it against $activeKeys keeps that
            // honest if a plan ever names something the catalogue drops.
            $seed = $plans['default']['models'] ?? [];
            foreach ($seed as $row) {
                $key = ModelCatalogDiff::key((string)$row['provider'], (string)$row['model']);
                if ($activeKeys !== [] && !isset($activeKeys[$key])) { continue; }
                if (isset($selection[$key])) { continue; }
                $selection[$key] = ['provider' => (string)$row['provider'], 'model' => (string)$row['model']];
            }
            if ($selection !== []) {
                $warnings[] = 'No model pool was selected, so it was seeded from the "default" consensus pool.';
            }
        }
        if ($upgraded !== []) {
            $warnings[] = 'Upgraded to the newest release on the same line: ' . implode(', ', $upgraded) . '.';
        }
        if ($uncatalogued !== []) {
            $warnings[] = sprintf(
                'Removed %d pool entry(ies) that the catalogue does not hold as an active model — the provider '
                    . 'no longer serves them, or the row is not active: %s. Pick replacements in the sidebar '
                    . '(or run Actualize again after checking the provider list).',
                count($uncatalogued),
                implode(', ', array_slice($uncatalogued, 0, 8)) . (count($uncatalogued) > 8 ? ', …' : ''),
            );
        }
        if ($batchDropped !== []) {
            $warnings[] = sprintf(
                'Removed %d batch-only model(s) from the pool — they are served through the asynchronous '
                    . 'Batch API and cannot be called synchronously: %s.',
                count($batchDropped),
                implode(', ', $batchDropped),
            );
        }

        return ['selection' => array_values($selection), 'previous' => $previous, 'warnings' => $warnings];
    }

    /**
     * Plan the catalogue rows (no cap applied yet): every existing row is
     * kept, every new live model is appended, the models the analyzer
     * assigned are marked active, and the models the provider no longer
     * serves are marked stale and inactive.
     *
     * v1.8.60 — split out of {@see self::planCatalog()} so the caller can
     * ask "which rows will be active?" *before* it decides the pool. The
     * pool has to be planned against the catalogue that is about to be
     * written, not against the one that is on disk: that mismatch is the
     * whole incident ({@see self::verifyCatalogInvariant()}).
     *
     * @param array<int,array<string,mixed>> $catalog existing rows
     * @param array<string,mixed> $diff
     * @param array<string,array<string,mixed>> $plans
     * @param array<int,array<string,mixed>> $linkedChanges the linked-model
     *        plan ({@see LinkedModels::plan()}) — its targets are synchronous
     *        callers too, so they are activated like an assigned preset pick
     * @return array<int,array<string,mixed>>
     */
    private function planCatalogRows(
        array $catalog,
        array $diff,
        array $plans,
        array $linkedChanges = [],
    ): array {
        $assigned = [];
        foreach ($plans as $plan) {
            foreach ($plan['models'] as $row) {
                $assigned[ModelCatalogDiff::key((string)$row['provider'], (string)$row['model'])] = true;
            }
            if (isset($plan['arbiter']['provider'], $plan['arbiter']['model'])) {
                $assigned[ModelCatalogDiff::key((string)$plan['arbiter']['provider'], (string)$plan['arbiter']['model'])] = true;
            }
        }
        // The analyzer, the synthesizer and the arbiter are called
        // synchronously exactly like an L1 participant, so a row this run is
        // about to point one of them at has to be active — otherwise the
        // run's own result would fail {@see self::verifyCatalogInvariant()}.
        // The *resulting* roles, not the changes: a role the run leaves
        // exactly where it was still has to be a servable row.
        foreach (self::resultingLinkedRoles($linkedChanges) as $role) {
            $assigned[ModelCatalogDiff::key(
                (string)($role['provider'] ?? ''),
                (string)($role['model'] ?? ''),
            )] = true;
        }

        // The models the provider answered *without*. `ModelCatalogDiff`
        // only reports a row as stale when its own provider returned a
        // non-empty list, so an outage never marks anything.
        $stale = [];
        foreach ((array)($diff['stale'] ?? []) as $row) {
            if (!is_array($row)) { continue; }
            $key = ModelCatalogDiff::key((string)($row['provider'] ?? ''), (string)($row['model'] ?? ''));
            if ($key !== '') {
                $stale[$key] = strtolower((string)($row['provider'] ?? ''));
            }
        }

        $rows = [];
        $seen = [];
        foreach ($catalog as $row) {
            $key = ModelCatalogDiff::key($row['provider'], $row['model']);
            if (isset($seen[$key])) { continue; }
            $seen[$key] = true;
            $isStale = isset($stale[$key]);
            $active = $isStale
                // A stale row keeps its place in the catalogue — the
                // operator can still see what was dropped, and a provider
                // outage must not erase a working configuration — but it is
                // never active: L1 cannot call a model the provider does not
                // serve, and leaving it active is how a run ended in HTTP
                // 404 with a green "completed" badge.
                ? false
                // Existing rows keep their operator-set flag, except that an
                // assigned model is activated by the run itself.
                : (isset($assigned[$key]) ? true : (bool)$row['active']);
            $rows[] = $this->applyStaleness(
                $this->catalogRow(
                    (string)$row['provider'],
                    (string)$row['model'],
                    $active,
                    // …and everything descriptive about them, which a
                    // three-key rewrite used to drop.
                    $row,
                ),
                $isStale,
                $stale[$key] ?? '',
            );
        }
        foreach ($diff['added'] as $row) {
            $key = ModelCatalogDiff::key($row['provider'], $row['model']);
            if (isset($seen[$key])) { continue; }
            $seen[$key] = true;
            $isStale = isset($stale[$key]);
            $rows[] = $this->applyStaleness(
                $this->catalogRow(
                    (string)$row['provider'],
                    (string)$row['model'],
                    !$isStale && isset($assigned[$key]),
                ),
                $isStale,
                $stale[$key] ?? '',
            );
        }

        $this->staleRowKeys = array_keys($stale);
        $this->staleRows = array_values(array_filter((array)($diff['stale'] ?? []), 'is_array'));
        $this->catalogReport = [
            'limit'          => Config::catalogMaxRows(),
            'kept'           => count($rows),
            'dropped'        => 0,
            'protected_kept' => 0,
            'over_cap'       => 0,
            'rule'           => CatalogTruncation::RULE_UNCHANGED,
            'families'       => 0,
            'dropped_ids'    => [],
            'stale'          => count($stale),
            'stale_ids'      => array_map(
                static fn(array $row): string => (string)$row['provider'] . '/' . (string)$row['model'],
                $this->staleRows,
            ),
        ];
        return $rows;
    }

    /**
     * `provider|model` => true for every row that will be **active** once
     * this run has written the catalogue. The pool is validated against
     * this, so a pool entry can never reference a row the run is about to
     * leave inactive or stale.
     *
     * @param array<int,array<string,mixed>> $rows {@see self::planCatalogRows()}
     * @return array<string,bool>
     */
    private function activeCatalogKeys(array $rows): array
    {
        $keys = [];
        foreach ($rows as $row) {
            if (($row['active'] ?? false) !== true) {
                continue;
            }
            $key = ModelCatalogDiff::key((string)$row['provider'], (string)$row['model']);
            if ($key !== '') {
                $keys[$key] = true;
            }
        }
        return $keys;
    }

    /**
     * Apply the configured row cap to the planned rows and record the
     * decision in {@see self::$catalogReport}.
     *
     * @param array<int,array<string,mixed>> $rows {@see self::planCatalogRows()}
     * @param array<string,array<string,mixed>> $plans
     * @param array<int,array{provider:string,model:string}> $selection
     * @param array<int,array<string,mixed>> $linkedChanges
     * @return array<int,array<string,mixed>>
     */
    private function applyCatalogLimit(
        array $rows,
        array $plans,
        array $selection,
        array $linkedChanges = [],
    ): array {
        $truncation = CatalogTruncation::apply(
            $rows,
            Config::catalogMaxRows(),
            $this->protectedCatalogKeys($plans, $selection, $linkedChanges),
        );
        $this->catalogReport = array_replace($this->catalogReport, [
            'limit'          => $truncation['limit'],
            'kept'           => $truncation['kept'],
            'dropped'        => $truncation['dropped'],
            'protected_kept' => $truncation['protected_kept'],
            'over_cap'       => $truncation['over_cap'],
            'rule'           => $truncation['rule'],
            'families'       => $truncation['families'],
            'dropped_ids'    => array_slice($truncation['dropped_ids'], 0, 24),
        ]);
        return $truncation['rows'];
    }

    /**
     * Attach or clear the staleness marker on one planned row.
     *
     * `stale` and `stale_reason` are the disclosure; `active=false` is the
     * behaviour. Both matter: the flag alone would still dispatch, and
     * `active=false` alone would leave the operator wondering why a model
     * they can see in the tab stopped running.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function applyStaleness(array $row, bool $isStale, string $provider): array
    {
        if (!$isStale) {
            // A row that is served again is served again: the marker is
            // cleared rather than carried, or a one-off provider hiccup
            // would brand a model forever.
            unset($row['stale'], $row['stale_reason']);
            return $row;
        }
        $row['stale'] = true;
        $row['stale_reason'] = $provider === ''
            ? 'the provider did not list this model id in its live catalogue'
            : sprintf(
                'provider "%s" did not list this model id in its live catalogue '
                    . '(retired, renamed, or not available on this account)',
                $provider,
            );
        return $row;
    }

    /**
     * The three linked roles as they will read once `$linkedChanges` has
     * been applied — `LinkedModels::current()` with the plan folded in.
     *
     * Shared by the three places that have to reason about "what will the
     * analyzer / synthesis / arbiter be pointed at after this run" (the
     * catalogue plan, the protected set and the invariant), so they cannot
     * disagree about it.
     *
     * @param array<int,array<string,mixed>> $linkedChanges
     * @return array<string,array<string,mixed>> role => row
     */
    private static function resultingLinkedRoles(array $linkedChanges): array
    {
        $roles = LinkedModels::current();
        foreach ($linkedChanges as $change) {
            if (!is_array($change)) { continue; }
            $role = (string)($change['role'] ?? '');
            if ($role === '' || !isset($roles[$role])) { continue; }
            if (($change['provider'] ?? '') !== '') {
                $roles[$role]['provider'] = (string)$change['provider'];
            }
            if (($change['model'] ?? '') !== '') {
                $roles[$role]['model'] = (string)$change['model'];
            }
        }
        return $roles;
    }

    /**
     * The catalogue rows this run must not drop, as `provider|model` keys.
     *
     * Taken from the values the run is *about to write*, not from what is in
     * effect now: the pool is replaced by `$selection`, the presets by
     * `$plans`, and the three linked roles by `$linkedChanges` on top of
     * their current values.
     *
     * @param array<string,array<string,mixed>> $plans
     * @param array<int,array{provider:string,model:string}> $selection
     * @param array<int,array<string,mixed>> $linkedChanges
     * @return array<int,string>
     */
    private function protectedCatalogKeys(array $plans, array $selection, array $linkedChanges = []): array
    {
        $keys = [];
        $add = static function (mixed $provider, mixed $model) use (&$keys): void {
            $key = CatalogTruncation::key((string)$provider, (string)$model);
            if ($key !== '') {
                $keys[$key] = true;
            }
        };
        foreach ($selection as $row) {
            $add($row['provider'] ?? '', $row['model'] ?? '');
        }
        foreach ($plans as $plan) {
            foreach ((array)($plan['models'] ?? []) as $row) {
                if (is_array($row)) {
                    $add($row['provider'] ?? '', $row['model'] ?? '');
                }
            }
            $arbiter = $plan['arbiter'] ?? null;
            if (is_array($arbiter)) {
                $add($arbiter['provider'] ?? '', $arbiter['model'] ?? '');
            }
        }
        // The three flat-config roles, as they will read once the run's own
        // linked-model changes have been applied.
        foreach (self::resultingLinkedRoles($linkedChanges) as $role) {
            $add($role['provider'] ?? '', $role['model'] ?? '');
        }
        return array_keys($keys);
    }

    /**
     * The invariant the catalogue, the presets, the pool and the three
     * linked roles have to satisfy together: **every referenced model is a
     * row of the catalogue, and (because all four are synchronous callers)
     * that row is active**.
     *
     * This is the check that was missing. `pipeline.default_selected_models`
     * held four models that appeared in none of the 64 catalogue rows, the
     * domain presets had been pointed at ids the catalogue never contained,
     * and the L4 synthesis model was one the provider had retired — each
     * separately invisible, and together a run that resolved an empty pool,
     * skipped L1, 404'd in L4 and still reported success.
     *
     * Returning a violation is a refusal, not a warning: the caller aborts
     * the run before anything is written, so the previous (working) state
     * stays in force and the report can name exactly what did not line up.
     *
     * @param array<int,array<string,mixed>> $rows the catalogue about to be written
     * @param array<string,array<string,mixed>> $plans
     * @param array<int,array{provider:string,model:string}> $selection
     * @param array<int,array<string,mixed>> $linkedChanges
     * @return array{ok:bool,violations:array<int,array{role:string,reference:string,provider:string,model:string,reason:string,message:string}>,checked:int}
     */
    private function verifyCatalogInvariant(
        array $rows,
        array $plans,
        array $selection,
        array $linkedChanges = [],
    ): array {
        $index = [];
        foreach ($rows as $row) {
            $key = ModelCatalogDiff::key((string)$row['provider'], (string)$row['model']);
            if ($key === '') { continue; }
            $index[$key] = (bool)($row['active'] ?? false);
        }

        $violations = [];
        $checked = 0;
        $check = function (string $role, string $reference, string $provider, string $model) use (&$violations, &$checked, $index): void {
            if ($provider === '' || $model === '') {
                return;
            }
            $checked++;
            $key = ModelCatalogDiff::key($provider, $model);
            $label = sprintf('%s (%s/%s — %s)', $role, $provider, $model, $reference);
            if (!array_key_exists($key, $index)) {
                $violations[] = [
                    'role'      => $role,
                    'reference' => $reference,
                    'provider'  => $provider,
                    'model'     => $model,
                    'reason'    => 'not_in_catalog',
                    'message'   => sprintf(
                        '%s references "%s/%s", which is not a row of the catalogue the run would write.',
                        $label,
                        $provider,
                        $model,
                    ),
                ];
                return;
            }
            if ($index[$key] !== true) {
                $violations[] = [
                    'role'      => $role,
                    'reference' => $reference,
                    'provider'  => $provider,
                    'model'     => $model,
                    'reason'    => 'inactive',
                    'message'   => sprintf(
                        '%s references "%s/%s", whose catalogue row is not active (the provider no longer '
                            . 'serves it, or it is a batch-only id).',
                        $label,
                        $provider,
                        $model,
                    ),
                ];
            }
        };

        foreach ($selection as $row) {
            $check('pool', 'pipeline.default_selected_models', (string)$row['provider'], (string)$row['model']);
        }
        foreach ($plans as $slug => $plan) {
            foreach ((array)($plan['models'] ?? []) as $row) {
                if (is_array($row)) {
                    $check(
                        sprintf('preset "%s"', (string)$slug),
                        'domain_presets.definition_json',
                        (string)($row['provider'] ?? ''),
                        (string)($row['model'] ?? ''),
                    );
                }
            }
            $arbiter = $plan['arbiter'] ?? null;
            if (is_array($arbiter) && ($arbiter['provider'] ?? '') !== '') {
                $check(
                    sprintf('preset "%s" arbiter', (string)$slug),
                    'domain_presets.definition_json',
                    (string)$arbiter['provider'],
                    (string)$arbiter['model'],
                );
            }
        }
        foreach (self::resultingLinkedRoles($linkedChanges) as $role => $state) {
            $check(
                sprintf('linked role %s', (string)$role),
                'analyzer/pipeline config',
                (string)($state['provider'] ?? ''),
                (string)($state['model'] ?? ''),
            );
        }

        return ['ok' => $violations === [], 'violations' => $violations, 'checked' => $checked];
    }

    /**
     * One persisted catalogue row.
     *
     * A batch-only id is written inactive whatever the caller asked for —
     * the pool dispatches synchronously, so activating it would only
     * guarantee a failed L1 call on every query — and it carries the reason
     * so the Models tab can mark the row instead of offering a checkbox
     * that can never take effect ({@see ModelId::syncPoolRejection()}).
     *
     * @param array<string,mixed> $meta the row this one is derived from, so
     *        its descriptive fields survive the rewrite
     * @return array<string,mixed>
     */
    private function catalogRow(string $provider, string $model, bool $active, array $meta = []): array
    {
        $row = ['provider' => $provider, 'model' => $model, 'active' => $active];
        foreach (self::CATALOG_META_KEYS as $key) {
            if (array_key_exists($key, $meta)) {
                $row[$key] = $meta[$key];
            }
        }
        $rejection = ModelId::syncPoolRejection($model);
        if ($rejection !== null) {
            $row['active']           = false;
            $row['batch_only']       = true;
            $row['sync_pool_reason'] = $rejection;
        }
        return $row;
    }

    /**
     * The catalogue rows a run wrote that cannot ever be dispatched
     * synchronously, as `provider/model` strings — the report line the
     * operator reads instead of wondering why a model they can see never
     * runs.
     *
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,string>
     */
    private function batchOnlyRows(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (ModelId::isBatchId((string)($row['model'] ?? ''))) {
                $out[] = (string)$row['provider'] . '/' . (string)$row['model'];
            }
        }
        return $out;
    }

    /** New live rows that this run would append (used for the summary line). */
    private function pendingAdded(array $catalog, array $diff): array
    {
        $known = [];
        foreach ($catalog as $row) {
            $known[ModelCatalogDiff::key($row['provider'], $row['model'])] = true;
        }
        return array_values(array_filter(
            $diff['added'],
            static fn(array $row): bool => !isset($known[ModelCatalogDiff::key($row['provider'], $row['model'])]),
        ));
    }

    /**
     * @param array<int,array{provider:string,model:string,active:bool}> $rows
     * @throws \App\Core\ConfigWriteException When the catalogue did not land
     *         on disk — the caller's catch restores the snapshot, so a
     *         failure here can never leave a half-written catalogue behind
     *         a "success" verdict.
     */
    private function writeCatalog(array $rows): void
    {
        RuntimeConfig::updateOrFail(['models' => $rows]);
        Config::reload(Config::rootDir() . '/config.php');
    }

    /**
     * @param array<string,array<string,mixed>> $plans
     * @param array<int,DomainPreset> $existing
     */
    private function writePresets(array $plans, array $existing): void
    {
        $bySlug = [];
        foreach ($existing as $preset) {
            $bySlug[$preset->slug] = $preset;
        }
        foreach ($plans as $slug => $plan) {
            $preset = $bySlug[$slug] ?? null;
            if ($preset === null) { continue; }
            $this->presets->save(new DomainPreset(
                id: $preset->id,
                slug: $preset->slug,
                name: $preset->name,
                description: $preset->description,
                models: $plan['models'],
                arbiter: $plan['arbiter'],
                skillSlug: $preset->skillSlug,
                isSystem: $preset->isSystem,
            ));
        }
    }

    /** @param array<int,array{provider:string,model:string}> $selection */
    private function writeSelection(array $selection): void
    {
        RuntimeConfig::updateOrFail(['pipeline.default_selected_models' => $selection]);
        Config::reload(Config::rootDir() . '/config.php');
    }

    // =================================================================
    // Snapshot / restore
    // =================================================================

    /**
     * @param array<int,array<string,mixed>> $catalog
     * @param array<int,DomainPreset> $presets
     * @param array<int,array{provider:string,model:string}> $selection
     * @param array<string,array<string,mixed>> $linked {@see LinkedModels::current()}
     *        as captured before the first write
     * @return array<string,mixed>
     */
    private function buildSnapshot(Job $job, array $catalog, array $presets, array $selection, array $linked): array
    {
        $presetState = [];
        foreach ($presets as $preset) {
            $presetState[$preset->slug] = [
                'models'  => $preset->models,
                'arbiter' => $preset->arbiter,
            ];
        }
        return [
            'taken_at'      => gmdate('Y-m-d\TH:i:s\Z'),
            'job_id'        => $job->id,
            'models'        => $catalog,
            'presets'       => $presetState,
            'selection'     => $selection,
            'default_selected' => (array)Config::get('pipeline.default_selected_models', []),
            // The linked models (analyzer / synthesis / arbiter): provider
            // and model per role, plus whether each value was an override —
            // an override has to be removed on restore, not pinned.
            'linked'        => $linked,
        ];
    }

    /**
     * Restore catalogue, presets, pool and linked models from a snapshot.
     * Used by both the automatic failure path and the explicit rollback
     * endpoint, so there is exactly one definition of "back to how it was".
     *
     * @param array<string,mixed> $snapshot
     */
    private function restore(array $snapshot): void
    {
        $models = $snapshot['models'] ?? null;
        if (is_array($models)) {
            // The same normaliser the write path uses, so "the rows before
            // the run" come back exactly as they were — including the
            // descriptive fields a rollback of a run that had imported a
            // catalogue would otherwise leave stripped.
            $rows = self::catalogRowsFrom($models);
            RuntimeConfig::updateOrFail(['models' => $rows]);
            Config::reload(Config::rootDir() . '/config.php');
        }

        $presetState = $snapshot['presets'] ?? null;
        if (is_array($presetState)) {
            $existing = [];
            foreach ($this->presets->all() as $preset) {
                $existing[$preset->slug] = $preset;
            }
            foreach ($presetState as $slug => $state) {
                $preset = $existing[$slug] ?? null;
                if ($preset === null || !is_array($state)) { continue; }
                $arbiter = is_array($state['arbiter'] ?? null) && isset($state['arbiter']['provider'], $state['arbiter']['model'])
                    ? [
                        'provider' => (string)$state['arbiter']['provider'],
                        'model'    => (string)$state['arbiter']['model'],
                        'role'     => (string)($state['arbiter']['role'] ?? 'main'),
                    ]
                    : null;
                $this->presets->save(new DomainPreset(
                    id: $preset->id,
                    slug: $preset->slug,
                    name: $preset->name,
                    description: $preset->description,
                    models: is_array($state['models'] ?? null) ? $state['models'] : [],
                    arbiter: $arbiter,
                    skillSlug: $preset->skillSlug,
                    isSystem: $preset->isSystem,
                ));
            }
        }

        if (array_key_exists('default_selected', $snapshot) && is_array($snapshot['default_selected'])) {
            RuntimeConfig::updateOrFail(['pipeline.default_selected_models' => $snapshot['default_selected']]);
            Config::reload(Config::rootDir() . '/config.php');
        }

        // Last, because the pipeline block was just replaced wholesale above
        // and the linked models are merged into it by dot-notation keys.
        // Snapshots taken before the linked models were captured (an older
        // store row) simply have no `linked` key and leave them alone.
        $linked = $snapshot['linked'] ?? null;
        LinkedModels::restore(is_array($linked) ? $linked : null);
    }

    // =================================================================
    // Plumbing
    // =================================================================

    /**
     * Publish a stage transition: to the durable job-event audit (so a
     * status poll can follow a run it did not start) AND to the status
     * record the Models tab polls (v1.8.50).
     *
     * The second half is what fixed "Analysing models forever": the status
     * key used to be written once, at the end, so the UI had no way to see
     * the middle stage — and a run that died before `finish()` left no
     * record at all, which renders as the untouched initial card.
     *
     * A failing progress write is never fatal (the run's real value is the
     * write phase), but it is reported: a host whose `app_config` is
     * unavailable must not look like a run that is progressing normally.
     *
     * @param array<string,mixed> $context
     * @param null|callable():void $heartbeat
     */
    private function publishStage(
        Job $job,
        string $workerId,
        string $stage,
        string $message,
        array $context = [],
        ?callable $heartbeat = null,
    ): void {
        $this->lastStage = $stage;
        if ($heartbeat !== null) {
            try {
                $heartbeat();
            } catch (\Throwable $e) {
                Logger::warn('models.actualize.heartbeat_failed', [
                    'job_id' => $job->id,
                    'error'  => Logger::sanitizeScalar($e->getMessage()),
                ]);
            }
        }
        if (!$this->store->saveProgress($job->id, $stage, $message, $context)
            && !$this->progressWriteFailed
        ) {
            $this->progressWriteFailed = true;
        }
        if ($this->audit === null) {
            return;
        }
        try {
            $this->audit->record([
                'job_id'     => $job->id,
                'session_id' => $job->sessionId,
                'user_id'    => $job->userId,
                'event_type' => self::EVENT_STAGE,
                'worker_id'  => $workerId,
                'message'    => $message,
                'context'    => ['stage' => $stage] + $context,
            ]);
        } catch (\Throwable) {
            // Events are diagnostics; a failing audit write must not abort
            // the run (JobAuditRepository::record() already swallows, this
            // covers a subclass that does not).
        }
    }

    /**
     * Record a terminal `problems` status for a run that died outside
     * {@see self::run()}.
     *
     * Called by the worker runner when the actualizer throws before it
     * could build its own envelope (a fatal in the connector layer, an
     * out-of-memory split). Without it the status endpoint finds no
     * record and reports `idle`, which is indistinguishable from "never
     * ran" — the reported "it just resets to the initial state".
     *
     * Never throws: it runs on an error path.
     */
    public function recordFailure(Job $job, string $message, array $detail = []): void
    {
        try {
            $this->finish($job, microtime(true), self::STAGE_PROBLEMS, [$message], [], [], [], [
                'message' => 'Actualization failed and nothing was written: ' . $message,
                'error'   => $detail === [] ? ['message' => $message] : $detail,
            ]);
        } catch (\Throwable $e) {
            Logger::warn('models.actualize.record_failure_failed', [
                'job_id' => $job->id,
                'error'  => Logger::sanitizeScalar($e->getMessage()),
            ]);
        }
    }

    /**
     * Build the final envelope and persist it as the last-run status.
     *
     * @param array<int,string> $warnings
     * @param array<string,array<int,string>> $assigned
     * @param array<string,mixed> $diff
     * @param array<int,array{provider:string,model:string}> $selection
     * @param array<string,mixed> $extra
     * @param array<int,array<string,mixed>> $linked linked-model changes
     *        that are *in effect* once this envelope is returned — empty on
     *        the failure paths, where the snapshot was restored
     * @return array<string,mixed>
     */
    private function finish(
        Job $job,
        float $started,
        string $stage,
        array $warnings,
        array $assigned,
        array $diff,
        array $selection,
        array $extra = [],
        array $linked = [],
    ): array {
        if ($this->progressWriteFailed) {
            // Say it once, in the record the operator actually reads.
            $warnings[] = 'The live progress of this run could not be stored (app_config unavailable), '
                . 'so the Models tab could not follow its stages. The result below is still authoritative.';
        }
        $finishedAt = gmdate('Y-m-d\TH:i:s\Z');
        $envelope = [
            'kind'         => self::KIND,
            'ok'           => $stage === self::STAGE_SUCCESS,
            'stage'        => $stage,
            'job_id'       => $job->id,
            'message'      => (string)($extra['message'] ?? ($stage === self::STAGE_SUCCESS
                ? 'Actualization complete.'
                : 'Actualization finished with problems.')),
            'warnings'     => array_values($warnings),
            'assigned'     => $assigned,
            'selection'    => $selection,
            'linked'       => array_values($linked),
            // What the first stage did: the source the operator chose, what
            // the provider sent, what the catalogue took, what it refused.
            // Additive — a status row written before this stage existed
            // simply has no `import` key.
            'import'       => self::importEnvelope($this->importReport),
            // Which model answered the assignment calls, where that value
            // came from (`section` or `inherited`), and how many times it
            // had to be asked. Additive in the same way.
            'analyzer'     => $this->analyzerReport(),
            // The one-sentence justification per preset, from the answer's
            // own `reason` field. Additive.
            'reasons'      => $this->answerReasons,
            // v1.8.60 — the row-cap decision and the staleness proof, as
            // numbers rather than prose: how many rows the catalogue holds,
            // what the cap is, how many rows it left out and by which rule,
            // how many of the kept rows were protected because the pool, a
            // preset or a linked role references them, and how many rows the
            // provider no longer serves. Additive: a status row written
            // before this existed simply has no `catalog` key.
            'catalog'      => $this->catalogEnvelope(),
            'invariant'    => $this->invariantReport,
            'diff'         => [
                'live_count'    => (int)($diff['live_count'] ?? 0),
                'catalog_count' => (int)($diff['catalog_count'] ?? 0),
                'added'         => array_map(
                    static fn(array $r): string => $r['provider'] . '/' . $r['model'],
                    (array)($diff['added'] ?? []),
                ),
                'stale'         => array_map(
                    static fn(array $r): string => $r['provider'] . '/' . $r['model'],
                    (array)($diff['stale'] ?? []),
                ),
                'unavailable'   => (array)($diff['unavailable'] ?? []),
            ],
            'started_at'   => gmdate('Y-m-d\TH:i:s\Z', (int)$started),
            'finished_at'  => $finishedAt,
            'duration_ms'  => (int)round((microtime(true) - $started) * 1000),
        ];
        // v1.8.50 — a non-success verdict carries the reason in a field of
        // its own, so the Models tab can render the failure text without
        // parsing prose out of `warnings[]`.
        if ($stage !== self::STAGE_SUCCESS) {
            $envelope['error'] = $this->errorDetail($extra, $warnings);
            // v1.8.51 — and the stage it died in. Without it the card could
            // only paint a terminal `problems` as "every stage finished",
            // i.e. a failed run that looked green.
            $envelope['failed_stage'] = $this->lastStage;
        }

        $snapshot = $this->store->loadSnapshot();
        $status = $envelope;
        $status['active'] = false;
        $status['job_status'] = 'completed';
        $status['snapshot'] = [
            'available' => $snapshot !== null,
            'taken_at'  => $snapshot['taken_at'] ?? null,
            'job_id'    => $snapshot['job_id'] ?? null,
        ];
        // A status that could not be persisted is exactly the "it finished
        // but the page shows nothing" case, so it is logged rather than
        // swallowed the way it used to be.
        if (!$this->store->saveStatus($status)) {
            Logger::warn('models.actualize.status_not_stored', [
                'job_id' => $job->id,
                'stage'  => $stage,
            ]);
        }

        return $envelope;
    }

    /**
     * The row-cap decision and the staleness proof, as the envelope reports
     * them. Empty before the planning stage ran (the abort paths that never
     * got there), in which case the key carries the configured cap so the
     * card can still say what the ceiling is.
     *
     * @return array<string,mixed>
     */
    private function catalogEnvelope(): array
    {
        $configured = Config::catalogMaxRows();
        if ($this->catalogReport === []) {
            return [
                'limit'          => $configured,
                'kept'           => 0,
                'dropped'        => 0,
                'protected_kept' => 0,
                'over_cap'       => 0,
                'rule'           => 'not_reached',
                'families'       => 0,
                'dropped_ids'    => [],
                'stale'          => 0,
                'stale_ids'      => [],
                'unlimited'      => $configured === 0,
            ];
        }
        return $this->catalogReport + ['unlimited' => $configured === 0];
    }

    /**
     * The import stage's counters as the envelope reports them.
     *
     * @param array<string,mixed> $report {@see ModelImporter::mergeReport()}
     * @return array<string,mixed>
     */
    private static function importEnvelope(array $report): array
    {
        return [
            'source'     => (string)($report['source'] ?? ''),
            'fetched'    => (int)($report['fetched'] ?? 0),
            'added'      => array_values((array)($report['added'] ?? [])),
            'duplicates' => (int)($report['duplicates'] ?? 0),
            'present'    => (int)($report['present'] ?? 0),
            'truncated'  => (int)($report['truncated'] ?? 0),
            'unusable'   => (int)($report['unusable'] ?? 0),
            // The cap the import itself applied. v1.8.60 leaves the import
            // uncapped on purpose and caps at the planning stage, so this is
            // normally 0 ("no ceiling") and the effective limit travels in
            // the `catalog` block instead.
            'limit'      => (int)($report['limit'] ?? 0),
            'batch'      => array_values((array)($report['batch'] ?? [])),
        ];
    }

    /**
     * Which model answered the assignment calls. `source` is the word the
     * UI needs to say "inherited from analyzer.provider / analyzer.model"
     * instead of pretending the section has a value of its own.
     *
     * @return array<string,mixed>
     */
    private function analyzerReport(): array
    {
        if ($this->analyzerUsage === []) {
            // The run died before it asked anything (the import stage, or an
            // analysis that found no live models). Report the value that
            // *would* have been used — an operator reading the card still
            // wants to know which model the section is pointed at.
            $choice = $this->preferences()->resolve();
            return [
                'provider'    => (string)$choice['provider'],
                'model'       => (string)$choice['model'],
                'source'      => (string)$choice['source'],
                'note'        => $choice['note'],
                'requests'    => 0,
                'corrections' => 0,
            ];
        }
        return $this->analyzerUsage;
    }

    /**
     * Normalise the failure detail for the envelope's `error` field.
     *
     * @param array<string,mixed> $extra
     * @param array<int,string>   $warnings
     * @return array<string,mixed>
     */
    private function errorDetail(array $extra, array $warnings): array
    {
        $detail = $extra['error'] ?? null;
        if (is_array($detail) && $detail !== []) {
            return $detail;
        }
        if (is_string($detail) && $detail !== '') {
            return ['message' => $detail];
        }
        return [
            'message'  => (string)($extra['message'] ?? 'Actualization finished with problems.'),
            'warnings' => array_values($warnings),
        ];
    }

    /** Turn a model id into a human label (`openai/gpt-5.5` → `GPT 5.5`). */
    private static function labelFor(string $model): string
    {
        $slug = ProviderFamily::slug($model);
        $words = preg_split('/[-_\s]+/', $slug) ?: [];
        $words = array_values(array_filter(array_map(
            static fn(string $w): string => $w === '' ? '' : mb_strtoupper(mb_substr($w, 0, 1)) . mb_substr($w, 1),
            $words,
        ), static fn(string $w): bool => $w !== ''));
        return $words === [] ? $model : implode(' ', $words);
    }
}
