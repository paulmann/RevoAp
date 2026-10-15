<?php
/**
 * FormatAdapter: bi-directional translation between the external REST
 * API formats (native, OpenAI-compatible, Anthropic-compatible) and
 * the internal RevoAp payload consumed by JobService::createJob().
 *
 * Request side (`parseRequest`):
 *   - `native` bodies pass through the documented RevoAp keys.
 *   - `openai` bodies ({"model", "messages":[...], "stream", ...}) are
 *     mapped to: `query` = the last user message, `selected_models` =
 *     [{provider, model}], and every other transport-level option
 *     (temperature, max_tokens, conversation history, system prompt)
 *     is preserved in scalar/JSON form under `request_meta` so the
 *     durable job row keeps a faithful audit of the original request.
 *   - `anthropic` bodies ({"model", "messages", "system", "max_tokens"})
 *     follow the same contract; text-block contents are flattened.
 *
 * Response side (`runResponse` / `errorResponse`): a canonical internal
 * run record is rendered as the format the caller requested so clients
 * can consume RevoAp through off-the-shelf OpenAI/Anthropic SDKs.
 *
 * The adapter is a pure mapping layer: no database, no network, no
 * side effects. All mapping decisions are deterministic and unit-tested
 * in tests/FormatAdapterTest.php.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-09-19
 */
declare(strict_types=1);

namespace App\Api;

final class FormatAdapter
{
    public const FORMAT_NATIVE    = 'native';
    public const FORMAT_OPENAI    = 'openai';
    public const FORMAT_ANTHROPIC = 'anthropic';

    public const FORMATS = [self::FORMAT_NATIVE, self::FORMAT_OPENAI, self::FORMAT_ANTHROPIC];

    /**
     * Guess the request format from the shape of a decoded JSON body.
     * A body carrying the native `query` key is native; otherwise an
     * OpenAI/Anthropic-style `messages` envelope is assumed. Callers
     * that already know the format (route hint) may pass `$hint` to
     * bypass the heuristic; an invalid hint is ignored.
     *
     * @param array<string,mixed> $body
     */
    public function detectFormat(array $body, ?string $hint = null): string
    {
        if ($hint !== null && in_array($hint, self::FORMATS, true)) {
            return $hint;
        }
        if (array_key_exists('query', $body)) {
            return self::FORMAT_NATIVE;
        }
        if (is_array($body['messages'] ?? null)) {
            // Anthropic envelopes carry a string `system` field; OpenAI
            // carries an optional messages[0].role=system instead.
            if (array_key_exists('system', $body) && is_string($body['system'])) {
                return self::FORMAT_ANTHROPIC;
            }
            return self::FORMAT_OPENAI;
        }
        return self::FORMAT_NATIVE;
    }

    /**
     * Translate a decoded request body into the internal job input.
     *
     * @param  array<string,mixed> $body Decoded JSON request body.
     * @param  string|null         $hint Route-provided format hint.
     * @return array{
     *   format:string,
     *   input:array<string,mixed>,
     *   stream:bool,
     *   warnings:list<string>,
     * }
     * @throws \InvalidArgumentException When the body cannot be mapped.
     */
    public function parseRequest(array $body, ?string $hint = null): array
    {
        $format = $this->detectFormat($body, $hint);
        return match ($format) {
            self::FORMAT_NATIVE    => $this->parseNative($body),
            self::FORMAT_OPENAI    => $this->parseOpenAi($body),
            self::FORMAT_ANTHROPIC => $this->parseAnthropic($body),
            default => throw new \InvalidArgumentException('unsupported_format'),
        };
    }

    /**
     * Render a canonical internal run record in the target format.
     *
     * Canonical run record shape:
     *   [
     *     'run_id'       => string,             // e.g. 'run_42'
     *     'session_id'   => int,
     *     'job_id'       => int|null,
     *     'status'       => string,             // queued|running|completed|failed|cancelled
     *     'created_at'   => string|null,        // ISO-8601 UTC
     *     'final_answer' => string|null,
     *     'consensus'    => array|null,         // {score,status}
     *     'model'        => string|null,        // aggregated model id
     *     'usage'        => array|null,         // {prompt_tokens,completion_tokens,total_tokens}
     *     'urls'         => array,              // events_url/result_url/cancel_url
     *   ]
     *
     * @param array<string,mixed> $run
     * @return array<string,mixed>
     */
    public function runResponse(string $format, array $run): array
    {
        $run = $this->normalizeRun($run);
        return match ($format) {
            self::FORMAT_NATIVE    => $this->nativeRunResponse($run),
            self::FORMAT_OPENAI    => $this->openAiRunResponse($run),
            self::FORMAT_ANTHROPIC => $this->anthropicRunResponse($run),
            default => $this->nativeRunResponse($run),
        };
    }

    /**
     * Render an API error body in the target format. Keeps the existing
     * native shape (ok/error/message) and maps it onto the error shape
     * OpenAI/Anthropic SDKs expect.
     *
     * @return array<string,mixed>
     */
    public function errorResponse(string $format, int $status, string $code, string $message): array
    {
        return match ($format) {
            self::FORMAT_OPENAI => [
                'error' => [
                    'message' => $message,
                    'type'    => self::openAiErrorType($status),
                    'code'    => $code,
                ],
            ],
            self::FORMAT_ANTHROPIC => [
                'type'  => 'error',
                'error' => [
                    'type'    => self::anthropicErrorType($status),
                    'message' => $message,
                ],
            ],
            default => [
                'ok'      => false,
                'error'   => $code,
                'message' => $message,
                'status'  => $status,
            ],
        };
    }

    // ----------------------------------------------------------------
    // Request parsing
    // ----------------------------------------------------------------

    /**
     * Native bodies are passed through, with the transport-only
     * `stream` flag pulled out for the controller.
     *
     * @param array<string,mixed> $body
     * @return array{format:string,input:array<string,mixed>,stream:bool,warnings:list<string>}
     */
    private function parseNative(array $body): array
    {
        $query = trim((string)($body['query'] ?? ''));
        if ($query === '') {
            throw new \InvalidArgumentException('query is required');
        }
        $input = $body;
        $stream = (bool)($body['stream'] ?? false);
        unset($input['stream']);
        return [
            'format'   => self::FORMAT_NATIVE,
            'input'    => $input,
            'stream'   => $stream,
            'warnings' => [],
        ];
    }

    /**
     * OpenAI-compatible mapping: query = last user message; model ->
     * selected_models; the rest is preserved in `request_meta`.
     *
     * @param array<string,mixed> $body
     * @return array{format:string,input:array<string,mixed>,stream:bool,warnings:list<string>}
     */
    private function parseOpenAi(array $body): array
    {
        $messages = $body['messages'] ?? null;
        if (!is_array($messages) || $messages === []) {
            throw new \InvalidArgumentException('messages is required');
        }
        $normalized = [];
        foreach ($messages as $m) {
            if (!is_array($m)) {
                continue;
            }
            $role = strtolower(trim((string)($m['role'] ?? '')));
            $contentText = self::flattenContent($m['content'] ?? null);
            if ($role === '' || !in_array($role, ['system', 'user', 'assistant', 'tool'], true)) {
                continue;
            }
            $normalized[] = ['role' => $role, 'content' => $contentText];
        }
        $queryIndex = null;
        for ($i = count($normalized) - 1; $i >= 0; $i--) {
            if ($normalized[$i]['role'] === 'user') {
                $queryIndex = $i;
                break;
            }
        }
        if ($queryIndex === null) {
            throw new \InvalidArgumentException('at least one user message is required');
        }
        $query = trim($normalized[$queryIndex]['content']);
        if ($query === '') {
            throw new \InvalidArgumentException('query is required');
        }
        $history = $normalized;
        array_splice($history, $queryIndex, 1);

        $model = trim((string)($body['model'] ?? ''));
        if ($model === '') {
            throw new \InvalidArgumentException('model is required');
        }
        $provider = trim((string)($body['revoap_provider'] ?? $body['provider'] ?? self::FORMAT_OPENAI));
        if ($provider === '') {
            $provider = self::FORMAT_OPENAI;
        }

        $meta = [];
        foreach (['temperature', 'max_tokens', 'max_completion_tokens', 'top_p', 'seed', 'reasoning_effort'] as $key) {
            if (array_key_exists($key, $body)) {
                $meta['openai_' . $key] = $this->scalarCarrier($body[$key]);
            }
        }
        if ($history !== []) {
            $meta['openai_history'] = json_encode($history, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $userMeta = (string)($body['user'] ?? '');
        if ($userMeta !== '') {
            $meta['openai_user'] = $userMeta;
        }

        $input = [
            'query'           => $query,
            'selected_models' => [['provider' => $provider, 'model' => $model]],
        ];
        if ($meta !== []) {
            $input['request_meta'] = $meta;
        }

        return [
            'format'   => self::FORMAT_OPENAI,
            'input'    => $input,
            'stream'   => (bool)($body['stream'] ?? false),
            'warnings' => $history !== []
                ? ['Conversation history is preserved in request_meta; only the last user message seeds the consensus query.']
                : [],
        ];
    }

    /**
     * Anthropic-compatible mapping. Supports both string content and
     * the block-list form ([{type:"text", text:"..."}, ...]).
     *
     * @param array<string,mixed> $body
     * @return array{format:string,input:array<string,mixed>,stream:bool,warnings:list<string>}
     */
    private function parseAnthropic(array $body): array
    {
        $messages = $body['messages'] ?? null;
        if (!is_array($messages) || $messages === []) {
            throw new \InvalidArgumentException('messages is required');
        }
        $normalized = [];
        foreach ($messages as $m) {
            if (!is_array($m)) {
                continue;
            }
            $role = strtolower(trim((string)($m['role'] ?? '')));
            if (!in_array($role, ['user', 'assistant'], true)) {
                continue;
            }
            $normalized[] = ['role' => $role, 'content' => self::flattenContent($m['content'] ?? null)];
        }
        $queryIndex = null;
        for ($i = count($normalized) - 1; $i >= 0; $i--) {
            if ($normalized[$i]['role'] === 'user') {
                $queryIndex = $i;
                break;
            }
        }
        if ($queryIndex === null) {
            throw new \InvalidArgumentException('at least one user message is required');
        }
        $query = trim($normalized[$queryIndex]['content']);
        if ($query === '') {
            throw new \InvalidArgumentException('query is required');
        }
        $history = $normalized;
        array_splice($history, $queryIndex, 1);

        $model = trim((string)($body['model'] ?? ''));
        if ($model === '') {
            throw new \InvalidArgumentException('model is required');
        }
        $provider = trim((string)($body['revoap_provider'] ?? $body['provider'] ?? self::FORMAT_ANTHROPIC));
        if ($provider === '') {
            $provider = self::FORMAT_ANTHROPIC;
        }

        $meta = [];
        $system = self::flattenContent($body['system'] ?? null);
        if ($system !== '') {
            $meta['anthropic_system'] = $system;
        }
        foreach (['max_tokens', 'temperature', 'top_p', 'top_k', 'thinking'] as $key) {
            if (array_key_exists($key, $body)) {
                $meta['anthropic_' . $key] = $this->scalarCarrier($body[$key]);
            }
        }
        if ($history !== []) {
            $meta['anthropic_history'] = json_encode($history, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        if (isset($body['metadata']) && is_array($body['metadata'])) {
            $meta['anthropic_metadata'] = json_encode($body['metadata'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $input = [
            'query'           => $query,
            'selected_models' => [['provider' => $provider, 'model' => $model]],
        ];
        if ($meta !== []) {
            $input['request_meta'] = $meta;
        }

        return [
            'format'   => self::FORMAT_ANTHROPIC,
            'input'    => $input,
            'stream'   => (bool)($body['stream'] ?? false),
            'warnings' => $history === []
                ? []
                : ['Conversation history is preserved in request_meta; only the last user message seeds the consensus query.'],
        ];
    }

    // ----------------------------------------------------------------
    // Response rendering
    // ----------------------------------------------------------------

    /**
     * @param array<string,mixed> $run
     * @return array<string,mixed>
     */
    private function nativeRunResponse(array $run): array
    {
        $out = [
            'ok'         => true,
            'run_id'     => $run['run_id'],
            'session_id' => $run['session_id'],
            'job_id'     => $run['job_id'],
            'status'     => $run['status'],
            'created_at' => $run['created_at'],
            'events_url' => $run['urls']['events_url'] ?? null,
            'result_url' => $run['urls']['result_url'] ?? null,
            'cancel_url' => $run['urls']['cancel_url'] ?? null,
        ];
        if ($run['final_answer'] !== null) {
            $out['final_answer'] = $run['final_answer'];
        }
        if ($run['consensus'] !== null) {
            $out['consensus'] = $run['consensus'];
        }
        if ($run['model'] !== null) {
            $out['model'] = $run['model'];
        }
        if ($run['usage'] !== null) {
            $out['usage'] = $run['usage'];
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $run
     * @return array<string,mixed>
     */
    private function openAiRunResponse(array $run): array
    {
        $done = $run['status'] === 'completed' || $run['status'] === 'failed'
            || $run['status'] === 'cancelled';
        $choices = [];
        if ($done && $run['final_answer'] !== null && $run['final_answer'] !== '') {
            $choices[] = [
                'index'         => 0,
                'message'       => ['role' => 'assistant', 'content' => $run['final_answer']],
                'finish_reason' => $run['status'] === 'failed' ? 'error' : 'stop',
            ];
        }
        $out = [
            'id'      => 'chatcmpl_' . substr(hash('sha256', $run['run_id']), 0, 24),
            'object'  => 'chat.completion',
            'created' => $this->unixTs($run['created_at']),
            'model'   => $run['model'] ?? '',
            'choices' => $choices,
        ];
        if ($run['usage'] !== null) {
            $out['usage'] = [
                'prompt_tokens'     => (int)($run['usage']['prompt_tokens'] ?? 0),
                'completion_tokens' => (int)($run['usage']['completion_tokens'] ?? 0),
                'total_tokens'      => (int)($run['usage']['total_tokens'] ?? 0),
            ];
        }
        $out['revoap'] = [
            'run_id'     => $run['run_id'],
            'session_id' => $run['session_id'],
            'status'     => $run['status'],
            'events_url' => $run['urls']['events_url'] ?? null,
            'result_url' => $run['urls']['result_url'] ?? null,
            'cancel_url' => $run['urls']['cancel_url'] ?? null,
        ];
        return $out;
    }

    /**
     * @param array<string,mixed> $run
     * @return array<string,mixed>
     */
    private function anthropicRunResponse(array $run): array
    {
        $done = in_array($run['status'], ['completed', 'failed', 'cancelled'], true);
        $content = [];
        if ($done && $run['final_answer'] !== null && $run['final_answer'] !== '') {
            $content[] = ['type' => 'text', 'text' => $run['final_answer']];
        }
        $out = [
            'id'         => 'msg_' . substr(hash('sha256', $run['run_id']), 0, 24),
            'type'       => 'message',
            'role'       => 'assistant',
            'model'      => $run['model'] ?? '',
            'content'    => $content,
            'stop_reason'=> $done ? ($run['status'] === 'failed' ? 'error' : 'end_turn') : null,
            'usage'      => [
                'input_tokens'  => (int)($run['usage']['prompt_tokens'] ?? 0),
                'output_tokens' => (int)($run['usage']['completion_tokens'] ?? 0),
            ],
        ];
        $out['revoap'] = [
            'run_id'     => $run['run_id'],
            'session_id' => $run['session_id'],
            'status'     => $run['status'],
            'events_url' => $run['urls']['events_url'] ?? null,
            'result_url' => $run['urls']['result_url'] ?? null,
            'cancel_url' => $run['urls']['cancel_url'] ?? null,
        ];
        return $out;
    }

    // ----------------------------------------------------------------
    // Helpers
    // ----------------------------------------------------------------

    /**
     * @param array<string,mixed> $run Raw run record.
     * @return array{run_id:string,session_id:int,job_id:int|null,status:string,created_at:string|null,final_answer:string|null,consensus:array|null,model:string|null,usage:array|null,urls:array<string,mixed>}
     */
    private function normalizeRun(array $run): array
    {
        $runId = (string)($run['run_id'] ?? '');
        if ($runId === '') {
            $sessionId = (int)($run['session_id'] ?? 0);
            $runId = $sessionId > 0 ? 'run_' . $sessionId : 'run_' . bin2hex(random_bytes(4));
        }
        return [
            'run_id'       => $runId,
            'session_id'   => isset($run['session_id']) ? (int)$run['session_id'] : 0,
            'job_id'       => isset($run['job_id']) ? (int)$run['job_id'] : null,
            'status'       => (string)($run['status'] ?? 'queued'),
            'created_at'   => $run['created_at'] ?? null,
            'final_answer' => $run['final_answer'] ?? null,
            'consensus'    => is_array($run['consensus'] ?? null) ? $run['consensus'] : null,
            'model'        => isset($run['model']) ? (string)$run['model'] : null,
            'usage'        => is_array($run['usage'] ?? null) ? $run['usage'] : null,
            'urls'         => is_array($run['urls'] ?? null) ? $run['urls'] : [],
        ];
    }

    /** Convert an ISO-8601 timestamp to a unix second stamp. */
    private function unixTs(mixed $iso): int
    {
        if (is_string($iso) && $iso !== '') {
            $ts = strtotime($iso);
            if ($ts !== false) {
                return $ts;
            }
        }
        return time();
    }

    private static function openAiErrorType(int $status): string
    {
        return match ($status) {
            401 => 'authentication_error',
            403 => 'permission_error',
            404 => 'not_found_error',
            429 => 'rate_limit_error',
            400 => 'invalid_request_error',
            default => 'api_error',
        };
    }

    private static function anthropicErrorType(int $status): string
    {
        return match ($status) {
            401 => 'authentication_error',
            403 => 'permission_error',
            404 => 'not_found_error',
            429 => 'rate_limit_error',
            400, 422 => 'invalid_request_error',
            529 => 'overloaded_error',
            default => 'api_error',
        };
    }

    /**
     * Coerce a transport option into a durable scalar carrier. Arrays
     * are JSON-encoded so they survive the scalar-only `request_meta`
     * filter inside JobService.
     */
    private function scalarCarrier(mixed $value): string
    {
        if (is_array($value)) {
            $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return is_string($encoded) ? $encoded : '';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        return (string)$value;
    }

    /**
     * Content may be a plain string, a text-block list, or (for OpenAI)
     * an array of text parts. Flatten to a single string; empty/missing
     * content returns ''.
     */
    private static function flattenContent(mixed $content): string
    {
        if ($content === null) {
            return '';
        }
        if (is_string($content)) {
            return trim($content);
        }
        if (!is_array($content)) {
            return '';
        }
        $parts = [];
        foreach ($content as $block) {
            if (is_string($block)) {
                $parts[] = trim($block);
                continue;
            }
            if (!is_array($block)) {
                continue;
            }
            if (isset($block['text']) && is_string($block['text'])) {
                $parts[] = trim($block['text']);
            } elseif (isset($block['content']) && is_string($block['content'])) {
                $parts[] = trim($block['content']);
            }
        }
        $joined = trim(implode("\n\n", array_filter($parts, static fn(string $s): bool => $s !== '')));
        return $joined;
    }
}
