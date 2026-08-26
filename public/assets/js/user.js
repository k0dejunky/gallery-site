/* Lightbox viewer */
(function(){
  document.addEventListener('DOMContentLoaded',function(){
    var control=document.querySelector('[data-grid-density]');
    if(!control)return;
    var current=document.documentElement.classList.contains('grid-density-compact')?'compact':'comfortable';
    control.value=current;
    control.addEventListener('change',function(){
      var value=control.value==='compact'?'compact':'comfortable';
      document.documentElement.classList.remove('grid-density-compact','grid-density-comfortable');
      document.documentElement.classList.add('grid-density-'+value);
      try{localStorage.setItem('galleryGridDensity',value);}catch(e){}
    });
  });
})();

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
      try{
        var saved=JSON.parse(localStorage.getItem(key)||'null');
        if(saved&&typeof saved.y==='number')window.setTimeout(function(){window.scrollTo(0,saved.y)},0);
        if(saved&&typeof saved.index==='number'&&progress)progress.textContent='Item '+(saved.index+1)+' of '+document.querySelectorAll('[data-gallery-thumb]').length;
      }catch(e){}
      gallery.querySelectorAll('a[href]').forEach(function(link){
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

/* Gallery display options toolbar */
(function(){
  var STORE_KEY='galleryDisplayPrefs';
  var grid=null,defaults={view:'grid',size:'md',masonry:false,perpage:24};

  function load(){
    try{return Object.assign({},defaults,JSON.parse(localStorage.getItem(STORE_KEY)||'{}'))}catch(e){return Object.assign({},defaults)}
  }
  function save(prefs){try{localStorage.setItem(STORE_KEY,JSON.stringify(prefs))}catch(e){}}

  function apply(prefs){
    if(!grid)return;
    document.documentElement.classList.remove('g-view-grid','g-view-list','g-view-compact','g-size-sm','g-size-md','g-size-lg','g-masonry');
    if(prefs.view==='list')document.documentElement.classList.add('g-view-list');
    else if(prefs.view==='compact')document.documentElement.classList.add('g-view-compact');
    else document.documentElement.classList.add('g-view-grid');
    document.documentElement.classList.add('g-size-'+prefs.size);
    if(prefs.masonry)document.documentElement.classList.add('g-masonry');
  }

  function syncButtons(prefs){
    var bar=document.getElementById('gDisplayBar');
    if(!bar)return;
    bar.querySelectorAll('.g-display-btn').forEach(function(btn){
      var key=btn.getAttribute('data-gd'),val=btn.getAttribute('data-val');
      if(!key||!val)return;
      if(key==='view')btn.classList.toggle('active',val===prefs.view);
      else if(key==='size')btn.classList.toggle('active',val===prefs.size);
      else if(key==='masonry')btn.classList.toggle('active',!!prefs.masonry&&val==='1');
      else if(key==='perpage')btn.classList.toggle('active',parseInt(val,10)===prefs.perpage);
    });
  }

  function updateSortLinks(prefs){
    var bar=document.getElementById('gDisplayBar');
    if(!bar)return;
    bar.querySelectorAll('.g-display-sort .g-display-btn').forEach(function(a){
      var href=a.getAttribute('href');if(!href)return;
      try{
        var u=new URL(href,location.origin);
        if(prefs.perpage>0)u.searchParams.set('per_page',prefs.perpage);
        else u.searchParams.delete('per_page');
        a.setAttribute('href',u.pathname+(u.search||''));
      }catch(e){}
    });
  }

  document.addEventListener('DOMContentLoaded',function(){
    grid=document.querySelector('.grid');if(!grid)return;
    var prefs=load();
    apply(prefs);syncButtons(prefs);updateSortLinks(prefs);

    var bar=document.getElementById('gDisplayBar');
    if(!bar)return;
    bar.addEventListener('click',function(e){
      var btn=e.target.closest('.g-display-btn[data-gd]');
      if(!btn||btn.tagName==='A')return;
      e.preventDefault();
      var key=btn.getAttribute('data-gd'),val=btn.getAttribute('data-val');
      var prefs=load();
      if(key==='view'){prefs.view=val;}
      else if(key==='size'){prefs.size=val;}
      else if(key==='masonry'){prefs.masonry=!prefs.masonry;}
      else if(key==='perpage'){
        prefs.perpage=parseInt(val,10);
        var u=new URL(location.href,location.origin);
        if(prefs.perpage>0)u.searchParams.set('per_page',prefs.perpage);
        else u.searchParams.delete('per_page');
        save(prefs);location.href=u.pathname+(u.search||'');return;
      }
      save(prefs);apply(prefs);syncButtons(prefs);updateSortLinks(prefs);
    });
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
