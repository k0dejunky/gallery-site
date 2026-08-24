#!/usr/bin/env bash
# Deploy changed files to the gallery server with an automatic pre-change
# snapshot, remote syntax check and FPM reload.
#
# Usage:
#   scripts/deploy.sh <file> [<file>...]      # paths relative to repo root,
#                                             # deployed to the same path on
#                                             # /var/www/gallery
#   DEPLOY_NO_RELOAD=1 scripts/deploy.sh ...  # skip the FPM reload
set -euo pipefail

HOST="${DEPLOY_HOST:-k0dejunky@192.168.1.110}"
PASS="${DEPLOY_PASS:-Km011758!!}"
REMOTE_ROOT="${DEPLOY_ROOT:-/var/www/gallery}"
SNAP_BASE="${DEPLOY_SNAP_DIR:-/tmp/gallery-pre-deploy}"

[ $# -ge 1 ] || { echo "usage: $0 <file> [<file>...]" >&2; exit 1; }

FILES=("$@")
ssh()   { sshpass -p "$PASS" ssh -o StrictHostKeyChecking=no "$@"; }
scp()   { sshpass -p "$PASS" scp -o StrictHostKeyChecking=no "$@"; }
remote(){ ssh "$HOST" "echo '$PASS' | sudo -S -p '' bash -c '$1'"; }

STAMP=$(date +%Y%m%d-%H%M%S)
SNAP="$SNAP_BASE-$STAMP"
echo ">> snapshot dir: $SNAP"
remote "mkdir -p $SNAP"

TMPNAMES=()
for rel in "${FILES[@]}"; do
    [ -f "$rel" ] || { echo "not a file: $rel" >&2; exit 1; }
    flat="dep_$(echo "$rel" | tr '/' '_')"
    TMPNAMES+=("$flat")
    scp "$rel" "$HOST:/tmp/$flat"
    remote "
        if [ -f '$REMOTE_ROOT/$rel' ]; then
            mkdir -p \"\$(dirname '$SNAP/$rel')\"
            cp '$REMOTE_ROOT/$rel' '$SNAP/$rel'
        fi"
done

for i in "${!FILES[@]}"; do
    rel="${FILES[$i]}"
    flat="${TMPNAMES[$i]}"
    remote "
        set -e
        mkdir -p \"\$(dirname '$REMOTE_ROOT/$rel')\"
        install -o www-data -g www-data -m 664 '/tmp/$flat' '$REMOTE_ROOT/$rel'
        rm -f '/tmp/$flat'
        php -l '$REMOTE_ROOT/$rel'"
done

if [ "${DEPLOY_NO_RELOAD:-0}" != "1" ]; then
    echo ">> reloading php-fpm"
    ssh "$HOST" "echo '$PASS' | sudo -S -p '' bash -c '
        systemctl reload php7.4-fpm 2>/dev/null || true
        systemctl reload php8.3-fpm 2>/dev/null || true'"
fi

echo ">> deployed: ${FILES[*]}"
echo ">> rollback copies on server: $SNAP"
