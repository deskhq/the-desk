---
title: REST API
description: Authenticate a bot or personal access token, understand the scope vocabulary, and call the versioned /api/v1 surface — with curl examples and the error shapes to expect.
---

The Desk exposes a small, versioned **REST API** so external systems can read and
post into a workspace. It is part of the [integrations
platform](/docs/reference/feature-toggles/#integrations-platform) and shares its
`INTEGRATIONS_ENABLED` master switch — with the platform off, every route below
returns **404**.

## Base URL and versioning

All endpoints live under `${APP_URL}/api/v1`. The version is in the path, so a
future `v2` never breaks a `v1` client.

## Authenticating

Every request carries a **bearer token** in the `Authorization` header:

```bash
curl https://desk.example.com/api/v1/channels \
  -H "Authorization: Bearer desk_bot_1|Kx9mQ2vL8pR4tW7yZ3aB..."
```

A token is one of two kinds, both minted from **Team settings → Integrations**:

- A **bot token** acts as a [bot](/docs/reference/feature-toggles/#integrations-platform)
  — a non-human workspace member. The bot posts as itself and can only act in
  channels it belongs to.
- A **personal access token** acts as the human who created it, with their own
  memberships and permissions.

Tokens are shown **once** at creation and stored only as a hash — copy the value
immediately; it can never be displayed again. Revoke a token from the same
surface and it stops working instantly (`401`).

## Scopes

Each token is granted a least-privilege set of **scopes** (`resource:action`).
Every endpoint requires exactly one scope; a token missing it gets a `403`.

| Scope              | Grants                                          |
| ------------------ | ----------------------------------------------- |
| `channels:read`    | Read channels the token's subject belongs to.   |
| `channels:write`   | Create and archive channels.                    |
| `messages:read`    | Read messages in those channels.                |
| `messages:write`   | Post, edit, and delete messages.                |
| `reactions:write`  | Add and remove reactions.                       |
| `members:read`     | Read channel membership.                        |
| `members:write`    | Add and remove channel members.                 |
| `webhooks:read`    | Read outgoing-webhook subscriptions.            |
| `webhooks:write`   | Create and revoke outgoing-webhook subscriptions. |

## Endpoints

| Method & path                                        | Scope             |
| ---------------------------------------------------- | ----------------- |
| `GET /channels`                                      | `channels:read`   |
| `POST /channels`                                     | `channels:write`  |
| `GET /channels/{channel}`                            | `channels:read`   |
| `POST /channels/{channel}/archive`                   | `channels:write`  |
| `GET /channels/{channel}/messages`                   | `messages:read`   |
| `POST /channels/{channel}/messages`                  | `messages:write`  |
| `GET /channels/{channel}/messages/{message}`         | `messages:read`   |
| `PATCH /channels/{channel}/messages/{message}`       | `messages:write`  |
| `DELETE /channels/{channel}/messages/{message}`      | `messages:write`  |
| `PUT /channels/{channel}/messages/{message}/reactions/{emoji}`    | `reactions:write` |
| `DELETE /channels/{channel}/messages/{message}/reactions/{emoji}` | `reactions:write` |
| `GET /channels/{channel}/members`                    | `members:read`    |
| `POST /channels/{channel}/members`                   | `members:write`   |
| `DELETE /channels/{channel}/members/{user}`          | `members:write`   |
| `GET /webhooks`                                      | `webhooks:read`   |
| `POST /webhooks`                                     | `webhooks:write`  |
| `GET /webhooks/{subscription}`                       | `webhooks:read`   |
| `DELETE /webhooks/{subscription}`                    | `webhooks:write`  |

Post a message:

```bash
curl -X POST https://desk.example.com/api/v1/channels/$CHANNEL/messages \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"body": "Deploy finished ✅"}'
```

Managing outgoing-webhook subscriptions over the API is documented under
[Outgoing webhooks](/docs/reference/webhooks/).

## Rate limiting

Requests are throttled **per token** at
[`INTEGRATIONS_API_RATE_LIMIT`](/docs/reference/feature-toggles/#integrations-platform)
requests per minute. Exceeding it returns **429** with a `Retry-After` header.

## Errors

The API answers with conventional status codes and a JSON body:

| Status | Meaning                                                        |
| ------ | ------------------------------------------------------------- |
| `401`  | Missing, malformed, or revoked token.                         |
| `403`  | The token lacks the scope the endpoint requires.             |
| `404`  | The resource is outside the token's workspace, or the platform is disabled. A channel the subject cannot see is reported as `404`, never leaking its existence. |
| `422`  | Validation failed; the body carries an `errors` map.         |
| `429`  | Rate limit exceeded; retry after the `Retry-After` header.   |
