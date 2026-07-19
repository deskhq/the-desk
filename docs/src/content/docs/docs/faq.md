---
title: Frequently asked questions
description: Common questions about self-hosting The Desk — cost, server requirements, backups, scaling, registration, mobile, attachments, SSO, upgrades, and data privacy.
---

Short answers to the questions people ask before self-hosting The Desk. Most link
to the fuller docs.

## Is it really free?

Yes. The Desk is **MIT-licensed** and free forever. There's no per-seat pricing,
no paywalled features, and no "open core" edition held back — because there's no
company to pay. Members, message history, and file storage are bounded only by
your own disk.

## What do I need to run it?

- A Linux host with **Docker Engine 24+** and the Compose plugin.
- A **domain name** and a **TLS-terminating reverse proxy** (nginx, Caddy,
  Traefik…) in front of the stack — HTTPS is your responsibility; the containers
  speak plain HTTP.
- Working **SMTP credentials** for invitations (and optional email verification).

See [Requirements](/docs/self-hosting/requirements/) for the full list.

## How much server does it need?

The Desk is modest. A small **2 vCPU / 2 GB RAM** VPS comfortably hosts a team;
scale up as message volume and the search index grow. See the
[Architecture reference](/docs/reference/architecture/) for what each service
does.

## How do I back it up?

Run `./docker/backup.sh`. It dumps the **PostgreSQL** database and archives the
**uploads** volume, which together are everything durable: the Meilisearch index
is rebuilt from Postgres on boot and Redis holds only cache, sessions, and queued
jobs, so neither needs backing up. `./docker/restore.sh` puts both back.

Add `--keep=N` to prune old backups and drive it from host cron for a schedule.
See [Backups](/docs/self-hosting/upgrading/#backups) for the details, and
the [Architecture reference](/docs/reference/architecture/) for exactly what runs
and where state lives.

## Can people sign up themselves, or is it invite-only?

Both — you choose. Open registration is controlled by the
[`REGISTRATION_ENABLED`](/docs/reference/feature-toggles/#open-registration)
toggle. Turn it off and the workspace is invitation-only. You can also require
[email verification](/docs/reference/feature-toggles/#email-verification).

## Is there a mobile app?

Not yet. The web app is fully responsive and works well in a mobile browser, but
there's no dedicated iOS/Android app or mobile push notifications today.

## Does it support file attachments, voice, or video?

**File & image attachments** are supported. **Voice and video calls** are not on
the near-term roadmap; if you need those now, see the
[comparison page](/docs/comparison/).

## Does it support SSO, OIDC, or LDAP?

Not today — single sign-on and directory-managed users are on the roadmap but not
available in v1.10.0. If your organization requires them now, a larger platform is <!-- x-release-please-version -->
the safer choice for the moment. See the [comparison](/docs/comparison/).

## How do upgrades work?

Upgrades are **tag-based** — you move to a new release tag and pull. Database
migrations and version-scoped search reindexing run automatically. See
[Upgrading](/docs/self-hosting/upgrading/) for the exact steps.

## Is my data private?

Yes — that's the point. The Desk is **self-hosted**, so your messages never leave
your server. There's no SaaS middleman, no analytics phoning home, and every user
can export their own data at any time. It's your database either way.

## What's the tech stack?

Laravel 13, Inertia + Vue 3, Laravel Reverb (WebSockets), PostgreSQL,
Meilisearch, and Redis — all in one compose file behind a FrankenPHP app image.
The full picture is in the [Architecture reference](/docs/reference/architecture/).
