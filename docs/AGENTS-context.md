# Контекст для агента: RevoAp

Назначение файла: собрать в одном месте проектные знания, которые иначе
приходится заново вычитывать из `SKILL.md`, `PROJECT_MAP.md`, `TECHNICAL_SPECIFICATION.md`,
`ROAD_MAP.md` и кода. Читать перед правками в незнакомых подсистемах; перед
любым утверждением о поведении — открывать код.

Источник истины — код. Всё, что не подтверждено файлом, помечено как
неподтверждённое. Ничего здесь не проверялось прогоном приложения, миграций
или деплоя.

---

## 1. Что это за продукт

RevoAp — веб-платформа «AI-консенсуса»: пользовательский запрос отправляется
сразу нескольким LLM, ответы нормализуются, между ними считается согласие,
затем синтезируется итоговый ответ, а весь ход прогона остаётся в MySQL как
append-only аудит-трейл, который SPA показывает по шагам с токен-статистикой.

Две аудитории:

- **оператор** — SPA с вкладками Chat / Models / Skills / Research / Settings /
  Logs / Security / History / Statistic;
- **внешние интеграции** — REST `/v1` с API-ключами (`docs/api-rest.md`).

Вход: `query` + до 6 текстовых аттачментов (128 000 символов на файл,
300 000 суммарно), пул моделей, скилл, reasoning, режим экономии.
Выход: `final_answer`, `consensus` (score/status), шаги L0–L4, события,
per-step статистика.

## 2. Пайплайн и консенсус

- **L0** — выбор или автосоздание скилла (Automatic Skill Selector).
- **L1** — каждый выбранный пользователем `role@model` отвечает параллельно
  (`ParallelChatDispatcher`, флаг `pipeline.parallel_l1`).
- **L2** — структурная нормализация, **LLM не вызывается**.
- **L3** — согласие (`SemanticConsensusEngine` по умолчанию;
  вспомогательный `ConsensusEngine`, старый режим `lexical_jaccard`).
- **L4** — синтез настроенной моделью.

Инварианты пайплайна (`src/Pipeline/Pipeline.md`):

- шаги идут только вперёд L0→L4; сбой шага порождает новое событие
  `*_FAILED`, а не откат назад;
- записи и события **только дописываются**; изменение прошлых шагов запрещено
  (это же зафиксировано в `docs/ADR-0001-append-only-pipeline.md`);
- повтор `step_key` даёт `LOOP_DETECTED` и остановку;
- префиксы шагов: `L1.role.<key>`, `L2.normalize.<key>`, `L3.consensus`,
  `L4.synthesis`;
- недоступный синтезатор — детерминированный fallback, а не исключение.

Второй круг: если L3 не дал `agreement`, L1-вызовы повторяются с промптом-ревизией
(модель получает свой прошлый ответ, ответы остальных участников без своего,
score первого круга, lateral-thinking системный промпт). Бюджет —
`pipeline.max_consensus_rounds` (2), per-request `cycle_limit` жёстко клампится
в диапазон 1..5 с событием `CONSENSUS_CYCLE_LIMIT_CLAMPED`; ранняя остановка при
стагнации (`consensus_stagnation_epsilon`).

Согласие: компоненты (claims / bullets / sentences) → fingerprints (основы слов,
3-граммы, числа и сущности) → жадная кластеризация; score — доля кросс-модельного
содержимого в общих кластерах. Ориентиры: `SIMILARITY_THRESHOLD = 0.18`,
`MIN_CLUSTER_COVERAGE = 2`, `NUMERIC_CONTRADICTION_PENALTY = 0.15`,
`UNANIMOUS_BONUS = 0.05`. Пороги статусов: ≥ 0.85 — agreement, ≥ 0.55 — partial,
иначе conflict; один ответ — тривиальное согласие 1.0; отсутствие сравнимых
компонентов — conflict 0.0. Пороги монотонны по контракту `src/Consensus/Consensus.md`.

Влияние на результат: статус не `agreement` вызывает конфликтного арбитра и
дисклеймер в итоговом ответе; цель синтеза выбирается по цепочке
`selected_models` → активная модель OpenRouter → первая доступная активная,
с событием `L4_PROVIDER_FALLBACK`. L3 дополнительно пишет человекочитаемое
объяснение (`ConsensusExplainer`: what / who / decision / conclusion).

## 3. Компоненты `src/`

| Каталог | Ответственность | Ключевые файлы |
|---|---|---|
| `Analyzer/` | анализ запроса, рекомендации по составу | `RequestAnalyzer.php` |
| `Api/` | внешний REST `/v1`: ключи, авторизация, диалекты, вебхуки | `ApiAuth.php`, `ApiV1Controller.php`, `FormatAdapter.php`, `WebhookDispatcher.php` |
| `Batch/` | пакетный контур поверх Batch API провайдера | — |
| `Cache/` | кэш ответов моделей и статистика попаданий | `ModelResponseCache.php`, `CacheStatistics.php` |
| `Connectors/` | провайдеры LLM, ретраи, breaker, батч, prompt-cache | `AbstractConnector.php`, `ConnectorRegistry.php`, `CallPolicy.php`, `ModelBreaker.php`, `ParallelChatDispatcher.php` |
| `Consensus/` | согласие, нормализация, объяснение | `SemanticConsensusEngine.php`, `ConsensusEngine.php`, `NormalizationService.php`, `ConsensusExplainer.php` |
| `Core/` | ядро: bootstrap, config, БД, роутер, лог, админ-гейт | `AdminAuth.php`, `Application.php`, `Database.php`, `Router.php` |
| `Cost/` | экономика: режимы, оценки, история, пригодность батча | `EconomyMode.php`, `RunCostEstimator.php`, `EconomyAdvisor.php`, `CostHistory.php` |
| `Diagnostics/` | диагностика | — |
| `DomainPresets/` | пресеты домена и роутер «задача → пресет» | — |
| `Extensions/` | установка расширений (в т. ч. MD.Viewer) | `MdViewerInstaller.php`, `MdViewerApi.php`, `MdViewerIni.php` |
| `Helpers/` | UTF-8, JSON, шаблоны, время, токены | — |
| `History/` | история прогонов, экспорт, закрытие сессий | `SessionCompletionService.php` |
| `Http/` | контроллеры и охрана периметра | `OriginGuard.php`, `SettingsController.php`, `JobsController.php`, `CouncilController.php` |
| `Jobs/` | очередь и восстановление | `JobRepository.php`, `JobService.php`, `PipelineJobRunner.php`, `WorkerJobExecutor.php`, `SessionSafetyNet.php` |
| `Logs/` | просмотр whitelisted логов | — |
| `Memory/` | память прогонов (embeddings) | `EmbeddingMemory.php`, `PdoMemoryRepository.php` |
| `Models/` | каталог моделей, актуализация, отчёт синхронизации | `ModelsActualizer.php`, `ModelsSnapshotStore.php`, `ModelImporter.php` |
| `Pipeline/` | движок консилиума | `PipelineEngine.php`, `PipelinePlan.php`, `ModelSubstitution.php`, `RunControl.php` |
| `Profiles/` | профили и отпечатки промптов | `PromptFingerprint*` |
| `Research/` | поиск и разбор публикаций (arXiv) | `ArxivClient`, `ArxivAtomParser`, `PaperAnalyst`, `PaperRanker` |
| `Security/` | IP-фаервол периметра | `IpFirewall.php`, `IpMatcher.php`, `ClientIp.php`, `SecurityStore.php` |
| `Settings/` | настройки, миграции, SQL-исполнитель, здоровье системы | `MigrationManager.php`, `SqlExecutor.php`, `SchemaInspector.php`, `SystemHealthService.php` |
| `Skills/` | скиллы: загрузка, роутинг, автогенерация, каталог | `SkillLoader.php`, `SkillRouter.php`, `SkillAutoCreator.php`, `definitions/*.json` |
| `Statistics/` | агрегированная статистика | — |
| `Stats/` | сбор токенов и латентности по шагам | — |
| `Storage/` | репозитории PDO | `SessionRepository.php`, `StepRepository.php`, `ResponseRepository.php`, `ShareRepository.php`, `Vector/QdrantVectorStore.php` |
| `Worker/` | диагностика воркера, фид задач | `WorkerDiagnostics`, `WorkerJobFeed` |

## 4. Модель данных и её инварианты

Источник: `db/schema.sql` (шапка `-- @schema-version 1.9.4`), миграции
`db/migrations/014…021`.

Ядро:

- `council_sessions` — запрос, итоговый ответ, `consensus_score DECIMAL(5,4)`,
  `consensus_status ENUM(pending|agreement|partial|conflict)`,
  `status ENUM(running|completed|failed)`, токены, длительность, `options_json`,
  FULLTEXT по запросу и ответу;
- `council_steps` — `session_id`, `step_key VARCHAR(191)`, `order_index`,
  `status ENUM(running|completed|failed|skipped)`, `summary MEDIUMTEXT`, `meta JSON`;
- `council_model_responses` — `step_id`, модель, провайдер, роль,
  `raw_content`, `normalized_response`, токены, `latency_ms`,
  `tokens_estimated`, `cache_read_tokens` / `cache_write_tokens` (NULL-able),
  `cache_discount` (знаковый), `cost DECIMAL(18,10) NULL`;
- `pipeline_events` — журнал событий для SSE-реплея.

Периферия: `skills`, `model_response_cache`, `shared_sessions` (публичные
share-токены), `api_keys` (только SHA-256 `key_hash`), `webhooks` +
`webhook_deliveries`, `revoap_jobs` + `revoap_job_events` +
`revoap_worker_presence`, `research_runs` + `research_seen`,
`memory_items`, `vector_embeddings`, `domain_presets`, `users` /
`user_profiles`, `app_config`, `schema_migrations`.

Связи: Session 1—N Steps 1—N Responses; `revoap_jobs.session_id →
council_sessions.id`; события привязаны к сессии и шагу.

**Ключевой инвариант данных: NULL ≠ 0.** Поля `cost` и cache-поля, равные NULL,
означают «провайдер не сообщил значение»; 0 означает «сообщил ноль». Схема явно
запрещает суммировать NULL как ноль — иначе экономика врёт. `cache_discount`
может быть отрицательным: запись в кэш бывает дороже чтения.

Версионирование схемы (конвенция с v1.8.40):

- `db/schema.sql` — источник истины; в шапке `-- @schema-version`, у каждой
  таблицы `-- @table-version <table> <semver>`;
- изменение таблицы = правка `CREATE TABLE` + bump `@table-version` и
  `@schema-version`; при необходимости — идемпотентная миграция
  `db/migrations/NNN_*.sql` с тем же `@table-version`;
- применение миграций — `App\Settings\MigrationManager` (трекер
  `schema_migrations`), запускается из **Settings → Database maintenance**
  (`SettingsController`), а не из History, как ошибочно сказано в `PROJECT_MAP.md`;
- после успешного применения версии пишутся в `app_config`, а `SchemaInspector`
  («Check DB») сверяет фактический набор таблиц с ожидаемым;
- **откат миграций не предусмотрен** — только идемпотентность, трекер и
  файловые бэкапы на стороне деплоя.

## 5. Коннекторы, деградация, политика вызовов

- Провайдеры: `BothubConnector`, `OpenRouterConnector`, плюс прямые
  (OpenAI / Anthropic / Gemini / DeepSeek / xAI) через `config/models/direct.php`.
- Контракт `ConnectorInterface`: `chat`, `embed`, `listModels`,
  `getProviderName`, `isAvailable`. Инвариант `ChatResponse`:
  `totalTokens === promptTokens + completionTokens`. У OpenRouter
  `embed` обязан бросать `UnsupportedOperationException`.
- `ConnectorRegistry` находит коннекторы автоматически по `*Connector.php`,
  пропускает абстрактные классы и конструкторы с обязательными аргументами;
  отброшенные видны в диагностике через `skipped()`.
- Резолв ключа, по порядку: `connectors.{provider}.api_key` (при `active => true`)
  → файл `.{provider}.key` в корне (алиасы `.claude.key`, `.gemini.key`,
  `.google.key`, `.grok.key`) → `$_ENV[{PROVIDER}_API_KEY]` → исключение
  `ConnectorKeyNotFoundException`. Ключи **никогда** не попадают в логи, тексты
  ошибок, DTO и кэш.
- `AbstractConnector`: общий cURL, `timeout_chat = 120s`, `timeout_embed = 30s`,
  `maxRetries = 3`, потолок 3600s; служебные опции (`stream`, `timeout`) не
  уходят провайдеру; ретраи транспорта (429/5xx с backoff) — внутри коннектора.
- `CallPolicy`: TRANSIENT (timeout, rate_limit, server, connection) ретраится,
  PERMANENT (not_found, rejected, budget, breaker, other) — нет. Настройки:
  `model_timeout_s = 180`, `model_retry_budget = 1`, `breaker_failures = 2`.
- `ModelBreaker` — **per-run и in-memory**: открытый breaker означает отказ
  участника в текущем прогоне, а не глобальное здоровье модели. Кросс-ран
  статистики отказов нет.
- Замена выпавшей модели — отдельный механизм `ModelSubstitution` (и добор
  участника на следующем круге), а не retry: смена модели меняет состав
  консилиума.

## 6. Экономика, кэш, статистика

- `economy.mode`: `off` / `suggest` (советует, прогон не меняет) / `prefer`
  (включает дешёвый путь). Пороги: `cache_min_calls = 20`,
  `cache_min_hit_rate = 0.05`, `batch_min_seconds = 120`.
  `budget_per_run` = null/≤0 означает «потолка нет», а не «ноль»; приложение
  предупреждает, а не блокирует.
- `RunCostEstimator` только считает по переданным наблюдениям и возвращает
  диапазоны; без истории даёт null с честной пометкой «unknown, not a zero».
  Валюты не смешиваются — единицы те, что сообщил провайдер.
- `ModelResponseCache`: ключ = sha256(провайдер, модель, нормализованные
  сообщения, значимые опции); `VERSION = 3`, и её bump инвалидирует все прежние
  записи; служебные опции (`stream`, `timeout`, `request_id`, `job_id`,
  `session_id`) в ключ не входят. Бэкенды по предпочтению: MySQL
  `model_response_cache` → файлы `cache/…` → legacy flat
  `var/cache/model-responses` (только чтение). TTL — `cache.model_responses.ttl_seconds`.
- **Ошибки, пустые ответы и отфильтрованные цензурой ответы не кэшируются
  никогда**; любой сбой бэкенда деградирует к обычному сетевому вызову,
  а не к исключению. Попадания видны как `cache_hit = true` и в
  `CacheStatistics`.
- Что защищает счёт: `cost` никогда не подменяется нулём, `tokens_estimated`
  помечает оценки, `cycle_limit` клампится на сервере.

## 7. Очередь, воркер, восстановление

- Очередь `revoap_jobs`: `session_id`, статус, приоритет, `attempts`,
  `max_attempts`, payload/result/error JSON, `locked_by`/`locked_at`,
  `heartbeat_at`, `next_run_at`, `last_recovered_at`, `recovery_reason`.
  Статусы: queued, running, pause_requested, paused, resume_requested,
  cancel_requested, cancelled, consensus_requested, completed, failed, stale.
- Два пути исполнения: легаси синхронный (`POST /api/council/run` +
  `GET /api/council/stream`, живёт в PHP-FPM) и durable
  (`POST /api/council/jobs` → CLI-воркер). SSE-эндпоинт
  `/api/council/session/{id}/events/stream` **только реплеит** записанные
  `pipeline_events` и пайплайн не исполняет.
- `bin/revoap-worker.php`: только CLI (иначе отказ); режимы `--once` / `--loop`,
  параметры `--sleep`, `--worker-id`, `--max-jobs`, `--stale-after`,
  `--max-attempts`, `--backoff-base`, `--backoff-max`, `--no-auto-recovery`,
  `--status`, `--help`; коды выхода 0/2/3/4 (важны для systemd). Захват задач —
  row lock + `FOR UPDATE SKIP LOCKED`.
- Автозапуск: `deploy/systemd/revoap-worker.service` (singleton) и
  `revoap-worker@.service` (пул), `EnvironmentFile=/etc/revoap/worker.env`,
  `Restart=always`, hardening-опции, `TimeoutStopSec=600`.
- Автовосстановление: задачи с heartbeat старше `stale_after` (по умолчанию 300 с)
  переклеймиваются и переставляются в очередь до исчерпания бюджета, backoff ×1/×4/×10;
  `--no-auto-recovery` оставляет их в `stale`. `cancelAbandoned` доводит
  брошенный `cancel_requested` до `cancelled`.
- Устойчивость к БД: соединение проверяется перед задачей; потеря во время
  выполнения — reconnect и ровно один повтор; повторный сбой —
  `requeueForInfrastructure` **без списания** бюджета модельных попыток
  (`attempts` считает только модельные отказы). События `worker.db.lost` /
  `worker.db.reconnected`.
- `SessionSafetyNet` закрывает сессию терминальной задачи только если она ещё
  `running` и не имеет собственного ответа; настоящий ответ никогда не
  перезаписывается. Служебный текст «# Run closed by the worker…» — маркер
  автозакрытия.
- Presence: `revoap_worker_presence` обновляется раз в секунду и удаляется при
  чистом выходе — так различаются «idle», «не запущен» и «упал».
- Идемпотентность: lock-поля и per-row `max_attempts`; доставка вебхука —
  одна строка на пару (вебхук, событие), повтор отправляет тот же payload
  с новой подписью.

## 8. API, безопасность, границы доверия

- Внешний контур `/v1`: ключ формата `rv_` + 48 hex, хранится только как SHA-256;
  транспорты по приоритету `X-API-Key` → `Authorization: Bearer` → `?api_key`;
  scope `full` / `read` (по умолчанию `read` — least privilege). Операторские
  пути (`/api/settings`, `/api/logs`, `/api/security`, `/api/history`,
  `/api/skills`, `/api/webhooks`, `/api/profile`, `/api/share/session` …)
  deny-listed и отвечают 404 даже для `full`. Rate-limit per-key (rpm/daily) и
  per-IP 120/мин **до** разбора ключа (антиперебор), 429 с `Retry-After`.
  Три диалекта запроса: native / openai / anthropic (`FormatAdapter`).
- Админ-авторизация (`src/Core/AdminAuth.php`): токен из `REVOAP_ADMIN_TOKEN`
  (legacy-алиас `OPEN_CORUM_ADMIN_TOKEN`) → файл `.admin.key` в корне → config
  `admin.token`; сравнение `hash_equals`; если ничего не сконфигурировано —
  503 (явный opt-in, fail-closed). Диагностика показывает источник и наличие
  файла, но никогда значение.
- Флаги вкладок строгие: учитывается только литеральное `true`; при `false`
  эндпоинты отвечают `404 *_disabled`.
- Периметр: `IpFirewall::enforce()` вызывается в `index.php` до маршрутизации;
  loopback разрешён всегда, blacklist важнее whitelist, `X-Forwarded-For`
  учитывается только от доверенных прокси, отказы логируются санитизированно,
  состояние — `var/config/runtime.json` (0600).
- `OriginGuard` вызывается в `index.php`; проверяются только
  POST/PUT/PATCH/DELETE; при наличии `Origin` решает он, иначе `Referer`;
  **отсутствие обоих заголовков = запрос разрешён** (curl, CLI, деплой-пробы).
  Это не защита от не-браузерного абьюза — не путать.
- Заголовки ответа: CSP `default-src 'self'`, `frame-ancestors 'none'`,
  `X-Content-Type-Options: nosniff`, `Referrer-Policy` на HTML.
- `.htaccess`: запрет `config.php`, `*.key`, `.env`, `composer.*`, всех dotfiles;
  front-controller. На проде дополнительно nginx `auth_basic` по `.httpauth`.
- Секреты на диске (только имена): `.admin.key`, `.bothub.key`, `.config.php`,
  `.httpauth`. Значения не читаются и не выводятся ни в одном отчёте.
- Вебхуки: HMAC-SHA256 в заголовке `X-RevoAp-HMAC-SHA256: t=<unix>;v1=<hex>`,
  подпись считается по `"<ts>.<raw-body>"`; backoff [60, 300, 900, 1800],
  максимум 6 попыток, timeout 5 с. Для вызывающего доставка fail-open.
  Отдельного фильтра URL вебхука (SSRF) в прочитанном коде **не найдено**.
- Валидация входа: `QueryAttachments` (6 файлов, 128k/300k, basename,
  CRLF→LF, вырезание NUL, перепроверка формата на сервере); репозитории —
  только prepared statements.

## 9. Интерфейс

- `assets/js` (~26 модулей): `app.js` (ядро SPA), `pipeline.js`, `models.js`,
  `settings.js`, `model-selector.js`, `history.js`, `worker-status.js`,
  `security.js`, `extensions.js`, `api.js`, `logs.js`, `statistic.js`, `stats.js`,
  `skills.js`, `shared.js`, `theme.js`, `tab-nav.js`, `prompt-cache.js`,
  `migration-banner.js`, `debug-trace.js`, `logger.js`, `katex-render.js`,
  `mermaid-render.js`, `copy_to_clipboard.js`, `copy-buttons.js`, `admin-ui.js`.
- `assets/templates`: `layout.html`, `chat.html`, `help.html`, `shared.html`,
  `pipeline-step.html`, `stats-panel.html`, `step-tab.html`.
- `assets/css`: `app.css` + `themes/light.css` / `themes/dark.css` +
  `components/*`; тема — только на CSS custom properties (дисциплину токенов
  проверяет `tests/CssTokensTest.php`).
- Связь с бэкендом: fetch `/api/*` + SSE
  `/api/council/session/{id}/events/stream` (реплей персистентных событий).
  Легаси `/api/council/stream` — живой синхронный поток.
  `?debug=0|1` управляет консольными логами фронта; промпты и ответы туда
  не пишутся.
- Всего в `index.php` зарегистрировано ~90 маршрутов.
- MD.Viewer — внешний просмотрщик Markdown, ставится и настраивается через
  `Extensions/`. Свой origin-контроль MD.Viewer получил в v3.11.0, и то же
  правило повторено в RevoAp через `OriginGuard`.

## 10. Обязательные соглашения проекта

Из `SKILL.md` (это авторитетный стандарт кода), `src/**/*.md` и шапки `config.php`:

- `declare(strict_types=1);` в каждом PHP-файле; readonly-объекты значений,
  enum для статусов, match вместо switch, named arguments для 3+ параметров.
- Полный PHPDoc: `@param` / `@return` / `@throws` у публичных методов,
  `@version` у класса, описание контракта у методов интерфейса.
- Шапка полного файла: `@project RevoAp`, `@author`, `@site`, `@email`,
  `@version`, `@since`.
- Запрещено: `die()` / `exit()` в коде приложения, `eval()`, `extract()`,
  `global $var`, переменные переменные, `@`-подавление ошибок, пустые
  `catch`, конкатенация в SQL, изменение прошлых записей пайплайна,
  `innerHTML` с пользовательскими данными, секреты в сессии и cookies,
  TODO-заглушки.
- Архитектура: инверсия зависимостей, никакого глобального состояния,
  тонкие контроллеры, repository pattern, значения-объекты `final readonly`.
- Каждый каталог `src/` обязан иметь файл-спутник `[Каталог].md` с разделами
  Purpose / Files / Public API / Invariants & Constraints / Integration Points /
  LLM Extension Guide. На практике правило выполнено не везде (см. §14).
- Изменение входов кэш-ключа — только вместе с bump `VERSION`.
- Публичные сигнатуры `ConsensusEngine` не меняются; новые метрики сходства —
  отдельной стратегией. Новые слои пайплайна — только с большим `order_index`
  и уникальным `step_key`; новые события — UPPER_SNAKE_CASE со стабильной схемой.
- SQL — только prepared (в репозиториях). `Storage.md`: репозитории принимают
  nullable PDO и повторяют in-memory поведение.
- Рабочий цикл новой фичи (SKILL.md §14): прочитать `[Module].md` → написать
  интерфейсы и значения-объекты → реализация → обновить `[Module].md` → тесты на
  чистую логику → проверить отсутствие хардкода и глобального состояния →
  обновить `CHANGELOG.md` с bump версии.

## 11. Инженерный регламент по типам изменений

- **Коннектор**: соблюсти `ConnectorInterface`; имя файла оканчивается на
  `Connector.php` (иначе не найдётся реестром); ключ — только через штатный
  резолв; `isAvailable()` проверяет наличие ключа и активность; инвариант
  суммы токенов; ключ не попадает в логи и ошибки.
- **Пайплайн и события**: только дописывание; `step_key` уникален; сбой —
  новое `*_FAILED` событие; фронт создаёт новый DOM-узел, а не перезаписывает
  существующий.
- **Схема и миграция**: правка `db/schema.sql` + bump `@table-version` и
  `@schema-version`; миграция `db/migrations/NNN_*.sql` идемпотентна
  (в MySQL нет `ADD COLUMN IF NOT EXISTS` — использовать пробы по
  `information_schema`); каждая таблица из миграции обязана быть объявлена
  в `schema.sql` с той же версией; **перед применением на живой БД — дамп**;
  отката нет; следующий свободный номер — `022`.
- **Кэш и экономика**: менять входы ключа только с bump `VERSION`; ошибки и
  пустые ответы не кэшировать; NULL и 0 не смешивать; стоимость не выдумывать.
- **Очередь и воркер**: не списывать модель-бюджет за инфраструктурные сбои;
  не ломать heartbeat и presence-контракт; коды выхода значимы для systemd;
  воркер не должен быть доступен из web SAPI.
- **SPA**: только CSS-токены для тем; `textContent` вместо `innerHTML` для
  пользовательских данных; `?v=` для обхода кэша статики; модули ES2022 без
  сборщика.
- **PowerShell (локальные вспомогательные скрипты)**: `#Requires -Version 7.6`
  и `#Requires -PSEdition Core`, UTF-8 без BOM (`-Encoding utf8NoBOM`),
  пользовательские пути только через `[Environment]::GetFolderPath(...)`,
  никогда `powershell.exe`. Исключение — существующий git-ignored инструмент
  `deploy/host/deploy.ps1`, который осознанно остаётся на 5.1.

## 12. Ловушки для разработчика

1. **NULL ≠ 0** — `cost` и cache-поля; суммирование NULL как нуля искажает
   экономику и отчётность. `cache_discount` может быть отрицательным.
2. **Кэш**: изменение входов ключа без bump `VERSION` даёт ложные промахи и
   коллизии; `short_key` — для UI, не для сверок.
3. **`step_key`**: повтор = `LOOP_DETECTED` и остановка прогона.
4. **L2 не вызывает LLM** — «ответа модели» на этом шаге не бывает.
5. **`ModelBreaker` только внутри прогона** — не глобальный health.
6. **Инфраструктурный requeue не списывает бюджет** модельных попыток;
   не путать с `attempts`.
7. **`SessionSafetyNet`** может закрыть сессию служебным текстом — это не
   итоговый ответ; настоящий ответ он не перезаписывает.
8. **`OriginGuard` пропускает запросы без Origin/Referer** — не считать это
   защитой от не-браузерного абьюза.
9. **`AdminAuth` fail-closed** (503, если ключ не сконфигурирован); BOM в
   `.admin.key` мешает распознаванию.
10. **MySQL в нестрогом режиме молча принимает `''` в enum** — так появился
    кейс `consensus_status = ''` у прогонов актуализации.
11. **Лимиты, которые нельзя обойти**: аттачменты 6 / 128k / 300k,
    `cycle_limit` 1..5, timeout 5..3600, retry 0..3, breaker 1..10,
    `max_roles_per_skill = 12`, `models_catalog.max_rows = 1000`.
12. **`PROJECT_MAP.md` утверждает, что миграции применяются из History** —
    фактически из Settings (`SettingsController`).
13. **Версии рассинхронизированы** — README, `config.php`, `CHANGELOG.md` и
    `ROAD_MAP.md` называют разные числа; «текущей версии» по README не
    определять.
14. **Тесты — не PHPUnit**: standalone-скрипты с `check()` и итогом
    `Passes / Failures`. У `SmokeTest` есть документированный baseline падений;
    полностью «зелёный» прогон не является нормой.
15. **DOM-тесты** требуют node; без node раннер печатает SKIP и выходит с 0,
    а не падает.
16. **`var/config/runtime.json`** после прогонов обязан оставаться неизменным
    (регламент R1).

## 13. Тесты и проверки

Фактическое состояние среды: `php` 8.5.0 CLI есть; **`composer` и `phpunit`
отсутствуют**, `phpunit.xml` в репозитории нет; node 24 есть.

- Запуск: `php tests/<Name>Test.php` (standalone, без PHPUnit, БД и сети для
  большинства файлов), автономная загрузка через `require __DIR__.'/../src/autoload.php'`.
- Проверки из регламента `docs/roadmap/R1_run_transparency.md`:
  `php -l <изменённые .php>`; `node --check <копии .mjs изменённых .js>`;
  `php tests/SmokeTest.php` (baseline не должен вырасти);
  `php tests/ChatStreamDomTest.php`; `node --experimental-vm-modules tests/harness/chat-stream.mjs`;
  `php tests/CssTokensTest.php`; `php tests/TerminalStatusTest.php`.
- Проверено в этой сессии: `php tests/AdminKeyProtectionTest.php` →
  `Passes: 84  Failures: 0`, exit 0; `php -l` по всем 228 файлам `src/` — 0 ошибок.
- Тесты, читающие репозиторий (правки этих файлов могут добавлять падения):
  `tests/SmokeTest.php` — 8 ассерт-блоков читают `CHANGELOG.md`, проверяет
  `app.version` через `version_compare`, проверяет парность
  «таблица из миграции ↔ объявлена в `schema.sql` и совпадает `@table-version`»
  (блок ~строки 4813–4926), ссылается на файлы миграций 018/019; `CssTokensTest.php`
  обходит `assets/css/**.css`; `MigrationBannerTest.php` читает
  `assets/js/migration-banner.js` и `settings.js`. Многие тесты делают
  `str_contains` по исходникам — правка `index.php`, `src/**`, `assets/js/**`
  может добавлять падения.
- **Не читают**: `AGENTS.md`, `PROJECT_MAP.md`, `ROAD_MAP.md`, `SKILL.md`,
  `TECHNICAL_SPECIFICATION.md` — эти документы тестами не защищены.

## 14. Расхождения в документации (обнаружено при разведке)

Записано, чтобы никто не действовал по устаревшему тексту; истина — код.

1. **Версии**: README — 1.8.18, `config.php` — `app.version = 1.9.4`,
   `db/schema.sql` — `@schema-version 1.9.4`, последний релиз CHANGELOG — 1.9.0,
   ROAD_MAP упоминает ожидание схемы 1.9.2. Единого источника нет.
2. **Дефолтные модели**: README v1.8.18 перечисляет набор OpenRouter, а
   `config.php` — набор BotHub; какой список побеждает, определяется порядком
   оверлеев конфигурации.
3. **Конфликтный арбитр**: комментарий `config.php` требует оставить провайдера
   OpenRouter, значение — BotHub; ROAD_MAP говорит, что арбитр снят. Три версии
   одного факта.
4. **ROAD_MAP внутренне противоречив**: пункт про автоподмену модели лежит
   в разделе «Закрыто», тогда как сводная таблица объявляет его реализованным
   частично; статус deprecated-шума PHP 8.5 также расходится между разделами.
5. **Правило спутников `*.md`** фактически нарушено для 14 каталогов `src/`
   (проверено: нет `Api.md`, `Batch.md`, `Cost.md`, `Diagnostics.md`,
   `Extensions.md`, `History.md`, `Jobs.md`, `Memory.md`, `Models.md`,
   `Research.md`, `Security.md`, `Settings.md`, `Statistics.md`, `Worker.md`).
6. **`TECHNICAL_SPECIFICATION.md`** — план от 2026-05-05 со статусом
   «Ready for implementation»; для описания текущего состояния использовать
   `PROJECT_MAP.md` и код, а не его.
7. **`composer.json` скрипты неисполнимы в этой среде**: `test` требует
   отсутствующий phpunit, `lint` использует POSIX `find | xargs`.
   `SKILL.md` §4 объявляет PHPUnit 11.x — фактически тесты standalone.

## 15. Открытые хвосты и известные проблемы

Из `ROAD_MAP.md` (раздел «Хвосты и незакрытые задачи», 28.09.2026) и верхушки
`CHANGELOG.md`:

- Требует решения оператора: применить миграции на живой БД (живая схема 1.9.0
  против ожидаемой) — **только после дампа**; отклонённый `git push` (нужен
  `pull --rebase`); поведение Share при отсутствии MD.Viewer; Share
  перезаписывает документ без резервных копий.
- Доработки: deprecated-предупреждения PHP 8.5 по
  `PDO::MYSQL_ATTR_USE_BUFFERED_QUERY` в нескольких местах; `consensus_status = ''`
  у прогонов актуализации; ревизия пунктов дорожной карты по коду; нормализация
  путей `//extensions/...` на входе.
- Повторить на живом контуре: прогон актуализации моделей после правки;
  клик «Download MD».
- Из незавершённого плана: A/B пресетов (P2-10) — «Не начато».
- `[Unreleased]` CHANGELOG: Automatic Skill Selector, доменные советы 4 скиллов,
  SkillDoc-рендер, фиксы парсера arXiv, Batch-скачивание, карточка Domain Models,
  закрытие сессии `models-actualize`, синхронизация версии схемы, новый Skills tab,
  `candidates_json` (миграция 021), аттачменты в чате и в Batch.

## 16. Риски, требующие решения владельца

1. **`.httpauth` не покрыт `.gitignore`** и не отслеживается git — файл с
   креденшлами basic-auth может попасть в коммит при `git add -A`.
   Аналогично вне индекса и без игнора: `nginx/`, `ROAD_MAP.md`, `PROJECT_MAP.md`,
   `.Debian/`.
2. **Гейт админки выключен**: `Protect_Settings_by_Admin_Key` = 0 и в базовом
   `config.php`, и в локальном `.config.php`. При выключенном гейте доступны
   ключи провайдеров, каталог моделей, пресеты, миграции, логи, все сессии
   и промпты, выпуск API-ключей. Для хоста, доступного извне, флаг нужно
   включать.
3. **Отката миграций нет** — только идемпотентность, трекер и бэкапы деплоя.
4. **Шифрования данных at-rest нет**; `var/config/runtime.json` и файловые
   кэши лежат открытым текстом, права зависят от хоста.
5. **SSRF-фильтр для URL вебхуков не найден** — при добавлении вебхуков
   исходить из того, что цель подписчика не ограничивается.
6. **`git status` показывает сотни незакоммиченных изменений** (рабочее дерево
   активно правится вне коммитов) — перед правками уточнять, что именно
   считается источником истины для конкретного файла.
