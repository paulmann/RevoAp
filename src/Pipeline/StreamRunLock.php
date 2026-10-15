<?php
/**
 * StreamRunLock: per-client_run_id mutex preventing duplicate SSE pipeline runs.
 *
 * The browser's EventSource auto-reconnects whenever a stream closes
 * without a terminal event. Without protection, each reconnect would
 * re-enter `/api/council/stream`, spawn a fresh PipelineEngine::run()
 * and re-issue every model call from cycle 1 again — burning tokens and
 * confusing the UI with overlapping pipelines (the v1.8.20 paste showed
 * 10 such phantom cycles when the configured cycle limit was 2).
 *
 * This class implements a tiny file-backed mutex:
 *   - `acquire($clientRunId)` atomically creates `<dir>/<sha8>.json`
 *     using O_EXCL semantics. Returns `true` on first claim.
 *   - The lock holder is responsible for `release($clientRunId)` once
 *     the pipeline terminates (PIPELINE_DONE / FATAL / REJECTED).
 *   - Stale locks older than `STALE_AFTER_SECONDS` are reclaimed so a
 *     PHP-FPM worker that died without releasing does not block reruns
 *     forever.
 *
 * Lock files carry a small JSON envelope with `client_run_id`,
 * `session_id` (set once the pipeline reports it), `pid`, `acquired_at`,
 * and `updated_at`. `read()` returns the envelope so the controller can
 * include the original session_id in the PIPELINE_DUPLICATE_RUN_REJECTED
 * event.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.8.21
 * @since    2026-05-12
 */
declare(strict_types=1);

namespace App\Pipeline;

use App\Core\Logger;

final class StreamRunLock
{
    /** Stale-lock reclaim threshold. PHP max execution + safety margin. */
    public const STALE_AFTER_SECONDS = 900;

    private readonly string $dir;

    public function __construct(string $baseDir)
    {
        $this->dir = rtrim($baseDir, '/') . '/runtime/stream-locks';
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0755, true);
        }
    }

    /**
     * Attempt to claim the lock for the supplied client_run_id. Returns
     * true on success, false if another active worker already holds it.
     */
    public function acquire(string $clientRunId): bool
    {
        $path = $this->path($clientRunId);
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0755, true) && !is_dir($this->dir)) {
            // No dir, no lock — let the run proceed (cache is best effort).
            return true;
        }
        $existing = $this->readPath($path);
        if (is_array($existing)) {
            $acquired = (int)($existing['acquired_at_unix'] ?? 0);
            $age = time() - $acquired;
            if ($age < self::STALE_AFTER_SECONDS) {
                return false;
            }
            // Stale — reclaim by unlinking. Best effort.
            @unlink($path);
            Logger::warn('stream_run_lock.reclaim_stale', ['path' => $path, 'age' => $age]);
        }
        $payload = [
            'client_run_id'    => $clientRunId,
            'pid'              => function_exists('getmypid') ? getmypid() : null,
            'acquired_at'      => gmdate('Y-m-d\TH:i:s\Z'),
            'acquired_at_unix' => time(),
            'updated_at'       => gmdate('Y-m-d\TH:i:s\Z'),
            'session_id'       => null,
        ];
        // Atomic create: O_EXCL via 'xb' guarantees first-writer wins.
        $fh = @fopen($path, 'xb');
        if ($fh === false) {
            return false;
        }
        $written = @fwrite($fh, (string)json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        @fclose($fh);
        if ($written === false) {
            @unlink($path);
            return false;
        }
        return true;
    }

    /** Attach a session_id to the lock record. Best-effort. */
    public function recordSession(string $clientRunId, int $sessionId): void
    {
        $path = $this->path($clientRunId);
        $payload = $this->readPath($path);
        if (!is_array($payload)) {
            return;
        }
        $payload['session_id'] = $sessionId;
        $payload['updated_at'] = gmdate('Y-m-d\TH:i:s\Z');
        $tmp = $path . '.tmp';
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) { return; }
        if (@file_put_contents($tmp, $json) !== false) {
            @rename($tmp, $path);
        }
    }

    /** Return the lock envelope for an existing client_run_id, or null. */
    public function read(string $clientRunId): ?array
    {
        $rec = $this->readPath($this->path($clientRunId));
        return is_array($rec) ? $rec : null;
    }

    /**
     * Find a lock record that currently claims the supplied session id.
     *
     * The pipeline stamps the session id onto its lock the moment
     * PIPELINE_STARTED is emitted, so a non-null result means a worker is
     * (or very recently was — within STALE_AFTER_SECONDS) driving that
     * session. Used by the History "Finish session" flow to refuse
     * touching a run that is still alive.
     *
     * Lock files are few (one per in-flight run) and each is tiny, so a
     * directory scan is cheaper than maintaining a second index.
     *
     * @return array<string,mixed>|null
     */
    public function findBySession(int $sessionId): ?array
    {
        if ($sessionId <= 0 || !is_dir($this->dir)) {
            return null;
        }
        $paths = glob($this->dir . '/*.json');
        if (!is_array($paths)) {
            return null;
        }
        foreach ($paths as $path) {
            $rec = $this->readPath((string)$path);
            if (!is_array($rec)) { continue; }
            if ((int)($rec['session_id'] ?? 0) !== $sessionId) { continue; }
            $age = time() - (int)($rec['acquired_at_unix'] ?? 0);
            if ($age >= 0 && $age < self::STALE_AFTER_SECONDS) {
                return $rec;
            }
        }
        return null;
    }

    /** Release the lock. Idempotent — missing files are not an error. */
    public function release(string $clientRunId): void
    {
        @unlink($this->path($clientRunId));
        @unlink($this->path($clientRunId) . '.tmp');
    }

    /** Sanitize the client_run_id and derive a short, deterministic file name. */
    private function path(string $clientRunId): string
    {
        $clean = preg_replace('/[^A-Za-z0-9_\-]/', '', $clientRunId) ?? '';
        if ($clean === '') {
            $clean = 'anon';
        }
        $hash = hash('sha256', $clean);
        return $this->dir . '/' . substr($hash, 0, 32) . '.json';
    }

    private function readPath(string $path): ?array
    {
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
}
