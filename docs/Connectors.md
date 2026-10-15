# Connectors

RevoAp talks to LLM providers through small connector classes that share
HTTP/cURL plumbing in `App\Connectors\AbstractConnector`. Every concrete
connector returns a `ChatResponse` value object that carries a normalized,
UI-ready set of statistics.

## Bundled connectors

| Slug         | Class                          | Default base URL                                | Embeddings |
|--------------|--------------------------------|-------------------------------------------------|------------|
| `openrouter` | `App\Connectors\OpenRouterConnector` | `https://openrouter.ai/api/v1`                  | unsupported (throws `UnsupportedOperationException`) |
| `bothub`     | `App\Connectors\BothubConnector`     | `https://bothub.chat/api/v2/openai/v1`          | OpenAI-compatible |

Both endpoints are OpenAI-compatible: BotHub's documentation states their
endpoints "fully match OpenAI" and the SDK example uses
`baseURL: "https://bothub.chat/api/v2/openai/v1"` together with
`openai.chat.completions.create`. OpenRouter exposes the same chat-completions
shape under `/api/v1/chat/completions`.

## Response parsing

`AbstractConnector::buildChatResponse()` extracts the standard OpenAI shape:

```jsonc
{
  "id":      "gen-…",                       // optional generation id (OpenRouter)
  "model":   "openai/gpt-4o-2024-08-06",    // server-resolved model
  "choices": [ { "message": { "content": "…" } } ],
  "usage": {
    "prompt_tokens":     123,
    "completion_tokens":  45,
    "total_tokens":      168,
    "cost":              0.000142,            // OpenRouter only
    "prompt_tokens_details":     { "cached_tokens": 5, "cache_write_tokens": 0 },
    "completion_tokens_details": { "reasoning_tokens": 2 },
    "cost_details":              { "upstream_inference_cost": 0.000142 }
  }
}
```

The connector populates the following fields on `ChatResponse`:

| Field             | Source                                                  |
|-------------------|---------------------------------------------------------|
| `model`           | The model id the caller requested.                      |
| `actualModel`     | `response.model` (server-resolved id, may be more specific). |
| `provider`        | The connector's `providerSlug`.                         |
| `promptTokens`    | `usage.prompt_tokens`                                   |
| `completionTokens`| `usage.completion_tokens`                               |
| `totalTokens`     | `usage.total_tokens`, reconciled to `prompt + completion` if mismatched. The raw provider total is preserved verbatim under `rawResponse['usage']['total_tokens']`. |
| `cost`            | `usage.cost` (OpenRouter)                               |
| `cachedTokens`    | `usage.prompt_tokens_details.cached_tokens`             |
| `reasoningTokens` | `usage.completion_tokens_details.reasoning_tokens`      |
| `generationId`    | top-level `id`                                          |
| `usageDetails`    | the three `*_details` objects, kept for the debug pane. |
| `latencyMs`       | wall-clock measured around the cURL call.               |

OpenRouter automatically returns `usage` in every non-streaming response —
no `usage: {include: true}` opt-in is required. For streaming chat
completions, OpenRouter delivers `usage` only in the final SSE event;
RevoAp does not consume the streaming endpoint.

## Fallback estimation

If the provider does not return a `usage` block (older models, network
trimmed bodies, exotic providers), the connector falls back to
`TokenHelper::estimateMessageTokens()` for prompts and
`TokenHelper::estimateTokens()` for the completion text. The resulting
`ChatResponse` carries `tokensEstimated = true` so the UI can flag the row
("estimated" suffix on the Total tokens stat). The
`promptTokens + completionTokens == totalTokens` invariant is always
maintained.

## Validating model IDs

Provider model catalogs change. A configured model id that has been retired
returns HTTP 404 ("No endpoints found" on OpenRouter) and the L1 step is
marked `failed`. Since v1.0.5, `PipelineEngine::diagnoseConnectorError()`
attaches a hint to every `L1_FAILED` event pointing the operator at the
provider's `/models` endpoint. Verify your active models against:

```bash
# OpenRouter — public, no key required
curl -s https://openrouter.ai/api/v1/models | jq -r '.data[].id' | sort

# BotHub — requires a valid Bearer key
curl -s -H "Authorization: Bearer $BOTHUB_KEY" \
     https://bothub.chat/api/v2/openai/v1/models \
   | jq -r '.data[].id' | sort
```

Since v1.8.15 the catalogue lives in dedicated files:

- `config/models/openrouter.php` — top 10 OpenRouter slugs.
- `config/models/bothub.php`     — top 10 BotHub slugs.
- `config/models/direct.php`     — top 5 direct providers (OpenAI,
  Anthropic, Gemini, DeepSeek, xAI/Grok).

Each row carries `provider`, `model`, `label`, `rank`, `category`,
`active`, and `default`. `Config::load()` walks the `model_files`
key in `config.php` and merges each file into the runtime `models`
list. If you change them, re-run `tests/SmokeTest.php` — it asserts
that the deprecated slugs `google/gemini-flash-1.5`,
`anthropic/claude-3.5-sonnet`, and `deepseek/deepseek-chat-v3` are
NOT in the active set.

### Currently-shipped active defaults (v1.8.15)

| Provider     | Model id                                |
|--------------|-----------------------------------------|
| `openrouter` | `openai/gpt-chat-latest`                |
| `openrouter` | `openai/gpt-5.5`                        |
| `openrouter` | `openai/gpt-5.5-pro`                    |
| `openrouter` | `anthropic/claude-sonnet-4.6`           |
| `openrouter` | `~anthropic/claude-sonnet-latest`       |
| `openrouter` | `google/gemini-3.1-pro-preview`         |
| `openrouter` | `google/gemini-3.1-flash-latest`        |
| `openrouter` | `deepseek/deepseek-v4-pro`              |

BotHub rows (e.g. `gpt-5.5`, `claude-sonnet-4.6`) ship as inactive
because BotHub access is a paid add-on; flip the row in
`config/models/bothub.php` (or via Settings) once a key is configured.
Direct-provider rows (`openai`, `anthropic`, `gemini`, `deepseek`,
`xai`) likewise ship inactive — turn `connectors.{provider}.active`
on and supply a key first.

## Wiring an additional provider

1. Subclass `AbstractConnector`, set `providerSlug` and `defaultBaseUrl`.
2. Override `headers()` if the API needs extra auth or attribution headers.
3. Drop the file in `src/Connectors/`. `ConnectorRegistry` discovers it by
   classname, instantiates it, and the front controller exposes it under
   `/api/connectors`.
