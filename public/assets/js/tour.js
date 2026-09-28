/* Interactive site tour: a guided, page-by-page walkthrough. Each step
   navigates to the feature's own page and spotlights the feature there. It
   starts on the first login, is dismissible, and can be re-launched from the
   Settings page. Tour state lives in sessionStorage so it survives the
   cross-page navigation and resumes where it left off. Steps that have no
   reachable target (e.g. no collection with videos yet) are skipped. */
(function(){
  var SEEN = 'galleryTourSeen_v2';
  var ACTIVE = 'galleryTourActive_v2';
  var STEP = 'galleryTourStep_v2';

  function T(){ return window.TOUR_TARGETS || {}; }
  function base(){ return document.body ? (document.body.dataset.base || '') : ''; }

  var steps = [
    { title: 'Welcome', body: 'A quick guided tour — we\'ll visit each part of the site so you can see what\'s here. You can skip it anytime and replay it later from Settings.' },
    { url: '/galleries', sel: '.grid', title: 'Gallery grid', body: 'Every gallery and media item appears as a card here. Use the Images / Videos tabs to filter the grid.' },
    { url: function(){ return T().gallery; }, sel: '.grid a', title: 'Open a gallery', body: 'Open any gallery — then click an image for the full-screen lightbox: zoom, pinch and switch to the full-size original.' },
    { url: function(){ return T().video; }, sel: '#video-player-wrap', title: 'Video player', body: 'Videos play here with a custom control bar: speed, quality, full screen and picture-in-picture.' },
    { url: function(){ return T().video; }, sel: '.player-keep', title: 'Keep PiP open', body: 'With "Keep open" checked (the default), the picture-in-picture window stays up after a video ends so you can replay it.' },
    { url: function(){ return T().video; }, sel: '#browse-galleries-btn', title: 'Browse & queue', body: 'Open this panel to browse other galleries without leaving the player — picture-in-picture keeps playing while you add videos to the queue.' },
    { url: function(){ return T().playlist; }, sel: '.player-playlist', title: 'Playlist', body: 'Videos you save to a collection play here as a playlist with thumbnails and durations — the playing one is highlighted and the next starts automatically.' },
    { url: '/collections', sel: '.grid', title: 'Collections', body: 'Build collections of whole galleries and individual videos, then play a collection as a playlist in the video player.' },
    { url: '/favorites', sel: '.favorites-section', title: 'Favorites', body: 'Mark your favorite categories and galleries so your home page is built around them.' },
    { url: '/chat', sel: '#chat-input', title: 'Chat', body: 'Chat with the operator. Retrieval and fine-tuned AI modes answer from real conversations; operator mode waits for a human.' },
    { url: '/membership', sel: 'main', title: 'Membership', body: 'Review your membership tier, upgrade, or manage a trial.' },
    { url: '/settings', sel: '.settings-form', title: 'Settings', body: 'Manage your profile, notifications, timezone and theme — and replay this tour any time.' }
  ];

  var idx = 0, list = [];
  var cleanupFns = [];

  function resolveUrl(step){
    var u = typeof step.url === 'function' ? step.url() : step.url;
    if(!u) return null;
    var b = base();
    if(u.charAt(0) === '/' && b && u.indexOf(b) !== 0) return b + u;
    return u;
  }

  // A step is included when it has a target and, if it navigates, a
  // resolvable URL (e.g. the playlist step is dropped when the user has no
  // collection with videos yet).
  function valid(s){
    if(!s.sel) return true;
    if(s.url && !resolveUrl(s)) return false;
    return true;
  }

  function visible(){ return steps.filter(valid); }

  function samePage(u){
    var target = new URL(u, location.origin);
    return target.pathname === location.pathname && target.search === location.search;
  }

  function setActive(i){
    try{ sessionStorage.setItem(ACTIVE, '1'); sessionStorage.setItem(STEP, String(i)); }catch(e){}
  }
  function clearActive(){
    try{ sessionStorage.removeItem(ACTIVE); sessionStorage.removeItem(STEP); }catch(e){}
  }

  function cleanup(){
    var el = document.getElementById('tour-card'); if(el) el.remove();
    var sp = document.getElementById('tour-spot'); if(sp) sp.remove();
    var ov = document.getElementById('tour-overlay'); if(ov) ov.remove();
    cleanupFns.forEach(function(fn){ try{ fn(); }catch(e){} });
    cleanupFns = [];
  }

  function onViewport(fn){
    window.addEventListener('scroll', fn, {passive:true});
    window.addEventListener('resize', fn);
    cleanupFns.push(function(){
      window.removeEventListener('scroll', fn);
      window.removeEventListener('resize', fn);
    });
  }

  // Spotlight: a light full-page dim with a generous cut-out over the target
  // section so the page stays visible and only the feature is emphasised.
  function placeSpotlight(ov, sp, target){
    var PAD = 14;
    var r = target.getBoundingClientRect();
    var vw = window.innerWidth, vh = window.innerHeight;
    var x = Math.max(0, r.left - PAD);
    var y = Math.max(0, r.top - PAD);
    var w = r.width + PAD * 2;
    var h = r.height + PAD * 2;
    if(w > vw) w = vw;
    if(h > vh) h = vh;
    x = Math.min(x, vw - w);
    y = Math.min(y, vh - h);
    if(w <= 0 || h <= 0 || (w >= vw && h >= vh)){
      ov.style.display = 'none';
      sp.style.display = 'none';
      return;
    }
    ov.style.display = '';
    sp.style.display = '';
    var x2 = Math.min(vw, x + w), y2 = Math.min(vh, y + h);
    ov.style.clipPath =
      'polygon(0 0, ' + vw + 'px 0, ' + vw + 'px ' + y + 'px, ' + x2 + 'px ' + y + 'px, ' +
      x2 + 'px ' + y2 + 'px, ' + x + 'px ' + y2 + 'px, ' + x + 'px ' + y + 'px, 0 ' + y + 'px)';
    sp.style.top = y + 'px';
    sp.style.left = x + 'px';
    sp.style.width = w + 'px';
    sp.style.height = h + 'px';
  }

  function show(){
    cleanup();
    if(idx < 0 || idx >= list.length){ finish(); return; }
    var step = list[idx];

    if(step.sel && !document.querySelector(step.sel)){
      // Target missing on this page (e.g. access blocked or no matching
      // content) — move on to the next step rather than get stuck.
      idx++;
      goToStep(idx);
      return;
    }

    var target = step.sel ? document.querySelector(step.sel) : null;

    if(target){
      try{ target.scrollIntoView({block:'center', behavior:'smooth'}); }catch(e){}
      var ov = document.createElement('div');
      ov.id = 'tour-overlay';
      ov.className = 'tour-overlay';
      document.body.appendChild(ov);
      var sp = document.createElement('div');
      sp.id = 'tour-spot';
      sp.className = 'tour-spot';
      document.body.appendChild(sp);
      var place = function(){ placeSpotlight(ov, sp, target); };
      place();
      onViewport(place);
    }

    var card = document.createElement('div');
    card.id = 'tour-card';
    card.className = 'tour-card';
    var n = idx + 1, total = list.length;
    card.innerHTML =
      '<div class="tour-body">' +
        '<strong class="tour-title">' + esc(step.title) + '</strong>' +
        '<p class="tour-text">' + esc(step.body) + '</p>' +
      '</div>' +
      '<div class="tour-actions">' +
        '<span class="tour-count">' + n + ' / ' + total + '</span>' +
        (idx > 0 ? '<button type="button" class="btn btn-sm" data-t="back">&larr; Back</button>' : '') +
        '<button type="button" class="btn btn-sm btn-outline" data-t="skip">Skip tour</button>' +
        (idx < total - 1 ? '<button type="button" class="btn btn-sm" data-t="next">Next &rarr;</button>' : '<button type="button" class="btn btn-sm" data-t="done">Done</button>') +
      '</div>';
    document.body.appendChild(card);
    var placeCard = function(){
      var r = target ? target.getBoundingClientRect() : null;
      var w = card.offsetWidth;
      if(r){
        var x = Math.min(Math.max(12, r.left + r.width/2 - w/2), window.innerWidth - w - 12);
        var y = r.top - card.offsetHeight - 14;
        if(y < 12) y = r.bottom + 14;
        card.style.left = x + 'px';
        card.style.top = y + 'px';
      } else {
        card.style.left = (window.innerWidth/2 - w/2) + 'px';
        card.style.top = '24px';
      }
    };
    placeCard();
    onViewport(placeCard);

    card.addEventListener('click', function(e){
      var b = e.target.closest('[data-t]');
      if(!b) return;
      var t = b.getAttribute('data-t');
      if(t === 'skip' || t === 'done'){ markSeen(); finish(); }
      else if(t === 'next'){ idx++; goToStep(idx); }
      else if(t === 'back'){ idx--; goToStep(idx); }
    });
  }

  function goToStep(i){
    idx = i;
    setActive(i);
    if(idx < 0 || idx >= list.length){ finish(); return; }
    var u = resolveUrl(list[idx]);
    if(u && !samePage(u)){
      location.href = u;
      return;
    }
    setTimeout(show, 400);
  }

  function finish(){
    cleanup();
    clearActive();
  }

  function markSeen(){ try{ localStorage.setItem(SEEN, '1'); }catch(e){} }
  function clearSeen(){ try{ localStorage.removeItem(SEEN); }catch(e){} }

  function start(){
    if(location.search.indexOf('se=') !== -1) return;
    if(!document.body || document.body.dataset.user !== '1') return;
    list = visible();
    if(list.length === 0) return;
    clearSeen();
    goToStep(0);
  }

  // Resume an in-progress tour after the cross-page navigation.
  function resume(){
    if(location.search.indexOf('se=') !== -1) return;
    if(!document.body || document.body.dataset.user !== '1') return;
    var active = false;
    try{ active = sessionStorage.getItem(ACTIVE) === '1'; }catch(e){}
    if(!active) return;
    list = visible();
    if(list.length === 0){ finish(); return; }
    var i = 0;
    try{ i = parseInt(sessionStorage.getItem(STEP) || '0', 10) || 0; }catch(e){}
    if(i < 0 || i >= list.length) i = 0;
    goToStep(i);
  }

  function esc(s){ return String(s==null?'':s).replace(/[&<>"]/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); }

  var seen = false;
  try{ seen = localStorage.getItem(SEEN) === '1'; }catch(e){}
  if(!seen){
    var resuming = false;
    try{ resuming = sessionStorage.getItem(ACTIVE) === '1'; }catch(e){}
    var kickoff = resuming ? resume : start;
    if(document.readyState === 'loading'){
      document.addEventListener('DOMContentLoaded', kickoff);
    } else {
      kickoff();
    }
  }

  window.GalleryTour = { start: start, markSeen: markSeen, clearSeen: clearSeen };
})();