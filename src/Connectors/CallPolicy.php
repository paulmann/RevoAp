<?php
declare(strict_types=1);

namespace App\Connectors;

use App\Core\Config;

/**
 * R11 - the policy for a single model call in the L1 fan-out.
 *
 * Deliberately pure and config-driven: it decides how long a model may take,
 * how many times it may be retried, when a failure is worth retrying at all,
 * and when a model has failed enough times in one run to stop calling it.
 *
 * The transport already retries a *request* (429/5xx with backoff, inside
 * AbstractConnector). This class governs the layer above: whether to try the
 * model again as a participant in the council. Those are different questions -
 * a model can answer the retry happily and still be unusable for the run.
 *
 * A note on substitution, which the roadmap asked for and this class does not
 * do: in ReVoAp every L1 call is `role@model`, so swapping in a different model
 * changes who is in the council. That is a change of substance, not a retry,
 * and it would silently alter the composition the operator saw when the run
 * started. Failures are reported with a same-family suggestion instead - see
 * `ModelBreaker::summary()` and the engine's `l1_outcome`.
 */
final class CallPolicy
{
    /** Kinds a retry can plausibly fix. */
    public const TRANSIENT = ['timeout', 'rate_limit', 'server', 'connection'];

    /** Kinds where trying again wastes money. */
    public const PERMANENT = ['not_found', 'rejected', 'budget', 'breaker', 'other'];

    /** Seconds one model call may take; independent of the run's own budget. */
    public static function timeoutSeconds(): int
    {
        return max(5, min(3600, (int)self::value('pipeline.model_timeout_s', 180)));
    }


    /**
     * The transport budget for one call to $provider (R11).
     *
     * The connector already owns a per-provider key
     * (`connectors.<provider>.timeout_chat`), and it stays the most specific
     * setting: an operator who needs one provider to be patient says so there.
     * `pipeline.model_timeout_s` is what applies when that key is absent, so the
     * R11 knob is real rather than decorative - which is exactly what it was
     * before this method existed, while the fan-out kept using its own literal
     * default of 120 seconds.
     */
    public static function timeoutFor(string $provider): int
    {
        $provider = trim($provider);
        if ($provider !== '') {
            try {
                $configured = Config::get("connectors.{$provider}.timeout_chat", null);
                if (is_numeric($configured) && (int)$configured > 0) {
                    return max(5, min(3600, (int)$configured));
                }
            } catch (\Throwable) {
                // Unbootstrapped config: fall through to the policy default.
            }
        }

        return self::timeoutSeconds();
    }

    /** How many extra attempts a transiently failing model gets in one run. */
    public static function retryBudget(): int
    {
        return max(0, min(3, (int)self::value('pipeline.model_retry_budget', 1)));
    }

    /** Consecutive failures before a model is dropped for the rest of the run. */
    public static function breakerThreshold(): int
    {
        return max(1, min(10, (int)self::value('pipeline.breaker_failures', 2)));
    }

    /**
     * Classify a failure from what the transport reported.
     *
     * Matching is on the message the connectors already build - the provider
     * slug, the HTTP status and the provider's own error code are all in there
     * (see ErrorDiagnostics::connectorHttpMessage) - plus the HTTP status when
     * the caller knows it. No guessing: anything unrecognised is `other`, which
     * is treated as permanent so a broken call cannot burn the budget.
     *
     * @return array{kind:string,transient:bool,reason:string}
     */
    public static function classify(string $error, ?int $status = null): array
    {
        $text = strtolower($error);

        if (preg_match('/\b(404|410)\b/', (string)$status) === 1
            || str_contains($text, 'model_not_found')
            || str_contains($text, 'not served')
            || str_contains($text, 'unknown model')) {
            return self::verdict('not_found', 'The provider no longer serves this model.');
        }
        if ($status === 401 || $status === 403
            || str_contains($text, 'unauthorized')
            || str_contains($text, 'forbidden')
            || str_contains($text, 'invalid key')) {
            return self::verdict('rejected', 'The provider rejected the request for this model (key or access).');
        }
        if ($status === 429 || str_contains($text, 'rate limit') || str_contains($text, 'too many requests')) {
            return self::verdict('rate_limit', 'The provider rate-limited the call.');
        }
        if (str_contains($text, 'timed out') || str_contains($text, 'timeout') || str_contains($text, 'operation was aborted')) {
            return self::verdict('timeout', 'The call did not finish in time.');
        }
        if (preg_match('/\b(500|502|503|504)\b/', (string)$status) === 1
            || str_contains($text, 'server error')
            || str_contains($text, 'bad gateway')
            || str_contains($text, 'service unavailable')
            || str_contains($text, 'gateway timeout')) {
            return self::verdict('server', 'The provider reported a server-side failure.');
        }
        if (str_contains($text, 'could not resolve')
            || str_contains($text, 'connection refused')
            || str_contains($text, 'connection reset')
            || str_contains($text, 'failed to connect')
            || str_contains($text, 'ssl')
            || str_contains($text, 'curl error')) {
            return self::verdict('connection', 'The connection to the provider failed.');
        }
        if (str_contains($text, 'batch-only')) {
            return self::verdict('rejected', 'This identifier is batch-only and cannot be called synchronously.');
        }

        return self::verdict('other', 'The failure was not recognised, so it is treated as permanent.');
    }

    /** May this kind be retried at all? */
    public static function isTransient(string $kind): bool
    {
        return in_array($kind, self::TRANSIENT, true);
    }


    /**
     * Read a configured value, or the documented default.
     *
     * `Config::get()` throws when the configuration was never bootstrapped -
     * which happens in unit fixtures that build a dispatcher directly. A policy
     * object must not turn that into a fatal: the defaults here are the same
     * values config.php ships, so an unbootstrapped caller gets the shipped
     * behaviour rather than an exception in the middle of the L1 fan-out.
     */
    private static function value(string $key, int|float $default): int|float
    {
        try {
            $raw = Config::get($key, null);
        } catch (\Throwable) {
            return $default;
        }

        return is_numeric($raw) ? $raw : $default;
    }

    /** @return array{kind:string,transient:bool,reason:string} */
    private static function verdict(string $kind, string $reason): array
    {
        return ['kind' => $kind, 'transient' => self::isTransient($kind), 'reason' => $reason];
    }
}
