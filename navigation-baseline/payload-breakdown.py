"""
Break a channel->channel Inertia visit down by prop: shell vs page, and which
props are byte-identical between two consecutive channel visits.

Run: python3 payload-breakdown.py <base-url> <team-slug> <channel-a> <channel-b>
Requires a `c.txt` cookie jar for a logged-in session (see login.sh) and a
`shell_keys.txt` listing the props `HandleInertiaRequests::share()` returns:

  sed -n '70,300p' app/Http/Middleware/HandleInertiaRequests.php \
    | grep -oE "^ {12}'[a-zA-Z]+'" | tr -d " '" > shell_keys.txt
"""
import subprocess, json, sys, re

BASE = sys.argv[1] if len(sys.argv) > 1 else 'https://demo.thedeskhq.app'
TEAM = sys.argv[2] if len(sys.argv) > 2 else 'northwind-labs'
A = sys.argv[3] if len(sys.argv) > 3 else 'engineering'
B = sys.argv[4] if len(sys.argv) > 4 else 'marketing'

VER = re.search(r'"version":"([0-9a-f]+)"',
                subprocess.run(['curl', '-s', '-b', 'c.txt', f'{BASE}/t/{TEAM}/c/{A}'],
                               capture_output=True, text=True).stdout).group(1)

def visit(slug):
    out = subprocess.run(['curl', '-s', '-b', 'c.txt', '--compressed', f'{BASE}/t/{TEAM}/c/{slug}',
                          '-H', 'X-Inertia: true', '-H', f'X-Inertia-Version: {VER}',
                          '-H', 'Accept: text/html, application/xhtml+xml'],
                         capture_output=True, text=True).stdout
    return json.loads(out)['props']

shell = set(open('shell_keys.txt').read().split())
a, b = visit(A), visit(B)
sz = lambda v: len(json.dumps(v, separators=(',', ':')).encode())
canon = lambda v: json.dumps(v, sort_keys=True)

tot = sum(sz(v) for v in b.values())
sh = sum(sz(v) for k, v in b.items() if k in shell)
print(f'{A} -> {B}: {tot:,} B of uncompressed props across {len(b)} props')
print(f'  shell props (share()) {sh:>8,} B  {100*sh/tot:5.1f}%  ({len([k for k in b if k in shell])} props)')
print(f'  page props            {tot-sh:>8,} B  {100*(tot-sh)/tot:5.1f}%  ({len([k for k in b if k not in shell])} props)')

ident = [(sz(v), k) for k, v in b.items() if k in a and canon(a[k]) == canon(v)]
print(f'\nbyte-identical between the two visits: {len(ident)} props, {sum(n for n,_ in ident):,} B '
      f'({100*sum(n for n,_ in ident)/tot:.1f}% of the payload)')
for n, k in sorted(ident, reverse=True)[:15]:
    print(f'  {n:>8,} B  {k}{"  [SHELL]" if k in shell else ""}')

print('\nchanged:')
for k, v in sorted(b.items(), key=lambda kv: -sz(kv[1])):
    if k not in a or canon(a[k]) != canon(v):
        print(f'  {sz(v):>8,} B  {k}{"  [SHELL]" if k in shell else ""}')
