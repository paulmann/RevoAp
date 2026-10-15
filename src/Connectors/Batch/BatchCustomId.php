<?php
/**
 * BatchCustomId: the stable key that ties a batch line back to its request.
 *
 * The Batch API answers a JSONL document whose lines may arrive in any
 * order — the docs say so explicitly, and it is the one property of the
 * result format a caller cannot ignore. Positional matching is therefore
 * not available, and `custom_id` is the only handle. Two requirements
 * follow, and this class exists to satisfy both:
 *
 *   1. **Stability across processes.** The id is written when the batch is
 *      submitted (one worker process, one run) and read back when the
 *      results are accepted (possibly a *different* worker process, after
 *      a restart, hours later). It can never be a counter or an object
 *      hash — it has to be a pure function of the request's coordinates.
 *      Every component below is derived from `(scope, round, participant
 *      index, provider, model)`, all of which are persisted with the job.
 *
 *   2. **Uniqueness within the document.** Two participants may legitimately
 *      share a provider and a model (the same model in two roles), so the
 *      participant index is part of the id and the model is not relied on
 *      for identity. The provider+model hash is carried as well, but as a
 *      *diagnostic* fingerprint: it lets a log line or a report say which
 *      model a lost id belonged to without a database lookup.
 *
 * Shape (OpenAI's contract for `custom_id` is
 * `^[a-zA-Z0-9_-]{1,64}$`):
 *
 *     j42-r1-p0-1f3a9c20
 *     ^   ^  ^  ^
 *     |   |  |  +-- 8 hex chars of sha256("provider|model")
 *     |   |  +----- participant index within the round
 *     |   +-------- round (1-based)
 *     +------------ scope: `j<job id>`, or `s<session id>` when no job exists
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-09-21
 */
declare(strict_types=1);

namespace App\Connectors\Batch;

final class BatchCustomId
{
    /** OpenAI's documented grammar for `custom_id`. */
    public const PATTERN = '/^[A-Za-z0-9_-]{1,64}$/';

    /** Characters kept from a scope label; the rest is dropped. */
    public const MAX_SCOPE_CHARS = 24;

    /** Hex characters of the provider|model digest kept in the id. */
    public const FINGERPRINT_CHARS = 8;

    /**
     * Build the id for one (round, participant) slot.
     *
     * @param string $scope      Job-scoped label, e.g. `j42`. Sanitized;
     *                           an empty result falls back to `x`.
     * @param int    $round      1-based consensus round.
     * @param int    $index      Participant index within the round.
     * @param string $provider   Provider slug.
     * @param string $model      Model id as configured (the `:batch`
     *                           suffix included when present — the id has
     *                           to identify the request, and the request
     *                           names the id it was built from).
     */
    public static function make(
        string $scope,
        int    $round,
        int    $index,
        string $provider,
        string $model,
    ): string {
        $clean = self::sanitizeScope($scope);
        return sprintf(
            '%s-r%d-p%d-%s',
            $clean,
            max(1, $round),
            max(0, $index),
            self::fingerprint($provider, $model),
        );
    }

    /**
     * The recommended scope label for a job — `j<id>` — or for a bare
     * session when the caller has no job row to name.
     */
    public static function scope(int $jobId, int $sessionId = 0): string
    {
        if ($jobId > 0) {
            return 'j' . $jobId;
        }
        return $sessionId > 0 ? 's' . $sessionId : 'x';
    }

    /**
     * Does this id satisfy the provider's documented grammar? Used as a
     * local pre-flight check: a `custom_id` outside the grammar fails the
     * whole batch at `validating` with no per-line explanation, so it is
     * worth refusing before the upload rather than after.
     */
    public static function isValid(string $customId): bool
    {
        return preg_match(self::PATTERN, $customId) === 1;
    }

    /**
     * Decompose an id we minted ourselves. Returns null for any id that
     * does not carry our shape — including one minted by an older version
     * — so callers treat it as "unknown provenance" instead of guessing.
     *
     * @return array{scope:string,round:int,index:int,fingerprint:string}|null
     */
    public static function describe(string $customId): ?array
    {
        if (preg_match('/^(?<scope>[A-Za-z0-9_]+)-r(?<round>\d+)-p(?<index>\d+)-(?<fp>[0-9a-f]{1,16})$/', $customId, $m) !== 1) {
            return null;
        }
        return [
            'scope'       => (string)$m['scope'],
            'round'       => (int)$m['round'],
            'index'       => (int)$m['index'],
            'fingerprint' => (string)$m['fp'],
        ];
    }

    /**
     * The provider|model fingerprint embedded in an id.
     *
     * Short on purpose: it is a diagnostic breadcrumb, not a hash of
     * record, and the id has a 64-character budget shared with everything
     * else. Collisions across two models of the same pool are irrelevant
     * because identity comes from `index`, never from the digest.
     */
    public static function fingerprint(string $provider, string $model): string
    {
        $digest = hash('sha256', strtolower(trim($provider)) . '|' . trim($model));
        return substr($digest, 0, self::FINGERPRINT_CHARS);
    }

    /**
     * Reduce an arbitrary label to the `custom_id` alphabet.
     *
     * Anything outside `[A-Za-z0-9_]` becomes `_`, runs collapse, and the
     * result is trimmed of separators so an id never starts or ends with
     * one. An input that reduces to nothing yields `x` rather than the
     * empty string, because an empty scope would produce an id like
     * `-r1-p0-…`, which is still valid to the provider but unreadable to
     * a human looking at the JSONL.
     */
    public static function sanitizeScope(string $scope): string
    {
        $clean = preg_replace('/[^A-Za-z0-9_]+/', '_', trim($scope)) ?? '';
        $clean = trim((string)$clean, '_');
        if ($clean === '') {
            return 'x';
        }
        if (strlen($clean) > self::MAX_SCOPE_CHARS) {
            $clean = substr($clean, 0, self::MAX_SCOPE_CHARS);
            $clean = rtrim($clean, '_');
        }
        return $clean === '' ? 'x' : $clean;
    }
}
