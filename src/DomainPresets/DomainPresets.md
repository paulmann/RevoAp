# App\DomainPresets

Per-domain model presets + heuristic domain classifier.

## Components

| Class | Purpose |
| --- | --- |
| `DomainPreset` | Readonly DTO: slug + provider/model pool + arbiter + linked skill. |
| `DomainPresetRepository` | PDO-backed CRUD for `domain_presets` (migration 010). Seeds Default / Mathematics / Physics at bootstrap when the store is empty. Falls back to in-memory store when migration 010 has not been applied. |
| `DomainRouter` | Lexical classifier. Returns `{ domain, confidence, scores, signals, formula_expected }`. |

## Signals used by `DomainRouter`

* LaTeX delimiters / macros (`\(`, `\[`, `\frac`, `\int`, …).
* Greek letters (Unicode range U+0370..U+03FF).
* Math symbols (`∫ ∑ ∇ ∂ √ ≤ ≥ ≠ ≈ ∞ ⊕ ⊗ ⊆ ⊇ ∈ ∉ ∀ ∃`).
* Physics SI unit tokens (`m/s²`, `kg`, `Hz`, `eV`, `J`, …).
* Math + physics keywords in English and Russian.
* Numerical density paired with `=` (word problems).

The first matching keyword carries a 0.40 baseline so a single
unambiguous mention ("integral") already lifts the math score above
the 0.35 routing threshold when combined with formula context.

## Auto-routing

Triggered from `PipelineEngine::run()`. When the request does not set
`options.lock_models = true` and the analyzer / router classifies the
query as math or physics, the engine swaps the selected_models, forces
the linked skill, and emits an `AUTO_ROUTING_NOTICE` event over SSE.

## Default selections (per v1.8.32 brief)

Mathematics / Physics share the same default pool:

* `openrouter / openai/o3` *(selected)*
* `openrouter / deepseek/deepseek-r1-0528` *(selected)*
* `openrouter / google/gemini-2.5-pro` *(selected)*
* `openrouter / anthropic/claude-opus-4.5` *(selected)*
* `openrouter / x-ai/grok-3-mini` *(selected)*
* `openrouter / deepseek/deepseek-r1-distill-qwen-32b` *(available)*
* `openrouter / openai/o4-mini` *(available)*
* `openrouter / deepseek/deepseek-prover-v2` *(available)*

Arbiter / main / checker: `openrouter / openai/gpt-5.5`.
