# Prompt caching (Bothub)

Provider-side prefix caching: the provider charges less for a prompt prefix
it has already seen. It is **not** the local model-response cache documented
in [`Statistic.md`](Statistic.md) / the Settings → Cache card — that one
skips the network call entirely. This one changes what the provider charges
for a call that still happens.

Bothub documents prompt caching for its OpenAI-compatible endpoint, so the
feature is implemented there and **only** there
(`AbstractConnector::promptCachePolicy()` returns `null` for every other
connector, which keeps an OpenRouter request byte-identical to what it was
before this feature existed).

- Code: `src/Connectors/PromptCache/` (`Capabilities`, `Capability`,
  `Policy`, `Planner`, `Plan`)
- UI: Settings → **Prompt caching**, the Chat per-step/session statistics,
  and the History run header and per-stage rows.
- Schema: `council_model_responses.cache_read_tokens`,
  `.cache_write_tokens`, `.cache_discount` (migration `018_prompt_cache_stats.sql`).

## The capability table (C1)

Read from the Bothub documentation, and it is the single source of truth for
the whole feature: `Capabilities` is what the request builder consults and
what the Settings card renders, so the table cannot drift from the behaviour.

| family | caching | min prompt | write price | read price | max breakpoints |
|---|---|---|---|---|---|
| Anthropic / Claude | needs `cache_control` breakpoints | not stated | 1.25× | 0.1× | 4 |
| Google / Gemini (via Bothub) | needs `cache_control` breakpoints | 2048 (2.5 Pro), 1028 (2.5 Flash) | 0× | 0.25× | no limit (Bothub uses the last one) |
| OpenAI / ChatGPT | automatic | 1024 | 0× | 0.25–0.5× | n/a |
| xAI / Grok | automatic | not stated | 0× | 0.25× | n/a |
| Moonshot | automatic | not stated | 0× | 0.25× | n/a |
| Groq | automatic (currently Kimi K2) | not stated | 0× | 0.5× | n/a |
| DeepSeek | automatic | not stated | 1× | 0.1× | n/a |
| anything else | **unknown** | — | — | — | — |

"Not stated" and "unknown" are decisions, not gaps: for an undocumented
family the application adds no breakpoint and promises no saving, and the UI
says so. Reported statistics are still displayed if the provider sends them.

Groq is a *hosting* provider, not a model vendor. The family is derived from
the model id, so a Groq-hosted Llama resolves to `meta` and is therefore
treated as undocumented; the Groq row applies to ids that carry a `groq/`
prefix and no vendor keyword of their own.

## The request side (C2)

`Planner::plan()` is a pure function of (messages, model, policy) — no
network, no clock, no state — so its output is asserted byte-for-byte in
`tests/PromptCacheTest.php`.

1. The **stable prefix** is the leading run of `system` messages.
2. A breakpoint goes on the **last** `system` message of that run (or the
   last N, when the operator raises the count; clamped to 1..4 because 4 is
   the documented hard ceiling).
3. Only those messages become composite; the dynamic tail — the user turn —
   is passed through untouched.

```
before:  [ {role: system, content: "<base file + profile + role prompt>"},
           {role: user,   content: "[MEMORY CONTEXT …]\n<query>"} ]

after:   [ {role: system, content: [ {type: text, text: "<…>",
                                      cache_control: {type: ephemeral}} ]},
           {role: user,   content: "[MEMORY CONTEXT …]\n<query>"} ]
```

`usage: {include: true}` is added whenever the feature is on, for every
family: it is what makes the provider return cache tokens at the end of the
response, and it never changes the message list.

### What was reordered, and why

`PipelineEngine::buildL1Messages()` used to append the cross-session memory
recall to the **`system`** message. Recall is query-dependent, so the cached
prefix was unique per query and no cache could ever hit — on the L1 fan-out,
which is the layer that reuses a prefix most. The block now travels in the
**user** message, ahead of the query, keeping its wording, its position
relative to the question and its `pipeline.memory_top_k` gate. The L2/L3/L4
builders were already dynamic-last and were not touched.

## The response side (C3)

`AbstractConnector::buildChatResponse()` reads cache statistics in both
dialects:

| what | OpenAI-like | Anthropic-like |
|---|---|---|
| tokens read from cache | `usage.prompt_tokens_details.cached_tokens` | `usage.cache_read_input_tokens` |
| tokens written to cache | — | `usage.cache_creation_input_tokens` |
| saving | `cache_discount` (body level; `usage.cache_discount` also read) | same |

The read side is **one** number whichever dialect reported it
(`ChatResponse::cachedTokens`), which is what keeps an aggregate from
counting the same tokens twice. `cache_discount` is signed: a cache *write*
at 1.25× of the input price comes back negative, and the UI shows the minus.

All three are `null` when unreported and `0` only when the provider actually
reported a zero. That distinction is the requirement, not a nicety: it is
what lets the UI say "the provider returned no cache data" instead of
implying the cache did not fire.

Storage:

- `council_steps.meta` (JSON) — carries the figures for every step, which is
  the primary read path for Chat and History.
- `council_model_responses.cache_read_tokens` / `.cache_write_tokens` /
  `.cache_discount` — the typed per-call ledger, added by
  `db/migrations/018_prompt_cache_stats.sql`. All three are nullable and the
  `ResponseRepository` **probes** for them, so a database that has not
  applied `018` keeps working and simply records no cache figures there.
- `SessionStats` / `TokenUsage` roll them up per model, per layer and per
  run (`cache_read_tokens`, `cache_write_tokens`, `cache_calls`,
  `cache_reported_calls`, `cache_discount`, `cache_discount_available`).
- `HistoryService::cacheRollup()` derives the run-level figure from the step
  meta, so the History header and the Chat session panel render the same
  numbers from the same input.

## Settings

| key | default | meaning |
|---|---|---|
| `prompt_cache.enabled` | `true` | master switch; off = the legacy request body, byte for byte |
| `prompt_cache.ttl` | `5m` | `5m` or `1h`; anything else is coerced to `5m` |
| `prompt_cache.max_breakpoints` | `1` | clamped to 1..4 |
| `prompt_cache.min_prompt_tokens` | `1024` | size guard; raised per family by the documented minimum, never lowered |
| `prompt_cache.include_usage` | `true` | send `usage: {include: true}` |
| `prompt_cache.provider` | `bothub` | not settable from the UI |

Written through `POST /api/settings/prompt-cache` (same admin gate and same
`SettingsPersist`/`RuntimeConfig` write path as `/api/settings/runtime`).
Read back in `GET /api/settings` under `runtime.prompt_cache` as
`policy` (resolved and clamped) plus `capabilities` (the table above).

## Tests

- `tests/PromptCacheTest.php` — the capability table; breakpoint placement,
  prefix stability, TTL shapes, the size guard, the ceiling, the
  no-stable-prefix case; the connector applying the plan to Bothub only and
  leaving an automatic/unknown family's payload byte-identical; the L1
  message assembly; both response dialects, null-vs-zero, storage with and
  without migration 018, the run roll-up and the History roll-up; the
  Settings endpoint and its clamps; the migration/schema agreement.
- `tests/PromptCacheUiTest.php` + `tests/harness/prompt-cache-ui.mjs` — the
  Settings card is shipped and wired, the statistics surfaces import the
  shared formatter, and every state the operator can be shown (hit, reported
  miss, negative saving, no data, run roll-up) is produced by the real
  `assets/js/prompt-cache.js` loaded through `vm.SourceTextModule`.

## What is not covered

This environment has no `curl` extension, so no assertion here has ever met
a real Bothub endpoint. The request shape is checked against the documented
contract and the response parsing against fixtures derived from it. Before
trusting the numbers on a deployment, confirm against a live key that
Bothub accepts `cache_control` on a composite `content`, that
`usage: {include: true}` is not rejected, and that `cache_discount` appears
in the response body — then check the Settings → Transactions page for the
same figures.
