#!/bin/sh
#
# Back up the production stack's durable state to the host.
#
#   ./docker/backup.sh [OUTPUT_DIR] [--keep=N]
#
# Writes two files into OUTPUT_DIR (default: the current directory):
#
#   db-backup-YYYY-MM-DD.sql.gz     gzipped logical dump of the database
#   storage-app-YYYY-MM-DD.tar.gz   archive of the uploaded files
#
# Those two hold everything durable. The Meilisearch index is derived data
# (rebuilt from Postgres on boot by `php artisan search:sync`) and Redis holds
# only cache, sessions, and transient jobs, so neither is backed up.
#
# --keep=N prunes all but the N most recent backup pairs in OUTPUT_DIR. Without
# it nothing is pruned. For scheduled backups, drive this from host cron:
#
#   0 3 * * * cd /srv/the-desk && ./docker/backup.sh /srv/backups --keep=7
#
# pg_dump runs inside the `pgsql` container so its version always matches the
# server; the app image ships no postgres-client and pg_dump refuses to run
# against a mismatched server.
#
# No `-f docker-compose.prod.yml` here: .env sets COMPOSE_FILE, so a bare
# `docker compose` resolves the right files for both the published-image and
# build-from-source setups.
set -eu

OUTPUT_DIR="."
KEEP=""

usage() {
    echo "Usage: ./docker/backup.sh [OUTPUT_DIR] [--keep=N]"
}

for arg in "$@"; do
    case "$arg" in
        --keep=*)
            KEEP="${arg#--keep=}"
            ;;
        -h | --help)
            usage
            exit 0
            ;;
        -*)
            echo "Error: unknown option '$arg'." >&2
            usage >&2
            exit 1
            ;;
        *)
            OUTPUT_DIR="$arg"
            ;;
    esac
done

if [ -n "$KEEP" ] && ! echo "$KEEP" | grep -Eq '^[1-9][0-9]*$'; then
    echo "Error: --keep must be a positive integer, got '$KEEP'." >&2
    exit 1
fi

if [ ! -f "docker-compose.prod.yml" ]; then
    echo "Error: run this from the project root (docker-compose.prod.yml not found)." >&2
    exit 1
fi

if [ ! -d "$OUTPUT_DIR" ]; then
    echo "Error: output directory '$OUTPUT_DIR' does not exist." >&2
    exit 1
fi

if [ ! -w "$OUTPUT_DIR" ]; then
    echo "Error: output directory '$OUTPUT_DIR' is not writable." >&2
    exit 1
fi

# Read a key from .env, falling back to the same default the compose file uses.
# An exported environment variable wins, matching the documented one-liners.
env_value() {
    key="$1"
    default="$2"
    value=""

    if [ -f ".env" ]; then
        value="$(sed -n "s/^${key}=//p" .env | tail -n 1 | sed -e 's/^"\(.*\)"$/\1/' -e "s/^'\(.*\)'\$/\1/")"
    fi

    if [ -z "$value" ]; then
        value="$default"
    fi

    echo "$value"
}

DB_USERNAME="${DB_USERNAME:-$(env_value DB_USERNAME laravel)}"
DB_DATABASE="${DB_DATABASE:-$(env_value DB_DATABASE laravel)}"

# Every docker call here reads from /dev/null: `docker compose exec -T` attaches
# the container's stdin and drains it, which would otherwise silently swallow
# whatever the caller had on stdin.
require_running() {
    service="$1"
    if [ -z "$(docker compose ps --quiet --status running "$service" 2>/dev/null </dev/null)" ]; then
        echo "Error: the '$service' service is not running; start the stack first:" >&2
        echo "  docker compose up -d" >&2
        exit 1
    fi
}

require_running pgsql
require_running app

STAMP="$(date +%F)"
DUMP_FILE="$OUTPUT_DIR/db-backup-$STAMP.sql.gz"
STORAGE_FILE="$OUTPUT_DIR/storage-app-$STAMP.tar.gz"

# Stage into .partial files next to the final ones (same filesystem, so the
# rename is atomic) and publish BOTH only once BOTH producers have reported
# success. A failed run therefore never leaves a truncated file that looks like a
# good backup, and never leaves a mismatched pair: publishing the dump as soon as
# it was written would, on a failed same-day retry, pair today's database with
# last week's uploads while looking complete. The trap clears the staging files
# on any exit path.
DUMP_PARTIAL="$OUTPUT_DIR/.db-backup-$STAMP.sql.gz.partial"
STORAGE_PARTIAL="$OUTPUT_DIR/.storage-app-$STAMP.tar.gz.partial"
STATUS_FILE="$(mktemp)"

cleanup() {
    rm -f "$DUMP_PARTIAL" "$STORAGE_PARTIAL" "$STATUS_FILE"
}
trap cleanup EXIT

# `cmd | gzip > file` reports gzip's exit status, not cmd's, and POSIX sh has no
# pipefail. Record the producer's status in a file so a failed pg_dump/tar is
# caught instead of silently yielding a well-formed archive of an error message.
piped_status() {
    if [ ! -s "$STATUS_FILE" ]; then
        echo "1"
        return
    fi

    cat "$STATUS_FILE"
}

# ---- Disk space -------------------------------------------------------------
# Check before writing anything: a backup that fills the host disk is worse than
# no backup, since it takes the running instance down with it. Both figures are
# uncompressed sizes and both files are compressed on the way out, so this is a
# deliberate over-estimate rather than a tight fit.
DB_BYTES="$(docker compose exec -T pgsql psql -U "$DB_USERNAME" -d "$DB_DATABASE" -tAc "SELECT pg_database_size('$DB_DATABASE')" </dev/null | tr -d '[:space:]')"
STORAGE_KB="$(docker compose exec -T app du -sk /app/storage/app </dev/null | awk '{print $1}' | tr -d '[:space:]')"

if ! echo "$DB_BYTES" | grep -Eq '^[0-9]+$' || ! echo "$STORAGE_KB" | grep -Eq '^[0-9]+$'; then
    echo "Error: could not measure the database and storage sizes." >&2
    exit 1
fi

REQUIRED_KB="$(((DB_BYTES / 1024) + STORAGE_KB))"
AVAILABLE_KB="$(df -Pk "$OUTPUT_DIR" | awk 'NR == 2 {print $4}' | tr -d '[:space:]')"

if ! echo "$AVAILABLE_KB" | grep -Eq '^[0-9]+$'; then
    echo "Error: could not determine free space in '$OUTPUT_DIR'." >&2
    exit 1
fi

if [ "$AVAILABLE_KB" -lt "$REQUIRED_KB" ]; then
    echo "Error: not enough free space in '$OUTPUT_DIR'." >&2
    echo "  need about $((REQUIRED_KB / 1024)) MB (uncompressed), $((AVAILABLE_KB / 1024)) MB available." >&2
    echo "  Free up space or pass a different output directory." >&2
    exit 1
fi

echo "Backing up to $OUTPUT_DIR:"

# ---- Database ---------------------------------------------------------------
# A `( )` subshell, not a `{ }` group: `set +e` must not leak into the rest of
# the script. Without it `set -e` would abort before the status was recorded.
(
    set +e
    docker compose exec -T pgsql pg_dump -U "$DB_USERNAME" "$DB_DATABASE" </dev/null
    echo "$?" >"$STATUS_FILE"
) | gzip -c >"$DUMP_PARTIAL"

if [ "$(piped_status)" != "0" ]; then
    echo "Error: pg_dump failed; no backup written." >&2
    exit 1
fi

# ---- Uploaded files ---------------------------------------------------------
# tar compresses on its own (czf), so this needs no gzip stage; the status file
# guards a mid-stream tar failure the same way.
(
    set +e
    docker compose exec -T app tar czf - -C /app/storage/app . </dev/null
    echo "$?" >"$STATUS_FILE"
) >"$STORAGE_PARTIAL"

if [ "$(piped_status)" != "0" ]; then
    echo "Error: archiving storage/app failed; no backup written." >&2
    exit 1
fi

# ---- Publish ----------------------------------------------------------------
# Both producers succeeded, so the pair can be published together.
mv "$DUMP_PARTIAL" "$DUMP_FILE"
echo "  wrote $DUMP_FILE"
mv "$STORAGE_PARTIAL" "$STORAGE_FILE"
echo "  wrote $STORAGE_FILE"

# ---- Retention --------------------------------------------------------------
# Only complete pairs are counted and pruned: a lone dump or archive left by an
# older/interrupted run must not consume a --keep slot, or it would evict a good
# pair while contributing nothing restorable itself.
if [ -n "$KEEP" ]; then
    stamps="$(
        find "$OUTPUT_DIR" -maxdepth 1 -type f -name 'db-backup-*.sql.gz' |
            sed -e 's|.*/db-backup-||' -e 's|\.sql\.gz$||' |
            while IFS= read -r stamp; do
                if [ -f "$OUTPUT_DIR/storage-app-$stamp.tar.gz" ]; then
                    echo "$stamp"
                fi
            done | sort
    )"

    if [ -n "$stamps" ]; then
        total="$(printf '%s\n' "$stamps" | wc -l | tr -d '[:space:]')"

        if [ "$total" -gt "$KEEP" ]; then
            printf '%s\n' "$stamps" | head -n "$((total - KEEP))" | while IFS= read -r stamp; do
                rm -f "$OUTPUT_DIR/db-backup-$stamp.sql.gz" "$OUTPUT_DIR/storage-app-$stamp.tar.gz"
                echo "  pruned $stamp"
            done
        fi
    fi
fi

echo
echo "Done. Store both files off the host. To restore them:"
echo "  ./docker/restore.sh $DUMP_FILE $STORAGE_FILE"
