#!/bin/sh
#
# Compare an operator's .env against a release's .env.prod.example and report
# the active (uncommented) settings the template has but the .env lacks.
#
#   ./docker/env-sync.sh ENV_FILE TEMPLATE_FILE [--apply]
#
# --apply appends the missing keys to ENV_FILE with the template's default
# values, carrying each key's comment block from the template for context.
# Keys already present in ENV_FILE are never touched, even when the template's
# default changed.
#
# Exit codes follow diff semantics: 0 = nothing missing (or --apply appended
# everything), 1 = missing keys were reported, 2 = usage or file errors.
set -eu

usage() {
    echo "Usage: ./docker/env-sync.sh ENV_FILE TEMPLATE_FILE [--apply]"
}

ENV_FILE=""
TEMPLATE_FILE=""
APPLY="false"

for arg in "$@"; do
    case "$arg" in
        --apply)
            APPLY="true"
            ;;
        -*)
            echo "Error: unknown option '$arg'." >&2
            usage >&2
            exit 2
            ;;
        *)
            if [ -z "$ENV_FILE" ]; then
                ENV_FILE="$arg"
            elif [ -z "$TEMPLATE_FILE" ]; then
                TEMPLATE_FILE="$arg"
            else
                echo "Error: unexpected argument '$arg'." >&2
                usage >&2
                exit 2
            fi
            ;;
    esac
done

if [ -z "$ENV_FILE" ] || [ -z "$TEMPLATE_FILE" ]; then
    usage >&2
    exit 2
fi

for file in "$ENV_FILE" "$TEMPLATE_FILE"; do
    if [ ! -f "$file" ]; then
        echo "Error: '$file' not found." >&2
        exit 2
    fi
done

# Active keys only: a line assigning KEY=... at column one. Commented-out
# template keys (`# APP_PORT=8000`) are documentation, not settings.
active_keys() {
    sed -n 's/^\([A-Za-z_][A-Za-z0-9_]*\)=.*/\1/p' "$1"
}

MISSING=""

for key in $(active_keys "$TEMPLATE_FILE"); do
    if ! active_keys "$ENV_FILE" | grep -qx "$key"; then
        MISSING="${MISSING}${key}
"
    fi
done

if [ -z "$MISSING" ]; then
    exit 0
fi

echo "New settings in $TEMPLATE_FILE not present in $ENV_FILE:"
printf '%s' "$MISSING" | sed 's/^/  /'

if [ "$APPLY" != "true" ]; then
    exit 1
fi

# A key's context is the contiguous run of comment lines directly above its
# assignment in the template — stopping at a blank line or at a commented-out
# key (`# APP_PORT=8000`), which documents a different setting, not this one.
template_block() {
    awk -v key="$1" '
        { lines[NR] = $0 }
        !found && $0 ~ "^" key "=" { found = NR }
        END {
            if (!found) { exit 1 }
            start = found
            while (start > 1 && lines[start - 1] ~ /^#/ && lines[start - 1] !~ /^#[[:space:]]*[A-Za-z_][A-Za-z0-9_]*=/) {
                start--
            }
            for (i = start; i <= found; i++) { print lines[i] }
        }
    ' "$TEMPLATE_FILE"
}

{
    printf '\n# Added by docker/env-sync.sh from %s on %s.\n' "$TEMPLATE_FILE" "$(date +%Y-%m-%d)"

    printf '%s' "$MISSING" | while IFS= read -r key; do
        echo
        template_block "$key"
    done
} >>"$ENV_FILE"

COUNT="$(printf '%s' "$MISSING" | wc -l | tr -d '[:space:]')"
echo "Appended $COUNT setting(s) to $ENV_FILE."
