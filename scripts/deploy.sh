#!/usr/bin/env bash
# Deploy selected files with local/remote validation and rollback on failure.
set -euo pipefail

: "${DEPLOY_HOST:?DEPLOY_HOST must be set (user@host)}"
HOST="${DEPLOY_HOST}"
PASS="${DEPLOY_PASS:-}"
REMOTE_ROOT="${DEPLOY_ROOT:-/var/www/gallery}"

# Prefer SSH keys; fall back to sshpass only when DEPLOY_PASS is provided.
if [[ -n "$PASS" ]]; then
    ssh() { sshpass -p "$PASS" ssh -o StrictHostKeyChecking=no "$@"; }
    scp() { sshpass -p "$PASS" scp -o StrictHostKeyChecking=no "$@"; }
else
    ssh() { ssh -o StrictHostKeyChecking=no "$@"; }
    scp() { scp -o StrictHostKeyChecking=no "$@"; }
fi
SNAP_BASE="${DEPLOY_SNAP_DIR:-/tmp/gallery-pre-deploy}"
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"

[[ $# -ge 1 ]] || { echo "usage: $0 <file> [<file>...]" >&2; exit 1; }
remote() {
    # Pipe the script to a temp file, then run it under sudo. The password goes
    # to sudo through its own pipe, so a host with NOPASSWD sudo never executes
    # the password line as a stray script command.
    local tmp=".gallery-deploy-$$-$(date +%s).sh"
    if [[ -n "$PASS" ]]; then
        printf '%s\n' "$1" | ssh "$HOST" "cat > /tmp/$tmp && { echo '$PASS' | sudo -S -p '' bash /tmp/$tmp; rm -f /tmp/$tmp; }"
    else
        printf '%s\n' "$1" | ssh "$HOST" "cat > /tmp/$tmp && bash /tmp/$tmp; rm -f /tmp/$tmp"
    fi
}

FILES=()
for rel in "$@"; do
    [[ "$rel" != /* && "$rel" != *$'\n'* ]] || { echo "path is not relative: $rel" >&2; exit 1; }
    [[ -f "$REPO_ROOT/$rel" ]] || { echo "not a file: $rel" >&2; exit 1; }
    resolved="$(realpath -e -- "$REPO_ROOT/$rel")"
    [[ "$resolved" == "$REPO_ROOT"/* ]] || { echo "path escapes repo: $rel" >&2; exit 1; }
    FILES+=("$rel")
done

# Validate the complete local PHP tree before taking a remote snapshot.
shopt -s globstar nullglob
PHP_FILES=("$REPO_ROOT"/*.php "$REPO_ROOT"/**/*.php)
for file in "${PHP_FILES[@]}"; do
    php -l "$file" >/dev/null || { echo "local PHP lint failed: $file" >&2; exit 1; }
done

# Run the smoke suite before touching the server. A deploy that passes lint
# but fails the static checks (route/schema drift) is aborted here.
php "$REPO_ROOT/tests/smoke.php" >/dev/null \
    || { echo "local smoke suite failed; aborting deploy" >&2; exit 1; }

STAMP="$(date +%Y%m%d-%H%M%S)-$$"
SNAP="$SNAP_BASE-$STAMP"
STAGE="/tmp/gallery-deploy-$STAMP"
SNAPSHOT_READY=0
ROLLBACK_NEEDED=0

rollback() {
    [[ "$ROLLBACK_NEEDED" == 1 ]] || return 0
    echo ">> deployment failed; restoring snapshot" >&2
    local script="set -e"$'\n'
    for rel in "${FILES[@]}"; do
        script+="if [ -f '$SNAP/$rel' ]; then mkdir -p \"\$(dirname '$REMOTE_ROOT/$rel')\"; cp '$SNAP/$rel' '$REMOTE_ROOT/$rel'; else rm -f '$REMOTE_ROOT/$rel'; fi"$'\n'
    done
    remote "$script" || echo "rollback failed; snapshot retained at $SNAP" >&2
}
trap rollback EXIT

echo ">> staging files on server: $STAGE"
ssh "$HOST" "mkdir -p '$STAGE'"
remote "mkdir -p '$SNAP'"
for rel in "${FILES[@]}"; do
    ssh "$HOST" "mkdir -p '$STAGE/$(dirname -- "$rel")'"
    scp "$REPO_ROOT/$rel" "$HOST:$STAGE/$rel"
done

echo ">> snapshot dir: $SNAP"
for rel in "${FILES[@]}"; do
    remote "if [ -f '$REMOTE_ROOT/$rel' ]; then mkdir -p \"\$(dirname '$SNAP/$rel')\"; cp '$REMOTE_ROOT/$rel' '$SNAP/$rel'; fi"
done
SNAPSHOT_READY=1
ROLLBACK_NEEDED=1

for rel in "${FILES[@]}"; do
    remote "set -e
mkdir -p \"\$(dirname '$REMOTE_ROOT/$rel')\"
install -o www-data -g www-data -m 664 '$STAGE/$rel' '$REMOTE_ROOT/$rel'
"
done

remote "set -e
while IFS= read -r -d '' file; do
    php -l \"\$file\" >/dev/null
done < <(find '$REMOTE_ROOT' -path '$REMOTE_ROOT/storage' -prune -o -type f -name '*.php' -print0)
"

if [[ -n "${DEPLOY_HEALTH_URL:-}" ]]; then
    echo ">> health check: $DEPLOY_HEALTH_URL"
    curl --fail --silent --show-error --location --max-time "${DEPLOY_HEALTH_TIMEOUT:-15}" "$DEPLOY_HEALTH_URL" >/dev/null
fi

if [[ "${DEPLOY_NO_RELOAD:-0}" != 1 ]]; then
    echo ">> reloading php-fpm"
    remote "systemctl reload php7.4-fpm 2>/dev/null || true
systemctl reload php8.3-fpm 2>/dev/null || true
"
fi

# Opt-in schema migrations: set DEPLOY_MIGRATE=1 to run scripts/migrate.php
# on the remote after files land (snapshot already taken above, so a failed
# migration can be rolled back by hand).
if [[ "${DEPLOY_MIGRATE:-0}" == 1 ]]; then
    echo ">> running remote migrations"
    remote "php '$REMOTE_ROOT/scripts/migrate.php'"
fi

ROLLBACK_NEEDED=0
remote "rm -rf '$STAGE'"
echo ">> deployed: ${FILES[*]}"
echo ">> rollback copies on server: $SNAP"

# Prune snapshot/stage dirs older than 7 days so /tmp never fills with
# historical rollback copies.
remote "find /tmp/gallery-pre-deploy-* /tmp/gallery-deploy-* -maxdepth 0 -mtime +7 -exec rm -rf {} + 2>/dev/null || true"
