# ADR-0002: Own the message eager-load set in one scope

- Status: Accepted
- Date: 2026-07-10
- Relates to: epic architecture-hardening (child: Message load-set scope)

## Context

`MessageData::fromMessage()` is the canonical read-model for a message and decides
which relations it reads. But the knowledge of *what to eager-load so it doesn't
N+1* was hand-written at six unrelated call sites (channel timeline, thread payload,
search, post, edit, broadcast). The relation lists had already drifted out of sync.
This is information leakage: one design decision spread across modules that must
change together.

## Decision

There is exactly one place that declares the relations `MessageData` needs — a query
scope on `Message` (e.g. `scopeWithMessageDataRelations`). Every code path that turns
a `Message` (or query) into `MessageData` routes through it.

Hand-writing a `with([...])` relation list for a message payload is not allowed;
add the relation to the scope instead.

## Consequences

- Adding a relation to `MessageData` is a one-line change in one place.
- The N+1 contract is testable in isolation and cannot silently diverge per caller.
- Passes the deletion test: the scope concentrates a real, reused contract.
