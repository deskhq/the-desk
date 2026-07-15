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

## Hardening your deployment

Most security outcomes for a self-hosted instance depend on how you run it. Follow
the [installation](/docs/self-hosting/installation/) and
[reverse proxy & TLS](/docs/self-hosting/reverse-proxy/) guides, keep
`APP_DEBUG=false` in production, and stay on the
[latest release](/docs/self-hosting/upgrading/) so you receive security fixes.
