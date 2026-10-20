/* The gallery grid: lazy-image skeletons, the "Load more" pager and the
   video resume. (The lightbox used to live here; it was replaced by the
   in-page image viewer with the comments - commit ded33e2 - and the dead
   code has been removed.) */
(function(){
  document.addEventListener('DOMContentLoaded',function(){
    document.querySelectorAll('video[data-video-id]').forEach(function(video){initVideoResume(video)});

    var gallery=document.getElementById('gallery'), progress=document.getElementById('gallery-progress');
    if(gallery){bindSkeletons(gallery);bindGalleryPaging(gallery,progress);}
  });

  // Apply lazy-image skeleton classes inside a scope (re-run after AJAX
  // load-more appends new items).
  function bindSkeletons(scope){
    (scope||document).querySelectorAll('img[loading="lazy"]').forEach(function(img){
      if(img.__skeletonBound)return;
      img.__skeletonBound=true;
      img.classList.add('loading','is-loading','media-skeleton');
      img.addEventListener('load',function(){img.classList.remove('loading','is-loading');img.classList.add('is-loaded')},{once:true});
      img.addEventListener('error',function(){img.classList.remove('loading','is-loading');img.classList.add('is-loaded')},{once:true});
      if(img.complete)img.classList.remove('loading','is-loading');
    });
  }

  // Scroll-position restore/save for the gallery grid plus the "Load more"
  // pagination. Uses a single galleryPosition key and delegated clicks so
  // items added via AJAX are covered automatically.
  function bindGalleryPaging(gallery,progress){
    var key='gallery-position-'+location.pathname;
    function indexEls(){return Array.prototype.slice.call(gallery.querySelectorAll('[data-gallery-index]'));}
    function loadedCount(){return indexEls().length;}

    // Restore scroll to the previously-viewed item once the page settles.
    try{
      var saved=JSON.parse(localStorage.getItem(key)||'null');
      if(saved){
        if(saved.index!=null){
          var els=indexEls();
          if(els[saved.index]&&els[saved.index].scrollIntoView){
            var target=els[saved.index];
            window.setTimeout(function(){target.scrollIntoView({block:'start'});},0);
          }else if(typeof saved.y==='number'){window.scrollTo(0,saved.y);}
        }else if(typeof saved.y==='number'){window.scrollTo(0,saved.y);}
      }
    }catch(err){}

    // Save position when opening an item (delegated -> covers load-more items).
    gallery.addEventListener('click',function(e){
      var link=e.target&&e.target.closest?e.target.closest('a[data-gallery-index]'):null;
      if(!link)return;
      var index=parseInt(link.getAttribute('data-gallery-index')||'',10);
      if(!isFinite(index))return;
      try{localStorage.setItem(key,JSON.stringify({index:index,y:window.scrollY}))}catch(err){}
    });

    var total=parseInt(gallery.getAttribute('data-total')||'0',10);
    var btn=document.getElementById('load-more-btn');
    var state=document.getElementById('load-more-state');
    var loaded=loadedCount();
    if(progress)progress.textContent='Showing '+loaded+' of '+total+' items';
    if(!btn||total<=loaded)return;

    var loading=false;
    btn.addEventListener('click',function(){
      if(loading)return;
      var offset=loadedCount();
      if(offset>=total)return;
      loading=true;
      if(state)state.textContent='Loading…';
      btn.disabled=true;
      fetch(location.pathname+'/photos?offset='+offset,{headers:{'X-Requested-With':'XMLHttpRequest'}})
        .then(function(r){return r.text()})
        .then(function(html){
          if(html){
            var wrap=document.createElement('div');
            wrap.innerHTML=html;
            while(wrap.firstChild){gallery.appendChild(wrap.firstChild);}
            bindSkeletons(gallery);
          }
          if(progress)progress.textContent='Showing '+loadedCount()+' of '+total+' items';
          if(btn)btn.hidden=loadedCount()>=total;
          if(state)state.textContent='';
        })
        .catch(function(){if(state)state.textContent='Could not load more. Please try again.';})
        .then(function(){loading=false;btn.disabled=false;});
    });
  }

  function initVideoResume(video){
    if(!video || video.__resumeBound) return;
    video.__resumeBound = true;
    var key='gallery-video-position-'+video.getAttribute('data-video-id'), saved=0,
        // The Resume strip is a SIBLING of .gallery-player (it sits inside the
        // figure, after the player wrapper), not a child of the video's parent -
        // looking only at video.parentNode never found it, so saved positions
        // were written but never offered or applied.
        scope=document.getElementById('video-player-wrap')||video.closest('figure')||video.parentNode,
        resume=scope.querySelector('.video-resume');
    try{saved=parseFloat(localStorage.getItem(key))||0;}catch(e){}
    /* The server-side position (this member watched it on another device)
       wins when it is further along. */
    var serverSaved=parseFloat(video.getAttribute('data-saved-position'))||0;
    if(isFinite(serverSaved)&&serverSaved>saved) saved=serverSaved;
    if(saved>0&&isFinite(saved)&&resume){
      resume.hidden=false;
      resume.querySelector('.video-resume-time').textContent=formatTime(saved);
      resume.querySelector('button').onclick=function(){video.currentTime=saved;resume.hidden=true;video.play().catch(function(){});};
      /* Apply the saved position ONCE, and never over a seek the viewer has
         already made. Binding this to loadedmetadata meant it could fire
         after the viewer had scrubbed to a chapter (or dragged the seek bar)
         and silently yank them back to the old position. */
      var applyResume=function(){
        if(video.__userSeeked) return;
        var target=Math.min(saved,Math.max(0,(video.duration||0)-0.5));
        if(target>0) video.currentTime=target;
      };
      if(video.readyState>=1){ applyResume(); }
      else { video.addEventListener('loadedmetadata',applyResume,{once:true}); }
    }
    /* Persisting on every timeupdate wrote to localStorage up to 4x a second
       for the whole runtime; on a long video that is thousands of sync writes
       on the main thread. Store at most every 5s, and always on the events that
       actually mark where the viewer stopped. */
    var pending=0, lastWrite=0;
    /* The same position goes to the server, so it follows the member to
       another device and the dashboard can show what they were watching. */
    var csrf=(document.querySelector('meta[name="csrf-token"]')||{}).content||'',
        base=(document.body&&document.body.getAttribute('data-base'))||'',
        photoId=parseInt(video.getAttribute('data-video-id')||'',10)||0,
        serverPending=false;
    function postProgress(finished){
      if(!photoId||!csrf) return;
      if(serverPending) return;
      serverPending=true;
      var fd=new FormData();
      fd.append('_token',csrf);
      fd.append('photo_id',String(photoId));
      fd.append('position',String(video.currentTime||0));
      fd.append('duration',String(video.duration||0));
      if(finished) fd.append('finished','1');
      fetch(base+'/media/progress',{
        method:'POST',
        headers:{'X-Requested-With':'XMLHttpRequest'},
        body:fd
      }).catch(function(){}).then(function(){serverPending=false;});
    }
    function persist(finished){
      lastWrite=Date.now();
      if(finished){
        try{localStorage.removeItem(key);}catch(e){}
        postProgress(true);
        return;
      }
      if(video.currentTime>1&&!video.ended){
        try{localStorage.setItem(key,video.currentTime);}catch(e){}
        postProgress(false);
      }
    }
    function schedule(){
      if(pending) return;
      pending=setTimeout(function(){pending=0;persist();},5000);
    }
    video.addEventListener('timeupdate',function(){ if(video.currentTime>1&&!video.ended){ schedule(); } });
    video.addEventListener('pause',function(){ if(pending){clearTimeout(pending);pending=0;} persist(false); });
    video.addEventListener('ended',function(){persist(true);if(resume)resume.hidden=true;});
    /* Closing the tab mid-video (mobile especially) fires pagehide, not pause:
       without this the last 5 seconds are lost every time. */
    window.addEventListener('pagehide', function(){ if(video.isConnected && video.currentTime>1 && !video.ended){ persist(false); } });
  }
  window.GalleryResume = initVideoResume;
  function formatTime(seconds){var minutes=Math.floor(seconds/60),secs=Math.floor(seconds%60);return minutes+':'+(secs<10?'0':'')+secs;}
  })();

/* First-time member orientation */
(function(){
  document.addEventListener('DOMContentLoaded',function(){
    var prompt=document.getElementById('member-onboarding');
    if(!prompt)return;
    var key='galleryMemberOnboardingDismissed';
    try{if(localStorage.getItem(key)==='1')return;}catch(e){}
    prompt.hidden=false;
    var dismiss=prompt.querySelector('[data-dismiss-onboarding]');
    if(dismiss)dismiss.addEventListener('click',function(){
      prompt.hidden=true;
      try{localStorage.setItem(key,'1');}catch(e){}
    });
    if(dismiss)dismiss.focus();
  });
})();

/* Modal focus management.
 *
 * The age gate and the member-onboarding card are real dialogs: they take
 * focus, keep it (Tab cycles inside instead of wandering into the page
 * behind them), close on Escape where that makes sense, and put the rest of
 * the document out of reach with `inert` so a screen reader does not read the
 * page underneath.
 */
(function(){
  function focusables(root){
    return Array.prototype.slice.call(
      root.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]), select, textarea, [tabindex]:not([tabindex="-1"])')
    ).filter(function(el){ return el.offsetParent !== null; });
  }

  function trap(container, e){
    if(e.key!=='Tab') return;
    var items=focusables(container);
    if(!items.length){ e.preventDefault(); return; }
    var first=items[0], last=items[items.length-1];
    if(e.shiftKey && document.activeElement===first){ e.preventDefault(); last.focus(); }
    else if(!e.shiftKey && document.activeElement===last){ e.preventDefault(); first.focus(); }
  }

  /* Everything outside an open modal is inert: not focusable, not read out. */
  function setBackgroundInert(on, keep){
    var children=Array.prototype.slice.call(document.body.children);
    children.forEach(function(el){
      if(el===keep) return;
      if(el.tagName==='SCRIPT'||el.tagName==='STYLE') return;
      if('inert' in HTMLElement.prototype){ el.inert=on; }
      else if(on){ el.setAttribute('aria-hidden','true'); }
      else { el.removeAttribute('aria-hidden'); }
    });
  }

  function initAgeGate(){
    var gate=document.querySelector('.age-gate');
    if(!gate) return;
    setBackgroundInert(true, gate);
    var first=focusables(gate)[0];
    if(first) first.focus();
    gate.addEventListener('keydown', function(e){ trap(gate, e); });
  }

  function initOnboarding(){
    var prompt=document.getElementById('member-onboarding');
    if(!prompt)return;
    // The age gate and the onboarding never appear together (one is for
    // guests, the other for members), but if they ever did, the second one
    // would make the first inert.
    if(document.querySelector('.age-gate')) return;
    var key='galleryMemberOnboardingDismissed';
    try{if(localStorage.getItem(key)==='1')return;}catch(e){}
    prompt.hidden=false;
    setBackgroundInert(true, prompt);
    var dismiss=prompt.querySelector('[data-dismiss-onboarding]');
    if(dismiss) dismiss.focus();
    prompt.addEventListener('keydown', function(e){
      if(e.key==='Escape'&&dismiss){ dismiss.click(); return; }
      trap(prompt, e);
    });
    if(dismiss) dismiss.addEventListener('click', function(){
      prompt.hidden=true;
      setBackgroundInert(false, null);
      try{localStorage.setItem(key,'1');}catch(e){}
    });
  }

  function init(){
    initAgeGate();
    initOnboarding();
  }
  if(document.readyState!=='loading'){ init(); } else { document.addEventListener('DOMContentLoaded', init); }
})();

/* Gallery card expand/collapse details */
(function(){
  document.addEventListener('DOMContentLoaded',function(){
    document.querySelectorAll('.card-expand-btn').forEach(function(btn){
      btn.addEventListener('click',function(e){
        e.preventDefault();
        var targetId=btn.getAttribute('data-target');
        var details=targetId?document.getElementById(targetId):btn.previousElementSibling;
        if(!details||!details.classList.contains('card-details'))return;
        var hidden=details.hidden;
        details.hidden=!hidden;
        btn.textContent=hidden?'Show less':'Show more';
        btn.setAttribute('aria-expanded',hidden?'true':'false');
      });
    });
  });
})();

/* Gallery card favorite toggle (AJAX) */
(function(){
  document.addEventListener('DOMContentLoaded',function(){
    document.querySelectorAll('.favorite-toggle').forEach(function(btn){
      btn.addEventListener('click',function(e){
        e.preventDefault();
        e.stopPropagation();
        var galleryId=btn.getAttribute('data-gallery-id');
        var csrf=btn.getAttribute('data-csrf');
        if(!galleryId||!csrf)return;
        btn.disabled=true;
        var fd=new FormData();
        fd.append('_token',csrf);
        var base=(document.body&&document.body.getAttribute('data-base'))||'';
        fetch(base+'/favorites/galleries/'+galleryId+'/toggle',{
          method:'POST',
          headers:{'X-Requested-With':'XMLHttpRequest'},
          body:fd
        }).then(function(r){return r.json()}).then(function(data){
          if(data.ok){
            if(data.favorited){
              btn.classList.add('is-favorite');
              btn.innerHTML='&#9733; Unfavorite';
            }else{
              btn.classList.remove('is-favorite');
              btn.innerHTML='&#9734; Favorite';
            }
          }
          btn.disabled=false;
        }).catch(function(){btn.disabled=false});
      });
    });
  });
})();

/* Category favorite star (AJAX) — Silver+ members can favourite a category
   straight from the gallery listing heading. */
(function(){
  document.addEventListener('DOMContentLoaded',function(){
    document.querySelectorAll('.cat-fav-toggle').forEach(function(btn){
      btn.addEventListener('click',function(e){
        e.preventDefault();
        e.stopPropagation();
        var catId=btn.getAttribute('data-cat-id');
        var csrf=btn.getAttribute('data-csrf');
        if(!catId||!csrf)return;
        btn.disabled=true;
        var fd=new FormData();
        fd.append('_token',csrf);
        var base=(document.body&&document.body.getAttribute('data-base'))||'';
        fetch(base+'/favorites/categories/'+catId+'/toggle',{
          method:'POST',
          headers:{'X-Requested-With':'XMLHttpRequest'},
          body:fd
        }).then(function(r){return r.json()}).then(function(data){
          if(data.ok){
            btn.classList.toggle('selected', !!data.favorited);
          }
          btn.disabled=false;
        }).catch(function(){btn.disabled=false});
      });
    });
  });
})();

/* Gallery display options (settings page + gallery application) */
(function(){
  var STORE_KEY='galleryDisplayPrefs';
  var defaults={view:'grid',size:'md',masonry:false,perpage:24,sort:''};

  function load(){
    try{return Object.assign({},defaults,JSON.parse(localStorage.getItem(STORE_KEY)||'{}'))}catch(e){return Object.assign({},defaults)}
  }
  function save(prefs){try{localStorage.setItem(STORE_KEY,JSON.stringify(prefs))}catch(e){}}

  function apply(prefs){
    document.documentElement.classList.remove('g-view-grid','g-view-list','g-view-compact','g-size-sm','g-size-md','g-size-lg','g-masonry');
    if(prefs.view==='list')document.documentElement.classList.add('g-view-list');
    else if(prefs.view==='compact')document.documentElement.classList.add('g-view-compact');
    else document.documentElement.classList.add('g-view-grid');
    document.documentElement.classList.add('g-size-'+prefs.size);
    if(prefs.masonry)document.documentElement.classList.add('g-masonry');
  }

  function bindSettings(){
    var bar=document.getElementById('gDisplayBar');
    if(!bar)return;
    bar.querySelectorAll('select[data-gd]').forEach(function(sel){
      var key=sel.getAttribute('data-gd');
      var prefs=load();
      if(key==='masonry')sel.value=prefs.masonry?'1':'0';
      else if(key==='sort')sel.value=prefs.sort||'';
      else if(prefs[key]!==undefined)sel.value=prefs[key];
      sel.addEventListener('change',function(){
        var p=load();
        if(key==='masonry')p.masonry=sel.value==='1';
        else if(key==='sort')p.sort=sel.value;
        else p[key]=sel.value;
        save(p);
      });
    });
  }

  document.addEventListener('DOMContentLoaded',function(){
    var prefs=load();
    // Settings page: bind selects
    bindSettings();
    // Gallery pages: apply prefs to document
    var grid=document.querySelector('.grid');
    if(grid)apply(prefs);
  });
})();

/* Recent-pictures/videos strip on the login/signup pages: keep it to a
   single row, hiding any card that would be clipped. */
(function(){
  function fitRecentStrip(){
    document.querySelectorAll('.recent-strip').forEach(function(strip){
      var cards=Array.prototype.slice.call(strip.querySelectorAll('.recent-card'));
      if(!cards.length)return;
      var cardWidth=cards[0].offsetWidth||220;
      var gap=parseFloat(getComputedStyle(strip).gap)||0;
      var available=strip.clientWidth;
      var count=Math.max(0,Math.floor((available+gap)/(cardWidth+gap)));
      strip.style.justifyContent=cards.length<4?'space-evenly':'space-between';
      cards.forEach(function(card,i){card.style.display=i<count?'':'none';});
    });
  }
  document.addEventListener('DOMContentLoaded',function(){
    if(!document.querySelector('.recent-strip'))return;
    fitRecentStrip();
    window.addEventListener('resize',fitRecentStrip);
    window.addEventListener('load',fitRecentStrip);
  });
})();

/* Flash auto-dismiss (pauses on hover/focus so AT users and slow readers
   can read the message before it fades) */
(function(){
  function scheduleDismiss(el){
    var timer=setTimeout(function(){
      el.style.transition='opacity .4s';
      el.style.opacity='0';
      setTimeout(function(){el.remove()},400);
    },5000);
    el._flashTimer=timer;
  }
  document.addEventListener('DOMContentLoaded',function(){
    document.querySelectorAll('.flash').forEach(function(el){
      scheduleDismiss(el);
      el.addEventListener('mouseenter',function(){clearTimeout(el._flashTimer);el.style.transition='';el.style.opacity='';});
      el.addEventListener('mouseleave',function(){scheduleDismiss(el);});
      el.addEventListener('focusin',function(){clearTimeout(el._flashTimer);el.style.transition='';el.style.opacity='';});
      el.addEventListener('focusout',function(){scheduleDismiss(el);});
    });
  });
})();

/* Anti-image-protection: disable right-click, drag, and save shortcuts */
(function(){
  document.addEventListener('contextmenu',function(e){
    if(e.target.tagName==='IMG'||e.target.closest('img')){
      e.preventDefault();
      return false;
    }
  });
  document.addEventListener('dragstart',function(e){
    if(e.target.tagName==='IMG'||e.target.closest('img')){
      e.preventDefault();
      return false;
    }
  });
  document.addEventListener('keydown',function(e){
    if((e.ctrlKey||e.metaKey)&&(e.key==='s'||e.key==='S')){
      var a=document.activeElement;
      if(a&&(a.tagName==='IMG'||a.closest('img'))){
        e.preventDefault();
        return false;
      }
    }
  });
})();

/* Custom video player: themed control bar for gallery MP4s and the live HLS
   stream. Binds to [data-player] wrappers; builds play/pause, seek (with a
   buffered-range fill and chapter ticks), time, speed, volume, quality (a VOD
   rendition menu off the server-rendered ladder, or HLS levels for live),
   picture-in-picture and fullscreen controls, plus a buffering/error overlay
   with a Retry button. Falls back to the native controls if this script fails
   to run.

   Player preferences (volume, mute, speed, rendition, resume position) persist
   in localStorage, namespaced by the signed-in user id so a shared browser
   does not leak one account's settings into another's. */
(function(){
  var SPEEDS = [0.5, 0.75, 1, 1.25, 1.5, 2];
  var liveHls = null;
  function fmt(s){ s = Math.max(0, Math.floor(s)); var m = Math.floor(s/60); var x = Math.floor(s%60); return m + ':' + (x<10?'0':'')+x; }
  function fmtHours(s){ s = Math.max(0, Math.floor(s)); var h = Math.floor(s/3600), m = Math.floor((s%3600)/60), x = s%60; return (h ? h + ':' + (m<10?'0':'') + m : m) + ':' + (x<10?'0':'')+x; }

  function store(key, value){
    try{
      var ns = (document.body && document.body.getAttribute('data-uid')) || '0';
      if(value === null || value === undefined){ localStorage.removeItem('vp' + ns + '.' + key); return; }
      localStorage.setItem('vp' + ns + '.' + key, value);
    }catch(e){}
  }
  function load(key){
    try{
      var ns = (document.body && document.body.getAttribute('data-uid')) || '0';
      var v = localStorage.getItem('vp' + ns + '.' + key);
      return v === null ? null : v;
    }catch(e){ return null; }
  }

  /* Pick the rendition the browser should start on: an explicit previous
     choice, else the lowest rung when the connection looks metered or slow
     (Network Information API), else the mid rung the server chose as src. */
  function chooseRendition(sources, current){
    var keys = Object.keys(sources);
    if(!keys.length) return current;
    var pref = load('quality');
    if(pref && sources[pref]) return pref;
    var conn = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
    if(conn){
      if(conn.saveData) return keys[0];
      var eff = conn.effectiveType || '';
      if(eff === 'slow-2g' || eff === '2g' || eff === '3g') return keys[0];
    }
    if(window.matchMedia && window.matchMedia('(prefers-reduced-data: reduce)').matches) return keys[0];
    return current;
  }

  /* --- sizing -------------------------------------------------------------
     The player's box must be exactly the video's shape, and as large as
     possible inside the visible area. CSS alone cannot do the second half:
     aspect-ratio with a max-height clamps the height but leaves the width
     alone, which breaks the ratio (measured in Chrome - a 9:16 box stayed
     900x710 instead of narrowing to 399x710). So the box gets an explicit
     width and height computed here, and the picture inside is contained in
     it: no black bars, in any orientation, and never past the viewport. */
  function aspectFor(el, media){
    if(media){
      if(media.videoWidth && media.videoHeight) return media.videoWidth / media.videoHeight;
      if(media.naturalWidth && media.naturalHeight) return media.naturalWidth / media.naturalHeight;
    }
    // The server's estimate, used until the browser has read the metadata.
    var attr = parseFloat(el.getAttribute('data-aspect'));
    return attr > 0 ? attr : 0;
  }

  function fitBox(el, media){
    if(!el || !el.isConnected) return;
    if(el.hasAttribute('data-fullscreen')) return;  // the live player fits itself
    var ar = aspectFor(el, media);
    if(!(ar > 0)) return;

    // The docked mini player has its own size from the stylesheet.
    if(el.classList.contains('is-mini')){
      el.style.width = '';
      el.style.height = '';
      el.style.maxHeight = '';
      return;
    }

    var deg = el.__vpRot || 0;
    var boxAr = (deg === 90 || deg === 270) ? (1 / ar) : ar;

    // Measure the column the player sits in. The box's own size is cleared
    // first so the measurement is not skewed by the previous fit.
    var prevW = el.style.width, prevH = el.style.height;
    el.style.width = '';
    el.style.height = '';
    var parent = el.parentElement;
    var column = parent ? parent.getBoundingClientRect().width : 0;
    el.style.width = prevW;
    el.style.height = prevH;
    if(!(column > 40)) column = window.innerWidth;

    // How much of the viewport is actually below the player's own top edge.
    var rect = el.getBoundingClientRect();
    var top = Math.max(0, rect.top);
    var chrome = 110;                       // nav + caption + progress line
    var cap = window.innerHeight - chrome;  // the most the video may ever be
    var avail = window.innerHeight - top - chrome;
    if(avail < 120){
      // The player sits below the fold (a phone held sideways, where the
      // page's own header and nav take most of the height). Size the video
      // for the whole viewport instead: the viewer scrolls it into view and
      // then sees it as large as the screen allows.
      avail = cap;
    }
    if(avail > cap) avail = cap;
    if(avail < 120) avail = 120;

    var w = column, h = w / boxAr;
    if(h > avail){ h = avail; w = h * boxAr; }
    if(w > column){ w = column; h = w / boxAr; }

    el.style.width = Math.round(w) + 'px';
    el.style.height = Math.round(h) + 'px';
    // The stylesheet's max-height is a safety net for the first paint (before
    // this script runs). Now that the size is computed, it would only fight
    // the fit: a clamped height with an unchanged width breaks the ratio and
    // brings the letterbox bars straight back.
    el.style.maxHeight = 'none';

    // A turned picture is laid out in a box of the opposite ratio, so the
    // element itself is sized to the box's other axis and then turned.
    if(deg === 90 || deg === 270){
      el.style.setProperty('--vp-rot-w', Math.round(h) + 'px');
      el.style.setProperty('--vp-rot-h', Math.round(w) + 'px');
    } else if(deg === 180){
      el.style.setProperty('--vp-rot-w', Math.round(w) + 'px');
      el.style.setProperty('--vp-rot-h', Math.round(h) + 'px');
    }
  }

  /* Wire the box to the viewport: a resize, a turn of the phone and a change
     of the surrounding column all change how much room the video gets. */
  function attachFit(el, media){
    if(el.__fitBound) return;
    el.__fitBound = true;
    var timer = null;
    function schedule(){
      if(timer) clearTimeout(timer);
      timer = setTimeout(function(){ fitBox(el, media); }, 60);
    }
    el.__scheduleFit = schedule;
    window.addEventListener('resize', schedule);
    window.addEventListener('orientationchange', schedule);
    if(window.ResizeObserver && el.parentElement){
      try{ new ResizeObserver(schedule).observe(el.parentElement); }catch(e){}
    }
    schedule();
  }

  function parseSources(video){
    try{ return JSON.parse(video.getAttribute('data-sources') || '{}') || {}; }catch(e){ return {}; }
  }
  function parseChapters(video){
    try{ return JSON.parse(video.getAttribute('data-chapters') || '[]') || []; }catch(e){ return []; }
  }

  function init(el){
    var video = el.querySelector('video');
    if(!video || video.__gplayer) return;
    video.__gplayer = true;

    var isLive = el.hasAttribute('data-live');
    var fsTarget = el.getAttribute('data-fullscreen');
    var sources = parseSources(video);
    var chapters = parseChapters(video);
    var bar = document.createElement('div');
    bar.className = 'player-bar';
    bar.innerHTML =
      (isLive ? '<span class="player-live-dot" aria-hidden="true"></span>' : '') +
      '<button type="button" class="player-btn" data-act="play" aria-label="Play or pause">&#9654;</button>' +
      '<div class="player-seek">' +
        '<div class="player-seek-track">' +
          '<div class="player-buffered" data-buffered></div>' +
          (chapters.length ? '<div class="player-ticks">' + chapters.map(function(c){
            return '<span class="player-tick" style="left:' + Math.min(100, Math.max(0, (c.start / (video.duration || 0)) * 100)) + '%"></span>';
          }).join('') + '</div>' : '') +
        '</div>' +
        '<input type="range" min="0" max="1000" value="0" step="1" aria-label="Seek">' +
      '</div>' +
      '<span class="player-time">0:00 / 0:00</span>' +
      '<button type="button" class="player-btn player-speed" data-act="speed" title="Playback speed">1&#215;</button>' +
      (isLive || Object.keys(sources).length > 1
        ? '<button type="button" class="player-btn" data-act="quality" title="Quality" aria-haspopup="true" aria-expanded="false">&#9881;</button>' : '') +
      '<button type="button" class="player-btn" data-act="vol" aria-label="Mute or unmute">&#128266;</button>' +
      '<label class="player-keep" title="Keep the picture-in-picture window open after the video ends (allows replay)"><input type="checkbox" data-act="keep" checked>Keep open</label>' +
      '<button type="button" class="player-btn" data-act="pip" title="Picture in picture">&#9210;</button>' +
      '<button type="button" class="player-btn" data-act="fs" title="Full screen">&#9974;</button>' +
      '<button type="button" class="player-btn" data-act="more" title="More options" aria-haspopup="true" aria-expanded="false">&#8942;</button>';
    el.appendChild(bar);

    /* The overflow menu holds the controls that do not fit in the bar on a
       phone: turn the picture, fill the screen instead of letterboxing it,
       and theater mode (the video takes the page, the side panels go away). */
    var more = document.createElement('div');
    more.className = 'player-more';
    more.hidden = true;
    more.innerHTML =
      '<button type="button" data-more="rotate" aria-pressed="false">Rotate picture</button>' +
      '<button type="button" data-more="fit" aria-pressed="false">Fill screen</button>' +
      '<button type="button" data-more="theater" aria-pressed="false">Theater mode</button>';
    el.appendChild(more);
    var moreBtn = bar.querySelector('[data-act="more"]');
    if(moreBtn){
      moreBtn.addEventListener('click', function(e){
        e.stopPropagation();
        more.hidden = !more.hidden;
        moreBtn.setAttribute('aria-expanded', more.hidden ? 'false' : 'true');
        if(!more.hidden){
          var qm = el.querySelector('.player-quality');
          if(qm) qm.remove();
        }
      });
    }
    more.addEventListener('click', function(e){
      var b = e.target && e.target.closest ? e.target.closest('button[data-more]') : null;
      if(!b) return;
      var what = b.getAttribute('data-more');
      if(what === 'rotate'){
        var deg = ((el.__vpRot || 0) + 90) % 360;
        el.__vpRot = deg;
        el.classList.toggle('is-rotated', deg !== 0);
        if(deg){ el.style.setProperty('--vp-rot-angle', deg + 'deg'); }
        else { el.style.removeProperty('--vp-rot-angle'); }
        b.setAttribute('aria-pressed', deg ? 'true' : 'false');
        b.textContent = deg ? 'Rotate picture (' + deg + '°)' : 'Rotate picture';
        fitBox(el, video);
      } else if(what === 'fit'){
        var fill = el.classList.toggle('is-fill');
        b.setAttribute('aria-pressed', fill ? 'true' : 'false');
        b.textContent = fill ? 'Fit picture' : 'Fill screen';
      } else if(what === 'theater'){
        var on = document.body.classList.toggle('player-theater');
        b.setAttribute('aria-pressed', on ? 'true' : 'false');
        fitBox(el, video);
      }
      more.hidden = true;
      if(moreBtn) moreBtn.setAttribute('aria-expanded', 'false');
    });

    /* Buffering / error overlay with a working Retry: without it a failed or
       stalled Range request left a black rectangle with no way forward. */
    var overlay = el.querySelector('[data-overlay]');
    var spinner = el.querySelector('[data-spinner]');
    var overlayMsg = el.querySelector('[data-overlay-msg]');
    var retryBtn = el.querySelector('[data-retry]');
    function showOverlay(msg, retry){
      if(!overlay) return;
      overlay.hidden = false;
      if(overlayMsg) overlayMsg.textContent = msg || '';
      if(spinner) spinner.hidden = !msg;
      if(retryBtn) retryBtn.hidden = !retry;
    }
    function hideOverlay(){ if(overlay) overlay.hidden = true; }
    function retry(){
      var t = video.currentTime;
      hideOverlay();
      video.load();
      if(t > 0 && isFinite(t)){ video.currentTime = t; }
      video.play().catch(function(){});
    }
    if(retryBtn) retryBtn.addEventListener('click', retry);

    /* --- rendition (quality) switching --------------------------------- */
    var currentRendition = null;
    var switching = false;
    function applyRendition(key, persist){
      if(switching || !sources[key] || key === currentRendition) return;
      var wasPlaying = !video.paused && !video.ended;
      var t = video.currentTime;
      switching = true;
      currentRendition = key;
      if(persist !== false) store('quality', key);
      video.src = sources[key].url;
      video.load();
      var restore = function(){
        if(t > 0 && isFinite(t) && isFinite(video.duration)) video.currentTime = Math.min(t, Math.max(0, video.duration - 0.25));
        if(wasPlaying) video.play().catch(function(){});
        switching = false;
      };
      video.addEventListener('loadedmetadata', restore, {once:true});
      video.addEventListener('error', function(){ switching = false; }, {once:true});
    }
    function toggleLevels(el2){
      el2 = el2 || el;
      var menu = el2.querySelector('.player-quality');
      var qb = el2.querySelector('[data-act="quality"]');
      if(menu){ menu.remove(); if(qb) qb.setAttribute('aria-expanded','false'); return; }
      var keys = Object.keys(sources);
      if(!isLive && !keys.length && !liveHls) return;
      menu = document.createElement('div');
      menu.className = 'player-quality';
      var items = isLive
        ? '<button type="button" data-l="auto">Auto</button>' +
          (liveHls && liveHls.levels || []).map(function(lv, i){ return '<button type="button" data-l="' + i + '">' + (lv.height ? lv.height + 'p' : 'Auto') + '</button>'; }).join('')
        : keys.map(function(k){
            var s = sources[k];
            var extra = '';
            // The real pixel size, so a "720p" rung that is really 576x1280
            // says so instead of pretending to be wider than it is.
            if(s.w && s.h) extra += ' · ' + s.w + '×' + s.h;
            if(s.bytes) extra += ' · ' + Math.round(s.bytes / 1048576) + ' MB';
            return '<button type="button" data-q="' + k + '"' + (k === currentRendition ? ' aria-current="true"' : '') + '>' + s.label + extra + '</button>';
          }).join('');
      menu.innerHTML = items;
      el2.appendChild(menu);
      if(qb) qb.setAttribute('aria-expanded','true');
      menu.addEventListener('click', function(e){
        var b = e.target && e.target.closest ? e.target.closest('button') : null;
        if(!b) return;
        if(b.hasAttribute('data-l') && liveHls){
          liveHls.currentLevel = b.getAttribute('data-l') === 'auto' ? -1 : parseInt(b.getAttribute('data-l'), 10);
        } else if(b.hasAttribute('data-q')){
          applyRendition(b.getAttribute('data-q'), true);
        }
        menu.remove();
        if(qb) qb.setAttribute('aria-expanded','false');
      });
    }
    el.addEventListener('click', function(e){
      // Clicking anywhere else in the player closes an open rendition menu.
      if(el.querySelector('.player-quality') && !(e.target && e.target.closest && e.target.closest('.player-quality, [data-act="quality"]'))){
        var m = el.querySelector('.player-quality');
        if(m) m.remove();
        var qb2 = el.querySelector('[data-act="quality"]');
        if(qb2) qb2.setAttribute('aria-expanded','false');
      }
    });

    var seek = bar.querySelector('.player-seek input');
    var track = bar.querySelector('.player-seek-track');
    var bufferedEl = bar.querySelector('[data-buffered]');
    var time = bar.querySelector('.player-time');
    var speedBtn = bar.querySelector('[data-act="speed"]');
    var playBtn = bar.querySelector('[data-act="play"]');
    var volBtn = bar.querySelector('[data-act="vol"]');
    var speedIdx = SPEEDS.indexOf(1);

    video.controls = false;
    /* Chapter ticks sit above the track as percentages, so they have to be
       re-laid-out once the real duration is known (the markup above uses 0). */
    function layoutTicks(){
      if(!track || !chapters.length || !isFinite(video.duration) || video.duration <= 0) return;
      var ticks = track.querySelectorAll('.player-tick');
      for(var i = 0; i < ticks.length; i++){
        var c = chapters[i];
        if(c) ticks[i].style.left = Math.min(100, Math.max(0, (c.start / video.duration) * 100)) + '%';
      }
    }
    /* Rebuild the tick marks from scratch - needed after an AJAX swap, when
       the chapters belong to a different video. */
    function renderTicks(){
      if(!track) return;
      var holder = track.querySelector('.player-ticks');
      if(!holder) return;
      if(!chapters.length){ holder.innerHTML = ''; return; }
      holder.innerHTML = chapters.map(function(c){
        return '<span class="player-tick" style="left:' + Math.min(100, Math.max(0, (c.start / (video.duration || 0)) * 100)) + '%"></span>';
      }).join('');
    }

    function setIcon(){
      if(playBtn) playBtn.innerHTML = video.paused ? '&#9654;' : '&#10074;&#10074;';
      el.classList.toggle('playing', !video.paused);
    }

    /* --- fit the box to the picture and the viewport ---------------------
       The server sends the picture's display ratio (rotation applied), so
       the box is the right shape from the first paint. Once the browser has
       read the real metadata the ratio is confirmed and the box is sized to
       the largest shape that still fits inside the visible area. */
    attachFit(el, video);
    function refreshFit(){
      fitBox(el, video);
    }
    video.addEventListener('loadedmetadata', function(){
      refreshFit();
      el.classList.remove('is-poster');
    });
    video.addEventListener('loadeddata', function(){ el.classList.remove('is-poster'); });
    video.addEventListener('playing', function(){ el.classList.remove('is-poster'); });
    if(video.readyState >= 1){ refreshFit(); }

    /* Fullscreen: the box fills the screen, so the fit is not ours to make
       any more - but a turned picture still has to be sized to the screen
       instead of to the inline page box. */
    document.addEventListener('fullscreenchange', function(){
      if(document.fullscreenElement === el){
        el.style.setProperty('--vp-rot-w', '100%');
        el.style.setProperty('--vp-rot-h', '100%');
      } else {
        refreshFit();
      }
    });
    function updateSeek(){
      if(isLive){ seek.value = 0; time.textContent = 'LIVE'; return; }
      if(!video.duration || isNaN(video.duration)){ seek.value = 0; time.textContent = '0:00 / 0:00'; return; }
      seek.value = Math.round(video.currentTime / video.duration * 1000);
      time.textContent = fmt(video.currentTime) + ' / ' + fmt(video.duration);
      if(bufferedEl && video.buffered && video.buffered.length){
        var end = 0;
        for(var i = 0; i < video.buffered.length; i++){
          if(video.buffered.start(i) <= video.currentTime && video.buffered.end(i) >= video.currentTime){ end = video.buffered.end(i); break; }
        }
        if(!end && video.buffered.length) end = video.buffered.end(video.buffered.length - 1);
        bufferedEl.style.width = Math.min(100, (end / video.duration) * 100) + '%';
      }
    }
    if(playBtn) playBtn.addEventListener('click', function(){
      if(video.paused){ video.play().catch(function(){}); } else { video.pause(); }
    });
    video.addEventListener('play', function(){ setIcon(); hideOverlay(); });
    video.addEventListener('pause', function(){ setIcon(); bar.classList.add('show'); });
    video.addEventListener('timeupdate', updateSeek);
    video.addEventListener('progress', updateSeek);
    video.addEventListener('durationchange', function(){ layoutTicks(); updateSeek(); });
    video.addEventListener('loadedmetadata', updateSeek);
    seek.addEventListener('input', function(){
      if(video.duration && !isLive){ video.__userSeeked = true; video.currentTime = seek.value / 1000 * video.duration; }
    });
    if(speedBtn) speedBtn.addEventListener('click', function(){
      speedIdx = (speedIdx + 1) % SPEEDS.length;
      video.playbackRate = SPEEDS[speedIdx];
      speedBtn.textContent = SPEEDS[speedIdx] + '\u00d7';
      store('speed', String(SPEEDS[speedIdx]));
    });
    if(volBtn) volBtn.addEventListener('click', function(){
      video.muted = !video.muted;
      volBtn.innerHTML = video.muted ? '&#128263;' : '&#128266;';
      volBtn.setAttribute('aria-label', video.muted ? 'Unmute' : 'Mute');
      store('muted', video.muted ? '1' : '0');
    });
    bar.querySelector('[data-act="pip"]').addEventListener('click', function(){
      if(document.pictureInPictureElement === video){ document.exitPictureInPicture().catch(function(){}); }
      else if(typeof video.requestPictureInPicture === 'function'){ video.requestPictureInPicture().catch(function(){}); }
    });
    // "Keep open" (default ON): after the video ends, the picture-in-picture
    // window stays open so it can be replayed; when off it closes on end.
    var keepOpen = true;
    try { keepOpen = localStorage.getItem('galleryPipKeepOpen') !== '0'; } catch (e) {}
    video.__pipKeepOpen = keepOpen;
    var keepCb = bar.querySelector('[data-act="keep"]');
    if (keepCb) {
      keepCb.checked = keepOpen;
      keepCb.addEventListener('change', function(){
        keepOpen = keepCb.checked;
        video.__pipKeepOpen = keepOpen;
        try { localStorage.setItem('galleryPipKeepOpen', keepOpen ? '1' : '0'); } catch (e) {}
      });
    }
    video.addEventListener('ended', function(){
      video.__pipJustEnded = true;
      setTimeout(function(){ video.__pipJustEnded = false; }, 2000);
    });
    bar.querySelector('[data-act="fs"]').addEventListener('click', function(){
      var target = fsTarget ? document.querySelector(fsTarget) : el;
      if(!target) target = el;
      if(document.fullscreenElement){ document.exitFullscreen().catch(function(){}); }
      else if(target.requestFullscreen){ target.requestFullscreen().catch(function(){}); }
      else if(video.webkitEnterFullscreen){ video.webkitEnterFullscreen(); }
    });
    var qualBtn = bar.querySelector('[data-act="quality"]');
    if(qualBtn) qualBtn.addEventListener('click', function(){ toggleLevels(el); });

    /* --- buffering / error state ---------------------------------------- */
    video.addEventListener('waiting', function(){ showOverlay('Buffering…', false); });
    video.addEventListener('stalled', function(){ showOverlay('Buffering…', false); });
    video.addEventListener('seeking', function(){ if(video.readyState < 3) showOverlay('Seeking…', false); });
    video.addEventListener('playing', hideOverlay);
    video.addEventListener('canplay', hideOverlay);
    video.addEventListener('error', function(){
      var err = video.error;
      var msg = 'This video could not be loaded.';
      if(err && err.code === 2) msg = 'This video could not be loaded (network error).';
      else if(err && err.code === 3) msg = 'This video could not be decoded.';
      else if(err && err.code === 4) msg = 'This video format is not supported by your browser.';
      showOverlay(msg, true);
    });

    /* --- restore remembered settings ------------------------------------ */
    var savedSpeed = parseFloat(load('speed'));
    if(isFinite(savedSpeed) && savedSpeed > 0){
      var si = SPEEDS.indexOf(savedSpeed);
      speedIdx = si >= 0 ? si : speedIdx;
      video.playbackRate = SPEEDS[speedIdx];
      if(speedBtn) speedBtn.textContent = SPEEDS[speedIdx] + '\u00d7';
    }
    var savedVol = parseFloat(load('volume'));
    if(isFinite(savedVol) && savedVol >= 0 && savedVol <= 1){
      video.volume = savedVol;
      video.muted = false;
    } else if(!video.muted){
      video.muted = load('muted') === '1';
    }
    if(volBtn){
      volBtn.innerHTML = video.muted ? '&#128263;' : '&#128266;';
      volBtn.setAttribute('aria-label', video.muted ? 'Unmute' : 'Mute');
    }
    video.addEventListener('volumechange', function(){
      store('volume', String(video.volume));
      store('muted', video.muted ? '1' : '0');
    });

    /* --- auto-hide bar: mouse, touch and keyboard ------------------------ */
    var hideTimer = null;
    function scheduleHide(){ if(hideTimer) clearTimeout(hideTimer); if(!video.paused){ hideTimer = setTimeout(function(){ bar.classList.remove('show'); }, 2600); } }
    function reveal(){ bar.classList.add('show'); scheduleHide(); }
    el.addEventListener('mousemove', reveal);
    el.addEventListener('mouseleave', function(){ if(!video.paused) bar.classList.remove('show'); });
    /* Touch: a tap on the picture reveals the bar (and a second tap hides it
       again). Without this the controls were unreachable on phones, where
       there is no mousemove. */
    el.addEventListener('touchstart', reveal, {passive:true});
    el.addEventListener('pointerdown', function(e){
      if(e.pointerType === 'touch') reveal();
    });
    document.addEventListener('keydown', function(e){
      if(!el.isConnected || document.hidden) return;
      var t = e.target;
      if(t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.tagName === 'SELECT' || t.isContentEditable)) return;
      if(document.querySelector('.player-quality')) { return; }
      var handled = true;
      switch(e.key){
        case ' ': case 'k': case 'K':
          if(video.paused){ video.play().catch(function(){}); } else { video.pause(); }
          break;
        case 'j': case 'J': seekBy(-10); break;
        case 'l': case 'L': seekBy(10); break;
        case 'ArrowLeft': seekBy(-5); break;
        case 'ArrowRight': seekBy(5); break;
        case 'ArrowUp': stepVolume(0.1); break;
        case 'ArrowDown': stepVolume(-0.1); break;
        case 'm': case 'M': if(volBtn) volBtn.click(); break;
        case 'f': case 'F': bar.querySelector('[data-act="fs"]').click(); break;
        default:
          if(e.key >= '0' && e.key <= '9'){ jumpTo(e.key / 10); }
          else { handled = false; }
      }
      if(handled){ e.preventDefault(); reveal(); }
    });
    function seekBy(delta){
      if(!isFinite(video.duration) || isLive) return;
      video.__userSeeked = true;
      video.currentTime = Math.min(Math.max(0, video.currentTime + delta), Math.max(0, video.duration - 0.25));
      showOverlay('Seeking…', false);
    }
    function stepVolume(delta){
      video.muted = false;
      video.volume = Math.min(1, Math.max(0, video.volume + delta));
      if(volBtn){ volBtn.innerHTML = '&#128266;'; volBtn.setAttribute('aria-label','Mute'); }
    }
    function jumpTo(fraction){
      if(!isFinite(video.duration) || isLive) return;
      video.currentTime = Math.min(Math.max(0, video.duration - 0.25), video.duration * fraction);
    }

    /* --- chapter list (server-rendered buttons) --------------------------- */
    function bindChapters(){
      el.querySelectorAll('[data-chapter-seek]').forEach(function(btn){
        if(btn.__chapterBound) return;
        btn.__chapterBound = true;
        btn.addEventListener('click', function(){
          var t = parseFloat(btn.getAttribute('data-chapter-seek'));
          if(!isFinite(t)) return;
          video.__userSeeked = true;
          video.currentTime = Math.min(Math.max(0, t), isFinite(video.duration) ? Math.max(0, video.duration - 0.25) : t);
          video.play().catch(function(){});
          reveal();
        });
      });
    }
    bindChapters();

    /* --- keyboard shortcut help ("?") ------------------------------------ */
    var keysPanel = null;
    function toggleKeys(){
      if(keysPanel){ keysPanel.remove(); keysPanel = null; return; }
      keysPanel = document.createElement('div');
      keysPanel.className = 'player-keys';
      keysPanel.setAttribute('role', 'note');
      keysPanel.innerHTML = '<strong>Keyboard</strong>'
        + '<dl>'
        + '<dt>Space / K</dt><dd>Play or pause</dd>'
        + '<dt>J / L</dt><dd>Back or forward 10s</dd>'
        + '<dt>&larr; / &rarr;</dt><dd>Seek 5s</dd>'
        + '<dt>&uarr; / &darr;</dt><dd>Volume</dd>'
        + '<dt>M</dt><dd>Mute</dd>'
        + '<dt>F</dt><dd>Full screen</dd>'
        + '<dt>0-9</dt><dd>Jump to 0-90%</dd>'
        + '<dt>?</dt><dd>This list</dd>'
        + '</dl>';
      el.appendChild(keysPanel);
    }
    document.addEventListener('keydown', function(e){
      if(e.key !== '?' && e.key !== '/') return;
      if(!el.isConnected) return;
      var t = e.target;
      if(t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.isContentEditable)) return;
      e.preventDefault();
      toggleKeys();
    });

    /* --- "next up" countdown ---------------------------------------------
       When a video ends and the queue is going to move on, show what is
       coming and give the viewer a few seconds to cancel it. */
    var nextUp = null;
    function advanceNow(){
      if(window.GalleryMediaNav && window.GalleryMediaNav.advanceNext) window.GalleryMediaNav.advanceNext();
    }
    function hideNextUp(){
      if(!nextUp) return;
      if(nextUp.__timer) clearInterval(nextUp.__timer);
      nextUp.remove();
      nextUp = null;
    }
    function showNextUp(info){
      hideNextUp();
      if(!info) return;
      nextUp = document.createElement('div');
      nextUp.className = 'player-nextup';
      nextUp.setAttribute('role', 'status');
      nextUp.innerHTML =
        '<span class="player-nextup-title"></span>' +
        '<span class="player-nextup-count">5</span>' +
        '<button type="button" class="player-btn" data-nextup="play">Play now</button>' +
        '<button type="button" class="player-btn" data-nextup="cancel">Cancel</button>';
      var titleEl = nextUp.querySelector('.player-nextup-title');
      if(titleEl) titleEl.textContent = info.title || 'Next video';
      var countEl = nextUp.querySelector('.player-nextup-count');
      var left = 5;
      nextUp.__timer = setInterval(function(){
        left--;
        if(countEl) countEl.textContent = String(left);
        if(left <= 0){ hideNextUp(); advanceNow(); }
      }, 1000);
      nextUp.addEventListener('click', function(e){
        var b = e.target && e.target.closest ? e.target.closest('button[data-nextup]') : null;
        if(!b) return;
        var what = b.getAttribute('data-nextup');
        hideNextUp();
        if(what === 'play') advanceNow();
      });
      el.appendChild(nextUp);
      nextUp.classList.add('show');
    }
    video.addEventListener('play', hideNextUp);
    video.addEventListener('seeking', hideNextUp);

    /* --- sticky mini player -----------------------------------------------
       Scrolling the player past the top of the viewport docks it in the
       corner so the video is never lost. A placeholder holds the space the
       player used to occupy, so nothing on the page jumps. */
    var mini = false;
    var placeholder = null;
    function setMini(on){
      if(on === mini) return;
      mini = on;
      if(on){
        if(!placeholder && el.parentNode){
          placeholder = document.createElement('div');
          placeholder.className = 'player-placeholder';
          // The placeholder has to hold exactly the space the player used,
          // or the page jumps when the player is docked.
          var prev = el.getBoundingClientRect();
          placeholder.style.height = Math.round(prev.height) + 'px';
          var ap = el.style.getPropertyValue('--vp-aspect');
          if(ap) placeholder.style.setProperty('--vp-aspect', ap);
          el.parentNode.insertBefore(placeholder, el);
        }
        el.classList.add('is-mini');
      } else {
        el.classList.remove('is-mini');
        if(placeholder && placeholder.parentNode) placeholder.parentNode.removeChild(placeholder);
        placeholder = null;
      }
      refreshFit();
    }
    var scrollPending = false;
    function checkMini(){
      scrollPending = false;
      if(!el.isConnected) return;
      if(mini){
        var ph = placeholder ? placeholder.getBoundingClientRect() : null;
        // The placeholder is back in view: the player goes back in the page.
        if(!ph || ph.bottom > 0) setMini(false);
        return;
      }
      var rect = el.getBoundingClientRect();
      setMini(rect.bottom < 0);
    }
    window.addEventListener('scroll', function(){
      if(scrollPending) return;
      scrollPending = true;
      if(window.requestAnimationFrame) window.requestAnimationFrame(checkMini);
      else checkMini();
    }, {passive:true});

    /* --- prefetch the next item -------------------------------------------
       Warming the next clip's connection makes the switch to it instant. */
    function prefetchNext(){
      if(!window.GalleryQueue || !window.GalleryQueue.next) return;
      var nx;
      try { nx = window.GalleryQueue.next(); } catch(e){ return; }
      if(!nx || nx.action !== 'play' || !nx.item || !nx.item.web) return;
      if(el.__prefetched === nx.item.web) return;
      el.__prefetched = nx.item.web;
      try{
        var link = document.createElement('link');
        link.rel = 'preload';
        link.as = 'video';
        link.href = nx.item.web;
        document.head.appendChild(link);
        setTimeout(function(){ if(link.parentNode) link.parentNode.removeChild(link); }, 30000);
      }catch(e){}
    }
    if(window.GalleryQueue) setTimeout(prefetchNext, 2500);

    /* --- start on the right rendition ----------------------------------- */
    var startRendition = chooseRendition(sources, null);
    if(startRendition){
      // Only switch when the server's src is not the picked rung: the element
      // already has src=sources[key].url in that case, so setting it again
      // would throw away the first bytes.
      var already = video.getAttribute('src') === sources[startRendition].url;
      if(!already){ currentRendition = startRendition; video.src = sources[startRendition].url; }
      else { currentRendition = startRendition; }
    }

    setIcon();
    updateSeek();
    layoutTicks();
    bar.classList.add('show');
    setTimeout(function(){ if(video.paused) bar.classList.remove('show'); }, 3200);

    /* --- resync after an AJAX swap ---------------------------------------
       The swap keeps the SAME <video> element (so picture-in-picture
       survives) and only changed its src. Everything else - the rendition
       list, the chapters, the poster, the picture's ratio - belongs to the
       video being swapped IN and has to be copied over. Keeping the old ones
       made the quality menu offer the PREVIOUS video's file, and play it. */
    el.__resync = function(newVideo){
      sources = parseSources(newVideo);
      chapters = parseChapters(newVideo);
      var poster = newVideo.getAttribute('poster');
      if(poster) video.setAttribute('poster', poster);
      var aspect = newVideo.getAttribute('data-aspect');
      if(aspect !== null && aspect !== '') el.setAttribute('data-aspect', aspect);
      currentRendition = null;
      renderTicks();
      // The chapter list is server-rendered markup inside the player box.
      var newHost = newVideo.closest ? newVideo.closest('.gallery-player') : null;
      var newCh = newHost ? newHost.querySelector('.player-chapters') : null;
      var oldCh = el.querySelector('.player-chapters');
      if(oldCh && newCh){ oldCh.outerHTML = newCh.outerHTML; }
      else if(oldCh && !newCh){ oldCh.remove(); }
      bindChapters();
      el.classList.add('is-poster');
      fitBox(el, video);
    };
    el.__showNextUp = showNextUp;
  }

  window.PlayerUI = {
    init: init,
    setLevels: function(hls){ liveHls = hls; },
    /* Re-fit every player (after a layout change, or a swap). */
    fit: function(){
      document.querySelectorAll('[data-player]').forEach(function(el){
        fitBox(el, el.querySelector('video') || el.querySelector('img'));
      });
    }
  };

  /* A gallery of stills has no <video> and so no control bar, but its stage
     still has to be shaped like the picture. */
  function initStage(el){
    if(el.__stageBound) return;
    el.__stageBound = true;
    var img = el.querySelector('img');
    if(!img) return;
    attachFit(el, img);
    if(img.complete && img.naturalWidth) fitBox(el, img);
    else img.addEventListener('load', function(){ fitBox(el, img); });
  }

  /* Public entry point used after an AJAX media swap: the swapped markup can
     bring a brand-new [data-player] wrapper (and a brand-new <video>), and the
     bar is only ever attached once per element. */
  function initAll(){
    document.querySelectorAll('[data-player]').forEach(function(el){
      if(!el.querySelector('video')){ initStage(el); return; }
      init(el);
    });
  }
  window.PlayerUI.initAll = initAll;
  if(document.readyState !== 'loading'){ initAll(); } else { document.addEventListener('DOMContentLoaded', initAll); }
  window.addEventListener('load', initAll);
})();
/* Picture-in-picture "keep open": when a video ends inside PiP and the user
   opted to keep it open, the browser auto-closes the PiP window on ended —
   reopen it so it stays up for replay. A manual close (not right after ended)
   is never reopened. */
document.addEventListener('leavepictureinpicture', function(){
  var v = document.pictureInPictureElement;
  if(v && v.__pipKeepOpen && v.__pipJustEnded){
    setTimeout(function(){
      if(!document.pictureInPictureElement && typeof v.requestPictureInPicture === 'function'){
        v.requestPictureInPicture().catch(function(){});
      }
    }, 150);
  }
});

/* In-gallery video navigation: Previous/Next swap the player via AJAX instead
   of a full page load, so a picture-in-picture window keeps playing as you
   move through the gallery. The <video> element is preserved and only its
   source is swapped. Also swaps the collection playlist panel + highlights. */
(function(){
  var slideshowTimer = null;
  var slideshowPaused = false;

  function clearSlideshowTimer(){
    if(slideshowTimer){ clearTimeout(slideshowTimer); slideshowTimer = null; }
  }

  // Advance to the next playlist item (slideshow / video-ended). Prefers the
  // active playlist row, then the media-nav Next link. Skips when the tab is
  // hidden so a backgrounded slideshow doesn't burn through.
  function advanceNext(){
    if(document.hidden) return;
    var active = document.querySelector('.player-playlist .pl-item.active');
    var next = active && active.nextElementSibling;
    if(next){
      var link = next.querySelector('a[data-swap]') || next.querySelector('a');
      if(link){ link.click(); return; }
    }
    var navNext = document.querySelector('.media-nav a[data-swap][data-next]');
    if(navNext){ navNext.click(); }
  }

  // The item the queue is going to play next, for the "next up" countdown.
  function nextInfo(){
    if(window.GalleryQueue && window.GalleryQueue.next){
      var nx = null;
      try { nx = window.GalleryQueue.next(); } catch(e){}
      if(nx && nx.action === 'play' && nx.item) return { title: nx.item.title || 'Next video' };
    }
    var active = document.querySelector('.player-playlist .pl-item.active');
    var next = active && active.nextElementSibling;
    if(next){
      var t = next.querySelector('.pl-title');
      if(t) return { title: (t.textContent || '').trim() };
    }
    return null;
  }

  // When the current item is an image (slideshow frame), auto-advance after
  // a few seconds unless paused or the tab is hidden.
  function scheduleSlideshow(){
    clearSlideshowTimer();
    var wrap = document.getElementById('video-player-wrap');
    var note = document.querySelector('.slideshow-note');
    if(!wrap || !wrap.querySelector('#slideshow-img')) return;
    if(slideshowPaused){ if(note) note.textContent = 'Slideshow paused'; return; }
    if(note) note.textContent = 'Auto-advances in a few seconds';
    slideshowTimer = setTimeout(function(){
      slideshowTimer = null;
      advanceNext();
    }, 4000);
  }

  // Bind the video-ended auto-advance to a (possibly replaced) video element.
  function bindVideo(video){
    if(!video || video.__advBound) return;
    video.__advBound = true;
    video.addEventListener('ended', function(){
      clearSlideshowTimer();
      // Autoplay off (or Repeat on, which the queue module handles by
      // replaying this video) must stop this path from advancing too.
      if(window.GalleryQueue && !window.GalleryQueue.canAdvance()) return;
      // Show what is coming with a countdown the viewer can cancel.
      var host = video.closest ? video.closest('[data-player]') : null;
      var info = nextInfo();
      if(host && host.__showNextUp && info){
        host.__showNextUp(info);
        return;
      }
      advanceNext();
    });
  }

  function updatePlaylist(doc){
    var npl = doc.querySelector('.player-playlist');
    var opl = document.querySelector('.player-playlist');
    if(npl && opl){ opl.outerHTML = npl.outerHTML; }
    var mediaId = null;
    var nv = doc.querySelector('#video-player-wrap video');
    var ni = doc.querySelector('#video-player-wrap img#slideshow-img');
    if(nv) mediaId = nv.getAttribute('data-video-id');
    else if(ni) mediaId = ni.getAttribute('data-video-id');
    document.querySelectorAll('.player-playlist .pl-item').forEach(function(li){
      li.classList.remove('active');
      if(li.getAttribute('data-video-id') === mediaId){ li.classList.add('active'); }
    });
    var active = document.querySelector('.player-playlist .pl-item.active');
    var list = document.querySelector('.player-playlist ul');
    if(active && list && active.scrollIntoView){ try{ active.scrollIntoView({block:'nearest'}); }catch(e){} }
    if(window.GalleryQueue) window.GalleryQueue.render();
    // The aside (and nav) were replaced with fresh markup — rebind the swap
    // links so playlist rows keep working after the first navigation.
    bindAllSwap();
    scheduleSlideshow();
  }

  // Swap the current media to the link's target (prev/next, playlist rows).
  // A video->video swap keeps the same <video> element so PiP persists; a
  // change between video and image replaces the whole media area.
  function bindSwapLink(a){
    if(a.__swapBound) return;
    a.__swapBound = true;
    a.addEventListener('click', function(e){
      e.preventDefault();
      clearSlideshowTimer();
      var href = a.getAttribute('href');
      fetch(href, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function(r){ return r.text(); })
        .then(function(html){
          var doc = new DOMParser().parseFromString(html, 'text/html');
          var wrap = document.getElementById('video-player-wrap');
          if(!wrap) return;
          var nv = doc.querySelector('#video-player-wrap video');
          var ni = doc.querySelector('#video-player-wrap img#slideshow-img');
          var curV = wrap.querySelector('video');
          if(nv && curV && nv.getAttribute('src')){
            var video = curV;
            var wasPlaying = video && !video.paused;
            // Copy the incoming video's data (renditions, chapters, poster,
            // picture ratio) onto the element we keep. Without this the
            // quality menu still offered - and played - the PREVIOUS
            // video's file.
            var host = curV.closest ? curV.closest('[data-player]') : null;
            if(host && host.__resync) host.__resync(nv);
            video.src = nv.getAttribute('src');
            video.load();
            if(wasPlaying){ video.play().catch(function(){}); }
          } else {
            var ngp = doc.querySelector('.gallery-player');
            var ogp = wrap.querySelector('.gallery-player');
            if(ngp && ogp){ ogp.outerHTML = ngp.outerHTML; }
            // A swapped video can bring a different ladder/poster/chapters set
            // and a new <video> element: rebuild the control bar for it.
            if(window.PlayerUI && window.PlayerUI.initAll) window.PlayerUI.initAll();
            var v3 = wrap.querySelector('video');
            if(v3 && window.GalleryResume) window.GalleryResume(v3);
          }
          var video2 = wrap.querySelector('video');
          if(video2) bindVideo(video2);
          var swap = function(sel){
            var nd = doc.querySelector(sel);
            var old = wrap.querySelector(sel);
            if(nd && old){ old.outerHTML = nd.outerHTML; }
          };
          swap('.media-progress');
          swap('figcaption');
          var nvNav = doc.querySelector('.media-nav');
          var oldNav = document.querySelector('.media-nav');
          if(nvNav && oldNav){ oldNav.outerHTML = nvNav.outerHTML; }
          var rep = doc.querySelector('#video-player-wrap + p a, figure + p a');
          var oldRep = document.querySelector('#video-player-wrap + p a, figure + p a');
          if(rep && oldRep){ oldRep.href = rep.getAttribute('href'); }
          updatePlaylist(doc);
          history.pushState({}, '', href);
          bindSlideshowToggle();
        })
        .catch(function(){});
    });
  }

  function bindSlideshowToggle(){
    var btn = document.querySelector('[data-slideshow-toggle]');
    if(!btn || btn.__ssBound) return;
    btn.__ssBound = true;
    btn.addEventListener('click', function(){
      slideshowPaused = !slideshowPaused;
      var note = document.querySelector('.slideshow-note');
      btn.textContent = slideshowPaused ? 'Resume slideshow' : 'Pause slideshow';
      if(slideshowPaused){ clearSlideshowTimer(); if(note) note.textContent = 'Slideshow paused'; }
      else { scheduleSlideshow(); }
    });
  }

  function bindAllSwap(){
    document.querySelectorAll('.media-nav a[data-swap], .player-playlist a[data-swap]').forEach(bindSwapLink);
  }

  function bind(){
    var wrap = document.getElementById('video-player-wrap');
    if(!wrap || wrap.__navBound) return;
    wrap.__navBound = true;

    bindAllSwap();
    bindSlideshowToggle();
    var video = wrap.querySelector('video');
    if(video) bindVideo(video);
    scheduleSlideshow();
  }
  if(document.readyState !== 'loading'){ bind(); } else { document.addEventListener('DOMContentLoaded', bind); }
  window.addEventListener('load', bind);

  /* The player's "next up" countdown needs a way to move the queue on when
     the countdown runs out or the viewer presses "Play now". */
  window.GalleryMediaNav = { advanceNext: advanceNext, nextInfo: nextInfo };
})();

/* Playlist queue + in-page gallery browser for the video player.
   Browsing happens inside the page (fetch + DOM swap), so a picture-in-picture
   window keeps playing. Picking a video adds it to a sessionStorage queue
   (front/end prompt when items are queued) and/or plays it in place.

   The queue is the transient "Up next" list only; the durable playlist is the
   server-side collection (see the Save-queue-as-collection button), which
   survives a new tab/device. Queue preferences (autoplay/repeat/shuffle) are
   remembered per signed-in user. */
(function(){
  var QKEY = 'galleryPlaylistQueue';
  var queue = [];
  var prefs = { autoplay: true, repeat: false, shuffle: false };
  var CSRF = (function(){
    var m = document.querySelector('meta[name="csrf-token"]');
    return m ? m.getAttribute('content') : '';
  })();

  function ns(){
    var body = document.body;
    return 'vp' + ((body && body.getAttribute('data-uid')) || '0') + '.';
  }
  function loadQ(){ try{ queue = JSON.parse(sessionStorage.getItem(QKEY)||'[]')||[]; }catch(e){ queue=[]; } }
  function saveQ(){ try{ sessionStorage.setItem(QKEY, JSON.stringify(queue)); }catch(e){} }
  function loadPrefs(){
    try{
      prefs.autoplay = localStorage.getItem(ns()+'autoplay') !== '0';
      prefs.repeat   = localStorage.getItem(ns()+'repeat') === '1';
      prefs.shuffle  = localStorage.getItem(ns()+'shuffle') === '1';
    }catch(e){}
  }
  function savePref(name, value){
    try{ localStorage.setItem(ns()+name, value ? '1' : '0'); }catch(e){}
  }
  function esc(s){ return String(s==null?'':s).replace(/[&<>"]/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); }
  function seconds(t){ var p=String(t||'').split(':').map(Number); if(p.some(isNaN)) return 0; return p.reduce(function(a,b){return a*60+b;},0); }

  function queueSeconds(){
    return queue.reduce(function(a,q){ return a + seconds(q.duration); },0);
  }
  function fmtTotal(total){
    total = Math.max(0, Math.floor(total));
    var h=Math.floor(total/3600), m=Math.floor((total%3600)/60), x=total%60;
    return (h ? h+':'+(m<10?'0':'')+m : m) + ':' + (x<10?'0':'')+x;
  }

  // Remove browse tiles whose video is already in the playlist/queue, so a
  // queued video no longer appears in the browse list.
  function removeQueuedTiles(){
    if(!queue.length) return;
    var ids = {};
    queue.forEach(function(q){ ids[String(q.id)] = true; });
    document.querySelectorAll('#player-browse .browse-video-tile').forEach(function(tile){
      var a = tile.querySelector('[data-browse-video]');
      if(a && ids[a.getAttribute('data-browse-video')]){ tile.remove(); }
    });
  }

  function init(){
    var btn = document.getElementById('browse-galleries-btn');
    var panel = document.getElementById('player-browse');
    if(btn && panel && !panel.__init){
      panel.__init = true;
      var body = panel.querySelector('.pb-body');
      var search = document.getElementById('pb-search');
      var base = (document.body && document.body.getAttribute('data-base')) || '';

      function loadList(q, offset){
        var url = base + '/browse/videos';
        var qs = [];
        if(q){ qs.push('q=' + encodeURIComponent(q)); }
        if(offset){ qs.push('offset=' + offset); }
        if(qs.length){ url += '?' + qs.join('&'); }
        return fetch(url).then(function(r){ return r.text(); });
      }

      function showList(q){
        body.innerHTML = '<p class="muted">Loading&hellip;</p>';
        loadList(q || '').then(function(html){ body.innerHTML = html; removeQueuedTiles(); }).catch(function(){ body.innerHTML = '<p class="muted">Could not load videos.</p>'; });
      }

      btn.addEventListener('click', function(){
        panel.hidden = !panel.hidden;
        btn.setAttribute('aria-expanded', panel.hidden ? 'false' : 'true');
        if(!panel.hidden && !body.childNodes.length){
          showList(search ? search.value : '');
        }
      });

      // Debounced search re-fetches the video list.
      var debounce = null;
      if(search){
        search.addEventListener('input', function(){
          clearTimeout(debounce);
          debounce = setTimeout(function(){ showList(search.value); }, 250);
        });
      }

      panel.addEventListener('click', function(e){
        var more = e.target.closest('.pb-more');
        if(more){
          e.preventDefault();
          loadList(more.getAttribute('data-q') || '', parseInt(more.getAttribute('data-offset'), 10) || 0)
            .then(function(html){
              var holder = document.createElement('div');
              holder.innerHTML = html;
              more.remove();
              // Append each child directly so the grid pattern is preserved.
              Array.prototype.forEach.call(holder.children, function(child){ body.appendChild(child); });
              removeQueuedTiles();
            })
            .catch(function(){});
          return;
        }
        var vid = e.target.closest('[data-browse-video]');
        if(vid){
          e.preventDefault();
          chooseAction({
            id: vid.getAttribute('data-browse-video'),
            title: vid.getAttribute('data-video-title') || 'Video',
            thumb: vid.getAttribute('data-video-thumb') || '',
            web: vid.getAttribute('data-video-web') || '',
            url: vid.getAttribute('data-video-url') || (base + '/videos/' + vid.getAttribute('data-browse-video')),
            duration: vid.getAttribute('data-video-duration') || ''
          });
        }
      });
    }

    bindPlaylistControls();
  }

  function bindPlaylistControls(){
    var aside = document.querySelector('.player-playlist');
    if(!aside || aside.__ctrlsBound) return;
    aside.__ctrlsBound = true;

    aside.querySelectorAll('[data-queue]').forEach(function(btn){
      var name = btn.getAttribute('data-queue');
      btn.setAttribute('aria-pressed', prefs[name] ? 'true' : 'false');
      btn.textContent = labelFor(name, prefs[name]);
      btn.addEventListener('click', function(){
        prefs[name] = !prefs[name];
        savePref(name, prefs[name]);
        btn.setAttribute('aria-pressed', prefs[name] ? 'true' : 'false');
        btn.textContent = labelFor(name, prefs[name]);
      });
    });

    var saveBtn = aside.querySelector('[data-save-collection]');
    if(saveBtn && !saveBtn.__saveBound){
      saveBtn.__saveBound = true;
      saveBtn.addEventListener('click', function(){
        if(!queue.length){ window.alert('Queue something first, then save it as a collection.'); return; }
        var name = window.prompt('Name this playlist', 'Watch later');
        if(!name || !name.trim()) return;
        var base = (document.body && document.body.getAttribute('data-base')) || '';
        var body = new URLSearchParams();
        body.append('name', name.trim());
        queue.forEach(function(q){ body.append('photo_ids[]', q.id); });
        if(CSRF) body.append('_token', CSRF);
        saveBtn.disabled = true;
        saveBtn.textContent = 'Saving…';
        fetch(base + '/collections/from-queue', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
          body: body.toString(),
          credentials: 'same-origin'
        })
          .then(function(r){ return r.text().then(function(t){ return { ok: r.ok, url: r.url, body: t }; }); })
          .then(function(res){
            if(res.ok || res.body.indexOf('collection') !== -1){
              window.location.href = res.url && res.url.indexOf('/collections') !== -1 ? res.url : (base + '/collections');
            } else {
              saveBtn.disabled = false;
              saveBtn.textContent = 'Save queue as collection';
              window.alert('Could not save that playlist. Please try again.');
            }
          })
          .catch(function(){
            saveBtn.disabled = false;
            saveBtn.textContent = 'Save queue as collection';
          });
      });
    }

    var toggle = document.querySelector('[data-playlist-toggle]');
    if(toggle && !toggle.__collapseBound){
      toggle.__collapseBound = true;
      toggle.addEventListener('click', function(){
        var body = document.getElementById('player-playlist-body');
        if(!body) return;
        var open = toggle.getAttribute('aria-expanded') === 'true';
        toggle.setAttribute('aria-expanded', open ? 'false' : 'true');
        toggle.textContent = open ? 'Show playlist' : 'Hide playlist';
        body.hidden = open;
      });
    }
  }

  function labelFor(name, on){
    if(name === 'autoplay') return 'Autoplay: ' + (on ? 'on' : 'off');
    if(name === 'repeat') return 'Repeat: ' + (on ? 'on' : 'off');
    return 'Shuffle: ' + (on ? 'on' : 'off');
  }

  function chooseAction(item){
    var video = document.querySelector('#video-player-wrap video');
    if(!video) return;
    var already = queue.some(function(q){ return String(q.id) === String(item.id); });
    var doIt = function(how){
      if(!already){
        if(how === 'front'){ queue.unshift(item); }
        else { queue.push(item); } // 'end' and 'play' both add it to the playlist
      }
      saveQ();
      renderQueue();
      removeQueuedTiles();
      if(how === 'front' || how === 'play'){
        playItem(item);
      }
    };
    if(queue.length > 0){
      showPrompt(item, doIt);
    } else {
      doIt('play'); // no queue -> play immediately
    }
  }

  function showPrompt(item, doIt){
    var old = document.getElementById('queue-prompt');
    if(old) old.remove();
    var d = document.createElement('div');
    d.id = 'queue-prompt';
    d.className = 'queue-prompt';
    d.innerHTML = '<div class="queue-prompt-card" role="dialog" aria-modal="true" aria-label="Add to playlist">' +
      '<p>Add &ldquo;' + esc(item.title) + '&rdquo; to the playlist?</p>' +
      '<div class="queue-prompt-actions">' +
      '<button type="button" data-q="play">Play now</button>' +
      '<button type="button" data-q="front">Add to front</button>' +
      '<button type="button" data-q="end">Add to end</button>' +
      '<button type="button" class="qp-cancel" data-q="cancel">Cancel</button>' +
      '</div></div>';
    document.body.appendChild(d);

    /* Focus trap: the dialog is modal, so Tab must not walk out of it and
       Escape must close it. Without this a keyboard user was stranded behind
       the overlay (or had to hunt for the cancel button). */
    var buttons = Array.prototype.slice.call(d.querySelectorAll('button'));
    var first = buttons[0];
    var last = buttons[buttons.length - 1];
    if(first) first.focus();
    d.addEventListener('keydown', function(e){
      if(e.key === 'Escape'){ e.preventDefault(); d.remove(); return; }
      if(e.key !== 'Tab') return;
      if(e.shiftKey && document.activeElement === first){ e.preventDefault(); last.focus(); }
      else if(!e.shiftKey && document.activeElement === last){ e.preventDefault(); first.focus(); }
    });
    var restore = document.activeElement;
    d.addEventListener('click', function(e){
      if(e.target === d){ d.remove(); if(restore && restore.focus) restore.focus(); }
    });

    buttons.forEach(function(b){
      b.addEventListener('click', function(){
        var how = b.getAttribute('data-q');
        d.remove();
        if(restore && restore.focus) restore.focus();
        if(how === 'cancel') return;
        if(how === 'front') doIt('front');
        else if(how === 'end') doIt('end');
        else doIt('play');
      });
    });
  }

  // Swap the SAME <video> element to the picked video (PiP persists).
  function playItem(item){
    var video = document.querySelector('#video-player-wrap video');
    if(!video || !item.web) return;
    var wasPaused = video.paused;
    var at = video.currentTime;
    video.src = item.web;
    video.load();
    if(!wasPaused){ video.play().catch(function(){}); }
    if(at > 0 && isFinite(video.duration) === false){
      video.addEventListener('loadedmetadata', function(){
        if(at > 0 && isFinite(video.duration)) video.currentTime = Math.min(at, Math.max(0, video.duration - 0.25));
      }, {once:true});
    }
    // highlight the matching playlist row if one exists
    var li = document.querySelector('.player-playlist .pl-item[data-video-id="' + item.id + '"]');
    if(li){
      document.querySelectorAll('.player-playlist .pl-item').forEach(function(x){ x.classList.remove('active'); });
      li.classList.add('active');
    }
  }

  /* The next video to play when the current one ends. Repeat wins over
     autoplay; shuffle picks a random queued item; otherwise the row after the
     active one. Returns null when nothing should play. */
  function nextQueued(){
    if(prefs.repeat){
      var video = document.querySelector('#video-player-wrap video');
      if(video) return { action: 'restart' };
    }
    if(!queue.length) return null;
    if(prefs.shuffle){
      return { action: 'play', item: queue[Math.floor(Math.random() * queue.length)] };
    }
    var active = document.querySelector('.player-playlist .pl-item.active');
    if(active && active.nextElementSibling){
      var next = active.nextElementSibling.getAttribute('data-video-id');
      for(var i = 0; i < queue.length; i++){
        if(String(queue[i].id) === String(next)) return { action: 'play', item: queue[i] };
      }
    }
    // Fall back to the first queued row (the queue is "up next" even when the
    // current video is not in the collection playlist).
    return { action: 'play', item: queue[0] };
  }

  function renderQueue(){
    var aside = document.querySelector('.player-playlist');
    var ul = aside ? aside.querySelector('ul') : null;
    if(!aside || !ul) return;
    // remove previously rendered queued rows (marked data-queued)
    ul.querySelectorAll('.pl-item[data-queued]').forEach(function(x){ x.remove(); });
    var sep = ul.querySelector('.pl-queued-sep');
    if(sep) sep.remove();
    var empty = aside.querySelector('.pl-empty');
    var clearBtn = aside.querySelector('.pl-clear');
    var saveBtn = aside.querySelector('[data-save-collection]');
    if(!queue.length){
      if(clearBtn) clearBtn.remove();
      if(saveBtn) saveBtn.hidden = true;
      // Keep the empty state only when there is no collection playlist either.
      if(!ul.querySelector('.pl-item') && !empty){
        empty = document.createElement('p');
        empty.className = 'pl-empty muted';
        empty.style.cssText = 'padding:.5rem;margin:.25rem 0 0;font-size:.85rem;';
        empty.textContent = 'No videos queued. Browse galleries to add to the playlist.';
        aside.appendChild(empty);
      }
      return;
    }
    if(empty) empty.remove();
    if(!clearBtn){
      clearBtn = document.createElement('button');
      clearBtn.type = 'button';
      clearBtn.className = 'btn btn-sm btn-outline pl-clear';
      clearBtn.textContent = 'Clear playlist';
      clearBtn.addEventListener('click', function(e){
        e.stopPropagation();
        queue = [];
        saveQ();
        renderQueue();
      });
      var h3 = aside.querySelector('h3');
      if(h3) h3.appendChild(clearBtn);
    }
    var wrap = document.createElement('li');
    wrap.className = 'pl-item pl-queued-sep';
    wrap.innerHTML = '<div class="pl-title" style="font-weight:600;">Up next &middot; '
      + queue.length + (queue.length === 1 ? ' video · ' : ' videos · ') + fmtTotal(queueSeconds()) + '</div>';
    ul.appendChild(wrap);
    queue.forEach(function(item){
      var li = document.createElement('li');
      li.className = 'pl-item';
      li.setAttribute('data-queued', '1');
      li.setAttribute('data-video-id', item.id);
      li.innerHTML = '<a href="' + esc(item.url) + '">' +
        (item.thumb ? '<img src="' + esc(item.thumb) + '" alt="" loading="lazy">' : '') +
        '<span class="pl-title">' + esc(item.title) + '</span>' +
        (item.duration ? '<span class="pl-duration">' + esc(item.duration) + '</span>' : '') +
        '</a>' +
        '<button type="button" class="pl-remove" data-id="' + esc(item.id) + '" aria-label="Remove ' + esc(item.title) + ' from playlist" title="Remove from playlist">&times;</button>';
      ul.appendChild(li);
      li.querySelector('a').addEventListener('click', function(e){
        e.preventDefault();
        var video = document.querySelector('#video-player-wrap video');
        if(video && item.web){ video.src = item.web; video.load(); video.play().catch(function(){}); }
      });
      li.querySelector('.pl-remove').addEventListener('click', function(e){
        e.stopPropagation();
        queue = queue.filter(function(q){ return String(q.id) !== String(item.id); });
        saveQ();
        renderQueue();
      });
    });
    if(saveBtn) saveBtn.hidden = false;
    bindPlaylistControls();
  }

  /* Autoplay-next now honours the user's choice. The previous behaviour always
     advanced on "ended", which made stopping the queue impossible. */
  function bindEnded(){
    var video = document.querySelector('#video-player-wrap video');
    if(!video || video.__queueEndedBound) return;
    video.__queueEndedBound = true;
    video.addEventListener('ended', function(){
      if(prefs.repeat){ video.currentTime = 0; video.play().catch(function(){}); return; }
      if(!prefs.autoplay) return;
      var nxt = nextQueued();
      if(!nxt) return;
      if(nxt.action === 'restart'){ video.currentTime = 0; video.play().catch(function(){}); return; }
      if(nxt.item && nxt.item.web) playItem(nxt.item);
    });
  }

  function wire(){
    init();
    bindPlaylistControls();
    bindEnded();
    renderQueue();
  }

  loadQ();
  loadPrefs();
  if(document.readyState !== 'loading'){ wire(); } else { document.addEventListener('DOMContentLoaded', wire); }
  window.addEventListener('load', function(){ wire(); renderQueue(); });
  // Exposed so the media-nav swap handler can re-render queued rows (and rebind
  // the controls) after a prev/next navigation replaces the playlist aside.
  window.GalleryQueue = {
    render: function(){ wire(); renderQueue(); },
    prefs: prefs,
    next: nextQueued,
    /* Single decision point for "should 'ended' move to the next video?".
       The media-nav swap module asks the same question, so the Autoplay /
       Repeat toggles cannot be bypassed by that older code path. */
    canAdvance: function(){ return prefs.autoplay && !prefs.repeat; }
  };
})();