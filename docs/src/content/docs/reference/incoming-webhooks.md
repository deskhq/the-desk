---
title: Incoming webhooks
description: Give an external system a secret URL that posts a message into one channel as a bot — how to create one, the payload shape, membership gating, and optional signature verification.
---

An **incoming webhook** is the simplest way to get a message _into_ The Desk: a
secret URL that, when `POST`ed to, posts a message into **one channel as a
[bot](/reference/feature-toggles/#integrations-platform)**. No token, no
scopes — the URL itself is the credential. Incoming webhooks are part of the
[integrations platform](/reference/feature-toggles/#integrations-platform)
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

## Posting under a different name or icon

One webhook can post as several logical sources. Add `username`, `icon_url`, or
both, and that single message is displayed under them instead of the bot's own
name and avatar:

```bash
curl -X POST $WEBHOOK_URL \
  -H 'Content-Type: application/json' \
  -d '{"text": "Rolled out to production", "username": "Release Train", "icon_url": "https://cdn.example.com/train.png"}'
```

Both fields are optional and independent: send only `icon_url` to keep the bot's
name with a different picture. The values are stored on the message as a
snapshot, so an old message keeps rendering as it did even after the webhook is
revoked or its bot renamed.

:::caution[The BOT badge cannot be removed]
An override changes only the **displayed** name and icon. The message is still
authored by the webhook's bot, so it keeps the uppercase **BOT** badge and the
squared-off avatar, and its hover card names the account that actually posted it
("via Deploy Bot"). There is no way to make a webhook message read as a person.
:::

Rules for the two fields:

| Field | Rules |
| --- | --- |
| `username` | Up to 255 characters. |
| `icon_url` | Up to 2048 characters, and must start with `http://` or `https://`. |

- **Blank is the same as absent.** An empty or whitespace-only value posts under
  the bot's own identity and still returns **202**, so a templated sender that
  emits `"username": ""` for an unset variable keeps working.
- **A malformed value is rejected with 422** rather than silently posting under a
  different name than the one you asked for.
- **The icon is never fetched at post time.** It is loaded through the app's image
  proxy when a reader views the message, so no reader's IP reaches its host. A URL
  that 404s simply falls back to the bot glyph, so a typo in `icon_url` never
  drops an alert.
- **`icon_emoji` is ignored.** Only `icon_url` is read, the same way Slack Block
  Kit (`blocks`) and legacy `attachments` are ignored.

## Membership gating

Posting is **membership-gated**: the webhook only works while its bot is a member
of the channel. Remove the bot from the channel — via **Remove** under the bot's
**Channels** — and the URL returns **403**, the same authorization path a bot's
API token follows, so there is no parallel way to post. Revoking the webhook (or
deleting the bot) stops it permanently.

## Signing (optional)

When you create the webhook you can also mint an **HMAC signing secret**, shown
once alongside the URL. If you do, sign each request so The Desk can reject
forgeries: compute `HMAC-SHA256` over the exact raw request body and send the hex
digest in the **`X-Signature-256`** header. A bare digest and a `sha256=`-prefixed
one (GitHub/Slack style) are both accepted.

```bash
BODY='{"text": "Build passed ✅"}'
SIGNATURE=$(printf '%s' "$BODY" | openssl dgst -sha256 -hmac "$SIGNING_SECRET" | awk '{print $2}')

curl -X POST $WEBHOOK_URL \
  -H 'Content-Type: application/json' \
  -H "X-Signature-256: sha256=$SIGNATURE" \
  -d "$BODY"
```

Sign the **exact bytes you send**: re-serializing the JSON, or letting an HTTP
client reformat it, changes the digest and the request is refused.

A webhook created without a secret accepts unsigned requests. One created with a
secret rejects a missing, malformed, or mismatched signature with **401** — so a
signature sent under any other header name fails as if it were absent.

:::caution[Not the header outgoing deliveries use]
Deliveries _from_ The Desk are signed with `X-Desk-Signature`, which carries
`t=<unix ts>,v1=<hex>` computed over `"{timestamp}.{body}"` — see
[verifying an outgoing signature](/reference/webhooks/#verifying-the-signature).
That is a different header with a different value format. Incoming ingest reads
only `X-Signature-256`, and the digest there is over the body alone — bare or
`sha256=`-prefixed, never timestamped.
:::
