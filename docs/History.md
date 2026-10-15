# RevoAp — Admin History

Since v1.7.0 RevoAp exposes an in-app **History** tab so an operator
can browse every past council session — the original prompt, the
synthesised answer, every L0/L1/L2/L3/L4 step, every model response,
every event, the consensus result and the runtime token / cost
totals — without shell access or direct SQL.

The tab is gated by **two** independent layers and additionally
requires a **configured database**. All three must pass:

1. **Config flag** `HistoryTab` in `config.php`. Default is `false`.
2. **Admin token** (`REVOAP_ADMIN_TOKEN` env or `.admin.key`).
3. **Database** — persistent history requires a working DSN. When the
   DB is unavailable the tab renders a clear `no_database` diagnostic
   instead of silently failing.

Why default off: every prompt and every model output is stored in
plaintext (subject to the standard secret-redaction filter), so the
operator must consciously opt in.

## Configuration

```php
return [
    'app' => [
        'SettingsTab' => true,
        'LogsTab'     => true,
        'SecurityTab' => false,
        'HistoryTab'  => false,
    ],
    // Top-level aliases — operator-friendly form. Either layer is
    // honored; the top-level form wins when both are set. Strict
    // comparison: only boolean `true` enables the tab.
    'HistoryTab' => false,
    // ...
];
```

## Endpoints

| Method | Path                              | Auth  | Notes |
|--------|-----------------------------------|-------|-------|
| GET    | `/api/tabs`                       | none  | Includes `history: bool`. |
| GET    | `/api/history/status`             | none  | `tab_enabled / admin_configured / admin_unlocked / db_available / db_migrated`. |
| GET    | `/api/history/list`               | admin | `?limit, ?offset, ?q, ?status`. Returns paginated list with year/month group counts. |
| GET    | `/api/history/session/{id}`       | admin | Full pipeline detail for one session. `404` when missing. |

### `/api/history/list` parameters

* `limit` — page size (default 25, max 100).
* `offset` — pagination offset (default 0).
* `q` — case-insensitive substring match against `query_text`,
  `final_answer` and `skill_slug`. Maximum length 256 chars.
* `status` — one of `running`, `completed`, `failed`. Anything else
  is ignored.

### Response shape (`/api/history/list`)

```json
{
  "ok":       true,
  "total":    1234,
  "limit":    25,
  "offset":   0,
  "has_more": true,
  "q":        "",
  "status":   null,
  "items":    [
    {
      "id":               42,
      "created_at":       "2026-05-08 10:24:01",
      "completed_at":     "2026-05-08 10:24:18",
      "bucket":           "2026-05",
      "status":           "completed",
      "consensus_status": "agreement",
      "consensus_score":  0.91,
      "total_tokens":     7421,
      "duration_ms":      17290,
      "skill_slug":       "code-explainer",
      "skill_name":       "Code explainer",
      "query_preview":    "Explain the bubble sort algorithm…",
      "answer_preview":   "Bubble sort iterates…",
      "model_count":      3,
      "reasoning":        "medium"
    }
  ],
  "groups":   [
    {"key": "2026-05", "label": "May 2026", "count": 18},
    {"key": "2026-04", "label": "April 2026", "count": 7}
  ]
}
```

### Response shape (`/api/history/session/{id}`)

```json
{
  "ok":      true,
  "session": { "...header...": "..." },
  "steps":   [
    {
      "id": 101, "step_key": "L1_role:critic",
      "label": "L1 — critic",
      "order_index": 4, "status": "completed",
      "summary": "...full L1 answer...",
      "meta": { "model": "openai/gpt-4o", "...": "..." },
      "started_at": "...", "ended_at": "...",
      "responses": [
        { "model": "openai/gpt-4o", "provider": "bothub",
          "role_name": "critic",
          "raw_content": "...", "normalized": {...},
          "prompt_tokens": 120, "completion_tokens": 240,
          "total_tokens": 360, "latency_ms": 1230,
          "tokens_estimated": false }
      ]
    }
  ],
  "events":  [
    { "id": 1, "step_id": null, "event_type": "L0_OPTIONS",
      "payload": {"selected_models": [...]}, "created_at": "..." }
  ]
}
```

## Database requirement

History is read-only on top of the existing pipeline tables
(`council_sessions`, `council_steps`, `council_model_responses`,
`pipeline_events`) plus two metadata columns on `council_sessions`
defined in `db/schema.sql`:

| Column                       | Why |
|------------------------------|-----|
| `council_sessions.skill_slug`| Denormalised slug for list filtering / display. |
| `council_sessions.options_json` | Snapshot of runtime options (selected models, reasoning, parallel\_l1) so a session can be replayed. |

The migration also creates supporting indexes:

* `idx_sessions_created_at` for chronological browsing.
* `idx_sessions_skill_slug` for skill-filtered listings.
* `ft_sessions_search` (FULLTEXT on query\_text + final\_answer) for
  future query expansion. The current implementation uses LIKE so
  short search terms still match.

## Deployment

The metadata columns and indexes above are already part of
`db/schema.sql` — a fresh install or a Settings → "ReCreate DB" creates
them automatically. There is no per-feature migration file to apply.

```sh
# Enable the tab
# (in config.php)
'HistoryTab' => true,

# Make sure the admin token is configured
echo 'your-strong-token' > .admin.key
chmod 600 .admin.key
```

Sessions that pre-date migration 004 still appear in the list — the
`skill_slug` and `options_json` fields are simply `null` until the
next pipeline run. The pipeline writes the new columns best-effort,
so a host that has the flag on but has not yet applied the migration
keeps running without errors (the UPDATE silently no-ops).

## Security

* Backend gates: `HistoryTab=true` AND admin token, in that order.
* Every free-form text field (query, final answer, raw model output,
  step summary, event payload) is passed through
  `Logger::sanitizeScalar` before serialization. Bearer tokens, DSN
  passwords, query-string secrets and absolute project paths are
  redacted; legitimate model output is preserved verbatim.
* The UI renders all text via `textContent` only — never `innerHTML`.
* The admin cookie is HttpOnly + SameSite=Lax (set by the existing
  `AdminAuth::setSessionCookie`).
* Pagination is hard-capped at 100 items per page to bound payload
  size; search queries are length-limited to 256 chars.

## Verification commands

```sh
# Public probe (no auth required)
curl -s http://localhost:8080/api/history/status | jq

# Admin list (replace TOKEN)
curl -s -H 'X-Admin-Token: TOKEN' \
     'http://localhost:8080/api/history/list?limit=5'

# Single session detail
curl -s -H 'X-Admin-Token: TOKEN' \
     http://localhost:8080/api/history/session/42

# Tab visibility probe
curl -s http://localhost:8080/api/tabs | jq
```
