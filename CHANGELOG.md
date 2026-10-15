# Changelog

All notable changes to this project are documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Added

- **Single-model runs: one model, the skill's system prompts, no consensus.**
  A new composer switch (*Single model (no consensus)*) and the matching
  `single_model` option on `POST /api/council/run|stream|jobs` and
  `POST /v1/runs` ask exactly ONE model with the resolved skill's own role
  prompt and use its answer as the final answer. Nothing is compared, so
  nothing is scored: the run records the new verdict
  `consensus_status = 'single'` with `consensus_score = NULL`, instead of the
  trivial `1.0 / agreement` a single response otherwise produced - and it
  never calls a synthesis model to rewrite the answer. L2/L3/L4 are written as
  explicitly `skipped` steps (the journal says the stages were considered and
  deliberately not run), the cycle limit does not apply, and an empty or
  censored-to-empty answer fails the run rather than being dressed up. The
  pool is never reshaped: auto-routing and breaker substitution are switched
  off for the mode, so "one model" means the model that was picked. New
  events: `SINGLE_MODEL_MODE`, `SINGLE_MODEL_MODE_UNAVAILABLE` (the database
  cannot store the verdict yet - the consensus pipeline runs instead of
  writing a blank one) and `SINGLE_MODEL_FINALIZE_IGNORED`. Schema:
  `council_sessions.consensus_status` gains `'single'`
  (`@table-version council_sessions 1.0.5`, `@schema-version 1.9.5`) with
  idempotent migration `022_single_model_consensus_status.sql`. History's
  "Finish session" recognises such a run as "no consensus owed" and never
  schedules an L3 that would invent one. Batch mode refuses the combination.
- **An Automatic Skill Selector leads the consensus skill list.** Chosen
  as a skill, it resolves the run before any council starts: a configured
  decision model reads the task against the whole catalogue and picks the
  best existing skill together with the role that should lead it - or
  decides to build a new one. A new skill is researched first: the selector
  searches arXiv for the task's literature, ranks the papers, and the role
  generator drafts the skill's roles, synthesis prompt and sources from
  that reading; the skill is kept in the library like any draft. The
  council then runs the picked or built skill on the Default configuration's
  models. Settings live in the Models tab (decision model, papers per
  research, whether building is allowed, the fallback skill); keys:
  skills.selector.*.

### Changed

- **The four domain skills now carry professional councils.** Web Application
  Architect, Database Optimizer, PHP 8.5 Code Refiner (renamed from 8.4) and
  UX &amp; Frontend Reviewer get full role prompts built from researched
  methodology - ADR/C4/arc42 for architecture, index and plan fundamentals for
  databases, PHP 8.5 idioms (pipe operator, clone with, #[\NoDiscard]) for the
  refiner, WCAG 2.2 and Core Web Vitals for UX - each with its sources list.
- **A skill document reads the database when there is no file.** The four
  domain skills live in the skills table; the description page looked for a
  catalogue file, found nothing, and rendered Roles (0). It now falls back to
  the kept row, and the tab chip counts sources for kept skills too.

### Fixed

- **The Automatic Skill Selector's decision no longer disappears on the
  replay stream.** The engine has emitted `SKILL_AUTO_SELECTED` since the
  selector shipped, but `assets/js/app.js` never bound the type. The replay
  endpoint writes an explicit `event: <TYPE>` line per frame and `EventSource`
  drops a frame whose type has no named listener, so an auto-selected skill was
  announced to the browser and silently lost. The type is bound now, and the
  status line reports whether a skill was picked or built - no model text
  travels in this event.
- **The consensus-skill catalogue test no longer fails on a correctly listed
  skill.** `tests/ConsensusSkillBindingTest.php` asserted that
  `consensus-reasoning` is the first entry of the slug-sorted list, which stopped
  being true once the Automatic Skill Selector (`auto-selector`) joined the
  catalogue. The check now asserts the sort order over the slugs actually present.
- **`PROJECT_MAP.md` no longer says migrations are applied from History.** They
  are applied from **Settings - Database maintenance**
  (`App\Settings\MigrationManager`); the stale claim is corrected where the
  migration directory is described.
- **`docs/api-rest.md` documents `single_model`,** and its authentication examples
  use `$REVOAP_API_KEY` instead of a placeholder that read like a key value - they
  describe the transport, not a credential.
- **The arXiv sources under a skill description now actually show up.** The
  viewer keeps a "Sources" section out of the rendered body on purpose and
  re-renders it as a linked Sources List - but its parser expects the numbered
  "**Title**" plus "- URL:" shape, which the skill document did not use, so
  the whole section was silently dropped. The document now writes that shape
  (URLs stay in plain text for every other reader), a skill without sources
  gets no heading at all so its honest note stays visible, and the Skills tab
  adds an "arXiv: N" hint on cards that carry sources.

- **The Database card's "Test connection" tested the blanks as blanks.**
  An untouched form sent an empty password while the real credentials sat
  in the protected overlay, so the test answered "Access denied ...
  (using password: NO)" on a perfectly healthy database. Blank fields now
  mean "as configured" - password (blank or masked), user, host, port,
  dbname, charset, socket - and the card reports where the credentials
  came from (" · пароль из конфига"). Covered by
  `tests/DatabaseDsnMergeTest.php`.

- **Batch API runs failed at result download on hosts that hand back a
  pointer.** bothub answers `GET /files/{id}` for a finished batch with a
  short JSON string holding a signed object-storage URL; the client treated
  that string as the JSONL document, so the row read as "not a JSON object"
  and the run failed with "the provider returned no row for this request"
  even though the batch had completed and been billed. `BothubBatchClient`
  now detects the pointer (a short body that is a JSON-encoded or bare
  http(s) URL), follows it once, and returns the real document - fetching it
  WITHOUT the connector credentials, because the storage host is not the
  provider. A failed follow-up degrades to the `/content` route as before.
  Found while verifying attachments on the Batch API: with the fix, a live
  batch run that carried an attached document completed and answered from
  it. Covered by `tests/BatchClientDownloadTest.php`.

- **The Domain Models card never listed its presets.** The preset transfer
  insert had nested its own block - the transfer bar and its helpers -
  inside `hydrateDomainPresets()`, so the function read `root` before the
  declaration further down its own body and threw on every call: the card
  stayed on "Loading.", the provider toolbar never populated and the
  presets never rendered. The block now sits at module scope, and the
  function lists the presets as it always meant to. Found by
  `tests/ModelsTabDomTest`, which had gone red when the insert landed;
  the harness stub also learned `prepend()`, which the transfer bar uses.
- **A models-actualization run finished its job but never closed its
  session.** `JobService::createModelsActualizeJob()` opens a
  `council_sessions` row up front so every session endpoint resolves while
  the run works, and nothing ever closed it: the queue row went
  `completed`, the Models tab showed the result, and History kept showing
  the run as `running` for ever - no steps, no answer. Six such runs had
  accumulated on the production host. `PipelineJobRunner::runModelsActualize()`
  now closes the session on every terminal path: success, a `problems`
  verdict, a worker without the actualizer wired in, and a thrown
  exception. The write is best-effort - a session row that could not be
  updated is logged and never turns a finished run into a failed job.

- **The composer's default Consensus skill left the model panel empty.** The
  "Consensus skill" control's first option is a placeholder with an empty value -
  the skill the server uses by default - and nothing resolved it: the binding was
  read as "no skill", nothing was applied and the note was cleared, so the
  *Consensus models* panel showed no list at all until a named skill had been
  picked. Saving the pool was refused for the same reason. One resolver
  (`consensusSkillInEffect()`) now decides what the composer means for both
  callers, and the named skills were never affected: two of them carry no preset
  of their own and already fell back to the Default pool, the other two have
  their own.

- **The recorded schema version lagged a release behind.** `db/schema.sql`
  declares the schema version for the release; migrations 014+ deliberately carry
  no top-level `@schema-version`, and only "Apply Migrations" records the declared
  value. Nothing had pressed it since the release, so `app_config` still held
  1.9.0 while the schema was at 1.9.2 and the two surfaces of the same database
  disagreed: History reported drift, Settings answered "Schema OK". The recorded
  version is synced, and `versionStatus()` now reports the release's own schema
  version as a mismatch, so a lagging value can no longer be reported as "nothing
  pending".

### Added

- **A Skills tab, to the right of History, and Statistics moved under API.**
  The new tab lists every consensus skill - bundled and kept - with its
  short description, binding and scoring; opening one writes the full
  description as a share document (roles verbatim, arXiv sources included)
  and shows it in a new window, through MD.Viewer when that extension is
  installed. The document is rendered by `App\Skills\SkillDoc` and
  published through the same writer the Share button uses
  (`DocumentShareController::publish()`), so limits and the viewer URL
  cannot drift into a second copy. The bundled skills now carry their arXiv
  sources (`"sources"` in their definitions). Statistic moved from the
  header into the sidebar's "Routes & build" block, directly under API -
  same id, same machinery. Covered by `tests/SkillDocsTest.php`.

- **The research journal keeps every article a search found, not just the
  shown ones.** `research_runs.candidates_json` (migration 021) stores the
  whole ranked window arXiv returned - scores and match notes included -
  while the shown top-N stay in `papers_json` exactly as before; History
  summaries now report "M of N papers" when the window was larger than the
  answer. Covered by extended `tests/ResearchJournalTest.php`.

- **Attached documents are named where a run is read.** The query card in
  the Chat tab shows "Files: brief.md, data.csv" under the question, and
  History shows the same names - an "Attached files" block in the run
  detail and a "files: N" count on each list item. The names travel in the
  session's `options_json.attachments` (names only - contents are never
  persisted), written both at enqueue and, for worker runs, by the engine
  at run start, so a batch run parked for hours already says what it
  carries. Covered by the extended `tests/ChatAttachmentsTest.php` checks.

- **The chat composer accepts text attachments.** A new "Attach files"
  control takes up to six plain-text documents per run - txt, md, csv, json,
  source code and most other text formats - reads them in the browser, and
  sends them with the query: `POST /api/council/jobs` carries them in the
  durable payload, the worker re-validates them
  (`App\Pipeline\QueryAttachments`), and every model call - L1, the round-2
  revision, synthesis and the arbiter - reads the same "[ATTACHED DOCUMENTS
  ...]" block ahead of the query, so the contents travel through the bothub
  chat-completions request like any other message text. Limits: six files,
  128000 characters each, 300000 together; names are reduced to basenames,
  CRLF becomes LF, NULs are stripped, and unknown formats are refused by
  name. The browser refuses what the server would refuse; the server refuses
  what the browser missed; and the Batch API carries them too - its L1
  planner builds every item through the same message builder the synchronous
  path uses, and the continuation stages read the same options - while a
  legacy-mode run that carries files is routed through the worker instead.
  Covered by `tests/QueryAttachmentsTest.php`,
  `tests/ChatAttachmentsTest.php` and `tests/BatchAttachmentsTest.php`.

- **The searcher keeps a research journal.** Every search and analysis now
  lands in `research_runs` (topic, query, candidates, the papers as shown
  with their scores, provider/model/tokens, the analysis, the proposals, the
  plan and its state), and every paper shown is remembered in
  `research_seen` - first sighting wins, and the memory survives deleting the
  run it was born from. The Research card gained: an "Only new" switch that
  drops papers seen in earlier searches (and reports how many were hidden), a
  seen-before note on each paper, a History list that loads any run back
  exactly as it was, plan statuses (draft / active / done / dropped), live
  checkboxes whose state is written back and carried into the copied or
  downloaded plan, and deletion - one run, or the whole journal. Writes are
  best-effort: an unavailable or unmigrated database never fails a search -
  the answer says `saved: false` and the card explains. Migration
  `020_research_journal.sql` ships the two tables and `db/schema.sql`
  declares them, so a fresh import and an incrementally migrated database
  stay identical. Covered by `tests/ResearchJournalTest.php` and the extended
  `tests/ResearchTest.php` / `tests/AnalysisProposalsTest.php`.

- **A searcher over arXiv, with a chosen model reading the result.** The
  Models tab gained a Research card: type a topic, search the public arXiv
  API - no account is involved and none is needed, one polite request per
  search (a contact User-Agent, a timeout, a result ceiling) - and either
  stop at the ranked list or hand the papers to a model that writes an
  evidence-bound brief: what the papers agree on, where they disagree, what
  is established against what is speculative, and what to change, each change
  citing the paper it came from. The analysis model has a setting of its own
  (`research.provider` / `research.model`) that inherits the synthesis model
  until one is chosen. Nothing is stored: no document is written, no run is
  created. Covered by `tests/ResearchTest.php`, which pins the parser and the
  prompt against a real captured feed.

- **The searcher ranks papers by relevance instead of arXiv's ordering.**
  A search now asks arXiv for a wider candidate window and re-orders it
  locally (`App\Research\PaperRanker`): a topic term found in the title
  weighs three times one found in the abstract, single words match whole
  words with their plural ("model" finds "models" but not "reagents"),
  versions of the same paper collapse to one row, and equal scores fall back
  to the newer paper and then to the id - the same search twice gives the
  same order twice. Every returned paper carries `score` and `matched`, so
  the card shows why it was kept, and the answer reports how many candidates
  were considered. Covered by `tests/PaperRankerTest.php` (23 checks).

- **A research analysis ends in a change plan.** The analyst is asked to
  close with a "Changes to consider" section; `App\Research\AnalysisProposals`
  extracts those items (a changes heading of any depth, bullets or numbered
  items, continuation lines glued back on, and a citation fallback for a
  model that skipped the heading), and the answer carries both the proposals
  and a ready markdown plan - topic, source, papers, and the changes as
  checkboxes - which the card offers to copy or download as
  `change-plan-<topic>.md`. Still nothing is stored server-side. Covered by
  `tests/AnalysisProposalsTest.php` (20 checks).

- **The step card says which role answered.** The L1 event payload carries
  `role_label` next to the role key, and the card renders a "Role" row only
  when a step has one - the activity line always knew the role, but the card
  showed only the model.

- **The actualization run's Final answer now reports what it synchronized.**
  `App\Models\ModelsActualizeReport` renders the envelope the run already
  persists into the document an operator asks for: how many models were
  downloaded, how many rows were already in the catalogue, how many are
  new, how many duplicates collapsed, what the provider serves now against
  what the catalogue holds, which rows were marked stale and which were
  dropped from the active pool, and which model replaced which in the
  linked roles (analyzer / synthesis / arbiter) - plus the presets and the
  analyzer's own reasoning, the catalogue invariant, the warnings and the
  timing. Nothing is recomputed or fetched: the text comes from
  `revoap_jobs.result_json`, a section whose data is absent is omitted
  rather than printed empty, and a run that ended in `problems` leads with
  the failure and the stage it stopped in.

- `bin/models-actualize-heal.php` closes the sessions of runs that
  finished before the fix, from the same stored envelope. Dry run unless
  `--yes`; it only touches runs whose job is already in a terminal state.

  Covered by `tests/ModelsActualizeReportTest.php` (52 checks), which also
  pins the runner/worker wiring that closes the session.
- **Share button on every copyable block.** Next to "Copy" / "Copy MD" a block
  now offers "Share": `POST /api/share/document`
  (`src/Http/Controllers/DocumentShareController.php`) writes the block - the
  same Markdown snippet "Copy MD" copies - into `share/<topic>.md`, named after
  the block's heading. The answer carries the link to copy: through MD.Viewer
  (`extensions/md-viewer/md.php?file=<name>`) when that extension is installed,
  the plain `/share/<name>` URL when it is not, and the client
  (`assets/js/copy-buttons.js` 1.2.0) opens the viewer link in a new tab, copies
  the link and reports the outcome in a toast that fades out on its own. A
  refusal from the endpoint is shown with its reason and never claims success.

  `App\History\DocumentSlug` transliterates the topic into the ASCII name
  MD.Viewer's `?file=` accepts - Cyrillic transliterated, Latin accents folded -
  which is also what keeps a hostile topic from steering the write out of
  `share/`; the resolved directory is compared against `realpath()` before the
  write and the payload is capped at 2 MiB. The endpoint is operator-owned:
  `ApiAuth::isAdminPath()` keeps external API keys off it.

  Covered by `tests/DocumentShareTest.php` (48 checks) and
  `tests/CopyButtonsShareDomTest.php` -> `tests/harness/copy-buttons-share.mjs`
  (55 checks). `tests/harness/dom-stub.mjs` gained `:scope > x` support, the one
  selector shape the modules use that it could not evaluate.

- **Download MD on every copyable block.** The fourth control in the group
  saves the same snippet the "Copy MD" button copies as a file on the
  operator's own machine: a Blob handed to the browser through an anchor with
  a `download` name, so nothing is sent to the server, no window opens and the
  clipboard is not touched. The file is named after the block's topic with the
  characters a file system refuses replaced by a dash - not transliterated,
  unlike the Share name, because it never reaches a server path and a downloads
  folder reads better in the language the block is written in. `assets/js
  /copy-buttons.js` is 1.3.0; `tests/harness/copy-buttons-share.mjs` is 78
  checks and the chat-stream harness pins four controls per group.

## [1.9.0] - 2026-09-22
### Added

- **Bothub prompt caching: enabled in the request and accounted for in the
  statistics.** Provider-side prefix caching had no support at all, so a
  repeated prompt prefix — the whole point of the L1 fan-out, where every
  role shares one base-file + profile + role prompt — was billed at full
  input price on every call, and the cache figures Bothub returns had
  nowhere to go. `src/Connectors/PromptCache/` now carries the documented
  capability table (`Capabilities`: which family needs explicit
  `cache_control` breakpoints, which caches automatically, which is
  undocumented, plus minimum prompt size and write/read multipliers), the
  resolved operator policy (`Policy`, clamped to the documented values) and
  the pure request planner (`Planner`). `AbstractConnector` applies the plan
  at the single funnel the synchronous and batch surfaces share, and the
  base connector's `promptCachePolicy()` returns `null`, so every provider
  without a documented caching surface — OpenRouter included — keeps the
  request body it had before, byte for byte; automatic families (OpenAI,
  Grok, Moonshot, Groq, DeepSeek) and undocumented families never receive
  composite `content` parts. `usage: {include: true}` is sent so the
  provider reports cache tokens back. On the response side both dialects are
  read — OpenAI-like `usage.prompt_tokens_details.cached_tokens` and
  Anthropic-like `cache_read_input_tokens` / `cache_creation_input_tokens`,
  one read number so an aggregate cannot double-count — plus Bothub's signed
  `cache_discount`. `ChatResponse`, `TokenUsage` and `SessionStats` gained
  the cache fields additively, and the figures are stored in
  `council_steps.meta` and in three new nullable columns on
  `council_model_responses` (`cache_read_tokens`, `cache_write_tokens`,
  `cache_discount`) shipped as the idempotent
  `db/migrations/018_prompt_cache_stats.sql`; `ResponseRepository` probes for
  those columns, so a database that has not applied `018` keeps working.
  Chat shows the figures per step and per run, History per run and per
  stage, and Settings gains a **Prompt caching** card (on/off, TTL,
  breakpoint count, size guard) rendering the same capability table the
  request builder reads, saved through `POST /api/settings/prompt-cache`.
  A provider that returned no cache block renders as "no cache data", never
  as a zero that would read as a cache miss. `buildL1Messages()` no longer
  appends the query-dependent memory recall to the `system` message — that
  made every cached prefix unique — and puts it in the user turn ahead of
  the query instead. See [`docs/PromptCaching.md`](docs/PromptCaching.md).
- **The Models tab now reports a finished run as finished, and binds every
  verdict to the job it belongs to.** A terminal `problems` verdict used to
  paint all three stage rows with the success colour, so a failed
  actualization was indistinguishable from a successful one at a glance, and
  pressing "Actualize linked models" again left the PREVIOUS run's verdict on
  screen until the worker published the new run's first stage — the reported
  "the statuses do not reset and stay green". The card now clears itself
  synchronously on the press, holds the awaited job id (from the enqueue
  answer, or the run the queue names) and shows "Queued — waiting for the
  worker to pick it up" until that run speaks, marks the stage the run died
  in with `oc-actualize-stage-failed` (`--color-error*`, both themes), and
  prints the run identity — `Run: job #12 · started … · finished …` — with
  every verdict. `GET /api/models/actualize/status` gains `failed_stage`
  (the stage the run died in, `null` when unknown), and `activeJob()` no
  longer stops at the finished job the status record names, so a freshly
  enqueued run is reported as the active one instead of being hidden behind
  the previous verdict.
- **`code_state` in the worker health snapshot: "is the running process
  executing the deployed code?"** A long-lived worker keeps the PHP classes
  it loaded at start-up, so a fresh deploy can leave "new caller, old callee"
  inside one process — a mix with no syntax error anywhere, which surfaced
  only as `Call to undefined method App\Core\RuntimeConfig::updateOrFail()`.
  `GET /api/health/worker` now reports `code_state` (`current` / `stale` /
  `unknown`) plus `code_stale_reason`, `code_newest_at` and `code_newest_file`,
  derived from the beacon's `app_version` versus `app.version` and from
  comparing the process start with the newest mtime among `src/**/*.php`,
  `index.php` and `config.php` (mtime only, cached 10 s in-process — one walk
  per two panel polls). Settings → Worker renders a banner naming the file,
  the times and the remedy (`sudo systemctl restart revoap-worker`), and a job
  that fails with "Call to undefined method/function/constant" keeps its
  original error text and gains the same advice (`stale_code: true` on the
  job's error JSON).
- **Models tab (`ModelsTab`, default `true`).** The model-facing half of
  Settings now has its own tab in the header, immediately after Chat:
  Providers / API keys, the model catalogue, the per-domain consensus
  presets and the request analyzer. The tab button tracks `ModelsTab`
  alone (so the affordance is visible before a token exists) and the body
  is gated by the same admin token — the token only ever lives in the
  HttpOnly `oc_admin` cookie, so unlocking either Models or Settings
  unlocks both. New: `GET /api/models/status`, `assets/js/models.js`,
  `assets/js/admin-ui.js` (shared lock screen / DOM helpers / sticky
  section strip, now used by both admin tabs) and
  `assets/css/components/models.css`. Settings keeps the host
  configuration (Interface, Profile, Database, Migrations, Worker, Cache,
  Runtime, Session). Element ids are unchanged, so anchors such as
  `#oc-domain-models` keep resolving.
- **Choosing a consensus preset now updates the Chat model pool.**
  `ModelSelector` gained `applyPreset(rows)` and the preset cards gained
  "Use as my consensus pool": the preset the API returns is pushed into
  the sidebar selector — which persists it the way the panel always has —
  without a reload. Models that are not currently available are skipped
  and reported, and an empty or unusable preset leaves the pool untouched
  with an explanation instead of emptying it.
- **"Actualize linked models" (`POST /api/models/actualize`).** Fetches the
  live catalogue from every active connector, diffs it against the
  persisted one, asks the analyzer model (`analyzer.provider` /
  `analyzer.model`, through the connector layer and the response cache)
  for the strongest models per consensus — most capable for `mathematics`
  and `physics`, strongest-but-from-different-base-vendors for `default`
  and the rest — and writes the result to the presets, the catalogue
  (`var/config/runtime.json`) and your model pool. It is long-running, so
  it runs in the worker as a durable job
  (`payload.kind = 'models_actualize'`) with stages published to
  `revoap_job_events`: `analysing_models` → `assigning_presets` →
  `success` / `problems`. Supporting endpoints:
  `GET /api/models/actualize/status` (stage, timing, warnings, snapshot,
  worker availability) and `POST /api/models/actualize/rollback`. Enqueue
  is idempotent — while a run is queued or in flight the call returns that
  job instead of starting a second one.
- **Rollback for an actualization.** A snapshot of the pre-run state
  (catalogue rows, every preset definition, the model pool the sidebar
  reported) is stored under the `app_config` key
  `models.actualize.snapshot.latest` (scope `models`) before anything is
  written; `POST /api/models/actualize/rollback` restores it. The run is
  all-or-nothing: without a snapshot it refuses to write at all, and a
  failure while writing restores the snapshot instead of leaving the app
  half-updated.
- **Bothub batch requests (`:batch`) — a second, asynchronous execution
  contour.** Models whose identifier carries the `:batch` suffix are served
  only through the provider's asynchronous Batch API; called synchronously
  they answer `404 MODEL_NOT_FOUND`, and the operator watched that 404 arrive
  at the very end of a run that had already paid for everything before it.
  `src/Connectors/Batch/` now carries the whole client: `BothubBatchClient`
  (JSONL upload with `purpose=batch`, `POST /batches` with
  `endpoint=/v1/chat/completions` and `completion_window=24h`, status with
  `include_usage`, cancel, list, result download, and `GET /batches/models`
  for the batch-capable catalogue), `BatchJsonlBuilder` /
  `BatchJsonlDocument` (one line per call, a deterministic `custom_id`
  checked locally against the provider grammar by `BatchCustomId` — a
  guessed id is unrecoverable), `BatchResultParser` / `BatchResultReport`
  (results are matched by `custom_id`, never by line order; each row carries
  its own `status_code`, and a call the provider never answered is reported
  as "not received" instead of being silently dropped) and a
  `BatchTransportInterface` with a curl implementation plus a substitutable
  transport, so every path above is exercised without network. The resumable
  worker task is `src/Batch/BatchPipeline.php` (`payload.kind =
  'batch_pipeline'`): its state lives in `revoap_jobs.result_json.batch` and
  the job parks itself on `next_run_at` — 30 s between polls for batches up
  to 1000 rows, 150 s above that — so a batch survives a worker restart, and
  a cancel is persisted as `cancel_requested` and only becomes `cancelled`
  after `POST /batches/{id}/cancel` has been acknowledged. Chat gains a
  **Run via Batch API** mode; only the L1 fan-out is batched (L2–L4 depend on
  its answers and therefore run synchronously once the results are in), and
  the card renders the phase, the provider status, the counts, the 24-hour
  window and the cancel button, while History shows such a run as
  "awaiting batch" rather than as a stalled `running`. The list of
  batch-capable models is the provider's own `GET /batches/models`: when that
  call is unavailable the capability reads as *unknown*, never as "not
  supported".
- **Actualize: the catalogue import is now the first stage, and the section
  has its own model.** A run used to start from whatever catalogue happened
  to be stored, so a stale list quietly produced stale preset assignments.
  It now begins at `importing_models`, executed in the worker, with the
  source chosen explicitly (bothub or openrouter); the report names what was
  fetched, added, already present and discarded, and a failed import fails
  the run loudly instead of continuing on the old list. The section also
  gained its own analyzer — `models.actualize.analyzer`, stored in
  `app_config` and *inherited* from `analyzer.*` when unset, so pointing
  Actualize at a stronger (or cheaper) model can no longer silently re-point
  the L0 query classifier (`ModelsActualizeSettings` reports which of the two
  is in effect). The assignment prompt was rewritten around an output
  contract — exactly one JSON object, no code fences, no commentary before or
  after it, no placeholders, model ids copied verbatim from the catalogue it
  was given, plus a complete worked example explicitly marked as invented —
  and it allows exactly one corrective retry, whose refusal text quotes the
  rejected reply verbatim.
- **The admin-key gate can be switched off (`Protect_Settings_by_Admin_Key`).**
  A new setting in `config.php`, overridable from `.config.php`, accepts `1`
  — the historical behaviour, and what an *absent* key means: the operator
  surface requires the token from `.admin.key` (or `REVOAP_ADMIN_TOKEN`) —
  and `0`, which stops asking for it anywhere. The decision is centralised in
  `AdminAuth::gateState()`, so the controller guards, the status probes and
  the front end agree by construction: with `0` the guards pass without a
  token, the unlock prompt and every "admin token required" banner
  disappear, nothing is reported as `unauthorized`, and a banner under the
  header states that the surface is unprotected. **This is a deliberate
  hole, not a convenience:** at `0`, anyone who can reach the host can
  rewrite the provider API keys, the model catalogue and the consensus
  presets, change the database DSN, apply and baseline migrations and
  recreate the database, purge the response cache, read the log files,
  rewrite the IP allow/deny lists, read every stored session (query, full
  final answer and prompts) and mint, rotate or revoke API keys — with no
  credential at all. Use `0` only on a host nobody else can reach
  (`.htaccess`/HTTP auth, loopback-only, or a private machine).

### Changed

- **Rebrand OpenCorum -> RevoAp.** Canonical naming is now RevoAp in the
  admin-token environment variable, log files, worker binary and systemd
  units:
  - `REVOAP_ADMIN_TOKEN` is the canonical token env var;
    `OPEN_CORUM_ADMIN_TOKEN` is still accepted as a deprecated legacy
    alias that logs a one-time warning without ever exposing the token
    value.
  - Log files are `logs/revoap-debug.log` and `logs/revoap-error.log`;
    the LogsService whitelist slugs are `revoap-debug` / `revoap-error`
    (legacy `opencorum-debug` / `opencorum-error` slugs keep resolving).
  - Worker entry point is `bin/revoap-worker.php` with systemd units
    `deploy/systemd/revoap-worker.service` and `revoap-worker@.service`
    (plus `deploy/systemd/worker.env.example`).
- **Worker/settings diagnostics** now recommend the consolidated schema
  import (`Settings -> Database -> ReCreate DB`, type RECREATE to confirm)
  instead of obsolete one-off migrations such as `011_opencorum_jobs.sql`.
- **Schema compatibility:** the DSN database name `opencorum` is
  intentionally unchanged; the two job tables were renamed — see
  "Database" below.

### Database

- **SQL identifiers renamed: `opencorum_jobs` → `revoap_jobs` and
  `opencorum_job_events` → `revoap_job_events`.** This finishes the
  OpenCorum → RevoAp rebrand on the database side — the two job tables
  were the last `opencorum_` identifiers in the code. Renamed
  everywhere: every `FROM` / `INTO` / `UPDATE` / `JOIN` / `SHOW TABLES
  LIKE` / `SHOW COLUMNS FROM` statement (`App\Jobs\*`,
  `App\Http\Controllers\JobsController`, `App\Api\ApiV1Controller`,
  `index.php`, `bin/revoap-worker.php`), the `db/schema.sql` DDL plus
  its version annotations (`-- @table-version revoap_jobs 1.1.0`,
  `-- @table-version revoap_job_events 1.0.0`), and the docs/tests.
  Index names (`idx_jobs_*`, `idx_job_events_*`) never carried a brand
  prefix and are unchanged. No new file was added to `db/migrations/` —
  `db/schema.sql` stays the source of truth.
- **Migrating an existing database.** Both new and old tables were
  empty at the time of the rename, so verify that first and then rename
  the tables in place:

```sql
-- 1) verify that all four tables are empty BEFORE touching anything
SELECT 'opencorum_jobs'                AS tbl, COUNT(*) AS n FROM opencorum_jobs
UNION ALL SELECT 'opencorum_job_events', COUNT(*) FROM opencorum_job_events
UNION ALL SELECT 'revoap_jobs',          COUNT(*) FROM revoap_jobs
UNION ALL SELECT 'revoap_job_events',    COUNT(*) FROM revoap_job_events;

-- 2) run ONLY when every n = 0
DROP TABLE revoap_jobs, revoap_job_events;
RENAME TABLE opencorum_jobs      TO revoap_jobs,
             opencorum_job_events TO revoap_job_events;
```

- The simple alternative is to drop all four tables and re-import the
  idempotent `db/schema.sql`, which already creates the `revoap_*`
  names.
- **`app_config` convergence.** After either path the table-version
  rows are stale: `table_version.opencorum_jobs` / `…opencorum_job_events`
  still exist while `table_version.revoap_jobs` /
  `…revoap_job_events` are missing, so `Settings → Database → Check DB`
  reports `no_config_entry` for both tables (`schema_ready=false`, no
  structural drift). Delete the stale keys and insert the new ones —
  the same rows the ReCreate DB action seeds from the `@table-version`
  annotations:

```sql
DELETE FROM app_config WHERE config_key IN
  ('table_version.opencorum_jobs', 'table_version.opencorum_job_events');
INSERT INTO app_config (config_key, config_value, scope) VALUES
  ('table_version.revoap_jobs', '1.1.0', 'schema'),
  ('table_version.revoap_job_events', '1.0.0', 'schema')
ON DUPLICATE KEY UPDATE config_value = VALUES(config_value);
```


### Fixed

- **The Automatic Skill Selector's decision no longer disappears on the
  replay stream.** The engine has emitted `SKILL_AUTO_SELECTED` since the
  selector shipped, but `assets/js/app.js` never bound the type. The replay
  endpoint writes an explicit `event: <TYPE>` line per frame and `EventSource`
  drops a frame whose type has no named listener, so an auto-selected skill was
  announced to the browser and silently lost. The type is bound now, and the
  status line reports whether a skill was picked or built - no model text
  travels in this event.
- **The consensus-skill catalogue test no longer fails on a correctly listed
  skill.** `tests/ConsensusSkillBindingTest.php` asserted that
  `consensus-reasoning` is the first entry of the slug-sorted list, which stopped
  being true once the Automatic Skill Selector (`auto-selector`) joined the
  catalogue. The check now asserts the sort order over the slugs actually present.
- **`PROJECT_MAP.md` no longer says migrations are applied from History.** They
  are applied from **Settings - Database maintenance**
  (`App\Settings\MigrationManager`); the stale claim is corrected where the
  migration directory is described.
- **`docs/api-rest.md` documents `single_model`,** and its authentication examples
  use `$REVOAP_API_KEY` instead of a placeholder that read like a key value - they
  describe the transport, not a credential.
- **A failed model actualization could not claim a restore it never made.**
  The summary message of a run that died while writing was a constant —
  "Actualization failed while writing; the previous state was restored." —
  while the warning next to it was built from the actual outcome, so a run
  whose rollback also failed reported `The snapshot could NOT be restored:
  Call to undefined method …` and `the previous state was restored` in the
  same card. Both texts now branch on the same fact: a successful restore
  reads exactly as before, a failed one says so, names the error and points
  at `var/config/runtime.json` as possibly inconsistent. The envelope's
  `error` gains `restore_ok` / `restore_error` so the UI can render it
  without parsing prose.
- **Saving models, providers or the analyzer could report `{"ok":true}` for
  a write that never reached the disk, and the next page load painted the
  shipped defaults back over the operator's list.** `RuntimeConfig::write()`
  called `file_put_contents()` + `rename()` and returned `false` on failure
  — a return value every caller ignored — while `Config::reload()` then
  re-read `var/config/runtime.json` from disk. On a host where that file was
  left owned by root (a manual `chown`, a restored backup), the reload saw
  either the old file or, because `read()` treated "cannot read" as "no
  overrides", nothing at all: every admin-saved setting silently reverted to
  its `config.php` default. That is the reported "Save Models resets to the
  initial settings" and "the analyzer says Saved but does not save".

  The write path is now verified end to end. `RuntimeConfig::writeChecked()`
  reads the file back after the atomic rename and compares it byte-for-byte
  with what it meant to write; `readChecked()` distinguishes "no file yet"
  (a valid empty overlay) from "exists but unreadable" and "not valid JSON"
  instead of collapsing all three into `[]`; and `updateOrFail()` /
  `forgetOrFail()` throw a `ConfigWriteException` carrying the failing stage
  (`mkdir`, `encode`, `tmp_write`, `rename`, `verify`), the target path and a
  `StorageProbe` snapshot of the filesystem (directory/file writability,
  mode, owner, PHP process user, free space, the last PHP error). The same
  verification applies to the `.{provider}.key` writer, which used to drop a
  pasted API key silently.

  Every save endpoint now goes through one helper, `SettingsPersist`, and
  answers `500 config_write_failed` with that detail block instead of `ok`:
  `POST /api/settings/models`, `POST /api/settings/providers` (including the
  key file), `POST /api/settings/database`, `POST /api/settings/runtime`,
  `POST /api/analyzer/settings`, `POST /api/security/*` (the firewall rules
  now throw through `SecurityStore`) and `POST /api/settings/models/import`.
  Each also echoes `persisted` — what the file on disk holds right now — so
  the client can verify the claim instead of trusting the status code.

  In the UI, `assets/js/models.js` gained one `reportSave()` /
  `describeSaveFailure()` pair that every Save button uses. A button may only
  say "Saved." when the response is `ok`, carries no `error`, and the echoed
  `persisted` agrees with what was sent; otherwise it shows the server's
  message plus the file, stage and permissions. The unconditional
  `status.textContent = 'Saved.'` on the old `res.ok`-only path is gone, the
  analyzer save compares the model it sent against the model the file holds,
  and the domain-preset buttons report the in-memory fallback ("saved in
  memory only — import db/schema.sql") rather than claiming a save that the
  next request will forget.
- **Saving the model catalogue stripped the curated metadata off every row.**
  The Models form only carries `provider` / `model` / `active`, and
  `saveModels()` wrote that reduced shape straight into `runtime.json`, so
  the `label`, `rank`, `category` and fresh-install `default` flags of the
  shipped `config/models/*.php` catalogue were lost on the first save (and
  with them the ordering the recommended-list ordering depended on).
  `ModelCatalogMerge::mergeSubmitted()` re-attaches the metadata of the row
  that is already in effect and takes only the operator's `active` toggle
  from the form.
- **"Import models" persisted nothing — the imported catalogue lived in the
  browser tab and was gone after F5.** `importModels()` fetched the provider
  catalogue, answered with it and returned; the rows only ever reached
  `state.lastImport` in the page. The import now merges the fetched ids into
  the same `models[]` list in `var/config/runtime.json` that "Save models"
  writes and that `Config::getActiveModels()` (the pipeline's L1 fan-out)
  reads: existing rows keep their order and every flag, a model that is
  already in the catalogue is counted as a duplicate rather than added
  twice, and newly discovered models are added inert (`active=false`,
  `category=imported`) so a bulk import cannot change what the pipeline
  calls without the operator ticking the row. The response reports
  `imported.added` / `duplicates` / `present` / `truncated` against the
  configured `models.catalog.max_rows` ceiling, and the tab reloads
  `/api/settings` so the stored rows are on
  screen immediately.
- **The model lists gained filters.** The Models tab catalogue has a
  debounced text filter and an "Only models with a newer version" checkbox
  (a row another row in the list supersedes — `newerVersionOf()`, a port of
  `App\Models\ModelVersion` pinned against the same fixture table), and the
  Chat sidebar's consensus pool has a text filter and a "Show all models"
  toggle that stops the add-model dropdown from hiding models already in the
  pool. Filtered-out catalogue rows are *hidden* rather than removed, so a
  save still carries them, and both surfaces count what the filter is
  hiding — including "N selected hidden by filter — still in the pool", so a
  filter can never look like a deleted model.
- **"Actualize linked models" could spin on "Analysing models" forever and
  then reset to the initial card, with no indication of whether it worked.**
  Three separate causes, all fixed. (1) The status record was written only
  once, by `finish()`, at the very end of a run: the stage transitions went
  to the job-event audit table, which the status endpoint never reads, so
  the UI could not see `assigning_presets` at all. Every stage is now
  persisted through `ModelsSnapshotStore::saveProgress()`. (2) A failure
  outside the write block (planning, the snapshot, the connector layer)
  escaped `run()` entirely, leaving no status record — which the endpoint
  reports as `idle`, i.e. the untouched card. `run()` is now wrapped and
  always ends in a terminal envelope, and `recordFailure()` covers an
  exception that escapes even that, so the run always shows `success` or
  `problems` with the error text (`error.message`, `error.class`).
  (3) The actualization never heartbeated its queue row, unlike the pipeline
  path: past `stale_after_seconds` (300s) another worker's recovery sweep
  declared the still-running job stale, requeued it and eventually failed it
  as "recovery exhausted". Every stage now heartbeats and refreshes the
  presence beacon. The status endpoint also reads the *last* actualization
  job (not only an active one) so a job that failed without leaving a
  record is reported as `problems` with its `error_json`, plus
  `queued_seconds` so "queued and no worker is polling" is distinguishable
  from "working". The assignment prompt now asks for the newest flagship of
  each preferred family (GPT/ChatGPT, Claude/Opus, Grok, Qwen, DeepSeek,
  Gemini, Llama, Mistral, GLM) and names the live ids that actually exist in
  the catalogue it was given — a family the provider does not serve is never
  mentioned, so it cannot be invented.
- **Job mode: chat could sit on "Job #N running — no events yet" forever.**
  `PipelineEngine::run()` ignored `options.session_id`, so a worker run
  created its *own* `council_sessions` row and wrote every
  `pipeline_events` row there, while the browser followed
  `/api/council/session/{id}/events/stream` for the id `JobService` had
  pre-created. The replay endpoint therefore polled an empty session
  forever, even though the job finished successfully and History showed
  the result under the other row. The engine now reuses a pre-created
  session when the caller supplies one and the row exists (logged as
  `pipeline.session.reused`; a missing row degrades to a new session and
  logs `pipeline.session.reuse_miss`), and `PipelineJobRunner` audits the
  invariant with `worker.job.session_mismatch`. Every legacy caller that
  omits the option keeps the historical behaviour byte-for-byte.
- **Job mode UI: a terminal run is no longer rendered as still running.**
  `workerWarnCaption()` knew only running / queued / "unknown", so a job
  that finished while the live stream delivered nothing was reported as
  "Job #N is queued — waiting for a free worker slot". The end of the
  reconnect loop also never repainted the activity card, which left the
  v1.9.0 hint on screen permanently next to a `running` badge. There is
  now a terminal branch per outcome (`completed` / `failed` /
  `cancelled`, with and without delivered frames) plus a reconciliation
  step that updates the status line, the activity badge and a
  “result is in History” banner with a working History button. A
  degraded live channel is explicitly *not* reported as a task failure,
  the probe interval stops at the terminal verdict, and a deliberate
  teardown (Reject / supersede / page unload) is never mistaken for a
  job that ended on its own.
- **Job mode UI: the wait caption is retired as soon as the run is alive.**
  The v1.9.0 hint was only ever cleared by an event that happened to set
  the status line itself. L1 model calls routinely outlast the warning
  window, so a healthy run could display "Job #N is running — no events
  yet" for minutes while L1 cards streamed underneath it. The first frame
  of a run now retires the probe's caption (guarded by an exact text
  match, so a pause notice or an error that claimed the line first is left
  untouched).
- **Eleven engine event types were silently dropped by the browser.**
  The replay endpoint emits an explicit `event: <TYPE>` line per frame, and
  per the EventSource specification `onmessage` fires only for frames
  without an event field — so any type missing from the client's
  `knownTypes` list vanished with no error and no trace. `PIPELINE_STARTED`,
  `REQUEST_ANALYZER`, the consensus round-progress pair, `L1_REPAIR_DISPATCH`
  and the whole arbiter / main-model-synthesis / provider-fallback surface
  (11 types) were in that gap. All are bound now, and
  `tests/SseEventContractTest.php` parses the engine's `emitEvent()` call
  sites and the client list so a new engine event cannot ship unbound.
- **Response cache: errors can no longer be cached.** `store()` accepted
  any `ChatResponse`, so an empty completion or a provider error envelope
  could be persisted and then replayed on every later run (and on a worker
  retry) for the whole TTL — forever with the shipped `ttl_seconds = 0`.
  `ModelResponseCache::isCacheable()` now refuses empty content, error
  envelopes and `content_filter` completions; refusals are logged as
  `cache.store.refused`.
- **Response cache: key collisions between different models and options.**
  Provider and model identifiers went through the *text* normalizer, which
  deletes punctuation — `gpt-4.1` and `gpt41`, `claude-3.5-sonnet` and
  `claude35sonnet` shared one cache slot, and message bodies were
  concatenated without framing, so `[{user,"ab"},{user,"user"}]` and
  `[{user,"abuser"},{user,""}]` produced the same key. Identifiers are now
  hashed verbatim, messages are length-framed, and every option that is a
  provider parameter participates in the key (the old fixed whitelist
  silently ignored anything added later, such as `seed`, `stop`,
  `response_format` or `top_k`); transport-only keys (`timeout`,
  `stream`, `max_retries`, `request_id`, …) are excluded instead.
  `ModelResponseCache::VERSION` is bumped 2 → 3, which invalidates
  previously written entries — reclaim them with the new
  `php bin/cache-purge.php --yes` (see “Added”).
- **Response cache: expired entries are reclaimed.** TTL expiry used to
  only hide a stale entry; it stayed on disk / in the table and was
  re-read on every call. Expiry now drops the entry and logs
  `cache.entry.expired`.
- **A run whose final synthesis failed was recorded as `completed`.**
  `layer4Synthesis()` has always had a last resort: when no connector is
  usable, or the provider rejects the call, it returns a locally assembled
  `fallbackSynthesis()` body so the run still ends with *something*. The
  engine then called `SessionRepository::complete()` unconditionally, so
  History showed a green "completed" badge for a session whose L4 had
  failed (`L4 · Synthesis (failed) — bothub HTTP 404 … MODEL_NOT_FOUND`),
  and "Finish session" reused that fallback text as if it were a real
  answer. `PipelineEngine` now records *why* a fallback was returned
  (`$lastSynthesisFailure`, set only at the points that produce one), and
  `SessionRepository::complete()` accepts an optional `status` so the
  session is stored as `failed` with the fallback body kept for reference.
  `repairSession()` propagates the same signal, and
  `SessionCompletionService` answers a rebuild whose synthesis failed again
  with `502 synthesis_failed` — naming the model and the provider code —
  instead of `200`, leaving the session re-runnable.
- **A failed L4 made "Finish session" a no-op.** The repair plan treated
  `council_sessions.final_answer` as a usable synthesis whenever no
  *completed* L4 step existed, which is exactly what a failed L4 leaves
  behind: the plan reported `l4.required = false`, the button reused the
  fallback text, and the session was flipped to `completed` again. The
  planner now records any `failed` L4 step and refuses to reuse the stored
  body, scheduling the synthesis for a re-run (`plan.l4.retry`). The
  History button reads "Re-run synthesis (L4)" in that case and stays
  available after a failure.
- **A rejected model produced an error with no model in it.** The
  connector's 4xx message was `<provider> HTTP <code>: <body>` — a
  `MODEL_NOT_FOUND` body names the code but never the model, and L4 walks
  several candidates, so the operator could not tell which one the
  provider had refused. `ErrorDiagnostics::connectorHttpMessage()` now
  builds that sentence in one place
  (`bothub HTTP 404 [MODEL_NOT_FOUND] for model "gpt-5.6": {…}`), the
  provider's own `error.code` is extracted from the body, and both the
  serial connector path and `ParallelChatDispatcher` use it. The legacy
  `<provider> HTTP <code>` prefix — which `RequestAnalyzer::shortError()`
  and `ErrorDiagnostics::extractHttpStatus()` parse — is unchanged, and
  the L4 failure hint now says what a `MODEL_NOT_FOUND` means: the stored
  catalogue is stale, refresh the live list
  (`POST /api/models/actualize`) before re-running.
- **The worker indicator went red while the worker was beating every
  second.** `latest_heartbeat_at` was `MAX(revoap_jobs.heartbeat_at)` — the
  last *job*, not the last *worker* — while the per-process table was
  merged with the 1 Hz presence beacon. On an idle deployment the panel
  therefore showed both workers "1s ago" next to "Last heartbeat: 6h 06m
  ago" and classified the pool `stale` → the red "degraded" pill; pressing
  Test "fixed" it by enqueuing a job with a fresh heartbeat. Freshness and
  classification now use the presence beacon whenever a row exists, falling
  back to the job heartbeat only when there is none, and take the newest of
  the two signals (each legitimately goes quiet while the other keeps
  ticking: a long pipeline run blocks the poll loop, an idle worker has no
  job row). New snapshot keys: `latest_heartbeat_source`,
  `latest_presence_at`, `latest_presence_age_s`, `latest_job_heartbeat_at`,
  `latest_job_heartbeat_age_s`. Existing keys keep their names and meaning.
  The header indicator's tooltip now names the signal it is showing
  ("Last presence beacon" / "Last job heartbeat", falling back to the old
  "Last heartbeat" for a snapshot without the new key) instead of
  describing a beacon as a heartbeat.
- **`x:batch` was read as a different model, so the batch-only row could win
  a ranking it cannot serve.** Read as an opaque string, `gpt-5.6:batch` and
  `gpt-5.6` looked unrelated: the version and family machinery saw no
  kinship, so batch-only rows were offered as ordinary candidates and beat
  the very model they shadow at "newest release on this line" — and the L1
  fan-out then had nothing callable at all. `src/Models/ModelId.php` now owns
  the reading (`isBatchId`, `baseModelId`, `modelMode`) and
  `ProviderFamily::slug()` — the single funnel every line, version and family
  comparison goes through — strips the suffix, so `x` and `x:batch` resolve
  to one line, one version and one family. The candidate filters that rank or
  de-duplicate models (`planSelection`, `diversify`, `preferredHighlights`,
  `LinkedModels::plan`) no longer treat a parallel batch row as a distinct
  candidate, and both lists mark a `:batch` id visually so it is not
  mistaken for its synchronous twin. Reaching for one in the synchronous pool
  is now refused before the run starts, with the reason
  (`ModelId::syncPoolRejection()`), instead of ending in a provider 404.
- **The 64-row catalogue cap emptied the L1 fan-out.** The shipped ceiling
  was applied twice — once when the catalogue was persisted, once when the
  live list was merged — so of the 524 models Bothub actually serves only 64
  rows reached the catalogue. Actualization then assigned presets from the
  *live* list while the run resolved its pool from the *stored* catalogue:
  the named model was absent, the pool resolved to zero callable models, and
  the fan-out came back empty for a reason nothing on screen explained. The
  ceiling is now a setting applied once — `models.catalog.max_rows`, default
  `1000`, `0` meaning no ceiling — and what it does when it does bite is
  intelligent: `src/Models/CatalogTruncation.php` never discards a row the
  pool, a preset or a linked role refers to (`RULE_PROTECTED_OVER_CAP`) and
  ranks the remainder as "newest release in its family", interleaved across
  families so one prolific provider cannot fill the list. A row the provider
  no longer serves is marked rather than silently kept (`stale = true`,
  `active = false`, with the reason). The invariant is now enforced instead
  of hoped for: the pool, the presets and the linked roles must be active
  catalogue rows, and a repair pass names every entry it removed and why.
- **A run with no usable answer is `failed`, decided from the run's facts.**
  The synthesis-failure path was one way to end a run with nothing; three
  others still reached `completed`, and all of them were folded into a green
  badge the operator had to disbelieve. The terminal verdict is now resolved
  from the persisted steps and events — a `failed` L4, an `L1_NO_RESULTS` or
  `L1_NO_AVAILABLE_MODELS` event, a non-empty pool that resolved to zero
  callable models, an empty or fallback final answer — and never from the
  text of the answer or from a single in-memory flag. The fallback body is
  still stored, but it is *marked* as such, so neither the Final Answer card
  nor History can present an apology as the result; History shows the run as
  Error and offers a re-run, and `WorkerHealthService::lastJob()` reports
  `outcome = error` for it. Before any model is called, a preflight names the
  role and the model it is about to use — and substitutes a catalogue row,
  saying so, when the *configured* synthesis model is no longer served —
  instead of collecting a `MODEL_NOT_FOUND` at the end of a fully paid run.
- **The shell scrolled away under the reader.** The header and the left
  column were ordinary flow content: scrolling a long Chat or Help page took
  the tab strip and the model pool off screen, so the controls an operator
  reaches for mid-run were only reachable by scrolling back. `.oc-header` is
  now `position: sticky` at `top: 0` with a stated `min-height: 64px`, and
  `.oc-sidebar` sticks below it (`top: 64px`, `align-self: start`,
  `height: calc(100vh - 64px)`, `overflow-y: auto` — all three load-bearing:
  a stretched sticky box cannot travel, and without its own scroller the
  lower half of a pinned column is unreachable). The offset is written once
  and repeated with a comment wherever it has to line up, and
  `scroll-padding-top` / `scroll-margin-top` were updated to match, so
  anchored jumps land below the pinned header instead of under it. The
  narrow (stacked) layout is deliberately unchanged: there the sidebar is a
  full-width band that scrolls with the page. The stale comment claiming the
  header "scrolls away with the page" is gone.

### Added

- **`last_job` in `GET /api/health/worker`, and a "Last job" block in the
  Worker card.** Which models took part, how long the run took, and whether
  it succeeded or failed — previously spread across three panes. The
  snapshot gains `last_job` (`job_id`, `session_id`, `status`, `outcome`,
  `started_at`, `finished_at`, `duration_s`, `duration_state`, `models[]`,
  `error`, `provider_code`, `synthesis_failed`, `attempts`,
  `max_attempts`). The newest *pipeline* run is preferred over a self-test,
  so pressing Test does not erase the record of the last real query, and
  `outcome` is `error` for a job whose synthesis failed even though the
  queue row says `completed` (`PipelineJobRunner` now copies the engine's
  `status` / `synthesis_failure` into the job's result envelope). Models
  come from the job payload, then the session's `options_json`; when
  nothing is recorded the block says so instead of naming the configured
  default pool.
- **The History "Final answer" sits on its own green plaque and is never a
  window.** The answer shared `.oc-history-block` with the query, the
  runtime options and the step excerpts, `max-height: 360px; overflow:
  auto` included — so the one block an operator reads end to end was the
  one block with a scrollbar inside a scrollbar. `.oc-history-final-answer`
  resets the clamp and paints a soft green gradient (dark green in the dark
  theme, light green in the light) from two new tokens,
  `--color-final-answer-bg` / `--color-final-answer-border`, defined in both
  themes. Measured on the rendered gradient: primary text 13.12:1 dark /
  14.07:1 light, secondary text 5.04:1 / 4.75:1. The chat surface's own
  accent-blue Final Answer card is deliberately unchanged.

- **`bin/cache-purge.php`** — the supported invalidation path for the
  model-response cache: `--status` (dry-run summary), `--older-than-days=N`
  and `--yes` to delete from both backends. Requires the admin token and
  refuses to run from a web SAPI, like the other maintenance commands.
  The token may now also come from **`.admin.key` in the project root**,
  so a plain `php bin/cache-purge.php --status` works on a server where
  the key file already exists: the sources are consulted in the order
  `--token=` → `REVOAP_ADMIN_TOKEN` → key file, and only when none of
  them yields a token does the command fail (exit 3) — with a message
  that now lists all three remedies and the state of the key file. The
  success JSON reports which source was used (`token_source`).
- **Settings → Cache (v1.9.0).** A statistics card plus bulk
  invalidation, so neither needs a shell:
  - `GET /api/settings/cache` — enabled/disabled, cache format version,
    TTL, entries and bytes per backend (database payload bytes and
    `information_schema` footprint; hierarchical `cache/` tree) with their
    paths, oldest/newest entry, legacy flat-layout leftovers, the hit
    counter (recorded hits, entries with hits, entries never hit, last
    hit, hit rate) and the top provider/model rows. Read-only; a missing
    cache directory is reported as an empty state, not an error.
  - `POST /api/settings/cache/purge` — `{"confirm":"PURGE"}` plus an
    optional `{"older_than_days":N}` (`0` = everything, max 36500).
    Reports what each backend deleted and how many bytes were reclaimed.
    Refuses without the control word (`400 confirmation_required`) and
    validates the window before touching anything (`400 invalid_window`);
    the UI mirrors the **Database → ReCreate DB** guard (warning dialog,
    then the control word typed by hand).
  - The card auto-refreshes every 10 s **only while visible**
    (IntersectionObserver + hidden-tab gate, like the Worker card) and
    shows an amber hint when entries are stored but no hit has ever been
    recorded. The hit counter lives in the database backend only, so a
    filesystem-only deployment reports it as unavailable rather than as
    zero (the card explains why).
- **Settings section strip (v1.9.0).** A sticky, horizontally scrollable
  strip above the Settings sections (**Interface** … **Logout**) that
  scrolls to a section and highlights the one being read
  (`IntersectionObserver`, no scroll handler). It is built from the
  sections actually present in the DOM, so a section switched off by
  config is not advertised. Its height is published as
  `--oc-settings-nav-height`, used by the cards (`scroll-margin-top`) and
  the document scroller (`scroll-padding-top`) so a scrolled-to section —
  including the Worker card reached from the header indicator — lands
  below the strip instead of under it. Every settings card also gained an
  id anchor plus a dashed separator between sections.
- **`tests/CachePurgeCliTest.php`** — offline coverage for the three token
  sources of `bin/cache-purge.php` (runs the real CLI from a generated
  project root, so the working copy's `.admin.key` is never touched) and
  for `--older-than-days` deleting only stale entries.
- **`tests/SettingsCacheApiTest.php`** — offline coverage for the Cache
  API: the admin gate, statistics over a prepared cache directory,
  statistics and hit-rate over an in-memory `model_response_cache` table,
  purge validation (nothing deleted on refusal), and the empty/missing
  directory case.
- **`PipelineEngine` option `cache_mode` (`reuse` | `bypass`).** Job mode
  now states its cache policy explicitly (`reuse` — a retried job must not
  re-pay for L1 calls it already made, and a cached entry can only be the
  answer to the same provider+model+messages+options tuple). `bypass` opts
  a single run out of both the lookup and the store.

### Internal

- **CLI retention test env leakage.** The CLI authorization test now builds
  the child-process environment from an explicit allowlist, so the
  wrong-token path deterministically exits with code 3 instead of
  sporadically passing from a leaked parent token.
- **Retention CLI uses the repository for the oldest-timestamp probe.**
  `PdoMemoryRepository::oldest()` owns the `MIN(created_at)` query; the
  CLI no longer embeds raw SQL.
- **SmokeTest no longer writes the live `var/config/runtime.json`.** The
  `analyzer.enabled` checks called `RuntimeConfig::update()` after an
  earlier `Config::reset()`, so the overlay was pinned back at the project
  root and every run re-serialised the operator's runtime config — the
  content came out identical (the flag was flipped and flipped back), which
  is why only the mtime moved and nothing noticed. The block now runs
  against a throwaway root. Both roots have to be pinned, not just
  `RuntimeConfig`: `Config::load()`/`Config::reload()` call
  `RuntimeConfig::init(self::$rootDir)` internally, so pointing only the
  overlay elsewhere is undone by the reload on the next line. Verified over
  repeated runs: content hash and mtime both unchanged, and a full-file
  manifest of the working tree shows the suite touching nothing else
  outside `tests/`.
- **`tests/SettingsCacheApiTest.php` no longer appends to the live error
  log.** The purge API logs its refusals at warn/error level, and the
  shipped `config.php` pins `logging.path` to its own `logs/` directory — an
  absolute path that beats the throwaway root the test otherwise runs
  against, so every run of the suite appended to the repository's real
  `logs/revoap-error.log`. The fixture now redirects `logging.path` into its
  own temp root as well, so the log the purge path writes to is the one the
  test owns and removes. With the runtime-config fix above, the operator's
  `var/config/runtime.json` is no longer re-serialised by a test run
  (verified over repeated runs: content hash and mtime both unchanged).

## [1.8.43] - 2026-09-19

### Added

- **External REST API v1** (`/v1/*`) for programmatic access to the consensus
  pipeline. Full reference and examples: [`docs/api-rest.md`](docs/api-rest.md).
  - **API keys (`rv_*`).** 48 hex chars from 24 random bytes; only a SHA-256
    hash is stored (`api_keys.key_hash CHAR(64)`, migration
    `015_api_keys.sql`). Scopes `full`/`read` (least privilege), per-key
    `rpm`/`daily` quotas, optional expiry, revoke/rotate/touch-last-used.
  - **Authentication & rate limiting (`App\Api\ApiAuth`).** Key transport
    priority `X-API-Key` > `Authorization: Bearer` > `?api_key=`; deny-list
    keeps every operator surface (`/api/settings`, `/api/skills`,
    `/api/webhooks`, `/api/logs`, `/api/security`, `/api/history`,
    `/api/statistics`, `/api/profile`, `/api/share/session`) out of API-key
    reach (404). Per-key `rpm`/`daily` quotas plus an anonymous per-IP budget
    (120 req/min, fail-closed counters, SHA-256-digest state file names -
    the raw IP is never persisted). Failures audit `api.auth.denied` events
    with reason/path/ip/key-id only - never key material.
  - **Format adapter (`App\Api\FormatAdapter`).** Native / OpenAI /
    Anthropic request dialects map onto the internal job payload (last user
    message becomes the query, transport options survive in `request_meta`);
    run records and errors render back into the requested dialect, so stock
    OpenAI/Anthropic SDKs can drive RevoAp (`?format=openai|anthropic`).
  - **Launch endpoint (`POST /v1/runs`).** Three modes: durable async
    (default, 202 + events/result/cancel URLs), synchronous in-process run,
    and SSE streaming (`stream=true`, same event contract as
    `/api/council/stream`, `client_run_id` dedupe lock).
  - **Read endpoints.** `GET /v1/runs`, `GET /v1/runs/{id}`,
    `GET /v1/runs/{id}/events` (`?after_event_id=` cursor),
    `GET /v1/runs/{id}/result` (202 until terminal),
    `POST /v1/runs/{id}/cancel`, `GET /v1/models`, `GET /v1/usage?period=`.
  - **Webhooks.** `run.completed|failed|cancelled` lifecycle notifications
    signed with `X-RevoAp-HMAC-SHA256` (`t=<unix>;v1=<hex>`, HMAC over
    `"<t>.<raw-body>"`); idempotent delivery rows, exponential backoff
    (60s..1800s, max 6 attempts), retry pump in the worker.
  - **Settings → API tab.** Operator UI to generate / revoke / rotate keys,
    review quotas and last-use, with inline usage help.
  - **Security tests.** `tests/ApiSecurityTest.php` covers the per-IP gate
    (including fail-closed storage and no-IP-in-state-filenames),
    deny-list 404s and secret-free audit trails.
  - **Scenario tests (`tests/scenarios/ApiScenariosTest.php`).** Five
    end-to-end scenarios (53 assertions) walk the public `/v1/*` surface:
    native sync run, async 202 + events/result polling, OpenAI dialect
    round-trip, error states (401/403/404/429) and webhook delivery with
    HMAC verification and retry replay.

## [1.8.41] - 2026-08-26

### Added
- **Domain Models global provider toolbar.** Settings → Domain Models now
  shows a toolbar with a provider dropdown (providers that resolve an API
  key) and an **Apply** button. Apply reassigns every *open* preset
  (Default / Mathematics / Physics) to the chosen provider: each card's
  model rows get a farthest-first diverse pick of that provider's catalog
  (provider, model and label are rewritten, cycling when a card has more
  rows than picks), and the card's arbiter is pointed at the provider's
  first catalog entry. Nothing is saved automatically — the operator
  reviews the rewritten rows and presses "Save preset" per card.
- **Consensus default selection now picks maximally-different models.**
  Both the `/api/connectors` server backstop (`pickDiverseModels()` in
  `index.php`) and the client-side fallback (`pickDiverseModels()` in
  `assets/js/model-selector.js`) use a farthest-first diversity picker
  over model ids — Jaccard distance on lowercase `[a-z0-9]+` tokens after
  stripping a leading `~` and the vendor prefix before the last `/` —
  instead of blindly taking the first three catalog rows. A fresh pool is
  no longer filled with near-identical variants of the same model family.
- **Import models surfaces the full imported catalog.** The Models card
  "add recommended" dropdown now lists every slug returned by the last
  provider import — including non-versioned / alias models — after the
  curated `recommended_models`, deduplicated by `provider|model` with the
  curated note winning.

## [1.8.40] — 2026-08-26

### Fixed
- **Check DB no longer reports false drift.** `App\Settings\SchemaParser`
  dropped the `UNSIGNED` / `ZEROFILL` / `BINARY` type modifiers (it cut
  the type at the first space outside parentheses) and treated inline
  `PRIMARY KEY` / `AUTO_INCREMENT` columns as nullable. Every table
  therefore diffed as `type expected=int live=int unsigned;
  nullable expected=YES live=NO` against a correct database. The parser
  now keeps the modifiers and parenthesised argument lists
  (`INT UNSIGNED` → `int unsigned`, `DECIMAL(5,4)`,
  `ENUM('a','b')`, `DATETIME(3)`), and `nullable=false` follows MySQL
  semantics for inline `PRIMARY KEY` and `AUTO_INCREMENT`.
  `SchemaInspector::normaliseType()` additionally normalises comma
  spacing inside parenthesised groups (`enum('a', 'b')` ==
  `enum('a','b')`) without touching quoted literals.
- **ReCreate DB now produces a complete database.** `db/schema.sql`
  previously stopped at the v1.8.32 tables, so the job subsystem
  (`opencorum_jobs`, `opencorum_job_events` — introduced by the
  v1.8.35…v1.8.40 worker migrations) was never created by ReCreate DB,
  while `SchemaManager` baselined every `db/migrations/*.sql` as
  applied — making "Apply Migrations" a silent no-op. The consolidated
  schema now contains all 15 tables.

### Added
- **`db/schema.sql` is now the single source of truth** (all former
  migrations `001_initial.sql` … `013_job_audit_events.sql` were
  consolidated into it; `@schema-version` bumped to 1.8.40). New
  tables: `opencorum_jobs` (1.1.0, includes the auto-recovery columns)
  and `opencorum_job_events` (1.0.0).
- **`db/migrations/` is now a stub** for future incremental changes,
  with a `README.md` describing the policy: edit `db/schema.sql` +
  bump `@table-version`; ship an idempotent `NNN_*.sql` only when
  existing databases must converge. `MigrationManager` handles the
  empty directory gracefully (`list()` → `[]`, `baseline()` → `[]`,
  `applyPending()` → nothing, `applyOneByName()` →
  `invalid_filename`).

## [1.8.34] — 2026-05-13

### Fixed
- **Urgent: Domain auto-routing (Mathematics / Physics) no longer
  empties the L1 model pool.** When the v1.8.32 analyzer/heuristic
  routed a math or physics query to the `mathematics` /  `physics`
  preset, the engine swapped the user-selected pool for the preset's
  pool and ran it through `resolveSelectedModels()`. That resolver
  required every entry to be present in the global active model
  catalogue (`config/models/openrouter.php`). The shipped catalogue
  lists ten flagship slugs that do not overlap with the reasoning-
  heavy preset picks (`openai/o3`, `deepseek/deepseek-r1-0528`,
  `google/gemini-2.5-pro`, `anthropic/claude-opus-4.5`,
  `x-ai/grok-3-mini`), so every candidate was rejected with
  `not_in_catalog` and `L1_NO_AVAILABLE_MODELS` fired. Auto-routing
  effectively bricked math/physics runs even though OpenRouter was
  fully configured. Symptom seen in production: `L0_OPTIONS`
  `selected_models: []`, immediate `L1_NO_AVAILABLE_MODELS`,
  followed by an empty L3 and a degenerate L4.
  * Preset selections now resolve through a
    `provider-authoritative` path: a model is usable when the
    provider connector is registered, regardless of whether the
    model id appears in the global active catalogue. The catalogue
    is still consulted for request-supplied selections so a
    malformed UI submission can't introduce unknown models.
  * Selection now follows an explicit fallback chain — `domain
    preset → original request models → Default preset →
    pipeline.default_selected_models → active catalog`. Each step
    is recorded in the new `selection_diagnostic` so the UI can
    explain which fallback ran.
  * `AUTO_ROUTING_NOTICE` carries `applied_models`,
    `rejected_models`, `fallback_used`, `fallback_source` and
    `fallback_chain`. `L0_OPTIONS` carries the same
    `selection_diagnostic`. `L1_NO_AVAILABLE_MODELS`, when it does
    fire, now reports the requested domain, preset slug,
    candidate count, rejected entries with reasons, the fallback
    chain attempted, and the list of available providers — so the
    operator can immediately tell whether the gap is a missing
    connector key, a stale preset, or a typo in the model id.

### Added
- **`SELECTED_MODELS_RESOLVED` trace event carries `used_fallback`
  and refined `source`** (`request`, `domain_preset`,
  `request_fallback`, `default_preset_fallback`, `config_default`,
  `active_catalog`) so the Raw tab can attribute every L0 selection
  to the path it actually took.
- **Smoke tests** covering: math/physics queries resolving with a
  non-empty L0 selection even when the preset models are absent
  from the global catalog; preset → original-request fallback when
  the preset yields zero usable entries; preset → Default-preset
  fallback when neither preset nor request work; the enriched
  `L1_NO_AVAILABLE_MODELS` payload shape; and the new
  `selection_diagnostic` block on `L0_OPTIONS`.

## [1.8.33] — 2026-05-13

### Added
- **Shared page — Council statistics card.** A new
  professionally-styled statistics card sits between the Final
  Answer and the Consensus history. Surfaces only persisted data:
  number of participating models, duration, total / prompt /
  completion tokens, estimated cost (when persisted), consensus
  status + score, cache hits (when persisted), per-response counts,
  average model latency, and call-outs for the top / weakest /
  fastest / slowest contributors. Missing values render as a graceful
  dash — no inventing numbers and no API keys leak into the snapshot.
- **Settings → Interface.** New section in the Settings UI exposing
  two operator preferences:
  * **Layout width** — `narrow` (720 px) / `standard` (960 px,
    default) / `wide` (1400 px). Applied to both the main app and
    the public Shared page.
  * **Default theme** — `follow system` (default, preserves the
    pre-1.8.33 behaviour) / `light` / `dark`. First-time visitors
    land on the chosen theme; the toggle button still lets each
    viewer flip the theme locally and the choice persists per-browser.
  Persisted in `app_config` under `ui.width` and `ui.theme`. New
  endpoint `POST /api/settings/interface`.
- **Smoke tests for the shared redesign and interface settings.**
  Cover the single-column shared layout, ordering Final-Answer →
  Stats → History, KaTeX target inclusion of the new consensus
  history selectors, the persisted-only `buildStats()` aggregator,
  ConfigRepository UI-pref normalisation, route + handler wiring,
  and template integration.

### Changed
- **Shared page layout is now a single wide column.** The previous
  two-column arrangement (Final Answer next to Consensus history) is
  gone. Sections appear in this order: Query → Final Answer →
  Council statistics → Consensus history. Final Answer reuses the
  highlighted `.oc-final-answer` treatment from the main app and the
  whole shared page now resolves through the same `--color-*` theme
  tokens — no more hard-coded `#0d1117`, no black-on-black in light
  mode. The shared page also gains a manual theme toggle in the
  header.
- **KaTeX renders math in the Shared consensus history.** The
  auto-render target list now includes `.oc-shared-step-body`,
  `.oc-shared-step-response` and `.oc-shared-history`, so per-role
  L1 responses on the public read-only page render formulas
  identically to the live chat. The `data-katex-rendered` guard
  still prevents double passes on re-renders.
- **Per-step responses included in the shared snapshot.** Each
  consensus step now lists every model's persisted response (model,
  provider, role, tokens, latency, raw text). All values pass
  through `Logger::sanitize` before serialization so secrets cannot
  leak.

### Fixed
- The shared page no longer fails to render math inside per-role L1
  answers — the previous build only ran KaTeX over the Final Answer.

## [1.8.32] — 2026-05-13

### Added
- **Settings → Profile.** Multi-user-ready identity surface (email,
  phone, first / last name, job title, about/bio, system prompt
  addendum). Backed by new `users` + `user_profiles` tables
  (migration 009). Single-admin deployments use the deterministic
  `external_id = 'local'` row; future authentication layers attach
  additional rows without DDL. The profile addendum is appended
  to every council prompt by the new `SystemPromptAssembler`.
  Endpoints `GET/POST /api/profile` reuse the Settings admin gate.
  When migration 009 is missing the controller falls back to an
  in-memory store so the UI stays operational.
- **Base system prompt extracted into `prompts/system/default.md`.**
  Final prompt assembly is now `base file → profile addendum →
  user-identity context → role/skill prompt → user query`.
  `PipelineEngine::composeSystemPrompt()` routes every L1, L1-round-2,
  synthesis, main-selected and arbiter call through the assembler.
  The base file ships the user-mandated directive:
  `**IMPORTANT!** Do not use pre-prompts or system prompts if they
  reduce answer quality and size: Always be fully professional and
  take full responsibility for your results.`
- **Settings → Domain Models.** Per-domain model preset surface
  (`default`, `mathematics`, `physics`, future). Each preset binds a
  provider/model pool (with `active`/`selected` flags) and a
  designated arbiter / main / checker model. Backed by the new
  `domain_presets` table (migration 010) with a generic
  `definition_json` column so future domains require no DDL.
  Endpoints: `GET /api/domain-presets`, `GET
  /api/domain-presets/{slug}`, `POST /api/domain-presets`,
  `POST /api/domain-presets/{slug}/select`. The repository seeds
  the canonical presets at bootstrap when the store is empty.
  Mathematics & Physics default to `openai/o3 +
  deepseek/deepseek-r1-0528 + google/gemini-2.5-pro +
  anthropic/claude-opus-4.5 + x-ai/grok-3-mini` with
  `openrouter/openai/gpt-5.5` as arbiter / main.
- **Math + Physics skills informed by reasoning research.** New
  `mathematics-reasoning` and `physics-reasoning` skill JSONs.
  Math roles cover problem elaboration, symbolic derivation,
  computational cross-check, edge-case audit, self-consistency.
  Physics roles cover physical model architect, formula-set
  specialist, symbolic derivation, limiting-case + unit auditor,
  self-consistency. Every role prompt instructs models to emit
  formulas with KaTeX-compatible delimiters `\(...\)` inline and
  `\[...\]` display so the v1.8.29 KaTeX integration renders them.
  Descriptions cite: CoT (arXiv:2201.11903), Self-Consistency
  (arXiv:2203.11171), Problem Elaboration (arXiv:2402.15764),
  MathPrompter (arXiv:2303.05398), LPML (arXiv:2309.13078),
  SciBench (arXiv:2307.10635), ChatGPT-in-Science
  (arXiv:2310.08773), Physics Reasoner (arXiv:2412.13791),
  UGPhysics (arXiv:2502.00334).
- **Auto domain detection & routing.** `DomainRouter` classifies
  each query into `default` / `mathematics` / `physics` using LaTeX
  delimiters, Greek letters, math symbols, physics units, English
  + Russian keyword sets and numerical density. When a math /
  physics domain is detected (and the request does not set
  `options.lock_models = true`) the pipeline swaps the selected
  pool to the matching preset, forces the matching skill, and
  emits a new SSE event `AUTO_ROUTING_NOTICE` carrying the
  classification, the swapped models and an anchor to
  `#oc-domain-models`. The chat UI renders an inline banner above
  the pipeline with a "Configure these models in Settings →" link.
- **Optional Request Analyzer.** New `RequestAnalyzer` calls a
  small frontier model (default `openrouter / openai/gpt-5.5`)
  before the pipeline kicks off, returning a structured envelope
  (domain, persona, suggested skill, suggested preset,
  formula_expected, confidence, reason). The persona is dynamically
  generated per query (e.g. *"Senior software engineer with 20+
  years of experience…"*) so council roles inherit a calibrated
  expert framing. Settings → Request Analyzer exposes the toggle,
  provider, model and a "Classify" probe. New routes:
  `GET / POST /api/analyzer/settings`, `POST /api/analyzer/classify`.
  The analyzer never logs the raw query body — only metadata.
  When disabled or unreachable the pipeline falls back to the
  heuristic `DomainRouter`.

### Database
- New migration **`db/migrations/009_users_and_profiles.sql`**
  (`@table-version users 1.0.0` + `@table-version user_profiles
  1.0.0`) plus the deterministic local seed row.
- New migration **`db/migrations/010_domain_presets.sql`**
  (`@table-version domain_presets 1.0.0`).
- **`db/schema.sql`** advanced to `@schema-version 1.8.32` and now
  ships the three new tables so a fresh `mysql < db/schema.sql`
  produces the same shape as the incremental migration path.

### Preserved
- Every existing feature continues to behave as in v1.8.31:
  cooperative pause/reject, KaTeX rendering, CSP (including the
  Perplexity `font-src` exception), share by token, history,
  statistics, semantic consensus, schema-version banner, per-file
  Apply migration, conflict arbiter,
  `markStaleRunningStepsFailed` export, and module-evaluation
  hardening.

## [1.8.31] — 2026-05-13

### Fixed
- **CSP font-src — Perplexity FKGroteskNeue webfont.** After deploying
  v1.8.30, browsers reported:
  `Refused to load the font 'https://frontend-cdn.perplexity.ai/_agi_assets/fonts/FKGroteskNeue.woff2'`
  `violates Content Security Policy directive: "font-src 'self' data: https://cdn.jsdelivr.net".`
  `Http\Response::html()` now extends `font-src` to include
  `https://frontend-cdn.perplexity.ai` (the FKGroteskNeue webfont
  origin referenced by some rendered LLM answers). All other directives
  — `script-src`, `style-src`, `img-src`, `connect-src`, `base-uri`,
  `frame-ancestors` — are unchanged; the KaTeX jsDelivr allowance
  added in v1.8.29 is preserved. `connect-src` stays pinned to
  `'self'` so the front-end cannot exfiltrate or fetch arbitrary
  endpoints; only opaque font bytes are loadable from the new origin.
- **`Uncaught SyntaxError: The requested module './pipeline.js' does not
  provide an export named 'markStaleRunningStepsFailed'`.** The export
  was present in `assets/js/pipeline.js` (line 801, since v1.8.22), but
  a top-level `const observed = container(); …` block at the END of the
  module ran at import time and threw on edge-case DOM states (the
  pipeline section element missing during very early app boot, or a
  test/SSR environment without `document`). When the throw happened
  during module evaluation, browsers reported the trailing function
  exports as undefined — even though the source code clearly declared
  them — and `app.js` then failed to import `markStaleRunningStepsFailed`
  at line 26. The fix wraps the top-level DOM access in
  `bindPipelineRegistryObserver()`, defers it to `DOMContentLoaded` when
  the document is still parsing, and swallows DOM-absent errors with a
  try/catch so module evaluation can never abort before the
  `export function` bindings register. Behavior is preserved: stale
  "Awaiting model response…" / running step cards are still flipped
  to `failed` via `markStaleRunningStepsFailed()` when an SSE stream
  closes mid-pipeline, when `PIPELINE_FATAL` arrives, and when a new
  run starts (`clearPipeline()` runs at the head of `submitQuery()`).

### Added
- **Smoke tests — CSP & JS module export/import consistency.**
  `tests/SmokeTest.php` now asserts that
  `font-src` carries both `cdn.jsdelivr.net` (KaTeX) and
  `frontend-cdn.perplexity.ai` (FKGroteskNeue), that `script-src`
  and `connect-src` do NOT silently widen, and that **every named
  import in `assets/js/app.js` from `./pipeline.js` is exported by
  `pipeline.js`** (parsed via regex over both files). The specific
  v1.8.22 contract (`markStaleRunningStepsFailed`) is pinned, and the
  v1.8.31 hardening — `bindPipelineRegistryObserver` + try/catch
  around the MutationObserver setup — is asserted, so this class of
  regression cannot ship green again.

### Preserved
- v1.8.30 cross-DB portability for HistoryService + StatisticsService
- v1.8.29 SQLSTATE 2014 "unbuffered query" fix + KaTeX (jsDelivr, SRI)
- v1.8.28 `council_steps.step_key` widen + `StepKeyHelper`
- v1.8.27 per-file Apply + `app_config` table versioning
- v1.8.26 Settings DB maintenance unbuffered-query 2014 fix
- v1.8.x semantic consensus, cycle controls, share, cache normalization
  and structured frontend console logging.

## [1.8.30] — 2026-05-13

### Fixed
- **HistoryService / StatisticsService — cross-database portability.**
  Smoke tests (which run against an in-memory SQLite) and any future
  non-MySQL deployment now pass the full History + Statistics suite.
  Root causes:
  - `HistoryService::isMigrated()` issued a MySQL-only
    `SHOW COLUMNS FROM council_sessions LIKE 'options_json'`. Replaced
    with a driver-aware probe that uses `PRAGMA table_info()` on
    SQLite, `SHOW COLUMNS` on MySQL, and a generic zero-row
    `SELECT options_json FROM council_sessions WHERE 1=0` fallback
    for every other dialect.
  - `HistoryService::list()` grouped by year/month with
    `DATE_FORMAT(created_at, '%Y-%m')` which is MySQL-only. Replaced
    with `SUBSTR(created_at, 1, 7)` on every dialect that stores
    timestamps in canonical ISO/MySQL form (kept the original
    `DATE_FORMAT` on MySQL where it is faster on indexed columns).
  - `StatisticsService::fetchSessions()` selected the
    `completed_at` column from `council_sessions`. The aggregator
    never reads it, but the SELECT failed against minimal smoke-test
    schemas that omit the column. Dropped from the projection.
- **Restores all 22 pre-existing smoke failures** in
  `HistoryService::*`, `HistoryController::*`, `StatisticsService::*`
  and `StatisticsController::*` to green.

### Preserved
- v1.8.29 SQLSTATE 2014 bulletproofing + KaTeX math rendering.
- v1.8.28 `step_key` widening + `StepKeyHelper` defensive
  normalisation.
- v1.8.27 per-file Apply + `app_config` table versioning.
- v1.8.26 `SqlExecutor` unbuffered-query fix.
- v1.8.25 Check DB / Apply Migrations / ReCreate DB.
- All existing features — semantic consensus, share links, cycle
  controls, cache, console logging, public History/Statistics tabs.

## [1.8.29] — 2026-05-13

### Fixed
- **Settings → Apply Migration still raised
  `SQLSTATE[HY000] 2014 — Cannot execute queries while other unbuffered
  queries are active` on some shared-hosting MySQL builds.** v1.8.26
  centralised cursor finalisation through `SqlExecutor`, but two
  bookkeeping writers (`ConfigRepository::set()` and
  `MigrationManager::recordApplied()`) still called `$pdo->prepare()`
  + `execute()` directly. On hosts where `MYSQL_ATTR_USE_BUFFERED_QUERY`
  is silently dropped from the option array (some PDO builds, custom
  `.sql.php` overlays that pre-date v1.8.26), those direct writers
  left a live statement on the handle and the next migration step —
  or the immediately-following `SHOW TABLES` inside `SchemaInspector`
  — tripped 2014. Apply Migration, Per-file Apply, Check DB, and
  ReCreate DB are all hardened.

### Added
- **`SqlExecutor::runWrite()`** — new helper that prepares, executes
  and `closeCursor()`s parameterised writes (`INSERT` / `UPDATE` /
  `DELETE` / parameterised DDL) in a `try/finally`. Every
  Settings-area writer now routes through it so the cursor
  discipline matches the read paths added in v1.8.26.
- **Runtime buffered-query enforcement.** `SqlExecutor::__construct`
  and `Application::database()` both call
  `setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true)` on the
  live PDO handle (best-effort; swallowed on SQLite). This is
  belt-and-braces — even if the option array dropped the attribute
  at connect time, the live connection is forced to buffered mode
  before any migration / schema / maintenance SQL runs.
- **KaTeX math rendering for LLM answers.** Final answers, per-role
  L1 step responses, the shared (public) view and any history detail
  containers are scanned for math delimiters (`$$…$$`, `$…$`,
  `\(…\)`, `\[…\]`, `\begin{…}…\end{…}`) and rendered via KaTeX's
  `renderMathInElement` contrib. Untrusted output is never passed to
  `innerHTML`; KaTeX operates on text nodes and builds sanitised DOM
  in place. A MutationObserver picks up SSE-appended steps and
  re-rendered final answers automatically. If math is not detected,
  the text is left untouched at zero cost.
  - `assets/templates/layout.html` and `assets/templates/shared.html`
    load KaTeX 0.16.45 from `cdn.jsdelivr.net` with integrity-pinned
    `<script>` / `<link>` tags (user-provided SRI hashes).
  - `assets/js/katex-render.js` is the new ES-module renderer.
  - `Http\Response::html()` extends the Content-Security-Policy
    `script-src` / `style-src` / `font-src` directives to include
    `https://cdn.jsdelivr.net` so KaTeX glyphs and JS load without
    weakening any other directive.

### Preserved
- v1.8.28 `step_key` widening + `StepKeyHelper` defensive
  normalisation.
- v1.8.27 per-file Apply + `app_config` table versioning.
- v1.8.26 `SqlExecutor` unbuffered-query fix (this release builds on
  it).
- All existing features — semantic consensus, share links, cycle
  controls, model response cache, console logging, History /
  Statistics / Security tabs.

## [1.8.28] — 2026-05-13

### Fixed
- **PIPELINE_FATAL after L2 caused by `SQLSTATE[22001]: Data too long
  for column 'step_key' at row 1`.** The v1.8.27 schema kept
  `council_steps.step_key` at `VARCHAR(64)`, which was sized for the
  simple `L1.role.<key>` keys that pre-1.8 pipelines emitted. The
  current pipeline composes keys with a round prefix and a
  `role@model_tag` suffix; with realistic model identifiers (e.g.
  `solution-auditor@deepseek_deepseek_v4_pro`,
  `energy-method-specialist@google_gemini_3_1_pro_preview`) the
  composed key (`L2.round2.normalize.<role>@<model>`) easily exceeds
  64 chars and aborted the run with a fatal SQL error after the L2
  stage.

### Added
- **`db/migrations/008_step_key_widen.sql`** widens
  `council_steps.step_key` to `VARCHAR(191)` so every composed key
  fits inside the InnoDB single-column index limit (191 × 4 = 764
  bytes < 767-byte ceiling on legacy MySQL 5.7 utf8mb4 deployments).
  Annotated `-- @table-version council_steps 1.8.28` so
  `MigrationManager` upserts `app_config.table_version.council_steps`
  on apply, and `db/schema.sql` carries the matching annotation so a
  fresh `ReCreate DB` seeds the new version.
- **`App\Helpers\StepKeyHelper`** — deterministic safe normalisation
  of pipeline step keys. Short keys pass through unchanged; keys
  wider than the column collapse to `<prefix>-<sha256:12>` so loop
  detection and step lookup remain stable while the persisted value
  is guaranteed to fit. Applied at every step-key construction site
  in `PipelineEngine` (L1, L2, L3, L4 main-selected) and in
  `StepRepository::createStep()` as a final defensive guard so no
  raw oversized key ever reaches the database.

### Preserved
- The v1.8.27 per-file Apply / app_config table versioning
  mechanism. Migration 008 plugs into the same parser/repository
  pipeline so the Settings UI surfaces the new version automatically.
- The v1.8.26 `SqlExecutor` unbuffered-query (2014) fix. No changes
  to query lifecycle.

## [1.8.27] — 2026-05-13

### Added
- **Per-file `Apply` buttons in Settings → Migrations.** Each row in
  the migrations table now exposes a dedicated button so the operator
  can apply (or re-sync) one file at a time without running every
  pending migration. Backed by a new admin endpoint:
  `POST /api/settings/migrations/apply-one` with body
  `{"name": "<file>.sql"}`. The filename is whitelisted against the
  directory listing inside `MigrationManager::resolveMigrationFile()`,
  so no path traversal is possible and arbitrary `.sql` reads outside
  `db/migrations/` are rejected. The endpoint always returns HTTP 200
  with a structured body (`status` ∈ `applied` / `already_applied` /
  `skipped_benign` / `failed` / `invalid_filename`) so the per-row
  UI button can render success / skipped / error without a 500-page
  intermission.
- **`app_config` system table** (`db/migrations/007_app_config.sql`,
  also inlined into `db/schema.sql`). A generic key/value store
  (`config_key`, `config_value`, `scope`, `updated_at`) used today by
  the schema-versioning subsystem to record the live version of every
  RevoAp table. The table is intentionally extensible — future
  subsystems can store feature flags or runtime tunables without
  another DDL migration. The seed list ships every current table's
  version under `table_version.<name>` keys and the global
  `schema.version = 1.8.27`. Note on naming: the table is
  `app_config` (lowercase, snake_case) because every other table in
  the schema follows that convention; the user-facing "Config" name
  is preserved in the Settings UI and docs, and every SQL reference
  uses backticks so a future rename is a single-edit change.
- **`@schema-version` / `@table-version` comment convention** in
  `db/schema.sql` and every `db/migrations/*.sql`:
  ```sql
  -- @schema-version 1.8.27
  -- @table-version skills 1.0.0
  -- @table-version council_steps 1.0.5
  ```
  Parsed by the new `App\Settings\VersionParser`. Both the file-level
  schema version and the per-table versions are returned;
  `VersionParser::compare()` handles semver-style ordering with
  numeric components.
- **`App\Settings\ConfigRepository`** — read/write helpers for
  `app_config`. Supplies `ensureTable()`, `tableExists()`, `get()`,
  `set()`, `tableVersions()`, `seedTableVersions()` and
  `setSchemaVersion()`. Read paths swallow failures so a missing
  table never breaks UI code that only asks "what versions do we
  have?"; write paths fail loudly so callers can record a structured
  error in the migration report. Every PDO call routes through
  `SqlExecutor` so cursors close between statements (the v1.8.26 fix
  applies here too).
- **Schema-version drift detection in `App\Settings\SchemaInspector`.**
  `Check DB` now also compares the live `app_config` entries against
  the `@table-version` annotations parsed from `db/schema.sql` and
  emits a `version_mismatches` array carrying
  `{table, current_version, expected_version, reason}` for each
  divergence. `schema_ready` is false (and `migration_needed` true)
  whenever any mismatch is present, even if the tables/columns
  themselves are structurally correct.
- **`SchemaManager::recreate()` seeds `app_config`** with the table
  versions parsed from `db/schema.sql` immediately after the rebuild,
  so a freshly recreated DB converges on a green Check DB without a
  separate manual step.
- **Public version-status endpoint**: `GET /api/settings/version-status`.
  Returns no admin secrets and never raises a hard error; used by the
  new top banner to decide whether to render. Reports `tab_enabled`,
  `migration_needed`, `database_ready`, `admin_unlocked`,
  `config_table_present`, `expected_schema_version`,
  `live_schema_version` and a `mismatches` array.
- **Top "migration needed" banner**
  (`assets/js/migration-banner.js` + `assets/css/components/migration-banner.css`).
  Renders above the page header on every load when the live database's
  schema versions do not match `db/schema.sql`. Clicking the banner
  switches to the Settings tab; if the admin is not yet unlocked,
  focuses the unlock input automatically — otherwise scrolls the
  Database maintenance card into view. Dismiss button stores its
  state in `sessionStorage` so the banner stays out of the way for
  the rest of the browser session, then reappears next visit if the
  problem persists.
- **Per-row table-version preview in the Migrations table.** The
  Settings UI now lists every `@table-version` annotation a file
  declares so the operator can see which tables a migration moves
  forward before clicking Apply.

### Changed
- **`MigrationManager::list()`** payload extended with `tables`
  (map of `<table> => <version>` parsed via `VersionParser`) and
  `schema_version` per file. The legacy `name` / `size` / `applied`
  fields are unchanged.
- **`MigrationManager::applyPending()`** writes the parsed
  `@table-version` annotations into `app_config` after every
  successful (or benign-skipped) file and returns a new
  `version_updates` map keyed by filename. Existing keys
  (`applied`, `skipped`, `failed`) keep their shape.
- **Settings UI Migrations table** grew two columns (`Table versions`
  and `Actions`) so the new per-row Apply button has a home.
- **Database maintenance report** now surfaces a `Schema versions`
  section listing every drifting table inline, including a banner
  prompting the operator to apply `007_app_config.sql` when the
  `app_config` table is missing entirely.
- **`docs/Settings.md`** and **`docs/Storage.md`** extended with a
  Mermaid flowchart of the schema-versioning subsystem and a checklist
  for "when you change a table".
- **`SKILL.md`** updated so future contributors know to bump
  `@table-version` and ship the matching migration whenever they
  alter a table.

### Security
- **No path traversal in apply-one.** Filenames are validated against
  `db/migrations/*.sql` listing AND a `[A-Za-z0-9._-]+\.sql$` regex;
  `/`, `\`, `..` and empty strings are rejected up front. The
  endpoint returns `invalid_filename` rather than touching the
  filesystem.
- All new admin endpoints (`apply-one`) require the admin token. The
  public `version-status` endpoint exposes only versions (already
  advertised in `db/schema.sql` which is in-repo) and never leaks
  secrets.

### Preserved (no regressions)
- v1.8.26 `SqlExecutor` / unbuffered-query 2014 fix — every new
  call path routes through the same executor.
- v1.8.25 Check DB / Apply Migrations / ReCreate DB buttons.
- v1.8.24 Share self-healing when migration 006 not applied.
- v1.8.23 main-selected L4 synthesis + SSE-drop recovery.
- v1.8.22 `Unexpected token <` chat-error fix + LogToConsole.

## [1.8.26] — 2026-05-13

### Fixed
- **Settings → Database maintenance no longer fails with
  `SQLSTATE[HY000]: General error: 2014 Cannot execute queries while
  other unbuffered queries are active`.** The 500 affected
  `POST /api/settings/migrations/apply` and
  `POST /api/settings/database/recreate`, leaving the UI permanently
  stuck on "Schema drift detected". Root cause: several call sites in
  `MigrationManager`, `SchemaManager` and `SchemaInspector` issued
  `$pdo->query()` and partially iterated the result (typical pattern
  `while (($row = $stmt->fetch()) !== false) { … }`), then immediately
  ran the next DDL/INSERT on the same handle. With
  `PDO::ATTR_EMULATE_PREPARES => false` MySQL refuses subsequent
  statements until the prior cursor is finalised. The fix introduces
  a single audited execution surface and routes every schema /
  migration code path through it.

### Added
- **`App\Settings\SqlExecutor`** — small final helper that wraps
  `PDO::query()` / `PDO::prepare()` so a result set is always fully
  consumed and the cursor closed in a `try/finally` block before the
  next statement runs. Exposes `fetchAll()`, `fetchColumnAll()`,
  `fetchScalar()`, `runRead()`, `exec()` and `execScript()`
  (statement-by-statement DDL replay with a progress callback so
  callers can report the failing statement index back to the UI).
- **Structured error payloads** for the maintenance endpoints. Both
  `applyMigrations` and `recreateDatabase` now return JSON carrying
  `phase`, `file`, `statement_index`, `sqlstate`, `driver_code` and a
  sanitized `message` on failure — never a raw HTML 500. The
  frontend renders e.g.
  `Failed [create] schema.sql stmt#7 [HY000]: <sanitized message>`
  so the operator sees exactly which statement broke instead of a
  generic "Failed" banner.
- **`PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true`** added to the
  shipped `config.php` database options and to the defaults injected
  by `Config::normalizeDatabaseDescriptor()` and
  `DatabaseDsn::test()`. Gated by `defined()` so non-MySQL builds
  are unaffected, and operator-supplied options stay authoritative
  (only injected when the key is absent). A subtle PHP pitfall avoided
  along the way: the array-spread operator renumbers integer keys, so
  the option is appended via an explicit closure rather than
  `[...$opts, PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true]`.

### Changed
- **`MigrationManager`**: `appliedSet()` now goes through
  `SqlExecutor::fetchColumnAll()`, `applyOne()` through
  `execScript()`, and `recordApplied()` closes the prepared
  statement in a `finally` block. `exportSchema()` materialises the
  `SHOW TABLES` list before issuing any `SHOW CREATE TABLE` — that
  was the second-most-likely 2014 trigger. The statement splitter
  also now handles doubled single-quote escapes (`'it''s'`),
  doubled double quotes, backtick-quoted identifiers containing
  semicolons, and backslash-escaped quotes inside string literals.
- **`SchemaManager`**: `recreate()` routes every read (live table
  list) and every write (DROP / SET FOREIGN_KEY_CHECKS / schema.sql
  replay) through `SqlExecutor`, so cursors are closed between
  statements. The error report carries a structured
  `{phase, sqlstate, driver_code, message, file?, table?,
  statement_index?}` per failure.
- **`SchemaInspector`**: `SELECT DATABASE()` and
  `INFORMATION_SCHEMA.COLUMNS` reads use `SqlExecutor` so the
  inspector can run multiple per-table reflections in a single
  request without tripping HY000/2014 on the next table.
- **Frontend** (`assets/js/settings.js`): the Apply / ReCreate status
  text now surfaces `name`, `statement`, `sqlstate` and the sanitized
  message from the new structured payloads. v1.8.25 buttons and
  v1.8.24 Share self-healing behaviour are preserved verbatim.

### Tests
- New SmokeTest cases for `SqlExecutor` (reflection-level for hosts
  without `pdo_sqlite`; live in-memory SQLite execution where
  available — exercises `fetchAll → exec` ordering that previously
  triggered HY000/2014, plus `execScript` progress callbacks and
  comment-only payload skipping).
- New splitter cases covering doubled-quote escapes, backtick
  identifiers with semicolons, block-comment semicolons, and
  backslash-escaped quotes.
- New assertion that `database.options` carries
  `PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true` after `Config::load`
  on MySQL-capable builds.

## [1.8.25] — 2026-05-13

### Added
- **Settings → Database maintenance now exposes three admin-only buttons
  for inspecting and rebuilding the schema:**
  1. **Check DB** — read-only structural comparison of the live database
     against `db/schema.sql`. Reports missing tables, extra tables not
     defined in the schema file, per-table column diffs
     (missing/incompatible/extra columns, with normalised MySQL type
     comparison so `int(10)` vs `int unsigned` does not show up as
     drift), driver, database name, migration tracker status, and
     sanitized errors. Backed by `App\Settings\SchemaParser` (subset
     CREATE TABLE parser) and `App\Settings\SchemaInspector`
     (INFORMATION_SCHEMA on MySQL, `PRAGMA table_info` on SQLite for
     tests). No DDL is executed.
  2. **Apply Migrations** — wired to the existing
     `App\Settings\MigrationManager::applyPending()`. Idempotent: every
     applied file is recorded in `schema_migrations`, and "already
     exists" / "duplicate column" PDO errors are treated as benign so
     re-runs never hard-fail.
  3. **ReCreate DB** — destructive admin-only rebuild. Requires:
       - browser-side `window.confirm()` listing every table that will
         be dropped (history, statistics, cached model responses, share
         tokens, schema migrations),
       - a typed-confirmation prompt — the operator must type
         `RECREATE` verbatim,
       - request body `{"confirm":"RECREATE"}` (anything else returns
         HTTP 400 `confirmation_required`).
     Drops only tables defined in `db/schema.sql` plus the
     `schema_migrations` tracker by default; tables present in the live
     DB that are NOT defined in `db/schema.sql` are preserved unless the
     caller opts in to `drop_extra=true`. Disables `FOREIGN_KEY_CHECKS`
     for the drop phase, replays `db/schema.sql` statement-by-statement,
     then baselines every `db/migrations/*.sql` file so the tracker
     lines up with the freshly built schema. Returns a structured
     `report` with `dropped_tables`, `kept_tables`, `created_tables`,
     `migrations`, sanitized `errors`, and a follow-up Check DB pass so
     the operator immediately sees the new state.

### Backend endpoints (admin-only, gated by the same admin-token check
as the rest of `/api/settings/*`)
- `GET  /api/settings/database/check` — structural check (idempotent).
- `POST /api/settings/database/check` — same, accepts POST too.
- `POST /api/settings/database/recreate` — destructive rebuild. Refuses
  without `{"confirm":"RECREATE"}` in the JSON body. No GET variant.

### Safety / security
- All three endpoints require the admin token and a configured Settings
  tab — the same protection as `/api/settings/*`, `/api/logs/*` and
  `/api/security/*`. Public callers cannot trigger DB maintenance.
- Mutating actions are POST-only. ReCreate is rejected unless the
  request body carries the literal string `RECREATE` in `confirm`.
- Responses are always structured JSON. No HTML fallback can leak into
  the chat layer's `r.json()` parsers.
- Database passwords, DSN secrets, API keys and absolute filesystem
  paths are never included in responses or in the new `info` /
  `warn`-level log lines (`settings.database.check` and
  `settings.database.recreate`). All values pass through
  `App\Core\Logger::sanitize()`.
- ReCreate never drops tables not defined in `db/schema.sql` by
  default — if the RevoAp database shares a schema with another
  application's tables, those tables survive a ReCreate.

### Tests (SmokeTest.php)
- SchemaParser extracts every table from `db/schema.sql` and records
  column metadata.
- SchemaInspector reports missing tables against an empty SQLite DB.
- SchemaInspector reports `schema_ready=true` when every parsed table
  is present.
- SettingsController gates the new endpoints on `requireAdmin()`.
- Routes `/api/settings/database/check` and
  `/api/settings/database/recreate` are wired in `index.php`.
- `recreate` returns HTTP 400 `confirmation_required` when the request
  body does not carry `confirm:"RECREATE"`.
- SchemaManager drops only schema-defined tables by default.
- Settings UI source (`assets/js/settings.js`) renders the three
  buttons, the typed-confirmation prompt, and the schema-report panel.

### Preserved
- v1.8.24 Share-button self-healing on databases missing migration 006
  (`App\Storage\ShareRepository::ensureTable()`) is unchanged.

## [1.8.24] — 2026-05-13

### Fixed
- **Chat → Share button returned HTTP 500 with a misleading "database
  unavailable" message even when the database was healthy.** The
  diagnosed root cause was that the live production database never had
  migration 006 applied, so the `shared_sessions` table was missing.
  `ShareRepository::shareSession()` inserted blind and the PDO driver
  raised `SQLSTATE[42S02] (errno 1146): Base table or view not found`;
  `ShareController` caught a generic `Throwable` and emitted an opaque
  500 that pointed operators at the wrong subsystem.

  This release fixes that exact path:
  1. `ShareRepository` now auto-ensures `shared_sessions` before the
     first INSERT by running the idempotent
     `CREATE TABLE IF NOT EXISTS shared_sessions …` DDL from
     `db/migrations/006_shared_sessions.sql`. Memoized per repository
     instance, with a one-shot retry if the table disappears between
     ensure and INSERT (race during a concurrent drop). The button now
     self-heals on DBs where migration 006 was never applied.
  2. New `App\Storage\SchemaMissingException` is the typed signal for
     "the table is missing and we could not create it" (typically
     restricted DDL privileges). It carries the missing table name and
     the migration filename.
  3. `ShareController::create()` now returns precise, structured JSON:
       - `409 migration_required` with `missing_table`, `migration`,
         and a sanitized `db_health` snapshot when the auto-ensure
         falls back to `SchemaMissingException`.
       - `503 database_unavailable` with sanitized `reason.sqlstate`
         and `reason.message` for live `PDOException`s.
       - `500 share_failed` with `reason.class` + sanitized
         `reason.message` for any other failure. Passwords and DSN
         secrets are never included in any path.
  4. `App\Core\DatabaseHealth::REQUIRED_TABLES` now includes
     `shared_sessions`, so health probes (History/Statistic banners,
     /api/health) warn operators about the missing table before a user
     clicks Share.
  5. `GET /api/share/session` (wrong verb, no token) now returns
     `405 method_not_allowed` with `Allow: POST` so curl / probe tools
     get an actionable diagnostic instead of a 404.
  6. `assets/js/app.js` renders a friendly inline message for
     `migration_required` (names the migration file and the
     Settings → Migrations path) and surfaces `reason.class /
     reason.sqlstate / reason.message` for other structured errors.

### Preserved
- v1.8.23 main-selected L4 synthesis + SSE-drop recovery for
  no-consensus runs is unchanged.

## [1.8.23] — 2026-05-12

### Fixed
- **No final answer when models did not reach consensus / `Current
  activity failed: Stream closed: SSE connection dropped mid-run` at
  L4.** The pipeline previously routed no-consensus runs through a
  dedicated arbiter (OpenRouter `qwen/qwen-2.5-72b-instruct`) and, when
  that was unavailable, the configured BotHub/default synthesis model.
  Both paths were prone to dropping mid-L4 when the EventSource layer
  reconnected, leaving the user with a failed-activity banner and no
  final answer even though the backend had collected every L1 result.
  Two changes ship together to fix this:
  1. When the council does not reach consensus (conflict / partial
     below threshold / cycle limit reached / stagnation / manual
     finalize), L4 now synthesises through the **MAIN user-selected
     model** — the first entry of `selected_models` in the user's
     order. The prompt includes the original query, the consensus
     status + score, the loop stop reason and every collected L1
     answer; the model is asked (RU/EN) to pick the most likely
     correct solution and produce one comprehensive answer that folds
     in reasonable nuances from the other answers and explicitly
     notes that the council did not fully agree.
  2. The SSE controller now calls `ignore_user_abort(true)` +
     `set_time_limit(0)` so the engine keeps running and persists the
     final answer even if the browser drops the connection at L4.
- **Frontend recovery after SSE drop at L4.** `app.js` now tracks the
  last-seen `session_id` and a `lastRunL4OrTerminalSeen` flag (set on
  the first `L4_*` / `CONSENSUS_CYCLE_LIMIT_REACHED` /
  `CONSENSUS_STAGNATION_DETECTED` / `CONSENSUS_MANUAL_FINALIZE_*` /
  `PIPELINE_FINALIZING` event). On `Stream closed: SSE connection
  dropped mid-run` the catch handler polls
  `GET /api/council/session/{id}` (up to ~13.5s across six attempts)
  and renders the persisted final answer + synthesis stats instead of
  showing a "failed" banner when the backend completed the run.

### Added
- **`L4_MAIN_MODEL_SYNTHESIS_STARTED` event** emitted before the first
  call to the main selected model, carrying `synthesis_strategy =
  main_selected_model`, `selected_index`, `provider`, `model`,
  `consensus_status`, `consensus_score`, `reason` (`no_consensus`,
  `cycle_limit_reached`, `stagnation`, `manual_finalize`,
  `partial_below_threshold`, `rejected`) and `candidate_count`.
- **`L4_MAIN_MODEL_ATTEMPT_FAILED` event** emitted on each failed
  attempt while walking the selected list, with the attempt index,
  provider, model and sanitised error message.
- **`L4_MAIN_MODEL_SYNTHESIS_FAILED` event** emitted only when every
  selected model fails. The legacy arbiter / configured synthesis
  chain still runs after this so `PIPELINE_DONE` is always emitted.
- **`L4_SYNTHESIS` payload extended** with `synthesis_strategy`,
  `selected_index`, `provider`, `model` and `reason` when the main
  selected model produced the answer.
- **Session recovery fields on `GET /api/council/session/{id}`**:
  `final_answer`, `consensus` (`{score, status}`), `synthesis_stats`
  (pulled from the most recent `L4_SYNTHESIS` event), `events` and
  `completed`. Backwards-compatible with the v1.x response shape.
- **`pipeline.main_selected_synthesis.enabled`** config flag (default
  on). Set `false` to preserve the v1.8.22 arbiter behaviour.

### Changed
- **`PipelineEngine::run()`** stores the resolved & validated
  `selected_models` list (in user-supplied order) on
  `$this->resolvedSelectedModels` so the L4 synthesizer can walk it
  without re-resolving against the catalog or connectors.
- **`CouncilController::stream()`** flips
  `ignore_user_abort(true)` + `set_time_limit(0)` so the worker
  finishes the pipeline after a browser drop.

### Constraints / preserved
- v1.8.22 `LogToConsole` config & safe-JSON behaviour preserved.
- PHP 8.4 `strict_types` still mandatory.
- Cache, cycle limit, manual finalize and Share features unchanged.
- `.sql.php` semicolon requirement unchanged.
- Secrets are never logged; L4 prompt bodies stay off the default
  console-log channel.

## [1.8.22] — 2026-05-12

### Fixed
- **"Unexpected token '<', '<!DOCTYPE \"... is not valid JSON" chat
  status after an L1 model returned.** Reproduced from a v1.8.21 paste
  on a Russian-language consensus run where `consensus_4@google_gemini_3_1_pro_preview`
  returned an `L1_RESPONSE` successfully but the SSE stream then
  dropped mid-pipeline (two other L1 models were still in flight). The
  catch handler in `submitQuery()` looked at `activeRun?.anyEventSeen`
  to decide whether to fall back to the synchronous `/api/council/run`,
  but `closeActiveRun()` (the `finally` block) had already cleared the
  `activeRun` global to null. `undefined` is falsy, so the fallback
  fired — and the backend, still mid-pipeline on that `client_run_id`,
  returned the SPA's HTML index page (or a 500 HTML body) on
  `/api/council/run`. `res.json()` then exploded with the parser
  error that landed in the chat status. The fix is layered:
  1. A new module-scope `lastRunAnyEventSeen` flag remembers whether
     ANY pipeline frame had landed before the stream terminated; the
     catch handler consults this flag instead of `activeRun?.…`.
  2. `closeActiveRun()` and `finish()` both copy the run-local flag
     into `lastRunAnyEventSeen` before clearing `activeRun`.
  3. The catch handler now sets the chat status to a human-readable
     "Stream closed: …" message AND flips any still-running step
     cards (typically L1 spinners stuck on "Awaiting model response…")
     to `failed` via the new `markStaleRunningStepsFailed()` helper
     so the audit trail no longer lies about in-flight work.
- **`/api/*` route misses returned HTML on uncaught exceptions.** The
  Router returns `null` on no match and the legacy 404 path in
  `index.php` used `Response::json(...)`, which IS JSON — but any
  exception thrown by a route handler bypassed that path entirely
  and bubbled up to the default PHP error page (text/html). Wrapped
  `router->dispatch()` in a try/catch that returns JSON 500 for
  `/api/*` exceptions, with `error`, `message`, `path`, `class` fields.
  Non-API paths preserve the prior behaviour so operators still see
  the PHP error page in the browser when debugging non-API failures.
- **L1 cards stuck on "Awaiting model response…" after PIPELINE_FATAL.**
  Pipeline.js now flips every still-running step card to `failed` with
  a clear reason whenever a terminal failure event (`PIPELINE_FATAL`,
  `LOOP_DETECTED`, `CONSENSUS_LOOP_GUARD_TRIGGERED`) arrives.

### Added
- **`app.log_to_console` config flag (default true).** A new top-level
  `LogToConsole` alias is honoured for operator convenience. When true,
  the frontend emits structured Chrome console logs covering:
  - boot banner with app version + build id + debug-mode summary
  - `api.start` / `api.end` / `api.error` spans for every `fetch()`
    call: method, URL path (query stripped), HTTP status, content-type,
    duration, request id, and a `non_json` flag when the response was
    not JSON
  - `sse.open` / `sse.event_dropped` / `sse.error` / `sse.close` /
    `sse.reconnect_suppressed` lifecycle events with run id, ready
    state and the anyEventSeen flag
  - `pipeline.event` rows for every SSE pipeline frame, surfacing
    `type`, `step_id`, `role`, `cycle`, `cycle_limit`, `model`,
    `provider`, `cache_hit`, `latency_ms`, `total_tokens`, `cost`,
    `session_id`, `reason` and (for failures) `error_class` /
    `error_msg`. No prompts or answer text — only metadata.
  - `control` action lifecycle (pause / continue / reject / finalize)
  - `activity` state changes (current-activity tail card)
  Prompts and answers are NEVER logged by default. Set
  `window.OC_DEBUG_VERBOSE = true` from the browser console to include
  full payloads (still scoped to fields you can already see in the
  pipeline UI). URL `?debug=0` / `?debug=1` and
  `window.OC_DEBUG = true/false` override the config flag for ad-hoc
  debugging without redeploying.
- **New `assets/js/logger.js` module** exporting `log`, `warn`, `error`,
  `apiStart`, `apiEnd`, `apiError`, `sse`, `pipelineEvent`, `control`,
  `activity`, `nonJsonPreview`, and a `fetchJson()` wrapper that
  validates `Content-Type` before calling `response.json()`. When the
  body is not JSON it throws a structured `NonJsonResponseError`
  carrying the HTTP status, content-type and a sanitized 1000-char
  body preview. `nonJsonPreview()` ALWAYS emits to the console even
  when logging is otherwise disabled — this is the operator's window
  into the "Unexpected token '<'" failure mode.
- **Safe JSON fetch wrappers everywhere.** `app.js` now calls
  `logger_fetchJsonOrSurface(...)` instead of raw `fetch().then(r =>
  r.json())` for `/api/connectors`, `/api/consensus/skills`,
  `/api/council/run`, `/api/council/control` and `/api/share/session`,
  so any backend that returns HTML produces a clear, actionable error
  in both the chat status and the Chrome console.
- **`/api/boot-config` endpoint.** Returns the runtime app name,
  version, build id, `app.debug` bool and `log_to_console` flag.
  Surfaced through the layout as `<meta name="oc-log-to-console">`
  and `<meta name="oc-app-debug">` so the logger has the flag values
  available the moment the module evaluates.
- **`pipeline.js::markStaleRunningStepsFailed(reason)`** export. Flips
  every still-running step card to `failed`, removes the spinner and
  rewrites the placeholder body text. Called from app.js on stream
  close and from pipeline.js on `PIPELINE_FATAL` / `LOOP_DETECTED` /
  `CONSENSUS_LOOP_GUARD_TRIGGERED`.

### Changed
- `Config::isLogToConsoleEnabled()` resolves the flag using the same
  precedence pattern as the tab flags: top-level `LogToConsole` alias
  wins, then nested `app.log_to_console`, default `true`.
- `index.php::renderHome()` now passes `log_to_console` and `app_debug`
  to the layout template as `"true"`/`"false"` strings so the
  `<meta>` injection is unambiguous.
- The old in-app `DEBUG` / `debugLog()` shim in `app.js` now delegates
  to `logger.js`, so a single resolved-once flag controls every
  module's verbosity.

### Security
- Console logs never include API keys, prompts or model answers by
  default. The `PROMPT_FIELDS` redaction set covers `content`,
  `prompt`, `messages`, `answer`, `response`, `system`, `user`,
  `final_answer`, `preview` and `summary`. Operators must explicitly
  set `window.OC_DEBUG_VERBOSE = true` to override.
- The non-JSON body preview is truncated to 1000 chars and stripped
  of internal whitespace before reaching the console — it never lands
  in DOM nodes (the chat status uses an `<output>` element whose
  textContent is set, not innerHTML).

## [1.8.21] — 2026-05-12

### Fixed
- **Phantom consensus cycles caused by EventSource auto-reconnect.**
  When the SSE stream `/api/council/stream` was closed by a PHP-FPM /
  proxy timeout before emitting `PIPELINE_DONE`, the browser's
  `EventSource` auto-reconnected to the same URL and the backend
  spawned a *brand new* `PipelineEngine::run()` from cycle 1. With
  `cycle_limit=2` users observed up to 10 round-1 cycles back-to-back
  (paste evidence: 10 `L3_CONSENSUS` events all stamped `round: 1,
  cycle: 1` with the identical score `0.40974…`). The fix is a
  per-`client_run_id` file-backed mutex (`StreamRunLock`):
  - Frontend mints a stable `client_run_id` per submission and passes
    it as a query-string parameter.
  - Backend acquires the lock on first connect; duplicates emit
    `PIPELINE_DUPLICATE_RUN_REJECTED` and terminate the SSE without
    re-running the pipeline.
  - The lock is released on `PIPELINE_DONE / FATAL / REJECTED`.
  - Stale locks (older than 15 minutes) are reclaimed automatically.
- **Defense-in-depth loop invariant guard.** Even in the absence of
  the EventSource bug, the engine now counts L1 batches per run; if
  the count would exceed `cycle_limit + 1` it emits
  `CONSENSUS_LOOP_GUARD_TRIGGERED` and drops through to L4. This
  bound is a hard contract independent of the `for` loop ceiling.

### Changed
- **Deterministic model-response cache layout (`cache/` at project
  root).** Cache files now live at
  `cache/<safe-provider>/<safe-model-hierarchy>/<sha>.json` instead of
  the flat `var/cache/model-responses/<sha>.json`. Reading still falls
  back to the legacy flat layout so existing entries do not need a
  migration step.
- **Cache checksum normalization.** Cache-key input is derived from a
  deterministic normalization of provider + model + each role-tagged
  message body + the cache-relevant options whitelist. Rules:
  1. Trim outer whitespace.
  2. Collapse internal `\s+` runs to a single ASCII space.
  3. Drop every character that is not a Unicode letter (`\p{L}`) or
     digit (`\p{N}`). Case is preserved.

  Trivial whitespace/punctuation differences map to the same key for
  the same model; different models (or different system prompts) never
  collide. The on-disk envelope stores BOTH the original (unmodified)
  request AND the original response so cached entries are inspectable.
- **Cache version bumped to 2** (`ModelResponseCache::VERSION`).
- **Cache files are protected from public access.** Added
  `cache/.htaccess` with `Require all denied` so even if the project
  root is the document root, cached responses cannot be served as
  static assets.

### Added
- **Verbose `debug=1` browser console logging.** Enable by appending
  `?debug=1` to any page URL (or setting `window.OC_DEBUG = true`).
  Logs cover pipeline run start (with `client_run_id`, selected
  models, consensus skill, reasoning, cycle limit), every SSE event
  type with `step_id`/`cycle`/`cycle_limit`/`model`/`cache_hit`/
  `session_id`/`reason`, control button actions and the server's
  reply, SSE open/error/auto-reconnect suppression decisions, and
  terminal events with the resolved reason (`agreement`,
  `cycle_limit_reached`, `stagnation`, `manual_finalize`,
  `loop_guard_triggered`, `duplicate_run_rejected`, `rejected`,
  fatal). API keys, prompts and answers are NEVER logged at this
  level; set `window.OC_DEBUG_VERBOSE = true` separately for full
  payloads.
- **New pipeline events**:
  - `CONSENSUS_LOOP_GUARD_TRIGGERED` — defense-in-depth loop invariant
    guard fired. Carries `l1_batches_run`, `guard_max`.
  - `PIPELINE_DUPLICATE_RUN_REJECTED` — the SSE backend refused to
    start a second pipeline for the same `client_run_id`. Carries the
    original session id where available.
- **New `StreamRunLock` class** under `src/Pipeline/`. Tiny file-backed
  mutex keyed by `client_run_id`. Atomic O_EXCL creates the lock file;
  stale locks are reclaimed after 15 minutes.
- **`SseEventStreamer::setOnEmit`** subscribe hook so the SSE
  controller can record the `session_id` on the active lock the moment
  `PIPELINE_STARTED` is emitted.

### Security
- Cache files are denied at the webserver level (`cache/.htaccess`).
  API keys, Authorization headers and bearer tokens are NEVER part of
  the cache key OR the cache envelope. Lock files in
  `runtime/stream-locks/` carry only the `client_run_id` hash, PID and
  timestamps — no prompt or response bodies.

## [1.8.20] — 2026-05-12

### Added
- **"Stop and Run consensus" pipeline control.** A new third action sits
  next to *Pause* and *Reject* in the chat controls
  (`assets/templates/chat.html` → `id="chat-finalize"`). Clicking it
  cooperatively stops the consensus cycle loop at the next safe
  boundary and routes whatever L1/L2/L3 results were already collected
  into L4 synthesis, producing a normal final answer. It is a distinct
  terminal-for-collection action:
  - *different from Reject* — Reject aborts the run and produces no
    answer; Stop and Run consensus produces an answer from the partial
    pool.
  - *different from Pause* — Pause is resumable; Stop and Run consensus
    is terminal for model collection.
  The button is disabled until at least one `L1_RESPONSE` event has
  been received (the minimum L4 needs to synthesize meaningfully).
- **New control action `finalize`** on `POST /api/council/control`. The
  body is the same shape as for `pause`/`continue`/`reject`. Idempotent
  — repeated calls are no-ops once the engine is already finalising.
- **New `RunControl` constant** `ACTION_FINALIZE` and state
  `STATE_FINALIZING`. Distinct from `STATE_PAUSED` and `STATE_REJECTED`
  so the engine can tell apart "stop & synthesise" from "abort". A new
  `RunControl::isFinalizing()` helper rounds out the API.
- **New pipeline events**:
  - `CONSENSUS_MANUAL_FINALIZE_REQUESTED` — user-initiated finalize was
    detected at a cycle boundary; carries `cycle`, `cycle_limit`,
    `reason="user_request"` and a human-readable detail.
  - `CONSENSUS_MANUAL_FINALIZE_STARTED` — engine accepted the request
    and is heading to L4.
  - `PIPELINE_FINALIZING` — terminal-for-collection marker emitted
    immediately before the engine drops out of the cycle loop into
    synthesis.
  `PIPELINE_DONE.loop_stop_reason` is set to `"manual_finalize"` so
  History/Share renderings can label the run accordingly.
- **Final-answer disclosure note.** When the loop ended via Stop and
  Run consensus the final answer is prepended with a single-line note
  that cites the cycle reached and the number of model results used by
  L4. The note is *added* — never replacing the synthesised content —
  so downstream share/history rendering still preserves the model
  output.

### Fixed
- **Invalid OpenRouter model id `google/gemini-3.1-flash-latest`.**
  Removed from the shipped `config/models/openrouter.php` catalogue.
  OpenRouter's live `/api/v1/models` endpoint contains **no** model
  with a `flash-latest` suffix — the slug was a guess that produced
  `HTTP 400: ... "is not a valid model ID"` on every L1 call routed to
  it. Replacement: **`google/gemini-3.1-flash-lite`**, the closest
  valid Gemini-3.1 Flash variant present in the live catalogue
  (sibling of `google/gemini-3.1-pro-preview`, which was already valid
  and is preserved). Operators who want auto-routing instead can set
  `~google/gemini-flash-latest` (note the leading `~`).
- **Regression guard** in `tests/SmokeTest.php` asserts the exact dead
  slug is absent from the catalogue file, the runtime
  `pipeline.default_selected_models`, and the
  `src/Settings/PopularModels.php` fallback list. The check is purely
  static — no live network — so it works on hosts that block outbound
  HTTP.

### Notes
- `RunControl` is no longer `final` so tests can subclass it to inject
  deterministic state transitions. Production code should not extend
  it.
- `pipeline.default_selected_models` is unchanged from v1.8.18 — the
  bad slug was never in the default pool, only the broader catalogue.

## [1.8.19] — 2026-05-12

### Fixed
- **Endless consensus-cycle / token-burn protection.** Multi-round
  consensus runs at high reasoning levels could spend many tokens
  iterating through every configured round even when the score had
  plateaued. The loop in `PipelineEngine::run()` is now defended by
  three independent hard stops, in priority order: (1) agreement above
  the configured score threshold; (2) zero L1 results; (3) cycle limit
  reached; (4) stagnation between cycles (delta below
  `pipeline.consensus_stagnation_epsilon` AND status unchanged). The
  cycle limit is itself clamped to `[pipeline.cycle_limit_min,
  pipeline.cycle_limit_max]` (default 1..5) so no UI, runtime or
  config value can drive runaway iteration.

### Added
- **`Cycle Limit` form control** next to Reasoning / Consensus skill on
  the main page (`assets/templates/chat.html`, `assets/js/app.js`).
  A numeric input bounded to `[1, 5]` lets the operator pick the
  maximum number of consensus cycles for one run. Default comes from
  `pipeline.max_consensus_rounds` (currently 2). The server clamps
  every request server-side regardless of what the UI sends — a
  `CONSENSUS_CYCLE_LIMIT_CLAMPED` event records the clamp.
- **Per-request `cycle_limit` option** end-to-end (`POST /api/council/run`
  and `GET /api/council/stream?cycle_limit=…`). Accepts integers and
  numeric strings; non-numeric values are ignored. Forwarded by
  `CouncilController::extractRunOptions()` to
  `PipelineEngine::resolveCycleLimit()`, which decides the effective
  limit, clamps it to the validated range, and exposes the resolution
  in the `L0_OPTIONS.cycle_limit_meta` block.
- **New pipeline events**:
  - `CONSENSUS_CYCLE_LIMIT_CLAMPED` — emitted on every clamp; carries
    `requested`, `effective`, `min`, `max`, `reason`.
  - `CONSENSUS_CYCLE_LIMIT_REACHED` — emitted when the loop exhausts
    its budget without reaching agreement. The engine does **NOT**
    call any models again — control falls through to the existing
    conflict-arbiter / synthesis path so the user always sees a
    final answer.
  - `CONSENSUS_STAGNATION_DETECTED` — emitted when the cycle-over-cycle
    score delta is below `consensus_stagnation_epsilon` AND the status
    did not change. The loop stops early and proceeds to final
    synthesis.
- **`cycle` and `cycle_limit` fields** on every consensus-bearing event
  (`CONSENSUS_ROUND_STARTED`, `CONSENSUS_ROUND_COMPLETED`,
  `CONSENSUS_ROUND_REPEAT`, `L1_STEP_STARTED`, `L1_RESPONSE`,
  `L2_NORMALIZED`, `L3_CONSENSUS`, `PIPELINE_DONE`) and on
  `L0_OPTIONS`. `PIPELINE_DONE` also carries `loop_stop_reason` (one
  of `agreement`, `cycle_limit_reached`, `stagnation`, `no_l1_results`).
- **Pipeline UI: "Consensus Cycle X / Y" headers**
  (`assets/js/pipeline.js`). `ensureRoundHeader()` accepts the cycle
  limit and renders it as a fraction. The header backfills the
  denominator if the first event of a cycle did not carry it. Human
  activity labels for the new events are pre-populated.

### Changed
- `pipeline.max_consensus_rounds` is now the **default** cycle limit; a
  per-request `cycle_limit` overrides it. New companion knobs:
  - `pipeline.cycle_limit_min` (default 1) — minimum allowed value
  - `pipeline.cycle_limit_max` (default 5) — hard ceiling
  - `pipeline.consensus_stagnation_epsilon` (default 0.01) — minimum
    cycle-over-cycle score delta below which the loop stops early.
    Set to 0 to disable stagnation detection.
- `app.version` bumped to **1.8.19**.

## [1.8.18] — 2026-05-12

### Changed
- **Fresh-install default model selection refreshed.** The four models
  pre-selected for a brand-new operator (before they touch the Settings
  UI) are now, in order:
  1. `openrouter` — `openai/gpt-chat-latest`
  2. `openrouter` — `anthropic/claude-sonnet-4.6`
  3. `openrouter` — `google/gemini-3.1-pro-preview`
  4. `openrouter` — `deepseek/deepseek-v4-pro`

  Previously `openai/gpt-5.5` occupied the slot now held by
  `deepseek/deepseek-v4-pro`. `openai/gpt-5.5` remains available in the
  OpenRouter catalogue (`config/models/openrouter.php`) and can be
  toggled on from the Settings UI, but it is no longer pre-selected on
  a fresh install. Operator-supplied selections — via
  `pipeline.default_selected_models` in `config.php` / `.config.php`,
  or saved through Settings — continue to win unchanged; this change
  only affects deployments where no selection has been persisted.

## [1.8.17] — 2026-05-12

### Added
- **Semantic-component consensus scoring** (`src/Consensus/SemanticConsensusEngine.php`).
  The L3 layer no longer relies on pairwise token-bag Jaccard. The new
  engine extracts semantic components (claims / bullets / sentences)
  from each model's answer, clusters them across models using keyword
  stems, character 3-grams and numeric / entity hints, and scores
  consensus as the fraction of cross-model semantic content covered
  by shared clusters. Russian and English paraphrases of the same
  idea now correctly fall in the partial/agreement band rather than
  collapsing to ≈ 0.07. Status thresholds (`agreement ≥ 0.85`,
  `partial ≥ 0.55`, `conflict` otherwise) are preserved. Numeric
  contradictions detected across models lower the score by up to 0.15;
  a fully-unanimous cluster grants a +0.05 bonus.
- **New bundled consensus skill** `semantic-consensus-reasoning`
  (`src/Skills/definitions/semantic_consensus_reasoning.json`). Roles
  emit numbered claims with calibrated confidence so the semantic
  clusterer can compare them across models. Round-2 system prompt asks
  each model to mark which peer claims it accepts, contradicts or
  revises (graded consensus, per Pokharel et al. 2025 — arXiv:2504.02128).
- **Selectable consensus skill on the main page.** The chat form exposes
  a new `Consensus skill` dropdown driven by `GET /api/consensus/skills`,
  listing every bundled consensus skill (`tags` contain `consensus`).
  The active selection flows through `POST /api/council/run` and the
  SSE `/api/council/stream` endpoint, where `CouncilController` parses
  `consensus_skill` as either a slug string or a structured object.
  The pipeline `resolveConsensusMode()` resolves the scoring backend
  for the run from (a) the request payload's `consensus_skill.scoring`,
  (b) the bundled JSON definition's `consensus_scoring` field, or
  (c) `pipeline.consensus.scoring` in `config.php`. Legacy
  `lexical_jaccard` remains available.
- **Share button & public read-only shared session view.** A new
  `ShareController` (`src/Http/Controllers/ShareController.php`) exposes
  three endpoints:
  - `POST /api/share/session` — mints (or returns the existing) opaque
    high-entropy 64-character hex token for a completed `session_id`
    and returns the absolute share URL. Tokens carry 256 bits of
    entropy via `random_bytes(32)` and are NOT derived from the
    session id, so they cannot be enumerated.
  - `GET /share/{token}` and `GET /shared?token=…` — render a
    standalone read-only HTML view (`assets/templates/shared.html`)
    of the query, final answer, ordered L0/L1/L2/L3/L4 steps,
    consensus rounds and stats. The shared page contains NO model
    selection controls, NO Settings / Logs / Security / History /
    Statistic admin tabs and NO ability to start a pipeline.
  - `GET /api/share/session/{token}` — JSON-only variant for
    automation. All rendered text is passed through `Logger::sanitize`.
  Tokens are persisted in the new `shared_sessions` table — see
  migration `db/migrations/006_shared_sessions.sql`. Falls back to
  in-process memory when no database is configured (links won't
  survive a process restart on memory-only hosts — the API surfaces
  this via a `persistent: false` flag).
- **`docs/consensus-research.md`** — bilingual (Russian-first / English)
  research note summarising the four papers that drove the v1.8.17
  scoring redesign and explaining exactly what was adopted: semantic
  components & clustering features from Kallem (arXiv:2601.07245),
  graded multi-round deliberation from Pokharel et al. (arXiv:2504.02128),
  ensemble-validation confidence from Naik (arXiv:2411.06535), and
  the CoT / reasoning taxonomy from Liu et al. (arXiv:2312.07622).
- **Database migration** `006_shared_sessions.sql` adds the
  `shared_sessions(id, session_id, token, created_at, revoked_at)` table.
  `db/schema.sql` is updated to include the same table for fresh installs.

### Changed
- `ConsensusEngine::compute()` now delegates to the semantic backend by
  default; the legacy lexical Jaccard implementation is preserved as
  `computeLexical()` and reachable via `computeWithMode(..., 'lexical_jaccard')`
  or `pipeline.consensus.scoring = 'lexical_jaccard'`.
- `ConsensusExplainer::explain()` mentions the active scoring mode and
  surfaces `clusters`, `shared_clusters` and the top shared topic
  keywords inside its structured `decision` block. UI Raw tabs see
  the same fields.
- `config.php` adds a new `pipeline.consensus.scoring` knob defaulting
  to `semantic_components`. Operators who depended on the previous
  scoring numbers can flip it back to `lexical_jaccard` without code
  changes.
- README highlights, version banner and tests updated to 1.8.17.

### Notes
- The shared view sets `<meta name="robots" content="noindex,nofollow">`
  so search engines do not index leaked share links by accident. The
  view is still public-by-possession-of-link — operators should treat
  share tokens like an API key.
- A graph-attention meta-learner over the similarity graph (as
  proposed by Kallem) is explicitly out of scope for v1.8.17 — the
  semantic engine is a strong analytical baseline that uses the same
  input features. Embedding-backed similarity is supported by the
  `SemanticConsensusEngine::similarity()` extension point but not
  enabled by default (no external API calls are required for the
  shipped baseline).

## [1.8.16] — 2026-05-12

### Added
- **Multi-round consensus with peer answers (round 2).** When L3 reports
  anything other than a clean `agreement` after round 1, the pipeline now
  reissues every L1 model call in a second round. Each model receives,
  in its round-2 prompt:
  - the original user query;
  - **its own previous round-1 answer**, clearly labelled
    `Your previous answer (round 1)`;
  - **every OTHER model's round-1 answer**, labelled by role / provider /
    model — the model's own answer is explicitly excluded from this
    peer-answer block;
  - the round-1 L3 consensus verdict and score;
  - an explicit instruction to revise OR defend its previous answer
    using peer evidence — without blindly copying any single peer.
- **`round2_system_prompt` on the consensus_reasoning skill.** The
  bundled `src/Skills/definitions/consensus_reasoning.json` now ships
  a lateral-thinking system prompt that is injected as an additional
  `system` message during round 2 (in addition to the model's role
  system prompt). Custom skills can override the prompt via the same
  key; auto-created generic skills inherit the bundled prompt as a
  fallback.
- **Config knobs.** `pipeline.max_consensus_rounds` (default `2`)
  caps the round budget; set to `1` to disable round 2 entirely.
  `pipeline.round2_score_threshold` (default `0.85`) controls when an
  otherwise non-agreement round-1 verdict still satisfies the loop.
- **New SSE / pipeline events.** `CONSENSUS_ROUND_STARTED`,
  `CONSENSUS_ROUND_COMPLETED`, and `CONSENSUS_ROUND_REPEAT` give the
  UI clear round boundaries. Every L1/L2/L3 event payload now carries
  a `round` field; the frontend already understood this field (the
  Consensus Round header machinery shipped in v1.8.5), so multi-round
  runs render with a second header card automatically.
- **Step-key disambiguation.** Round-2 step keys carry a `round2.`
  segment (`L1.round2.role.<role>`, `L2.round2.normalize.<role>`,
  `L3.round2.consensus`) so the history serialization can reconstruct
  both rounds without ambiguity. The `meta` JSON on each step row
  records `round` for the same reason.

### Notes
- L1 cache hits across rounds are kept distinct via a new
  `consensus_round` option flag, so a round-2 prompt never collides
  with a round-1 cache entry for the same provider + model.
- The conflict arbiter behaviour (v1.8.6) is unchanged: when the
  final round still doesn't reach agreement, the arbiter still routes
  the raw last-round L1 answers to the dedicated reconciliation model.

## [1.8.15] — 2026-05-12

### Added
- **Model catalogue extracted into dedicated files.** The shipped model
  list has moved out of `config.php` into three new files under
  `config/models/`:
  - `config/models/openrouter.php` — top 10 OpenRouter slugs
    (`openai/gpt-chat-latest`, `openai/gpt-5.5`, `openai/gpt-5.5-pro`,
    `anthropic/claude-sonnet-4.6`, `~anthropic/claude-sonnet-latest`,
    `google/gemini-3.1-pro-preview`, `google/gemini-3.1-flash-latest`,
    `deepseek/deepseek-v4-pro`, `deepseek/deepseek-v4-flash`,
    `x-ai/grok-4.20` plus `qwen/qwen3.6-max-preview` as a bonus row).
  - `config/models/bothub.php` — top 10 BotHub slugs (`gpt-5.5`,
    `gpt-5.5-pro`, `gpt-5.4`, `gpt-5.2`, `claude-sonnet-4.6`,
    `claude-sonnet-4`, `deepseek-r1`,
    `gemini-2.5-flash-lite-preview-09-2025`, `gpt-4o`, `gemini-2.5-pro`).
    Every row defaults to `active=false` because BotHub access is a
    paid add-on.
  - `config/models/direct.php` — top 5 direct providers/models
    (`openai/gpt-5.5`, `anthropic/claude-sonnet-4.6`,
    `gemini/gemini-3.1-pro-preview`, `deepseek/deepseek-chat`,
    `xai/grok-4.20`). All disabled by default.
  Each row carries `provider`, `model`, `label`, `rank`, `category`,
  `active`, and `default`. `Config::load()` walks the new
  `model_files` key in `config.php`, expands the files into the
  runtime `models` list, and records per-file status under
  `app.diagnostics.model_files`.
- **Four fresh-install picks.** A brand-new operator now sees four
  consensus-pool defaults instead of three: `openai/gpt-chat-latest`,
  `openai/gpt-5.5`, `anthropic/claude-sonnet-4.6`, and
  `google/gemini-3.1-pro-preview`. The selection is pinned in
  `config.php` and is also synthesised from any catalogue row marked
  `default=true` if the operator leaves
  `pipeline.default_selected_models` empty.
- **Direct provider connectors.** New `connectors.{provider}` blocks
  for `openai`, `anthropic`, `gemini`, `deepseek`, and `xai` ship
  alongside the existing `bothub` / `openrouter` entries with
  documented `base_url`, timeouts, and optional `key_files` alias
  list. All five are disabled by default; flip `active=true` and
  supply a key to enable.
- **Alias key files.** `Config::getConnectorKey($provider, $model)`
  now consults `connectors.{provider}.key_files` after the canonical
  `.{provider}.key`, so `.claude.key`, `.gemini.key`, `.google.key`,
  and `.grok.key` are picked up for Anthropic, Gemini, and xAI
  respectively. When a model id is supplied the resolver also tries
  a `.{sanitised-model}.key` file (e.g. `.gpt-5.5.key`,
  `.claude-sonnet-4.6.key`) so secrets can be scoped per model.

### Changed
- `pipeline.synthesis_model` defaults to `openai/gpt-chat-latest` and
  `pipeline.conflict_arbiter.model` defaults to `openai/gpt-5.5-pro` —
  the v1.8.14 defaults referenced retired slugs.
- `Settings\PopularModels::recommended()` now derives its picks from
  the runtime catalogue (and, when Config is not yet loaded, directly
  from the shipped `config/models/*.php` files). New picks should be
  added to the catalogue files, not to the class.

### Compatibility
- Operators who still set a populated `models` list in `config.php`,
  `.config.php`, or `runtime.json` keep that list unchanged — the
  shipped catalogue is only expanded when the operator-supplied list
  is empty. Any saved `default_selected_models` selection wins over
  the synthesised one.

## [1.8.14] — 2026-05-08

### Fixed
- **Production `.sql.php` fragments rejected as `no usable dsn returned`.**
  After v1.8.13 operators uploaded a `.sql.php` whose body was the exact
  fragment shown in `config.php`'s comment block:
  ```php
  'database' => [
      'dsn'      => 'mysql:host=localhost;dbname=opencorum;charset=utf8mb4',
      'user'     => 'root',
      ...
  ]
  ```
  i.e. an array pair without a leading `<?php return` wrapper. The
  loader required a full PHP return file, so the override was reported
  as `loaded=false, error="no usable dsn returned"` and production
  silently kept the default `root@localhost`. The loader now accepts
  three shapes: canonical `return_file`, `fragment_database`
  (`'database' => [...]`), and `fragment_fields` (`'dsn' => '...', ...`).
  Fragments are wrapped into a synthetic `<?php return [{fragment}];`
  payload after a strict pre-validation pass that rejects reopened
  PHP tags, backticks, and function-call patterns. `.sql.php` is a
  trusted root deployment artefact — never web-writable, never reading
  user input — which is what makes the fragment path acceptable here.
- **`.admin.key` Permission denied left no actionable hint.** The same
  production deploy carried `.admin.key` with mode `0600` but owned by
  a user the PHP-FPM pool cannot read, so `file_get_contents()`
  returned `Permission denied`. The operator-facing message now
  includes the captured underlying error verbatim, names the
  `chown www-data:www-data .admin.key && chmod 600 .admin.key` fix,
  and points at the `admin.token` overlay alternative below for
  hosts that disallow ownership/mode changes.

### Added
- **`admin.token` config-tree fallback.** AdminAuth resolves the
  admin token from, in priority order:
  1. `OPEN_CORUM_ADMIN_TOKEN` environment variable,
  2. `.admin.key` file (when readable),
  3. `admin.token` key in the loaded config tree.
  The third source is intended for the gitignored `.config.php`
  overlay only — the tracked `config.php` MUST NOT carry secrets and
  the test suite enforces the absence of `admin.token`/`value`/
  `secret` keys in the shipped `config.php`. This unblocks shared
  hosts where PHP cannot read `.admin.key` regardless of how the
  permissions are tweaked. Diagnostics surface `source: "config"`
  and a `config.token_length` field; the token value is never
  exposed.
- **`.sql.php` source diagnostics gain a `format` field.** The
  `app.diagnostics.config_sources['.sql.php']` block now reports
  `format: "return_file" | "fragment_database" | "fragment_fields"`
  alongside `loaded`, `size`, `mtime`, and (on failure) a precise
  `error` such as `"fragment contains disallowed syntax"`. Operators
  inspecting `/api/health/database` no longer have to guess why
  their override was ignored.

### Changed
- `config.php` → `app.version` to `1.8.14`.

### Notes for production
After deploying v1.8.14, the existing `.sql.php` body shipped by
operators (the exact fragment from `config.php`'s comment block)
loads cleanly:
```
config_sources['.sql.php'].format  = "fragment_database"
config_sources['.sql.php'].loaded  = true
database_source                    = ".sql.php"
```
For the `.admin.key` `Permission denied` case, two fixes are
available depending on what the hosting environment allows:
1. Run `chown www-data:www-data .admin.key && chmod 600 .admin.key`
   (replace `www-data` with the user the PHP-FPM pool runs as).
2. Or place a private `.config.php` next to `config.php` containing
   only the admin token leaf:
   ```php
   <?php
   return [
       'admin' => ['token' => 'your-secret-token'],
   ];
   ```
   `.config.php` is gitignored; never paste the token into the
   tracked `config.php`.

## [1.8.13] — 2026-05-08

### Fixed
- **Production database still fell back to `root@localhost` despite
  v1.8.10–1.8.12.** Operators reported repeated
  `SQLSTATE[HY000] [1698] Access denied for user 'root'@'localhost'`
  even though `.url`, `.sql.php`, `.admin.key` and `.openrouter` sat
  side-by-side in `public_html/` and `.openrouter` worked. Root cause:
  `.sql.php` is the only mechanism that actually overrides the
  database block, but it requires either a `<?php return [...]` or
  the flat database fields shape — operators editing only the
  database password (or any other single field) had no way to override
  one leaf without rewriting the whole block, so they ended up either
  shipping incomplete files or relying on `runtime.json` (which the
  Settings UI rewrites). Layered with that, every wrong attempt
  produced no diagnostic — `/api/health` reported the database as
  unconfigured without saying which dot-file it had inspected, what
  size/mtime it observed, or why the contents were rejected.

### Added
- **`.config.php` recursive overlay.** A new optional root file:
  ```php
  <?php
  return [
      'app'      => ['debug' => true, 'url' => 'https://prod.example.com'],
      'database' => ['password' => 'secret-from-vault'],
      'connectors' => [
          'openrouter' => ['api_key' => 'sk-…'],
      ],
  ];
  ```
  is recursively merged into `config.php` BEFORE `.url`/`.sql.php`,
  so only the leaves you set override the base. Sibling keys
  (`app.name`, `app.version`, `database.dsn`, `database.user`,
  `connectors.bothub.api_key`, …) are preserved. Associative arrays
  recursively merge; list arrays such as `models` and
  `pipeline.default_selected_models` replace the base list
  wholesale (documented as the safe behavior since lists are
  catalogues, not partial sets). Malformed files (parse error, non-
  array return, etc.) never block boot — the failure surfaces in
  diagnostics with a concrete reason and the base config is kept.
- **Per-source diagnostics block.** `/api/health` now exposes
  `config_sources` with one entry per dot-file (`.config.php`,
  `.url`, `.sql.php`, `.admin.key`):
  ```json
  {
    "path": "public_html/.config.php",
    "source": "document-root",
    "exists": true,
    "size": 412,
    "mtime": 1715122813,
    "loaded": true,
    "error": null,
    "sections": ["app", "database"]
  }
  ```
  Only metadata is exposed — never the contents — so the block is
  safe to consume publicly. `DatabaseHealth::snapshot` also surfaces
  the same `config_sources` block plus the new
  `runtime_shadow_sections` array (which sections of `runtime.json`
  are masking the deployment files). Operators can finally tell at
  a glance why a deployment file did or did not take effect.
- **Documented precedence.** Application order is now spelled out in
  `config.php` and `src/Core/Config.php`:
  `base config.php < .config.php < .url < .sql.php < runtime.json`.
  `.sql.php` deliberately wins over `.config.php` for the database
  block because it is an emergency DB-only override; `runtime.json`
  always wins last because it is what the admin Settings UI persists.

### Changed
- **Logger throttles identical lines per request.** The resilient
  Database wrapper, the connector chain and a few other modules
  emit the same warning multiple times per request when each
  dependent call retries. `Logger::log()` now collapses duplicate
  `(level + message)` pairs in-process so a single failing
  connection produces one log line instead of dozens. The original
  signal is preserved (first occurrence is written verbatim).

### Notes for operators after upgrading
1. `git pull` and reload PHP-FPM (or otherwise reset opcache) so
   `Config.php` and `Logger.php` pick up the new code. Dot-files
   themselves do not need an opcache reset when
   `opcache.validate_timestamps=1` is on, but the includes that
   read them do.
2. Hit `/api/health`. If `database.source` is still `config.php`,
   inspect `config_sources['.config.php']`,
   `config_sources['.sql.php']` and `root.basename` to see exactly
   which directory the resolver picked, whether the file exists,
   whether it loaded, and what error rejected it. The block never
   exposes values, so it is safe to share with support.
3. Drop a one-line `.config.php` next to `index.php` /
   `config.php` if you only want to override a few leaves
   (`['app' => ['debug' => true]]`); use `.sql.php` only when you
   need the entire database block replaced as an emergency
   override.

## [1.8.12] — 2026-05-08

### Fixed
- **Hidden dot-files in `public_html/` were not applied even though
  they sat next to `config.php` / `index.php`.** Health endpoints
  reported `.admin.key` as `exists=true, readable=false, empty=true,
  configured=false` for a 65-byte file on disk; `.url` and `.sql.php`
  were silently ignored, so the database fell back to the default
  `mysql:host=localhost;dbname=opencorum` block while only
  `.openrouter.key` (which used a different code path) actually
  loaded. Root cause: `is_readable()` is unreliable on a number of
  shared hosts (cPanel + suEXEC, some PHP-FPM chroots) — it returns
  false for hidden files that `file_get_contents()` can still open.
  The previous loaders gated every read on `is_readable()` and so
  treated those files as missing.

### Added
- **`App\Helpers\RootFiles` — a single root-file resolver and reader
  shared by Config, AdminAuth, and `.{provider}.key` lookups.** It
  enumerates the same candidate list (pinned root → Config root →
  src-relative → DOCUMENT_ROOT → walk-up → cwd), prefers the
  directory that already contains the requested file, and crucially
  attempts `file_get_contents()` directly without trusting
  `is_readable()` as a gate. Read failures are captured into a
  structured `read_error` field instead of being silently misreported.
- **Honest admin diagnostics.** `AdminAuth::diagnostics()` now
  surfaces `file.read_ok` (true when the open succeeded) and
  `file.read_error` (PHP error message when it failed). The legacy
  `file.empty` field is now tri-state: `true` when the file is empty,
  `false` when it contains a token, and `null` when the file exists
  on disk but the read failed — killing the misleading
  `exists=true,size>0,empty=true` triplet that survived from earlier
  builds.
- **Database source visibility.** `DatabaseHealth::snapshot()` now
  includes `source` (`config.php`, `.sql.php`, or `runtime.json`) and
  `runtime_shadow` so operators immediately see which file produced
  the active database block — and whether an admin-saved
  `var/config/runtime.json` is silently masking a deployment
  `.sql.php`.

### Changed
- **`Config::applyRootFileOverrides` no longer gates `.url` /
  `.sql.php` on `is_readable()`.** It reads through the shared
  RootFiles helper, records a `.url present but unreadable — ignored`
  diagnostic if the open genuinely fails, and stamps
  `app.diagnostics.database_source` so health endpoints can name the
  active source without re-resolving.
- **`Config::getConnectorKey` reads `.{provider}.key` via RootFiles
  too**, so a `public_html/`-style deployment finds the file next to
  `config.php` / `index.php` even when the canonical src-relative
  root is a parent of `DOCUMENT_ROOT`.

### Tests
- New `tests/SmokeTest.php` block creates a deployment-shaped tmp root
  with `config.php`, `index.php`, `.admin.key`, `.url`, `.sql.php`,
  and `.openrouter.key` all in the same directory and asserts that
  every file loads with the same precedence rules. The block also
  carries the regression assertion against
  `exists=true,size>0,empty=true` for read failures and verifies the
  `runtime.json` shadow path.

## [1.8.11] — 2026-05-08

### Fixed
- **`Run Pipeline` stayed disabled after the user clicked `Reject`.**
  Clicking Reject closed the SSE stream eagerly via `closeActiveRun()`
  but left the pending `startSse()` Promise unresolved, so the
  awaiting `submitQuery()` never reached its `finally` block — the
  block that re-enables the submit button. `closeActiveRun()` now
  settles the awaiting Promise with a `reason: 'rejected'` envelope,
  the awaiting submitQuery skips the synchronous fallback (a rejected
  run must NOT silently re-execute), and the Run button is force-
  enabled in `onRejectClick()` so the user can immediately fire a
  fresh request without reloading the page. Re-running after Reject
  opens a fresh EventSource and a fresh session id; no stale state
  carries over.
- **`.admin.key` still reported "not configured" on hosts where the
  file was saved with hidden control bytes.** The normalizer now
  strips UTF-16 LE/BE BOMs and embedded NUL bytes (in addition to
  the existing UTF-8 BOM handling) before trimming surrounding
  whitespace, so files saved by editors that emit zero-padding around
  ASCII content no longer fail detection.

### Added
- **Configurable admin lookup names via `config.php`.** A new
  `admin.token_env` / `admin.token_file` block (already present in
  the shipped `config.php` since v1.8.4 as documentation) is now
  honored at runtime: `AdminAuth::envName()` and `AdminAuth::fileName()`
  read these keys, and every lookup, env-var probe, and diagnostic
  message uses the configured names. The defaults remain
  `OPEN_CORUM_ADMIN_TOKEN` and `.admin.key` for hosts that do not
  override them. The token VALUE is still NEVER stored in `config.php`
  — only the names of the env-var and the key file are.
- **Richer admin diagnostics.** `AdminAuth::diagnostics()` now
  returns `checked` (every project-root candidate AdminAuth probed,
  with basenames + has-key/has-signature flags) and `token_length`
  (length only — never the bytes), so operators can see exactly
  which directories were searched and confirm a non-zero token is
  loaded without ever exposing the secret.
- **`DatabaseHealth::snapshot()` and `/api/health/database`.** A new
  helper (`src/Core/DatabaseHealth.php`) produces a sanitized snapshot
  of the database state — `configured`, `connected`, `schema_ready`,
  `missing_tables`, `present_tables`, parsed DSN host/port/dbname
  (no password), `user_set`/`password_set` booleans, and the last
  PDO error class+sanitized message. The History/Statistic
  controllers and a new `/api/health/database` endpoint surface the
  snapshot so operators can diagnose "Persistent history requires a
  configured database" without unlocking Settings — when the schema
  is incomplete the message names the missing tables explicitly
  rather than directing the user to "Open Settings". `/api/health`
  also embeds the snapshot plus an admin-diagnostics block.

### Changed
- **History/Statistic controllers wire the new health snapshot.**
  Both `status` endpoints include `db_health`; `list` / `summary` /
  `session` map `no_database` and `migration_required` errors to the
  health-derived diagnostic message + the snapshot, so the front-end
  can render an actionable empty state. The existing public-tab
  contract from v1.8.6 (no admin gate for History/Statistic) is
  preserved.
- **`OPEN_CORUM_ADMIN_TOKEN` documentation reaffirmed.** The
  diagnostic strings now lead with the configured env-var name (so
  operators who renamed it in `config.php` see THEIR name) but still
  spell out that the env var is NEVER a `config.php` key — the
  token VALUE belongs in the environment or in the key file, never
  in version control.

### Bumped
- `config.php` → `app.version` to `1.8.11`.

### Tests
- `Run after Reject` regression guard: verifies that after the
  reject path fires, the awaiting promise resolves with
  `reason: 'rejected'` and that `closeActiveRun()` settles the
  promise so a subsequent submit can run.
- `AdminAuth: configurable token_env / token_file` — confirms
  config-driven overrides resolve, fall back when invalid, and that
  diagnostics surface the configured names.
- `DatabaseHealth::snapshot()` — exercises configured/unconfigured/
  connected-with-missing-tables/connected-with-full-schema branches
  on an in-memory SQLite PDO and asserts no secrets leak.
- `schema.sql` integrity — required-table presence check (`skills`,
  `council_sessions`, `council_steps`, `council_model_responses`,
  `pipeline_events`, `model_response_cache`, `vector_embeddings`,
  `schema_migrations`) plus a check that `options_json` and
  `skill_slug` columns are inlined so a fresh import is migration-
  equivalent.

## [1.8.10] — 2026-05-08

### Fixed
- **Run Pipeline failed with `Uncaught SyntaxError: The requested
  module './pipeline.js' does not provide an export named
  'renderFinalAnswer'`.** The deployed `app.js` (which has imported
  `renderFinalAnswer` since v1.8.5) was being served alongside a
  stale `pipeline.js` that lacked the matching `export`, breaking
  the entire ES module graph at load time. The export is in place
  in this release and a regression guard has been added — see the
  smoke test addition below — so any future drift between an
  `import { … } from './x.js'` line and the export list of `x.js`
  fails the build before it ships.

### Added
- **Smoke test: static ES module import/export consistency.**
  `tests/SmokeTest.php` now scans every file in `assets/js/`,
  collects each named export and each named import, and verifies
  that every relative `import { Name } from './target.js'` resolves
  to an actual `export` in `target.js`. The check requires no Node
  toolchain — it runs under plain PHP — and explicitly asserts that
  `pipeline.js` exports `renderFinalAnswer`, `renderStep`,
  `clearPipeline`, `upsertStepFromEvent`, `startActivityTimer`,
  `stopActivityTimer` and `setActivity`.

### Changed
- **`config.php` → `app.version` bumped to `1.8.10`.** The asset
  cache-busting token is `version + max(mtime under /assets)`, so
  the bump (combined with the touched `pipeline.js` / `app.js`
  mtimes from this commit) forces every browser to re-fetch the
  JS/CSS bundle and stop loading the broken `?v=1.8.8.…` snapshot.

### Notes — CSP font error (`FKGroteskNeue.woff2` from `frontend-cdn.perplexity.ai`)
- The browser console error
  `Refused to load the font 'https://frontend-cdn.perplexity.ai/_agi_assets/fonts/FKGroteskNeue.woff2' because it violates the … directive: "font-src 'self' data:"`
  is **not produced by RevoAp**. A repository-wide search for
  `perplexity` / `FKGroteskNeue` / any external font URL across
  PHP, HTML, CSS and JS sources returns zero matches. The font
  request is injected by an external overlay running in the user's
  browser (a browser extension or a Perplexity-affiliated tab/page
  script that touches every document in the tab group), and the
  CSP correctly blocks it.
- **CSP was therefore left intact**:
  `default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self' data:; connect-src 'self'; base-uri 'self'; frame-ancestors 'none'`.
  Allowing `frontend-cdn.perplexity.ai` would weaken the policy
  for no benefit to RevoAp users — the message is harmless to
  the application and resolves itself when the user disables the
  injecting extension.

## [1.8.9] — 2026-05-08

### Fixed
- **Logs / Settings / Security tabs: spurious "Admin token not
  configured. Set OPEN_CORUM_ADMIN_TOKEN or .admin.key first." even
  when `.admin.key` exists at the project root.** Two failure modes
  were collapsing into the same error:
  - **UTF-8 BOM in `.admin.key`.** Editors that "preserve" encoding
    markers prefix the file with `\xEF\xBB\xBF`. The previous loader
    only `trim()`-ed whitespace, so a token saved with a BOM came
    through as `\xEF\xBB\xBFmy-token` and never matched any presented
    value, while the gate refused with the generic "not configured"
    message. `AdminAuth::loadToken()` now strips a leading BOM before
    trimming, and `diagnostics().file.had_bom` reports whether the
    file was BOM-prefixed so the operator can re-save it cleanly.
  - **Project-root resolution under unusual SAPIs.** When the PHP
    process ran with a working directory or document root different
    from the canonical `dirname(__DIR__, 2)` of `AdminAuth.php`
    (symlinked deployments, vendor copies, PHP built-in server
    started from another directory), the lookup could miss an
    existing `.admin.key`. AdminAuth now tries an ordered list of
    candidates — pinned root → `Config::rootDir()` → src-relative
    canonical → `realpath()` of the same → `$_SERVER['DOCUMENT_ROOT']`
    → tree walk → `getcwd()` — and picks the first candidate that
    actually contains a readable `.admin.key`. If no candidate
    carries the key file the helper falls back to the first
    project-shaped directory (`config.php` / `index.php`) for
    diagnostics.
  - **Operator-facing error message.** The "admin not configured"
    response now states explicitly that `OPEN_CORUM_ADMIN_TOKEN` is
    an *environment variable* name (not a `config.php` key), reports
    which root AdminAuth searched (basename only — never the absolute
    path), and surfaces a UTF-8 BOM hint when applicable.

### Added
- **`config.php` → `admin` block (informational only).** The new
  block records WHERE the admin token is read from — `admin.token_env
  = 'OPEN_CORUM_ADMIN_TOKEN'` and `admin.token_file = '.admin.key'`
  — without ever carrying the token value. This documents the
  authentication wiring directly in `config.php` (a frequent
  operator question) while preserving the deliberate design that
  secrets do not live in tracked files.
- **`AdminAuth::diagnostics()` extensions** — non-secret status
  payload now exposes:
  - `env_name` — the literal env variable name expected.
  - `file.had_bom` — whether `.admin.key` started with a UTF-8 BOM.
  - `file.size` — byte size of the file (helps detect 0-byte or
    accidentally-truncated files without revealing token content).
  - `root.basename` and `root.source` — basename of the resolved
    project root and which strategy (`pinned`, `config`,
    `src-relative`, `src-relative-realpath`, `document-root`,
    `walk`, `cwd`, `fallback`) produced it. Absolute paths are
    never included.
- **Smoke tests** (`tests/SmokeTest.php`): new `AdminAuth: BOM + root
  resolution (1.8.9)` block covering BOM tolerance (with and without
  trailing content), CRLF line endings, BOM hint in the
  not-configured message, multi-candidate root resolution
  (pinned-vs-keyed precedence, cwd-based discovery), and the
  informational `admin` config block (asserting no token-shaped key
  ever lands in `config.php`).

### Configuration
- `app.version` bumped to `1.8.9`.
- `src/Core/AdminAuth.php` (1.1.0 → 1.2.0): BOM-tolerant loader,
  multi-candidate `rootInfo()`, richer diagnostics shape.
- `src/Http/Controllers/SettingsController.php` (1.0.0 → 1.0.1):
  `adminNotConfiguredMessage()` rewritten to surface the resolved
  root basename, the env-var-vs-config-key distinction, and a UTF-8
  BOM hint when the file is BOM-only.
- `config.php` (1.0.2 → 1.0.3): docstring documents `.admin.key`;
  new informational `admin` block.

### Operator FAQ
- **Why is `OPEN_CORUM_ADMIN_TOKEN` not in `config.php`?** Because
  `config.php` is tracked in git. Storing the admin token there
  would publish it on every commit. The variable name is documented
  in `config.php → admin.token_env`; the *value* belongs in either
  the process environment (`export OPEN_CORUM_ADMIN_TOKEN=…`) or in
  the git-ignored `.admin.key` file at the project root. The
  AdminAuth gate reads from both, with the env var taking priority.

## [1.8.8] — 2026-05-08

### Added
- **Root-file deployment overrides — `.url` and `.sql.php`.** Two new
  optional files at the project root let operators inject deployment-
  specific data without editing `config.php`. Both are git-ignored so
  secrets and host-specific URLs never reach the repository.
  - **`.url`** — plain text. The trimmed contents replace
    `app.url` at boot. An empty (whitespace-only) file is ignored on
    purpose so an accidentally-blank deployment file cannot wipe the
    configured URL. No PHP wrappers, quotes, or trailing punctuation:
    just the URL on a single line, e.g. `https://opencorum.example.com`.
  - **`.sql.php`** — PHP file with a `return` statement. Two shapes are
    accepted:
    1. Full config fragment:
       ```php
       <?php declare(strict_types=1);
       return [
           'database' => [
               'dsn'      => 'mysql:host=localhost;dbname=opencorum;charset=utf8mb4',
               'user'     => 'root',
               'password' => '',
               'options'  => [
                   PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                   PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                   PDO::ATTR_EMULATE_PREPARES   => false,
               ],
           ],
       ];
       ```
    2. Database fields directly:
       ```php
       <?php declare(strict_types=1);
       return [
           'dsn'      => 'mysql:host=localhost;dbname=opencorum;charset=utf8mb4',
           'user'     => 'root',
           'password' => '',
           'options'  => [
               PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
               PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
               PDO::ATTR_EMULATE_PREPARES   => false,
           ],
       ];
       ```
    Files that do not contain a `return` statement (e.g. a bare array
    fragment) are NOT accepted — there is no `eval()` fallback, by
    design. The result must be an array containing a non-empty `dsn`;
    missing `user`, `password`, or `options` are filled with safe
    defaults. Invalid contents fall back silently to the base config in
    `config.php` so a malformed deployment file never blocks boot. When
    overrides are applied, a non-secret diagnostic string lands in
    `app.diagnostics.config_overrides` (no DSN, user, or password is
    ever logged).
- **`.gitignore`** entries for `/.url` and `/.sql.php` so deployment
  artifacts are never committed.

### Configuration
- `app.version` bumped to `1.8.8`.
- `src/Core/Config.php` (1.0.0 → 1.1.0): `Config::load()` now applies
  root-file overrides before merging `var/config/runtime.json`, so the
  precedence is `config.php` < root-file overrides < runtime overrides.
- `config.php` (1.0.1 → 1.0.2): docstring documents the override files.

## [1.8.7] — 2026-05-08

### Fixed
- **Statistics tab — `HY093` SQL error.** The fallback fetch in
  `StatisticsService::fetchModelRows()` mixed named (`:from`, `:to`)
  and positional (`?`) PDO placeholders in the same prepared
  statement, which PDO rejects with `Invalid parameter number: mixed
  named and positional parameters`. Both placeholder sets are now
  uniformly named (`:skip0`, `:skip1`, …) so every supported time
  range (all-time / year / quarter / week / day / hour) executes
  cleanly. Affected: `src/Statistics/StatisticsService.php`
  (1.0.0 → 1.0.1).
- **History detail pane collapsed to ~0px.** The History tab markup
  shipped two nested elements with the same `oc-history` class — the
  outer `<section>` (rendered by `index.php` / layout template) and
  the inner `<div>` created by `assets/js/history.js` — and the CSS
  applied `display: grid; grid-template-columns: 320px 1fr` to BOTH.
  The outer grid received the inner div as its single grid item,
  pushing it into the first 320px column and squeezing the detail
  pane to zero. The grid is now scoped to `div.oc-history` (so only
  the inner wrapper is laid out as a grid) and the detail pane gets
  explicit `min-width: 0; width: 100%` so a long unbroken token in a
  child block can no longer collapse the implicit min-content width.
  Mobile fallback retained at 900px. Affected:
  `assets/css/components/history.css`.

### Added
- **Pipeline Pause / Continue / Reject controls.** New `Pause` and
  `Reject` buttons next to "Run pipeline". The Pause button toggles
  to "Continue" while paused so a single button drives both
  directions. Behavior:
  - **Pause** records a cooperative flag for the active session id.
    The running PHP worker polls the flag at every safe boundary
    (between L1/L2/L3/L4, before launching new dispatcher rounds,
    and between sequential L1 calls when `pipeline.parallel_l1` is
    off) and blocks there until the user resumes or rejects. SSE
    clients see a `PIPELINE_PAUSED` event when the worker enters
    pause and `PIPELINE_CONTINUED` when it resumes.
  - **Continue** clears the flag — the worker exits its
    `awaitResume()` poll loop and the next layer runs. Idempotent:
    repeated continue clicks are safely ignored.
  - **Reject** is honored at the same checkpoints: the engine
    throws `PipelineRejectedException`, which the run() catch turns
    into a non-fatal terminal state. The session is marked failed
    in `council_sessions`, in-flight steps are finalized, a
    `PIPELINE_REJECTED` SSE frame is emitted and the EventSource is
    closed on the client. The "Reject" button on the frontend also
    eagerly closes the SSE so the UI feels instant — the backend
    still records the rejection on its next checkpoint.
  - **Caveat (in-flight model calls).** PHP's `curl_multi_exec` does
    not provide a way for another HTTP request to abort an
    already-issued chat completion mid-flight. So a pause/reject
    issued during an L1 batch of network calls only takes effect
    when the current batch returns; any responses that landed before
    the user clicked are still kept in the audit trail. This is
    documented inline in `RunControl.php` and surfaced in the
    `/api/council/control` response body.
- **`/api/council/control` endpoint.** Accepts `{session_id, action}`
  with `action ∈ {pause, continue, reject}`. Idempotent. Returns the
  persisted state and a brief description of cooperative semantics.
  Affected: `src/Http/Controllers/CouncilController.php`
  (1.1.0 → 1.2.0), `index.php` (1.0.2 → 1.0.3) — no database
  migration required.
- **`PIPELINE_STARTED` SSE event.** Emitted as the very first event
  of every run, carries `session_id` so the client can target the
  control endpoint before any model call has finished.
- **`App\Pipeline\RunControl`.** New file-backed control store at
  `<project>/runtime/run-control/<session>.json` (atomic writes via
  rename, lock-free reads, 600s pause-poll cap). Files are removed
  in the engine's `finally` block once the run terminates. Works on
  any host whose project directory is writable; database is NOT
  required.

### Configuration
- `app.version` bumped to `1.8.7`.
- New runtime directory `runtime/run-control/` is auto-created on
  the first pause/reject. No new config keys.

## [1.8.6] — 2026-05-08

### Changed
- **History and Statistic tabs are publicly viewable** (no admin token
  required). The single gate is now the `HistoryTab` / `StatisticTab`
  flag in `config.php`. Hosts where session contents or aggregated
  usage data are sensitive should keep the corresponding flag at
  `false`. Sensitive surfaces (Settings, Logs, Security) remain
  admin-protected. Affected: `src/Http/Controllers/HistoryController.php`,
  `src/Http/Controllers/StatisticsController.php`, `assets/js/history.js`,
  `assets/js/statistic.js`.
- **Real-time pipeline streaming.** `ParallelChatDispatcher` now
  exposes a per-completion callback driven by `curl_multi_info_read`,
  and `PipelineEngine::layer1Distribute` consumes it to emit
  `L1_RESPONSE` / `L1_FAILED` the moment each network call returns
  rather than after the slowest model. SSE clients see L1 cards fill
  live as each provider replies. Sequential fallback was refactored
  to use the same code path so behaviour is uniform regardless of
  `pipeline.parallel_l1`. Affected:
  `src/Connectors/ParallelChatDispatcher.php` (1.0.0 → 1.1.0),
  `src/Pipeline/PipelineEngine.php`.

### Added
- **Conflict-resolution arbiter.** When L3 reports a non-agreement
  consensus (or score below the configured threshold), L4 routes the
  raw L1 answers to a dedicated arbiter — by default OpenRouter
  `qwen/qwen-2.5-72b-instruct` — with a bilingual (RU/EN) prompt that
  asks for a best-of combined answer. The Final Answer card prepends
  a clear disclaimer ("models did not reach consensus, but this is
  the most accurate combined answer based on their outputs") and the
  arbiter call is fully accounted for in history / statistics. Falls
  back transparently to the existing synthesis chain when the OpenRouter
  arbiter is unavailable. New config block: `pipeline.conflict_arbiter`
  (`enabled`, `provider`, `model`, `threshold`).
- **Favicon route.** `/favicon.ico` and `/favicon.svg` now serve a tiny
  inline-generated SVG so browsers stop 404-ing on the icon. No external
  asset is shipped; the markup is generated by `serveFavicon()` in
  `index.php`.

### Fixed
- **Pipeline stale pending cards.** `upsertStepFromEvent` now removes
  every prior article carrying a step's `dom_key` before inserting the
  fresh one. Previously the L1 placeholder ("Awaiting model response…")
  could remain in the DOM next to the L1 response card under certain
  ordering scenarios; the new logic guarantees one current card per
  step_id and clears orphan placeholders left over from prior runs.
  Affected: `assets/js/pipeline.js`.
- **Settings dark-theme Models block.** Inputs, checkboxes and the
  recommended-models dropdown inside the Models card now set
  `color: var(--color-text-primary)` so text is readable on dark
  backgrounds. Light theme is unchanged because the same CSS tokens
  resolve to high-contrast values there. Affected:
  `assets/css/components/settings.css`.

### Configuration
- `app.version` bumped to `1.8.6`.
- `pipeline.conflict_arbiter` block added.
- `qwen/qwen-2.5-72b-instruct` enabled in the default model catalog
  (it is the default arbiter; toggle off only if you reconfigure
  `pipeline.conflict_arbiter.model`).

## [1.8.5] — 2026-05-08

### Added
- **Pipeline UX overhaul.** Live pipeline rendering now communicates state
  far more clearly during and after a run.
  - **Current activity card** pinned to the bottom of the pipeline. Updates
    in place from every SSE event so users always see the current stage,
    a live spinner, elapsed time, last update timestamp and a final
    `completed` / `failed` status when the run terminates. Visually
    distinct (dashed accent border, pulse spinner) so it is never confused
    with audit-trail step cards.
  - **Consensus Round headers** group L0/L1/L2/L3/L4 step cards into a
    visual "Consensus Round N" band. The renderer reads `round` from
    the event payload (`payload.round` / `payload.meta.round`) when
    present and falls back to round 1 otherwise — multi-round retries
    can be added by the backend without further frontend changes. Each
    round number is inserted at most once per run.
  - **L3 (Consensus) step is visually distinguished.** Accent border,
    accent-tinted header, dedicated verdict badge in the step header
    (color-coded: agreement → green, partial → amber, conflict → red)
    and a structured response pane: large verdict + score gauge,
    participants/pairs tiles, role chips, "What was evaluated" and
    "Conclusion" sections.
  - **Final Answer is rendered in its own section** outside the
    pipeline (`#final-answer-section`), with stronger accent border,
    Σ badge, "Final Answer" header, and Answer / Statistics / Raw
    tabs. It no longer renders as a regular pipeline step card.

### Changed
- `assets/js/pipeline.js` exports `renderFinalAnswer`, `startActivityTimer`,
  `stopActivityTimer`, `setActivity`, `ensureRoundHeader`,
  `humanActivityLabel`, `consensusStatusClass`. The first call to
  `clearPipeline()` now also resets the round registry and final-answer
  section so multiple runs in the same session render cleanly.
- `assets/js/app.js` drives the activity card lifecycle (start/stop
  timer, success / failure state) and renders the final answer through
  `renderFinalAnswer` instead of appending a synthetic pipeline step.
- `assets/templates/layout.html` adds `<section id="final-answer-section">`
  between the pipeline section and the session stats panel.
- All new DOM is constructed via `createElement` + `textContent` — no
  `innerHTML` is used for any model-supplied content, so a malicious
  L1/L4 response cannot inject markup.
- Assets are still cache-busted via `?v={{asset_version}}` (mtime +
  app.version) so deployments invalidate browser caches automatically.

### Tests
- Smoke tests assert the new exports (`renderFinalAnswer`,
  `startActivityTimer`, `stopActivityTimer`, `setActivity`,
  `ensureRoundHeader`, `humanActivityLabel`, `consensusStatusClass`),
  the activity-label / verdict-class mappings, the new CSS classes,
  the layout-template host element, and that `renderStep({id:'final'})`
  is no longer called from app.js. PHP `php -l` lints the modified PHP
  files.

## [1.8.4] — 2026-05-08

### Fixed
- **Admin tabs no longer report "Admin token not configured" when
  `.admin.key` exists.** `App\Core\AdminAuth` previously asked
  `Config::rootDir()` for the project root, which returned an empty
  string before `Config::load()` had run and silently fell back to a
  noop. Worse, when `.admin.key` was present but the php-fpm user
  could not read it, the gate logged nothing and treated the file as
  absent. Resolution is now independent of Config: AdminAuth derives
  the project root from its own location (`src/Core/AdminAuth.php`)
  and walks up the tree to the directory holding `config.php` or
  `index.php`. It also distinguishes "file missing" from "file
  exists but unreadable" and "file exists but empty".

### Added
- `AdminAuth::diagnostics()` — non-secret status snapshot exposed by
  `/api/tabs`, `/api/settings/status`, `/api/logs/status`,
  `/api/security/status`, `/api/history/status`, and
  `/api/statistics/status`. Shape:
  `{configured, source: 'env'|'file'|'none', env: bool,
    file: {name, exists, readable, empty}}`. Never leaks the token
  value or absolute filesystem paths — only the fixed basename
  `.admin.key`.
- `AdminAuth::setRootDir()` — test seam so unit tests can pin a
  temp directory as the project root.
- `SettingsController::adminNotConfiguredMessage()` — actionable
  error copy. When `.admin.key` exists but is unreadable the message
  now reads "exists but cannot be read by the PHP process. Set
  ownership/mode so php-fpm can read it (e.g. chown www-data
  .admin.key && chmod 600 .admin.key)" instead of the generic
  "set OPEN_CORUM_ADMIN_TOKEN or .admin.key" hint. All five admin
  controllers (`Settings`, `Logs`, `Security`, `History`,
  `Statistics`) emit the helper's output.

### Tests
- `tests/SmokeTest.php` adds an "AdminAuth: .admin.key + diagnostics"
  block (18 new checks): detection from an arbitrary cwd, env beats
  file, empty file ignored, unreadable file surfaces
  `readable=false`, diagnostics never include the token value or an
  absolute path, and `/api/tabs` reports `admin_configured=true`
  when only the key file is present. Total smoke checks: **489
  passing**.

### Documentation
- README: deploy notes for `.admin.key` (project root, `chmod 600`,
  owned/readable by the php-fpm user). Operators must NOT add
  `OPEN_CORUM_ADMIN_TOKEN` to `config.php`.

## [1.8.3] — 2026-05-08

### Fixed
- **`db/schema.sql` is once again the single source of truth for a fresh
  install.** Earlier releases shipped the v1.0.0 schema and asked
  operators to layer migrations 002..005 on top to reach the current
  shape; that was easy to get wrong on a `git clone` + `mysql <
  db/schema.sql` boot. The consolidated schema now declares the
  `MEDIUMTEXT` `council_steps.summary` (002), the
  `model_response_cache` table (003), the `council_sessions.skill_slug`
  / `options_json` columns and the `ft_sessions_search` FULLTEXT plus
  `idx_sessions_created_at` / `idx_sessions_skill_slug` indexes (004),
  and the `idx_steps_session_step_key` / `idx_steps_started_at`
  indexes (005). The `schema_migrations` tracker table is also created
  up-front so a fresh import lines up byte-for-byte with the
  incremental path. Each individual migration file remains in
  `db/migrations/` and the `MigrationManager` continues to be the
  authoritative upgrade path for existing databases.

### Documentation
- README's **Quick Start** now distinguishes a fresh install
  (`mysql < db/schema.sql` + admin "Baseline migrations") from an
  upgrade (`mysql < db/migrations/<file>.sql` for each pending file,
  or admin "Apply pending"), with copy-paste commands for both.

### Tests
- `tests/SmokeTest.php` adds a schema-consolidation block (16 new
  checks): asserts `db/schema.sql` declares every column / index /
  table introduced by migrations 002..005, that every expected
  `db/migrations/*.sql` file is still on disk, and that
  `MigrationManager::splitStatements()` parses the consolidated schema
  cleanly. Total smoke checks: 471 passing.

## [1.8.2] — 2026-05-08

### Fixed
- **Top-nav tabs render whenever their `*Tab` flag is `true`, even if
  the admin token isn't configured yet.** Previously the front-end
  hid Settings / Logs / Security / History / Statistic until BOTH the
  config flag was true AND `admin_configured` was reported by the
  matching `/api/<tab>/status` probe. With a fresh checkout (no
  `OPEN_CORUM_ADMIN_TOKEN` / `.admin.key` yet) the tabs never appeared,
  so operators couldn't see how to enable the feature. Each per-tab
  client (`assets/js/{settings,logs,security,history,statistic}.js`)
  now keys tab visibility solely on the `tab_enabled` flag from the
  status endpoint and surfaces a clear "Admin token not configured"
  notice inside the tab body when authentication is still missing.
- **`opencorum-debug.log` now actually receives lifecycle events when
  `app.debug=true`.** The Logger was wired up correctly but the
  pipeline never called `Logger::info()` / `Logger::warn()` for the
  events operators expect, so the file was created and stayed empty.
  `PipelineEngine` now logs `pipeline.start`, `pipeline.done`,
  `pipeline.fatal`, `L1.response[.cache_hit]`, `L4.synthesis`, and
  `L4.provider.fallback` with sanitized payloads (no secrets, no
  filesystem paths, no tokens). `/api/debug` additionally emits a
  one-line `debug.report.requested` record so a single curl call
  confirms the logger is live.
- **L1 and L4 step events now carry the FULL model output**, so the UI
  Response tab no longer truncates at ~240 chars. `L1_RESPONSE` and
  `L4_SYNTHESIS` SSE payloads include both the verbatim `content`
  field (full model output) and the existing compact `preview` (kept
  for history list views). The front-end pipeline renderer renders
  `content` first, then `summary`, then `explanation`, and finally
  `preview` as a last-resort fallback.
- **L2 cards no longer show "(awaiting full response from session
  events)".** The `L2_NORMALIZED` payload now embeds the full detailed
  `summary` / `content` / `explanation` text, the structured
  `claims_count` / `claim_previews`, the `source` block (model,
  provider, role, raw answer length), and the normalization
  `strategy`. The persisted L2 step row keeps its rich audit text.

### Added
- `/api/tabs` now returns a structured `tabs` map plus
  `admin_configured` and `admin_unlocked` flags so the front-end can
  render a complete navigation/empty-state pair from a single probe.
  Backwards-compatible: the flat `settings`/`logs`/`security`/
  `history`/`statistic` keys remain in the response.

### Notes
- L4 provider fallback (1.8.1) is preserved verbatim — the new logger
  call simply records the fallback decision so `/api/debug` users can
  trace it without enabling SSE.

## [1.8.1] — 2026-05-08

### Fixed
- **L4 synthesis no longer fails when the configured provider is
  inactive.** Previously, if `pipeline.synthesis_provider` pointed at a
  connector marked `active=false` (or otherwise unavailable), L4
  emitted `No connector for provider 'X': marked inactive` and
  short-circuited to the apology fallback even when an active
  connector (e.g. OpenRouter) was available and the user had selected
  OpenRouter models. `PipelineEngine::layer4Synthesis()` now resolves
  the effective synthesis target through a deterministic chain:
  configured provider → first available selected model (preferring
  OpenRouter) → configured OpenRouter active model from
  `config.models` → first available active model → existing apology
  fallback when nothing is reachable.

### Added
- `L4_PROVIDER_FALLBACK` event (and matching trace row) emitted
  whenever the effective synthesis provider/model differs from the
  configured one. Payload carries `configured_provider`,
  `configured_model`, `effective_provider`, `effective_model`, and a
  `reason` describing why the configured target was rejected. No
  secrets are logged.
- L4 step `meta` now records `configured_provider` and
  `configured_model` alongside the effective `provider`/`model`, so
  the UI / `/api/debug` / history viewer can show "configured X →
  effective Y" without a separate query.

## [1.8.0] — 2026-05-08

### Added
- **Admin Statistic tab** — aggregated usage of the council pipeline.
  - Period selector: `All` / `Year` / `Quarter` / `Week` / `Day` /
    `Hour`. Year and quarter use calendar boundaries; week/day/hour
    are rolling windows from the current server time. Tests pass an
    explicit anchor for deterministic period math.
  - Top-card summary: session counts (`total`/`completed`/`failed`/
    `running`), total + average duration, total + average latency
    across every LLM call, prompt / completion / total / cached /
    reasoning tokens, cache-hit counts, and provider-reported cost.
    `cost.total` and `per_model[].cost` are returned as `null` when
    no row in the group carried a numeric provider cost — the UI
    renders that as `—`, never `$0.00`.
  - Detailed per-LLM breakdown table grouped by `(provider, model)`:
    `calls`, `success`/`failed`, `prompt_tokens`, `completion_tokens`,
    `total_tokens`, `cached_tokens`, `reasoning_tokens`,
    `cache_hits`, `estimated_rows`, `total_latency_ms`,
    `avg_latency_ms`, `cost`, `avg_cost`. Sorted by call count desc.
  - Free-form fields are not surfaced (statistics are numeric); the
    diagnostic `message` returned when a query fails is run through
    `Logger::sanitizeScalar` and never exposes admin tokens / DSN
    secrets.
- `StatisticTab` config flag (top-level or nested under `app.*`).
  Default is `false` because the metrics describe operational and
  per-user usage. Strict comparison: only boolean `true` enables.
- `App\Core\Config::isStatisticTabEnabled()` resolver; wired into
  `/api/tabs` (which now returns `{settings, logs, security,
  history, statistic}`).
- `App\Statistics\StatisticsPeriod` — pure helper that maps a period
  token + optional anchor to a `[from, to]` DATETIME pair.
- `App\Statistics\StatisticsAggregator` — pure aggregation function
  operating on pre-fetched session + per-call rows. Sums tokens,
  counts statuses and cache hits, computes per-model breakdowns,
  averages duration / latency over rows that actually reported
  values, leaves cost null when no row carried it.
- `App\Statistics\StatisticsService` — read-only access to existing
  history tables (`council_sessions`, `council_steps.meta` JSON, with
  fallback to `council_model_responses`). Returns a `no_database`
  envelope when the DSN is unconfigured.
- `App\Http\Controllers\StatisticsController` — new endpoints under
  `/api/statistics/*`. Every protected endpoint is gated by **both**
  `StatisticTab=true` AND `AdminAuth::check()`.
- `assets/js/statistic.js`, `assets/css/components/statistic.css`,
  and a `Statistic` tab in the top navigation. Free-form values are
  rendered via `textContent` only — never `innerHTML`.
- `db/migrations/005_statistics_indexes.sql` — adds
  `idx_sessions_created_at` (when missing),
  `idx_steps_session_step_key`, and `idx_steps_started_at`.
  Idempotent (each `CREATE INDEX` guarded by an
  `information_schema` existence check); safe to re-run on hosts
  that have or have not applied migration 004.

### Changed
- Application version bumped to `1.8.0` in `config.php`.
- `/api/tabs` aggregator now exposes `statistic` alongside the
  existing four flags.

### Deploy

```bash
git pull origin main
mysql -u root opencorum < db/migrations/005_statistics_indexes.sql
# Edit config.php → set 'StatisticTab' => true (top-level or under 'app').
curl -s http://localhost:8080/api/tabs | jq '.statistic'   # → true
curl -s -H "X-Admin-Token: $OPEN_CORUM_ADMIN_TOKEN" \
     "http://localhost:8080/api/statistics/summary?period=day" | jq '.ok'
# → true
```

## [1.7.0] — 2026-05-08

### Added
- **Admin History tab** — browse every past council session in the UI.
  - Server-paginated list (max 100 per page) with year/month grouping.
  - Search across `query_text`, `final_answer` and `skill_slug`;
    status filter (`completed` / `running` / `failed`).
  - Per-session detail view with the original prompt, the synthesised
    final answer, every L0/L1/L2/L3/L4 step, every per-step model
    response (raw + normalized), every event and the consensus result.
  - Runtime options snapshot (selected models, reasoning preset,
    parallel\_l1) captured at run-time so a session can be replayed
    exactly as it was issued.
- `HistoryTab` config flag (top-level or nested under `app.*`). Default
  is `false` because the tab surfaces past prompts and model output in
  plaintext. Strict comparison: only boolean `true` enables the tab.
- `App\Core\Config::isHistoryTabEnabled()` resolver; wired into
  `/api/tabs` alongside the other tab flags.
- `App\History\HistoryService` — read-only repository for the History
  tab. Returns paginated lists, year/month group counts and full
  session detail. Every free-form text field is run through
  `Logger::sanitizeScalar` before serialization.
- `App\Http\Controllers\HistoryController` — new endpoints under
  `/api/history/*`. Every protected endpoint is gated by **both**
  `HistoryTab=true` AND `AdminAuth::check()`. Returns clear
  `no_database` / `migration_required` diagnostics when the host has
  no DSN or has not applied migration 004.
- `assets/js/history.js`, `assets/css/components/history.css`, and a
  dedicated `History` tab in the top navigation. Free-form text is
  rendered via `textContent` only — never `innerHTML`.
- `db/migrations/004_history_session_metadata.sql` — adds
  `council_sessions.skill_slug`, `council_sessions.options_json`,
  the `idx_sessions_created_at`, `idx_sessions_skill_slug`, and
  `ft_sessions_search` indexes. Idempotent — safe to re-run.
- `App\Storage\SessionRepository::setMetadata()` — best-effort
  persistence of skill slug + runtime-options snapshot. Older deploys
  that have not applied migration 004 keep running because the
  UPDATE silently no-ops when the columns are missing.

### Changed
- `config.php` bumped to `app.version = 1.7.0`. The default config
  now lists `HistoryTab` (top-level + nested) at `false`.
- `PipelineEngine::run` calls `SessionRepository::setMetadata` right
  after `create()` so every new session captures the operator's
  selection regardless of whether the run later fails.

### Security
- `HistoryTab` defaults to `false` — operators must explicitly opt in.
- When the flag is disabled, every `/api/history/*` endpoint returns
  `404 history_disabled` so probes leak no information.
- Endpoints additionally refuse with `503 admin_not_configured` when
  no admin token is configured, and `401 unauthorized` when a token
  is required but not presented.
- All free-form payloads are sanitized via `Logger::sanitizeScalar`
  before they leave the host (Bearer tokens, DSN passwords, sk-…
  keys, query-string secrets, absolute paths).

### Tests
- New `SmokeTest` cases: HistoryTab flag honors top-level / nested /
  strict-true comparison, `HistoryController::status` shape,
  `HistoryController::list` returns `history_disabled`,
  `admin_not_configured` and `unauthorized` in the documented
  conditions, `HistoryController::session` returns 404 for missing
  session ids with valid auth and `history_disabled` when the flag
  is off.

## [1.6.0] — 2026-05-07

### Added
- **Admin Security tab** — an in-app IP firewall editor. Operators can:
  - toggle whitelist enforcement (only listed IPs may reach the app),
  - toggle blacklist enforcement (listed IPs are always denied),
  - paste IP / CIDR rules for IPv4 and IPv6 (validated and normalized),
  - configure trusted reverse proxies for safe `X-Forwarded-For` handling,
  - dry-run any IP against the current ruleset,
  - reset the entire policy to safe defaults,
  - inspect a runtime checklist (HTTPS detected, debug mode, `var/`
    writable, security headers, admin token configured, sibling tabs).
- `SecurityTab` config flag (top-level or nested under `app.*`). Default
  is `false` — the firewall is opt-in. Strict-true semantics, identical
  to `SettingsTab` / `LogsTab`. `Config::isSecurityTabEnabled()` is the
  single resolver used by every gate.
- `App\Security\IpMatcher` — IPv4 / IPv6 / CIDR validator and matcher.
  Cross-family rules never match (IPv4 rule cannot deny an IPv6
  address). Loopback recognition (`127.0.0.0/8`, `::1`) is exposed via
  `IpMatcher::isLoopback()`.
- `App\Security\SecurityStore` — persistent state under
  `var/config/runtime.json[security]`. Always re-asserts the loopback
  rules in the whitelist; silently strips loopback entries from the
  blacklist (anti-lockout invariant).
- `App\Security\ClientIp` — request-time client IP resolver. Defaults
  to `REMOTE_ADDR`; honours `X-Forwarded-For` ONLY when
  `trust_proxy_headers=true` AND `REMOTE_ADDR` matches one of the
  configured `trusted_proxies` rules.
- `App\Security\IpFirewall` — pure decision function (`decide()`) +
  request-lifetime gate (`enforce()`). Loopback always allowed,
  blacklist before whitelist. `enforce()` writes a sanitized
  `ip_firewall.denied` line to the application logger and `exit`s with
  HTTP 403 JSON before any controller resolves.
- `App\Http\Controllers\SecurityController` — `/api/security/*`:
  - `GET  /api/security/status`   — public probe; when admin-authenticated
    also returns the live state, the detected IP, and auto-adds that IP
    to the whitelist (idempotent).
  - `POST /api/security/save`     — replace the security state.
  - `POST /api/security/test-ip`  — dry-run match.
  - `POST /api/security/reset`    — restore safe defaults.
- `GET /api/tabs` now also returns `security`, alongside `settings` and
  `logs`, so the SPA can probe all three flags in one request.
- `assets/js/security.js` + `assets/css/components/security.css` — UI.
  Renders only sanitized text via `textContent`; surfaces a lockout
  warning banner when whitelist enforcement is on but the current IP
  is not yet covered (server still enforces auto-add on save).
- `docs/Security.md` — operator documentation: visibility flag, decision
  order, client-IP detection rules, recovery procedure if a misclick
  ever locks the operator out.
- 50+ new smoke tests covering: IPv4 + IPv6 + CIDR validity and
  matching, cross-family safety, loopback recognition, IPv6
  canonicalization, store normalization (loopback rules forced into
  whitelist + stripped from blacklist), the four firewall verdicts,
  blacklist-over-whitelist precedence, the loopback-cannot-be-blacklisted
  invariant, `ClientIp` proxy-trust rules, the controller gate when
  `SecurityTab=false` / no admin / wrong token, and the auto-add-current-IP
  flow on `GET /api/security/status`.

### Security
- The IP firewall enforces **before** any controller code runs. Denials
  are logged through the application logger (sanitized) and return a
  flat HTTP 403 JSON. No path, no header, no query value reaches the
  router on a denied request.
- Loopback addresses are protected at multiple layers: store
  normalization forces them into the whitelist on every save and
  strips them from the blacklist; the firewall short-circuits to
  `allow` for any loopback IP regardless of the configured rules. An
  operator cannot lock themselves out from `localhost`.
- `X-Forwarded-For` is **never** blindly trusted. The trust path
  requires both an explicit toggle AND a matching `trusted_proxies`
  entry. When neither holds, only `REMOTE_ADDR` is consulted.
- `var/config/runtime.json` is written with `0600` permissions and is
  git-ignored.
- `SecurityTab` defaults to `false` — operators must explicitly opt in.
  When off, the four endpoints return `404 security_disabled` so
  probes leak no information.

### Changed
- `config.php` bumped to `app.version = 1.6.0`. The default config now
  lists `SecurityTab` (top-level + nested) at `false`.
- `index.php` calls `IpFirewall::enforce()` immediately after
  `Application` boots (before any route is registered).

## [1.5.0] — 2026-05-07

### Added
- **Config-driven tab visibility** — two new flags in `config.php`, accepted both at the top level and under `app.*`:
  - `SettingsTab` (default `true`) — controls the admin Settings tab.
  - `LogsTab` (default `false`) — controls the new admin Logs tab.
  Comparison is strict (`=== true`), so accidental truthy values (`'yes'`, `1`) do NOT enable a tab. Top-level form wins when both layers are set.
- `App\Core\Config::tabFlag() / isSettingsTabEnabled() / isLogsTabEnabled()` — single-source-of-truth resolvers used by every gate.
- **Admin Logs tab** — an in-app log viewer. Operators pick a file from a whitelisted list, see size + mtime, set the tail size, refresh on demand, toggle 5-second auto-refresh, and (with confirmation) truncate the file in place. Content is rendered with `textContent` ONLY — never `innerHTML`.
- `App\Logs\LogsService` — read-only access layer. Whitelists `opencorum-debug.log / php-error.log / access.log`, refuses unknown slugs, verifies the resolved real path stays inside the project logs directory, and tails efficiently from the end of the file in 8 KiB chunks (no full-file reads). `lines` is clamped to `[1, 5000]`.
- `App\Http\Controllers\LogsController` — `/api/logs/*` endpoints. Every protected endpoint is gated by **both** `LogsTab=true` AND `AdminAuth::check()`.
  - `GET  /api/logs/status` — public probe (`tab_enabled / admin_configured / admin_unlocked`).
  - `GET  /api/logs/list`   — admin — file metadata.
  - `GET  /api/logs/tail`   — admin — `?slug=…&lines=…`, sanitized content.
  - `POST /api/logs/clear`  — admin — truncates one whitelisted file.
- `GET /api/tabs` — aggregated config-flag probe used by the front-end at boot to decide which top-nav tabs to render.
- `assets/css/components/logs.css` + `assets/js/logs.js` — UI.
- `docs/Logs.md` + `src/Logs/Logs.md` — operator + module documentation.
- 33 new smoke tests covering: tab-flag precedence and strict-true semantics, `SettingsController` returning `404 settings_disabled` when `SettingsTab=false`, `LogsService` whitelist + path-traversal rejection + tail sanitization (Bearer / `sk-…` / DSN passwords / `?api_key=…`) + clear semantics, and `LogsController` gate (admin not configured / wrong token / disabled flag).

### Security
- Log lines reach the wire only after `Logger::sanitizeScalar()` — the same redactor that protects the structured debug logger. API keys, Bearer tokens, DSN credentials, and absolute paths are never displayed.
- `LogsTab` is `false` by default. Operators must explicitly opt in. When disabled, every `/api/logs/*` endpoint returns `404 logs_disabled` so probes leak no information.
- Path traversal cannot reach files outside the logs directory — the slug is matched against a fixed whitelist, and the resolved real path is verified to be within the configured logs directory.
- `Clear` is admin-only, requires a `POST` (not idempotent), and the UI requires an explicit confirmation dialog.

### Changed
- `config.php` bumped to `app.version = 1.5.0`. The default config now lists `SettingsTab` and `LogsTab` (both `true` for the bundled dev preset; production deployments should set `LogsTab=false` if not needed).
- `GET /api/settings/status` now returns `tab_enabled` so the front-end can hide the Settings tab when `SettingsTab=false` even if the admin token is configured.
- `assets/js/settings.js` honors the new `tab_enabled` field; older builds without the field continue to work (default `true`).

## [1.4.0] — 2026-05-07

### Added
- **Admin Settings tab** in the top navigation — manages provider keys, SQL DSN, active model list, migrations, and runtime flags from the browser. Hidden when no admin token is configured.
- `App\Core\AdminAuth` — token gate accepting `X-Admin-Token` header, `Authorization: Bearer`, `?admin_token=` query, or `oc_admin` HttpOnly cookie set after a successful unlock. Token is read from `OPEN_CORUM_ADMIN_TOKEN` env or `.admin.key` file; comparison uses `hash_equals`.
- `App\Core\RuntimeConfig` — persistent operator overrides at `var/config/runtime.json`, merged on top of `config.php` at boot via `Config::load()`. Saved settings take effect on the next request. Provider keys are written to `.{provider}.key` (0600) instead of the JSON.
- `App\Settings\ProviderTester` — non-destructive probes for OpenRouter (`/key` with `/models` fallback) and BotHub (`/models`). Returns sanitized status + model count.
- `App\Settings\DatabaseDsn` — parse / build a MySQL PDO DSN from individual fields and run a `SELECT 1` connectivity test through `App\Core\ErrorDiagnostics`. Passwords never appear in responses.
- `App\Settings\MigrationManager` — list / apply / baseline `db/migrations/*.sql` against a `schema_migrations` tracker table. "Already exists" / "duplicate column" errors are treated as benign so re-runs are safe. Includes a portable `SHOW CREATE TABLE` schema export.
- `App\Settings\PopularModels` — curated top-10 recommendations per provider, intersected with the live OpenRouter catalog so retired IDs are not surfaced.
- `App\Http\Controllers\SettingsController` — `/api/settings/*` endpoints. Every protected endpoint requires the admin token; `/api/settings/status` is public and only reports whether Settings is configured + unlocked.
- 39 new smoke tests covering AdminAuth masking + gate, RuntimeConfig persistence + dot-notation updates, DSN parse/build, MigrationManager statement splitter, PopularModels recommendations, and the SettingsController gate.
- `docs/Settings.md` — operator guide.

### Security
- `var/` and `.admin.key` are git-ignored. Settings endpoints respond `503 admin_not_configured` when no token is provisioned, so probes leak no information.
- `GET /api/settings` returns masked fingerprints for API keys (`sk-o••••••••cdef`) and never echoes the database password.

## [1.3.0] — 2026-05-07

### Fixed
- **Active connectors / Consensus models selectors overflowed the sidebar column horizontally and showed mostly the long `openrouter/bothub/…` provider prefix instead of the model id.** The selector is now constrained to 100% of the sidebar column width, the closed-state label shows the model id with ellipsis truncation, and the provider is rendered as a small muted badge to the left. On focus / mousedown the selector lifts into an overlay (`z-index: 50`, `width: max-content`, capped at `min(90vw, 640px)`) so every full model id is readable while choosing without breaking layout. Mobile keeps the constrained behavior.

### Added
- `App\Core\ErrorDiagnostics` — central classifier for database and connector errors. Public surface:
  - `parseDsn(string)` — returns `driver / host / port / dbname / unix_socket`. Never reads or returns the DSN password.
  - `describeDatabaseError(?Throwable, $extra)` — emits `category / class / operation / driver / host / port / dbname / user / sqlstate / vendor_code / message / hint`. Recognizes MySQL/MariaDB codes 1045, 1049, 2002, 2006, 2013 and SQLSTATE 28000 / 08006 / 08001 with actionable hints.
  - `describeConnectorError(?Throwable, $extra)` — emits `category / class / provider / model / endpoint_host / endpoint_path / endpoint_scheme / http_status / request / message / hint`. Recognizes HTTP 401 / 402 / 403 / 404 / 408 / 429 / 5xx with actionable hints. Never carries the Authorization header or the API key.
  - `filterRequestOptions($opts)` — allow-list strip of request knobs to `model / reasoning / temperature / top_p / max_tokens / max_completion_tokens / stream / timeout / retry_count / max_retries / response_format / stop / tool_choice`.
  - `terse($context)` — one-line, safe production message used when `app.debug=false`.
- `Diagnostics::recordDatabaseError($e, $operation)` — now also stores a structured `error_context` (via `ErrorDiagnostics`). When `app.debug=true`, `/api/debug` includes `database.diagnostics` with the structured block; in production it is omitted and only the terse `error_message` line stays.
- `PipelineEngine` — when `app.debug=true`, every `L1_FAILED`, `L4_FAILED`, and `PIPELINE_FATAL` event carries a `diagnostics` block describing the connector or DB context (provider, model, endpoint host/path, HTTP status, sanitized request options, classification hint). In production, the message is replaced with a safe terse line and no structured block is emitted.
- 38 new smoke tests covering: DSN parsing for `mysql / pgsql / sqlite`, password-leak negative checks, hint copy for 1045 / 1049 / 2002 / 2006 / 2013, hint copy for HTTP 401 / 402 / 404 / 429 / 5xx, request-options allow-listing, `Logger` redaction of `pwd / access_token / private_key / sk-… / ?api_key=…`, and the debug-mode toggle for `database.diagnostics` in `/api/debug`.

### Changed
- `Logger::sanitizeScalar()` now also redacts standalone `sk-…` API keys and `?api_key=… / &token=…` URL query secrets.
- `Logger::isSecretKey()` extended with `pwd / access_token / refresh_token / private_key`.
- `app.version` bumped to `1.3.0`.

### Verifying
- UI: open `/`, observe that selectors stay inside the sidebar column. The visible label shows the model id (`gpt-4o-mini`, `anthropic/claude-3.5-haiku`, …) and the provider sits as a small badge. Click the selector; it expands above the layout, showing every full id in the dropdown.
- Debug: set `'app' => ['debug' => true]` in `config.php`, hit `GET /api/debug`. With a misconfigured DSN, the `database.diagnostics` block now contains driver / host / port / dbname / sqlstate / vendor_code / hint. With a missing connector, the `L1_FAILED` SSE frame contains the `diagnostics` block with provider / model / endpoint_host / hint. Toggle `debug=false` and verify the structured block disappears.

## [1.2.0] — 2026-05-07

### Fixed
- **All L1 steps stayed RUNNING and the pipeline aborted with `PIPELINE_FATAL: SQLSTATE[HY000]: General error: 2006 MySQL server has gone away`.** Long parallel L1 + L4 calls held the PDO handle idle past MySQL's `wait_timeout`. v1.2.0 ships `App\Core\Database`, a resilient wrapper that retries each repository call once after a "MySQL server has gone away" / "Lost connection" / `2006` / `2013` error. `SessionRepository`, `StepRepository`, `ResponseRepository` now route every read/write through it.
- **Running steps were left as RUNNING in the UI when a fatal aborted the pipeline.** `PipelineEngine::run()` now calls `finalizeRunningSteps()` in its catch path: every still-running step is marked failed with a readable summary AND a `L1_FAILED` / `L4_FAILED` / `STEP_FAILED` event is emitted so the live UI replaces stale cards. `CouncilController::stream()` mirrors the same path defensively when a throw escapes the engine.
- **EventSource auto-reconnected after fatal/done events, causing duplicate `/api/council/stream` GETs.** `assets/js/app.js` now closes the active `EventSource` before opening a new one, on every terminal `PIPELINE_DONE` / `PIPELINE_FATAL`, and on `pagehide`/`beforeunload`. Stale events from a superseded run are dropped client-side. The synchronous `/api/council/run` fallback is only used when the SSE stream fails BEFORE delivering any pipeline event, so the pipeline cannot be run twice.

### Added
- `App\Core\Database` — resilient PDO with `run(callable)` that reconnects + retries on idle disconnects. Records reconnect / retry counts for diagnostics.
- `App\Cache\ModelResponseCache` — sha256-keyed (provider + model + messages + reasoning + temperature/max_tokens) cache for successful chat responses. Backends: MySQL `model_response_cache` table (DB) + `var/cache/model-responses/<sha>.json` (file fallback). Hits surface as `cache_hit=true` with the full key, the `cache_short_key=sha256(model+query)` for the user-facing tooltip, the original creation timestamp, and the per-key hit count, in the `L1_RESPONSE` / `L4_SYNTHESIS` SSE events and in `council_steps.meta`. Failed responses are NEVER cached. API keys are NEVER part of the key or stored value.
- `db/migrations/003_model_response_cache.sql` — production migration for the cache table. The cache also self-installs the table via `CREATE TABLE IF NOT EXISTS` on first use.
- `cache.model_responses.{enabled,ttl_seconds}` config — toggle and TTL for the cache (0 = no expiry).
- `Diagnostics::routesReport()` — the `/api/debug` envelope now lists the routes the deployed build is expected to expose (including `/api/council/stream`), the build_id, and operator hints for the three most common deploy issues (route 404, SSE buffering, MySQL gone-away). Operators can verify a stale deployment by curling `/api/debug` and checking the `build_id` against the latest `git rev-parse HEAD`.
- `Diagnostics::cacheReport()` — cache backend snapshot (DB / filesystem availability, entry count, total disk usage) in `/api/debug`.
- `StepRepository::failRunningSteps(int, string)` and `listRunningSteps(int)` — used by the fatal path; also exposed for tests.
- 30 new smoke tests covering: gone-away classification, cache key + hydrate roundtrip, `failRunningSteps`/`listRunningSteps`, `PipelineEngine::finalizeRunningSteps` event emission, `Diagnostics` routes/cache sections.
- `docs/Storage.md` — resilience, fatal handling, MySQL `wait_timeout` guidance, cache invalidation.

### Changed
- `App\Core\Application` exposes `database(): ?Database` alongside `pdo(): ?PDO`. `index.php` builds repositories with the `Database` wrapper when available so retries work end-to-end.
- `PipelineEngine` accepts an optional `ModelResponseCache` and emits `cache_hit=true` (with the full + short keys) on every cached L1/L4 response.
- `app.version` bumped to `1.2.0`.

## [1.1.0] — 2026-05-07

### Fixed
- **Only 2 L1 model invocations regardless of how many models were active.** L1 was driven by the *number of skill roles* (e.g. `user`/`assistant`), not by the consensus pool. v1.1.0 drives L1 from the user-selected model list. When the skill defines fewer roles than the selected pool has models, additional consensus participants are minted from the first role's prompt template so every selected model is queried once.

### Added
- **Per-query consensus model selection.** The sidebar now hosts a `ModelSelector` widget that lists 3 default models, lets the user swap each via a dropdown, remove any row with the `×` button, and add another model with the `+ add model` selector. Selection persists in `localStorage` (`oc.selected_models.v1`).
- `pipeline.default_selected_models` config — defaults the consensus pool to 3 popular OpenRouter models.
- `POST /api/council/run` accepts `selected_models: [{provider, model}, …]` and `reasoning: "off"|"low"|"medium"|"high"` in its JSON body. Models that aren't in `config.models` (with `active=true`) or whose connector has no API key are rejected and surfaced in the run trace under `SELECTED_MODELS_RESOLVED.rejected[]`.
- **Server-Sent Events streaming endpoint** at `GET /api/council/stream?query=…&selected_models=…&reasoning=…`. The pipeline emits one SSE frame per pipeline event (`L0_SKILL`, `L0_OPTIONS`, `L1_STEP_STARTED`, `L1_RESPONSE`, `L1_FAILED`, `L2_NORMALIZED`, `L3_CONSENSUS`, `L4_SYNTHESIS`, `PIPELINE_DONE`, `PIPELINE_FATAL`) so the UI renders steps as they complete. Frontend uses `EventSource` and falls back to `POST /api/council/run` when SSE is unavailable.
- **Parallel L1 dispatch via `curl_multi`** in `App\Connectors\ParallelChatDispatcher`. Every selected model is invoked concurrently; the dispatcher returns one row per request preserving caller-supplied tags. Falls back to sequential calls when `pipeline.parallel_l1=false` or when the connector is not HTTP-based.
- **Reasoning / thinking** request option. `App\Pipeline\PipelineEngine` translates the user's preset (`off`/`low`/`medium`/`high`) into the OpenRouter `reasoning: {effort: …, exclude: false}` request object, attaches it to L1 + L4 chat options, and lifts any provider-emitted `reasoning` text from the response into `meta.reasoning_text` for safe display. The `/api/connectors` envelope advertises the available levels under `reasoning_levels`.
- **Hover tooltips on every layer/event header chip** describing what the stage does (`L0/L1/L2/L3/L4/Σ`) and what each event type means (`L0_SKILL`, `L1_STEP_STARTED`, `L1_RESPONSE`, `L1_FAILED`, `L2_NORMALIZED`, `L3_CONSENSUS`, `L4_SYNTHESIS`, `PIPELINE_DONE`, …).
- **Detailed L0_SKILL payload** — slug, name, description, tags, full role list (key + label + first 600 chars of system prompt), synthesis prompt, output_schema flag, and full `definition` JSON for the Raw tab. The frontend renders this as a structured skill card via `pipeline.js#skillPane()`.
- **Detailed L2 message** — surfaces source role/model/provider/length, normalization shape, strategy (sentence split / JSON projection), claim count, a 3-claim preview, and the downstream L3 usage. `meta.source` carries the structured form for the Raw tab.
- **Default `consensus-reasoning` skill** at `src/Skills/definitions/consensus_reasoning.json` — implements the user-supplied flow (analyst, domain expert, adversarial reviewer, pragmatist, peer reviewer with self-review). Used as a sensible default for queries that don't match a more specific skill.
- `pipeline.parallel_l1` config flag (default `true`) — set to `false` to force sequential L1 dispatch.
- 33 new smoke tests covering: `default_selected_models` shape, parallel L1 dispatch via the dispatcher, request-supplied selected models, rejection of unknown models, `L0_OPTIONS` event payload, `L0_SKILL` event detail, `AbstractConnector::buildChatRequest()` + `buildChatResponseFromRaw()`, SSE frame format, default skill load, L2 detail message, layout selector root.

### Changed
- `pipeline.max_roles_per_skill` default raised from 5 → 12 so a skill can scale to a larger consensus pool.
- `PipelineEngine::trace()` now records `SELECTED_MODELS_RESOLVED` (with `accepted` / `rejected` / `source`) in place of the legacy `L1_MODEL_SELECTION` event.
- `PipelineEngine::run()` signature gains a third `array $options = []` parameter (`selected_models`, `reasoning`) and returns an additional `selected_models` entry. Called by `CouncilController::run()` and `CouncilController::stream()`.

## [1.0.6] — 2026-05-07

### Fixed
- **L3 step rendered as a one-line phrase such as `Significant disagreement between roles.`**, leaving operators asking "что решается и какой результат". The pipeline now generates a structured, human-readable consensus explanation that states (a) what was evaluated — normalized claims from each L1 role, (b) participants — role keys and count, (c) decision — status / score / score-percent / pair count, and (d) the result — `consensus reached`, `partial agreement`, or `no consensus (treat L4 output as fallback synthesis)`.

### Added
- `App\Consensus\ConsensusExplainer::explain(ConsensusResult, array $roleKeys = [])` — produces both a multi-line `text` block (used as the L3 step `summary`) and a structured payload (`participants`, `decision`, `conclusion`, `raw`) embedded under `meta.explanation` and inside the `L3_CONSENSUS` event. Raw consensus JSON (`score`, `status`, `summary`, `meta`) is preserved verbatim under `meta.consensus` and at the top of the event payload, so the Raw tab keeps the machine-readable shape.
- `pipeline.js` renders the L3 explanation as a structured DOM tree using `textContent` only (no `innerHTML`), so role keys / summaries cannot inject markup. CSS hooks: `.oc-l3-explain`, `.oc-l3-what`, `.oc-l3-participants`, `.oc-l3-decision`, `.oc-l3-conclusion`, `.oc-l3-detail`.
- Smoke tests covering: explainer wording for the conflict case (score 0.244, n_roles=2, pairs=1) and the agreement case (score 0.92, n_roles=3, pairs=3); end-to-end check that `PipelineEngine` writes the explanation block into `council_steps.summary`, surfaces `meta.explanation` and `meta.consensus`, and embeds `explanation` alongside the raw fields in the `L3_CONSENSUS` event payload returned by `/api/council/run`.

## [1.0.5] — 2026-05-07

### Fixed
- **L1 / L4 step responses truncated at 240 characters in the UI.** Both layers called `mb_substr($resp->content, 0, 240)` before persisting to `council_steps.summary`, so the Response tab showed answers cut mid-sentence (e.g. ending at `- Если ответ б`). The pipeline now writes the **full** model response to `summary`, with a separate short preview kept under `meta.summary_preview` for compact list views. The `summary` column was widened from `TEXT` to `MEDIUMTEXT` (16 MB ceiling) — see `db/migrations/002_summary_mediumtext.sql`.
- **L2 step rows showed "Normalized as text." with empty stats**, leading operators to think an LLM had been called and produced a one-word reply. L2 now writes `Normalized answer from role "<role>" into N text|JSON claims for consensus.` and embeds `claims`, `claims_count`, `shape`, and a `note: "L2 is an internal normalization step — no LLM call is made."` under `meta` so the raw payload is self-explanatory.
- **OpenRouter `google/gemini-flash-1.5` returned HTTP 404 ("No endpoints found").** The slug is no longer in the OpenRouter catalog. Default `config.models` swapped the deprecated entries for currently-available successors:
  - `google/gemini-flash-1.5` → `google/gemini-2.0-flash-001`
  - `anthropic/claude-3-haiku` → `anthropic/claude-3.5-haiku`
  - `meta-llama/llama-3.1-70b-instruct` → `meta-llama/llama-3.3-70b-instruct`
  - `deepseek/deepseek-chat-v3` → `deepseek/deepseek-chat`
  - BotHub: `anthropic/claude-3.5-sonnet` → `anthropic/claude-sonnet-4.5`, `google/gemini-2.0-flash` → `google/gemini-2.5-flash`
- **`config.connectors.bothub.base_url` overrode the connector default with the wrong host (`https://api.bothub.chat/v1`)**, which 404s. Restored to BotHub's published OpenAI-compatible path `https://bothub.chat/api/v2/openai/v1`.

### Added
- `PipelineEngine::diagnoseConnectorError()` — pattern-matches the most common chat-completions failures (HTTP 404 / "no endpoints found", 401/403, 429) and attaches an actionable `hint` to every `L1_FAILED` event so operators see *what to fix* (e.g. "Verify the slug against https://openrouter.ai/api/v1/models") instead of a raw HTTP error string.
- `db/migrations/002_summary_mediumtext.sql` — production-safe widen of `council_steps.summary` to MEDIUMTEXT.
- Smoke tests covering: full-content storage on L1 + L4, friendly L2 wording, presence of `meta.summary_preview` / `meta.claims` / `meta.note`, `L1_FAILED` hint on 404, and a config-regression assertion that the deprecated slugs are not re-enabled.
- `docs/Connectors.md` now includes a "Validating model IDs" section with the curl commands to list each provider's catalog.

## [1.0.4] — 2026-05-07

### Fixed
- **Empty Statistics block on successful steps.** Completed L1 / L4 step rows showed "Model —, Provider —, Prompt tokens —, Completion tokens —, Total tokens —, Latency —" in the UI even though the connector returned a full `usage` block. Root cause: step meta only carried the *requested* model/provider; the UI tried to recover stats from event payloads that only contained `tokens`. Fix:
  - `AbstractConnector::buildChatResponse()` now extracts every field OpenRouter / OpenAI-compatible providers expose (`prompt_tokens_details.cached_tokens`, `completion_tokens_details.reasoning_tokens`, `cost`, `cost_details`, top-level `id`, server-resolved `model`).
  - `ChatResponse` gained `actualModel`, `cost`, `cachedTokens`, `reasoningTokens`, `generationId`, `usageDetails` and a `statsPayload()` helper.
  - `PipelineEngine` merges the stats payload into each step's `meta` JSON via the new `StepRepository::completeStep($id, $status, $summary, $metaExtras)` signature, and emits the same payload on `L1_RESPONSE` / `L4_SYNTHESIS` events.
  - `PipelineEngine::run()` now returns `synthesis_stats`, forwarded by `CouncilController` so the Final Answer card shows the synthesis model/provider/totals instead of dashes.
  - `assets/js/app.js` reads stats from `step.meta` (one card per real step) and surfaces optional extras (cost, cached / reasoning tokens, generation id, "estimated" suffix).
- **BotHub base URL.** Default base URL switched to `https://bothub.chat/api/v2/openai/v1` to match BotHub's published OpenAI-compatible endpoint.

### Added
- `docs/Connectors.md` and `docs/Stats.md` documenting the OpenRouter / BotHub usage shape and the data path from connector → step meta → UI.
- Smoke tests for OpenRouter and BotHub usage parsing (incl. cost / cached / reasoning / generation id), estimation fallback, inconsistent-total reconciliation, and a UI/data-mapping check that completed steps carry non-blank stats.

## [1.0.3] — 2026-05-07

### Fixed
- **`PipelineEngine` L1 silent-skip bug.** The distributor used round-robin against `Config::getActiveModels()` without filtering by connector availability. With three roles and two providers (e.g. `bothub` + `openrouter`) where only one had a key, every L1 role was assigned to the unavailable provider and silently failed — so consensus had nothing to compare and L4 was the only call that produced output. L1 now partitions active models by available connector, routes only across usable models, and emits a clear `L1_NO_AVAILABLE_MODELS` event when nothing is usable.

### Added
- `src/Core/Diagnostics.php` — single source of truth for the sanitized debug payload reused by `?debug=1`, `GET /api/debug`, the `POST /api/council/run` debug envelope, and the 404 envelope on `GET /api/council/session/{id}`.
- `src/Core/Logger.php` — append-only line-delimited JSON logger with key/DSN/path sanitization. Writes to `logs/opencorum-debug.log` only when `app.debug=true` and the directory is writable; failure is silent.
- `Diagnostics::buildReport()` adds: request normalization (`request.resolved_path`), database error class/sanitized message, `storage_mode` (`memory`/`persistent`), per-connector availability with reason, per-model availability flag, pipeline `stage_plan`, and a silent-skip hint that fires when every active model points to an unavailable provider.
- `POST /api/council/run` returns a `debug` envelope (pipeline trace + diagnostics snapshot) when `?debug=1` is set or `app.debug=true`.
- `GET /api/council/session/{id}` returns a `debug` envelope on 404 explaining storage mode, last DB error class, and remediation steps.
- `PipelineEngine::trace()` exposes a per-run sanitized trace of model selection, role assignment, and L4 routing decisions.
- README: nginx + Apache rewrite snippets and a Debug Mode section documenting every debug surface and the silent-skip detector.
- Smoke tests for: model-selection filtering, `L1_NO_AVAILABLE_MODELS` diagnostic, `Logger::sanitize` (DSN passwords, absolute paths, keyed redaction), `Diagnostics::buildReport` shape, run-time debug envelope, and 404 debug envelope.

## [1.0.2] — 2026-05-05

### Added
- `?debug=1` (or `?DEBUG=1`) global query and `GET /api/debug` route — sanitized diagnostic snapshot covering active connectors, available connectors, skipped connectors, model counts, synthesis-provider availability, database connection, and triage hints. Never exposes API keys.
- `Application::isDebugRequest()` for parsing the debug query.
- Explicit `font-src 'self' data:` directive in the HTML CSP.

### Changed
- `POST /api/council/run` now embeds `steps` and `events` in the JSON response. The frontend prefers the embedded data when present, falling back to `GET /api/council/session/{id}` only when missing. This fixes the 404 reported when polling session-by-id against an in-memory backend (sessions don't survive across requests without a persistent database).
- `PipelineEngine` reports a precise reason when the synthesis provider has no available connector (active flag, missing API key, or class not registered). The fallback final answer surfaces the provider name and how to fix it.
- `PipelineEngine` emits a top-level `L1_NO_RESULTS` event when every L1 role call fails, so the audit trail shows the global failure mode, not just per-role failures.

## [1.0.0] — 2026-05-05

### Added
- Initial RevoAp implementation per `TECHNICAL_SPECIFICATION.md` v1.0.0.
- `src/Core/`: `Application`, `Config`, `Router` with dot-notation config and `.key`/`$_ENV` fallback for connector keys.
- `src/Helpers/`: `Utf8Helper`, `JsonHelper`, `TemplateHelper`, `TimeHelper`, `TokenHelper`.
- `src/Connectors/`: `ConnectorInterface`, `ChatResponse`, `EmbedResponse`, `AbstractConnector`, `ConnectorRegistry` (auto-discovery), `BothubConnector`, `OpenRouterConnector`, exception hierarchy, and `HOW_TO_CREATE_CONNECTOR.md`.
- `src/Pipeline/`: forward-only `PipelineEngine` with loop detection; `PipelineStep`, `StepResult`, `PipelineEvent` value objects.
- `src/Consensus/`: `ConsensusEngine`, `ConsensusResult`, `NormalizationService`.
- `src/Skills/`: `Skill`, `SkillLoader`, `SkillRouter`, `SkillAutoCreator`, `SkillRepository`; four system skill JSON definitions.
- `src/Stats/`: `TokenUsage`, `SessionStats`, `StatsCollector`, `StatsAggregator`.
- `src/Storage/`: `SessionRepository`, `StepRepository`, `ResponseRepository`, `VectorStore` — all with PDO + in-memory fallback.
- `src/Http/`: `Response` helpers with CSP headers; `CouncilController`, `SkillController`, `StatsController`, `CreatorController`.
- `assets/`: HTML templates, light/dark CSS themes, ES2022 modules for theme/pipeline/stats/app.
- `db/schema.sql` + `db/migrations/001_initial.sql`.
- Front controller `index.php`, lightweight PSR-4 autoloader `src/autoload.php`, `composer.json`, `.htaccess`, `.gitignore`, `.env.example`.
- Per-module documentation: `Core.md`, `Helpers.md`, `Connectors.md`, `Pipeline.md`, `Consensus.md`, `Skills.md`, `Stats.md`, `Storage.md`, `Http.md`.
- Smoke tests under `tests/` covering token-stat invariants, router, normalization, consensus, registry discovery, and pipeline end-to-end with a stub connector.
- [2026-09-18T18:44:17] src/Core/Logger.php (backup v1) - Logger: docblock split levels + errorLogFile state
- [2026-09-18T18:45:17] src/Core/Logger.php (backup v2) - Logger: enabled() refactor + resolvedLogDir/errorLogPath
- [2026-09-18T18:45:33] src/Core/Logger.php (backup v3) - Logger: reset clears errorLogFile; log() branches error-level to always-on file
- [2026-09-18T18:45:38] src/Core/Logger.php (backup v4) - Logger: write to resolved target
- [2026-09-18T18:46:31] tests/ObservabilityTest.php - New offline test for Task 4 always-on error log
- [2026-09-18T18:46:38] src/Logs/LogsService.php (backup v1) - LogsService: expose opencorum-error.log via whitelist
- [2026-09-18T18:48:33] index.php (backup v1) - index.php: merge worker block into base /api/health
- [2026-09-18T18:49:10] src/Jobs/JobAuditRepository.php (backup v1) - JobAuditRepository: fix nonexistent Logger::warning -> warn
- [2026-09-18T18:53:21] src/Core/Logger.php (backup v5) - Logger: scrub log context through sanitizeLogContext
- [2026-09-18T18:53:49] src/Core/Logger.php (backup v6) - Logger: strict free-text redaction in log context
- [2026-09-18T18:54:30] tests/ObservabilityTest.php (backup v1) - ObservabilityTest: expect whole-value redaction for secret keys
- [2026-09-18T19:13:46] assets/templates/layout.html (backup v2) - layout.html: job-mode meta tags from UPSTREAM
- [2026-09-18T19:13:52] assets/templates/layout.html (backup v3) - layout.html: mermaid renderer script from UPSTREAM
- [2026-09-18T19:14:13] assets/templates/shared.html (backup v2) - shared.html: add mermaid renderer script (UPSTREAM parity)
- [2026-09-18T19:14:39] index.php (backup v2) - index.php /api/boot-config: pipeline block from PROD-SNAP
- [2026-09-18T19:14:48] index.php (backup v3) - index.php renderHome: execution mode vars into layout.html
- [2026-09-18T19:16:09] assets/templates/layout.html (backup v4) - layout.html: restore em-dash for byte-parity with UPSTREAM
- [2026-09-18T19:28:02] deploy/systemd/opencorum-worker@.service - Task 2: systemd pool template opencorum-worker@.service with stable worker-%i ids and MemoryMax cap
- [2026-09-18T19:28:40] docs/WorkerArchitecture.md (backup v2) - WorkerArchitecture.md: replace multi-worker note with pool deployment + sizing section
- [2026-09-18T19:29:15] deploy/systemd/opencorum-worker@.service (backup v1) - openecorum-worker@.service: comment out MemoryMax until measured
- [2026-09-18T19:42:14] deploy/systemd/opencorum-worker.service (backup v1) - opencorum-worker.service: Environment vars + templated ExecStart + /etc/revoap/worker.env
- [2026-09-18T19:43:42] deploy/systemd/opencorum-worker.service (backup v2) - opencorum-worker.service: full rewrite to fix orphaned comment and update multi-worker header
- [2026-09-18T19:43:55] deploy/systemd/opencorum-worker@.service (backup v2) - opencorum-worker@.service: EnvironmentFile tempo controls + templated ExecStart
- [2026-09-18T19:44:07] deploy/systemd/opencorum-worker@.service (backup v3) - opencorum-worker@.service: align ExecStart comment with env-driven tempo
- [2026-09-18T19:44:14] deploy/systemd/worker.env.example - Task 3: worker.env.example template for /etc/revoap/worker.env
- [2026-09-18T19:44:31] docs/WorkerArchitecture.md (backup v3) - Task 3: document WORKER_SLEEP interactive/quiet modes in WorkerArchitecture.md
- [2026-09-18T19:45:29] docs/WorkerArchitecture.md (backup v4) - fix wording: recoverStale runs every poll iteration, not a scheduled sweep
- [2026-09-18T19:47:57] bin/opencorum-worker.php (backup v1) - worker: sync docblock and --help with rebuilt-ramp override semantics
- [2026-09-18T19:48:29] bin/opencorum-worker.php (backup v2) - worker: rebuild backoff ramp x1/x4/x10 on CLI override so --backoff-base/max are observable
- [2026-09-18T19:50:47] docs/WorkerArchitecture.md (backup v5) - Task 3: web-facing timeout guidance + updated CLI backoff override docs
- [2026-09-18T21:04:58] src/Storage/VectorStoreInterface.php
- [2026-09-18T21:05:22] src/Storage/VectorStore.php (backup v1)
- [2026-09-18T21:16:40] src/Storage/Vector/QdrantVectorStore.php
- [2026-09-18T21:17:41] src/Storage/Vector/VectorStoreFactory.php
- [2026-09-18T21:19:00] src/Memory/MemoryItem.php
- [2026-09-18T21:22:24] src/Memory/PdoMemoryRepository.php
- [2026-09-18T21:24:42] src/Memory/MemoryEmbedderInterface.php
- [2026-09-18T21:26:02] src/Memory/ConnectorMemoryEmbedder.php - Task 5: connector-based MemoryEmbedderInterface implementation
- [2026-09-18T21:30:51] src/Memory/EmbeddingMemory.php
- [2026-09-18T21:56:23] db/migrations/014_memory_items.sql - Task 5: idempotent memory_items migration (014)
- [2026-09-18T21:58:39] tests/VectorMemoryTest.php - Task 5: offline vector-memory test suite
- [2026-09-18T21:59:39] tests/VectorMemoryTest.php (backup v1)
- [2026-09-18T22:00:20] src/Memory/EmbeddingMemory.php (backup v1)
- [2026-09-18T22:00:55] src/Memory/MemoryItem.php (backup v1)
- [2026-09-18T22:01:19] bin/vector-memory-bench.php - Task 5: offline vector memory benchmark CLI (1k/5k/20k)
- [2026-09-18T22:27:07] src/Skills/SkillRouter.php (backup v1)
- [2026-09-18T22:28:14] src/Skills/SkillSearch.php
- [2026-09-18T22:28:44] src/Skills/SkillRouter.php (backup v2)
- [2026-09-18T22:34:44] src/Memory/EmbeddingMemory.php (backup v2)
- [2026-09-18T22:46:47] src/Pipeline/PipelineEngine.php (backup v1)
- [2026-09-18T22:46:50] src/Pipeline/PipelineEngine.php (backup v2)
- [2026-09-18T22:46:58] src/Pipeline/PipelineEngine.php (backup v3)
- [2026-09-18T23:05:29] src/Pipeline/PipelineEngine.php (backup v4)
- [2026-09-18T23:05:46] src/Pipeline/PipelineEngine.php (backup v5)
- [2026-09-18T23:05:57] src/Pipeline/PipelineEngine.php (backup v6)
- [2026-09-18T23:18:44] index.php (backup v4)
- [2026-09-18T23:19:03] index.php (backup v5)
- [2026-09-18T23:19:15] bin/opencorum-worker.php (backup v3)
- [2026-09-18T23:38:48] bin/memory-retention.php
- [2026-09-18T23:42:15] tests/MemoryPipelineGateTest.php
- [2026-09-18T23:43:09] src/Memory/EmbeddingMemory.php (backup v3) - EmbeddingMemory::storeText - guard vector upsert so any store failure degrades instead of propagating
- [2026-09-18T23:43:18] src/Memory/EmbeddingMemory.php (backup v4) - Fix docblock indentation damaged by fuzzy edit in EmbeddingMemory.php
- [2026-09-18T23:43:37] src/Memory/EmbeddingMemory.php (backup v5) - Fix line 15 indentation (stray space before asterisk) in EmbeddingMemory.php docblock
- [2026-09-18T23:45:59] bin/vector-memory-bench.php (backup v1) - Parametrize vector dimension (argv[2], default 64) - full rewrite of small bench file
- [2026-09-18T23:46:37] bin/vector-memory-bench.php (backup v2)
- [2026-09-18T23:47:13] bin/memory-retention.php (backup v1) - Use AdminAuth::envName() for the env-token source in memory-retention
- [2026-09-18T23:47:55] src/Core/AdminAuth.php (backup v1) - Add AdminAuth::verifyToken for CLI token comparison (shared source with check())
- [2026-09-18T23:48:21] bin/memory-retention.php (backup v2) - Retention CLI: switch to AdminAuth::verifyToken (correct current text anchor)
- [2026-09-19T00:23:57] bin/vector-memory-bench.php (backup v3)
- [2026-09-19T00:30:10] tests/MemoryRetentionCliTest.php - Add standalone CLI auth regression test for bin/memory-retention.php (no real secrets)
- [2026-09-19T00:34:23] bin/memory-retention.php (backup v3) - Apply deprecation filter + drop useless use via full rewrite (apply_patch anchors failed twice)
- [2026-09-19T00:36:37] docs/VectorMemory.md - Create docs/VectorMemory.md: architecture, config, measured thresholds, ops and server checklist for Task 5
- [2026-09-19T00:57:48] tests/MemoryRetentionCliTest.php (backup v1)
- [2026-09-19T01:01:05] src/Memory/PdoMemoryRepository.php (backup v1)
- [2026-09-19T01:02:43] bin/memory-retention.php (backup v4)
- [2026-09-19T01:07:01] .dev_agent/task_states/20260919_004824_a064c5/task_1/opencorum_inventory.md
- [2026-09-19T01:13:33] src/Core/AdminAuth.php (backup v2)
- [2026-09-19T01:14:56] src/Core/AdminAuth.php (backup v3)
- [2026-09-19T01:15:01] src/Core/AdminAuth.php (backup v4)
- [2026-09-19T01:17:29] src/Core/AdminAuth.php (backup v5) - rename env var to REVOAP_ADMIN_TOKEN with legacy alias and one-time warn
- [2026-09-19T01:19:06] bin/memory-retention.php (backup v5)
- [2026-09-19T01:19:43] bin/memory-retention.php (backup v6)
- [2026-09-19T01:19:48] tests/MemoryRetentionCliTest.php (backup v2)
- [2026-09-19T01:20:08] tests/MemoryRetentionCliTest.php (backup v3)
- [2026-09-19T01:20:12] tests/MemoryRetentionCliTest.php (backup v4)
- [2026-09-19T01:20:23] src/Core/Logger.php (backup v7)
- [2026-09-19T01:20:40] src/Core/Logger.php (backup v8)
- [2026-09-19T01:20:47] src/Core/Logger.php (backup v9)
- [2026-09-19T01:23:00] src/Logs/LogsService.php (backup v2)
- [2026-09-19T01:23:05] src/Logs/LogsService.php (backup v3)
- [2026-09-19T01:23:31] tests/ObservabilityTest.php (backup v2)
- [2026-09-19T01:23:38] tests/ObservabilityTest.php (backup v3)
- [2026-09-19T01:23:47] tests/ObservabilityTest.php (backup v4)
- [2026-09-19T01:24:16] src/Jobs/WorkerHealthService.php (backup v1)
- [2026-09-19T01:24:36] src/Jobs/WorkerHealthService.php (backup v2)
- [2026-09-19T01:25:10] src/Jobs/WorkerHealthService.php (backup v3)
- [2026-09-19T01:27:00] bin/opencorum-worker.php (backup v4)
- [2026-09-19T01:29:08] bin/opencorum-worker.php (backup v5) - full internal rename: rv_worker_* functions, revoap-worker service tag and CLI message, revoap path in usage/docblock
- [2026-09-19T01:32:23] src/Core/Config.php (backup v1)
- [2026-09-19T01:33:07] src/Http/Controllers/JobsController.php (backup v1)
- [2026-09-19T01:33:54] src/Http/Controllers/JobsController.php (backup v2)
- [2026-09-19T01:34:41] src/Http/Controllers/SettingsController.php (backup v1)
- [2026-09-19T01:34:51] src/Jobs/PipelineJobRunner.php (backup v1)
- [2026-09-19T01:35:45] index.php (backup v6)
- [2026-09-19T01:36:26] assets/js/app.js (backup v3)
- [2026-09-19T01:36:46] assets/js/app.js (backup v4)
- [2026-09-19T01:37:36] assets/js/app.js (backup v5)
- [2026-09-19T01:37:41] assets/js/mermaid-render.js (backup v3)
- [2026-09-19T01:37:45] tests/AnalyzerHardeningTest.php (backup v1)
- [2026-09-19T01:39:29] tests/SmokeTest.php (backup v1)
- [2026-09-19T01:39:36] tests/SmokeTest.php (backup v2)
- [2026-09-19T01:39:40] tests/SmokeTest.php (backup v3)
- [2026-09-19T01:45:00] tests/SmokeTest.php (backup v4)
- [2026-09-19T01:45:36] tests/SmokeTest.php (backup v5)
- [2026-09-19T01:45:45] tests/SmokeTest.php (backup v6)
- [2026-09-19T01:46:21] tests/SmokeTest.php (backup v7)
- [2026-09-19T01:46:46] tests/SmokeTest.php (backup v8)
- [2026-09-19T01:46:51] tests/SmokeTest.php (backup v9)
- [2026-09-19T01:47:21] tests/SmokeTest.php (backup v10)
- [2026-09-19T01:51:48] assets/js/logs.js (backup v3)
- [2026-09-19T01:51:51] assets/js/security.js (backup v3)
- [2026-09-19T01:51:59] assets/js/settings.js (backup v3)
- [2026-09-19T01:52:31] .env.example (backup v1)
- [2026-09-19T01:54:25] deploy/systemd/opencorum-worker.service (backup v3) - Rename OpenCorum->RevoAp in systemd unit internals
- [2026-09-19T01:54:53] deploy/systemd/opencorum-worker@.service (backup v4) - Rename OpenCorum->RevoAp in systemd pool template internals
- [2026-09-19T01:56:09] deploy/systemd/worker.env.example (backup v1) - Rename OpenCorum->RevoAp in worker.env.example
- [2026-09-19T02:02:55] docs/Logs.md (backup v3)
- [2026-09-19T02:15:43] tests/AnalyzerHardeningTest.php (backup v2)
- [2026-09-19T02:17:04] tests/AnalyzerHardeningTest.php (backup v3)
- [2026-09-19T02:19:35] assets/js/app.js (backup v6) - step4: stale migration filename -> consolidated db/schema.sql
- [2026-09-19T02:21:07] src/Jobs/WorkerHealthService.php (backup v4) - step4: stale migration guidance -> consolidated db/schema.sql
- [2026-09-19T02:23:29] src/Jobs/WorkerHealthService.php (backup v5) - step4: ReCreate DB lives in Database card, not Migrations
- [2026-09-19T02:24:22] assets/js/settings.js (backup v4) - step4: stale migration warnings -> schema import path
- [2026-09-19T02:28:15] docs/WorkerArchitecture.md (backup v7) - step4: stale migration ref -> schema import path
- [2026-09-19T02:34:45] .env.example (backup v2) - remove stray artifact line
- [2026-09-19T02:51:38] CHANGELOG.md (backup v3)
- [2026-09-19T02:53:36] README.md (backup v3)
- [2026-09-19T02:55:44] tests/VectorMemoryTest.php (backup v2)
- [2026-09-19T02:58:00] .gitignore (backup v1)
- [2026-09-19T02:58:34] TECHNICAL_SPECIFICATION.md (backup v3)
- [2026-09-19T03:00:00] SKILL.md (backup v3)
- [2026-09-19T03:56:55] ROAD_MAP.md - ROAD_MAP.md part 1: шапка, легенда, сводная таблица, маппинг запросов, P0 (пункты 1-3)
- [2026-09-19T03:57:40] ROAD_MAP.md (backup v1)
- [2026-09-19T04:18:41] db/schema.sql (backup v3)
- [2026-09-19T04:20:49] db/migrations/015_api_keys.sql
- [2026-09-19T04:21:03] src/Api/ApiKey.php
- [2026-09-19T04:21:46] src/Api/ApiKeyRepository.php
- [2026-09-19T04:22:59] src/Api/ApiKey.php (backup v1)
- [2026-09-19T04:23:41] src/Api/ApiKeyRepository.php (backup v1)
- [2026-09-19T04:24:30] src/Api/ApiKeyRepository.php (backup v2)
- [2026-09-19T04:25:45] src/Api/ApiKeyRepository.php (backup v3)
- [2026-09-19T04:25:51] src/Api/ApiKeyRepository.php (backup v4)
- [2026-09-19T04:26:26] src/Api/ApiKeyService.php
- [2026-09-19T04:27:04] tests/ApiKeyTest.php
- [2026-09-19T04:27:17] tests/ApiKeyTest.php (backup v1)
- [2026-09-19T04:33:23] src/Api/ApiRateLimiter.php
- [2026-09-19T04:34:07] src/Api/ApiKeyService.php (backup v1)
- [2026-09-19T04:34:29] src/Api/ApiAuth.php
- [2026-09-19T04:36:02] tests/ApiAuthTest.php
- [2026-09-19T04:37:02] src/Api/ApiKey.php (backup v2)
- [2026-09-19T04:37:08] src/Api/ApiAuth.php (backup v1)
- [2026-09-19T04:45:13] src/Api/FormatAdapter.php
- [2026-09-19T04:45:42] tests/FormatAdapterTest.php
- [2026-09-19T05:17:26] src/Api/ApiV1Controller.php
- [2026-09-19T05:21:03] src/Api/ApiV1Controller.php (backup v1)
- [2026-09-19T05:21:26] src/Api/ApiV1Controller.php (backup v2)
- [2026-09-19T05:22:31] src/Api/ApiV1Controller.php (backup v3)
- [2026-09-19T05:23:17] index.php (backup v7)
- [2026-09-19T05:23:24] index.php (backup v8)
- [2026-09-19T05:23:29] index.php (backup v9)
- [2026-09-19T05:31:14] tests/ApiLaunchTest.php
- [2026-09-19T05:32:47] tests/ApiLaunchTest.php (backup v1)
- [2026-09-19T05:33:23] tests/ApiLaunchTest.php (backup v2)
- [2026-09-19T05:56:44] src/Jobs/JobService.php (backup v1)
- [2026-09-19T05:57:53] src/Api/ApiV1Controller.php (backup v4)
- [2026-09-19T06:04:06] src/Api/ApiV1Controller.php (backup v5)
- [2026-09-19T06:06:16] src/Api/ApiV1Controller.php (backup v6)
- [2026-09-19T06:10:47] src/Api/ApiV1Controller.php (backup v7)
- [2026-09-19T06:12:49] src/Api/ApiV1Controller.php (backup v8)
- [2026-09-19T06:14:13] src/Api/ApiV1Controller.php (backup v9)
- [2026-09-19T06:14:57] src/Api/ApiV1Controller.php (backup v10)
- [2026-09-19T06:15:19] index.php (backup v10)
- [2026-09-19T06:16:23] tests/ApiLaunchTest.php (backup v3)
- [2026-09-19T06:18:32] tests/ApiReadTest.php - Step 5: read-endpoint tests (runs/events/result/cancel/models/usage) + agent polling scenario
- [2026-09-19T06:23:10] db/migrations/016_webhooks.sql - Step 6: migration 016_webhooks.sql (webhooks + webhook_deliveries)
- [2026-09-19T06:24:17] db/migrations/016_webhooks.sql (backup v1) - Step 6: migration 016_webhooks.sql, webhook_deliveries.paydoad added for idempotent retries
- [2026-09-19T06:26:10] db/schema.sql (backup v4)
- [2026-09-19T06:26:31] src/Api/Webhook.php
- [2026-09-19T06:27:10] src/Api/WebhookRepository.php
- [2026-09-19T06:31:36] db/migrations/016_webhooks.sql (backup v2)
- [2026-09-19T06:31:41] db/schema.sql (backup v5)
- [2026-09-19T06:36:13] src/Api/WebhookDelivery.php
- [2026-09-19T06:37:52] src/Api/WebhookDeliveryRepository.php
- [2026-09-19T06:44:29] src/Api/WebhookDispatcher.php
- [2026-09-19T06:54:33] src/Api/WebhookService.php
- [2026-09-19T07:00:46] src/Api/WebhookController.php
- [2026-09-19T07:01:55] src/Api/WebhookService.php (backup v1)
- [2026-09-19T07:11:31] tests/WebhookTest.php
- [2026-09-19T07:15:10] src/Api/WebhookDispatcher.php (backup v1)
- [2026-09-19T07:18:03] tests/WebhookTest.php (backup v1)
- [2026-09-19T07:18:58] tests/WebhookTest.php (backup v2)
- [2026-09-19T07:19:37] index.php (backup v11)
- [2026-09-19T07:21:42] index.php (backup v12)
- [2026-09-19T07:21:53] index.php (backup v13)
- [2026-09-19T07:22:33] bin/revoap-worker.php (backup v1)
- [2026-09-19T07:23:45] bin/revoap-worker.php (backup v2)
- [2026-09-19T07:24:08] index.php (backup v14)
- [2026-09-19T07:24:24] index.php (backup v15)
- [2026-09-19T07:24:46] src/Api/WebhookController.php (backup v1)
- [2026-09-19T13:37:05] src/Api/ApiKeyController.php - Step 7: admin CRUD контроллер ключей для вкладки API
- [2026-09-19T13:37:18] src/Core/Config.php (backup v2)
- [2026-09-19T13:37:46] config.php (backup v1)
- [2026-09-19T13:37:57] index.php (backup v16)
- [2026-09-19T13:38:12] index.php (backup v17)
- [2026-09-19T13:38:30] src/Api/ApiAuth.php (backup v2)
- [2026-09-19T13:39:24] index.php (backup v18)
- [2026-09-19T13:40:00] assets/templates/layout.html (backup v6)
- [2026-09-19T13:40:38] assets/css/components/api.css - Step 7: стили вкладки API
- [2026-09-19T13:41:14] assets/js/api.js - Step 7: клиент вкладки API (генерация/rotate/revoke/reveal/справка)
- [2026-09-19T13:41:35] assets/js/api.js (backup v1)
- [2026-09-19T13:48:48] tests/ApiKeyControllerTest.php - Step 7: тест ApiKeyController (гейты/CRUD/one-time key/deny-list)
- [2026-09-19T13:51:41] tests/ApiKeyControllerTest.php (backup v1) - Step 7: исправленный тест ApiKeyController (буферизация отчёта, разные random-байты, instance-метод isAdminPath, реальная семантика rotate)
- [2026-09-19T13:54:10] tests/SmokeTest.php (backup v11)
- [2026-09-19T13:59:28] assets/js/api.js (backup v2) - Step 7: тултипы всех полей формы генерации ключа
- [2026-09-19T14:00:13] tests/SmokeTest.php (backup v12) - Step 7: актуализировать счётчик таблиц schema.sql (18) и добавить проверку API-таблиц
- [2026-09-19T14:02:48] src/Settings/SchemaParser.php (backup v1) - Step 7: SchemaParser не должен глотать колонки вида key_hash как KEY-индекс (граница слова)
- [2026-09-19T14:11:24] src/Api/ApiRateLimiter.php (backup v1) - Step 8: IP rate-limit в ApiRateLimiter (fail-closed, sha256-имя файла, не считает отклонённые)
- [2026-09-19T14:11:51] src/Api/ApiAuth.php (backup v3) - Step 8: ApiAuth docblock + конструктор с параметром IP-лимита (null=выкл для BC)
- [2026-09-19T14:12:14] src/Api/ApiAuth.php (backup v4) - Правка выравнивания docblock после fuzzy-матча
- [2026-09-19T14:12:20] src/Api/ApiAuth.php (backup v5) - Step 8: IP rate-limit gate в ApiAuth::check до обработки ключа
- [2026-09-19T14:12:29] index.php (backup v19) - Step 8: включить IP rate-limit 120 rpm в index.php
- [2026-09-19T14:13:33] tests/ApiSecurityTest.php - Step 8: security/audit тест (IP rate-limit, fail-closed, аудит без секретов, BC)
- [2026-09-19T14:13:49] tests/ApiSecurityTest.php (backup v1) - Step 8: пер-ключ счётчики key_*.json также валидны в каталоге лимитера
- [2026-09-19T14:20:25] docs/api-rest.md
- [2026-09-19T14:21:39] docs/api-rest.md (backup v1) - Step 9: восстановить потерянное начало docs/api-rest.md
- [2026-09-19T14:22:02] README.md (backup v4) - Step 9: секция External REST API в README.md
- [2026-09-19T14:22:52] CHANGELOG.md (backup v4) - Step 9: запись v1.8.43 REST API в CHANGELOG.md
- [2026-09-19T14:23:15] CHANGELOG.md (backup v5) - Step 9: убрать лишние бэкслеши в именах классов CHANGELOG
- [2026-09-19T14:23:30] ROAD_MAP.md (backup v2) - Step 9: ROAD_MAP - пункт 14 переведён в статус Реализовано (v1.8.43)
- [2026-09-19T14:33:34] tests/scenarios/ApiScenariosTest.php - Step 10: сценарные тесты REST API (Stage 3 Tier 3)
- [2026-09-19T14:40:40] tests/scenarios/ApiScenariosTest.php (backup v1) - Step 10: фикс ассертов сценария (stub-ответ без текста запроса, реальная проверка счётчика отправок)
- [2026-09-19T14:42:46] tests/scenarios/ApiScenariosTest.php (backup v2) - Step 10: фикс сценария 5 под реальный контракт диспетчера (детерминированный HMAC, изоляция хуков) и удаление мёртвой функции
- [2026-09-19T14:44:02] tests/scenarios/ApiScenariosTest.php (backup v3) - Step 10: удаление задвоенного ассерта подписи
- [2026-09-19T14:44:32] tests/scenarios/ApiScenariosTest.php (backup v4) - Step 10: догоняем откаченные правки (удаление вызова resolveEventOrder, усиление revoap-ассерта)
- [2026-09-19T14:53:15] CHANGELOG.md (backup v6) - Step 10: CHANGELOG 1.8.43 — упоминание сценарных тестов
