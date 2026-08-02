# ADR-0012: Three test suites — pure, database, HTTP contract

- Status: Accepted
- Date: 2026-07-31
- Relates to: epic architecture-hardening III (#1110), child #1111

## Context

There were two suites and three shapes of test.

`tests/Pest.php` bound `RefreshDatabase` with `->in('Feature')`, and nothing else
distinguished the two directories. So the split on the ground was **"needs a database"
against "doesn't"** — not "through HTTP" against "direct", which is what the names
`Unit` and `Feature` suggest and what everybody read them as. Both consequences of that
were load-bearing:

**`tests/Unit` was not a unit suite.** Only 33 of its 81 files named `App\` at all —
1,761 of 8,875 lines. The other 80% tested YAML, Dockerfiles, shell scripts and
documentation; `ReleaseFlowTest.php` alone was 1,392 lines and never named `App\`. That
is real and valuable work, and it is *repo hygiene*, not unit testing.

**115 of 265 `tests/Feature` files never called `route()`.** They were unit-shaped tests
exiled to `Feature` purely to get a database. `tests/Feature/Support/SidebarChannelsTest.php`
said so in its own docblock: *"The read-model is driven straight against these — no HTTP
round-trip."*

The cost was not misfiling. It was that **a module which is perfectly constructible could
only be reached through a rendered page.** 36 of 89 Actions were never named in any test.
57 `app/Data` DTOs had no unit test, including `MessageData`, which `CONTEXT.md` calls the
canonical read-model DTO. Six named `app/Support` seams had zero test references at all —
`ExpirySweep` (124 lines) and `SecurityEventRecorder` (56) among them. Every one of those
reached 100% line coverage as a side effect of somebody else's HTTP test, which is
coverage without specification: the gate was green and no test said what those modules do.

`CONTEXT.md` states that **the interface is the test surface**. For 36 Actions and 57 DTOs
it was not, and the reason was that there was nowhere to put the test.

The same gap had already been closed once, in the other direction. `tests/Pest.php` has
`require_once`'d `tests/Browser/Helpers.php` since #53, and `browserTeamWithChannel()` is
used by 68 of the browser suite's 74 files with **zero** local clones. The headless
channel/team files never got the same treatment: **37 byte-identical `*TeamWithGeneral()`
bodies** (`threadTeamWithGeneral`, `pinTeamWithGeneral`, `draftTeamWithGeneral`, …) across
about 481 lines, another ~20 "add a user to the team and the channel" clones across ~220,
and 116 hand-rolled `channelMembers()->firstOrCreate()` calls against a
`ChannelMemberFactory` whose four purpose-built states had two usages in the whole
repository.

## Decision

**Three suites, named for how much of the application each one boots**, declared in
`phpunit.xml` in that order and bound in `tests/Pest.php`:

| suite | boots | drives | example |
| --- | --- | --- | --- |
| `tests/Unit` | nothing, usually | a pure function, or a repository file | `ReleaseFlowTest`, `TeamRoleTest` |
| `tests/Integration` | the application **and** the database | the module, constructed and called | `ExpirySweepTest`, `ScheduleMessageTest` |
| `tests/Feature` | the same, plus HTTP | `route()`, and what the response carries | `ThreadTest`, `ChannelStarTest` |

`Integration` and `Feature` are given exactly the same thing — `TestCase` plus
`RefreshDatabase`. **What separates them is not what they are given but what they drive.**
A test belongs in `Feature` when the HTTP contract is the subject: routing, validation,
authorization, redirects, the Inertia props a page ships. Everything else that needs a
database belongs in `Integration`.

`tests/Browser` stays undeclared as a `<testsuite>`, which is what keeps it out of the
coverage gate; it runs by path through `bin/browser-tests`.

**One shared arrange, `tests/Helpers.php`**, `require_once`'d from `tests/Pest.php`
alongside the three helper files that already were. It carries the team/channel/member
arrange the local clones re-declare — `teamWithChannel()`, `teamMemberInChannel()`,
`channelMembership()` — and takes membership state as a closure over
`ChannelMemberFactory`, so the four states have one spelling and the helper itself names
none of the pivot's columns.

**No existing test file moved.** The 115 route-less `Feature` files stay where they are.

## Consequences

- A DB-backed module has somewhere to be specified directly. `ExpirySweep`'s
  compare-and-swap — the guard that spares a status a user re-set while the sweep was
  running — is now stated by a test that opens exactly that window, which no HTTP test
  was ever going to express.
- **Coverage stops standing in for specification.** A module at 100% because a controller
  test walked over it reads identically to one that is actually specified; the gate cannot
  tell them apart and never could. A third home does not fix that by itself, but it
  removes the excuse.
- The five remaining children of #1110 have somewhere to prove themselves, which is why
  this one merged first.
- **The cost, stated plainly: three homes is one more judgement call than two.** The line
  between `Integration` and `Feature` is a *judgement about the subject of the test*, not
  a mechanical property a linter can check, and a test that reads a page's Inertia props
  to assert a read-model's output sits right on it. The table above and the entry in
  `CONTRIBUTING.md` are the whole of the guidance; when it is genuinely ambiguous, ask
  which failure the test should catch — a broken route, or a broken module.
- **Nothing was moved, so for now the new suite is the smallest of the three and
  `tests/Feature` still holds ~115 tests that belong in it.** That is deliberate. A bulk
  move changes no behaviour, wrecks `git blame` on a third of the test suite, and does not
  address the actual complaint, which was that new modules had nowhere to go rather than
  that old files were misfiled. #1110's child 6b relocates a file only when it is already
  rewriting that file's assertions.
- **`tests/Unit` keeps its 48 repo-hygiene files.** Renaming the suite to match what those
  files really are would rename the one part of the suite causing nobody any friction, and
  would touch every pointer in the workflows and rules that names a file inside it.
