/* Lightbox viewer */
(function(){
  var lb, lbImg, lbCaption, lbCounter, images=[], currentIdx=0;
  var touchStartX=0, touchStartY=0, touchStartDistance=0, touchScale=1;
  var touchMoved=false, pinchActive=false, suppressClickUntil=0, returnFocus=null;
  var historyKey='galleryLightbox';
  var lbFullBtn=null, fullSize=false;

  function create(){
    if(lb)return;
    lb=document.createElement('div');
    lb.className='se-lightbox';
    lb.setAttribute('role','dialog');
    lb.setAttribute('aria-modal','true');
    lb.setAttribute('aria-label','Image viewer');
    lb.innerHTML='<span class="lb-counter" aria-live="polite"></span><button class="lb-close" aria-label="Close image viewer">&times;</button><button class="lb-nav lb-prev" aria-label="Previous image">&#8249;</button><button class="lb-nav lb-next" aria-label="Next image">&#8250;</button><button class="lb-full" aria-label="View full-size original">Full size</button><img src="" alt=""><div class="lb-caption"></div>';
    document.body.appendChild(lb);
    lbImg=lb.querySelector('img');
    lbCaption=lb.querySelector('.lb-caption');
    lbCounter=lb.querySelector('.lb-counter');
    lb.querySelector('.lb-close').onclick=close;
    lb.querySelector('.lb-prev').onclick=function(){navigate(-1)};
    lb.querySelector('.lb-next').onclick=function(){navigate(1)};
    lb.addEventListener('click',function(e){if(e.target===lb)close()});
    lbImg.addEventListener('click',function(e){
      e.stopPropagation();
      if(Date.now()<suppressClickUntil)return;
      if(!pinchActive){
        lbImg.classList.toggle('zoomed');
        // Reset the pan position so a fresh zoom starts at the top-left.
        lb.scrollTop=0; lb.scrollLeft=0;
      }
    });
    lbFullBtn=lb.querySelector('.lb-full');
    lbFullBtn.onclick=function(e){e.stopPropagation();fullSize=!fullSize;show();};
    lbImg.addEventListener('touchstart',touchStart,{passive:false});
    lbImg.addEventListener('touchmove',touchMove,{passive:false});
    lbImg.addEventListener('touchend',touchEnd,{passive:false});
    document.addEventListener('keydown',function(e){
      if(!lb.classList.contains('open'))return;
       if(e.key==='Escape'){e.preventDefault();close();}
       else if(e.key==='ArrowLeft'){e.preventDefault();navigate(-1);}
       else if(e.key==='ArrowRight'){e.preventDefault();navigate(1);}
    });
  }

  // Build the lightbox image list from every rendered grid image. Because
  // galleries can load more items via AJAX, the list is rebuilt at open time
  // from the current DOM so the lightbox always covers everything loaded.
  function buildList(){
    var out=[];
    document.querySelectorAll('#gallery [data-lightbox]').forEach(function(el){
      out.push({
        src:el.getAttribute('data-lightbox'),
        full:el.getAttribute('data-lightbox-full')||null,
        caption:el.getAttribute('data-lightbox-caption')||el.alt||''
      });
    });
    return out;
  }

  function open(list,startIdx,opener){
    create();
    returnFocus=opener||document.activeElement;
    fullSize=false;
    if(list&&list.length){images=list;currentIdx=startIdx<0?0:startIdx;}
    else{
      images=buildList();
      currentIdx=0;
      if(images.length&&opener){
        var idx=Array.prototype.indexOf.call(document.querySelectorAll('#gallery [data-lightbox]'),opener);
        if(idx>-1)currentIdx=idx;
      }
    }
    show();
    lb.classList.add('open');
    document.body.style.overflow='hidden';
    if(!history.state||history.state[historyKey]!==true)history.pushState({galleryLightbox:true},'',location.href);
    lb.querySelector('.lb-close').focus();
  }

  function close(fromHistory){
    if(!lb)return;
    lb.classList.remove('open');
    lbImg.classList.remove('zoomed');
    lbImg.style.transform='';
    touchScale=1;
    lb.scrollTop=0; lb.scrollLeft=0;
    document.body.style.overflow='';
    if(returnFocus&&typeof returnFocus.focus==='function')returnFocus.focus();
    returnFocus=null;
    if(fromHistory!==false&&history.state&&history.state[historyKey]===true)history.back();
  }

  function navigate(dir){
    currentIdx+=dir;
    if(currentIdx<0)currentIdx=images.length-1;
    if(currentIdx>=images.length)currentIdx=0;
    lbImg.classList.remove('zoomed');
    lbImg.style.transform='';
    touchScale=1;
    fullSize=false;
    show();
  }

  function distance(a,b){var x=a.clientX-b.clientX,y=a.clientY-b.clientY;return Math.sqrt(x*x+y*y)}
  function touchStart(e){
    if(!lb.classList.contains('open'))return;
    touchMoved=false;pinchActive=e.touches.length>1;
    if(pinchActive)touchStartDistance=distance(e.touches[0],e.touches[1]);
    else{touchStartX=e.touches[0].clientX;touchStartY=e.touches[0].clientY;}
  }
  function touchMove(e){
    if(e.touches.length>1){
      e.preventDefault();pinchActive=true;touchMoved=true;
      var ratio=distance(e.touches[0],e.touches[1])/touchStartDistance;
      touchScale=Math.max(1,Math.min(4,touchScale*ratio));
      touchStartDistance=distance(e.touches[0],e.touches[1]);
      lbImg.style.transform='scale('+touchScale+')';
      // Beyond 1x the image must overflow so the lightbox can scroll (pan)
      // to the rest of it; the class drops the max-width/max-height caps.
      if(touchScale>1){lbImg.classList.add('zoomed');}else{lbImg.classList.remove('zoomed');}
      return;
    }
    // When zoomed, don't block the browser's native pan/scroll; swiping to
    // navigate images only applies at 1x.
    if(!pinchActive&&!lbImg.classList.contains('zoomed')&&Math.abs(e.touches[0].clientX-touchStartX)>10){e.preventDefault();touchMoved=true;}
  }
  function touchEnd(e){
    if(touchMoved)suppressClickUntil=Date.now()+500;
    if(!pinchActive&&touchMoved&&e.changedTouches.length&&!lbImg.classList.contains('zoomed')){
      var dx=e.changedTouches[0].clientX-touchStartX,dy=e.changedTouches[0].clientY-touchStartY;
      if(Math.abs(dx)>=50&&Math.abs(dx)>Math.abs(dy)){navigate(dx<0?1:-1);}
    }
    if(e.touches.length===0){pinchActive=false;touchMoved=false;}
  }

  function show(){
    var m=images[currentIdx];
    // Clear the previous image immediately so a slow next image never leaves
    // the old one on screen ("clicking a new one loads the old image").
    if(lbImg.src!=='')lbImg.removeAttribute('src');
    lbImg.classList.remove('lb-loaded');
    lbImg.src=(fullSize&&m.full)?m.full:m.src;
    lbImg.decoding='async';
    lbImg.fetchPriority='high';
    lbImg.alt=m.caption||'';
    lbImg.classList.remove('zoomed');
    if(lb){ lb.scrollTop=0; lb.scrollLeft=0; }
    lbImg.onload=function(){lbImg.classList.add('lb-loaded');};
    lbCaption.textContent=m.caption||'';
    lbCounter.textContent='Item '+(currentIdx+1)+' of '+images.length;
    if(lbFullBtn){
      lbFullBtn.style.display=m.full?'':'none';
      lbFullBtn.textContent=fullSize?'Show web size':'Full size';
    }
    [-1,1].forEach(function(offset){
      var adjacent=images[(currentIdx+offset+images.length)%images.length];
      if(adjacent&&adjacent.src){var preload=new Image();preload.decoding='async';preload.src=adjacent.src;}
    });
  }

  window.GalleryLightbox={open:open};

  window.addEventListener('popstate',function(e){
    if(lb&&lb.classList.contains('open')&&(!e.state||e.state[historyKey]!==true))close(false);
  });

  document.addEventListener('DOMContentLoaded',function(){
    document.querySelectorAll('video[data-video-id]').forEach(function(video){initVideoResume(video)});

    // Lightbox: delegated so newly loaded ("Load more") items work too.
    document.addEventListener('click',function(e){
      var el=e.target&&e.target.closest?e.target.closest('[data-lightbox]'):null;
      if(el){e.preventDefault();open(null,-1,el);}
    });

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
    var key='gallery-video-position-'+video.getAttribute('data-video-id'), saved=0, resume=video.parentNode.querySelector('.video-resume');
    try{saved=parseFloat(localStorage.getItem(key))||0;}catch(e){}
    if(saved>0&&isFinite(saved)){
      resume.hidden=false;
      resume.querySelector('.video-resume-time').textContent=formatTime(saved);
      resume.querySelector('button').onclick=function(){video.currentTime=saved;resume.hidden=true;video.play().catch(function(){});};
      video.addEventListener('loadedmetadata',function(){video.currentTime=Math.min(saved,Math.max(0,video.duration-0.5));},{once:true});
    }
    video.addEventListener('timeupdate',function(){if(video.currentTime>1&&!video.ended)try{localStorage.setItem(key,video.currentTime);}catch(e){}});
    video.addEventListener('pause',function(){if(video.currentTime>1&&!video.ended)try{localStorage.setItem(key,video.currentTime);}catch(e){}});
    video.addEventListener('ended',function(){try{localStorage.removeItem(key);}catch(e){};if(resume)resume.hidden=true;});
  }
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
      if(a&&(a.tagName==='IMG'||a.closest('img')||a.closest('.se-lightbox'))){
        e.preventDefault();
        return false;
      }
    }
  });
})();

/* Picture-in-picture: [data-pip] button toggles PiP for the nearest <video>
   in the same wrapper. Falls back gracefully when the browser does not
   support PiP (the button stays inert). */
(function(){
  function togglePip(video, btn){
    if(!video || typeof video.requestPictureInPicture !== 'function') return;
    if(document.pictureInPictureElement === video){
      document.exitPictureInPicture().catch(function(){});
    }else{
      video.requestPictureInPicture().catch(function(){});
    }
  }
  document.addEventListener('click', function(e){
    var btn = e.target && e.target.closest ? e.target.closest('[data-pip]') : null;
    if(!btn) return;
    e.preventDefault();
    var video = btn.closest('figure, .live-player-wrap, .player-wrap')
      ? btn.closest('figure, .live-player-wrap, .player-wrap').querySelector('video')
      : btn.parentElement.querySelector('video');
    togglePip(video, btn);
  });
  document.addEventListener('enterpictureinpicture', function(e){
    var btn = document.querySelector('[data-pip]');
    if(btn && btn.textContent.indexOf('Close') === -1) btn.textContent = 'Close picture in picture';
  });
  document.addEventListener('leavepictureinpicture', function(e){
    var btn = document.querySelector('[data-pip]');
    if(btn && btn.textContent.indexOf('Picture') === -1) btn.textContent = 'Picture in picture';
  });
})();

/* Custom video player: themed control bar for gallery MP4s and the live HLS
   stream. Binds to [data-player] wrappers; builds play/pause, seek, time,
   speed, volume, PiP and fullscreen controls, plus an HLS quality menu for
   live. Falls back to the native controls if this script fails to run. */
(function(){
  var SPEEDS = [0.5, 0.75, 1, 1.25, 1.5, 2];
  var liveHls = null;
  function fmt(s){ s = Math.max(0, Math.floor(s)); var m = Math.floor(s/60); var x = Math.floor(s%60); return m + ':' + (x<10?'0':'') + x; }

  function init(el){
    var video = el.querySelector('video');
    if(!video || video.__gplayer) return;
    video.__gplayer = true;

    var isLive = el.hasAttribute('data-live');
    var fsTarget = el.getAttribute('data-fullscreen');
    var bar = document.createElement('div');
    bar.className = 'player-bar';
    bar.innerHTML =
      (isLive ? '<span class="player-live-dot" title="LIVE"></span>' : '') +
      '<button type="button" class="player-btn" data-act="play" aria-label="Play or pause">&#9654;</button>' +
      '<div class="player-seek"><input type="range" min="0" max="1000" value="0" step="1" aria-label="Seek"></div>' +
      '<span class="player-time">0:00 / 0:00</span>' +
      '<button type="button" class="player-btn player-speed" data-act="speed" title="Playback speed">1&#215;</button>' +
      (isLive ? '<button type="button" class="player-btn" data-act="quality" title="Quality">&#9881;</button>' : '') +
      '<button type="button" class="player-btn" data-act="vol" aria-label="Mute or unmute">&#128266;</button>' +
      '<label class="player-keep" title="Keep the picture-in-picture window open after the video ends (allows replay)"><input type="checkbox" data-act="keep" checked>Keep open</label>' +
      '<button type="button" class="player-btn" data-act="pip" title="Picture in picture">&#9210;</button>' +
      '<button type="button" class="player-btn" data-act="fs" title="Full screen">&#9974;</button>';
    el.appendChild(bar);

    var seek = bar.querySelector('.player-seek input');
    var time = bar.querySelector('.player-time');
    var speedBtn = bar.querySelector('[data-act="speed"]');
    var playBtn = bar.querySelector('[data-act="play"]');
    var volBtn = bar.querySelector('[data-act="vol"]');
    var speedIdx = SPEEDS.indexOf(1);

    video.controls = false;

    function setIcon(){
      if(playBtn) playBtn.innerHTML = video.paused ? '&#9654;' : '&#10074;&#10074;';
      el.classList.toggle('playing', !video.paused);
    }
    function updateSeek(){
      if(isLive){ seek.value = 0; time.textContent = 'LIVE'; return; }
      if(!video.duration || isNaN(video.duration)){ seek.value = 0; time.textContent = '0:00 / 0:00'; return; }
      seek.value = Math.round(video.currentTime / video.duration * 1000);
      time.textContent = fmt(video.currentTime) + ' / ' + fmt(video.duration);
    }
    if(playBtn) playBtn.addEventListener('click', function(){
      if(video.paused){ video.play().catch(function(){}); } else { video.pause(); }
    });
    video.addEventListener('play', setIcon);
    video.addEventListener('pause', setIcon);
    video.addEventListener('timeupdate', updateSeek);
    video.addEventListener('loadedmetadata', updateSeek);
    seek.addEventListener('input', function(){
      if(video.duration && !isLive){ video.currentTime = seek.value / 1000 * video.duration; }
    });
    if(speedBtn) speedBtn.addEventListener('click', function(){
      speedIdx = (speedIdx + 1) % SPEEDS.length;
      video.playbackRate = SPEEDS[speedIdx];
      speedBtn.textContent = SPEEDS[speedIdx] + '\u00d7';
    });
    if(volBtn) volBtn.addEventListener('click', function(){
      video.muted = !video.muted;
      volBtn.innerHTML = video.muted ? '&#128263;' : '&#128266;';
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

    var hideTimer = null;
    function scheduleHide(){ if(hideTimer) clearTimeout(hideTimer); if(!video.paused){ hideTimer = setTimeout(function(){ bar.classList.remove('show'); }, 2600); } }
    el.addEventListener('mousemove', function(){ bar.classList.add('show'); scheduleHide(); });
    el.addEventListener('mouseleave', function(){ if(!video.paused) bar.classList.remove('show'); });
    video.addEventListener('pause', function(){ bar.classList.add('show'); });

    setIcon();
    updateSeek();
    bar.classList.add('show');
    setTimeout(function(){ if(video.paused) bar.classList.remove('show'); }, 3200);
  }

  function toggleLevels(el){
    var menu = el.querySelector('.player-quality');
    if(menu){ menu.remove(); return; }
    if(!liveHls) return;
    menu = document.createElement('div');
    menu.className = 'player-quality';
    var items = '<button type="button" data-l="auto">Auto</button>' +
      (liveHls.levels || []).map(function(lv, i){ return '<button type="button" data-l="' + i + '">' + (lv.height ? lv.height + 'p' : 'Auto') + '</button>'; }).join('');
    menu.innerHTML = items;
    el.appendChild(menu);
    menu.addEventListener('click', function(e){
      var b = e.target && e.target.closest ? e.target.closest('[data-l]') : null;
      if(!b) return;
      liveHls.currentLevel = b.getAttribute('data-l') === 'auto' ? -1 : parseInt(b.getAttribute('data-l'), 10);
      menu.remove();
    });
  }

  window.PlayerUI = {
    init: init,
    setLevels: function(hls){ liveHls = hls; },
    toggleLevels: toggleLevels
  };

  function initAll(){ document.querySelectorAll('[data-player]').forEach(function(el){ init(el); }); }
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
  function updatePlaylist(doc, href, video){
    // Replace the playlist aside (if the new page has one) and highlight the
    // active row; keep a reference to the next item for auto-advance.
    var npl = doc.querySelector('.player-playlist');
    var opl = document.querySelector('.player-playlist');
    if(npl && opl){ opl.outerHTML = npl.outerHTML; }
    var vidId = null;
    var nv = doc.querySelector('#video-player-wrap video');
    if(nv) vidId = nv.getAttribute('data-video-id');
    var active = null, next = null;
    document.querySelectorAll('.player-playlist .pl-item').forEach(function(li, i, arr){
      li.classList.remove('active');
      if(li.getAttribute('data-video-id') === vidId){ li.classList.add('active'); active = li; }
      if(active && !next && i > Array.prototype.indexOf.call(arr, active)){ next = li; }
    });
    var list = document.querySelector('.player-playlist ul');
    if(active && list && active.scrollIntoView){ try{ active.scrollIntoView({block:'nearest'}); }catch(e){} }
    if(video){ video.__plNext = next ? (next.querySelector('a') ? next.querySelector('a').getAttribute('href') : null) : null; }
    if(window.GalleryQueue) window.GalleryQueue.render();
  }

  function bind(){
    var wrap = document.getElementById('video-player-wrap');
    if(!wrap || !wrap.querySelector('video') || wrap.__navBound) return;
    wrap.__navBound = true;

    document.querySelectorAll('.media-nav a[data-swap], .player-playlist a[data-swap]').forEach(function(a){
      a.addEventListener('click', function(e){
        e.preventDefault();
        var href = a.getAttribute('href');
        fetch(href, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
          .then(function(r){ return r.text(); })
          .then(function(html){
            var doc = new DOMParser().parseFromString(html, 'text/html');
            var nv = doc.querySelector('#video-player-wrap video');
            if(!nv || !nv.getAttribute('src')) return;
            var video = wrap.querySelector('video');
            var wasPlaying = video && !video.paused;
            video.src = nv.getAttribute('src');
            video.load();
            if(wasPlaying){ video.play().catch(function(){}); }
            // Update caption, progress and nav from the fetched fragment.
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
            updatePlaylist(doc, href, video);
            history.pushState({}, '', href);
          })
          .catch(function(){});
      });
    });

    // Auto-advance: when the current video ends, play the next playlist item,
    // or the next media item in the same gallery when browsing normally.
    var video = wrap.querySelector('video');
    video.addEventListener('ended', function(){
      if(!document.hasFocus()) return;
      var active = document.querySelector('.player-playlist .pl-item.active');
      var next = active && active.nextElementSibling;
      if(next){
        var link = next.querySelector('a[data-swap]');
        if(link){ link.click(); return; }
      }
      var navNext = document.querySelector('.media-nav a[data-swap][data-next]');
      if(navNext){ navNext.click(); }
    });
  }
  if(document.readyState !== 'loading'){ bind(); } else { document.addEventListener('DOMContentLoaded', bind); }
  window.addEventListener('load', bind);
})();

/* Playlist queue + in-page gallery browser for the video player.
   Browsing happens inside the page (fetch + DOM swap), so a picture-in-picture
   window keeps playing. Picking a video adds it to a sessionStorage queue
   (front/end prompt when items are queued) and/or plays it in place. */
(function(){
  var QKEY = 'galleryPlaylistQueue';
  var queue = [];
  function loadQ(){ try{ queue = JSON.parse(sessionStorage.getItem(QKEY)||'[]')||[]; }catch(e){ queue=[]; } }
  function saveQ(){ try{ sessionStorage.setItem(QKEY, JSON.stringify(queue)); }catch(e){} }
  function esc(s){ return String(s==null?'':s).replace(/[&<>"]/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); }

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
    if(!btn || !panel || panel.__init) return;
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
          url: vid.getAttribute('data-video-url') || (base + '/videos/' + vid.getAttribute('data-browse-video'))
        });
      }
    });
  }

  function chooseAction(item){
    var video = document.querySelector('#video-player-wrap video');
    if(!video) return;
    var already = queue.some(function(q){ return q.id === item.id; });
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
    d.innerHTML = '<div class="queue-prompt-card" role="dialog" aria-modal="true">' +
      '<p>Add &ldquo;' + esc(item.title) + '&rdquo; to the playlist?</p>' +
      '<div class="queue-prompt-actions">' +
      '<button type="button" data-q="play">Play now</button>' +
      '<button type="button" data-q="front">Add to front</button>' +
      '<button type="button" data-q="end">Add to end</button>' +
      '<button type="button" class="qp-cancel" data-q="cancel">Cancel</button>' +
      '</div></div>';
    document.body.appendChild(d);
    d.querySelectorAll('button').forEach(function(b){
      b.addEventListener('click', function(){
        var how = b.getAttribute('data-q');
        d.remove();
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
    video.src = item.web;
    video.load();
    if(!wasPaused){ video.play().catch(function(){}); }
    // highlight the matching playlist row if one exists
    var li = document.querySelector('.player-playlist .pl-item[data-video-id="' + item.id + '"]');
    if(li){
      document.querySelectorAll('.player-playlist .pl-item').forEach(function(x){ x.classList.remove('active'); });
      li.classList.add('active');
    }
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
    if(!queue.length){
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
    var wrap = document.createElement('li');
    wrap.className = 'pl-item pl-queued-sep';
    wrap.innerHTML = '<div class="pl-title" style="font-weight:600;">Up next</div>';
    ul.appendChild(wrap);
    queue.forEach(function(item){
      var li = document.createElement('li');
      li.className = 'pl-item';
      li.setAttribute('data-queued', '1');
      li.setAttribute('data-video-id', item.id);
      li.innerHTML = '<a href="' + esc(item.url) + '">' +
        (item.thumb ? '<img src="' + esc(item.thumb) + '" alt="" loading="lazy">' : '') +
        '<span class="pl-title">' + esc(item.title) + '</span>' +
        '</a>';
      ul.appendChild(li);
      li.querySelector('a').addEventListener('click', function(e){
        e.preventDefault();
        var video = document.querySelector('#video-player-wrap video');
        if(video && item.web){ video.src = item.web; video.load(); video.play().catch(function(){}); }
      });
    });
    // auto-advance flows into queued rows via the playlist ended handler
  }

  loadQ();
  if(document.readyState !== 'loading'){ init(); } else { document.addEventListener('DOMContentLoaded', init); }
  window.addEventListener('load', function(){ init(); renderQueue(); });
  // Expose for the media-nav swap handler to re-render queued rows after a
  // prev/next navigation replaces the playlist aside.
  window.GalleryQueue = { render: renderQueue };
})();
