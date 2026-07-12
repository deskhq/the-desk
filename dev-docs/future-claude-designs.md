# Design & Product Gaps

## Product Flows

### First-run / No Team State
- User registers with no invitation.
- Create-your-first-team screen.
- General "Create Team" flow.

### Invitation Accept Flow
- Landing on an invite link while logged in.
- Accept / decline invitation.
- Pending invitations list.

### Team Settings
- Members list with roles.
- Invite member.
- Transfer ownership.
- Delete team.
- Existing modals are implemented, but a complete settings page has not been designed.

### Search & Threads
- Search results page.
- Thread inbox (the **Threads** navigation item currently leads to an undesigned destination).

### Scheduled Messages
- Scheduled messages list/dialog.
- "Send later" option in the composer.

---

## Settings (Beyond 5i)

- Sessions management.
- Security activity log.
- Data export.
- Delete account (`DataPrivacy.vue`).
- Appearance (Light / Dark / System).
- Notification preferences.
- Chime preferences.

---

## System States

- Error pages:
    - 403
    - 404
    - 500
    - Maintenance
- Designed in **The Desk** brand voice.
- Loading & skeleton states.
- Toast notifications (`flashToast` already exists in code).
- Dark mode for auth pages.
- Mobile layouts for auth and welcome pages.

---

## Non-UI Surfaces

### Transactional Emails
Code exists, but no visual design for:
- Verify email.
- Reset password.
- Team invitation.
- Data export ready.

### Branding Assets
- Favicon.
- Open Graph (OG) image for the welcome page.

---

# Priority

1. First-run / Create Team flow.
2. Invitation Accept flow.
3. Error pages.
4. Transactional emails (currently the most neglected brand surface).

---

**Recommendation:** Start with **First-run / Create Team**, then **Invitation Accept**, followed by **Error Pages**, and finally the **Transactional Emails**.
