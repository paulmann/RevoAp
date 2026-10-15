# Module: Connectors

## Purpose
Pluggable LLM provider integrations. Every supported provider lives as a
single `*Connector.php` file; `ConnectorRegistry` auto-discovers them at
runtime and the rest of the application talks to providers only through
`ConnectorInterface`.

## Files
| File | Class/Interface | Responsibility |
|------|-----------------|----------------|
| ConnectorInterface.php | `App\Connectors\ConnectorInterface` | Universal contract for chat/embed/listModels. |
| ChatResponse.php       | `App\Connectors\ChatResponse`       | Immutable VO for chat completions + token stats. |
| EmbedResponse.php      | `App\Connectors\EmbedResponse`      | Immutable VO for embeddings. |
| AbstractConnector.php  | `App\Connectors\AbstractConnector`  | Shared cURL plumbing, retry, key resolution. |
| BothubConnector.php    | `App\Connectors\BothubConnector`    | BotHub.chat OpenAI-compatible client. |
| OpenRouterConnector.php| `App\Connectors\OpenRouterConnector`| OpenRouter.ai client (no embeddings). |
| ConnectorRegistry.php  | `App\Connectors\ConnectorRegistry`  | Drop-in auto-discovery of `*Connector.php` files. |
| Exceptions/*.php       | `*Exception`                        | `ConnectorException`, `ConnectorKeyNotFoundException`, `UnsupportedOperationException`. |
| HOW_TO_CREATE_CONNECTOR.md | —                               | Authoritative LLM guide for adding a new provider. |

## Public API (selected)
### ConnectorInterface
- `chat(string $model, array $messages, array $options = []): ChatResponse`
- `embed(string $model, string $text): EmbedResponse`
- `listModels(): array<int,string>`
- `getProviderName(): string`  — lowercase slug
- `isAvailable(): bool`

### ChatResponse (readonly)
- `content`, `model`, `provider`
- `promptTokens`, `completionTokens`, `totalTokens`
- `latencyMs`, `rawResponse`, `tokensEstimated`
- **Invariant**: `totalTokens === promptTokens + completionTokens`.

### ConnectorRegistry
- `all(): ConnectorInterface[]` — only available connectors
- `discovered(): ConnectorInterface[]` — every discovered class regardless of availability
- `getByProvider(string): ?ConnectorInterface`
- `hasProvider(string): bool`
- `skipped(): string[]` — diagnostics for files that failed to register

## Invariants & Constraints
- API keys never appear in logs or error messages.
- Token counts on `ChatResponse` are reconciled to `promptTokens + completionTokens`.
- Registry skips abstract classes and constructors with required arguments.
- `OpenRouterConnector::embed` must throw `UnsupportedOperationException`.

## Integration Points
- Called by: `Pipeline\PipelineEngine`, `Skills\SkillAutoCreator`, `Http\Controllers`.
- Calls: `Core\Config`, `Helpers\*`.

## LLM Extension Guide
See [`HOW_TO_CREATE_CONNECTOR.md`](HOW_TO_CREATE_CONNECTOR.md). When in
doubt, prefer extending `AbstractConnector` and overriding only what is
genuinely provider-specific (headers, model list parsing).
