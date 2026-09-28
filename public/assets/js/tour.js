/* Interactive site tour: a small overlay that walks members through the
   main features. Shows on the first visit (everyone), is dismissible, and can
   be re-launched from the Settings page (localStorage flag). Steps that don't
   apply to the current page are skipped automatically. */
(function(){
  var SEEN = 'galleryTourSeen';

  var steps = [
    { title: 'Welcome', body: 'Take a quick tour of what the site has to offer. You can dismiss this anytime and replay it later from Settings.' },
    { sel: 'nav a[href*="/galleries"], a[href*="/galleries"]', title: 'Galleries', body: 'Browse every photo and video gallery here. Use the Images / Videos tabs to filter the grid.' },
    { sel: 'nav a[href*="/favorites"], a[href*="/favorites"]', title: 'Favorites', body: 'Mark your favourite categories and galleries so your home page is built around them.' },
    { sel: 'nav a[href*="/collections"], a[href*="/collections"]', title: 'Collections & playlists', body: 'Build collections of whole galleries and individual videos, then play a collection as a playlist in the video player.' },
    { sel: '.grid', title: 'Gallery grid', body: 'Every gallery and media item appears as a card. Click one to open it.' },
    { sel: '[data-lightbox]', title: 'Lightbox', body: 'Click an image to open the full-screen lightbox — zoom, pinch and switch to the full-size original.' },
    { sel: '#video-player-wrap, .gallery-player', title: 'Video player', body: 'Watch videos here with a custom control bar: speed, quality, full screen and picture-in-picture.' },
    { sel: '.player-keep', title: 'Keep PiP open', body: 'With "Keep open" checked (the default), the picture-in-picture window stays up after a video ends so you can replay it.' },
    { sel: '.player-playlist', title: 'Playlist', body: 'A collection\'s videos appear here with thumbnails and durations. The playing one is highlighted and the next starts automatically.' },
    { sel: '#browse-galleries-btn', title: 'Browse & queue', body: 'Open this panel to browse other galleries without leaving the player — your picture-in-picture keeps playing while you add videos to the queue.' },
    { sel: 'nav a[href*="/chat"], a[href*="/chat"]', title: 'Chat', body: 'Chat with the operator. Retrieval and fine-tuned AI modes answer from real conversations; operator mode waits for a human.' },
    { sel: 'nav a[href*="/membership"], a[href*="/membership"]', title: 'Membership', body: 'Review your membership tier, upgrade, or manage a trial.' },
    { sel: 'nav a[href*="/settings"], a[href*="/settings"]', title: 'Settings', body: 'Manage your profile, notifications, theme and more — and replay this tour any time.' }
  ];

  var idx = 0, list = [];

  function visible(){
    return steps.filter(function(s){ return !s.sel || document.querySelector(s.sel); });
  }

  function cleanup(){
    var el = document.getElementById('tour-card'); if(el) el.remove();
    var sp = document.getElementById('tour-spot'); if(sp) sp.remove();
  }

  function show(){
    cleanup();
    if(idx >= list.length || idx < 0){ finish(); return; }
    var step = list[idx];
    var target = step.sel ? document.querySelector(step.sel) : null;

    if(target){
      try{ target.scrollIntoView({block:'center', behavior:'smooth'}); }catch(e){}
      // Highlight ring around the target.
      var sp = document.createElement('div');
      sp.id = 'tour-spot';
      sp.className = 'tour-spot';
      document.body.appendChild(sp);
      var place = function(){
        var r = target.getBoundingClientRect();
        sp.style.top = (r.top - 6) + 'px';
        sp.style.left = (r.left - 6) + 'px';
        sp.style.width = (r.width + 12) + 'px';
        sp.style.height = (r.height + 12) + 'px';
      };
      place();
      window.addEventListener('scroll', place, {passive:true});
      window.addEventListener('resize', place);
      sp._place = place;
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
    // Position the card near the target (or top-center when no target).
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
    window.addEventListener('scroll', placeCard, {passive:true});
    window.addEventListener('resize', placeCard);
    card._place = placeCard;

    card.addEventListener('click', function(e){
      var b = e.target.closest('[data-t]');
      if(!b) return;
      var t = b.getAttribute('data-t');
      if(t === 'skip' || t === 'done'){ markSeen(); finish(); }
      else if(t === 'next'){ idx++; show(); }
      else if(t === 'back'){ idx--; show(); }
    });
  }

  function finish(){
    cleanup();
    // remove the transient listeners we added
    window.removeEventListener('scroll', cleanup); // no-op guard
  }

  function markSeen(){ try{ localStorage.setItem(SEEN, '1'); }catch(e){} }
  function clearSeen(){ try{ localStorage.removeItem(SEEN); }catch(e){} }

  function start(){
    if(location.pathname.indexOf('/admin') !== -1) return;
    if(location.search.indexOf('se=') !== -1) return;
    list = visible();
    if(list.length === 0) return;
    idx = 0;
    setTimeout(show, 600);
  }

  function esc(s){ return String(s==null?'':s).replace(/[&<>"]/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); }

  // Auto-show on first visit.
  var seen = false;
  try{ seen = localStorage.getItem(SEEN) === '1'; }catch(e){}
  if(!seen){
    if(document.readyState === 'loading'){
      document.addEventListener('DOMContentLoaded', start);
    } else {
      start();
    }
  }

  window.GalleryTour = { start: start, markSeen: markSeen, clearSeen: clearSeen };
})();