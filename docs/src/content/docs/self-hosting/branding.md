---
title: Branding
description: Whitelabel your instance — rename it, replace the mark, favicons, Open Graph image and PWA icons, and remove the attribution line.
---

An instance you self-host can carry your own name and marks. Everything here is
read at **runtime**, from `.env` and from a directory you mount, so nothing on
this page needs a fork, a rebuild, or a custom image.

There are three pieces:

1. **The name** — one `.env` setting.
2. **The marks** — files you drop into a mounted directory.
3. **The attribution line** — one `.env` setting.

## 1. Rename the instance

```ini title=".env"
APP_NAME=Acme Chat
```

Then restart the stack:

```bash
docker compose up -d
```

That covers page titles, the mail from-name, the error pages, the sign-in
screen, the user menu, and **the installed app**: the web app manifest is
rendered per request from `APP_NAME`, so an instance that sets only this
installs to a home screen or a dock as "Acme Chat".

:::caution
Editing `.env` through a PaaS environment editor (Dokploy, Coolify, …)? Those
editors normalise quotes away, so `APP_NAME="Acme Chat"` can land as
`APP_NAME=Acme Chat` and the boot fails with `Encountered unexpected
whitespace`. Use a single-word name there, or edit the file on disk.
:::

## 2. Replace the marks

The stack bind-mounts `./branding` (next to your `.env` and
`docker-compose.prod.yml`) into every app service. Drop a file in, restart
nothing — assets are resolved per request:

```bash
mkdir -p branding/icons
cp my-logo.svg branding/logo.svg
```

Every file is **optional and independent**. Supplying a logo does not oblige you
to supply an Open Graph image: anything you leave out falls back to the one
shipped in the image.

| File                              | Size        | Where it shows                                     |
| --------------------------------- | ----------- | -------------------------------------------------- |
| `logo.svg` *or* `logo.png`        | square      | The mark beside the instance name, in the app and on the sign-in and welcome screens. |
| `favicon.ico`                     | 16–48 px    | Browser tab, bookmarks.                             |
| `favicon.svg`                     | square      | Browser tab on browsers that prefer a vector.       |
| `apple-touch-icon.png`            | 180 × 180   | iOS home screen.                                    |
| `og-image.png`                    | 1200 × 630  | Link previews in chat apps and social networks.     |
| `icons/icon-192.png`              | 192 × 192   | Installed app (PWA).                                |
| `icons/icon-512.png`              | 512 × 512   | Installed app, splash screen.                       |
| `icons/icon-maskable-512.png`     | 512 × 512   | Adaptive launchers on Android. Keep the mark inside the middle 80% so it is not cropped. |

Filenames are exact and case-sensitive. Anything else in the directory is
ignored.

If you supply both `logo.svg` and `logo.png`, the SVG wins.

### The tradeoff on the logo mark

The mark shipped with The Desk is an inline SVG whose lower planes are drawn in
`currentColor`, so it **adapts to whatever surface it sits on** — dark ink on the
light theme, light ink on the dark one, without two files.

A supplied file cannot do that: it is rendered as an ordinary image and keeps its
own colours everywhere. Pick a mark that reads on **both** the light and dark
themes, or supply an SVG that carries its own
`@media (prefers-color-scheme: dark)` rules.

### Verifying

The assets are served from their canonical URLs, so you can check one directly:

```bash
curl -I https://chat.example.com/favicon.ico
curl -s https://chat.example.com/manifest.webmanifest
```

Responses carry an `ETag` and `Cache-Control: no-cache`, meaning browsers
revalidate rather than hold them: replace a file and the next request picks it
up. A browser that has already cached a favicon may still need a hard reload —
that is the browser's own favicon cache, not the server's.

:::note
The self-contained **500** and **503** fallback pages keep the shipped inline
mark. They deliberately reference no external file at all, so that they still
render when the app, the database, or the asset pipeline is the thing that is
broken. They do follow `APP_NAME`.
:::

## 3. Remove the attribution line

```ini title=".env"
BRANDING_ATTRIBUTION=false
```

Removes the "Powered by The Desk" footer link everywhere. See
[Feature toggles](/reference/feature-toggles/#powered-by-the-desk-attribution).

## What is not configurable

- **Theme colours.** The palette is contrast-audited against WCAG AA in both
  themes. Accepting an arbitrary brand accent would mean shipping colour pairs
  that have never been checked, so it is deliberately not offered.
- **Per-workspace branding.** Branding is instance-wide. Teams inside one
  instance share it.
- **An admin UI.** There is none, on purpose: a mounted directory needs no new
  authenticated upload surface, and your files are never inside the image, so an
  upgrade cannot clobber them.

## Upgrading

Nothing to do. `./branding` lives on the host, outside the image, so
`docker compose pull && docker compose up -d` leaves it untouched. If a future
release adds a new asset, it ships with a default and you can override it later.
