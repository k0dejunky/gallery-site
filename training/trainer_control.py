#!/usr/bin/env python3
"""
Chat trainer control server (Windows 7 / Python 3.8, stdlib only).

Runs on http://<training-pc-ip>:8790 and provides:
  - a Trainer panel  (status + Pause / Resume / Stop / Restart AI / Train now)
  - an Admin view    (edit every trainer setting, register at-logon task)

It supervises chat_trainer.py as a child process, so Stop really stops it
(no auto-restart while stopped) and Restart kills + respawns it.

Endpoints:
  GET  /                  -> the UI page
  GET  /api/status        -> trainer/control state (JSON)
  GET  /api/config        -> current config (JSON)
  POST /api/config        -> save config (JSON body) and restart trainer
  POST /api/pause         -> create pause file
  POST /api/resume        -> delete pause file
  POST /api/stop          -> kill the trainer subprocess (hold stopped)
  POST /api/restart       -> kill (if running) + respawn
  POST /api/train-now     -> touch the force-train marker
  GET  /api/log?lines=N   -> tail of chat_trainer.log
  POST /api/autostart     -> register/unregister the at-logon scheduled task
"""

import json
import os
import subprocess
import sys
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

# ------------------------------------------------------------------ config
HOST = os.environ.get("CONTROL_HOST", "0.0.0.0")
PORT = int(os.environ.get("CONTROL_PORT", "8790"))
TOKEN = os.environ.get("CONTROL_TOKEN", "")  # optional; empty = open on LAN
PYTHON = os.environ.get("TRAINER_PYTHON", r"C:\Python38\python.exe")
TRAINER_SCRIPT = os.environ.get("TRAINER_SCRIPT", r"C:\ai\chat_trainer.py")
CONFIG_FILE = os.environ.get("CHAT_TRAINER_CONFIG", r"C:\work\chat_trainer_config.json")
PAUSE_FILE = os.environ.get("PAUSE_FILE", r"C:\work\.chat_trainer_paused")
FORCE_TRAIN_FILE = os.environ.get("FORCE_TRAIN_FILE", r"C:\work\.chat_trainer_train_now")
STATUS_FILE = os.environ.get("STATUS_FILE", r"C:\work\.chat_trainer_status.json")
LOG_FILE = os.environ.get("LOG_FILE", r"C:\work\chat_trainer.log")
TASK_NAME = "ChatTrainer"

# The launcher .bat used for the at-logon task.
RUNNER_BAT = os.environ.get("RUNNER_BAT", r"C:\ai\run_chat_trainer.bat")

DEFAULT_CONFIG = {
    "server_base": "https://amethyst2213.com/gallery",
    "bridge_token": "",
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
    "lr": 0.0002,
    "required_idle_seconds": 300,
    "cpu_threads": 0,
    "pause_file": PAUSE_FILE,
    "force_train_file": FORCE_TRAIN_FILE,
    "log_file": LOG_FILE,
    "status_file": STATUS_FILE,
    "base_model": "llama3.2:3b",
}

MASK = "********"  # placeholder shown for the bridge token in GET /api/config


# ------------------------------------------------------------------ supervisor
class TrainerSupervisor:
    def __init__(self):
        self.proc = None
        self.stopped = False
        self.restart_count = 0
        self.last_exit = None

    def spawn(self):
        if self.proc is not None and self.proc.poll() is None:
            return  # already running
        self.stopped = False
        env = dict(os.environ)
        env["PYTHONUNBUFFERED"] = "1"
        # Forward the trainer's stdout to its log via shell redirection isn't
        # possible with subprocess PIPE on old Python cleanly, so we let the
        # trainer log to its own file and capture a small live buffer here.
        self.proc = subprocess.Popen(
            [PYTHON, TRAINER_SCRIPT],
            cwd=os.path.dirname(TRAINER_SCRIPT) or ".",
            env=env,
            stdout=subprocess.PIPE,
            stderr=subprocess.STDOUT,
        )
        self.restart_count += 1
        self.started_at = time.time()

    def is_running(self):
        return self.proc is not None and self.proc.poll() is None

    def stop(self):
        """Kill the trainer and mark stopped so the supervisor won't respawn."""
        if self.is_running():
            try:
                self.proc.terminate()
                time.sleep(1)
                if self.proc.poll() is None:
                    self.proc.kill()
            except Exception:
                pass
            self.proc.wait(timeout=5)
            self.last_exit = self.proc.returncode
        self.stopped = True
        self.proc = None

    def restart(self):
        self.stop()  # also clears stopped flag? no - restart means resume
        self.stopped = False
        self.spawn()

    def tick(self):
        """Auto-respawn a crashed trainer unless stopped."""
        if self.stopped:
            return
        if self.proc is not None and self.proc.poll() is not None:
            self.last_exit = self.proc.returncode
            self.proc = None
            time.sleep(2)
            self.spawn()


supervisor = TrainerSupervisor()


# ------------------------------------------------------------------ config helpers
def read_config_file() -> dict:
    data = {}
    try:
        if os.path.isfile(CONFIG_FILE):
            with open(CONFIG_FILE, encoding="utf-8") as fh:
                data = json.load(fh)
    except Exception:
        data = {}
    cfg = dict(DEFAULT_CONFIG)
    cfg.update(data if isinstance(data, dict) else {})
    return cfg


def write_config_file(cfg: dict):
    with open(CONFIG_FILE, "w", encoding="utf-8") as fh:
        json.dump(cfg, fh, indent=2, ensure_ascii=False)


def public_config() -> dict:
    """Config with the bridge token masked."""
    cfg = read_config_file()
    if cfg.get("bridge_token"):
        cfg["bridge_token"] = MASK
    cfg["_config_file"] = CONFIG_FILE
    return cfg


def apply_save(body: dict) -> dict:
    """Validate + save config. Returns {ok, errors?, config}."""
    cfg = read_config_file()
    errors = {}
    int_keys = ("poll_seconds", "min_new_pairs", "max_pairs_per_run", "lora_r",
                "lora_alpha", "lora_dropout", "max_len", "steps",
                "required_idle_seconds", "cpu_threads")
    str_keys = ("server_base", "bridge_token", "model_dir", "output_adapter",
                "state_file", "pause_file", "force_train_file", "log_file",
                "status_file", "base_model")

    for k in str_keys:
        if k in body:
            cfg[k] = str(body[k]).strip()
    for k in int_keys:
        if k in body:
            try:
                cfg[k] = int(body[k])
            except (TypeError, ValueError):
                errors[k] = "must be an integer"
    if "lr" in body:
        try:
            cfg["lr"] = float(body["lr"])
        except (TypeError, ValueError):
            errors["lr"] = "must be a number"

    # Keep the stored token if the masked placeholder was submitted unchanged.
    if cfg.get("bridge_token") == MASK:
        stored = read_config_file()
        cfg["bridge_token"] = stored.get("bridge_token", "")

    if errors:
        return {"ok": False, "errors": errors}

    write_config_file(cfg)
    return {"ok": True, "config": public_config()}


def autostart_status() -> dict:
    """Whether the at-logon task is registered."""
    try:
        r = subprocess.run(["schtasks", "/Query", "/TN", TASK_NAME],
                           capture_output=True, text=True, timeout=15)
        return {"autostart": r.returncode == 0}
    except Exception:
        return {"autostart": False, "error": "schtasks unavailable"}


def set_autostart(enabled: bool) -> dict:
    try:
        if enabled:
            r = subprocess.run(
                ["schtasks", "/Create", "/TN", TASK_NAME,
                 "/TR", RUNNER_BAT, "/SC", "ONLOGON", "/RL", "HIGHEST", "/F"],
                capture_output=True, text=True, timeout=20)
        else:
            r = subprocess.run(
                ["schtasks", "/Delete", "/TN", TASK_NAME, "/F"],
                capture_output=True, text=True, timeout=20)
        return {"ok": r.returncode == 0, "detail": (r.stdout or r.stderr).strip()}
    except Exception as e:
        return {"ok": False, "detail": str(e)}


# ------------------------------------------------------------------ HTTP
class Handler(BaseHTTPRequestHandler):
    def log_message(self, fmt, *args):
        pass  # keep the console clean

    def _auth(self):
        if not TOKEN:
            return True
        return self.headers.get("X-Control-Token") == TOKEN

    def _send(self, code, body, ctype="application/json; charset=utf-8"):
        data = body.encode("utf-8") if isinstance(body, str) else body
        self.send_response(code)
        self.send_header("Content-Type", ctype)
        self.send_header("Content-Length", str(len(data)))
        self.send_header("Cache-Control", "no-store")
        self.end_headers()
        self.wfile.write(data)

    def _json(self, obj, code=200):
        self._send(code, json.dumps(obj))

    def _read_body(self, max_bytes=262144):
        try:
            length = int(self.headers.get("Content-Length", 0))
            if length > max_bytes:
                return None
            return self.rfile.read(length)
        except Exception:
            return None

    # -- routes --------------------------------------------------------------
    def do_GET(self):
        if not self._auth():
            self._json({"ok": False, "error": "unauthorized"}, 401)
            return
        path = self.path.split("?")[0]
        if path == "/":
            self._send(200, PAGE, "text/html; charset=utf-8")
        elif path == "/api/status":
            self._json(self.status_payload())
        elif path == "/api/config":
            self._json({"ok": True, "config": public_config()})
        elif path == "/api/log":
            self._json({"ok": True, "log": self.log_tail()})
        elif path == "/api/autostart":
            self._json(autostart_status())
        else:
            self._json({"ok": False, "error": "not found"}, 404)

    def do_POST(self):
        if not self._auth():
            self._json({"ok": False, "error": "unauthorized"}, 401)
            return
        path = self.path.split("?")[0]
        if path == "/api/pause":
            self._touch(PAUSE_FILE)
            self._json({"ok": True, "paused": True})
        elif path == "/api/resume":
            self._remove(PAUSE_FILE)
            self._json({"ok": True, "paused": False})
        elif path == "/api/stop":
            supervisor.stop()
            self._json({"ok": True, "running": False})
        elif path == "/api/restart":
            supervisor.restart()
            self._json({"ok": True, "running": True})
        elif path == "/api/train-now":
            self._touch(FORCE_TRAIN_FILE)
            self._json({"ok": True, "note": "train-now marker set"})
        elif path == "/api/config":
            body = self._read_body()
            if body is None:
                self._json({"ok": False, "error": "bad body"}, 400)
                return
            try:
                payload = json.loads(body.decode("utf-8"))
            except Exception:
                self._json({"ok": False, "error": "invalid JSON"}, 400)
                return
            result = apply_save(payload)
            if result["ok"]:
                supervisor.restart()  # apply by restarting the trainer
            self._json(result)
        elif path == "/api/autostart":
            body = self._read_body()
            try:
                enabled = bool(json.loads((body or b"{}").decode("utf-8")).get("enabled"))
            except Exception:
                enabled = True
            self._json(set_autostart(enabled))
        else:
            self._json({"ok": False, "error": "not found"}, 404)

    # -- helpers -------------------------------------------------------------
    def status_payload(self):
        status = {
            "running": supervisor.is_running(),
            "stopped": supervisor.stopped,
            "pid": supervisor.proc.pid if supervisor.is_running() else None,
            "last_exit": supervisor.last_exit,
            "restart_count": supervisor.restart_count,
            "paused": os.path.exists(PAUSE_FILE),
            "train_now_pending": os.path.exists(FORCE_TRAIN_FILE),
            "uptime_sec": int(time.time() - supervisor.started_at) if supervisor.is_running() else None,
            "config_file": CONFIG_FILE,
        }
        try:
            if os.path.isfile(STATUS_FILE):
                with open(STATUS_FILE) as fh:
                    status["trainer"] = json.load(fh)
        except Exception:
            status["trainer"] = None
        status["autostart"] = autostart_status().get("autostart", False)
        return {"ok": True, "status": status}

    def log_tail(self):
        try:
            lines = int(self.headers.get("X-Lines", 60))
        except Exception:
            lines = 60
        lines = max(10, min(500, lines))
        try:
            if not os.path.isfile(LOG_FILE):
                return ""
            with open(LOG_FILE, encoding="utf-8", errors="replace") as fh:
                return "".join(fh.readlines()[-lines:])
        except Exception:
            return ""

    def _touch(self, path):
        if path:
            try:
                with open(path, "a"):
                    os.utime(path, None)
            except Exception:
                pass

    def _remove(self, path):
        if path:
            try:
                if os.path.exists(path):
                    os.remove(path)
            except Exception:
                pass


# ------------------------------------------------------------------ UI page
PAGE = r"""<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Chat trainer control</title>
<style>
:root{--bg:#14121a;--card:#1e1b28;--line:#322d42;--fg:#e8e6f0;--mut:#9a94ad;
--acc:#b18cff;--ok:#3ddc84;--warn:#ffb454;--err:#ff6b6b;--btn:#2a2540}
*{box-sizing:border-box}
body{margin:0;font:14px/1.5 system-ui,Segoe UI,Arial,sans-serif;background:var(--bg);color:var(--fg)}
header{padding:14px 20px;border-bottom:1px solid var(--line);display:flex;align-items:center;gap:14px;flex-wrap:wrap}
header h1{font-size:16px;margin:0;flex:1}
nav a{margin-right:10px;color:var(--acc);text-decoration:none;font-weight:600}
nav a.active{text-decoration:underline}
main{padding:20px;max-width:1100px;margin:0 auto}
.card{background:var(--card);border:1px solid var(--line);border-radius:10px;padding:16px;margin-bottom:16px}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:12px}
.metric{background:var(--btn);border-radius:8px;padding:10px 12px}
.metric .k{font-size:11px;color:var(--mut);text-transform:uppercase;letter-spacing:.5px}
.metric .v{font-size:18px;font-weight:700;margin-top:2px}
.badge{padding:2px 8px;border-radius:999px;font-size:12px;font-weight:700}
.run{background:rgba(61,220,132,.15);color:var(--ok)}
.stop{background:rgba(255,107,107,.15);color:var(--err)}
.pause{background:rgba(255,180,84,.15);color:var(--warn)}
button{background:var(--btn);color:var(--fg);border:1px solid var(--line);border-radius:8px;
padding:8px 14px;font-size:13px;cursor:pointer;margin-right:8px;margin-bottom:8px}
button:hover{border-color:var(--acc)}
button:disabled{opacity:.4;cursor:not-allowed}
button.primary{background:var(--acc);color:#14121a;border:none;font-weight:700}
button.danger{background:rgba(255,107,107,.15);color:var(--err)}
button.warn{background:rgba(255,180,84,.15);color:var(--warn)}
label{display:block;font-size:12px;color:var(--mut);margin:8px 0 2px}
input,select{width:100%;background:#14121a;color:var(--fg);border:1px solid var(--line);
border-radius:6px;padding:6px 8px;font-size:13px}
.cols{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:6px 16px}
pre{background:#0f0d15;border:1px solid var(--line);border-radius:8px;padding:10px;max-height:360px;
overflow:auto;font-size:12px;white-space:pre-wrap}
#toast{position:fixed;bottom:16px;right:16px;background:var(--card);border:1px solid var(--acc);
border-radius:8px;padding:10px 14px;display:none;max-width:380px}
</style>
</head>
<body>
<header>
  <h1>Chat trainer — <span id="host"></span></h1>
  <nav>
    <a href="#panel" data-view="panel" class="active">Trainer</a>
    <a href="#admin" data-view="admin">Admin</a>
  </nav>
</header>
<main>
<!-- ======================= TRAINER PANEL ======================= -->
<section id="view-panel">
  <div class="card">
    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:4px">
      <h2 style="margin:0;font-size:15px">Trainer</h2>
      <span id="statusBadge" class="badge stop">stopped</span>
      <span id="uptime" class="mut" style="color:var(--mut);font-size:12px"></span>
    </div>
    <div style="margin:6px 0 12px;color:var(--mut);font-size:12px" id="statusNote"></div>
    <div>
      <button id="btnPause" class="warn">Pause</button>
      <button id="btnResume" class="primary">Resume</button>
      <button id="btnStop" class="danger">Stop</button>
      <button id="btnRestart" class="primary">Restart AI</button>
      <button id="btnTrainNow">Train now</button>
    </div>
  </div>

  <div class="grid" id="metrics"></div>

  <div class="card">
    <h2 style="margin:0 0 8px;font-size:15px">Log</h2>
    <pre id="log"></pre>
  </div>
</section>

<!-- ======================= ADMIN VIEW ======================= -->
<section id="view-admin" style="display:none">
  <div class="card">
    <h2 style="margin:0 0 4px;font-size:15px">Settings</h2>
    <p style="margin:0 0 8px;color:var(--mut);font-size:12px">
      Saved to <code id="cfgFile"></code>. Saving restarts the trainer to apply.
    </p>
    <div class="cols" id="settingsFields"></div>
    <div style="margin-top:12px">
      <button id="btnSave" class="primary">Save &amp; restart trainer</button>
      <button id="btnDefaults">Reset to defaults</button>
    </div>
  </div>

  <div class="card">
    <h2 style="margin:0 0 8px;font-size:15px">Run at logon</h2>
    <p style="margin:0 0 8px;color:var(--mut);font-size:12px">
      Registers the <code>ChatTrainer</code> scheduled task so this control server
      (and the trainer it supervises) starts when you log into this PC.
    </p>
    <button id="btnAutoOn" class="primary">Enable run at logon</button>
    <button id="btnAutoOff" class="danger">Disable</button>
    <span id="autoState" style="color:var(--mut);font-size:12px;margin-left:6px"></span>
  </div>
</section>
</main>
<div id="toast"></div>

<script>
var state = { cfg: null, status: null, logTimer: null, token: localStorage.getItem('controlToken') || '' };
var $ = function(id){ return document.getElementById(id); };

function req(path, opts){
  opts = opts || {};
  opts.headers = opts.headers || {};
  if (state.token) opts.headers['X-Control-Token'] = state.token;
  if (opts.body && typeof opts.body === 'object' && !(opts.body instanceof FormData)){
    opts.headers['Content-Type'] = 'application/json';
    opts.body = JSON.stringify(opts.body);
  }
  return fetch(path, opts).then(function(r){
    if (r.status === 401){ alert('Unauthorized - set CONTROL_TOKEN on the server.'); throw new Error('401'); }
    return r.json().catch(function(){ return {}; });
  });
}

function toast(msg){
  var t = $('toast'); t.textContent = msg; t.style.display = 'block';
  clearTimeout(toast._h); toast._h = setTimeout(function(){ t.style.display = 'none'; }, 3000);
}

function fmtAge(sec){
  if (sec == null) return '—';
  sec = Math.floor(sec);
  if (sec < 60) return sec + 's';
  if (sec < 3600) return Math.floor(sec/60) + 'm';
  return Math.floor(sec/3600) + 'h ' + Math.floor((sec%3600)/60) + 'm';
}

function renderStatus(st){
  var badge = $('statusBadge');
  if (st.running){ badge.className='badge run'; badge.textContent='running'; }
  else if (st.stopped){ badge.className='badge stop'; badge.textContent='stopped'; }
  else { badge.className='badge stop'; badge.textContent='not running'; }
  $('uptime').textContent = st.running && st.uptime_sec != null ? 'uptime ' + fmtAge(st.uptime_sec) : '';
  var note = [];
  if (st.paused) note.push('PAUSED - training held until Resume');
  if (st.train_now_pending) note.push('train-now requested');
  if (st.last_exit != null) note.push('last exit code: ' + st.last_exit);
  $('statusNote').textContent = note.join('  ·  ');

  $('btnPause').disabled = !st.running;
  $('btnResume').disabled = !st.paused;
  $('btnStop').disabled = !st.running;
  $('btnRestart').disabled = !st.running;

  var t = st.trainer || {};
  var cells = [
    ['Last poll', t.last_poll_at || '—'],
    ['Since id', t.since_id != null ? t.since_id : '—'],
    ['Trained pairs', t.trained_pairs != null ? t.trained_pairs : '—'],
    ['Idle', t.idle_seconds != null ? fmtAge(t.idle_seconds) : '—'],
    ['Phase', t.phase || '—'],
    ['Paused', t.paused ? 'yes' : 'no'],
  ];
  $('metrics').innerHTML = cells.map(function(c){
    return '<div class="metric"><div class="k">'+c[0]+'</div><div class="v">'+c[1]+'</div></div>';
  }).join('');
}

var FIELDS = [
  ['server_base','text','Server URL'],
  ['bridge_token','password','Bridge token'],
  ['model_dir','text','Model dir (HF base)'],
  ['output_adapter','text','Output adapter file'],
  ['base_model','text','Base model name (upload)'],
  ['poll_seconds','number','Poll seconds'],
  ['min_new_pairs','number','Min new pairs to train'],
  ['max_pairs_per_run','number','Max pairs per run'],
  ['lora_r','number','LoRA r'],
  ['lora_alpha','number','LoRA alpha'],
  ['lora_dropout','number','LoRA dropout'],
  ['max_len','number','Max token length'],
  ['steps','number','Training steps'],
  ['lr','number','Learning rate'],
  ['required_idle_seconds','number','Required idle seconds'],
  ['cpu_threads','number','CPU threads (0=half)'],
  ['state_file','text','State file'],
  ['pause_file','text','Pause file'],
  ['force_train_file','text','Force-train file'],
  ['log_file','text','Log file'],
  ['status_file','text','Status file'],
];

function renderConfig(cfg){
  $('cfgFile').textContent = cfg._config_file || '';
  $('settingsFields').innerHTML = FIELDS.map(function(f){
    var val = cfg[f[0]] != null ? cfg[f[0]] : '';
    return '<div><label>'+f[2]+'</label><input type="'+f[1]+'" data-key="'+f[0]+'" value="'+
      String(val).replace(/"/g,'&quot;')+'"></div>';
  }).join('');
}

function collectConfig(){
  var out = {};
  document.querySelectorAll('#settingsFields input[data-key]').forEach(function(inp){
    out[inp.getAttribute('data-key')] = inp.value;
  });
  return out;
}

function refreshStatus(){
  return req('/api/status').then(function(d){
    if (d.status){ state.status = d.status; renderStatus(d.status); }
  }).catch(function(){});
}

function refreshConfig(){
  return req('/api/config').then(function(d){
    if (d.config){ state.cfg = d.config; renderConfig(d.config); }
  }).catch(function(){});
}

function refreshLog(){
  req('/api/log').then(function(d){ if (d.log) $('log').textContent = d.log; }).catch(function(){});
}

function refreshAutostart(){
  req('/api/autostart').then(function(d){
    $('autoState').textContent = d.autostart ? 'enabled' : 'disabled';
  }).catch(function(){});
}

$('btnPause').onclick = function(){ req('/api/pause','POST').then(refreshStatus); };
$('btnResume').onclick = function(){ req('/api/resume','POST').then(refreshStatus); };
$('btnStop').onclick = function(){
  if (!confirm('Stop the trainer? It will not auto-restart until you press Restart AI.')) return;
  req('/api/stop','POST').then(refreshStatus);
};
$('btnRestart').onclick = function(){ req('/api/restart','POST').then(refreshStatus); };
$('btnTrainNow').onclick = function(){
  req('/api/train-now','POST').then(function(){ toast('Train-now requested'); refreshStatus(); });
};
$('btnSave').onclick = function(){
  req('/api/config',{ method:'POST', body: collectConfig() }).then(function(d){
    if (d.ok){ toast('Saved - trainer restarted with new settings'); refreshConfig(); }
    else if (d.errors){ toast('Invalid: ' + JSON.stringify(d.errors)); }
    else { toast('Save failed'); }
  });
};
$('btnDefaults').onclick = function(){
  if (!confirm('Reset all settings to defaults?')) return;
  req('/api/config',{ method:'POST', body: {} }).then(function(){ refreshConfig(); });
};
$('btnAutoOn').onclick = function(){ req('/api/autostart',{method:'POST',body:{enabled:true}}).then(function(d){ toast(d.detail || 'ok'); refreshAutostart(); }); };
$('btnAutoOff').onclick = function(){ req('/api/autostart',{method:'POST',body:{enabled:false}}).then(function(d){ toast(d.detail || 'ok'); refreshAutostart(); }); };

document.querySelectorAll('nav a').forEach(function(a){
  a.onclick = function(e){
    e.preventDefault();
    document.querySelectorAll('nav a').forEach(function(x){ x.classList.remove('active'); });
    a.classList.add('active');
    var v = a.getAttribute('data-view');
    $('view-panel').style.display = v === 'panel' ? '' : 'none';
    $('view-admin').style.display = v === 'admin' ? '' : 'none';
    if (v === 'admin'){ refreshConfig(); refreshAutostart(); }
  };
});

$('host').textContent = location.host;

refreshStatus(); refreshConfig(); refreshLog(); refreshAutostart();
state.logTimer = setInterval(refreshLog, 4000);
setInterval(refreshStatus, 3000);
</script>
</body>
</html>
"""


# ------------------------------------------------------------------ main
def main():
    # Seed a config file from defaults if none exists yet.
    if not os.path.isfile(CONFIG_FILE):
        write_config_file(dict(DEFAULT_CONFIG))

    # Bring the trainer up if it wasn't explicitly stopped.
    supervisor.spawn()

    server = ThreadingHTTPServer((HOST, PORT), Handler)
    print("[control] listening on http://%s:%d  (ctrl-c to stop)" % (HOST, PORT), flush=True)
    try:
        while True:
            server.handle_request()
            supervisor.tick()
    except KeyboardInterrupt:
        pass
    finally:
        server.server_close()


if __name__ == "__main__":
    main()