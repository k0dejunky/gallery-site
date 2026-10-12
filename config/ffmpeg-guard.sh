#!/usr/bin/env bash
# /usr/bin/ffmpeg  ->  global-concurrency guard wrapper. Caps concurrent
# ffmpeg at FFMPEG_CONCURRENCY_LIMIT (default 2); the real binary is in
# /usr/local/bin/ffmpeg.real. Waits up to 15s for a slot, then fails fast
# (rc=1) so burst traffic can't pin every php-fpm worker waiting in line.
#
# The lock dir must be writable by every caller: the web app runs as www-data,
# cron/root as root. It is created 0770 root:www-data at boot by
# config/ffmpeg-limits.tmpfiles. It must NOT be sticky — when root runs ffmpeg
# it leaves a stale lock www-data has to be able to delete. Without group write,
# www-data cannot create a lock and EVERY web ffmpeg call exits 1, which
# silently breaks video thumbnail generation.
set -u
LIMIT=${FFMPEG_CONCURRENCY_LIMIT:-2}
D=${FFMPEG_LIMIT_DIR:-/run/ffmpeg-limits}
mkdir -p "$D" 2>/dev/null
chmod 0770 "$D" 2>/dev/null || true
g=""
cleanup(){ [ -n "$g" ] && rm -f "$D/$g.pid" && rmdir "$D/$g.lock" 2>/dev/null; }
trap cleanup EXIT
acquire(){
  local i pp
  for i in $(seq 1 "$LIMIT"); do
    if mkdir "$D/$i.lock" 2>/dev/null; then g="$i"; echo $$ > "$D/$i.pid" 2>/dev/null; return 0; fi
    pp=$(cat "$D/$i.pid" 2>/dev/null || true)
    if [ -n "$pp" ] && ! kill -0 "$pp" 2>/dev/null; then rm -f "$D/$i.pid" 2>/dev/null; rmdir "$D/$i.lock" 2>/dev/null; fi
  done
  return 1
}
deadline=$((SECONDS+15))
while ! acquire; do
  if [ $SECONDS -ge $deadline ]; then exit 1; fi
  sleep 0.2
done
exec /usr/local/bin/ffmpeg.real "$@"
