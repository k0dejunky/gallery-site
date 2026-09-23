#!/usr/bin/env python3
"""
Chat LoRA trainer for the always-on training PC (Windows 7 / Phenom II).

Fetches cleaned chat training pairs from the gallery server, trains a LoRA
adapter on Llama-3.2-3B using HuggingFace transformers + PEFT (CPU-only),
then uploads the adapter back to the server.

The server rebuilds its Ollama fine-tuned model from the uploaded adapter
(Modelfile ADAPTER directive), so the adapter is saved as adapter_model.
safetensors and uploaded as chat-lora.safetensors.

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
SERVER_BASE = os.environ.get("CHAT_SERVER", "https://amethyst2213.com/gallery")
BRIDGE_TOKEN = os.environ.get("CHAT_BRIDGE_TOKEN", "REPLACE_WITH_GALLERY_CHAT_KEY")
MODEL_DIR = os.environ.get("MODEL_DIR", r"D:\llama-3.2-3b-hf-ab")
OUTPUT_ADAPTER = os.environ.get("OUTPUT_ADAPTER", r"C:\work\chat-lora.safetensors")
POLL_SECONDS = int(os.environ.get("POLL_SECONDS", "600"))
MIN_NEW_PAIRS = int(os.environ.get("MIN_NEW_PAIRS", "20"))
STATE_FILE = os.environ.get("STATE_FILE", r"C:\work\.chat_trainer_state.json")
MAX_PAIRS_PER_RUN = int(os.environ.get("MAX_PAIRS_PER_RUN", "2000"))
LORA_R = int(os.environ.get("LORA_R", "8"))
LORA_ALPHA = int(os.environ.get("LORA_ALPHA", "16"))
MAX_LEN = int(os.environ.get("MAX_LEN", "512"))
STEPS = int(os.environ.get("STEPS", "60"))
LR = float(os.environ.get("LR", "2e-4"))

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

JUNK_WORDS = {
    "test", "testing", "tests", "dbg", "dbg-test", "sup", "hi", "hello", "hey",
    "h", "asdf", "khgkghkjghkjhg", "hdjdjfjfj", "pic", "tits", "pussy",
}

def _is_junk(text: str) -> bool:
    """Heuristic junk filter: empty, tiny, or pure test/placeholder text."""
    t = text.strip().lower()
    if not t:
        return True
    # single emoji / symbol-only replies
    if len(t) <= 2 and not t.isalnum():
        return True
    words = t.split()
    if len(words) <= 1 and (t in JUNK_WORDS or t.isdigit()):
        return True
    return False

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
    from peft import LoraConfig, get_peft_model, prepare_model_for_kbit_training
    from datasets import Dataset

    print(f"[chat-trainer] loading model from {MODEL_DIR}", flush=True)
    tokenizer = AutoTokenizer.from_pretrained(MODEL_DIR)
    if tokenizer.pad_token is None:
        tokenizer.pad_token = tokenizer.eos_token

    model = AutoModelForCausalLM.from_pretrained(
        MODEL_DIR, torch_dtype=torch.float32, low_cpu_mem_usage=True
    )
    print("[chat-trainer] model loaded, params=", model.num_parameters(), flush=True)

    peft_config = LoraConfig(
        r=LORA_R, lora_alpha=LORA_ALPHA, target_modules=["q_proj", "k_proj", "v_proj", "o_proj"],
        lora_dropout=0.05, bias="none", task_type="CAUSAL_LM",
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
    trainer = Trainer(model=model, args=args, train_dataset=ds)
    trainer.train()

    model.save_pretrained(r"C:\work\peft-out\final")
    tokenizer.save_pretrained(r"C:\work\peft-out\final")

    import shutil
    src = r"C:\work\peft-out\final\adapter_model.safetensors"
    if not os.path.isfile(src):
        print("[chat-trainer] adapter_model.safetensors not found", flush=True)
        return False
    shutil.copyfile(src, out_path)

    # Read the PEFT metadata for the server webhook (adapter_config.json).
    global ADAPTER_CONFIG_JSON, BASE_CONFIG_JSON
    try:
        with open(r"C:\work\peft-out\final\adapter_config.json", encoding="utf-8") as fh:
            ADAPTER_CONFIG_JSON = fh.read()
    except Exception:
        ADAPTER_CONFIG_JSON = ""
    BASE_CONFIG_JSON = json.dumps(BASE_CONFIG)
    print(f"[chat-trainer] adapter saved to {out_path}", flush=True)
    return True

def upload_adapter(path: str, base_model: str, pair_count: int) -> bool:
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
    req = urllib.request.Request(
        url, data=body, method="POST",
        headers={"Authorization": f"Bearer {BRIDGE_TOKEN}",
                 "Content-Type": f"multipart/form-data; boundary={boundary}"},
    )
    try:
        with urllib.request.urlopen(req, timeout=180) as resp:
            result = json.loads(resp.read().decode("utf-8"))
            print(f"[chat-trainer] upload result: {result}", flush=True)
            return bool(result.get("ok"))
    except urllib.error.HTTPError as e:
        print(f"[chat-trainer] upload HTTP {e.code}: {e.read().decode('utf-8', 'ignore')}", flush=True)
        return False

# ------------------------------------------------------------------ main loop
def main():
    if BRIDGE_TOKEN.startswith("REPLACE_WITH"):
        print("[chat-trainer] CHAT_BRIDGE_TOKEN not set - edit or export it.", flush=True)
        sys.exit(1)

    print(f"[chat-trainer] starting (server={SERVER_BASE}, poll={POLL_SECONDS}s, min_pairs={MIN_NEW_PAIRS})", flush=True)

    while True:
        try:
            st = state()
            since = int(st.get("since_id", 0))
            pairs = fetch_training_data(since)
            print(f"[chat-trainer] fetched {len(pairs)} new pair(s) since id {since}", flush=True)

            if len(pairs) >= MIN_NEW_PAIRS:
                batch = pairs[:MAX_PAIRS_PER_RUN]
                records = build_training_records(batch)
                print(f"[chat-trainer] training on {len(records)} records", flush=True)

                if train_adapter(records, OUTPUT_ADAPTER):
                    if upload_adapter(OUTPUT_ADAPTER, "llama3.2:3b", len(batch)):
                        st["since_id"] = int(batch[-1]["id"]) if batch else since
                        st["trained_pairs"] = int(st.get("trained_pairs", 0)) + len(batch)
                        save_state(st)
                        print(f"[chat-trainer] trained + uploaded {len(batch)} pairs", flush=True)
                    else:
                        print("[chat-trainer] training ok but upload failed; retrying next poll", flush=True)
                else:
                    print("[chat-trainer] training failed; keeping pairs for retry", flush=True)
            else:
                print(f"[chat-trainer] only {len(pairs)} pair(s) - need {MIN_NEW_PAIRS}; waiting", flush=True)
        except Exception as e:
            import traceback
            print(f"[chat-trainer] error: {e}", flush=True)
            traceback.print_exc()

        time.sleep(POLL_SECONDS)

if __name__ == "__main__":
    main()