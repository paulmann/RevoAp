<?php
/**
 * VectorStoreFactory: Build the configured VectorStoreInterface driver.
 *
 * Driver selection reads the `vector.*` config keys:
 *   vector.driver            'mysql' (default) | 'qdrant'
 *   vector.qdrant.url        Qdrant REST base URL (required for qdrant)
 *   vector.qdrant.api_key    Optional api-key header value
 *   vector.qdrant.timeout_ms Per-request timeout (default 2000)
 *   vector.qdrant.max_retries Per-request HTTP retries (default 2)
 *
 * Degradation: when `qdrant` is requested but unusable (curl missing,
 * empty URL), the factory logs a warning and falls back to the MySQL
 * driver so the pipeline keeps running with "no memory" behaviour
 * instead of breaking the run.
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

use App\Core\Config;
use App\Core\Logger;
use App\Storage\VectorStore;
use App\Storage\VectorStoreInterface;
use PDO;

final class VectorStoreFactory
{
    public static function create(?PDO $pdo = null): VectorStoreInterface
    {
        $driver = strtolower((string)Config::get('vector.driver', 'mysql'));
        if ($driver === 'qdrant') {
            $qdrant = new QdrantVectorStore(
                baseUrl:    (string)Config::get('vector.qdrant.url', ''),
                apiKey:     (string)Config::get('vector.qdrant.api_key', ''),
                timeoutMs:  (int)Config::get('vector.qdrant.timeout_ms', 2000),
                maxRetries: (int)Config::get('vector.qdrant.max_retries', 2),
            );
            if ($qdrant->isAvailable()) {
                return $qdrant;
            }
            Logger::warn('vector.factory.qdrant_unavailable_fallback', []);
        }
        return new VectorStore($pdo);
    }
}
