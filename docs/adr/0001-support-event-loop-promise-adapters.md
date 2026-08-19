# Support Event-Loop Promise Adapters

## Status

Accepted

## Context

DataLoader dispatches promise batches through adapters. Existing adapters expose
promises with a `then()` method or provide a synchronous drain operation. Amp v3
Futures expose neither behavior. They require event-loop scheduling and explicit
observation.

Putting Amp and Revolt checks in DataLoader would couple its batching logic to one
promise implementation. Other event-loop promise adapters would require more
concrete checks in the same module.

## Decision

Add `AsyncPromiseAdapterInterface` as an optional extension of
`PromiseAdapterInterface`. It lets an adapter enqueue a batch dispatch and observe
a promise without exposing implementation-specific promise or event-loop types to
DataLoader.

Existing adapters continue to implement `PromiseAdapterInterface` without
changes. `AmpFutureAdapter` implements the optional interface and owns its
scheduling, observation, pending-work draining, and cancellation behavior.

## Consequences

DataLoader does not depend directly on Amp or Revolt classes. New event-loop
adapters can use the same seam without changes to DataLoader. Async adapters must
track scheduled and observed work so a no-argument `await()` drains all work and
does not leave unhandled failures.
