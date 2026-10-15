<?php
/**
 * AbstractConnector: Shared HTTP/cURL plumbing for OpenAI-compatible providers.
 *
 * Provides:
 *  - Lazy API-key resolution via Config::getConnectorKey().
 *  - cURL-based JSON POST/GET with retry and exponential backoff.
 *  - Standardized building of ChatResponse from OpenAI-style payloads.
 *
 * Concrete connectors override the small set of provider-specific hooks
 * (base URL, request headers, model-list parsing) and rely on the inherited
 * methods for the heavy lifting.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-05-05
 */
declare(strict_types=1);

namespace App\Connectors;

use App\Connectors\Exceptions\ConnectorException;
use App\Connectors\Exceptions\ConnectorKeyNotFoundException;
use App\Connectors\PromptCache\Plan;
use App\Connectors\PromptCache\Planner;
use App\Connectors\PromptCache\Policy;
use App\Core\Config;
use App\Core\ErrorDiagnostics;
use App\Core\Logger;
use App\Helpers\JsonHelper;
use App\Helpers\TimeHelper;
use App\Helpers\TokenHelper;
use App\Models\ModelId;

abstract class AbstractConnector implements ConnectorInterface
{
    /**
     * Ceiling for a per-call `options['timeout']` override. The option is
     * caller-controlled, so an absurd value must not be able to disable
     * the transport guard for a request.
     */
    private const MAX_CALL_TIMEOUT_SECONDS = 3600;

    /**
     * Transport-only chat options: consumed by the connector and
     * stripped from the provider payload. `stream` is dropped because
     * the parallel path does not parse streamed deltas yet; `timeout`
     * is the per-call transport budget (see buildChatRequest()).
     */
    private const TRANSPORT_ONLY_OPTIONS = ['stream', 'timeout'];

    private ?string $cachedKey = null;
    private bool    $keyResolved = false;

    public function __construct(
        protected readonly string $providerSlug,
        protected readonly string $defaultBaseUrl,
        protected readonly int    $defaultTimeoutChat = 120,
        protected readonly int    $defaultTimeoutEmbed = 30,
        protected readonly int    $defaultMaxRetries = 3,
    ) {}

    public function getProviderName(): string
    {
        return $this->providerSlug;
    }

    public function isAvailable(): bool
    {
        $cfg = Config::get("connectors.{$this->providerSlug}", []);
        if (!is_array($cfg) || ($cfg['active'] ?? false) !== true) {
            return false;
        }
        try {
            $k = $this->resolveKey();
            return $k !== '';
        } catch (ConnectorKeyNotFoundException) {
            return false;
        }
    }

    /**
     * Default chat() implementation for OpenAI-compatible /chat/completions.
     *
     * @param  array<int,array{role:string,content:string}> $messages
     * @param  array<string,mixed> $options
     */
    public function chat(string $model, array $messages, array $options = []): ChatResponse
    {
        $build   = $this->buildChatRequest($model, $messages, $options);
        $started = TimeHelper::now();
        $raw     = $this->httpJson('POST', $build['url'], $build['payload'], $build['timeout'], $model);
        $latency = TimeHelper::elapsedMs($started);

        return $this->buildChatResponse($model, $messages, $raw, $latency);
    }

    /**
     * Pre-build the URL/headers/payload for a chat request without sending it.
     * Used by ParallelChatDispatcher to drive concurrent curl_multi handles
     * while keeping the connector authoritative on payload/headers shape.
     *
     * `options['timeout']` is a per-call transport override: it wins over
     * `timeout_chat()` for this request only and is never forwarded to the
     * provider as a request parameter. Callers that omit the key keep the
     * configured connector default.
     *
     * Throws before the request is built when the model is a batch-only
     * identifier — see {@see self::assertSynchronousModel()}.
     *
     * @param  array<int,array{role:string,content:string}> $messages
     * @param  array<string,mixed> $options
     * @return array{url:string,headers:string[],payload:array<string,mixed>,timeout:int}
     * @throws ConnectorException on a batch-only model id
     */
    /**
     * The same call as {@see self::chat()}, delivered as it is generated.
     *
     * The provider dialect was verified against the live endpoint before this
     * was written: OpenAI-compatible `data: {...}` frames, `choices[0].delta
     * .content`, terminated by `data: [DONE]`. The request builder treats
     * `stream` as a transport option and strips it from the payload, so it is
     * put back on the wire here - without it the provider answers with one
     * buffered body, which is exactly the behaviour this method exists to
     * replace.
     *
     * The return value is a normal ChatResponse built from what streamed in, so
     * every caller keeps the accounting it already has (tokens, cost, cache
     * statistics). A stream that dies mid-flight keeps the text it delivered
     * rather than throwing it away; only a stream that produced nothing throws,
     * and it throws the same ConnectorException the buffered path would, so the
     * caller can fall back knowingly.
     *
     * @param callable(string):void|null $onDelta
     * @throws ConnectorException
     */
    public function chatStream(string $model, array $messages, array $options = [], ?callable $onDelta = null): ChatResponse
    {
        $this->assertSynchronousModel($model);
        $request  = $this->buildChatRequest($model, $messages, $options);
        $url      = (string)($request['url'] ?? rtrim($this->baseUrl(), '/') . '/chat/completions');
        $headers  = is_array($request['headers'] ?? null) ? $request['headers'] : $this->headers();
        $payload  = is_array($request['payload'] ?? null) ? $request['payload'] : [];
        $timeout  = (int)($request['timeout'] ?? $this->timeoutChat());

        $payload['stream'] = true;
        if (!isset($payload['stream_options'])) {
            // Asked for, and ignored by providers that do not know it.
            $payload['stream_options'] = ['include_usage' => true];
        }

        $parser = new SseDeltaParser();
        $thrown = null;
        $started = \App\Helpers\TimeHelper::now();

        $ch = curl_init();
        if ($ch === false) {
            throw new ConnectorException('Failed to init cURL');
        }
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => JsonHelper::encode($payload),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(15, $timeout),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_WRITEFUNCTION  => static function ($handle, string $data) use ($parser, $onDelta, &$thrown): int {
                try {
                    $frame = $parser->push($data);
                    if ($frame['text'] !== '' && $onDelta !== null) {
                        $onDelta($frame['text']);
                    }
                } catch (\Throwable $e) {
                    $thrown = $e;
                    return 0;
                }
                return strlen($data);
            },
        ]);
        $executed = curl_exec($ch);
        $code     = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);
        $latency = \App\Helpers\TimeHelper::elapsedMs($started);

        if ($thrown !== null) {
            throw $thrown instanceof ConnectorException ? $thrown : new ConnectorException($thrown->getMessage());
        }
        if ($executed === false || $curlErr !== '') {
            if ($parser->text() === '') {
                throw new ConnectorException("{$this->providerSlug}: stream failed ({$curlErr})");
            }
            // Partial answer: keep it, the caller's accounting stays honest.
        } elseif ($code >= 400) {
            $body = $parser->text() !== '' ? $parser->text() : 'no body';
            throw new ConnectorException(
                ErrorDiagnostics::connectorHttpMessage($this->providerSlug, $code, $body, $model, 500)
            );
        } elseif (!$parser->isDone() && $parser->text() === '') {
            throw new ConnectorException("{$this->providerSlug}: the stream ended without any content");
        }

        $raw = [
            'id'      => $parser->generationId(),
            'choices' => [[
                'index'         => 0,
                'message'       => ['role' => 'assistant', 'content' => $parser->text()],
                'finish_reason' => $parser->finishReason() ?? 'stop',
            ]],
        ];
        if ($parser->usage() !== null) {
            $raw['usage'] = $parser->usage();
        }

        return $this->buildChatResponse($model, $messages, $raw, $latency);
    }
    public function buildChatRequest(string $model, array $messages, array $options = []): array
    {
        $this->assertSynchronousModel($model);
        return $this->assembleChatRequest($model, $messages, $options);
    }

    /**
     * The same request, built for the ASYNCHRONOUS Batch API surface.
     *
     * A separate method rather than a flag on {@see self::buildChatRequest()}
     * on purpose. The guard it skips is about *which endpoint* the payload is
     * going to, not about the identifier being wrong: a `:batch` id sent to
     * `/chat/completions` is an id that endpoint does not serve, while the
     * same id as the `body.model` of a Batch API row is exactly how the
     * asynchronous surface is addressed — {@see ModelId::syncCallRejection()}
     * says so in its own message ("use the plain id or the batch path").
     *
     * Two properties follow from it being a separate entry point:
     *
     *   1. `buildChatRequest()` keeps its exact signature and behaviour, so
     *      every existing caller — `chat()`, `embed()`, the parallel
     *      dispatcher, and any connector subclass that overrides it — is
     *      untouched, and the fail-fast refusal cannot be switched off by a
     *      value that travels in the request.
     *   2. There is no second copy of the payload rules: both methods return
     *      whatever {@see self::assembleChatRequest()} produces, so a batch
     *      body and a synchronous body can never drift apart.
     *
     * @param  array<int,array{role:string,content:string}> $messages
     * @param  array<string,mixed> $options
     * @return array{url:string,headers:string[],payload:array<string,mixed>,timeout:int}
     */
    public function buildBatchChatRequest(string $model, array $messages, array $options = []): array
    {
        return $this->assembleChatRequest($model, $messages, $options);
    }

    /**
     * Assemble the URL/headers/payload/timeout for a chat-completions call.
     * Shared by the synchronous and the asynchronous entry points above, and
     * the only place the payload shape is defined.
     *
     * @param  array<int,array{role:string,content:string}> $messages
     * @param  array<string,mixed> $options
     * @return array{url:string,headers:string[],payload:array<string,mixed>,timeout:int}
     */
    private function assembleChatRequest(string $model, array $messages, array $options): array
    {
        $payload = array_merge($options, [
            'model'    => $model,
            'messages' => $messages,
        ]);
        // Transport concerns are not provider parameters.
        foreach (self::TRANSPORT_ONLY_OPTIONS as $key) {
            unset($payload[$key]);
        }
        // Prompt caching is applied here, at the single funnel both the
        // synchronous and the asynchronous surface go through, so a batch
        // body and a chat body can never disagree about the breakpoint
        // placement. A connector that has no documented caching surface
        // returns null from the hook and the payload is byte-identical to
        // what it always was.
        $policy = $this->promptCachePolicy();
        if ($policy !== null) {
            $plan = (new Planner($policy))->plan($model, $payload['messages']);
            $payload['messages'] = $plan->messages;
            if ($plan->includeUsage && !array_key_exists('usage', $payload)) {
                $payload['usage'] = ['include' => true];
            }
            if ($plan->applied || $plan->reason !== Plan::REASON_AUTOMATIC_FAMILY) {
                // Automatic families are the expected, silent case — they
                // would otherwise log on every single request. The info
                // channel is gated on app.debug (see Logger::enabled()), so
                // this costs nothing in production.
                Logger::info('connector.prompt_cache', $plan->logContext() + [
                    'provider' => $this->providerSlug,
                    'model'    => $model,
                ]);
            }
        }
        return [
            'url'     => rtrim($this->baseUrl(), '/') . '/chat/completions',
            'headers' => $this->headers(),
            'payload' => $payload,
            'timeout' => $this->callTimeout($options['timeout'] ?? null),
        ];
    }

    /**
     * Prompt-caching policy for this provider, or null when it has no
     * documented caching surface.
     *
     * Only {@see BothubConnector} overrides this: prompt caching
     * (`cache_control` breakpoints, `usage.include`, `cache_discount`) is
     * a Bothub API feature, so an OpenRouter request must keep exactly the
     * shape it had before this feature existed.
     */
    protected function promptCachePolicy(): ?Policy
    {
        return null;
    }

    /**
     * The provider base URL, resolved through the same config lookup the
     * connector itself uses, without a trailing slash.
     *
     * Exposed for auxiliary clients that speak to a *different* endpoint of
     * the same provider — today the Batch API client, whose routes
     * (`/files`, `/batches`, `/batches/models`) sit under the same base as
     * `/chat/completions`. They must not re-derive it from config: a
     * deployment that overrides `connectors.<provider>.base_url` has to
     * move both surfaces together, or a batch would be submitted to a host
     * the chat path never talks to.
     */
    public function endpointBase(): string
    {
        return rtrim($this->baseUrl(), '/');
    }

    /**
     * The resolved API key, through the connector's own resolver chain
     * (`Config::getConnectorKey`, including the per-model `.{model}.key`
     * fallback and any operator-configured `key_files` aliases).
     *
     * Same reason as {@see self::endpointBase()}: one resolver, so a key
     * rotation cannot leave the batch surface authenticating with a stale
     * credential. Never log the return value — pass it only into a header.
     *
     * @throws ConnectorKeyNotFoundException when no key resolves.
     */
    public function credential(): string
    {
        return $this->resolveKey();
    }

    /**
     * The connector's own header set, minus the body `Content-Type`.
     *
     * The Batch API needs the provider's real auth headers — including any
     * a concrete connector adds in its {@see self::headers()} override, so
     * this filters that method's output rather than duplicating it — but it
     * has to choose its own `Content-Type` per request: a multipart upload
     * gets its content type from curl's boundary, and hardcoding
     * `application/json` there would silently corrupt the file upload.
     *
     * @return string[] Header lines: 'Name: Value'
     */
    public function authHeaderLines(): array
    {
        $out = [];
        foreach ($this->headers() as $line) {
            if (stripos($line, 'content-type:') === 0) {
                continue;
            }
            $out[] = $line;
        }
        return $out;
    }

    /**
     * Effective timeout for one chat request: a positive per-call
     * `options['timeout']` wins over the configured connector default.
     * Non-numeric / non-positive values fall through to the default, and
     * an oversized value is clamped to {@see self::MAX_CALL_TIMEOUT_SECONDS}.
     */
    private function callTimeout(mixed $override): int
    {
        if (is_int($override) || is_float($override) || (is_string($override) && is_numeric($override))) {
            $seconds = (int)$override;
            if ($seconds > 0) {
                return min($seconds, self::MAX_CALL_TIMEOUT_SECONDS);
            }
        }
        return $this->timeoutChat();
    }

    /**
     * Public wrapper around buildChatResponse() so external dispatchers
     * (ParallelChatDispatcher) can finish assembly after running the
     * network IO themselves.
     *
     * @param  array<int,array{role:string,content:string}> $messages
     * @param  array<string,mixed> $raw
     */
    public function buildChatResponseFromRaw(string $model, array $messages, array $raw, float $latency): ChatResponse
    {
        return $this->buildChatResponse($model, $messages, $raw, $latency);
    }

    /**
     * Refuse a batch-only identifier before any request is built.
     *
     * A `:batch` id names the asynchronous surface of a model (Batch API:
     * upload → create → poll → download), not a chat model. Sent to
     * `/chat/completions` it is simply an id the provider does not serve,
     * so BotHub answers 404 `MODEL_NOT_FOUND` — a message that reads like a
     * typo in the operator's own configuration, names the wrong cause, and
     * costs a round trip to find out. The guard turns that into one local,
     * self-explaining failure, and it lives here (rather than in one
     * connector) because every OpenAI-compatible connector shares this
     * path — `chat()` and ParallelChatDispatcher both go through
     * {@see self::buildChatRequest()}.
     *
     * @throws ConnectorException when the model is batch-only
     */
    private function assertSynchronousModel(string $model): void
    {
        $rejection = ModelId::syncCallRejection($model);
        if ($rejection !== null) {
            throw new ConnectorException($rejection);
        }
    }

    public function embed(string $model, string $text): EmbedResponse
    {
        $this->assertSynchronousModel($model);
        $url     = rtrim($this->baseUrl(), '/') . '/embeddings';
        $started = TimeHelper::now();
        $raw     = $this->httpJson('POST', $url, ['model' => $model, 'input' => $text], $this->timeoutEmbed(), $model);
        $latency = TimeHelper::elapsedMs($started);

        $vector = [];
        if (isset($raw['data'][0]['embedding']) && is_array($raw['data'][0]['embedding'])) {
            foreach ($raw['data'][0]['embedding'] as $f) {
                $vector[] = (float)$f;
            }
        }
        $promptTokens = (int)($raw['usage']['prompt_tokens'] ?? 0);
        $totalTokens  = (int)($raw['usage']['total_tokens']  ?? $promptTokens);
        if ($totalTokens === 0) {
            $totalTokens = TokenHelper::estimateTokens($text);
            $promptTokens = $totalTokens;
        }
        return new EmbedResponse($vector, $model, $this->providerSlug, $promptTokens, $totalTokens, $latency, $raw);
    }

    /**
     * Build a normalized ChatResponse from an OpenAI-compatible /chat/completions
     * payload. Both OpenRouter and BotHub return the standard shape:
     *
     *   {
     *     "id": "gen-...",                      // optional, OpenRouter
     *     "model": "openai/gpt-4o-2024-08-06",  // server-resolved model id
     *     "choices": [ { "message": { "content": "..." } } ],
     *     "usage": {
     *        "prompt_tokens": N, "completion_tokens": N, "total_tokens": N,
     *        "prompt_tokens_details":     { "cached_tokens": N, ... },     // optional
     *        "completion_tokens_details": { "reasoning_tokens": N, ... }, // optional
     *        "cost": 0.000123, "cost_details": { ... },                   // OpenRouter
     *        "cache_read_input_tokens": N,                                // Anthropic-like
     *        "cache_creation_input_tokens": N                             // Anthropic-like
     *     },
     *     "cache_discount": 0.00042            // Bothub: how much the cache saved
     *   }
     *
     * If `usage` is absent we fall back to TokenHelper estimation and set
     * tokensEstimated=true so callers/UI can flag the row. Cache fields are
     * read independently of that fallback and stay null when unreported —
     * an estimated token count says nothing about the cache.
     *
     * @param  array<int,array{role:string,content:string}> $messages
     * @param  array<string,mixed> $raw
     */
    protected function buildChatResponse(string $model, array $messages, array $raw, float $latency): ChatResponse
    {
        $content = '';
        if (isset($raw['choices'][0]['message']['content'])) {
            $content = (string)$raw['choices'][0]['message']['content'];
        } elseif (isset($raw['choices'][0]['text'])) {
            $content = (string)$raw['choices'][0]['text'];
        }

        $usage = is_array($raw['usage'] ?? null) ? $raw['usage'] : [];
        $hasUsage = isset($usage['prompt_tokens']) || isset($usage['completion_tokens']) || isset($usage['total_tokens']);

        $promptTokens     = (int)($usage['prompt_tokens']     ?? 0);
        $completionTokens = (int)($usage['completion_tokens'] ?? 0);
        $totalTokens      = (int)($usage['total_tokens']      ?? 0);
        $estimated        = false;

        if (!$hasUsage || ($promptTokens === 0 && $completionTokens === 0 && $totalTokens === 0)) {
            $promptTokens     = TokenHelper::estimateMessageTokens($messages);
            $completionTokens = TokenHelper::estimateTokens($content);
            $totalTokens      = $promptTokens + $completionTokens;
            $estimated        = true;
        } elseif ($totalTokens === 0) {
            // Provider reported a breakdown but no total — derive it.
            $totalTokens = $promptTokens + $completionTokens;
        } elseif ($totalTokens !== $promptTokens + $completionTokens) {
            // Inconsistent breakdown — trust the breakdown for the invariant
            // and preserve the provider's raw total in rawResponse for audit.
            $totalTokens = $promptTokens + $completionTokens;
        }

        $promptDetails     = is_array($usage['prompt_tokens_details']     ?? null) ? $usage['prompt_tokens_details']     : [];
        $completionDetails = is_array($usage['completion_tokens_details'] ?? null) ? $usage['completion_tokens_details'] : [];
        $costDetails       = is_array($usage['cost_details']              ?? null) ? $usage['cost_details']              : [];

        $cost = isset($usage['cost']) && is_numeric($usage['cost']) ? (float)$usage['cost'] : null;
        $cachedTokens    = isset($promptDetails['cached_tokens'])      && is_numeric($promptDetails['cached_tokens'])      ? (int)$promptDetails['cached_tokens']      : null;
        $reasoningTokens = isset($completionDetails['reasoning_tokens']) && is_numeric($completionDetails['reasoning_tokens']) ? (int)$completionDetails['reasoning_tokens'] : null;
        $generationId    = isset($raw['id']) && is_string($raw['id']) && $raw['id'] !== '' ? $raw['id'] : null;

        // --- prompt cache statistics, both dialects ----------------------
        // OpenAI-like : usage.prompt_tokens_details.cached_tokens  (read)
        // Anthropic-like: usage.cache_read_input_tokens             (read)
        //                 usage.cache_creation_input_tokens         (write)
        // The read side is ONE number whichever dialect reported it, so an
        // aggregate over models can never count the same tokens twice.
        $cacheRead   = self::intOrNull($usage['cache_read_input_tokens'] ?? null);
        $cacheWrite  = self::intOrNull($usage['cache_creation_input_tokens'] ?? null);
        if ($cachedTokens === null) {
            $cachedTokens = $cacheRead;
        }
        // `cache_discount` is a Bothub body field ("сколько сэкономлено").
        // Read from the body first, fall back to a nested `usage` copy so a
        // provider that mirrors it there is still understood. It is signed:
        // a cache WRITE at 1.25× of the input price comes back negative.
        $cacheDiscount = self::floatOrNull($raw['cache_discount'] ?? null)
                      ?? self::floatOrNull($usage['cache_discount'] ?? null);

        $actualModel = isset($raw['model']) && is_string($raw['model']) && $raw['model'] !== '' ? $raw['model'] : null;

        $usageDetails = [];
        if ($promptDetails !== [])     { $usageDetails['prompt_tokens_details']     = $promptDetails; }
        if ($completionDetails !== []) { $usageDetails['completion_tokens_details'] = $completionDetails; }
        if ($costDetails !== [])       { $usageDetails['cost_details']              = $costDetails; }
        if ($cacheRead !== null || $cacheWrite !== null) {
            $usageDetails['cache_input_tokens'] = array_filter([
                'read'  => $cacheRead,
                'write' => $cacheWrite,
            ], static fn(?int $v): bool => $v !== null);
        }

        return new ChatResponse(
            content:          $content,
            model:            $model,
            provider:         $this->providerSlug,
            promptTokens:     $promptTokens,
            completionTokens: $completionTokens,
            totalTokens:      $totalTokens,
            latencyMs:        $latency,
            rawResponse:      $raw,
            tokensEstimated:  $estimated,
            actualModel:      $actualModel,
            cost:             $cost,
            cachedTokens:     $cachedTokens,
            reasoningTokens:  $reasoningTokens,
            generationId:     $generationId,
            usageDetails:     $usageDetails,
            cacheWriteTokens: $cacheWrite,
            cacheDiscount:    $cacheDiscount,
        );
    }

    /** Numeric value as int, or null when absent / non-numeric. */
    protected static function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int)$value : null;
    }

    /** Numeric value as float, or null when absent / non-numeric. */
    protected static function floatOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? (float)$value : null;
    }

    /**
     * Override in concrete connector to add Authorization, X-Title, etc.
     * The base implementation provides Bearer auth + JSON content type.
     *
     * @return string[] Header lines: 'Name: Value'
     */
    protected function headers(): array
    {
        return [
            'Authorization: Bearer ' . $this->resolveKey(),
            'Content-Type: application/json',
            'Accept: application/json',
        ];
    }

    /**
     * @param array<string,mixed>|null $body
     * @param string|null $model The model this call is for, when there is
     *                           one. Threaded through so a failed request
     *                           can name it (see httpErrorMessage()):
     *                           "provider HTTP 404 [MODEL_NOT_FOUND] for
     *                           model "gpt-5.6": …" is actionable, while
     *                           the old bare "HTTP 404" left the operator
     *                           guessing which of the selected models was
     *                           rejected.
     * @return array<string,mixed>
     * @throws ConnectorException
     */
    protected function httpJson(string $method, string $url, ?array $body, int $timeout, ?string $model = null): array
    {
        $attempts = max(1, $this->maxRetries());
        $lastErr  = null;

        for ($i = 0; $i < $attempts; $i++) {
            $ch = curl_init();
            if ($ch === false) {
                throw new ConnectorException('Failed to init cURL');
            }
            $opts = [
                CURLOPT_URL            => $url,
                CURLOPT_CUSTOMREQUEST  => $method,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => $this->headers(),
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_CONNECTTIMEOUT => min(15, $timeout),
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 3,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ];
            if ($body !== null) {
                $opts[CURLOPT_POSTFIELDS] = JsonHelper::encode($body);
            }
            curl_setopt_array($ch, $opts);
            $raw = curl_exec($ch);
            $err = curl_error($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($raw === false || $err !== '') {
                $lastErr = "cURL error: {$err}";
                $this->backoff($i);
                continue;
            }
            // Retry on 429 + 5xx
            if ($code === 429 || ($code >= 500 && $code < 600)) {
                $lastErr = ErrorDiagnostics::connectorHttpMessage($this->providerSlug, $code, (string)$raw, $model, 200);
                $this->backoff($i);
                continue;
            }
            if ($code >= 400) {
                throw new ConnectorException(
                    ErrorDiagnostics::connectorHttpMessage($this->providerSlug, $code, (string)$raw, $model, 500)
                );
            }
            $decoded = JsonHelper::tryDecode((string)$raw);
            if (!is_array($decoded)) {
                throw new ConnectorException("{$this->providerSlug}: invalid JSON response");
            }
            return $decoded;
        }
        throw new ConnectorException("{$this->providerSlug}: request failed after {$attempts} attempts ({$lastErr})");
    }

    protected function backoff(int $attemptIndex): void
    {
        $delaySec = (1 << $attemptIndex); // 1, 2, 4 ...
        $jitterMs = random_int(0, 250);
        usleep($delaySec * 1_000_000 + $jitterMs * 1_000);
    }

    /** Resolved API key, cached after first lookup. */
    protected function resolveKey(): string
    {
        if (!$this->keyResolved) {
            $this->cachedKey   = Config::getConnectorKey($this->providerSlug);
            $this->keyResolved = true;
        }
        return (string)$this->cachedKey;
    }

    protected function baseUrl(): string
    {
        $cfg = Config::get("connectors.{$this->providerSlug}.base_url", $this->defaultBaseUrl);
        return is_string($cfg) && $cfg !== '' ? $cfg : $this->defaultBaseUrl;
    }

    protected function timeoutChat(): int
    {
        $v = (int)Config::get("connectors.{$this->providerSlug}.timeout_chat", $this->defaultTimeoutChat);
        return $v > 0 ? $v : $this->defaultTimeoutChat;
    }

    protected function timeoutEmbed(): int
    {
        $v = (int)Config::get("connectors.{$this->providerSlug}.timeout_embed", $this->defaultTimeoutEmbed);
        return $v > 0 ? $v : $this->defaultTimeoutEmbed;
    }

    protected function maxRetries(): int
    {
        $v = (int)Config::get("connectors.{$this->providerSlug}.max_retries", $this->defaultMaxRetries);
        return $v > 0 ? $v : $this->defaultMaxRetries;
    }
}
