import React, { useEffect, useMemo, useRef, useState, useCallback } from 'react';
import { createRoot } from 'react-dom/client';
import './styles.css';

const config = window.__VIDEO_EDITOR__;

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

function ensureProject(project, sourceId) {
  const next = structuredClone(project || {});
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
  return next;
}

function fmt(s) { s = Math.max(0, Number(s) || 0); return `${Math.floor(s/60)}:${(s%60).toFixed(1).padStart(4,'0')}`; }
function fmtTC(s) { s = Math.max(0, Number(s) || 0); const f = Math.floor((s % 1) * 30); return `${Math.floor(s/60).toString().padStart(2,'0')}:${Math.floor(s%60).toString().padStart(2,'0')}:${f.toString().padStart(2,'0')}`; }
function cssFilter(f) { const p = []; if (f.brightness) p.push(`brightness(${1+Number(f.brightness)})`); if (Number(f.contrast)!==1) p.push(`contrast(${f.contrast})`); if (Number(f.saturation)!==1) p.push(`saturate(${f.saturation})`); if (f.grayscale) p.push('grayscale(1)'); if (f.sepia) p.push('sepia(1)'); if (Number(f.blur)>0) p.push(`blur(${f.blur}px)`); if (Number(f.hue)!==0) p.push(`hue-rotate(${f.hue}deg)`); return p.join(' ')||'none'; }
function cssCrop(c) { const p = []; if (Number(c.zoom)>1) p.push(`scale(${c.zoom})`); if (c.mirrorH) p.push('scaleX(-1)'); if (c.mirrorV) p.push('scaleY(-1)'); if (!p.length) return 'none'; const tx=(0.5-(c.panX??0.5))*(Number(c.zoom)-1)*100; const ty=(0.5-(c.panY??0.5))*(Number(c.zoom)-1)*100; return `${p.join(' ')} translate(${tx}%,${ty}%)`; }
function clamp(v,lo,hi){return Math.max(lo,Math.min(hi,v));}

/* ── History (undo/redo) ── */
function useHistory(state) {
  const past = useRef([]); const future = useRef([]);
  const push = useCallback((snap) => { past.current.push(snap); if (past.current.length > 80) past.current.shift(); future.current = []; }, []);
  const undo = useCallback((current) => { if (!past.current.length) return current; future.current.push(structuredClone(current)); return past.current.pop(); }, []);
  const redo = useCallback((current) => { if (!future.current.length) return current; past.current.push(structuredClone(current)); return future.current.pop(); }, []);
  const canUndo = past.current.length > 0;
  const canRedo = future.current.length > 0;
  return { push, undo, redo, canUndo, canRedo, pastLen: past.current.length, futureLen: future.current.length };
}

function Editor() {
  const videoRef = useRef(null);
  const stageRef = useRef(null);
  const timelineScrollRef = useRef(null);
  const timelineInnerRef = useRef(null);
  const saveTimer = useRef(null);
  const dragState = useRef(null);
  const skipNextSave = useRef(true);
  const loopRef = useRef(false);

  const [project, setProject] = useState(() => ensureProject(config.project, config.sourceId));
  const [version, setVersion] = useState(config.version);
  const [title, setTitle] = useState(config.title || 'Untitled');
  const [duration, setDuration] = useState(0);
  const [currentTime, setCurrentTime] = useState(0);
  const [playing, setPlaying] = useState(false);
  const [status, setStatus] = useState('Ready');
  const [exportStatus, setExportStatus] = useState('');
  const [exportLink, setExportLink] = useState('');
  const [loop, setLoop] = useState(false);
  const [tool, setTool] = useState('cursor');
  const [snap, setSnap] = useState(true);
  const [timelineZoom, setTimelineZoom] = useState(1);
  const [selection, setSelection] = useState(null);
  const [overlayDraft, setOverlayDraft] = useState({ text: '', font_size: 32, color: '#ffffff', opacity: 1, padding: 8, shadow: false });
  const [captionDraft, setCaptionDraft] = useState({ text: '', opacity: 1, padding: 8, shadow: false });
  const [snapDraft, setSnapDraft] = useState({ time: 0 });
  const [previewFullscreen, setPreviewFullscreen] = useState(false);
  const [saveOverOriginal, setSaveOverOriginal] = useState(false);
  const blurDraw = useRef(null);
  const [blurDraft, setBlurDraft] = useState({ strength: 10 });

  const savedSnapshot = useRef({ project: ensureProject(config.project, config.sourceId), version: config.version, title: config.title || 'Untitled' });
  const initialSnapshot = useRef({ project: ensureProject(config.project, config.sourceId), version: config.version, title: config.title || 'Untitled' });
  const h = useHistory(project);

  loopRef.current = loop;

  const videoTrack = project.tracks.find((t) => t.type === 'video');
  const audioTrack = project.tracks.find((t) => t.type === 'audio');
  const captionTrack = project.tracks.find((t) => t.type === 'captions');
  const clip = videoTrack?.clips[0] || { start: 0, end: 0 };
  const audio = audioTrack?.clips[0] || { volume: 1, muted: false, fade_in: 0, fade_out: 0 };
  const crop = project.crop || CROP_DEFAULTS;
  const total = duration || clip.end || 1;
  const clipStart = Math.max(0, Number(clip.start) || 0);
  const clipEnd = Number(clip.end) > clipStart ? Number(clip.end) : total;
  const clipDur = Math.max(0, clipEnd - clipStart);

  const PIXELS_PER_SEC = 80 * timelineZoom;
  const timelineWidth = Math.max(600, total * PIXELS_PER_SEC);

  const updateProject = useCallback((updater, skipHistory) => {
    setProject((current) => {
      const next = structuredClone(current);
      updater(next);
      if (!skipHistory) h.push(structuredClone(current));
      return next;
    });
  }, [h]);

  const save = useCallback(async (silent) => {
    if (!silent) setStatus('Saving...');
    try {
      const r = await fetch(config.saveUrl, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
        body: new URLSearchParams({ _token: config.token, title, version: String(version), project: JSON.stringify(project) }) });
      const d = await r.json(); if (!r.ok) throw new Error(d.error || 'Save failed');
      setVersion(d.version); savedSnapshot.current = { project: structuredClone(project), version: d.version, title };
      setStatus('Saved ' + new Date().toLocaleTimeString());
    } catch (e) { setStatus(e.message); }
  }, [project, title, version]);

  const cancel = useCallback(() => {
    const snap = initialSnapshot.current;
    if (videoRef.current) { videoRef.current.pause(); videoRef.current.currentTime = 0; }
    setProject(structuredClone(snap.project)); setVersion(snap.version); setTitle(snap.title);
    setCurrentTime(0); setPlaying(false); setSelection(null); setStatus('Reverted to original');
  }, []);

  useEffect(() => { if (skipNextSave.current) { skipNextSave.current = false; return; } clearTimeout(saveTimer.current); saveTimer.current = setTimeout(() => save(true), 900); return () => clearTimeout(saveTimer.current); }, [project, title, save]);

  /* ── Playback ── */
  useEffect(() => { const v = videoRef.current; if (!v) return; v.playbackRate = project.speed || 1; v.volume = audio.muted ? 0 : clamp(Number(audio.volume ?? 1), 0, 2); }, [project.speed, audio.volume, audio.muted]);

  const onTimeUpdate = useCallback(() => { const v = videoRef.current; if (!v) return; setCurrentTime(v.currentTime); if (v.currentTime >= clipEnd) { if (loopRef.current) { v.currentTime = clipStart; } else { v.pause(); v.currentTime = clipStart; setPlaying(false); } } }, [clipStart, clipEnd]);

  const playPause = useCallback(() => { const v = videoRef.current; if (!v) return; if (v.paused) { if (v.currentTime < clipStart || v.currentTime >= clipEnd) v.currentTime = clipStart; v.play(); setPlaying(true); } else { v.pause(); setPlaying(false); } }, [clipStart, clipEnd]);

  const stepFrame = useCallback((d) => { const v = videoRef.current; if (!v) return; if (!v.paused) { v.pause(); setPlaying(false); } const t = clamp(v.currentTime + d * (1/30), clipStart, clipEnd); v.currentTime = t; setCurrentTime(t); }, [clipStart, clipEnd]);

  const seekTo = useCallback((t) => { t = clamp(t, 0, total); if (videoRef.current) videoRef.current.currentTime = t; setCurrentTime(t); }, [total]);

  /* ── Timeline pointer ── */
  const timeFromEvent = useCallback((e) => { const el = timelineScrollRef.current; if (!el) return 0; return (e.clientX - el.getBoundingClientRect().left + el.scrollLeft) / PIXELS_PER_SEC; }, [PIXELS_PER_SEC]);

  const onTimelinePointer = useCallback((e) => {
    if (e.target.dataset.handle) { dragState.current = { type: e.target.dataset.handle, itemIdx: Number(e.target.dataset.idx), itemTarget: e.target.dataset.target }; e.target.setPointerCapture(e.pointerId); return; }
    if (tool === 'razor') { const t = timeFromEvent(e); updateProject((next) => { for (const item of next.text_overlays) { if (t > item.start + 0.1 && t < item.end - 0.1) { next.text_overlays.push({ ...structuredClone(item), start: Number(t.toFixed(2)), end: item.end }); item.end = Number(t.toFixed(2)); } } for (const item of captionTrack.items) { if (t > item.start + 0.1 && t < item.end - 0.1) { const ci = next.tracks.find((x) => x.type === 'captions'); ci.items.push({ ...structuredClone(item), start: Number(t.toFixed(2)), end: item.end }); item.end = Number(t.toFixed(2)); } } }); return; }
    if (tool === 'marker') { const t = timeFromEvent(e); updateProject((next) => { next.markers.push({ time: Number(t.toFixed(2)), label: 'Marker ' + (next.markers.length + 1) }); }); return; }
    const t = timeFromEvent(e); seekTo(t);
  }, [tool, timeFromEvent, seekTo, updateProject, captionTrack]);

  const onTimelineMove = useCallback((e) => {
    if (!dragState.current) return;
    const t = timeFromEvent(e);
    const d = dragState.current;
    if (d.type === 'start') { updateProject((next) => { const list = d.itemTarget === 'overlay' ? next.text_overlays : next.tracks.find((x) => x.type === 'captions').items; if (list[d.itemIdx]) list[d.itemIdx].start = clamp(t, 0, list[d.itemIdx].end - 0.1); }, true); }
    else if (d.type === 'end') { updateProject((next) => { const list = d.itemTarget === 'overlay' ? next.text_overlays : next.tracks.find((x) => x.type === 'captions').items; if (list[d.itemIdx]) list[d.itemIdx].end = clamp(t, list[d.itemIdx].start + 0.1, total); }, true); }
    else if (d.type === 'move') { const dt = t - (d.lastTime ?? t); d.lastTime = t; updateProject((next) => {
      if (d.itemTarget === 'clip') { const c = next.tracks.find((x) => x.type === 'video').clips[0]; const dur = c.end - c.start; c.start = clamp(c.start + dt, 0, total - dur); c.end = c.start + dur; }
      else { const list = d.itemTarget === 'overlay' ? next.text_overlays : next.tracks.find((x) => x.type === 'captions').items; if (list[d.itemIdx]) { const dur = list[d.itemIdx].end - list[d.itemIdx].start; list[d.itemIdx].start = clamp(list[d.itemIdx].start + dt, 0, total - dur); list[d.itemIdx].end = list[d.itemIdx].start + dur; } }
    }, true); }
  }, [timeFromEvent, total, updateProject]);

  const endDrag = useCallback(() => { dragState.current = null; }, []);

  /* ── Blur region drawing on stage ── */
  const onStagePointerDown = useCallback((e) => {
    if (tool !== 'blur') return;
    if (e.target.closest('.nle-overlay,.nle-caption')) return;
    e.preventDefault();
    const st = stageRef.current.getBoundingClientRect();
    const startX = clamp((e.clientX - st.left) / st.width, 0, 1);
    const startY = clamp((e.clientY - st.top) / st.height, 0, 1);
    const tmpId = Date.now();
    blurDraw.current = { startX, startY, tmpId };
    const move = (ev) => {
      const endX = clamp((ev.clientX - st.left) / st.width, 0, 1);
      const endY = clamp((ev.clientY - st.top) / st.height, 0, 1);
      const x = Math.min(startX, endX), y = Math.min(startY, endY);
      const w = Math.abs(endX - startX), h = Math.abs(endY - startY);
      if (w < 0.005 || h < 0.005) return;
      const existing = project.blur_regions.find((r) => r.id === tmpId);
      if (existing) { Object.assign(existing, { x, y, w, h }); setProject((c) => structuredClone(c)); }
      else { updateProject((n) => { n.blur_regions.push({ id: tmpId, x, y, w, h, strength: blurDraft.strength, start: Number(currentTime.toFixed(2)), end: Number(Math.min(currentTime + 5, clipEnd).toFixed(2)) }); }); }
    };
    const up = () => { blurDraw.current = null; window.removeEventListener('pointermove', move); window.removeEventListener('pointerup', up); };
    window.addEventListener('pointermove', move);
    window.addEventListener('pointerup', up);
  }, [tool, project.blur_regions, blurDraft.strength, currentTime, clipEnd, updateProject]);

  /* ── Overlay pointer on stage ── */
  const onOverlayPointerDown = useCallback((idx, e) => { e.preventDefault(); setSelection({ type: 'overlay', idx }); const st = stageRef.current.getBoundingClientRect(); const move = (ev) => { const x = clamp((ev.clientX - st.left) / st.width, 0, 1); const y = clamp((ev.clientY - st.top) / st.height, 0, 1); updateProject((n) => { n.text_overlays[idx].x = x; n.text_overlays[idx].y = y; }, true); }; const up = () => { window.removeEventListener('pointermove', move); window.removeEventListener('pointerup', up); }; window.addEventListener('pointermove', move); window.addEventListener('pointerup', up); }, [updateProject]);

  /* ── Keyboard shortcuts ── */
  useEffect(() => {
    const handler = (e) => {
      if (e.target.tagName === 'INPUT' || e.target.tagName === 'SELECT' || e.target.tagName === 'TEXTAREA') return;
      const ctrl = e.ctrlKey || e.metaKey;
      if (e.code === 'Space') { e.preventDefault(); playPause(); }
      else if (e.code === 'ArrowLeft') { e.preventDefault(); stepFrame(e.shiftKey ? -10 : -1); }
      else if (e.code === 'ArrowRight') { e.preventDefault(); stepFrame(e.shiftKey ? 10 : 1); }
      else if (e.code === 'Home') { e.preventDefault(); seekTo(clipStart); }
      else if (e.code === 'End') { e.preventDefault(); seekTo(clipEnd); }
      else if (e.code === 'KeyL') { e.preventDefault(); setLoop((l) => !l); }
      else if (e.code === 'KeyV') { e.preventDefault(); setTool('cursor'); }
      else if (e.code === 'KeyC' && !ctrl) { e.preventDefault(); setTool('razor'); }
      else if (e.code === 'KeyM') { e.preventDefault(); setTool('marker'); }
      else if (e.code === 'KeyB') { e.preventDefault(); setTool('blur'); }
      else if (ctrl && e.code === 'KeyZ' && !e.shiftKey) { e.preventDefault(); setProject(h.undo); }
      else if (ctrl && (e.code === 'KeyY' || (e.code === 'KeyZ' && e.shiftKey))) { e.preventDefault(); setProject(h.redo); }
      else if (ctrl && e.code === 'KeyS') { e.preventDefault(); save(false); }
      else if (e.code === 'Delete' || e.code === 'Backspace') { if (selection) { e.preventDefault(); updateProject((n) => { const list = selection.type === 'overlay' ? n.text_overlays : n.tracks.find((x) => x.type === 'captions').items; list.splice(selection.idx, 1); }); setSelection(null); } }
      else if (ctrl && e.code === 'KeyD' && selection) { e.preventDefault(); updateProject((n) => { const list = selection.type === 'overlay' ? n.text_overlays : n.tracks.find((x) => x.type === 'captions').items; const item = structuredClone(list[selection.idx]); const dur = item.end - item.start; item.start = clamp(item.end, 0, clipEnd - dur); item.end = clamp(item.end + dur, 0, clipEnd); list.splice(selection.idx + 1, 0, item); }); }
      else if (e.code === 'Equal' || e.code === 'NumpadAdd') { e.preventDefault(); setTimelineZoom((z) => clamp(z * 1.25, 0.2, 5)); }
      else if (e.code === 'Minus' || e.code === 'NumpadSubtract') { e.preventDefault(); setTimelineZoom((z) => clamp(z / 1.25, 0.2, 5)); }
      else if (e.code === 'KeyF') { e.preventDefault(); setPreviewFullscreen((f) => !f); }
      else if (e.code === 'Escape') { if (previewFullscreen) { e.preventDefault(); setPreviewFullscreen(false); } }
    };
    window.addEventListener('keydown', handler);
    return () => window.removeEventListener('keydown', handler);
  }, [playPause, stepFrame, seekTo, clipStart, clipEnd, selection, updateProject, save, h, clipEnd, previewFullscreen]);

  /* ── Timeline wheel zoom ── */
  useEffect(() => {
    const el = timelineScrollRef.current; if (!el) return;
    const handler = (e) => { if (e.ctrlKey || e.metaKey) { e.preventDefault(); setTimelineZoom((z) => clamp(z * (e.deltaY < 0 ? 1.1 : 0.9), 0.2, 5)); } };
    el.addEventListener('wheel', handler, { passive: false });
    return () => el.removeEventListener('wheel', handler);
  }, []);

  /* ── Derived ── */
  const visibleOverlays = project.text_overlays.map((item, i) => ({ ...item, i })).filter((item) => currentTime >= item.start && currentTime <= item.end);
  const visibleCaptions = captionTrack.items.map((item, i) => ({ ...item, i })).filter((item) => currentTime >= item.start && currentTime <= item.end);

  const addOverlay = () => { if (!overlayDraft.text.trim()) return; updateProject((n) => { n.text_overlays.push({ ...overlayDraft, text: overlayDraft.text.trim(), x: 0.5, y: 0.8, start: Number(currentTime.toFixed(2)), end: Number(Math.min(currentTime + 3, clipEnd).toFixed(2)) }); }); setOverlayDraft((d) => ({ ...d, text: '' })); };
  const addCaption = () => { if (!captionDraft.text.trim()) return; updateProject((n) => { n.tracks.find((x) => x.type === 'captions').items.push({ ...captionDraft, text: captionDraft.text.trim(), start: Number(currentTime.toFixed(2)), end: Number(Math.min(currentTime + 3, clipEnd).toFixed(2)) }); }); setCaptionDraft((d) => ({ ...d, text: '' })); };
  const removeItem = (type, idx) => { updateProject((n) => { const list = type === 'overlay' ? n.text_overlays : n.tracks.find((x) => x.type === 'captions').items; list.splice(idx, 1); }); if (selection?.idx === idx) setSelection(null); };
  const patchClip = (k, v) => updateProject((n) => { n.tracks.find((x) => x.type === 'video').clips[0][k] = v; });
  const patchAudio = (k, v) => updateProject((n) => { n.tracks.find((x) => x.type === 'audio').clips[0][k] = v; });
  const patchFilters = (k, v) => updateProject((n) => { n.filters[k] = v; });
  const patchCrop = (k, v) => updateProject((n) => { n.crop[k] = v; });
  const patchOverlay = (idx, k, v) => { setSelection({ type: 'overlay', idx }); updateProject((n) => { n.text_overlays[idx][k] = v; }); };
  const patchCaption = (idx, k, v) => { setSelection({ type: 'caption', idx }); updateProject((n) => { n.tracks.find((x) => x.type === 'captions').items[idx][k] = v; }); };

  /* ── Inspector ── */
  const selectedItem = selection ? (selection.type === 'overlay' ? project.text_overlays[selection.idx] : selection.type === 'blur' ? project.blur_regions[selection.idx] : captionTrack.items[selection.idx]) : null;

  /* ── Export ── */
  const exportVideo = useCallback(async (selectionOnly) => { setExportStatus('Starting export...'); setExportLink(''); await save(true); const body = { _token: config.token }; if (saveOverOriginal) body.save_over_original = '1'; if (selectionOnly) { body.export_start = Number(currentTime.toFixed(2)); body.export_end = clipEnd; } const r = await fetch(config.exportUrl, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams(body) }); const d = await r.json(); if (!r.ok || !d.job_id) { setExportStatus(d.error || 'Export failed'); return; } const poll = async () => { const s = await fetch(config.statusUrl.replace('__JOB__', d.job_id), { headers: { 'X-Requested-With': 'XMLHttpRequest' } }).then((x) => x.json()); if (s.status === 'completed') { setExportStatus('Export complete'); setExportLink(config.downloadUrl.replace('__JOB__', d.job_id)); } else if (s.status === 'failed') setExportStatus(s.error || 'Failed'); else { setExportStatus(`${s.status} ${s.progress}%`); setTimeout(poll, 1000); } }; poll(); }, [save, saveOverOriginal, currentTime, clipEnd]);

  const markers = useMemo(() => Array.from({ length: Math.floor(total / (total / (10 * timelineZoom))) + 1 }, (_, i) => (total / (Math.floor(total / (total / (10 * timelineZoom))) || 1)) * i), [total, timelineZoom]);

  return (
    <div className="nle">
      {/* ── HEADER ── */}
      <div className="nle-header">
        <div className="nle-header-left">
          <span className="nle-logo">AME</span>
          <input className="nle-title-input" value={title} onChange={(e) => setTitle(e.target.value)} />
          <span className="nle-version">v{version}</span>
        </div>
        <div className="nle-header-center">
          <button className={`nle-tool-btn${tool === 'cursor' ? ' active' : ''}`} onClick={() => setTool('cursor')} title="Selection tool (V)">
            <svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="M4 2l12 16-4-1.5L8 22l-2-4L2 18z"/></svg>
          </button>
          <button className={`nle-tool-btn${tool === 'razor' ? ' active' : ''}`} onClick={() => setTool('razor')} title="Razor tool (C)">
            <svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="M17 15l-5 5-2-2 5-5-5-5 2-2 5 5 5-5 2 2-5 5 5 5z"/></svg>
          </button>
          <button className={`nle-tool-btn${tool === 'marker' ? ' active' : ''}`} onClick={() => setTool('marker')} title="Add marker (M)">
            <svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><circle cx="12" cy="6" r="3"/><rect x="10.5" y="6" width="3" height="14"/></svg>
          </button>
          <button className={`nle-tool-btn${tool === 'blur' ? ' active' : ''}`} onClick={() => setTool('blur')} title="Blur region (B)">
            <svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><rect x="3" y="3" width="18" height="18" rx="2" fill="none" stroke="currentColor" stroke-width="2" stroke-dasharray="3 2"/></svg>
          </button>
          <span className="nle-sep" />
          <button className="nle-tool-btn" onClick={() => setTimelineZoom((z) => clamp(z * 1.25, 0.2, 5))} title="Zoom in (+)">+</button>
          <button className="nle-tool-btn" onClick={() => setTimelineZoom((z) => clamp(z / 1.25, 0.2, 5))} title="Zoom out (-)">-</button>
          <button className={`nle-tool-btn${snap ? ' active' : ''}`} onClick={() => setSnap((s) => !s)} title="Snap to playhead">S</button>
          <button className={`nle-tool-btn${loop ? ' active' : ''}`} onClick={() => setLoop((l) => !l)} title="Loop (L)">L</button>
        </div>
        <div className="nle-header-right">
          <button className="nle-btn" onClick={() => setPreviewFullscreen((f) => !f)} title="Fullscreen preview (F)">FS</button>
          <button className="nle-btn" onClick={() => h.undo && setProject(h.undo)} disabled={!h.canUndo} title="Undo (Ctrl+Z)">Undo</button>
          <button className="nle-btn" onClick={() => h.redo && setProject(h.redo)} disabled={!h.canRedo} title="Redo (Ctrl+Y)">Redo</button>
          <button className="nle-btn primary" onClick={() => save(false)} title="Save (Ctrl+S)">Save</button>
          <button className="nle-btn accent" onClick={() => exportVideo(false)}>Export</button>
          <button className="nle-btn accent" onClick={() => exportVideo(true)} title="Export from playhead to end">Export selection</button>
          <label className="nle-check nle-save-orig"><input type="checkbox" checked={saveOverOriginal} onChange={(e) => setSaveOverOriginal(e.target.checked)} /> Overwrite original</label>
          <button className="nle-btn" onClick={cancel}>Cancel</button>
        </div>
      </div>

      <div className="nle-body">
        {/* ── LEFT: Program + Inspector ── */}
        <div className="nle-left">
          {/* Program Monitor */}
          <div className={`nle-monitor${previewFullscreen ? ' fullscreen' : ''}`}>
            <div className="nle-monitor-bar">
              <span className="nle-monitor-label">Program Monitor</span>
              <span className="nle-tc">{fmtTC(currentTime)}</span>
              {previewFullscreen && <button className="nle-fs-close" onClick={() => setPreviewFullscreen(false)} title="Exit fullscreen (Esc)">✕ Exit fullscreen</button>}
            </div>
            <div className={`nle-stage${tool === 'blur' ? ' tool-blur' : ''}`} ref={stageRef} onPointerDown={onStagePointerDown}>
              <div className="nle-stage-inner" style={{ filter: cssFilter(project.filters), transform: cssCrop(crop) }}>
                <video ref={videoRef} src={config.sourceUrl} preload="metadata"
                  onLoadedMetadata={(e) => { const v = e.currentTarget.duration; setDuration(v); if (!clip.end) { skipNextSave.current = false; patchClip('end', Number(v.toFixed(2))); } }}
                  onTimeUpdate={onTimeUpdate} onClick={playPause} />
              </div>
              {visibleOverlays.map((item) => (
                <div key={item.i} className={`nle-overlay${selection?.type === 'overlay' && selection.idx === item.i ? ' sel' : ''}`}
                  style={{ left: `${item.x*100}%`, top: `${item.y*100}%`, fontSize: `${item.font_size/10}cqw`, color: item.color||'#fff', opacity: item.opacity, padding: `${item.padding}px`, textShadow: item.shadow ? '0 2px 8px rgba(0,0,0,.9)' : 'none' }}
                  onPointerDown={(e) => onOverlayPointerDown(item.i, e)}>{item.text}</div>
              ))}
              {visibleCaptions.map((item) => (
                <div key={item.i} className={`nle-caption${selection?.type === 'caption' && selection.idx === item.i ? ' sel' : ''}`}
                  style={{ opacity: item.opacity, padding: `${item.padding}px ${item.padding*2}px`, textShadow: item.shadow ? '0 2px 8px rgba(0,0,0,.9)' : 'none' }}
                  onClick={() => setSelection({ type: 'caption', idx: item.i })}>{item.text}</div>
              ))}
              {project.blur_regions.filter((r) => currentTime >= r.start && currentTime <= r.end).map((r, i) => (
                <div key={r.id || i} className="nle-blur-region"
                  style={{ left: `${r.x*100}%`, top: `${r.y*100}%`, width: `${r.w*100}%`, height: `${r.h*100}%` }}
                  onClick={(e) => { e.stopPropagation(); setSelection({ type: 'blur', idx: project.blur_regions.indexOf(r) }); }} />
              ))}
            </div>
            <div className="nle-transport">
              <button className="nle-tbtn" onClick={() => stepFrame(-1)} title="Previous frame">◀◀</button>
              <button className="nle-tbtn primary" onClick={playPause}>{playing ? '❚❚' : '▶'}</button>
              <button className="nle-tbtn" onClick={() => stepFrame(1)} title="Next frame">▶▶</button>
              <button className="nle-tbtn" onClick={() => seekTo(clipStart)} title="Go to start">|◀</button>
              <button className="nle-tbtn" onClick={() => seekTo(clipEnd)} title="Go to end">▶|</button>
              <span className="nle-tc">{fmt(currentTime)} / {fmt(total)}</span>
            </div>
          </div>

          {/* Inspector Panel */}
          <div className="nle-inspector">
            <div className="nle-inspector-bar"><span>Inspector</span></div>
            <div className="nle-inspector-body">
              {!selectedItem && <div className="nle-inspector-empty">Select an overlay or caption to inspect</div>}
              {selectedItem && selection.type === 'overlay' && (
                <div className="nle-inspector-content">
                  <label>Text<input value={selectedItem.text} onChange={(e) => patchOverlay(selection.idx, 'text', e.target.value)} /></label>
                  <div className="nle-row"><label>Start<input type="number" step=".1" value={selectedItem.start} onChange={(e) => patchOverlay(selection.idx, 'start', Number(e.target.value)||0)} /></label>
                  <label>End<input type="number" step=".1" value={selectedItem.end} onChange={(e) => patchOverlay(selection.idx, 'end', Number(e.target.value)||0)} /></label></div>
                  <div className="nle-row"><label>X<input type="range" min="0" max="1" step=".01" value={selectedItem.x} onChange={(e) => patchOverlay(selection.idx, 'x', Number(e.target.value))} /></label>
                  <label>Y<input type="range" min="0" max="1" step=".01" value={selectedItem.y} onChange={(e) => patchOverlay(selection.idx, 'y', Number(e.target.value))} /></label></div>
                  <div className="nle-row"><label>Size<input type="number" min="10" max="180" value={selectedItem.font_size} onChange={(e) => patchOverlay(selection.idx, 'font_size', Number(e.target.value)||32)} /></label>
                  <label>Color<input type="color" value={selectedItem.color} onChange={(e) => patchOverlay(selection.idx, 'color', e.target.value)} /></label></div>
                  <div className="nle-row"><label>Opacity<input type="range" min="0" max="1" step=".05" value={selectedItem.opacity} onChange={(e) => patchOverlay(selection.idx, 'opacity', Number(e.target.value))} /></label>
                  <label>Padding<input type="range" min="0" max="40" step="2" value={selectedItem.padding} onChange={(e) => patchOverlay(selection.idx, 'padding', Number(e.target.value))} /></label></div>
                  <label className="nle-check"><input type="checkbox" checked={selectedItem.shadow} onChange={(e) => patchOverlay(selection.idx, 'shadow', e.target.checked)} /> Shadow</label>
                  <button className="nle-btn danger" onClick={() => removeItem('overlay', selection.idx)}>Delete</button>
                </div>
              )}
              {selectedItem && selection.type === 'caption' && (
                <div className="nle-inspector-content">
                  <label>Text<textarea rows="3" value={selectedItem.text} onChange={(e) => patchCaption(selection.idx, 'text', e.target.value)} /></label>
                  <div className="nle-row"><label>Start<input type="number" step=".1" value={selectedItem.start} onChange={(e) => patchCaption(selection.idx, 'start', Number(e.target.value)||0)} /></label>
                  <label>End<input type="number" step=".1" value={selectedItem.end} onChange={(e) => patchCaption(selection.idx, 'end', Number(e.target.value)||0)} /></label></div>
                  <div className="nle-row"><label>Opacity<input type="range" min="0" max="1" step=".05" value={selectedItem.opacity} onChange={(e) => patchCaption(selection.idx, 'opacity', Number(e.target.value))} /></label>
                  <label>Padding<input type="range" min="0" max="40" step="2" value={selectedItem.padding} onChange={(e) => patchCaption(selection.idx, 'padding', Number(e.target.value))} /></label></div>
                  <label className="nle-check"><input type="checkbox" checked={selectedItem.shadow} onChange={(e) => patchCaption(selection.idx, 'shadow', e.target.checked)} /> Shadow</label>
                  <button className="nle-btn danger" onClick={() => removeItem('caption', selection.idx)}>Delete</button>
                </div>
              )}
              {selectedItem && selection.type === 'blur' && (
                <div className="nle-inspector-content">
                  <h4>Blur region</h4>
                  <div className="nle-row"><label>Start<input type="number" step=".1" value={selectedItem.start} onChange={(e) => { const v = Number(e.target.value)||0; updateProject((n) => { n.blur_regions[selection.idx].start = v; }); }} /></label>
                  <label>End<input type="number" step=".1" value={selectedItem.end} onChange={(e) => { const v = Number(e.target.value)||0; updateProject((n) => { n.blur_regions[selection.idx].end = v; }); }} /></label></div>
                  <label>Strength ({selectedItem.strength})<input type="range" min="2" max="30" step="1" value={selectedItem.strength} onChange={(e) => updateProject((n) => { n.blur_regions[selection.idx].strength = Number(e.target.value); })} /></label>
                  <div className="nle-row"><label>X<input type="range" min="0" max="1" step=".01" value={selectedItem.x} onChange={(e) => updateProject((n) => { n.blur_regions[selection.idx].x = Number(e.target.value); }, true)} /></label>
                  <label>Y<input type="range" min="0" max="1" step=".01" value={selectedItem.y} onChange={(e) => updateProject((n) => { n.blur_regions[selection.idx].y = Number(e.target.value); }, true)} /></label></div>
                  <div className="nle-row"><label>Width<input type="range" min="0.01" max="1" step=".01" value={selectedItem.w} onChange={(e) => updateProject((n) => { n.blur_regions[selection.idx].w = Number(e.target.value); }, true)} /></label>
                  <label>Height<input type="range" min="0.01" max="1" step=".01" value={selectedItem.h} onChange={(e) => updateProject((n) => { n.blur_regions[selection.idx].h = Number(e.target.value); }, true)} /></label></div>
                  <button className="nle-btn danger" onClick={() => { updateProject((n) => { n.blur_regions.splice(selection.idx, 1); }); setSelection(null); }}>Delete</button>
                </div>
              )}
              {!selectedItem && (
                <div className="nle-inspector-content">
                  <h4>Clip</h4>
                  <div className="nle-row"><label>Start<input type="number" step=".1" value={clipStart.toFixed(2)} onChange={(e) => patchClip('start', Number(e.target.value)||0)} /></label>
                  <label>End<input type="number" step=".1" value={clipEnd.toFixed(2)} onChange={(e) => patchClip('end', Number(e.target.value)||0)} /></label></div>
                  <label>Speed ({project.speed}x)<input type="range" min="0.25" max="3" step=".25" value={project.speed} onChange={(e) => updateProject((n) => { n.speed = Number(e.target.value); })} /></label>
                  <label>Transition<select value={clip.transition||'none'} onChange={(e) => patchClip('transition', e.target.value)}><option value="none">None</option><option value="fade">Fade</option></select></label>
                  <h4>Audio</h4>
                  <label>Volume ({Math.round((audio.volume??1)*100)}%)<input type="range" min="0" max="2" step=".05" value={audio.volume??1} onChange={(e) => patchAudio('volume', Number(e.target.value))} /></label>
                  <label className="nle-check"><input type="checkbox" checked={!!audio.muted} onChange={(e) => patchAudio('muted', e.target.checked)} /> Mute</label>
                  <div className="nle-row"><label>Fade in<input type="number" step=".1" min="0" value={audio.fade_in??0} onChange={(e) => patchAudio('fade_in', Number(e.target.value)||0)} /></label>
                  <label>Fade out<input type="number" step=".1" min="0" value={audio.fade_out??0} onChange={(e) => patchAudio('fade_out', Number(e.target.value)||0)} /></label></div>
                  <h4>Crop & mirror</h4>
                  <label>Zoom ({Number(crop.zoom).toFixed(1)}x)<input type="range" min="1" max="3" step=".1" value={crop.zoom} onChange={(e) => patchCrop('zoom', Number(e.target.value))} /></label>
                  <label>Pan X<input type="range" min="0" max="1" step=".01" value={crop.panX??0.5} onChange={(e) => patchCrop('panX', Number(e.target.value))} /></label>
                  <label>Pan Y<input type="range" min="0" max="1" step=".01" value={crop.panY??0.5} onChange={(e) => patchCrop('panY', Number(e.target.value))} /></label>
                  <div className="nle-row"><label className="nle-check"><input type="checkbox" checked={!!crop.mirrorH} onChange={(e) => patchCrop('mirrorH', e.target.checked)} /> Mirror H</label>
                  <label className="nle-check"><input type="checkbox" checked={!!crop.mirrorV} onChange={(e) => patchCrop('mirrorV', e.target.checked)} /> Mirror V</label></div>
                  <button className="nle-btn small" onClick={() => updateProject((n) => { n.crop = { ...CROP_DEFAULTS }; })}>Reset crop</button>
                </div>
              )}
            </div>
          </div>
        </div>

        {/* ── RIGHT: Effects + Add panel ── */}
        <div className="nle-right">
          <div className="nle-panel">
            <div className="nle-panel-bar"><span>Effects</span></div>
            <div className="nle-panel-body nle-filters">
              <div className="nle-preset-grid">
                {FILTER_PRESETS.map((p) => (
                  <button key={p.name} className={`nle-preset${JSON.stringify(project.filters) === JSON.stringify(p.values) ? ' active' : ''}`}
                    onClick={() => updateProject((n) => { n.filters = { ...p.values }; })}>{p.name}</button>
                ))}
              </div>
              <label>Brightness<input type="range" min="-0.5" max="0.5" step=".05" value={project.filters.brightness} onChange={(e) => patchFilters('brightness', Number(e.target.value))} /></label>
              <label>Contrast<input type="range" min="0.5" max="2" step=".05" value={project.filters.contrast} onChange={(e) => patchFilters('contrast', Number(e.target.value))} /></label>
              <label>Saturation<input type="range" min="0" max="2" step=".05" value={project.filters.saturation} onChange={(e) => patchFilters('saturation', Number(e.target.value))} /></label>
              <label>Blur<input type="range" min="0" max="8" step=".5" value={project.filters.blur} onChange={(e) => patchFilters('blur', Number(e.target.value))} /></label>
              <label>Hue<input type="range" min="-180" max="180" step="5" value={project.filters.hue} onChange={(e) => patchFilters('hue', Number(e.target.value))} /></label>
              <div className="nle-row"><label className="nle-check"><input type="checkbox" checked={!!project.filters.grayscale} onChange={(e) => patchFilters('grayscale', e.target.checked)} /> B&W</label>
              <label className="nle-check"><input type="checkbox" checked={!!project.filters.sepia} onChange={(e) => patchFilters('sepia', e.target.checked)} /> Sepia</label></div>
              <button className="nle-btn small" onClick={() => updateProject((n) => { n.filters = { ...FILTER_DEFAULTS }; })}>Reset</button>
            </div>
          </div>
          <div className="nle-panel">
            <div className="nle-panel-bar"><span>Add</span></div>
            <div className="nle-panel-body">
              <div className="nle-add-section">
                <h4>Text overlay</h4>
                <input value={overlayDraft.text} placeholder="Overlay text" onChange={(e) => setOverlayDraft({ ...overlayDraft, text: e.target.value })} onKeyDown={(e) => { if (e.key === 'Enter') addOverlay(); }} />
                <div className="nle-row"><label>Size<input type="number" min="10" max="180" value={overlayDraft.font_size} onChange={(e) => setOverlayDraft({ ...overlayDraft, font_size: Number(e.target.value)||32 })} /></label>
                <label>Color<input type="color" value={overlayDraft.color} onChange={(e) => setOverlayDraft({ ...overlayDraft, color: e.target.value })} /></label></div>
                <button className="nle-btn primary" onClick={addOverlay}>+ Add at playhead</button>
              </div>
              <div className="nle-add-section">
                <h4>Caption</h4>
                <input value={captionDraft.text} placeholder="Caption text" onChange={(e) => setCaptionDraft({ ...captionDraft, text: e.target.value })} onKeyDown={(e) => { if (e.key === 'Enter') addCaption(); }} />
                <button className="nle-btn primary" onClick={addCaption}>+ Add at playhead</button>
              </div>
              {project.markers.length > 0 && (
                <div className="nle-add-section">
                  <h4>Markers</h4>
                  {project.markers.map((m, i) => (
                    <div key={i} className="nle-marker-item" onClick={() => seekTo(m.time)}>
                      <span className="nle-marker-dot" />
                      <span>{fmt(m.time)}</span>
                      <span className="nle-marker-label">{m.label}</span>
                      <button className="nle-btn tiny" onClick={(e) => { e.stopPropagation(); updateProject((n) => { n.markers.splice(i, 1); }); }}>×</button>
                    </div>
                  ))}
                </div>
              )}
              <div className="nle-add-section">
                <h4>Blur regions</h4>
                <label>Blur strength ({blurDraft.strength})<input type="range" min="2" max="30" step="1" value={blurDraft.strength} onChange={(e) => setBlurDraft({ ...blurDraft, strength: Number(e.target.value) })} /></label>
                <p className="nle-hint">Select blur tool (B), then click & drag on the video to create a blur region.</p>
                {project.blur_regions.length === 0 && <p className="nle-hint">No blur regions yet.</p>}
                {project.blur_regions.map((r, i) => (
                  <div key={r.id || i} className="nle-marker-item" onClick={() => { seekTo(r.start); setSelection({ type: 'blur', idx: i }); }}>
                    <span className="nle-marker-dot" style={{ background: '#a855f7' }} />
                    <span>{fmt(r.start)}–{fmt(r.end)}</span>
                    <span className="nle-marker-label">str:{r.strength}</span>
                    <button className="nle-btn tiny" onClick={(e) => { e.stopPropagation(); updateProject((n) => { n.blur_regions.splice(i, 1); }); setSelection(null); }}>×</button>
                  </div>
                ))}
              </div>
            </div>
          </div>
        </div>
      </div>

      {/* ── TIMELINE ── */}
      <div className="nle-timeline">
        <div className="nle-timeline-header">
          <div className="nle-timeline-ruler" ref={timelineScrollRef} style={{ overflowX: 'auto' }}>
            <div className="nle-timeline-inner" ref={timelineInnerRef} style={{ width: `${timelineWidth}px`, position: 'relative' }}
              onPointerDown={onTimelinePointer} onPointerMove={onTimelineMove} onPointerUp={endDrag} onPointerLeave={endDrag}>
              {/* Ruler */}
              <div className="nle-ruler">
                {markers.map((m, i) => <div key={i} className="nle-ruler-tick" style={{ left: `${(m / total) * 100}%` }}><span>{fmt(m)}</span></div>)}
              </div>
              {/* Video track */}
              <div className="nle-track" style={{ top: 0 }}>
                <div className="nle-track-label">V1</div>
                <div className="nle-track-lane" style={{ width: '100%' }}>
                  <div className="nle-clip video" style={{ left: `${(clipStart/total)*100}%`, width: `${Math.max(2, ((clipEnd-clipStart)/total)*100)}%` }}>
                    <span className="nle-clip-handle left" data-handle="start" data-target="clip" />
                    <span className="nle-clip-name" data-handle="move" data-target="clip" style={{cursor:'grab'}}>Video</span>
                    <span className="nle-clip-handle right" data-handle="end" data-target="clip" />
                  </div>
                </div>
              </div>
              {/* Overlays track */}
              <div className="nle-track" style={{ top: '40px' }}>
                <div className="nle-track-label">Ov{project.text_overlays.length > 0 ? ` (${project.text_overlays.length})` : ''}</div>
                <div className="nle-track-lane">
                  {project.text_overlays.map((item, i) => (
                    <div key={i} className={`nle-clip overlay${selection?.type === 'overlay' && selection.idx === i ? ' sel' : ''}`}
                      style={{ left: `${(item.start/total)*100}%`, width: `${Math.max(1.5, ((item.end-item.start)/total)*100)}%`, opacity: item.opacity }}
                      onClick={(e) => { e.stopPropagation(); setSelection({ type: 'overlay', idx: i }); }}>
                      <span className="nle-clip-handle left" data-handle="start" data-target="overlay" data-idx={i} />
                      <span className="nle-clip-name">{item.text}</span>
                      <span className="nle-clip-handle right" data-handle="end" data-target="overlay" data-idx={i} />
                    </div>
                  ))}
                </div>
              </div>
              {/* Captions track */}
              <div className="nle-track" style={{ top: '80px' }}>
                <div className="nle-track-label">CC{captionTrack.items.length > 0 ? ` (${captionTrack.items.length})` : ''}</div>
                <div className="nle-track-lane">
                  {captionTrack.items.map((item, i) => (
                    <div key={i} className={`nle-clip caption${selection?.type === 'caption' && selection.idx === i ? ' sel' : ''}`}
                      style={{ left: `${(item.start/total)*100}%`, width: `${Math.max(1.5, ((item.end-item.start)/total)*100)}%`, opacity: item.opacity }}
                      onClick={(e) => { e.stopPropagation(); setSelection({ type: 'caption', idx: i }); }}>
                      <span className="nle-clip-handle left" data-handle="start" data-target="caption" data-idx={i} />
                      <span className="nle-clip-name">{item.text}</span>
                      <span className="nle-clip-handle right" data-handle="end" data-target="caption" data-idx={i} />
                    </div>
                  ))}
                </div>
              </div>
              {/* Markers */}
              {project.markers.map((m, i) => (
                <div key={i} className="nle-marker" style={{ left: `${(m.time/total)*100}%` }} title={m.label} onClick={() => seekTo(m.time)}>
                  <div className="nle-marker-flag" />
                </div>
              ))}
              {/* Playhead */}
              <div className="nle-playhead" style={{ left: `${(clamp(currentTime, 0, total)/total)*100}%` }}>
                <div className="nle-playhead-head" />
                <div className="nle-playhead-line" />
              </div>
            </div>
          </div>
        </div>
        <div className="nle-timeline-status">
          <span className="nle-tool-indicator">{tool === 'cursor' ? 'SEL' : tool === 'razor' ? 'RAZ' : tool === 'blur' ? 'BLR' : 'MRK'}</span>
          <span>{fmtTC(currentTime)} / {fmtTC(total)}</span>
          <span>Zoom: {Math.round(timelineZoom * 100)}%</span>
          <span>Snap: {snap ? 'ON' : 'OFF'}</span>
          <span>Loop: {loop ? 'ON' : 'OFF'}</span>
          <span className="nle-status-msg">{status}</span>
          <span className="nle-export-msg">{exportStatus} {exportLink && <a href={exportLink}>Download</a>}</span>
          <span className="nle-shortcut-hints">V=Select C=Razor M=Marker Space=Play ←→=Step L=Loop Ctrl+Z/Y=Undo/Redo F=Fullscreen</span>
        </div>
      </div>
    </div>
  );
}

createRoot(document.getElementById('root')).render(<Editor />);
