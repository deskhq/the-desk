# Domain & Architecture Context

This file is the shared vocabulary for the codebase. It exists so that new work
(new issues, new features) names concepts the way the rest of the code already
does — and reuses the **deep modules** we've deliberately built instead of
re-inventing shallow ones. Architecture reviews (`/improve-codebase-architecture`)
read this file first.

If you introduce a new concept or sharpen a fuzzy one while working, **update this
file in the same change**. Decisions that should not be re-litigated live as ADRs
in [`dev-docs/adr/`](dev-docs/adr/).

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
- **Visible-channels ACL** _(ADR-0003)_ — the channel authorization boundary,
  **two named readings on `User`** because two different questions share the word
  "visible". `memberChannelIds(Team)` (and `memberChannelIdsAcrossTeams()`) is
  *what is mine* — search, the thread inbox, unread dots, forwarding, placement.
  `readableChannels(Team)` / `readableChannelIds(Team)` is *what may I open* — in
  a team I belong to, and either public or one I belong to — behind
  `ChannelPolicy::view()` (the channel page) and, through
  `ApiChannelAccess::channelIds()`, the REST channel list. The split is
  deliberate: `browse` exists so a user discovers a public channel and *joins*
  it. Never re-`pluck` either, and never re-spell "public or member" at a call
  site; `tests/Unit/VisibleChannelsAclHomeTest.php` fails if you do.
- **Channel timeline window** _(ADR-0004)_ — the read-model/query object
  that resolves where a channel's initial message window opens (unread anchoring,
  jump context, paging). Takes explicit params; the controller keeps HTTP glue.
- **Workspace shell read-model** _(ADR-0008)_ — `WorkspaceShell` owns the
  `(authenticated, on a workspace route, with a bound team)` precondition and every
  read-model an in-workspace page draws its shell from (`SidebarChannels`,
  `SidebarReminders`, `ThreadInbox`, `MessageSearchPanel`, the emoji/group
  vocabularies). It is constructed from a `User` and a `Team`, never a `Request`, so
  every one of those is reachable from a test without an HTTP round-trip.
  `HandleInertiaRequests::share()` is glue: it names the 44 shared props — that list
  *is* the Inertia contract and stays spelled out there — and computes none of them.
  A new workspace prop is one line in `share()` plus one method on the shell; never
  re-derive "am I on a workspace page?" per prop, and never put a query in the
  middleware.
- **Domain-event recording** _(ADR-0005)_ — audit and security events are recorded
  via the event→listener seam, next to the mutation, never by a `record()` call in
  a controller. The Action dispatches `AuditableActionOccurred` (carrying team,
  actor, `AuditAction`, target and context) or `SecurityEventOccurred`;
  `RecordAuditActivity` and `RecordSecurityEvents` append the row. "Is this
  auditable?" is a rule of the mutation, so it lives in the Action too — a member
  joining a channel is not an add, a member deleting their own message is not
  moderation.
- **`AuditRecorder` / `SecurityEventRecorder`** — deep modules hiding the
  activity-log builder, each with exactly one caller: its listener. Keep them; a
  new call site is a sign the event seam was skipped. Neither depends on the live
  `Request` — `RecordSecurityEvents` captures the device context and passes it in,
  which is what lets a queued job record.
- **`ExportLifecycle`** — the one lifecycle every asynchronous file export runs:
  resolve-or-bail, write, mark ready, send the ready notice, and fail cleanly.
  `GenerateAuditExport` and `ExportUserData` are adapters over it, supplying only
  what differs. It owns the two answers the jobs used to disagree on (a throwing
  mailer never undoes a written export; a failed export keeps no archive
  metadata) and the `DISK` / `RETENTION_DAYS` constants. A new export type adapts
  in; it does not re-hand-roll the steps.
- **`ExpirySweep`** — the cursor walk every scheduled expiry sweeper runs, in the
  two shapes that exist: purge an expired export (file before row) and clear a
  lapsed profile instant (compare-and-swap, then broadcast). Sweepers stay one
  Action per concern, each with its own name, description and
  `withoutOverlapping()` in `routes/console.php`; only the walk is shared.
- **Channel membership** — `ChannelMembership` is the one module that reads and
  writes the channel-member pivot: star, mute, notification level, draft, close
  (hide) and sidebar placement are its columns, not five unrelated settings. It is
  constructed from a `Channel` and a `User`, never a `Request`, and every write
  carries the resolve-or-no-op rule — a non-member writes nothing rather than
  joining. `updateExistingPivot` appears nowhere else, and the pivot's column set
  is declared once, on `ChannelMember::PIVOT_COLUMNS`, which both `withPivot()`
  calls, the `#[Fillable]` and `SidebarChannels`' select read. One policy ability,
  `updateMembership`, gates the lot. Never re-derive the membership row with a
  `channelMembers()->where('user_id', ...)` of your own.
- **`DirectMessageRoster`** _(ADR-0011)_ — the one batched load of the memberships a
  direct message has to be read through before it can be named. A DM stores no name,
  so what it is called, whose avatars it stacks and which counterpart drives presence
  are all viewer-relative and read off its members — an N+1 by default. The sidebar,
  the thread inbox and search hits all load a page's rosters through this module, then
  let `Channel::displayNameFor()` / `directParticipantFor()` read the loaded relation.
  It belongs to none of the three, so never fold it into one of them. Its companion
  rule: a read-model DTO takes a `User $viewer` parameter — `auth()` in `app/Data/` is
  a defect, and `grep -rn "auth()" app/Data/` returning zero hits is what keeps it out.
  `SidebarChannelsQueryCountTest` pins the sidebar's cost the way `MessageLoadSetScopeTest`
  pins ADR-0002's.
- **`ManualOrder`** — the ownership-filter-then-reindex walk behind a sidebar
  drag, shared by channel placement and section reordering: ids the user does not
  own are dropped, and the survivors are reindexed in a single statement rather
  than one UPDATE per item.
- **Message thread-state scopes** (`Message::withThreadReadState`, `followedBy`) —
  exemplary depth; correlated-subquery logic hidden behind a scope that reuses one
  SQL constant so scope and filter can't disagree. The model to aspire to.
- **Channel-traffic predicate** _(ADR-0010)_ — "a thread-only reply is not ordinary
  channel traffic" lives once, on `Message`: `channelTraffic()` for a query,
  `channelTrafficSql()` for the one conditional aggregate a scope cannot express
  (`WorkspaceUnread`'s grouped tally), and `isChannelTraffic()` for a path holding a
  `MessageData` rather than a query (the push listener). It is the rule that decides
  whether a badge, a chime, a push and the timeline agree about one message, and it was
  spelled seven times before. **Opt-in per call site, deliberately** — `mention_count`
  must *not* carry it, because a mention inside a thread still badges its channel, so
  never fold it into `WorkspaceUnread::forChannelsOf()` or make it a global scope. Its
  client twin is `lib/channelTraffic.ts`, and both are pinned against one shared case
  table (`tests/Fixtures/channel-traffic-cases.json`).
- **Alert predicate** _(ADR-0010, second application)_ — "muted x notification level =>
  does this alert?" lives once, on `App\Enums\NotificationLevel`, with **two readings**:
  `alertsOnUnread($muted)` for ordinary traffic (only `all`) and `alertsOnMention($muted)`
  for a direct @mention (only `nothing` silences it). Each has a SQL twin —
  `alertsOnUnreadSql($membership)` / `alertsOnMentionSql($membership)`, parameterised only
  by the membership table's alias — that `WorkspaceUnread` and `Message`'s thread-unread
  SQL read rather than re-spell. **The two readings are not interchangeable**: applying the
  unread one to a mention is exactly the bug #1143 fixed, where a mention inside a thread
  on a "mentions only" channel badged the channel but raised no thread dot. Ask the
  question per *message*, not per channel, wherever the two can differ. Its client twin is
  `lib/alerts.ts`, and both are pinned against one shared case table
  (`tests/Fixtures/alert-cases.json`).
- **`UserAvailability`** — "is this person available, and until when?" lives once, reached
  through `User::availability($at)`. It owns every reading of the five columns that answer
  it: the manual pause (`pausedUntil()`), the recurring quiet-hours window and its snooze
  (`isInsideScheduleWindow()`, `snoozedUntil()`, `scheduleClosesAt()`), the custom-status
  expiry (`hasLiveStatus()`), the manual away override the live connections otherwise
  decide (`presence()`), and `isDnd()` over the lot. **It takes the instant and the
  `PresenceRegistry`, it does not fetch them** — that is what makes the midnight wrap, the
  timezone-relative wall clock and the snooze a pure unit test rather than a travelled
  clock and a saved row, and it is why no `app(...)` call remains in a `User` accessor.
  The columns stay declared on `User` (Eloquent's serialisation contract is the model's),
  but each accessor is one delegation; never read `dnd_until`, `dnd_starts_at` or
  `presence_state` and decide for yourself. Its client twin is `lib/dnd.ts`, and both are
  pinned against one shared case table (`tests/Fixtures/availability-cases.json`) —
  ADR-0010's device, third application. Writes to those columns are Actions —
  `PauseNotifications`, `ResumeNotifications`, `SetDndSchedule`, `SnoozeDndSchedule`,
  `SetUserStatus`, `ClearUserStatus`, `SetPresenceOverride` — living in
  `app/Actions/Users/` beside the three scheduled sweeps that clear the same columns;
  `forceFill` appears in no controller.

### Frontend (`resources/js/`)

- **`lib/*.ts` pure helpers** — the canonical pattern: pure, deep, each paired with
  a `*.test.ts`. New pure logic (formatting, parsing, decisions) goes here, not
  into a `.vue` setup block. Examples: `messageBody`, `reactions`, `shouldChime`,
  `unreadDivider`, `readReceipts`, `scheduleTime`.
- **`lib/channelTraffic.ts`** _(ADR-0010)_ — the client half of the channel-traffic
  rule above: `isChannelTraffic(message)`, called by the chime, the sidebar-badge
  refresh and `messagePlacement`, so a live append, a badge and the server's own
  paging cannot disagree about which messages belong to a channel. It shares its case
  table with the server's tests, so a change made on one side of the wire turns the
  other side's suite red. Answering "is this a reply?" or "which of these is the root?"
  is a *different* question — read `threadRootId` for those; this helper is only for
  the channel-traffic one.
- **`lib/alerts.ts`** _(ADR-0010)_ — the client half of the alert predicate above:
  `alertsOnUnread(channel)` and `alertsOnMention(channel)`, called by the chime, the
  mobile unread rollup and the live thread dot, so no surface re-derives "does this alert?"
  from `muted` and a level string. It shares its case table with the server's tests. Which
  level a membership is *at* is a different question — `lib/notificationIndicator.ts` maps
  all three to an icon and deliberately does not go through this.
- **`lib/dnd.ts`** _(ADR-0010 device, third application)_ — the client half of
  `UserAvailability` above: `isDndActiveNow(dnd, timeZone, at)` and
  `quietHoursEndsAt(...)`, called by the chime gate, the user menu and the settings page,
  because those need the answer at message-arrival time rather than at page-load time. It
  shares its case table with the server's tests, so a rule changed on one side of the wire
  turns the other side red — the docblock used to ask whoever edited it to keep the
  semantics in lockstep, which is an instruction, not a mechanism. The strip helpers
  (`quietHoursSegments`, `quietHoursTicks`) are presentation and have no server twin.
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
- **`useAutocompleteMenu` + `AutocompleteListbox`** — the composer's one
  autocomplete engine and the one listbox that renders it. The engine owns the
  wrap-around active row, the open/close protocol, selection, and the listbox
  ARIA contract (`useAutocompleteAria` is what the field's combobox attributes
  are read off, so it never learns which autocompletes exist). An adapter
  supplies only what differs: `useComposerMentions` the `@` token grammar and
  the roster, `useComposerSlashCommands` the `/name` grammar and the server's
  manifest. Neither declares a `moveActive` / `showMenu` / `close` of its own,
  and a third autocomplete is an adapter plus a row template — not a second
  keyboard model. The commands that open a surface instead of posting text
  (`useComposerGifPicker`, `useComposerPollBuilder`) declare a `PickerCommand`
  each, which is how the slash adapter diverts to them without knowing either
  exists.
- **The message-action context** _(ADR-0009)_ — every action a message row can ask
  for (`MessageActionHandlers`: the eleven writes, plus the `reply` and `openThread`
  navigations that rode the same relay) and the viewer's channel capabilities are
  **provided** by the channel page and read through `useMessageActionsContext()` /
  `useMessageSubtree()` / `useMessageActionGuards()`, never drilled and never a raw
  `inject`. A new message action is one method on the facade, not a prop and an emit
  at five levels; `pending` is the only per-row fact and stays a prop. The accessors
  throw without a provider, which is what makes a row mountable against a stub
  facade.
- **`ScrollableMessageList`** — the shared scroll container + "jump to latest / N
  new" pill for the channel view and the thread panel. The pin decision core stays
  in `useScrollPin` (each consumer owns how appends reach it and wires the pin
  outputs into its own realtime/unread machinery); the module owns the duplicated
  markup, taking pin state as props and handing the scroll element back through a
  `register-container` function ref so the consumer's `useScrollPin` binds the same node.
- **`useReminders`** — the one facade over every message-reminder write: set,
  snooze, clear, clear-all and open. Set and snooze are the same `updateOrCreate`
  write, so the snapshot → post → toast-with-Undo triple is written once here
  rather than at each call site, and the inverse stays `useReminderUndo`'s.
  A new reminder surface calls it; it does not hand-roll a `router.post` with its
  own toast.
- **`useOptimisticWrite`** — the one module behind every write that shows its
  outcome before the server has agreed: capture → apply → post →
  restore-on-error → toast, plus the visit options an optimistic write always
  wants (`preserveScroll`, `preserveState` — it is not a navigation) and the
  two-stream case, where a message on screen in both the timeline and the thread
  panel has to roll back in both (`snapshotStreams`). A call site supplies only
  what differs: what to snapshot, what to show, where to post, which props that
  invalidates, and what to say when it fails. The line is behavioural — a write
  with *nothing* to roll back stays a plain `router.post`, because it would gain
  uniformity and nothing else. `useReminders` is the reference: it is where the
  lesson was first written down, and its snapshot feeds an *Undo* rather than a
  rollback, which is why it stays its own shape.
- **`lib/reloadProps`** — the prop sets a write invalidates, named once each
  (`CHANNEL_LIST_PROPS`, `CHANNEL_SECTION_PROPS`, `COLLAPSED_SECTION_PROPS`,
  `PIN_PROPS`, `SCHEDULED_MESSAGE_PROPS`, `THREAD_PROPS`). The pairs are the
  sharp edge: `pins`/`pinCount` are two readings of one fact, so a surface
  refreshing one goes stale against the other. `reloadProps.test.ts` fails on a
  second copy of any of them, so a new `only: [...]` is an import, never an
  array literal.
- **`lib/reminderReload`** — the props a reminder mutation invalidates
  (`reminders` + `firedReminders`) and the visit options carrying them. The two
  move together, so a surface refreshing one without the other goes stale; the
  set is named here and nowhere else (`reminderReload.test.ts` fails on a second
  copy). The seventh set, kept beside the six above because the visit options
  belong with it.
- **`useDialog` + `DialogHost`** — the shell's dialog registry. Open state is one
  module-scoped entry per dialog, and every one of them is mounted once by
  `DialogHost`; a dialog is opened by name from wherever the gesture is, never by
  an emit chain climbing to whoever owns the modal. A new shell dialog is an entry
  in `SHELL_DIALOGS` plus a mount in the host — not a new composable and a new
  mount site. `useToast` is the model both follow.
- **The shell composables** (`useShellFocus`, `useShellShortcuts`,
  `useShellStartup`, `useChannelUploadToasts`) — what the workspace shell *does*,
  as opposed to what it renders. `MainLayout.vue` is a mount point: it makes no
  `router` call and holds no dialog state of its own, and its test asserts both
  (#1093). Behaviour that needs the shell to exist goes in one of these, not into
  its setup block.
- **`ConfirmDialog`** — one confirmation-dialog module the
  leave/remove/cancel/delete/transfer modals are thin call-sites of. A small
  interface (`submit: { visit } | { form }`, `#trigger`/`#description`/`#body`
  slots) hides the shared skeleton, the pending/disable wiring, close-on-success,
  focus-on-error, and clean-form-on-reopen.

---

## Where new work goes (quick reference)

- New pure logic (format/parse/decide) → a `lib/*.ts` with a paired test.
- New realtime behaviour → a composable with a seam + a pure decision core in
  `lib/`; never inline in a page.
- New channel message payload → the **Message load-set scope**.
- New "which channels can this user see" check → the **Visible-channels ACL**,
  after deciding which reading you mean: *is it mine* (`memberChannelIds`) or
  *may I open it* (`readableChannels`).
- New auditable mutation → give it an Action if it has none, then dispatch
  `AuditableActionOccurred` from it — the **domain-event seam**, next to the
  mutation.
- New channel-member preference → the **channel membership settings** concern.
- New message action (a row affordance that writes) → one method on the **message-action
  context**; never a new prop/emit pair through the timeline.
- A write that shows its outcome before the server answers →
  **`useOptimisticWrite`**; never a hand-rolled snapshot / `onError` restore /
  toast triple. A write with nothing to roll back stays a plain `router` call.
- The props a write invalidates → a named set in **`lib/reloadProps`**; never an
  inline `only: [...]` array.
- New reminder behaviour → **`useReminders`**; new reminder *props* → the set in
  **`lib/reminderReload`**.
- A new dialog the shell puts over itself → an entry in **`useDialog`**'s
  registry plus a mount in **`DialogHost`**.
- A `.vue` file crossing ~400 lines or owning several independent lifecycles →
  decompose into composables before adding more. `max-lines` enforces this and
  the grandfather list is empty, so there is no exemption left to join.
