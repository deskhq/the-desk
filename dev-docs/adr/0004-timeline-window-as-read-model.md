# ADR-0004: Resolve the channel timeline window in a read-model, not the controller

- Status: Accepted — amended: the payload follows the window out of the controller (2026-08-03)
- Date: 2026-07-10
- Relates to: epic architecture-hardening (child: Channel timeline window); epic architecture-hardening V ([#1195](https://github.com/deskhq/the-desk/issues/1195), child: [#1197](https://github.com/deskhq/the-desk/issues/1197))

> **Status note (2026-08-03).** As accepted, this moved the *window* and said `show`
> "shrinks to a thin action consistent with its siblings". The window did move. The
> **payload did not**, and the consequence above was therefore never true: `show()`
> stayed ~150 lines holding 19 of the ~40 query-builder calls in the whole controller
> tree — the member count, the pin count, a raw `join('message_pins', ...)`, the
> scheduled messages, the roster union and the read receipts, plus seven `Gate::allows`
> flattened into `can*` props.
>
> The sharpest of them was not a query at all. `show()` wrote three synthetic
> attributes — `muted`, `notification_level`, `draft` — onto the bound `Channel` so
> `ChannelData::fromChannel()` could read them back off it. ADR-0011 gave that DTO an
> explicit viewer precisely so it would stop depending on ambient state; handing it the
> same state through a side channel is that defect wearing a different coat. The DTO now
> takes `?ChannelMember $membership` and no caller writes to the model.
>
> Two smaller breaches rode along and are closed with it: a hand-written `with([...])`
> the scheduled-message payload spelled for itself, which ADR-0002 says never to do (it
> is now `ScheduledMessage::withScheduledMessageDataRelations()`); and `pins` /
> `pinCount`, computed by two independent queries that had already diverged — the count
> included a pin whose message was a tombstone and the panel did not — despite being
> named in `lib/reloadProps` as a pair that must move together. They are one reading now,
> so the badge counts what the panel holds by construction.
>
> **The decision below is unchanged and now finished.** It gains a second module rather
> than a wider one: `App\Support\ChannelPage`, constructed from `(Channel, User, Team)`
> and never a `Request`, holding the payload; `ChannelTimelineWindow` keeps the window.
> The controller constructs both and holds both. The alternative — one read-model taking
> the raw `?message=` / `?thread=` values and building the window internally — was
> rejected: two collaborators in a controller is not friction, and a read-model that
> knows about query strings is exactly what ADR-0008 keeps out.
>
> `ChannelPage`'s readings are stated directly in
> `tests/Integration/Support/ChannelPageTest.php` (no HTTP round-trip, the bar ADR-0012
> named), and its cost is pinned by `ChannelPageQueryCountTest` the way
> `SidebarChannelsQueryCountTest` pins the sidebar's.
>
> Deliberately **out of scope**, so the next review does not read the omission as an
> oversight: `ChannelPolicy`. `postMessage` omits the `belongsToTeam` clause its five
> siblings spell, and `administers` / `create` each carry one that cannot change their
> answer. All real, all authorization questions, and fixing them inside a payload
> refactor would turn it into a security change. They belong with the `Api/V1` policy
> restatements in a later sweep.

## Context

`ChannelController::show` had grown to ~265 of the controller's 454 lines — the
endpoint plus seven private helpers computing where a channel's initial message
window should open (unread-boundary anchoring, jump context, page-size arithmetic).
This is genuinely complex, regression-prone domain logic, but it read `Request`
state directly and ended in `Inertia::render`, so it was reachable only through a
full HTTP round-trip. The most bug-prone code in the app had no unit-test seam.

## Decision

Complex message-window / read-model resolution lives in a dedicated query object /
read-model that takes explicit parameters (channel, viewer, jump target, last-read
id) and returns the window ceiling + payload. Controllers keep HTTP glue only:
resolve params from the request, call the read-model, render.

This applies whenever timeline-assembly logic grows beyond trivial in a controller.

## Consequences

- The window math becomes unit-testable without an HTTP request.
- `show` shrinks to a thin action consistent with its siblings.
- Passes the deletion test: real weight, moved to where it can be tested.
