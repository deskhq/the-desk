#!/bin/bash
# Mint a logged-in cookie jar (c.txt) against the public demo, which signs every
# visitor into the same shared account. Against a non-demo instance, log in by
# hand and export the session cookie into c.txt instead.
BASE="${1:-https://demo.thedeskhq.app}"
rm -f c.txt
curl -s -c c.txt -b c.txt "$BASE/login" -o /dev/null
XSRF=$(python3 -c "
import urllib.parse
for line in open('c.txt'):
    p = line.split('\t')
    if len(p) > 6 and p[5] == 'XSRF-TOKEN':
        print(urllib.parse.unquote(p[6].strip()))
")
curl -s -c c.txt -b c.txt -X POST "$BASE/demo/login" \
  -H "X-XSRF-TOKEN: $XSRF" -H "Referer: $BASE/login" -o /dev/null -w "login %{http_code}\n"
