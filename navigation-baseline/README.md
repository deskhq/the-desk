# Navigation baseline harness

Throwaway branch for [#1233](https://github.com/deskhq/the-desk/issues/1233), a ticket on the
[navigation feels instant](https://github.com/deskhq/the-desk/issues/1232) map. **Never merge this** —
it exists so the "after" number in the epic can be taken with the same instrument as the "before",
against the same target.

The captured baseline, with its conditions, is the resolution comment on #1233. This directory is
only the instrument.

## Running it

Everything measures a **deployed** instance over the real network. Sail dev numbers are meaningless
here (no opcache, `refresh: true`, an unbundled dev server, Docker Desktop's macOS filesystem).

```sh
cd navigation-baseline
./login.sh https://demo.thedeskhq.app          # mints c.txt, the logged-in cookie jar

# the props HandleInertiaRequests::share() returns, which the breakdown classifies against
sed -n '70,300p' ../app/Http/Middleware/HandleInertiaRequests.php \
  | grep -oE "^ {12}'[a-zA-Z]+'" | tr -d " '" > shell_keys.txt

python3 payload-breakdown.py    https://demo.thedeskhq.app northwind-labs engineering marketing
python3 server-decomposition.py https://demo.thedeskhq.app northwind-labs marketing
node cold.mjs      # cold load: document, bundle, fonts, paint timings, CPU
node warm.mjs      # warm client-side channel -> channel click, felt latency
```

`cold.mjs` and `warm.mjs` import Playwright from the repo's `node_modules` and launch **headless**
Chromium. Keep it that way.

## Reading the numbers honestly

- **Only deltas inside a single run are trustworthy.** The network-and-edge floor moved between 76 ms
  and 142 ms across two runs an hour apart, so every absolute figure carries that spread.
  `server-decomposition.py` interleaves its five levels round-robin for exactly this reason.
- **Per-prop *time* attribution inside `share()` is below the noise floor** when measured off-box.
  Individual prop deltas came out at 14–34 ms with a ±40 ms interquartile range, and the sum of the
  individual deltas was four times the measured aggregate. The per-prop *byte* ranking is exact; the
  per-prop *time* ranking is not, and needs an in-container profiler.
- **The public demo is a floor, not a typical workspace.** `share()`'s cost scales with channel and
  member count, and the demo has 14 channels and 7 members. See #1233 for the extrapolation.
- **Playwright double-reports every request** while a service worker controls the page, and the app
  registers a no-op-`fetch` worker for installability. Count round trips off
  `performance.getEntriesByType('resource')`, which `warm.mjs` does, not off `page.on('request')`.
