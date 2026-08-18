# ADR-0016: The link unfurler is a separate service, and the egress guard gains a second home

- Status: Accepted
- Date: 2026-08-17
- Relates to: [ADR-0015](0015-one-guarded-egress-module.md) (which this amends), [ADR-0010](0010-channel-traffic-predicate-has-one-home-per-language.md) (whose device this reuses), [#1202](https://github.com/deskhq/the-desk/issues/1202)

## Context

Unfurling a link is the one thing this application does entirely on a member's
say-so: someone types a URL into a message and the server fetches it, follows its
redirects, and parses whatever bytes come back. Everything about that sentence is
a liability, and `GuardedEgress` plus `OutboundUrlGuard` are ~380 lines of
carefully-argued PHP spent on containing it.

Two of those liabilities the PHP cannot contain, and both are structural rather
than sloppy:

**The byte cap is applied after the body is in memory.** `GuardedEgress::read()`
pre-checks `Content-Length` and then calls `$response->body()`, but Guzzle has
already buffered the whole response by then. A chunked reply declares no length
to pre-check against, so the cap bounds what the module *keeps*, not what it
*reads*. That is [#1202](https://github.com/deskhq/the-desk/issues/1202), open
since ADR-0015 deliberately deferred it as a behaviour change rather than a
refactor. It is reachable from a member-typed URL on a queue worker and from a
scraped `og:image` on a **web** worker, so it is a memory-pressure lever, not a
theoretical one.

**The connection pin is a request, not a guarantee.** `resolveDeliveryIp()`
resolves the hostname, `transportOptions()` hands curl a `CURLOPT_RESOLVE` entry,
and correctness then rests on two things: curl honouring the pin, and
`GuardedEgress::fetch()` remembering to redo the whole triple on every hop. The
second is why the hop walk exists and why ADR-0015 was written at all -- three
call sites had spelled it by hand and two had drifted. The window between the
check and the `connect(2)` is narrowed by the pin. It is not closed by it.

A Go process closes both, and not by being written more carefully:
`io.LimitReader` makes the cap the read rather than a check after the read, and
`net.Dialer.Control` runs after resolution and immediately before `connect(2)`,
on **every** connection the transport opens, including every redirect hop. There
is no window and there is nothing to remember. The rest of what a Go service buys
here -- three URLs fetched concurrently instead of serially, a tokenizer that
stops at `</head>` -- is real but would not on its own justify a second language.

The cost is not small and is not hidden: the guard now exists twice.

## Decision

**`services/unfurler` is a separate Go service, and it owns the whole unfurl:
guarding, fetching, redirect walking and parsing.** PHP keeps everything that is
not those four things -- extracting URLs from a body, reconciling
`message_link_previews` rows, caching, writing `Ready`/`Failed`, broadcasting
`MessageUpdated`, and rewriting `og:image` through the image proxy.

**The egress guard has one home per language, and the specification is a shared
case table.** This is [ADR-0010](0010-channel-traffic-predicate-has-one-home-per-language.md)'s
device applied a third time, to the highest-stakes rule it has been applied to
yet. `tests/Fixtures/egress-verdict-cases.json` holds the table; neither
`tests/Feature/Support/OutboundUrlGuardTest.php` nor
`services/unfurler/internal/guard/verdicts_test.go` owns it, so a case added on
one side of the wire has to satisfy the other. `tests/Unit/SsrfVerdictParityTest.php`
is the tripwire: it fails if either consumer stops reading the file, if the table
loses its allow cases (a guard that refuses everything must not pass), or if it
shrinks below a floor -- the fail-closed rule `ProductionCompose` already
establishes, because a specification that resolves to nothing passes every test
vacuously.

**The two guards are not identical, and the table says so rather than pretending
otherwise.** Each case carries a `scope`: `both` means the verdict must match in
PHP and Go; `go` means Go is stricter. Go refuses CGNAT (`100.64.0.0/10`), the
TEST-NETs, `240.0.0.0/4`, NAT64 and the documentation ranges, which PHP's
`FILTER_FLAG_NO_RES_RANGE` does not consistently cover. Recording that as data is
what stops it being discovered later as a surprise, and every `go` row is a
standing candidate for the PHP guard -- which still fronts webhook delivery and
the image proxy -- to catch up on.

**ADR-0015's claim is amended, not falsified.** `GuardedEgress` remains the only
place *this application* opens a connection to a member-controlled URL, because
after this change PHP no longer opens one for an unfurl at all.
`App\Support\Unfurl\HttpUnfurler` joins `GiphyClient` and `UpdateChecker` on
`GuardedEgressHomeTest`'s allowlist on the criterion those two already
established: **it talks to an operator-configured host, not a member-controlled
one.** `config('unfurl.url')` is exactly that. Routing it through
`GuardedEgress::send()` instead would be worse than useless -- the guard would
refuse a private container address, and the only way through is the per-caller
`guard: off` flag ADR-0015 names as how a security module stops being one.

**The service holds nothing.** It ships inside the application image (one
artifact, one tag, one Trivy scan, one attestation, one `APP_VERSION` for an
operator to pin) but it does **not** run as an application container: no
`env_file`, no `.env` bind, no `storage-app`, no branding mount, `read_only`,
`cap_drop: [ALL]`, `no-new-privileges`, and no published host port. Moving an
HTML parser fed hostile bytes out of the application process is only a
containment win because of that list, which is why
`tests/Unit/ProductionComposeStorageVolumeTest.php` gains an assertion naming it:
a later "simplification" to the `x-app` anchor would silently undo the entire
point, and nothing else in the suite would notice.

**A failure to reach the unfurler is not a failure to unfurl.** The service
answering "no preview for this URL" is cached for 24 hours exactly as today,
because a dead link is dead. The service being unreachable is not cached at all.
Without that split a five-minute outage would poison the negative cache for every
link posted during it, recoverable only by `cache:clear`, which is a far worse
failure than the one it is reporting.

## Consequences

- **#1202 closes on the unfurl path.** It stays open for the image proxy, which
  is still a PHP `fetch()` caller and still buffers before it caps. That is now
  the only remaining instance and is worth saying out loud rather than letting
  the issue read as fixed.
- **`FetchPolicy` collapses to one reading.** `truncatingAt()` existed for the
  unfurl and `refusingOver()` for images; with the unfurl gone, the
  `truncatesOversizeBody` flag and the truncation branch in
  `GuardedEgress::read()` lose their only caller. ADR-0015's "the two size-cap
  readings are now stated rather than implied" is historical from here.
  `symfony/dom-crawler` loses its only import in the tree and leaves
  `composer.json` with it.
- **Changing the guard is two edits, and missing one turns the other language
  red.** It was one edit. That is the bill, stated plainly. The table makes drift
  *visible*; nothing makes it impossible, and a rule spelled twice will eventually
  be wrong in one place.
- **A second gate at a weaker floor.** PHP is gated at 100% because Laravel gives
  you a seam for everything. Go is gated at 90% on `./internal/...`, because the
  last ten percent is `io` and `net` error branches reachable only through
  contorted interfaces. `tests/Unit/GoQualityGateTest.php` keeps that floor
  visible from inside the PHP gate, which is the only one anyone runs by reflex.
- **`queue-broadcasts` stays.** The architecture doc justifies it with "a link
  unfurl spends up to five seconds on outbound HTTP", and that number is now
  wrong. The service is not: webhook delivery and audit exports still block the
  shared `default` queue, and the head-of-line problem is theirs as much as it
  was ever the unfurl's.
- **An operator who never adds the container loses link previews and nothing
  else.** Rows resolve to `Failed`, no card renders, and every message still
  posts, edits, broadcasts, searches and renders. `docker/upgrade.sh` warns when
  the stack is missing the service, because nothing in this repository previously
  noticed a new *compose service* the way `env-sync.sh` notices a new `.env` key
  -- which is exactly how `queue-broadcasts` went missing for five months
  ([#1040](https://github.com/deskhq/the-desk/issues/1040)).

## Explicitly out of scope

- **The image proxy (`FetchRemoteImage`) and webhook delivery (`DeliverWebhook`).**
  Both stay in PHP behind `GuardedEgress`. The image proxy is a synchronous
  web-worker fetch whose oversize reading is "refuse" rather than "truncate", and
  webhook delivery composes a signed body and owns its own retry ledger and
  auto-disable threshold. Moving them is the other coherent end state -- PHP with
  no egress guard at all -- and it is a much larger change than this one.
- **Bringing the PHP guard up to the Go address table.** Every `scope: "go"` row
  is a real gap on the webhook and image-proxy paths. Fixing them here would mean
  shipping a behaviour change to two unrelated surfaces inside a refactor, which
  is the mistake ADR-0015 was careful not to make. They get their own issue.
- **Caching in Go.** `Cache::remember` is already Redis-backed and shared across
  workers; a hit costs zero network calls, and moving it would give the one
  process that should hold nothing a credential and a state store. The single
  real argument for it -- `singleflight` deduping two workers racing the same URL
  -- is worth one wasted fetch, not a Redis dependency in the security-critical
  service.
