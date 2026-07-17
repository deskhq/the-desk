---
title: SOC 2 & ISO 27001 control mapping
description: How the controls The Desk ships map to SOC 2 Trust Services Criteria and ISO 27001 Annex A, so your auditor's job is easy.
---

This page is written for an auditor. It maps the security controls The Desk ships
to the **SOC 2 Trust Services Criteria** and **ISO/IEC 27001:2022 Annex A** so an
adopter running a self-hosted instance can point an assessor straight at the
relevant evidence.

## What "certified" means here

:::caution[The Desk is not, and cannot be, "SOC 2 certified" or "ISO 27001 certified"]
SOC 2 and ISO 27001 certify an **organization** and the way it operates a system,
not a piece of software. There is no SaaS version of The Desk to certify: every
instance is run by the adopter, on infrastructure the adopter controls. So the
subject of any audit is **your** organization, not this project.
:::

What this project can do, and what this page is for, is make that audit
straightforward: The Desk ships the technical controls an auditor expects to see
(authentication, least-privilege access, audit logging, session revocation,
provisioning and deprovisioning, data portability and erasure), and this page
tells you where each one lives so you can produce evidence quickly. The mappings
below are **indicative**: they show which criteria a control is relevant to, not
a claim of full coverage. Your auditor decides whether a control meets a
criterion in the context of your whole system.

## Control mapping

| Control area | What The Desk provides | SOC 2 (Trust Services Criteria) | ISO 27001:2022 (Annex A) |
| --- | --- | --- | --- |
| **Authentication** | Password sign-in via Laravel Fortify, plus optional SSO through OpenID Connect and LDAP / Active Directory. Failed-login throttling (5 attempts per minute, keyed to email and IP). A local break-glass account keeps working during an SSO outage unless you disable it. | CC6.1 | A.5.15, A.5.16, A.8.5 |
| **Multi-factor authentication** | Delegated to your identity provider. Route every sign-in through SSO with `AUTH_SSO_ONLY=true` and enforce MFA (TOTP, push, or passkeys) at the IdP. See the note below. | CC6.1 | A.8.5, A.5.17 |
| **Password policy** | In production, passwords must be at least 12 characters with mixed case, letters, numbers, and symbols, and are checked against the Have I Been Pwned breach corpus (k-anonymity, no password leaves the server). Stored only as a bcrypt hash (work factor 12); never in plaintext or reversible form. | CC6.1 | A.5.17, A.8.5 |
| **RBAC and least privilege** | Three team roles (Owner, Admin, Member) enforced by server-side policies on every action. Members hold no management permissions by default. Team deletion and ownership transfer are Owner-only and cannot be delegated. | CC6.3 | A.5.15, A.5.18, A.8.2, A.8.3 |
| **Audit logging and tamper-evidence** | A team-scoped admin audit log records rename, role change, member removal, ownership transfer, channel lifecycle, message deletion, emoji revocation, and the invitation lifecycle (sent, resent, cancelled, accepted). It is append-only: the application rejects any attempt to edit or delete an entry. Admins and Owners view it read-only in the app. | CC7.2, CC7.3 | A.8.15, A.8.16 |
| **Account-activity log** | Each user sees their own recent security events — sign-in, sign-out, password and two-factor changes, passkey changes, session revocation, data-export requests and downloads, SSO provisioning and (de)activation, and workspace deletion — with IP address, user agent, and a new-device flag, so they can spot unfamiliar access. | CC7.2 | A.8.15, A.8.16 |
| **Audit evidence export** | Admins and Owners can export either the workspace audit log or the security-event log as a CSV or JSON file, optionally scoped to a date range, for an assessment period. The file is built in the background, emailed to the requester, and downloadable by any current team admin or owner for 7 days. See [Audit-log exports](#audit-log-exports). | CC7.2, CC7.3 | A.8.15, A.8.16 |
| **Session management and revocation** | Session cookies are HTTP-only and `SameSite=Lax`, and are marked `Secure` when `SESSION_SECURE_COOKIE=true`. Users see every active session and can revoke a single device or sign out all other devices; a revoked session is force-signed-out on its next request. | CC6.1 | A.5.15, A.8.5 |
| **Provisioning and deprovisioning (SSO / SCIM)** | SSO logins just-in-time provision accounts. SCIM 2.0 lets your IdP push lifecycle changes: removing someone from the directory deactivates their account here, revoking access and ending all their sessions immediately, while retaining history. Reactivation is a later `active: true`. | CC6.1, CC6.2, CC6.3 | A.5.16, A.5.18 |
| **Data export (portability)** | A user can request a self-service export of their own data: a ZIP of JSON files (profile, teams, messages, and their security events), delivered by email and downloadable for 7 days. | (Privacy) P5 | A.5.34, A.8.11 |
| **Account deletion and erasure** | Deleting an account removes the user record and reassigns their authored messages to a shared "Deleted User" tombstone, so conversations stay coherent while personal attribution is removed. The user's personal teams are deleted. | (Privacy) P4 | A.8.10, A.5.34 |
| **Retention windows** | Uploaded-but-unsent attachments are swept after `ATTACHMENT_PENDING_TTL_HOURS` (default 24). Data-export archives and audit-evidence export files are downloadable for a fixed 7-day window, after which a scheduled task deletes both the file and its record. Long-term retention of the audit and activity logs themselves is an operator decision (see the note below). | (Privacy) P4 | A.8.10, A.5.34 |
| **Encryption and transport posture** | The application encrypts session data and any encrypted attributes with `APP_KEY` (AES-256). Containers speak plain HTTP by design; TLS termination is delegated to your reverse proxy (see operator responsibilities). | CC6.1, CC6.7 | A.8.24, A.5.14 |
| **Backups** | Durable state is one PostgreSQL database plus the uploaded-files volume; the search index and Redis are derived or transient. `docker/backup.sh` and `docker/restore.sh` ship with the stack and cover the dump, restore, and retention (`--keep=N`), but scheduling, testing, encrypting, and off-siting backups is an operator responsibility. | A1.2 | A.8.13 |

### Notes an auditor should read

- **Audit-log immutability is enforced in the application, not cryptographically.**
  The audit log rejects updates and deletes through the app, but it does not
  hash-chain or sign entries. Treat the database itself as in-scope: restrict
  direct database access and rely on your infrastructure controls (least-privilege
  database credentials, backups, and network isolation) as the second layer.
- **MFA is delegated, not built in.** The Desk does not ship its own TOTP or
  passkey enrollment today. The supported way to require MFA is to front all
  access with SSO (`AUTH_SSO_ONLY=true`) and enforce the factor at your identity
  provider, which is where most SOC 2 and ISO 27001 programs already manage it.
- **Long-term log retention and disposal is yours to schedule.** The app does not
  automatically prune the audit or account-activity logs, so they accumulate.
  Define a retention window that matches your policy and enforce it at the
  database or backup layer.
- **The password policy is enforced in production.** The full complexity and
  breach-check rules apply when the app runs in its production environment, which
  is the configuration you audit.

## Audit-log exports

Admins and Owners can export a workspace's audit evidence from **Team settings ›
Exports**. Each export is one log, in one format, over one period:

- **Log** — either the **workspace audit log** (rename, role change, member
  removal, ownership transfer, channel lifecycle, message deletion, emoji
  revocation, the invitation lifecycle, and export requests) or the
  **security-event log** (sign-in, sign-out, password change and reset,
  two-factor and passkey changes, session revocation, data-export requests and
  downloads, SSO provisioning and (de)activation, and workspace deletion).
- **Format** — **CSV** for spreadsheets, or **JSON** for the full records with
  nested properties preserved.
- **Period** — an optional inclusive date range, interpreted as whole days in the
  requester's timezone and converted to UTC for the query. Leave it empty to
  export everything.

The file is generated in the background, the requester is emailed when it is
ready, and **any current admin or owner** of the team can download it for 7 days.
All timestamps in the file are UTC ISO-8601 (for example `2026-07-15T14:03:22Z`).
Only one export can be generating per team at a time. Every export request is
itself recorded in the audit log, so the act of exporting is auditable.

Files live on the private application disk and are never publicly linked; download
re-checks the same policy that guards the on-screen log. After 7 days a scheduled
task deletes both the file and its record.

:::caution[Security-event exports are account-level]
Security events are recorded against a **user account**, not a team — a person's
sign-ins are the same events whichever workspace they were acting in. A team's
security-event export therefore contains the account-level events of the team's
**current** members for the chosen period, which can include activity from before
they joined this team or from while they were acting in another team. This matches
the on-screen security log exactly; there is no team boundary in the underlying
data to scope it tighter. Removing a member immediately drops their events from
both the view and future exports.
:::

## Operator responsibilities

A self-hosted deployment delegates several controls to you. An auditor will expect
evidence for each of these from **your** environment, not from this project:

- **TLS termination and HSTS.** The Desk's containers speak plain HTTP. Terminate
  TLS at your reverse proxy, redirect HTTP to HTTPS, and set HSTS. See
  [Reverse proxy & TLS](/docs/self-hosting/reverse-proxy/), and set
  `SESSION_SECURE_COOKIE=true` and the browser-facing `REVERB_SCHEME_PUBLIC=https`.
- **Encryption at rest.** Encrypt the disk or volume that holds the PostgreSQL
  database and the uploaded-files volume. This is provided by your host or storage
  layer, not the app.
- **Backups.** Take, encrypt, test, and off-site the database and file-volume
  backups. `docker/backup.sh` and `docker/restore.sh` handle the taking and the
  restoring, including a `--keep=N` retention flag and a host-cron example; see
  [Upgrading](/docs/self-hosting/upgrading/#backups). Encrypting and
  off-siting the resulting files remains yours.
- **Secret management.** `APP_KEY`, `DB_PASSWORD`, `MEILISEARCH_KEY`, the
  `REVERB_*` credentials, and any `SCIM_TOKEN` are full secrets. Generate them
  with `./docker/gen-secrets.sh`, store them in a secret manager rather than a
  committed `.env`, and rotate on exposure.
- **Network isolation.** Expose only the web port publicly; keep PostgreSQL,
  Redis, Meilisearch, and Reverb on an internal network. Restrict administrative
  and database access to trusted operators.
- **Patching.** Stay on the [latest release](/docs/self-hosting/upgrading/) so you
  receive security fixes, and keep the host OS and reverse proxy patched.

## Related reference

- [Feature toggles](/docs/reference/feature-toggles/) for the switches that turn
  registration, SSO-only mode, SCIM provisioning, and audit logging on or off.
- [Environment variables](/docs/reference/environment-variables/) for the exact
  `.env` settings behind every control above.
- [Security & vulnerability reporting](/docs/reference/security/) for how to report
  a problem privately and the automated scanning that guards the codebase.
