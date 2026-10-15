<?php
/**
 * ModelVersion: compare model identifiers by release version.
 *
 * The actualization run replaces a selected model with the newest release
 * in the same *line*: `claude-sonnet-4` → `claude-sonnet-4.6`,
 * `gpt-5.2` → `gpt-5.5`. "Same line" is what makes that safe — a jump
 * from `gpt-5.5` to `gpt-5.5-pro`, or from `gemini-2.5-pro` to
 * `gemini-2.5-flash-lite`, is a *different* model, not a newer one, and
 * silently swapping it would change what the operator asked for.
 *
 * Line and version are derived from the identifier alone:
 *
 *   `anthropic/claude-sonnet-4.6` → line `claudesonnet`,  version [4, 6]
 *   `gpt-5.5-pro`                 → line `gptpro`,        version [5, 5]
 *   `deepseek-r1`                 → line `deepseekr1`,    version []
 *   `gemini-2.5-flash-lite-preview-09-2025`
 *                                 → line `geminiflashlitepreview`,
 *                                   version [2, 5, 9, 2025]
 *
 * So a release alias like `deepseek-r1` (no numeric tokens outside `r1`,
 * which is one token and therefore part of the line) is never treated as
 * versioned, and two rows are comparable only when their lines match.
 *
 * The mode suffix is not part of the model: `gpt-4o:batch` and `gpt-4o` are
 * the same release of the same line, because {@see ProviderFamily::slug()}
 * — the one funnel the identifier is read through here — drops `:batch`
 * before any tokenising ({@see ModelId}). Without that, the two ids would
 * tokenise to `gptobatch` and `gpto`, become incomparable, and the
 * actualization run would quietly stop versioning either of them.
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

final class ModelVersion
{
    /**
     * Split an id into its release line and its version tuple.
     *
     * @return array{line:string,version:array<int,int>}
     */
    public static function parse(string $model): array
    {
        $slug = ProviderFamily::slug($model);
        // `-`, `_`, `.` and any other separator are equivalent here: the
        // version is read as the sequence of numeric tokens, the line as
        // everything else.
        $tokens = preg_split('/[^a-z0-9]+/', $slug) ?: [];
        $line = '';
        $version = [];
        foreach ($tokens as $token) {
            if ($token === '') { continue; }
            if (ctype_digit($token)) {
                $version[] = (int)$token;
                continue;
            }
            $line .= $token;
        }
        return ['line' => $line === '' ? $slug : $line, 'version' => $version];
    }

    /** The release line of a model id (`gpt-5.5-pro` → `gptpro`). */
    public static function line(string $model): string
    {
        return self::parse($model)['line'];
    }

    /** @return array<int,int> */
    public static function tuple(string $model): array
    {
        return self::parse($model)['version'];
    }

    /**
     * Compare two identifiers on the same line.
     *
     * A shorter tuple is compared as if padded with -1, so `[4,6]` beats
     * `[4]` (a missing component never counts as a newer one) while
     * `[5]` beats `[5,0]`-with-nothing-after only on the tie-break below.
     *
     * @return int -1 when $a is older, 0 on a tie, 1 when $a is newer
     */
    public static function compare(string $a, string $b): int
    {
        $va = self::tuple($a);
        $vb = self::tuple($b);
        $len = max(count($va), count($vb));
        for ($i = 0; $i < $len; $i++) {
            $x = $va[$i] ?? -1;
            $y = $vb[$i] ?? -1;
            if ($x !== $y) {
                return $x < $y ? -1 : 1;
            }
        }
        return 0;
    }

    /**
     * Pick the newest release of `$current`'s line out of a candidate
     * list, or null when no candidate is on that line.
     *
     * @param array<int,string> $candidates model ids, any order
     * @param string            $current    the id being superseded
     */
    public static function newestOnSameLine(array $candidates, string $current): ?string
    {
        $line = self::line($current);
        $best = null;
        foreach ($candidates as $candidate) {
            if (!is_string($candidate) || $candidate === '') { continue; }
            if (self::line($candidate) !== $line) { continue; }
            if ($best === null || self::compare($candidate, $best) > 0) {
                $best = $candidate;
            }
        }
        return $best;
    }
}
