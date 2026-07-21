# The Desk — Documentation site

The public documentation site for [The Desk](https://github.com/deskhq/the-desk),
built with [Astro Starlight](https://starlight.astro.build/). It lives inside the
main repo under `docs/` but is a **self-contained project** with its own
`package.json` and `node_modules` — completely isolated from the Laravel/Vite
application and its quality gates.

It is served from `docs.thedeskhq.app`, and the documentation sits at the root of
that origin. The site has two parts:

- **Documentation** at `/` — Starlight pages. Content lives in
  `src/content/docs/`; site config (title, sidebar, edit links) is in
  `astro.config.mjs`.
- **API reference** under `/api-reference` — generated at build time by
  [starlight-openapi](https://starlight-openapi.vercel.app/) from
  `public/openapi.yaml`, the hand-authored OpenAPI 3.1 contract for the
  application's `/api/v1` surface. Because the file sits in `public/`, it is
  also served raw at `/openapi.yaml` for client generators.

## The OpenAPI spec

`public/openapi.yaml` is edited by hand, and two gates keep it honest:

- `npm run openapi:lint` validates it against the OpenAPI 3.1 schema, using the
  ruleset pinned in `redocly.yaml`. The `docs` CI workflow runs it before the
  build.
- `tests/Unit/OpenApiSpecTest.php` (in the **application's** PHP suite, not this
  project) diffs every documented path, method, and scope against the live route
  table, so adding an `/api/v1` route without documenting it fails the build.

Edit the spec whenever `routes/api.php`, an `App\Http\Requests\Api\V1\*` rule, or
an `App\Http\Resources\Api\V1\*` shape changes.

`starlight-openapi` renders code samples through `httpsnippet`, which pins
`form-data` to an exact version — so when that pin lands on a version carrying an
advisory, npm cannot resolve it away and the repo's `dependency-review` gate
fails the PR. The `form-data` entry in `overrides` forces a patched release
instead. Drop it once `httpsnippet` pins a clean version on its own.

## Local development

All commands run inside **this `docs/` directory** (`cd docs` from the repo root
first — none of them work from the root itself), and on the
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
| `npm run openapi:lint` | Validate `public/openapi.yaml` against the OpenAPI 3.1 schema |

## Deployment (Cloudflare Pages)

The site deploys to Cloudflare Pages as a static build from this subdirectory:

- **Root directory:** `docs`
- **Build command:** `npm run build`
- **Output directory:** `dist`
- **Build watch paths:** `docs/**` — so Laravel-only commits don't trigger a docs
  deploy.

Update `site` in `astro.config.mjs` to the deployed URL (or your custom domain)
so the sitemap and canonical links are correct.
