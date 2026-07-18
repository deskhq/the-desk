---
title: Incoming webhooks
description: Give an external system a secret URL that posts a message into one channel as a bot — how to create one, the payload shape, membership gating, and optional signature verification.
---

An **incoming webhook** is the simplest way to get a message _into_ The Desk: a
secret URL that, when `POST`ed to, posts a message into **one channel as a
[bot](/docs/reference/feature-toggles/#integrations-platform)**. No token, no
scopes — the URL itself is the credential. Incoming webhooks are part of the
[integrations platform](/docs/reference/feature-toggles/#integrations-platform)
and share its `INTEGRATIONS_ENABLED` master switch; with the platform off the
ingest endpoint returns **404**.

## Creating one

From **Team settings → Integrations**, create a bot (or reuse one). Open the bot
from the **Bots** list and, under **Channels**, use **Add to channel** to add it
to the target channel — a bot can only post where it is a member. Then create an
incoming webhook naming that bot and channel. The opaque URL is revealed **once**
— copy it immediately. Only its hash is stored, so it can never be shown again; to
rotate it, revoke the webhook and create a new one.

```
https://desk.example.com/webhooks/incoming/9f2c8a41-77b3-4e02-b1a9-c3d5e6f70812
```

## Posting a message

`POST` a JSON body with a `text` field:

```bash
curl -X POST $WEBHOOK_URL \
  -H 'Content-Type: application/json' \
  -d '{"text": "Build passed ✅"}'
```

The message appears in the channel authored by the webhook's bot.

## Membership gating

Posting is **membership-gated**: the webhook only works while its bot is a member
of the channel. Remove the bot from the channel — via **Remove** under the bot's
**Channels** — and the URL returns **403**, the same authorization path a bot's
API token follows, so there is no parallel way to post. Revoking the webhook (or
deleting the bot) stops it permanently.

## Signing (optional)

When you create the webhook you can also mint an **HMAC signing secret**, shown
once alongside the URL. If you do, sign each request so The Desk can reject
forgeries: compute `HMAC-SHA256` over the exact raw request body and send it in
the `X-Desk-Signature` header. A webhook created without a secret accepts
unsigned requests; one created with a secret rejects requests whose signature
does not match.
