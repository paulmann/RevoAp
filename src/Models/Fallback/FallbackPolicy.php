<?php
/**
 * FallbackPolicy: the operator's substitution rules, as one immutable value.
 *
 * Every knob the UI exposes maps to exactly one property here, and
 * {@see fromConfig()} is the only place that reads configuration. That
 * separation is what lets {@see AlternativeResolver} be pure and unit-testable:
 * a test constructs a policy literal instead of stubbing a global Config.
 *
 * Defaults are conservative in the direction that cannot lose data:
 * the context window is respected (never truncate a prompt), degraded models
 * are not substituted in (a flaky voice weakens a consensus), and cost has a
 * ceiling but the ceiling is soft (a run that needs five voices should not
 * fail over a 1.6× price).
 *
 * @project  RevoAp   @version 2.0.0   @since 2.0.0
 */
declare(strict_types=1);

namespace App\Models\Fallback;

use App\Core\Config;

final class FallbackPolicy
{
    public function __construct(
        public readonly FallbackMode $mode = FallbackMode::Auto,
        public readonly bool $preferVendorDiversity = true,
        public readonly bool $sameVendorOnly = false,
        public readonly bool $respectContextWindow = true,
        public readonly bool $allowDegradedSubstitutes = false,
        public readonly int $maxLatencyMs = 0,
        public readonly float $maxCostMultiplier = 1.5,
        public readonly bool $costIsHardLimit = false,
        public readonly int $maxAlternatives = 6,
        /** Per-model ordered overrides: `openrouter/openai/gpt-5.5` => [ids…] */
        public readonly array $chains = [],
        public readonly bool $monitorEnabled = true,
        public readonly int $monitorIntervalSeconds = 300,
        public readonly int $monitorWindowSeconds = 3600,
        public readonly float $degradedSuccessFloor = 0.85,
        public readonly float $unavailableSuccessFloor = 0.40,
        public readonly int $minSamplesForVerdict = 5,
    ) {
    }

    /**
     * Read the policy from config.php / .config.php / runtime.json.
     *
     * Keys live under `models.fallback.*`. Everything is coerced and clamped:
     * a runtime overlay an operator edits by hand must not be able to produce
     * a negative multiplier or a zero-candidate limit.
     */
    public static function fromConfig(): self
    {
        // tryGet: the resolver is pure and is exercised by unit tests that do
        // not boot an Application. A policy that cannot be read falls back to
        // the constructor defaults, which are the documented shipped defaults.
        $get = static fn(string $key, mixed $default): mixed => Config::tryGet('models.fallback.' . $key, $default);

        $chains = $get('chains', []);
        $normalizedChains = [];
        if (is_array($chains)) {
            foreach ($chains as $model => $list) {
                if (!is_string($model) || !is_array($list)) { continue; }
                $clean = [];
                foreach ($list as $candidate) {
                    if (is_string($candidate) && trim($candidate) !== '') { $clean[] = trim($candidate); }
                }
                if ($clean !== []) { $normalizedChains[$model] = array_values(array_unique($clean)); }
            }
        }

        return new self(
            mode: FallbackMode::parse($get('mode', 'auto')),
            preferVendorDiversity: (bool)$get('prefer_vendor_diversity', true),
            sameVendorOnly: (bool)$get('same_vendor_only', false),
            respectContextWindow: (bool)$get('respect_context_window', true),
            allowDegradedSubstitutes: (bool)$get('allow_degraded_substitutes', false),
            maxLatencyMs: max(0, (int)$get('max_latency_ms', 0)),
            maxCostMultiplier: max(0.0, (float)$get('max_cost_multiplier', 1.5)),
            costIsHardLimit: (bool)$get('cost_is_hard_limit', false),
            maxAlternatives: max(1, min(50, (int)$get('max_alternatives', 6))),
            chains: $normalizedChains,
            monitorEnabled: (bool)$get('monitor.enabled', true),
            monitorIntervalSeconds: max(30, min(86_400, (int)$get('monitor.interval_seconds', 300))),
            monitorWindowSeconds: max(60, min(604_800, (int)$get('monitor.window_seconds', 3600))),
            degradedSuccessFloor: self::clampRatio((float)$get('monitor.degraded_below', 0.85)),
            unavailableSuccessFloor: self::clampRatio((float)$get('monitor.unavailable_below', 0.40)),
            minSamplesForVerdict: max(1, min(1000, (int)$get('monitor.min_samples', 5))),
        );
    }

    /** The ordered chain for one model, or [] when none is configured. */
    public function chainFor(string $modelId): array
    {
        return $this->chains[$modelId] ?? [];
    }

    /**
     * @return array<string,mixed> the serialisable form for the admin API
     */
    public function toArray(): array
    {
        return [
            'mode' => $this->mode->value,
            'mode_label_key' => $this->mode->labelKey(),
            'prefer_vendor_diversity' => $this->preferVendorDiversity,
            'same_vendor_only' => $this->sameVendorOnly,
            'respect_context_window' => $this->respectContextWindow,
            'allow_degraded_substitutes' => $this->allowDegradedSubstitutes,
            'max_latency_ms' => $this->maxLatencyMs,
            'max_cost_multiplier' => $this->maxCostMultiplier,
            'cost_is_hard_limit' => $this->costIsHardLimit,
            'max_alternatives' => $this->maxAlternatives,
            'chains' => $this->chains,
            'monitor' => [
                'enabled' => $this->monitorEnabled,
                'interval_seconds' => $this->monitorIntervalSeconds,
                'window_seconds' => $this->monitorWindowSeconds,
                'degraded_below' => $this->degradedSuccessFloor,
                'unavailable_below' => $this->unavailableSuccessFloor,
                'min_samples' => $this->minSamplesForVerdict,
            ],
        ];
    }

    public function withMode(FallbackMode $mode): self
    {
        return new self(
            $mode, $this->preferVendorDiversity, $this->sameVendorOnly, $this->respectContextWindow,
            $this->allowDegradedSubstitutes, $this->maxLatencyMs, $this->maxCostMultiplier,
            $this->costIsHardLimit, $this->maxAlternatives, $this->chains, $this->monitorEnabled,
            $this->monitorIntervalSeconds, $this->monitorWindowSeconds, $this->degradedSuccessFloor,
            $this->unavailableSuccessFloor, $this->minSamplesForVerdict,
        );
    }

    private static function clampRatio(float $v): float
    {
        if (!is_finite($v)) { return 0.0; }
        return max(0.0, min(1.0, $v));
    }
}
