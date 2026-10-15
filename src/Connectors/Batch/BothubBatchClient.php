<?php
/**
 * BothubBatchClient: the asynchronous Batch API surface of BotHub.
 *
 * Base URL: `https://bothub.chat/api/v2/openai/v1` (the connector's own
 * `defaultBaseUrl`, resolved through {@see AbstractConnector::endpointBase()}
 * so an operator override moves both surfaces at once). The documentation
 * also names the alias `https://openai.bothub.chat/v1`; it is the same API.
 *
 * Endpoint map, exactly as documented — nothing here is guessed:
 *
 *   POST /files                 multipart upload, `purpose=batch`, `.jsonl`
 *                               → {id}
 *   POST /batches               {input_file_id, endpoint, completion_window}
 *                               → {id, status}
 *   GET  /batches/{id}?include_usage=true
 *                               → {status, output_file_id?, …}
 *   POST /batches/{id}/cancel   → {status:"cancelling"}
 *   GET  /batches?limit=&offset=  limit 1..100
 *   GET  /files/{id}            raw JSONL download
 *   GET  /batches/models        the ids that support background processing
 *
 * ## Two documented ambiguities, handled explicitly
 *
 * 1. **The upload field name.** The cURL example calls the file part
 *    `files`; every SDK example calls it `file`. Both are attempted, in
 *    that order, and whichever one the provider accepted is logged. The
 *    retry is safe because a request rejected for a missing file part
 *    created nothing — `POST /files` is the only endpoint where a retry
 *    cannot duplicate state, and it is the only one that has one.
 *
 * 2. **The download route.** The examples fetch `GET /files/{id}` and
 *    receive the JSONL body. A host that instead serves metadata there
 *    answers 4xx (or a JSON object), and the client falls back to
 *    `GET /files/{id}/content`. Again, which one worked is logged.
 *
 * ## No retries anywhere else, deliberately
 *
 * `POST /batches` is not retried on a 5xx: a batch created but not
 * acknowledged is indistinguishable from one that was, and resubmitting
 * would run — and bill — every request twice. The same reasoning rules out
 * retrying `POST /files`. Missing an answer from `GET /batches/{id}` costs
 * nothing, because the next poll asks again; there is no reason to
 * complicate the transport for it.
 *
 * ## Testability
 *
 * The transport is injected ({@see BatchTransportInterface}, or a plain
 * callable for a one-off stub). Every method here is therefore exercisable
 * with zero network access, which is how the suite covers all seven
 * endpoints including their error paths — see `tests/BatchClientTest.php`.
 * The default transport is {@see CurlBatchTransport}, which raises a
 * self-explaining {@see ConnectorException} when ext-curl is missing
 * instead of dying inside the engine.
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

use App\Connectors\AbstractConnector;
use App\Connectors\BothubConnector;
use App\Connectors\Exceptions\ConnectorException;
use App\Core\Logger;

final class BothubBatchClient
{
    /** Documented route for the `.jsonl` upload. */
    public const PATH_FILES = '/files';

    /** Documented route for batch creation / status / cancel / list. */
    public const PATH_BATCHES = '/batches';

    /** Documented route listing the ids that support background processing. */
    public const PATH_BATCH_MODELS = '/batches/models';

    /** Documented `endpoint` value for a chat-completions batch. */
    public const ENDPOINT_CHAT_COMPLETIONS = '/v1/chat/completions';

    /** Documented — and currently the only accepted — completion window. */
    public const COMPLETION_WINDOW_24H = '24h';

    /** Documented `purpose` for a batch input file. */
    public const PURPOSE_BATCH = 'batch';

    /**
     * Upload field names, in the order the ambiguity is resolved. The
     * cURL example uses `files`, the SDK examples use `file`.
     *
     * @var list<string>
     */
    public const UPLOAD_FIELD_CANDIDATES = ['files', 'file'];

    /** Page size ceiling for `GET /batches` (documented: 1..100). */
    public const LIST_LIMIT_MAX = 100;

    public const DEFAULT_LIST_LIMIT = 20;

    /** Transport timeout for control calls (create / status / cancel / list). */
    public const DEFAULT_TIMEOUT_SECONDS = 60;

    /**
     * Transport timeout for the two calls that move a whole document: the
     * JSONL upload and the result download. A 1000-line file is a few
     * hundred kilobytes, but a slow link plus a proxy should not turn a
     * legitimate upload into a spurious failure.
     */
    public const TRANSFER_TIMEOUT_SECONDS = 300;

    /**
     * Largest indirect (pointer-followed) document accepted, in bytes. A
     * guard against a hostile or misconfigured URL, not a real limit: batch
     * outputs are JSONL of a few hundred kilobytes.
     */
    private const MAX_INDIRECT_BYTES = 33554432;

    private readonly BatchTransportInterface $transport;

    /**
     * Was the transport left at its default (the real ext-curl one)?
     *
     * Exposed so a capability report can decide whether probing ext-curl is
     * meaningful: an injected fixture transport is usable by construction,
     * and telling an operator "ext-curl is missing" when the code is not
     * even going to use it would be a false diagnosis.
     */
    private readonly bool $defaultTransport;

    /**
     * @param AbstractConnector|null $connector Key/base-URL resolver. Must be
     *        the provider whose Batch API is being addressed (`bothub`); the
     *        parameter is typed as the abstract connector so the suite can
     *        inject a stub without a config file or a key on disk. Defaults
     *        to a fresh {@see BothubConnector}.
     * @param BatchTransportInterface|callable|null $transport Offline seam.
     *        A callable must match {@see BatchTransportInterface::send()}.
     */
    /**
     * The key/base-URL resolver. NOT `readonly`: the default connector is
     * created lazily by {@see self::connector()}, and a readonly promoted
     * property cannot be assigned a second time — `??=` on it throws, which
     * would surface as "this deployment has no API key" from a deployment
     * that has one.
     */
    public function __construct(
        private ?AbstractConnector $connector = null,
        BatchTransportInterface|callable|null $transport = null,
        private readonly int $transferTimeoutSeconds = self::TRANSFER_TIMEOUT_SECONDS,
    ) {
        $this->defaultTransport = $transport === null;
        $this->transport = match (true) {
            $transport instanceof BatchTransportInterface => $transport,
            $transport === null                            => new CurlBatchTransport(),
            default                                        => new class($transport) implements BatchTransportInterface {
                public function __construct(private $callable) {}

                public function send(
                    string $method,
                    string $url,
                    array $headers,
                    ?array $jsonBody = null,
                    ?array $multipart = null,
                    int $timeout = 60,
                ): BatchHttpResponse {
                    $result = ($this->callable)($method, $url, $headers, $jsonBody, $multipart, $timeout);
                    if (!$result instanceof BatchHttpResponse) {
                        throw new ConnectorException('Batch transport callable must return a BatchHttpResponse');
                    }
                    return $result;
                }
            },
        };
    }

    /** The connector whose key/base URL this client uses. */
    public function connector(): AbstractConnector
    {
        return $this->connector ??= new BothubConnector();
    }

    /**
     * Is this client using the real ext-curl transport (as opposed to an
     * injected one)? See {@see self::$defaultTransport}.
     */
    public function transportIsDefault(): bool
    {
        return $this->defaultTransport;
    }

    /** Is the Batch API reachable in principle — transport AND key present? */
    public function isAvailable(): bool
    {
        try {
            return $this->connector()->credential() !== '';
        } catch (\Throwable) {
            return false;
        }
    }

    // -----------------------------------------------------------------
    // Step 1 — upload the input file
    // -----------------------------------------------------------------

    /**
     * Upload a JSONL document as a batch input file.
     *
     * @param BatchJsonlDocument|string $document Rendered document, or raw JSONL.
     * @return array{id:string,filename:string,bytes:int,purpose:string,field:string,raw:array<string,mixed>}
     * @throws ConnectorException when no candidate field name is accepted
     */
    public function uploadInputFile(BatchJsonlDocument|string $document, string $filename = BatchJsonlBuilder::FILENAME): array
    {
        $content = $document instanceof BatchJsonlDocument ? $document->content : $document;
        if (trim($content) === '') {
            throw new ConnectorException('Batch upload refused: the JSONL document is empty');
        }
        $fieldErrors = [];
        foreach (self::UPLOAD_FIELD_CANDIDATES as $field) {
            $response = $this->request(
                'POST',
                self::PATH_FILES,
                $this->uploadHeaders(),
                // The extra non-file part. curl folds these into the same
                // multipart body as the file (see CurlBatchTransport).
                ['purpose' => self::PURPOSE_BATCH],
                [
                    'field'        => $field,
                    'filename'     => $filename,
                    'content'      => $content,
                    'content_type' => BatchJsonlBuilder::CONTENT_TYPE,
                ],
                $this->transferTimeoutSeconds,
            );
            $body = $response->json();
            $id   = is_array($body) ? ($body['id'] ?? null) : null;
            if ($response->isSuccess() && is_string($id) && $id !== '') {
                // Which spelling the provider accepted is worth recording:
                // the documentation disagrees with itself, and a future
                // reader of the logs should not have to re-derive it.
                Logger::info('batch.upload.accepted_field', [
                    'field' => $field,
                    'bytes' => strlen($content),
                ]);
                return [
                    'id'       => $id,
                    'filename' => $filename,
                    'bytes'    => strlen($content),
                    'purpose'  => self::PURPOSE_BATCH,
                    'field'    => $field,
                    'raw'      => is_array($body) ? $body : [],
                ];
            }
            $fieldErrors[$field] = $response->errorMessage();
        }

        $detail = [];
        foreach ($fieldErrors as $field => $message) {
            $detail[] = sprintf('"%s" → %s', $field, $message);
        }
        throw new ConnectorException(
            'Batch upload failed for every documented multipart field name: ' . implode('; ', $detail)
        );
    }

    // -----------------------------------------------------------------
    // Step 2 — create the batch
    // -----------------------------------------------------------------

    /**
     * Create a batch from an uploaded input file.
     *
     * @return array{id:string,status:string,input_file_id:string,endpoint:string,completion_window:string,raw:array<string,mixed>}
     * @throws ConnectorException on any non-2xx or a bodiless response
     */
    public function createBatch(
        string $inputFileId,
        string $endpoint = self::ENDPOINT_CHAT_COMPLETIONS,
        string $completionWindow = self::COMPLETION_WINDOW_24H,
    ): array {
        $inputFileId = trim($inputFileId);
        if ($inputFileId === '') {
            throw new ConnectorException('createBatch requires the input file id returned by uploadInputFile()');
        }
        $response = $this->request(
            'POST',
            self::PATH_BATCHES,
            $this->jsonHeaders(),
            [
                'input_file_id'     => $inputFileId,
                'endpoint'          => $endpoint,
                'completion_window' => $completionWindow,
            ],
            null,
            self::DEFAULT_TIMEOUT_SECONDS,
        );
        $body = $response->json();
        if (!$response->isSuccess() || !is_array($body)) {
            throw new ConnectorException('Batch creation failed: ' . $response->errorMessage());
        }
        $id     = $body['id'] ?? null;
        $status = $body['status'] ?? null;
        if (!is_string($id) || $id === '') {
            throw new ConnectorException(
                'Batch creation returned no batch id: ' . BatchHttpResponse::excerpt($response->body)
            );
        }
        return [
            'id'                => $id,
            'status'            => is_string($status) && $status !== '' ? $status : BatchLifecycle::VALIDATING,
            'input_file_id'     => $inputFileId,
            'endpoint'          => $endpoint,
            'completion_window' => $completionWindow,
            'raw'               => $body,
        ];
    }

    // -----------------------------------------------------------------
    // Monitoring
    // -----------------------------------------------------------------

    /**
     * Read the current state of a batch.
     *
     * `include_usage=true` is documented as the flag that populates the
     * detailed usage/cost block, so it is always sent — it costs nothing on
     * a batch that has not finished, and asking for it only on the final
     * poll would mean the one poll that matters is the one that might omit
     * it.
     *
     * A non-2xx answer is REPORTED, not thrown: a poll that fails because
     * the network blipped must leave the batch where it is and let the
     * scheduler try again, not fail a run that is progressing normally on
     * the provider's side. `ok=false` carries the cause.
     *
     * @return array{ok:bool,error?:string,status:string,output_file_id:?string,error_file_id:?string,request_counts:array<string,int>,raw:array<string,mixed>}
     */
    public function getBatch(string $batchId, bool $includeUsage = true): array
    {
        $batchId = trim($batchId);
        if ($batchId === '') {
            return self::failedBatchSnapshot('getBatch requires a batch id');
        }
        $path = self::PATH_BATCHES . '/' . rawurlencode($batchId);
        if ($includeUsage) {
            $path .= '?include_usage=true';
        }
        $response = $this->request('GET', $path, $this->jsonHeaders(), null, null, self::DEFAULT_TIMEOUT_SECONDS);
        $body = $response->json();
        if (!$response->isSuccess() || !is_array($body)) {
            return self::failedBatchSnapshot($response->errorMessage());
        }
        $status = $body['status'] ?? null;
        $counts = is_array($body['request_counts'] ?? null) ? $body['request_counts'] : [];
        return [
            'ok'               => true,
            'status'           => is_string($status) && $status !== '' ? $status : BatchLifecycle::VALIDATING,
            'output_file_id'   => self::stringOrNull($body['output_file_id'] ?? null),
            'error_file_id'    => self::stringOrNull($body['error_file_id'] ?? null),
            'request_counts'   => [
                'total'     => (int)($counts['total']     ?? 0),
                'completed' => (int)($counts['completed'] ?? 0),
                'failed'    => (int)($counts['failed']    ?? 0),
            ],
            'raw'              => $body,
        ];
    }

    /**
     * The stable "we could not read the batch" snapshot. Reported instead
     * of thrown so a transient polling failure is a scheduling event, not a
     * run-ending one.
     *
     * @return array{ok:bool,error:string,status:string,output_file_id:?string,error_file_id:?string,request_counts:array<string,int>,raw:array<string,mixed>}
     */
    public static function failedBatchSnapshot(string $error): array
    {
        return [
            'ok'             => false,
            'error'          => Logger::sanitizeScalar($error),
            'status'         => BatchLifecycle::UNKNOWN,
            'output_file_id' => null,
            'error_file_id'  => null,
            'request_counts' => ['total' => 0, 'completed' => 0, 'failed' => 0],
            'raw'            => [],
        ];
    }

    // -----------------------------------------------------------------
    // Cancel / list
    // -----------------------------------------------------------------

    /**
     * Ask the provider to cancel a batch.
     *
     * @return array{ok:bool,error?:string,status:string,raw:array<string,mixed>}
     */
    public function cancelBatch(string $batchId): array
    {
        $batchId = trim($batchId);
        if ($batchId === '') {
            return ['ok' => false, 'error' => 'cancelBatch requires a batch id', 'status' => BatchLifecycle::UNKNOWN, 'raw' => []];
        }
        $response = $this->request(
            'POST',
            self::PATH_BATCHES . '/' . rawurlencode($batchId) . '/cancel',
            $this->jsonHeaders(),
            null,
            null,
            self::DEFAULT_TIMEOUT_SECONDS,
        );
        $body = $response->json();
        if (!$response->isSuccess()) {
            return ['ok' => false, 'error' => $response->errorMessage(), 'status' => BatchLifecycle::UNKNOWN, 'raw' => is_array($body) ? $body : []];
        }
        $status = is_array($body) ? ($body['status'] ?? null) : null;
        return [
            'ok'     => true,
            'status' => is_string($status) && $status !== '' ? $status : BatchLifecycle::CANCELLING,
            'raw'    => is_array($body) ? $body : [],
        ];
    }

    /**
     * List recent batches. The documented page size range is 1..100.
     *
     * @return array{ok:bool,error?:string,items:list<array<string,mixed>>,raw:array<string,mixed>}
     */
    public function listBatches(int $limit = self::DEFAULT_LIST_LIMIT, int $offset = 0): array
    {
        $limit  = max(1, min(self::LIST_LIMIT_MAX, $limit));
        $offset = max(0, $offset);
        $response = $this->request(
            'GET',
            sprintf('%s?limit=%d&offset=%d', self::PATH_BATCHES, $limit, $offset),
            $this->jsonHeaders(),
            null,
            null,
            self::DEFAULT_TIMEOUT_SECONDS,
        );
        $body = $response->json();
        if (!$response->isSuccess() || !is_array($body)) {
            return ['ok' => false, 'error' => $response->errorMessage(), 'items' => [], 'raw' => is_array($body) ? $body : []];
        }
        $items = $body['data'] ?? $body['batches'] ?? [];
        return [
            'ok'    => true,
            'items' => is_array($items) ? array_values(array_filter($items, 'is_array')) : [],
            'raw'   => $body,
        ];
    }

    // -----------------------------------------------------------------
    // Result download
    // -----------------------------------------------------------------

    /**
     * Download a completed batch's output document as raw bytes.
     *
     * Two documented-adjacent routes are tried: the examples use
     * `GET /files/{id}` and read the body; a host that serves metadata
     * there answers with a JSON object or a 4xx, and the client then tries
     * `GET /files/{id}/content`. Which one produced the document is logged.
     *
     * The body is returned verbatim — never decoded here. Deciding what a
     * line means belongs to {@see BatchResultParser}, and a JSONL document
     * is not a JSON document in the first place.
     *
     * One exception to "verbatim": when the host answers with a pointer (a
     * short JSON string holding a signed object-storage URL - bothub's
     * dialect), the document behind it is fetched and returned instead.
     * That fetch intentionally carries none of the connector credentials.
     *
     * @return array{ok:bool,error?:string,content:string,path:string,bytes:int}
     */
    public function downloadResult(string $fileId): array
    {
        $fileId = trim($fileId);
        if ($fileId === '') {
            return ['ok' => false, 'error' => 'downloadResult requires an output file id', 'content' => '', 'path' => '', 'bytes' => 0];
        }
        $paths = [
            self::PATH_FILES . '/' . rawurlencode($fileId),
            self::PATH_FILES . '/' . rawurlencode($fileId) . '/content',
        ];
        $attempts = [];
        foreach ($paths as $index => $path) {
            $response = $this->request('GET', $path, $this->acceptAnyHeaders(), null, null, $this->transferTimeoutSeconds);
            if ($response->isSuccess() && trim($response->body) !== '') {
                // The first route can also succeed with a metadata object
                // on hosts that serve one — that is not the document, so a
                // JSON *object* whose only plausible content is a file
                // descriptor is treated as "wrong route" and the fallback
                // is used instead.
                if (!$this->looksLikeFileMetadata($response)) {
                    // Hosts that keep batch outputs on object storage answer
                    // with a pointer - a short JSON string holding a signed
                    // URL - instead of the document. Follow it once, without
                    // the connector's credentials: the storage host is not
                    // the provider and the API key must not travel there.
                    $indirect = self::indirectDocumentUrl($response->body);
                    if ($indirect !== null) {
                        $followed = $this->fetchIndirectDocument($indirect);
                        if (($followed['ok'] ?? false) === true) {
                            if ($index > 0) {
                                Logger::info('batch.download.fallback_path', ['path' => $path]);
                            }
                            Logger::info('batch.download.indirect', [
                                'host'  => (string)parse_url($indirect, PHP_URL_HOST),
                                'bytes' => (int)($followed['bytes'] ?? 0),
                            ]);
                            return $followed;
                        }
                        $attempts[] = sprintf(
                            '%s  pointer to %s could not be fetched: %s',
                            $path,
                            $indirect,
                            (string)($followed['error'] ?? 'unknown error'),
                        );
                        continue;
                    }
                    if ($index > 0) {
                        Logger::info('batch.download.fallback_path', ['path' => $path]);
                    }
                    return [
                        'ok'      => true,
                        'content' => $response->body,
                        'path'    => $path,
                        'bytes'   => strlen($response->body),
                    ];
                }
                $attempts[] = sprintf('%s → metadata instead of the document', $path);
                continue;
            }
            $attempts[] = sprintf('%s → %s', $path, $response->errorMessage());
        }
        return [
            'ok'      => false,
            'error'   => 'Batch result download failed: ' . implode('; ', $attempts),
            'content' => '',
            'path'    => '',
            'bytes'   => 0,
        ];
    }

    /**
     * Is this 2xx body a file descriptor rather than a document?
     *
     * A JSONL output document starts with `{` on every line, so "the body
     * parses as one complete JSON object" is a reliable tell. The check is
     * narrow on purpose: `object === "file"` plus an `id`, which is the
     * shape the OpenAI-compatible file-metadata endpoint returns.
     */
    private function looksLikeFileMetadata(BatchHttpResponse $response): bool
    {
        $body = $response->json();
        if (!is_array($body)) {
            return false;
        }
        $object = $body['object'] ?? null;
        $hasDocumentKey = isset($body['bytes']) || isset($body['filename']) || isset($body['purpose']);
        return $object === 'file' && $hasDocumentKey && !isset($body['choices']);
    }

    /**
     * A 2xx body that is a pointer, not a document.
     *
     * Detected narrowly: a short (<= 2048 bytes) body that is either a
     * JSON-encoded http(s) URL or a bare one. Anything else - a JSONL line,
     * a metadata object, prose - is the caller's business, exactly as
     * before.
     */
    public static function indirectDocumentUrl(string $body): ?string
    {
        $trimmed = trim($body);
        if ($trimmed === '' || strlen($trimmed) > 2048) {
            return null;
        }
        $decoded = json_decode($trimmed, true);
        $url = is_string($decoded) ? trim($decoded) : $trimmed;
        if (preg_match('#^https?://[^\s]+$#i', $url) !== 1) {
            return null;
        }
        return $url;
    }

    /**
     * Fetch the document a pointer names - WITHOUT the connector's
     * credentials. The storage host is a third-party origin; the API key
     * stays at the provider.
     *
     * @return array{ok:bool,error?:string,content:string,path:string,bytes:int,indirect?:bool}
     */
    private function fetchIndirectDocument(string $url): array
    {
        try {
            $response = $this->transport->send(
                'GET',
                $url,
                ['Accept: */*', 'User-Agent: RevoAp/1.0 (batch result fetch)'],
                null,
                null,
                $this->transferTimeoutSeconds(),
            );
        } catch (ConnectorException $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'content' => '', 'path' => '', 'bytes' => 0];
        }
        if (!$response->isSuccess() || trim($response->body) === '') {
            return ['ok' => false, 'error' => $response->errorMessage(), 'content' => '', 'path' => '', 'bytes' => 0];
        }
        if (strlen($response->body) > self::MAX_INDIRECT_BYTES) {
            return [
                'ok'      => false,
                'error'   => 'indirect document is larger than ' . self::MAX_INDIRECT_BYTES . ' bytes',
                'content' => '',
                'path'    => '',
                'bytes'   => 0,
            ];
        }
        return [
            'ok'       => true,
            'content'  => $response->body,
            'path'     => $url,
            'bytes'    => strlen($response->body),
            'indirect' => true,
        ];
    }

    // -----------------------------------------------------------------
    // Batch-capable models
    // -----------------------------------------------------------------

    /**
     * `GET /batches/models` — the ids that support background processing.
     *
     * Returned as a {@see BatchModelCatalog}, which keeps "the answer was
     * unknown" distinct from "the model is not in the list". See that class
     * for why the distinction is load-bearing.
     */
    public function listBatchModels(): BatchModelCatalog
    {
        $response = $this->request(
            'GET',
            self::PATH_BATCH_MODELS,
            $this->jsonHeaders(),
            null,
            null,
            self::DEFAULT_TIMEOUT_SECONDS,
        );
        if (!$response->isSuccess()) {
            return BatchModelCatalog::unknown($response->errorMessage());
        }
        $body = $response->json();
        if (!is_array($body)) {
            return BatchModelCatalog::unknown(
                'GET ' . self::PATH_BATCH_MODELS . ' returned a body that is not a JSON object'
            );
        }
        return BatchModelCatalog::fromResponse($body);
    }

    // -----------------------------------------------------------------
    // Plumbing
    // -----------------------------------------------------------------

    /**
     * One request against the connector's base URL.
     *
     * The base URL and the key both come from the connector, so a
     * deployment override or a rotated credential applies to every Batch
     * route without a second configuration surface.
     *
     * @param array<string,mixed>|null $jsonBody
     * @param array{field:string,filename:string,content:string,content_type:string}|null $multipart
     */
    private function request(
        string $method,
        string $path,
        array  $headers,
        ?array $jsonBody = null,
        ?array $multipart = null,
        int    $timeout = self::DEFAULT_TIMEOUT_SECONDS,
    ): BatchHttpResponse {
        $url = $this->connector()->endpointBase() . $path;
        try {
            return $this->transport->send($method, $url, $headers, $jsonBody, $multipart, $timeout);
        } catch (ConnectorException $e) {
            // A transport-level failure (no curl, curl init refused) is
            // re-shaped as an HTTP-shaped answer so the callers above have
            // exactly one failure representation to handle. The message is
            // already sanitized by the transport.
            return new BatchHttpResponse(0, $e->getMessage(), $url, $method);
        }
    }

    /**
     * Auth headers + JSON content type. Provider auth comes from the
     * connector; only the body type is added here.
     *
     * @return string[]
     */
    private function jsonHeaders(): array
    {
        return array_merge($this->connector()->authHeaderLines(), [
            'Content-Type: application/json',
            'Accept: application/json',
        ]);
    }

    /**
     * Auth headers for the multipart upload. No `Content-Type`: curl writes
     * the multipart boundary itself, and a hand-set type would make the
     * provider reject the body it cannot delimit.
     *
     * @return string[]
     */
    private function uploadHeaders(): array
    {
        return array_merge($this->connector()->authHeaderLines(), ['Accept: application/json']);
    }

    /**
     * Auth headers for the raw document download: no JSON type, because the
     * answer is JSONL.
     *
     * @return string[]
     */
    private function acceptAnyHeaders(): array
    {
        return array_merge($this->connector()->authHeaderLines(), ['Accept: */*']);
    }

    private function transferTimeoutSeconds(): int
    {
        return max(self::DEFAULT_TIMEOUT_SECONDS, $this->transferTimeoutSeconds);
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
