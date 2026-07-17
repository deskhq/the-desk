#!/bin/sh
#
# Upgrade the production stack in one command: back up, start the new release,
# and verify the instance is actually running it.
#
#   ./docker/upgrade.sh --target=X.Y.Z [BACKUP_DIR] [--timeout=SECONDS] [--no-pull]
#
# No git checkout: the release lives in .env as APP_VERSION, and the compose file
# pins the image to it.
#
# BACKUP_DIR defaults to the current directory and is passed straight to
# docker/backup.sh, so the backup lands wherever you would have put it anyway.
#
# The target version is APP_VERSION in .env. --target=X.Y.Z writes it for you
# before upgrading, so a routine upgrade is one command with no hand-edit; omit
# --target to run whatever APP_VERSION already holds (e.g. after editing .env
# yourself). It must match what the started container reports through app:version.
#
# --timeout bounds the health check (default 300s). A cold boot runs migrations
# and rebuilds the search index (`search:sync`) before /up answers, so it is
# deliberately generous; raise it on a slow host or a large database.
#
# --no-pull skips `docker compose pull` and uses the image already on the host,
# for air-gapped hosts or when you pulled ahead of the maintenance window.
# Build-from-source setups never pull: the overlay is detected from COMPOSE_FILE.
#
# IT NEVER ROLLS BACK ON ITS OWN. Rolling back is not a git revert here, it is a
# destructive database restore, and this script cannot tell the two cases apart:
# a migration that genuinely broke the schema, and an app that is fine but whose
# /up did not answer inside the timeout (slow first boot, a wedged healthcheck, a
# proxy hiccup). In the second case an automatic restore is a data-loss event the
# script itself caused, destroying every message written since the dump, and it
# would fire at 2am under exactly the conditions where nobody can evaluate it.
# So on failure it stops where it is, prints the exact restore command with the
# paths filled in, and leaves the decision to you. Note "stops", not "reverts":
# if it got as far as starting the new release, those containers are up and
# their migrations have run. Nothing is undone on your behalf.
#
# No `-f docker-compose.prod.yml` here: .env sets COMPOSE_FILE, so a bare
# `docker compose` resolves the right files for both setups.
set -eu

BACKUP_DIR=""
TARGET=""
TIMEOUT="300"
PULL="true"
POLL_INTERVAL="5"

usage() {
    echo "Usage: ./docker/upgrade.sh --target=X.Y.Z [BACKUP_DIR] [--timeout=SECONDS] [--no-pull]"
}

for arg in "$@"; do
    case "$arg" in
        --target=*)
            TARGET="${arg#--target=}"
            ;;
        --timeout=*)
            TIMEOUT="${arg#--timeout=}"
            ;;
        --no-pull)
            PULL="false"
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
            # Take at most one, rather than letting a second path silently win
            # and send the backup somewhere the operator did not mean.
            if [ -n "$BACKUP_DIR" ]; then
                echo "Error: unexpected argument '$arg'; only one backup directory is taken." >&2
                usage >&2
                exit 1
            fi

            BACKUP_DIR="$arg"
            ;;
    esac
done

if [ -z "$BACKUP_DIR" ]; then
    BACKUP_DIR="."
fi

if ! echo "$TIMEOUT" | grep -Eq '^[1-9][0-9]*$'; then
    echo "Error: --timeout must be a positive integer, got '$TIMEOUT'." >&2
    exit 1
fi

if [ ! -f "docker-compose.prod.yml" ]; then
    echo "Error: run this from the project root (docker-compose.prod.yml not found)." >&2
    exit 1
fi

for script in docker/backup.sh docker/restore.sh; do
    if [ ! -x "$script" ]; then
        echo "Error: $script is missing or not executable." >&2
        exit 1
    fi
done

# Read a key from .env, falling back to a default. An exported environment
# variable wins, matching how the other scripts read configuration. A trailing
# inline comment is stripped: the template ships `APP_VERSION=1.6.1 # x-release-...`
# (Docker Compose strips it too), so without this the target would carry the
# annotation and fail validation.
env_value() {
    key="$1"
    default="$2"
    value=""

    if [ -f ".env" ]; then
        value="$(sed -n "s/^${key}=//p" .env | tail -n 1 \
            | sed -e 's/[[:space:]][[:space:]]*#.*$//' \
                  -e 's/[[:space:]]*$//' \
                  -e 's/^"\(.*\)"$/\1/' \
                  -e "s/^'\(.*\)'\$/\1/")"
    fi

    if [ -z "$value" ]; then
        value="$default"
    fi

    echo "$value"
}

# Set KEY=VALUE in .env, replacing the line if present or appending it. Used to
# record --target so `docker compose pull` below actually moves to it: a bare
# pull reads APP_VERSION from .env, never this script's arguments.
set_env() {
    key="$1"
    value="$2"
    tmp="$(mktemp)"

    if grep -Eq "^${key}=" .env; then
        # Non-/ delimiter since versions are #-free but keep the habit.
        sed "s#^${key}=.*#${key}=${value}#" .env >"$tmp"
    else
        cat .env >"$tmp"
        printf '%s=%s\n' "$key" "$value" >>"$tmp"
    fi

    mv "$tmp" .env
}

# ---- Target version ---------------------------------------------------------
# APP_VERSION in .env is the release to run: the compose file pins the image to
# it, and the started container reports it back through `php artisan app:version`.
# --target writes APP_VERSION (so the pull moves to it); otherwise whatever
# APP_VERSION already holds is the target.
FROM_FLAG="false"

if [ -n "$TARGET" ]; then
    FROM_FLAG="true"
else
    TARGET="$(env_value APP_VERSION "")"
fi

if [ -z "$TARGET" ]; then
    echo "Error: no target version. Set APP_VERSION in .env, or pass --target=X.Y.Z." >&2
    exit 1
fi

# Validate before it reaches the image tag, the version check, or set_env's sed.
if ! echo "$TARGET" | grep -Eq '^[0-9]+\.[0-9]+\.[0-9]+([.-][0-9A-Za-z.-]+)?$'; then
    echo "Error: target version '$TARGET' is not a release like 1.6.1." >&2
    echo "  For a floating tag (e.g. edge), override APP_IMAGE instead." >&2
    exit 1
fi

if [ "$FROM_FLAG" = "true" ]; then
    if [ ! -f ".env" ]; then
        echo "Error: --target needs a .env to write APP_VERSION into (none here)." >&2
        exit 1
    fi

    set_env APP_VERSION "$TARGET"
fi

# Build-from-source operators list the build overlay in COMPOSE_FILE (that is how
# the install docs tell them to enable it), so detect the path from there rather
# than making them remember a flag. They build instead of pulling: their image is
# not in any registry.
COMPOSE_FILE_VALUE="${COMPOSE_FILE:-$(env_value COMPOSE_FILE docker-compose.prod.yml)}"
BUILD_FROM_SOURCE="false"

if echo "$COMPOSE_FILE_VALUE" | grep -q 'docker-compose\.build\.yml'; then
    BUILD_FROM_SOURCE="true"
    PULL="false"
fi

echo "Upgrading to $TARGET."
if [ "$BUILD_FROM_SOURCE" = "true" ]; then
    echo "  build-from-source setup detected (COMPOSE_FILE lists the build overlay)"
fi
echo

# ---- 1. Back up -------------------------------------------------------------
# Delegated to backup.sh rather than reimplemented, so there is one definition of
# what a backup is. Its output is captured to recover the exact paths it wrote,
# which the failure path needs to hand back to the operator.
echo "Step 1/3: backing up..."
BACKUP_LOG="$(mktemp)"

cleanup() {
    rm -f "$BACKUP_LOG"
}
trap cleanup EXIT

if ! ./docker/backup.sh "$BACKUP_DIR" >"$BACKUP_LOG" 2>&1; then
    cat "$BACKUP_LOG" >&2
    echo >&2
    echo "Error: backup failed, so the upgrade stopped before touching the stack." >&2
    echo "  Nothing has changed. Fix the backup, then run this again." >&2
    exit 1
fi

cat "$BACKUP_LOG"

# Parse the paths back out of backup.sh's own report. If its output format ever
# changes this fails here, before the stack is touched, rather than silently
# leaving the failure path without a restore command to print.
DUMP_FILE="$(sed -n 's|^  wrote \(.*db-backup-.*\.sql\.gz\)$|\1|p' "$BACKUP_LOG" | tail -n 1)"
STORAGE_FILE="$(sed -n 's|^  wrote \(.*storage-app-.*\.tar\.gz\)$|\1|p' "$BACKUP_LOG" | tail -n 1)"

if [ -z "$DUMP_FILE" ] || [ -z "$STORAGE_FILE" ] || [ ! -f "$DUMP_FILE" ] || [ ! -f "$STORAGE_FILE" ]; then
    echo "Error: the backup reported success but its files could not be identified." >&2
    echo "  Refusing to upgrade without a known-good backup to point you back at." >&2
    exit 1
fi

# The restore command below is this script's whole promise on the failure path,
# so it has to survive being pasted. Quote a path only when it contains anything
# the shell would act on, keeping the usual output clean.
shell_quote() {
    case "$1" in
        *[!A-Za-z0-9_/.@%+-]*)
            printf "'%s'" "$(printf '%s' "$1" | sed "s/'/'\\\\''/g")"
            ;;
        *)
            printf '%s' "$1"
            ;;
    esac
}

# Every failure from here on has a backup to offer, so they share this exit.
#
# Careful with the wording: by the time verification fails, the new image is
# already up and its migrations have already run. Saying the stack is
# "unchanged" would be a lie told at exactly the moment the operator is deciding
# whether to restore. The guarantee is only that nothing was rolled back.
fail_with_restore() {
    echo >&2
    echo "Error: $1" >&2
    echo >&2
    echo "Nothing was rolled back, so the stack is in whatever state the attempt" >&2
    echo "left it: the new containers may be up and their migrations may already" >&2
    echo "have run. It is untouched from here on, for you to inspect:" >&2
    echo "  docker compose ps" >&2
    echo "  docker compose logs --tail=100 app" >&2
    echo >&2
    echo "Rolling back means restoring the database, which destroys everything" >&2
    echo "written since the backup was taken a moment ago, and a slow boot looks" >&2
    echo "identical to a broken one from here. That call is yours." >&2
    echo >&2
    echo "Your backup is safe. When you have decided, restore it with:" >&2
    echo "  ./docker/restore.sh $(shell_quote "$DUMP_FILE") $(shell_quote "$STORAGE_FILE")" >&2
    echo >&2
    echo "(It will refuse the populated database until you add --force, and say so.)" >&2
    exit 1
}

# ---- 2. Start the new release -----------------------------------------------
echo
echo "Step 2/3: starting $TARGET..."

if [ "$PULL" = "true" ]; then
    if ! docker compose pull </dev/null; then
        fail_with_restore "could not pull the image for $TARGET; nothing was restarted."
    fi
fi

# Migrations run automatically on start, via the app service's entrypoint.
if [ "$BUILD_FROM_SOURCE" = "true" ]; then
    docker compose up -d --build </dev/null || fail_with_restore "the stack failed to build and start."
else
    docker compose up -d </dev/null || fail_with_restore "the stack failed to start."
fi

# ---- 3. Verify --------------------------------------------------------------
echo
echo "Step 3/3: verifying..."

# curl runs inside the container against its own port rather than against a host
# port: publishing is optional (a proxy inside the compose network reaches app:8080
# directly), so the host port is not a reliable way to ask. This is the same check
# the compose healthcheck makes, just polled faster than its 30s interval.
echo "  waiting for /up (timeout ${TIMEOUT}s)..."
STARTED="$(date +%s)"
DEADLINE="$((STARTED + TIMEOUT))"

# The deadline is real elapsed time, not a count of sleeps: every probe spawns a
# `docker compose exec`, which takes long enough that counting intervals would
# overshoot --timeout badly on a slow host, exactly where the wait matters most.
# curl gets its own --max-time so a single wedged probe cannot sail past the
# deadline either.
until docker compose exec -T app curl -fsS --max-time 5 -o /dev/null http://localhost:8080/up </dev/null 2>/dev/null; do
    if [ "$(date +%s)" -ge "$DEADLINE" ]; then
        fail_with_restore "the app did not answer /up within ${TIMEOUT}s. It may still be booting (migrations and search:sync run first), or the migration may have failed: check the logs before deciding. --timeout raises the wait."
    fi

    sleep "$POLL_INTERVAL"
done

echo "  /up answered after $(($(date +%s) - STARTED))s"

# Liveness is not identity: the OLD container answers /up just as happily as the
# new one, so a stack that came back on the previous image would look like a
# successful upgrade. Ask it what it is actually running.
RUNNING="$(docker compose exec -T app php artisan app:version </dev/null | tr -d '[:space:]')"

if [ -z "$RUNNING" ]; then
    fail_with_restore "the app is up but would not report its version, so the upgrade could not be confirmed."
fi

if [ "$RUNNING" != "$TARGET" ]; then
    fail_with_restore "the stack is running $RUNNING, not $TARGET. The old container is probably still up, or APP_IMAGE overrides the image to a tag other than the one APP_VERSION selects."
fi

echo "  running version confirmed: $RUNNING"
echo
echo "Upgraded to $RUNNING."
echo "  backup: $DUMP_FILE"
echo "          $STORAGE_FILE"
