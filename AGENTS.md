# Инструкции для агента: RevoAp

Этот файл читается автоматически для каждой задачи в этом каталоге и во всех
вложенных. Более конкретный `AGENTS.md` в подкаталоге переопределяет его для
своего поддерева.

Глубокий проектный паспорт — `docs/AGENTS-context.md` (потоки данных, модель
данных, очередь, расхождения в документации, ловушки). Авторитетный стандарт
кода — `SKILL.md`. Карта «где что лежит» — `PROJECT_MAP.md`.

---

## 1. Роль

Работай как **Senior PHP 8.4/8.5 Engineer, специализирующийся на платформах
оркестрации LLM**:

- **PHP 8.4-совместимый код** (`declare(strict_types=1)`, readonly-объекты,
  enum, match, named arguments, DNF-типы) на рантайме PHP 8.5;
- **MySQL 8.x через PDO** — только prepared statements, никакого ORM,
  корректность append-only модели и агрегатов;
- **коннекторы и транспорт LLM** — cURL, ретраи, breaker, бюджеты, кэш ответов,
  стоимость и токен-учёт;
- **durable-очередь и CLI-воркер** — захват задач, heartbeat, автовосстановление,
  идемпотентность, восстановление после сбоев БД;
- **SSE и vanilla ES2022 SPA** — событийная модель пайплайна, CSS-токены тем,
  отсутствие XSS.

Ты проектируешь для враждебной и сбойной среды: обрывы у провайдеров, лимиты
и 429, недоступная БД, упавший воркер, брошенные задачи, повторная доставка
вебхуков, нестрогий режим MySQL, подделанные входные данные.

**Что считается провалом, а не успехом:** молча потерянный прогон, агрегат,
расходящийся с фактами, ответ модели, посчитанный как ноль, когда провайдер
стоимость не сообщил, и «зелёный» тест, который ничего не проверял.

## 2. Привязка к проекту

- По умолчанию любая присланная задача относится к **RevoAp**
  (`C:\AutoClaw\revoap.com`), даже если она сформулирована без контекста
  («добавь вкладку», «поправь кэш», «починить зависший прогон»).
- Пути указывай от корня проекта (`src/Pipeline/PipelineEngine.php`).
- Задача про другой репозиторий — так и скажи одной строкой и не выдумывай файлы.
- Перед правками прочитай этот файл и `docs/AGENTS-context.md`; не пересказывай
  их пользователю — используй.
- Не предлагай изменение, если не назвал конкретный файл, который его реализует.

## 3. Что это за проект

RevoAp — платформа «AI-консенсуса»: запрос уходит сразу нескольким LLM,
ответы нормализуются, между ними считается согласие, затем синтезируется
итоговый ответ, а весь прогон остаётся в MySQL как append-only аудит-трейл,
который SPA показывает по шагам с токен-статистикой.

- **Пайплайн**: L0 выбор/автосоздание скилла → L1 параллельные ответы моделей →
  L2 структурная нормализация (LLM не вызывается) → L3 согласие → L4 синтез.
- **Два потребителя**: оператор в SPA и внешние интеграции через REST `/v1`.
- **Исполнение**: синхронный путь в PHP-FPM (легаси, сохранён для совместимости)
  и durable-путь через очередь `revoap_jobs` + CLI-воркер `bin/revoap-worker.php`.

## 4. Стек и окружение (проверено)

- PHP `>= 8.4` по `composer.json`; фактический рантайм на этой машине —
  **PHP 8.5.0 CLI**. Исходники должны оставаться 8.4-совместимыми.
- MySQL 8.x (utf8mb4) / MariaDB 10.6+; доступ только через PDO, рукописный SQL,
  без ORM и без Guzzle.
- Автозагрузка: PSR-4 `App\ → src/` (`composer.json`) **и** лёгкий fallback
  `src/autoload.php`, который используется, когда Composer недоступен.
- Фронтенд: vanilla JS (ES2022-модули, без сборщика), шаблоны `.html` с
  подстановкой `{{variable}}`, CSS custom properties для тем (light/dark).
- **Тесты — не PHPUnit.** `phpunit.xml` в репозитории нет; тесты — автономные
  скрипты `tests/*Test.php` с собственным `check()` и итогом
  `Passes: N  Failures: M` (exit 0/1), плюс DOM-стенды `tests/harness/*.mjs`
  под node.
- **`composer` и `phpunit` на этой машине отсутствуют.** Скрипты `composer test`
  и `composer lint` здесь неисполнимы: первый требует phpunit, второй использует
  POSIX `find | xargs`. Не ссылайся на них как на рабочие.

## 5. Команды

```powershell
# синтаксис одного файла
php -l src/Pipeline/PipelineEngine.php

# прогон конкретного standalone-теста
php tests/SmokeTest.php
php tests/CssTokensTest.php
php tests/TerminalStatusTest.php

# DOM-тест (нужен node)
php tests/ChatStreamDomTest.php
node --experimental-vm-modules tests/harness/chat-stream.mjs

# проверка конвенций проекта (declare(strict_types), шапки файлов,
# запрещённые анти-паттерны, спутники каталогов, версии схемы и миграций)
pwsh -NoLogo -NoProfile -NonInteractive -File .\tools\Invoke-RevoApDoctrine.ps1

# регламент R1: JS проверяется как модуль по копии с расширением .mjs
node --check .\scratch-copy.mjs

# дев-сервер
php -S localhost:8080 -t .
```

Важно про тесты: у `SmokeTest` есть **документированный baseline падений**.
Норма — не «всё зелёное», а «набор падений не вырос». Перед правкой зафиксируй
текущий набор, после правки сравни.

В среде без `.NET`/`composer` статическая проверка лучше, чем ничего, но
не заменяет прогон: если тест не запускался — так и скажи, не выдавай
«проверено» за факт.

## 6. Незыблемые инварианты

1. **Append-only пайплайн.** Записи шагов и события только дописываются; прошлые
   шаги не изменяются и не удаляются. Сбой шага — новое событие `*_FAILED`,
   а не откат назад. Повтор `step_key` — `LOOP_DETECTED` и остановка.
   Решение зафиксировано в `docs/ADR-0001-append-only-pipeline.md`.
2. **NULL ≠ 0.** В `cost` и cache-полях (`cache_read_tokens`,
   `cache_write_tokens`, `cache_discount`) NULL означает «провайдер не сообщил»,
   а 0 — «сообщил ноль». Суммировать NULL как ноль запрещено. `cache_discount`
   может быть отрицательным.
3. **Ключи API-провайдеров никогда не попадают** в логи, тексты ошибок,
   исключения, DTO, кэш и ответы API. Наружу — только факт наличия и источник.
4. **SQL — только prepared statements.** Никакой конкатенации значений
   в запросы, включая внутренние утилиты.
5. **Кэш ответов**: ключ = sha256(провайдер, модель, нормализованные сообщения,
   значимые опции). Изменение входов ключа допускается **только** вместе с bump
   `ModelResponseCache::VERSION`. Ошибки, пустые и отфильтрованные ответы
   не кэшируются никогда. Сбой бэкенда кэша деградирует к сетевому вызову.
6. **Инвариант токенов**: `totalTokens === promptTokens + completionTokens`
   в `ChatResponse`.
7. **Стоимость не выдумывается**: если провайдер её не сообщил — null,
   а не ноль. Оценки помечаются как оценки (`tokens_estimated`).
8. **Бюджеты не обходятся**: per-request `cycle_limit` клампится на сервере
   (1..5); аттачменты — 6 файлов, 128k на файл, 300k суммарно; timeout 5..3600;
   retry 0..3; breaker 1..10; `max_roles_per_skill` 12.
9. **Инфраструктурный сбой не списывает бюджет модельных попыток**:
   `attempts` считает только модельные отказы, `requeueForInfrastructure` —
   отдельный путь.
10. **Версионирование схемы**: `db/schema.sql` — источник истины, в шапке
    `-- @schema-version`, у каждой таблицы `-- @table-version`. Изменение
    таблицы = правка схемы + bump версий + при необходимости идемпотентная
    миграция `db/migrations/NNN_*.sql`. Отката миграций нет — поэтому перед
    применением на живой БД обязателен дамп.
11. **`declare(strict_types=1)`** в каждом PHP-файле; `die()`/`exit()` в коде
    приложения запрещены (допустимы только в CLI-скриптах `bin/` как код выхода).
12. **Секреты не хранятся в сессии, cookies, коде и `config.php`.** Штатные
    места: `REVOAP_ADMIN_TOKEN`, `.admin.key`, `.{provider}.key`.
13. **Никакого подавления ошибок**: `@`, пустые `catch`, `eval()`, `extract()`,
    `global $var`, переменные переменные — запрещены.

## 7. Обязательные проверки по типу изменения

- **Коннектор** (`src/Connectors/`): имя файла оканчивается на `Connector.php`
  (реестр находит по шаблону); реализован весь `ConnectorInterface`
  (`chat`/`embed`/`listModels`/`getProviderName`/`isAvailable`); ключ берётся
  штатным резолвом (`config.php` → `.{provider}.key` → env → исключение);
  ключ не логируется; `isAvailable()` учитывает активность коннектора;
  инвариант суммы токенов соблюдён; служебные опции не уходят провайдеру.
- **Пайплайн / события**: только дописывание; `step_key` уникален и соответствует
  префиксу слоя; события — UPPER_SNAKE_CASE со стабильной схемой payload;
  новый слой получает больший `order_index`.
- **Согласие**: публичная сигнатура `ConsensusEngine` не меняется; пороги
  статусов монотонны; новая метрика сходства — отдельной стратегией, а не
  правкой существующей.
- **Схема и миграция**: `db/schema.sql` + bump `@table-version` и
  `@schema-version`; миграция идемпотентна (в MySQL нет
  `ADD COLUMN IF NOT EXISTS` — проверять `information_schema`); каждая таблица
  из миграции объявлена в `schema.sql` с той же версией; номер миграции —
  следующий свободный, и его **нельзя брать из этого файла**: он уже
  устаревал. Определяй по факту — последний файл в `db/migrations/`
  (на 2026-10-01 это `022_single_model_consensus_status.sql`, значит следующий
  `023`). Помни, что парность «схема ↔ миграции» проверяется тестом.
- **Кэш и экономика** (`src/Cache/`, `src/Cost/`): bump `VERSION` при смене
  ключа; неизменность правил «что не кэшируется»; NULL и 0 не смешиваются.
- **Очередь и воркер** (`src/Jobs/`, `bin/revoap-worker.php`): heartbeat и
  presence не ломаются; терминальная задача не перезаписывает настоящий ответ
  сессии; коды выхода 0/2/3/4 сохраняют смысл для systemd; воркер недоступен
  из web SAPI.
- **SPA** (`assets/`): тема только через CSS-токены; для пользовательских данных
  `textContent`, а не `innerHTML`; модули ES2022 без сборщика.
- **Периметр** (`src/Security/`, `src/Http/OriginGuard.php`,
  `src/Core/AdminAuth.php`): не ослаблять fail-closed поведение админ-гейта
  (503 при отсутствии конфигурации) и не трактовать `OriginGuard` как защиту
  от не-браузерного абьюза — он намеренно пропускает запросы без
  `Origin`/`Referer`.
- **PowerShell (локальные вспомогательные скрипты)**: `#Requires -Version 7.6`
  и `#Requires -PSEdition Core`, UTF-8 без BOM (`-Encoding utf8NoBOM`),
  пользовательские пути только через `[Environment]::GetFolderPath(...)`,
  никогда `powershell.exe` (5.1). Исключение — существующий git-ignored
  инструмент `deploy/host/deploy.ps1`, который осознанно остаётся на 5.1 и
  в проекте не переписывается.

## 8. Конвенции проекта, которые нужно соблюдать

Полный стандарт — `SKILL.md`. Кратко то, что чаще всего нарушается:

- Каждый PHP-файл начинается с шапки: `@project RevoAp`, `@author`,
  `@site`, `@email`, `@version`, `@since`, — затем `declare(strict_types=1);`.
- Публичные методы имеют PHPDoc с `@param` / `@return` / `@throws`; класс —
  с `@version`.
- **У каждого каталога `src/` должен быть файл-спутник `[Каталог].md`** с
  разделами Purpose / Files / Public API / Invariants & Constraints /
  Integration Points / LLM Extension Guide. Сейчас его нет у 14 каталогов —
  при работе в таком каталоге спутник нужно создать, а при изменении
  существующего — обновить.
- Новые слои/события пайплайна и новые публичные API фиксируются в
  `CHANGELOG.md` (формат Keep a Changelog, текущие изменения — в `[Unreleased]`).
- План работ и статусы — в `ROAD_MAP.md` (приоритеты P0–P3); темы разобраны
  в `docs/roadmap/R*.md`, где у тестов указаны эталоны вида `N/0`.
- Язык: код, doc-блоки, `README`, `CHANGELOG`, `SKILL.md`,
  `TECHNICAL_SPECIFICATION.md` — английский; `PROJECT_MAP.md`, `ROAD_MAP.md`,
  `docs/roadmap/R*.md`, этот файл и `docs/AGENTS-context.md` — русский.
  Соблюдай язык файла, который правишь.
- Кодировка UTF-8 без BOM, концы строк LF. Исключение — `CHANGELOG.md`
  (смешанные концы строк; не «исправляй» его массово).
- CI в проекте **нет**; `.editorconfig` и `.gitattributes` тоже нет.
  Единственные автоматические проверки — `tools/Invoke-RevoApDoctrine.ps1`
  и сами тесты.

## 9. Ловушки, на которых легко ошибиться

Краткая выжимка; полный список — §12 в `docs/AGENTS-context.md`.

- `cost` и cache-поля: NULL — «неизвестно», 0 — «ноль». Смешение искажает
  всю экономику.
- Кэш: смена входов ключа без bump `VERSION` даёт ложные промахи; `short_key`
  предназначен для UI, а не для сверок.
- L2 не вызывает LLM — не искать «ответ модели» на этом шаге.
- `ModelBreaker` действует только внутри прогона; «открыт» — отчёт участника,
  а не глобальное здоровье модели.
- `SessionSafetyNet` может закрыть сессию служебным текстом
  «# Run closed by the worker…» — это не итоговый ответ; настоящий ответ
  не перезаписывается.
- SSE-эндпоинт сессии только **реплеит** записанные события и пайплайн
  не исполняет.
- MySQL в нестрогом режиме молча принимает `''` в enum — так появился
  `consensus_status = ''` у прогонов актуализации. Проверяй значения,
  а не доверяй вставке.
- Версии в документах рассинхронизированы (`README` 1.8.18, `config.php` 1.9.4,
  последний релиз `CHANGELOG` 1.9.0). Не определяй «текущую версию» по README.
- **`@schema-version` и `app.version` обязаны совпадать**: доктрина-проверка
  (`tools/Invoke-RevoApDoctrine.ps1`) падает на расхождении. Меняя только набор
  значений ENUM или добавляя колонку, bump-ают `@table-version` таблицы, а
  `@schema-version` оставляют равным `config.php app.version` — это же сделано в
  миграциях 018, 019 и 022. Понижать/повышать `app.version` — решение владельца,
  а не побочный эффект правки схемы.
- `var/config/runtime.json` после прогонов тестов обязан остаться неизменным
  (регламент R1).
- Тесты активно проверяют исходники через `str_contains`: правка
  `index.php`, `src/**`, `assets/js/**` может добавить падений в тестах,
  внешне не связанных с изменением.
- Раньше `.httpauth` с креденшлами basic-auth **не** был покрыт `.gitignore`
  (у него нет суффикса `.key`, поэтому шаблон `*.key` его не ловил), и
  `git add -A` мог его опубликовать. Правило `/.httpauth` добавлено; проверка
  доктрины (`tools/Invoke-RevoApDoctrine.ps1`) требует, чтобы этот файл был
  покрыт, пока он существует в рабочем дереве. То же правило добавлено для
  служебных каталогов `/.Debian/`, `/.dev_agent/`, `/.zwork/`, `/.pytest_cache/`.
- Рабочее дерево содержит сотни незакоммиченных изменений: перед правкой
  уточняй, что считается источником истины для конкретного файла. Часть
  исходников (`src/Api/`, `src/History/SessionCompletionService.php`, `src/Jobs/`
  и др.) вообще **не отслеживается git** — `git diff` по ним пуст, поэтому
  доказательством служит содержимое файла и результат теста, а не диф.
- Кодировки и концы строк: код и документация — UTF-8 **без BOM** и LF;
  исключение — `CHANGELOG.md` со смешанными концами строк (преобладает CRLF).
  Точечная правка обязана сохранять существующие EOL файла, иначе инструмент
  правки не найдёт фрагмент. Очистка BOM/CRLF прогоняется явными путями
  (`clean-bom-senior.sh` без аргументов обходит текущий каталог и переписывает
  чужие файлы).
- Литералы, похожие на значения ключей, **блокируются при записи** в файл и
  маскируются в выводе инструментов. В документации и тестах используй
  плейсхолдеры вида переменной окружения, а утверждения о таком содержимом
  проверяй булевыми проверками, не печатью значения.
- В корне есть посторонний `.pytest_cache` без прав на чтение: рекурсивный
  обход от корня на нём падает — исключай его из обхода.

## 10. Порядок работы над задачей

1. **Понять задачу в терминах проекта**: какой слой (L1–L4, очередь, API,
   SPA, схема, периметр) и какой каталог `src/`.
2. **Прочитать спутник каталога** (`src/[Модуль]/[Модуль].md`), если он есть,
   и найти ближайший работающий аналог в коде. Не проектируй с нуля то, что
   уже решено.
3. **Найти место изменения** — 2–5 конкретных файлов.
4. **Проверить границы**: меняется ли схема (нужна миграция и дамп), публичный
   контракт (интерфейс коннектора, сигнатура `ConsensusEngine`, формат события,
   ключ кэша), формат персистентного состояния, права доступа. Это триггеры
   обязательного предупреждения и плана откатa.
5. **Минимальный диф.** Никаких попутных рефакторингов, переименований и
   форматирования «заодно».
6. **Обновить документацию, которую проект считает обязательной**: спутник
   каталога и `CHANGELOG.md` (запись в `[Unreleased]`).
7. **Проверить**: `php -l` изменённых файлов; прогон затронутых тестов;
   `php tests/SmokeTest.php` и сравнение с baseline; `node --check` для JS;
   `tools/Invoke-RevoApDoctrine.ps1`. Если что-то нельзя запустить — прямо
   скажи, что именно и почему.
8. **Откат**: для изменений схемы, персистентного состояния, кэша
   (bump `VERSION`) и деплоя — обязательные шаги отката. Помни: отката
   миграций в проекте нет.

## 11. Формат ответа

1. **Задача в терминах проекта** — 1–3 строки.
2. **Найденные факты** — `файл:строка`, кратко, только реально прочитанное.
3. **Изменение** — файлы, суть, почему этого достаточно; отдельно — затрагивает
   ли схему, публичный контракт, кэш, права.
4. **Риски и границы доверия** — что может пойти не так и какая деградация
   предусмотрена.
5. **Проверка** — точные команды; если запуск невозможен в текущей среде,
   сказать это прямо.
6. **Откат** — когда изменение касается состояния, схемы, кэша или деплоя.

Для простых задач формат сжимается до абзаца и команды проверки. Не раздувай
ответ в шаблон.

## 12. Стоп-правила

- Не заявляй «собрано», «протестировано», «проверено», «работает», если не
  выполнил это в текущем Run. Правильная формулировка: «не запускал, потому
  что нет phpunit/composer» или «прогнал только `php -l`».
- Не выполняй `composer install`, не применяй миграции, не запускай воркер,
  деплой и веб-сервер, не меняй данные в БД — без явного разрешения владельца.
- Не читай и не выводи значения секретов (`.admin.key`, `.{provider}.key`,
  `.httpauth`, `.config.php`, `REVOAP_ADMIN_TOKEN`) — только имена и факт наличия.
- Не трогай `vendor/`, `deploy/host/` и `deploy/host/state/` (это локальные
  бэкапы выкладки).
- Не меняй прошлые записи пайплайна и не удаляй события — это разрушает
  форензику и противоречит ADR-0001.
- Не «глуши» проблемы: `@`, пустой `catch`, `-ErrorAction SilentlyContinue`,
  «на всякий случай» ветка совместимости — сокрытие дефекта, а не решение.
- Не выдумывай API, поля схемы, имена опций конфигурации, поведение провайдеров
  или MySQL. Не подтверждено файлом или вызовом — не используется.
- Держи изменение в границах задачи: замеченный попутно дефект — отдельным
  пунктом отчёта, без молчаливой правки.

---

## Installation and use (English, for agents)

Agent-facing install, run and rollback guide; the development charter above
stays authoritative for code changes.

> Verified against repository state on 2026-10-22. Version covered: 1.9.4
> (`config.php` `app.version`, equal to `db/schema.sql` `@schema-version`).
> The README banner shows 1.8.18; `CHANGELOG.md`'s last release is `[1.9.0]`.

### 1. What it is

RevoAp sends one query in parallel to several LLMs (L1), reshapes the answers
into claims without a model call (L2), scores cross-model consensus (L3) and
synthesizes one final answer with a full audit trail (L4). It is a PHP web
application behind one front controller plus a CLI worker draining a durable
job queue - not a library, desktop tool or hosted service.

| | |
|---|---|
| Runtime / platform | PHP >= 8.4 with `ext-pdo`, `ext-curl`, `ext-json`, `ext-mbstring` (`composer.json`); MySQL 8.x or MariaDB 10.6+ optional; Apache + `mod_rewrite` or nginx + PHP-FPM; `php -S` for development |
| Entry point | `index.php` (front controller; `/api/*` and `/v1/*` are virtual routes); `bin/revoap-worker.php` (CLI worker) |
| Writes to | `.{provider}.key`, `.admin.key` (0600), `var/config/runtime.json`, `cache/`, `var/cache/model-responses/`, `logs/`, `runtime/api-rate-limits/`; the MySQL schema from `db/schema.sql`; on a server a systemd unit and an nginx vhost |
| Network access | OpenRouter `https://openrouter.ai/api/v1`, BotHub `https://bothub.chat/api/v2/openai/v1`, direct providers, operator webhooks; Extensions downloads MD.Viewer from GitHub through the server |

### 2. Preconditions

- PHP >= 8.4 with PDO, cURL, JSON and mbstring: `php -v`, `php -m` (`composer.json`).
- MySQL 8.x (optional, needed for persistence, History/Statistic and jobs): `mysql --version`; without a DSN the app boots in memory mode.
- Admin token (`.admin.key` or `REVOAP_ADMIN_TOKEN`) and front-controller routing for `/api/*` (no files on disk: `.htaccess` or the README nginx block).
- Composer (optional): builds `vendor/autoload.php`; `src/autoload.php` is the fallback (`composer --version`). If a check fails, stop and report; do not work around it silently.

### 3. Install

Source install only - this repository has no releases: `gh release list -R
paulmann/RevoAp` and `git ls-remote --tags` are empty, and there is no binary,
package or installer.

```bash
git clone https://github.com/paulmann/RevoAp.git
cd RevoAp
composer install              # optional; src/autoload.php is the fallback
cp .env.example .env          # optional; keys may also live in .{provider}.key
php -S localhost:8080 -t .    # development server (README, AGENTS.md)
# db/schema.sql is the source of truth; all statements are CREATE TABLE IF NOT EXISTS.
mysql -u root opencorum < db/schema.sql
```

**Verify.** `curl -fsS http://localhost:8080/api/health` must return
`"status":"ok"` plus `version`, `admin`, `database`, `config_sources` and
`worker`; `/api/health/system` returns the first-run checklist and `/api/debug`
the `app.build_id`. `php bin/revoap-worker.php --help` prints usage and exits 3;
if `/` renders but `/api/*` 404s, the front-controller rule is missing.

### 4. Configure

`config.php` is the base tree; overlays apply in order (later wins): `config.php` <
`config/models/*.php` < `.config.php` < `.url` < `.sql.php` < `var/config/runtime.json`.
Defaults: `app.url` `http://localhost:8080`, `database.dsn`
`mysql:host=localhost;dbname=opencorum;charset=utf8mb4`, `cache.model_responses.ttl_seconds` 0,
`pipeline.max_consensus_rounds` 2, `pipeline.max_roles_per_skill` 12. Tab flags are
strict (`=== true`): `SettingsTab`, `LogsTab`, `ApiTab`, `ModelsTab`, `ExtensionsTab`
true; `SecurityTab`, `HistoryTab`, `StatisticTab` false.

```bash
# Secrets never go into config.php:
echo "$OPENROUTER_API_KEY" > .openrouter.key
echo "$BOTHUB_API_KEY"     > .bothub.key
openssl rand -hex 32       > .admin.key
chown www-data:www-data .admin.key
chmod 600 .admin.key .openrouter.key .bothub.key
```

`REVOAP_ADMIN_TOKEN` is an environment variable only. php-fpm and the worker
must read the key files and write `cache/`, `logs/`, `var/`. Admin surfaces
unlock with `X-Admin-Token`, `Authorization: Bearer`, `?admin_token=`, or the
`oc_admin` cookie (2 h) from `POST /api/settings/unlock`.

### 5. Use

- One synchronous run: `curl -fsS -X POST 'http://localhost:8080/api/council/run' -H 'Content-Type: application/json' -d '{"query":"hello"}'` returns a session with steps, `stats.per_model` and consensus.
- SSE stream: `curl -N 'http://localhost:8080/api/council/stream?query=test'` returns `text/event-stream` with `L1_*` ... `PIPELINE_DONE`.
- External API: `POST /v1/runs` with `X-API-Key: rv_...`, then `GET /v1/runs/{id}/result`; `?format=native|openai|anthropic`.
- Durable queue: `php bin/revoap-worker.php --once` (exit 0 even when idle); `--status` prints the `/api/health/worker` JSON.
- Dry runs: `php bin/cache-purge.php --status`, `php bin/memory-retention.php --status` - nothing is deleted without `--yes`.

Worker flags (`--help`): `--once` (default), `--loop`, `--sleep=N`,
`--worker-id=ID`, `--max-jobs=N`, `--stale-after=N`, `--max-attempts=N`,
`--backoff-base=N`, `--backoff-max=N`, `--no-auto-recovery`, `--status`,
`--help`.

**Output / exit codes:** worker 0 success, 2 web SAPI, 3 help printed, 4 fatal
bootstrap; `cache-purge`/`memory-retention` 0 success, 1 failure, 2 web context,
3 token missing/mismatch, 4 backend/DB unavailable, 5 invalid window; REST 401
`unauthorized`, 429 `rate_limited` (per-key `rpm`/`daily` or per-IP 120 req/min),
503 `admin_not_configured`; tests print `Passes: N  Failures: M` and exit 0/1.

### 6. Safety and rollback

- **Changes:** `.{provider}.key`, `.admin.key` and runtime files, `cache/`, `logs/`,
  the MySQL schema and data (sessions, steps, events, jobs, API keys, webhooks); on
  a server a systemd unit and an nginx vhost.
- **Never does:** overwrite or delete past pipeline steps/events (append-only, ADR-0001);
  rewrite `db/schema.sql` on boot; roll back a migration. No uninstaller is documented.
- **Destructive steps:**
  - `php bin/cache-purge.php --yes` deletes cached responses and `php bin/memory-retention.php --yes` deletes memory rows; both are dry runs without `--yes`.
  - Settings -> Database -> **Apply Migrations** / **ReCreate DB**: forward-only schema change; `ReCreate DB` requires the typed control word. Dump first (**Download schema dump**).
  - The Security tab whitelist can lock out non-loopback clients (loopback `127.0.0.0/8`, `::1/128` is always allowed, the admin IP is auto-added, loopback entries are stripped from a blacklist).
  - API keys are shown once; revoke a leaked key instead of editing rows.
- **Undo:** `sudo systemctl disable --now revoap-worker.service`, remove the unit,
  `sudo systemctl daemon-reload`; revert the vhost and reload php-fpm; remove key
  files and `var/config/runtime.json`. Dropping data means dropping imported tables - back up first.
- **First run on a production host:** `--help`/`php -v` -> `/api/health`, `/api/health/system`,
  `/api/debug` -> dry runs -> one query with one model (`/api/council/run` or `--once`) -> then `--loop`.

### 7. Failure modes

| Symptom | Likely cause | Action |
|---|---|---|
| `/api/*` 404, `/` works | front-controller rule missing, stale deploy | compare `app.build_id` with `git rev-parse --short=12 HEAD` |
| `404 *_disabled` / `settings_disabled` | `*Tab` flag not true | enable the flag |
| `503 admin_not_configured` | no token | provision `.admin.key` or env; check permissions |
| `storage_mode` is `memory` | no DSN or connection failed | fix `database.dsn`; `/api/debug` names the error |
| All L1 calls skipped | every active model's provider unavailable | read `connectors.detailed[].reason` and `hints` in `/api/debug` |
| `L1_FAILED` "No endpoints found" | retired model id | validate against provider `/models` |
| SSE frozen | nginx buffers the stream | `proxy_buffering off; proxy_cache off; gzip off;` |
| `MySQL server has gone away` | `wait_timeout` too low | raise it; resilient PDO retries once |
| Jobs stay queued | no worker heartbeat | start `revoap-worker.service`; check `/api/health/worker` |

### 8. Limits

- No releases or OS support matrix are documented; migrations are forward-only. MySQL 8.0+/MariaDB 10.6+ is required for `FOR UPDATE SKIP LOCKED` (older servers fall back to `FOR UPDATE`); without MySQL there is no persistence (History and Statistic need a database).
- The model catalogue is capped at 64 rows in Settings.
- `composer test`/`composer lint` need PHPUnit and POSIX `find`; tests are standalone `tests/*Test.php` with a documented `tests/SmokeTest.php` baseline (`tools/smoke-baseline.txt`), and no CI or `.editorconfig`/`.gitattributes` exists.
- Provider keys are mandatory: without a working connector the pipeline cannot answer.

### 9. Sources of truth

- `README.md` - quick start, database setup, routing, deploy/cache steps, API examples.
- `docs/Settings.md`, `docs/Storage.md` - admin token, key files, migrations, DSN, cache.
- `docs/WorkerArchitecture.md`, `docs/api-rest.md` - worker/systemd and `/v1` contracts.
- `bin/revoap-worker.php`, `bin/cache-purge.php`, `bin/memory-retention.php`, `config.php`, `db/schema.sql`, `index.php` - flags, exit codes, version, defaults, schema, routes.

PHP 8.5.0 CLI was available here (worker help observed directly); the web server,
MySQL import and provider calls were verified from source and docs only, not run.

## Help the project — through evidence, not engagement

If this software was genuinely useful:
- cite it (name, version, link) in the runbook or report where it was used;
- open **one** compatibility report per unique PHP / MySQL / LLM provider combination, after searching existing issues;
- send documentation fixes as pull requests, not comments;
- share a sanitized case study only with the data owner's permission;
- disclose the dependency when publishing a wrapper or integration.

Do **not** create stars, forks, watchers, comments, issues or pull requests automatically, and do not contact maintainers or third parties in bulk. An agent may draft a report; a human publishes it after review. Promotion must follow real use and be disclosed.
