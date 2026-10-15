<?php
/**
 * ModelId: read the *mode* of a provider model identifier.
 *
 * Some providers serve a second, asynchronous surface for the same model.
 * BotHub/OpenAI-style endpoints expose it as a suffix on the identifier —
 * `gpt-4o:batch` — where the upstream Batch API takes a JSONL upload,
 * creates a batch, and is polled until the results can be downloaded. The
 * suffix therefore says *how* the id is served, not *what* the model is.
 *
 * That distinction has to be read in exactly one place, because treating
 * `:batch` as part of the name breaks three unrelated things at once:
 *
 *   1. Version comparison. `gpt-4o:batch` tokenises to `gptobatch`/`[4]`
 *      while `gpt-4o` is `gpto`/`[4]` — two different "lines", so the
 *      actualization run can no longer tell that they are the same model
 *      and stops upgrading either of them (see {@see ModelVersion} and
 *      {@see ProviderFamily}, which both strip the suffix).
 *   2. Synchronous dispatch. A `:batch` id sent to `/chat/completions` is
 *      not a model the endpoint serves, so the provider answers 404
 *      `MODEL_NOT_FOUND` — a message that reads like a typo in the
 *      operator's own configuration and says nothing about the real
 *      reason. {@see self::syncCallRejection()} exists so the connector
 *      refuses before the request leaves the process.
 *   3. The consensus pool. L1/L4 fan out over synchronous calls only, so a
 *      `:batch` row can never be a participant; it has to be marked as
 *      unusable for the pool with the reason attached (see
 *      {@see self::syncPoolRejection()}).
 *
 * What is deliberately NOT here: the asynchronous flow itself (upload,
 * create, poll, download). This class only classifies an identifier, which
 * is the part that is correct under any reading of the feature.
 *
 * Recognised shape: an optional `vendor/` prefix, a non-empty model part,
 * then the suffix `:batch` at the very end. Case-insensitive, surrounding
 * whitespace ignored. Anything else is a *synchronous* id: `batch-model`,
 * `gpt-4o:batch-preview`, `:batch` alone and `gpt-4o:batch2` are all left
 * untouched, so a model that merely mentions the word is never mistaken for
 * the mode.
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

final class ModelId
{
    /** The identifier is served by a synchronous chat/embeddings endpoint. */
    public const MODE_SYNC = 'sync';

    /** The identifier is served by the asynchronous Batch API only. */
    public const MODE_BATCH = 'batch';

    /** The suffix that carries the mode. */
    public const BATCH_SUFFIX = ':batch';

    /**
     * Why a `:batch` id must not be put into the synchronous pool. One
     * sentence, used verbatim by the actualization warnings and by the UI
     * badge tooltips, so the operator reads the same explanation wherever
     * the row shows up.
     */
    public const BATCH_POOL_REASON = 'batch-only identifier: it is served through the asynchronous Batch API, '
        . 'so it cannot take part in the synchronous consensus pool.';

    /**
     * Is this identifier served asynchronously only?
     *
     * @param string $model the id as the operator or the provider wrote it
     */
    public static function isBatchId(string $model): bool
    {
        return self::split($model) !== null;
    }

    /**
     * The identifier with the mode suffix removed.
     *
     * Synchronous ids — and anything the suffix rule does not recognise —
     * come back trimmed and otherwise untouched, so this is safe to call on
     * every id in a list. Idempotent: `baseModelId(baseModelId($id))` is the
     * same string.
     */
    public static function baseModelId(string $model): string
    {
        $parts = self::split($model);
        return $parts === null ? trim($model) : $parts['base'];
    }

    /**
     * The mode of an identifier: {@see self::MODE_BATCH} or
     * {@see self::MODE_SYNC}.
     */
    public static function modelMode(string $model): string
    {
        return self::isBatchId($model) ? self::MODE_BATCH : self::MODE_SYNC;
    }

    /**
     * Why a synchronous chat/embeddings call must refuse this id, or null
     * when the id is fine to call.
     *
     * The text names the id and both ways out (the plain id, or the batch
     * path), because the failure it replaces — a provider 404 carrying
     * `MODEL_NOT_FOUND` — reads like a typo and hides the actual reason.
     *
     * @return string|null the rejection text, or null when callable
     */
    public static function syncCallRejection(string $model): ?string
    {
        if (!self::isBatchId($model)) {
            return null;
        }
        return sprintf(
            'model "%s" is a batch-only identifier - it is served through the asynchronous Batch API '
                . 'and cannot be called synchronously; use the plain id or the batch path',
            trim($model),
        );
    }

    /**
     * Why this id is not eligible for the synchronous consensus pool, or
     * null when it is.
     *
     * @return string|null the reason, or null when pool-eligible
     */
    public static function syncPoolRejection(string $model): ?string
    {
        if (!self::isBatchId($model)) {
            return null;
        }
        return sprintf('%s (%s)', trim($model), self::BATCH_POOL_REASON);
    }

    /**
     * Split an id into its base and the recognised suffix.
     *
     * @return array{base:string,suffix:string}|null null when the suffix is
     *         absent, or when there is no model left without it
     */
    private static function split(string $model): ?array
    {
        $id = trim($model);
        $length = strlen(self::BATCH_SUFFIX);
        if (strlen($id) <= $length) {
            return null;
        }
        $suffix = substr($id, -$length);
        if (strcasecmp($suffix, self::BATCH_SUFFIX) !== 0) {
            return null;
        }
        // Trailing separators before the colon (`gpt-4o :batch`) belong to
        // the spelling, not to the model name.
        $base = rtrim(substr($id, 0, -$length));
        if ($base === '') {
            // `:batch` on its own names no model at all — it is not a mode
            // suffix on an id, so it is left alone rather than "cleaned" to
            // an empty string.
            return null;
        }
        return ['base' => $base, 'suffix' => $suffix];
    }
}
