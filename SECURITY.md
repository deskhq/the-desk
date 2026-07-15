# Security Policy

The Desk is a self-hosted application, so most deployments run entirely under
your own control. We still take security seriously and want reporting a problem
to be straightforward and safe.

## Supported versions

The Desk ships from `master` with automated releases, so security fixes land in
the next tagged release rather than being backported. Always run the most recent
release to stay covered.

| Version        | Supported |
| -------------- | --------- |
| Latest release | Yes       |
| Older releases | No        |

## Reporting a vulnerability

Please **do not** open a public issue, discussion, or pull request for a security
vulnerability. Public disclosure before a fix is available puts every operator at
risk.

Report vulnerabilities privately through GitHub's **private vulnerability
reporting**:

1. Open the repository's [**Security** tab](https://github.com/emmpaul/the-desk/security).
2. Click **Report a vulnerability**, or use the direct link:
   [github.com/emmpaul/the-desk/security/advisories/new](https://github.com/emmpaul/the-desk/security/advisories/new).
3. Describe the issue with enough detail to reproduce it: affected version, a
   proof of concept or steps, and the impact you observed.

Reports stay private between you and the maintainers until a fix is released.

## What to expect

We aim to respond to a report on the following timeline (best effort for a
volunteer-maintained project):

| Stage                          | Target             |
| ------------------------------ | ------------------ |
| Acknowledge your report        | Within 3 days      |
| Initial assessment and triage  | Within 7 days      |
| Fix and coordinated disclosure | Depends on severity and complexity |

We follow **coordinated disclosure**: we will work with you on a fix, agree on a
disclosure date, and credit you in the published advisory unless you prefer to
stay anonymous. Please give us a reasonable window to ship a fix before disclosing
publicly.

## Scope

In scope:

- The application code in this repository (Laravel backend, Inertia + Vue
  frontend, and the Reverb WebSocket server).
- The production deployment assets in this repository (`docker-compose.prod.yml`,
  the container image build, and the default configuration).

Out of scope:

- Vulnerabilities in third-party dependencies that already have a public advisory
  and an available upgrade. Bump the dependency (Dependabot handles most of these
  automatically) or open a regular issue instead.
- Findings that require a misconfigured, out-of-date, or otherwise non-default
  deployment (for example, running with `APP_DEBUG=true` in production, or exposing
  internal services without a reverse proxy). See the
  [self-hosting docs](https://the-desk.emmanuelpaul.com/docs/self-hosting/installation/)
  for the recommended setup.
- Social engineering, physical attacks, and denial-of-service testing against any
  hosted instance you do not own.

## Automated scanning

This repository runs continuous security scanning so many classes of issue are
caught before they ship:

- **CodeQL** static analysis on every push and pull request, plus a weekly scan.
- **Dependency review** on pull requests, which blocks known-vulnerable
  dependencies from being introduced.
- **Dependabot** security updates and secret scanning with push protection.

Findings surface in the repository's **Security** tab.
