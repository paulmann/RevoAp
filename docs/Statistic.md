# RevoAp — Admin Statistic

Since v1.8.0 RevoAp exposes an in-app **Statistic** tab so an
operator can review aggregated usage of the council pipeline —
session counts, token totals, latency, provider-reported cost and a
per-LLM breakdown — over selectable time windows, without running
ad-hoc SQL against the history tables.

The tab is gated by **two** independent layers and additionally
requires a **configured database**. All three must pass:

1. **Config flag** `StatisticTab` in `config.php`. Default is `false`.
2. **Admin token** (`REVOAP_ADMIN_TOKEN` env or `.admin.key`).
3. **Database** — persistent statistics require a working DSN. When
   the DB is unavailable the tab renders a clear `no_database`
   diagnostic instead of silently failing.

Why default off: the metrics describe operational and per-user usage
(prompt/completion volumes, costs, latencies) and may be sensitive in
a multi-tenant deployment. The operator must consciously opt in.

## Configuration

```php
return [
    'app' => [
        'SettingsTab'  => true,
        'LogsTab'      => true,
        'SecurityTab'  => false,
        'HistoryTab'   => false,
        'StatisticTab' => true,  // <-- enable the Statistic tab
    ],
    'StatisticTab' => true,      // <-- top-level alias also honored
];
```

The flag is parsed with `=== true`. Strings such as `'yes'` or `'1'`
do **not** enable the tab — only the PHP boolean `true` counts. When
both the top-level form (`StatisticTab`) and the nested form
(`app.StatisticTab`) are set, the top-level form wins.

## Periods

The selector exposes six windows:

| Token     | Label   | Window                                          |
|-----------|---------|-------------------------------------------------|
| `all`     | All     | All recorded sessions (`from = 1970-01-01`)     |
| `year`    | Year    | Calendar year start (`YYYY-01-01 00:00`) → now  |
| `quarter` | Quarter | Calendar quarter start (Jan/Apr/Jul/Oct) → now  |
| `week`    | Week    | Last 7 days from now (rolling window)           |
| `day`     | Day     | Last 24 hours from now (rolling window)         |
| `hour`    | Hour    | Last 60 minutes from now (rolling window)       |

The default is `all`. Periods are computed against current server
time (UTC); pass an anchor date programmatically when constructing
deterministic test fixtures.

## Endpoints

| Method | Path                          | Auth      | Description                                           |
|--------|-------------------------------|-----------|-------------------------------------------------------|
| GET    | `/api/statistics/status`      | public    | Whether the tab is enabled, admin state, db state, supported periods |
| GET    | `/api/statistics/summary`     | admin     | Aggregated totals + per-LLM breakdown for `?period=…` |

The `summary` payload shape:

```jsonc
{
  "ok": true,
  "period": "day",
  "from": "2026-05-07 12:00:00",
  "to":   "2026-05-08 12:00:00",
  "anchor": "2026-05-08T12:00:00+00:00",
  "sessions": {
    "total": 4,
    "completed": 2,
    "failed": 1,
    "running": 1,
    "duration_total_ms": 4050,
    "duration_avg_ms": 1350
  },
  "tokens": {
    "prompt": 210,
    "completion": 365,
    "total": 575,
    "cached": 25,
    "reasoning": 10,
    "estimated_rows": 1
  },
  "calls":   { "total": 3, "success": 2, "failed": 1, "cache_hits": 1 },
  "latency": { "total_ms": 2300, "avg_ms": 1150 },
  "cost":    { "total": 0.0036, "avg": 0.0018, "available": true },
  "per_model": [
    {
      "provider": "bothub", "model": "openai/gpt-4o",
      "calls": 2, "success": 2, "failed": 0,
      "prompt_tokens": 150, "completion_tokens": 275, "total_tokens": 425,
      "cached_tokens": 25, "reasoning_tokens": 10,
      "cache_hits": 1, "estimated_rows": 0,
      "total_latency_ms": 1500, "avg_latency_ms": 1500,
      "cost": 0.0021, "avg_cost": 0.0021
    }
  ]
}
```

### Cost reporting

`cost.total` and `per_model[].cost` are `null` when no row in the
group carried a provider-reported cost. The UI renders `null` as
`—`, never `$0.00`. Set `cost.available = true` only when at least
one row reported a numeric cost.

## Data sources

`StatisticsService` pulls from the existing history tables:

* `council_sessions` — session counts, status (`completed`/`failed`/
  `running`), `duration_ms`, `created_at`.
* `council_steps.meta` (JSON) — preferred source for per-call
  `provider`, `model`, `prompt_tokens`, `completion_tokens`,
  `total_tokens`, `latency_ms`, `cached_tokens`, `reasoning_tokens`,
  `cache_hit`, `tokens_estimated`, `cost`. `PipelineEngine` already
  writes the `ChatResponse::statsPayload()` plus the cache envelope
  into every L1 step's meta at completion time.
* `council_model_responses` — fallback for steps whose meta JSON is
  empty (the synthesis layer, or failed-step paths). Provides core
  token / latency figures only — no cost or cache info.

The `model_response_cache` table is **not** queried directly — its
hit-or-miss state is already mirrored into `council_steps.meta` as
`cache_hit`, so reading meta keeps the aggregator self-contained.

## Migration

The indexes below are already part of `db/schema.sql` — a fresh install
or a Settings → "ReCreate DB" creates them automatically, so no
migration file needs to be applied on a new host. The set is small and
indexes only:

* `council_sessions(created_at)` — chronological aggregation.
* `council_steps(session_id, step_key)` — speeds up per-call
  aggregation when the service pulls L1 step rows for a date window.
* `council_steps(started_at)` — supports time-window scans when the
  aggregator wants step-level chronology.

## Deploy steps

```bash
# 1. Pull main (StatisticTab is included as of v1.8.0).
git pull origin main

# 2. Enable the tab.
#    Edit config.php → set 'StatisticTab' => true (top-level or under 'app').

# 3. Confirm wiring.
curl -s http://localhost:8080/api/tabs | jq '.statistic'
# → true

# 4. Confirm admin gate.
curl -s -H "X-Admin-Token: $REVOAP_ADMIN_TOKEN" \
     "http://localhost:8080/api/statistics/summary?period=day" | jq '.ok'
# → true
```

## Verification

```bash
# Lint + smoke tests
php -l src/Statistics/StatisticsService.php
php -l src/Statistics/StatisticsAggregator.php
php -l src/Statistics/StatisticsPeriod.php
php -l src/Http/Controllers/StatisticsController.php
php tests/SmokeTest.php
```

The SmokeTest covers: flag resolution (top-level / nested / strict
boolean), `/api/tabs` exposure, `requireAdmin` gating (disabled tab
→ 404; missing token → 503; wrong token → 401), `no_database`
diagnostic when DB is null, period boundary math (year / quarter /
week / day / hour), and aggregator behaviour on synthetic rows
(session counts, token sums, cost null handling, cache_hit /
estimated aggregation, per-model breakdown order, no-secret-leakage
in error payloads).
