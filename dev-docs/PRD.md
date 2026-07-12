# Slack Clone — MVP PRD

## 1. Overview

A team messaging app (Slack clone). Real-time channel-based chat scoped to
workspaces, built on the existing Laravel + Inertia (Vue) + Fortify scaffold.

**Stack:** Laravel 13 (PHP 8.5), Inertia v3 + Vue 3, shadcn-vue, TailwindCSS v4,
Laravel Reverb (WebSockets), Laravel Scout + Meilisearch, spatie/laravel-data
(+ typescript-transformer), Postgres, Redis (queue/cache), Sail (local dev).

**Method:** Every feature is built test-first with the `/tdd` skill
(red → green → refactor). Feature tests (Pest) are primary.

### Code conventions (match existing scaffold)

- **Actions** — every write operation is a single-purpose Action class in
  `app/Actions/{Domain}/{Verb}{Noun}.php` with a `handle()` method that wraps
  its work in `DB::transaction` and returns the model. Controllers do **not**
  contain business logic. (Mirrors `app/Actions/Teams/CreateTeam.php`.)
  MVP actions: `CreateChannel`, `JoinChannel`, `LeaveChannel`, `PostMessage`,
  `EditMessage`, `DeleteMessage`, `ArchiveChannel`, `MarkChannelRead`.
- **Controllers** — thin: a `FormRequest`
  (`app/Http/Requests/{Domain}/...`) validates, the Action is injected and
  invoked, then `Inertia::flash` a toast and redirect/`Inertia::render`.
- **DTOs (spatie/laravel-data)** — server → client shapes are typed Data
  objects (`MessageData`, `ChannelData`, `UserData`, `MentionData`). The **same**
  DTO produces the Inertia prop, the Reverb broadcast payload, and the search
  result — one source of truth so shapes never drift (critical: optimistic-send
  dedup requires broadcast shape == HTTP shape). `laravel-typescript-transformer`
  generates matching TS types for the Vue side (complements Wayfinder routes).
- **Validation stays in FormRequests** (not laravel-data) — matches existing
  convention.
- **Authorization** via Policies / `Gate` (`ChannelPolicy`, `MessagePolicy`).
- **Enums** in `app/Enums`.
- **UUID primary keys everywhere** — every model (existing scaffold `User`,
  `Team`, `Membership`, `TeamInvitation` **and** new MVP `Channel`,
  `ChannelMember`, `Message`, `Mention`) uses a UUID primary key via the
  `HasUuids` trait. Every `id` column is `uuid` with a DB-level
  `gen_random_uuid()` default (so seeders using `WithoutModelEvents` and pivot
  `attach()` inserts still get a key); every FK is `foreignUuid`. No bigint
  auto-increment keys. Route keys stay as they were (`Team` → slug,
  `TeamInvitation` → code).

### Existing foundation (do not rebuild)

- `Team` model = **Slack Workspace** (slug, `is_personal`, soft deletes).
- `team_members` pivot (`Membership`) with `TeamRole` enum (Owner/Admin/Member)
  and `TeamPermission` enum + `TeamPolicy`.
- `team_invitations` (code, email, role, expiry) + invite flow.
- Fortify auth (login/register/etc.), settings, teams Vue pages.

The MVP adds **Channels + Messages + Realtime + Search** on top.

---

## 2. Core domain model

```
Team (workspace)
 └─ Channel (belongsTo Team)            public | private
     ├─ ChannelMember (belongsTo Channel, User)   last_read_message_id
     └─ Message (belongsTo Channel, User)         text, edited_at, softDeletes
         └─ Mention (belongsTo Message, User)
```

**Invariants**

- A channel belongs to exactly one team. No cross-team channels.
- Channel membership ⊆ team membership. Must be a team member to join a channel.
- `#general` auto-created per team; every team member auto-joined; cannot be
  archived or deleted.

### Schema

**channels**
| col | type | notes |
|---|---|---|
| id | uuid pk | |
| team_id | fk → teams (uuid) | cascade delete |
| name | string | display, `#` stripped |
| slug | string | slugified name |
| visibility | string | `public` \| `private` |
| topic | string nullable | shown in channel header |
| created_by | fk → users (uuid) nullable | null on creator deletion |
| archived_at | timestamp nullable | archive = read-only, hidden |
| timestamps | | |

Unique index `(team_id, slug)`.

**channel_members**
| col | type | notes |
|---|---|---|
| id | uuid pk | |
| channel_id | fk → channels (uuid) | cascade delete |
| user_id | fk → users (bigint) | cascade delete |
| last_read_message_id | fk → messages (uuid) nullable | drives unread/mention counts |
| timestamps | | |

Unique index `(channel_id, user_id)`.

**messages**
| col | type | notes |
|---|---|---|
| id | bigint pk | |
| channel_id | fk → channels | cascade delete |
| user_id | fk → users | author |
| client_uuid | uuid nullable | optimistic-send dedup |
| body | text | raw text |
| edited_at | timestamp nullable | |
| deleted_at | timestamp nullable | soft delete → tombstone |
| timestamps | | |

Index `(channel_id, id)` for cursor pagination.

**mentions**
| col | type | notes |
|---|---|---|
| id | bigint pk | |
| message_id | fk → messages | cascade delete |
| mentioned_user_id | fk → users | cascade delete |

Unique index `(message_id, mentioned_user_id)`.

---

## 3. Features (MVP)

### 3.1 Channels

- **Create channel** — any team **Member+**. Name unique per team, choose
  public/private, optional topic. Creator auto-added as member.
- **Browse & join public channels** — public channels are listed; user joins to
  add them to their sidebar. Private channels are invite-only, not browsable.
- **Private channel membership** — members (or Admin+) add/remove other team
  members.
- **Archive channel** — Admin+ or creator. Archived = read-only, hidden from
  active list, messages preserved (still searchable). `#general` cannot be
  archived. No hard delete in MVP.
- **`#general`** auto-created on team creation; every new team member auto-joined.

### 3.2 Messages

- **Post message** — any channel member; plain text body.
- **Optimistic send** — message renders instantly (pending state) with a
  client-generated `client_uuid`; confirmed on server ack / Reverb echo; the
  echoed broadcast is de-duplicated against the local optimistic message;
  rollback + error toast on failure.
- **Edit own message** — author only; sets `edited_at`; shows "(edited)".
- **Delete own message** — author (soft delete) → "message deleted" tombstone.
  Admin+ may delete any message (moderation).
- **Rendering** — safe text: escape HTML, autolink URLs, preserve newlines.
  No markdown editor in MVP.
- **Grouping** — consecutive messages by the same author within N minutes
  collapse the author header (display polish).

### 3.3 Mentions

- **`@username` autocomplete** in composer, scoped to team members.
- Parsing on send writes `mentions` rows.
- Mentioned user gets an in-app **mention badge** on the channel (bump unread +
  distinct mention indicator). Mention token highlighted in rendered message.
- **No** `@channel` / `@here`, **no** email/push, **no** separate activity feed
  in MVP.

### 3.4 Unread & badges

- `last_read_message_id` on `channel_members`.
- **Unread count** = messages after `last_read_message_id`.
- **Mention count** = unread messages mentioning the user.
- Client advances `last_read_message_id` when a channel is open + focused
  (debounced).

### 3.5 Realtime (Reverb)

- **Events:** `MessageSent`, `MessageUpdated`, `MessageDeleted` broadcast to
  channel members.
- **Typing indicator** — client whisper on the channel (no DB).
- **Presence** — online/offline dots via a per-team presence channel.
- **Channels:** private `channel.{id}` (authorized by channel membership);
  presence `team.{id}` for the online roster.
- Broadcasting dispatched to the **queue** (Redis); send endpoint stays snappy.

### 3.6 Message history

- **Cursor pagination** on message id, 50/page.
- **Infinite scroll up** for older messages (Inertia v3 merge/infinite-scroll).
- New realtime messages **append at bottom**; auto-scroll only if the user is
  already at the bottom.

### 3.7 Search (Meilisearch)

- Messages indexed via **Laravel Scout** (`Searchable`), indexing queued.
- Indexed fields: `body, channel_id, team_id, user_id, created_at`.
- **ACL:** results filtered to channels the searching user is a member of
  (`channel_id IN` user's channel ids). Scoped to the **current team**.
- Soft-deleted messages removed from index; edited messages reindexed.
- **UI:** search bar → simple results list (message + channel + jump link).
  No facets/highlighting in MVP.

### 3.8 Identity & layout

- **Author display** = `name` + generated initials/color avatar
  (shadcn Avatar fallback). No avatar upload in MVP.
- **Routes:** `/t/{team:slug}/c/{channel}`; `/t/{team}` with no channel
  redirects to `#general`.
- **3-pane layout:** team switcher rail | channel sidebar (with unread/mention
  badges + channel-name filter) | message pane + composer.
- **Navigation:** Inertia visit per channel with prefetch on hover; shadcn-vue
  components (sidebar, dialogs, avatar, etc.).

---

## 4. Permissions

Extend `TeamPermission` + add `ChannelPolicy` / `MessagePolicy`.

| Action | Who |
|---|---|
| Create channel | Member+ |
| Archive channel | Admin+ or creator (never `#general`) |
| Add/remove private channel members | channel members or Admin+ |
| Post message | channel member |
| Edit / delete own message | author |
| Delete any message (moderation) | Admin+ |
| Join/browse public channel | any team member |

---

## 5. Infrastructure & dev

- **Present in Sail:** Postgres 18, Redis, Meilisearch, Mailpit.
- **To install:** `laravel/reverb`, `laravel/scout` + Meilisearch client,
  `laravel-echo` + `pusher-js`, `spatie/laravel-data`,
  `spatie/laravel-typescript-transformer`.
- **Config:** `BROADCAST_CONNECTION=reverb`, `QUEUE_CONNECTION=redis`,
  `SCOUT_DRIVER=meilisearch`.
- **Queues (Redis):** broadcasting + Scout indexing dispatched via `ShouldQueue`.
- **`composer dev`** runs concurrently: app server, `queue:work`,
  `reverb:start`, `vite`.

---

## 6. Testing strategy (`/tdd` for every feature)

- **Feature tests (Pest)** — primary; TDD red-green on every endpoint
  (create/join channel, post/edit/delete message, mention parse, unread compute,
  search ACL).
- **Broadcast** — `Event::fake()` + assert event broadcast on the correct
  channel to the correct members. No live Reverb in tests.
- **Search** — faked/array Scout driver (assert `Searchable` sync); optional
  single live Meili integration test.
- **Policies** — dedicated test per policy method.
- **Browser (Pest 4 `visit`)** — thin smoke layer only: login → open channel →
  send message appears. Not per-feature.

---

## 7. Out of scope (deferred — see FUTURE.md)

DMs & group DMs, threads, reactions, file uploads/attachments, `@channel`/`@here`,
activity/mentions feed, email/push notifications, custom status/away/DND, avatar
upload, hard-delete channels, cross-team channels (Slack Connect), search
facets/highlighting, saved items/bookmarks, pinned messages, markdown editor,
huddles/calls, workspace admin settings, rate-limit configuration.
