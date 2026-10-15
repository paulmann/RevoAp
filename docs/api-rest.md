# RevoAp External REST API v1

> Version: 1.8.43 · Base path: `/v1` · API key: `rv_*`

RevoAp exposes a REST API for launching consensus runs, polling their
status, reading results and receiving webhooks. It is designed for
external automation: scripts, chat agents and custom integrations.

The same pipeline, job and storage services drive this API as the
interactive UI, but the front door is an **API key**, not the operator
admin session. This document is the full reference; the quick start and
the key-management UI live in [`README.md`](../README.md) and on the
**Settings → API** tab.

## Table of contents

- [Authentication](#authentication)
- [API keys and scopes](#api-keys-and-scopes)
- [Rate limits](#rate-limits)
- [Request formats](#request-formats)
- [Launch endpoint: `POST /v1/runs`](#launch-endpoint-post-v1runs)
- [Read endpoints](#read-endpoints)
- [Errors](#errors)
- [Webhooks](#webhooks)
- [Event stream (SSE)](#event-stream-sse)
- [Examples](#examples)

---

## Authentication

Every `/v1/*` request must present a valid API key. Accepted transports,
in priority order:

1. `X-API-Key: $REVOAP_API_KEY` (recommended)
2. `Authorization: Bearer $REVOAP_API_KEY`
3. `?api_key=$REVOAP_API_KEY` (query parameter, curl convenience; keys in URLs
   leak into logs and proxies - prefer headers)

When several are present the header wins, bearer wins over the query
parameter. Requests without a key receive `401 unauthorized`.

```bash
curl https://your-host/v1/models -H 'X-API-Key: $REVOAP_API_KEY'
```

## API keys and scopes

Keys are generated on the **Settings → API** tab (operator UI). Each
key has:

- **label** - operator-chosen display name;
- **prefix** - `rv_XXXXXXXX` (the full key is `rv_` + 48 hex characters
  from 24 random bytes);
- **scope** - `full` (launch runs, cancel) or `read` (queries only);
- **limits** - `rpm` (requests per minute) and `daily` (requests per
  UTC day);
- **expiry** - optional `expires_at`.

Security rules:

- Only a SHA-256 **hash** of the key is stored (`key_hash CHAR(64)`);
  the plaintext key is displayed exactly once at creation;
- a key that is revoked or expired is rejected with `401`;
- a key without a scope defaults to `read` (least privilege);
- `full` implies `read`, never the reverse;
- all operator surfaces (`/api/settings`, `/api/skills`, `/api/webhooks`,
  `/api/logs`, `/api/security`, `/api/history`, `/api/statistics`,
  `/api/profile`, `/api/share/session`, ...) are **deny-listed** for API
  keys and answer `404` even when the key is `full`.

## Rate limits

Two independent layers:

1. **Per-key quotas** - the key's own `rpm` / `daily`; tracked by
   `runtime/api-rate-limits/key_<id>.json` (flock-protected).
2. **Per-IP budget** - 120 requests/minute per client address, enforced
   **before** the key is even inspected (brute-force backstop). Keyed
   on `REMOTE_ADDR`; the state file name is an opaque SHA-256 digest,
   the IP itself is never stored.

On breach the API answers `429 rate_limited` with:

```
Retry-After: <seconds>
X-RateLimit-Remaining-Minute: <n>
X-RateLimit-Reset-Minute: <unix>
X-RateLimit-Remaining-Daily: <n>   (per-key layer only)
```

Rejected requests do not consume quota; the counters reset when the
minute/day window rolls over.

## Request formats

The API speaks three dialects. Chose one per request (or force it with
`?format=native|openai|anthropic`); responses render in the same
format.

### `native` (default)

```json
{
  "query": "Which storage engine fits our workload best?",
  "selected_models": [
    {"provider": "openrouter", "model": "openai/gpt-5.5"},
    {"provider": "openrouter", "model": "anthropic/claude-sonnet-4.6"}
  ],
  "models": [ ],
  "skill": "db_optimizer",
  "reasoning": "high",
  "cycle_limit": 2,
  "consensus_skill": "semantic-consensus-reasoning",
  "priority": 0,
  "user_id": 42,
  "client_run_id": "import-2026-09-19-01",
  "async": false
}
```

Accepted keys: `query` (required), `selected_models` or `models`

`single_model: true` runs the single-model mode instead of a council: exactly
ONE model is asked with the resolved skill's own system prompt, its answer is
the final answer, and no consensus is computed - L2/L3/L4 do not run and no
synthesis model is called. The response reports `run_mode: "single_model"` and
`consensus_computed: false`, with `consensus: null` (nothing was measured - not
a score of zero). `cycle_limit` does not apply, and the mode cannot be combined
with the asynchronous Batch API, where `single_model` is refused. If the
database has not applied `db/migrations/022_single_model_consensus_status.sql`,
the run reports the mode as unavailable and falls back to the full consensus
pipeline rather than storing a blank verdict.
(alias; `[{provider, model}]`), `skill`, `reasoning`, `cycle_limit`,
`consensus_skill`, `domain`, `profile`, `analyzer`, `priority`,
`user_id`, `client_run_id`, and the transport flags `stream` / `async`.

### `openai`

```json
{
  "model": "openai/gpt-5.5",
  "provider": "openrouter",
  "messages": [
    {"role": "system", "content": "You are a storage engineer."},
    {"role": "user", "content": "Which storage engine fits best?"}
  ],
  "temperature": 0.2,
  "max_tokens": 4096
}
```

Mapping: `query` = the **last user message**; `model` + `provider`
(`revoap_provider` is also accepted; default `openai`) become
`selected_models`. The system prompt, conversation history and
transport options (`temperature`, `max_tokens`, `max_completion_tokens`,
`top_p`, `seed`, `reasoning_effort`, `user`) are preserved verbatim in
the durable job's `request_meta` so the run keeps a faithful audit.

### `anthropic`

```json
{
  "model": "claude-sonnet-4.6",
  "provider": "anthropic",
  "system": "You are a storage engineer.",
  "messages": [
    {"role": "user", "content": [{"type": "text", "text": "Which storage engine fits best?"}]}
  ],
  "max_tokens": 4096,
  "temperature": 0.2,
  "thinking": {"type": "enabled", "budget_tokens": 1024}
}
```

Mapping mirrors the OpenAI dialect: `query` = last user message; the
string `system` field and block-list message contents are flattened;
`max_tokens`, `temperature`, `top_p`, `top_k`, `thinking` and
`metadata` survive in `request_meta`. History messages before the last
user message are preserved as `anthropic_history`.

## Launch endpoint: `POST /v1/runs`

Starts a consensus run. Scope: `full`. One endpoint, three modes:

### Asynchronous (default)

`async` unset or truthy → the request is queued durably
(`council_sessions` + `revoap_jobs`) and the CLI worker
(`bin/revoap-worker.php`) executes it outside PHP-FPM.

`202 Accepted`:

```json
{
  "ok": true,
  "run_id": "run_123",
  "session_id": 123,
  "job_id": 456,
  "status": "queued",
  "created_at": "2026-09-19T12:34:56+00:00",
  "model": "openrouter/openai/gpt-5.5",
  "events_url": "/v1/runs/123/events",
  "result_url": "/v1/runs/123/result",
  "cancel_url": "/v1/runs/123/cancel"
}
```

Poll `result_url` until a terminal status arrives.

### Synchronous

`"async": false` → the pipeline runs inside the request and the
response carries the complete result (`final_answer`, `consensus`,
`usage`). Use only for short runs.

### Streaming

`"stream": true` → the request switches to `text/event-stream` and
emits pipeline events live (see [Event stream](#event-stream-sse)).
`client_run_id` deduplicates concurrent submissions of the same
client run.

## Read endpoints

All read endpoints require `read` scope (or `full`).

| Endpoint | Meaning |
|---|---|
| `GET /v1/runs` | Recent runs, newest first (`?limit=` 1..100, default 20) |
| `GET /v1/runs/{id}` | One run record (`run_<id>` or plain numeric id) |
| `GET /v1/runs/{id}/events` | Persisted pipeline events, `?after_event_id=` cursor |
| `GET /v1/runs/{id}/result` | Finished run record; `202` while still active |
| `POST /v1/runs/{id}/cancel` | Cancellation intent (`full` scope); `409` when already terminal |
| `GET /v1/models` | Active consensus models (`{id, provider, model}` list) |
| `GET /v1/usage` | Aggregated usage, `?period=all\|year\|quarter\|week\|day\|hour`, optional `?user_id=<n>` to scope to one end user (a key is an operator credential, so the scope is explicit); `503` without a statistics database |

Canonical run record (native format):

```json
{
  "ok": true,
  "run_id": "run_123",
  "session_id": 123,
  "job_id": 456,
  "status": "completed",
  "created_at": "2026-09-19T12:34:56+00:00",
  "final_answer": "…",
  "consensus": {"score": 0.88, "status": "agreement"},
  "model": "openrouter/openai/gpt-5.5",
  "usage": {"prompt_tokens": 1000, "completion_tokens": 400, "total_tokens": 1400},
  "events_url": "/v1/runs/123/events",
  "result_url": "/v1/runs/123/result",
  "cancel_url": "/v1/runs/123/cancel"
}
```

Statuses: `queued`, `running`, `completed`, `failed`, `cancelled`.

### SDK-shaped responses

With `?format=openai` the run record renders as a chat completion
(`id: chatcmpl_…`, `object: chat.completion`, `choices[].message`,
`finish_reason`, `usage`) plus a `revoap` extension block; with
`?format=anthropic` as a message (`msg_…`, `content:[{type:text,text}]`,
`stop_reason`, `usage:{input_tokens,output_tokens}`) plus `revoap`.
While a run is still running, `choices`/`content` are empty and the
`revoap.status` field carries the progress.

## Errors

Native: `{"ok":false, "error":"<code>", "message":"<human text>",
"status":<http>}`. OpenAI/Anthropic envelopes map onto the error shapes
their SDKs expect (`error.type/message/code`).

| HTTP | Code | When |
|---|---|---|
| 400 | `invalid_request` | Malformed JSON / missing `query` / bad run id |
| 401 | `unauthorized` | Missing, unknown, revoked or expired key |
| 403 | `forbidden` | Scope insufficient (write route with a `read` key) |
| 404 | `not_found` | Unknown run, or an operator-only path hit by an API key |
| 409 | `invalid_state` | Cancel on an already-finished run |
| 429 | `rate_limited` | Per-key or per-IP quota exhausted (`Retry-After`) |
| 429 | `budget_exceeded` | The run's `user_id` has an exhausted budget with `action_at_limit=block` (`Retry-After` at the period end; the native body carries a `budget` block with spent/limit/reset — see [`MULTI_USER.md`](MULTI_USER.md) §4) |
| 500 | `internal_error` / `enqueue_failed` / `cancel_failed` | Server-side failure |
| 503 | `service_unavailable` | `/v1/usage` without a configured statistics store |

## Webhooks

Subscriptions are managed by the operator on the **Settings → Webhooks**
tab (`/api/webhooks` is admin-only and deny-listed for API keys). Each
subscription listens to one or more lifecycle events:

- `run.completed`
- `run.failed`
- `run.cancelled`

Delivery is `POST` with `Content-Type: application/json`:

```json
{
  "event": "run.completed",
  "event_id": "3c9d1e2f-…-uuid",
  "created_at": "2026-09-19T12:40:00Z",
  "data": { "run_id": "run_123", "session_id": 123, "status": "completed", "final_answer": "…" }
}
```

### Signature verification

Every delivery carries the header `X-RevoAp-HMAC-SHA256`:

```
t=<unix-timestamp>;v1=<lowercase-hex HMAC-SHA256>
```

where the HMAC is computed over `"<unix-timestamp>.<raw-body>"` with
the per-subscription shared secret:

```php
$expected = 'v1=' . strtolower(hash_hmac('sha256', $t . '.' . $body, $secret));
if (!hash_equals($expected, $got)) { /* reject */ }
// optional: reject when abs((int)$t - time()) > 300
```

Replay-safe: a unique `event_id` per delivery row makes retries
idempotent.

### Retries

A 2xx response marks the delivery successful. Any other outcome (or a
transport error) schedules a retry with exponential backoff: `60s,
300s, 900s, 1800s, …` up to 6 attempts (configurable via
`webhooks.retry_backoff_seconds`, `webhooks.max_attempts`,
`webhooks.timeout_seconds` in `config.php`). A retry re-sends the SAME
timestamped payload with a fresh signature. After the last attempt the
delivery row becomes `giving_up`. Delivery problems never abort the
caller.

Receiver-side PHP helper for verification ships in
`WebhookDispatcher::verifySignature()` (`src/Api/WebhookDispatcher.php`).

## Event stream (SSE)

`POST /v1/runs` with `"stream": true` answers `text/event-stream` and
emits one `data:` frame per pipeline event - `PIPELINE_STARTED`,
`L0_*`, `L1_*`, `L2_*`, `L3_*`, `L4_*`, `PIPELINE_DONE` /
`PIPELINE_FATAL` - in the same contract as the UI's
`/api/council/stream`. The terminal frame carries `final_answer` and
the session id; consumers should open the stream, capture the
`PIPELINE_STARTED.payload.session_id`, and optionally switch to polling
`/v1/runs/{id}/result` if the connection drops.

Web-server note: put the stream route behind
`proxy_buffering off; proxy_cache off; gzip off;` (nginx) or
`SetEnv no-gzip 1` (Apache), the same as for `/api/council/stream`.

## Examples

### Start a run and poll it to completion

```bash
KEY='$REVOAP_API_KEY'
BASE='https://your-host'

# 1. Enqueue (async is the default).
RESP=$(curl -fsS -X POST "$BASE/v1/runs" \
  -H "X-API-Key: $KEY" -H 'Content-Type: application/json' \
  -d '{"query":"Compare MySQL and PostgreSQL for a write-heavy workload",
       "models":[{"provider":"openrouter","model":"openai/gpt-5.5"}]}')
RUN_ID=$(echo "$RESP" | jq -r .run_id)   # run_123

# 2. Poll the result endpoint until terminal.
for i in $(seq 1 60); do
  OUT=$(curl -fsS -X GET "$BASE/v1/runs/${RUN_ID#run_}/result" -H "X-API-Key: $KEY")
  STATUS=$(echo "$OUT" | jq -r .status)
  case "$STATUS" in completed|failed|cancelled) break;; esac
  sleep 5
done
echo "$OUT" | jq '{status, final_answer, consensus, usage}'
```

### Synchronous round-trip through the OpenAI dialect

```bash
curl -fsS -X POST 'https://your-host/v1/runs?format=openai' \
  -H "X-API-Key: $KEY" -H 'Content-Type: application/json' \
  -d '{"model":"openai/gpt-5.5","provider":"openrouter",
       "messages":[{"role":"user","content":"Hello in one sentence"}],
       "async":false}'
# {"id":"chatcmpl_…","object":"chat.completion","choices":[…],"revoap":{…}}
```

### Streaming

```bash
curl -N -X POST 'https://your-host/v1/runs' \
  -H "X-API-Key: $KEY" -H 'Content-Type: application/json' \
  -d '{"query":"Hello","stream":true,"models":[{"provider":"openrouter","model":"openai/gpt-5.5"}]}'
```

### Models and usage

```bash
curl -fsS 'https://your-host/v1/models' -H "X-API-Key: $KEY"
curl -fsS 'https://your-host/v1/usage?period=week' -H "X-API-Key: $KEY"
```
