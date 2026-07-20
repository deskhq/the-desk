<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset=".github/readme-banner-dark.png">
    <img src=".github/readme-banner-light.png" alt="The Desk — open source, self-hosted team chat" width="800">
  </picture>
</p>

<p align="center">
  A calm, fast, <strong>self-hosted team chat</strong> app you run yourself —
  workspaces, channels, threads, reactions, search, reminders, and scheduled
  messages. Built with Laravel 13, Inertia + Vue 3, Laravel Reverb (WebSockets),
  and Meilisearch.
</p>

<p align="center">
  <a href="https://github.com/emmpaul/the-desk/actions/workflows/tests.yml"><img src="https://img.shields.io/github/actions/workflow/status/emmpaul/the-desk/tests.yml?branch=master&label=tests" alt="Tests"></a>
  <a href="https://github.com/emmpaul/the-desk/releases"><img src="https://img.shields.io/github/v/release/emmpaul/the-desk?color=c9a35c" alt="Latest release"></a>
  <a href="LICENSE"><img src="https://img.shields.io/github/license/emmpaul/the-desk?color=c9a35c" alt="License: MIT"></a>
</p>

<p align="center">
  <a href="https://demo-the-desk.emmanuelpaul.com/"><strong>Live&nbsp;demo</strong></a> ·
  <a href="https://the-desk.emmanuelpaul.com">Website</a> ·
  <a href="https://the-desk.emmanuelpaul.com/docs/">Docs</a> ·
  <a href="https://the-desk.emmanuelpaul.com/docs/self-hosting/installation/">Install</a> ·
  <a href="https://the-desk.emmanuelpaul.com/docs/comparison/">vs&nbsp;Slack</a> ·
  <a href="LICENSE">MIT&nbsp;License</a>
</p>

<p align="center">
  <img src=".github/screenshot-app.png" alt="The Desk: a channel timeline with threads, reactions, and direct messages" width="900">
</p>

## Self-hosting

The Desk ships as a single Docker Compose stack with a prebuilt image. The
installer fetches the compose file, generates secrets, and pins the latest
release, then `up -d` runs it with no build step:

```bash
curl -fsSL https://raw.githubusercontent.com/emmpaul/the-desk/master/docker/install.sh | sh
# edit .env (APP_URL, mail, REVERB_*_PUBLIC), then:
docker compose up -d
```

Full operator docs (requirements, configuration, reverse proxy & TLS, and
upgrades) live at
**[the-desk.emmanuelpaul.com/docs](https://the-desk.emmanuelpaul.com/docs/)**.

### Public demo

Try it live at **[demo-the-desk.emmanuelpaul.com](https://demo-the-desk.emmanuelpaul.com/)** — the login page shows the shared credentials.

Set `DEMO_MODE=true` to run a public, single-shared-account demo off the seeded
"Northwind Labs" workspace (`php artisan demo:seed`). Every visitor signs in as
the same owner, so the mode adds guard rails: destructive owner actions are
blocked, all outbound email is swallowed, message/attachment writes are
rate-limited per IP, self-registration is forced off, and an hourly reset heals
the workspace. It defaults to `false` — leave it off on any real deployment. See
**[Running a public demo](https://the-desk.emmanuelpaul.com/docs/self-hosting/demo/)**.

## Development

Local development uses [Laravel Sail](https://laravel.com/docs/sail):

```bash
git clone https://github.com/emmpaul/the-desk.git
cd the-desk
cp .env.example .env
composer install
./vendor/bin/sail up -d
./vendor/bin/sail composer setup
```

Run the quality gate before pushing:

```bash
./vendor/bin/sail composer test        # Pint, PHPStan, Rector (dry-run), and tests at 100% coverage
./vendor/bin/sail npm run lint:check   # ESLint
./vendor/bin/sail npm run format:check # Prettier
./vendor/bin/sail npm run types:check  # vue-tsc
./vendor/bin/sail npm run test:js      # Vitest unit suite (composables, lib helpers, eslint rules)
./vendor/bin/sail npm run build        # Vite build
```

`./vendor/bin/sail composer ci:check` runs both gates in one command.

[Rector](https://github.com/rectorphp/rector) handles automated structural
refactoring (the semantic counterpart to Pint's formatter). The gate runs it in
dry-run mode; when it reports pending changes, apply them and re-run the gate:

```bash
./vendor/bin/sail composer refactor    # apply Rector's suggested refactors
```

### Browser (E2E) realtime tests

`tests/Browser` holds Pest 4 browser tests that drive real Playwright browsers
against the app served in-process, with two clients exchanging messages over a
live Reverb server — the realtime send/receive, typing, edit, and delete paths
that headless feature tests can't reach. They live in a separate `browser` test
group excluded from the coverage gate, so `composer test` never runs them (and
they never affect the 100% coverage requirement). CI runs them in a dedicated
`browser` job.

Prerequisites (one-time, inside the Sail container):

```bash
./vendor/bin/sail npm ci                              # playwright npm package
./vendor/bin/sail npx playwright install chromium     # the browser binary
```

Then, with Sail up (Reverb is part of `sail up -d`) and the frontend built:

```bash
./vendor/bin/sail npm run build                       # tests use the built assets
./vendor/bin/sail composer test:browser               # or: sail bin pest tests/Browser
```

Rebuild the frontend (`npm run build`) after changing any Vue component the
tests touch, since the in-process server serves the compiled Vite assets.

### Local SSO providers (OIDC & LDAP)

Two opt-in dev containers let you exercise the single sign-on flows against real
providers locally, without registering an app at an external IdP. They sit
behind the `sso` compose profile, so a plain `sail up` never starts them. Enable
them by setting `COMPOSE_PROFILES=sso` in your `.env` (or `sail up --profile
sso`), then uncomment the matching dev values in `.env.example`:

- **OIDC** — a mock provider ([soluto/oidc-server-mock](https://github.com/Soluto/oidc-server-mock))
  seeded with `oidc1@the-desk.local` … `oidc4@the-desk.local` (password
  `password`) and a pre-registered `the-desk-dev` client. It's reached as
  `oidc:8081` from both the browser and the app container so the derived issuer
  stays consistent, so add one line to your host's `/etc/hosts` once:

    ```
    127.0.0.1 oidc
    ```

    Then set `SSO_OIDC_ISSUER=http://oidc:8081`, `SSO_OIDC_CLIENT_ID=the-desk-dev`,
    `SSO_OIDC_CLIENT_SECRET=the-desk-dev-secret` and use "Sign in with SSO".

- **LDAP** — an OpenLDAP directory ([osixia/openldap](https://github.com/osixia/docker-openldap))
  seeded with `ldap1@the-desk.local` … `ldap4@the-desk.local` (password
  `password`) under `dc=the-desk,dc=local`. Uncomment the dev block in
  `.env.example`:

    ```
    LDAP_HOST=ldap
    LDAP_PORT=389
    LDAP_BASE_DN="dc=the-desk,dc=local"
    LDAP_USERNAME="cn=admin,dc=the-desk,dc=local"
    LDAP_PASSWORD=adminpassword
    LDAP_ATTR_GUID=entryuuid   # OpenLDAP's stable id, not AD's objectGUID
    ```

    Then sign in through the normal login form with a seeded email.

## Contributing

Contributions are welcome — bug reports, docs fixes, and pull requests. Please
read **[CONTRIBUTING.md](CONTRIBUTING.md)** for the development setup, the quality
gates (100% test coverage, Pint/PHPStan/Rector, and the frontend checks), the
Conventional Commits convention, and the PR workflow.

## Security

Found a vulnerability? Please report it privately through GitHub's
[private vulnerability reporting](https://github.com/emmpaul/the-desk/security/advisories/new)
rather than opening a public issue. See **[SECURITY.md](SECURITY.md)** for the
full policy, supported versions, and response timeline. The codebase is scanned
continuously with CodeQL, dependency review, and Dependabot; findings surface in
the [Security tab](https://github.com/emmpaul/the-desk/security).

## License

The Desk is open source under the [MIT License](LICENSE).
