# ADR-0001: Record architecture decisions

- Status: Accepted
- Date: 2026-07-10

## Context

The codebase is well-factored, but architectural decisions (why one seam exists,
why a module is shaped a certain way) were undocumented. Architecture reviews and
new contributors kept re-deriving — and sometimes re-litigating — settled choices.

## Decision

We record significant architecture decisions as short ADRs in `docs/adr/`, numbered
sequentially (`NNNN-title.md`). Each ADR states Context, Decision, and Consequences.

An ADR is warranted when a decision is load-bearing enough that a future reviewer
would otherwise re-suggest the thing we deliberately chose against, or re-invent the
module we deliberately built. The shared vocabulary and the named seams themselves
live in [`CONTEXT.md`](../../CONTEXT.md); ADRs record the *decisions* behind them.

## Consequences

- `/improve-codebase-architecture` reads existing ADRs and does not re-surface a
  decision an ADR already settles.
- ADRs are append-only in spirit: supersede rather than edit, linking the successor.
