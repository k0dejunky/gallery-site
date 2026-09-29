#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Gallery folder importer for the Windows 7 training PC and Ubuntu.

Reads a host folder, splits each reference subfolder into an images gallery and
a videos gallery (title = folder name), creates them on the gallery site through
the import API (POST /webhooks/import/gallery) scheduled 24 hours after the last
gallery-queue post, and moves the imported folder into a "posted" folder.

Dependency-free (stdlib only) so it runs on Python 3.8 (Windows 7) and any
modern Python on Ubuntu without pip installs.

Two uses:
  * Standalone (Ubuntu / manual):  python gallery_import.py --run [--host DIR]
  * Inside trainer_control.py:      from gallery_import import run_once, CONFIG_DEFAULTS
"""

import argparse
import json
import mimetypes
import os
import shutil
import socket
import sys
import threading
import time
import traceback
import uuid
from datetime import datetime, timedelta
from urllib import request as urlrequest
from urllib import error as urlerror
from urllib import parse as urlparse

IMAGE_EXT = ("jpg", "jpeg", "png", "gif", "webp", "bmp", "heic", "heif", "avif", "tiff")
VIDEO_EXT = ("mp4", "webm", "mov", "m4v", "ogg", "avi", "mkv", "3gp", "3g2", "mpg", "mpeg",
             "wmv", "flv", "ts", "mts", "m2ts", "vob", "asf")

# Resumable chunked upload: files at/above CHUNK_MIN bytes are sliced into
# CHUNK_SIZE parts and uploaded as many small requests (each safely within the
# server's proxy/timeout limits), so multi-GB videos upload reliably instead of
# stalling in a single long request. Values must match the server's
# config('app.uploads') chunk_size/chunk_min.
CHUNK_SIZE = 16 * 1024 * 1024   # 16 MiB
CHUNK_MIN = 8 * 1024 * 1024     # 8 MiB

DEFAULT_CONFIG = {
    "host_folder": "",           # set at load (folder picker / --host / config)
    "posted_folder": "",         # default: <host_folder>/posted
    "machine": "",               # machine name for per-machine site settings
    "server_base": "https://amethyst2213.com/gallery",
    "import_token": "",
    "spacing_hours": 24,
    "min_level": 0,
    "description": "",
    "is_secret": False,
    "status_file": "",           # default: <host_folder>/.gallery_import_status.json
    "log_file": "",              # default: <host_folder>/gallery_import.log
    "image_ext": list(IMAGE_EXT),
    "video_ext": list(VIDEO_EXT),
}

# Single-flight: scheduled + on-demand triggers never run concurrently.
_LOCK = threading.Lock()
_CONFIG_FILE = None
STATUS_MASK = "********"

# Live activity reported to the control server UI: the folder/file being
# processed and, for chunked uploads, byte-accurate progress of the current
# file. Written from the run thread, read from the status endpoint thread.
PROGRESS_LOCK = threading.Lock()
PROGRESS = {
    "running": False,
    "stage": "idle",          # idle|queue|scan|uploading|moving|done|error
    "folder": "",
    "gallery_type": "",
    "file": "",
    "file_index": 0,
    "files_total": 0,
    "file_bytes": 0,
    "file_total": 0,
    "message": "",
    "started_at": "",
    "last_update": "",
}


def current_progress():
    """Snapshot of live import activity for the control UI."""
    with PROGRESS_LOCK:
        return dict(PROGRESS)


def _set_progress(**kw):
    with PROGRESS_LOCK:
        PROGRESS.update(kw)
        PROGRESS["last_update"] = datetime.now().strftime("%Y-%m-%d %H:%M:%S")


def set_config_file(path):
    global _CONFIG_FILE
    _CONFIG_FILE = path


def read_config(path=None):
    cfg = dict(DEFAULT_CONFIG)
    path = path or _CONFIG_FILE or "import.config.json"
    try:
        if os.path.isfile(path):
            with open(path, encoding="utf-8") as fh:
                data = json.load(fh)
            if isinstance(data, dict):
                cfg.update(data)
    except Exception:
        pass
    return cfg


def write_config(cfg, path=None):
    path = path or _CONFIG_FILE or "import.config.json"
    with open(path, "w", encoding="utf-8") as fh:
        json.dump(cfg, fh, indent=2, ensure_ascii=False)


def _masked(cfg):
    out = dict(cfg)
    if out.get("import_token"):
        out["import_token"] = STATUS_MASK
    return out


def _log(cfg, line):
    text = "%s  %s" % (datetime.now().strftime("%Y-%m-%d %H:%M:%S"), line)
    print(text, flush=True)
    log_file = cfg.get("log_file") or os.path.join(cfg["host_folder"] or ".", "gallery_import.log")
    try:
        with open(log_file, "a", encoding="utf-8") as fh:
            fh.write(text + "\n")
    except Exception:
        pass


def _http_json(url, token, fields=None, files=None, timeout=900):
    """HTTP call to the import API. GET when no fields/files; otherwise a
    multipart POST (fields, files, or both). Returns (status, parsed)."""
    if not fields and not files:
        req = urlrequest.Request(url, headers={"Authorization": "Bearer " + token})
        with urlrequest.urlopen(req, timeout=timeout) as resp:
            return resp.status, json.loads(resp.read().decode("utf-8") or "{}")

    boundary = "----galleryImport" + uuid.uuid4().hex
    parts = []
    for key, value in (fields or {}).items():
        parts.append(
            ("--%s\r\nContent-Disposition: form-data; name=\"%s\"\r\n\r\n%s\r\n"
             % (boundary, key, value)).encode("utf-8"))
    for fname in (files or []):
        ctype = mimetypes.guess_type(fname)[0] or "application/octet-stream"
        parts.append(
            ("--%s\r\nContent-Disposition: form-data; name=\"files[]\"; filename=\"%s\"\r\n"
             "Content-Type: %s\r\n\r\n"
             % (boundary, os.path.basename(fname), ctype)).encode("utf-8"))
        with open(fname, "rb") as fh:
            parts.append(fh.read())
        parts.append(b"\r\n")
    parts.append(("--%s--\r\n" % boundary).encode("utf-8"))
    body = b"".join(parts)

    req = urlrequest.Request(
        url, data=body, method="POST",
        headers={
            "Authorization": "Bearer " + token,
            "Content-Type": "multipart/form-data; boundary=" + boundary,
            "Content-Length": str(len(body)),
        })
    with urlrequest.urlopen(req, timeout=timeout) as resp:
        return resp.status, json.loads(resp.read().decode("utf-8") or "{}")


def _post_chunk(url, token, fields, chunk_bytes, timeout=600):
    """Multipart POST of one raw chunk (field "chunk") with the given fields.
    Used by the resumable chunked upload; each request carries at most one
    CHUNK_SIZE part so it stays well within server timeout limits."""
    boundary = "----galleryImport" + uuid.uuid4().hex
    parts = []
    for key, value in (fields or {}).items():
        parts.append(
            ("--%s\r\nContent-Disposition: form-data; name=\"%s\"\r\n\r\n%s\r\n"
             % (boundary, key, value)).encode("utf-8"))
    parts.append(
        ("--%s\r\nContent-Disposition: form-data; name=\"chunk\"; filename=\"chunk\"\r\n"
         "Content-Type: application/octet-stream\r\n\r\n" % boundary).encode("utf-8"))
    parts.append(chunk_bytes)
    parts.append(b"\r\n")
    parts.append(("--%s--\r\n" % boundary).encode("utf-8"))
    body = b"".join(parts)

    req = urlrequest.Request(
        url, data=body, method="POST",
        headers={
            "Authorization": "Bearer " + token,
            "Content-Type": "multipart/form-data; boundary=" + boundary,
            "Content-Length": str(len(body)),
        })
    with urlrequest.urlopen(req, timeout=timeout) as resp:
        return resp.status, json.loads(resp.read().decode("utf-8") or "{}")


def fetch_next_slot(cfg):
    """The next publish slot from the gallery queue (24h after the last post)."""
    base = (cfg["server_base"] or "").rstrip("/")
    token = (cfg["import_token"] or "").strip()
    if not base or not token:
        raise RuntimeError("server_base and import_token are required")
    status, data = _http_json(base + "/webhooks/import/queue?spacing_hours=%d" % int(cfg["spacing_hours"] or 24),
                              token, timeout=30)
    if status != 200:
        raise RuntimeError("queue lookup failed (HTTP %d)" % status)
    slot = str(data.get("next_slot") or "")
    if not slot:
        raise RuntimeError("queue returned no next_slot")
    return slot


def _bump_slot(slot, hours):
    dt = datetime.strptime(slot, "%Y-%m-%d %H:%M:%S") + timedelta(hours=int(hours))
    return dt.strftime("%Y-%m-%d %H:%M:%S")


def bucket_files(folder, cfg):
    """Collect image/video files from a reference folder, scanning nested
    subfolders recursively (reference folders often wrap their media in a
    subfolder)."""
    images, videos = [], []
    image_ext = set(cfg.get("image_ext") or IMAGE_EXT)
    video_ext = set(cfg.get("video_ext") or VIDEO_EXT)
    for root, _dirs, names in os.walk(folder):
        for name in names:
            full = os.path.join(root, name)
            if not os.path.isfile(full):
                continue
            ext = (name.rsplit(".", 1)[-1] if "." in name else "").lower()
            if ext in image_ext:
                images.append(full)
            elif ext in video_ext:
                videos.append(full)
    return sorted(images), sorted(videos)


def _http_retry(url, token, fields=None, files=None, timeout=900, attempts=3, raw=None):
    """_http_json (or _post_chunk when raw is given) with retries for transient
    connection resets (the box's network intermittently drops connections with
    WinError 10053/10054). Safe: re-creating a gallery is idempotent
    (title+type), files dedupe by hash, and chunk parts simply overwrite."""
    last = None
    for i in range(attempts):
        try:
            if raw is not None:
                return _post_chunk(url, token, fields, raw, timeout)
            return _http_json(url, token, fields, files, timeout)
        except Exception as exc:
            last = exc
            time.sleep(2)
    raise last


def _upload_chunked(cfg, gid, path, original_name):
    """Upload one file to an import gallery in CHUNK_SIZE parts. Each part is
    its own request with per-part retry, so large videos upload reliably even
    over flaky/slow links; the server reassembles + validates on completion."""
    token = (cfg["import_token"] or "").strip()
    base = (cfg["server_base"] or "").rstrip("/")
    chunk_url = base + "/webhooks/import/gallery/%d/files/chunk" % gid
    complete_url = base + "/webhooks/import/gallery/%d/files/chunk/complete" % gid
    uid = uuid.uuid4().hex
    size = os.path.getsize(path)
    total = max(1, (size + CHUNK_SIZE - 1) // CHUNK_SIZE)

    with open(path, "rb") as fh:
        for index in range(total):
            data = fh.read(CHUNK_SIZE)
            if not data:
                break
            fields = {
                "upload_uid": uid,
                "chunk_index": str(index),
                "total_chunks": str(total),
            }
            status, resp = _http_retry(chunk_url, token, fields, None, 600, 3, raw=data)
            if status not in (200, 201) or not resp.get("ok"):
                raise RuntimeError("chunk %d/%d: HTTP %d: %s" %
                                   (index, total, status, resp.get("error") or resp))
            _set_progress(file_bytes=min(size, (index + 1) * CHUNK_SIZE))

    fields = {
        "upload_uid": uid,
        "original_name": original_name,
        "total_chunks": str(total),
    }
    status, resp = _http_retry(complete_url, token, fields, None, 1200, 3)
    if status not in (200, 201) or not resp.get("ok"):
        raise RuntimeError("chunk complete: HTTP %d: %s" % (status, resp.get("error") or resp))
    return resp


def post_gallery(cfg, title, gtype, slot, files):
    """Create the scheduled gallery (metadata only), then upload each file in
    its own request so no single upload is huge (avoids server input timeouts
    on large folders / long videos)."""
    base = (cfg["server_base"] or "").rstrip("/")
    token = (cfg["import_token"] or "").strip()
    fields = {
        "title": title,
        "type": gtype,
        "published_at": slot,
        "description": str(cfg.get("description") or ""),
        "min_level": str(int(cfg.get("min_level") or 0)),
        "is_secret": "1" if cfg.get("is_secret") else "0",
    }
    status, data = _http_retry(base + "/webhooks/import/gallery", token, fields, None, timeout=60)
    if status not in (200, 201) or not data.get("ok"):
        return {"ok": False, "error": "create HTTP %d: %s" % (status, data.get("error") or data)}
    gid = int(data.get("gallery_id") or 0)
    if not gid:
        return {"ok": False, "error": "create returned no gallery_id"}

    uploaded = 0
    errors = []
    for idx, fname in enumerate(files, 1):
        try:
            fsize = os.path.getsize(fname)
            _set_progress(file=os.path.basename(fname), file_index=idx,
                          files_total=len(files), file_bytes=0, file_total=fsize,
                          stage="uploading",
                          message="uploading %s" % os.path.basename(fname))
            if fsize >= CHUNK_MIN:
                # Large file → resumable chunked upload (many small requests).
                data = _upload_chunked(cfg, gid, fname, os.path.basename(fname))
                status, ok = 201, bool(data.get("ok"))
            else:
                status, data = _http_retry(base + "/webhooks/import/gallery/%d/files" % gid, token,
                                           None, [fname], timeout=900)
                ok = data.get("ok")
        except Exception as exc:
            errors.append("%s: %s" % (os.path.basename(fname), exc))
            continue

        if status in (200, 201) and ok:
            uploaded += 1
        else:
            errors.append("%s: HTTP %d: %s" % (os.path.basename(fname), status, data.get("error") or data))

    if errors:
        # Partial failure: report it as a failure so the folder stays put and
        # the real per-file error is logged. A re-run resumes the same gallery
        # (idempotent by title+type) and uploads the missing files.
        return {"ok": False, "error": "%s (uploaded %d of %d)" %
                (errors[0], uploaded, len(files)), "gallery_id": gid, "uploaded": uploaded}
    return {"ok": True, "gallery_id": gid, "url": str(data.get("url") or ""), "uploaded": uploaded}


def move_to_posted(folder, posted):
    if not posted:
        return
    if not os.path.isdir(posted):
        os.makedirs(posted)
    name = os.path.basename(os.path.normpath(folder))
    dest = os.path.join(posted, name)
    if os.path.exists(dest):
        dest = os.path.join(posted, name + "_" + datetime.now().strftime("%Y%m%d%H%M%S"))
    shutil.move(folder, dest)
    return dest


def run_once(cfg=None):
    """Scan the host folder, create + schedule galleries, move folders to posted.

    Returns a result dict; raises only on unrecoverable config problems.
    Settings are pulled from the site first (editable on the gallery management
    page), falling back to the local config when the site is unreachable.
    """
    cfg = cfg or read_config()
    cfg = pull_site_settings(cfg)
    with _LOCK:
        return _run(cfg)


def pull_site_settings(cfg):
    """Merge the import settings stored on the site (gallery management page)
    into the local config. The site is the source of truth when reachable;
    host/posted folders are resolved per machine (cfg['machine']). Local
    host/posted are kept when the site has no entry for this machine."""
    base = (cfg.get("server_base") or "").rstrip("/")
    token = (cfg.get("import_token") or "").strip()
    if not base or not token:
        return cfg
    try:
        machine = str(cfg.get("machine") or socket.gethostname()).strip()
        url = base + "/webhooks/import/settings"
        if machine:
            url += "?machine=" + urlparse.quote(machine)
        status, data = _http_json(url, token, timeout=30)
        if status == 200 and data.get("ok"):
            settings = data.get("settings") or {}
            for key in ("import_token", "spacing_hours", "min_level", "description",
                        "is_secret", "enabled", "schedule", "interval_minutes"):
                if key in settings and settings[key] not in (None, ""):
                    cfg[key] = settings[key]
            # Machine-resolved host/posted override local only when present.
            if settings.get("host_folder"):
                cfg["host_folder"] = settings["host_folder"]
            if settings.get("posted_folder"):
                cfg["posted_folder"] = settings["posted_folder"]
    except Exception:
        pass
    return cfg


def _run(cfg):
    result = {"ok": True, "galleries": [], "imported_folders": [], "errors": []}
    host = (cfg.get("host_folder") or "").strip()
    if not host or not os.path.isdir(host):
        raise RuntimeError("host_folder does not exist: %r" % host)
    posted = (cfg.get("posted_folder") or "").strip() or os.path.join(host, "posted")

    _set_progress(running=True, stage="queue", folder="", gallery_type="",
                  file="", file_index=0, files_total=0, file_bytes=0, file_total=0,
                  message="looking up next publish slot",
                  started_at=datetime.now().strftime("%Y-%m-%d %H:%M:%S"))

    slot = ""
    try:
        slot = fetch_next_slot(cfg)
    except Exception as exc:
        result["ok"] = False
        result["errors"].append("queue: %s" % exc)
        _log(cfg, "import run failed before posting: %s" % exc)
        _set_progress(running=False, stage="error", message="queue lookup failed: %s" % exc)
        _write_status(cfg, result, slot)
        return result

    result["next_slot"] = slot
    _log(cfg, "import run starting; host=%s next_slot=%s" % (host, slot))

    try:
        names = sorted(os.listdir(host))
    except OSError as exc:
        result["ok"] = False
        result["errors"].append(str(exc))
        _set_progress(running=False, stage="error", message="cannot read host folder: %s" % exc)
        _write_status(cfg, result, slot)
        return result

    for name in names:
        folder = os.path.join(host, name)
        if not os.path.isdir(folder) or os.path.abspath(folder) == os.path.abspath(posted):
            continue
        created = []
        failed = False
        _set_progress(folder=name, gallery_type="", file="", file_index=0,
                      files_total=0, file_bytes=0, file_total=0,
                      stage="scan", message="scanning folder")
        try:
            images, videos = bucket_files(folder, cfg)
        except Exception as exc:
            result["errors"].append("%s: scan failed: %s" % (name, exc))
            _log(cfg, "scan failed: %s (%s)" % (name, exc))
            continue

        if not images and not videos:
            result["errors"].append("%s: no recognized image/video files (left in place)" % name)
            _log(cfg, "skipped (no media): %s" % name)
            continue

        for gtype, files in (("images", images), ("videos", videos)):
            if not files:
                continue
            _set_progress(gallery_type=gtype, file="", file_index=0,
                          files_total=len(files), file_bytes=0, file_total=0,
                          stage="uploading", message="posting %s gallery" % gtype)
            try:
                data = post_gallery(cfg, name, gtype, slot, files)
            except Exception as exc:
                data = {"ok": False, "error": str(exc)}
            if data.get("ok"):
                gid = int(data.get("gallery_id") or 0)
                created.append((gtype, gid))
                result["galleries"].append({"folder": name, "type": gtype, "gallery_id": gid})
                _log(cfg, "created %s gallery #%s for %s -> %s" % (gtype, gid, name, slot))
                try:
                    slot = _bump_slot(slot, cfg.get("spacing_hours") or 24)
                except Exception as exc:
                    failed = True
                    result["errors"].append("%s: slot advance failed: %s" % (name, exc))
                    _log(cfg, "slot advance failed for %s: %s" % (name, exc))
                    break
            else:
                failed = True
                msg = "%s/%s: %s" % (name, gtype, data.get("error") or "unknown")
                result["errors"].append(msg)
                _log(cfg, "FAILED " + msg)
                break

        if created and not failed:
            try:
                _set_progress(stage="moving", file="", message="moving folder to posted")
                moved = move_to_posted(folder, posted)
                result["imported_folders"].append(name)
                _log(cfg, "moved %s -> %s" % (name, moved or posted))
            except Exception as exc:
                failed = True
                result["errors"].append("%s: move failed: %s" % (name, exc))
                _log(cfg, "move failed: %s (%s)" % (name, exc))

        # Refresh the status file after every folder so the control UI's stats
        # keep updating even during a long multi-folder run.
        result["next_slot"] = slot
        _write_status(cfg, result, slot)

    result["next_slot"] = slot
    _write_status(cfg, result, slot)
    _set_progress(running=False, stage="done", file="", message="run finished")
    _log(cfg, "import run finished: %d folder(s) imported, %d gallery(s), %d error(s)" %
         (len(result["imported_folders"]), len(result["galleries"]), len(result["errors"])))
    return result


def _write_status(cfg, result, next_slot):
    status_file = cfg.get("status_file") or os.path.join(cfg["host_folder"] or ".", ".gallery_import_status.json")
    try:
        with open(status_file, "w", encoding="utf-8") as fh:
            json.dump({
                "last_run": datetime.now().strftime("%Y-%m-%d %H:%M:%S"),
                "next_slot": next_slot,
                "imported_folders": result.get("imported_folders", []),
                "gallery_count": len(result.get("galleries", [])),
                "errors": result.get("errors", []),
                "ok": bool(result.get("ok")),
            }, fh, indent=2, ensure_ascii=False)
    except Exception:
        pass


def read_status(cfg):
    status_file = cfg.get("status_file") or os.path.join(cfg["host_folder"] or ".", ".gallery_import_status.json")
    try:
        if os.path.isfile(status_file):
            with open(status_file, encoding="utf-8") as fh:
                return json.load(fh)
    except Exception:
        pass
    return {}


def cli():
    ap = argparse.ArgumentParser(description="Gallery folder importer")
    ap.add_argument("--config", help="config JSON path")
    ap.add_argument("--host", help="host folder (overrides config)")
    ap.add_argument("--posted", help="posted folder (overrides config)")
    ap.add_argument("--run", action="store_true", help="run one import pass and exit")
    args = ap.parse_args()

    cfg = read_config(args.config)
    if args.host:
        cfg["host_folder"] = args.host
    if args.posted:
        cfg["posted_folder"] = args.posted
    if not cfg.get("host_folder"):
        cfg["host_folder"] = input("Host folder path: ").strip()
    if not cfg.get("posted_folder"):
        default = os.path.join(cfg["host_folder"], "posted")
        cfg["posted_folder"] = input("Posted folder path [%s]: " % default).strip() or default
    if not cfg.get("import_token"):
        cfg["import_token"] = input("Import token: ").strip()
    if args.config:
        write_config(cfg, args.config)

    if args.run:
        result = run_once(cfg)
        print(json.dumps(result, indent=2, ensure_ascii=False))
        sys.exit(0 if result.get("ok") else 1)

    ap.print_help()


if __name__ == "__main__":
    cli()