# Capacity notes — streaming and chat concurrency

Measured 2026-11-14 on production (23 GB RAM, Apache `mpm_event`, PHP-FPM 8.3).

## The change that matters most: no SSE on the public web

An `EventSource` (SSE) response is a PHP request that stays open for its whole
duration (~30 s). Every web viewer with the member chat or the live page open
therefore held **one PHP-FPM worker for 30 seconds at a time** — 30 viewers
could occupy the entire pool.

The same data is already available through the JSON polls:

- member chat: `GET /chat/poll` every 4 s (messages, typing, read state)
- live page: `GET /live/state` every 5 s (chat messages, viewers, show time)
- admin chat: `GET /admin/chat/poll` every 5 s

The web clients now use only those polls. A poll request finishes in
milliseconds, so 1,000 viewers with chat open is ~250 requests/second of short
work rather than 1,000 pinned workers. The Android operator app keeps its own
SSE endpoint (`/webhooks/chat/stream`); that is one device.

`smoke.chat.client_surface` fails if the web chat opens the stream again or the
live page calls `connectSse()`.

## Production tuning applied

`/etc/php/8.3/fpm/pool.d/www.conf`:

| setting | before | after |
|---|---|---|
| `pm.max_children` | 30 | 120 |
| `pm.start_servers` | 8 | 12 |
| `pm.min_spare_servers` | 4 | 6 |
| `pm.max_spare_servers` | 12 | 30 |

Average FPM worker RSS is ~32 MB, so 120 workers is ~4 GB worst case on a 23 GB
box.

`/etc/apache2/mods-enabled/mpm_event.conf`:

| setting | before | after |
|---|---|---|
| `StartServers` | 2 | 4 |
| `MinSpareThreads` | 25 | 50 |
| `MaxSpareThreads` | 75 | 150 |
| `MaxRequestWorkers` | 150 | 400 |

`/etc/apache2/apache2.conf`: `KeepAliveTimeout 5 → 3`, `MaxKeepAliveRequests
100 → 500` (HLS segment requests reuse the connection).

`gallery-alias.conf`: the HLS proxy is
`ProxyPass /gallery/live/hls http://127.0.0.1:8888 flushpackets=on retry=0`,
so playlists are not buffered by the proxy.

The dev box has 2 GB RAM and keeps the conservative defaults (FPM 15). The
installer's defaults are deliberately small; production sizing is a per-box
decision.

## Measured on production (localhost, no TLS)

| test | before | after |
|---|---|---|
| `ab -n 2000 -c 100` static CSS | 982 req/s, 0 failed | 1127 req/s, 0 failed |
| `ab -n … -c 60/120` PHP `/health` | 535 req/s, 0 failed | 561 req/s, 0 failed |

The PHP number is flat because `/health` is tiny; the important change is that
the pool can now serve 120 concurrent requests (and that chat no longer parks
workers).

## The real ceiling: bandwidth

The operator app streams at **2.5 Mbps** (`BroadcastSession.kt`). At a 1 Gbps
uplink that is roughly **350–400 simultaneous live viewers**, and VOD is the
same arithmetic per active stream. Above that, reduce the broadcast bitrate or
add a CDN; CPU is not the limit.

## Verify / roll back

Config backups from the tuning run are in `/tmp/` on the box
(`php-pool.bak-*`, `mpm-event.bak-*`, `apache2-conf.bak-*`,
`gallery-alias.bak-*`). `apache2ctl configtest` and `php-fpm8.3 -t` must pass
before a reload.
