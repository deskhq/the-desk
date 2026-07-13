<!-- Thanks for contributing! See CONTRIBUTING.md for the full workflow. -->

## What & why

<!-- What does this change do, and why? Link the issue it closes. -->

Closes #

## How I tested it

<!-- Tests added/updated, and how you verified the behaviour. -->

## Checklist

- [ ] Tests added or updated, and the suite passes at 100% coverage (`./vendor/bin/sail composer test`)
- [ ] Frontend checks pass (`./vendor/bin/sail npm run lint:check`, `format:check`, `types:check`, `build`) — if the frontend changed
- [ ] User-facing copy goes through the translation layer (`$t` / `__`), with `lang/fr.json` updated
- [ ] Docs under `docs/` updated if this changes anything operator-facing
- [ ] Commits follow Conventional Commits (lowercase type + subject)
