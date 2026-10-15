# Module: Logs

## Purpose
Read-only access layer for whitelisted log files. Backs the admin Logs
tab introduced in v1.5.0. Exposes only the files explicitly registered
in `LogsService::whitelist()`; sanitizes every line through the shared
`Logger::sanitizeScalar()` redactor before it leaves the server.

## Files
| File              | Class                | Responsibility |
|-------------------|----------------------|----------------|
| `LogsService.php` | `App\Logs\LogsService` | Whitelist resolution, efficient tail, clear, sanitization. |

## Public API
### LogsService::__construct(string $rootDir)
- **Input**: project root directory.
- **Output**: `LogsService` instance.

### LogsService::logsDir()
- **Output**: `string` — absolute path to the logs directory (`config.logging.path` or `{root}/logs`).

### LogsService::whitelist()
- **Output**: `array<string,{slug,filename,label}>` — exposed log files.

### LogsService::list()
- **Output**: `list<array{slug,label,filename,exists,size,mtime,writable}>`.

### LogsService::resolve(string $slug)
- **Output**: `?string` — absolute path or `null` for unknown slugs.

### LogsService::tail(string $slug, int $lines = 500)
- **Output**: `?array{slug,filename,label,exists,size,mtime,lines,truncated,content}` — sanitized content or `null` for unknown slugs.

### LogsService::clear(string $slug)
- **Output**: `bool` — true on truncate success.

## Invariants & Constraints
- `tail()` and `resolve()` MUST return `null` for slugs not in the whitelist.
- The resolved real path MUST live inside the configured logs directory.
- `tail()` content MUST pass through `Logger::sanitizeScalar()` line-by-line.
- `tail()` MUST honor `lines` ∈ [1, `TAIL_MAX_LINES` (5000)].
- Chunked tail reader MUST NOT load the entire file into memory.

## Integration Points
- Called by: `App\Http\Controllers\LogsController`.
- Calls: `App\Core\Config`, `App\Core\Logger`.

## LLM Extension Guide
- To expose a new log file, add an entry to `LogsService::whitelist()`.
  The slug becomes the URL parameter; the filename is resolved relative
  to the logs directory. Do NOT accept arbitrary paths from clients.
- If you add a new redaction pattern, put it in `Logger::sanitizeScalar()`
  so every callsite (logger writes, debug payloads, log tails) inherits
  the change.
- Any new endpoint MUST be gated by both `Config::isLogsTabEnabled()`
  AND `AdminAuth::check()` exactly as the existing `LogsController`
  methods are.
