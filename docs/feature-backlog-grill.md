# Feature Backlog — Grill Candidates

Missing Slack-clone capabilities, verified absent from the codebase and not yet tracked in an open issue (as of 2026-07-10). Each entry is a candidate for a dedicated grill session before it becomes a spec/issue. Numbering matches the original shortlist (8 and 13 intentionally excluded).

---

## 1. File & image attachments / uploads

**What:** Attach files and images to messages — drag-and-drop, paste, and a file picker. Inline image/thumbnail previews in the timeline; download for arbitrary files.

**Why it matters:** The single biggest gap. There is no upload path, attachment model, or storage anywhere in the app. It's a core, table-stakes Slack feature.

**Grill starters:**
- Storage backend: local disk vs S3-compatible? How does that interact with Sail/local dev?
- New `attachments` table + polymorphic vs message-owned? Multiple attachments per message?
- Size/type limits, virus/type validation, image reprocessing (thumbnails, EXIF stripping).
- How do attachments flow through search, data export, forwarding, and scheduled messages?
- Realtime: do attachments broadcast with the message or lazily hydrate?

---

## 2. Pinned messages

**What:** Pin a message to its channel; a "pins" view accessible from the channel header showing all pinned messages.

**Why it matters:** No `pinned_at` / pin model exists. Common way teams surface canonical info in a channel.

**Grill starters:**
- Who can pin/unpin — any member, or role-gated?
- New `message_pins` table vs columns on `messages`? Per-channel pin cap?
- Header affordance + count; how the pins panel reuses the message-list rendering.
- Audit-log the pin/unpin? Realtime broadcast to other viewers?

---

## 3. Saved / bookmarked messages ("Later")

**What:** Personal, private save of any message for follow-up; a "Saved" view listing them. Distinct from channel drafts (which already exist).

**Why it matters:** No saved-item model exists. Complements reminders (#33) but is manual and persistent rather than time-based.

**Grill starters:**
- Per-user `saved_messages` pivot; ordering (manual vs recency)?
- Overlap with message reminders (#33) — one unified "Later" surface or two features?
- Entry point: hover toolbar (relates to #171) + a top-level nav item.
- What happens to a saved message when the original is edited/deleted?

---

## 4. Rich-text formatting

**What:** Bold/italic/strikethrough, inline code, blockquotes, code blocks, and lists — both a composer affordance and timeline rendering.

**Why it matters:** `resources/js/lib/messageBody.ts` currently renders only mentions + bare URLs. Everything else is plain text.

**Grill starters:**
- Markdown-ish input vs a WYSIWYG editor? What's the storage format (raw markdown vs tokens)?
- Sanitization/XSS story — current renderer escapes HTML by hand; how does that extend safely?
- Composer toolbar vs keyboard shortcuts only; how it coexists with the mention token format (`@[Name](id)`).
- Scope creep guard: which subset ships first (inline marks + code blocks)?

---

## 5. Slash commands

**What:** `/shrug`, `/me`, `/away`, `/remind`, `/rename`, etc. — typed in the composer, parsed before send.

**Why it matters:** Nothing exists. A natural extension point that also anchors future integrations (#11).

**Grill starters:**
- Client-side parse vs server-side command dispatch? Registry pattern for commands?
- Which commands are v1 (text-transform like `/me`, `/shrug`) vs stateful (`/remind`, `/away` → depends on #6/#7)?
- Autocomplete UI in the composer; discoverability.
- Extensibility so custom/integration commands (#11) can register later.

---

## 6. Presence + custom status

**What:** Online/away indicator dots on avatars, plus a user-set custom status (emoji + text, optional expiry).

**Why it matters:** Users table has no presence/status columns, despite Reverb being installed. Presence is a defining real-time feature.

**Grill starters:**
- Presence via Reverb presence channels vs heartbeat + DB? "Away" detection (idle timeout, tab visibility)?
- New columns/table for custom status (emoji, text, expires_at, clear-after presets).
- Where dots/status render: sidebar, hover cards, message headers, member lists.
- Privacy/notification interplay with DND (#7); does status drive DND?

---

## 7. Do Not Disturb / notification schedule / snooze

**What:** Snooze notifications for a duration, plus a recurring quiet-hours schedule. Suppresses pushes/badges while active.

**Why it matters:** No DND, quiet hours, or snooze exists. Pairs with presence (#6) and the notification prefs already in settings.

**Grill starters:**
- Schema: snooze `until` timestamp + weekly quiet-hours window (timezone-aware — timezones already exist).
- What DND actually suppresses given notifications are currently in-app only (ties into web push if that lands).
- Override for urgent/mention? "Notify anyway" escape hatch?
- UI: quick snooze menu + settings page section.

---

## 9. Activity & Unreads views

**What:** An "Activity" inbox (your mentions + reactions to your messages) and an "All unreads" aggregated view. A Threads view already exists as the model.

**Why it matters:** No way to see mentions/reactions in one place or triage all unreads; users must visit each channel.

**Grill starters:**
- Data source: derive from mentions table + reactions vs a materialized activity feed?
- "Unreads" aggregation performance across many channels (relates to virtualized list #42).
- Mark-as-read semantics; how it reconciles with per-channel read state.
- Nav placement and badge counts (reuse `useSidebarBadges`).

---

## 10. Channel description/purpose + bookmarks bar

**What:** A longer channel description/purpose (beyond the existing short `topic`), plus a per-channel bookmarks bar for pinned links/resources.

**Why it matters:** Channels have only `topic` today. Description and bookmarks are standard channel-header furniture.

**Grill starters:**
- `description` column on channels vs a richer "about" panel; who can edit?
- Bookmarks: new `channel_bookmarks` table (label + url + emoji, ordered).
- Header UI density — how bookmarks bar coexists with the redesign (#145) and pins (#2).
- Link validation/unfurl reuse for bookmarks?

---

## 11. Webhooks, bots & public API (with tokens)

**What:** Incoming webhooks (post to a channel via URL), outgoing webhooks/events, bot identities, and a token-authenticated public API.

**Why it matters:** No integration surface at all. Foundational for extensibility; large in scope.

**Grill starters:**
- Scope for v1: incoming webhooks only vs full API? Auth model (personal access tokens, Sanctum?).
- Bot/app identity model — how bot-authored messages render and are attributed.
- Rate limiting, scoping/permissions, signing secrets.
- Relationship to slash commands (#5) as command handlers.
- Big enough to be its own epic — should the grill decide the epic split?

---

## 12. Message retention policies

**What:** Admin-configurable auto-deletion of messages after N days (workspace-wide and/or per-channel).

**Why it matters:** No retention exists. A common compliance/admin requirement; pairs with the existing audit log and data export.

**Grill starters:**
- Granularity: workspace default + per-channel override? Threads/attachments handling?
- Deletion job (scheduled command) — hard delete vs tombstone (users already tombstone).
- Interaction with data export (#already built) and audit trail — do deletions get logged?
- Admin UI + guardrails (confirmations, minimum retention floors).

---

_Excluded from this file: #8 web push/desktop notifications, #13 guest accounts._
