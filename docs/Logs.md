# RevoAp — Admin Logs

Since v1.5.0 RevoAp exposes an in-app Logs tab so an operator can
inspect the project's whitelisted log files (debug, PHP error, access)
without shell access.

The tab is gated by **two** independent layers — both must pass:

1. **Config flag** `LogsTab` in `config.php`. Set to `true` to enable.
2. **Admin token** (`REVOAP_ADMIN_TOKEN` env or `.admin.key`).

When the flag is `false`, the tab is hidden in the UI and every
`/api/logs/*` endpoint replies `404 logs_disabled`. When the flag is
`true` but the admin token is missing, endpoints reply
`503 admin_not_configured`. When the token is configured but the
request does not present it, endpoints reply `401 unauthorized`.

## Configuration

```php
return [
    'app' => [
        'SettingsTab' => true,   // admin Settings tab
        'LogsTab'     => true,   // admin Logs tab
    ],
    // Top-level aliases — operator-friendly form. Either layer is honored;
    // the top-level form wins when both are set. Strict comparison: only
    // boolean `true` enables the tab.
    'SettingsTab' => true,
    'LogsTab'     => true,
    // ...
];
```

## Endpoints

| Method | Path                | Auth | Notes |
|--------|---------------------|------|-------|
| GET    | `/api/tabs`         | none | Returns `{settings: bool, logs: bool}` so the front-end can decide which top-nav tabs to render. |
| GET    | `/api/logs/status`  | none | Returns `tab_enabled / admin_configured / admin_unlocked`. |
| GET    | `/api/logs/list`    | admin | Lists whitelisted files with `slug / label / filename / exists / size / mtime / writable`. |
| GET    | `/api/logs/tail`    | admin | `?slug=…&lines=…`. Returns the last N lines of a whitelisted file. Lines are sanitized before display. |
| POST   | `/api/logs/clear`   | admin | `{slug: …}`. Truncates one whitelisted file. Destructive — the UI requires confirmation. |

## Whitelist

Only files registered in `LogsService::whitelist()` are exposed:

| Slug              | Filename                  |
|-------------------|---------------------------|
| `revoap-debug` | `logs/revoap-debug.log` |
| `php-error`    | `logs/php-error.log`      |
| `access`          | `logs/access.log`         |

The slug is the only client-supplied input; it is matched exactly
against the whitelist. Path traversal cannot reach files outside
`logs/`. As a defense-in-depth, the resolved real path is verified to
stay inside the project logs directory.

## Sanitization

Every line returned by `/api/logs/tail` is passed through
`Logger::sanitizeScalar()`. The same redaction layer used elsewhere in
the application removes:

- `Bearer …` tokens
- DSN passwords (`password=…`, `pass=…`, `pwd=…`)
- Query-string secrets (`?api_key=…`, `&token=…`)
- `sk-…` style API keys
- Absolute filesystem paths (replaced with `<project>` / `<home>`)

The front-end renders log content with `textContent` only — never
`innerHTML` — so even sanitized lines cannot inject markup.

## Bandwidth caps

`tail` accepts `lines=` between 1 and 5000 (`LogsService::TAIL_MAX_LINES`).
The default is 500. The reader streams from the end of the file in
8 KiB chunks so multi-megabyte logs do not pull the whole file into
memory.

## Tests

`tests/SmokeTest.php` covers:

- `Config::isSettingsTabEnabled / isLogsTabEnabled` precedence + strict-true.
- `SettingsController` + `LogsController` return `*_disabled` (404) when
  the matching flag is `false`.
- Whitelist enforcement: unknown / empty / traversal slugs return null.
- Tail sanitization: Bearer, `sk-…`, DSN passwords, `?api_key=` tokens
  never reach the wire.
- `clear` truncates the file in place; refuses unknown slugs.
- Admin gate: missing token → 503, wrong token → 401, correct → 200.
