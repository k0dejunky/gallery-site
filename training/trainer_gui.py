#!/usr/bin/env python3
"""
Chat Trainer Control GUI (Windows 7 / Python 3.8, Tkinter).

A native desktop client for the trainer control server (trainer_control.py).
Talks to http://127.0.0.1:8790 over the stdlib urllib - no extra deps.
Two tabs:
  - Trainer: live status + Pause / Resume / Stop / Restart AI / Train now
  - Admin:   every trainer setting + Save (restart) + Reset + run-at-logon
"""

import json
import os
import queue
import subprocess
import sys
import threading
import time
import urllib.request
import urllib.error
import tkinter as tk
from tkinter import ttk, messagebox

# GUI connection settings are persisted here so the app remembers the control
# server host/port/token across launches (env vars act as first-run defaults).
GUI_SETTINGS_FILE = os.environ.get("GUI_SETTINGS_FILE", r"C:\work\chat_trainer_gui.json")


def load_gui_settings():
    try:
        with open(GUI_SETTINGS_FILE, encoding="utf-8") as fh:
            data = json.load(fh)
            return data if isinstance(data, dict) else {}
    except Exception:
        return {}


def save_gui_settings(host, port, token):
    try:
        with open(GUI_SETTINGS_FILE, "w", encoding="utf-8") as fh:
            json.dump({"control_host": host, "control_port": port, "control_token": token}, fh)
    except Exception:
        pass


_gs = load_gui_settings()
CONTROL_HOST = str(_gs.get("control_host") or os.environ.get("CONTROL_HOST", "127.0.0.1"))
CONTROL_PORT = str(_gs.get("control_port") or os.environ.get("CONTROL_PORT", "8790"))
CONTROL_TOKEN = str(_gs.get("control_token") or os.environ.get("CONTROL_TOKEN", ""))
BASE = "http://%s:%s" % (CONTROL_HOST, CONTROL_PORT)

PYTHON = os.environ.get("TRAINER_PYTHON", r"C:\Python38\python.exe")
CONTROL_SCRIPT = os.environ.get("CONTROL_SCRIPT", r"C:\ai\trainer_control.py")


def apply_connection(host, port, token):
    """Update + persist the control-server connection (used by fetch())."""
    global CONTROL_HOST, CONTROL_PORT, CONTROL_TOKEN, BASE
    CONTROL_HOST = str(host or "127.0.0.1").strip()
    CONTROL_PORT = str(port or "8790").strip()
    CONTROL_TOKEN = str(token or "").strip()
    BASE = "http://%s:%s" % (CONTROL_HOST, CONTROL_PORT)
    save_gui_settings(CONTROL_HOST, CONTROL_PORT, CONTROL_TOKEN)

# ---- dark theme palette -----------------------------------------------------
BG      = "#14121a"
CARD    = "#1e1b28"
LINE    = "#322d42"
FG      = "#e8e6f0"
MUT     = "#9a94ad"
ACC     = "#b18cff"
OK      = "#3ddc84"
WARN    = "#ffb454"
ERR     = "#ff6b6b"
BTN     = "#2a2540"


def style_configure(root):
    style = ttk.Style(root)
    try:
        style.theme_use("clam")
    except Exception:
        pass
    style.configure("TFrame", background=BG)
    style.configure("TLabel", background=BG, foreground=FG)
    style.configure("Header.TLabel", background=BG, foreground=ACC, font=("Segoe UI", 15, "bold"))
    style.configure("Mut.TLabel", background=BG, foreground=MUT)
    style.configure("TNotebook", background=BG, borderwidth=0)
    style.configure("TNotebook.Tab", background=CARD, foreground=MUT, padding=(14, 6))
    style.map("TNotebook.Tab", background=[("selected", ACC)], foreground=[("selected", "#14121a")])
    style.configure("TButton", background=BTN, foreground=FG, borderwidth=1, padding=(10, 6))
    style.map("TButton", background=[("active", "#3a3356")])
    style.configure("Primary.TButton", background=ACC, foreground="#14121a", font=("Segoe UI", 9, "bold"))
    style.map("Primary.TButton", background=[("active", "#c4a6ff")])
    style.configure("Danger.TButton", background="#3a1f24", foreground=ERR)
    style.map("Danger.TButton", background=[("active", "#55292f")])
    style.configure("Warn.TButton", background="#3a2c18", foreground=WARN)
    style.map("Warn.TButton", background=[("active", "#553f22")])
    style.configure("TEntry", fieldbackground="#14121a", foreground=FG, bordercolor=LINE,
                    insertcolor=FG, lightcolor=CARD, darkcolor=CARD)
    style.configure("TCombobox", fieldbackground="#14121a", background=BTN, foreground=FG, arrowcolor=FG)
    style.map("TCombobox", fieldbackground=[("readonly", "#14121a")])
    style.configure("TLabelframe", background=CARD, bordercolor=LINE)
    style.configure("TLabelframe.Label", background=CARD, foreground=ACC)
    style.configure("Status.TLabel", background=BG, foreground=FG, font=("Segoe UI", 20, "bold"))
    style.configure("Badge.TLabel", background=BG, font=("Segoe UI", 11, "bold"))


def fetch(path, method="GET", body=None, timeout=8):
    """Talk to the control server. Returns parsed JSON or raises."""
    data = None
    headers = {}
    if CONTROL_TOKEN:
        headers["X-Control-Token"] = CONTROL_TOKEN
    if body is not None:
        data = json.dumps(body).encode("utf-8")
        headers["Content-Type"] = "application/json"
    req = urllib.request.Request(BASE + path, data=data, method=method, headers=headers)
    with urllib.request.urlopen(req, timeout=timeout) as resp:
        return json.loads(resp.read().decode("utf-8"))


# ----------------------------------------------------------------------------
class TrainerGUI:
    def __init__(self, root):
        self.root = root
        root.title("Chat Trainer Control")
        root.configure(bg=BG)
        root.geometry("900x640")
        root.minsize(760, 520)

        style_configure(root)

        self.status = {}
        self.cfg = {}
        self.entries = {}
        self.running_flag = True
        self._log_prev = None
        self._polling = False
        self._q = queue.Queue()

        self.build()
        self.refresh_all()
        root.protocol("WM_DELETE_WINDOW", self.on_close)
        self._drain()
        self.poll()

    # ---- layout -------------------------------------------------------------
    def build(self):
        top = ttk.Frame(self.root, padding=(16, 10))
        top.pack(fill="x")
        ttk.Label(top, text="Chat Trainer Control", style="Header.TLabel").pack(side="left")
        self.badge = ttk.Label(top, text="—", style="Badge.TLabel")
        self.badge.pack(side="right")

        self.nb = ttk.Notebook(self.root)
        self.nb.pack(fill="both", expand=True, padx=16, pady=(4, 12))
        self.tab_trainer = ttk.Frame(self.nb, padding=12)
        self.tab_admin = ttk.Frame(self.nb, padding=12)
        self.nb.add(self.tab_trainer, text="Trainer")
        self.nb.add(self.tab_admin, text="Admin")

        self.build_trainer()
        self.build_admin()

    def build_trainer(self):
        f = self.tab_trainer

        # status metrics
        mf = ttk.Frame(f)
        mf.pack(fill="x", pady=(0, 10))
        self.metrics = {}
        names = [("last_poll", "Last poll"), ("since_id", "Since id"),
                 ("trained_pairs", "Trained pairs"), ("waiting", "Waiting to train"),
                 ("idle", "Idle"), ("phase", "Phase"),
                 ("autostart", "Run at logon")]
        for i, (key, label) in enumerate(names):
            # Plain tk.Frame: ttk.Frame rejects borderwidth/relief/bg.
            cell = tk.Frame(mf, bg=CARD, borderwidth=1, relief="solid", padx=8, pady=6)
            cell.grid(row=0, column=i, padx=4, sticky="nsew")
            cell_lbl = tk.Label(cell, text=label, bg=CARD, fg=MUT, font=("Segoe UI", 8))
            cell_lbl.pack(anchor="w")
            cell_val = tk.Label(cell, text="—", bg=CARD, fg=FG, font=("Segoe UI", 13, "bold"))
            cell_val.pack(anchor="w")
            self.metrics[key] = cell_val
        for c in range(len(names)):
            mf.grid_columnconfigure(c, weight=1)

        # note line
        self.note = ttk.Label(f, text="", style="Mut.TLabel")
        self.note.pack(fill="x", pady=(0, 8))

        # buttons
        bf = ttk.Frame(f)
        bf.pack(fill="x", pady=(0, 10))
        self.btn_pause = ttk.Button(bf, text="Pause", style="Warn.TButton", command=lambda: self.action("pause"))
        self.btn_pause.pack(side="left", padx=(0, 8))
        self.btn_resume = ttk.Button(bf, text="Resume", style="Primary.TButton", command=lambda: self.action("resume"))
        self.btn_resume.pack(side="left", padx=(0, 8))
        self.btn_stop = ttk.Button(bf, text="Stop", style="Danger.TButton", command=lambda: self.action("stop"))
        self.btn_stop.pack(side="left", padx=(0, 8))
        self.btn_restart = ttk.Button(bf, text="Restart AI", style="Primary.TButton", command=lambda: self.action("restart"))
        self.btn_restart.pack(side="left", padx=(0, 8))
        self.btn_trainnow = ttk.Button(bf, text="Train now", command=lambda: self.action("train-now"))
        self.btn_trainnow.pack(side="left")

        # training-run progress
        pcard = tk.Frame(f, bg=CARD, borderwidth=1, relief="solid", padx=10, pady=8)
        pcard.pack(fill="x", pady=(0, 10))
        prow = tk.Frame(pcard, bg=CARD)
        prow.pack(fill="x")
        self.prog_lbl = tk.Label(prow, text="Training progress", bg=CARD, fg=MUT, font=("Segoe UI", 8))
        self.prog_lbl.pack(side="left")
        self.prog_pct = tk.Label(prow, text="—", bg=CARD, fg=FG, font=("Segoe UI", 10, "bold"))
        self.prog_pct.pack(side="right")
        self.progress = ttk.Progressbar(pcard, maximum=100, value=0)
        self.progress.pack(fill="x", pady=(6, 0))
        self.prog_detail = tk.Label(pcard, text="", bg=CARD, fg=MUT, font=("Segoe UI", 9))
        self.prog_detail.pack(anchor="w", pady=(4, 0))

        # unreachable banner + start server
        self.banner = ttk.Frame(f)
        self.banner_lbl = tk.Label(self.banner, text="Control server not reachable.", fg=ERR, bg=CARD)
        self.banner_lbl.pack(side="left", padx=(0, 8))
        self.btn_startserver = ttk.Button(self.banner, text="Start server", command=self.start_server)
        self.btn_startserver.pack(side="left")
        self.banner_visible = False

        # log
        lf = ttk.LabelFrame(f, text="Log", padding=6)
        lf.pack(fill="both", expand=True)
        self.logtxt = tk.Text(lf, bg="#0f0d15", fg=FG, insertbackground=FG, wrap="none",
                              font=("Consolas", 9), relief="flat", state="disabled")
        self.logtxt.pack(side="left", fill="both", expand=True)
        sbar = ttk.Scrollbar(lf, command=self.logtxt.yview)
        sbar.pack(side="right", fill="y")
        self.logtxt.config(yscrollcommand=sbar.set)

    def build_admin(self):
        f = self.tab_admin

        # Control-server connection: host/port/token, persisted so the app
        # remembers the sign-on across launches.
        conn = ttk.LabelFrame(f, text="Control server connection", padding=6)
        conn.pack(fill="x", pady=(0, 8))
        crow = ttk.Frame(conn)
        crow.pack(fill="x")
        ttk.Label(crow, text="Host").pack(side="left", padx=(0, 4))
        self.e_host = ttk.Entry(crow, width=16)
        self.e_host.pack(side="left", padx=(0, 10))
        self.e_host.insert(0, CONTROL_HOST)
        ttk.Label(crow, text="Port").pack(side="left", padx=(0, 4))
        self.e_port = ttk.Entry(crow, width=7)
        self.e_port.pack(side="left", padx=(0, 10))
        self.e_port.insert(0, CONTROL_PORT)
        ttk.Label(crow, text="Token (optional)").pack(side="left", padx=(0, 4))
        self.e_token = ttk.Entry(crow, width=26, show="*")
        self.e_token.pack(side="left", padx=(0, 10))
        self.e_token.insert(0, CONTROL_TOKEN)
        ttk.Button(crow, text="Save connection", style="Primary.TButton", command=self.save_connection).pack(side="left")

        warn = ttk.Label(f, text="Saved to C:\\work\\chat_trainer_config.json. Saving restarts the trainer to apply.",
                         style="Mut.TLabel")
        warn.pack(anchor="w", pady=(0, 8))

        # scrollable settings grid
        canvas = tk.Canvas(f, bg=BG, highlightthickness=0)
        sb = ttk.Scrollbar(f, orient="vertical", command=canvas.yview)
        inner = ttk.Frame(canvas)
        inner.bind("<Configure>", lambda e: canvas.configure(scrollregion=canvas.bbox("all")))
        canvas.create_window((0, 0), window=inner, anchor="nw")
        canvas.configure(yscrollcommand=sb.set)
        canvas.pack(side="left", fill="both", expand=True)
        sb.pack(side="right", fill="y")
        canvas.bind("<Enter>", lambda e: canvas.bind_all("<MouseWheel>",
                    lambda ev: canvas.yview_scroll(-1 * int(ev.delta / 120), "units")))
        canvas.bind("<Leave>", lambda e: canvas.unbind_all("<MouseWheel>"))

        FIELDS = [
            ("server_base", "text", "Server URL"),
            ("bridge_token", "password", "Bridge token"),
            ("model_dir", "text", "Model dir (HF base)"),
            ("output_adapter", "text", "Output adapter file"),
            ("base_model", "text", "Base model name (upload)"),
            ("poll_seconds", "number", "Poll seconds"),
            ("min_new_pairs", "number", "Min new pairs to train"),
            ("max_pairs_per_run", "number", "Max pairs per run"),
            ("lora_r", "number", "LoRA r"),
            ("lora_alpha", "number", "LoRA alpha"),
            ("lora_dropout", "number", "LoRA dropout"),
            ("max_len", "number", "Max token length"),
            ("steps", "number", "Training steps"),
            ("lr", "number", "Learning rate"),
            ("required_idle_seconds", "number", "Required idle seconds"),
            ("cpu_threads", "number", "CPU threads (0=half)"),
            ("state_file", "text", "State file"),
            ("pause_file", "text", "Pause file"),
            ("force_train_file", "text", "Force-train file"),
            ("log_file", "text", "Log file"),
            ("status_file", "text", "Status file"),
        ]
        for i, (key, kind, label) in enumerate(FIELDS):
            row = i // 2
            col = i % 2
            cell = ttk.Frame(inner, padding=4)
            cell.grid(row=row, column=col, sticky="ew", padx=4, pady=2)
            ttk.Label(cell, text=label).pack(anchor="w")
            show = "" if kind == "password" else None
            e = ttk.Entry(cell, show=show)
            e.pack(fill="x")
            self.entries[key] = e
        inner.grid_columnconfigure(0, weight=1)
        inner.grid_columnconfigure(1, weight=1)

        bf = ttk.Frame(f)
        bf.pack(fill="x", pady=(10, 0))
        self.btn_save = ttk.Button(bf, text="Save & restart trainer", style="Primary.TButton", command=self.save_cfg)
        self.btn_save.pack(side="left", padx=(0, 8))
        self.btn_reset = ttk.Button(bf, text="Reset to defaults", command=self.reset_cfg)
        self.btn_reset.pack(side="left", padx=(0, 8))
        self.btn_auto_on = ttk.Button(bf, text="Run at logon: ON", command=lambda: self.autostart(True))
        self.btn_auto_on.pack(side="left", padx=(0, 8))
        self.btn_auto_off = ttk.Button(bf, text="OFF", style="Danger.TButton", command=lambda: self.autostart(False))
        self.btn_auto_off.pack(side="left")

    # ---- actions ------------------------------------------------------------
    def action(self, which):
        try:
            fetch("/api/" + which, method="POST", timeout=10)
        except Exception as e:
            messagebox.showerror("Trainer", "Request failed:\n%s" % e)
            return
        time.sleep(0.5)
        self.refresh_status()
        self.refresh_log()

    def save_cfg(self):
        body = {}
        for key, e in self.entries.items():
            body[key] = e.get()
        try:
            res = fetch("/api/config", method="POST", body=body, timeout=15)
        except Exception as e:
            messagebox.showerror("Trainer", "Save failed:\n%s" % e)
            return
        if not res.get("ok"):
            messagebox.showerror("Trainer", "Invalid settings:\n%s" % json.dumps(res.get("errors", {})))
            return
        messagebox.showinfo("Trainer", "Saved - trainer restarted with new settings.")
        self.refresh_config()

    def reset_cfg(self):
        if not messagebox.askyesno("Trainer", "Reset all settings to defaults?"):
            return
        try:
            fetch("/api/config", method="POST", body={}, timeout=15)
        except Exception as e:
            messagebox.showerror("Trainer", str(e))
            return
        self.refresh_config()

    def autostart(self, enabled):
        try:
            res = fetch("/api/autostart", method="POST", body={"enabled": enabled}, timeout=15)
        except Exception as e:
            messagebox.showerror("Trainer", str(e))
            return
        messagebox.showinfo("Trainer", res.get("detail", "ok"))
        self.refresh_status()

    def start_server(self):
        try:
            subprocess.Popen([PYTHON, CONTROL_SCRIPT], creationflags=0x08000000 if os.name == "nt" else 0)
            messagebox.showinfo("Trainer", "Control server starting - refresh shortly.")
        except Exception as e:
            messagebox.showerror("Trainer", str(e))
        time.sleep(1)
        self.refresh_all()

    def save_connection(self):
        apply_connection(self.e_host.get(), self.e_port.get(), self.e_token.get())
        messagebox.showinfo("Trainer", "Connection saved - remembered next launch.")
        self.refresh_all()

    # ---- polling ------------------------------------------------------------
    def poll(self):
        if not self.running_flag:
            return
        if not self._polling:
            self._polling = True
            threading.Thread(target=self._poll_worker, daemon=True).start()
        self.root.after(3000, self.poll)

    def _poll_worker(self):
        # Network fetches run off the Tk thread; results are handed back via a
        # thread-safe queue so Tk is only ever touched on the main thread.
        status = None
        log = None
        try:
            status = fetch("/api/status").get("status", {})
        except Exception:
            status = None
        try:
            log = fetch("/api/log").get("log", "")
        except Exception:
            log = None
        self._polling = False
        try:
            self._q.put(("poll", status, log))
        except Exception:
            pass

    def _drain(self):
        # Main-thread loop: apply whatever the poll workers handed back.
        if not self.running_flag:
            return
        try:
            while True:
                kind, a, b = self._q.get_nowait()
                if kind == "poll":
                    self.apply_poll(a, b)
        except queue.Empty:
            pass
        self.root.after(100, self._drain)

    def on_close(self):
        self.running_flag = False
        self.root.destroy()

    def apply_poll(self, status, log):
        if status is None:
            self.status = {}
            self.show_banner()
        else:
            self.status = status
            self.hide_banner()
        self.render_status()
        if log is not None and log != self._log_prev:
            self._log_prev = log
            self.render_log(log)

    def refresh_all(self):
        self.refresh_status()
        self.refresh_config()
        self.refresh_log()

    def refresh_status(self):
        try:
            d = fetch("/api/status")
            self.status = d.get("status", {})
            self.hide_banner()
        except Exception:
            self.status = {}
            self.show_banner()
        self.render_status()

    def render_status(self):
        s = self.status
        running = bool(s.get("running"))
        paused = bool(s.get("paused"))
        stopped = bool(s.get("stopped"))

        if running:
            badge_text, color = "RUNNING", OK
        elif paused:
            badge_text, color = "PAUSED", WARN
        elif stopped:
            badge_text, color = "STOPPED", ERR
        else:
            badge_text, color = "STOPPED", ERR
        self.badge.config(text=badge_text, foreground=color)

        t = s.get("trainer") or {}
        self.metrics["last_poll"].config(text=t.get("last_poll_at") or "—")
        self.metrics["since_id"].config(text=t.get("since_id", "—"))
        self.metrics["trained_pairs"].config(text=t.get("trained_pairs", "—"))
        self.metrics["idle"].config(text=t.get("idle_seconds", "—"))
        self.metrics["phase"].config(text=t.get("phase") or "—")
        self.metrics["autostart"].config(text="on" if s.get("autostart") else "off")

        # pairs waiting to be trained (from the site, via the control server)
        p = s.get("pending") or {}
        waiting = p.get("waiting")
        if waiting is None:
            self.metrics["waiting"].config(text="—")
        else:
            color = OK if int(waiting) <= 0 else WARN
            self.metrics["waiting"].config(text=str(waiting), foreground=color)

        # training-run progress from the trainer's live status
        progress = t.get("progress") or {}
        step = progress.get("step")
        total = progress.get("total")
        loss = progress.get("loss")
        pct = progress.get("pct")
        if progress and step is not None and total:
            pct = max(0, min(100, int(round(pct * 100))) if pct is not None else int(step * 100 / total))
            self.progress["value"] = pct
            self.prog_pct.config(text="%d%%" % pct)
            detail = "Step %d / %d" % (step, total)
            if loss is not None:
                detail += "   ·   loss %.4f" % loss
            self.prog_detail.config(text=detail)
            self.prog_lbl.config(text="Training in progress")
        else:
            self.progress["value"] = 0
            self.prog_pct.config(text="—")
            self.prog_detail.config(text="No training run active (idle).")
            self.prog_lbl.config(text="Training progress")

        notes = []
        if paused:
            notes.append("PAUSED - training held until Resume")
        if s.get("train_now_pending"):
            notes.append("train-now requested")
        if s.get("last_exit") is not None:
            notes.append("last exit code: %s" % s.get("last_exit"))
        if running and s.get("uptime_sec") is not None:
            notes.append("uptime %ds" % s.get("uptime_sec"))
        self.note.config(text="   ·   ".join(notes))

        self.btn_pause.config(state="normal" if running else "disabled")
        self.btn_resume.config(state="normal" if paused else "disabled")
        self.btn_stop.config(state="normal" if running else "disabled")
        self.btn_restart.config(state="normal" if running else "disabled")

    def show_banner(self):
        if not self.banner_visible:
            self.banner.pack(fill="x", pady=(0, 8))
            self.banner_visible = True

    def hide_banner(self):
        if self.banner_visible:
            self.banner.pack_forget()
            self.banner_visible = False

    def refresh_config(self):
        try:
            d = fetch("/api/config")
            cfg = d.get("config", {})
            for key, e in self.entries.items():
                val = cfg.get(key, "")
                if val is not None:
                    e.delete(0, "end")
                    e.insert(0, str(val))
        except Exception:
            pass

    def refresh_log(self):
        try:
            d = fetch("/api/log")
            log = d.get("log", "")
        except Exception:
            return
        self._log_prev = log
        self.render_log(log)

    def render_log(self, log):
        # Keep the view pinned to the bottom when the user is already there;
        # otherwise preserve their scroll position.
        at_end = self.logtxt.yview()[1] >= 0.99
        self.logtxt.config(state="normal")
        self.logtxt.delete("1.0", "end")
        self.logtxt.insert("end", log)
        if at_end:
            self.logtxt.see("end")
        self.logtxt.config(state="disabled")


def main():
    try:
        root = tk.Tk()
    except Exception as e:
        # Very early failure (no display / Tk init) - show what we can.
        print("FATAL: could not start Tk: %s" % e)
        try:
            import ctypes
            ctypes.windll.user32.MessageBoxW(0, str(e), "Chat Trainer Control", 0x10)
        except Exception:
            pass
        sys.exit(1)
    try:
        TrainerGUI(root)
    except Exception as e:
        import traceback
        traceback.print_exc()
        try:
            import ctypes
            ctypes.windll.user32.MessageBoxW(0, "Failed to start the UI:\n%s" % e,
                                             "Chat Trainer Control", 0x10)
        except Exception:
            pass
        sys.exit(1)
    root.mainloop()


if __name__ == "__main__":
    main()