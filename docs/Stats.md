# Pipeline statistics

Every successful pipeline step propagates real model/provider/token/latency
figures from the LLM call all the way to the UI. This document describes
the data flow.

## Source of truth: `ChatResponse`

A `ChatResponse` is constructed by the connector from the LLM response.
Its `statsPayload()` method returns a UI-shaped associative array:

```php
[
  'model'             => 'openai/gpt-4o-2024-08-06', // actualModel preferred
  'requested_model'   => 'openai/gpt-4o',
  'provider'          => 'openrouter',
  'prompt_tokens'     => 123,
  'completion_tokens' => 45,
  'total_tokens'      => 168,
  'latency_ms'        => 412,
  'tokens_estimated'  => false,
  // optional, present only when the provider returned them:
  'cost'             => 0.000142,
  'cached_tokens'    => 5,
  'reasoning_tokens' => 2,
  'generation_id'    => 'gen-…',
]
```

## Step persistence

`PipelineEngine` calls `StepRepository::completeStep($id, $status, $summary, $metaExtras)`
with the connector's stats payload merged into the step's `meta` JSON. The
existing meta keys (layer, role, original requested model/provider) are
preserved; new keys overwrite same-named keys, so reading the row yields a
single coherent stats dictionary. Step rows therefore look like:

```json
{
  "id": 17, "step_key": "L1.role.analyst", "label": "L1 · analyst",
  "status": "completed",
  "meta": {
    "layer": "L1", "role": "analyst",
    "model": "openai/gpt-4o-2024-08-06",
    "provider": "openrouter",
    "prompt_tokens": 123, "completion_tokens": 45, "total_tokens": 168,
    "latency_ms": 412, "tokens_estimated": false,
    "cost": 0.000142, "cached_tokens": 5, "reasoning_tokens": 2,
    "generation_id": "gen-…"
  }
}
```

## Pipeline events

`L1_RESPONSE` and `L4_SYNTHESIS` events carry the same payload as the step
meta. Events are intended for the audit trail; the UI no longer derives
its Statistics tab from event payloads — it reads steps directly.

## Final answer

`PipelineEngine::run()` returns a top-level `synthesis_stats` field that
mirrors the L4 step's `statsPayload()`. The CouncilController forwards it
in the `/api/council/run` response so the Final Answer card can render the
synthesis model/provider/totals instead of placeholder dashes.

## UI rendering

`assets/js/app.js` loops over `data.steps` and feeds each row through
`statsFromMeta(meta)`. Optional fields (`cost`, `cached_tokens`,
`reasoning_tokens`, `generation_id`) appear only when the provider
returned them. When `tokens_estimated` is `true`, the UI suffixes
"(estimated)" to the Total tokens row so reviewers know the figure did
not come from the provider.
