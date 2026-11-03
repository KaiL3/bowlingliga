#!/usr/bin/env bash
#
# Update of the bowling league website on the server directly from its Git
# repository.
#
#     sudo bash deploy/update.sh              latest version of the branch
#     sudo bash deploy/update.sh <commit|tag> a specific version (e.g. going back)
#
# Procedure: fetch the repository into a working copy, back up the database,
# then run deploy/install.sh from the new version. install.sh takes care of
# copying, dependencies, migrations and configuration; the first time without
# an existing installation it asks for the details.
#
# Repository: REPO, otherwise the address remembered by install.sh, otherwise
# the one of the existing working copy. A public repository is cloned via
# HTTPS (https://…) without any key. For a private repository use its SSH
# address (git@…): on the first run the script creates a deploy key and shows
# it; enter it at the Git host as a read-only deploy key, then start the
# script again.
#
# Environment variables (optional):
#   REPO=https://github.com/<owner>/<repository>.git
#   BRANCH=main
#   INSTANCE=bowlingliga                 see install.sh
#   WORKING_COPY=/var/lib/<instance>/source
#   BACKUPS=/var/backups/<instance>      database backup before the update
#   NO_BACKUP=1                          do not create a backup
#   as well as all variables of install.sh (TARGET, PHP_VERSION …)
#
set -euo pipefail

info() { printf '\n\033[1;34m==> %s\033[0m\n' "$*"; }
note() { printf '\033[0;33m    %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mError: %s\033[0m\n' "$*" >&2; exit 1; }

[[ $EUID -eq 0 ]] || fail "Please run with sudo or as root."

# --- Instance (same rule as install.sh) ------------------------------------------

if [[ -z ${INSTANCE:-} ]]; then
    mapfile -t FOUND < <(grep -l '(deploy/install.sh)' /etc/nginx/sites-available/* 2>/dev/null | xargs -r -n1 basename)
    if (( ${#FOUND[@]} == 1 )); then
        INSTANCE=${FOUND[0]}
    elif (( ${#FOUND[@]} > 1 )); then
        fail "Several instances found (${FOUND[*]}). Choose one with INSTANCE=<name>."
    else
        INSTANCE=bowlingliga
    fi
fi
[[ $INSTANCE =~ ^[a-z][a-z0-9_]{1,30}$ ]] \
    || fail "Invalid instance name \"$INSTANCE\" (a-z, 0-9 and _, starting with a letter)."

SETTINGS=/etc/$INSTANCE/install.conf
TARGET_DIR=""; REPO_URL=""
if [[ -f $SETTINGS ]]; then
    # shellcheck source=/dev/null
    source "$SETTINGS"
fi
BRANCH="${BRANCH:-main}"
WORKING_COPY="${WORKING_COPY:-/var/lib/$INSTANCE/source}"
BACKUPS="${BACKUPS:-/var/backups/$INSTANCE}"
NO_BACKUP="${NO_BACKUP:-0}"
TARGET="${TARGET:-${TARGET_DIR:-/var/www.$INSTANCE}}"
DEPLOY_KEY=/root/.ssh/${INSTANCE}_deploy
VERSION="${1:-}"

if [[ -z ${REPO:-} ]]; then
    REPO=$REPO_URL
fi
if [[ -z $REPO && -d $WORKING_COPY/.git ]]; then
    REPO="$(git -C "$WORKING_COPY" remote get-url origin 2>/dev/null || true)"
fi
[[ -n $REPO ]] || fail "Which repository? Start with REPO=https://github.com/<owner>/<repository>.git"

if ! command -v git >/dev/null; then
    info "Installing git"
    DEBIAN_FRONTEND=noninteractive apt-get install -y -qq --no-upgrade git openssh-client >/dev/null
fi

# --- Deploy key (only for a private repository via SSH) --------------------------

if [[ $REPO == git@* || $REPO == ssh://* ]]; then
    if [[ ! -f $DEPLOY_KEY ]]; then
        info "Creating a deploy key"
        mkdir -p -m 700 /root/.ssh
        ssh-keygen -q -t ed25519 -N '' -C "$INSTANCE-deploy@$(hostname)" -f "$DEPLOY_KEY"
        echo
        echo "    Enter this key at the Git host as a deploy key of the repository,"
        echo "    read-only (GitHub: Repository → Settings → Deploy keys → Add deploy key,"
        echo "    do NOT tick \"Allow write access\"):"
        echo
        cat "$DEPLOY_KEY.pub"
        echo
        echo "    Then start this script again."
        exit 0
    fi
    export GIT_SSH_COMMAND="ssh -i $DEPLOY_KEY -o IdentitiesOnly=yes -o StrictHostKeyChecking=accept-new"
fi

# --- Fetch the version -----------------------------------------------------------

info "Fetching from $REPO"
if [[ ! -d $WORKING_COPY/.git ]]; then
    mkdir -p "$(dirname "$WORKING_COPY")"
    git clone -q --branch "$BRANCH" "$REPO" "$WORKING_COPY" \
        || fail "Cloning failed – is the address right (and for SSH the deploy key entered)?"
    BEFORE=""
else
    git -C "$WORKING_COPY" remote set-url origin "$REPO"
    BEFORE="$(git -C "$WORKING_COPY" rev-parse HEAD)"
    git -C "$WORKING_COPY" fetch -q --tags origin "$BRANCH" \
        || fail "Fetching failed – is the address right (and for SSH the deploy key entered)?"
fi

# The working copy is only a mirror: local changes are discarded.
TARGET_VERSION="${VERSION:-origin/$BRANCH}"
git -C "$WORKING_COPY" rev-parse -q --verify "$TARGET_VERSION^{commit}" >/dev/null \
    || fail "Version \"$TARGET_VERSION\" does not exist in the repository."
git -C "$WORKING_COPY" checkout -q --detach "$TARGET_VERSION"
git -C "$WORKING_COPY" reset -q --hard
git -C "$WORKING_COPY" clean -q -fdx
AFTER="$(git -C "$WORKING_COPY" rev-parse HEAD)"

if [[ -n $BEFORE && $BEFORE == "$AFTER" ]]; then
    note "No new changes ($(git -C "$WORKING_COPY" log -1 --format='%h %s'))."
    note "The installation is brought in line anyway."
elif [[ -n $BEFORE ]]; then
    note "New since the last update:"
    git -C "$WORKING_COPY" log --format='      %h %s' "$BEFORE..$AFTER" 2>/dev/null \
        || git -C "$WORKING_COPY" log -1 --format='      %h %s'
else
    note "Version: $(git -C "$WORKING_COPY" log -1 --format='%h %s')"
fi

# --- Back up the database --------------------------------------------------------

CONFIG="$TARGET/config/database.php"
if [[ $NO_BACKUP != 1 && -f $CONFIG ]]; then
    info "Backing up the database"
    DB_NAME="$(php -r '$c = require $argv[1]; echo $c["dbname"] ?? "";' "$CONFIG")"
    mkdir -p -m 700 "$BACKUPS"
    FILE="$BACKUPS/pre-update-$(date +%F-%H%M%S).sql.gz"
    if ! (umask 077; mariadb-dump --single-transaction "$DB_NAME" | gzip > "$FILE"); then
        rm -f "$FILE"
        fail "Backup failed, update aborted (without backup: NO_BACKUP=1)."
    fi
    note "$FILE"
    # keep only the last ten backups before updates
    ls -1t "$BACKUPS"/pre-update-*.sql.gz 2>/dev/null | tail -n +11 | xargs -r rm -f || true
fi

# --- Bring the installation up to date -------------------------------------------

info "Running install.sh of the new version"
INSTANCE="$INSTANCE" TARGET="$TARGET" REPO="$REPO" exec bash "$WORKING_COPY/deploy/install.sh"
