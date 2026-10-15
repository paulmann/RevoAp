<?php
/**
 * StepRepository: Append-only persistence for pipeline steps and events.
 *
 * Accepts either a raw PDO (legacy) or a resilient Database wrapper.
 * When a Database is supplied, every write/read survives a single
 * MySQL "server has gone away" disconnect via Database::run().
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

use App\Core\Database;
use App\Helpers\JsonHelper;
use App\Helpers\StepKeyHelper;
use PDO;

final class StepRepository
{
    /** @var array<int,array<string,mixed>> */
    private array $stepMemory = [];
    /** @var array<int,array<string,mixed>> */
    private array $eventMemory = [];
    private int  $stepAutoId  = 0;
    private int  $eventAutoId = 0;
    private readonly ?Database $db;

    public function __construct(null|PDO|Database $db = null)
    {
        $this->db = $db instanceof PDO ? Database::fromPdo($db) : $db;
    }

    public function database(): ?Database { return $this->db; }

    /**
     * Create a new pipeline step record.
     *
     * @param  array<string,mixed>|null $meta
     */
    public function createStep(int $sessionId, string $stepKey, string $label, int $orderIndex, ?array $meta = null): int
    {
        // Defensive normalisation: never persist a value wider than the
        // `council_steps.step_key` column (VARCHAR(191) in v1.8.28+).
        // Callers should already pre-normalise via StepKeyHelper but
        // this guards every path through the repository in one place.
        $safeKey = StepKeyHelper::normalize($stepKey);
        if ($this->db === null) {
            $id = ++$this->stepAutoId;
            $this->stepMemory[$id] = [
                'id'          => $id,
                'session_id'  => $sessionId,
                'step_key'    => $safeKey,
                'label'       => $label,
                'order_index' => $orderIndex,
                'status'      => 'running',
                'summary'     => null,
                'meta'        => $meta,
                'started_at'  => date('Y-m-d H:i:s'),
                'ended_at'    => null,
            ];
            return $id;
        }
        return (int)$this->db->run(static function (PDO $pdo) use ($sessionId, $safeKey, $label, $orderIndex, $meta): int {
            $stmt = $pdo->prepare(
                'INSERT INTO council_steps (session_id, step_key, label, order_index, meta)
                 VALUES (:sid, :key, :label, :idx, :meta)'
            );
            $stmt->execute([
                'sid'   => $sessionId,
                'key'   => $safeKey,
                'label' => $label,
                'idx'   => $orderIndex,
                'meta'  => $meta !== null ? JsonHelper::encode($meta) : null,
            ]);
            return (int)$pdo->lastInsertId();
        });
    }

    /**
     * Mark a step as finished. Optionally merges extra meta into the
     * existing meta JSON. Existing meta keys are preserved; new keys
     * overwrite same-named keys.
     *
     * @param  array<string,mixed>|null $metaExtras
     */
    public function completeStep(int $stepId, string $status, ?string $summary = null, ?array $metaExtras = null): void
    {
        if ($this->db === null) {
            if (isset($this->stepMemory[$stepId])) {
                $this->stepMemory[$stepId]['status']   = $status;
                $this->stepMemory[$stepId]['summary']  = $summary;
                $this->stepMemory[$stepId]['ended_at'] = date('Y-m-d H:i:s');
                if ($metaExtras !== null) {
                    $existing = is_array($this->stepMemory[$stepId]['meta'] ?? null)
                        ? $this->stepMemory[$stepId]['meta']
                        : [];
                    $this->stepMemory[$stepId]['meta'] = array_merge($existing, $metaExtras);
                }
            }
            return;
        }
        if ($metaExtras === null || $metaExtras === []) {
            $this->db->run(static function (PDO $pdo) use ($stepId, $status, $summary): void {
                $stmt = $pdo->prepare(
                    'UPDATE council_steps SET status = :s, summary = :sum, ended_at = CURRENT_TIMESTAMP WHERE id = :id'
                );
                $stmt->execute(['id' => $stepId, 's' => $status, 'sum' => $summary]);
            });
            return;
        }
        $this->db->run(static function (PDO $pdo) use ($stepId, $status, $summary, $metaExtras): void {
            $sel = $pdo->prepare('SELECT meta FROM council_steps WHERE id = :id');
            $sel->execute(['id' => $stepId]);
            $existing = [];
            $row = $sel->fetch();
            if (is_array($row) && isset($row['meta']) && is_string($row['meta']) && $row['meta'] !== '') {
                $decoded = json_decode($row['meta'], true);
                if (is_array($decoded)) {
                    $existing = $decoded;
                }
            }
            $merged = array_merge($existing, $metaExtras);
            $stmt = $pdo->prepare(
                'UPDATE council_steps SET status = :s, summary = :sum, meta = :meta, ended_at = CURRENT_TIMESTAMP WHERE id = :id'
            );
            $stmt->execute([
                'id'   => $stepId,
                's'    => $status,
                'sum'  => $summary,
                'meta' => JsonHelper::encode($merged),
            ]);
        });
    }

    /**
     * Mark every step that is still "running" for the given session as
     * failed with a single explanatory summary. Used by the pipeline
     * engine when a fatal error aborts the run mid-flight so the UI
     * never leaves stale RUNNING cards on screen.
     */
    public function failRunningSteps(int $sessionId, string $reason): int
    {
        if ($this->db === null) {
            $count = 0;
            foreach ($this->stepMemory as &$row) {
                if ((int)$row['session_id'] === $sessionId && $row['status'] === 'running') {
                    $row['status']  = 'failed';
                    $row['summary'] = $reason;
                    $row['ended_at'] = date('Y-m-d H:i:s');
                    $count++;
                }
            }
            unset($row);
            return $count;
        }
        return (int)$this->db->run(static function (PDO $pdo) use ($sessionId, $reason): int {
            $stmt = $pdo->prepare(
                'UPDATE council_steps
                 SET status = "failed", summary = :reason, ended_at = CURRENT_TIMESTAMP
                 WHERE session_id = :sid AND status = "running"'
            );
            $stmt->execute(['sid' => $sessionId, 'reason' => $reason]);
            return $stmt->rowCount();
        });
    }

    /**
     * @return array<int,array{id:int,step_key:string,label:string}> Running steps for fatal handling.
     */
    public function listRunningSteps(int $sessionId): array
    {
        if ($this->db === null) {
            $rows = [];
            foreach ($this->stepMemory as $row) {
                if ((int)$row['session_id'] === $sessionId && $row['status'] === 'running') {
                    $rows[] = ['id' => (int)$row['id'], 'step_key' => (string)$row['step_key'], 'label' => (string)$row['label']];
                }
            }
            return $rows;
        }
        return $this->db->run(static function (PDO $pdo) use ($sessionId): array {
            $stmt = $pdo->prepare(
                'SELECT id, step_key, label FROM council_steps
                 WHERE session_id = :sid AND status = "running"'
            );
            $stmt->execute(['sid' => $sessionId]);
            $rows = $stmt->fetchAll();
            $out = [];
            foreach (is_array($rows) ? $rows : [] as $r) {
                $out[] = ['id' => (int)$r['id'], 'step_key' => (string)$r['step_key'], 'label' => (string)$r['label']];
            }
            return $out;
        });
    }

    /**
     * @param  array<string,mixed>|null $payload
     */
    public function appendEvent(int $sessionId, ?int $stepId, string $eventType, ?array $payload = null): int
    {
        if ($this->db === null) {
            $id = ++$this->eventAutoId;
            $this->eventMemory[$id] = [
                'id'         => $id,
                'session_id' => $sessionId,
                'step_id'    => $stepId,
                'event_type' => $eventType,
                'payload'    => $payload,
                'created_at' => date('Y-m-d H:i:s.v'),
            ];
            return $id;
        }
        return (int)$this->db->run(static function (PDO $pdo) use ($sessionId, $stepId, $eventType, $payload): int {
            $stmt = $pdo->prepare(
                'INSERT INTO pipeline_events (session_id, step_id, event_type, payload)
                 VALUES (:sid, :step, :type, :payload)'
            );
            $stmt->execute([
                'sid'     => $sessionId,
                'step'    => $stepId,
                'type'    => $eventType,
                'payload' => $payload !== null ? JsonHelper::encode($payload) : null,
            ]);
            return (int)$pdo->lastInsertId();
        });
    }

    /** @return array<int,array<string,mixed>> */
    public function listStepsBySession(int $sessionId): array
    {
        if ($this->db === null) {
            $rows = array_values(array_filter(
                $this->stepMemory,
                static fn(array $r): bool => (int)$r['session_id'] === $sessionId
            ));
            usort($rows, static fn(array $a, array $b): int => $a['order_index'] <=> $b['order_index']);
            return $rows;
        }
        return $this->db->run(static function (PDO $pdo) use ($sessionId): array {
            $stmt = $pdo->prepare(
                'SELECT * FROM council_steps WHERE session_id = :sid ORDER BY order_index ASC, id ASC'
            );
            $stmt->execute(['sid' => $sessionId]);
            $rows = $stmt->fetchAll();
            return is_array($rows) ? $rows : [];
        });
    }

    /** @return array<int,array<string,mixed>> */
    public function listEventsBySession(int $sessionId): array
    {
        if ($this->db === null) {
            $rows = array_values(array_filter(
                $this->eventMemory,
                static fn(array $r): bool => (int)$r['session_id'] === $sessionId
            ));
            usort($rows, static fn(array $a, array $b): int => $a['id'] <=> $b['id']);
            return $rows;
        }
        return $this->db->run(static function (PDO $pdo) use ($sessionId): array {
            $stmt = $pdo->prepare(
                'SELECT * FROM pipeline_events WHERE session_id = :sid ORDER BY id ASC'
            );
            $stmt->execute(['sid' => $sessionId]);
            $rows = $stmt->fetchAll();
            return is_array($rows) ? $rows : [];
        });
    }
}
