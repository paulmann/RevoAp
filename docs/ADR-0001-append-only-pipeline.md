# ADR-0001 — Forward-only, append-only pipeline

## Context
The 4-layer council pipeline (L1 distribution → L2 normalization →
L3 consensus → L4 synthesis) needs to provide a complete audit trail
that an end-user can replay. Earlier prototypes mutated step records as
the pipeline progressed, which made debugging hard and made it
impossible to safely render incremental updates in the browser.

## Decision
Pipeline state is **append-only**:

- Each step is created once with an `order_index`. After creation only
  `status`/`summary`/`ended_at` may transition; no other fields ever
  change.
- Every state transition emits a new row in `pipeline_events`. Events
  are immutable.
- The frontend appends new DOM nodes for each event/step; it never
  modifies an already-rendered card.
- Loop detection is structural: when the same `step_key` is observed
  twice during a session, a `LOOP_DETECTED` event is emitted and the
  pipeline halts.

## Consequences
- Simpler reasoning about pipeline progress (write-once semantics).
- Trivial to stream progress to UIs and external observers.
- Slightly higher write count vs. update-in-place — acceptable for the
  expected throughput.

## Status
Accepted, 2026-05-05.
