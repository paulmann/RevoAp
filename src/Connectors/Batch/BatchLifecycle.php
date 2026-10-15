<?php
/**
 * BatchLifecycle: the documented status vocabulary of a batch, in one place.
 *
 * The documentation names five states, and every one of them has to be
 * interpreted identically by code that lives far apart: the client that
 * reads a status, the runner that decides whether to keep polling, the
 * runner's cancel path, and the UI that renders the phase. Scattering the
 * literal strings is how a typo becomes "the job polls forever".
 *
 *   validating   the file structure is being checked. Not a failure, not
 *                yet running — a batch that dies here (malformed JSONL,
 *                zero balance) goes straight to `failed`, which is why the
 *                builder validates locally instead of relying on this state.
 *   in_progress  the requests are being processed.
 *   completed    the gateway is done and `output_file_id` is available.
 *                NOT a statement about the individual rows: each one still
 *                has to be checked for its own `status_code`.
 *   failed       the batch never ran (invalid JSON, zero balance).
 *   cancelled    cancelled, either by us or by the provider.
 *
 * Two values are additions, not documentation, and both are marked as such
 * below: `cancelling`, which the cancel endpoint itself returns as an
 * intermediate state, and `unknown`, which the client reports when a poll
 * could not be read. Neither is ever written to a job as a terminal
 * verdict.
 *
 * ## Poll cadence
 *
 * The documentation's own recommendation: every 30 seconds for batches up
 * to 1000 rows, every 2–5 minutes for larger ones. That is implemented
 * here as {@see self::pollDelaySeconds()} so the runner, its tests and any
 * future scheduler all read the same number.
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

final class BatchLifecycle
{
    // --- documented states -------------------------------------------
    public const VALIDATING  = 'validating';
    public const IN_PROGRESS = 'in_progress';
    public const COMPLETED   = 'completed';
    public const FAILED      = 'failed';
    public const CANCELLED   = 'cancelled';

    // --- additions (see class docblock) ------------------------------
    /** Returned by `POST /batches/{id}/cancel`; not a terminal state. */
    public const CANCELLING = 'cancelling';
    /** A poll that could not be read. Never terminal — retried. */
    public const UNKNOWN = 'unknown';

    /** Documented row-count threshold for the fast poll interval. */
    public const SMALL_BATCH_ROWS = 1000;

    /** Documented poll interval for batches up to {@see self::SMALL_BATCH_ROWS}. */
    public const SMALL_BATCH_POLL_SECONDS = 30;

    /**
     * Documented poll interval for larger batches ("2–5 minutes"). The
     * lower end is used: the cost of an extra poll that answers
     * `in_progress` is one cheap GET, while the cost of polling at five
     * minutes is up to five minutes of added latency on every large run.
     */
    public const LARGE_BATCH_POLL_SECONDS = 150;

    /**
     * Is this a state the batch will never leave?
     *
     * `cancelling` is deliberately NOT terminal: the provider acknowledges
     * the request before the batch is actually stopped, and treating the
     * acknowledgement as the end would abandon a batch that is still
     * running — and still billing.
     */
    public static function isTerminal(string $status): bool
    {
        return match (strtolower(trim($status))) {
            self::COMPLETED, self::FAILED, self::CANCELLED => true,
            default => false,
        };
    }

    /** Is the batch still expected to make progress on its own? */
    public static function isActive(string $status): bool
    {
        return match (strtolower(trim($status))) {
            self::VALIDATING, self::IN_PROGRESS, self::CANCELLING => true,
            default => false,
        };
    }

    /** Is the batch done in a way that produced an answer file? */
    public static function hasOutput(string $status): bool
    {
        return strtolower(trim($status)) === self::COMPLETED;
    }

    /**
     * Every value this class recognises, for input validation and for the
     * test that pins the vocabulary.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return [
            self::VALIDATING,
            self::IN_PROGRESS,
            self::COMPLETED,
            self::FAILED,
            self::CANCELLED,
            self::CANCELLING,
            self::UNKNOWN,
        ];
    }

    /**
     * Seconds to wait before the next poll, per the documentation's own
     * sizing rule.
     *
     * @param int $requestCount Rows in the submitted document.
     */
    public static function pollDelaySeconds(int $requestCount): int
    {
        return $requestCount > self::SMALL_BATCH_ROWS
            ? self::LARGE_BATCH_POLL_SECONDS
            : self::SMALL_BATCH_POLL_SECONDS;
    }

    /**
     * Operator-facing wording for a status. Used by the UI payload builder,
     * so Chat and History describe a batch the same way.
     */
    public static function label(string $status): string
    {
        return match (strtolower(trim($status))) {
            self::VALIDATING  => 'Validating the uploaded file',
            self::IN_PROGRESS => 'Running on the provider (up to 24 h)',
            self::CANCELLING  => 'Cancelling',
            self::COMPLETED   => 'Results ready',
            self::FAILED      => 'Rejected by the provider',
            self::CANCELLED   => 'Cancelled',
            default           => 'Waiting for the provider',
        };
    }

    /**
     * The provider's own reason for a `failed` batch, pulled from the
     * response when it carries one. Returns '' when it does not — the
     * caller then falls back to a generic sentence rather than inventing a
     * cause.
     *
     * @param array<string,mixed> $batchSnapshot
     */
    public static function failureReason(array $batchSnapshot): string
    {
        $reason = BatchHttpResponse::extractProviderMessage($batchSnapshot);
        if ($reason !== '') {
            return $reason;
        }
        $errors = $batchSnapshot['errors'] ?? null;
        if (is_array($errors)) {
            $flat = BatchHttpResponse::extractProviderMessage($errors);
            if ($flat !== '') {
                return $flat;
            }
        }
        return '';
    }
}
