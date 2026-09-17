#!/usr/bin/env python3
"""
Chat trainer for the always-on training PC (Phenom II 1100T, 16 GB RAM).

Fetches cleaned chat training pairs from the gallery server, fine-tunes a LoRA
adapter on Llama-3.2-3B using llama.cpp (CPU-only; the GTX 750 Ti's 2 GB VRAM
is too small and the Phenom II lacks AVX2, so we use a baseline llama.cpp
build), then uploads the adapter back to the server.

This runs continuously. It polls the server every POLL_SECONDS, trains when at
least MIN_NEW_PAIRS new clean pairs have accumulated since the last training,
and uploads the finished adapter via the training-upload webhook.

Requirements (install once on the PC):
  - Python 3.10+
  - llama.cpp compiled for this CPU (baseline/no-AVX build) with the LoRA
    trainer: https://github.com/ggml-org/llama.cpp  (see README notes)
  - The base model GGUF: llama-3.2-3b-instruct-q4_k_m.gguf
  - requests:  pip install requests

Config: edit the values below or set the same env vars.
"""

import hashlib
import json
import os
import subprocess
import sys
import time
import urllib.request
import urllib.error

# ------------------------------------------------------------------ config
SERVER_BASE = os.environ.get("CHAT_SERVER", "https://amethyst2213.com/gallery")
BRIDGE_TOKEN = os.environ.get("CHAT_BRIDGE_TOKEN", "REPLACE_WITH_GALLERY_CHAT_KEY")
LLAMA_CPP_DIR = os.environ.get("LLAMA_CPP_DIR", os.path.expanduser("~/llama.cpp/build/bin"))
BASE_MODEL_GGUF = os.environ.get("BASE_MODEL_GGUF", os.path.expanduser("~/models/llama-3.2-3b-instruct-q4_k_m.gguf"))
POLL_SECONDS = int(os.environ.get("POLL_SECONDS", "600"))
MIN_NEW_PAIRS = int(os.environ.get("MIN_NEW_PAIRS", "20"))
OUTPUT_LORA = os.environ.get("OUTPUT_LORA", os.path.expanduser("~/chat-lora.gguf"))
STATE_FILE = os.environ.get("STATE_FILE", os.path.expanduser("~/.chat_trainer_state.json"))
MAX_PAIRS_PER_RUN = int(os.environ.get("MAX_PAIRS_PER_RUN", "2000"))

# ------------------------------------------------------------------ helpers
def state() -> dict:
    try:
        with open(STATE_FILE) as fh:
            return json.load(fh)
    except Exception:
        return {"since_id": 0, "trained_pairs": 0}

def save_state(st):
    with open(STATE_FILE, "w") as fh:
        json.dump(st, fh)

def fetch_training_data(since_id: int):
    url = f"{SERVER_BASE}/webhooks/chat/training-data?since_id={since_id}"
    req = urllib.request.Request(url, headers={"Authorization": f"Bearer {BRIDGE_TOKEN}"})
    pairs = []
    with urllib.request.urlopen(req, timeout=60) as resp:
        for line in resp:
            line = line.decode("utf-8").strip()
            if not line:
                continue
            try:
                pairs.append(json.loads(line))
            except json.JSONDecodeError:
                continue
    return pairs

def build_training_file(pairs, path):
    """Write the training pairs in the chat format llama.cpp expects."""
    with open(path, "w", encoding="utf-8") as fh:
        for p in pairs:
            user = p.get("user_message", "")
            reply = p.get("operator_reply", "")
            if not user or not reply:
                continue
            # chat template: <|start_header_id|>user<|end_header_id|> ... etc.
            fh.write(f"<|start_header_id|>user<|end_header_id|>\n\n{user}<|eot_id|>\n")
            fh.write(f"<|start_header_id|>assistant<|end_header_id|>\n\n{reply}<|eot_id|>\n")

def run_training(train_file: str, out_lora: str) -> bool:
    """llama.cpp LoRA fine-tune (CPU). Returns True on success."""
    train_bin = os.path.join(LLAMA_CPP_DIR, "llama-train")
    if not os.path.isfile(train_bin):
        print(f"[chat-trainer] llama-train not found at {train_bin}")
        return False
    if not os.path.isfile(BASE_MODEL_GGUF):
        print(f"[chat-trainer] base model not found at {BASE_MODEL_GGUF}")
        return False

    cmd = [
        train_bin,
        "--model", BASE_MODEL_GGUF,
        "--train-data", train_file,
        "--lora-out", out_lora,
        "--threads", "6",
        "--epochs", "1",
        "--batch-size", "4",
    ]
    print(f"[chat-trainer] running: {' '.join(cmd)}")
    proc = subprocess.run(cmd)
    return proc.returncode == 0

def upload_adapter(path: str, base_model: str, pair_count: int) -> bool:
    boundary = "----chatform-" + hashlib.md5(str(time.time()).encode()).hexdigest()
    with open(path, "rb") as fh:
        data = fh.read()
    body = b""
    for field, val in [("base_model", base_model), ("pair_count", str(pair_count))]:
        body += (
            f"--{boundary}\r\nContent-Disposition: form-data; name=\"{field}\"\r\n\r\n"
            f"{val}\r\n".encode("utf-8")
        )
    body += (
        f"--{boundary}\r\nContent-Disposition: form-data; name=\"adapter\"; "
        f"filename=\"chat-lora.gguf\"\r\nContent-Type: application/octet-stream\r\n\r\n"
    ).encode("utf-8")
    body += data
    body += f"\r\n--{boundary}--\r\n".encode("utf-8")

    url = f"{SERVER_BASE}/webhooks/chat/training-upload"
    req = urllib.request.Request(
        url,
        data=body,
        method="POST",
        headers={
            "Authorization": f"Bearer {BRIDGE_TOKEN}",
            "Content-Type": f"multipart/form-data; boundary={boundary}",
        },
    )
    try:
        with urllib.request.urlopen(req, timeout=120) as resp:
            result = json.loads(resp.read().decode("utf-8"))
            print(f"[chat-trainer] upload result: {result}")
            return bool(result.get("ok"))
    except urllib.error.HTTPError as e:
        print(f"[chat-trainer] upload HTTP {e.code}: {e.read().decode('utf-8', 'ignore')}")
        return False

# ------------------------------------------------------------------ main loop
def main():
    if BRIDGE_TOKEN.startswith("REPLACE_WITH"):
        print("[chat-trainer] CHAT_BRIDGE_TOKEN not set — edit the script or export it.")
        sys.exit(1)

    print(f"[chat-trainer] starting (server={SERVER_BASE}, poll={POLL_SECONDS}s, min_pairs={MIN_NEW_PAIRS})")

    while True:
        try:
            st = state()
            since = int(st.get("since_id", 0))
            pairs = fetch_training_data(since)
            print(f"[chat-trainer] fetched {len(pairs)} new pair(s) since id {since}")

            if len(pairs) >= MIN_NEW_PAIRS:
                batch = pairs[:MAX_PAIRS_PER_RUN]
                train_file = os.path.expanduser("~/chat_train.txt")
                build_training_file(batch, train_file)
                out_lora = os.path.expanduser(OUTPUT_LORA)

                if run_training(train_file, out_lora):
                    if upload_adapter(out_lora, "llama3.2:3b", len(batch)):
                        st["since_id"] = int(batch[-1]["id"]) if batch else since
                        st["trained_pairs"] = int(st.get("trained_pairs", 0)) + len(batch)
                        save_state(st)
                        print(f"[chat-trainer] trained + uploaded {len(batch)} pairs")
                    else:
                        print("[chat-trainer] training ok but upload failed; retrying next poll")
                else:
                    print("[chat-trainer] training failed; keeping pairs for retry")
            else:
                # advance watermark only for pairs we will never train on? No:
                # leave since_id so they accumulate until MIN_NEW_PAIRS is met.
                print(f"[chat-trainer] only {len(pairs)} pair(s) — need {MIN_NEW_PAIRS}; waiting")
        except Exception as e:
            print(f"[chat-trainer] error: {e}")

        time.sleep(POLL_SECONDS)

if __name__ == "__main__":
    main()