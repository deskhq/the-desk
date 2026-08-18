# ADR-0015: One guarded-egress module owns every connection to a member-controlled URL

- Status: Accepted — amended by [ADR-0016](0016-unfurler-is-a-separate-service.md) (2026-08-17)
- Date: 2026-08-03
- Relates to: epic architecture-hardening V (#1195), child #1196

> **Amendment note (2026-08-17).** The unfurl is no longer a PHP `fetch()` caller.
> [ADR-0016](0016-unfurler-is-a-separate-service.md) moves it to a separate Go
> service, for the two things this module cannot do from PHP: bound the response
> *while reading it* rather than after Guzzle has buffered it (#1202, deferred
> below), and check the destination address on the `connect(2)` path rather than
> asking curl to honour a pin. Three consequences land back here.
>
> - **`fetch()` has one caller left**, `FetchRemoteImage`. So `FetchPolicy`
>   collapses to `refusingOver()`: the `truncatingAt()` reading, the
>   `truncatesOversizeBody` flag and the truncation branch in `read()` were the
>   unfurl's and left with it. The "two size-cap readings are now stated rather
>   than implied" consequence below is history, and it was true when written.
> - **#1202 is closed on the unfurl path and still open on the image path.** The
>   scope note below deferred it as pre-existing and identical in both copies;
>   only one of those copies is left.
> - **The allowlist in `GuardedEgressHomeTest` gains a fifth entry**,
>   `App\Support\Unfurl\HttpUnfurler`, on the criterion `GiphyClient` and
>   `UpdateChecker` already established: it dials an operator-configured host.
>   The claim in the Decision below is unchanged and still literally true — PHP
>   opens no connection to a member-controlled URL for an unfurl, because it
>   opens none at all.

## Context

`OutboundUrlGuard` answers three separate questions — *is this URL public*
(`isPublic()`, static), *what address does it resolve to* (`resolveDeliveryIp()`),
and *how must the connection be pinned* (`transportOptions()`). None of them means
anything on its own: a URL vetted and then resolved by curl is a DNS rebind waiting
to happen, and a pinned connection that curl is free to redirect off is not pinned at
all. They are one sequence, asked in one order, on **every** hop.

Three call sites asked that sequence by hand — `FetchLinkPreview`,
`FetchRemoteImage` and `DeliverWebhook` — and the two `fetch` ones then spelled the
same ~45-line hop-and-pin walk twice: the same `for ($hop = 0; $hop <= MAX_REDIRECTS; $hop++)`,
the same three guard calls, the same `redirect()` → `Location` → `AbsoluteUrl::from()`
→ `continue` block, the same `Content-Length` pre-check. Even the class docblocks
were near-copies, both ending *"redirects followed manually so each new target is
re-checked rather than handed to curl"*.

**One copy had a `try/catch` and the other did not.** `FetchRemoteImage` caught
`Throwable` and returned null. `FetchLinkPreview` was unprotected, so an unreachable
host raised `ConnectionException` out of `Cache::remember` and out of
`UnfurlMessageLinks::handle`, failing and requeuing the job — while every sibling
egress site in the codebase degraded to an empty result instead. The 100% coverage
gate could not see it: an uncaught path leaves no line uncovered. There was no test
to write against it either, which is why `ImageProxyTest`, `WebhookDeliveryTest` and
`UpdateCheckTest` each had a `ConnectionException` case and `LinkPreviewFetchTest`
had none. The missing `catch` and the missing test were the same hole.

The duplication had a second cost, in the suite. The `HostResolver` stub was
re-declared **six times** under six names, two of them byte-for-byte identical. And
the pin was asserted at **one of three** call sites: `CURLOPT_RESOLVE` appeared in
tests only in `LinkPreviewFetchTest`, through a capture helper local to that file.
`allow_redirects => false` was asserted nowhere at all, and `transportOptions()` had
no direct test.

## Decision

**`App\Support\Http\GuardedEgress` is the only place this application opens a
connection to a member-controlled URL.** Two named readings, because two callers
need two different things from the same guarantee:

- **`fetch(string $url, FetchPolicy $policy): ?FetchedBody`** — *give me the bytes
  at this URL*. Owns the hop bound, re-guards and re-pins every hop, disables
  curl-level redirects, enforces the size cap, applies the policy's content-type
  predicate, and maps every failure — blocked destination, dead host, error status,
  wrong type, oversize body, redirect loop — to one null. Callers supply only what
  differs, through `FetchPolicy`: the content-type predicate, the byte cap, and
  which of the two readings of the cap they want (`truncatingAt()` for an unfurl
  that only parses `<head>`, `refusingOver()` for an image, where half the bytes is
  a corrupt file rather than a small one).
- **`send(string $url, PendingRequest $request, string $method): Response`** — *send
  this request I have already composed*. Owns the guard triple and the no-redirects
  rule and nothing else. `DeliverWebhook` adapts in and keeps its own retry, its own
  `recordFailure`, its own 255-character message trim.

Judged alone, `send()` is shallow. It earns its place as the second reading of a
module whose first reading is deep: leaving `DeliverWebhook` outside would keep the
three-call ritual public at one site, which is the thing the module exists to end.
It is also what `fetch()` is built on, so there is one vetting path, not two.

**A refused destination leaves through the same door as an unreachable one.**
`send()` throws `BlockedEgress` rather than returning a second shape every caller
would have to remember to check. `fetch()` catches it alongside the transport
failure — which *is* the behaviour fix — and `DeliverWebhook`'s existing
`catch (Throwable)` now covers all three of its old branches, logging the reason and
counting the attempt towards the auto-disable threshold exactly as before.

**`OutboundUrlGuard` splits by audience.**

- **`isPublic()` stays public and static.** `PublicWebhookUrl` asks it at validation
  time, before any request exists, and `User::routeNotificationForWebPush` asks it
  where the web-push package owns the connection. It is a *pre-flight* question, not
  an egress one.
- **`resolveDeliveryIp()` and `transportOptions()` keep their home on the guard but
  gain a single caller.** They are two thirds of a sequence with no meaning apart,
  and `GuardedEgress` is the only thing that asks them.
  `tests/Unit/GuardedEgressHomeTest.php` fails if a second caller appears.
- **The `HostResolver` container binding stays the seam.** It already works, and it
  is what proves the DNS-rebind defence. The win is that six anonymous stubs
  collapse to one shared `Tests\Support\StubHostResolver` with two named
  constructors: `returning()` for a fixed answer, `rebinding()` for a nameserver
  that answers differently on successive lookups.

**`Http::preventStrayRequests()` runs suite-wide**, from `Tests\TestCase::setUp()`.
With one module owning egress, a tenth site added later either goes through it or
turns the suite red; before this, an unfaked request quietly dialled whatever host
the fixture named.

## Consequences

- **The behaviour fix ships with a test.** An unreachable host on the link-preview
  path degrades to a null preview instead of raising, `UnfurlMessageLinks` no longer
  requeues, and the failure is cached like any other so a dead host is not re-dialled
  on the next message. `LinkPreviewFetchTest` now mirrors `ImageProxyTest`'s
  `ConnectionException` case.
- **The pin is asserted at all three call sites**, through one shared
  `captureTransportOptions()` helper in `tests/Helpers.php` rather than a copy per
  file, and `allow_redirects => false` is asserted alongside it.
  `transportOptions()` also gains direct cases in `OutboundUrlGuardTest` — default
  ports per scheme, an explicit port, and the brackets an IPv6 literal needs before
  curl will read the colons as an address rather than a port.
- **Every hop is re-guarded and re-pinned, and both `fetch()` callers now prove it.**
  A redirect chain whose second hop resolves private is refused on the image path as
  well as the unfurl path.
- **A blocked webhook delivery now records a measured duration rather than a literal
  zero**, since it takes the same `catch` as a transport error. The value is
  sub-millisecond — nothing was dialled — and no caller reads it as a sentinel.
- **The two size-cap readings are now stated rather than implied.** They genuinely
  differ and always did; before, that difference lived in two files that otherwise
  claimed to be the same algorithm.

## Explicitly out of scope

- **Giphy (`GiphyClient`), `UpdateChecker`, and the three OIDC discovery calls in
  `GenericOidcProvider`.** They talk to **operator-configured** hosts rather than
  member-controlled ones, and want JSON semantics plus degrade-to-empty. Routing
  them through a guarded module would need a `guard: off` flag per caller — which is
  how a security module stops being one. This is recorded here so the next review
  does not re-suggest it; `GuardedEgressHomeTest` lists them by name for the same
  reason.
- **The OIDC defects themselves** — no timeout on any of the three calls, and
  `authorization_endpoint` / `token_endpoint` / `userinfo_endpoint` / `jwks_uri`
  taken verbatim from the fetched discovery document with no scheme or issuer-host
  check. Real, and worth their own issue; fixed with Guzzle client config and a
  check, not with this module.
- **Bounding the response in memory (#1202).** The byte cap is enforced after the
  body is buffered, so it bounds what the module *keeps* rather than what it reads —
  a chunked response declares no `Content-Length` to pre-check against. That is
  pre-existing, identical in both of the copies collapsed here, and fixing it means
  streaming the body rather than moving it.
- **A user-agent, a connect timeout, or egress logging.** `GuardedEgress` is the
  first place any of those could live. Deciding whether they should is a separate
  question, and adding them here by reflex would be the same mistake as folding
  Giphy in.
