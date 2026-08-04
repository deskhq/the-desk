"""
Decompose a channel->channel Inertia visit into network / boot / page-props /
share()-closure time, against a *production-shaped* target.

Run: python3 server-decomposition.py <base-url> <team-slug> <channel-slug>
Requires a `c.txt` cookie jar for a logged-in session (see login.sh).

Every level is requested once per round and the rounds are interleaved, so
server drift and network jitter hit all five levels equally. Only the deltas
within a run are meaningful; absolute numbers move by ~2x between runs.
"""
import subprocess, statistics, sys, re, urllib.request

BASE = sys.argv[1] if len(sys.argv) > 1 else 'https://demo.thedeskhq.app'
TEAM = sys.argv[2] if len(sys.argv) > 2 else 'northwind-labs'
CHAN = sys.argv[3] if len(sys.argv) > 3 else 'marketing'
ROUNDS = 20
CH = f'{BASE}/t/{TEAM}/c/{CHAN}'

# The Inertia asset version, read off any rendered page.
VER = re.search(r'"version":"([0-9a-f]+)"',
                subprocess.run(['curl', '-s', '-b', 'c.txt', f'{BASE}/t/{TEAM}/c/{CHAN}'],
                               capture_output=True, text=True).stdout).group(1)

IN = ['-H', 'X-Inertia: true', '-H', f'X-Inertia-Version: {VER}',
      '-H', 'Accept: text/html, application/xhtml+xml']
PAGE = ('messages,channel,members,channelReaders,threadReplies,lastReadMessageId,'
        'pins,pinCount,memberCount,isMember,scheduledMessages,thread,notificationLevels,team')

CASES = [
    ('1. static asset (no PHP)', [f'{BASE}/build/manifest.json']),
    ('2. settings/profile (no workspace shell)', [f'{BASE}/settings/profile'] + IN),
    ('3. channel, only=channel (boot + eager shared values)',
     [CH] + IN + ['-H', 'X-Inertia-Partial-Component: channels/Show', '-H', 'X-Inertia-Partial-Data: channel']),
    ('4. channel, only=page props (share() closures skipped)',
     [CH] + IN + ['-H', 'X-Inertia-Partial-Component: channels/Show', '-H', f'X-Inertia-Partial-Data: {PAGE}']),
    ('5. channel, full visit (what a <Link> click costs)', [CH] + IN),
]

S = {c[0]: [] for c in CASES}
W = {}
for _ in range(ROUNDS):
    for label, args in CASES:
        out = subprocess.run(
            ['curl', '-s', '-b', 'c.txt', '--compressed', '-w', '%{time_starttransfer} %{size_download}\n']
            + args + ['-o', '/dev/null'], capture_output=True, text=True).stdout.strip().split()
        S[label].append(float(out[0]))
        W[label] = int(out[1])

med = {}
print(f'{"":56} {"med":>7} {"p25":>6} {"p75":>6} {"wire":>10}')
for label, _ in CASES:
    s = sorted(S[label])
    med[label] = statistics.median(s)
    print(f'{label:<56} {1000*med[label]:7.0f} {1000*s[len(s)//4]:6.0f} {1000*s[3*len(s)//4]:6.0f} {W[label]:>9,}B')

k = [c[0] for c in CASES]
print('\ndecomposition of a full channel -> channel visit:')
print(f'  network + edge (floor)          {1000*med[k[0]]:6.0f} ms')
print(f'  PHP boot + auth + eager shared  {1000*(med[k[2]]-med[k[0]]):6.0f} ms')
print(f'  page props (messages etc.)      {1000*(med[k[3]]-med[k[2]]):6.0f} ms')
print(f'  share() closures (the shell)    {1000*(med[k[4]]-med[k[3]]):6.0f} ms')
print(f'  TOTAL                           {1000*med[k[4]]:6.0f} ms')
