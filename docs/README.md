# The Desk — Documentation site

The public documentation site for [The Desk](https://github.com/emmpaul/the-desk),
built with [Astro Starlight](https://starlight.astro.build/). It lives inside the
main repo under `docs/` but is a **self-contained project** with its own
`package.json` and `node_modules` — completely isolated from the Laravel/Vite
application and its quality gates.

The site has two parts:

- **Marketing landing page** at `/` — a standalone Astro page (not Starlight) in
  `src/pages/index.astro`, with its own self-contained styles.
- **Documentation** under `/docs` — Starlight pages. Content lives in
  `src/content/docs/docs/`; site config (title, sidebar, edit links) is in
  `astro.config.mjs`.

## Local development

All commands run from **this `docs/` directory** (not the repo root), and on the
Node version pinned in `docs/.nvmrc` — **22.16.0**, matching the Cloudflare Pages
builder (which bundles npm 10.9.2). The same pin is declared in `engines` here
and used by the `docs` CI workflow.

```bash
cd docs
nvm use            # or `fnm use` — picks up docs/.nvmrc
npm install        # first time only
npm run dev        # dev server with hot reload at http://localhost:4321
```

**Regenerate `package-lock.json` on that pinned Node, never on another version.**
The lockfile is resolved on the contributor's machine but consumed on Linux, and
platform-gated optional dependencies mean a lock resolved elsewhere can omit
entries Linux needs — `npm ci` then aborts with `EUSAGE` and the Cloudflare
deploy fails after merge (see #614). The `docs` workflow runs `npm ci` and
`npm run build` on `ubuntu-latest` for any PR touching `docs/**` so that desync
is caught before it lands.

## Build

```bash
cd docs
npm run build      # static site → docs/dist/
npm run preview    # serve the built site locally to check it
```

`npm run build` produces a fully static site in `docs/dist/`, including the
[Pagefind](https://pagefind.app/) search index (Starlight builds it
automatically).

## Commands

| Command           | Action                                        |
| ----------------- | --------------------------------------------- |
| `npm install`     | Install dependencies                          |
| `npm run dev`     | Start the dev server at `localhost:4321`      |
| `npm run build`   | Build the production site to `./dist/`        |
| `npm run preview` | Preview the production build locally          |

## Deployment (Cloudflare Pages)

The site deploys to Cloudflare Pages as a static build from this subdirectory:

- **Root directory:** `docs`
- **Build command:** `npm run build`
- **Output directory:** `dist`
- **Build watch paths:** `docs/**` — so Laravel-only commits don't trigger a docs
  deploy.

Update `site` in `astro.config.mjs` to the deployed URL (or your custom domain)
so the sitemap and canonical links are correct.
