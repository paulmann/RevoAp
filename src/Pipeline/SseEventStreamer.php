<?php
/**
 * SseEventStreamer: writes Server-Sent Events to the active output buffer.
 *
 * Compatible with PHP-FPM when output buffering is disabled and Apache
 * `flush` is invoked after each event. We do NOT depend on long-running
 * background workers — events stream as they are produced inside a
 * single HTTP request.
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

use App\Core\Logger;
use App\Helpers\JsonHelper;

final class SseEventStreamer implements EventStreamer
{
    private int $eventId = 0;

    /** @var (\Closure(string,array<string,mixed>):void)|null */
    private ?\Closure $onEmit = null;

    /**
     * Subscribe a closure to receive every event before it is written to
     * the wire. v1.8.21 — used by CouncilController::stream() to record
     * the session_id on the StreamRunLock once the engine reports it.
     */
    public function setOnEmit(?\Closure $cb): void
    {
        $this->onEmit = $cb;
    }

    public function emit(string $eventType, array $payload, ?int $stepId = null): void
    {
        if ($this->onEmit !== null) {
            try {
                ($this->onEmit)($eventType, $payload);
            } catch (\Throwable $e) {
                Logger::warn('sse.onEmit_callback_failed', ['error' => $e->getMessage()]);
            }
        }
        $body = JsonHelper::encode([
            'type'    => $eventType,
            'step_id' => $stepId,
            'at'      => date('c'),
            'payload' => Logger::sanitize($payload),
        ]);
        $id = ++$this->eventId;
        // Use a custom event name so EventSource clients can dispatch
        // by type via addEventListener("L1_RESPONSE", …) AND fall back
        // to onmessage when they only care about the JSON body.
        echo "id: {$id}\n";
        echo "event: pipeline\n";
        echo 'data: ' . $body . "\n\n";
        $this->flush();
    }

    public function comment(string $text): void
    {
        // SSE comment line — keeps proxies from closing the connection
        // and never reaches an EventSource onmessage handler.
        echo ': ' . str_replace(["\r", "\n"], ' ', $text) . "\n\n";
        $this->flush();
    }

    private function flush(): void
    {
        if (function_exists('ob_get_level')) {
            while (ob_get_level() > 0) {
                @ob_end_flush();
            }
        }
        @flush();
    }
}
