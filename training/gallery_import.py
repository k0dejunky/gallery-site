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
import sys
import threading
import time
import traceback
import uuid
from datetime import datetime, timedelta
from urllib import request as urlrequest
from urllib import error as urlerror

IMAGE_EXT = ("jpg", "jpeg", "png", "gif", "webp", "bmp", "heic", "heif", "avif", "tiff")
VIDEO_EXT = ("mp4", "webm", "mov", "m4v", "ogg", "avi", "mkv", "3gp", "3g2", "mpg", "mpeg",
             "wmv", "flv", "ts", "mts", "m2ts", "vob", "asf")

DEFAULT_CONFIG = {
    "host_folder": "",           # set at load (folder picker / --host / config)
    "posted_folder": "",         # default: <host_folder>/posted
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
    """Multipart POST (or GET) to the import API; returns (status, parsed)."""
    if files is None:
        req = urlrequest.Request(url, headers={"Authorization": "Bearer " + token})
        with urlrequest.urlopen(req, timeout=timeout) as resp:
            return resp.status, json.loads(resp.read().decode("utf-8") or "{}")

    boundary = "----galleryImport" + uuid.uuid4().hex
    parts = []
    for key, value in (fields or {}).items():
        parts.append(
            ("--%s\r\nContent-Disposition: form-data; name=\"%s\"\r\n\r\n%s\r\n"
             % (boundary, key, value)).encode("utf-8"))
    for fname in files:
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
        url, data=body,
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
    images, videos = [], []
    image_ext = set(cfg.get("image_ext") or IMAGE_EXT)
    video_ext = set(cfg.get("video_ext") or VIDEO_EXT)
    for name in sorted(os.listdir(folder)):
        full = os.path.join(folder, name)
        if not os.path.isfile(full):
            continue
        ext = (name.rsplit(".", 1)[-1] if "." in name else "").lower()
        if ext in image_ext:
            images.append(full)
        elif ext in video_ext:
            videos.append(full)
    return images, videos


def post_gallery(cfg, title, gtype, slot, files):
    fields = {
        "title": title,
        "type": gtype,
        "published_at": slot,
        "description": str(cfg.get("description") or ""),
        "min_level": str(int(cfg.get("min_level") or 0)),
        "is_secret": "1" if cfg.get("is_secret") else "0",
    }
    status, data = _http_json(
        (cfg["server_base"].rstrip("/")) + "/webhooks/import/gallery",
        (cfg["import_token"] or "").strip(), fields, files)
    if status not in (200, 201):
        return {"ok": False, "error": "HTTP %d: %s" % (status, data.get("error") or data)}
    if not data.get("ok"):
        return {"ok": False, "error": str(data.get("error") or "import failed")}
    return data


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
    """
    cfg = cfg or read_config()
    with _LOCK:
        return _run(cfg)


def _run(cfg):
    result = {"ok": True, "galleries": [], "imported_folders": [], "errors": []}
    host = (cfg.get("host_folder") or "").strip()
    if not host or not os.path.isdir(host):
        raise RuntimeError("host_folder does not exist: %r" % host)
    posted = (cfg.get("posted_folder") or "").strip() or os.path.join(host, "posted")

    try:
        slot = fetch_next_slot(cfg)
    except Exception as exc:
        result["ok"] = False
        result["errors"].append("queue: %s" % exc)
        _log(cfg, "import run failed before posting: %s" % exc)
        return result

    result["next_slot"] = slot
    _log(cfg, "import run starting; host=%s next_slot=%s" % (host, slot))

    try:
        names = sorted(os.listdir(host))
    except OSError as exc:
        result["ok"] = False
        result["errors"].append(str(exc))
        return result

    for name in names:
        folder = os.path.join(host, name)
        if not os.path.isdir(folder) or os.path.abspath(folder) == os.path.abspath(posted):
            continue
        images, videos = bucket_files(folder, cfg)
        if not images and not videos:
            result["errors"].append("%s: no recognized image/video files (left in place)" % name)
            _log(cfg, "skipped (no media): %s" % name)
            continue

        created = []
        failed = False
        for gtype, files in (("images", images), ("videos", videos)):
            if not files:
                continue
            try:
                data = post_gallery(cfg, name, gtype, slot, files)
            except Exception as exc:
                data = {"ok": False, "error": str(exc)}
            if data.get("ok"):
                gid = int(data.get("gallery_id") or 0)
                created.append((gtype, gid))
                result["galleries"].append({"folder": name, "type": gtype, "gallery_id": gid})
                _log(cfg, "created %s gallery #%s for %s -> %s" % (gtype, gid, name, slot))
                slot = _bump_slot(slot, cfg.get("spacing_hours") or 24)
            else:
                failed = True
                msg = "%s/%s: %s" % (name, gtype, data.get("error") or "unknown")
                result["errors"].append(msg)
                _log(cfg, "FAILED " + msg)
                break

        if created and not failed:
            moved = move_to_posted(folder, posted)
            result["imported_folders"].append(name)
            _log(cfg, "moved %s -> %s" % (name, moved or posted))

    result["next_slot"] = slot
    _write_status(cfg, result, slot)
    _log(cfg, "import run finished: %d folder(s) imported, %d gallery(s)" %
         (len(result["imported_folders"]), len(result["galleries"])))
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