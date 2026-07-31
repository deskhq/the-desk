# ADR-0008: The shared-props middleware is glue; the workspace shell is a read-model

- Status: Accepted
- Date: 2026-07-31
- Relates to: epic architecture-hardening II (#1089), child #1090

## Context

`app/Http/Middleware/HandleInertiaRequests.php` was 748 lines, the largest file in
`app/`. `share()` returned 44 top-level props and the class carried 42 imports: six
Models, eight Data DTOs, eight Enums, `SlashCommandRegistry`, `MessageSearchPanel`,
`ThreadInbox`, `UpdateChecker` — and a queued Job, `PurgeDeletedChannel`, imported
solely to read its `GRACE_WINDOW_DAYS` constant. A middleware reaching into a job
class for a domain rule is the tell that the rule had no home.

This was the inverse of a deep module. The interface was one method, but the array
it returned *is* 44 separate contracts with the frontend, and nothing smaller than
the whole array could be addressed. You could not ask for "the sidebar" or "the
threads panel"; there was no such object.

Two costs followed.

**Locality.** Every workspace-scoped prop is meaningful only for a signed-in viewer,
on a workspace route, with a team bound to it. That composite precondition was
written out verbatim eight times:

```php
if (! $user || ! $team instanceof Team || ! $this->isWorkspaceRoute($request)) {
```

`$request->route('team')` was re-resolved eight times, once per helper, and a
twelfth shell prop would have meant a ninth copy. `isWorkspaceRoute()` was correctly
documented as the single source of truth — and it was, for its own third of the
condition, while the composite it participated in was copy-pasted.

**No test surface.** Nothing constructed this read-model. Only two test files
mentioned the class, both merely to compute an `X-Inertia-Version` header. Every
assertion about sidebar channels, unread counts, DM hiding, the threads inbox or
search results went through a full HTTP `GET` plus `AssertableInertia`.
`tests/Feature/Channels/MessageSearchTest.php` was 656 lines of requests to
`route('channels.show', [..., 'nav' => 'search'])` exercising `MessageSearchPanel`,
an object that was already perfectly constructible.

## Decision

**The middleware is glue. Workspace read-models are constructible objects.**

`App\Support\WorkspaceShell` owns the precondition and exposes the read-models:

- `WorkspaceShell::forRequest($request)` resolves `(authenticated, on a workspace
  route, with a bound team)` **once**, and answers `null` when it does not hold.
  `isWorkspaceRoute()` is private to it — broadening the set of workspace surfaces
  is one edit, in the module that defines what a workspace surface is.
- The constructor takes a `User` and a `Team`. **No `Request`.** Everything the
  shell exposes is reachable from a test without an HTTP round-trip. What is
  genuinely request state — which dock destination `?nav=` pins, the search facets
  on the URL — is resolved by the glue and passed in as a value.
- The query-heavy builders that had no module became one each: `SidebarChannels`,
  `SidebarReminders`, `PendingInvitations`, and the per-channel unread sub-query
  moved next to the per-workspace one it must agree with, in `WorkspaceUnread`.

`share()` keeps all 44 prop names and computes none of them. A null shell means
there is no workspace to describe, and each prop falls back to the empty value the
frontend already renders for "not here" (`$shell?->channels(...) ?? []`).

**What deliberately did not move: the prop names.** That list *is* the Inertia
contract and it is legitimate glue. Hiding the names inside `WorkspaceShell` too
would have shipped a second god module with a prettier name.

The domain constant went to the domain: `Channel::RESTORE_WINDOW_DAYS`. How long a
deleted channel stays restorable is a rule of the channel; `PurgeDeletedChannel` is
only the sweeper that enforces it.

## Consequences

- The middleware falls from 748 lines to about 300, most of which is the prop list
  and the comments explaining what each prop is for.
- One precondition instead of eight, resolved once per request instead of eight
  times. A new shell prop is one line in `share()` and one method on the shell.
- The largest read-model in the app has a test surface. `SidebarChannels`,
  `SidebarReminders`, `PendingInvitations`, `WorkspaceUnread` and `WorkspaceShell`
  each have tests that construct them directly.
- The seam is collected, not merely built: `MessageSearchTest.php` fell from 656
  lines to about 300 and the facet, ACL, result-shaping and lenient-URL-parsing
  cases now run against a constructed `MessageSearchPanel`. What stayed on HTTP is
  what is genuinely HTTP — the destination riding `?nav=search`, the legacy
  redirect, the membership gate, the JSON suggest endpoint.
- Passes the deletion test in the right direction: deleting `WorkspaceShell` would
  concentrate the shell's assembly back into one unaddressable place, which is
  exactly where it was.
