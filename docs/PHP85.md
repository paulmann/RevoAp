# PHP 8.5 production readiness

RevoAp v2.0.0 requires **PHP ^8.5** (`composer.json`). This document records what
was verified against a real 8.5 runtime, what changed, and how to deploy it.

Everything below was checked with **PHP 8.5.8** (and 8.4.14 / 8.3.27 for
comparison) using static builds, not by reading a changelog.

---

## 1. Baseline verification

| Check | Result |
|---|---|
| `php -l` over every first-party file | **344 files, 0 syntax errors** |
| `E_DEPRECATED` across the whole test suite | **0** |
| Full suite | 79 pass / 6 fail — the same six environment-dependent tests that failed on the v1.9 baseline |
| New v2 suites | `I18nTest` 194 · `ModelFallbackTest` 134 · `PdoCompatTest` 46 · `LiveSmoke` 163 |

The six baseline failures are environmental, not code: they need provider API
keys, the migrations `001`–`010` that were consolidated into `db/schema.sql` in
v1.8.x, or a deployment-local `.config.php`. CI asserts that exact set so a **new**
failure cannot hide behind them.

Reproduce:

```bash
PHP=/path/to/php8.5 bash tools/run-tests.sh && tail -n 5 /tmp/testrun.txt
tools/scan85.sh .              # static scan for deprecated symbols
```

---

## 2. Deprecations removed

PHP 8.5 deprecated three things this codebase used, in 20 files. All were removed
by a tokeniser-driven codemod, then verified by re-running the suite with
`error_reporting=E_ALL`.

### `curl_close()` — 9 call sites

A no-op since PHP 8.0 (the handle is an object now), deprecated in 8.5. Removed
outright; the variable going out of scope is what frees the handle.

```
src/Api/WebhookDispatcher.php            src/Connectors/AbstractConnector.php (×2)
src/Connectors/Batch/CurlBatchTransport  src/Connectors/ParallelChatDispatcher.php
src/Extensions/MdViewerInstaller.php     src/Research/ArxivClient.php
src/Settings/ProviderTester.php          src/Storage/Vector/QdrantVectorStore.php
```

### `Reflection*::setAccessible()` — 18 call sites

A no-op since PHP 8.1, deprecated in 8.5. Removed from the test suite and
`bin/vector-memory-bench.php`. Reflection can access any property or method
directly now.

### `PDO::MYSQL_ATTR_*` — 5 files, 15 reads

This one is subtle, and it is why `App\Core\PdoCompat` exists.

The constants moved onto the new `Pdo\Mysql` / `Pdo\Pgsql` / `Pdo\Sqlite` classes.
**Merely reading the old constant emits `E_DEPRECATED`**, so the usual defensive
pattern does not help:

```php
if (defined('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY')) {   // true
    $opts[PDO::MYSQL_ATTR_USE_BUFFERED_QUERY] = true;  // ← deprecation raised here
}
```

On a busy console that is thousands of records per hour and a permanently red
error log. `PdoCompat::mysql()` resolves the constant **once per process** from
whichever spelling the runtime provides, and never touches the deprecated name
when the new one exists:

```php
$opts[PdoCompat::mysqlBufferedQuery()] = true;
```

The numeric values are identical in both spellings, which is what keeps a
`runtime.json` written by one PHP version readable by another.

> **A codemod bug this caught.** The mechanical rewrite also replaced the constant
> *inside* those `defined()` guards, producing `defined('PdoCompat::mysqlBufferedQuery()')`
> at five sites. That expression is always false, so the buffered-query attribute
> was **never set** — and without it a partially iterated `SELECT` leaves the
> connection unable to run a second statement (`SQLSTATE[HY000] 2014`), which is
> exactly what the job repository and the statistics aggregator do. Replaced with
> `PdoCompat::isMysqlDsn()` / `mysqlConnectionOptions()`, which is also the
> *correct* predicate: the attribute is MySQL-only and PDO rejects it at connect
> time on `sqlite:` / `pgsql:` — which would have broken the in-memory fallback.
> `tests/PdoCompatTest.php` now drives a real SQLite connection through the
> computed options.

> **A second bug it caught.** `PdoCompat`'s fallback table (used only on a build
> without `pdo_mysql`) had four wrong values, two of them transposed:
> `FOUND_ROWS` ↔ `LOCAL_INFILE`. On such a build "return matched rows" would have
> become "**allow `LOAD DATA LOCAL INFILE`**" — a server-side file-read primitive
> enabled by a typo, in a table no runtime path exercises. All nine values are now
> asserted against the live constants on every CI run.

### Also checked and clean

`get_class()` / `get_parent_class()` without arguments (8.4) · `E_STRICT` (8.4) ·
implicit nullable parameters (`Type $p = null`, 8.4) — a tokeniser scan of all 344
files found **zero** · `date_sunrise`/`date_sunset` · `strptime` ·
`xml_set_object` · `libxml_disable_entity_loader`.

---

## 3. 8.5 features actually probed

Verified empirically against 8.5.8, not assumed from an RFC list:

| Feature | Available | Used? |
|---|---|---|
| Pipe operator `\|>` | yes | not yet — no site where it reads better than a variable |
| `array_first()` / `array_last()` (8.4) | yes | not yet |
| `#[\NoReturn]` (8.5) | yes | not yet |
| `#[\Deprecated]` (8.4) | yes | not yet |
| Property hooks (8.4) | yes | no — the DTOs are `readonly` and hooks would make them mutable-looking |
| Asymmetric visibility (8.4) | yes | no |
| `readonly class` (8.2) | yes | **yes**, 22 classes |
| `new` in initializers (8.1) | yes | **yes** |
| Backed enums (8.1) | yes | **yes**, extensively — `Locale`, `FallbackMode`, `AvailabilityStatus`, `UnavailableReason`, `JobStatus` |
| First-class callable syntax (8.1) | yes | **yes** |
| DNF types (8.2) | yes | **yes** — `null\|PDO\|Database` in every repository constructor |
| Standalone `null`/`true`/`false` types (8.2) | yes | **yes** |
| `json_validate()` (8.3) | yes | available; `json_decode(…, true)` is still used where the value is wanted anyway |
| `mb_str_pad()` (8.3) | yes | available |
| `clone with` | **not in this build** | not used |

> **Enum gotcha worth recording.** A backed enum's own `from()` is `final` and
> throws on an unknown value. Three v2 enums declared a tolerant
> `public static function from(mixed $value)` and hit
> `Cannot redeclare …::from()` — a fatal at class-load time. Renamed to
> `parse()`. Any future enum that needs tolerant parsing must not use that name.

---

## 4. Extensions

Required: `pdo` · `curl` · `json` · `mbstring` · `hash` · `openssl`.

Suggested (`composer.json` → `suggest`, each with the impact of not having it):

| Extension | Without it |
|---|---|
| `intl` | `App\I18n\Translator` falls back to a built-in table. The fallback is locale-shaped, not merely unambiguous — `zh` still renders `2023年11月14日`. CI runs the whole i18n suite **without** intl so the fallback cannot rot. |
| `opcache` | Expect a several-fold slowdown. See `deploy/php/opcache-recommended.ini`. |
| `redis` | The shared cache backend for multi-worker deployments. |
| `pcntl` | `bin/revoap-worker.php` cannot handle `SIGTERM`/`SIGUSR1` for graceful draining under systemd. |
| `zlib` | No compressed audit-log rotation or response-cache storage. |

The **System** tab reports all of this at runtime, including which are missing and
what each one costs you.

---

## 5. Deploying

### OPcache

```ini
opcache.enable=1
opcache.enable_cli=0            ; CLI workers re-read code every run
opcache.memory_consumption=256
opcache.interned_strings_buffer=32
opcache.max_accelerated_files=20000
opcache.validate_timestamps=0   ; production: deploy = reload, not mtime polling
opcache.save_comments=1         ; docblocks drive several annotations
opcache.jit=tracing
opcache.jit_buffer_size=128M
opcache.preload=/var/www/revoap/deploy/php/preload.php
opcache.preload_user=www-data
```

`validate_timestamps=0` is the one that matters: with it on, OPcache stats every
file on every request. With it off, **a deploy must reload php-fpm** — otherwise
the console keeps serving old bytecode and `/api/debug`'s `build_id` is the only
clue. That build id exists for exactly this reason; the System tab shows it.

### php.ini

```ini
memory_limit=512M               ; a 5-model L1 fan-out holds every response in memory
max_execution_time=600          ; legacy inline mode outlives 30 s easily
post_max_size=32M               ; QueryAttachments accepts files
upload_max_file_size=32M
expose_php=0
session.cookie_httponly=1
session.cookie_secure=1         ; behind TLS
session.cookie_samesite=Lax
session.use_strict_mode=1
```

### Worker under systemd

`deploy/systemd/revoap-worker.service`. The worker reports its **code version**
through `revoap_worker_presence`, and the Queue panel says plainly when a running
worker is on stale code — the failure mode where a deploy lands, the web tier
picks it up, and the worker keeps running last week's pipeline for hours.

### Web server

See [`SECURITY.md`](SECURITY.md) §S5. If nginx serves `/assets` statically (the
faster, common setup), include `nginx/security-hardening.include` — otherwise
`/assets/templates/*.html`, `/i18n/*.json`, `/db/*.sql` and `/tools/*` are
directly readable, because the application's own allow-list only runs for requests
that reach PHP. Apache is covered by the shipped `.htaccess` files.

### Database

```bash
mysql -u root revoap < db/schema.sql                 # fresh install
# or, for an existing deployment:
mysql -u root revoap < db/migrations/023_v2_admin_and_fallback.sql
```

`db/schema.sql` is the single source of truth and is idempotent — safe to
re-import against a populated database. Migration 023 adds 13 tables. Every
statement is `CREATE TABLE IF NOT EXISTS`; no DML ships, because a migration that
seeds data silently overwrites an operator's choices on the second apply.

The app boots **without** MySQL. The in-memory fallback covers the pipeline;
History, Statistic, the job queue and the audit trail need a database.

---

## 6. Upgrading from v1.9

1. **Create `.admin.key` before deploying.** `Protect_Settings_by_Admin_Key` now
   defaults to `1`, and the gate fails closed: protection on without a key file
   means the admin surface is unreachable.
   ```bash
   openssl rand -hex 32 > .admin.key && chmod 600 .admin.key
   chown www-data:www-data .admin.key
   ```
   To keep an open intranet install, set `0` in `.config.php`. See
   [`SECURITY.md`](SECURITY.md) §S1.
2. Apply migration 023 (or re-import `db/schema.sql`).
3. Reload php-fpm and **restart the worker** — it will otherwise report stale code.
4. Add the nginx hardening include, or confirm Apache is reading the `.htaccess`
   files (`AllowOverride All` on the document root).
5. Check the **System** tab: PHP version, missing extensions, OPcache state,
   writable paths, build id.

Nothing in the v1 REST surface changed. `/v1/*` and `/api/*` keep their contracts;
the v2 routes are additive.
