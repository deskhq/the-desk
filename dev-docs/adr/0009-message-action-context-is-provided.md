# ADR-0009: The message-action context is provided, not drilled

- Status: Accepted
- Date: 2026-07-31
- Relates to: epic architecture-hardening II (#1089), child #1092

## Context

`composables/useMessageActions.ts` was already a deep module: one options object in,
one flat facade of eleven actions out. All the friction was in getting a click to it.

Eleven actions (`react`, `vote`, `closePoll`, `pin`, `unpin`, `remind`,
`remindCustom`, `forward`, `jump`, `edit`, `delete`) were re-declared and
re-forwarded, unchanged, at every level between `pages/channels/Show.vue` and the
button that fires them: `ChannelPane` (21 props / 17 emits), `ThreadPanel` (15/14),
`MessageList` (22/16), `MessageRow` (19/16), `MessageActions` (8/10) — **85 props and
73 emits**, most of them the same eleven names spelled again. `Show.vue` bound the
identical twelve-line handler block twice, once for the pane and once for the panel.

The viewer's capabilities rode the same chain: `canReact` appeared in 8 `.vue` files,
`canModerate` in 12, `canPin` in 7. `lib/messageActions.ts` already defined
`MessageActionContext` — the right shape — but it was *reconstructed* from
individually-drilled booleans in three components. One concept even had two
spellings, `viewerTimeZone` and `viewerTimezone`, both computed and both passed down.

The cost was not the line count. It was that no module below `Show.vue` could be
mounted without it: a row's behaviour was only reachable by threading twenty props
through four intermediaries, so ten test files did exactly that.

## Decision

The viewer-and-channel scope and the action facade are **provided** by the channel
page and read through a **named accessor pair**, never a raw `inject`:

- `provideMessageActions(context)` / `useMessageActionsContext()` — who the viewer
  is, what the open channel lets them do, the single viewer time zone, and the
  eleven actions. Provided once, by the page.
- `provideMessageSubtree(scope)` / `useMessageSubtree()` — what one timeline answers
  differently: `inThread`, and the composer a mention lands in. `ChannelPane`
  provides `false` and the channel composer; `ThreadPanel` provides `true` and its
  own reply composer.
- `useMessageActionGuards()` — builds `MessageActionContext` from those two scopes
  plus the one fact only a row has: `pending`, which stays a row-local prop.

Intermediate modules declare none of the eleven. `ChannelPane`, `ThreadPanel`,
`MessageList`, `MessageRow` and `MessageActions` read the context where they use it.

**The accessors throw when no provider is above them.** That is the point of the
pair: raw `inject` returns `undefined` with no signal, and the failure would surface
as a dead button rather than a mount-time error.

Two affordances deliberately stay events, because they open UI the *timeline* owns
rather than performing a write: `startEdit` (swap a row for its inline editor) and
`requestDelete` (raise the confirmation). The writes they eventually reach —
`edit(message, body)` and `delete(message)` — go through the facade like everything
else, and the events are named apart from them so the distinction is legible.

## Consequences

- Props fall from 85 to 60 and emits from 73 to 15 across the five modules; the
  duplicated binding block in `Show.vue` is gone.
- `MessageRow` and `MessageActions` are mountable on their own against a stub
  facade, which `components/timeline/MessageRow.test.ts` does — no `Show.vue`
  involved. The ten suites that used to thread props now provide one facade and
  assert on its spies, and together they lost about 430 lines.
- One spelling of the viewer time zone (`viewerTimeZone`) across the whole app.
- A twelfth action (#524's "save for later") is one method on the facade, not a new
  prop and emit at five levels.
- **The cost, stated plainly:** provide/inject is implicit. A reader of
  `MessageRow.vue` cannot see where `scope.actions.pin` comes from by looking at its
  props. That is the trade this ADR makes, and it is why the accessors are named,
  typed, and throwing rather than a bare `inject(key)` — the seam is greppable, and
  a missing provider fails loudly at mount.
- Reactivity is the provider's job: the page provides a `reactive()` bag of
  computeds, so a capability revised mid-session (a channel switch, an archive)
  reaches every row.
