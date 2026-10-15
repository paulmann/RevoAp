<?php
/**
 * RunControl: durable, file-backed control flag store for pipeline runs.
 *
 * The PHP request that drives a pipeline cannot itself accept a second
 * HTTP call (PHP-FPM is one-request-per-worker), so the `pause` /
 * `continue` / `reject` UI signals must be persisted out-of-band where
 * the running worker can poll them. This class stores one tiny JSON
 * file per session id under `<base>/runtime/run-control/`.
 *
 * The runtime file format is:
 *     {
 *       "session_id":   123,
 *       "state":        "running" | "paused" | "rejected" | "done",
 *       "updated_at":   "2026-05-08T13:04:17Z",
 *       "reason":       "user requested pause"   // optional
 *     }
 *
 * Behavior is cooperative: the pipeline polls the state between layers
 * (and inside the L1 dispatcher gap, before launching new requests). It
 * is not safe to abort an already in-flight curl call from another
 * request, so a `reject` issued mid-network-round will only take effect
 * when the current network round completes — the engine then refuses
 * to start any further work and the run finalizes as `rejected`.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-05-08
 */
declare(strict_types=1);

namespace App\Pipeline;

use App\Core\Logger;

/**
 * RunControl is intentionally NON-final since v1.8.20: tests subclass
 * it to inject deterministic state transitions without touching the
 * filesystem. Production code should never extend this class.
 */
class RunControl
{
    public const STATE_RUNNING    = 'running';
    public const STATE_PAUSED     = 'paused';
    public const STATE_REJECTED   = 'rejected';
    /**
     * v1.8.20: user has requested "Stop and Run consensus". The
     * consensus loop will NOT start any more cycles, but the run
     * proceeds straight to L4 synthesis with whatever L1/L2/L3
     * results were already collected, then terminates normally with
     * a PIPELINE_DONE event.
     */
    public const STATE_FINALIZING = 'finalizing';
    public const STATE_DONE       = 'done';

    public const ACTION_PAUSE    = 'pause';
    public const ACTION_CONTINUE = 'continue';
    public const ACTION_REJECT   = 'reject';
    /**
     * v1.8.20: "Stop and Run consensus" — cooperatively stops the
     * cycle loop and routes already-collected results to L4. Different
     * from REJECT (which aborts and produces no final answer) and from
     * PAUSE (which is resumable).
     */
    public const ACTION_FINALIZE = 'finalize';

    /** Maximum seconds the engine will sit in the pause-poll loop before giving up. */
    public const PAUSE_TIMEOUT_SECONDS = 600;
    /** Poll interval while paused. Short enough to feel snappy, long enough to be cheap. */
    public const PAUSE_POLL_INTERVAL_USEC = 250_000; // 250 ms

    private readonly string $dir;

    public function __construct(string $baseDir)
    {
        $this->dir = rtrim($baseDir, '/') . '/runtime/run-control';
        if (!is_dir($this->dir)) {
            // Best-effort. If the parent is read-only the engine still
            // works — pause/reject simply become no-ops.
            @mkdir($this->dir, 0755, true);
        }
    }

    /**
     * Set the desired control state for a session. Idempotent: repeated
     * calls with the same action keep the file in the same state. Returns
     * the persisted record.
     *
     * @return array<string,mixed>
     */
    public function applyAction(int $sessionId, string $action, ?string $reason = null): array
    {
        $action = strtolower(trim($action));
        $state = match ($action) {
            self::ACTION_PAUSE    => self::STATE_PAUSED,
            self::ACTION_CONTINUE => self::STATE_RUNNING,
            self::ACTION_REJECT   => self::STATE_REJECTED,
            self::ACTION_FINALIZE => self::STATE_FINALIZING,
            default               => null,
        };
        if ($state === null) {
            throw new \InvalidArgumentException('unknown action: ' . $action);
        }
        $payload = [
            'session_id' => $sessionId,
            'state'      => $state,
            'updated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'reason'     => $reason,
        ];
        $this->write($sessionId, $payload);
        return $payload;
    }

    /**
     * Mark a run as completed/cleared. Called by the pipeline once a run
     * terminates (success, fatal or rejected) so stale files do not pile
     * up on disk and so a subsequent identical session id does not
     * inherit a previous rejection by accident.
     */
    public function finalize(int $sessionId, string $finalState = self::STATE_DONE): void
    {
        @unlink($this->path($sessionId));
        // Also clean up any hidden tmp file.
        @unlink($this->path($sessionId) . '.tmp');
    }

    /**
     * Return the live control record for a session, or null when nothing
     * has been signaled (which is interpreted as "still running"). The
     * read is lock-free; concurrent writes use atomic rename so a torn
     * read never decodes.
     *
     * @return array<string,mixed>|null
     */
    public function read(int $sessionId): ?array
    {
        $path = $this->path($sessionId);
        if (!is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    /** Convenience getter: returns the state string ("running", "paused", "rejected") or "running" by default. */
    public function state(int $sessionId): string
    {
        $rec = $this->read($sessionId);
        if (!is_array($rec)) {
            return self::STATE_RUNNING;
        }
        $s = (string)($rec['state'] ?? self::STATE_RUNNING);
        return in_array($s, [self::STATE_RUNNING, self::STATE_PAUSED, self::STATE_REJECTED, self::STATE_FINALIZING, self::STATE_DONE], true)
            ? $s
            : self::STATE_RUNNING;
    }

    /** True iff the session has been explicitly rejected by the user. */
    public function isRejected(int $sessionId): bool
    {
        return $this->state($sessionId) === self::STATE_REJECTED;
    }

    /** True iff the session is currently paused (and not also rejected). */
    public function isPaused(int $sessionId): bool
    {
        return $this->state($sessionId) === self::STATE_PAUSED;
    }

    /**
     * True iff the user pressed "Stop and Run consensus" — the engine
     * should refuse to start any further consensus cycles and drop
     * through to L4 synthesis using results gathered so far.
     */
    public function isFinalizing(int $sessionId): bool
    {
        return $this->state($sessionId) === self::STATE_FINALIZING;
    }

    /**
     * Block while the session is in `paused` state. Returns the final
     * state once the file flips to `running`, `rejected`, or
     * `finalizing` (v1.8.20), or after PAUSE_TIMEOUT_SECONDS to avoid
     * pinning a worker forever.
     */
    public function awaitResume(int $sessionId): string
    {
        $deadline = microtime(true) + self::PAUSE_TIMEOUT_SECONDS;
        while (microtime(true) < $deadline) {
            $state = $this->state($sessionId);
            if ($state !== self::STATE_PAUSED) {
                return $state;
            }
            usleep(self::PAUSE_POLL_INTERVAL_USEC);
        }
        // Timeout — treat as continue so we don't strand the worker.
        Logger::warn('run_control.pause.timeout', ['session_id' => $sessionId]);
        return self::STATE_RUNNING;
    }

    private function path(int $sessionId): string
    {
        return $this->dir . '/' . $sessionId . '.json';
    }

    /** @param array<string,mixed> $payload */
    private function write(int $sessionId, array $payload): void
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0755, true) && !is_dir($this->dir)) {
            return;
        }
        $path = $this->path($sessionId);
        $tmp  = $path . '.tmp';
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return;
        }
        if (@file_put_contents($tmp, $json) !== false) {
            @rename($tmp, $path);
        }
    }
}
