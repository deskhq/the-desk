# Changelog

## [0.2.0](https://github.com/emmpaul/laravel-slack-clone/compare/v0.1.0...v0.2.0) (2026-07-10)


### Features

* **messaging:** scheduled / send-later messages ([#115](https://github.com/emmpaul/laravel-slack-clone/issues/115)) ([c2f2728](https://github.com/emmpaul/laravel-slack-clone/commit/c2f27283b9c8c410c064d166a0233104d0bb081c))
* self-hosting with Docker (prod image, compose, CI build check) ([#117](https://github.com/emmpaul/laravel-slack-clone/issues/117)) ([f4fbbf7](https://github.com/emmpaul/laravel-slack-clone/commit/f4fbbf7262725a3976261d427fa372897d78be0b))


### Code Refactoring

* **architecture:** land the architecture-hardening epic ([#131](https://github.com/emmpaul/laravel-slack-clone/issues/131)) ([211c3c0](https://github.com/emmpaul/laravel-slack-clone/commit/211c3c0731ef89b4b029ef64c0ead6779e136c96))

## 0.1.0 (2026-07-10)

Initial release — the first tagged, self-hostable cut of the app.

### Features

**Channels**

- Create & join channels; archive channels
- Unread & mention badges; new-messages divider with jump-to-unread
- Per-member notification preferences (mute & level)
- Window the initial message load around the unread boundary

**Messaging**

- Post & read channel messages over HTTP; realtime delivery over Reverb
- Edit & delete messages; inline quoted replies; @mentions; typing indicators
- Emoji reactions; forward a message to another channel
- Link unfurling / Open Graph previews
- Per-channel unsent composer drafts; read receipts ("seen by")

**Threads**

- Slack-style threaded replies; per-thread read state & unread dots
- Threads inbox; paginated thread-panel replies

**Navigation & workspace**

- Default 3-pane workspace shell & navigation
- Quick switcher command palette; keyboard shortcuts + help modal
- Star channels; collapsible and custom drag-ordered sidebar sections
- Live unread & mention badges in the sidebar

**Identity & presence**

- User profile pages and hover cards with quick actions
- Extended profile fields (pronouns, title, phone); per-user timezone
- Online presence dots on member avatars

**Search, notifications & admin**

- Full-text message search (Scout + Meilisearch)
- Audible chimes for incoming messages
- Workspace audit log for moderation & admin actions

**Security & account**

- Active session / device management; login & security activity history
- Account deletion policy & GDPR data export
- Team ownership transfer

### Bug Fixes

- Never land on a 404 after a team switch or login
- Align message-list presence dots with the avatar rhythm
- Opt the message composer out of password-manager autofill
