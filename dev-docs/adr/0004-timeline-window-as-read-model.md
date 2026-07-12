# ADR-0004: Resolve the channel timeline window in a read-model, not the controller

- Status: Accepted
- Date: 2026-07-10
- Relates to: epic architecture-hardening (child: Channel timeline window)

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
