# Domain & Architecture Context

This file is the shared vocabulary for the codebase. It exists so that new work
(new issues, new features) names concepts the way the rest of the code already
does — and reuses the **deep modules** we've deliberately built instead of
re-inventing shallow ones. Architecture reviews (`/improve-codebase-architecture`)
read this file first.

If you introduce a new concept or sharpen a fuzzy one while working, **update this
file in the same change**. Decisions that should not be re-litigated live as ADRs
in [`docs/adr/`](docs/adr/).

---

## Architecture vocabulary

Use these terms exactly. Don't drift into "service", "component" (except a Vue
component), "layer", "wrapper", or "boundary" when you mean one of these.

- **module** — a unit that hides complexity behind an interface (a class, an
  Action, a composable, a `lib/*.ts` helper).
- **interface** — the surface a caller must understand to use a module.
- **depth** — a **deep** module has a small interface hiding a lot of
  implementation; a **shallow** module has an interface nearly as wide as its
  implementation (it hides almost nothing).
- **seam** — a clean point where behaviour can be substituted or tested.
- **leverage** — how much reuse/power a module gives per unit of interface.
- **locality** — related logic living together, so a reader or bug-hunter doesn't
  bounce between files.
- **deletion test** — would deleting this module *concentrate* complexity (good —
  it was carrying weight) or just *move* it around (bad — it was a shallow
  pass-through)?

---

## Domain glossary

The nouns the product is built from.

- **Team** — a workspace. A user belongs to teams through a **Membership**.
- **Membership** — the pivot between a User and a Team; carries role
  (Owner/Admin/Member). Creating one auto-joins the team's `#general`.
- **Channel** — a conversation space inside a Team. A user's relationship to a
  channel (membership, star, mute, notification level, draft, placement) lives on
  the **channel-member pivot**.
- **Message** — a post in a channel. May reply into a **Thread**, forward another
  message, mention users, carry reactions and link previews. `MessageData` is the
  canonical read-model DTO.
- **Thread** — the reply tree hanging off a root Message. Read state is tracked
  per-thread (`ThreadRead`).
- **Scheduled message** — a Message queued to send later.
- **Reaction** — an emoji a user attaches to a Message.
- **Audit activity** / **Security event** — append-only records of what a user
  did (workspace admin actions vs. account-security actions respectively).

---

## Named seams — the deep modules to build and reuse

These are the deep modules that carry the codebase's real weight. **When new work
touches one of these concerns, route through the named module — do not re-inline
the logic.** Items still marked _(planned)_ are deferred follow-ups not yet
built; build them once, then reuse.

### Backend (`app/`)

- **Message load-set scope** _(ADR-0002)_ — the single query scope that
  eager-loads exactly the relations `MessageData::fromMessage()` reads. Every
  timeline / thread / search / broadcast / edit payload goes through it, so the
  N+1 contract has one home. Never hand-write the `with([...])` relation list.
- **Visible-channels ACL** _(ADR-0003)_ — one scope on `User` returning
  the channel ids a user may see in a team. This *is* the authorization boundary
  for search, the thread inbox, unread dots, and forwarding. Never re-`pluck` it.
- **Channel timeline window** _(ADR-0004)_ — the read-model/query object
  that resolves where a channel's initial message window opens (unread anchoring,
  jump context, paging). Takes explicit params; the controller keeps HTTP glue.
- **Domain-event recording** _(ADR-0005)_ — audit and security events are recorded
  via the event→listener seam (`RecordSecurityEvents`), next to the mutation, not
  by inline `record()` calls in controllers.
- **`AuditRecorder` / `SecurityEventRecorder`** — deep modules hiding the
  activity-log builder. Keep them; only change *where they're called from*.
- **Channel membership settings** — star, mute, notification level, draft, and
  placement are all mutations of the channel-member pivot; treat them as one
  concern, not five unrelated ones.
- **Message thread-state scopes** (`Message::withThreadReadState`, `followedBy`) —
  exemplary depth; correlated-subquery logic hidden behind a scope that reuses one
  SQL constant so scope and filter can't disagree. The model to aspire to.

### Frontend (`resources/js/`)

- **`lib/*.ts` pure helpers** — the canonical pattern: pure, deep, each paired with
  a `*.test.ts`. New pure logic (formatting, parsing, decisions) goes here, not
  into a `.vue` setup block. Examples: `messageBody`, `reactions`, `shouldChime`,
  `unreadDivider`, `readReceipts`, `scheduleTime`.
- **`useMessageStream`** — deep composable: a simple `appendLive`/`applyPatch`
  interface hiding a three-source merge engine. The model for composables.
- **`useChannelRealtime`** _(ADR-0006)_ — owns the channel's Echo
  subscribe/route/teardown and feeds the message streams; its placement decisions
  push into a pure `lib/` helper. Realtime wiring never lives inline in a page.
- **`useChannelFleetSubscription`** _(ADR-0006)_ — one engine for
  subscribing to a set of channels (sidebar badges, chimes, and the active
  channel all share it). One tested reconcile/teardown lifecycle.
- **`useDebouncedPost`** — the debounced, focus-gated, auto-teardown router POST
  used by mark-read, mark-thread-read, and draft persistence.
- **`ScrollableMessageList`** _(planned)_ — the scroll container + `useScrollPin` +
  "jump to latest / N new" pill, shared by the channel view and the thread panel.
- **`ConfirmDialog`** _(planned)_ — one confirmation-dialog module the
  leave/remove/cancel/delete/transfer/archive modals become thin call-sites of.

---

## Where new work goes (quick reference)

- New pure logic (format/parse/decide) → a `lib/*.ts` with a paired test.
- New realtime behaviour → a composable with a seam + a pure decision core in
  `lib/`; never inline in a page.
- New channel message payload → the **Message load-set scope**.
- New "which channels can this user see" check → the **Visible-channels ACL**.
- New auditable mutation → record it via the **domain-event seam**, next to the
  mutation.
- New channel-member preference → the **channel membership settings** concern.
- A `.vue` file crossing ~400 lines or owning several independent lifecycles →
  decompose into composables before adding more.
