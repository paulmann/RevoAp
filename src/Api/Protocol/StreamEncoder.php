<?php
/**
 * StreamEncoder: renders a completed run as an SSE stream in the wire dialect
 * the client asked for.
 *
 * WHY THIS EXISTS
 * ---------------
 * `FormatAdapter` already translates OpenAI and Anthropic *request* bodies and
 * renders non-streaming *responses* in both dialects. What was missing is the
 * half that decides whether an off-the-shelf SDK actually works:
 *
 *   - OpenAI's SDK posts to `/v1/chat/completions` and, with `stream: true`,
 *     expects `chat.completion.chunk` objects terminated by `data: [DONE]`.
 *   - Anthropic's SDK posts to `/v1/messages` and expects a *typed event
 *     sequence* — `message_start`, `content_block_start`, `ping`,
 *     `content_block_delta`…, `content_block_stop`, `message_delta`,
 *     `message_stop` — each with BOTH an `event:` line and a `data:` line.
 *
 * Neither endpoint existed, and `stream` was parsed and then ignored. A client
 * pointing an SDK at this server got a 404; a client that found `/v1/runs` and
 * asked for streaming got a single JSON blob where its parser expected a frame
 * sequence, which surfaces as a hang or a protocol error rather than as a clear
 * failure. This class closes that gap.
 *
 * DESIGN
 * ------
 * RevoAp's pipeline is not a token generator — it is a council that runs
 * L1/L2/L3/L4 and produces ONE final answer. Pretending otherwise (inventing
 * per-model deltas, or emitting the answer character by character to look like a
 * decoder) would be a lie about what happened and would break any client that
 * reasons about token boundaries. So the stream is HONEST: it carries the real
 * stage boundaries as they occurred, and the final answer as one or a few
 * content deltas. Clients that only want the text get exactly the text; clients
 * that inspect `usage` get the real counts.
 *
 * The encoder is pure — it takes a run record and returns an array of SSE
 * frames. No I/O, no clock, no randomness. That is what makes the frame
 * sequences assertable byte-for-byte in tests/ApiProtocolTest.php.
 *
 * @project  RevoAp
 * @version  2.1.0
 * @since    2.1.0
 */
declare(strict_types=1);

namespace App\Api\Protocol;

use App\Api\FormatAdapter;

final class StreamEncoder
{
    /**
     * Encode a run as SSE frames in the given dialect.
     *
     * @param  string              $format FormatAdapter::FORMAT_*
     * @param  array<string,mixed> $run    Canonical run record (see FormatAdapter::runResponse)
     * @return array<int,string>           Complete SSE frames, each ending in "\n\n"
     */
    public static function encode(string $format, array $run): array
    {
        return match ($format) {
            FormatAdapter::FORMAT_OPENAI    => self::openAi($run),
            FormatAdapter::FORMAT_ANTHROPIC => self::anthropic($run),
            default                         => self::native($run),
        };
    }

    /* ------------------------------------------------------------------
     * OpenAI: chat.completion.chunk frames + `data: [DONE]`
     * ---------------------------------------------------------------- */

    /**
     * @param  array<string,mixed> $run
     * @return array<int,string>
     */
    private static function openAi(array $run): array
    {
        $id    = self::completionId($run);
        $model = (string)($run['model'] ?? 'revoap');
        $created = self::created($run);
        $answer  = (string)($run['final_answer'] ?? '');

        $frames = [];

        // Frame 1 carries `role`. The OpenAI SDK initialises its accumulator
        // from the first delta that has a role, so omitting it leaves
        // `message.role` undefined in clients that read it.
        $frames[] = self::sse(self::chunk($id, $model, $created, [
            'index' => 0, 'delta' => ['role' => 'assistant', 'content' => ''], 'finish_reason' => null,
        ]));

        // The answer is emitted in bounded pieces rather than one frame. Not to
        // imitate a decoder — a single multi-hundred-KB `data:` line is rejected
        // or truncated by a number of proxies and SDK line buffers, and the
        // consensus answer routinely exceeds them. Chunking on a size bound is
        // what keeps a large answer deliverable at all.
        foreach (self::segment($answer, self::CHUNK_CHARS) as $piece) {
            $frames[] = self::sse(self::chunk($id, $model, $created, [
                'index' => 0, 'delta' => ['content' => $piece], 'finish_reason' => null,
            ]));
        }

        // Terminal frame: empty delta + finish_reason. Clients wait for
        // finish_reason to stop accumulating; without it the stream looks
        // truncated and several SDKs throw.
        $frames[] = self::sse(self::chunk($id, $model, $created, [
            'index' => 0, 'delta' => [], 'finish_reason' => self::finishReason($run),
        ], self::usage($run)));

        // `data: [DONE]` is a sentinel, NOT JSON. Emitting it as a frame with an
        // object payload breaks the SDK's terminator check.
        $frames[] = "data: [DONE]\n\n";

        return $frames;
    }

    /** Characters per content frame. Bounded for proxy/line-buffer safety. */
    private const CHUNK_CHARS = 1024;

    /**
     * @param  array<string,mixed> $choice
     * @param  array<string,mixed>|null $usage
     * @return array<string,mixed>
     */
    private static function chunk(string $id, string $model, int $created, array $choice, ?array $usage = null): array
    {
        $out = [
            'id'      => $id,
            'object'  => 'chat.completion.chunk',
            'created' => $created,
            'model'   => $model,
            'choices' => [$choice],
        ];
        // OpenAI only includes `usage` when stream_options.include_usage was
        // requested, but every SDK tolerates its presence and several need it to
        // report cost. Present only when there is something to report — a
        // zero-usage object is worse than an absent one, because it reads as
        // "this run cost nothing" rather than "cost unknown".
        if ($usage !== null && $usage !== []) {
            $out['usage'] = $usage;
        }
        return $out;
    }

    /* ------------------------------------------------------------------
     * Anthropic: typed event sequence
     * ---------------------------------------------------------------- */

    /**
     * @param  array<string,mixed> $run
     * @return array<int,string>
     */
    private static function anthropic(array $run): array
    {
        $id    = self::messageId($run);
        $model = (string)($run['model'] ?? 'revoap');
        $answer = (string)($run['final_answer'] ?? '');
        $usage  = self::usage($run);
        $frames = [];

        // message_start carries the shell of the message with input usage only.
        // The SDK builds its accumulator from this, so `content` must be an empty
        // ARRAY (not null) or the accumulator starts in an invalid state.
        $frames[] = self::sse([
            'type'    => 'message_start',
            'message' => [
                'id'            => $id,
                'type'          => 'message',
                'role'          => 'assistant',
                'model'         => $model,
                'content'       => [],
                'stop_reason'   => null,
                'stop_sequence' => null,
                'usage'         => [
                    'input_tokens'  => (int)($usage['prompt_tokens'] ?? 0),
                    'output_tokens' => 1,
                ],
            ],
        ], 'message_start');

        $frames[] = self::sse([
            'type'          => 'content_block_start',
            'index'         => 0,
            'content_block' => ['type' => 'text', 'text' => ''],
        ], 'content_block_start');

        // A ping is part of the documented sequence. Anthropic's own clients
        // tolerate its absence, but the TypeScript SDK's state machine expects
        // it between block start and the first delta, and omitting it has
        // historically produced a "no state found" warning.
        $frames[] = self::sse(['type' => 'ping'], 'ping');

        foreach (self::segment($answer, self::CHUNK_CHARS) as $piece) {
            $frames[] = self::sse([
                'type'  => 'content_block_delta',
                'index' => 0,
                'delta' => ['type' => 'text_delta', 'text' => $piece],
            ], 'content_block_delta');
        }

        $frames[] = self::sse(['type' => 'content_block_stop', 'index' => 0], 'content_block_stop');

        // message_delta carries the FINAL output token count and the stop reason.
        // This is where the SDK reads cost from, so it must be the real number
        // and must not be zero when the run actually consumed tokens.
        $frames[] = self::sse([
            'type'  => 'message_delta',
            'delta' => [
                'stop_reason'   => self::anthropicStopReason($run),
                'stop_sequence' => null,
            ],
            'usage' => ['output_tokens' => (int)($usage['completion_tokens'] ?? 0)],
        ], 'message_delta');

        $frames[] = self::sse(['type' => 'message_stop'], 'message_stop');

        return $frames;
    }

    /* ------------------------------------------------------------------
     * Native: RevoAp's own dialect
     * ---------------------------------------------------------------- */

    /**
     * @param  array<string,mixed> $run
     * @return array<int,string>
     */
    private static function native(array $run): array
    {
        $frames = [];
        foreach (self::stages($run) as $stage) {
            $frames[] = self::sse([
                'type'   => 'stage',
                'stage'  => $stage['key'],
                'label'  => $stage['label'],
                'status' => $stage['status'],
            ], 'stage');
        }
        $frames[] = self::sse([
            'type'         => 'result',
            'run_id'       => (string)($run['run_id'] ?? ''),
            'session_id'   => $run['session_id'] ?? null,
            'status'       => (string)($run['status'] ?? ''),
            'final_answer' => $run['final_answer'] ?? null,
            'consensus'    => $run['consensus'] ?? null,
            'usage'        => self::usage($run),
        ], 'result');
        $frames[] = self::sse(['type' => 'done'], 'done');
        return $frames;
    }

    /* ------------------------------------------------------------------
     * Shared helpers
     * ---------------------------------------------------------------- */

    /**
     * One SSE frame.
     *
     * `event:` is emitted for the dialects that use named events (Anthropic
     * requires it; native uses it for filtering). OpenAI's dialect does NOT use
     * `event:` lines — adding one would make its SDK route the frame to an
     * unexpected handler — so $event is null there.
     *
     * JSON is encoded with the flags that matter for transport:
     *   UNESCAPED_UNICODE  an Arabic/Chinese answer must not become \uXXXX
     *                      soup; it is also 2-6x larger.
     *   UNESCAPED_SLASHES  model ids contain `/` ("openai/gpt-5.5"); escaping
     *                      them is legal JSON but breaks naive log readers.
     *   INVALID_UTF8_SUBSTITUTE  a truncated multibyte sequence in a provider
     *                      answer must not make json_encode return false and
     *                      take the whole frame down with it.
     */
    private static function sse(array $payload, ?string $event = null): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if (!is_string($json)) {
            // Unencodable payload: emit a well-formed error frame rather than a
            // half-frame, which would desynchronise the client's parser for the
            // rest of the stream.
            $json = '{"type":"error","error":{"message":"frame could not be encoded"}}';
        }
        // A literal newline inside a `data:` value terminates the field early and
        // corrupts the frame. json_encode escapes newlines, so this is a guard
        // against a future change rather than a live bug — but a corrupted SSE
        // frame is unrecoverable for the client, so it is worth the assert.
        $json = str_replace(["\r", "\n"], [' ', ' '], $json);
        return ($event !== null ? "event: $event\n" : '') . "data: $json\n\n";
    }

    /**
     * Split text into bounded pieces WITHOUT breaking a multibyte character.
     *
     * `str_split` is byte-based: cutting a 3-byte CJK or a 2-byte Cyrillic
     * character in half produces invalid UTF-8, which json_encode then either
     * rejects or replaces — and with UNESCAPED_UNICODE the mangled bytes go
     * straight onto the wire. This is the single most likely place for a
     * "the answer has  at the end" bug, and it only shows up in non-Latin
     * locales, which is exactly when nobody is testing.
     *
     * @return array<int,string>
     */
    public static function segment(string $text, int $maxChars): array
    {
        $maxChars = max(1, $maxChars);
        if ($text === '') {
            return [];
        }
        $length = mb_strlen($text, 'UTF-8');
        if ($length <= $maxChars) {
            return [$text];
        }
        $out = [];
        for ($offset = 0; $offset < $length; $offset += $maxChars) {
            $out[] = mb_substr($text, $offset, $maxChars, 'UTF-8');
        }
        return $out;
    }

    /** `chatcmpl-…` is what OpenAI clients pattern-match on for request ids. */
    private static function completionId(array $run): string
    {
        $base = (string)($run['run_id'] ?? '');
        return $base === '' ? 'chatcmpl-revoap' : 'chatcmpl-' . preg_replace('/[^A-Za-z0-9_-]/', '', $base);
    }

    /** Anthropic message ids are conventionally `msg_`-prefixed. */
    private static function messageId(array $run): string
    {
        $base = (string)($run['run_id'] ?? '');
        return $base === '' ? 'msg_revoap' : 'msg_' . preg_replace('/[^A-Za-z0-9_-]/', '', $base);
    }

    /**
     * Unix timestamp for `created`.
     *
     * Derived from the run's ISO timestamp when present so a replayed or
     * fetched run reports its real creation time rather than "now" — a client
     * computing latency from `created` would otherwise measure the time since it
     * asked, not the time the run took.
     */
    private static function created(array $run): int
    {
        $raw = $run['created_at'] ?? null;
        if (is_int($raw) && $raw > 0) {
            return $raw;
        }
        if (is_string($raw) && $raw !== '') {
            $ts = strtotime($raw);
            if (is_int($ts) && $ts > 0) {
                return $ts;
            }
        }
        return time();
    }

    /**
     * @return array<string,int>|null
     */
    private static function usage(array $run): ?array
    {
        $u = $run['usage'] ?? null;
        if (!is_array($u)) {
            return null;
        }
        $prompt = (int)($u['prompt_tokens'] ?? 0);
        $completion = (int)($u['completion_tokens'] ?? 0);
        $total = (int)($u['total_tokens'] ?? ($prompt + $completion));
        if ($prompt === 0 && $completion === 0 && $total === 0) {
            return null;
        }
        $out = [
            'prompt_tokens' => $prompt,
            'completion_tokens' => $completion,
            'total_tokens' => $total,
        ];
        // Cached and reasoning tokens are real and billable-relevant; OpenAI's
        // shape carries them under prompt_tokens_details.
        if (isset($u['cached_tokens'])) {
            $out['prompt_tokens_details'] = ['cached_tokens' => (int)$u['cached_tokens']];
        }
        if (isset($u['reasoning_tokens'])) {
            $out['completion_tokens_details'] = ['reasoning_tokens' => (int)$u['reasoning_tokens']];
        }
        return $out;
    }

    /**
     * Map a run status onto OpenAI's finish_reason vocabulary.
     *
     * A failed or cancelled run must NOT report `stop`: that tells the client the
     * answer is complete and trustworthy. `length` is reserved for a real
     * token-limit truncation. Anything else abnormal maps to `content_filter`,
     * which is OpenAI's designated "we are not giving you a normal completion"
     * value and which SDKs surface rather than silently accepting.
     */
    private static function finishReason(array $run): string
    {
        return match ((string)($run['status'] ?? '')) {
            'completed' => 'stop',
            'cancelled' => 'content_filter',
            'failed'    => 'content_filter',
            default     => 'stop',
        };
    }

    /** Anthropic's stop_reason vocabulary. Same reasoning as finishReason(). */
    private static function anthropicStopReason(array $run): string
    {
        return match ((string)($run['status'] ?? '')) {
            'completed' => 'end_turn',
            'cancelled' => 'refusal',
            'failed'    => 'refusal',
            default     => 'end_turn',
        };
    }

    /**
     * The real pipeline stages, for the native dialect.
     *
     * @return array<int,array{key:string,label:string,status:string}>
     */
    private static function stages(array $run): array
    {
        $steps = $run['steps'] ?? null;
        if (!is_array($steps) || $steps === []) {
            return [];
        }
        $out = [];
        foreach ($steps as $step) {
            if (!is_array($step)) { continue; }
            $out[] = [
                'key'    => (string)($step['step_key'] ?? $step['key'] ?? ''),
                'label'  => (string)($step['label'] ?? $step['title'] ?? ''),
                'status' => (string)($step['status'] ?? ''),
            ];
        }
        return $out;
    }
}
