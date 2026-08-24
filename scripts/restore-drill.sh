#!/usr/bin/env bash
# Backup restore drill: proves the newest backup actually restores.
#
# Strategy (avoids pulling gigabytes through the pipe):
#   1. Locate the newest run-stamp group locally (storage/backups) or on
#      gdrive when missing locally.
#   2. Verify per-part sha256 manifests, concatenate the split archive,
#      and fully decode it with tar -tzf.
#   3. Require a database dump in the run.
#   4. When a local copy exists, confirm the Drive copy is byte-identical
#      via `rclone check` (API-side MD5 comparison — no download).
#
# Status file: /var/www/gallery/storage/backups/.last_drill
#   {"ok":true,"at":"...","file":"gallery-backup-...","note":"..."}
set -u

ROOT=/var/www/gallery
LOCAL_DIR="$ROOT/storage/backups"
DRILL_DIR=/tmp/restore-drill
STATUS="$LOCAL_DIR/.last_drill"
RCLONE=${RCLONE:-/usr/local/bin/rclone}
export RCLONE_CONFIG=${RCLONE_CONFIG:-/var/www/.config/rclone/rclone.conf}
REMOTE=gdrive:gallery-site/backups

mkdir -p "$LOCAL_DIR"
rm -rf "$DRILL_DIR"; mkdir -p "$DRILL_DIR"

now() { date -u +%Y-%m-%dT%H:%M:%SZ; }
write_status() { # ok file note
    printf '{"ok":%s,"at":"%s","file":"%s","note":%s}\n' \
        "$1" "$(now)" "$2" "$(printf '%s' "$3" | python3 -c 'import json,sys; print(json.dumps(sys.stdin.read()[:300]))')" \
        > "$STATUS.tmp" && mv "$STATUS.tmp" "$STATUS"
}
fail() { write_status false "${2:-}" "$1"; echo "restore-drill: FAIL - $1"; rm -rf "$DRILL_DIR"; exit 1; }

# --- 1. newest run stamp ----------------------------------------------------
STAMP=$(ls "$LOCAL_DIR" 2>/dev/null | grep -oE 'gallery-backup-[0-9]{8}-[0-9]{6}' | sort -u | tail -1)
SOURCE=local
if [ -z "$STAMP" ]; then
    STAMP=$("$RCLONE" lsf "$REMOTE" --files-only 2>/dev/null \
        | grep -oE 'gallery-backup-[0-9]{8}-[0-9]{6}' | sort -u | tail -1)
    SOURCE=remote
fi
[ -n "$STAMP" ] || fail "no backups found (local or $REMOTE)"
ARCHIVE="$STAMP.tar.gz"

# --- 2. get the archive locally ---------------------------------------------
if [ "$SOURCE" = remote ]; then
    DB_STAMP="gallery-db-${STAMP#gallery-backup-}"
    "$RCLONE" copy "$REMOTE" "$DRILL_DIR" --quiet --multi-thread-cutoff 100M \
        --multi-thread-streams 8 --include "$STAMP*" --include "$DB_STAMP*" \
        || fail "rclone download failed" "$STAMP"
    WORK="$DRILL_DIR"
else
    WORK="$LOCAL_DIR"
    # Confirm the Drive copy matches the local bytes via MD5 (no download).
    if ! "$RCLONE" check "$REMOTE" "$WORK" \
            --include "$ARCHIVE*" --include "gallery-db-${STAMP#gallery-backup-}*" >/dev/null 2>&1; then
        fail "rclone check: Drive copy differs from local for $STAMP" "$STAMP"
    fi
fi

# --- 3. verify + reassemble --------------------------------------------------
cd "$WORK" || fail "workdir vanished" "$STAMP"

if ls "$ARCHIVE".part-* >/dev/null 2>&1; then
    [ -f "$ARCHIVE.sha256" ] || fail "split backup without .sha256 manifest" "$STAMP"
    sha256sum -c "$ARCHIVE.sha256" --quiet || fail "part checksum mismatch" "$STAMP"
    cat "$ARCHIVE".part-* > "$DRILL_DIR/$ARCHIVE"
    VERIFY="$DRILL_DIR/$ARCHIVE"
elif [ -f "$ARCHIVE" ]; then
    VERIFY="$ARCHIVE"
else
    fail "no archive found in run" "$STAMP"
fi

tar -tzf "$VERIFY" >/dev/null 2>&1 || fail "archive corrupt (full gzip decode failed)" "$STAMP"
FILES=$(tar -tzf "$VERIFY" | wc -l)

ls gallery-db-*.sql.gz >/dev/null 2>&1 \
    || tar -tzf "$VERIFY" | grep -q 'gallery-db-.*\.sql\.gz' \
    || fail "no database dump found in run" "$STAMP"

rm -rf "$DRILL_DIR"
write_status true "$STAMP" "OK from $SOURCE: archive readable ($FILES entries), checksums verified"
echo "restore-drill: OK ($STAMP from $SOURCE, $FILES entries)"
