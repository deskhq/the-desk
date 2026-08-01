# ADR-0010: The channel-traffic predicate has one home per language

- Status: Accepted
- Date: 2026-07-31
- Relates to: epic architecture-hardening III ([#1110](https://github.com/deskhq/the-desk/issues/1110), child: [#1114](https://github.com/deskhq/the-desk/issues/1114))

## Context

One domain rule — **a thread-only reply is not ordinary channel traffic** — was spelled
seven times, in two languages, in five different forms:

| # | location | form |
|---|---|---|
| 1 | `app/Listeners/SendMessagePushNotifications.php` | PHP expression on a DTO |
| 2 | `app/Support/WorkspaceUnread.php` | raw SQL inside a `selectRaw` aggregate |
| 3 | `app/Support/SidebarChannels.php` | builder closure |
| 4 | `app/Support/ChannelTimelineWindow.php` | builder closure |
| 5 | `resources/js/composables/useChimeNotifications.ts` | TS expression |
| 6 | `resources/js/composables/useSidebarBadges.ts` | TS expression |
| 7 | `resources/js/lib/messagePlacement.ts` | TS expression, via a local `isReply` |

Six of the seven had no test. The push listener even wrote the rule out in prose before
restating it in code, which is what a rule with nowhere to live looks like.

This is the highest-stakes rule in the epic, because it is the one that decides whether a
**badge, a chime, a push notification and the timeline agree about the same message**.
Those four surfaces are the whole of what a user experiences as "did this message reach
me?", and they were four independent transcriptions. Changing what counts as channel
traffic meant seven synchronised edits across PHP, SQL and TypeScript, and **nothing
failed if you missed one** — the failure would surface as a badge disagreeing with a
timeline, weeks later, for one message shape.

The rule also has a deliberate asymmetry that a careless fix would erase.
`SidebarChannels` documents that `mention_count` does **not** carry the thread filter — *"a
mention anywhere, including inside a thread, still badges the channel"* — while
`unread_count`, built from the very same shared sub-query, does. So the rule cannot simply
be pushed down into `WorkspaceUnread::forChannelsOf()`: that would take the mention count
with it, and a mention nobody was badged for is the worst outcome this module has.

## Decision

**The rule has one home per language, and both are named after the rule rather than after
the columns it reads.**

Server, in `app/Models/Message.php`:

- `Message::CHANNEL_TRAFFIC_SQL` — the rule, once, as SQL.
- `Message::channelTraffic()` — the query scope every filtering call site uses. It is
  `whereRaw` over that constant, so the scope and the constant cannot disagree. This is the
  shape `Message::followedBy` / `withThreadReadState` already established and that
  `CONTEXT.md` calls "the model to aspire to".
- `Message::channelTrafficSql()` — the same constant, for the one caller that needs the
  fragment inside an expression rather than as a `where`. `WorkspaceUnread` counts channel
  traffic and mentions in a single grouped query, so its unread half is a conditional
  aggregate (`sum(case when … then 1 else 0 end)`), which no scope can express.
- `Message::isChannelTraffic(?string $threadRootId, bool $sentToChannel)` — the in-memory
  twin, for the paths that hold a payload rather than a query. The push listener decides
  from the broadcast `MessageData`; re-reading the row to ask the database would be a query
  per message per send.

Client, in `resources/js/lib/channelTraffic.ts`: `isChannelTraffic(message)`, the pure
helper the chime, the sidebar refresh and the timeline placement all call. This mirrors
`PushDecision` ↔ `shouldChime` — the app already accepts that one rule may need a server
statement and a client statement — and `lib/reminderReload.ts`, whose test fails on a
second copy of the prop set.

**The predicate stays opt-in per call site.** It is not folded into
`WorkspaceUnread::forChannelsOf()`, nor made a global scope, precisely because
`mention_count` must not have it. The asymmetry is the feature; a call site asks for the
rule when the rule applies.

**Three implementations, one specification.** `tests/Fixtures/channel-traffic-cases.json`
holds the case table — all four combinations of the two facts the rule reads — and is read
by both `tests/Integration/Models/ChannelTrafficPredicateTest.php` (the scope and the
in-memory twin, the scope proven against real rows) and
`resources/js/lib/channelTraffic.test.ts` (the client twin). Neither suite owns the table,
so a row added or flipped on one side of the wire has to satisfy the other.

## Consequences

- Changing what counts as channel traffic is now **two edits** — one per language — and the
  shared case table fails the *other* language's suite if only one is made. It was seven
  edits with no failure at all.
- The raw-SQL copy in `WorkspaceUnread` is gone; it references the shared fragment. The two
  builder closures and the listener's expression are gone too, replaced by the scope and
  the in-memory twin.
- Six previously untested sites are now covered by the tests the one home carries, plus two
  new pins on the surfaces the rule decides: `WorkspaceUnreadTest` now states that a
  thread-only reply does not tally for a workspace and that a **mention** inside one still
  does, and `SidebarChannelsTest` states the same asymmetry per channel. The workspace
  tally had no thread coverage whatsoever — the rail's dot could have been counting thread
  replies indefinitely and agreed with nothing.
- `tests/Unit/ChannelTrafficPredicateHomeTest.php` fails if an eighth spelling appears in
  `app/` or `resources/js/`, matching all five forms the seven copies actually took.
  **Stated plainly: it is a tripwire, not a proof.** A copy written in a shape none of the
  patterns anticipate escapes it, and the guard deliberately matches only the
  *disjunction* — `thread_root_id` alone is a legitimate question ("is this a reply?"), and
  so is `threadRootId === null` ("which of these is the root?", which `ThreadPanel.vue` and
  `MessageList.vue` both ask, and which is **not** this rule). The specification is the case
  table; the guard only catches the lazy way back.
- **The cost: the rule is stated twice on the server**, once as SQL and once in PHP, and the
  compiler cannot connect them. That is not avoidable — one call site holds a query, another
  holds a DTO — so the mitigation is that both live in the same file, adjacent, and are
  proven against the same table. The alternative, having the listener re-query, trades a
  provable duplication for a per-message query.
- **The tempting alternative — "it is one boolean, just inline it" — is exactly what produced
  the seven copies.** Each of them was individually reasonable: two lines, obvious at the
  call site, no indirection. It is the seventh that is expensive, and by then no single edit
  is where the cost shows up.
- The five server spellings of **"unread"** are deliberately left alone. Their
  archived/muted/`nothing` exclusions genuinely differ per call site and at least one of
  those differences is intended, so collapsing them is a behaviour change, not a mechanical
  one. That is a separate decision from this one.
