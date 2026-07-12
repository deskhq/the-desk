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

All commands run from **this `docs/` directory** (not the repo root). Node 18+ is
required.

```bash
cd docs
npm install        # first time only
npm run dev        # dev server with hot reload at http://localhost:4321
```

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
