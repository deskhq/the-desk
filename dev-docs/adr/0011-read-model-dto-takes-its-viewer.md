# ADR-0011: A read-model DTO takes its viewer, and batches its roster

- Status: Accepted
- Date: 2026-07-31
- Relates to: epic architecture-hardening III ([#1110](https://github.com/deskhq/the-desk/issues/1110), child: [#1113](https://github.com/deskhq/the-desk/issues/1113))

## Context

`ChannelData::fromChannel()` was the only thing in `app/Data/` that read ambient auth:

```php
$viewer = auth()->user();
```

Every sibling DTO already takes its viewer explicitly — `MessageData::fromMessage($message,
$viewerId, ...)`, `ThreadInboxItemData::fromMessage($message, $viewer)`,
`MessageSearchResultData::fromMessage($message, $snippet, $viewer)`. This one could not be
built for a viewer who was not the authenticated user, so its tests had to `actingAs` and
then re-fetch the channel with `->fresh()` to shed the roster the previous assertion had
loaded. The dependency was invisible in the signature and unavoidable at the call site.

It also N+1'd the payload it feeds. `SidebarChannels::query()` is documented as *"The single
query behind the list"*, and then every row it produced re-entered the database to work out
what a DM is called for the viewer: one to two queries per 1:1 through
`Channel::directParticipantFor()`, one per group DM through an inline `members()` query. A
sidebar with 15 DMs cost 15-30 extra queries **on every workspace request**, silently —
`Model::preventLazyLoading()` is set nowhere.

Underneath both was one question, "what is this DM called, for this viewer?", answered three
times. `ThreadInbox` batched the rosters and asked `Channel::displayNameFor()`; the search
path asked `displayNameFor()` but loaded only `channel.team`, so it lazy-loaded a membership
per DM hit; `ChannelData` re-derived the name inline and queried per row. Two of the three
were wrong, and each was wrong in its own way.

## Decision

**A read-model DTO takes its viewer as a parameter.** `auth()` (and `request()`, and any other
ambient accessor) in `app/Data/` is a defect, not a convenience: it turns a mapping into
something that can only be built inside a request, and hides from the signature the one input
the output most depends on. The viewer is a parameter of the mapping, so it is a parameter of
the method.

**The roster a viewer-relative name is read from is batched for a page, in one place.**
`App\Support\DirectMessageRoster` loads the memberships of the DMs in a page of channels (or
of a page of messages' channels) in a single query, and `Channel::displayNameFor()` /
`Channel::directParticipantFor()` read off the loaded relation. All three consumers — the
sidebar, the thread inbox, and search hits — call it. It deliberately does **not** live inside
`SidebarChannels`: parking it in the consumer that happened to be worst would have left the
other two re-deriving.

**Naming a channel is the model's answer, not each DTO's.** `Channel::displayNameFor()` is the
only implementation; a DTO that needs a viewer-relative channel name calls it.

## Consequences

- `ChannelData` is a pure mapping. Its tests construct it for an arbitrary viewer, with no
  `actingAs` and no `->fresh()` (`tests/Integration/Data/ChannelDataTest.php`), and one arrange
  now serves every viewer in it.
- The sidebar payload costs a constant two queries — the list, and the one batched roster load
  — however many DMs it lists, and `tests/Integration/Support/SidebarChannelsQueryCountTest.php`
  pins that the way `MessageLoadSetScopeTest` pins ADR-0002. Without the pin the N+1 comes back.
- The search path stops lazy-loading a membership per DM hit, which nothing was measuring.
- `ChannelData::collect()` is no longer usable: a magic-method DTO cannot be handed a second
  argument by `collect()`, so call sites map explicitly. That is the point — a payload built
  for *nobody in particular* is exactly the bug this removes.
- **This generalises to all 57 `Data` classes, not just this one.** `ChannelData` was the only
  offender when the rule was written (`grep -rn "auth()" app/Data/` returns zero hits), and the
  grep is what keeps it that way. A new DTO that reaches for `auth()->user()` should take a
  `User $viewer` instead.
