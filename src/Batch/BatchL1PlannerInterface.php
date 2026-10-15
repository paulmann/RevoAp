<?php
/**
 * BatchL1PlannerInterface: what the batch orchestrator needs from the engine.
 *
 * The Batch path replaces exactly one stage of the pipeline — the L1 fan-out
 * — with an asynchronous round trip. Everything else (normalisation, the
 * consensus verdict, the synthesis) depends on the answers and therefore
 * cannot be submitted before them; it runs synchronously after the results
 * are accepted, in the same worker invocation.
 *
 * That boundary is what this interface describes. It is deliberately narrow
 * — two methods — so the orchestrator holds no engine internals and can be
 * exercised end to end with a stub planner: no connector registry, no
 * skills on disk, no network. The production implementation is
 * {@see \App\Pipeline\PipelineEngine}, which satisfies it with the very
 * helpers the synchronous L1 path uses (participant/role resolution, the
 * `L1.role.<role>@<model>` step keys, `AbstractConnector::buildChatRequest()`
 * for the request bodies, and the same `council_steps` /
 * `council_model_responses` writes), so a batch run and a synchronous run
 * of the same query leave the journal in the same shape.
 *
 * Contract notes that matter to the orchestrator:
 *
 *   * `prepare()` may return `ok=false` with a reason instead of throwing;
 *     a pool that cannot be batched (no models, no connector, no usable
 *     document) is an operator-fixable condition, and the reason is what
 *     the job report shows.
 *   * `prepare()` is expected to be idempotent in effect: calling it twice
 *     for the same session must not re-create step rows for participants
 *     whose `custom_id` already has one.
 *   * `complete()` must record EVERY participant it is handed — one that
 *     answered, one the provider refused, and one that never came back —
 *     because "no answer arrived" is the failure mode a `custom_id` map
 *     exists to detect.
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

interface BatchL1PlannerInterface
{
    /**
     * Build the batchable L1 fan-out for a session and create its step rows.
     *
     * @param int                       $sessionId
     * @param string                    $query
     * @param array<int,array{provider?:string,model?:string}>|null $requestedModels
     * @param array<string,mixed>       $options        Engine options (reasoning, cache_mode…).
     * @param int                       $jobId          Scope for the `custom_id`s.
     *
     * @return array{
     *     ok:bool,
     *     reason?:string,
     *     participants:list<array{custom_id:string,step_id:int,provider:string,model:string,role:string,index:int,body:array<string,mixed>}>,
     *     pool:list<array{provider:string,model:string}>,
     *     skipped:list<array{provider:string,model:string,reason:string}>
     * }
     */
    public function prepareBatchL1(int $sessionId, string $query, ?array $requestedModels, array $options, int $jobId): array;

    /**
     * Write accepted batch outcomes into the journal and finish the run.
     *
     * @param int   $sessionId
     * @param string $query
     * @param array<string,mixed> $options Engine options, as passed to prepare().
     * @param list<array{
     *     custom_id:string,
     *     error:?string,
     *     body?:array<string,mixed>,
     *     status_code?:int
     * }> $outcomes
     *        One row per submitted participant, in submission order. Exactly
     *        one of `error` / `body` is meaningful: `error` is the reason a
     *        participant produced no answer (the provider refused the row,
     *        local validation refused the line, or no row came back at all),
     *        and `body` is the raw chat-completions payload the
     *        implementation converts with the connector's own
     *        `buildChatResponseFromRaw()`.
     * @param list<array{custom_id:string,step_id:int,provider:string,model:string,role:string}>
     *        $participants
     *        The mapping `prepare()` returned, persisted with the job so the
     *        accept phase — which may run in another process, hours later —
     *        can turn a `custom_id` back into the step it belongs to.
     *        Passing it explicitly keeps this method free of batch-protocol
     *        knowledge while still being the only thing that needs the
     *        mapping.
     *
     * @return array{
     *     answered:int,
     *     failed:int,
     *     status:string,
     *     final_answer:string,
     *     synthesis_failure:array<string,mixed>|null,
     *     envelope:array<string,mixed>
     * }
     */
    public function completeBatchL1(int $sessionId, string $query, array $options, array $outcomes, array $participants = []): array;
}
