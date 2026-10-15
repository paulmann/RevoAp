<?php
/**
 * Capabilities: the prompt-caching capability table (C1).
 *
 * One row per base provider family, transcribed from the Bothub
 * «Кэширование промптов» documentation. It answers three questions the
 * rest of the feature needs to ask, and it is the *only* place the answers
 * live:
 *
 *   1. does this family need explicit `cache_control` breakpoints, cache
 *      automatically, or is nothing known about it?
 *   2. below what prompt size is caching pointless for this family?
 *   3. what does a cache write / cache read cost relative to the input?
 *
 * Families the documentation does not cover resolve to
 * {@see Capability::MODE_UNKNOWN}, which is a *decision*, not a default:
 * the request builder then leaves the payload exactly as it was and the UI
 * says so instead of advertising a saving that was never documented.
 *
 * The family is derived from the model id alone ({@see \App\Models\ProviderFamily::of()}),
 * so no vendor table has to be kept in sync here — a new `claude-*` model
 * is classified the moment it appears in the catalogue.
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

use App\Models\ProviderFamily;

final class Capabilities
{
    /**
     * Documented families, in the order the Settings table lists them:
     * the two that need request-side work first, then the automatic ones.
     *
     * `null` in any numeric field means "the documentation does not state
     * it" — see {@see Capability}.
     *
     * @var array<string,array{mode:string,min:?int,write:?float,read_min:?float,read_max:?float,breakpoints:?int,notes:string}>
     */
    private const TABLE = [
        'anthropic' => [
            'mode'        => Capability::MODE_EXPLICIT,
            'min'         => null,
            'write'       => 1.25,
            'read_min'    => 0.1,
            'read_max'    => 0.1,
            'breakpoints' => 4,
            'notes'       => 'Кеширование включается явно, точками останова cache_control; не более 4 точек. '
                           . 'TTL по умолчанию 5 минут, «"ttl":"1h"» продлевает до часа. '
                           . 'Запись (1.25×) дороже ввода, чтение (0.1×) дешевле — поэтому скидка на записи отрицательная.',
        ],
        'google' => [
            'mode'        => Capability::MODE_EXPLICIT,
            'min'         => 1028,
            'write'       => 0.0,
            'read_min'    => 0.25,
            'read_max'    => 0.25,
            'breakpoints' => null,
            'notes'       => 'Неявное кеширование 2.5 Pro/Flash: записи и хранения нет, чтение 0.25×, TTL 5 минут и не продлевается, '
                           . 'минимум 2048 токенов (2.5 Pro) и 1028 (2.5 Flash). Через Bothub включается явными точками останова '
                           . 'cache_control, как у Anthropic; ограничения на число точек нет, Bothub использует только последнюю.',
        ],
        'openai' => [
            'mode'        => Capability::MODE_AUTOMATIC,
            'min'         => 1024,
            'write'       => 0.0,
            'read_min'    => 0.25,
            'read_max'    => 0.5,
            'breakpoints' => null,
            'notes'       => 'Включено автоматически, настройка не нужна; запись бесплатна, чтение 0.25–0.5×, минимальный промпт 1024 токена.',
        ],
        'xai' => [
            'mode'        => Capability::MODE_AUTOMATIC,
            'min'         => null,
            'write'       => 0.0,
            'read_min'    => 0.25,
            'read_max'    => 0.25,
            'breakpoints' => null,
            'notes'       => 'Grok: автоматически; запись бесплатна, чтение 0.25×.',
        ],
        'moonshot' => [
            'mode'        => Capability::MODE_AUTOMATIC,
            'min'         => null,
            'write'       => 0.0,
            'read_min'    => 0.25,
            'read_max'    => 0.25,
            'breakpoints' => null,
            'notes'       => 'Moonshot AI: автоматически; запись бесплатна, чтение 0.25×. '
                           . 'Сюда же попадают модели Kimi K2 (на них кеширование включено у Groq).',
        ],
        'groq' => [
            'mode'        => Capability::MODE_AUTOMATIC,
            'min'         => null,
            'write'       => 0.0,
            'read_min'    => 0.5,
            'read_max'    => 0.5,
            'breakpoints' => null,
            'notes'       => 'Groq: автоматически (сейчас — на моделях Kimi K2); запись бесплатна, чтение 0.5×.',
        ],
        'deepseek' => [
            'mode'        => Capability::MODE_AUTOMATIC,
            'min'         => null,
            'write'       => 1.0,
            'read_min'    => 0.1,
            'read_max'    => 0.1,
            'breakpoints' => null,
            'notes'       => 'Автоматически; запись 1× (по цене ввода), чтение 0.1×.',
        ],
    ];

    /**
     * Labels for rows that are not base provider families.
     *
     * `groq` is a *hosting* provider, not a model vendor: the model served
     * on it (Kimi K2) belongs to the Moonshot family, so it has no entry in
     * {@see ProviderFamily}'s catalogue and would otherwise render under
     * its bare slug. Overriding the label here keeps the table readable
     * without adding a pseudo-vendor to the family catalogue that the
     * analyzer prompt and the consensus-diversity rule both read from.
     *
     * @var array<string,string>
     */
    private const LABEL_OVERRIDES = [
        'groq' => 'Groq (hosting for Kimi K2)',
    ];

    /**
     * Documented minimum prompt size for a concrete model id.
     *
     * The only family whose minimum depends on the model rather than on
     * the vendor is Gemini: the documentation gives 2048 tokens for 2.5 Pro
     * and 1028 for 2.5 Flash. Everything else either has one number or
     * none. Returning the *stated* number matters because it feeds the
     * "do not put a breakpoint on a prompt that is too small to cache"
     * guard — a guess there would silently disable caching.
     */
    public static function minPromptTokensFor(string $model): ?int
    {
        $cap = self::forModel($model);
        if ($cap->family === 'google') {
            return str_contains(ProviderFamily::slug($model), 'pro') ? 2048 : 1028;
        }
        return $cap->minPromptTokens;
    }

    /** Capability row for a model id, resolved through its base family. */
    public static function forModel(string $model): Capability
    {
        return self::forFamily(ProviderFamily::of($model));
    }

    /** Capability row for a family slug; `unknown` for anything undocumented. */
    public static function forFamily(string $family): Capability
    {
        $key  = strtolower(trim($family));
        $key  = $key === '' ? ProviderFamily::UNKNOWN : $key;
        $label = self::LABEL_OVERRIDES[$key] ?? ProviderFamily::label($key);
        $row  = self::TABLE[$key] ?? null;

        if ($row === null) {
            return new Capability(
                family:               $key,
                label:                $label,
                mode:                 Capability::MODE_UNKNOWN,
                minPromptTokens:      null,
                writeMultiplier:      null,
                readMultiplierMin:    null,
                readMultiplierMax:    null,
                maxBreakpoints:       null,
                notes:                'В документации Bothub это семейство не описано: точки останова не добавляются, '
                                    . 'кеширование не обещается. Статистику кеша покажем, если провайдер её вернёт.',
            );
        }

        return new Capability(
            family:            $key,
            label:             $label,
            mode:              $row['mode'],
            minPromptTokens:   $row['min'],
            writeMultiplier:   $row['write'],
            readMultiplierMin: $row['read_min'],
            readMultiplierMax: $row['read_max'],
            maxBreakpoints:    $row['breakpoints'],
            notes:             $row['notes'],
        );
    }

    /**
     * Every documented family plus the two terminal states the UI must
     * show, in table order: the documented rows (explicit first, then
     * automatic) followed by the "everything else" row.
     *
     * @return Capability[]
     */
    public static function documented(): array
    {
        $out = [];
        foreach (array_keys(self::TABLE) as $family) {
            $out[] = self::forFamily($family);
        }
        $out[] = self::forFamily(ProviderFamily::UNKNOWN);
        return $out;
    }

    /** @return array<int,array<string,mixed>> */
    public static function documentedArray(): array
    {
        return array_map(static fn(Capability $c): array => $c->toArray(), self::documented());
    }
}
