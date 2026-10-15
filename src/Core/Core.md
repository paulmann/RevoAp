# Module: Core

## Purpose
Bootstraps the application, loads configuration, dispatches HTTP requests,
and provides the (optional) PDO connection used by repositories. Code in
this module is responsible for "wiring", never for domain logic.

## Files
| File | Class | Responsibility |
|------|-------|----------------|
| Config.php       | `App\Core\Config`      | Loads `config.php`, exposes `get()`/`getConnectorKey()` with .key/$_ENV fallback. |
| Router.php       | `App\Core\Router`      | Pattern-based HTTP method+URI dispatcher. |
| Application.php  | `App\Core\Application` | Bootstrap container; lazy PDO and `ConnectorRegistry`. |

## Public API
### Config
- `Config::load(string $file, string $rootDir): void` — load once.
- `Config::get(string $key, mixed $default = null): mixed` — dot-notation read.
- `Config::getConnectorKey(string $provider): string` — resolves key via priority order.
- `Config::getActiveModels(): array` — only `'active' => true` models.
- `Config::isDebug(): bool`.

### Router
- `add(string $method, string $pattern, callable $handler): void`
- `get/post/delete(...)` shorthands.
- `dispatch(string $method, string $uri, array $context = []): mixed`

### Application
- `router(): Router`
- `pdo(): ?PDO` — returns null when DSN is missing or connection fails.
- `connectors(): ConnectorRegistry`
- `isCreatorRequest(): bool`

## Invariants & Constraints
- `Config::load()` must be called before any other Config method.
- `?creator` is checked **before** routing and never reaches a controller other than `CreatorController`.
- `pdo()` must never throw — repositories are designed to also work in-memory when DB is absent.

## Integration Points
- Called by: `index.php`
- Calls: `App\Connectors\ConnectorRegistry`, repository constructors.

## LLM Extension Guide
- Add new routes by editing `index.php` (no edits needed in `Router.php`).
- New configuration sections must default safely; never throw if missing.
- Do **not** add static state outside `Config` — request lifecycle must remain stateless.
