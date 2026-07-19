#!/bin/sh
#
# Install the production stack on a fresh host, with no git repository required.
# It fetches the two files the stack needs (docker-compose.prod.yml and the .env
# template) plus the operational scripts, generates secrets, pins the release,
# and leaves you one edit away from `docker compose up -d`:
#
#   curl -fsSL https://raw.githubusercontent.com/emmpaul/the-desk/master/docker/install.sh | sh
#
# or, from a checkout:
#
#   ./docker/install.sh [--version=X.Y.Z] [--ref=REF] [TARGET_DIR]
#
# --version   Release to install (default: the version this script was cut with,
#             stamped below). Selects the image tag written to .env as APP_VERSION.
# --ref       Git ref to download the files from (default: the version tag,
#             `vX.Y.Z`). Pass `master` to track the tip, e.g. for testing.
# TARGET_DIR  Where to install (default: the current directory).
#
# It stops short of starting the stack on purpose: APP_URL, mail, and the
# browser-side REVERB_*_PUBLIC values have no safe defaults, and a curl | sh
# pipe has no terminal to prompt on. So it prepares everything and prints the
# two remaining steps. Re-running against a directory that already has a .env is
# refused — that is an upgrade, which is docker/upgrade.sh's job, not this one.
set -eu

# The release installed when --version is not given. Tracks the latest release.
DEFAULT_VERSION="1.10.1" # x-release-please-version

REPO="emmpaul/the-desk"
VERSION=""
REF=""
TARGET_DIR=""

usage() {
    echo "Usage: ./docker/install.sh [--version=X.Y.Z] [--ref=REF] [TARGET_DIR]"
}

for arg in "$@"; do
    case "$arg" in
        --version=*)
            VERSION="${arg#--version=}"
            ;;
        --ref=*)
            REF="${arg#--ref=}"
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
            if [ -n "$TARGET_DIR" ]; then
                echo "Error: unexpected argument '$arg'; only one target directory is taken." >&2
                usage >&2
                exit 1
            fi

            TARGET_DIR="$arg"
            ;;
    esac
done

if [ -z "$VERSION" ]; then
    VERSION="$DEFAULT_VERSION"
fi

# Accept a leading `v` (people paste the tag) and normalise it away.
VERSION="${VERSION#v}"

if ! echo "$VERSION" | grep -Eq '^[0-9]+\.[0-9]+\.[0-9]+([.-][0-9A-Za-z.-]+)?$'; then
    echo "Error: --version must be a release like 1.6.1, got '$VERSION'." >&2
    echo "  To run a floating tag (e.g. edge), install a release and set APP_IMAGE." >&2
    exit 1
fi

if [ -z "$REF" ]; then
    REF="v$VERSION"
fi

if [ -z "$TARGET_DIR" ]; then
    TARGET_DIR="."
fi

for tool in curl openssl docker; do
    if ! command -v "$tool" >/dev/null 2>&1; then
        echo "Error: $tool is required but not installed." >&2
        exit 1
    fi
done

if [ ! -d "$TARGET_DIR" ]; then
    mkdir -p "$TARGET_DIR"
fi

cd "$TARGET_DIR"

if [ -f ".env" ]; then
    echo "Error: .env already exists here, so this looks like an existing install." >&2
    echo "  Refusing to overwrite it. To move to another release, upgrade instead:" >&2
    echo "    edit APP_VERSION in .env, then ./docker/upgrade.sh" >&2
    exit 1
fi

RAW_BASE="https://raw.githubusercontent.com/${REPO}/${REF}"

# Fetch one repo file to a local path, creating parent directories. Downloads to
# a temp file first so a mid-transfer failure never leaves a truncated file that
# a later step would mistake for a good one.
fetch() {
    src="$1"
    dest="$2"
    dir="$(dirname "$dest")"

    if [ "$dir" != "." ]; then
        mkdir -p "$dir"
    fi

    tmp="$(mktemp)"

    if ! curl -fsSL "${RAW_BASE}/${src}" -o "$tmp"; then
        rm -f "$tmp"
        echo "Error: could not download ${src} from ${REF}." >&2
        echo "  Check the version/ref exists: https://github.com/${REPO}/tree/${REF}" >&2
        exit 1
    fi

    mv "$tmp" "$dest"
}

echo "Installing The Desk $VERSION into $(pwd) (files from $REF)."
echo

echo "Step 1/3: downloading the stack..."
fetch "docker-compose.prod.yml" "docker-compose.prod.yml"
fetch ".env.prod.example" ".env.prod.example"
# The operational scripts, so backup / upgrade / restore are on the box too.
for script in gen-secrets upgrade backup restore; do
    fetch "docker/${script}.sh" "docker/${script}.sh"
    chmod +x "docker/${script}.sh"
done
echo "  downloaded docker-compose.prod.yml, .env.prod.example, and docker/*.sh"
echo

echo "Step 2/3: generating .env and secrets..."
./docker/gen-secrets.sh
echo

echo "Step 3/3: pinning APP_VERSION=$VERSION..."
# gen-secrets copied the template's APP_VERSION verbatim; set it to the release
# actually being installed (they differ when --version or --ref override it).
# Append the line if an older template predates it, so the pin is never lost.
if grep -Eq '^APP_VERSION=' .env; then
    tmp="$(mktemp)"
    sed "s#^APP_VERSION=.*#APP_VERSION=${VERSION}#" .env >"$tmp"
    mv "$tmp" .env
else
    printf 'APP_VERSION=%s\n' "$VERSION" >>.env
fi
echo "  set APP_VERSION=$VERSION"
echo

echo "Done. Two steps remain before starting the stack:"
echo "  1. Edit .env — set APP_URL, mail credentials, and the browser-side"
echo "     REVERB_*_PUBLIC values (see the comments in the file)."
echo "  2. Start it:"
echo "       docker compose up -d"
