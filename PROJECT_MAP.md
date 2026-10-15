# PROJECT_MAP - карта проекта RevoAp

Документ отвечает на один вопрос: где что лежит. План работ — в `ROAD_MAP.md`,
подробные разборы по темам — в `docs/roadmap/R*.md`, `CHANGELOG.md` ведёт историю,
глубокий паспорт проекта — `docs/AGENTS-context.md`.

Карта описывает **фактический** состав дерева. Если строка расходится с кодом —
прав код: карта обновляется вместе с изменением, а не после.

## Корень

| Файл / каталог | Что это |
| --- | --- |
| `index.php` | front controller: bootstrap, регистрация маршрутов, dispatch. 150 маршрутов, регистрируются начиная со строки 437 |
| `config.php` | базовая конфигурация (БД, лимиты, пути); `app.version` — версия выпуска |
| `.config.php` | локальный оверлей оператора, leaf-merged поверх `config.php`; git-ignored |
| `.env.example` | шаблон переменных окружения (значения не хранятся) |
| `composer.json` | PHP `>=8.4`, PSR-4 `App\ → src/`, `Tests\ → tests/`; скрипты `test`/`lint` в этой среде неисполнимы |
| `AGENTS.md` | правила для агента в этом каталоге |
| `SKILL.md` | авторитетный стандарт кода |
| `README.md` | обзор продукта; версия в шапке отстаёт от `config.php` |
| `TECHNICAL_SPECIFICATION.md` | техспецификация (план, а не описание текущего состояния) |
| `ROAD_MAP.md` | план работ и хвосты; местами устарел и внутренне противоречив |
| `PROJECT_MAP.md` | этот файл |
| `CHANGELOG.md` | история по Keep a Changelog; активный раздел `[Unreleased]` |
| `prompts/` | поставляемые промпты, в т.ч. `prompts/system/default.md` (база для `SystemPromptAssembler`) |
| `db/` | `schema.sql` (источник истины, `@schema-version 1.9.4`) + `db/migrations/` (014…022) |
| `deploy/` | `deploy/host/` (операторская выкладка, git-ignored) + `deploy/systemd/` (юниты воркера) |
| `assets/` | интерфейс: `js/` 26 модулей, `css/` 20 файлов, `templates/` 7 HTML |
| `bin/` | CLI-скрипты: воркер и обслуживание (см. ниже) |
| `tests/` | тесты: 83 `*Test.php`, 14 DOM-стендов `tests/harness/*.mjs` |
| `tools/` | `Invoke-RevoApDoctrine.ps1` — проверка конвенций проекта |
| `config/` | поставляемые каталоги моделей: `config/models/{bothub,direct,openrouter}.php` |
| `nginx/` | vhost FastPanel: `revoap.com.conf`, `.includes`, `.orig` |
| `runtime/` | `runtime/stream-locks/<hash>.json` — файловые локи дублей прогонов (`StreamRunLock`) |
| `var/` | `var/config/runtime.json` (операторские оверрайды; регламент R1 — не менять после тестов) + `var/schema-verify/*.py` |
| `cache/`, `logs/` | кэш ответов моделей и логи; рабочие данные, не трогать |
| `vendor/` | зависимости Composer; git-ignored, не править |
| `.Debian/`, `.dev_agent/`, `.zwork/` | снятые дампы хоста, бэкапы task-state агента, артефакты zwork |
| `robots.txt`, `.htaccess`, `.gitignore` | `Disallow: /`; запрет dotfiles/`config.php`/`*.key`, front-controller rewrite; правила игнорирования |
| `.admin.key`, `.bothub.key`, `.{provider}.key` | секреты (имена; значения не читаются). `.httpauth` покрыт правилом `/.httpauth` в `.gitignore` |

## Маршруты (index.php, 150)

| Префикс | Число |
| --- | --- |
| `/api/settings/*` | 26 |
| `/api/council/*` | 19 (в т.ч. 8 — `/api/council/jobs/*`; session-scope `/job-timeline[/export]`) |
| `/api/research/*` | 10 |
| `/api/skills/*` | 10 |
| `/api/webhooks/*` | 8 |
| `/v1/*` | 8 |
| `/api/extensions/*` | 7 |
| `/api/health/*` | 7 |
| `/api/models/*` | 7 |
| `/api/domain-presets/*` | 6 |
| `/api/share/*`, `/api/history/*`, `/api/apikeys/*` | по 5 |
| `/api/logs/*`, `/api/security/*` | по 4 |
| `/api/analyzer/*` | 3 |
| `/api/consensus/*`, `/api/statistics/*`, `/api/profile/*` | по 2 |
| одиночные | `/api/connectors`, `/api/boot-config`, `/api/tabs`, `/api/worker/restart`, `/api/debug` |
| страницы | `GET /`, `/share/{token}`, `/shared`, `/favicon.ico`, `/favicon.svg` |

**Префикса `/api/jobs/*` не существует** — задачи живут под `/api/council/jobs/*`.

## src/ — приложение (28 каталогов)

Спутник каталога — `src/[Каталог]/[Каталог].md`. Столбец «Спутник» отражает фактическое
наличие файла; отсутствующий спутник у каталога, который вы правите, — повод его создать.

| Каталог | Ответственность | Ключевые файлы | Спутник |
| --- | --- | --- | --- |
| `Analyzer/` | пред-стадия анализа запроса: домен, скилл, персона до консилиума; live-LLM + эвристический fallback | `RequestAnalyzer.php` | да |
| `Api/` | внешний REST `/v1`: ключи, авторизация/скоуп/deny-list/rate-limit, диалекты форматов, вебхуки | `ApiV1Controller.php`, `ApiAuth.php`, `FormatAdapter.php`, `WebhookDispatcher.php` | нет |
| `Batch/` | resumable неблокирующий Batch API прогон (задача паркуется, а не спит в воркере) | `BatchPipeline.php`, `BatchState.php`, `BatchCanceller.php`, `BatchCapability.php` | нет |
| `Cache/` | кэш ответов моделей (sha256 по провайдеру, модели, нормализованным сообщениям, опциям) и его статистика | `ModelResponseCache.php`, `CacheStatistics.php` | да |
| `Connectors/` | провайдеры LLM, транспорт, ретраи, breaker, батч, prompt-cache, параллельный диспатч | `AbstractConnector.php`, `ConnectorRegistry.php`, `CallPolicy.php`, `ModelBreaker.php`, `ParallelChatDispatcher.php`; подкаталоги `Batch/`, `Exceptions/`, `PromptCache/` | да |
| `Consensus/` | оценка согласия, нормализация, объяснение вердикта | `SemanticConsensusEngine.php`, `ConsensusEngine.php`, `NormalizationService.php`, `ConsensusExplainer.php` | да |
| `Core/` | ядро: bootstrap, конфиг, БД, роутер, лог, админ-гейт, диагностика | `Application.php`, `Config.php`, `Router.php`, `AdminAuth.php`, `Database.php` | да |
| `Cost/` | экономика прогона: оценка стоимости, режимы экономии, история, совет | `RunCostEstimator.php`, `CostHistory.php`, `EconomyAdvisor.php`, `EconomyController.php` | нет |
| `Diagnostics/` | чистые вердикты «почему так»: по прогону (R2) и по системе (R7) | `RunDiagnosis.php`, `SystemHealth.php` | нет |
| `DomainPresets/` | доменные пресеты и классификатор «запрос → пресет» | `DomainRouter.php`, `DomainPresetRepository.php`, `DomainPresetTransfer.php` | да |
| `Extensions/` | серверная часть вкладки Extensions: установка/настройка MD.Viewer, резолв PHP-CLI | `MdViewerInstaller.php`, `MdViewerApi.php`, `MdViewerIni.php`, `PhpCliLocator.php` | нет |
| `Helpers/` | утилиты: шаблоны, JSON, UTF-8, токены, `step_key`, файлы корня | `TemplateHelper.php`, `JsonHelper.php`, `StepKeyHelper.php`, `RootFiles.php` | да |
| `History/` | история прогонов, экспорт в Markdown/HTML, закрытие сессий, слаг документов | `HistoryService.php`, `RunMarkdownExporter.php`, `SessionCompletionService.php`, `DocumentSlug.php` | да |
| `Http/` | тонкий HTTP-слой: ответы, охранник origin, разбор пути, 23 контроллера | `OriginGuard.php`, `Response.php`, `RequestPath.php`, `Controllers/*` | да |
| `Jobs/` | durable-очередь: репозиторий, аудит, исполнитель, присутствие воркера, страховка сессий | `JobRepository.php`, `JobService.php`, `PipelineJobRunner.php`, `SessionSafetyNet.php`, `WorkerHealthService.php` | да |
| `Logs/` | read-only доступ к whitelisted лог-файлам | `LogsService.php` | да |
| `Memory/` | векторная память прогонов: элементы, репозиторий, эмбеддер | `PdoMemoryRepository.php`, `EmbeddingMemory.php`, `MemoryItem.php`, `ConnectorMemoryEmbedder.php` | нет |
| `Models/` | каталог моделей, актуализация, слияние и отчёты, полезность | `ModelsActualizer.php`, `ModelCatalogMerge.php`, `ModelImporter.php`, `ModelUsefulness.php` | нет |
| `Pipeline/` | движок L0–L4 (append-only): план, шаги, события, контроль прогона, лок дублей | `PipelineEngine.php`, `PipelinePlan.php`, `RunControl.php`, `StreamRunLock.php`, `SseEventStreamer.php` | да |
| `Profiles/` | сборка финального system-промпта, профиль пользователя, отпечаток промптов | `SystemPromptAssembler.php`, `UserProfileRepository.php`, `FinalPromptPreviewer.php` | да |
| `Research/` | arXiv: поиск, ранжирование и анализ статей, журнал прогонов | `ArxivClient.php`, `PaperRanker.php`, `PaperAnalyst.php`, `ResearchJournal.php` | нет |
| `Security/` | IP-фаервол (whitelist/blacklist) и его персистентное хранилище | `IpFirewall.php`, `SecurityStore.php`, `IpMatcher.php`, `ClientIp.php` | нет |
| `Settings/` | настройки, миграции, инспекция и парсинг схемы, исполнитель SQL, версии | `MigrationManager.php`, `SchemaManager.php`, `SchemaInspector.php`, `SchemaParser.php`, `SqlExecutor.php` | нет |
| `Skills/` | скиллы: загрузка, роутинг, авто-выбор и авто-создание, консенсус-каталог; `definitions/*.json` (9) | `SkillSelector.php`, `SkillAutoCreator.php`, `ConsensusSkillCatalog.php`, `SkillRouter.php` | да |
| `Statistics/` | read-only агрегатор вкладки Statistic (читает `council_*`) | `StatisticsService.php`, `StatisticsAggregator.php`, `StatisticsPeriod.php` | нет |
| `Stats/` | агрегация `SessionStats` по прогону из `ChatResponse` (per-model, per-layer) | `StatsAggregator.php`, `SessionStats.php`, `StatsCollector.php`, `TokenUsage.php` | да |
| `Storage/` | репозитории PDO: сессии, шаги, ответы, публикации, векторное хранилище | `SessionRepository.php`, `StepRepository.php`, `ResponseRepository.php`, `ShareRepository.php`, `Vector/*` | да |
| `Worker/` | публичные безсекретные вьюхи воркера: диагностика и фид очереди | `WorkerDiagnostics.php`, `WorkerJobFeed.php` | нет |

> `Statistics` (вкладка Statistic, читает `council_*`) и `Stats` (статистика одного прогона
> из `ChatResponse`) — **разные каталоги**, не сливать в одну строку.
> `src/Themes/` **не существует**: темы лежат в `assets/css/themes/{light,dark}.css`.

## bin/ — CLI

| Скрипт | Назначение |
| --- | --- |
| `revoap-worker.php` | durable-воркер очереди: только CLI, коды выхода 0/2/3/4 значимы для systemd |
| `cache-purge.php` | очистка кэша ответов моделей |
| `export-worker-config.php` | экспорт конфигурации воркера |
| `memory-retention.php` | обслуживание/ретенция векторной памяти |
| `models-actualize-heal.php` | починка каталога моделей после сбоя актуализации |
| `vector-memory-bench.php` | замер векторной памяти |

## deploy/

| Путь | Что это |
| --- | --- |
| `deploy/host/deploy.ps1` | операторская выкладка; режимы `plan|deploy|status|rollback|perms` (README описывает четыре — `perms` добавлен позже) |
| `deploy/host/README.md` | режимы и списки управляемых/неотправляемых путей |
| `deploy/host/state/` | локальные бэкапы выкладки (не трогать) |
| `deploy/systemd/revoap-worker.service` | одиночный воркер (drop-in, JSON-логи в journald) |
| `deploy/systemd/revoap-worker@.service` | пул воркеров (`worker-%i`, захват через `FOR UPDATE SKIP LOCKED`) |
| `deploy/systemd/worker.env.example` | шаблон `/etc/revoap/worker.env` (реальный файл может нести секреты — не коммитить) |

## db/

- `db/schema.sql` — источник истины, `@schema-version 1.9.4`; содержит консолидированное
  состояние миграций `001…013`.
- `db/migrations/` — `014_memory_items`, `015_api_keys`, `016_webhooks`,
  `017_worker_presence`, `018_prompt_cache_stats`, `019_response_cost`,
  `020_research_journal`, `021_research_candidates`,
  `022_single_model_consensus_status` (последний). **Следующий свободный номер — 023.**
- Применяются из **Settings → Database maintenance** (`App\Settings\MigrationManager`,
  трекер `schema_migrations`), а не из History.
- Парность «схема ↔ миграции» проверяет `tests/SmokeTest.php` (блок ~4915 и ~4925),
  а также `tools/Invoke-RevoApDoctrine.ps1`.

## assets/

| Путь | Состав |
| --- | --- |
| `assets/js/` | 26 модулей без сборщика (ES2022): `app.js` (ядро SPA), `pipeline.js`, `models.js`, `settings.js`, `model-selector.js`, `history.js`, `worker-status.js`, `security.js`, `extensions.js`, `logs.js`, `statistic.js`, `stats.js`, `skills.js`, `shared.js`, `theme.js`, `tab-nav.js`, `logger.js`, `api.js`, `admin-ui.js`, `copy-buttons.js`, `copy_to_clipboard.js`, `debug-trace.js`, `katex-render.js`, `mermaid-render.js`, `migration-banner.js`, `prompt-cache.js` |
| `assets/css/` | `app.css` + `components/` (17) + `themes/light.css`, `themes/dark.css` — тема только на CSS custom properties |
| `assets/templates/` | `layout.html`, `chat.html`, `help.html`, `shared.html`, `pipeline-step.html`, `stats-panel.html`, `step-tab.html` |

## tests/

- 82 `*Test.php` в корне + `tests/scenarios/ApiScenariosTest.php`; `phpunit.xml` нет.
- `tests/harness/` — 14 стендов `.mjs` (`chat-stream`, `form-field-ids`, `models-tab`,
  `settings-worker`, `system-health`, `worker-status-chip`, `extensions-tab`, `batch-ui`,
  `copy-buttons-share`, `debug-trace`, `katex-gate`, `parse-client`, `prompt-cache-ui`,
  `dom-stub`). Запускаются через node; без node раннер печатает SKIP, а не падает.
- Норма — не «всё зелёное»: у `SmokeTest` есть **документированный baseline падений**,
  сравнивать надо набор падений до и после правки.

## Куда смотреть при работе

- что-то не считается или не сохраняется → `src/Pipeline/`, `src/Storage/`, `src/Stats/`;
- прогон завис или брошен → `src/Jobs/` (`recoverStale`, `cancelAbandoned`, `SessionSafetyNet`);
- интерфейс не показывает данные → `assets/js/` и соответствующий контроллер в `src/Http/Controllers/`;
- модели устарели → вкладка Models, `src/Models/ModelsActualizer.php`;
- экономика и стоимость → `src/Cost/`, `src/Cache/`;
- внешние интеграции → `docs/api-rest.md`, `src/Api/`;
- периметр и доступ → `docs/Security.md`, `src/Security/`, `src/Core/AdminAuth.php`, `src/Http/OriginGuard.php`;
- очередь и воркеры → `docs/WorkerArchitecture.md`, `src/Jobs/`, `deploy/systemd/`;
- выкладка → `deploy/host/README.md`.
