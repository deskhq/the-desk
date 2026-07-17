#!/bin/sh
#
# Restore the production stack's durable state from a backup pair.
#
#   ./docker/restore.sh db-backup-YYYY-MM-DD.sql.gz storage-app-YYYY-MM-DD.tar.gz [--force]
#
# Restore is destructive in a way backup is not, so this script:
#
#   - refuses a non-empty database unless --force is passed, because psql
#     replaying a plain dump over existing tables produces a half-merged mess
#     rather than the backup you asked for;
#   - prints exactly what it will overwrite and asks for confirmation, which
#     --force also skips for non-interactive use (cron, CI);
#   - stops app, reverb, queue, and scheduler before touching anything, so
#     nothing writes to the database or the uploads mid-restore.
#
# Every check that can refuse runs *before* the stack is stopped, so a run that
# bails leaves the instance exactly as it found it.
#
# Those services are left stopped afterwards so you can check things over (pgsql
# stays up: it is what we restore into); bring everything back with
# `docker compose up -d`.
#
# No `-f docker-compose.prod.yml` here: .env sets COMPOSE_FILE, so a bare
# `docker compose` resolves the right files for both the published-image and
# build-from-source setups.
set -eu

DUMP_FILE=""
STORAGE_FILE=""
FORCE="false"

usage() {
    echo "Usage: ./docker/restore.sh DUMP_FILE STORAGE_FILE [--force]"
}

for arg in "$@"; do
    case "$arg" in
        --force)
            FORCE="true"
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
            if [ -z "$DUMP_FILE" ]; then
                DUMP_FILE="$arg"
            elif [ -z "$STORAGE_FILE" ]; then
                STORAGE_FILE="$arg"
            else
                echo "Error: unexpected argument '$arg'." >&2
                usage >&2
                exit 1
            fi
            ;;
    esac
done

if [ -z "$DUMP_FILE" ] || [ -z "$STORAGE_FILE" ]; then
    echo "Error: both a database dump and a storage archive are required." >&2
    usage >&2
    exit 1
fi

if [ ! -f "docker-compose.prod.yml" ]; then
    echo "Error: run this from the project root (docker-compose.prod.yml not found)." >&2
    exit 1
fi

# Verify both archives before anything is stopped or overwritten. Restoring half
# of a truncated pair is worse than refusing the whole run: a valid gzip prefix
# can replay into psql cleanly and look like it worked.
for file in "$DUMP_FILE" "$STORAGE_FILE"; do
    if [ ! -r "$file" ]; then
        echo "Error: '$file' does not exist or is not readable." >&2
        exit 1
    fi

    if ! gzip -t "$file" 2>/dev/null; then
        echo "Error: '$file' is not a readable gzip archive (truncated or corrupt?)." >&2
        exit 1
    fi
done

# `gzip -t` only proves the compressed stream is intact; a valid gzip of garbage
# passes it. The uploads are extracted after storage/app has been cleared, so
# check the tar structure now rather than discovering it is unreadable with the
# existing uploads already gone.
if ! tar tzf "$STORAGE_FILE" >/dev/null 2>&1; then
    echo "Error: '$STORAGE_FILE' is not a readable tar archive." >&2
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

# pgsql is the one service that must be up: it is what we restore into, and the
# emptiness check below has to query it. Starting it changes no data.
#
# Every docker call from here to the confirmation prompt reads from /dev/null:
# `docker compose exec -T` attaches the container's stdin and drains it, which
# would otherwise eat the operator's answer before `read` sees it (and makes
# `echo yes | ./docker/restore.sh ...` fail).
echo "Ensuring the database is up..."
docker compose up -d --wait pgsql </dev/null

# ---- Guard a populated database ---------------------------------------------
TABLE_COUNT="$(docker compose exec -T pgsql psql -U "$DB_USERNAME" -d "$DB_DATABASE" -tAc \
    "SELECT count(*) FROM information_schema.tables WHERE table_schema = 'public'" </dev/null | tr -d '[:space:]')"

if ! echo "$TABLE_COUNT" | grep -Eq '^[0-9]+$'; then
    echo "Error: could not inspect database '$DB_DATABASE'." >&2
    exit 1
fi

if [ "$TABLE_COUNT" -gt 0 ] && [ "$FORCE" != "true" ]; then
    echo "Error: database '$DB_DATABASE' is not empty ($TABLE_COUNT tables)." >&2
    echo "  Restoring a dump over existing tables produces a half-merged database," >&2
    echo "  so this needs an explicit --force, which replaces the schema outright:" >&2
    echo "  ./docker/restore.sh $DUMP_FILE $STORAGE_FILE --force" >&2
    exit 1
fi

# ---- Which writers are up ---------------------------------------------------
# Everything that could write mid-restore. pgsql stays up by design. reverb is
# included because it shares the app image and boots the framework, even though
# it is not itself a writer of note.
WRITERS="app reverb queue scheduler"

running_writers() {
    for service in $WRITERS; do
        if [ -n "$(docker compose ps --quiet --status running "$service" 2>/dev/null </dev/null)" ]; then
            echo "$service"
        fi
    done
}

# Only ever used for the message below. The stop itself covers every writer
# unconditionally: this snapshot goes stale the moment it is taken, because
# `restart: unless-stopped` can bring a container back while the operator is
# still reading the confirmation prompt, and a writer missing from a stale list
# would never be stopped and would write straight through the restore.
RUNNING="$(running_writers | tr '\n' ' ' | sed -e 's/ *$//')"

# ---- Plan and confirm -------------------------------------------------------
echo
echo "About to restore into this instance, overwriting:"
if [ "$TABLE_COUNT" -gt 0 ]; then
    echo "  database '$DB_DATABASE' ($TABLE_COUNT existing tables, schema will be replaced)"
else
    echo "  database '$DB_DATABASE' (currently empty)"
fi
echo "    <- $DUMP_FILE"
echo "  all uploaded files in storage/app"
echo "    <- $STORAGE_FILE"

if [ -n "$RUNNING" ]; then
    echo "  stopping first, so nothing writes mid-restore: $RUNNING"
fi
echo

if [ "$FORCE" != "true" ]; then
    printf 'This cannot be undone. Type "yes" to continue: '
    if ! read -r reply; then
        echo >&2
        echo "Error: no input available to confirm; pass --force for non-interactive use." >&2
        exit 1
    fi

    if [ "$reply" != "yes" ]; then
        echo "Aborted; nothing was changed."
        exit 1
    fi
    echo
fi

# ---- Stop the writers -------------------------------------------------------
# Every writer, not just the ones that happened to be running when the plan was
# printed: stopping an already-stopped service is a no-op, while missing one that
# came back during the prompt means it writes into the middle of the restore.
echo "Stopping ${WRITERS}..."
# Unquoted on purpose: the service names are a word list, not one argument.
# shellcheck disable=SC2086
docker compose stop $WRITERS </dev/null

# ---- Database ---------------------------------------------------------------
echo "Restoring the database..."
# Dropping the schema and replaying the dump go to psql as ONE stream under
# --single-transaction, so a failure rolls the whole thing back and leaves the
# database exactly as it was. Doing the DROP as its own statement first would
# mean a dump that fails to replay leaves the operator with neither their old
# database nor their backup.
#
# The schema is dropped and recreated (rather than restoring over what is there)
# so the dump lands in the "freshly created, empty database" it expects: a plain
# pg_dump carries no DROP statements, so every CREATE would otherwise collide.
#
# ON_ERROR_STOP is what makes psql abort, and therefore roll back, on the first
# failed statement instead of replaying the rest over a broken schema and
# exiting 0. psql is last in the pipeline, so `set -e` sees its status.
#
# The `|| echo` on gunzip is what makes that guarantee hold for a decompression
# failure too. --single-transaction commits at end of input, so a gunzip that
# dies mid-stream would otherwise look like a clean EOF and psql would COMMIT the
# half a database it had read, exiting 0. Feeding it a statement that cannot
# succeed turns that silence into the error ON_ERROR_STOP needs to roll back.
# (`gzip -t` above already rejects a corrupt file up front; this covers the file
# changing underneath us, or an I/O error part way through reading it.)
{
    if [ "$TABLE_COUNT" -gt 0 ]; then
        echo "DROP SCHEMA public CASCADE;"
        echo "CREATE SCHEMA public;"
    fi

    gunzip -c "$DUMP_FILE" || echo "DO \$\$ BEGIN RAISE EXCEPTION 'restore aborted: reading $DUMP_FILE failed part way through'; END \$\$;"
} | docker compose exec -T pgsql \
    psql --single-transaction -v ON_ERROR_STOP=1 --quiet -U "$DB_USERNAME" -d "$DB_DATABASE" >/dev/null

# ---- Uploaded files ---------------------------------------------------------
# `run --rm --no-deps`, not `exec`: the app container is stopped by this point
# and exec needs a running one. This starts a throwaway container with the same
# storage-app volume mounted. The entrypoint is overridden because its job is to
# migrate and cache config, none of which should happen mid-restore.
#
# Extract into a staging directory FIRST, and only swap it in once tar has
# succeeded. Clearing the live tree up front would mean a failure part way
# through extraction (the disk filling is the realistic one) leaves the uploads
# gone with the database restore already committed: the one combination there is
# no recovering from. Staging costs the space twice, but a failed extract now
# leaves the existing uploads untouched.
#
# The staging directory has to live inside the volume to keep the moves on one
# filesystem, so it is cleaned up on every exit path (and again by the next run)
# rather than being left for a later backup to sweep up.
#
# The swap replaces the live contents rather than merging into them, so the
# result is the backup rather than the backup layered over whatever was there.
echo "Restoring uploaded files..."
docker compose run --rm --no-deps -T --entrypoint sh app -c '
    set -e
    root=/app/storage/app
    staging="$root/.restore-staging"
    previous="$root/.restore-previous"

    # Only the staging tree is disposable on the way out. `previous` is the
    # operator uploads and is cleared explicitly, once the swap has succeeded.
    trap "rm -rf \"$staging\"" EXIT

    rm -rf "$staging" "$previous"
    mkdir -p "$staging"

    # The live tree is untouched until this succeeds, so running out of disk
    # here costs nothing but the attempt.
    tar xzf - -C "$staging"

    # Extraction is done, so swap. Move the live tree aside rather than deleting
    # it: both steps are renames on one filesystem, so this costs no extra space
    # and leaves something to put back if the second half fails part way.
    mkdir -p "$previous"
    find "$root" -mindepth 1 -maxdepth 1 ! -name .restore-staging ! -name .restore-previous \
        -exec mv {} "$previous/" \;

    if ! find "$staging" -mindepth 1 -maxdepth 1 -exec mv {} "$root/" \; ; then
        echo "Installing the restored files failed; putting the previous ones back." >&2
        find "$root" -mindepth 1 -maxdepth 1 ! -name .restore-staging ! -name .restore-previous \
            -exec rm -rf {} +
        find "$previous" -mindepth 1 -maxdepth 1 -exec mv {} "$root/" \;
        rmdir "$previous"
        exit 1
    fi

    rm -rf "$previous"
    rmdir "$staging"
' <"$STORAGE_FILE"

echo
echo "Restore complete."
if [ -n "$RUNNING" ]; then
    # pgsql is deliberately still up: it is what we restored into.
    echo "Still stopped so you can check things over first: $RUNNING"
fi
echo "Bring the stack back with:"
echo "  docker compose up -d"
