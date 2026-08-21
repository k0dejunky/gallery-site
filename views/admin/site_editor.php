<?php $title = 'Site Editor'; ?>
<style>
  #se-root{position:fixed!important;inset:0!important;width:100vw!important;height:100vh!important;margin:0!important;z-index:100000;background:#111;overflow:hidden!important}
  #se-root>div:nth-child(1),#se-root>div:nth-child(3){position:absolute!important;top:12px;bottom:12px;z-index:100002;box-shadow:0 8px 30px rgba(0,0,0,.45)}
  #se-root>div:nth-child(1){left:12px}
  #se-root>div:nth-child(3){right:12px}
  #se-root>div:nth-child(2){position:absolute!important;inset:0!important;width:auto!important;z-index:100001}
  #se-left-head,#se-right-head{cursor:move;user-select:none}
  #se-back{background:#7040a0;border:1px solid #8c5dc0;color:#fff;border-radius:3px;padding:4px 8px;font-size:10px;cursor:pointer}
  #se-back:hover{background:#8554b5}
  .se-tool{flex:1;padding:5px 4px;font-size:10px;background:#2a2a2a;border:1px solid #444;border-radius:3px;color:#aaa;cursor:pointer;text-align:center}
  .se-tool:hover{background:#3a3a3a;color:#ddd}
  .se-tool.active{background:#5b8af5;color:#fff;border-color:#4a7ae8}
  .se-btn-p{background:#5b8af5;color:#fff;border:none;border-radius:3px;padding:5px 8px;font-size:11px;cursor:pointer}
  .se-btn-p:hover{background:#4a7ae8}
  .se-btn-d{background:#c04040;color:#fff;border:none;border-radius:3px;padding:5px 8px;font-size:11px;cursor:pointer}
  .se-btn-d:hover{background:#a83535}
  .se-btn-g{background:#40a040;color:#fff;border:none;border-radius:3px;padding:5px 8px;font-size:11px;cursor:pointer}
  .se-btn-g:hover{background:#358a35}
  .se-ci{display:flex;align-items:center;gap:4px;padding:4px 6px;border-radius:3px;cursor:pointer;font-size:11px;margin-bottom:2px}
  .se-ci:hover{background:#333}
  .se-ct{font-size:9px;font-weight:700;padding:1px 4px;border-radius:2px;text-transform:uppercase;flex-shrink:0}
  .se-ct.hide{background:#e8a840;color:#1e1e1e}.se-ct.delete{background:#c04040;color:#fff}.se-ct.move{background:#5b8af5;color:#fff}.se-ct.add{background:#40a040;color:#fff}
  .se-cl{flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#bbb}
  .se-x{background:none;border:none;color:#888;cursor:pointer;font-size:13px;padding:0 2px}.se-x:hover{color:#e8a840}
  .se-show{background:#357a52;border:none;border-radius:2px;color:#fff;cursor:pointer;font-size:9px;padding:2px 4px}.se-show:hover{background:#459966}
  .se-ti{padding:6px 8px;border-radius:4px;cursor:pointer;margin-bottom:4px;border:1px solid #333;background:#252526}
  .se-ti:hover{border-color:#5b8af5}.se-tp{border-color:#40a040;background:#1a2a1a}
  .se-tn{font-size:12px;font-weight:600;color:#ddd}.se-tm{font-size:10px;color:#888;margin-top:2px}
  .se-ta{display:flex;gap:4px;margin-top:4px}.se-ta button{font-size:10px;padding:2px 6px}
  #se-root input,#se-root select{background:#2a2a2a;border:1px solid #444;color:#ddd;border-radius:3px;padding:4px 6px;font-size:11px}
  #se-root input:focus,#se-root select:focus{outline:1px solid #5b8af5}
  #se-root input::placeholder{color:#666}
  #se-hl{position:absolute;pointer-events:none;background:rgba(91,138,245,.15);border:2px solid #5b8af5;z-index:99999;display:none;transition:none}
  #se-sel{position:absolute;pointer-events:none;background:rgba(232,168,64,.1);border:2px solid #e8a840;z-index:99999;display:none;transition:none}
  .se-scope-tab{flex:1;padding:5px 4px;font-size:10px;background:#2a2a2a;border:1px solid #444;border-radius:3px;color:#aaa;cursor:pointer;text-align:center}
  .se-scope-tab:hover{background:#3a3a3a;color:#ddd}
  .se-scope-tab.active{background:#40a040;color:#fff;border-color:#358a35}
</style>
<div id="se-root" style="display:flex;height:calc(100vh - 40px);gap:0;overflow:hidden;margin:-1rem -1.5rem;">
  <!-- Left Panel -->
  <div id="se-left-panel" style="width:220px;flex-shrink:0;background:#1e1e1e;color:#ccc;display:flex;flex-direction:column;border-right:1px solid #333;">
    <div id="se-left-head" style="padding:8px 12px;background:#252526;border-bottom:1px solid #333;font-weight:700;font-size:13px;display:flex;justify-content:space-between;align-items:center;">
      <span>Site Editor</span>
      <span style="display:flex;align-items:center;gap:5px;"><button id="se-back" type="button">Back to Admin</button><button id="se-refresh" title="Reload site" style="background:none;border:none;color:#aaa;cursor:pointer;font-size:16px;">&#8635;</button></span>
    </div>
    <div style="padding:6px 12px;border-bottom:1px solid #333;display:flex;gap:4px;">
      <button class="se-scope-tab<?= $scope==='user'?' active':'' ?>" data-scope="user">User Site</button>
      <button class="se-scope-tab<?= $scope==='admin'?' active':'' ?>" data-scope="admin">Admin Site</button>
    </div>
    <div style="padding:6px 12px;border-bottom:1px solid #333;display:flex;gap:4px;flex-wrap:wrap;">
      <button class="se-tool active" data-mode="select">Select</button>
      <button class="se-tool" data-mode="hide">Hide</button>
      <button class="se-tool" data-mode="delete">Delete</button>
      <button class="se-tool" data-mode="move">Move</button>
    </div>
    <div style="padding:6px 12px;border-bottom:1px solid #333;">
      <div style="font-size:10px;color:#888;text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px;">Add Element</div>
      <select id="se-add-type" style="width:100%;margin-bottom:4px;">
        <option value="">-- choose --</option>
        <option value="section">Section</option>
        <option value="card">Card</option>
        <option value="text">Text Block</option>
        <option value="heading">Heading</option>
        <option value="button">Button</option>
        <option value="link">Text Link</option>
        <option value="list">List</option>
        <option value="callout">Callout</option>
        <option value="divider">Divider</option>
        <option value="spacer">Spacer</option>
        <option value="image">Image</option>
        <option value="video">Video</option>
        <option value="html">Custom HTML</option>
      </select>
      <input id="se-add-title" placeholder="Title (optional)" style="width:100%;margin-bottom:4px;" />
      <textarea id="se-add-content" placeholder="Content / text (one item per line for lists)" rows="3" style="width:100%;margin-bottom:4px;resize:vertical;"></textarea>
      <input id="se-add-url" placeholder="URL (link/button)" style="width:100%;margin-bottom:4px;" />
      <input id="se-add-class" placeholder="CSS class (optional)" style="width:100%;margin-bottom:4px;" />
      <select id="se-add-position" style="width:100%;margin-bottom:4px;">
        <option value="append">Place inside target</option>
        <option value="prepend">Place at start of target</option>
        <option value="before">Place before target</option>
        <option value="after">Place after target</option>
      </select>
      <button id="se-btn-add" class="se-btn-p" style="width:100%;">+ Add</button>
    </div>
    <div style="flex:1;overflow-y:auto;padding:6px 12px;">
      <div style="font-size:10px;color:#888;text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px;">Changes (<span id="se-cc">0</span>)</div>
      <div id="se-cl"></div>
    </div>
    <div style="padding:6px 12px;border-top:1px solid #333;display:flex;gap:4px;">
      <button id="se-undo" style="flex:1;" disabled>Undo</button>
      <button id="se-clear" style="flex:1;" class="se-btn-d">Clear All</button>
    </div>
  </div>

  <!-- Center: iframe -->
  <div id="se-frame-wrap" style="flex:1;position:relative;overflow:hidden;">
    <iframe id="se-iframe" src="<?= e($siteUrl) ?><?= $scope==='admin'?'?se=admin':'?se=user' ?>" style="width:100%;height:100%;border:none;"></iframe>
    <div id="se-hl"></div>
    <div id="se-sel"></div>
    <div id="se-msg" style="position:absolute;bottom:0;left:0;right:0;padding:4px 12px;background:rgba(30,30,30,.9);color:#aaa;font-size:11px;border-top:1px solid #333;display:none;"></div>
    <div id="se-status" style="position:absolute;top:4px;left:4px;padding:3px 8px;background:rgba(30,30,30,.85);color:#e8a840;font-size:10px;border-radius:3px;z-index:100000;font-family:monospace;">connecting...</div>
  </div>

  <!-- Right Panel -->
  <div id="se-right-panel" style="width:240px;flex-shrink:0;background:#1e1e1e;color:#ccc;display:flex;flex-direction:column;border-left:1px solid #333;">
    <div id="se-right-head" style="padding:8px 12px;background:#252526;border-bottom:1px solid #333;font-weight:700;font-size:13px;">Templates</div>
    <div style="padding:6px 12px;border-bottom:1px solid #333;">
      <input id="se-tn" placeholder="Template name" style="width:100%;margin-bottom:4px;" />
      <input id="se-td" placeholder="Description (optional)" style="width:100%;margin-bottom:4px;" />
      <button id="se-save" class="se-btn-g" style="width:100%;">Save as Template</button>
    </div>
    <div style="flex:1;overflow-y:auto;padding:6px 12px;" id="se-tpl-list"></div>
    <div style="padding:6px 12px;border-top:1px solid #333;">
      <div id="se-active" style="font-size:11px;color:#888;text-align:center;"></div>
    </div>
  </div>
</div>

<script>
(function(){
var CSRF='<?= \App\Core\Csrf::token() ?>';
var currentScope='<?= e($scope) ?>';
var iframe=document.getElementById('se-iframe');
var wrap=document.getElementById('se-frame-wrap');
var hlEl=document.getElementById('se-hl');
var selEl=document.getElementById('se-sel');
var iDoc=null;
var iBody=null;
var changes=[];
var mode='select';
var selectedIframeEl=null;
var highlightIframeEl=null;
var addDraft=null;
var justDropped=false;
var moveKeySeq=0;
var moveParents=[];
var templates=<?= json_encode($templates) ?>;
var activeTpl=<?= json_encode($activeTemplate) ?>;
if(activeTpl&&activeTpl.config_json){try{changes=JSON.parse(activeTpl.config_json);if(!Array.isArray(changes))changes=[];}catch(e){changes=[];}}

function buildSelector(el){
  if(!el||!iBody||el===iBody||el===iDoc.documentElement)return null;
  var parts=[];
  var cur=el;
  while(cur&&cur!==iBody&&cur!==iDoc.documentElement){
    var tag=cur.tagName.toLowerCase();
    var part=tag;
    if(cur.id){part='#'+cur.id;parts.unshift(part);break;}
    if(tag==='a'&&cur.getAttribute('href')){
      var anchorClass=cur.className&&typeof cur.className==='string'?cur.className.trim().split(/\s+/).filter(function(c){return c&&c.indexOf('se-')<0;}).slice(0,1).join('.') : '';
      part='a'+(anchorClass?'.'+anchorClass:'')+'[href="'+cur.getAttribute('href').replace(/"/g,'\\"')+'"]';
      parts.unshift(part);break;
    }
    if(cur.className&&typeof cur.className==='string'){
      var cls=cur.className.trim().split(/\s+/).filter(function(c){return c&&c.indexOf('se-')<0&&c.indexOf('nle-')<0}).slice(0,2).join('.');
      if(cls)part+='.'+cls;
    }
    var p=cur.parentElement;
    if(p){
      var sibs=Array.prototype.filter.call(p.children,function(c){return c.tagName===cur.tagName});
      if(sibs.length>1){var idx=sibs.indexOf(cur)+1;part+=':nth-of-type('+idx+')';}
    }
    parts.unshift(part);
    cur=cur.parentElement;
  }
  return parts.join(' > ');
}
function moveMeta(el){
  var key=el.getAttribute('data-se-move-key'),origin=el.getAttribute('data-se-move-origin');
  if(!key){key='m'+(++moveKeySeq);el.setAttribute('data-se-move-key',key);}
  if(!origin){origin=buildSelector(el);el.setAttribute('data-se-move-origin',origin||'');}
  return{key:key,origin:origin};
}
function captureVisualStyle(el){
  var cs=iDoc.defaultView.getComputedStyle(el),props=['padding','font','font-size','font-family','font-weight','line-height','letter-spacing','color','background','background-color','background-image','border','border-radius','box-shadow','text-align','text-decoration','text-shadow','fill','stroke','outline','opacity'];
  var out={};props.forEach(function(p){out[p]=cs.getPropertyValue(p);});
  for(var i=0;i<cs.length;i++){var name=cs[i];if(name.indexOf('--')===0)out[name]=cs.getPropertyValue(name);}
  return out;
}
function restoreVisualStyle(el,styles){
  Object.keys(styles||{}).forEach(function(p){if(styles[p])el.style.setProperty(p,styles[p]);});
}
function snapshotOrders(doc,styleKey,styleValue){
  var parents=moveParents.slice();
  return parents.map(function(p){
    var pm=p===iBody?{key:'body',origin:'body'}:moveMeta(p);
    var parentOrigin=p===iBody?'body':(buildSelector(p)||pm.origin);
    return{type:'order',parentKey:pm.key,parentOrigin:parentOrigin,items:Array.prototype.map.call(p.children,function(child){var m=moveMeta(child);return{key:m.key,origin:buildSelector(child)||m.origin,styles:m.key===styleKey?styleValue:null};})};
  });
}
function applyOrderOperation(c,doc){
   var p=c.parentKey==='body'?doc.body:null;
  if(c.parentOrigin){p=doc.querySelector(c.parentOrigin)||p;if(p&&c.parentKey)p.setAttribute('data-se-move-key',c.parentKey);}
  if(!p)return;
   var items=(c.items||[]).map(function(item){var el=item.origin?doc.querySelector(item.origin):null;if(!el)el=doc.querySelector('[data-se-move-key="'+item.key+'"]');if(el&&item.key)el.setAttribute('data-se-move-key',item.key);if(el&&item.styles)restoreVisualStyle(el,item.styles);return el;}).filter(Boolean);
  items.forEach(function(el){p.appendChild(el);});
}

function getElLabel(el){
  if(!el)return'';
  var tag=el.tagName?el.tagName.toLowerCase():'?';
  var id=el.id?'#'+el.id:'';
  var cls='';
  if(el.className&&typeof el.className==='string'){
    cls=el.className.trim().split(/\s+/).filter(function(c){return c&&c.indexOf('se-')<0}).slice(0,2).join('.');
    if(cls)cls='.'+cls;
  }
  var txt=(el.textContent||'').trim().substring(0,30);
  return(tag+id+cls+(txt?' "'+txt+'"':''));
}

function scrollIntoViewIframe(el){
  if(!el||!iframe.contentWindow)return;
  try{
    var r=el.getBoundingClientRect();
    var wh=iframe.contentWindow.innerHeight;
    if(r.top<0||r.bottom>wh)el.scrollIntoView({behavior:'smooth',block:'center'});
  }catch(e){}
}

var INLINE_TAGS={A:1,SPAN:1,STRONG:1,EM:1,B:1,I:1,SMALL:1,S:1,SUB:1,SUP:1,MARK:1,CODE:1,ABBR:1,TIME:1,IMG:1};
function findContainer(el){
  if(!el||el===iBody||el===iDoc.documentElement)return el;
  var cur=el;
  while(cur&&cur!==iBody&&cur!==iDoc.documentElement){
    var tag=cur.tagName;
    var cs=getComputedStyle(cur);
    var display=cs.display;
    if(display==='block'||display==='flex'||display==='grid'||display==='table'||display==='list-item'||display==='table-cell'){
      var r=cur.getBoundingClientRect();
      if(r.width>30&&r.height>20)return cur;
    }
    if(!INLINE_TAGS[tag]&&cur.children&&cur.children.length>0){
      var r=cur.getBoundingClientRect();
      if(r.width>80&&r.height>40)return cur;
    }
    cur=cur.parentElement;
  }
  return el;
}

var iframeRectCache=null;
var iframeRectCacheTime=0;
function updateIframeRect(){
  if(!iframe.contentDocument)return;
  iframeRectCache=iframe.getBoundingClientRect();
  iframeRectCacheTime=Date.now();
}
function getIframeRect(){
  if(!iframeRectCache||Date.now()-iframeRectCacheTime>500){
    updateIframeRect();
  }
  return iframeRectCache;
}
function positionOverlay(overlayEl,targetEl){
  if(!targetEl||!iframe.contentDocument)return;
  if(overlayEl._raf){cancelAnimationFrame(overlayEl._raf);}
  overlayEl._raf=requestAnimationFrame(function(){
    try{
      var r=targetEl.getBoundingClientRect();
      var iRect=getIframeRect();
      var wRect=wrap.getBoundingClientRect();
      var x=iRect.left+r.left-wRect.left;
      var y=iRect.top+r.top-wRect.top;
      overlayEl.style.display='block';
      overlayEl.style.transform='translate('+x+'px,'+y+'px)';
      overlayEl.style.width=r.width+'px';
      overlayEl.style.height=r.height+'px';
    }catch(e){overlayEl.style.display='none';}
  });
}

function clearHighlights(){
  if(highlightIframeEl){try{highlightIframeEl.style.removeProperty('outline');highlightIframeEl.style.removeProperty('outline-offset');}catch(e){}highlightIframeEl=null;}
  hlEl.style.display='none';
}

function selectElement(el){
  if(selectedIframeEl){try{selectedIframeEl.style.removeProperty('outline');selectedIframeEl.style.removeProperty('outline-offset');}catch(e){}}
  selectedIframeEl=el;
  if(el){positionOverlay(selEl,el);}else{selEl.style.display='none';}
  sendState();
}

function applyMoveOperation(c,doc){
  if(!c||c.type!=='move'||!doc)return;
  var el=c.key?doc.querySelector('[data-se-move-key="'+c.key+'"]'):null;
  if(!el&&c.origin){el=doc.querySelector(c.origin);if(el&&c.key)el.setAttribute('data-se-move-key',c.key);}
  if(!el)el=doc.querySelector(c.selector);if(!el)return;
  if(c.anchor){
    var anchor=c.anchorKey?doc.querySelector('[data-se-move-key="'+c.anchorKey+'"]'):null;
    if(!anchor&&c.anchorOrigin){anchor=doc.querySelector(c.anchorOrigin);if(anchor&&c.anchorKey)anchor.setAttribute('data-se-move-key',c.anchorKey);}
    if(!anchor)anchor=doc.querySelector(c.anchor);if(!anchor||anchor===el||el.contains(anchor))return;
    if(c.position==='before')anchor.parentNode.insertBefore(el,anchor);
    else anchor.parentNode.insertBefore(el,anchor.nextSibling);
  }else if(c.position==='append'){
    var parent=c.parent==='body'?doc.body:doc.querySelector(c.parent);
    if(parent)parent.appendChild(el);
  }
}

function deselectAll(){
  if(selectedIframeEl){try{selectedIframeEl.style.removeProperty('outline');selectedIframeEl.style.removeProperty('outline-offset');}catch(e){}}
  selectedIframeEl=null;selEl.style.display='none';
  clearHighlights();sendState();
}

function applyChanges(){
  if(!iBody)return;
   changes.forEach(function(c){
    try{
       if(c.type==='order'){applyOrderOperation(c,iDoc);return;}
       var el=c.key?iDoc.querySelector('[data-se-move-key="'+c.key+'"]'):null;
       if(!el&&c.origin){el=iDoc.querySelector(c.origin);if(el&&c.key)el.setAttribute('data-se-move-key',c.key);}
       if(!el)el=iDoc.querySelector(c.selector);
      if(!el)return;
      if(c.type==='hide'||c.type==='delete')el.style.display='none';
       else if(c.type==='move'){
         if(c.anchor||c.parent){applyMoveOperation(c,iDoc);return;}
         var vw=iDoc.documentElement.clientWidth||iBody.clientWidth||1,vh=iDoc.documentElement.clientHeight||iBody.clientHeight||1;
         var rect=el.getBoundingClientRect();
         var mx=c.targetXRatio!=null?c.targetXRatio*vw-rect.left:(c.dxRatio!=null?c.dxRatio*vw:(c.dx||0));
         var my=c.targetYRatio!=null?c.targetYRatio*vh-rect.top:(c.dyRatio!=null?c.dyRatio*vh:(c.dy||0));
         el.style.setProperty('transform','translate('+mx+'px,'+my+'px)','important');
       }
      else if(c.type==='restyle')Object.keys(c.styles||{}).forEach(function(k){el.style[k]=c.styles[k]});
      else if(c.type==='add'){
        var target=c.parent?iDoc.querySelector(c.parent):iBody;
        if(target){
          var d=iDoc.createElement(c.tag||'div');d.className='se-added-element';d.setAttribute('data-se-added','1');
          d.innerHTML=c.html||'';if(c.styles)Object.keys(c.styles).forEach(function(k){d.style[k]=c.styles[k]});
          if(c.position==='prepend')target.prepend(d);else if(c.position==='before')target.parentElement.insertBefore(d,target);
          else if(c.position==='after')target.parentElement.insertBefore(d,target.nextSibling);else target.appendChild(d);
        }
      }
    }catch(e){}
  });
}

function sendState(){
   var sel=selectedIframeEl?{selector:buildSelector(selectedIframeEl),label:getElLabel(selectedIframeEl)}:null;
   window.parent.postMessage({type:'se-state',state:{selected:sel,changes:changes.map(function(c){
     var el=iDoc&&c.selector?iDoc.querySelector(c.selector):null;
    return{type:c.type,selector:c.selector,label:el?getElLabel(el):c.selector,dx:c.dx,dy:c.dy,dxRatio:c.dxRatio,dyRatio:c.dyRatio,targetXRatio:c.targetXRatio,targetYRatio:c.targetYRatio,styles:c.styles,html:c.html,tag:c.tag,parent:c.parent,position:c.position};
  })}},'*');
}

/* ── Event handlers on iframe document ── */
function attachHandlers(){
  if(!iDoc||!iBody)return;

  iDoc.addEventListener('mouseover',function(e){
    if(mode!=='select'||!iBody)return;
    clearHighlights();
    var target=findContainer(e.target);
    highlightIframeEl=target;
    positionOverlay(hlEl,target);
  },true);

  iDoc.addEventListener('mouseout',function(e){
    if(mode!=='select')return;
    if(highlightIframeEl===e.target){clearHighlights();}
  },true);

  // Invalidate iframe rect cache on scroll/resize
  iDoc.addEventListener('scroll',function(){iframeRectCache=null;},true);
  iDoc.defaultView.addEventListener('resize',function(){iframeRectCache=null;});

  iDoc.addEventListener('pointerdown',function(e){
    if(!iBody)return;
    if(justDropped){justDropped=false;e.preventDefault();e.stopPropagation();return;}
    var el=e.target;
    if(mode==='select'){
      e.preventDefault();e.stopPropagation();
      var target=findContainer(el);
      selectElement(target);
      if(target)scrollIntoViewIframe(target);
    }else if(mode==='hide'){
      e.preventDefault();e.stopPropagation();
      var target=findContainer(el);
      var s=buildSelector(target);
      if(s){
        var idx=-1;for(var i=0;i<changes.length;i++){if(changes[i].selector===s&&changes[i].type==='hide'){idx=i;break;}}
        if(idx>=0){changes.splice(idx,1);target.style.removeProperty('display');}
        else{changes.push({type:'hide',selector:s});target.style.display='none';}
        sendState();renderChanges();
      }
    }else if(mode==='delete'){
      e.preventDefault();e.stopPropagation();
      var target=findContainer(el);
      var s=buildSelector(target);
      if(s){changes.push({type:'delete',selector:s});target.style.display='none';deselectAll();renderChanges();}
    }else if(mode==='move'){
      e.preventDefault();e.stopPropagation();
      var target=findContainer(el);
      var s=buildSelector(target);
      if(!s)return;
       var originalParent=target.parentElement,originalNext=target.nextElementSibling;
       var moved=false;
       moveParents=[originalParent];
       Array.prototype.forEach.call(originalParent.children,function(child){moveMeta(child);});
       if(originalParent!==iBody)moveMeta(originalParent);
        var originalCss=target.getAttribute('style')||'',visualStyle=captureVisualStyle(target),rect=target.getBoundingClientRect();
       var grabX=e.clientX-rect.left,grabY=e.clientY-rect.top;
       selectElement(target);
       var placeholder=iDoc.createElement('div');
       placeholder.className='se-drag-placeholder';
       placeholder.style.width=rect.width+'px';placeholder.style.height=rect.height+'px';
       placeholder.style.flex='0 0 '+rect.width+'px';placeholder.style.visibility='hidden';placeholder.style.pointerEvents='none';
       originalParent.replaceChild(placeholder,target);
       var ghost=target.cloneNode(true);
       iBody.appendChild(ghost);
       ghost.style.position='fixed';ghost.style.left=rect.left+'px';ghost.style.top=rect.top+'px';
       ghost.style.width=rect.width+'px';ghost.style.height=rect.height+'px';ghost.style.margin='0';
       ghost.style.zIndex='100000';ghost.style.opacity='.55';ghost.style.pointerEvents='none';ghost.style.cursor='grabbing';ghost.style.transform='none';ghost.style.transition='none';ghost.style.boxSizing='border-box';
       function onMove(ev){
         ghost.style.left=ev.clientX-grabX+'px';ghost.style.top=ev.clientY-grabY+'px';
         positionOverlay(selEl,ghost);
         moved=true;
          var hit=iDoc.elementFromPoint(ev.clientX,ev.clientY);
          var candidate=findContainer(hit);
          if(!candidate||candidate===target||candidate===placeholder||target.contains(candidate))return;
          var canContain=candidate!==iBody&&candidate.children&&candidate.children.length>0&&/^(DIV|SECTION|MAIN|ASIDE|NAV|HEADER|FOOTER|UL|OL)$/.test(candidate.tagName);
          var dropParent=canContain?candidate:candidate.parentElement;
          if(!dropParent)return;
          Array.prototype.forEach.call(dropParent.children,function(child){if(child!==placeholder)moveMeta(child);});
          if(dropParent!==iBody)moveMeta(dropParent);
          if(moveParents.indexOf(dropParent)<0)moveParents.push(dropParent);
          if(canContain){
            // Dropping on a real container reparents the element into it;
            // use the hovered direct child as the insertion point when there is one.
            var insertBefore=null;
            var direct=hit;
            while(direct&&direct.parentElement!==candidate)direct=direct.parentElement;
            if(direct&&direct!==target)insertBefore=direct;
            if(insertBefore)dropParent.insertBefore(placeholder,insertBefore);
            else dropParent.appendChild(placeholder);
          }else{
            var candidateRect=candidate.getBoundingClientRect();
            var row=getComputedStyle(dropParent).display==='flex'&&getComputedStyle(dropParent).flexDirection==='row';
            var before=row?ev.clientX<candidateRect.left+candidateRect.width/2:ev.clientY<candidateRect.top+candidateRect.height/2;
            if(before)dropParent.insertBefore(placeholder,candidate);
            else dropParent.insertBefore(placeholder,candidate.nextSibling);
          }
      }
      function onUp(ev){
        iDoc.removeEventListener('pointermove',onMove);
        iDoc.removeEventListener('pointerup',onUp);
        iDoc.removeEventListener('pointercancel',onUp);
         ghost.remove();
         if(moved){
             if(placeholder.parentNode)placeholder.parentNode.insertBefore(target,placeholder);
            placeholder.remove();
            target.setAttribute('style',originalCss);
            restoreVisualStyle(target,visualStyle);
            var movedMeta=moveMeta(target),orders=snapshotOrders(iDoc,movedMeta.key,visualStyle),orderParents=orders.map(function(c){return c.parentKey;});
            changes=changes.filter(function(c){return c.type!=='move'&&!(c.type==='order'&&orderParents.indexOf(c.parentKey)>=0);});
            changes=changes.concat(orders);
        }else if(originalParent){originalParent.insertBefore(target,originalNext);placeholder.remove();}
        positionOverlay(selEl,target);
        justDropped=true;setTimeout(function(){justDropped=false;},200);
        sendState();renderChanges();
      }
      iDoc.addEventListener('pointermove',onMove);
      iDoc.addEventListener('pointerup',onUp);
      iDoc.addEventListener('pointercancel',onUp);
    }else if(mode==='add-place'&&addDraft){
      e.preventDefault();e.stopPropagation();
      var addTarget=findContainer(el),s=addTarget===iBody?'body':buildSelector(addTarget);
      if(s){
        var target=s==='body'?iBody:iDoc.querySelector(s);
        if(target){
          var d=iDoc.createElement(addDraft.tag);d.className='se-added-element';d.setAttribute('data-se-added','1');
          d.innerHTML=addDraft.html;if(addDraft.styles)Object.keys(addDraft.styles).forEach(function(k){d.style[k]=addDraft.styles[k]});
          var pos=addDraft.position||'append';
          if(pos==='prepend')target.prepend(d);else if(pos==='before')target.parentElement.insertBefore(d,target);
          else if(pos==='after')target.parentElement.insertBefore(d,target.nextSibling);else target.appendChild(d);
          changes.push({type:'add',tag:addDraft.tag,html:addDraft.html,parent:s,position:pos,styles:addDraft.styles});
          addDraft=null;mode='select';updateModeButtons();
          sendState();renderChanges();showMsg('Element placed');
        }
      }
    }
  },true);

  iDoc.addEventListener('keydown',function(e){
    if(e.key==='Escape')deselectAll();
    if((e.key==='Delete'||e.key==='Backspace')&&selectedIframeEl&&!e.target.closest('input,textarea,select')){
      e.preventDefault();
      var s=buildSelector(selectedIframeEl);
      if(s){changes.push({type:'delete',selector:s});selectedIframeEl.style.display='none';deselectAll();renderChanges();}
    }
  },true);

  return true;
}

/* ── Scope tabs ── */
document.querySelectorAll('.se-scope-tab').forEach(function(btn){
  btn.addEventListener('click',function(){
    var newScope=btn.dataset.scope;
    if(newScope===currentScope)return;
    if(changes.length&&!confirm('Unsaved changes will be lost. Switch scope?'))return;
    window.location.href='<?= url('/admin/site-editor') ?>?scope='+newScope;
  });
});

/* ── Mode buttons ── */
function updateModeButtons(){
  document.querySelectorAll('.se-tool').forEach(function(b){b.classList.toggle('active',b.dataset.mode===mode);});
}
document.querySelectorAll('.se-tool').forEach(function(btn){
  btn.addEventListener('click',function(){mode=btn.dataset.mode;updateModeButtons();deselectAll();});
});

/* ── Refresh ── */
 function reloadPreview(){
   handlersAttached=false;iDoc=null;iBody=null;iframe.src=iframe.src;
 }
 document.getElementById('se-refresh').addEventListener('click',reloadPreview);
document.getElementById('se-back').addEventListener('click',function(){window.location.href='<?= url('/admin') ?>';});
function makePanelDraggable(panel,handle){
  handle.addEventListener('pointerdown',function(e){
    if(e.target.closest('button,a,input,select'))return;
    e.preventDefault();
    var rect=panel.getBoundingClientRect(),ox=e.clientX-rect.left,oy=e.clientY-rect.top;
    panel.style.right='auto';panel.style.bottom='auto';panel.style.left=rect.left+'px';panel.style.top=rect.top+'px';
    function move(ev){panel.style.left=Math.max(0,ev.clientX-ox)+'px';panel.style.top=Math.max(0,ev.clientY-oy)+'px';}
    function up(){document.removeEventListener('pointermove',move);document.removeEventListener('pointerup',up);}
    document.addEventListener('pointermove',move);document.addEventListener('pointerup',up);
  });
}
makePanelDraggable(document.getElementById('se-left-panel'),document.getElementById('se-left-head'));
makePanelDraggable(document.getElementById('se-right-panel'),document.getElementById('se-right-head'));

/* ── Add element ── */
document.getElementById('se-btn-add').addEventListener('click',function(){
  if(!iDoc||!iBody){showMsg('Preview is still loading. Try again when it shows CONNECTED.');return;}
  var type=document.getElementById('se-add-type').value;
  var title=document.getElementById('se-add-title').value;
  var content=document.getElementById('se-add-content').value;
  var url=document.getElementById('se-add-url').value;
  var customClass=document.getElementById('se-add-class').value.trim().replace(/[^a-zA-Z0-9_ -]/g,'');
  var position=document.getElementById('se-add-position').value;
  if(!type)return;
  var tag='div',html='',styles={},cls=customClass?(' class="'+escH(customClass)+'"'):'';
  if(type==='section'){html='<section'+cls+' style="padding:16px;margin:12px 0;"><h2>'+escH(title||'Section')+'</h2><div>'+escH(content||'Section content')+'</div></section>';}
  else if(type==='card'){html='<div class="card'+(customClass?' '+escH(customClass): '')+'" style="padding:16px;margin:12px 0;"><h3>'+escH(title||'Card')+'</h3><p>'+escH(content||'Card content')+'</p></div>';}
  else if(type==='text'){html='<p'+cls+' style="padding:8px 16px;font-size:14px;">'+escH(content||'New text')+'</p>';}
  else if(type==='heading'){html='<h2'+cls+' style="padding:8px 16px;">'+escH(content||title||'Heading')+'</h2>';}
  else if(type==='button'){html='<a class="btn'+(customClass?' '+escH(customClass): '')+'" href="'+escH(url||'#')+'" style="display:inline-block;margin:8px 16px;">'+escH(content||title||'Click me')+'</a>';}
  else if(type==='link'){html='<a'+cls+' href="'+escH(url||'#')+'" style="display:inline-block;padding:8px 16px;">'+escH(content||title||'Link')+'</a>';}
  else if(type==='list'){html='<ul'+cls+' style="padding:8px 32px;">'+(content||'List item').split(/\r?\n/).filter(Boolean).map(function(item){return'<li>'+escH(item)+'</li>';}).join('')+'</ul>';}
  else if(type==='callout'){html='<aside'+cls+' style="padding:12px 16px;margin:12px 0;border-left:4px solid currentColor;"><strong>'+escH(title||'Notice')+'</strong><div>'+escH(content||'Important information')+'</div></aside>';}
  else if(type==='divider'){html='<hr style="border:none;border-top:1px solid #ccc;margin:12px 16px;">';}
  else if(type==='spacer'){html='<div style="height:40px;"></div>';styles={height:'40px'};}
  else if(type==='image'){html='<img'+cls+' src="'+escH(url||content||'https://via.placeholder.com/300x200')+'" alt="'+escH(title)+'" style="max-width:100%;height:auto;padding:8px 16px;">';}
  else if(type==='video'){html='<video'+cls+' src="'+escH(url||content||'')+'" controls style="max-width:100%;height:auto;margin:8px 16px;"></video>';}
  else if(type==='html'){html=content;}
  addDraft={tag:tag,html:html,styles:styles,position:position};
  mode='add-place';updateModeButtons();
  showMsg('Click on the site to place the element');
});

/* ── Undo / Clear ── */
document.getElementById('se-undo').addEventListener('click',function(){
  if(!changes.length)return;
  changes.pop();
  reloadPreview();
  sendState();renderChanges();
});
document.getElementById('se-clear').addEventListener('click',function(){
  if(!confirm('Clear all changes?'))return;
  changes=[];sendState();renderChanges();
  reloadPreview();
});

/* ── Save template ── */
document.getElementById('se-save').addEventListener('click',function(){
  var saveButton=this;
  var name=document.getElementById('se-tn').value.trim();
  var desc=document.getElementById('se-td').value.trim();
  var params=new URLSearchParams();
  params.append('name',name);
  params.append('description',desc);
  params.append('config',JSON.stringify(changes));
  params.append('scope',currentScope);
  params.append('_token',CSRF);
  saveButton.disabled=true;saveButton.textContent='Saving...';showMsg('Saving template...');
  fetch('<?= url('/admin/site-editor/save') ?>',{
    method:'POST',
    headers:{'Content-Type':'application/x-www-form-urlencoded','X-Requested-With':'XMLHttpRequest'},
    body:params.toString()
}).then(function(r){return r.json();}).then(function(d){
        if(d.ok){
          document.getElementById('se-tn').value='';
          document.getElementById('se-td').value='';
          // If a new template was created (d.id), activate it
          if(d.id && !d.updated){
            var params=new URLSearchParams();
            params.append('_token',CSRF);
            fetch('<?= url('/admin/site-editor/activate/') ?>'+d.id,{
              method:'POST',
              headers:{'Content-Type':'application/x-www-form-urlencoded','X-Requested-With':'XMLHttpRequest'},
              body:params.toString()
            }).then(function(r){return r.json();}).then(function(d2){
              if(d2.ok){loadTemplates();showMsg('Template saved and activated successfully');}
              else {loadTemplates();showMsg('Template saved but activation failed');}
            }).catch(function(e){loadTemplates();showMsg('Template saved but activation failed');});
          }else{
            loadTemplates();
            showMsg(d.updated?'Template updated successfully':'Template saved successfully');
          }
        }else showMsg(d.error||'Save failed');
      }).catch(function(e){showMsg('Save failed: '+e.message);}).finally(function(){saveButton.disabled=false;saveButton.textContent='Save as Template';});
});

/* ── Template list ── */
function loadTemplates(){
  fetch('<?= url('/admin/site-editor/templates') ?>?scope='+currentScope,{headers:{'X-Requested-With':'XMLHttpRequest'}})
  .then(function(r){return r.json();}).then(function(d){templates=d.templates||[];activeTpl=d.active;renderTemplates();});
}
function renderTemplates(){
  var el=document.getElementById('se-tpl-list');
  if(!templates.length){el.innerHTML='<div style="color:#666;font-size:11px;font-style:italic;padding:8px 0;">No templates yet. Make changes and save one.</div>';updateActiveLabel();return;}
  el.innerHTML=templates.map(function(t){
    var isActive=activeTpl&&activeTpl.id==t.id;
    var cfg=[];try{cfg=JSON.parse(t.config_json);if(!Array.isArray(cfg))cfg=[];}catch(e){}
    return'<div class="se-ti'+(isActive?' se-tp':'')+'" data-id="'+t.id+'">'
      +'<div class="se-tn">'+escH(t.name)+(isActive?' &#10003;':'')+'</div>'
      +'<div class="se-tm">'+cfg.length+' changes'+(t.description?' · '+escH(t.description):'')+'</div>'
      +'<div class="se-ta"><button class="se-btn-p" data-a="load">Load</button>'
      +(isActive?'<button class="se-btn-d" data-a="deactivate">Off</button>':'<button class="se-btn-g" data-a="activate">Set Active</button>')
      +'<button class="se-btn-d" data-a="delete">Del</button></div></div>';
  }).join('');
  updateActiveLabel();
  el.querySelectorAll('.se-ti').forEach(function(item){
    item.addEventListener('click',function(e){
      var btn=e.target.closest('[data-a]');if(!btn)return;
      var id=item.dataset.id,a=btn.dataset.a;
      if(a==='load'){
        var tpl=templates.find(function(t){return t.id==id;});
        previewTemplate(tpl);
      }else if(a==='activate'){
        var params=new URLSearchParams();params.append('_token',CSRF);
        fetch('<?= url('/admin/site-editor/activate/') ?>'+id,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest','Content-Type':'application/x-www-form-urlencoded'},body:params.toString()})
        .then(function(){
          var tpl=templates.find(function(t){return t.id==id;});
          previewTemplate(tpl);
          loadTemplates();
          showMsg('Template activated. Live pages will use it on their next reload.');
        }).catch(function(e){showMsg('Activation failed: '+e.message);});
      }else if(a==='deactivate'){
        var params=new URLSearchParams();params.append('scope',currentScope);params.append('_token',CSRF);
        fetch('<?= url('/admin/site-editor/deactivate') ?>',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest','Content-Type':'application/x-www-form-urlencoded'},body:params.toString()})
        .then(function(){loadTemplates();showMsg('Template deactivated');});
      }else if(a==='delete'){
        if(!confirm('Delete this template?'))return;
        var params=new URLSearchParams();params.append('_token',CSRF);
        fetch('<?= url('/admin/site-editor/delete/') ?>'+id,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest','Content-Type':'application/x-www-form-urlencoded'},body:params.toString()})
        .then(function(){loadTemplates();});
      }
      e.stopPropagation();
    });
  });
}
function previewTemplate(tpl){
  if(!tpl||!iDoc)return;
  clearAllVisuals();
  changes=[];
  try{changes=JSON.parse(tpl.config_json);}catch(e){changes=[];}
  applyChanges();
  sendState();
  renderChanges();
  showMsg('Loaded: '+tpl.name);
}
function clearAllVisuals(){
  if(!iDoc)return;
  changes.forEach(function(c){
    try{var el=iDoc.querySelector(c.selector);if(el){if(c.type==='hide'||c.type==='delete')el.style.removeProperty('display');else if(c.type==='move')el.style.removeProperty('transform');}}catch(e){}
  });
}
function updateActiveLabel(){
  var el=document.getElementById('se-active');
  var scopeLabel=currentScope==='admin'?'Admin':'User';
  el.textContent=activeTpl?('Active '+scopeLabel+': '+activeTpl.name):('No active '+scopeLabel+' template');
  el.style.color=activeTpl?'#40a040':'#666';
}

/* ── Changes list ── */
function renderChanges(){
  document.getElementById('se-cc').textContent=changes.length;
  document.getElementById('se-undo').disabled=!changes.length;
  var el=document.getElementById('se-cl');
  if(!changes.length){el.innerHTML='<div style="color:#666;font-size:11px;font-style:italic;padding:4px 0;">No changes yet.</div>';return;}
  el.innerHTML=changes.map(function(c,i){
    return'<div class="se-ci" data-i="'+i+'">'
      +'<span class="se-ct '+c.type+'">'+c.type+'</span>'
      +'<span class="se-cl" title="'+escH(c.selector)+'">'+escH((c.selector||'').substring(0,35))+'</span>'
      +(c.type==='hide'?'<button class="se-show" data-i="'+i+'" title="Show element">Show</button>':'')
      +'<button class="se-x" data-i="'+i+'" title="Remove">&times;</button></div>';
  }).join('');
  el.querySelectorAll('.se-x').forEach(function(btn){
    btn.addEventListener('click',function(e){
      e.stopPropagation();
      var i=parseInt(btn.dataset.i),c=changes[i];
      if(c&&iDoc){try{var el=iDoc.querySelector(c.selector);if(el){if(c.type==='hide'||c.type==='delete')el.style.removeProperty('display');else if(c.type==='move')el.style.removeProperty('transform');}}catch(e){}}
      changes.splice(i,1);sendState();renderChanges();
    });
  });
  el.querySelectorAll('.se-show').forEach(function(btn){
    btn.addEventListener('click',function(e){
      e.stopPropagation();
      var i=parseInt(btn.dataset.i),c=changes[i];
      if(c&&c.type==='hide'&&iDoc){try{var target=iDoc.querySelector(c.selector);if(target)target.style.removeProperty('display');}catch(e){}}
      changes.splice(i,1);sendState();renderChanges();showMsg('Element restored');
    });
  });
}

/* ── Helpers ── */
function escH(s){var d=document.createElement('div');d.textContent=s;return d.innerHTML;}
function showMsg(m){var el=document.getElementById('se-msg');el.textContent=m;el.style.display='block';}
function hideMsg(){document.getElementById('se-msg').style.display='none';}

/* ── iframe ready polling ── */
var handlersAttached=false;
var pollCount=0;
var statusEl=document.getElementById('se-status');
function setStatus(m){if(statusEl)statusEl.textContent=m;}
function tryAttach(){
  try{
    var cw=iframe.contentWindow;
    if(!cw){setStatus('no contentWindow');return false;}
    var doc=cw.document;
    if(!doc){setStatus('no contentDocument');return false;}
    var body=doc.body;
    if(!body){setStatus('no body yet');return false;}
    var hlen=body.innerHTML.length;
    if(hlen<50){setStatus('body too small: '+hlen+' poll:'+pollCount);return false;}
    iDoc=doc;iBody=body;
    if(handlersAttached)return true;
    attachHandlers();
    handlersAttached=true;
    if(changes.length)applyChanges();
    setStatus('CONNECTED - '+hlen+' chars');
    showMsg('Editor ready - click any element on the site');
    setTimeout(hideMsg,3000);
    return true;
  }catch(e){setStatus('ERROR: '+e.message+' line:'+e.lineNumber);return false;}
}
var pollTimer=setInterval(function(){
  pollCount++;
  if(tryAttach()){clearInterval(pollTimer);return;}
  if(pollCount>100){clearInterval(pollTimer);setStatus('FAILED - no iframe access');showMsg('Error: could not access iframe. Check console.');}
},300);

iframe.addEventListener('load',function(){
  setStatus('loaded, attaching...');
  handlersAttached=false;
  pollCount=0;
  tryAttach();
});

renderTemplates();renderChanges();
})();
</script>
