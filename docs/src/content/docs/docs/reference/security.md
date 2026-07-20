---
title: Security & compliance
description: How to report a vulnerability in The Desk, and the automated security scanning that runs on every change.
---

The Desk is self-hosted, so you own the deployment and its data. This page covers
how to report a security problem responsibly and what automated checks guard the
codebase.

## Reporting a vulnerability

Please do not open a public issue for a security problem. Report it privately
through GitHub's **private vulnerability reporting** instead:

1. Open the [**Security** tab](https://github.com/emmpaul/the-desk/security) on the
   repository.
2. Click **Report a vulnerability**, or go straight to
   [the advisory form](https://github.com/emmpaul/the-desk/security/advisories/new).
3. Include the affected version, steps to reproduce, and the impact you observed.

The report stays private between you and the maintainers until a fix is released.
The full policy, including the response timeline, coordinated-disclosure
expectations, and scope, lives in
[SECURITY.md](https://github.com/emmpaul/the-desk/blob/master/SECURITY.md).

## Automated scanning

Every change to the repository runs through continuous security scanning, and all
findings surface in the repository's **Security** tab:

| Check                 | When it runs                              | What it does                                                              |
| --------------------- | ----------------------------------------- | ------------------------------------------------------------------------- |
| **CodeQL**            | Every push and pull request, plus weekly  | Static analysis of the JavaScript/TypeScript frontend for security bugs.  |
| **Dependency review** | Every pull request                        | Blocks introducing a dependency that has a known advisory.                |
| **Dependabot**        | Weekly, and on new advisories             | Opens PRs to update vulnerable or outdated dependencies.                  |
| **Secret scanning**   | Continuous, with push protection          | Detects committed credentials and blocks pushes that contain them.        |

:::note
CodeQL does not support PHP, so the Laravel backend is covered by PHPStan
(Larastan), Rector, and Dependabot rather than CodeQL. See the project's quality
gates in the repository for details.
:::

## Content Security Policy

Every web response carries a `Content-Security-Policy` header: the browser-side
allow-list that decides which scripts, styles, images and connections a page may
use. It does not replace output escaping. It caps the damage when escaping fails,
which matters here because The Desk renders user-authored content nearly
everywhere: messages, markdown, emoji names, link-preview titles, uploaded file
names.

It is **on by default** and ships in the image, so every deployment inherits it
without extra proxy configuration. See
[Feature toggles → Content Security Policy](/docs/reference/feature-toggles/#content-security-policy)
for the switches.

The policy sent is:

| Directive     | Value                                        |
| ------------- | -------------------------------------------- |
| `default-src` | `'self'`                                     |
| `script-src`  | `'self' 'nonce-…' 'strict-dynamic'`          |
| `style-src`   | `'self' 'unsafe-inline'`                     |
| `img-src`     | `'self' data: blob: https:`                  |
| `font-src`    | `'self'`                                     |
| `connect-src` | `'self'` plus your Reverb WebSocket origin   |
| `media-src`   | `'self'`                                     |
| `worker-src`  | `'self'`                                     |
| `frame-src`   | `'none'`                                     |
| `frame-ancestors` | `'none'`, or whatever `CSP_FRAME_ANCESTORS` names |
| `base-uri`    | `'self'`                                     |
| `form-action` | `'self'`                                     |
| `object-src`  | `'none'`                                     |

Scripts are the directive that matters, and they carry no `'unsafe-inline'`: the
app's own inline script runs only because it carries a per-request nonce, and the
page chunks the app loads as you navigate run only because `'strict-dynamic'`
extends that trust to them. Injected markup gets neither.

`base-uri` and `form-action` are stated separately for a reason: they do **not**
fall back to `default-src`. A policy that omits them leaves them wide open
however tight `default-src` is, so an injected `<base href="//attacker">` could
still repoint every relative URL on the page, and a fake login form could still
post credentials off-origin. `object-src` does fall back, but only to
`default-src 'self'`; nothing in The Desk renders an `<object>` or `<embed>`,
and the plugin documents they load are outside what `script-src` governs, so it
is denied outright instead.

`frame-ancestors` is the same kind of directive and answers the opposite
question to `frame-src`: not what the app may embed, but who may embed *it*. It
defaults to `'none'`, which is what stops an attacker overlaying an invisible
frame of your instance on their own page and steering a signed-in member's
clicks into real controls. It is paired with `X-Frame-Options: DENY` for
browsers that do not support CSP Level 2 `frame-ancestors`, and both are
configurable through
[`CSP_FRAME_ANCESTORS`](/docs/reference/feature-toggles/#clickjacking-protection)
if you embed the app in a portal of your own.

### Accepted residuals

Two directives are deliberately looser than they look, and both are limited to
resources that cannot execute script:

- **`style-src 'unsafe-inline'`.** Popovers, dropdowns and the emoji picker
  position themselves by writing style *attributes* at runtime. A nonce here
  would make browsers ignore `'unsafe-inline'` entirely, and the narrower
  `style-src-attr` is unsupported on Safari before 15.4, which would silently
  break every floating element. The exposure is CSS, not code execution.
- **`img-src https:`.** Link-preview thumbnails are fetched from whatever site
  was linked, Giphy results are hotlinked from Giphy's CDN, and the Gravatar
  base URL is yours to configure — so there is no enumerable host list to write
  down. Images cannot execute, and the risk this leaves (a crafted URL signalling
  that a page was viewed) is bounded by the tight `connect-src`.

### Running behind Cloudflare or a script-injecting proxy

Cloudflare features that inject their own JavaScript into your pages — Email
Obfuscation, Rocket Loader, a managed challenge — serve those scripts from
`/cdn-cgi/` without the app's nonce, so `script-src` will block them. If you use
any of them, either turn that Cloudflare feature off for the app's hostname or
allow-list it with `CSP_EXTRA_SCRIPT_SRC` (and `CSP_EXTRA_FRAME_SRC` for a
challenge that renders in a frame).

### Fonts are self-hosted

The app ships its own font files: they are downloaded at build time and served
from your own origin, which is why `font-src` defaults to `'self'` — anything
you add with `CSP_EXTRA_FONT_SRC` is appended to it. The app never requests
`fonts.googleapis.com` or `fonts.gstatic.com`.

So a Google Fonts violation in the browser console does **not** come from the
app. Something else is injecting stylesheets into the page — a browser
extension, or an edge feature that rewrites your HTML. Check those first. If you
have genuinely added a web font of your own, allow-list each origin it uses in
the matching key: the host serving the stylesheet in `CSP_EXTRA_STYLE_SRC`, and
the host serving the `@font-face` files in `CSP_EXTRA_FONT_SRC`. Google Fonts
splits those across two hosts and so needs both.

## Hardening your deployment

Most security outcomes for a self-hosted instance depend on how you run it. Follow
the [installation](/docs/self-hosting/installation/) and
[reverse proxy & TLS](/docs/self-hosting/reverse-proxy/) guides, keep
`APP_DEBUG=false` in production, and stay on the
[latest release](/docs/self-hosting/upgrading/) so you receive security fixes.
