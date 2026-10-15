# How to Create a New RevoAp LLM Connector

> Audience: an LLM (or human) tasked with adding support for a new LLM
> provider to RevoAp. This guide is the authoritative checklist.
> If the spec in `TECHNICAL_SPECIFICATION.md` and this file disagree,
> the spec wins — update this guide afterward.

RevoAp's connector system is **drop-in**: place a new
`{Provider}Connector.php` file into `src/Connectors/` and add a matching
`config.php` entry — no other code edits are required.
`ConnectorRegistry` will auto-discover it on the next request.

---

## 1. Step-by-step Checklist

1. **Pick a provider slug**: lowercase, alphanumeric (e.g. `mistral`,
   `together`, `groq`).
2. **Create the file** `src/Connectors/{ProviderName}Connector.php`
   (PascalCase class name, e.g. `MistralConnector`).
3. **Extend `AbstractConnector`** so you inherit retry, key resolution,
   timeouts and ChatResponse construction.
4. **Pass provider details to `parent::__construct`**: provider slug,
   default base URL, timeouts, retry count.
5. **Implement `listModels(): string[]`** — usually a `GET /models` call.
6. **Override `headers()` only if** the provider needs extra headers
   (Referer, custom auth, etc.).
7. **Override `embed()` only if** the provider does not expose embeddings
   (throw `UnsupportedOperationException`) **or** uses a non-standard schema.
8. **Add a `config.php` entry** under `connectors.{slug}` (see template
   below).
9. **(Optional) Drop a `.{slug}.key` file** in the project root if you do
   not want to embed the API key in `config.php`.
10. **Verify `isAvailable()`** returns `true` after configuration.
11. **Smoke-test** by calling `chat()` with a known model identifier and
    confirming `ChatResponse.totalTokens > 0` and content is non-empty.

---

## 2. Annotated Code Template

```php
<?php
/**
 * MyProviderConnector: Brief description.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    YYYY-MM-DD
 */
declare(strict_types=1);

namespace App\Connectors;

use App\Connectors\Exceptions\ConnectorException;

final class MyProviderConnector extends AbstractConnector
{
    public function __construct()
    {
        parent::__construct(
            providerSlug:        'myprovider',                          // lowercase slug
            defaultBaseUrl:      'https://api.myprovider.example/v1',   // OpenAI-compatible
            defaultTimeoutChat:  120,
            defaultTimeoutEmbed: 30,
            defaultMaxRetries:   3,
        );
    }

    /** @return string[] */
    public function listModels(): array
    {
        try {
            $raw = $this->httpJson('GET', rtrim($this->baseUrl(), '/') . '/models', null, $this->timeoutEmbed());
        } catch (ConnectorException) {
            return [];
        }
        $out = [];
        foreach (($raw['data'] ?? []) as $m) {
            if (is_array($m) && is_string($m['id'] ?? null) && $m['id'] !== '') {
                $out[] = $m['id'];
            }
        }
        return $out;
    }

    /** Optional: only override when provider needs extra headers. */
    protected function headers(): array
    {
        return array_merge(parent::headers(), [
            'X-Title: RevoAp',
        ]);
    }
}
```

---

## 3. Auto-Registration

`ConnectorRegistry` scans `src/Connectors/` for files matching
`*Connector.php` (excluding `AbstractConnector.php`), resolves the FQCN
`App\Connectors\{Basename}`, calls `isAvailable()`, and registers any
connector that returns `true`. **You do not need to edit a registry file.**

The registry uses `\ReflectionClass` to skip:

- Abstract classes
- Classes that do not implement `ConnectorInterface`
- Classes whose constructor requires arguments

---

## 4. `config.php` Template

```php
'connectors' => [
    // ...existing providers...
    'myprovider' => [
        'active'        => true,                                 // set false to skip
        'api_key'       => '',                                   // empty → use .myprovider.key or env
        'base_url'      => 'https://api.myprovider.example/v1',
        'timeout_chat'  => 120,
        'timeout_embed' => 30,
        'max_retries'   => 3,
    ],
],

'models' => [
    // ...existing models...
    ['provider' => 'myprovider', 'model' => 'myprovider/some-model', 'active' => true],
],
```

Key resolution order (handled by `Config::getConnectorKey()`):

1. `connectors.{slug}.api_key` (when `active=true`)
2. `<projectRoot>/.{slug}.key` file
3. `$_ENV[strtoupper(slug) . '_API_KEY']`

If none yield a value, `ConnectorKeyNotFoundException` is thrown and
`isAvailable()` returns `false`.

---

## 5. Self-Test Checklist

Before marking a connector "done":

- [ ] `isAvailable()` returns `true` with valid configuration.
- [ ] `isAvailable()` returns `false` when the API key is missing.
- [ ] `chat()` returns a `ChatResponse` whose
      `totalTokens === promptTokens + completionTokens`.
- [ ] `latencyMs` is greater than zero on a real request.
- [ ] `listModels()` returns a non-empty `string[]` for a provider that
      exposes a `/models` endpoint, and `[]` (not throw) on transport
      failure.
- [ ] `getProviderName()` returns the same lowercase slug used in
      `config.php`.
- [ ] Embedding behavior is correct: a real vector is returned, or
      `UnsupportedOperationException` is thrown.
- [ ] No API keys appear in logs, error messages, or rendered HTML.

---

## 6. Common Mistakes

| Mistake | Symptom | Fix |
|---|---|---|
| Forgetting `parent::__construct(...)` | Fatal error on instantiation | Always invoke parent constructor with provider slug + base URL. |
| Wrong header casing (`authorization` lowercase) | 401/403 from provider | Use `Authorization: Bearer {key}` exactly. |
| Not populating `latency_ms` | Stats panel shows 0ms | Always wrap `httpJson()` between `TimeHelper::now()` and `TimeHelper::elapsedMs()` if you bypass `chat()`. |
| Returning `0` for token totals when API returns usage=0 | Inflated estimates everywhere | Allow the base class to fall back to `TokenHelper::estimateTokens()` and mark `tokensEstimated=true`. |
| Using `die()` / `exit()` on error | Pipeline breaks mid-step | Throw `ConnectorException`; the pipeline records failure and continues. |
| Caching the resolved key in a public field | Key leaks in dumps | Keep it `private` (the abstract class already does). |
| Forgetting `.{slug}.key` in `.gitignore` | Secret committed | Add `.{slug}.key` to `.gitignore`. |
| Capitalizing the slug | `getByProvider()` lookups fail | Slug must be lowercase in both code and config. |

---

## 7. Minimal Working Example: `MockConnector`

This connector is **for tests only**. Drop it into `src/Connectors/`,
mark it `active=true` in `config.php`, and it will be discoverable.
It returns deterministic fake responses without any HTTP traffic — useful
for verifying the registry, pipeline, and stats end-to-end.

```php
<?php
/**
 * MockConnector: Deterministic in-memory connector for tests.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    YYYY-MM-DD
 */
declare(strict_types=1);

namespace App\Connectors;

use App\Connectors\Exceptions\UnsupportedOperationException;
use App\Helpers\TokenHelper;

final class MockConnector implements ConnectorInterface
{
    public function chat(string $model, array $messages, array $options = []): ChatResponse
    {
        $content = '[mock] ' . end($messages)['content'] ?? '';
        $prompt  = TokenHelper::estimateMessageTokens($messages);
        $compl   = TokenHelper::estimateTokens($content);
        return new ChatResponse(
            content:          $content,
            model:            $model,
            provider:         'mock',
            promptTokens:     $prompt,
            completionTokens: $compl,
            totalTokens:      $prompt + $compl,
            latencyMs:        1.0,
            rawResponse:      ['mock' => true],
            tokensEstimated:  true,
        );
    }

    public function embed(string $model, string $text): EmbedResponse
    {
        throw new UnsupportedOperationException('mock provider has no embeddings');
    }

    public function listModels(): array        { return ['mock/echo']; }
    public function getProviderName(): string  { return 'mock'; }
    public function isAvailable(): bool        { return true; }
}
```

---

*RevoAp — `src/Connectors/HOW_TO_CREATE_CONNECTOR.md` v1.0.0 — 2026-05-05*
