<?php
/**
 * AlternativeResolver: rank and pick replacements for models that cannot answer.
 *
 * This is the class behind "make choosing an alternative convenient". It is
 * pure — no database, no network, no config reads — because a ranking rule
 * that cannot be exercised in isolation is a ranking rule nobody trusts. The
 * caller supplies the catalogue rows, the availability verdicts and the
 * policy; the resolver returns an ordered, explainable candidate list.
 *
 * Why a scored ranking rather than a first-match walk
 * ---------------------------------------------------
 * `ModelSubstitution::plan()` (v1.x) takes the first candidate whose vendor is
 * not already in the pool. That is the right idea and the wrong tie-break: it
 * will happily substitute a 4k-context model for a 200k-context one, or a
 * model that costs 40× more, because both are "a different vendor". When the
 * substitution is silent the operator discovers it in the invoice or in a
 * truncated answer.
 *
 * So every candidate gets a 0..1 **fit score** built from five weighted
 * signals, and the resolver returns the score components alongside the pick.
 * The UI shows them ("same vendor · 2× the context · 31 % cheaper · 99.2 %
 * success"), which turns a magic replacement into a decision the operator can
 * accept, override or codify as a rule.
 *
 * Hard constraints vs. soft preferences
 * ------------------------------------
 * `respect_context_window` and `same_vendor_only` are HARD: a candidate that
 * violates them is excluded, not merely scored down. Silently shrinking the
 * context window is data loss, and no score should justify it.
 *
 * `prefer_vendor_diversity`, latency history, success rate and price are SOFT:
 * they move the score, so a genuinely better same-vendor model still wins over
 * a worse diverse one.
 *
 * `max_cost_multiplier` is soft by default and hard when the policy says so —
 * a run that needs five voices should not fail because the only affordable
 * replacement is 1.6× instead of 1.5×.
 *
 * @project  RevoAp
 * @version  2.0.0
 * @since    2.0.0
 */
declare(strict_types=1);

namespace App\Models\Fallback;

final class AlternativeResolver
{
    /**
     * Weights of the five fit signals. They sum to 1.0 so the score is
     * directly readable as a percentage, and so retuning one signal cannot
     * silently inflate the total above 1.
     */
    private const W_VENDOR      = 0.22;
    private const W_CONTEXT     = 0.20;
    private const W_RELIABILITY = 0.26;
    private const W_LATENCY     = 0.14;
    private const W_PRICE       = 0.18;

    public function __construct(private readonly FallbackPolicy $policy)
    {
    }

    /**
     * Rank every catalogue row that could replace `$model`.
     *
     * @param array<string,mixed>        $model       The model being replaced.
     * @param array<int,array<string,mixed>> $catalogue Active catalogue rows.
     * @param array<int,string>          $poolIds     `provider/model` already in the run.
     * @param array<string,ModelAvailability> $availability Verdicts keyed by id.
     * @return array<int,AlternativeCandidate> best first
     */
    public function alternatives(
        array $model,
        array $catalogue,
        array $poolIds = [],
        array $availability = [],
    ): array {
        $modelId  = self::id($model);
        $modelVendor = self::vendor($model);
        $modelContext = self::intOrNull($model['context_length'] ?? $model['context_window'] ?? null);
        $modelPrice   = self::priceOf($model);
        $modelLatency = self::floatOrNull($model['avg_latency_ms'] ?? null);

        $inPool = [];
        foreach ($poolIds as $id) {
            $id = trim((string)$id);
            if ($id !== '') { $inPool[$id] = true; }
        }
        // The pool's vendors, for the diversity signal. The replaced model's
        // own vendor does NOT count: its slot is being vacated.
        $poolVendors = [];
        foreach ($catalogue as $row) {
            $id = self::id($row);
            if (isset($inPool[$id]) && $id !== $modelId) {
                $poolVendors[strtolower(self::vendor($row))] = true;
            }
        }

        $out = [];
        foreach ($catalogue as $row) {
            $id = self::id($row);
            if ($id === '' || $id === $modelId || isset($inPool[$id])) {
                continue;
            }
            $candidate = $this->score(
                $row,
                $id,
                $modelVendor,
                $modelContext,
                $modelPrice,
                $modelLatency,
                $poolVendors,
                $availability[$id] ?? null
            );
            if ($candidate !== null) {
                $out[] = $candidate;
            }
        }

        // Best score first; ties broken by id so the order is stable across
        // requests. An unstable order makes the UI reshuffle between two
        // refreshes and looks like a bug even though it is not.
        usort($out, static function (AlternativeCandidate $a, AlternativeCandidate $b): int {
            return ($b->score <=> $a->score) ?: strcmp($a->id, $b->id);
        });

        return array_slice($out, 0, $this->policy->maxAlternatives);
    }

    /**
     * The single best replacement, or null when the rules admit none.
     */
    public function best(
        array $model,
        array $catalogue,
        array $poolIds = [],
        array $availability = [],
    ): ?AlternativeCandidate {
        $list = $this->alternatives($model, $catalogue, $poolIds, $availability);
        return $list === [] ? null : $list[0];
    }

    /**
     * Plan substitutions for a whole pool at once.
     *
     * Slots are processed in order and each chosen candidate is removed from
     * the pool of remaining candidates, so two broken models can never be
     * given the same replacement. The returned plan records both the swaps
     * that happened and the slots that could not be filled, with the reason —
     * an unfilled slot that is silently dropped is how a "5-model council"
     * quietly becomes a 3-model one.
     */
    public function planPool(
        array $pool,
        array $catalogue,
        array $availability = [],
    ): SubstitutionPlan {
        $poolIds = [];
        foreach ($pool as $row) {
            $id = self::id($row);
            if ($id !== '') { $poolIds[$id] = true; }
        }

        $broken = [];
        foreach ($pool as $index => $row) {
            $id = self::id($row);
            if ($id === '') { continue; }
            $state = $availability[$id] ?? null;
            if ($state === null) { continue; }
            if (!$state->status->isUsable()) {
                $broken[] = ['index' => $index, 'row' => $row, 'id' => $id, 'why' => $state->primaryReason()];
            }
        }

        $plan = new SubstitutionPlan($this->policy->mode);

        // `Never` and `Strict` both refuse to substitute, for opposite reasons:
        // Never runs short and reports the gap, Strict aborts. Asking the enum
        // rather than comparing against one case is what keeps a future mode
        // from silently inheriting substitution it should not have.
        if ($broken === [] || !$this->policy->mode->substitutes()) {
            $cause = $this->policy->mode->abortsOnGap() ? 'policy_strict' : 'policy_never';
            foreach ($broken as $b) {
                $plan->addUnfilled($b['index'], $b['id'], $b['why'], $cause);
            }
            // An unusable model must not stay in the effective pool: it cannot
            // answer, and leaving it there makes `effective()` claim a voice
            // the run does not have.
            $effective = [];
            $dropIndexes = [];
            foreach ($broken as $b) { $dropIndexes[$b['index']] = true; }
            foreach ($pool as $index => $row) {
                if (!isset($dropIndexes[$index])) { $effective[] = $row; }
            }
            return $plan->withPool($effective, count($pool));
        }

        $next = $pool;
        $usedAsReplacement = [];
        $requestedCount = count($pool);
        $dropIndexes = [];

        foreach ($broken as $b) {
            $poolIdsNow = array_keys(array_diff_key($poolIds, [$b['id'] => true]));
            foreach ($usedAsReplacement as $id) { $poolIdsNow[] = $id; }

            $candidates = $this->alternatives($b['row'], $catalogue, $poolIdsNow, $availability);

            if ($candidates === []) {
                $plan->addUnfilled($b['index'], $b['id'], $b['why'], 'no_candidate');
                // The slot is dropped, not left holding a model that cannot
                // answer. Leaving it in would make `effective()` count a voice
                // the run does not have — which is precisely the silent
                // "you asked for five, you got four" this plan exists to
                // prevent. The gap stays visible through `unfilled`.
                $dropIndexes[$b['index']] = true;
                continue;
            }

            $chosen = $candidates[0];
            $row = $chosen->row;
            // Keep the slot's identity fields so downstream role/prompt
            // bookkeeping still sees the shape it expects.
            $next[$b['index']] = $row + [
                'provider' => $row['provider'] ?? '',
                'model'    => $row['model'] ?? '',
            ];
            $usedAsReplacement[] = $chosen->id;
            $plan->addReplacement($b['index'], $b['id'], $chosen, $b['why']);
        }

        if ($dropIndexes !== []) {
            $effective = [];
            foreach ($next as $index => $row) {
                if (!isset($dropIndexes[$index])) { $effective[] = $row; }
            }
            $next = $effective;
        }

        return $plan->withPool($next, $requestedCount);
    }

    /* ------------------------------------------------------------------
     * Scoring
     * ------------------------------------------------------------------ */

    /**
     * @param array<string,mixed> $row
     * @param array<string,bool>  $poolVendors lower-cased vendor => true
     */
    private function score(
        array $row,
        string $id,
        string $modelVendor,
        ?int $modelContext,
        ?float $modelPrice,
        ?float $modelLatency,
        array $poolVendors,
        ?ModelAvailability $availability,
    ): ?AlternativeCandidate {
        $status = $availability?->status ?? AvailabilityStatus::Unknown;

        // --- HARD constraints ------------------------------------------
        if (!$status->isSubstitutable() && !$this->policy->allowDegradedSubstitutes) {
            return null;
        }
        if ($status === AvailabilityStatus::Degraded && !$this->policy->allowDegradedSubstitutes) {
            return null;
        }
        if (!$this->isEnabled($row)) {
            return null;
        }

        $vendor = self::vendor($row);
        $sameVendor = $vendor !== '' && strcasecmp($vendor, $modelVendor) === 0;

        if ($this->policy->sameVendorOnly && !$sameVendor) {
            return null;
        }

        $context = self::intOrNull($row['context_length'] ?? $row['context_window'] ?? null);
        if ($this->policy->respectContextWindow
            && $modelContext !== null && $context !== null
            && $context < $modelContext) {
            // Shrinking the window would truncate the prompt. Never silent.
            return null;
        }

        $price = self::priceOf($row);
        if ($this->policy->maxCostMultiplier > 0
            && $price !== null && $modelPrice !== null && $modelPrice > 0.0
            && $price > $modelPrice * $this->policy->maxCostMultiplier
            && $this->policy->costIsHardLimit) {
            return null;
        }

        $latency = self::floatOrNull($row['avg_latency_ms'] ?? null);
        if ($this->policy->maxLatencyMs > 0 && $latency !== null && $latency > $this->policy->maxLatencyMs) {
            return null;
        }

        // --- SOFT signals ------------------------------------------------

        // Vendor diversity: reward a vendor not already in the pool. When the
        // policy pins the same vendor the signal is neutral (0.5) rather than
        // penalising, so it cannot distort the ranking it is not allowed to.
        if ($this->policy->sameVendorOnly) {
            $vendorScore = 0.5;
        } elseif ($this->policy->preferVendorDiversity) {
            $vendorScore = isset($poolVendors[strtolower($vendor)]) ? 0.15 : 1.0;
        } else {
            $vendorScore = isset($poolVendors[strtolower($vendor)]) ? 0.35 : 0.85;
        }
        // A same-vendor replacement is a mild positive when diversity is off:
        // pricing, rate limits and quirks are already known-good for this run.
        if ($sameVendor && !$this->policy->preferVendorDiversity) {
            $vendorScore = min(1.0, $vendorScore + 0.1);
        }

        // Context headroom: equal is 1.0, more is capped at 1.0 (a 1M window
        // is not better than a 200k window for a 4k prompt, it is just
        // usually more expensive), less is impossible because of the hard
        // constraint above. Unknown context scores neutral.
        if ($context === null || $modelContext === null || $modelContext <= 0) {
            $contextScore = 0.6;
        } else {
            $ratio = $context / $modelContext;
            $contextScore = $ratio >= 1.0 ? 1.0 : max(0.0, $ratio);
        }

        // Reliability: measured success rate beats an unmeasured model, which
        // in turn beats a model with a bad record. Unknown status is neutral
        // so a fresh catalogue is not penalised against a stale one.
        $successRate = $availability?->successRate;
        $reliabilityScore = match (true) {
            $status === AvailabilityStatus::Healthy && $successRate !== null => 0.6 + (0.4 * min(1.0, max(0.0, $successRate))),
            $status === AvailabilityStatus::Healthy                         => 0.95,
            $status === AvailabilityStatus::Unknown                         => 0.6,
            $status === AvailabilityStatus::Degraded                        => 0.25 + (0.2 * ($successRate ?? 0.0)),
            default                                                         => 0.0,
        };

        // Latency: faster is better, normalised against the model being
        // replaced. Without either measurement the signal is neutral.
        if ($latency === null || $modelLatency === null || $modelLatency <= 0.0) {
            $latencyScore = 0.6;
        } else {
            $ratio = $modelLatency / $latency;              // >1 means faster
            $latencyScore = max(0.0, min(1.0, 0.5 + ($ratio - 1.0) * 0.5));
        }

        // Price: cheaper is better. Equal price scores 0.75 so a free-of-charge
        // difference does not dominate; more expensive falls off linearly to
        // zero at the multiplier ceiling.
        if ($price === null || $modelPrice === null || $modelPrice <= 0.0) {
            $priceScore = 0.6;
        } else {
            $ratio = $price / $modelPrice;
            $priceScore = match (true) {
                $ratio <= 1.0 => 0.75 + (0.25 * (1.0 - $ratio)),
                $this->policy->maxCostMultiplier <= 0 => 0.5,
                $ratio >= $this->policy->maxCostMultiplier => 0.0,
                default => 0.75 * (1.0 - (($ratio - 1.0) / max(1e-9, $this->policy->maxCostMultiplier - 1.0))),
            };
        }

        $score = (self::W_VENDOR * $vendorScore)
               + (self::W_CONTEXT * $contextScore)
               + (self::W_RELIABILITY * $reliabilityScore)
               + (self::W_LATENCY * $latencyScore)
               + (self::W_PRICE * $priceScore);

        return new AlternativeCandidate(
            id: $id,
            provider: (string)($row['provider'] ?? ''),
            model: (string)($row['model'] ?? ''),
            row: $row,
            score: round(max(0.0, min(1.0, $score)), 4),
            components: [
                'vendor'      => round($vendorScore, 3),
                'context'     => round($contextScore, 3),
                'reliability' => round($reliabilityScore, 3),
                'latency'     => round($latencyScore, 3),
                'price'       => round($priceScore, 3),
            ],
            sameVendor: $sameVendor,
            contextLength: $context,
            avgLatencyMs: $latency,
            price: $price,
            status: $status,
            reason: $availability?->primaryReason(),
        );
    }

    /** A catalogue row is usable unless it is explicitly switched off. */
    private function isEnabled(array $row): bool
    {
        foreach (['enabled', 'active', 'is_active', 'is_enabled'] as $flag) {
            if (array_key_exists($flag, $row)) {
                return (bool)$row[$flag];
            }
        }
        return true;
    }

    /* ------------------------------------------------------------------
     * Field readers. The catalogue's shape differs between the BotHub and
     * OpenRouter importers, so every read is tolerant and typed.
     * ------------------------------------------------------------------ */

    public static function id(array $row): string
    {
        $provider = trim((string)($row['provider'] ?? ''));
        $model = trim((string)($row['model'] ?? $row['id'] ?? ''));
        if ($model === '') {
            return '';
        }
        // OpenRouter ids already carry the vendor prefix ("openai/gpt-5.5").
        // Prefixing again would produce "openrouter/openai/gpt-5.5", which
        // never matches anything and silently disables substitution.
        if ($provider !== '' && !str_contains($model, '/')) {
            return $provider . '/' . $model;
        }
        return $model;
    }

    public static function vendor(array $row): string
    {
        $explicit = (string)($row['vendor'] ?? $row['family'] ?? '');
        if ($explicit !== '') {
            return $explicit;
        }
        $model = (string)($row['model'] ?? $row['id'] ?? '');
        if (str_contains($model, '/')) {
            return explode('/', $model, 2)[0];
        }
        return (string)($row['provider'] ?? '');
    }

    private static function intOrNull(mixed $v): ?int
    {
        if ($v === null || $v === '') { return null; }
        if (is_int($v)) { return $v > 0 ? $v : null; }
        if (is_numeric($v)) { $n = (int)$v; return $n > 0 ? $n : null; }
        return null;
    }

    private static function floatOrNull(mixed $v): ?float
    {
        if ($v === null || $v === '') { return null; }
        if (is_int($v) || is_float($v)) { return (float)$v; }
        return is_numeric($v) ? (float)$v : null;
    }

    /**
     * Blended price per token, prompt and completion combined.
     *
     * A completion token is worth more than a prompt token in practice (runs
     * are completion-heavy), so the blend is 40/60 rather than a mean. The
     * exact ratio matters far less than using ONE consistent number: a
     * candidate ranked against a prompt-only price of the original model is
     * not comparable at all.
     */
    private static function priceOf(array $row): ?float
    {
        $prompt = self::floatOrNull(
            $row['pricing_prompt'] ?? $row['prompt_price'] ?? ($row['pricing']['prompt'] ?? null)
        );
        $completion = self::floatOrNull(
            $row['pricing_completion'] ?? $row['completion_price'] ?? ($row['pricing']['completion'] ?? null)
        );
        if ($prompt === null && $completion === null) {
            $blended = self::floatOrNull($row['price'] ?? $row['blended_price'] ?? null);
            return $blended !== null && $blended > 0 ? $blended : null;
        }
        $p = $prompt ?? 0.0;
        $c = $completion ?? 0.0;
        $blend = ($p * 0.4) + ($c * 0.6);
        return $blend > 0 ? $blend : null;
    }
}
