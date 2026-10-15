import React, { useCallback, useEffect, useMemo, useReducer, useRef, useState } from 'react';
import { createRoot } from 'react-dom/client';
import './styles.css';

const config = window.__VIDEO_EDITOR__ || {};

const FILTER_DEFAULTS = { brightness: 0, contrast: 1, saturation: 1, grayscale: false, sepia: false, blur: 0, hue: 0 };
const CROP_DEFAULTS = { zoom: 1, panX: 0.5, panY: 0.5, mirrorH: false, mirrorV: false };

const FILTER_PRESETS = [
  { name: 'None', values: { ...FILTER_DEFAULTS } },
  { name: 'Cinematic', values: { brightness: -0.05, contrast: 1.3, saturation: 0.85, hue: -10, grayscale: false, sepia: false, blur: 0 } },
  { name: 'Warm', values: { brightness: 0.05, contrast: 1.1, saturation: 1.2, hue: 15, grayscale: false, sepia: false, blur: 0 } },
  { name: 'Cool', values: { brightness: 0, contrast: 1.1, saturation: 0.9, hue: -20, grayscale: false, sepia: false, blur: 0 } },
  { name: 'Noir', values: { brightness: -0.05, contrast: 1.4, saturation: 0, hue: 0, grayscale: true, sepia: false, blur: 0 } },
  { name: 'Vintage', values: { brightness: 0.03, contrast: 1.15, saturation: 0.7, hue: 10, grayscale: false, sepia: true, blur: 0 } },
  { name: 'Vivid', values: { brightness: 0.03, contrast: 1.25, saturation: 1.5, hue: 0, grayscale: false, sepia: false, blur: 0 } },
  { name: 'Muted', values: { brightness: 0, contrast: 0.95, saturation: 0.5, hue: 0, grayscale: false, sepia: false, blur: 0 } },
  { name: 'Dreamy', values: { brightness: 0.08, contrast: 0.9, saturation: 0.8, hue: 5, grayscale: false, sepia: false, blur: 1.5 } },
  { name: 'HiCon', values: { brightness: 0, contrast: 1.8, saturation: 1.1, hue: 0, grayscale: false, sepia: false, blur: 0 } },
];

const TOOLS = [
  { id: 'cursor', label: 'Select', key: 'V', icon: 'cursor' },
  { id: 'razor', label: 'Razor', key: 'C', icon: 'razor' },
  { id: 'marker', label: 'Marker', key: 'M', icon: 'marker' },
  { id: 'blur-rect', label: 'Blur box', key: 'B', icon: 'blurBox' },
  { id: 'blur-brush', label: 'Blur brush', key: 'Shift+B', icon: 'brush' },
];

const RESOLUTIONS = [
  { id: 'src', label: 'Source', w: 0, h: 0 },
  { id: '1080', label: '1080p', w: 1920, h: 1080 },
  { id: '720', label: '720p', w: 1280, h: 720 },
  { id: '480', label: '480p', w: 854, h: 480 },
  { id: '360', label: '360p', w: 640, h: 360 },
];

const SHORTCUTS = [
  ['Space / K', 'Play or pause'],
  ['J / L', 'Jump 2s back / forward'],
  ['Left / Right', 'Step one frame'],
  ['Shift + arrows', 'Step 10 frames'],
  ['Home / End', 'Go to in / out point'],
  ['V / C / M / B', 'Select / razor / marker / blur box'],
  ['Shift + B', 'Blur brush'],
  ['F', 'Fullscreen preview'],
  ['+ / -', 'Zoom timeline'],
  ['Ctrl + wheel', 'Zoom timeline'],
  ['Ctrl + Z / Ctrl + Shift + Z', 'Undo / redo'],
  ['Ctrl + S', 'Save now'],
  ['Delete', 'Remove selection'],
  ['Ctrl + D', 'Duplicate selection'],
  ['?', 'Show or hide this list'],
];

const RULER_H = 26;
const TRACK_H = 42;
const HEAD_W = 104;
const BASE_PPS = 80;
const HISTORY_LIMIT = 100;

function clone(v) {
  return typeof structuredClone === 'function' ? structuredClone(v) : JSON.parse(JSON.stringify(v));
}

function ensureProject(project, sourceId) {
  const next = clone(project || {});
  next.tracks ||= [];
  next.text_overlays ||= [];
  next.blur_regions ||= [];
  next.filters = { ...FILTER_DEFAULTS, ...(next.filters || {}) };
  next.crop = { ...CROP_DEFAULTS, ...(next.crop || {}) };
  next.speed = Number(next.speed) > 0 ? Number(next.speed) : 1;
  next.markers ||= [];
  let video = next.tracks.find((t) => t.type === 'video');
  let audio = next.tracks.find((t) => t.type === 'audio');
  let captions = next.tracks.find((t) => t.type === 'captions');
  if (!video) next.tracks.push(video = { type: 'video', clips: [], visible: true, locked: false });
  if (!audio) next.tracks.push(audio = { type: 'audio', clips: [], visible: true, locked: false, label: 'Audio 1' });
  if (!captions) next.tracks.push(captions = { type: 'captions', items: [], visible: true, locked: false, label: 'Captions' });
  video.clips ||= []; audio.clips ||= []; captions.items ||= [];
  video.clips[0] ||= { asset_id: sourceId, start: 0, end: 0, transition: 'none' };
  audio.clips[0] ||= { asset_id: sourceId, volume: 1, fade_in: 0, fade_out: 0, muted: false };
  if (audio.clips[0].muted === undefined) audio.clips[0].muted = false;
  for (const item of next.text_overlays) { item.opacity = item.opacity ?? 1; item.padding = item.padding ?? 8; item.shadow = item.shadow ?? false; }
  for (const item of captions.items) { item.opacity = item.opacity ?? 1; item.padding = item.padding ?? 8; item.shadow = item.shadow ?? false; }
  next.blur_regions.forEach((r, i) => {
    if (r.id === undefined || r.id === null) r.id = Date.now() + i;
    r.strength = Number(r.strength) || 10;
  });
  return next;
}

function fmt(s) { s = Math.max(0, Number(s) || 0); return `${Math.floor(s / 60)}:${(s % 60).toFixed(1).padStart(4, '0')}`; }
function fmtTC(s) { s = Math.max(0, Number(s) || 0); const f = Math.floor((s % 1) * 30); return `${Math.floor(s / 60).toString().padStart(2, '0')}:${Math.floor(s % 60).toString().padStart(2, '0')}:${f.toString().padStart(2, '0')}`; }
function tickStep(pps) {
  const steps = [0.1, 0.2, 0.25, 0.5, 1, 2, 5, 10, 15, 30, 60, 120, 300, 600];
  for (const s of steps) if (s * pps >= 70) return s;
  return 900;
}
function tickLabel(s, step) { return step < 1 ? `${s.toFixed(step < 0.25 ? 2 : 1)}s` : fmt(s); }

function cssFilter(f) {
  const p = [];
  if (f.brightness) p.push(`brightness(${1 + Number(f.brightness)})`);
  if (Number(f.contrast) !== 1) p.push(`contrast(${f.contrast})`);
  if (Number(f.saturation) !== 1) p.push(`saturate(${f.saturation})`);
  if (f.grayscale) p.push('grayscale(1)');
  if (f.sepia) p.push('sepia(1)');
  if (Number(f.blur) > 0) p.push(`blur(${f.blur}px)`);
  if (Number(f.hue) !== 0) p.push(`hue-rotate(${f.hue}deg)`);
  return p.join(' ') || 'none';
}

function cssCrop(c) {
  const p = [];
  if (Number(c.zoom) > 1) p.push(`scale(${c.zoom})`);
  if (c.mirrorH) p.push('scaleX(-1)');
  if (c.mirrorV) p.push('scaleY(-1)');
  if (!p.length) return 'none';
  const tx = (0.5 - (c.panX ?? 0.5)) * (Number(c.zoom) - 1) * 100;
  const ty = (0.5 - (c.panY ?? 0.5)) * (Number(c.zoom) - 1) * 100;
  return `${p.join(' ')} translate(${tx}%,${ty}%)`;
}

function clamp(v, lo, hi) { return Math.max(lo, Math.min(hi, v)); }

function densify(points, r) {
  if (points.length < 2) return points;
  const out = [points[0]];
  const step = Math.max(0.004, r * 0.55);
  for (let i = 1; i < points.length; i++) {
    const [x0, y0] = points[i - 1];
    const [x1, y1] = points[i];
    const dist = Math.hypot(x1 - x0, y1 - y0);
    const n = Math.max(1, Math.ceil(dist / step));
    for (let k = 1; k <= n; k++) out.push([x0 + ((x1 - x0) * k) / n, y0 + ((y1 - y0) * k) / n]);
  }
  return out;
}

const reducer = (state, action) => {
  switch (action.type) {
    case 'edit': {
      const next = clone(state.project);
      action.updater(next);
      return { project: next, past: [...state.past, state.project].slice(-HISTORY_LIMIT), future: [], rev: state.rev + 1 };
    }
    case 'live': {
      const next = clone(state.project);
      action.updater(next);
      return { ...state, project: next, rev: state.rev + 1 };
    }
    case 'snapshot': {
      if (!action.snap || action.snap === state.project) return state;
      return { ...state, past: [...state.past, action.snap].slice(-HISTORY_LIMIT), future: [] };
    }
    case 'undo': {
      if (!state.past.length) return state;
      const past = state.past.slice();
      const prev = past.pop();
      return { project: prev, past, future: [state.project, ...state.future].slice(0, HISTORY_LIMIT), rev: state.rev + 1 };
    }
    case 'redo': {
      if (!state.future.length) return state;
      const [head, ...future] = state.future;
      return { project: head, past: [...state.past, state.project].slice(-HISTORY_LIMIT), future, rev: state.rev + 1 };
    }
    case 'reset': {
      return { project: action.project, past: [], future: [], rev: state.rev + 1 };
    }
    default: return state;
  }
};

const ICONS = {
  cursor: <path d="M5 2l14 9-6.5 1.6L9.6 20 5 2z" />,
  razor: <path d="M8 20l9-9-3-3-9 9-1 5 4-2zm7.5-13.5l3-3a2.1 2.1 0 013 3l-3 3-3-3z" />,
  marker: <path d="M10 2a4 4 0 00-4 4c0 3.5 4 8 4 8s4-4.5 4-8a4 4 0 00-4-4zm0 5.6A1.6 1.6 0 1110 4.4a1.6 1.6 0 010 3.2zM6 22l7-9 7 9H6z" />,
  blurBox: <g><rect x="3" y="3" width="18" height="18" rx="3" fill="none" stroke="currentColor" strokeWidth="2" opacity=".55" /><rect x="8" y="8" width="8" height="8" rx="1.5" /></g>,
  brush: <path d="M14.6 2.4a4.6 4.6 0 00-4.1 6.4L3 16.2V21h4.8l7.4-7.4a4.6 4.6 0 006.4-4.1c-1.5 1-2.8 1.3-4 1.2-1.5-.1-2.6-.9-3.3-1.8-.6-.8-.9-1.6-.9-2.4 0-.8.3-1.6.8-2.3.7-1.1 1-1.4.8-1.8z" />,
  undo: <path d="M8 5v4H4a8 8 0 111 8.2l-1.7-1A6 6 0 105.8 9H8v4l5-4-5-4z" />,
  redo: <path d="M16 5v4h4a8 8 0 10-1 8.2l1.7-1A6 6 0 1118.2 9H16v4l-5-4 5-4z" />,
  save: <path d="M4 3h13l3 3v15H4V3zm3 0v6h9V5H7zm0 10h6v7H7v-7z" />,
  play: <path d="M7 4l13 8-13 8V4z" />,
  full: <path d="M3 3h7v2H5v5H3V3zm11 0h7v7h-2V5h-5V3zM3 14h2v5h5v2H3v-7zm16 0h2v7h-7v-2h5v-5z" />,
  help: <path d="M12 2a10 10 0 100 20 10 10 0 000-20zm.9 15.4h-1.8v-1.8h1.8v1.8zm1.6-5.1c-.6.7-1.5 1.2-1.7 1.9-.1.5-.1.9-.1 1.3h-1.7c0-.6.1-1.1.3-1.7.3-.9 1.2-1.5 1.6-2 .4-.5.4-1.2.1-1.7-.4-.7-1.5-.8-2.1-.3-.5.4-.7 1-.7 1.9H8.4c0-1.5.6-2.7 1.8-3.3 1.2-.6 2.8-.5 3.8.3.9.7 1.3 1.7 1.1 2.7-.1.7-.4 1-.6 1.9z" />,
  clip: <path d="M4 5h16v14H4V5zm2 2v10h12V7H6z" />,
  close: <path d="M6 6l12 12-1.4 1.4L6 7.4 6 6zm12 0L6 18l1.4 1.4L18 7.4 18 6z" />,
};

function Icon({ name, size = 16 }) {
  return (
    <svg className="nle-ico" viewBox="0 0 24 24" width={size} height={size} fill="currentColor" aria-hidden="true" focusable="false">
      {ICONS[name] || null}
    </svg>
  );
}

function Section({ title, actions, children, id }) {
  return (
    <section className="nle-sec" id={id}>
      <h4 className="nle-sec-h">
        <span className="nle-sec-t">{title}</span>
        {actions ? <span className="nle-sec-a">{actions}</span> : null}
      </h4>
      <div className="nle-sec-b">{children}</div>
    </section>
  );
}

function Slider({ label, value, min, max, step, onChange, format }) {
  return (
    <label className="nle-field nle-slider">
      <span className="nle-field-l">{label}</span>
      <span className="nle-field-v">{format ? format(value) : value}</span>
      <input type="range" min={min} max={max} step={step} value={value} onChange={(e) => onChange(Number(e.target.value))} />
    </label>
  );
}

function Num({ label, value, step = 0.1, min = 0, max = 86400, onChange, hint }) {
  return (
    <label className="nle-field nle-num">
      <span className="nle-field-l">{label}</span>
      <input type="number" step={step} min={min} max={max} value={value} onChange={(e) => onChange(Number(e.target.value) || 0)} />
      {hint ? <span className="nle-field-v">{hint}</span> : null}
    </label>
  );
}

function Check({ label, checked, onChange }) {
  return (
    <label className="nle-field nle-check">
      <input type="checkbox" checked={!!checked} onChange={(e) => onChange(e.target.checked)} />
      <span>{label}</span>
    </label>
  );
}

function Row({ children }) { return <div className="nle-row">{children}</div>; }

function Timeline(props) {
  const { total, timelineWidth, ticks, step, project, captionItems, currentTime, selection, onSelect, onDown, onMove, onUp, scrollRef, playheadRef } = props;
  const pct = (t) => `${(Math.max(0, Math.min(total, Number(t) || 0)) / total) * 100}%`;
  return (
    <div className="nle-timeline">
      <div className="nle-tl-heads" style={{ width: HEAD_W }}>
        <div className="nle-tl-gap" style={{ height: RULER_H }} />
        <div className="nle-tl-head nle-tl-head-v"><span>V1</span><em>{props.resLabel}</em></div>
        <div className="nle-tl-head nle-tl-head-o"><span>Overlays</span><em>{project.text_overlays.length}</em></div>
        <div className="nle-tl-head nle-tl-head-c"><span>Captions</span><em>{captionItems.length}</em></div>
      </div>
      <div className="nle-tl-scroll" ref={scrollRef} onPointerDown={onDown} onPointerMove={onMove} onPointerUp={onUp} onPointerLeave={onUp}>
        <div className="nle-tl-area" style={{ width: timelineWidth, height: RULER_H + TRACK_H * 3 }}>
          <div className="nle-tl-ruler" style={{ height: RULER_H }}>
            {ticks.map((t) => (
              <div key={t} className="nle-tl-tick" style={{ left: pct(t) }}>
                <span>{tickLabel(t, step)}</span>
              </div>
            ))}
          </div>

          <div className="nle-tl-lane" style={{ top: RULER_H, height: TRACK_H }}>
            <div className="nle-clip is-video" data-target="clip" data-idx="0" style={{ left: pct(props.clipStart), width: `max(2px, ${(props.clipEnd - props.clipStart) / total * 100}%)` }}>
              <span className="nle-clip-h is-l" data-handle="start" data-target="clip" data-idx="0" />
              <span className="nle-clip-n">Video</span>
              <span className="nle-clip-h is-r" data-handle="end" data-target="clip" data-idx="0" />
            </div>
          </div>

          <div className="nle-tl-lane" style={{ top: RULER_H + TRACK_H, height: TRACK_H }}>
            {project.text_overlays.map((item, i) => (
              <div key={`o${i}`} className={`nle-clip is-overlay${selection?.type === 'overlay' && selection.idx === i ? ' is-sel' : ''}`}
                data-target="overlay" data-idx={i}
                style={{ left: pct(item.start), width: `max(3px, ${(item.end - item.start) / total * 100}%)` }}
                onClick={(e) => { e.stopPropagation(); onSelect({ type: 'overlay', idx: i }); }}>
                <span className="nle-clip-h is-l" data-handle="start" data-target="overlay" data-idx={i} />
                <span className="nle-clip-n">{item.text || '(empty)'}</span>
                <span className="nle-clip-h is-r" data-handle="end" data-target="overlay" data-idx={i} />
              </div>
            ))}
          </div>

          <div className="nle-tl-lane" style={{ top: RULER_H + TRACK_H * 2, height: TRACK_H }}>
            {captionItems.map((item, i) => (
              <div key={`c${i}`} className={`nle-clip is-caption${selection?.type === 'caption' && selection.idx === i ? ' is-sel' : ''}`}
                data-target="caption" data-idx={i}
                style={{ left: pct(item.start), width: `max(3px, ${(item.end - item.start) / total * 100}%)` }}
                onClick={(e) => { e.stopPropagation(); onSelect({ type: 'caption', idx: i }); }}>
                <span className="nle-clip-h is-l" data-handle="start" data-target="caption" data-idx={i} />
                <span className="nle-clip-n">{item.text || '(empty)'}</span>
                <span className="nle-clip-h is-r" data-handle="end" data-target="caption" data-idx={i} />
              </div>
            ))}
          </div>

          {project.markers.map((m, i) => (
            <div key={`m${i}`} className="nle-tl-marker" style={{ left: pct(m.time) }} title={m.label}
              onClick={(e) => { e.stopPropagation(); props.onSeek(m.time); }}>
              <span className="nle-tl-marker-f" />
            </div>
          ))}

          <div className="nle-tl-play" ref={playheadRef} style={{ left: pct(currentTime) }}>
            <span className="nle-tl-play-h" />
            <span className="nle-tl-play-l" />
          </div>
        </div>
      </div>
    </div>
  );
}

function Editor() {
  const videoRef = useRef(null);
  const stageRef = useRef(null);
  const scrollRef = useRef(null);
  const saveTimer = useRef(null);
  const drag = useRef(null);
  const skipNextSave = useRef(true);
  const loopRef = useRef(false);
  const saveChain = useRef(Promise.resolve());
  const lastSaved = useRef('');
  const versionRef = useRef(config.version);
  const playheadRef = useRef(null);

  const [state, dispatch] = useReducer(reducer, null, () => {
    const project = ensureProject(config.project, config.sourceId);
    return { project, past: [], future: [], rev: 0 };
  });
  const project = state.project;
  const projectRef = useRef(project);
  projectRef.current = project;

  const [title, setTitle] = useState(config.title || 'Untitled');
  const [version, setVersion] = useState(config.version);
  const [duration, setDuration] = useState(0);
  const [srcSize, setSrcSize] = useState({ w: 0, h: 0 });
  const [stageBox, setStageBox] = useState({ w: 0, h: 0 });
  const [currentTime, setCurrentTime] = useState(0);
  const [playing, setPlaying] = useState(false);
  const [status, setStatus] = useState('Ready');
  const [saveState, setSaveState] = useState('saved');
  const [tool, setTool] = useState('cursor');
  const [snap, setSnap] = useState(true);
  const [zoom, setZoom] = useState(1);
  const [loop, setLoop] = useState(false);
  const [selection, setSelection] = useState(null);
  const [fullscreen, setFullscreen] = useState(false);
  const [saveOverOriginal, setSaveOverOriginal] = useState(false);
  const [exportRes, setExportRes] = useState('src');
  const [exportStatus, setExportStatus] = useState('');
  const [exportLink, setExportLink] = useState('');
  const [exportStream, setExportStream] = useState('');
  const [exportProgress, setExportProgress] = useState(-1);
  const [brushRadius, setBrushRadius] = useState(0.045);
  const [blurStrength, setBlurStrength] = useState(10);
  const [blurSeconds, setBlurSeconds] = useState(5);
  const [overlayDraft, setOverlayDraft] = useState({ text: '', font_size: 42, color: '#ffffff', opacity: 1, padding: 10, shadow: true });
  const [captionDraft, setCaptionDraft] = useState({ text: '', opacity: 1, padding: 8, shadow: true });
  const [showHelp, setShowHelp] = useState(false);

  const videoTrack = project.tracks.find((t) => t.type === 'video');
  const audioTrack = project.tracks.find((t) => t.type === 'audio');
  const captionTrack = project.tracks.find((t) => t.type === 'captions');
  const clip = videoTrack?.clips[0] || { start: 0, end: 0, transition: 'none' };
  const audio = audioTrack?.clips[0] || { volume: 1, muted: false, fade_in: 0, fade_out: 0 };
  const crop = project.crop || CROP_DEFAULTS;
  const filterPresetName = FILTER_PRESETS.find((p) => JSON.stringify(p.values) === JSON.stringify(project.filters))?.name || 'Custom';

  const total = duration || clip.end || 1;
  const clipStart = Math.max(0, Number(clip.start) || 0);
  const clipEnd = Number(clip.end) > clipStart ? Number(clip.end) : total;
  const srcW = srcSize.w;

  const PIXELS_PER_SEC = BASE_PPS * zoom;
  const timelineWidth = Math.max(600, total * PIXELS_PER_SEC);
  const canUndo = state.past.length > 0;
  const canRedo = state.future.length > 0;
  const signature = useMemo(() => JSON.stringify([project, title]), [project, title]);
  const dirty = lastSaved.current !== '' && lastSaved.current !== signature;

  loopRef.current = loop;
  useEffect(() => { versionRef.current = version; }, [version]);

  const fitRect = useCallback(() => {
    const stage = stageRef.current; if (!stage) return null;
    const fit = stage.querySelector('.nle-stage-fit');
    return (fit || stage).getBoundingClientRect();
  }, []);

  const edit = useCallback((updater) => dispatch({ type: 'edit', updater }), []);
  const live = useCallback((updater) => dispatch({ type: 'live', updater }), []);
  const commitDrag = useCallback((snapBefore) => dispatch({ type: 'snapshot', snap: snapBefore }), []);
  const listOf = useCallback((next, target) => (target === 'overlay' ? next.text_overlays : next.tracks.find((x) => x.type === 'captions').items), []);

  const save = useCallback(async (force) => {
    if (!force && signature === lastSaved.current) { setSaveState('saved'); return; }
    setSaveState('saving');
    const run = saveChain.current.then(async () => {
      try {
        const r = await fetch(config.saveUrl, {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
          body: new URLSearchParams({
            _token: config.token,
            title,
            version: String(versionRef.current),
            project: JSON.stringify(projectRef.current),
          }),
        });
        const d = await r.json().catch(() => ({}));
        if (!r.ok) throw new Error(d.error || 'Save failed');
        versionRef.current = d.version;
        setVersion(d.version);
        lastSaved.current = JSON.stringify([projectRef.current, title]);
        setSaveState('saved');
        setStatus('Saved ' + new Date().toLocaleTimeString());
      } catch (e) {
        setSaveState('error');
        setStatus(e.message || 'Save failed');
      }
    });
    saveChain.current = run.then(() => {}, () => {});
    return run;
  }, [signature, title]);

  useEffect(() => {
    if (skipNextSave.current) { skipNextSave.current = false; lastSaved.current = signature; return; }
    clearTimeout(saveTimer.current);
    saveTimer.current = setTimeout(() => {
      if (lastSaved.current !== JSON.stringify([projectRef.current, title])) save(true);
    }, 1100);
    return () => clearTimeout(saveTimer.current);
  }, [signature, save, title]);

  useEffect(() => {
    const warn = (e) => { if (lastSaved.current !== '' && lastSaved.current !== JSON.stringify([projectRef.current, title])) { e.preventDefault(); e.returnValue = ''; } };
    window.addEventListener('beforeunload', warn);
    return () => window.removeEventListener('beforeunload', warn);
  }, []);

  useEffect(() => {
    const el = stageRef.current; if (!el) return;
    const ro = new ResizeObserver(() => setStageBox({ w: el.clientWidth, h: el.clientHeight }));
    ro.observe(el);
    setStageBox({ w: el.clientWidth, h: el.clientHeight });
    return () => ro.disconnect();
  }, []);

  useEffect(() => {
    const onKey = (e) => { if (e.type === 've:fullscreen') setFullscreen((f) => !f); };
    window.addEventListener('ve:fullscreen', onKey);
    return () => window.removeEventListener('ve:fullscreen', onKey);
  }, []);

  const frame = useMemo(() => {
    if (!srcW || !srcSize.h || !stageBox.w || !stageBox.h) return null;
    const s = Math.min(stageBox.w / srcW, stageBox.h / srcSize.h);
    return { w: Math.max(1, Math.round(srcW * s)), h: Math.max(1, Math.round(srcSize.h * s)) };
  }, [srcW, srcSize.h, stageBox]);

  const cancel = useCallback(() => {
    if (!window.confirm('Discard every change made since this page was opened?')) return;
    if (videoRef.current) { videoRef.current.pause(); videoRef.current.currentTime = 0; }
    skipNextSave.current = true;
    lastSaved.current = '';
    dispatch({ type: 'reset', project: ensureProject(config.project, config.sourceId) });
    setTitle(config.title || 'Untitled');
    setCurrentTime(0); setPlaying(false); setSelection(null); setStatus('Reverted to the last saved version');
  }, []);

  useEffect(() => {
    const v = videoRef.current; if (!v) return;
    v.playbackRate = project.speed || 1;
    v.volume = audio.muted ? 0 : clamp(Number(audio.volume ?? 1), 0, 2);
  }, [project.speed, audio.volume, audio.muted]);

  /* The transport follows the element itself, so the glyph is right even when
     playback starts or stops without the button (keyboard, end of clip, or a
     browser that refuses play()). */
  useEffect(() => {
    const v = videoRef.current; if (!v) return;
    const onPlay = () => setPlaying(true);
    const onPause = () => setPlaying(false);
    v.addEventListener('play', onPlay);
    v.addEventListener('pause', onPause);
    return () => { v.removeEventListener('play', onPlay); v.removeEventListener('pause', onPause); };
  }, []);

  const scrollToTime = useCallback((t) => {
    const el = scrollRef.current; if (!el) return;
    const x = t * PIXELS_PER_SEC;
    const left = el.scrollLeft, w = el.clientWidth;
    if (x < left + 40 || x > left + w - 60) el.scrollLeft = Math.max(0, x - w * 0.4);
  }, [PIXELS_PER_SEC]);

  const onTimeUpdate = useCallback(() => {
    const v = videoRef.current; if (!v) return;
    setCurrentTime(v.currentTime);
    const boundary = Number(clip.end) > clipStart ? clipEnd : v.duration;
    if (boundary > 0 && v.currentTime >= boundary) {
      if (loopRef.current) v.currentTime = clipStart;
      else { v.pause(); v.currentTime = clipStart; setPlaying(false); }
      setCurrentTime(clipStart);
    }
  }, [clipStart, clipEnd]);

  const playPause = useCallback(() => {
    const v = videoRef.current; if (!v) return;
    if (!v.paused) { v.pause(); setPlaying(false); return; }
    if (v.currentTime < clipStart || v.currentTime >= clipEnd - 0.02) v.currentTime = clipStart;
    const started = v.play();
    if (started && typeof started.catch === 'function') {
      started.catch((e) => { setPlaying(false); setStatus('Playback could not start: ' + (e && e.message ? e.message : 'blocked by the browser')); });
    }
  }, [clipStart, clipEnd]);

  const seekTo = useCallback((t) => {
    t = clamp(t, 0, total);
    if (videoRef.current) videoRef.current.currentTime = t;
    setCurrentTime(t);
  }, [total]);

  const stepFrame = useCallback((d) => {
    const v = videoRef.current; if (!v) return;
    if (!v.paused) { v.pause(); setPlaying(false); }
    const t = clamp(v.currentTime + d * (1 / 30), clipStart, clipEnd);
    v.currentTime = t; setCurrentTime(t);
  }, [clipStart, clipEnd]);

  const nudge = useCallback((d) => {
    const v = videoRef.current; if (!v) return;
    const t = clamp(v.currentTime + d, clipStart, clipEnd);
    v.currentTime = t; setCurrentTime(t);
  }, [clipStart, clipEnd]);

  const timeFromEvent = useCallback((e) => {
    const el = scrollRef.current; if (!el) return 0;
    return (e.clientX - el.getBoundingClientRect().left + el.scrollLeft) / PIXELS_PER_SEC;
  }, [PIXELS_PER_SEC]);

  const snapTargets = useMemo(() => {
    const list = [0, total, clipStart, clipEnd, currentTime];
    for (const m of project.markers) list.push(Number(m.time) || 0);
    for (const o of project.text_overlays) { list.push(o.start, o.end); }
    for (const c of captionTrack.items) { list.push(c.start, c.end); }
    for (const b of project.blur_regions) { list.push(b.start, b.end); }
    return list.filter((v) => Number.isFinite(v));
  }, [project.markers, project.text_overlays, project.blur_regions, captionTrack.items, clipStart, clipEnd, currentTime, total]);

  const applySnap = useCallback((t) => {
    if (!snap) return t;
    const tol = 8 / PIXELS_PER_SEC;
    let best = t, bestD = tol;
    for (const s of snapTargets) { const d = Math.abs(s - t); if (d <= bestD) { bestD = d; best = s; } }
    return best;
  }, [snap, PIXELS_PER_SEC, snapTargets]);

  const onTimelineDown = useCallback((e) => {
    const handleEl = e.target.closest('[data-handle]');
    if (handleEl) {
      drag.current = {
        kind: 'handle', type: handleEl.dataset.handle, target: handleEl.dataset.target,
        idx: Number(handleEl.dataset.idx), last: timeFromEvent(e), base: clone(projectRef.current),
      };
      handleEl.setPointerCapture(e.pointerId);
      return;
    }
    const t = timeFromEvent(e);
    if (tool === 'razor') {
      e.stopPropagation();
      const at = Number(t.toFixed(2));
      edit((n) => {
        for (const item of n.text_overlays) {
          if (at > item.start + 0.05 && at < item.end - 0.05) {
            n.text_overlays.push({ ...clone(item), start: at });
            item.end = at;
          }
        }
        for (const item of n.tracks.find((x) => x.type === 'captions').items) {
          if (at > item.start + 0.05 && at < item.end - 0.05) {
            n.tracks.find((x) => x.type === 'captions').items.push({ ...clone(item), start: at });
            item.end = at;
          }
        }
      });
      return;
    }
    if (tool === 'marker') {
      e.stopPropagation();
      const at = Number(t.toFixed(2));
      edit((n) => n.markers.push({ time: at, label: `Marker ${n.markers.length + 1}` }));
      return;
    }
    const clipEl = e.target.closest('.nle-clip');
    if (clipEl) {
      const target = clipEl.dataset.target;
      const idx = Number(clipEl.dataset.idx);
      setSelection({ type: target, idx });
      drag.current = { kind: 'move', target, idx, last: t, base: clone(projectRef.current) };
      clipEl.setPointerCapture(e.pointerId);
      return;
    }
    drag.current = { kind: 'scrub' };
    seekTo(applySnap(t));
    scrollRef.current?.setPointerCapture(e.pointerId);
  }, [tool, timeFromEvent, edit, seekTo, applySnap]);

  const onTimelineMove = useCallback((e) => {
    const d = drag.current; if (!d) return;
    const t = timeFromEvent(e);
    if (d.kind === 'scrub') { seekTo(applySnap(t)); return; }
    const dt = t - (d.last ?? t);
    d.last = t;
    live((n) => {
      if (d.target === 'clip') {
        const c = n.tracks.find((x) => x.type === 'video').clips[0];
        const len = Math.max(0.1, Number(c.end) - Number(c.start));
        if (d.type === 'start') c.start = clamp(t, 0, Number(c.end) - 0.1);
        else if (d.type === 'end') c.end = clamp(t, Number(c.start) + 0.1, total);
        else { c.start = clamp(Number(c.start) + dt, 0, total - len); c.end = c.start + len; }
        return;
      }
      const list = listOf(n, d.target);
      const item = list[d.idx]; if (!item) return;
      const dur = Math.max(0.1, Number(item.end) - Number(item.start));
      if (d.type === 'start') item.start = clamp(t, 0, Number(item.end) - 0.1);
      else if (d.type === 'end') item.end = clamp(t, Number(item.start) + 0.1, total);
      else { item.start = clamp(Number(item.start) + dt, 0, total - dur); item.end = item.start + dur; }
    });
  }, [timeFromEvent, live, total, listOf, applySnap]);

  const onTimelineUp = useCallback(() => {
    const d = drag.current; if (!d) return;
    drag.current = null;
    if (d.base) commitDrag(d.base);
  }, [commitDrag]);

  useEffect(() => {
    const el = scrollRef.current; if (!el) return;
    const onWheel = (e) => { if (e.ctrlKey || e.metaKey) { e.preventDefault(); setZoom((z) => clamp(z * (e.deltaY < 0 ? 1.12 : 0.89), 0.25, 6)); } };
    el.addEventListener('wheel', onWheel, { passive: false });
    return () => el.removeEventListener('wheel', onWheel);
  }, []);

  useEffect(() => { scrollToTime(currentTime); }, [currentTime, scrollToTime]);

  const addOverlay = useCallback(() => {
    const text = overlayDraft.text.trim();
    if (!text) return;
    const start = Number(currentTime.toFixed(2));
    const end = Number(Math.min(currentTime + 4, clipEnd).toFixed(2));
    edit((n) => n.text_overlays.push({ ...overlayDraft, text, x: 0.5, y: 0.78, start, end: Math.max(end, start + 0.5) }));
    setSelection({ type: 'overlay', idx: projectRef.current.text_overlays.length });
    setOverlayDraft((d) => ({ ...d, text: '' }));
  }, [overlayDraft, currentTime, clipEnd, edit]);

  const addCaption = useCallback(() => {
    const text = captionDraft.text.trim();
    if (!text) return;
    const start = Number(currentTime.toFixed(2));
    const end = Number(Math.min(currentTime + 4, clipEnd).toFixed(2));
    edit((n) => n.tracks.find((x) => x.type === 'captions').items.push({ ...captionDraft, text, start, end: Math.max(end, start + 0.5) }));
    setSelection({ type: 'caption', idx: projectRef.current.tracks.find((x) => x.type === 'captions').items.length });
    setCaptionDraft((d) => ({ ...d, text: '' }));
  }, [captionDraft, currentTime, clipEnd, edit]);

  const removeItem = useCallback((type, idx) => {
    edit((n) => {
      if (type === 'overlay') n.text_overlays.splice(idx, 1);
      else if (type === 'caption') n.tracks.find((x) => x.type === 'captions').items.splice(idx, 1);
      else if (type === 'marker') n.markers.splice(idx, 1);
      else n.blur_regions.splice(idx, 1);
    });
    setSelection(null);
  }, [edit]);

  const duplicateItem = useCallback((type, idx) => {
    edit((n) => {
      const list = type === 'overlay' ? n.text_overlays : type === 'caption' ? n.tracks.find((x) => x.type === 'captions').items : n.blur_regions;
      const item = clone(list[idx]); if (!item) return;
      const dur = Number(item.end) - Number(item.start);
      item.start = clamp(Number(item.end), 0, total - dur);
      item.end = item.start + dur;
      list.splice(idx + 1, 0, item);
    });
    setSelection((s) => (s ? { ...s, idx: idx + 1 } : s));
  }, [edit, total]);

  const patchClip = useCallback((k, v) => edit((n) => { n.tracks.find((x) => x.type === 'video').clips[0][k] = v; }), [edit]);
  const patchAudio = useCallback((k, v) => edit((n) => { n.tracks.find((x) => x.type === 'audio').clips[0][k] = v; }), [edit]);
  const patchFilters = useCallback((k, v) => edit((n) => { n.filters[k] = v; }), [edit]);
  const patchCrop = useCallback((k, v) => edit((n) => { n.crop[k] = v; }), [edit]);
  const patchItem = useCallback((type, idx, k, v) => edit((n) => { listOf(n, type)[idx][k] = v; }), [edit, listOf]);

  const onStageDown = useCallback((e) => {
    if (!tool.startsWith('blur')) return;
    if (e.target.closest('.nle-blur-region, .nle-overlay, .nle-caption, .nle-stage-bar')) return;
    e.preventDefault();
    const st = fitRect(); if (!st) return;
    const pt = (ev) => [clamp((ev.clientX - st.left) / st.width, 0, 1), clamp((ev.clientY - st.top) / st.height, 0, 1)];
    const a = pt(e);
    const start = Number(currentTime.toFixed(2));
    const end = Number(Math.max(start + 0.5, Math.min(currentTime + blurSeconds, clipEnd)).toFixed(2));
    const base = clone(projectRef.current);

    if (tool === 'blur-brush') {
      const strokes = [a];
      const paint = (ev) => {
        const p = pt(ev);
        const last = strokes[strokes.length - 1];
        if (Math.hypot(p[0] - last[0], p[1] - last[1]) > 0.004) strokes.push(p);
      };
      const up = () => {
        window.removeEventListener('pointermove', paint);
        window.removeEventListener('pointerup', up);
        const pts = densify(strokes, brushRadius);
        if (pts.length < 2) { commitDrag(base); return; }
        const xs = pts.map((p) => p[0]), ys = pts.map((p) => p[1]);
        const minX = Math.min(...xs), maxX = Math.max(...xs), minY = Math.min(...ys), maxY = Math.max(...ys);
        edit((n) => n.blur_regions.push({
          id: Date.now(), x: minX, y: minY, w: Math.max(0.01, maxX - minX), h: Math.max(0.01, maxY - minY),
          points: pts, r: brushRadius, strength: blurStrength, start, end,
        }));
        setSelection({ type: 'blur', idx: projectRef.current.blur_regions.length });
      };
      window.addEventListener('pointermove', paint);
      window.addEventListener('pointerup', up);
      return;
    }

    const region = { id: Date.now(), x: a[0], y: a[1], w: 0, h: 0, strength: blurStrength, start, end };
    let added = false;
    const draw = (ev) => {
      const b = pt(ev);
      if (!added) { edit((n) => n.blur_regions.push(clone(region))); added = true; }
      live((n) => {
        const r = n.blur_regions[n.blur_regions.length - 1]; if (!r || r.id !== region.id) return;
        r.x = Math.min(a[0], b[0]); r.y = Math.min(a[1], b[1]);
        r.w = Math.abs(b[0] - a[0]); r.h = Math.abs(b[1] - a[1]);
      });
    };
    const up = () => {
      window.removeEventListener('pointermove', draw);
      window.removeEventListener('pointerup', up);
      const r = projectRef.current.blur_regions.find((x) => x.id === region.id);
      if (r && r.w < 0.01 && r.h < 0.01) {
        live((n) => { n.blur_regions = n.blur_regions.filter((x) => x.id !== region.id); });
      } else if (r) {
        setSelection({ type: 'blur', idx: projectRef.current.blur_regions.indexOf(r) });
      }
      commitDrag(base);
    };
    window.addEventListener('pointermove', draw);
    window.addEventListener('pointerup', up);
  }, [tool, currentTime, clipEnd, blurSeconds, brushRadius, blurStrength, edit, live, commitDrag]);

  const onRegionDown = useCallback((idx, e) => {
    e.stopPropagation();
    setSelection({ type: 'blur', idx });
    const st = fitRect(); if (!st) return;
    const mode = e.target.dataset.resize || 'move';
    const r = projectRef.current.blur_regions[idx]; if (!r) return;
    const rect = { x: r.x, y: r.y, w: r.w, h: r.h };
    const base = clone(projectRef.current);
    const move = (ev) => {
      const px = clamp((ev.clientX - st.left) / st.width, 0, 1);
      const py = clamp((ev.clientY - st.top) / st.height, 0, 1);
      live((n) => {
        const t2 = n.blur_regions[idx]; if (!t2) return;
        if (mode === 'move') { t2.x = clamp(px - rect.w / 2, 0, 1 - rect.w); t2.y = clamp(py - rect.h / 2, 0, 1 - rect.h); return; }
        if (mode === 'nw') {
          const nx = Math.min(px, rect.x + rect.w - 0.01), ny = Math.min(py, rect.y + rect.h - 0.01);
          t2.x = nx; t2.y = ny; t2.w = rect.x + rect.w - nx; t2.h = rect.y + rect.h - ny;
          return;
        }
        if (mode === 'ne') { t2.y = Math.min(py, rect.y + rect.h - 0.01); t2.w = Math.max(0.01, px - rect.x); t2.h = rect.y + rect.h - t2.y; return; }
        if (mode === 'se') { t2.w = Math.max(0.01, px - rect.x); t2.h = Math.max(0.01, py - rect.y); return; }
        const nx = Math.min(px, rect.x + rect.w - 0.01);
        t2.x = nx; t2.w = rect.x + rect.w - nx; t2.h = Math.max(0.01, py - rect.y);
      });
    };
    const up = () => { window.removeEventListener('pointermove', move); window.removeEventListener('pointerup', up); commitDrag(base); };
    window.addEventListener('pointermove', move);
    window.addEventListener('pointerup', up);
  }, [live, commitDrag]);

  const onOverlayDown = useCallback((idx, e) => {
    e.stopPropagation();
    setSelection({ type: 'overlay', idx });
    const st = fitRect(); if (!st) return;
    const base = clone(projectRef.current);
    const move = (ev) => live((n) => {
      const it = n.text_overlays[idx]; if (!it) return;
      it.x = clamp((ev.clientX - st.left) / st.width, 0, 1);
      it.y = clamp((ev.clientY - st.top) / st.height, 0, 1);
    });
    const up = () => { window.removeEventListener('pointermove', move); window.removeEventListener('pointerup', up); commitDrag(base); };
    window.addEventListener('pointermove', move);
    window.addEventListener('pointerup', up);
  }, [live, commitDrag]);

  const exportVideo = useCallback(async (selectionOnly) => {
    setExportStatus('Starting export...');
    setExportLink(''); setExportStream(''); setExportProgress(0);
    clearTimeout(saveTimer.current);
    await save(true);
    const res = RESOLUTIONS.find((r) => r.id === exportRes) || RESOLUTIONS[0];
    const body = { _token: config.token };
    if (saveOverOriginal) body.save_over_original = '1';
    if (res.w) { body.export_width = String(res.w); body.export_height = String(res.h); }
    if (selectionOnly) { body.export_start = Number(currentTime.toFixed(2)); body.export_end = clipEnd; }
    let jobId = 0;
    try {
      const r = await fetch(config.exportUrl, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams(body),
      });
      const d = await r.json().catch(() => ({}));
      if (!r.ok || !d.job_id) { setExportStatus(d.error || 'Export failed'); setExportProgress(-1); return; }
      jobId = d.job_id;
    } catch (e) { setExportStatus('Export could not be started'); setExportProgress(-1); return; }
    const poll = async () => {
      let s = {};
      try {
        const resp = await fetch(config.statusUrl.replace('__JOB__', String(jobId)), { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        s = await resp.json();
      } catch (e) { setExportStatus('Export status unavailable'); setExportProgress(-1); return; }
      setExportProgress(Number(s.progress ?? 0));
      if (s.status === 'completed') {
        setExportStatus('Export complete');
        setExportLink(config.downloadUrl.replace('__JOB__', String(jobId)));
        setExportStream(config.streamUrl ? config.streamUrl.replace('__JOB__', String(jobId)) : '');
      } else if (s.status === 'failed') {
        setExportStatus(s.error || 'Export failed');
      } else if (s.status === 'queued' || s.status === 'running') {
        setExportStatus(`${s.status === 'queued' ? 'Queued' : 'Encoding'} ${s.progress ?? 0}%`);
        setTimeout(poll, 1200);
      } else setExportStatus(s.error || 'Export status unavailable');
    };
    poll();
  }, [save, saveOverOriginal, exportRes, currentTime, clipEnd]);

  useEffect(() => {
    const handler = (e) => {
      const t = e.target;
      if (t && (t.tagName === 'INPUT' || t.tagName === 'SELECT' || t.tagName === 'TEXTAREA' || t.isContentEditable)) return;
      const ctrl = e.ctrlKey || e.metaKey;
      const code = e.code;
      if (code === 'Space' || code === 'KeyK') { e.preventDefault(); playPause(); }
      else if (code === 'ArrowLeft') { e.preventDefault(); e.shiftKey ? stepFrame(-10) : stepFrame(-1); }
      else if (code === 'ArrowRight') { e.preventDefault(); e.shiftKey ? stepFrame(10) : stepFrame(1); }
      else if (code === 'KeyJ') { e.preventDefault(); nudge(-2); }
      else if (code === 'KeyL') { e.preventDefault(); nudge(2); }
      else if (code === 'Home') { e.preventDefault(); seekTo(clipStart); }
      else if (code === 'End') { e.preventDefault(); seekTo(clipEnd); }
      else if (!ctrl && code === 'KeyV') { e.preventDefault(); setTool('cursor'); }
      else if (!ctrl && code === 'KeyC') { e.preventDefault(); setTool('razor'); }
      else if (!ctrl && code === 'KeyM') { e.preventDefault(); setTool('marker'); }
      else if (!ctrl && code === 'KeyB' && e.shiftKey) { e.preventDefault(); setTool('blur-brush'); }
      else if (!ctrl && code === 'KeyB') { e.preventDefault(); setTool('blur-rect'); }
      else if (e.shiftKey && code === 'Slash') { e.preventDefault(); setShowHelp((s) => !s); }
      else if (ctrl && code === 'KeyZ' && !e.shiftKey) { e.preventDefault(); dispatch({ type: 'undo' }); }
      else if (ctrl && (code === 'KeyY' || (code === 'KeyZ' && e.shiftKey))) { e.preventDefault(); dispatch({ type: 'redo' }); }
      else if (ctrl && code === 'KeyS') { e.preventDefault(); save(false); }
      else if (code === 'Delete' || code === 'Backspace') {
        if (selection && selection.type !== 'clip') { e.preventDefault(); removeItem(selection.type, selection.idx); }
      }
      else if (ctrl && code === 'KeyD' && selection && selection.type !== 'clip') { e.preventDefault(); duplicateItem(selection.type, selection.idx); }
      else if (code === 'Equal' || code === 'NumpadAdd') { e.preventDefault(); setZoom((z) => clamp(z * 1.25, 0.25, 6)); }
      else if (code === 'Minus' || code === 'NumpadSubtract') { e.preventDefault(); setZoom((z) => clamp(z / 1.25, 0.25, 6)); }
      else if (code === 'KeyF') { e.preventDefault(); setFullscreen((f) => !f); }
      else if (code === 'Escape') {
        if (showHelp) setShowHelp(false);
        else if (selection) setSelection(null);
        else if (fullscreen) setFullscreen(false);
      }
    };
    window.addEventListener('keydown', handler);
    return () => window.removeEventListener('keydown', handler);
  }, [playPause, stepFrame, nudge, seekTo, clipStart, clipEnd, selection, removeItem, duplicateItem, save, fullscreen, showHelp]);

  const step = tickStep(PIXELS_PER_SEC);
  const ticks = useMemo(() => {
    const out = [];
    for (let t = 0; t <= total + 0.0001; t += step) out.push(Number(t.toFixed(4)));
    return out;
  }, [total, step]);

  const visibleOverlays = project.text_overlays.map((item, i) => ({ ...item, i })).filter((i) => currentTime >= i.start && currentTime <= i.end);
  const visibleCaptions = captionTrack.items.map((item, i) => ({ ...item, i })).filter((i) => currentTime >= i.start && currentTime <= i.end);
  const activeBlur = project.blur_regions.map((item, i) => ({ ...item, i })).filter((i) => currentTime >= i.start && currentTime <= i.end);

  const sel = selection && selection.type !== 'clip' ? {
    overlay: () => project.text_overlays[selection.idx],
    caption: () => captionTrack.items[selection.idx],
    blur: () => project.blur_regions[selection.idx],
    marker: () => project.markers[selection.idx],
  }[selection.type]?.() : null;

  const toolInfo = TOOLS.find((t) => t.id === tool) || TOOLS[0];

  return (
    <div className={`nle${fullscreen ? ' is-fs' : ''}`} data-tool={tool}>
      <header className="nle-top">
        <div className="nle-top-l">
          <span className="nle-logo">AME</span>
          <input className="nle-title" value={title} spellCheck={false} onChange={(e) => setTitle(e.target.value)} aria-label="Project title" />
          <span className="nle-ver">v{version}</span>
          <span className={`nle-savestate is-${saveState}${dirty ? ' is-dirty' : ''}`} title={dirty ? 'Unsaved changes' : 'All changes saved'}>
            <i />
            {saveState === 'saving' ? 'Saving' : saveState === 'error' ? 'Save failed' : dirty ? 'Edited' : 'Saved'}
          </span>
        </div>

        <div className="nle-tools">
          {TOOLS.map((t) => (
            <button key={t.id} className={`nle-tbtn${tool === t.id ? ' is-on' : ''}`} onClick={() => setTool(t.id)} title={`${t.label} (${t.key})`} aria-label={t.label}>
              <Icon name={t.icon} />
            </button>
          ))}
          <span className="nle-div" />
          <button className="nle-tbtn" onClick={() => setZoom((z) => clamp(z / 1.25, 0.25, 6))} title="Zoom out (-)">&minus;</button>
          <span className="nle-zoom">{Math.round(zoom * 100)}%</span>
          <button className="nle-tbtn" onClick={() => setZoom((z) => clamp(z * 1.25, 0.25, 6))} title="Zoom in (+)">+</button>
          <button className={`nle-tbtn is-wide${snap ? ' is-on' : ''}`} onClick={() => setSnap((s) => !s)} title="Snap edits to the playhead and clip edges">Snap</button>
          <button className={`nle-tbtn is-wide${loop ? ' is-on' : ''}`} onClick={() => setLoop((l) => !l)} title="Loop playback (L)">Loop</button>
        </div>

        <div className="nle-top-r">
          <button className="nle-btn" onClick={() => dispatch({ type: 'undo' })} disabled={!canUndo} title="Undo (Ctrl+Z)"><Icon name="undo" size={14} />Undo</button>
          <button className="nle-btn" onClick={() => dispatch({ type: 'redo' })} disabled={!canRedo} title="Redo (Ctrl+Shift+Z)"><Icon name="redo" size={14} />Redo</button>
          <button className="nle-btn is-primary" onClick={() => save(false)} title="Save now (Ctrl+S)"><Icon name="save" size={14} />Save</button>
          <button className="nle-btn is-ghost" onClick={() => setShowHelp(true)} title="Keyboard shortcuts (?)"><Icon name="help" size={14} /></button>
        </div>
      </header>

      <div className="nle-main">
        <div className="nle-pane">
          <div className="nle-monitor">
            <div className="nle-mon-bar">
              <span className="nle-mon-label">Program monitor</span>
              <span className="nle-tc">{fmtTC(currentTime)}</span>
              <span className="nle-mon-meta">
                {srcW ? `${srcW}x${srcSize.h}` : 'loading'} &middot; {fmt(total)} &middot; {filterPresetName}
              </span>
              <button className="nle-btn is-ghost is-tight" onClick={() => setFullscreen((f) => !f)} title="Fullscreen preview (F)">
                <Icon name="full" size={13} />{fullscreen ? 'Exit' : 'Fullscreen'}
              </button>
            </div>

            <div className="nle-stage" ref={stageRef} onPointerDown={onStageDown}>
              <div className="nle-stage-crop" style={frame ? { width: frame.w, height: frame.h, transform: cssCrop(crop) } : { transform: cssCrop(crop) }}>
                <video
                  ref={videoRef}
                  className="nle-stage-video"
                  src={config.sourceUrl}
                  preload="metadata"
                  playsInline
                  style={{ filter: cssFilter(project.filters) }}
                  onLoadedMetadata={(e) => {
                    const v = e.currentTarget;
                    const d = v.duration;
                    if (isFinite(d) && d > 0) setDuration(d);
                    setSrcSize({ w: v.videoWidth, h: v.videoHeight });
                    const c = projectRef.current.tracks.find((x) => x.type === 'video').clips[0];
                    if ((!c.end || Number(c.end) <= 0) && isFinite(d)) {
                      skipNextSave.current = true;
                      patchClip('end', Number(d.toFixed(2)));
                    }
                  }}
                  onError={() => setStatus('The source video could not be loaded')}
                  onTimeUpdate={onTimeUpdate}
                  onClick={playPause}
                />
              </div>

              <div className="nle-stage-fit" style={frame ? { width: frame.w, height: frame.h } : undefined}>
                <svg className="nle-brush-svg" viewBox="0 0 1 1" preserveAspectRatio="none" aria-hidden="true">
                  {activeBlur.filter((r) => Array.isArray(r.points) && r.points.length > 1).map((r) => (
                    <g key={r.id} className={selection?.type === 'blur' && selection.idx === r.i ? 'is-sel' : ''}>
                      {r.points.map((p, i) => <circle key={i} cx={p[0]} cy={p[1]} r={r.r || 0.05} />)}
                    </g>
                  ))}
                </svg>
              </div>

              <div className="nle-stage-fit" style={frame ? { width: frame.w, height: frame.h } : undefined}>
              {activeBlur.map((r) => (
                <div key={r.id}
                  className={`nle-bregion${selection?.type === 'blur' && selection.idx === r.i ? ' is-sel' : ''}`}
                  style={{
                    left: `${r.x * 100}%`, top: `${r.y * 100}%`, width: `${r.w * 100}%`, height: `${r.h * 100}%`,
                    backdropFilter: `blur(${Math.max(2, Math.min(24, r.strength * 0.7))}px)`,
                  }}
                  onPointerDown={(e) => onRegionDown(r.i, e)}
                  title={`Blur ${r.strength} - ${fmt(r.start)} to ${fmt(r.end)}`}>
                  {selection?.type === 'blur' && selection.idx === r.i && (
                    <>
                      <span className="nle-bregion-h" data-resize="nw" /><span className="nle-bregion-h" data-resize="ne" />
                      <span className="nle-bregion-h" data-resize="se" /><span className="nle-bregion-h" data-resize="sw" />
                      <span className="nle-bregion-tag">blur {r.strength}</span>
                    </>
                  )}
                </div>
              ))}

              {visibleOverlays.map((item) => (
                <div key={item.i}
                  className={`nle-overlay${selection?.type === 'overlay' && selection.idx === item.i ? ' is-sel' : ''}`}
                  style={{
                    left: `${item.x * 100}%`, top: `${item.y * 100}%`,
                    color: item.color || '#fff', opacity: item.opacity,
                    fontSize: `${(item.font_size / (srcW || 1920)) * 100}cqw`,
                    padding: srcW ? `${(item.padding / srcW) * 100}cqw` : '0.4cqw',
                    textShadow: item.shadow ? '0 2px 10px rgba(0,0,0,.9)' : 'none',
                  }}
                  onPointerDown={(e) => onOverlayDown(item.i, e)}>{item.text}</div>
              ))}

              {visibleCaptions.map((item) => (
                <div key={item.i}
                  className={`nle-caption${selection?.type === 'caption' && selection.idx === item.i ? ' is-sel' : ''}`}
                  style={{
                    opacity: item.opacity,
                    fontSize: srcW ? `${(28 / srcW) * 100}cqw` : '1.6cqw',
                    padding: srcW ? `${(item.padding / srcW) * 100}cqw ${(item.padding * 2 / srcW) * 100}cqw` : '0.4cqw 0.8cqw',
                    top: srcW ? `calc(100% - ${(40 / srcW) * 100}cqw)` : 'calc(100% - 2cqw)',
                    textShadow: item.shadow ? '0 2px 10px rgba(0,0,0,.9)' : 'none',
                  }}
                  onClick={() => setSelection({ type: 'caption', idx: item.i })}>{item.text}</div>
              ))}

              </div>

              {tool.startsWith('blur') && (
                <div className="nle-stage-hint">
                  {tool === 'blur-brush'
                    ? `Paint on the video - brush ${Math.round(brushRadius * 100)}% of the frame wide`
                    : `Drag a box on the video - blur ${blurStrength}, ${blurSeconds}s from the playhead`}
                </div>
              )}
            </div>

            <div className="nle-transport">
              <button className="nle-pbtn" onClick={() => seekTo(clipStart)} title="Go to in point (Home)">|{'\u25c0'}</button>
              <button className="nle-pbtn" onClick={() => stepFrame(-1)} title="Previous frame (Left)">{'\u25c0\u25c0'}</button>
              <button className="nle-pbtn is-play" onClick={playPause} title="Play or pause (Space)">{playing ? '\u275a\u275a' : '\u25b6'}</button>
              <button className="nle-pbtn" onClick={() => stepFrame(1)} title="Next frame (Right)">{'\u25b6\u25b6'}</button>
              <button className="nle-pbtn" onClick={() => seekTo(clipEnd)} title="Go to out point (End)">{'\u25b6'}|</button>
              <span className="nle-tc is-dim">{fmt(currentTime)} <i>/</i> {fmt(total)}</span>
              <span className="nle-tc is-dim is-mono">in {fmtTC(clipStart)} &middot; out {fmtTC(clipEnd)}</span>
            </div>
          </div>

          <section className="nle-inspector">
            <div className="nle-sec-h nle-inspector-bar">
              <span className="nle-sec-t">Inspector</span>
              <span className="nle-sec-a">
                {sel ? (
                  <button className="nle-btn is-ghost is-tight" onClick={() => setSelection(null)}>
                    <Icon name="clip" size={13} />Clip settings
                  </button>
                ) : 'Clip settings'}
              </span>
            </div>
            <div className="nle-inspector-b">
              {sel && selection.type === 'overlay' && (
                <>
                  <Section title="Text overlay" actions={
                    <>
                      <button className="nle-btn is-ghost is-tight" onClick={() => duplicateItem('overlay', selection.idx)}>Duplicate</button>
                      <button className="nle-btn is-danger is-tight" onClick={() => removeItem('overlay', selection.idx)}>Delete</button>
                    </>
                  }>
                    <label className="nle-field nle-text">
                      <span className="nle-field-l">Text</span>
                      <textarea rows="2" value={sel.text} onChange={(e) => patchItem('overlay', selection.idx, 'text', e.target.value)} />
                    </label>
                    <Row>
                      <Num label="Start" value={sel.start} onChange={(v) => patchItem('overlay', selection.idx, 'start', v)} hint="s" />
                      <Num label="End" value={sel.end} onChange={(v) => patchItem('overlay', selection.idx, 'end', v)} hint="s" />
                    </Row>
                    <Row>
                      <Slider label="X" min="0" max="1" step="0.01" value={sel.x} onChange={(v) => patchItem('overlay', selection.idx, 'x', v)} format={(v) => `${Math.round(v * 100)}%`} />
                      <Slider label="Y" min="0" max="1" step="0.01" value={sel.y} onChange={(v) => patchItem('overlay', selection.idx, 'y', v)} format={(v) => `${Math.round(v * 100)}%`} />
                    </Row>
                    <Row>
                      <Num label="Size" step="1" min="10" max="180" value={sel.font_size} onChange={(v) => patchItem('overlay', selection.idx, 'font_size', v)} hint="px" />
                      <label className="nle-field nle-color"><span className="nle-field-l">Colour</span>
                        <input type="color" value={sel.color} onChange={(e) => patchItem('overlay', selection.idx, 'color', e.target.value)} />
                      </label>
                    </Row>
                    <Row>
                      <Slider label="Opacity" min="0" max="1" step="0.05" value={sel.opacity} onChange={(v) => patchItem('overlay', selection.idx, 'opacity', v)} format={(v) => `${Math.round(v * 100)}%`} />
                      <Slider label="Padding" min="0" max="40" step="2" value={sel.padding} onChange={(v) => patchItem('overlay', selection.idx, 'padding', v)} format={(v) => `${v}px`} />
                    </Row>
                    <Check label="Drop shadow" checked={sel.shadow} onChange={(v) => patchItem('overlay', selection.idx, 'shadow', v)} />
                  </Section>
                  <p className="nle-note">Drag the text on the preview to move it. Size and padding are shown at the real export resolution.</p>
                </>
              )}

              {sel && selection.type === 'caption' && (
                <Section title="Caption" actions={
                  <>
                    <button className="nle-btn is-ghost is-tight" onClick={() => duplicateItem('caption', selection.idx)}>Duplicate</button>
                    <button className="nle-btn is-danger is-tight" onClick={() => removeItem('caption', selection.idx)}>Delete</button>
                  </>
                }>
                  <label className="nle-field nle-text">
                    <span className="nle-field-l">Text</span>
                    <textarea rows="2" value={sel.text} onChange={(e) => patchItem('caption', selection.idx, 'text', e.target.value)} />
                  </label>
                  <Row>
                    <Num label="Start" value={sel.start} onChange={(v) => patchItem('caption', selection.idx, 'start', v)} hint="s" />
                    <Num label="End" value={sel.end} onChange={(v) => patchItem('caption', selection.idx, 'end', v)} hint="s" />
                  </Row>
                  <Row>
                    <Slider label="Opacity" min="0" max="1" step="0.05" value={sel.opacity} onChange={(v) => patchItem('caption', selection.idx, 'opacity', v)} format={(v) => `${Math.round(v * 100)}%`} />
                    <Slider label="Padding" min="0" max="40" step="2" value={sel.padding} onChange={(v) => patchItem('caption', selection.idx, 'padding', v)} format={(v) => `${v}px`} />
                  </Row>
                  <Check label="Drop shadow" checked={sel.shadow} onChange={(v) => patchItem('caption', selection.idx, 'shadow', v)} />
                  <p className="nle-note">Captions always render centred along the bottom edge, 40px above it.</p>
                </Section>
              )}

              {sel && selection.type === 'blur' && (
                <Section title="Blur region" actions={
                  <>
                    <button className="nle-btn is-ghost is-tight" onClick={() => duplicateItem('blur', selection.idx)}>Duplicate</button>
                    <button className="nle-btn is-danger is-tight" onClick={() => removeItem('blur', selection.idx)}>Delete</button>
                  </>
                }>
                  <Row>
                    <Num label="Start" value={sel.start} onChange={(v) => edit((n) => { n.blur_regions[selection.idx].start = v; })} hint="s" />
                    <Num label="End" value={sel.end} onChange={(v) => edit((n) => { n.blur_regions[selection.idx].end = v; })} hint="s" />
                  </Row>
                  <Slider label="Blur strength" min="2" max="30" step="1" value={sel.strength}
                    onChange={(v) => edit((n) => { n.blur_regions[selection.idx].strength = v; })} format={(v) => v} />
                  {Array.isArray(sel.points) && sel.points.length > 1 ? (
                    <Slider label="Brush width" min="0.005" max="0.2" step="0.005" value={sel.r || 0.05}
                      onChange={(v) => edit((n) => { n.blur_regions[selection.idx].r = v; })} format={(v) => `${Math.round(v * 100)}%`} />
                  ) : null}
                  <Row>
                    <Slider label="X" min="0" max="1" step="0.01" value={sel.x} onChange={(v) => edit((n) => { n.blur_regions[selection.idx].x = v; })} format={(v) => `${Math.round(v * 100)}%`} />
                    <Slider label="Y" min="0" max="1" step="0.01" value={sel.y} onChange={(v) => edit((n) => { n.blur_regions[selection.idx].y = v; })} format={(v) => `${Math.round(v * 100)}%`} />
                  </Row>
                  <Row>
                    <Slider label="Width" min="0.01" max="1" step="0.01" value={sel.w} onChange={(v) => edit((n) => { n.blur_regions[selection.idx].w = v; })} format={(v) => `${Math.round(v * 100)}%`} />
                    <Slider label="Height" min="0.01" max="1" step="0.01" value={sel.h} onChange={(v) => edit((n) => { n.blur_regions[selection.idx].h = v; })} format={(v) => `${Math.round(v * 100)}%`} />
                  </Row>
                  <p className="nle-note">Drag the box or its corners on the preview to move and resize it.</p>
                </Section>
              )}

              {!sel && (
                <>
                  <Section title="Clip">
                    <Row>
                      <Num label="In" value={Number(clip.start) || 0} onChange={(v) => patchClip('start', v)} hint="s" />
                      <Num label="Out" value={Number(clip.end) || 0} onChange={(v) => patchClip('end', v)} hint="s" />
                    </Row>
                    <label className="nle-field nle-select">
                      <span className="nle-field-l">Transition</span>
                      <select value={clip.transition || 'none'} onChange={(e) => patchClip('transition', e.target.value)}>
                        <option value="none">None</option>
                        <option value="fade">Fade in / out</option>
                      </select>
                    </label>
                  </Section>
                  <Section title="Audio">
                    <Slider label="Volume" min="0" max="2" step="0.05" value={Number(audio.volume ?? 1)}
                      onChange={(v) => patchAudio('volume', v)} format={(v) => `${Math.round(v * 100)}%`} />
                    <Row>
                      <Num label="Fade in" value={Number(audio.fade_in ?? 0)} onChange={(v) => patchAudio('fade_in', v)} hint="s" />
                      <Num label="Fade out" value={Number(audio.fade_out ?? 0)} onChange={(v) => patchAudio('fade_out', v)} hint="s" />
                    </Row>
                    <Check label="Mute" checked={!!audio.muted} onChange={(v) => patchAudio('muted', v)} />
                  </Section>
                  <Section title="Speed">
                    <Slider label="Playback speed" min="0.25" max="3" step="0.05" value={project.speed}
                      onChange={(v) => edit((n) => { n.speed = v; })} format={(v) => `${Number(v).toFixed(2)}x`} />
                    <p className="nle-note">Speed changes the exported length; the preview plays at the same rate.</p>
                  </Section>
                  <Section title="Crop and mirror">
                    <Slider label="Zoom" min="1" max="3" step="0.05" value={Number(crop.zoom)} onChange={(v) => patchCrop('zoom', v)} format={(v) => `${Number(v).toFixed(2)}x`} />
                    <Row>
                      <Slider label="Pan X" min="0" max="1" step="0.01" value={Number(crop.panX ?? 0.5)} onChange={(v) => patchCrop('panX', v)} format={(v) => `${Math.round(v * 100)}%`} />
                      <Slider label="Pan Y" min="0" max="1" step="0.01" value={Number(crop.panY ?? 0.5)} onChange={(v) => patchCrop('panY', v)} format={(v) => `${Math.round(v * 100)}%`} />
                    </Row>
                    <Row>
                      <Check label="Mirror horizontally" checked={!!crop.mirrorH} onChange={(v) => patchCrop('mirrorH', v)} />
                      <Check label="Mirror vertically" checked={!!crop.mirrorV} onChange={(v) => patchCrop('mirrorV', v)} />
                    </Row>
                    <button className="nle-btn is-tight" onClick={() => edit((n) => { n.crop = { ...CROP_DEFAULTS }; })}>Reset crop</button>
                  </Section>
                </>
              )}
            </div>
          </section>
        </div>

        <aside className="nle-side">
          <Section title="Effects" actions={<span className="nle-badge">{filterPresetName}</span>}>
            <div className="nle-presets">
              {FILTER_PRESETS.map((p) => (
                <button key={p.name} className={`nle-preset${JSON.stringify(project.filters) === JSON.stringify(p.values) ? ' is-on' : ''}`}
                  onClick={() => edit((n) => { n.filters = { ...p.values }; })}>{p.name}</button>
              ))}
            </div>
            <Slider label="Brightness" min="-0.5" max="0.5" step="0.05" value={project.filters.brightness} onChange={(v) => patchFilters('brightness', v)} format={(v) => v.toFixed(2)} />
            <Slider label="Contrast" min="0.5" max="2" step="0.05" value={project.filters.contrast} onChange={(v) => patchFilters('contrast', v)} format={(v) => v.toFixed(2)} />
            <Slider label="Saturation" min="0" max="2" step="0.05" value={project.filters.saturation} onChange={(v) => patchFilters('saturation', v)} format={(v) => v.toFixed(2)} />
            <Slider label="Blur" min="0" max="8" step="0.5" value={project.filters.blur} onChange={(v) => patchFilters('blur', v)} format={(v) => v.toFixed(1)} />
            <Slider label="Hue" min="-180" max="180" step="5" value={project.filters.hue} onChange={(v) => patchFilters('hue', v)} format={(v) => `${v}deg`} />
            <Row>
              <Check label="Black and white" checked={!!project.filters.grayscale} onChange={(v) => patchFilters('grayscale', v)} />
              <Check label="Sepia" checked={!!project.filters.sepia} onChange={(v) => patchFilters('sepia', v)} />
            </Row>
            <button className="nle-btn is-tight" onClick={() => edit((n) => { n.filters = { ...FILTER_DEFAULTS }; })}>Reset effects</button>
          </Section>

          <Section title="Add" actions={<span className="nle-badge">at {fmt(currentTime)}</span>}>
            <label className="nle-field nle-text">
              <span className="nle-field-l">Text overlay</span>
              <textarea rows="2" value={overlayDraft.text} placeholder="Text burned into the video"
                onChange={(e) => setOverlayDraft((d) => ({ ...d, text: e.target.value }))}
                onKeyDown={(e) => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); addOverlay(); } }} />
            </label>
            <Row>
              <Num label="Size" step="1" min="10" max="180" value={overlayDraft.font_size} onChange={(v) => setOverlayDraft((d) => ({ ...d, font_size: v }))} hint="px" />
              <label className="nle-field nle-color"><span className="nle-field-l">Colour</span>
                <input type="color" value={overlayDraft.color} onChange={(e) => setOverlayDraft((d) => ({ ...d, color: e.target.value }))} />
              </label>
            </Row>
            <button className="nle-btn is-primary is-block" onClick={addOverlay}>Add overlay at playhead</button>

            <div className="nle-hr" />
            <label className="nle-field nle-text">
              <span className="nle-field-l">Caption</span>
              <textarea rows="2" value={captionDraft.text} placeholder="Bottom centred subtitle"
                onChange={(e) => setCaptionDraft((d) => ({ ...d, text: e.target.value }))}
                onKeyDown={(e) => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); addCaption(); } }} />
            </label>
            <button className="nle-btn is-primary is-block" onClick={addCaption}>Add caption at playhead</button>
          </Section>

          <Section title="Blur regions" actions={<span className="nle-badge">{project.blur_regions.length}</span>}>
            <div className="nle-seg">
              <button className={tool === 'blur-rect' ? 'is-on' : ''} onClick={() => setTool('blur-rect')}>Box</button>
              <button className={tool === 'blur-brush' ? 'is-on' : ''} onClick={() => setTool('blur-brush')}>Brush</button>
            </div>
            <Slider label="Strength" min="2" max="30" step="1" value={blurStrength} onChange={setBlurStrength} format={(v) => v} />
            <Slider label="Duration" min="0.5" max="60" step="0.5" value={blurSeconds} onChange={setBlurSeconds} format={(v) => `${v}s`} />
            <Slider label="Brush width" min="0.005" max="0.2" step="0.005" value={brushRadius} onChange={setBrushRadius} format={(v) => `${Math.round(v * 100)}%`} />
            {project.blur_regions.length === 0 && <p className="nle-note">Pick a tool and drag on the video to hide something.</p>}
            <ul className="nle-items">
              {project.blur_regions.map((r, i) => (
                <li key={r.id} className={selection?.type === 'blur' && selection.idx === i ? 'is-sel' : ''}
                  onClick={() => { seekTo(r.start); setSelection({ type: 'blur', idx: i }); }}>
                  <span className="nle-dot is-blur" />
                  <span className="nle-items-t">{fmt(r.start)} - {fmt(r.end)}</span>
                  <span className="nle-items-m">{Array.isArray(r.points) && r.points.length > 1 ? 'brush' : 'box'} {r.strength}</span>
                  <button className="nle-x" onClick={(e) => { e.stopPropagation(); removeItem('blur', i); }} title="Remove"><Icon name="close" size={11} /></button>
                </li>
              ))}
            </ul>
          </Section>

          <Section title="Markers" actions={<span className="nle-badge">{project.markers.length}</span>}>
            {project.markers.length === 0 ? <p className="nle-note">Pick the marker tool (M) and click the timeline.</p> : null}
            <ul className="nle-items">
              {project.markers.map((m, i) => (
                <li key={i} className={selection?.type === 'marker' && selection.idx === i ? 'is-sel' : ''}
                  onClick={() => { seekTo(m.time); setSelection({ type: 'marker', idx: i }); }}>
                  <span className="nle-dot" />
                  <span className="nle-items-t">{fmt(m.time)}</span>
                  <span className="nle-items-m">{m.label}</span>
                  <button className="nle-x" onClick={(e) => { e.stopPropagation(); removeItem('marker', i); }} title="Remove"><Icon name="close" size={11} /></button>
                </li>
              ))}
            </ul>
          </Section>
        </aside>
      </div>

      <Timeline
        total={total} timelineWidth={timelineWidth} ticks={ticks} step={step}
        clipStart={clipStart} clipEnd={clipEnd} resLabel={RESOLUTIONS.find((r) => r.id === exportRes)?.label || 'Source'}
        project={project} captionItems={captionTrack.items} currentTime={currentTime}
        selection={selection} onSelect={setSelection} onSeek={seekTo}
        onDown={onTimelineDown} onMove={onTimelineMove} onUp={onTimelineUp}
        scrollRef={scrollRef} playheadRef={playheadRef}
      />

      <footer className="nle-status">
        <span className="nle-chip">{toolInfo.label}</span>
        <span className="nle-tc is-mono">{fmtTC(currentTime)} / {fmtTC(total)}</span>
        <span>Zoom {Math.round(zoom * 100)}%</span>
        <span>Snap {snap ? 'on' : 'off'}</span>
        <span>Loop {loop ? 'on' : 'off'}</span>
        <span className={`nle-msg is-${saveState}`}>{status}</span>
        <span className="nle-export">
          <label className="nle-field nle-select is-tight">
            <span className="nle-field-l">Export size</span>
            <select value={exportRes} onChange={(e) => setExportRes(e.target.value)}>
              {RESOLUTIONS.map((r) => <option key={r.id} value={r.id}>{r.label}</option>)}
            </select>
          </label>
          <label className="nle-field nle-check is-tight"><input type="checkbox" checked={saveOverOriginal} onChange={(e) => setSaveOverOriginal(e.target.checked)} /><span>Overwrite source</span></label>
          <button className="nle-btn is-primary is-tight" onClick={() => exportVideo(false)}>Export</button>
          <button className="nle-btn is-tight" onClick={() => exportVideo(true)} title="Export from the playhead to the out point">From playhead</button>
          <button className="nle-btn is-danger is-tight" onClick={cancel}>Discard</button>
          {exportProgress >= 0 && (
            <span className="nle-progress" title={`${exportProgress}%`}><i style={{ width: `${exportProgress}%` }} /></span>
          )}
          <span className="nle-export-msg">
            {exportStatus}
            {exportLink && <a className="nle-link" href={exportLink} download>Download</a>}
            {exportStream && <a className="nle-link" href={exportStream} target="_blank" rel="noopener">Play</a>}
          </span>
        </span>
        <button className="nle-btn is-ghost is-tight" onClick={() => setShowHelp(true)}>Shortcuts</button>
      </footer>

      {showHelp && (
        <div className="nle-modal" onClick={() => setShowHelp(false)}>
          <div className="nle-modal-b" onClick={(e) => e.stopPropagation()}>
            <header>
              <h3>Keyboard shortcuts</h3>
              <button className="nle-btn is-ghost is-tight" onClick={() => setShowHelp(false)}><Icon name="close" size={13} /></button>
            </header>
            <dl>
              {SHORTCUTS.map(([k, v]) => (<div key={k}><dt>{k}</dt><dd>{v}</dd></div>))}
            </dl>
          </div>
        </div>
      )}
    </div>
  );
}

createRoot(document.getElementById('root')).render(<Editor />);
