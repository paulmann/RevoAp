# Module: Jobs

## Purpose
Durable queue for council runs. A browser (or an API client) enqueues a job,
a CLI worker picks it up under a row lock, the pipeline runs inside the worker
process, and every state change is written to `revoap_jobs` so the interface
can follow a run that outlives the request that started it.

Two execution shapes share this directory: the synchronous engine path
(`WorkerJobExecutor` → `PipelineJobRunner`) and the asynchronous provider
Batch API path (`kind = batch_pipeline`).

## Files
| File | Class | Responsibility |
|------|-------|----------------|
| `Job.php` | `App\Jobs\Job` | Value object (`final readonly`): one `revoap_jobs` row. |
| `JobStatus.php` | `App\Jobs\JobStatus` | Enum of the job statuses (`queued`, `running`, `completed`, `failed`, `stale`, …). |
| `JobRepository.php` | `App\Jobs\JobRepository` | All `revoap_jobs` SQL: enqueue, `FOR UPDATE SKIP LOCKED` claim, heartbeat, requeue, terminal writes. |
| `JobService.php` | `App\Jobs\JobService` | Enqueue API used by the controllers: builds the payload, creates the `council_sessions` row up-front, writes session metadata. |
| `JobRunnerInterface.php` | `App\Jobs\JobRunnerInterface` | Contract the worker depends on instead of the concrete executor. |
| `PipelineJobRunner.php` | `App\Jobs\PipelineJobRunner` | Runs one job: self-test / models-actualize / batch / pipeline dispatch, engine invocation, result envelope. |
| `WorkerJobExecutor.php` | `App\Jobs\WorkerJobExecutor` | Wires one job to its runner and owns the per-job lifecycle decisions. |
| `WorkerSelfTest.php` | `App\Jobs\WorkerSelfTest` | `kind = selftest` probe: proves web → DB → worker → result without calling a model. |
| `WorkerHealthService.php` | `App\Jobs\WorkerHealthService` | Worker health/state reporting (presence, last beat, queue depth). |
| `WorkerPresenceRepository.php` | `App\Jobs\WorkerPresenceRepository` | `revoap_worker_presence` heartbeat; distinguishes idle / not running / crashed. |
| `WorkerRestartSignal.php` | `App\Jobs\WorkerRestartSignal` | Cross-process "restart requested" flag. |
| `JobAuditRepository.php`, `JobAuditEvent.php` | `App\Jobs\JobAudit*` | Append-only audit trail of job control actions. |

## Public API
- `JobService::createJob(array $input): array` — enqueue a pipeline run.
- `JobService::createBatchJob(array $input): array` — enqueue a Batch API run;
  **refuses** `single_model` (a batch parks a fan-out, single-model mode makes
  one direct call — accepting both would mean silently picking one for the
  operator).
- `JobService::createSelfTestJob()` — enqueue a worker probe.
- `JobRepository::acquireNext(string $workerId, int $maxAttempts)` — atomic
  claim; `heartbeat()`, `recoverStale()`, `cancelAbandoned()`,
  `requeueForInfrastructure()`.
- `PipelineJobRunner::run(Job $job, string $workerId): JobStatus`.

## Invariants & Constraints
- **Claim is atomic**: one row per worker, taken with `FOR UPDATE SKIP LOCKED`.
- **`attempts` counts model failures only.** An infrastructure failure (lost DB
  connection, worker restart) goes through `requeueForInfrastructure()` and does
  **not** spend the model-retry budget.
- **Heartbeat and presence must not break**: a live job updates
  `heartbeat_at`, presence is refreshed once a second and removed on a clean
  exit. The two together are what tell "idle" from "crashed".
- **Exit codes are a contract with systemd**: `bin/revoap-worker.php` keeps
  0/2/3/4 meanings. The worker refuses to run outside the CLI SAPI.
- **A terminal job must not overwrite a real session answer.**
  `SessionSafetyNet` closes a session only when it is still `running` and has no
  answer of its own; its marker text (`# Run closed by the worker…`) is never the
  run's result.
- **Batch jobs are exempt from local termination**: a batch parked at the
  provider is cancelled through the provider, never by abandoning the row
  locally, because the provider would keep working with no owner.
- **Secrets never travel through a payload, a result envelope or a log line.**

## Integration Points
- Enqueued by `Http\Controllers\JobsController` (operator SPA) and
  `Api\ApiV1Controller` (`POST /v1/runs`, async mode).
- Executes `Pipeline\PipelineEngine::run()` (and `repairSession()` for a batch
  continuation) — see `src/Pipeline/Pipeline.md` for the run modes, including
  `single_model`.
- Reads presence for `Worker`-tab surfaces; writes `revoap_job_events` for
  replay.
- Started by `bin/revoap-worker.php` under the systemd units in
  `deploy/systemd/`.

## LLM Extension Guide
- A new job `kind` belongs here: recognise it in `PipelineJobRunner::run()` on
  the same footing as `selftest`, `models_actualize` and `batch_pipeline`, each
  of which is checked **before** the pipeline payload contract is enforced.
- A new engine option that a worker must forward goes in
  `PipelineJobRunner::buildEngineOptions()` (and, if History has to reconstruct
  it later, into `JobService::buildSessionMetadata()` — see `single_model` for a
  key that needed both).
- Never widen the payload with a secret. A provider key is resolved inside the
  connector, in the worker process, and stays there.
