<?php
/**
 * QdrantVectorStore: Qdrant-backed VectorStoreInterface implementation.
 *
 * Adapted from the PROTO prototype
 * (Consensus-AI-main/src/Infrastructure/Memory/VectorStore/QdrantVectorStore.php)
 * per Task 5 plan:
 *   - GuzzleHttp\ClientInterface replaced with hand-rolled ext-curl calls
 *     (composer.json allows only ext-pdo, ext-curl, ext-json, ext-mbstring);
 *   - PSR-3 LoggerInterface replaced with App\Core\Logger;
 *   - added: PUT /collections/{name} on first upsert, api-key header,
 *     timeout, retries, batch-upsert and wait=true;
 *   - search now returns metadata (interface contract) in addition to
 *     id and similarity.
 *
 * Degradation contract: every public method fails softly (false / []) and
 * never throws. When the curl extension is missing or the base URL is
 * unset, isAvailable() reports false and all operations become no-ops
 * (a single warning is logged per process).
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-09-18
 */
declare(strict_types=1);

namespace App\Storage\Vector;

use App\Core\Logger;
use App\Helpers\JsonHelper;
use App\Storage\VectorStore;
use App\Storage\VectorStoreInterface;
use Throwable;

final class QdrantVectorStore implements VectorStoreInterface
{
    private const DEFAULT_TIMEOUT_MS = 2000;
    private const DEFAULT_MAX_RETRIES = 2;

    /** @var array<string,bool> Collections ensured in this process. */
    private array $ensuredCollections = [];

    /** Warn about an unavailable driver once per process. */
    private static bool $availabilityWarned = false;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey = '',
        private readonly int $timeoutMs = self::DEFAULT_TIMEOUT_MS,
        private readonly int $maxRetries = self::DEFAULT_MAX_RETRIES,
    ) {}

    /**
     * The driver needs the curl extension and a non-empty base URL.
     * Logs one warning per process when unavailable; the caller can
     * still keep the instance for graceful degradation.
     */
    public function isAvailable(): bool
    {
        $ok = function_exists('curl_init') && trim($this->baseUrl) !== '';
        if (!$ok && !self::$availabilityWarned) {
            self::$availabilityWarned = true;
            Logger::warn('vector.qdrant.unavailable', [
                'curl_loaded' => function_exists('curl_init'),
                'base_url_set' => trim($this->baseUrl) !== '',
            ]);
        }
        return $ok;
    }

    /**
     * @inheritDoc
     */
    public function upsert(string $collection, int $id, array $vector, array $metadata = []): bool
    {
        if (!$this->isAvailable()) { return false; }
        $normalized = VectorStore::normalizeVector($vector);
        if ($normalized === []) { return false; }
        if (!$this->ensureCollection($collection, count($normalized))) { return false; }
        $response = $this->request('PUT', $this->path('/collections/{c}/points?wait=true', $collection), [
            'points' => [[
                'id'      => $id,
                'vector'  => $normalized,
                'payload' => $metadata,
            ]],
        ]);
        return $response['ok'];
    }

    /**
     * Insert or replace many points in a single request (wait=true).
     *
     * Used by the memory maintenance CLI for backfill/reindex runs.
     *
     * @param array<int,array{id:int,vector:list<float|int>,metadata?:array<string,mixed>}> $points
     */
    public function upsertBatch(string $collection, array $points): bool
    {
        if (!$this->isAvailable()) { return false; }
        $prepared = [];
        foreach ($points as $p) {
            if (!is_array($p) || !isset($p['id'], $p['vector'])) { continue; }
            $normalized = VectorStore::normalizeVector($p['vector']);
            if ($normalized === []) { continue; }
            $prepared[] = [
                'id'      => (int)$p['id'],
                'vector'  => $normalized,
                'payload' => is_array($p['metadata'] ?? null) ? $p['metadata'] : [],
            ];
        }
        if ($prepared === []) { return false; }
        if (!$this->ensureCollection($collection, count($prepared[0]['vector']))) { return false; }
        $response = $this->request('PUT', $this->path('/collections/{c}/points?wait=true', $collection), [
            'points' => $prepared,
        ]);
        return $response['ok'];
    }

    /**
     * @inheritDoc
     */
    public function search(string $collection, array $queryVector, int $topK = 10): array
    {
        if (!$this->isAvailable()) { return []; }
        $normalized = VectorStore::normalizeVector($queryVector);
        if ($normalized === []) { return []; }
        $response = $this->request('POST', $this->path('/collections/{c}/points/search', $collection), [
            'vector'       => $normalized,
            'limit'        => max(1, $topK),
            'with_payload' => true,
        ]);
        if (!$response['ok']) { return []; }
        $out = [];
        foreach (($response['data']['result'] ?? []) as $hit) {
            if (!is_array($hit) || !array_key_exists('id', $hit)) { continue; }
            $out[] = [
                'id'         => (int)$hit['id'],
                'similarity' => (float)($hit['score'] ?? 0.0),
                'metadata'   => is_array($hit['payload'] ?? null) ? $hit['payload'] : [],
            ];
        }
        return $out;
    }

    /**
     * @inheritDoc
     */
    public function delete(string $collection, int $id): bool
    {
        if (!$this->isAvailable()) { return false; }
        $response = $this->request('POST', $this->path('/collections/{c}/points/delete?wait=true', $collection), [
            'points' => [$id],
        ]);
        return $response['ok'];
    }

    /**
     * Ensure a collection exists with the right dimension, once per
     * process. Existence is probed first so an already-created
     * collection never goes through a conflicting PUT.
     */
    private function ensureCollection(string $collection, int $dimension): bool
    {
        if (isset($this->ensuredCollections[$collection])) { return true; }
        $probe = $this->request('GET', $this->path('/collections/{c}', $collection), null, true);
        if ($probe['ok']) {
            $this->ensuredCollections[$collection] = true;
            return true;
        }
        $created = $this->request('PUT', $this->path('/collections/{c}', $collection), [
            'vectors' => ['size' => $dimension, 'distance' => 'Cosine'],
        ]);
        if ($created['ok']) {
            $this->ensuredCollections[$collection] = true;
            return true;
        }
        Logger::warn('vector.qdrant.ensure_collection_failed', [
            'collection' => $collection,
            'dimension'  => $dimension,
        ]);
        return false;
    }

    /**
     * Perform one curl request with retries on transport errors and
     * HTTP 5xx. Returns ['ok'=>bool, 'status'=>int, 'data'=>array].
     */
    private function request(string $method, string $path, ?array $payload, bool $allow404 = false): array
    {
        $attempts = max(1, $this->maxRetries + 1);
        $url = rtrim($this->baseUrl, '/') . $path;
        $headers = ['Content-Type: application/json'];
        if ($this->apiKey !== '') {
            $headers[] = 'api-key: ' . $this->apiKey;
        }
        try {
            $jsonPayload = $payload !== null ? JsonHelper::encode($payload) : null;
        } catch (Throwable $e) {
            Logger::warn('vector.qdrant.payload_encode_failed', ['error' => Logger::sanitizeScalar($e->getMessage())]);
            return ['ok' => false, 'status' => 0, 'data' => []];
        }

        for ($i = 0; $i < $attempts; $i++) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => $url,
                CURLOPT_CUSTOMREQUEST  => $method,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT_MS     => max(1, $this->timeoutMs),
                CURLOPT_CONNECTTIMEOUT_MS => max(1, min($this->timeoutMs, 1500)),
                CURLOPT_HTTPHEADER     => $headers,
            ]);
            if ($jsonPayload !== null && ($method === 'POST' || $method === 'PUT')) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
            }
            $raw = curl_exec($ch);
            if ($raw === false) {
                $error = curl_error($ch);
                $status = 0;
            } else {
                $error = '';
                $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            }
            curl_close($ch);

            if ($status >= 200 && $status < 300) {
                $data = JsonHelper::tryDecode((string)$raw, []);
                return ['ok' => true, 'status' => $status, 'data' => is_array($data) ? $data : []];
            }
            if ($status === 404 && $allow404) {
                return ['ok' => false, 'status' => 404, 'data' => []];
            }
            $retryable = $status === 0 || $status >= 500;
            if ($retryable && $i + 1 < $attempts) {
                usleep(100_000 * ($i + 1));
                continue;
            }
            Logger::warn('vector.qdrant.http_error', [
                'method' => $method,
                'status' => $status,
                'error'  => Logger::sanitizeScalar($error),
            ]);
            return ['ok' => false, 'status' => $status, 'data' => []];
        }
        return ['ok' => false, 'status' => 0, 'data' => []];
    }

    /** Build a request path with a rawurlencoded collection segment. */
    private function path(string $template, string $collection): string
    {
        return str_replace('{c}', rawurlencode($collection), $template);
    }
}
