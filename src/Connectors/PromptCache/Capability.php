<?php
/**
 * Capability: what prompt caching looks like for one base provider family.
 *
 * Immutable value object produced by {@see Capabilities::forModel()}. Every
 * field is either a fact taken from the Bothub «Кэширование промптов»
 * documentation or `null`, and `null` always means *the documentation does
 * not state it* — never "zero" and never "free". That distinction is the
 * whole point of the class: the request builder must be able to tell
 * "this vendor caches automatically, leave the payload alone" apart from
 * "nobody documented this vendor, so promise nothing".
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-09-21
 */
declare(strict_types=1);

namespace App\Connectors\PromptCache;

final readonly class Capability
{
    /**
     * The provider caches automatically and needs no request-side change:
     * the payload is sent exactly as before and no saving is promised
     * until the provider reports one.
     */
    public const MODE_AUTOMATIC = 'automatic';

    /**
     * Caching only happens when the request carries explicit
     * `cache_control` breakpoints (Anthropic; Gemini through Bothub).
     */
    public const MODE_EXPLICIT = 'explicit';

    /**
     * The family is not described by the documentation. No breakpoint is
     * added and no caching is promised — the request keeps its legacy
     * shape.
     */
    public const MODE_UNKNOWN = 'unknown';

    /**
     * @param string     $family       Base provider family slug ({@see \App\Models\ProviderFamily}).
     * @param string     $label        Human label for the table in Settings.
     * @param string     $mode         One of MODE_AUTOMATIC / MODE_EXPLICIT / MODE_UNKNOWN.
     * @param int|null   $minPromptTokens     Documented minimum prompt size, or null when unstated.
     * @param float|null $writeMultiplier     Cache-write price vs. the input price (0.0 = free).
     * @param float|null $readMultiplierMin   Cheapest documented cache-read multiplier.
     * @param float|null $readMultiplierMax   Dearest documented cache-read multiplier.
     * @param int|null   $maxBreakpoints      Documented breakpoint ceiling, or null when unstated.
     * @param string     $notes        One-line summary shown in the Settings table.
     */
    public function __construct(
        public string $family,
        public string $label,
        public string $mode,
        public ?int   $minPromptTokens,
        public ?float $writeMultiplier,
        public ?float $readMultiplierMin,
        public ?float $readMultiplierMax,
        public ?int   $maxBreakpoints,
        public string $notes,
    ) {}

    /** True when the request must carry `cache_control` for caching to happen. */
    public function requiresBreakpoints(): bool
    {
        return $this->mode === self::MODE_EXPLICIT;
    }

    /** True when the provider caches on its own and the payload must stay untouched. */
    public function isAutomatic(): bool
    {
        return $this->mode === self::MODE_AUTOMATIC;
    }

    /** True when the documentation says nothing about this family. */
    public function isUnknown(): bool
    {
        return $this->mode === self::MODE_UNKNOWN;
    }

    /** True when at least one cache statistic was documented for this family. */
    public function hasDocumentedPricing(): bool
    {
        return $this->writeMultiplier !== null
            || $this->readMultiplierMin !== null
            || $this->readMultiplierMax !== null;
    }

    /**
     * Read multiplier rendered the way the documentation writes it:
     * `0.25×`, `0.25–0.5×`, or `—` when unstated.
     */
    public function readMultiplierLabel(): string
    {
        $min = $this->readMultiplierMin;
        $max = $this->readMultiplierMax;
        if ($min === null && $max === null) {
            return '—';
        }
        $min ??= $max;
        $max ??= $min;
        if ($min === $max) {
            return self::fmtMultiplier($min) . '×';
        }
        return self::fmtMultiplier($min) . '–' . self::fmtMultiplier($max) . '×';
    }

    /** Write multiplier rendered as `1.25×` / `0× (запись бесплатна)` / `—`. */
    public function writeMultiplierLabel(): string
    {
        if ($this->writeMultiplier === null) {
            return '—';
        }
        if ($this->writeMultiplier === 0.0) {
            return '0× (запись бесплатна)';
        }
        return self::fmtMultiplier($this->writeMultiplier) . '×';
    }

    /** Minimum prompt size as `1024` / `1028 (Flash), 2048 (Pro)` / `не указан`. */
    public function minPromptLabel(): string
    {
        return $this->minPromptTokens === null ? 'не указан' : (string)$this->minPromptTokens;
    }

    /** Breakpoint ceiling as `4` / `нет ограничения` / `не применимо` / `не указано`. */
    public function maxBreakpointsLabel(): string
    {
        if ($this->maxBreakpoints !== null) {
            return (string)$this->maxBreakpoints;
        }
        return match ($this->mode) {
            self::MODE_AUTOMATIC => 'не применимо',
            self::MODE_UNKNOWN   => 'не указано',
            default              => 'нет ограничения',
        };
    }

    /** Human label for the caching mode column. */
    public function modeLabel(): string
    {
        return match ($this->mode) {
            self::MODE_AUTOMATIC => 'автоматически',
            self::MODE_EXPLICIT  => 'нужны точки останова',
            default              => 'неизвестно',
        };
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'family'              => $this->family,
            'label'               => $this->label,
            'mode'                => $this->mode,
            'mode_label'          => $this->modeLabel(),
            'min_prompt_tokens'   => $this->minPromptTokens,
            'min_prompt_label'    => $this->minPromptLabel(),
            'write_multiplier'    => $this->writeMultiplier,
            'write_label'         => $this->writeMultiplierLabel(),
            'read_multiplier_min' => $this->readMultiplierMin,
            'read_multiplier_max' => $this->readMultiplierMax,
            'read_label'          => $this->readMultiplierLabel(),
            'max_breakpoints'     => $this->maxBreakpoints,
            'max_breakpoints_label' => $this->maxBreakpointsLabel(),
            'notes'               => $this->notes,
        ];
    }

    private static function fmtMultiplier(float $v): string
    {
        $s = rtrim(rtrim(number_format($v, 4, '.', ''), '0'), '.');
        return $s === '' ? '0' : $s;
    }
}
