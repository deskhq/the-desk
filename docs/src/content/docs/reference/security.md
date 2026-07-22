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

1. Open the [**Security** tab](https://github.com/deskhq/the-desk/security) on the
   repository.
2. Click **Report a vulnerability**, or go straight to
   [the advisory form](https://github.com/deskhq/the-desk/security/advisories/new).
3. Include the affected version, steps to reproduce, and the impact you observed.

The report stays private between you and the maintainers until a fix is released.
The full policy, including the response timeline, coordinated-disclosure
expectations, and scope, lives in
[SECURITY.md](https://github.com/deskhq/the-desk/blob/master/SECURITY.md).

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

## HTTPS is pinned once it is available

Every response that arrives over HTTPS carries
`Strict-Transport-Security: max-age=31536000; includeSubDomains`. A browser that
has seen your instance once will not speak plain HTTP to it again for a year,
which closes the SSL-strip window: without the header, a first visit or a URL
typed without a scheme goes out unencrypted, and an attacker on the path can
answer it themselves and take the session cookie before your redirect to HTTPS
is ever reached.

It is sent only when the request really was secure — the app reads your proxy's
`X-Forwarded-Proto`. A deployment served over plain HTTP therefore never pins
itself to a scheme it cannot answer on. Session cookies pick up the matching
`Secure` flag by default whenever `APP_URL` is an `https://` URL, so a
downgraded request carries no session at all.

Both are configurable:
[HTTPS enforcement (HSTS)](/reference/feature-toggles/#https-enforcement-hsts)
and [`SESSION_SECURE_COOKIE`](/reference/environment-variables/#session-cookies).
`preload` is opt-in and off by default: it is effectively irreversible for a
domain and commits every subdomain with it.

## Rendered HTML is sanitized

A chat app has to turn what people type into markup: bold text, mention pills,
autolinked URLs, custom emoji, highlighted search hits. That is the classic
cross-site-scripting surface, where a crafted message becomes script running in
every reader's session.

The Desk handles it in two stages. The code that builds that markup escapes
every character of user input before it goes anywhere near a tag, and only ever
emits tags it writes itself. Then, whatever it produced is sanitized with
[DOMPurify](https://github.com/cure53/DOMPurify) against a fixed allow-list of
tags and attributes just before the browser renders it.

The second stage runs in exactly one component, `SafeHtml`, which is the only
place in the interface allowed to render a string as markup. Every surface goes
through it: message bodies, forwarded and quoted messages, the quick switcher,
the threads list, search snippets, and the two-factor QR code. Each names the
allow-list it renders under, so a search snippet can carry a highlight and
nothing else, and a QR code can carry SVG shapes but no script.

That rule is enforced mechanically rather than by review: the linter fails the
build on any other component using Vue's `v-html` directive, so a new component
cannot add a second, unsanitized rendering path by accident.

None of this is configurable, and there is nothing to switch on. It is
described here because it is the control that the policy below exists to back
up.

## Content Security Policy

Every web response carries a `Content-Security-Policy` header: the browser-side
allow-list that decides which scripts, styles, images and connections a page may
use. It does not replace the output escaping and sanitization described above.
It caps the damage if those ever fail, which matters here because The Desk
renders user-authored content nearly everywhere: messages, markdown, emoji
names, link-preview titles, uploaded file names.

It is **on by default** and ships in the image, so every deployment inherits it
without extra proxy configuration. See
[Feature toggles → Content Security Policy](/reference/feature-toggles/#content-security-policy)
for the switches.

The policy sent is:

| Directive     | Value                                        |
| ------------- | -------------------------------------------- |
| `default-src` | `'self'`                                     |
| `script-src`  | `'self' 'nonce-…' 'strict-dynamic'`          |
| `style-src`   | `'self' 'unsafe-inline'`                     |
| `img-src`     | `'self' data: blob:`                         |
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
[`CSP_FRAME_ANCESTORS`](/reference/feature-toggles/#clickjacking-protection)
if you embed the app in a portal of your own.

`img-src` allows no remote host, which is only possible because the app never
asks the browser to load one. See [Remote images are proxied](#remote-images-are-proxied)
below.

### Accepted residuals

One directive is deliberately looser than it looks, and it is limited to
resources that cannot execute script:

- **`style-src 'unsafe-inline'`.** Popovers, dropdowns and the emoji picker
  position themselves by writing style *attributes* at runtime. A nonce here
  would make browsers ignore `'unsafe-inline'` entirely, and the narrower
  `style-src-attr` is unsupported on Safari before 15.4, which would silently
  break every floating element. The exposure is CSS, not code execution.

### Remote images are proxied

Three things The Desk renders are images it does not host: link-preview
thumbnails scraped from whatever site someone linked, Giphy renditions, and
Gravatar avatars. Loading those directly would hand every reader's IP address,
user agent and referring page to those sites without the reader choosing to
visit them, and would force `img-src` to allow any HTTPS host.

Instead the server fetches each one and re-serves it from your own origin, under
a signed, session-authenticated URL. The signature pins the target to a URL the
server itself generated, so the endpoint cannot be used as an open proxy; the
fetch goes through the same SSRF guard as outgoing webhooks (see
[`WEBHOOKS_BLOCK_PRIVATE_URLS`](/reference/environment-variables/)), with a
5-second timeout, a 5 MB cap, redirects re-checked hop by hop, and a
raster-images-only content-type allowlist that excludes SVG. Fetched bytes are
cached on the private disk for seven days and swept daily.

Nothing needs configuring, and there is no toggle: an instance with no outbound
egress simply degrades — avatars fall back to initials and link previews render
without a thumbnail — rather than hanging or erroring. Setting
`GRAVATAR_ENABLED=false` stops the avatar fetch being attempted at all.

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

## Cookies

The Desk sets four cookies. Only one of them, the session cookie, is worth
stealing, and that one is HTTP-only so a script cannot read it. All four are
`SameSite=Lax`, so none is sent on a cross-site subrequest.

| Cookie                | Set by         | Contents                       | `HttpOnly` | Encrypted |
| --------------------- | -------------- | ------------------------------ | ---------- | --------- |
| `the-desk-session`    | Server         | The session identifier         | Yes        | Yes       |
| `XSRF-TOKEN`          | Server         | The CSRF token                 | No         | Yes       |
| `appearance`          | Browser        | `light`, `dark` or `system`    | No         | No        |
| `sidebar_state`       | Browser        | `true` or `false`              | No         | No        |

The `Secure` flag comes from two different places. The two cookies the browser
writes take it from the page's own scheme, so they are `Secure` on any HTTPS
page with nothing to configure. The two the server sets are `Secure` only when
`SESSION_SECURE_COOKIE=true`, which is **not** inferred from the request: set it
explicitly in any production deployment, or your session cookie will keep being
sent over plain HTTP. See
[reverse proxy & TLS](/self-hosting/reverse-proxy/).

The session cookie name follows `APP_NAME`, so it reads `the-desk-session` on a
default install and something else if you renamed the app.

### `XSRF-TOKEN` is readable by JavaScript on purpose

A surface scanner will flag `XSRF-TOKEN` as a cookie missing `HttpOnly`. That is
expected behaviour, not a defect, and we have accepted it deliberately.

Laravel's CSRF middleware sets that cookie precisely so the frontend can read it
and echo the value back in an `X-XSRF-TOKEN` header. The server then compares the
header against the token in the session. Making the cookie `HttpOnly` would put
the value out of the frontend's reach and break every non-form request in the app:
Inertia visits, posting a message, reactions, uploads. It is not the session
cookie, and it is encrypted at rest in the browser: stealing it grants no session,
only the ability to pass CSRF on a request you could already forge if you were
running script on the page.

Which is the real point. The threat this flag is meant to blunt is a malicious
script running on your origin, and the answer to that is stopping the script from
running at all. That is what the [Content Security Policy](#content-security-policy)
above is for.

The two browser-set cookies are exempt from Laravel's cookie encryption for the
same practical reason: the frontend writes them and the server reads them as
plain text. They hold a theme name and a boolean, so there is nothing in them to
protect. Nothing else is exempt, and a regression test asserts that the exemption
list stays those two.

## Hardening your deployment

Most security outcomes for a self-hosted instance depend on how you run it. Follow
the [installation](/self-hosting/installation/) and
[reverse proxy & TLS](/self-hosting/reverse-proxy/) guides, keep
`APP_DEBUG=false` in production, and stay on the
[latest release](/self-hosting/upgrading/) so you receive security fixes.
