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

  function open(list,startIdx,opener){
    create();
    images=list;currentIdx=startIdx;returnFocus=opener||document.activeElement;
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
    document.querySelectorAll('img[loading="lazy"]').forEach(function(img){
      img.classList.add('loading','is-loading','media-skeleton');
      img.addEventListener('load',function(){img.classList.remove('loading','is-loading');img.classList.add('is-loaded')},{once:true});
      img.addEventListener('error',function(){img.classList.remove('loading','is-loading');img.classList.add('is-loaded')},{once:true});
      if(img.complete)img.classList.remove('loading','is-loading');
    });
    document.querySelectorAll('video[data-video-id]').forEach(function(video){initVideoResume(video)});
    var items=document.querySelectorAll('[data-lightbox]');
    if(!items.length)return;
    var list=[];
    items.forEach(function(el,i){
      list.push({src:el.getAttribute('data-lightbox'),caption:el.getAttribute('data-lightbox-caption')||el.alt||''});
      el.style.cursor='pointer';
       el.addEventListener('click',function(e){e.preventDefault();open(list,i,el)});
    });
    var gallery=document.getElementById('gallery'), progress=document.getElementById('gallery-progress');
    if(gallery){
      var key='gallery-position-'+location.pathname;
      var items=function(){return Array.prototype.slice.call(document.querySelectorAll('[data-gallery-index]'));};
      try{
        var saved=JSON.parse(localStorage.getItem(key)||'null');
        if(saved&&saved.index!=null){
          // Scroll to the exact item the member was viewing (element-based so
          // it stays accurate even while lazy thumbnails above are still
          // loading and reshuffling layout height).
          var list=items();
          if(list[saved.index]){
            var target=list[saved.index];
            window.setTimeout(function(){target.scrollIntoView({block:'start'});},0);
          }else if(typeof saved.y==='number'){
            window.scrollTo(0,saved.y);
          }
          if(progress)progress.textContent='Item '+(saved.index+1)+' of '+list.length;
        }else if(saved&&typeof saved.y==='number'){
          window.scrollTo(0,saved.y);
        }
      }catch(e){}
      gallery.querySelectorAll('a[href][data-gallery-index]').forEach(function(link){
        link.addEventListener('click',function(){
          var index=parseInt(link.getAttribute('data-gallery-index')||'',10);
          if(!isFinite(index))return;
          try{localStorage.setItem(key,JSON.stringify({index:index,y:window.scrollY}))}catch(e){}
        });
      });
      document.querySelectorAll('[data-gallery-thumb]').forEach(function(thumb){
        thumb.addEventListener('click',function(){
          var index=parseInt(thumb.getAttribute('data-gallery-thumb'),10)||0;
          try{localStorage.setItem(key,JSON.stringify({index:index,y:window.scrollY}))}catch(e){}
          if(progress)progress.textContent='Item '+(index+1)+' of '+document.querySelectorAll('[data-gallery-thumb]').length;
          document.querySelectorAll('[data-gallery-thumb]').forEach(function(item){item.removeAttribute('aria-current')});
          thumb.setAttribute('aria-current','page');
        });
      });
    }
  });

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
