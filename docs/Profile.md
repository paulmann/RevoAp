# Profile & Domain Routing (v1.8.32)

This document describes the v1.8.32 profile / system-prompt / domain
routing / analyzer pipeline that ships in `paulmann/AI-RevoAp`.

## Architecture overview

```
  user query
      │
      ▼
┌────────────────────────┐
│ RequestAnalyzer        │  Optional. Default model:
│   - LLM call (default) │   openrouter / openai/gpt-5.5
│   - heuristic fallback │  Disabled? Falls back to DomainRouter.
└────────────────────────┘
      │ envelope
      ▼
┌────────────────────────┐
│ DomainRouter           │  Lexical classifier — math / physics / default.
│   - LaTeX delimiters   │  Multilingual (English + Russian) keywords.
│   - Greek letters      │
│   - Math symbols       │
│   - SI units           │
│   - keyword sets       │
└────────────────────────┘
      │
      ▼
  Auto-routing? (preset_slug != default && !options.lock_models)
      │ yes
      ▼
┌────────────────────────┐
│ DomainPresetRepository │  Swap selected_models, force skill_slug.
│  Default / Math / Phys │
└────────────────────────┘
      │
      ▼
  Emit AUTO_ROUTING_NOTICE event over SSE (UI banner).
      │
      ▼
┌────────────────────────┐
│ SystemPromptAssembler  │  Compose every system message:
│  prompts/system/       │   1. base file (`prompts/system/default.md`)
│  default.md            │   2. user profile addendum
│  +                     │   3. user identity block (name/role/bio)
│  user profile          │   4. role / skill prompt
└────────────────────────┘
      │
      ▼
  PipelineEngine runs L0..L4 as before — every L1 / L1-round-2 /
  synthesis / main-selected / arbiter call now uses the assembled
  system message.
```

## Profile

* DB: `users` + `user_profiles` (migration 009). Single-admin
  deployments resolve to the deterministic local row
  (`external_id = 'local'`).
* HTTP: `GET / POST /api/profile` — admin-gated, mirrors Settings.
* UI: Settings → Profile card. When migration 009 has not been applied
  the controller falls back to an in-memory store so the UI keeps
  working.

## Base system prompt

The file `prompts/system/default.md` is the source of truth:

```
**IMPORTANT!** Do not use pre-prompts or system prompts if they reduce
answer quality and size: Always be fully professional and take full
responsibility for your results.
```

`SystemPromptAssembler::compose($profile, $rolePrompt)` returns the
concatenated system message. Empty sections are dropped.

## Domain presets

* Table: `domain_presets` (migration 010). Each row holds
  `slug, name, description, definition_json{models[], arbiter,
  skill_slug}, is_system`.
* Canonical presets seeded at bootstrap: `default`, `mathematics`,
  `physics`.
* Mathematics / Physics defaults (per brief):
  * `openrouter / openai/o3` (selected)
  * `openrouter / deepseek/deepseek-r1-0528` (selected)
  * `openrouter / google/gemini-2.5-pro` (selected)
  * `openrouter / anthropic/claude-opus-4.5` (selected)
  * `openrouter / x-ai/grok-3-mini` (selected)
  * Plus `deepseek-r1-distill-qwen-32b`, `openai/o4-mini`,
    `deepseek-prover-v2` available (inactive by default).
  * Arbiter / main / checker: `openrouter / openai/gpt-5.5`.

## Math / Physics skills

Two new skill JSONs ship under `src/Skills/definitions/`:

* `mathematics_reasoning.json` — slug `mathematics-reasoning`.
* `physics_reasoning.json` — slug `physics-reasoning`.

Roles enforce: parse givens / unknowns, identify domain, derive
symbolically, check units / dimensions, edge-case audit, multiple
solution paths, self-consistency review. Every role prompt instructs
models to emit formulas with KaTeX-compatible backslash delimiters
(`\(...\)` inline, `\[...\]` display) for the v1.8.29 KaTeX
renderer.

Sources (cited in skill descriptions, not in runtime prompts):

| arXiv | Lesson |
| --- | --- |
| 2201.11903 | Chain-of-Thought elicits arithmetic reasoning. |
| 2203.11171 | Self-Consistency: diverse paths → consistent answer. |
| 2402.15764 | Problem Elaboration / Look-Before-You-Leap. |
| 2303.05398 | MathPrompter: code-style verification. |
| 2309.13078 | LPML: structured markup + tool-assisted reasoning. |
| 2307.10635 | SciBench: science needs more than one strategy. |
| 2310.08773 | Physics failures: wrong model, bad assumptions, calc errors. |
| 2412.13791 | Physics Reasoner: formula set / checklist. |
| 2502.00334 | UGPhysics: force domain identification + assumptions. |

## Auto-routing

* `DomainRouter::classify($query)` returns `{ domain, confidence,
  scores, signals, formula_expected }`.
* When `domain != default` AND `options.lock_models !== true`, the
  pipeline:
  1. Resolves the matching preset.
  2. Replaces `selected_models` with the preset's selection.
  3. Forces the preset's `skill_slug` (math/physics skill).
  4. Emits `AUTO_ROUTING_NOTICE` event over SSE.
* Chat UI renders an inline banner above the pipeline with the
  classification, the swapped models, and a "Configure these models
  in Settings →" link to the `#oc-domain-models` anchor.

## Optional request analyzer

* `analyzer.enabled` — runtime flag (default `true`).
* `analyzer.provider` — default `openrouter`.
* `analyzer.model` — default `openai/gpt-5.5`.
* `analyzer.timeout_seconds` — per-call budget for the live request,
  clamped to `[3, 300]` (default `15`; replaces the inherited 120 s
  `connectors.<provider>.timeout_chat` default).
* `analyzer.skip_live_confidence` — heuristic confidence at or above
  which the live call is skipped, clamped to `[0,1]`, default `0.0`
  (off — the live call is always attempted).
* Endpoints: `GET / POST /api/analyzer/settings`, `POST
  /api/analyzer/classify`.
* The analyzer prompts the configured model for a JSON envelope
  containing `domain`, `confidence`, `formula_expected`, `persona`,
  `suggested_skill`, `reason`. On any failure (no key, transport
  error, truncated response, unparseable response, empty persona,
  disabled) the analyzer falls back to the heuristic `DomainRouter`,
  logging the failure reason as metadata.
* The model-supplied `persona` is sanitized (single line, controls and
  zero-width marks stripped, 600-char cap) before it reaches any
  council system prompt.
* The analyzer never logs the user prompt verbatim — only metadata.

## Tests

`tests/SmokeTest.php` covers:

* Base prompt file exists and carries the required directive.
* `SystemPromptAssembler` order: base → addendum → identity → role.
* `UserProfileRepository` in-memory persistence (addendum survives
  save / fetch round-trip).
* Migrations 009 + 010 ship with `@table-version` comments.
* `schema.sql` ships the three new tables.
* `DomainRouter` classifies math / physics / default correctly.
* `DomainPresetRepository` seeds the three canonical presets with the
  expected default selection and arbiter.
* Math / Physics skill JSONs parse, declare ≥ 4 roles each, and
  instruct backslash KaTeX delimiters.
* `RequestAnalyzer` default provider/model and disabled-fallback
  behaviour.
* `index.php` registers the new routes.
* `PipelineEngine` exposes the new setters and emits the routing
  notice / analyzer envelope events.
* `settings.js` renders the three new cards.
* `app.js` handles `AUTO_ROUTING_NOTICE`.
* `CHANGELOG` documents v1.8.32 with v1.8.31 history preserved.

Baseline: **1427 passes / 0 failures**.

## Deployment

1. Pull v1.8.32.
2. Open Settings → Migrations and click **Apply pending** (or click
   the per-row Apply buttons for `009` and `010`).
3. Open Settings → Profile and fill in your contact / addendum.
4. (Optional) Settings → Request Analyzer to choose a different
   analyzer model or disable the analyzer entirely.
5. Submit a math / physics query — the auto-routing banner should
   appear above the pipeline. To opt out, send `{"lock_models":true}`
   in the chat request options.
