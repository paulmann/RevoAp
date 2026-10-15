<?php
/**
 * BatchState: the batch run's state, persisted in the job row.
 *
 * ## Why this lives in the database and not in the process
 *
 * A batch is defined by the fact that it outlives the call that started it.
 * The provider's own window is 24 hours, and the worker that submits a
 * batch is not necessarily the worker that polls it — `systemctl restart`,
 * a deploy, a crash and a `--once` invocation all end the process in
 * between. Anything remembered in a field or a static would be gone, and
 * the run would be lost with the batch still running and still billing.
 *
 * So the state is a plain array in `revoap_jobs.result_json`, written
 * after every phase transition through
 * {@see \App\Jobs\JobRepository::recordResult()}. Every phase reads it back
 * from the job row it was handed, which is what makes each phase a pure
 * function of the database:
 *
 *     phase 'new'        → build the L1 fan-out, upload, create   (prepare)
 *     phase 'submitted'  → ask the provider for the status        (poll)
 *     phase 'accepting'  → download, parse, write back, continue  (accept)
 *     phase terminal     → nothing left to do                     (done)
 *
 * The two scheduling values are split between the two stores on purpose:
 * `next_poll_at` here is for DISPLAY (the UI shows a countdown and an
 * elapsed time), while the authoritative "when may a worker pick this up
 * again" is the `revoap_jobs.next_run_at` column, because that is the
 * column `acquireNext()` actually filters on. A divergence between them
 * can delay a poll; it can never double-run or lose a phase, because the
 * phase itself is the state machine's input, not the clock.
 *
 * ## What is deliberately NOT here
 *
 * The request bodies. The JSONL file needs them exactly once — between
 * building the document and creating the batch, which is a single worker
 * invocation — so persisting six prompts (with the operator's query and
 * their system prompts inside) into a job row that the browser reads back
 * through `GET /api/council/jobs/{id}` would leak prompt content no UI
 * needs. What is persisted per participant is the mapping only:
 * `custom_id → step_id`, provider, model, role.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-09-21
 */
declare(strict_types=1);

namespace App\Batch;

use App\Connectors\Batch\BatchLifecycle;

final class BatchState
{
    /** Nothing submitted yet; the next pickup prepares, uploads and creates. */
    public const PHASE_NEW = 'new';

    /** Document built, steps created — the upload has not been confirmed. */
    public const PHASE_PREPARED = 'prepared';

    /** File uploaded, batch not yet created. */
    public const PHASE_UPLOADED = 'uploaded';

    /** Batch created; the provider owns it until it reaches a terminal status. */
    public const PHASE_SUBMITTED = 'submitted';

    /** The provider finished; the output file still has to be fetched. */
    public const PHASE_ACCEPTING = 'accepting';

    /** Rows written back into the journal; L2/L3/L4 may be running. */
    public const PHASE_ACCEPTED = 'accepted';

    /** Terminal for our purposes — the job row carries the verdict. */
    public const PHASE_DONE = 'done';

    /** Machine actions the scheduler can decide on. */
    public const ACTION_PREPARE = 'prepare';
    public const ACTION_POLL    = 'poll';
    public const ACTION_ACCEPT  = 'accept';
    public const ACTION_CANCEL  = 'cancel';
    public const ACTION_DONE    = 'done';

    /** Consecutive unreadable polls tolerated before the run is failed. */
    public const MAX_POLL_FAILURES = 10;

    /** Window the provider guarantees, as documented. */
    public const COMPLETION_WINDOW_HOURS = 24;

    /**
     * @param array<string,mixed> $data Persisted payload, or [] for a fresh run.
     */
    public function __construct(private readonly array $data = []) {}

    /**
     * Read the state out of a job's `result_json`. A job that has never
     * run a batch phase has no `batch` key, which is exactly the fresh
     * state — so a first pickup, a retry after a deploy and a resumed run
     * all arrive here the same way.
     *
     * @param array<string,mixed>|null $result
     */
    public static function fromJobResult(?array $result): self
    {
        $batch = $result['batch'] ?? null;
        return new self(is_array($batch) ? $batch : []);
    }

    /**
     * The envelope to merge back into `result_json`. Always nested under
     * `batch` so the envelope can keep carrying the ordinary pipeline keys
     * (`final_answer`, `stats`, …) alongside it, and the UI reads one
     * stable path.
     *
     * @return array<string,mixed>
     */
    public function toEnvelope(): array
    {
        return ['batch' => $this->data];
    }

    /**
     * A copy with the given keys replaced.
     *
     * @param array<string,mixed> $patch
     */
    public function with(array $patch): self
    {
        return new self(array_merge($this->data, $patch));
    }

    /**
     * The raw persisted payload.
     *
     * Exposed for the two cases a typed accessor would only obscure: the
     * orchestrator merging a phase patch onto what is already stored, and
     * the failure path reading the provider's `errors` block verbatim so
     * the operator sees the provider's wording rather than a paraphrase.
     *
     * @return array<string,mixed>
     */
    public function data(): array
    {
        return $this->data;
    }

    // ---- reads ------------------------------------------------------

    public function phase(): string
    {
        $phase = $this->data['phase'] ?? self::PHASE_NEW;
        return is_string($phase) && $phase !== '' ? $phase : self::PHASE_NEW;
    }

    public function batchId(): string
    {
        return self::str($this->data['batch_id'] ?? null);
    }

    public function inputFileId(): string
    {
        return self::str($this->data['input_file_id'] ?? null);
    }

    public function outputFileId(): string
    {
        return self::str($this->data['output_file_id'] ?? null);
    }

    /** Provider-reported status, or '' before a batch exists. */
    public function status(): string
    {
        return self::str($this->data['status'] ?? null);
    }

    /** Provider status with a sane fallback for the pre-submit phases. */
    public function displayStatus(): string
    {
        $status = $this->status();
        if ($status !== '') {
            return $status;
        }
        return match ($this->phase()) {
            self::PHASE_PREPARED, self::PHASE_UPLOADED => BatchLifecycle::VALIDATING,
            default => BatchLifecycle::UNKNOWN,
        };
    }

    public function requestCount(): int
    {
        return max(0, (int)($this->data['request_count'] ?? 0));
    }

    public function polls(): int
    {
        return max(0, (int)($this->data['polls'] ?? 0));
    }

    /** Consecutive polls that could not be read (reset by any good one). */
    public function pollFailures(): int
    {
        return max(0, (int)($this->data['poll_failures'] ?? 0));
    }

    public function cancelRequested(): bool
    {
        return ($this->data['cancel_requested'] ?? false) === true;
    }

    /** ISO-8601 UTC, or null. Used for the elapsed-time readout. */
    public function submittedAt(): ?string
    {
        $value = self::str($this->data['submitted_at'] ?? null);
        return $value === '' ? null : $value;
    }

    /** ISO-8601 UTC of the scheduled next poll, or null. Display only. */
    public function nextPollAt(): ?string
    {
        $value = self::str($this->data['next_poll_at'] ?? null);
        return $value === '' ? null : $value;
    }

    /**
     * One entry per submitted request:
     * `['custom_id'=>…,'step_id'=>int,'provider'=>…,'model'=>…,'role'=>…]`.
     *
     * @return list<array{custom_id:string,step_id:int,provider:string,model:string,role:string}>
     */
    public function participants(): array
    {
        $rows = $this->data['participants'] ?? null;
        if (!is_array($rows)) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) { continue; }
            $customId = self::str($row['custom_id'] ?? null);
            if ($customId === '') { continue; }
            $out[] = [
                'custom_id' => $customId,
                'step_id'   => (int)($row['step_id'] ?? 0),
                'provider'  => self::str($row['provider'] ?? null),
                'model'     => self::str($row['model'] ?? null),
                'role'      => self::str($row['role'] ?? null),
            ];
        }
        return $out;
    }

    /** @return list<string> */
    public function customIds(): array
    {
        return array_values(array_map(
            static fn(array $p): string => $p['custom_id'],
            $this->participants(),
        ));
    }

    /** Lines the builder refused before upload, for the operator report. */
    public function rejectedLines(): array
    {
        $rows = $this->data['rejected_lines'] ?? null;
        if (!is_array($rows)) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) { continue; }
            $out[] = [
                'custom_id' => self::str($row['custom_id'] ?? null),
                'reason'    => self::str($row['reason'] ?? null),
            ];
        }
        return $out;
    }

    /** The parsed result report, when the accept phase has run. */
    public function resultReport(): array
    {
        $report = $this->data['result'] ?? null;
        return is_array($report) ? $report : [];
    }

    /**
     * The provider's own reason for a terminal non-`completed` status, or
     * '' when it did not give one. Never invented: an empty string means
     * the caller has to fall back to a generic sentence.
     */
    public function failureReason(): string
    {
        return self::str($this->data['failure_reason'] ?? null);
    }

    /**
     * Local validation refusals, keyed by `custom_id`, so the accept phase
     * can give a refused participant its real reason instead of reporting
     * it as an answer that never arrived.
     *
     * @return array<string,string>
     */
    public function rejectedReasons(): array
    {
        $rows = $this->data['rejected_reasons'] ?? null;
        if (!is_array($rows)) {
            return [];
        }
        $out = [];
        foreach ($rows as $id => $reason) {
            if (!is_string($id) || $id === '') { continue; }
            $out[$id] = self::str($reason);
        }
        return $out;
    }

    /** A batch id exists, so the provider owns work we must account for. */
    public function hasBatch(): bool
    {
        return $this->batchId() !== '';
    }

    public function isTerminal(): bool
    {
        return $this->phase() === self::PHASE_DONE;
    }

    /** Did the run reach a phase where the output file is known? */
    public function hasOutput(): bool
    {
        return $this->outputFileId() !== '';
    }

    // ---- the state machine ------------------------------------------

    /**
     * What should the next pickup do?
     *
     * Kept as one pure function of the persisted state — no clock, no
     * network, no database — because this is the piece that has to be
     * right after a restart, and it is the piece the test suite can drive
     * exhaustively.
     *
     * Precedence, and why:
     *
     *   1. `cancel` first. An operator who asked to stop a 150-second poll
     *      should not wait for the poll to come back before the provider is
     *      told to stop, and the request has to be sent while the batch is
     *      still active. A cancel recorded before the batch was created
     *      stops it from ever being created — the one case where cancelling
     *      saves the whole run's cost.
     *   2. `done`. A finished run is never re-entered, even if a later
     *      process finds a stale state file — the job row is the verdict.
     *   3. `accept`. An output file id, or a `completed` status, means the
     *      results are waiting; reading the status again would be a wasted
     *      round trip that can only lose information.
     *   4. `poll`. A batch exists and the provider still considers it
     *      active, or the last poll could not be read (unknown status is
     *      retried, never terminal).
     *   5. `prepare`. No batch id: either the first pickup, or a run that
     *      died between the upload and the create. Re-running the prepare
     *      is safe — it rebuilds the same document with the same
     *      `custom_id`s — and is the only way forward.
     */
    public function action(): string
    {
        if ($this->isTerminal()) {
            return self::ACTION_DONE;
        }
        // Cancel outranks everything else the run could do, including the
        // very first `prepare`: an operator who cancelled while the row was
        // still `new` must not have the batch submitted on the next pickup,
        // because a submitted batch cannot be un-billed. The cancel phase
        // copes with a missing batch id by simply not calling the provider.
        if ($this->cancelRequested()) {
            return self::ACTION_CANCEL;
        }
        if ($this->hasBatch()) {
            if ($this->hasOutput() || BatchLifecycle::hasOutput($this->status())) {
                return self::ACTION_ACCEPT;
            }
            // 'unknown' is the unreadable-poll marker: keep polling rather
            // than hazard a guess about a batch the provider is running.
            if (BatchLifecycle::isActive($this->status()) || $this->status() === '' || $this->status() === BatchLifecycle::UNKNOWN) {
                return self::ACTION_POLL;
            }
            // A terminal provider status we are not handling explicitly
            // (`failed`, `cancelled`) is a verdict to report, not a reason
            // to keep asking. `accept` reads the outcome file when there is
            // one and reports the failure when there is not.
            return self::ACTION_ACCEPT;
        }
        return self::ACTION_PREPARE;
    }

    /**
     * Seconds until the next poll, per the documentation's sizing rule
     * ({@see BatchLifecycle::pollDelaySeconds()}).
     *
     * A batch whose poll just failed backs off: the failing polls are
     * cheap to repeat but pointless to hammer, and the run is retried up to
     * {@see self::MAX_POLL_FAILURES} times before it is failed with the
     * provider's own error. `$baseDelay` is the caller's cadence; the
     * backoff multiplies it by the number of consecutive failures so a
     * 30-second cadence stretches to at most five minutes.
     */
    public function nextPollDelaySeconds(int $baseDelay): int
    {
        $failures = $this->pollFailures();
        if ($failures <= 1) {
            return max(1, $baseDelay);
        }
        return max(1, $baseDelay) * min($failures, self::MAX_POLL_FAILURES);
    }

    /** Have we given up on reading the batch status? */
    public function pollFailuresExhausted(): bool
    {
        return $this->pollFailures() >= self::MAX_POLL_FAILURES;
    }

    // ---- browser payload --------------------------------------------

    /**
     * The snapshot the UI renders. Additive by construction: the phase, the
     * status and the counters are what Chat and History need to say
     * "this run is waiting on the provider", and no prompt, message or
     * credential ever appears here.
     *
     * @return array<string,mixed>
     */
    public function toPublicArray(): array
    {
        $labels = [
            'phase'                => $this->phase(),
            'phase_label'          => self::phaseLabel($this->phase()),
            'status'               => $this->displayStatus(),
            'status_label'         => BatchLifecycle::label($this->displayStatus()),
            'batch_id'             => $this->batchId(),
            'input_file_id'        => $this->inputFileId(),
            'output_file_id'       => $this->outputFileId(),
            'request_count'        => $this->requestCount(),
            'participant_count'    => count($this->participants()),
            'polls'                => $this->polls(),
            'poll_failures'        => $this->pollFailures(),
            'cancel_requested'     => $this->cancelRequested(),
            'submitted_at'         => $this->submittedAt(),
            'next_poll_at'         => $this->nextPollAt(),
            'completion_window_h'  => self::COMPLETION_WINDOW_HOURS,
            'rejected_lines'       => $this->rejectedLines(),
            'result'               => $this->resultReport(),
            'error'                => self::str($this->data['error'] ?? null),
        ];
        // `awaiting` is the single boolean the UI keys on: a batch session
        // is neither finished nor broken while this is true.
        $labels['awaiting'] = !$this->isTerminal()
            && $this->hasBatch()
            && in_array($this->displayStatus(), [
                BatchLifecycle::VALIDATING,
                BatchLifecycle::IN_PROGRESS,
                BatchLifecycle::CANCELLING,
                BatchLifecycle::UNKNOWN,
            ], true);
        return $labels;
    }

    /** Operator-facing phase wording, shared by Chat and History. */
    public static function phaseLabel(string $phase): string
    {
        return match ($phase) {
            self::PHASE_PREPARED => 'Preparing the batch',
            self::PHASE_UPLOADED => 'Uploading the batch file',
            self::PHASE_SUBMITTED => 'Waiting for the provider',
            self::PHASE_ACCEPTING => 'Collecting the results',
            self::PHASE_ACCEPTED => 'Writing the results back',
            self::PHASE_DONE => 'Finished',
            default => 'Queued',
        };
    }

    private static function str(mixed $value): string
    {
        return is_string($value) ? $value : (is_scalar($value) ? (string)$value : '');
    }
}
