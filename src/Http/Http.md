# Module: Http

## Purpose
Thin HTTP layer: validates input, calls services, formats response.
Controllers contain no domain logic.

## Files
| File | Class | Responsibility |
|------|-------|----------------|
| Response.php                  | `App\Http\Response`                   | JSON / HTML / text response helpers with security headers. |
| Controllers/CouncilController.php | `App\Http\Controllers\CouncilController` | Run pipeline; fetch session/events. |
| Controllers/SkillController.php   | `App\Http\Controllers\SkillController`   | Skill REST CRUD. |
| Controllers/StatsController.php   | `App\Http\Controllers\StatsController`   | Per-session aggregated statistics. |
| Controllers/CreatorController.php | `App\Http\Controllers\CreatorController` | `?creator` author info page. |

## Public API (selected)
### Response
- `Response::json(array $data, int $status = 200): string`
- `Response::html(string $body, int $status = 200): string`
- `Response::text(string $body, int $status = 200): string`

### CouncilController
- `run(): string` — POST `/api/council/run`
- `getSession(array $params): string` — GET `/api/council/session/{id}`
- `getEvents(array $params): string`  — GET `/api/council/session/{id}/events`

### SkillController
- `index(): string` / `show($p)` / `create()` / `delete($p)`

### StatsController
- `show(array $params): string` — GET `/api/council/session/{id}/stats`

### CreatorController
- `show(): string` — `?creator` page

## Invariants & Constraints
- Controllers MUST NOT perform DB queries directly; they call
  repositories or services.
- All HTML responses set CSP headers via `Response::html()`.
- All JSON responses set `Content-Type: application/json; charset=utf-8`.
- `?creator` exposes only static author info — never config keys.

## Integration Points
- Called by: `index.php` route table.
- Calls: `Pipeline`, `Skills`, `Stats`, `Storage`.

## LLM Extension Guide
- Add a new controller as `final class` with constructor-injected
  dependencies.
- Wire the routes in `index.php`.
- Never call `die()`/`exit()` in a controller — return a string.
