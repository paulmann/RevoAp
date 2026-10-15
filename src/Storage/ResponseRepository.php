<?php
/**
 * ResponseRepository: Persistence for council_model_responses (per-step LLM
 * outputs and their token statistics). Accepts a resilient Database
 * wrapper so writes survive idle MySQL disconnects.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.1.0
 * @since    2026-05-05
 */
declare(strict_types=1);

namespace App\Storage;

use App\Connectors\ChatResponse;
use App\Core\Database;
use App\Helpers\JsonHelper;
use PDO;

final class ResponseRepository
{
    /** @var array<int,array<string,mixed>> */
    private array $memory = [];
    private int  $autoId  = 0;
    private readonly ?Database $db;

    /**
     * Whether `council_model_responses` carries the v1.8.45 cache columns
     * (see db/migrations/018_prompt_cache_stats.sql). Probed once per
     * instance and cached, because the answer cannot change while the
     * process lives. A database that has not applied 018 keeps working —
     * it simply stores no cache figures in this table, which the UI then
     * reports as "no data" instead of a cache miss.
     */
    private ?bool $hasCacheColumns = null;
    private ?bool $hasCostColumn  = null;   // R10: column from db/migrations/019

    public function __construct(null|PDO|Database $db = null)
    {
        $this->db = $db instanceof PDO ? Database::fromPdo($db) : $db;
    }

    public function database(): ?Database { return $this->db; }

    /**
     * @param  array<string,mixed>|null $normalized
     */
    public function record(int $stepId, ChatResponse $response, ?string $roleName = null, ?array $normalized = null): int
    {
        if ($this->db === null) {
            $id = ++$this->autoId;
            $this->memory[$id] = [
                'id'                  => $id,
                'step_id'             => $stepId,
                'model'               => $response->model,
                'provider'            => $response->provider,
                'role_name'           => $roleName,
                'raw_content'         => $response->content,
                'normalized_response' => $normalized,
                'prompt_tokens'       => $response->promptTokens,
                'completion_tokens'   => $response->completionTokens,
                'total_tokens'        => $response->totalTokens,
                'latency_ms'          => (int)round($response->latencyMs),
                'tokens_estimated'    => $response->tokensEstimated ? 1 : 0,
                // Cache statistics stay null when the provider reported
                // none — see the column comment in db/schema.sql. Writing 0
                // here instead would turn "no data" into "cache miss".
                'cache_read_tokens'   => $response->cachedTokens,
                'cache_write_tokens'  => $response->cacheWriteTokens,
                'cache_discount'      => $response->cacheDiscount,
                // $response->cost is null when the provider reported no
                // price - see the column comment in db/schema.sql.
                'cost'                => $response->cost,
                'created_at'          => date('Y-m-d H:i:s'),
            ];
            return $id;
        }
        return (int)$this->db->run(function (PDO $pdo) use ($stepId, $response, $roleName, $normalized): int {
            $columns = 'step_id, model, provider, role_name, raw_content, normalized_response,
                        prompt_tokens, completion_tokens, total_tokens, latency_ms, tokens_estimated';
            $values  = ':step, :model, :provider, :role, :raw, :norm,
                        :pt, :ct, :tt, :lat, :est';
            $params  = [
                'step'     => $stepId,
                'model'    => $response->model,
                'provider' => $response->provider,
                'role'     => $roleName,
                'raw'      => $response->content,
                'norm'     => $normalized !== null ? JsonHelper::encode($normalized) : null,
                'pt'       => $response->promptTokens,
                'ct'       => $response->completionTokens,
                'tt'       => $response->totalTokens,
                'lat'      => (int)round($response->latencyMs),
                'est'      => $response->tokensEstimated ? 1 : 0,
            ];
            if ($this->cacheColumnsPresent($pdo)) {
                $columns .= ', cache_read_tokens, cache_write_tokens, cache_discount';
                $values  .= ', :cr, :cw, :cd';
                $params  += [
                    'cr' => $response->cachedTokens,
                    'cw' => $response->cacheWriteTokens,
                    'cd' => $response->cacheDiscount,
                ];
            }
            if ($this->costColumnPresent($pdo)) {
                $columns .= ', cost';
                $values  .= ', :cost';
                $params  += ['cost' => $response->cost];
            }
            $stmt = $pdo->prepare("INSERT INTO council_model_responses ({$columns}) VALUES ({$values})");
            $stmt->execute($params);
            return (int)$pdo->lastInsertId();
        });
    }

    /**
     * Probe for the cache columns added by db/migrations/018.
     *
     * Driver-aware for the same reason HistoryService probes
     * `council_sessions.options_json`: the test suites drive SQLite while a
     * deployment drives MySQL, and a missing column must degrade the
     * INSERT, not fail it. A table that does not exist yet also answers
     * `false` here, which is the right answer — there is then no column.
     */
    private function cacheColumnsPresent(PDO $pdo): bool
    {
        if ($this->hasCacheColumns !== null) {
            return $this->hasCacheColumns;
        }
        $found = false;
        try {
            $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($driver === 'sqlite') {
                $rows = $pdo->query('PRAGMA table_info(council_model_responses)');
                foreach (($rows === false ? [] : $rows->fetchAll()) ?: [] as $row) {
                    if (is_array($row) && ($row['name'] ?? '') === 'cache_read_tokens') {
                        $found = true;
                        break;
                    }
                }
            } elseif ($driver === 'mysql') {
                $stmt = $pdo->prepare("SHOW COLUMNS FROM council_model_responses LIKE 'cache_read_tokens'");
                $stmt->execute();
                $found = $stmt->fetch() !== false;
            } else {
                $pdo->query('SELECT cache_read_tokens FROM council_model_responses WHERE 1=0');
                $found = true;
            }
        } catch (\Throwable) {
            $found = false;
        }
        return $this->hasCacheColumns = $found;
    }

    /**
     * Probe for the price column added by db/migrations/019.
     *
     * Same driver-aware shape as cacheColumnsPresent(): SQLite in the test
     * suites, MySQL in a deployment, and a missing column degrading the INSERT
     * (to "price not recorded") rather than failing it.
     */
    private function costColumnPresent(PDO $pdo): bool
    {
        if ($this->hasCostColumn !== null) {
            return $this->hasCostColumn;
        }
        $found = false;
        try {
            $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($driver === 'sqlite') {
                $rows = $pdo->query('PRAGMA table_info(council_model_responses)');
                foreach (($rows === false ? [] : $rows->fetchAll()) ?: [] as $row) {
                    if (is_array($row) && ($row['name'] ?? '') === 'cost') { $found = true; break; }
                }
            } elseif ($driver === 'mysql') {
                $stmt = $pdo->prepare("SHOW COLUMNS FROM council_model_responses LIKE 'cost'");
                $stmt->execute();
                $found = $stmt->fetch() !== false;
            } else {
                $pdo->query('SELECT cost FROM council_model_responses WHERE 1=0');
                $found = true;
            }
        } catch (\Throwable) {
            $found = false;
        }

        return $this->hasCostColumn = $found;
    }


    /** @return array<int,array<string,mixed>> */
    public function listByStep(int $stepId): array
    {
        if ($this->db === null) {
            $rows = array_values(array_filter(
                $this->memory,
                static fn(array $r): bool => (int)$r['step_id'] === $stepId
            ));
            return $rows;
        }
        return $this->db->run(static function (PDO $pdo) use ($stepId): array {
            $stmt = $pdo->prepare('SELECT * FROM council_model_responses WHERE step_id = :sid ORDER BY id ASC');
            $stmt->execute(['sid' => $stepId]);
            $rows = $stmt->fetchAll();
            return is_array($rows) ? $rows : [];
        });
    }
}