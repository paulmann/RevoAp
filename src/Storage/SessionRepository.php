<?php
/**
 * SessionRepository: PDO-backed CRUD for council_sessions.
 *
 * Accepts either a raw PDO (legacy) or a resilient Database wrapper.
 * When a Database is supplied, each write/read survives a single
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
use PDO;

final class SessionRepository
{
    /** @var array<int,array<string,mixed>> */
    private array $memory = [];
    private int  $autoId  = 0;
    private readonly ?Database $db;

    public function __construct(null|PDO|Database $db = null)
    {
        $this->db = $db instanceof PDO ? Database::fromPdo($db) : $db;
    }

    public function database(): ?Database { return $this->db; }

    /**
     * Create a new session and return its id.
     */
    public function create(int $skillId, string $query, ?int $userId = null): int
    {
        if ($this->db === null) {
            $id = ++$this->autoId;
            $this->memory[$id] = [
                'id'              => $id,
                'user_id'         => $userId,
                'skill_id'        => $skillId,
                'query_text'      => $query,
                'final_answer'    => null,
                'consensus_score' => null,
                'consensus_status'=> 'pending',
                'status'          => 'running',
                'total_tokens'    => 0,
                'duration_ms'     => 0,
                'created_at'      => date('Y-m-d H:i:s'),
                'completed_at'    => null,
            ];
            return $id;
        }
        return (int)$this->db->run(static function (PDO $pdo) use ($skillId, $query, $userId): int {
            $stmt = $pdo->prepare(
                'INSERT INTO council_sessions (user_id, skill_id, query_text)
                 VALUES (:user_id, :skill_id, :query_text)'
            );
            $stmt->execute([
                'user_id'    => $userId,
                'skill_id'   => $skillId,
                'query_text' => $query,
            ]);
            return (int)$pdo->lastInsertId();
        });
    }

    /**
     * @return array<string,mixed>|null
     */
    public function find(int $id): ?array
    {
        if ($this->db === null) {
            return $this->memory[$id] ?? null;
        }
        return $this->db->run(static function (PDO $pdo) use ($id): ?array {
            $stmt = $pdo->prepare('SELECT * FROM council_sessions WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $id]);
            $row = $stmt->fetch();
            return is_array($row) ? $row : null;
        });
    }

    /**
     * Write the terminal state of a session.
     *
     * `$status` is optional and defaults to `completed`, which is what
     * every pre-v1.8.48 caller passes implicitly — the column, the answer,
     * the verdict, the token total and the duration all land exactly as
     * before. It exists because "the run finished" and "the run produced a
     * final answer" are not the same thing: when L4 fails, the engine still
     * has a locally assembled fallback body to persist (it is the only
     * record of what the models did say), but the session must NOT be
     * advertised as a finished one, or History paints it green and the
     * repair path refuses to re-run the synthesis.
     *
     * Only `completed`/`failed` are accepted; anything else is treated as
     * `failed`, so a bad argument can never invent a status the UI does
     * not know how to render.
     */
    public function complete(
        int $id,
        string $finalAnswer,
        ?float $consensusScore,
        string $consensusStatus,
        int $totalTokens,
        int $durationMs,
        string $status = 'completed',
    ): void {
        if ($status !== 'completed') {
            $status = 'failed';
        }
        if ($this->db === null) {
            if (isset($this->memory[$id])) {
                $this->memory[$id] = array_merge($this->memory[$id], [
                    'status'           => $status,
                    'final_answer'     => $finalAnswer,
                    'consensus_score'  => $consensusScore,
                    'consensus_status' => $consensusStatus,
                    'total_tokens'     => $totalTokens,
                    'duration_ms'      => $durationMs,
                    'completed_at'     => date('Y-m-d H:i:s'),
                ]);
            }
            return;
        }
        $this->db->run(static function (PDO $pdo) use ($id, $finalAnswer, $consensusScore, $consensusStatus, $totalTokens, $durationMs, $status): void {
            $stmt = $pdo->prepare(
                'UPDATE council_sessions
                 SET status = :status, final_answer = :answer,
                     consensus_score = :score, consensus_status = :cstatus,
                     total_tokens = :tokens, duration_ms = :duration,
                     completed_at = CURRENT_TIMESTAMP
                 WHERE id = :id'
            );
            $stmt->execute([
                'id'       => $id,
                'status'   => $status,
                'answer'   => $finalAnswer,
                'score'    => $consensusScore,
                'cstatus'  => $consensusStatus,
                'tokens'   => $totalTokens,
                'duration' => $durationMs,
            ]);
        });
    }

    /**
     * Persist History tab metadata (skill slug + serialized runtime
     * options) onto an existing council_sessions row. Best-effort: if
     * the migration that adds those columns has not been applied yet,
     * the UPDATE silently no-ops so older deploys keep running.
     *
     * @param array<string,mixed>|null $options
     */
    public function setMetadata(int $id, ?string $skillSlug, ?array $options): void
    {
        if ($this->db === null) {
            if (isset($this->memory[$id])) {
                $this->memory[$id]['skill_slug']   = $skillSlug;
                $this->memory[$id]['options_json'] = $options;
            }
            return;
        }
        $optionsJson = $options !== null ? JsonHelper::encode($options) : null;
        $this->db->run(static function (PDO $pdo) use ($id, $skillSlug, $optionsJson): void {
            try {
                $stmt = $pdo->prepare(
                    'UPDATE council_sessions
                     SET skill_slug = :slug, options_json = :opts
                     WHERE id = :id'
                );
                $stmt->execute([
                    'id'   => $id,
                    'slug' => $skillSlug,
                    'opts' => $optionsJson,
                ]);
            } catch (\Throwable) {
                // Columns may not exist yet on hosts that have not applied
                // db/migrations/004_history_session_metadata.sql. Skip the
                // write — the rest of the pipeline must keep running.
            }
        });
    }

    public function markFailed(int $id, int $durationMs): void
    {
        if ($this->db === null) {
            if (isset($this->memory[$id])) {
                $this->memory[$id]['status']      = 'failed';
                $this->memory[$id]['duration_ms'] = $durationMs;
            }
            return;
        }
        $this->db->run(static function (PDO $pdo) use ($id, $durationMs): void {
            $stmt = $pdo->prepare(
                'UPDATE council_sessions
                 SET status = "failed", duration_ms = :duration, completed_at = CURRENT_TIMESTAMP
                 WHERE id = :id'
            );
            $stmt->execute(['id' => $id, 'duration' => $durationMs]);
        });
    }
}
