<?php
/**
 * ConnectorInterface: Universal contract every LLM provider must satisfy.
 *
 * Implementations MUST:
 *  - return a fully populated ChatResponse from chat() (totalTokens included),
 *  - report a stable lowercase provider slug from getProviderName(),
 *  - report availability based on key resolution + config 'active' flag.
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
use App\Connectors\Exceptions\UnsupportedOperationException;

interface ConnectorInterface
{
    /**
     * Send a chat completion request.
     *
     * @param  string $model    Provider-specific model identifier.
     * @param  array<int,array{role:string,content:string}> $messages Chat messages.
     * @param  array<string,mixed> $options Optional: temperature, max_tokens, top_p, stream.
     * @return ChatResponse Populated with content + full token stats.
     * @throws ConnectorException on transport or API error.
     */
    public function chat(string $model, array $messages, array $options = []): ChatResponse;

    /**
     * Create a text embedding vector.
     *
     * @return EmbedResponse
     * @throws UnsupportedOperationException When the provider does not expose embeddings.
     * @throws ConnectorException on transport or API error.
     */
    public function embed(string $model, string $text): EmbedResponse;

    /**
     * @return string[] Available model identifiers from this provider.
     * @throws ConnectorException on transport error.
     */
    public function listModels(): array;

    /** @return string Provider slug, lowercase ('bothub'|'openrouter'|...). */
    public function getProviderName(): string;

    /** @return bool True when key resolves and provider is marked active in config. */
    public function isAvailable(): bool;
}
