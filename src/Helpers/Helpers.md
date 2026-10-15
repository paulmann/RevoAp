# Module: Helpers

## Purpose
Stateless utility classes used across the application. Each helper does
one thing and never depends on other modules' domain types.

## Files
| File | Class | Responsibility |
|------|-------|----------------|
| Utf8Helper.php     | `App\Helpers\Utf8Helper`     | Convert/clean/escape UTF-8 strings. |
| TemplateHelper.php | `App\Helpers\TemplateHelper` | Render `assets/templates/*.html` with `{{var}}` placeholders. |
| JsonHelper.php     | `App\Helpers\JsonHelper`     | Safe JSON encode/decode with `JSON_THROW_ON_ERROR`. |
| TimeHelper.php     | `App\Helpers\TimeHelper`     | `now()`, `elapsedMs()`, `format()`, `isoNow()`. |
| TokenHelper.php    | `App\Helpers\TokenHelper`    | Heuristic fallback token estimator. |

## Public API
### Utf8Helper
- `toUtf8(string): string`
- `clean(string): string`
- `escape(string): string` — `htmlspecialchars` with `ENT_QUOTES|ENT_SUBSTITUTE|ENT_HTML5`.

### TemplateHelper
- `setTemplatesDir(string): void`
- `render(string $template, array $vars = []): string`
- `renderString(string $body, array $vars = []): string`

### JsonHelper
- `encode(mixed $value, bool $pretty = false, bool $unescaped = true): string`
- `decode(string $json): mixed`
- `tryDecode(string $json, mixed $default = null): mixed`
- `extractFirstObject(string $text): ?array` — find first JSON object in arbitrary text.

### TimeHelper
- `now(): float`
- `elapsedMs(float $start): float`
- `format(float $ms): string`
- `isoNow(): string`

### TokenHelper
- `estimateTokens(string): int`
- `estimateMessageTokens(array): int`

## Invariants & Constraints
- All helpers are **pure** and stateless except `TemplateHelper::setTemplatesDir`.
- Output of `Utf8Helper::escape` is HTML-safe in text contexts.
- `JsonHelper::encode` never returns invalid JSON (throws on failure).

## Integration Points
- Used by: `Core`, `Connectors`, `Pipeline`, `Stats`, `Http`.
- Calls: nothing application-specific.

## LLM Extension Guide
- Add a new helper as a `final` class with only `static` methods.
- Do not store mutable state in helpers (except documented setters).
- Never call repositories or perform I/O beyond reading template files.
