---
paths:
  - "docs/**"
---

# Self-Hosting Documentation (Starlight)

CLAUDE.md carries the trigger — an operator-facing change means updating `docs/` in the same PR, because that trigger fires on *app* changes. This rule carries the mechanics.

- **The public docs site lives in `docs/`** (an Astro Starlight project, deployed to Cloudflare Pages). It is self-contained — its own `package.json`/`node_modules`, isolated from the Laravel/Vite app and **excluded from every app quality gate** (ESLint, Pint, Prettier, PHPStan, Rector, `vue-tsc`). Content is Markdown/MDX under `docs/src/content/docs/`; site config (title, sidebar, edit links) is in `docs/astro.config.mjs`. Not to be confused with `dev-docs/` (internal ADRs, PRD, design notes — not published).
- **When a change adds or alters anything operator-facing, update the docs in the same PR.** This is a non-negotiable part of "done" for such changes, the same way i18n catalogs and tests are. Triggers include:
  - A new or changed **`.env`/config setting** an operator would set — especially a feature toggle (like `REGISTRATION_ENABLED`, `EMAIL_VERIFICATION_ENABLED`). Update `docs/src/content/docs/reference/environment-variables.md` and, if it's an on/off switch, `docs/src/content/docs/reference/feature-toggles.md`.
  - A change to **install, configuration, reverse-proxy/TLS, first-run, or upgrade** steps, or to the **production stack** (services in `docker-compose.prod.yml`, volumes, drivers). Update the relevant page under `docs/src/content/docs/self-hosting/` and `docs/src/content/docs/reference/architecture.md`.
- **Source the docs from the code, not memory.** Read the actual `config/*.php`, `.env.example`, and `docker-compose.prod.yml` so defaults and behaviour are accurate; if the root `README.md` disagrees with the compose file, the compose file wins (and file a `documentation` issue for the stale README).
- **Verify the site still builds:** `cd docs && npm run build` (Node tooling for the docs site runs on the host, not through Sail — its `node_modules` is a separate host install). See `docs/README.md` for dev/build commands. Add a matching key to any relevant page's sidebar entry in `astro.config.mjs` when you add a new page.
- **Use the Node pinned in `docs/.nvmrc` (22.16.0, matching the Cloudflare builder) whenever you regenerate `docs/package-lock.json`.** The lock is resolved on the host but consumed on Linux, and platform-gated optional dependencies mean a lock resolved on another Node/OS can omit entries Linux needs — `npm ci` then aborts with `EUSAGE` and the deploy fails after merge (#614). The `docs` workflow (`.github/workflows/docs.yml`) runs `npm ci` + `npm run build` on `ubuntu-latest` for any PR touching `docs/**`, so this is caught before it lands; `tests/Unit/DocsBuildGateTest.php` keeps that guard and the pin from drifting.
