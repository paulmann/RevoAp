<?php
/**
 * BatchResultEntry: one accepted line of the output JSONL, normalized.
 *
 * The provider's per-line envelope is:
 *
 *     {"id":"batch_req_…","custom_id":"…",
 *      "response":{"status_code":200,"request_id":"…","body":{…}},
 *      "error":null}
 *
 * Two different things can go wrong with one line, and they arrive in two
 * different places:
 *
 *   * the layer-7 answer itself failed — `response.status_code` is 429/500
 *     and `response.body` carries the provider's error object. This is
 *     what the documentation warns about: a batch can be `completed` while
 *     individual rows were refused for rate limits or moderation.
 *   * the request never reached the model — the top-level `error` field
 *     is set instead, and `response` may be absent entirely.
 *
 * Both are captured here so the caller never has to re-inspect raw JSON,
 * and so the per-participant diagnostic can quote the provider verbatim.
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

final readonly class BatchResultEntry
{
    /**
     * @param array<string,mixed>|null $body Chat-completions payload, when
     *        the provider answered with one. This is what feeds
     *        {@see \App\Connectors\AbstractConnector::buildChatResponseFromRaw()}.
     */
    public function __construct(
        public string  $customId,
        public int     $statusCode,
        public ?array  $body,
        public ?string $errorMessage,
        public string  $batchRequestId = '',
    ) {}

    /**
     * A usable answer: HTTP 2xx AND no top-level error AND an actual body.
     *
     * The body check is not redundant. A `200` with no body would decode
     * into an empty ChatResponse and be written to the journal as a
     * contentless L1 success, which reads in the UI as a model that
     * answered nothing rather than as the transport fault it is.
     */
    public function isSuccess(): bool
    {
        return $this->statusCode >= 200
            && $this->statusCode < 300
            && $this->errorMessage === null
            && $this->body !== null
            && $this->body !== [];
    }

    /**
     * The one-line cause shown on the participant's failed step, or null
     * when the entry succeeded.
     */
    public function failureReason(): ?string
    {
        if ($this->isSuccess()) {
            return null;
        }
        if ($this->errorMessage !== null && $this->errorMessage !== '') {
            return sprintf('batch row failed (HTTP %d): %s', $this->statusCode, $this->errorMessage);
        }
        if ($this->statusCode < 200 || $this->statusCode >= 300) {
            return sprintf('batch row failed with HTTP %d and no provider message', $this->statusCode);
        }
        return 'batch row returned no completion body';
    }
}
