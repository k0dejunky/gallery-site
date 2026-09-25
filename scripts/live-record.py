#!/usr/bin/env python3
"""Live stream recorder for the gallery.

Triggered by MediaMTX's runOnReady when a stream becomes live. Downloads the
MPEG-TS HLS segments from MediaMTX (authenticated as the internal 'rec'
reader via ?rec=1&recpass=<hash>) and appends them into one .ts file, so the
site can import the recording into a gallery. ffmpeg drops the auth query on
HLS segment URLs, so this downloads each segment directly instead of using
ffmpeg's HLS reader.
"""
import hashlib
import os
import re
import sys
import time
import urllib.request
import urllib.error

KEY = sys.argv[1]
if not re.match(r"^[A-Za-z0-9]+$", KEY):
    sys.exit(1)

MEDIA_KEY = ""
for line in open("/var/www/gallery/.env", encoding="utf-8", errors="replace"):
    if line.startswith("GALLERY_MEDIA_KEY="):
        MEDIA_KEY = line.split("=", 1)[1].strip()
        break
if not MEDIA_KEY:
    sys.exit(1)
REC_PASS = hashlib.sha256(MEDIA_KEY.encode()).hexdigest()

BASE = "http://127.0.0.1:8888/" + KEY
OUT = "/var/www/gallery/storage/live-recordings/" + KEY + ".ts"


def fetch(rel):
    url = BASE + "/" + rel + "?rec=1&recpass=" + REC_PASS
    req = urllib.request.Request(url)
    with urllib.request.urlopen(req, timeout=10) as resp:
        return resp.read()


def media_playlist():
    master = fetch("index.m3u8").decode("utf-8", "replace")
    for line in master.splitlines():
        line = line.strip()
        if line and not line.startswith("#") and line.endswith(".m3u8"):
            return line
    return "stream.m3u8"


def segments(media):
    pl = fetch(media).decode("utf-8", "replace")
    return [ln.strip() for ln in pl.splitlines()
            if ln.strip() and not ln.startswith("#") and ln.strip().endswith(".ts")]


def main():
    os.makedirs(os.path.dirname(OUT), exist_ok=True)
    seen = set()
    last_new = time.time()
    with open(OUT, "ab") as outf:
        while True:
            try:
                media = media_playlist()
                segs = segments(media)
            except Exception:
                # Playlist gone (stream ended) -> finalize.
                break

            wrote = False
            for seg in segs:
                if seg in seen:
                    continue
                try:
                    data = fetch(seg)
                except Exception:
                    break
                outf.write(data)
                outf.flush()
                seen.add(seg)
                last_new = time.time()
                wrote = True

            if not wrote and time.time() - last_new > 30:
                # No new segment for a while -> the publisher is gone (or the
                # first keyframe/segment is unusually late).
                break
            time.sleep(1)

    if os.path.getsize(OUT) < 100 * 1024:
        os.unlink(OUT)


if __name__ == "__main__":
    main()