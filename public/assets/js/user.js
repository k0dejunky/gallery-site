/* Lightbox viewer */
(function(){
  var lb, lbImg, lbCaption, lbCounter, images=[], currentIdx=0;
  var touchStartX=0, touchStartY=0, touchStartDistance=0, touchScale=1;
  var touchMoved=false, pinchActive=false, suppressClickUntil=0, returnFocus=null;
  var historyKey='galleryLightbox';

  function create(){
    if(lb)return;
    lb=document.createElement('div');
    lb.className='se-lightbox';
    lb.setAttribute('role','dialog');
    lb.setAttribute('aria-modal','true');
    lb.setAttribute('aria-label','Image viewer');
    lb.innerHTML='<span class="lb-counter" aria-live="polite"></span><button class="lb-close" aria-label="Close image viewer">&times;</button><button class="lb-nav lb-prev" aria-label="Previous image">&#8249;</button><button class="lb-nav lb-next" aria-label="Next image">&#8250;</button><img src="" alt=""><div class="lb-caption"></div>';
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
      if(!pinchActive)lbImg.classList.toggle('zoomed');
    });
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
      out.push({src:el.getAttribute('data-lightbox'),caption:el.getAttribute('data-lightbox-caption')||el.alt||''});
    });
    return out;
  }

  function open(list,startIdx,opener){
    create();
    returnFocus=opener||document.activeElement;
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
      return;
    }
    if(!pinchActive&&Math.abs(e.touches[0].clientX-touchStartX)>10){e.preventDefault();touchMoved=true;}
  }
  function touchEnd(e){
    if(touchMoved)suppressClickUntil=Date.now()+500;
    if(!pinchActive&&touchMoved&&e.changedTouches.length){
      var dx=e.changedTouches[0].clientX-touchStartX,dy=e.changedTouches[0].clientY-touchStartY;
      if(Math.abs(dx)>=50&&Math.abs(dx)>Math.abs(dy)){navigate(dx<0?1:-1);}
    }
    if(e.touches.length===0){pinchActive=false;touchMoved=false;}
  }

  function show(){
    var m=images[currentIdx];
    lbImg.src=m.src;
    lbImg.decoding='async';
    lbImg.fetchPriority='high';
    lbImg.alt=m.caption||'';
    lbImg.classList.remove('zoomed');
    lbCaption.textContent=m.caption||'';
    lbCounter.textContent='Item '+(currentIdx+1)+' of '+images.length;
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
      if(state)state.textContent='Loading&hellip;';
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
        fetch('/gallery/favorites/galleries/'+galleryId+'/toggle',{
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

/* Flash auto-dismiss */
(function(){
  document.addEventListener('DOMContentLoaded',function(){
    document.querySelectorAll('.flash').forEach(function(el){
      setTimeout(function(){el.style.transition='opacity .4s';el.style.opacity='0';setTimeout(function(){el.remove()},400)},5000);
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
