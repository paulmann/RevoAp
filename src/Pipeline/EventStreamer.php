<?php
/**
 * EventStreamer: optional sink for incremental pipeline events.
 *
 * The pipeline engine appends every event to the persistent
 * StepRepository unconditionally. An EventStreamer is an *additional*
 * sink that lets a transport (Server-Sent Events, WebSocket, log tail)
 * observe events as they happen without polling the database.
 *
 * Implementations MUST be non-blocking: the pipeline engine treats
 * emit() as a best-effort hook and never depends on its return value.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-05-07
 */
declare(strict_types=1);

namespace App\Pipeline;

interface EventStreamer
{
    /**
     * Emit a single pipeline event.
     *
     * @param string                $eventType  e.g. L1_RESPONSE
     * @param array<string,mixed>   $payload    Sanitization is the streamer's responsibility.
     * @param int|null              $stepId
     */
    public function emit(string $eventType, array $payload, ?int $stepId = null): void;
}
