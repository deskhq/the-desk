# ADR-0005: Record audit & security events via the event→listener seam

- Status: Accepted
- Date: 2026-07-10
- Relates to: epic architecture-hardening (audit locality; supersedes divergent recorders)

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
