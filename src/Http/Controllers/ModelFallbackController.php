<?php
/**
 * ModelFallbackController: the HTTP surface for model availability, alternatives
 * and the substitution policy.
 *
 * This is what makes "choosing an alternative is convenient" true in the UI
 * rather than only in the resolver:
 *
 *   GET  /api/models/availability/summary   statuses for the models currently
 *                                           selected in the Chat pool, so the
 *                                           picker can flag a dead one BEFORE
 *                                           the operator presses Run;
 *   GET  /api/models/alternatives?model=…   the ranked candidate list with the
 *                                           score breakdown, rendered as a
 *                                           "Swap" button per row;
 *   POST /api/models/alternatives/plan      a dry run of the whole pool: what
 *                                           would be substituted, what could
 *                                           not be filled, and why;
 *   POST /api/models/availability/probe     run the monitor now;
 *   POST /api/models/availability/reset     forget a verdict ("I fixed the key,
 *                                           stop treating this model as dead");
 *   GET/POST /api/models/fallback/policy    the mode + the soft/hard rules;
 *   GET/POST/DELETE …/fallback/rules        operator-authored hard overrides.
 *
 * Authorisation
 * -------------
 * Everything is admin-gated EXCEPT `availabilitySummary`, which the Chat tab
 * needs for an unauthenticated operator. That endpoint returns statuses and
 * counts only — never pricing, never catalogue detail, never a key
 * fingerprint — so it cannot be used to enumerate the installation's models or
 * to infer spend.
 *
 * Every response goes through `Response::json()`, which sets `no-store`: an
 * availability verdict is by definition time-sensitive, and a shared proxy
 * caching it would show a dead model as healthy for as long as the proxy felt
 * like it.
 *
 * @project  RevoAp
 * @version  2.0.0
 * @since    2.0.0
 */
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Connectors\ConnectorRegistry;
use App\Core\Config;
use App\Core\RuntimeConfig;
use App\Models\Fallback\AlternativeResolver;
use App\Models\Fallback\AvailabilityMonitor;
use App\Models\Fallback\AvailabilityRepository;
use App\Models\Fallback\AvailabilityStatus;
use App\Models\Fallback\FallbackMode;
use App\Models\Fallback\FallbackPolicy;
use App\Models\Fallback\UnavailableReason;
use App\Http\AdminGate;
use App\Http\Response;

final class ModelFallbackController
{
    /** Hard ceiling on one probe run, so a 400-model catalogue cannot turn a
     *  single POST into a request that outlives `max_execution_time`. */
    private const MAX_PROBE_TARGETS = 40;

    /** Keys the policy writer accepts. Anything else in the body is ignored,
     *  which is what stops a crafted POST from writing an arbitrary
     *  `models.fallback.*` sub-key the resolver does not know about. */
    private const POLICY_KEYS = [
        'mode' => 'string',
        'prefer_vendor_diversity' => 'bool',
        'same_vendor_only' => 'bool',
        'respect_context_window' => 'bool',
        'allow_degraded_substitutes' => 'bool',
        'max_latency_ms' => 'int',
        'max_cost_multiplier' => 'float',
        'cost_is_hard_limit' => 'bool',
        'max_alternatives' => 'int',
        'monitor_enabled' => 'bool',
        'monitor_interval_seconds' => 'int',
    ];

    public function __construct(
        private readonly AvailabilityRepository $availability,
        private readonly ?ConnectorRegistry $connectors = null,
    ) {
    }

    /* ------------------------------------------------------------------
     * Availability
     * ---------------------------------------------------------------- */

    /**
     * GET /api/models/availability — the full verdict table (admin only).
     */
    public function availability(): string
    {
        if ($denied = AdminGate::deny('models.fallback')) {
            return $denied;
        }
        $policy = FallbackPolicy::fromConfig();
        $rows = $this->availability->all(null, $policy->monitorWindowSeconds);

        $out = [];
        foreach ($rows as $id => $state) {
            $out[] = $state->toArray();
        }
        usort($out, static fn(array $a, array $b): int => strcmp((string)$a['id'], (string)$b['id']));

        return Response::json([
            'ok' => true,
            'persistent' => $this->availability->isPersistent(),
            'policy' => $policy->toArray(),
            'counts' => $this->availability->statusCounts(),
            'models' => $out,
        ]);
    }

    /**
     * GET /api/models/availability/summary — public, Chat-tab facing.
     *
     * Deliberately minimal: id, status, and whether a substitute exists. No
     * pricing, no latency history, no error text (which can contain a provider
     * response body and therefore an account id).
     */
    public function availabilitySummary(): string
    {
        $policy = FallbackPolicy::fromConfig();
        $requested = $this->requestedIds();
        if ($requested === []) {
            return Response::json(['ok' => true, 'models' => [], 'unavailable' => 0, 'mode' => $policy->mode->value]);
        }
        $rows = $this->availability->all($requested, $policy->monitorWindowSeconds);

        $models = [];
        $unavailable = 0;
        foreach ($requested as $id) {
            $state = $rows[$id] ?? null;
            $status = $state?->status ?? AvailabilityStatus::Unknown;
            if (!$status->isUsable()) { $unavailable++; }
            $models[] = [
                'id' => $id,
                'status' => $status->value,
                'tone' => $status->tone(),
                'label_key' => $status->labelKey(),
                'usable' => $status->isUsable(),
                'reason_key' => $state?->primaryReason()?->labelKey(),
            ];
        }

        return Response::json([
            'ok' => true,
            'mode' => $policy->mode->value,
            'mode_label_key' => $policy->mode->labelKey(),
            'unavailable' => $unavailable,
            'models' => $models,
        ]);
    }

    /**
     * POST /api/models/availability/probe — run the monitor now.
     *
     * `?ids=a,b,c` probes a specific set (the per-model "Probe" button);
     * without it, the monitor picks whatever is due.
     */
    public function probe(): string
    {
        if ($denied = AdminGate::deny('models.fallback')) {
            return $denied;
        }
        $policy = FallbackPolicy::fromConfig();
        $catalogue = $this->catalogue();
        if ($catalogue === []) {
            return Response::json([
                'ok' => false,
                'error' => 'empty_catalogue',
                'hint' => 'Refresh the model catalogue before probing.',
            ], 409);
        }

        $body = $this->jsonBody();
        $requested = $this->normalizeIds($body['ids'] ?? ($_GET['ids'] ?? null));

        $monitor = new AvailabilityMonitor($this->availability, $this->connectors);
        $availability = $this->availability->all(null, $policy->monitorWindowSeconds);

        if ($requested !== []) {
            $targets = [];
            foreach ($catalogue as $row) {
                $id = AlternativeResolver::id($row);
                if (in_array($id, $requested, true)) { $targets[] = $row; }
            }
            // A requested id that is not in the catalogue is reported rather
            // than silently dropped: "probe gpt-5.5" returning an empty result
            // is indistinguishable from "gpt-5.5 is fine" otherwise.
            $unknown = array_values(array_diff($requested, array_map(
                static fn(array $r): string => AlternativeResolver::id($r),
                $targets
            )));
            $result = $monitor->probe(array_slice($targets, 0, self::MAX_PROBE_TARGETS));
            $result['unknown_ids'] = $unknown;
            return Response::json(['ok' => true] + $result);
        }

        $due = $monitor->dueForProbe($catalogue, $availability, $policy);
        $result = $monitor->probe(array_slice($due, 0, self::MAX_PROBE_TARGETS));
        $result['due_total'] = count($due);
        $result['truncated'] = count($due) > self::MAX_PROBE_TARGETS;
        return Response::json(['ok' => true] + $result);
    }

    /**
     * POST /api/models/availability/reset — forget one model's verdict.
     */
    public function reset(): string
    {
        if ($denied = AdminGate::deny('models.fallback')) {
            return $denied;
        }
        $body = $this->jsonBody();
        $ids = $this->normalizeIds($body['ids'] ?? $body['id'] ?? null);
        if ($ids === []) {
            return Response::json(['ok' => false, 'error' => 'missing_id'], 400);
        }
        $done = 0;
        foreach ($ids as $id) {
            if ($this->availability->forget($id)) { $done++; }
        }
        return Response::json(['ok' => true, 'reset' => $done, 'requested' => count($ids)]);
    }

    /**
     * POST /api/models/availability/retire — mark a model Retired (or undo it).
     *
     * Retired is sticky: no amount of successful traffic un-retires a model an
     * administrator switched off, which is the difference between "this is
     * temporarily failing" and "we are not allowed to use this any more".
     */
    public function retire(): string
    {
        if ($denied = AdminGate::deny('models.fallback')) {
            return $denied;
        }
        $body = $this->jsonBody();
        $id = trim((string)($body['id'] ?? ''));
        if ($id === '') {
            return Response::json(['ok' => false, 'error' => 'missing_id'], 400);
        }
        $retire = (bool)($body['retired'] ?? true);
        $note = mb_substr(trim((string)($body['note'] ?? '')), 0, 200);

        $this->availability->setStatus(
            $id,
            $retire ? AvailabilityStatus::Retired : AvailabilityStatus::Unknown,
            $note === '' ? null : $note
        );
        return Response::json(['ok' => true, 'id' => $id, 'status' => $retire ? 'retired' : 'unknown']);
    }

    /* ------------------------------------------------------------------
     * Alternatives
     * ---------------------------------------------------------------- */

    /**
     * GET /api/models/alternatives?model=provider/model&pool=a,b
     *
     * The ranked candidate list for one model, with the score breakdown the UI
     * renders as "why this one".
     */
    public function alternatives(): string
    {
        if ($denied = AdminGate::deny('models.fallback')) {
            return $denied;
        }
        $model = trim((string)($_GET['model'] ?? ''));
        if ($model === '') {
            return Response::json(['ok' => false, 'error' => 'missing_model'], 400);
        }

        $catalogue = $this->catalogue();
        $target = $this->findRow($catalogue, $model);
        if ($target === null) {
            // Still answer with a ranked list: the operator may be asking about
            // a model that just disappeared from the catalogue, which is the
            // single most common reason to want a substitute. Synthesise a
            // minimal row so the resolver has an id and a vendor to work from.
            [$provider, $name] = AvailabilityRepository::splitId($model);
            $target = ['provider' => $provider, 'model' => $name, 'enabled' => true];
        }

        $policy = FallbackPolicy::fromConfig();
        $poolIds = $this->normalizeIds($_GET['pool'] ?? null);
        $availability = $this->availability->all(null, $policy->monitorWindowSeconds);

        $resolver = new AlternativeResolver($policy);
        $candidates = $resolver->alternatives($target, $catalogue, $poolIds, $availability);

        $state = $availability[$model] ?? null;
        return Response::json([
            'ok' => true,
            'model' => $model,
            'in_catalogue' => $this->findRow($catalogue, $model) !== null,
            'current' => $state?->toArray(),
            'reason' => $state?->primaryReason()?->value,
            'reason_label_key' => $state?->primaryReason()?->labelKey(),
            'policy' => $policy->toArray(),
            // The chain the operator pinned, if any, is reported separately:
            // it OVERRIDES the scored ranking and the UI has to say so.
            'pinned_chain' => $policy->chainFor($model),
            'alternatives' => array_map(static fn($c): array => $c->toArray(), $candidates),
        ]);
    }

    /**
     * POST /api/models/alternatives/plan — dry run over a whole pool.
     */
    public function plan(): string
    {
        if ($denied = AdminGate::deny('models.fallback')) {
            return $denied;
        }
        $body = $this->jsonBody();
        $poolIds = $this->normalizeIds($body['pool'] ?? null);
        if ($poolIds === []) { $poolIds = $this->requestedIds(); }

        $catalogue = $this->catalogue();
        $pool = [];
        foreach ($poolIds as $id) {
            $row = $this->findRow($catalogue, $id);
            if ($row !== null) {
                $pool[] = $row;
                continue;
            }
            [$provider, $name] = AvailabilityRepository::splitId($id);
            $pool[] = ['provider' => $provider, 'model' => $name, 'enabled' => true];
        }

        $policy = FallbackPolicy::fromConfig();
        $mode = isset($body['mode']) ? FallbackMode::parse($body['mode']) : $policy->mode;
        $policy = $policy->withMode($mode);

        $availability = $this->availability->all(null, $policy->monitorWindowSeconds);
        $resolver = new AlternativeResolver($policy);
        $plan = $resolver->planPool($pool, $catalogue, $availability);

        return Response::json([
            'ok' => true,
            'dry_run' => true,
            'requested_ids' => $poolIds,
            'availability' => array_map(
                static fn($id): ?array => isset($availability[$id]) ? $availability[$id]->toArray() : null,
                $poolIds
            ),
            'plan' => $plan->toArray(),
        ]);
    }

    /* ------------------------------------------------------------------
     * Policy + rules
     * ---------------------------------------------------------------- */

    /** GET /api/models/fallback/policy */
    public function policy(): string
    {
        if ($denied = AdminGate::deny('models.fallback')) {
            return $denied;
        }
        $policy = FallbackPolicy::fromConfig();
        return Response::json([
            'ok' => true,
            'policy' => $policy->toArray(),
            'modes' => array_map(static fn(FallbackMode $m): array => [
                'value' => $m->value,
                'label_key' => $m->labelKey(),
                'help_key' => $m->helpKey(),
                'substitutes' => $m->substitutes(),
                'aborts_on_gap' => $m->abortsOnGap(),
            ], FallbackMode::cases()),
            'reasons' => array_map(static fn(UnavailableReason $r): array => [
                'value' => $r->value,
                'label_key' => $r->labelKey(),
                'transient' => $r->transient(),
                'substitutable' => $r->substitutable(),
                'probe_worthy' => $r->probeWorthy(),
            ], UnavailableReason::cases()),
            'rules' => $this->readRules(),
        ]);
    }

    /** POST /api/models/fallback/policy */
    public function savePolicy(): string
    {
        if ($denied = AdminGate::deny('models.fallback')) {
            return $denied;
        }
        $body = $this->jsonBody();
        $writes = [];
        $errors = [];

        foreach (self::POLICY_KEYS as $key => $type) {
            if (!array_key_exists($key, $body)) { continue; }
            $raw = $body[$key];

            // `mode` is validated against the enum rather than stored raw: an
            // unrecognised mode would make FallbackMode::parse() fall back to
            // Auto, i.e. silently start substituting when the operator asked
            // for something else.
            if ($key === 'mode') {
                $mode = FallbackMode::tryFrom(strtolower(trim((string)$raw)));
                if ($mode === null) {
                    $errors[] = ['key' => $key, 'error' => 'unsupported_mode'];
                    continue;
                }
                $writes['models.fallback.mode'] = $mode->value;
                continue;
            }

            $value = match ($type) {
                'bool'  => filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
                'int'   => filter_var($raw, FILTER_VALIDATE_INT),
                'float' => filter_var($raw, FILTER_VALIDATE_FLOAT),
                default => is_string($raw) ? trim($raw) : null,
            };
            if ($value === null || $value === false && $type !== 'bool') {
                $errors[] = ['key' => $key, 'error' => 'invalid_value'];
                continue;
            }
            // Clamp the numerics: a negative multiplier disables every
            // candidate, and a 0-second probe interval is a denial-of-service
            // against the operator's own provider quota.
            if ($key === 'max_cost_multiplier') { $value = max(0.0, min(50.0, (float)$value)); }
            if ($key === 'max_latency_ms')      { $value = max(0, min(600_000, (int)$value)); }
            if ($key === 'max_alternatives')    { $value = max(1, min(50, (int)$value)); }
            if ($key === 'monitor_interval_seconds') { $value = max(30, min(86_400, (int)$value)); }

            $writes['models.fallback.' . $key] = $value;
        }

        // `updateChecked` rather than `update`: a runtime overlay that cannot
        // be written (read-only filesystem, wrong owner on var/) must be
        // REPORTED, not silently dropped. Reporting "saved" while the file was
        // never written is how an operator ends up debugging a policy that
        // they can see in the UI and that the pipeline does not apply.
        $persisted = true;
        $writeError = null;
        if ($writes !== []) {
            $result = RuntimeConfig::updateChecked($writes);
            $persisted = (bool)$result['ok'];
            $writeError = $result['error'];
        }

        return Response::json([
            'ok' => $errors === [] && $persisted,
            'written' => $persisted ? $writes : [],
            'persisted' => $persisted,
            'write_error' => $writeError,
            'errors' => $errors,
            // Re-read from Config so the response reflects what the NEXT
            // request will actually see, not what was asked for.
            'policy' => FallbackPolicy::fromConfig()->toArray(),
        ], ($errors === [] && $persisted) ? 200 : 422);
    }

    /** GET /api/models/fallback/rules */
    public function rules(): string
    {
        if ($denied = AdminGate::deny('models.fallback')) {
            return $denied;
        }
        return Response::json(['ok' => true, 'rules' => $this->readRules()]);
    }

    /** POST /api/models/fallback/rules — create or update one rule. */
    public function saveRule(): string
    {
        if ($denied = AdminGate::deny('models.fallback')) {
            return $denied;
        }
        $body = $this->jsonBody();
        $name = trim((string)($body['name'] ?? ''));
        $action = strtolower(trim((string)($body['action'] ?? 'substitute')));
        if ($name === '' || mb_strlen($name) > 120) {
            return Response::json(['ok' => false, 'error' => 'invalid_name'], 400);
        }
        if (!in_array($action, ['substitute', 'never', 'abort'], true)) {
            return Response::json(['ok' => false, 'error' => 'invalid_action'], 400);
        }

        $rules = $this->readRules();
        $id = (int)($body['id'] ?? 0);
        $row = [
            'id' => $id > 0 ? $id : (int)(max(0, ...array_map(static fn(array $r): int => (int)($r['id'] ?? 0), $rules)) + 1),
            'name' => $name,
            'enabled' => (bool)($body['enabled'] ?? true),
            'priority' => max(-1000, min(1000, (int)($body['priority'] ?? 100))),
            'match_model' => self::cleanId($body['match_model'] ?? null),
            'match_provider' => self::cleanId($body['match_provider'] ?? null),
            'match_reason' => self::cleanEnum($body['match_reason'] ?? null, UnavailableReason::class),
            'action' => $action,
            'action_model' => self::cleanId($body['action_model'] ?? null),
            'updated_at' => time(),
        ];

        $found = false;
        foreach ($rules as $i => $existing) {
            if ((int)($existing['id'] ?? 0) === $row['id']) { $rules[$i] = $row; $found = true; break; }
        }
        if (!$found) { $rules[] = $row; }

        usort($rules, static fn(array $a, array $b): int => ((int)$a['priority']) <=> ((int)$b['priority']));
        $written = $this->writeRules($rules);
        if (!$written['ok']) {
            return Response::json([
                'ok' => false, 'error' => 'persist_failed', 'detail' => $written['error'],
            ], 500);
        }

        return Response::json(['ok' => true, 'rule' => $row, 'rules' => $this->readRules()]);
    }

    /** DELETE /api/models/fallback/rules/{id} */
    public function deleteRule(array $params): string
    {
        if ($denied = AdminGate::deny('models.fallback')) {
            return $denied;
        }
        $id = (int)($params['id'] ?? 0);
        $rules = $this->readRules();
        $next = array_values(array_filter($rules, static fn(array $r): bool => (int)($r['id'] ?? 0) !== $id));
        if (count($next) === count($rules)) {
            return Response::json(['ok' => false, 'error' => 'not_found'], 404);
        }
        $written = $this->writeRules($next);
        if (!$written['ok']) {
            return Response::json([
                'ok' => false, 'error' => 'persist_failed', 'detail' => $written['error'],
            ], 500);
        }
        return Response::json(['ok' => true, 'deleted' => $id, 'rules' => $this->readRules()]);
    }

    /* ------------------------------------------------------------------
     * Internals
     * ---------------------------------------------------------------- */

    /**
     * Rules live in the runtime overlay rather than in a table.
     *
     * There IS a `revoap_fallback_rules` table (migration 023), and a
     * multi-node deployment should use it so every node sees the same rules.
     * The overlay is the default because a single-node install — the common
     * case — then needs no migration applied before the feature works, and
     * because the overlay is what every other operator setting already uses,
     * so one mental model covers both.
     *
     * @return array<int,array<string,mixed>>
     */
    private function readRules(): array
    {
        $raw = Config::get('models.fallback.rules', []);
        if (!is_array($raw)) { return []; }
        $out = [];
        foreach ($raw as $row) {
            if (!is_array($row)) { continue; }
            $out[] = [
                'id' => (int)($row['id'] ?? count($out) + 1),
                'name' => (string)($row['name'] ?? ''),
                'enabled' => (bool)($row['enabled'] ?? true),
                'priority' => (int)($row['priority'] ?? 100),
                'match_model' => $row['match_model'] ?? null,
                'match_provider' => $row['match_provider'] ?? null,
                'match_reason' => $row['match_reason'] ?? null,
                'action' => (string)($row['action'] ?? 'substitute'),
                'action_model' => $row['action_model'] ?? null,
                'updated_at' => (int)($row['updated_at'] ?? 0),
            ];
        }
        usort($out, static fn(array $a, array $b): int => $a['priority'] <=> $b['priority']);
        return $out;
    }

    /** @param array<int,array<string,mixed>> $rules */
    /** @return array{ok:bool,error:array<string,mixed>|null} */
    private function writeRules(array $rules): array
    {
        $result = RuntimeConfig::updateChecked(['models.fallback.rules' => array_values($rules)]);
        return ['ok' => (bool)$result['ok'], 'error' => $result['error']];
    }

    /** The models the Chat pool currently has selected. */
    private function requestedIds(): array
    {
        $fromQuery = $this->normalizeIds($_GET['ids'] ?? null);
        if ($fromQuery !== []) { return $fromQuery; }

        $selected = Config::get('pipeline.default_selected_models', []);
        if (!is_array($selected)) { return []; }
        $out = [];
        foreach ($selected as $entry) {
            if (is_string($entry) && trim($entry) !== '') { $out[] = trim($entry); continue; }
            if (!is_array($entry)) { continue; }
            $provider = trim((string)($entry['provider'] ?? ''));
            $model = trim((string)($entry['model'] ?? ''));
            if ($model === '') { continue; }
            $out[] = ($provider !== '' && !str_contains($model, '/')) ? "$provider/$model" : $model;
        }
        return array_values(array_unique($out));
    }

    /**
     * Normalise an id list from a query string, a JSON array or a JSON string.
     *
     * @return array<int,string>
     */
    private function normalizeIds(mixed $raw): array
    {
        if ($raw === null) { return []; }
        if (is_string($raw)) {
            $trimmed = trim($raw);
            if ($trimmed === '') { return []; }
            if ($trimmed[0] === '[') {
                $decoded = json_decode($trimmed, true);
                $raw = is_array($decoded) ? $decoded : [$trimmed];
            } else {
                $raw = explode(',', $trimmed);
            }
        }
        if (!is_array($raw)) { return []; }
        $out = [];
        foreach ($raw as $item) {
            $id = self::cleanId($item);
            if ($id !== null) { $out[] = $id; }
        }
        return array_values(array_unique($out));
    }

    /**
     * Validate one model id.
     *
     * The shape is `vendor/model` or `provider/model`, both ASCII, both
     * bounded. Anything else is rejected rather than passed through: these ids
     * end up in a SQL parameter (safe, but a 10 KB id is a waste), in a
     * localStorage key, and in an HTML attribute.
     */
    private static function cleanId(mixed $value): ?string
    {
        if (!is_string($value)) { return null; }
        $id = trim($value);
        if ($id === '' || strlen($id) > 191) { return null; }
        // Model ids in the wild are `openai/gpt-5.5`, `anthropic/claude-opus-4.5`,
        // `deepseek/deepseek-v4-pro`: letters, digits, dot, dash, underscore,
        // colon and one or two slashes. No whitespace, no quotes, no angle
        // brackets, no backslash.
        if (preg_match('#^[A-Za-z0-9][A-Za-z0-9._:\-/]{0,190}$#', $id) !== 1) { return null; }
        return $id;
    }

    /** @param class-string<\BackedEnum> $enum */
    private static function cleanEnum(mixed $value, string $enum): ?string
    {
        if (!is_string($value) || $value === '') { return null; }
        $case = $enum::tryFrom(strtolower(trim($value)));
        return $case instanceof \BackedEnum ? (string)$case->value : null;
    }

    /**
     * The active model catalogue.
     *
     * @return array<int,array<string,mixed>>
     */
    private function catalogue(): array
    {
        $raw = Config::get('models.catalog', Config::get('config.models', []));
        if (!is_array($raw)) { return []; }
        $out = [];
        foreach ($raw as $row) {
            if (!is_array($row)) { continue; }
            if (($row['provider'] ?? '') === '' && ($row['model'] ?? $row['id'] ?? '') === '') { continue; }
            $out[] = $row;
        }
        return $out;
    }

    /** @param array<int,array<string,mixed>> $catalogue */
    private function findRow(array $catalogue, string $id): ?array
    {
        foreach ($catalogue as $row) {
            if (AlternativeResolver::id($row) === $id) { return $row; }
        }
        return null;
    }

    /** @return array<string,mixed> */
    private function jsonBody(): array
    {
        $raw = file_get_contents('php://input');
        if (!is_string($raw) || $raw === '' || strlen($raw) > 262_144) { return []; }
        $decoded = json_decode($raw, true, 16);
        return is_array($decoded) ? $decoded : [];
    }
}
