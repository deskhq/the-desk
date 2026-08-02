# ADR-0014: Nested-resource tenancy is enforced by the route, not by controller preamble

- Status: Accepted
- Date: 2026-08-02
- Relates to: epic architecture-hardening IV (#1142), child #1149

## Context

`routes/settings.php` hung twenty-six nested resources off `settings/teams/{team}/…`
and bound every one of them by id alone. Nothing on the route tied a child to the
workspace in the URL, so each controller re-answered *"does this row actually belong to
this team?"* by hand, and each one answered it in its own words:

```php
abort_unless($incomingWebhook->team_id === $team->id, 404);          // IncomingWebhookController
abort_unless($invitation->team_id === $team->id, 404);               // TeamInvitationController
abort_unless($bot->isBot() && $bot->owner_team_id === $team->id, 404); // three controllers, verbatim
abort_unless($user->belongsToTeam($team), 404);                      // TeamMemberController
```

`ensureBotBelongsToTeam` was copy-pasted body-and-docblock into three controllers and
called from six sites; `ensureSubscriptionBelongsToTeam` was called from five. Counting
the FormRequest accessors that carried the same check, the rule had **fifteen spellings**.

Two consequences, and only the second is about duplication.

**Cross-tenant isolation rested on fifteen remembered call sites.** A guard you have to
remember is a guard you can forget, and `settings/teams/{team}/members/{user}` had:
`Gate::authorize('removeMember', $team)` asked whether the actor may remove *somebody*,
and then `RemoveTeamMember::handle()` ran against a user who was never in the workspace.
The delete matched no row, but the action still emitted `AuditAction::MemberRemoved`
carrying that outsider's name — an admin could write a fabricated removal into their own
audit log naming any user in the instance. No test caught it because there was no rule to
test against, only a habit that this method happened not to follow.

**`manageIntegrations` was asked sixteen times for one surface** — eleven controller
sites plus five FormRequests — and the placement disagreed by verb. `store` delegated to
its FormRequest's `authorize()`; `show`, `destroy`, `reenable`, `replay` and
`rotateSecret` asked inline. A reader of any one method could not tell which stratum had
answered, or whether anything had.

## Decision

**A nested resource is scoped to its parent by the route, and a controller under
`settings/teams/{team}/…` never re-checks tenancy.**

Three parts:

1. **`->scopeBindings()` on the whole `EnsureTeamMembership` group.** Laravel resolves a
   child through the preceding model's relationship, so a row belonging to another
   workspace — or, for a grandchild, to another parent — is a **404 raised by
   `SubstituteBindings`, before any controller, FormRequest or policy runs.**
2. **Each child parameter is named after the relationship that owns it.** Scoped binding
   resolves `{customEmoji}` by calling `Team::customEmojis()`, so the parameter name *is*
   the wiring. `{emoji}` → `{customEmoji}`, `{group}` → `{userGroup}`, `{user}` →
   `{member}` (through `Team::members()` and `UserGroup::members()`), `{webhookDelivery}`
   → `{delivery}`. A mismatch is not a style question: it is a `BadMethodCallException` on
   the first request.
3. **One `can:manageIntegrations,team` on the integrations group**, ahead of every verb,
   replacing eleven `Gate::authorize` calls and five FormRequest `authorize()` bodies.
   `Authorize` sorts after `SubstituteBindings` in the middleware priority list, so a
   foreign child is a 404 and a forbidden actor is a 403, in that order.

**A hand-rolled `belongs to this team` check under `settings/teams/{team}/…` is a
defect.** If a check is needed that scoped binding cannot express, it is not tenancy —
say what it actually is. `BotChannelController::destroy` keeps
`abort_unless($channel->type === ChannelType::Standard, 404)` because that is a domain
rule (this surface manages standard-channel membership, never a DM), and the comment
above it says so.

## Consequences

- **The guard is structural.** A new nested route under this group is scoped the moment it
  is declared; forgetting is no longer possible, because forgetting now means naming a
  parameter that no relationship answers to, and that fails loudly on the first request.
- **Two behaviour changes, both tightenings**, and both now have tests:
  `settings/teams/{team}/members/{member}` 404s for a non-member on every verb (closing the
  fabricated-audit-entry path above), and removing a bot from a channel it is not a member
  of 404s rather than silently succeeding.
- **`TeamMemberController` reads the membership off the pivot** rather than re-querying it.
  `{member}` resolves through `Team::members()`, a `belongsToMany` with `Membership` as its
  pivot, so the row arrives hydrated. This is a real coupling to how the binding resolved,
  and it is stated in the method's docblock.
- **Route parameter names are part of the public surface.** They appear in
  Wayfinder-generated call signatures, so renaming one is a frontend change too. Tests that
  pass positional arrays are unaffected; the ones naming keys had to be updated. That cost
  is paid once per route and buys the scoping.
- **`Api/V1` is deliberately out of scope.** It is a separate route group whose parents are
  resolved from the authenticated token rather than from the URL, so it needs its own
  answer rather than this one applied by rote. Four hand-rolled tenancy checks remain
  there (`ReactionController`, `MessageController` ×2, `WebhookSubscriptionController`),
  as do two in `app/Http/Requests/Channels/Sidebar/`. They are follow-up territory, not an
  exception to the rule.
- **The FormRequest accessors survive**, now doing only what their name says — resolving
  the binding, not policing it. #1151 gives them one shared base.
