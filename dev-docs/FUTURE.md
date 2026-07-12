# Slack Clone — Future Features (post-MVP)

Ordered roughly by expected value / natural build order. Each still built
test-first with `/tdd`.

## Messaging depth
- **Direct messages (1:1)** — new conversation model (membership-defined, no
  channel name), separate sidebar section.
- **Group DMs** — multi-user ad-hoc conversations.
- **Threads** — replies on a message; thread model + realtime thread channel +
  reply-count UI.
- **Reactions** — emoji reactions on messages; `reactions` table + realtime sync.
- **File uploads / attachments** — object storage, previews, image thumbnails.
- **Markdown / rich formatting** — formatting toolbar, code blocks, block quotes.
- **Pinned messages** — per-channel pins.
- **Saved items / bookmarks** — personal saved messages.
- **Message rate-limit configuration** — anti-spam throttle settings.

## Notifications & mentions
- **`@channel` / `@here`** — broadcast mentions (`@here` needs presence).
- **Activity / mentions feed** — dedicated inbox of mentions & reactions.
- **Email notifications** — offline mention/DM digests via Mailpit → real SMTP.
- **Push notifications** — web push / mobile.
- **Per-channel notification preferences** — all / mentions / muted.

## Presence & identity
- **Custom status** — emoji + text status.
- **Away / DND** — auto-away, do-not-disturb schedules.
- **Avatar upload** — user-uploaded profile images.
- **Cross-device unread sync** — realtime read-state sync.

## Channels & workspace
- **Hard-delete channels** — admin destructive delete (vs archive).
- **Cross-team channels (Slack Connect)** — shared channels between workspaces.
- **Channel descriptions / purpose** — long-form beyond topic.
- **Workspace admin settings** — who-can-create-channels, retention, defaults.
- **Guest / restricted accounts** — limited-membership users.

## Search & discovery
- **Search facets & highlighting** — filter by author/channel/date, snippet
  highlights.
- **Global cross-team search** — search across all the user's workspaces.

## Real-time / calls
- **Huddles / voice** — lightweight audio.
- **Video calls / screen share**.

## Platform
- **Slash commands & bots** — extensibility.
- **Webhooks / incoming integrations**.
- **Mobile app** — native or PWA.
