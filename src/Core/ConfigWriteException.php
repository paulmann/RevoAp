<?php
/**
 * ConfigWriteException: a settings write that did not land.
 *
 * Thrown by the `*OrFail()` variants of {@see RuntimeConfig}, which are
 * the ones the model-actualization run uses: there, a silent write
 * failure would leave the catalogue half-rewritten while the run reports
 * success. The pipeline's own save endpoints do not throw — they return
 * the same detail as a JSON error — but both paths carry the same
 * payload, so an operator (and a test) reads one shape either way.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-09-21
 */
declare(strict_types=1);

namespace App\Core;

final class ConfigWriteException extends \RuntimeException
{
    /** @var array<string,mixed> */
    private readonly array $details;

    /**
     * @param array<string,mixed> $details Result of
     *        {@see RuntimeConfig::writeChecked()} — stage, target, bytes,
     *        filesystem facts. Never empty.
     */
    public function __construct(array $details, ?\Throwable $previous = null)
    {
        $this->details = $details;
        $message = (string)($details['message'] ?? 'The configuration file could not be written.');
        parent::__construct($message, 0, $previous);
    }

    /** @return array<string,mixed> */
    public function details(): array
    {
        return $this->details;
    }
}
