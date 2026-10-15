<?php
/**
 * SubstitutionPlan: the result of planning a pool's replacements.
 *
 * Deliberately NOT just the rewritten pool. Three lists travel together, and
 * each exists because dropping it would hide something from the operator:
 *
 *   pool        the effective models the run will use;
 *   replaced    every from -> to swap, with the score and the component
 *               breakdown that justified it, so the UI can show
 *               "gpt-5.5 → claude-opus-5 (87 % fit: same vendor, 2× context)"
 *               instead of silently answering with a different model;
 *   unfilled    slots that could NOT be filled, with the reason. This is the
 *               list that makes "you asked for five, you got four" impossible
 *               to miss.
 *
 * @project  RevoAp   @version 2.0.0   @since 2.0.0
 */
declare(strict_types=1);

namespace App\Models\Fallback;

final class SubstitutionPlan
{
    /** @var array<int,array<string,mixed>> */
    private array $pool = [];

    /** How many slots the operator asked for. Tracked separately from the pool
     *  because an unfilled slot is REMOVED from the effective pool — deriving
     *  the request from `count($pool) + count($unfilled)` double-counts. */
    private ?int $requestedCount = null;

    /** @var array<int,array<string,mixed>> */
    private array $replaced = [];

    /** @var array<int,array<string,mixed>> */
    private array $unfilled = [];

    public function __construct(public readonly FallbackMode $mode = FallbackMode::Auto)
    {
    }

    /**
     * @param array<int,array<string,mixed>> $pool      models that WILL run
     * @param int|null                       $requested slots the operator asked
     *                                                  for; null derives it
     */
    public function withPool(array $pool, ?int $requested = null): self
    {
        $this->pool = $pool;
        $this->requestedCount = $requested;
        return $this;
    }

    public function addReplacement(int $index, string $fromId, AlternativeCandidate $to, ?UnavailableReason $why): void
    {
        $this->replaced[] = [
            'index' => $index,
            'from' => $fromId,
            'to' => $to->id,
            'to_provider' => $to->provider,
            'to_model' => $to->model,
            'score' => $to->score,
            'components' => $to->components,
            'same_vendor' => $to->sameVendor,
            'reason' => $why?->value,
            'reason_label_key' => $why?->labelKey(),
        ];
    }

    public function addUnfilled(int $index, string $fromId, ?UnavailableReason $why, string $cause): void
    {
        $this->unfilled[] = [
            'index' => $index,
            'from' => $fromId,
            'reason' => $why?->value,
            'reason_label_key' => $why?->labelKey(),
            'cause' => $cause,
        ];
    }

    /** @return array<int,array<string,mixed>> */
    public function pool(): array { return $this->pool; }
    /** @return array<int,array<string,mixed>> */
    public function replaced(): array { return $this->replaced; }
    /** @return array<int,array<string,mixed>> */
    public function unfilled(): array { return $this->unfilled; }

    public function changed(): bool { return $this->replaced !== [] || $this->unfilled !== []; }

    /**
     * Slots the operator asked for. When it was not supplied explicitly (a plan
     * built by hand, or an old caller) it is derived, which over-counts only in
     * the case where a broken model was both dropped and reported.
     */
    public function requested(): int
    {
        return $this->requestedCount ?? (count($this->pool) + count($this->unfilled));
    }

    /** Models that will actually answer. */
    public function effective(): int { return count($this->pool); }

    /** True when the run is weaker than the operator configured. */
    public function isShort(): bool { return $this->effective() < $this->requested(); }

    /**
     * True when the plan cannot be honoured and the run should stop.
     * Only {@see FallbackMode::Strict} makes a gap fatal.
     */
    public function mustAbort(): bool
    {
        return $this->mode->abortsOnGap() && $this->unfilled !== [];
    }

    /** True when the UI must pause and ask before proceeding. */
    public function needsConfirmation(): bool
    {
        return $this->mode === FallbackMode::Ask && $this->changed();
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'mode' => $this->mode->value,
            'changed' => $this->changed(),
            'must_abort' => $this->mustAbort(),
            'needs_confirmation' => $this->needsConfirmation(),
            'requested' => $this->requested(),
            'effective' => $this->effective(),
            'short' => $this->isShort(),
            'replaced' => $this->replaced,
            'unfilled' => $this->unfilled,
            'pool' => array_map(static function (array $row): array {
                return [
                    'provider' => (string)($row['provider'] ?? ''),
                    'model' => (string)($row['model'] ?? $row['id'] ?? ''),
                ];
            }, $this->pool),
        ];
    }
}
