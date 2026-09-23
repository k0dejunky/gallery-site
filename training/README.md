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
        | POST /webhooks/chat/training-upload           (multipart adapter + configs)
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
  keyboard/mouse input for `REQUIRED_IDLE_SECONDS` (default 300). If you sit
  down while training is already running it finishes (mid-run checkpointing is
  not practical), but the next round waits for idle again. Set `0` to disable.
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

A native Windows GUI that mirrors the web UI, built with Tkinter and packaged
with PyInstaller. It talks to the control server over localhost (`:8790`) so it
works even if the box's firewall blocks the LAN.

- **Trainer tab** — live status + working **Pause / Resume / Stop / Restart AI /
  Train now** buttons, and a scrolling log tail.
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