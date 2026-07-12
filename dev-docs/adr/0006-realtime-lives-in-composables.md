# ADR-0006: Realtime subscription lifecycles live in composables, not components

- Status: Accepted
- Date: 2026-07-10
- Relates to: epic architecture-hardening (children: useChannelRealtime, useChannelFleetSubscription)

## Context

The most behaviour-dense frontend code — subscribing to Echo channels, routing five
event types plus typing whispers into message streams, deciding main-vs-thread
placement, reconciling subscriptions on channel change — was inlined in `.vue` setup.
`pages/channels/Show.vue` reached 1584 lines partly from this. The same
subscribe/reconcile/teardown lifecycle was hand-rolled three times (the active
channel in `Show.vue`, sidebar badges, chime notifications), each a copy waiting to
drift. None of it had a seam: it was reachable only through a mounted component and a
live Echo mock, while the *pure* decision helpers it should call
(`shouldFlagThreadUnread`, `unreadDivider`) were tested but their callers were not.

## Decision

Realtime subscription lifecycles live in composables with a seam, not inline in
pages or components:

- `useChannelRealtime` owns the active channel's subscribe/route/teardown and feeds
  the message streams. Its placement/routing decisions are extracted into pure
  `lib/*.ts` helpers (with paired tests).
- `useChannelFleetSubscription` owns the one reconcile/teardown lifecycle for
  subscribing to a *set* of channels; sidebar badges, chimes, and the active channel
  are consumers that supply an on-message callback.

New realtime behaviour extends these composables; it is not re-inlined into a page.

## Consequences

- The realtime contract is assertable in isolation; the pure decision cores are
  unit-tested like the rest of `lib/`.
- One subscription lifecycle is verified once instead of three copies.
- `Show.vue` decomposes into composables with seams (unblocks the god-component
  split).
