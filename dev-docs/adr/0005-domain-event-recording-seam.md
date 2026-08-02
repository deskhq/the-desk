# ADR-0005: Record audit & security events via the event→listener seam

- Status: Accepted — applied across every recording site (2026-07-30)
- Date: 2026-07-10
- Relates to: epic architecture-hardening (audit locality; supersedes divergent recorders)

> **Status note (2026-07-30).** As accepted, this landed on `Actions/Integrations/*`
> only: 23 `AuditRecorder` call sites across 11 controllers and 6
> `SecurityEventRecorder` sites stayed where they were, and `ChannelMemberAdded`,
> `ChannelMemberRemoved`, `ChannelCreated` and `ChannelArchived` were each recorded
> by two or three controllers with identical bodies while the Action already
> dispatched the webhook half. It is now applied everywhere: every auditable
> mutation dispatches `AuditableActionOccurred` and every account event dispatches
> `SecurityEventOccurred`, both from the Action that owns the mutation, and
> `RecordAuditActivity` / `RecordSecurityEvents` are their only recorders.
> `SecurityEventRecorder` no longer takes the `Request` — the listener captures the
> device context, so recording works from a queued job.

## Context

The app has two structurally identical concerns — appending a row that describes
something a user did — built with *opposite* seams. Security events flow through an
event→listener (`RecordSecurityEvents`): decoupled and testable by dispatching an
event. Audit events were recorded by direct `AuditRecorder::record()` calls wired
into six controllers, often carrying the "is this auditable?" condition
(`$oldRole !== $newRole`, moderation checks). The mutation lives in an Action; the
audit that must accompany it lived in the controller — so a future non-HTTP caller
of that Action (a command, a job) would silently skip the audit.
`SecurityEventRecorder` also coupled itself to the live `Request` in its
constructor, making it un-resolvable from a job.

## Decision

Recording "what happened" uses one seam: the mutation (in its Action) dispatches a
domain event, and a listener records the audit/security row. Recording does not live
in controllers, and the recorder does not depend on the live `Request`. The existing
`RecordSecurityEvents` listener is the reference shape.

`AuditRecorder` / `SecurityEventRecorder` stay as deep modules hiding the
activity-log builder — only *where they are invoked from* changes.

## Consequences

- "This mutation is auditable" has one home: next to the mutation.
- Every caller of an Action gets the audit for free; new callers can't forget it.
- Auditing works from jobs/commands, not only HTTP.
