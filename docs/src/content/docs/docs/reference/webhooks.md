---
title: Outgoing webhooks
description: Subscribe to workspace events and receive signed, retried HTTP POSTs — the event set, frozen payload shapes, and how to verify a delivery's signature.
---

Outgoing webhooks let your systems **react to activity** in a workspace. A bot
registers a **subscription** for a set of events, optionally scoped to specific
channels, and The Desk delivers each matching event as a signed `POST` to your
URL. Webhooks are part of the [integrations platform](/docs/reference/feature-toggles/#integrations-platform)
and share its `INTEGRATIONS_ENABLED` master switch — with the platform off,
nothing is delivered.

## Managing subscriptions

Subscriptions are managed over the public REST API with a bot token carrying the
right scope. All routes are under `${APP_URL}/api/v1` and are team-scoped to the
bot's workspace.

| Method & path                | Scope             | Purpose                                    |
| ---------------------------- | ----------------- | ------------------------------------------ |
| `GET /webhooks`              | `webhooks:read`   | List the workspace's subscriptions.        |
| `POST /webhooks`             | `webhooks:write`  | Register a subscription.                    |
| `GET /webhooks/{id}`         | `webhooks:read`   | Show a subscription with recent deliveries. |
| `DELETE /webhooks/{id}`      | `webhooks:write`  | Revoke (delete) a subscription.             |

Register a subscription with a name, a target `url`, the `events` to listen for,
and an optional `channel_ids` allow-list (omit it to receive events from every
channel in the workspace):

```json
// POST /api/v1/webhooks
{
  "name": "CI relay",
  "url": "https://example.com/hooks/the-desk",
  "events": ["message.created", "reaction.added"],
  "channel_ids": ["0193b2c1-..."]
}
```

The response returns the subscription **and its signing `secret`** — this is the
only time the secret is shown, so store it now:

```json
{
  "data": { "id": "...", "name": "CI relay", "status": "active", "...": "..." },
  "secret": "whsec_..."
}
```

## Events

The v1 event set is curated and each payload shape is **frozen** — new fields may
be added, but existing ones will not change or be removed.

| Event                   | Fires when…                          |
| ----------------------- | ------------------------------------ |
| `message.created`       | A message is posted to a channel.    |
| `message.updated`       | A message is edited.                 |
| `message.deleted`       | A message is deleted (a tombstone — its body is blanked). |
| `reaction.added`        | A reaction is added to a message.    |
| `channel.member_added`  | A member is added to a channel.      |

## Delivery envelope

Every delivery is a `POST` with a JSON body wrapping the event in a stable
envelope. The `data` object is the event-specific payload.

```json
{
  "id": "b0f7...",                       // unique delivery id (also the X-Desk-Delivery header)
  "type": "message.created",             // the event (also the X-Desk-Event header)
  "created_at": "2026-07-18T11:12:13+00:00",
  "data": { "...": "..." }
}
```

`message.*` events carry the full message shape under `data`; `reaction.added`
carries `{ channel_id, message_id, emoji, user }`; `channel.member_added`
carries `{ channel_id, user }`.

### Headers

| Header             | Value                                            |
| ------------------ | ------------------------------------------------ |
| `X-Desk-Event`     | The event type, e.g. `message.created`.          |
| `X-Desk-Delivery`  | The delivery id (matches the envelope `id`).     |
| `X-Desk-Signature` | The signature, `t=<unix ts>,v1=<hex>` (below).   |

## Verifying the signature

Each request is signed with the subscription's secret so you can confirm it came
from The Desk and was not tampered with. The `X-Desk-Signature` header carries a
timestamp and an HMAC-SHA256 digest computed over `"{timestamp}.{raw body}"`:

```
X-Desk-Signature: t=1752836400,v1=5257a869e7...
```

To verify, recompute the digest over the **exact raw request body** you received
and compare in constant time. In PHP:

```php
[$t, $v1] = sscanf($request->header('X-Desk-Signature'), 't=%d,v1=%s');
$expected = hash_hmac('sha256', $t.'.'.$request->getContent(), $secret);

if (! hash_equals($expected, $v1)) {
    abort(400, 'Invalid signature');
}

// Optionally reject a stale timestamp to defend against replay:
abort_if(abs(time() - $t) > 300, 400, 'Timestamp outside tolerance');
```

## Retries and auto-disabling

A delivery that does not return a `2xx` status (or times out) is retried with
exponential backoff. Redirects are never followed — a `3xx` response counts as
a failed attempt, so register the final URL directly (e.g. `https://`, not an
`http://` address that redirects to it). If a subscription's deliveries fail
[`WEBHOOKS_DISABLE_AFTER`](/docs/reference/feature-toggles/#outgoing-webhooks)
times in a row — with no success in between — it is **auto-disabled** and stops
delivering. Its `status` (surfaced on the API resource, along with
`consecutive_failures` and `last_success_at`) then reads `disabled`, and the
event is recorded in the workspace audit log. To resume, **re-enable** the
subscription from **Team settings → Integrations** (which clears the failure
streak) or register a new one; the same detail page shows the recent delivery log
and can **rotate** the signing secret. Tune the attempt count, per-request
timeout, and disable threshold with the
[`WEBHOOKS_*` variables](/docs/reference/feature-toggles/#outgoing-webhooks).

Your endpoint should respond `2xx` quickly and do its own work asynchronously,
and should treat deliveries as **at-least-once** — dedupe on the envelope `id`.
