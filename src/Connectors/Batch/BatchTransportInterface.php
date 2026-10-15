<?php
/**
 * BatchTransportInterface: the seam between the Batch client and HTTP.
 *
 * The Batch API cannot be exercised from the test suite (no live key, no
 * network, and in this deployment no ext-curl either), so the client's
 * ability to be verified offline rests entirely on this interface: every
 * request the client makes goes through an injected transport, and the
 * tests inject a fixture transport that answers from canned bodies.
 *
 * A transport is deliberately dumb — it sends bytes and returns bytes
 * with a status code. It does not retry, does not parse, does not decide
 * whether an answer is good. Two consequences worth stating:
 *
 *   1. Retrying is the CLIENT's business and it is deliberately rare
 *      (see {@see BothubBatchClient}). A transport-level retry would be
 *      actively dangerous here: repeating `POST /batches` after a
 *      truncated response would submit the same batch twice.
 *   2. Errors are values, not exceptions. A 404 from
 *      `GET /files/{id}` means "this route does not exist on this host,
 *      try `/files/{id}/content`" — a normal control-flow branch for the
 *      client, not a fault for the transport to raise.
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

interface BatchTransportInterface
{
    /**
     * Perform one HTTP request.
     *
     * Exactly one of `$jsonBody` / `$multipart` may be supplied. When both
     * are null the request carries no body (GET, and the POSTs that take
     * no parameters, such as `POST /batches/{id}/cancel`).
     *
     * @param string                        $method    Upper-case HTTP verb.
     * @param string                        $url       Absolute URL.
     * @param string[]                      $headers   Header lines ('Name: Value').
     * @param array<string,mixed>|null      $jsonBody  Encoded as application/json.
     * @param array{field:string,filename:string,content:string,content_type:string}|null $multipart
     *                                                 One-file multipart/form-data body.
     * @param int                           $timeout   Seconds.
     *
     * @throws \App\Connectors\Exceptions\ConnectorException only for a
     *         transport-level failure that makes the request impossible
     *         (ext-curl absent, curl init refused). An HTTP error status
     *         is returned, never thrown.
     */
    public function send(
        string $method,
        string $url,
        array $headers,
        ?array $jsonBody = null,
        ?array $multipart = null,
        int $timeout = 60,
    ): BatchHttpResponse;
}
