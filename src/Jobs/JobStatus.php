<?php
/**
 * JobStatus: durable lifecycle states for revoap_jobs.
 *
 * The values are stored verbatim in `revoap_jobs.status` as VARCHAR.
 * Using a backed enum (instead of an ENUM column) keeps the schema
 * stable when future statuses are added.
 *
 * Lifecycle:
 *   queued -> running -> completed | failed
 *   running -> pause_requested -> paused -> resume_requested -> running
 *   running -> cancel_requested -> cancelled
 *   running -> consensus_requested -> running (worker stops new cycles, runs L4)
 *   running -> stale (heartbeat lapsed; reclaimed by another worker)
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-05-13
 */
declare(strict_types=1);

namespace App\Jobs;

enum JobStatus: string
{
    case Queued             = 'queued';
    case Running            = 'running';
    case PauseRequested     = 'pause_requested';
    case Paused             = 'paused';
    case ResumeRequested    = 'resume_requested';
    case CancelRequested    = 'cancel_requested';
    case Cancelled          = 'cancelled';
    case ConsensusRequested = 'consensus_requested';
    case Completed          = 'completed';
    case Failed             = 'failed';
    case Stale              = 'stale';

    /**
     * Terminal statuses cannot transition further. Pause/cancel/consensus
     * intent flags on a terminal job are ignored.
     *
     * @return bool
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Completed, self::Failed, self::Cancelled => true,
            default => false,
        };
    }

    /**
     * Whether the job is currently active (worker is, or should be,
     * making progress on it).
     */
    public function isActive(): bool
    {
        return match ($this) {
            self::Queued, self::Running, self::PauseRequested,
            self::ResumeRequested, self::ConsensusRequested => true,
            default => false,
        };
    }

    /**
     * Allowed status values as a flat list (lower-case strings). Useful
     * for input validation at the API boundary.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn(self $c): string => $c->value, self::cases());
    }

    public static function tryFromString(string $value): ?self
    {
        return self::tryFrom(strtolower(trim($value)));
    }
}
