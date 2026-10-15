# RevoAp — Technical Specification v1.0.0

> **Project**: RevoAp (fork/rewrite of Consensus-AI)  
> **Target repository**: `paulmann/AI-RevoAp`  
> **PHP version**: 8.5 CLI (verified runtime; source stays PHP 8.4-compatible, strict types, readonly, enums)  
> **Date**: 2026-05-05  
> **Status**: Ready for implementation by Perplexity Computer

---

## 1. Executive Summary

RevoAp is a PHP web application (verified on PHP 8.5 CLI) implementing a **4-layer AI council pipeline**.
It accepts a natural-language query, routes it through multiple LLMs simultaneously,
normalizes responses, computes consensus, synthesizes a final answer, and displays
a full interactive pipeline audit trail with per-step token statistics.

This version introduces:
- **OpenRouter.ai** connector alongside the existing BotHub connector
- A **universal connector architecture** allowing new LLMs by adding a single file
- A **forward-only pipeline** (no record modification, append-only events)
- **Per-step statistics tabs** (tokens, latency, model info)
- **Aggregate session statistics** with per-LLM efficiency breakdown
- **HTML/JS/CSS templates** extracted to `/assets/`
- **Light/dark theme** with system preference detection
- **Author info hidden** except in code headers and `?creator` endpoint

---

## 2. Directory Structure

```
AI-RevoAp/
├── index.php                       # Thin entry point — bootstrap + dispatch
├── config.php                      # All configuration (keys, models, DB, app)
├── composer.json                   # PSR-4: App\ => src/
├── .env.example                    # Environment variable template
├── .htaccess                       # Apache rewrite rules
├── SKILL.md                        # Perplexity Computer skill definition
├── TECHNICAL_SPECIFICATION.md      # This file
├── README.md                       # Public-facing project readme (no author refs)
├── CHANGELOG.md                    # Version history
│
├── src/
│   ├── Core/
│   │   ├── Application.php         # Bootstrap: DI wiring, env loading
│   │   ├── Router.php              # HTTP router (method + URI patterns)
│   │   ├── Config.php              # Config loader (config.php + .key files)
│   │   └── Core.md
│   │
│   ├── Helpers/
│   │   ├── Utf8Helper.php          # UTF-8 normalization/conversion
│   │   ├── TemplateHelper.php      # Render assets/templates/*.html
│   │   ├── JsonHelper.php          # Safe JSON encode/decode
│   │   ├── TimeHelper.php          # microtime, duration formatting
│   │   ├── TokenHelper.php         # Approximate token counting (word-based)
│   │   └── Helpers.md
│   │
│   ├── Connectors/
│   │   ├── ConnectorInterface.php  # Universal LLM connector contract
│   │   ├── ChatResponse.php        # Value object: LLM chat response + stats
│   │   ├── EmbedResponse.php       # Value object: LLM embedding response
│   │   ├── ConnectorRegistry.php   # Auto-discovers and registers connectors
│   │   ├── AbstractConnector.php   # Base: retry, key resolution, logging
│   │   ├── BothubConnector.php     # BotHub.chat implementation
│   │   ├── OpenRouterConnector.php # OpenRouter.ai implementation
│   │   ├── HOW_TO_CREATE_CONNECTOR.md  # LLM guide for new connectors
│   │   └── Connectors.md
│   │
│   ├── Pipeline/
│   │   ├── PipelineEngine.php      # Orchestrates L1→L2→L3→L4
│   │   ├── PipelineStep.php        # Step value object (id, key, label, order)
│   │   ├── StepResult.php          # Step result + ChatResponse[] + stats
│   │   ├── PipelineEvent.php       # Append-only event record
│   │   └── Pipeline.md
│   │
│   ├── Consensus/
│   │   ├── ConsensusEngine.php     # Agreement scoring + conflict detection
│   │   ├── ConsensusResult.php     # Score, status, summary, meta
│   │   ├── NormalizationService.php # JSON schema enforcement per role
│   │   └── Consensus.md
│   │
│   ├── Skills/
│   │   ├── Skill.php               # Skill domain entity
│   │   ├── SkillLoader.php         # Loads JSON skill definitions
│   │   ├── SkillRouter.php         # Vector-similarity skill selection
│   │   ├── SkillAutoCreator.php    # LLM-driven skill auto-generation
│   │   ├── SkillRepository.php     # PDO-backed CRUD for skills
│   │   ├── definitions/
│   │   │   ├── webapp_architect.json
│   │   │   ├── php_code_refiner.json
│   │   │   ├── db_optimizer.json
│   │   │   └── ux_frontend.json
│   │   └── Skills.md
│   │
│   ├── Stats/
│   │   ├── StatsCollector.php      # Per-step token/latency recording
│   │   ├── StatsAggregator.php     # Session-wide totals per model+provider
│   │   ├── TokenUsage.php          # VO: prompt/completion/total/latencyMs
│   │   ├── SessionStats.php        # VO: full session summary
│   │   └── Stats.md
│   │
│   ├── Storage/
│   │   ├── SessionRepository.php   # council_sessions table
│   │   ├── StepRepository.php      # council_steps table (append-only)
│   │   ├── ResponseRepository.php  # council_model_responses table
│   │   ├── VectorStore.php         # vector_embeddings table
│   │   └── Storage.md
│   │
│   └── Http/
│       ├── Controllers/
│       │   ├── CouncilController.php
│       │   ├── SkillController.php
│       │   ├── StatsController.php
│       │   └── CreatorController.php
│       └── Http.md
│
├── assets/
│   ├── css/
│   │   ├── app.css                 # CSS custom properties, layout, typography
│   │   ├── themes/
│   │   │   ├── light.css
│   │   │   └── dark.css
│   │   └── components/
│   │       ├── pipeline.css
│   │       ├── stats.css
│   │       └── chat.css
│   ├── js/
│   │   ├── app.js                  # App bootstrap, router, event bus
│   │   ├── pipeline.js             # Live pipeline step rendering
│   │   ├── stats.js                # Stats panel rendering
│   │   └── theme.js                # Light/dark toggle + persistence
│   └── templates/
│       ├── layout.html             # Full page shell
│       ├── chat.html               # Query input + response area
│       ├── pipeline-step.html      # Single step card
│       ├── step-tab.html           # Per-step statistics tab
│       └── stats-panel.html        # Final session statistics panel
│
└── db/
    ├── schema.sql                  # Full DDL: all tables
    └── migrations/
        └── 001_initial.sql
```

---

## 3. Module Specifications

### 3.1 `src/Core/Config.php`

**Responsibility**: Load configuration from `config.php`, then apply overrides from
`.{provider}.key` files and `$_ENV`. Provide a single interface for all config access.

**Key Resolution Algorithm** (in priority order):
```
1. config.php → connectors → {provider} → api_key (if 'active' = true)
2. File in project root: .{provider}.key (e.g. .bothub.key, .openrouter.key)
3. File in project root: .{ModelName}.key (e.g. .openai.key)
4. $_ENV[strtoupper($provider) . '_API_KEY']
5. Throws ConnectorKeyNotFoundException
```

**Public API**:
```php
Config::get(string $key, mixed $default = null): mixed     // dot-notation: 'app.debug'
Config::getConnectorKey(string $provider): string          // throws if not found & active=false
Config::getActiveModels(): array                           // only models with 'active' => true
Config::isDebug(): bool
```

---

### 3.2 `src/Connectors/ConnectorInterface.php`

**Contract** (must be implemented by every connector):

```php
interface ConnectorInterface
{
    /**
     * Send a chat completion request.
     * @param  string  $model    Provider-specific model identifier
     * @param  array   $messages Array of ['role'=>string, 'content'=>string]
     * @param  array   $options  Optional: temperature, max_tokens, top_p, stream
     * @return ChatResponse      Populated with content + full token stats
     * @throws ConnectorException on any transport or API error
     */
    public function chat(string $model, array $messages, array $options = []): ChatResponse;

    /**
     * Create a text embedding vector.
     * @return EmbedResponse  Contains float[] vector + token stats
     */
    public function embed(string $model, string $text): EmbedResponse;

    /** Returns string[] of available model identifiers from this provider */
    public function listModels(): array;

    /** Provider slug: 'bothub' | 'openrouter' | ... */
    public function getProviderName(): string;

    /** True if key exists, is non-empty, and 'active'=>true in config */
    public function isAvailable(): bool;
}
```

---

### 3.3 `src/Connectors/ChatResponse.php`

```php
final readonly class ChatResponse
{
    public function __construct(
        public string $content,         // LLM response text
        public string $model,           // model used
        public string $provider,        // connector provider name
        public int    $promptTokens,    // from API usage.prompt_tokens
        public int    $completionTokens,// from API usage.completion_tokens
        public int    $totalTokens,     // promptTokens + completionTokens
        public float  $latencyMs,       // wall-clock time in milliseconds
        public array  $rawResponse      // full JSON from API (for audit)
    ) {}
}
```

Rule: `totalTokens` **must equal** `promptTokens + completionTokens`.
If API returns zero usage, use `TokenHelper::estimateTokens()` as fallback but mark it as estimated.

---

### 3.4 `src/Connectors/BothubConnector.php`

- Base URL: `https://api.bothub.chat/v1`
- Authentication: `Authorization: Bearer {key}`
- Endpoint: `POST /chat/completions` (OpenAI-compatible)
- Retry: 3 attempts, exponential backoff (1s, 2s, 4s)
- Timeout: 120s for chat, 30s for embeddings
- Handles 429 (rate limit) as retryable

---

### 3.5 `src/Connectors/OpenRouterConnector.php`

- Base URL: `https://openrouter.ai/api/v1`
- Authentication: `Authorization: Bearer {key}`
- Additional headers:
  - `HTTP-Referer: https://github.com/paulmann/AI-RevoAp`
  - `X-Title: RevoAp`
- Endpoint: `POST /chat/completions` (OpenAI-compatible)
- Models endpoint: `GET /models` → parse `data[].id`
- Retry: 3 attempts, exponential backoff
- Timeout: 120s chat, 30s embed
- Note: OpenRouter may not support embeddings — `embed()` should throw `UnsupportedOperationException`

---

### 3.6 `src/Connectors/HOW_TO_CREATE_CONNECTOR.md`

This file is an **LLM instruction guide** for adding new provider connectors.
It must include:

1. **Step-by-step checklist** to create a new connector file
2. **Annotated code template** with all required method stubs
3. **How to register**: just drop the file — `ConnectorRegistry` auto-discovers it
4. **How to add config entry**: template for `config.php` `connectors` section
5. **How to test**: checklist of what `isAvailable()`, `chat()`, `embed()` must return
6. **Common mistakes**: wrong header names, missing `latencyMs`, not populating token stats
7. **Full working example**: a minimal `MockConnector.php` that passes all contracts

---

### 3.7 `src/Pipeline/PipelineEngine.php`

**4-Layer Pipeline** (forward-only, append-only):

```
L0: Skill Routing
  → SkillRouter::resolve(string $query): Skill
  → If no match: SkillAutoCreator::create(string $query): Skill

L1: Query Distribution
  → For each role in Skill.definition.roles:
      → Select active model (from config, matching provider)
      → Call Connector::chat()
      → Append StepResult + ChatResponse to session
      → Append PipelineEvent('L1_RESPONSE', roleKey, stepId)

L2: Response Normalization
  → For each L1 response:
      → NormalizationService::normalize(rawResponse, role.output_schema)
      → Append PipelineEvent('L2_NORMALIZED', roleKey, stepId)

L3: Consensus Analysis
  → ConsensusEngine::compute(session): ConsensusResult
  → Append PipelineEvent('L3_CONSENSUS', score, summary)

L4: Decision Synthesis
  → Build synthesis prompt from all L2 normalized outputs
  → Call Connector::chat() with synthesis model
  → Append PipelineEvent('L4_SYNTHESIS', finalAnswer)
  → Update session.final_answer, session.consensus_score
```

**CRITICAL PIPELINE RULES**:
- A step record is **NEVER** modified after creation
- If a role call fails: record `StepResult` with `status='error'`, continue to next role
- If L3 fails: record failure, use best available partial result for L4
- If pipeline detects a loop (same stepKey appears twice): append `PipelineEvent('LOOP_DETECTED')`, halt pipeline
- Frontend must not re-render existing steps — only append new DOM nodes on new events

---

### 3.8 `src/Stats/StatsCollector.php`

Collects stats during pipeline execution:

```php
interface StatsCollectorInterface
{
    public function record(string $stepKey, ChatResponse $response): void;
    public function getStepStats(string $stepKey): TokenUsage[];
    public function getSessionStats(): SessionStats;
}
```

**`SessionStats` fields**:
```php
final readonly class SessionStats
{
    public int   $totalTokens;          // sum of all calls
    public float $totalLatencyMs;       // wall-clock pipeline duration
    public array $perModel;             // ['model:provider' => TokenUsage]
    public array $perLayer;             // ['L1' => TokenUsage, 'L3' => TokenUsage, ...]
    public float $avgTokensPerRole;     // totalTokens / roleCount
    public string $mostEfficientModel;  // lowest tokens-per-role
}
```

---

### 3.9 `src/Http/Controllers/CreatorController.php`

Handles `?creator` query parameter on any URL.

**Trigger**: `isset($_GET['creator'])`

**Response** (text/html, no sensitive data):
```html
<pre>
RevoAp — AI Consensus Platform
Author : Mikhail Deynekin
Site   : https://Deynekin.com
Email  : mikhail@deynekin.com
GitHub : https://github.com/paulmann/AI-RevoAp
Version: 1.0.0
</pre>
```

---

## 4. Database Schema

### Tables

```sql
-- Council sessions
CREATE TABLE council_sessions (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NULL,
    skill_id        INT UNSIGNED NOT NULL,
    query_text      TEXT NOT NULL,
    final_answer    TEXT NULL,
    consensus_score DECIMAL(5,4) NULL,
    consensus_status ENUM('pending','agreement','partial','conflict') DEFAULT 'pending',
    status          ENUM('running','completed','failed') DEFAULT 'running',
    total_tokens    INT UNSIGNED DEFAULT 0,
    duration_ms     INT UNSIGNED DEFAULT 0,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at    DATETIME NULL
);

-- Pipeline steps (append-only)
CREATE TABLE council_steps (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id  INT UNSIGNED NOT NULL,
    step_key    VARCHAR(64) NOT NULL,
    label       VARCHAR(128) NOT NULL,
    order_index SMALLINT UNSIGNED NOT NULL,
    status      ENUM('running','completed','failed','skipped') DEFAULT 'running',
    summary     TEXT NULL,
    meta        JSON NULL,
    started_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ended_at    DATETIME NULL
);

-- Model responses per step
CREATE TABLE council_model_responses (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    step_id             INT UNSIGNED NOT NULL,
    model               VARCHAR(128) NOT NULL,
    provider            VARCHAR(64) NOT NULL,
    role_name           VARCHAR(64) NULL,
    raw_content         TEXT NOT NULL,
    normalized_response JSON NULL,
    prompt_tokens       INT UNSIGNED DEFAULT 0,
    completion_tokens   INT UNSIGNED DEFAULT 0,
    total_tokens        INT UNSIGNED DEFAULT 0,
    latency_ms          INT UNSIGNED DEFAULT 0,
    tokens_estimated    TINYINT(1) DEFAULT 0,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Pipeline events (immutable event log)
CREATE TABLE pipeline_events (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id  INT UNSIGNED NOT NULL,
    step_id     INT UNSIGNED NULL,
    event_type  VARCHAR(64) NOT NULL,
    payload     JSON NULL,
    created_at  DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
);

-- Skills
CREATE TABLE skills (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    slug        VARCHAR(64) NOT NULL UNIQUE,
    name        VARCHAR(128) NOT NULL,
    description TEXT NULL,
    definition  JSON NOT NULL,
    is_system   TINYINT(1) DEFAULT 0,
    use_count   INT UNSIGNED DEFAULT 0,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Vector embeddings
CREATE TABLE vector_embeddings (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    collection  VARCHAR(64) NOT NULL,
    source_id   INT UNSIGNED NOT NULL,
    vector      JSON NOT NULL,
    metadata    JSON NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_collection_source (collection, source_id)
);
```

---

## 5. API Endpoints

### Council

| Method | URI | Description |
|--------|-----|-------------|
| POST | `/api/council/run` | Start new council session |
| GET | `/api/council/session/{id}` | Get session with all steps |
| GET | `/api/council/session/{id}/events` | Get append-only event stream |
| GET | `/api/council/session/{id}/stats` | Get full session stats |

### Skills

| Method | URI | Description |
|--------|-----|-------------|
| GET | `/api/skills` | List all skills |
| POST | `/api/skills` | Create skill |
| GET | `/api/skills/{id}` | Get skill |
| DELETE | `/api/skills/{id}` | Delete skill |

### System

| Method | URI | Description |
|--------|-----|-------------|
| GET | `/?creator` | Author info |
| GET | `/api/connectors` | List active connectors + models |
| GET | `/api/health` | Health check |

---

## 6. Frontend Specification

### 6.1 Theme System

```css
/* CSS Custom Properties — mandatory variables */
:root[data-theme="light"] {
    --color-bg-primary:    #ffffff;
    --color-bg-secondary:  #f6f8fa;
    --color-bg-surface:    #ffffff;
    --color-border:        #d0d7de;
    --color-text-primary:  #1f2328;
    --color-text-secondary:#636c76;
    --color-accent:        #0969da;
    --color-accent-hover:  #0550ae;
    --color-success:       #1a7f37;
    --color-warning:       #9a6700;
    --color-error:         #cf222e;
    --shadow-sm:           0 1px 3px rgba(0,0,0,.08);
}
:root[data-theme="dark"] {
    --color-bg-primary:    #0d1117;
    --color-bg-secondary:  #161b22;
    --color-bg-surface:    #21262d;
    --color-border:        #30363d;
    --color-text-primary:  #e6edf3;
    --color-text-secondary:#8b949e;
    --color-accent:        #58a6ff;
    --color-accent-hover:  #79c0ff;
    --color-success:       #3fb950;
    --color-warning:       #d29922;
    --color-error:         #f85149;
    --shadow-sm:           0 1px 3px rgba(0,0,0,.4);
}
```

### 6.2 Pipeline Step Card

Each step renders as a **card with tabs**:
- Tab 1: **Response** — LLM output text
- Tab 2: **Statistics** — tokens (prompt/completion/total), latency, model, provider, timestamp
- Tab 3: **Raw** — collapsible raw JSON response

New cards append to the bottom of the pipeline column. Existing cards are **never modified**.

### 6.3 Final Stats Panel

After pipeline completion, show a dedicated panel:

```
┌─ Session Statistics ──────────────────────────────┐
│ Total tokens used:  3,847   Duration: 12.4s        │
├───────────────────────────────────────────────────┤
│ Model                   Tokens  Calls  Latency     │
│ openai/gpt-4o (bothub)  1,203     2    3.2s        │
│ claude-3.5 (bothub)       891     1    2.8s        │
│ gemini-flash (openrouter)  753     2    1.9s        │
│ [synthesis] gpt-4o        1,000   1    4.5s        │
├───────────────────────────────────────────────────┤
│ Most efficient: gemini-flash (376 tok/role)        │
└───────────────────────────────────────────────────┘
```

### 6.4 Template Variables

`TemplateHelper::render($template, $vars)` uses `{{varName}}` placeholders.
All values are HTML-escaped before substitution. Use `{{varName|raw}}` only for
pre-sanitized HTML fragments.

---

## 7. Configuration File (`config.php`)

```php
<?php
// config.php — RevoAp Configuration
// Connector API keys can also be stored in .{provider}.key files
return [
    'app' => [
        'name'    => 'RevoAp',
        'version' => '1.0.0',
        'debug'   => false,
        'url'     => 'http://localhost:8080',
        'timezone'=> 'UTC',
    ],
    'database' => [
        'dsn'      => 'mysql:host=localhost;dbname=opencorum;charset=utf8mb4',
        'user'     => 'root',
        'password' => '',
        'options'  => [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    ],
    'connectors' => [
        'bothub' => [
            'active'   => true,
            'api_key'  => '',   // set here OR in .bothub.key
            'base_url' => 'https://api.bothub.chat/v1',
            'timeout_chat'  => 120,
            'timeout_embed' => 30,
            'max_retries'   => 3,
        ],
        'openrouter' => [
            'active'   => true,
            'api_key'  => '',   // set here OR in .openrouter.key
            'base_url' => 'https://openrouter.ai/api/v1',
            'timeout_chat'  => 120,
            'max_retries'   => 3,
        ],
    ],
    'models' => [
        ['provider'=>'bothub',     'model'=>'openai/gpt-4o',                   'active'=>true],
        ['provider'=>'bothub',     'model'=>'anthropic/claude-3.5-sonnet',     'active'=>true],
        ['provider'=>'bothub',     'model'=>'google/gemini-2.0-flash',         'active'=>true],
        ['provider'=>'openrouter', 'model'=>'openai/gpt-4o-mini',              'active'=>true],
        ['provider'=>'openrouter', 'model'=>'anthropic/claude-3-haiku',        'active'=>true],
        ['provider'=>'openrouter', 'model'=>'google/gemini-flash-1.5',         'active'=>true],
        ['provider'=>'openrouter', 'model'=>'meta-llama/llama-3.1-70b-instruct','active'=>true],
        ['provider'=>'openrouter', 'model'=>'mistralai/mistral-large',         'active'=>false],
        ['provider'=>'openrouter', 'model'=>'deepseek/deepseek-chat-v3',       'active'=>true],
        ['provider'=>'openrouter', 'model'=>'qwen/qwen-2.5-72b-instruct',      'active'=>false],
    ],
    'pipeline' => [
        'synthesis_model'     => 'openai/gpt-4o',
        'synthesis_provider'  => 'bothub',
        'max_roles_per_skill' => 5,
        'memory_top_k'        => 10,
    ],
    'session' => [
        'lifetime' => 7200,
        'secure'   => false,
    ],
    'logging' => [
        'path'  => __DIR__ . '/logs',
        'level' => 'info',  // debug|info|warning|error
    ],
];
```

---

## 8. Security Requirements

| Requirement | Implementation |
|---|---|
| API keys not in version control | `.bothub.key` / `.openrouter.key` in `.gitignore` |
| SQL injection prevention | PDO prepared statements throughout |
| XSS prevention | `htmlspecialchars()` on all output; `textContent` in JS |
| Config exposure prevention | `?creator` returns only static author text |
| Error disclosure prevention | Stack traces only when `app.debug = true` |
| CSRF | Token on all POST forms (if UI forms added) |
| Key file permissions | Warn if `.key` files have permissions > 600 |

---

## 9. Connector Extension Protocol

### Adding a New LLM Provider

1. Create `src/Connectors/{ProviderName}Connector.php`
2. Extend `AbstractConnector`, implement `ConnectorInterface`
3. Set `getProviderName()` to return lowercase provider slug
4. Add entry to `config.php` → `connectors` section
5. Optionally create `.[providerslug].key` file with API key
6. `ConnectorRegistry` will auto-discover on next request

No other files need to be modified. The pipeline uses connectors by interface,
and `ConnectorRegistry::getByProvider(string $provider): ConnectorInterface` handles lookup.

---

## 10. Migration from Consensus-AI

| Old Component | New Location | Changes |
|---|---|---|
| `BothubClient.php` | `src/Connectors/BothubConnector.php` | Implements ConnectorInterface, returns ChatResponse |
| `CouncilEngine.php` | `src/Pipeline/PipelineEngine.php` | Append-only events, stats integrated |
| `SkillAutoCreator.php` | `src/Skills/SkillAutoCreator.php` | Namespace change only |
| `Router.php` | `src/Core/Router.php` | Move to Core |
| `MySQLVectorStore.php` | `src/Storage/VectorStore.php` | Namespace change |
| HTML/JS in PHP | `assets/templates/*.html` + `assets/js/*.js` | Fully extracted |
| `.env` config | `config.php` + `.key` files | Unified config system |

---

## 11. Acceptance Criteria

### Functional
- [ ] `POST /api/council/run` returns session ID and begins pipeline
- [ ] All 4 pipeline layers execute in order without going backward
- [ ] Each step creates a new DB record (no updates to existing steps)
- [ ] Loop detection: pipeline halts if same stepKey appears twice
- [ ] BothubConnector and OpenRouterConnector both produce valid `ChatResponse`
- [ ] `ChatResponse.totalTokens == promptTokens + completionTokens` always
- [ ] `GET /?creator` returns only author info, no config data
- [ ] Light and dark theme work correctly with system preference detection
- [ ] Per-step stats tab shows tokens, latency, model, provider
- [ ] Final stats panel shows per-LLM breakdown
- [ ] `config.php` has 10 models, each with `active` flag
- [ ] Key resolution tries config.php → .key file → $_ENV in order

### Code Quality
- [ ] All PHP files have `declare(strict_types=1)`
- [ ] All public methods have PHPDoc
- [ ] No hardcoded API keys in any PHP file
- [ ] No SQL string concatenation (prepared statements only)
- [ ] Every `src/` subdirectory has a `[Module].md` documentation file
- [ ] `HOW_TO_CREATE_CONNECTOR.md` exists with complete LLM guide
- [ ] `SKILL.md` and `TECHNICAL_SPECIFICATION.md` present in repo root

### Performance
- [ ] L1 role calls are executed concurrently (PHP cURL multi or async approach)
- [ ] Page load < 500ms (no blocking DB calls on initial page render)
- [ ] API response headers include `Content-Type: application/json; charset=utf-8`

---

## 12. Implementation Order (Recommended)

```
Phase 1 — Foundation
  1. config.php
  2. src/Core/Config.php
  3. src/Core/Router.php
  4. src/Core/Application.php
  5. src/Helpers/ (all 5 helpers)

Phase 2 — Connector Layer
  6. src/Connectors/ConnectorInterface.php + ChatResponse + EmbedResponse
  7. src/Connectors/AbstractConnector.php
  8. src/Connectors/BothubConnector.php
  9. src/Connectors/OpenRouterConnector.php
  10. src/Connectors/ConnectorRegistry.php
  11. src/Connectors/HOW_TO_CREATE_CONNECTOR.md

Phase 3 — Domain & Storage
  12. db/schema.sql
  13. src/Storage/ (all repositories)
  14. src/Skills/ (Skill entity + repositories)
  15. src/Stats/ (TokenUsage, SessionStats, Collector, Aggregator)

Phase 4 — Pipeline
  16. src/Consensus/ (ConsensusEngine, NormalizationService)
  17. src/Pipeline/ (PipelineEvent, StepResult, PipelineEngine)
  18. src/Skills/SkillRouter + SkillAutoCreator

Phase 5 — HTTP & UI
  19. src/Http/Controllers/
  20. assets/templates/ + assets/css/ + assets/js/
  21. index.php (thin entry point)

Phase 6 — Documentation
  22. All [Module].md files
  23. README.md
  24. CHANGELOG.md
```

---

## 13. Definition of Done

A feature or module is **Done** when:
1. All acceptance criteria from Section 11 are met
2. The module's `.md` documentation file is complete and accurate
3. All PHP files pass `php -l` (no syntax errors)
4. No secrets are committed (run `git grep -i 'api_key.*=.*[a-zA-Z0-9]\{20\}'`)
5. The pipeline smoke test passes: submit a query → receive final answer with stats

---

*RevoAp Technical Specification v1.0.0 — 2026-05-05*  
*Reference repository: [paulmann/Consensus-AI](https://github.com/paulmann/Consensus-AI)*
