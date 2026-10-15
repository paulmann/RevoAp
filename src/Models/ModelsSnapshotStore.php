<?php
/**
 * ModelsSnapshotStore: durable before/after state for an actualization.
 *
 * An actualization rewrites three things that the operator cannot
 * reconstruct by hand — the persisted catalogue, every consensus preset
 * and the model pool the Chat sidebar had selected. Before the first byte
 * is written, the old values are stored here so a run that turns out to
 * be wrong can be undone in one call.
 *
 * Storage: the `app_config` table (key/value, already migrated and
 * already the home of runtime UI preferences), through
 * {@see ConfigRepository}.
 *
 * Key choice
 * ----------
 *   `models.actualize.snapshot.latest`
 *   `models.actualize.status`
 *
 * Two keys under the `models.` namespace, with the `models` scope:
 *
 *   * **One slot, named `latest`,** not an append-only history. Only the
 *     state *before the most recent run* is recoverable, which is the
 *     operation the UI offers ("roll back the last actualization"). A
 *     history would need a retention policy, a size cap and a pruning
 *     job — real complexity for a rollback nobody asked to go back two
 *     runs. The single slot is overwritten on each new run, and that
 *     overwrite is itself the retention policy.
 *   * **Under `models.`**, so the keys sort next to future model-related
 *     entries and stay out of `app.*` (runtime flags) and `ui.*`
 *     (presentation), which `ConfigRepository` already owns for other
 *     purposes.
 *   * **The snapshot and the live status are separate keys**, so reading
 *     progress on every 3s UI poll does not read (or risk clobbering) the
 *     rollback payload, which is the larger and more valuable record.
 *
 * The `app_config` table may be absent on a host mid-migration:
 * `ConfigRepository` degrades to null/void reads and writes, so every
 * method here stays usable and `available()` reports the truth so the
 * controller can tell the operator that rollback is not offered.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-09-21
 */
declare(strict_types=1);

namespace App\Models;

use App\Settings\ConfigRepository;

final class ModelsSnapshotStore
{
    /** Pre-run state of the most recent actualization. */
    public const KEY_SNAPSHOT = 'models.actualize.snapshot.latest';

    /** Status record of the most recent actualization. */
    public const KEY_STATUS = 'models.actualize.status';

    /** Namespace for both keys. */
    public const SCOPE = 'models';

    public function __construct(private readonly ?ConfigRepository $config = null) {}

    /**
     * True when a snapshot can actually be persisted and read back.
     *
     * The `app_config` table is created if it is missing — a
     * `CREATE TABLE IF NOT EXISTS`, so this is a no-op on every host that
     * already imported `db/schema.sql`. Only the admin-gated mutating
     * paths call this (enqueue, rollback); the public status probe reports
     * snapshot availability from the snapshot it already read, so no
     * unauthenticated request performs DDL.
     */
    public function available(): bool
    {
        if ($this->config === null) {
            return false;
        }
        try {
            $this->config->ensureTable();
            return $this->config->tableExists();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Persist a snapshot. Returns false when storage is unavailable, so
     * the caller can refuse to write anything (an actualization without a
     * rollback path would be unrecoverable).
     *
     * @param array<string,mixed> $snapshot
     */
    public function saveSnapshot(array $snapshot): bool
    {
        return $this->put(self::KEY_SNAPSHOT, $snapshot);
    }

    /** @return array<string,mixed>|null */
    public function loadSnapshot(): ?array
    {
        return $this->read(self::KEY_SNAPSHOT);
    }

    /** @param array<string,mixed> $status */
    public function saveStatus(array $status): bool
    {
        return $this->put(self::KEY_STATUS, $status);
    }

    /**
     * v1.8.50 — record where an in-flight run has got to.
     *
     * Before this existed the status key was written exactly once, at the
     * end of a run (`ModelsActualizer::finish()`); the stage transitions
     * only went to the job-event audit table, which the status endpoint
     * does not read. The Models tab therefore showed "Analysing models"
     * from the moment the job was queued until the envelope landed — and
     * if the run died before `finish()` (an exception in planning, a
     * killed worker) nothing was written at all, so the card fell back to
     * its initial state: "it spins, then resets, and I cannot tell whether
     * it worked".
     *
     * The record is merged into the existing one rather than derived from
     * a fresh array, so `started_at` and any warnings already published
     * survive. A record belonging to a DIFFERENT job id is discarded
     * first: a new run must never inherit the previous run's verdict.
     *
     * @param array<string,mixed> $context Extra progress facts (counts, slug…).
     */
    public function saveProgress(int $jobId, string $stage, string $message, array $context = []): bool
    {
        $status = $this->loadStatus() ?? [];
        if ((int)($status['job_id'] ?? 0) !== $jobId) {
            $status = [];
        }
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $status['kind']    = ModelsActualizer::KIND;
        $status['job_id']  = $jobId;
        $status['stage']   = $stage;
        $status['message'] = $message;
        $status['active']  = true;
        $status['updated_at'] = $now;
        $status['started_at'] = is_string($status['started_at'] ?? null) && $status['started_at'] !== ''
            ? $status['started_at']
            : $now;
        if (is_array($status['warnings'] ?? null) === false) {
            $status['warnings'] = [];
        }
        // A run that is still going has no verdict yet.
        unset($status['ok'], $status['finished_at'], $status['duration_ms'], $status['error'], $status['failed_stage']);
        if ($context !== []) {
            $status['progress'] = $context;
        }
        return $this->saveStatus($status);
    }

    /**
     * v1.8.50 — the newest status record, when it belongs to `$jobId`.
     *
     * Lets a caller read back what it published without inheriting an
     * unrelated run's terminal verdict.
     *
     * @return array<string,mixed>|null
     */
    public function statusFor(int $jobId): ?array
    {
        $status = $this->loadStatus();
        if ($status === null || (int)($status['job_id'] ?? 0) !== $jobId) {
            return null;
        }
        return $status;
    }

    /** @return array<string,mixed>|null */
    public function loadStatus(): ?array
    {
        return $this->read(self::KEY_STATUS);
    }

    /** @param array<string,mixed> $value */
    private function put(string $key, array $value): bool
    {
        if ($this->config === null) {
            return false;
        }
        try {
            // set() creates the table when it is missing, so the first
            // snapshot on a fresh host succeeds instead of failing the run.
            $this->config->set($key, self::encode($value), self::SCOPE);
            return true;
        } catch (\Throwable) {
            // A failed snapshot write must surface as "no rollback path",
            // not as a fatal error halfway through a run.
            return false;
        }
    }

    /** @return array<string,mixed>|null */
    private function read(string $key): ?array
    {
        if ($this->config === null) {
            return null;
        }
        $raw = $this->config->get($key);
        if ($raw === null || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    /** @param array<string,mixed> $value */
    private static function encode(array $value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $json === false ? '{}' : $json;
    }
}
