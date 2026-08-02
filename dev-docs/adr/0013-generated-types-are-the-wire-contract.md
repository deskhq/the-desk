# ADR-0013: The generated `App.Data.*` types are the wire contract

- Status: Accepted
- Date: 2026-08-02
- Relates to: epic architecture-hardening IV (#1142), child #1146

## Context

The server-to-client contract was declared twice.

`spatie/laravel-typescript-transformer` already emitted every `#[TypeScript]` DTO in
`app/Data/` into `resources/js/generated/generated.d.ts` — 52 `App.Data.*` types and 34
`App.Enums.*`, with nested shapes fully resolved:

```ts
export type ReactionData = {
  emoji: string,
  count: number,
  reactors: App.Data.MentionData[],   // resolved from the @param docblock
};
```

Alongside it, `resources/js/types/*.ts` hand-declared 82 types, of which **31 shadowed a
generated one** — most of them field for field: `Message`, `Reaction`, `Poll`,
`PollOption`, `ChannelReader`, `MessageReply`, `MessageForward`, `ThreadInboxItem`,
`MessageReminder`, `MessageSearchResult`, `Channel`, `UserProfile`, `TeamStorage`,
`WorkspaceAnalytics`, and six unions restating an enum's cases (`MessageType`,
`NotificationLevel`, `TeamRole`, `ChimeSound`, `AppLocale`, `SidebarPosition`). Two more
had drifted *off* their DTO while still meaning it — `ScheduledMessage` had lost
`clientUuid`, and `Passkey` had kept a shape the server stopped sending altogether.
Both vocabularies were live: 73 files referenced
`App.Data.*`, 283 imported from `@/types`, and only six files carried a single
`= App.Data.*` alias each. `types/messages.ts` was 449 lines and the #3 churn hotspot on
the frontend.

Spot-checked, the copies were field-identical to their DTO. So what the hand-written half
added was **prose, not structure** — and the prose belonged on the PHP DTO, where the
truth is. Meanwhile nothing checked the two against each other: a DTO field rename was
silent drift until something rendered wrong.

Two of the copies had already drifted, and both were invisible:

- `TeamPermissions` was missing `canManageEmojis`. The server shipped it with every team
  settings page; the client's type said it did not exist.
- `Mention` carried an optional `isBot` that `MentionData` has no field for, because the
  channel roster ships `UserData` and mention payloads ship `MentionData` — one
  hand-written type had been widened to blur two different server payloads together.

## Decision

**`App.Data.*` / `App.Enums.*` are the wire contract. A hand-written type in
`resources/js/types/` that restates one is a defect.**

Each type in that directory is now exactly one of three things:

1. **An alias** of the generated type — `export type Message = App.Data.MessageData`.
   This is the default, and it is what keeps the 283 import sites working untouched: the
   change is ~31 declarations, not a 283-file rewrite. `@/types` stays the import surface;
   only what it *means* changed.
2. **A rename**, when the client's type is deliberately *not* the DTO's shape.
   `Mention` (widened with `isBot`) split into `Mention = App.Data.MentionData` for the
   mention payload and `RosterMember = App.Data.UserData` for the channel roster the
   composer and facepile read — two server payloads, two names.
3. **A genuine client-only view model** — `MessagePage`, `ThreadInboxPage`,
   `MessageSearchCriteria`, `PersonRef`, `StackMember`, `Thread`. These invent something
   the server never sends (a paginated envelope, a URL echo, a structural minimum), and
   their names say so.

**Where the DTO under-specified a field, the DTO was sharpened rather than the client
being allowed to know better.** `ChannelData::$notificationLevel`, `LinkPreviewData::$status`
and `UserProfileData::$role` were `string` while the client spelled the union out by hand;
they are now the backed enums, so the union is generated. `TeamPermissions` gained
`#[TypeScript]`. The rule generalises: when an alias would lose a fact, add the type or the
`@param array<int, XData>` annotation on the PHP side.

**A prose docblock belongs on the PHP DTO, not on the alias.** The alias keeps a sentence
orienting the reader; anything the payload itself asserts is the DTO's to say, so both
sides of the wire read one sentence rather than two that can disagree.

## Consequences

- **Drift is now a compile error.** Renaming or removing a DTO field turns `vue-tsc` red at
  every site that reads it — that is the whole point, and it is checkable by hand in three
  commands. Rename `ReactionData::$count` to `$tally`, then:

  ```
  sail artisan typescript:transform && sail npm run types:check
  ```

  reports six errors, one of them in shipped code —
  `MessageReactions.vue(103,34): Property 'count' does not exist on type 'ReactionData'` —
  and reverting the rename returns it to zero. Before this change the same rename compiled
  cleanly and shipped a component reading a field the server had stopped sending.
  `types/messages.ts` went from 449 lines to 188.
- **`tests/Unit/GeneratedTypesAreTheWireContractTest.php` keeps it that way.** It reflects
  every `#[TypeScript]` DTO's constructor and every `App\Enums` case list, then scans
  `resources/js/types/*.ts` for an object literal whose field set — or a union whose
  literal set — is exactly one of them. A 32nd shadow fails there rather than months later
  in whichever surface was missed. It matches on *shape*, not on name, so a copy renamed to
  look original (`AuditEntry` restating `AuditEventData`) is caught too.
- **Three drifts closed on the way through.** The team settings page can now see
  `canManageEmojis`; the roster/mention split means a surface that wants `isBot` has to ask
  for the payload that carries it; and `types/auth.ts`'s `Passkey` — a shape with
  `created_at_diff` fields the server has not sent since `PasskeyData` replaced it, imported
  by nothing — is gone.
- **Optionality got stricter, and it should have.** The transformer emits a nullable field
  as `avatar: string | null` — present in the JSON, possibly null — where the hand-written
  copies had `avatar?: string | null`. Test doubles that built half a payload now build the
  whole one. That is the contract being enforced, not incidental churn.
- **One optimistic-write seam had to become explicit.** `optimisticMessage()` builds a row
  the client authors before the server answers, and it only knows the sender's id, name and
  avatar. It now spells out the rest of `UserData` (`isBot: false`, `status: null`,
  `presence: 'active'`, `isDnd: false`, `authorOverride: null`, `postedVia: null`) rather
  than omitting fields the type used to let it omit. Same rendering, stated instead of
  implied — and the echo replaces the row wholesale regardless.
- **`resources/js/generated/` stays gitignored.** It is regenerated by
  `sail npm run build` (or `sail artisan typescript:transform`). Never run
  `wayfinder:generate` by hand — it clobbers the `.form` variants.
- **This ADR does not make `@/types` disappear.** Rewriting the 283 import sites to read
  `App.Data.*` directly was explicitly out of scope: the aliases carry the client's
  vocabulary, and a vocabulary is worth having. What is not worth having is a second
  declaration of the same facts underneath it.
