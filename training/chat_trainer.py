#!/usr/bin/env python3
"""
Chat LoRA trainer for the always-on training PC (Windows 7 / Phenom II).

Fetches cleaned chat training pairs from the gallery server, trains a LoRA
adapter on Llama-3.2-3B using HuggingFace transformers + PEFT (CPU-only),
then uploads the adapter back to the server.

Managed by trainer_control.py (the web control server + supervisor). All
settings are read from C:\\work\\chat_trainer_config.json (env vars and then
built-in defaults are used as fallbacks when a key is missing), so the control
UI's "Save" changes behaviour without editing the launcher .bat.

Run continuously: poll every POLL_SECONDS, train when >= MIN_NEW_PAIRS new
clean pairs have accumulated, upload the finished adapter.
"""

import hashlib
import json
import os
import sys
import time
import urllib.request
import urllib.error

# ------------------------------------------------------------------ config
DEFAULT_CONFIG = {
    "server_base": "https://amethyst2213.com/gallery",
    "bridge_token": "REPLACE_WITH_GALLERY_CHAT_KEY",
    # Per-device operator token used ONLY for training-upload (the server
    # requires it for adapter uploads; the shared key is not enough). Leave
    # empty to fall back to bridge_token when the server still allows it.
    "upload_token": "",
    "model_dir": r"D:\llama-3.2-3b-hf-ab",
    "output_adapter": r"C:\work\chat-lora.safetensors",
    "poll_seconds": 600,
    "min_new_pairs": 20,
    "state_file": r"C:\work\.chat_trainer_state.json",
    "max_pairs_per_run": 2000,
    "lora_r": 8,
    "lora_alpha": 16,
    "lora_dropout": 0.05,
    "max_len": 512,
    "steps": 60,
    "lr": 2e-4,
    "required_idle_seconds": 300,
    "cpu_threads": 0,
    "pause_file": r"C:\work\.chat_trainer_paused",
    "force_train_file": r"C:\work\.chat_trainer_train_now",
    "log_file": r"C:\work\chat_trainer.log",
    "status_file": r"C:\work\.chat_trainer_status.json",
    "base_model": "llama3.2:3b",
}

ENV_MAP = {
    "server_base": "CHAT_SERVER",
    "bridge_token": "CHAT_BRIDGE_TOKEN",
    "upload_token": "CHAT_UPLOAD_TOKEN",
    "model_dir": "MODEL_DIR",
    "output_adapter": "OUTPUT_ADAPTER",
    "poll_seconds": "POLL_SECONDS",
    "min_new_pairs": "MIN_NEW_PAIRS",
    "state_file": "STATE_FILE",
    "max_pairs_per_run": "MAX_PAIRS_PER_RUN",
    "lora_r": "LORA_R",
    "lora_alpha": "LORA_ALPHA",
    "max_len": "MAX_LEN",
    "steps": "STEPS",
    "lr": "LR",
    "required_idle_seconds": "REQUIRED_IDLE_SECONDS",
    "cpu_threads": "CPU_THREADS",
    "pause_file": "PAUSE_FILE",
}

CONFIG_FILE = os.environ.get("CHAT_TRAINER_CONFIG", r"C:\work\chat_trainer_config.json")


def load_config() -> dict:
    """Config precedence: config file > env var > built-in default."""
    cfg = dict(DEFAULT_CONFIG)
    data = {}
    try:
        if os.path.isfile(CONFIG_FILE):
            with open(CONFIG_FILE, encoding="utf-8") as fh:
                data = json.load(fh)
            if not isinstance(data, dict):
                data = {}
    except Exception:
        data = {}
    cfg.update(data)

    for key, env in ENV_MAP.items():
        if env in os.environ and os.environ[env] != "":
            cfg[key] = os.environ[env]

    # Coerce numeric types from strings (file or env). Integer keys stay ints;
    # lr / lora_dropout are floats (int-casting a float would silently zero it).
    for key in ("poll_seconds", "min_new_pairs", "max_pairs_per_run", "lora_r",
                "lora_alpha", "max_len", "steps",
                "required_idle_seconds", "cpu_threads"):
        cfg[key] = _to_num(cfg.get(key), default=DEFAULT_CONFIG.get(key))
    for key in ("lr", "lora_dropout"):
        cfg[key] = _to_num(cfg.get(key), float, default=DEFAULT_CONFIG.get(key))
    cfg["bridge_token"] = str(cfg.get("bridge_token", "")).strip()
    cfg["upload_token"] = str(cfg.get("upload_token", "")).strip()
    return cfg


def _to_num(value, cast=int, default=None):
    if default is None:
        default = DEFAULT_CONFIG.get("poll_seconds", 600) if cast is int else 2e-4
    try:
        return cast(value)
    except (TypeError, ValueError):
        return default


CFG = load_config()

SERVER_BASE = str(CFG["server_base"]).rstrip("/")
BRIDGE_TOKEN = CFG["bridge_token"]
UPLOAD_TOKEN = CFG["upload_token"]
MODEL_DIR = CFG["model_dir"]
OUTPUT_ADAPTER = CFG["output_adapter"]
POLL_SECONDS = CFG["poll_seconds"]
MIN_NEW_PAIRS = CFG["min_new_pairs"]
STATE_FILE = CFG["state_file"]
MAX_PAIRS_PER_RUN = CFG["max_pairs_per_run"]
LORA_R = CFG["lora_r"]
LORA_ALPHA = CFG["lora_alpha"]
LORA_DROPOUT = CFG["lora_dropout"]
MAX_LEN = CFG["max_len"]
STEPS = CFG["steps"]
LR = CFG["lr"]
REQUIRED_IDLE_SECONDS = CFG["required_idle_seconds"]
CPU_THREADS = CFG["cpu_threads"]
PAUSE_FILE = CFG["pause_file"]
FORCE_TRAIN_FILE = CFG["force_train_file"]
LOG_FILE = CFG["log_file"]
STATUS_FILE = CFG["status_file"]
BASE_MODEL = str(CFG["base_model"])

# Populated by train_adapter(); sent to the server so the adapter directory is
# self-contained (Ollama 0.33.x ADAPTER needs adapter_config.json + config.json).
ADAPTER_CONFIG_JSON = ""
BASE_CONFIG_JSON = ""

# Base model architecture metadata for the server's config.json. Must match the
# server's llama3.2:3b (28 layers, 24 heads, 8 KV heads, 3072 hidden).
BASE_CONFIG = {
    "architectures": ["LlamaForCausalLM"],
    "model_type": "llama",
    "hidden_size": 3072,
    "intermediate_size": 8192,
    "num_hidden_layers": 28,
    "num_attention_heads": 24,
    "num_key_value_heads": 8,
    "vocab_size": 128256,
    "max_position_embeddings": 4096,
    "rms_norm_eps": 1e-05,
    "rope_theta": 500000.0,
    "tie_word_embeddings": True,
}

# ------------------------------------------------------------------ logging
def _log(msg: str):
    line = "[%s] %s" % (time.strftime("%Y-%m-%d %H:%M:%S"), msg)
    print(line, flush=True)
    try:
        with open(LOG_FILE, "a", encoding="utf-8") as fh:
            fh.write(line + "\n")
    except Exception:
        pass


def write_status(extra: dict = None):
    """Publish live state for the control UI (C:\\work\\.chat_trainer_status.json)."""
    status = {
        "pid": os.getpid(),
        "running": True,
        "paused": is_paused(),
        "idle_seconds": idle_seconds(),
        "last_poll_at": time.strftime("%Y-%m-%d %H:%M:%S"),
        "config_file": CONFIG_FILE,
    }
    try:
        st = state()
        status["since_id"] = int(st.get("since_id", 0))
        status["trained_pairs"] = int(st.get("trained_pairs", 0))
    except Exception:
        pass
    if isinstance(extra, dict):
        status.update(extra)
    try:
        with open(STATUS_FILE, "w") as fh:
            json.dump(status, fh)
    except Exception:
        pass


class ProgressCallback:
    """Report live LoRA training progress (step/total/loss) into the status
    file so the desktop UI can show a real progress bar."""

    def __init__(self):
        self.total = 0

    def on_train_begin(self, args, state, control, **kwargs):
        self.total = int(getattr(state, "max_steps", 0) or 0)
        write_status({"phase": "training", "progress": {"step": 0, "total": self.total, "pct": 0.0, "loss": None}})

    def on_init_end(self, args, state, control, **kwargs):
        self.total = int(getattr(state, "max_steps", 0) or 0)
        write_status({"phase": "training", "progress": {"step": 0, "total": self.total, "pct": 0.0, "loss": None}})

    def on_log(self, args, state, control, logs=None, **kwargs):
        step = int(getattr(state, "global_step", 0) or 0)
        loss = None
        if isinstance(logs, dict):
            loss = logs.get("loss") or logs.get("train_loss")
            if loss is not None:
                try:
                    loss = float(loss)
                except (TypeError, ValueError):
                    loss = None
        total = self.total or int(getattr(state, "max_steps", 0) or 0)
        pct = (step / total) if total else 0.0
        write_status({
            "phase": "training",
            "progress": {"step": step, "total": total, "pct": pct, "loss": loss},
        })

    def on_step_end(self, args, state, control, **kwargs):
        step = int(getattr(state, "global_step", 0) or 0)
        total = self.total or int(getattr(state, "max_steps", 0) or 0)
        pct = (step / total) if total else 0.0
        write_status({
            "phase": "training",
            "progress": {"step": step, "total": total, "pct": pct, "loss": None},
        })

    def on_epoch_begin(self, args, state, control, **kwargs):
        pass

    def on_epoch_end(self, args, state, control, **kwargs):
        pass

    def on_train_end(self, args, state, control, **kwargs):
        write_status({"phase": "idle", "progress": None})

    def on_step_begin(self, args, state, control, **kwargs):
        pass

    def on_substep_end(self, args, state, control, **kwargs):
        pass

    def on_save(self, args, state, control, **kwargs):
        pass

    def on_optimizer_step(self, args, state, control, optimizer=None, **kwargs):
        pass

    def on_optimizer_end(self, args, state, control, **kwargs):
        pass

    def on_evaluate(self, args, state, control, metrics=None, **kwargs):
        pass

    def on_predict(self, args, state, control, metrics=None, **kwargs):
        pass

    def on_preprocess_data(self, args, state, control, **kwargs):
        pass


# ------------------------------------------------------------------ helpers
def idle_seconds() -> int:
    """Seconds since the last keyboard/mouse input (Windows). On non-Windows
    or when the check fails, treat the machine as idle (0) so training can
    proceed (e.g. in CI or under a service account)."""
    if os.name != "nt":
        return 0
    try:
        import ctypes
        class LASTINPUTINFO(ctypes.Structure):
            _fields_ = [("cbSize", ctypes.c_uint), ("dwTime", ctypes.c_uint)]
        info = LASTINPUTINFO()
        info.cbSize = ctypes.sizeof(LASTINPUTINFO)
        if not ctypes.windll.user32.GetLastInputInfo(ctypes.byref(info)):
            return 0
        millis = ctypes.windll.kernel32.GetTickCount() - info.dwTime
        return int(millis // 1000)
    except Exception:
        return 0


def is_paused() -> bool:
    """True when the pause file exists (manual stop)."""
    return PAUSE_FILE != "" and os.path.exists(PAUSE_FILE)


def force_train_requested() -> bool:
    """True when the control UI asked for an immediate training round."""
    return FORCE_TRAIN_FILE != "" and os.path.exists(FORCE_TRAIN_FILE)


def consume_force_train() -> bool:
    """Consume the force-train marker and return whether it was set."""
    if FORCE_TRAIN_FILE and os.path.exists(FORCE_TRAIN_FILE):
        try:
            os.remove(FORCE_TRAIN_FILE)
        except Exception:
            pass
        return True
    return False


def can_start_training() -> str:
    """Return '' when a training round may start now, else a reason string."""
    if is_paused():
        return "paused (pause file present)"
    if force_train_requested():
        return ""  # operator override: train now regardless of idle
    if REQUIRED_IDLE_SECONDS > 0:
        idle = idle_seconds()
        if idle < REQUIRED_IDLE_SECONDS:
            return "PC busy (idle %ds, need %ds)" % (idle, REQUIRED_IDLE_SECONDS)
    return ""


def apply_cpu_budget():
    """Limit torch to CPU_THREADS cores (0 = half the physical cores), so
    training never starves other apps on the shared PC."""
    try:
        import torch
        physical = getattr(torch, "get_num_physical_cores", lambda: None)() or os.cpu_count() or 1
        threads = CPU_THREADS if CPU_THREADS > 0 else max(1, int(physical) // 2)
        torch.set_num_threads(threads)
        _log("torch threads = %d (physical %d)" % (threads, physical))
    except Exception as e:
        _log("could not set torch threads: %s" % e)


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


def report_watermark(since_id: int, trained_pairs: int = 0):
    """Tell the server the highest pair id we have consumed plus how many pairs
    we have actually trained and uploaded. The server shows "pairs waiting to
    be trained" = cleaned pairs above the id, and "pairs trained" = the real
    trained counter, live on the website and the desktop UI. Best-effort;
    failures are logged but never break the poll loop."""
    try:
        url = f"{SERVER_BASE}/webhooks/chat/training-progress"
        body = json.dumps({"since_id": int(since_id), "trained_pairs": int(trained_pairs)}).encode("utf-8")
        req = urllib.request.Request(
            url, data=body, method="POST",
            headers={"Authorization": f"Bearer {BRIDGE_TOKEN}",
                     "Content-Type": "application/json"},
        )
        with urllib.request.urlopen(req, timeout=30) as resp:
            result = json.loads(resp.read().decode("utf-8"))
            _log("reported watermark since_id=%d trained=%d -> %s" % (int(since_id), int(trained_pairs), result.get("ok")))
    except Exception as e:
        _log("watermark report failed (ignored): %s" % e)


JUNK_WORDS = {
    "test", "testing", "tests", "dbg", "dbg-test", "sup", "hi", "hello", "hey",
    "h", "asdf", "khgkghkjghkjhg", "hdjdjfjfj", "pic", "tits", "pussy",
}


def _is_junk(text: str) -> bool:
    """Heuristic junk filter: empty, tiny, or pure test/placeholder text."""
    t = text.strip().lower()
    if not t:
        return True
    if len(t) <= 2 and not t.isalnum():
        return True
    words = t.split()
    if len(words) <= 1 and (t in JUNK_WORDS or t.isdigit()):
        return True
    return False


# The static system persona ChatAi sends at inference. Prepended inside the
# user turn of every training record so trained examples match inference format
# (the model sees the same instruction during training and at chat time).
SYSTEM_PERSONA = (
    "You are the chat assistant for an adult content gallery site. Be warm, "
    "flirty, and human. Stay in character and respond naturally. Never break "
    "character. Keep replies under 2000 characters."
)


def build_training_records(pairs):
    """Convert pairs to list of {text: <chat-format>} records, dropping junk."""
    records = []
    for p in pairs:
        user = (p.get("user_message") or "").strip()
        reply = (p.get("operator_reply") or "").strip()
        if not user or not reply:
            continue
        if _is_junk(user) or _is_junk(reply):
            continue
        text = (
            "<|start_header_id|>user<|end_header_id|>\n\n"
            + SYSTEM_PERSONA
            + "\n\n"
            + user
            + "<|eot_id|>\n<|start_header_id|>assistant<|end_header_id|>\n\n"
            + reply
            + "<|eot_id|>"
        )
        records.append({"text": text})
    return records


def train_adapter(records, out_path):
    """Train a LoRA with transformers + PEFT on CPU. Returns True on success."""
    import torch
    from transformers import AutoModelForCausalLM, AutoTokenizer, TrainingArguments, Trainer
    from peft import LoraConfig, get_peft_model
    from datasets import Dataset

    apply_cpu_budget()

    _log("loading model from %s" % MODEL_DIR)
    tokenizer = AutoTokenizer.from_pretrained(MODEL_DIR)
    if tokenizer.pad_token is None:
        tokenizer.pad_token = tokenizer.eos_token

    model = AutoModelForCausalLM.from_pretrained(
        MODEL_DIR, torch_dtype=torch.float32, low_cpu_mem_usage=True
    )
    _log("model loaded, params=%s" % model.num_parameters())

    peft_config = LoraConfig(
        r=LORA_R, lora_alpha=LORA_ALPHA, target_modules=["q_proj", "k_proj", "v_proj", "o_proj"],
        lora_dropout=LORA_DROPOUT, bias="none", task_type="CAUSAL_LM",
    )
    model = get_peft_model(model, peft_config)
    model.print_trainable_parameters()

    ds = Dataset.from_list(records)

    def tokenize(ex):
        out = tokenizer(ex["text"], truncation=True, max_length=MAX_LEN, padding="max_length")
        out["labels"] = out["input_ids"].copy()
        return out

    ds = ds.map(tokenize)

    args = TrainingArguments(
        output_dir=r"C:\work\peft-out",
        num_train_epochs=1,
        per_device_train_batch_size=1,
        gradient_accumulation_steps=1,
        learning_rate=LR,
        max_steps=STEPS,
        logging_steps=1,
        save_strategy="no",
        report_to=[],
        use_cpu=True,
        dataloader_pin_memory=False,
    )
    trainer = Trainer(model=model, args=args, train_dataset=ds, callbacks=[ProgressCallback()])
    trainer.train()
    write_status({"phase": "idle", "progress": None})

    model.save_pretrained(r"C:\work\peft-out\final")
    tokenizer.save_pretrained(r"C:\work\peft-out\final")

    import shutil
    src = r"C:\work\peft-out\final\adapter_model.safetensors"
    if not os.path.isfile(src):
        _log("adapter_model.safetensors not found")
        return False
    shutil.copyfile(src, out_path)

    global ADAPTER_CONFIG_JSON, BASE_CONFIG_JSON
    try:
        with open(r"C:\work\peft-out\final\adapter_config.json", encoding="utf-8") as fh:
            ADAPTER_CONFIG_JSON = fh.read()
    except Exception:
        ADAPTER_CONFIG_JSON = ""
    BASE_CONFIG_JSON = json.dumps(BASE_CONFIG)
    _log("adapter saved to %s" % out_path)
    return True


def upload_adapter(path: str, base_model: str, pair_count: int, since_id: int = 0, trained_pairs: int = 0) -> bool:
    boundary = "----chatform-" + hashlib.md5(str(time.time()).encode()).hexdigest()
    with open(path, "rb") as fh:
        data = fh.read()
    body = b""

    def add_field(name, value):
        nonlocal body
        body += (
            f"--{boundary}\r\nContent-Disposition: form-data; name=\"{name}\"\r\n\r\n"
            f"{value}\r\n".encode("utf-8")
        )

    add_field("base_model", base_model)
    add_field("pair_count", str(pair_count))
    # Absolute counters (idempotent on the server): the highest consumed pair
    # id and the cumulative trained count. Sent with the upload so the website
    # reflects a finished training round even if the next watermark poll is
    # delayed or never arrives.
    if since_id:
        add_field("since_id", str(since_id))
    if trained_pairs:
        add_field("trained_pairs", str(trained_pairs))
    if ADAPTER_CONFIG_JSON:
        add_field("adapter_config", ADAPTER_CONFIG_JSON)
    if BASE_CONFIG_JSON:
        add_field("base_config", BASE_CONFIG_JSON)
    body += (
        f"--{boundary}\r\nContent-Disposition: form-data; name=\"adapter\"; "
        f"filename=\"chat-lora.safetensors\"\r\nContent-Type: application/octet-stream\r\n\r\n"
    ).encode("utf-8")
    body += data
    body += f"\r\n--{boundary}--\r\n".encode("utf-8")

    url = f"{SERVER_BASE}/webhooks/chat/training-upload"
    # Adapter uploads rebuild the fine-tuned model, so the server requires a
    # per-device operator token here (the shared bridge key is not accepted).
    upload_auth = UPLOAD_TOKEN or BRIDGE_TOKEN
    req = urllib.request.Request(
        url, data=body, method="POST",
        headers={"Authorization": f"Bearer {upload_auth}",
                 "Content-Type": f"multipart/form-data; boundary={boundary}"},
    )
    try:
        with urllib.request.urlopen(req, timeout=180) as resp:
            result = json.loads(resp.read().decode("utf-8"))
            _log("upload result: %s" % result)
            return bool(result.get("ok"))
    except urllib.error.HTTPError as e:
        _log("upload HTTP %s: %s" % (e.code, e.read().decode("utf-8", "ignore")))
        return False


# ------------------------------------------------------------------ main loop
def main():
    # The control server launches us with stdout=PIPE but never drains it, so
    # once transformers/tqdm fill the pipe buffer (64K) we block forever on a
    # write. Redirect our own stdout/stderr to the log file so the pipe stays
    # empty and training can never deadlock on it.
    try:
        fh = open(LOG_FILE, "a", encoding="utf-8", buffering=1)
        os.dup2(fh.fileno(), sys.stdout.fileno())
        os.dup2(fh.fileno(), sys.stderr.fileno())
    except Exception:
        pass

    if BRIDGE_TOKEN.startswith("REPLACE_WITH"):
        _log("CHAT_BRIDGE_TOKEN not set - edit the control UI or config file.")
        sys.exit(1)

    _log("starting (server=%s, poll=%ds, min_pairs=%d)" % (SERVER_BASE, POLL_SECONDS, MIN_NEW_PAIRS))
    _log("sharing PC: require_idle=%ds, pause_file=%s" % (REQUIRED_IDLE_SECONDS, PAUSE_FILE or "(none)"))

    while True:
        try:
            st = state()
            since = int(st.get("since_id", 0))
            pairs = fetch_training_data(since)
            _log("fetched %d new pair(s) since id %d" % (len(pairs), since))
            write_status({"last_poll": len(pairs)})
            report_watermark(since, int(st.get("trained_pairs", 0)))

            if len(pairs) >= MIN_NEW_PAIRS:
                blocked = can_start_training()
                if blocked:
                    _log("deferring training (%s); pairs kept" % blocked)
                    write_status({"defer_reason": blocked})
                    time.sleep(POLL_SECONDS)
                    continue

                forced = consume_force_train()
                batch = pairs[:MAX_PAIRS_PER_RUN]
                records = build_training_records(batch)
                _log("training on %d records%s" % (len(records), " (operator force-train)" if forced else ""))
                write_status({"phase": "training", "records": len(records)})

                if train_adapter(records, OUTPUT_ADAPTER):
                    new_since = int(batch[-1]["id"]) if batch else since
                    new_trained = int(st.get("trained_pairs", 0)) + len(batch)
                    if upload_adapter(OUTPUT_ADAPTER, BASE_MODEL, len(batch), new_since, new_trained):
                        st["since_id"] = new_since
                        st["trained_pairs"] = new_trained
                        save_state(st)
                        _log("trained + uploaded %d pairs" % len(batch))
                        write_status({"phase": "idle", "last_train": len(batch)})
                    else:
                        _log("training ok but upload failed; retrying next poll")
                        write_status({"phase": "idle", "upload_failed": True})
                else:
                    _log("training failed; keeping pairs for retry")
                    write_status({"phase": "idle", "train_failed": True})
            else:
                _log("only %d pair(s) - need %d; waiting" % (len(pairs), MIN_NEW_PAIRS))
                write_status({"phase": "idle"})
        except Exception as e:
            import traceback
            _log("error: %s" % e)
            traceback.print_exc()
            write_status({"phase": "error", "error": str(e)})

        time.sleep(POLL_SECONDS)


if __name__ == "__main__":
    main()