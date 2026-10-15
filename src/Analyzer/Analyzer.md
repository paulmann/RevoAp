# App\Analyzer

Optional pre-pipeline request analyzer.

## Components

| Class | Purpose |
| --- | --- |
| `RequestAnalyzer` | Analyzes a query before the council fires. Returns a structured envelope (`domain`, `confidence`, `formula_expected`, `persona`, `suggested_skill`, `suggested_preset`, `signals`, `source`, `reason`, `model`, `provider`). |

## Configuration

* `analyzer.enabled` — runtime flag (default `true`).
* `analyzer.provider` — default `openrouter`.
* `analyzer.model` — default `openai/gpt-5.5`.
* `analyzer.timeout_seconds` — per-call budget for the live request,
  clamped to `[3, 300]` (default `15`). Without this key the analyzer no
  longer inherits `connectors.<provider>.timeout_chat` (120 s).
* `analyzer.skip_live_confidence` — heuristic confidence at or above
  which the live call is skipped, clamped to `[0,1]`. Default `0.0`
  keeps the pre-existing behaviour (live call always attempted). When it
  triggers, the envelope carries `source: 'heuristic'` and the reason
  "heuristic confidence above the skip threshold".

## Flow

1. `analyze($query)` checks `analyzer.enabled`.
2. Optional short-circuit: heuristic confidence ≥
   `analyzer.skip_live_confidence` (only when the threshold is > 0).
3. Otherwise calls the configured provider/model with a strict
   JSON-output system prompt. Maximum 400 completion tokens, timeout
   `analyzer.timeout_seconds` (passed as a per-call `chat()` option,
   never sent to the provider).
4. The response is rejected outright when the provider reports
   `finish_reason: length` / `max_tokens` (truncated envelope).
5. On any failure (no key, transport error, truncation, unparseable
   JSON, empty persona, disabled) it falls back to `DomainRouter`'s
   heuristic classifier and synthesises a persona from the detected
   domain. The individual reason is logged as metadata.
6. The analyzer never logs the raw query body or the raw model
   response — only metadata (provider, model, exception class, short
   body-free error excerpt).

## Persona handling

The `persona` field is model output that is prefixed to *every* council
role's system prompt, so it is sanitized before use:
whitespace runs (including `\n`, `\r`, `\t`, NBSP and the Unicode space
block) collapse to a single space, C0/C1 controls and zero-width
code points (U+200B..U+200D, U+FEFF) are dropped, markdown backticks /
fences are stripped, and the result is truncated to 600 characters. An
empty result falls back to the domain persona.

## Integration

`PipelineEngine::setRequestAnalyzer()` accepts an instance. When set,
the pipeline runs the analyzer before resolving the skill/models so
the routing notice + persona can flow into every L1 role.
