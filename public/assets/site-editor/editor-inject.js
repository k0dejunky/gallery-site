(function () {
  if (window.__SITE_EDITOR_INJECTED__) return;
  window.__SITE_EDITOR_INJECTED__ = true;

  var changes = [];
  var mode = 'select';
  var selectedEl = null;
  var highlightEl = null;
  var moveData = null;
  var addDraft = null;

  function sel(el) {
    if (!el || el === document.body || el === document.documentElement) return null;
    var parts = [];
    var cur = el;
    while (cur && cur !== document.body && cur !== document.documentElement) {
      var tag = cur.tagName.toLowerCase();
      var part = tag;
      if (cur.id) { part = '#' + cur.id; parts.unshift(part); break; }
      if (cur.className && typeof cur.className === 'string') {
        var cls = cur.className.trim().split(/\s+/).filter(function (c) { return c && c.indexOf('__') < 0 && c.indexOf('nle-') < 0 && c.indexOf('se-') < 0; }).slice(0, 2).join('.');
        if (cls) part += '.' + cls;
      }
      var parent = cur.parentElement;
      if (parent) {
        var siblings = Array.prototype.filter.call(parent.children, function (c) { return c.tagName === cur.tagName; });
        if (siblings.length > 1) {
          var idx = siblings.indexOf(cur) + 1;
          part += ':nth-of-type(' + idx + ')';
        }
      }
      parts.unshift(part);
      cur = cur.parentElement;
    }
    return parts.join(' > ');
  }

  function getRect(el) {
    var r = el.getBoundingClientRect();
    var st = document.documentElement.scrollTop || document.body.scrollTop;
    var sl = document.documentElement.scrollLeft || document.body.scrollLeft;
    return { top: r.top + st, left: r.left + sl, width: r.width, height: r.height };
  }

  function getElementLabel(el) {
    var tag = el.tagName.toLowerCase();
    var text = (el.textContent || '').trim().substring(0, 40);
    if (el.id) return '#' + el.id;
    if (el.className && typeof el.className === 'string') {
      var cls = el.className.trim().split(/\s+/).filter(function (c) { return c.indexOf('nle-') < 0 && c.indexOf('se-') < 0; }).slice(0, 2).join('.');
      if (cls) tag += '.' + cls;
    }
    return tag + (text ? ': ' + text : '');
  }

  function isInsideOverlay(el) {
    while (el) {
      if (el.classList && (el.classList.contains('se-overlay') || el.classList.contains('se-panel') || el.classList.contains('se-toolbar'))) return true;
      el = el.parentElement;
    }
    return false;
  }

  function highlight(el) {
    if (highlightEl && highlightEl !== selectedEl) {
      highlightEl.style.removeProperty('outline');
      highlightEl.style.removeProperty('outline-offset');
    }
    highlightEl = el;
    if (el && el !== selectedEl) {
      el.style.outline = '2px solid #5b8af5';
      el.style.outlineOffset = '2px';
    }
  }

  function selectEl(el) {
    if (selectedEl) selectedEl.style.removeProperty('outline');
    if (selectedEl) selectedEl.style.removeProperty('outline-offset');
    selectedEl = el;
    if (el) {
      el.style.outline = '2px solid #e8a840';
      el.style.outlineOffset = '2px';
    }
    sendState();
  }

  function deselectAll() {
    if (selectedEl) { selectedEl.style.removeProperty('outline'); selectedEl.style.removeProperty('outline-offset'); }
    selectedEl = null;
    highlight(null);
    sendState();
  }

  function findChangeForEl(el) {
    var s = sel(el);
    if (!s) return -1;
    for (var i = 0; i < changes.length; i++) {
      if (changes[i].selector === s) return i;
    }
    return -1;
  }

  function applyChanges() {
    changes.forEach(function (c) {
      if (c.type === 'hide') {
        var el = document.querySelector(c.selector);
        if (el) el.style.display = 'none';
      } else if (c.type === 'delete') {
        var el = document.querySelector(c.selector);
        if (el) el.style.display = 'none';
      } else if (c.type === 'move') {
        var el = document.querySelector(c.selector);
        if (el) el.style.transform = 'translate(' + (c.dx || 0) + 'px,' + (c.dy || 0) + 'px)';
      } else if (c.type === 'restyle') {
        var el = document.querySelector(c.selector);
        if (el) Object.keys(c.styles || {}).forEach(function (k) { el.style[k] = c.styles[k]; });
      } else if (c.type === 'add') {
        var target = c.parent ? document.querySelector(c.parent) : document.body;
        if (target) {
          var div = document.createElement(c.tag || 'div');
          div.className = 'se-added-element';
          div.setAttribute('data-se-added', 'true');
          div.innerHTML = c.html || '';
          if (c.styles) Object.keys(c.styles).forEach(function (k) { div.style[k] = c.styles[k]; });
          if (c.position === 'prepend') target.prepend(div);
          else if (c.position === 'before') target.parentElement.insertBefore(div, target);
          else if (c.position === 'after') target.parentElement.insertBefore(div, target.nextSibling);
          else target.appendChild(div);
        }
      }
    });
  }

  function sendState() {
    var state = {
      selected: selectedEl ? { selector: sel(selectedEl), label: getElementLabel(selectedEl), rect: getRect(selectedEl) } : null,
      changes: changes.map(function (c) {
        var r = document.querySelector(c.selector);
        return { type: c.type, selector: c.selector, label: r ? getElementLabel(r) : c.selector, dx: c.dx, dy: c.dy, styles: c.styles, html: c.html, tag: c.tag, parent: c.parent, position: c.position };
      })
    };
    window.parent.postMessage({ type: 'se-state', state: state }, '*');
  }

  document.addEventListener('mouseover', function (e) {
    if (mode !== 'select' || isInsideOverlay(e.target)) return;
    highlight(e.target);
  }, true);

  document.addEventListener('click', function (e) {
    if (isInsideOverlay(e.target)) return;
    if (mode === 'select') {
      e.preventDefault();
      e.stopPropagation();
      selectEl(e.target);
    } else if (mode === 'hide') {
      e.preventDefault();
      e.stopPropagation();
      var s = sel(e.target);
      if (s) {
        var idx = -1;
        for (var i = 0; i < changes.length; i++) { if (changes[i].selector === s && changes[i].type === 'hide') { idx = i; break; } }
        if (idx >= 0) { changes.splice(idx, 1); e.target.style.removeProperty('display'); }
        else { changes.push({ type: 'hide', selector: s }); e.target.style.display = 'none'; }
        sendState();
      }
    } else if (mode === 'delete') {
      e.preventDefault();
      e.stopPropagation();
      var s = sel(e.target);
      if (s) {
        changes.push({ type: 'delete', selector: s });
        e.target.style.display = 'none';
        deselectAll();
      }
    } else if (mode === 'move') {
      e.preventDefault();
      e.stopPropagation();
      var s = sel(e.target);
      if (s) {
        var rect = e.target.getBoundingClientRect();
        var startX = e.clientX, startY = e.clientY;
        var existing = null;
        for (var i = 0; i < changes.length; i++) { if (changes[i].selector === s && changes[i].type === 'move') { existing = changes[i]; break; } }
        var origDx = existing ? (existing.dx || 0) : 0;
        var origDy = existing ? (existing.dy || 0) : 0;
        function onMove(ev) {
          var dx = origDx + (ev.clientX - startX);
          var dy = origDy + (ev.clientY - startY);
          e.target.style.transform = 'translate(' + dx + 'px,' + dy + 'px)';
        }
        function onUp(ev) {
          document.removeEventListener('mousemove', onMove);
          document.removeEventListener('mouseup', onUp);
          var finalDx = origDx + (ev.clientX - startX);
          var finalDy = origDy + (ev.clientY - startY);
          if (existing) { existing.dx = finalDx; existing.dy = finalDy; }
          else { changes.push({ type: 'move', selector: s, dx: finalDx, dy: finalDy }); }
          sendState();
        }
        document.addEventListener('mousemove', onMove);
        document.addEventListener('mouseup', onUp);
      }
    }
  }, true);

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') deselectAll();
    if ((e.key === 'Delete' || e.key === 'Backspace') && selectedEl && !e.target.closest('input,textarea,select')) {
      e.preventDefault();
      var s = sel(selectedEl);
      if (s) { changes.push({ type: 'delete', selector: s }); selectedEl.style.display = 'none'; deselectAll(); }
    }
  }, true);

  window.addEventListener('message', function (e) {
    var d = e.data;
    if (!d || !d.type) return;
    if (d.type === 'se-set-mode') { mode = d.mode; deselectAll(); }
    else if (d.type === 'se-set-changes') { changes = d.changes || []; applyChanges(); sendState(); }
    else if (d.type === 'se-undo-last') {
      if (changes.length > 0) {
        var last = changes.pop();
        var el = document.querySelector(last.selector);
        if (el) {
          if (last.type === 'hide' || last.type === 'delete') el.style.removeProperty('display');
          else if (last.type === 'move') el.style.removeProperty('transform');
        }
        sendState();
      }
    }
    else if (d.type === 'se-add-element') {
      addDraft = { tag: d.tag, html: d.html, parent: d.parent, position: d.position, styles: d.styles || {} };
      mode = 'add-place';
      window.parent.postMessage({ type: 'se-add-mode', message: 'Click an element to place the new content' }, '*');
    }
    else if (d.type === 'se-place-add' && addDraft) {
      var target = document.querySelector(d.targetSelector);
      if (target) {
        var div = document.createElement(addDraft.tag);
        div.className = 'se-added-element';
        div.setAttribute('data-se-added', 'true');
        div.innerHTML = addDraft.html;
        if (addDraft.styles) Object.keys(addDraft.styles).forEach(function (k) { div.style[k] = addDraft.styles[k]; });
        var pos = addDraft.position || 'append';
        if (pos === 'prepend') target.prepend(div);
        else if (pos === 'before') target.parentElement.insertBefore(div, target);
        else if (pos === 'after') target.parentElement.insertBefore(div, target.nextSibling);
        else target.appendChild(div);
        changes.push({ type: 'add', tag: addDraft.tag, html: addDraft.html, parent: d.targetSelector, position: pos, styles: addDraft.styles });
        addDraft = null;
        mode = 'select';
        sendState();
      }
    }
  });

  sendState();
})();
