#!/bin/sh
#
# Bootstrap a production .env for the Docker self-hosting stack.
#
#   ./docker/gen-secrets.sh
#
# Creates .env from .env.prod.example (if missing) and fills any EMPTY required
# secret with a freshly generated value. Existing values are never overwritten,
# so the script is safe to re-run. After it finishes, fill in the non-secret
# settings (APP_URL, mail, the browser-side REVERB_*_PUBLIC values) before
# starting the stack.
set -eu

EXAMPLE_FILE=".env.prod.example"
ENV_FILE=".env"

if ! command -v openssl >/dev/null 2>&1; then
    echo "Error: openssl is required to generate secrets." >&2
    exit 1
fi

if [ ! -f "$EXAMPLE_FILE" ]; then
    echo "Error: $EXAMPLE_FILE not found. Run this from the project root." >&2
    exit 1
fi

if [ ! -f "$ENV_FILE" ]; then
    cp "$EXAMPLE_FILE" "$ENV_FILE"
    echo "Created $ENV_FILE from $EXAMPLE_FILE."
fi

# Set KEY=VALUE only when KEY is currently empty (matches "KEY=" at end of line).
set_if_empty() {
    key="$1"
    value="$2"
    if grep -Eq "^${key}=$" "$ENV_FILE"; then
        # Use a non-/ delimiter since values may contain / or +.
        tmp="$(mktemp)"
        sed "s#^${key}=\$#${key}=${value}#" "$ENV_FILE" >"$tmp"
        mv "$tmp" "$ENV_FILE"
        echo "  set ${key}"
    else
        echo "  kept ${key} (already set)"
    fi
}

# Encode stdin as base64url, the form the Web Push spec (and every browser) reads
# VAPID keys in: standard base64 with the two URL-unsafe characters swapped and
# the padding dropped.
b64url() {
    openssl base64 -A | tr '+/' '-_' | tr -d '='
}

# Mint a VAPID keypair, leaving it in $vapid_public_key / $vapid_private_key.
#
# It is a P-256 keypair: the public key is the 65-byte uncompressed point
# (0x04 || X || Y) at the tail of the SubjectPublicKeyInfo DER, the private key
# the raw 32-byte scalar the SEC1 ECPrivateKey DER carries after its fixed
# 7-byte header (30 77 02 01 01 04 20). Both offsets are fixed for prime256v1
# and are asserted against the web push library in
# tests/Unit/GenSecretsScriptTest.php, since a wrong one still yields a
# plausible-looking key that signs nothing.
#
# Returns non-zero without setting either half if this openssl cannot do the
# curve, so a host that only half-works never writes a mismatched pair.
generate_vapid_keys() {
    vapid_public_key=""
    vapid_private_key=""

    tmp_key="$(mktemp)"
    if ! openssl ecparam -name prime256v1 -genkey -noout -out "$tmp_key" 2>/dev/null; then
        rm -f "$tmp_key"
        return 1
    fi

    vapid_public_key="$(openssl ec -in "$tmp_key" -pubout -outform DER 2>/dev/null | tail -c 65 | b64url)"
    vapid_private_key="$(openssl ec -in "$tmp_key" -outform DER 2>/dev/null | dd bs=1 skip=7 count=32 2>/dev/null | b64url)"
    rm -f "$tmp_key"

    # 65 and 32 raw bytes are 87 and 43 base64url characters; any other length
    # means the DER was not shaped as assumed, and the pair must be discarded.
    [ "${#vapid_public_key}" -eq 87 ] && [ "${#vapid_private_key}" -eq 43 ]
}

echo "Generating missing secrets in $ENV_FILE:"
set_if_empty "APP_KEY"         "base64:$(openssl rand -base64 32)"
set_if_empty "DB_PASSWORD"     "$(openssl rand -hex 24)"
set_if_empty "MEILISEARCH_KEY" "$(openssl rand -hex 32)"
# The unfurl service checks this on every request. It is deliberately its own
# secret rather than something derived from APP_KEY: a derivation would have to
# be computable inside the unfurler container, which means handing it the key
# that signs every session — and that container exists precisely to hold nothing.
set_if_empty "UNFURLER_TOKEN" "$(openssl rand -hex 32)"
set_if_empty "REVERB_APP_ID"   "$(openssl rand 4 | od -An -tu4 | tr -d ' ')"

# The browser and server share the one REVERB_APP_KEY (the frontend receives it
# at runtime), so it is generated once here.
set_if_empty "REVERB_APP_KEY"    "$(openssl rand -hex 16)"
set_if_empty "REVERB_APP_SECRET" "$(openssl rand -hex 16)"

# Web push. The pair is generated only when both halves are empty: filling one
# half of an operator's existing pair would leave a public key no private key
# signs for, which browsers accept at subscribe time and push services reject at
# send time. VAPID_SUBJECT is an address rather than a secret, so it is left to
# the operator; config/webpush.php falls back to APP_URL.
if grep -Eq "^VAPID_PUBLIC_KEY=$" "$ENV_FILE" && grep -Eq "^VAPID_PRIVATE_KEY=$" "$ENV_FILE"; then
    if generate_vapid_keys; then
        set_if_empty "VAPID_PUBLIC_KEY"  "$vapid_public_key"
        set_if_empty "VAPID_PRIVATE_KEY" "$vapid_private_key"
    else
        echo "Warning: this openssl cannot generate a prime256v1 key, so no VAPID pair was written." >&2
        echo "Web push stays off; generate a pair later with: php artisan webpush:vapid --show" >&2
    fi
elif grep -Eq "^VAPID_(PUBLIC|PRIVATE)_KEY=$" "$ENV_FILE"; then
    echo "Warning: only one half of the VAPID pair is set, and the halves must come from" >&2
    echo "the same key. Replace both with a fresh pair: php artisan webpush:vapid --show" >&2
else
    echo "  kept VAPID_PUBLIC_KEY / VAPID_PRIVATE_KEY (already set)"
fi

echo
echo "Done. Review $ENV_FILE and set APP_URL, mail credentials, and the"
echo "browser-side REVERB_*_PUBLIC values before running:"
echo "  docker compose up -d"
