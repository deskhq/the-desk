# ADR-0003: One scope for the visible-channels ACL

- Status: Accepted
- Date: 2026-07-10
- Relates to: epic architecture-hardening (child: Visible-channels ACL)

## Context

"The channel ids a user may see in a team" is the entire authorization boundary for
message search, the thread inbox, unread indicators, and message forwarding. It was
reimplemented as an ad-hoc `pluck` in at least five places across three different
architectural strata (middleware, controller, Action, FormRequest). A change to what
"visible" means (e.g. excluding archived channels, honouring a block-list) would need
five synchronized edits, and one would be missed — a security-relevant divergence.

## Decision

The visible-channel id set is a single named scope/method on `User`
(e.g. `visibleChannelIds(Team)`). Every consumer — search, thread inbox, unread
dots, forwarding, placement — routes through it. No feature re-derives the ACL with
its own query.

## Consequences

- The authorization boundary has one greppable name and one test surface.
- Tightening the ACL is a single change that every consumer inherits.
- Passes the deletion test: the scope carries a reused security decision.
