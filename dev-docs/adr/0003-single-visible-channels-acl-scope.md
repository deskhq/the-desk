# ADR-0003: One scope for the visible-channels ACL

- Status: Accepted — amended: the ACL has two readings, and `Api/V1` is in scope (2026-08-02)
- Date: 2026-07-10
- Relates to: epic architecture-hardening (child: Visible-channels ACL); epic IV (#1142, child #1144)

> **Status note (2026-08-02).** As accepted, this collapsed five `pluck` copies into
> one method — and then quietly acquired the problem it was written to prevent, because
> it did not anticipate that **two different questions** were sharing the name
> "visible", nor that `app/Http/Controllers/Api/V1/` was outside the sweep that
> applied it.
>
> `visibleChannelIds()` answered *membership* ("which channels are mine"), which is
> what search, the thread inbox, forwarding and sidebar placement want.
> `ChannelPolicy::view()` answered *readability* ("which channels may I open" — in a
> team I belong to, and either public or one I belong to), which is what the channel
> page wants. `Api/V1\ChannelController::index` was a raw third copy of the second,
> and it had already drifted: it omitted the team-membership half, so a personal
> access token outliving its holder's membership still enumerated the team's public
> channels while every `show` on them 404'd.
>
> The decision below still holds, with one correction: **there are two named
> readings, both on `User`, and no consumer re-derives either.**
>
> - `memberChannelIds(Team)` / `memberChannelIdsAcrossTeams()` — the membership
>   reading (search, the thread inbox, forwarding, `ChannelMembership` placement).
> - `readableChannels(Team)` / `readableChannelIds(Team)` — the readable reading
>   (`ChannelPolicy::view()`, and through `ApiChannelAccess::channelIds()` the REST
>   channel list). Returned as a query so the policy's per-channel `exists()` and the
>   list's id set derive from one expression.
>
> The split is intentional and stays: `browse` exists precisely so a user discovers a
> public channel and *joins* it, so widening search to surface messages from channels
> they never opted into is a product decision about privacy, not a refactor. A third
> question — "public and not a member", the browse listing itself — is genuinely
> different and stays local to `ChannelController::browse()`.
>
> `tests/Unit/VisibleChannelsAclHomeTest.php` fails if a third spelling of either
> reading appears anywhere in `app/`. Like its sibling in ADR-0010 it is a tripwire,
> not a proof: it matches the *disjunction*, because filtering on visibility alone
> and asking whether one user is in one channel are both legitimate questions it must
> stay quiet about.

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
