# Chat trainer (training PC)

The gallery site fine-tunes its chat assistant on operator replies. Training
runs on a dedicated always-on PC (Windows 7 x64, AMD Phenom II X6 1100T, 16 GB
RAM, NVIDIA GTX 750 Ti) and uploads the finished LoRA adapter to the server,
which rebuilds the Ollama fine-tuned model.

## Architecture

```
training PC (192.168.1.250, user k0debox)
  C:\ai\chat_trainer.py            -> polling trainer (Python 3.8)
  D:\llama-3.2-3b-hf\             -> HuggingFace-format base model (6.4 GB)
  C:\work\.chat_trainer_state.json -> watermark (since_id / trained_pairs)
        |
        | GET /webhooks/chat/training-data?since_id=N   (Bearer CHAT_BRIDGE_TOKEN)
        | POST /webhooks/chat/training-upload           (multipart adapter + configs; Bearer CHAT_UPLOAD_TOKEN)
        v
gallery server (amethyst2213.com /var/www/gallery)
  storage/training/chat-lora/     -> model.safetensors + adapter_config.json + config.json
  ChatModel::rebuild()            -> ollama create from Modelfile (FROM llama3.2:3b + ADAPTER)
  chat-finetuned:<hash>           -> the fine-tuned model used by chat
```

## Training approach (why PEFT, not llama.cpp)

The original plan used llama.cpp's `llama-train`/`finetune` tool. That tool was
removed from llama.cpp before Llama 3.2 support existed, and the last versions
that still had it (b2800/b2950) crash on the Llama 3.2 architecture (they
predate its tensor layout, tied output embeddings, and GQA). Both the
tensor-count and tied-embedding issues were patched, but the training graph
still aborts. So the trainer uses **HuggingFace transformers + PEFT** on CPU:

- Python 3.8.10 (last version supporting Windows 7) at `C:\Python38`
- `torch==1.13.1` (CPU), `transformers==4.40.2`, `peft==0.12.0`,
  `tokenizers==0.15.2`, `safetensors==0.4.2`, `datasets==3.1.0`,
  `pyarrow==14.0.2`, `numpy==1.24.4`, `gguf`, `jinja2`
- These exact versions matter: newer `tokenizers`/`pyarrow`/`safetensors`
  native wheels use Win7-incompatible APIs ("DLL load failed: procedure not
  found"), and transformers 4.45+ needs tokenizers 0.20+ which also breaks.

## Server-side prerequisites

Ollama on the server must support the Modelfile `ADAPTER` directive, which was
**removed in Ollama 0.34.1** ("LoRA adapters are no longer supported"). The
server runs **Ollama 0.33.3** (backup of the old 0.34.1 install:
`/root/ollama-backup-0341/`). The fine-tuned feature will silently stop working
if Ollama is upgraded past 0.33.x.

`ChatModel::adapterPath()` expects the adapter as a directory:
`storage/training/chat-lora/{model.safetensors, adapter_config.json, config.json}`.
The `training-upload` webhook stores it that way (it also accepts the legacy
single-file names for backward compatibility).

## Authentication

- Read endpoints (`training-data`, `training-count`, `training-progress`) use
  the shared **`CHAT_BRIDGE_TOKEN`** (`GALLERY_CHAT_KEY`).
- `training-upload` rebuilds the fine-tuned model, so it requires a
  **per-device operator token** (the shared key is rejected). Create one on the
  admin **Chat** page (Operator tokens), then set **`CHAT_UPLOAD_TOKEN`** (or
  `upload_token` in the trainer config file). The trainer falls back to the
  bridge token only if the server still permits it.

## Teach the fine-tuned model to reference galleries

The site has an AI content search: when a member asks for specific content
("do you have any blowjob content?"), the server searches the galleries they
can view and injects the matches into the prompt as a "Site content this member
can view:" block, then asks the model to name a matching gallery.

- **Retrieval mode** follows that instruction (the base abliterated model).
- **Finetuned mode** is LoRA-trained on plain operator replies, so it replies in
  operator voice and ignores the injected gallery list.

To teach finetuned mode to reference galleries, the training data needs examples
in the **same shape the model sees at inference**: a user turn that contains the
content block + the member's query, and an assistant reply that names the exact
gallery.

### How the trainer formats records

`build_training_records()` wraps each pair in the Llama-3 chat template and
prepends the static system persona (the same string `ChatAi` sends at
inference) inside the user turn, so trained records match inference:

```
<|start_header_id|>user<|end_header_id|>
You are the chat assistant for an adult content gallery site. Be warm, flirty,
and human. Stay in character and respond naturally. Never break character.
Keep replies under 2000 characters.
{user_message}<|eot_id|>
<|start_header_id|>assistant<|end_header_id|>
{operator_reply}<|eot_id|>
```

For a content-referral example, `user_message` is the injected content block +
the member's query, and `operator_reply` names one of the listed galleries.

### The seed corpus

`training/content-referral-pairs.jsonl` contains 14 curated pairs built from
real site galleries (titles and categories are real). Import it so the next
training round teaches the model to name galleries:

- **Admin UI:** admin **Chat** → **Import training pairs** → paste the file's
  JSONL lines, or upload the file.
- **CLI:** `php bin/chat_training_import.php --file=training/content-referral-pairs.jsonl`

Pairs are cleaned, junk-filtered and stored as `cleaned = 1`; re-importing is
idempotent.

### Authoring new content-referral pairs

Follow the same shape (keep each record well under the trainer's 512-token
`MAX_LEN`, so avoid long descriptions):

- `user_message` = `Site content this member can view (exact gallery titles, do not invent others): - "Exact Title" [category, category]` + the rule line + `member: <the content question>`.
- `operator_reply` = a short, flirty reply in the operator's voice that names
  the matching gallery by its **exact title** from the list and invites the
  member to open it.

Rules:

- Only ever reference galleries whose **exact titles** appear in the list.
- Match the reply's topic to the query (blow job query → blow job gallery).
- Vary the phrasing so the model generalizes (don't memorize one template).
- Use galleries the member's level allows (level-gate like the live search).
- Don't add pairs where the member is just chatting — those are for normal
  operator-style training, not content referral.

### Retraining

The trainer only runs when **≥ 20 new pairs** have accumulated and the PC has
been idle. Import the seeds (or add more) and wait for the next run; the new
adapter upload rebuilds `chat-finetuned` atomically. Remember Ollama must stay
≤ 0.33.x (the `ADAPTER` Modelfile directive was removed in 0.34.1).

## Installing on a fresh training PC

1. Install Python 3.8.10 (All Users, Prepend Path, Include pip) to `C:\Python38`.
2. Install the pinned pip packages above.
3. Download the base model (HF format, not GGUF) to `D:\llama-3.2-3b-hf`:
   - `config.json` patched for transformers 4.40: `max_position_embeddings=4096`,
     no `rope_scaling`, no `head_dim`, `torch_dtype="float32"`,
     `transformers_version="4.40.2"`.
   - `tokenizer.json` patched for tokenizers 0.15: merges as space-joined
     strings, no `ignore_merges`/`fuse_unk`, ByteLevel post-processor only.
   - `model-00001-of-00002.safetensors` + `model-00002-of-00002.safetensors`.
4. Copy `training/chat_trainer.py` to `C:\ai\chat_trainer.py`.
5. Copy `training/trainer_control.py` to `C:\ai\trainer_control.py`.
6. Copy `training/run_chat_trainer.bat` to `C:\ai\run_chat_trainer.bat`
   (starts the control server, which supervises the trainer).
7. Run `training/install_control.bat` once on the PC (or via RDP) — it
   registers the `ChatTrainer` at-logon scheduled task and starts the server.
8. Seed `C:\work\.chat_trainer_state.json` with `{"since_id": <max pair id>, "trained_pairs": 0}`
   so it does not re-train pairs the server has already processed.

## Control UI (trainer_control.py)

The training PC runs a small stdlib web server on **http://<pc-ip>:8790**
(LAN reachable). It supervises `chat_trainer.py` as a child process and serves
two views:

- **Trainer panel** — live status (running/stopped/paused, last poll, since_id,
  trained pairs, idle, phase) + **Pause / Resume / Stop / Restart AI / Train
  now** buttons that actually work:
  - Pause/Resume create/delete the pause file (training held, polling continues).
  - Stop kills the trainer subprocess; the supervisor does **not** auto-respawn
    while stopped.
  - Restart AI kills + respawns the trainer with the current config.
  - Train now sets a one-shot force-train marker (skips the idle check).
- **Admin view** — edit every trainer setting (server URL, bridge token, model
  dir, poll/min pairs, LoRA r/alpha/dropout, steps, LR, idle seconds, CPU
  threads, file paths) and Save (restarts the trainer to apply), plus a
  **Run at logon** toggle that registers/removes the scheduled task.

Settings live in `C:\work\chat_trainer_config.json` (created on first start).
The trainer reads `config file > env var > built-in default`, so the UI's Save
actually changes behaviour. A `CONTROL_TOKEN` env var on `trainer_control.py`
adds an `X-Control-Token` requirement (default: open on the LAN).

## Behavior

- Polls `GET /webhooks/chat/training-data?since_id=<last>` every `POLL_SECONDS` (600).
- When `>= MIN_NEW_PAIRS` (20) new pairs have accumulated, trains a LoRA
  (`r=8`, `alpha=16`, q/k/v/o projections, 60 steps, max len 512) on CPU.
- Uploads `chat-lora.safetensors` + `adapter_config` + `base_config` to the
  webhook; the server stores the adapter dir and runs `ollama create` to build
  `chat-finetuned:<hash>`, atomically (versioned create + smoke test).
- On success advances the watermark; on failure keeps pairs for the next poll.

## Sharing the PC with other work

The training PC is used for other things, so training yields to interactive use:

- **Idle gating** — a training round only *starts* when the machine has had no
  keyboard/mouse input for `REQUIRED_IDLE_SECONDS` (default 0 = disabled, so the
  next cycle starts as soon as a poll finds new pairs). Set it to e.g. 300 to
  skip training while the PC is being used.
- **CPU budget** — `apply_cpu_budget()` limits torch to `CPU_THREADS` threads
  (default 0 = half the physical cores) so the rest of the machine stays
  responsive during a run. Set an explicit count to override.
- **Pause file** — while `C:\work\.chat_trainer_paused` exists, polling
  continues but no training starts. Create the file to pause, delete it to
  resume. A handy one-liner: `type nul > C:\work\.chat_trainer_paused` /
  `del C:\work\.chat_trainer_paused`.

These are controlled via env vars in `run_chat_trainer.bat`
(`REQUIRED_IDLE_SECONDS`, `CPU_THREADS`, `PAUSE_FILE`).

## Desktop GUI (ChatTrainerUI.exe)

The desktop app now lives in its own repo: **https://github.com/k0dejunky/chat-trainer-ui**
(mirrors of these files). A native Windows GUI that mirrors the web UI, built
with Tkinter and packaged with PyInstaller. It talks to the control server over
localhost (`:8790`) so it works even if the box's firewall blocks the LAN.

- **Trainer tab** — live status + working **Pause / Resume / Stop / Restart AI /
  Train now** buttons, a live **training-run progress bar** (step N/total +
  loss, fed by `ProgressCallback` in `chat_trainer.py`), and a scrolling log
  tail.
- **Admin tab** — edit every trainer setting and Save (restarts the trainer to
  apply), plus the run-at-logon toggle.

Installed on the training PC as `C:\ai\dist\ChatTrainerUI.exe` with a
**"Chat Trainer"** desktop shortcut (purple chat icon). Rebuild after changing
`trainer_gui.py`:

```bat
copy trainer_gui.py trainer_gui.spec trainer.ico C:\ai\
C:\ai\build_ui.bat          :: or: C:\Python38\python.exe -m PyInstaller --clean trainer_gui.spec
```

## Windows-specific notes

- The Phenom II has no AVX2 and no SMT; the trainer uses all 6 physical cores
  (`torch.get_num_threads()=6`). ~165 s/step for 23 records; a 60-step run takes
  roughly 2-3 hours on this CPU. Batch 1, no gradient accumulation.
- GTX 750 Ti (2 GB VRAM) is too small to offload a 3B model; training is CPU-only.