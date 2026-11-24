(function(){
 'use strict';const D=window.Daf,E=D.element,C=window.DAF_CONFIG || {},root=document.getElementById('daf-app');
 function defaultDocument(){
   let seed=C.defaultDocument || window.DAF_EXAMPLE || D.empty();
   try{seed=D.normalize(seed);}catch(_){seed=D.normalize(window.DAF_EXAMPLE || D.empty());}
   // Site appearance defaults intentionally win over the seed JSON. Per-block overrides remain intact.
   seed.settings={...seed.settings,...D.SETTINGS};
   return seed;
 }
 const ui=C.uiTheme&&typeof C.uiTheme==='object'?C.uiTheme:{};
 const cssVars={brand:'--brand',brandSoft:'--brand-soft',workspace:'--ui',surface:'--surface',text:'--text',muted:'--muted'};
 for(const [key,variable] of Object.entries(cssVars))if(/^#[0-9a-f]{6}$/i.test(ui[key]||''))document.documentElement.style.setProperty(variable,ui[key]);
 if(/^#[0-9a-f]{6}$/i.test(ui.brand||''))document.documentElement.style.setProperty('--focus',ui.brand);
 let doc=defaultDocument(),selected=doc.blocks[0]?.id,tab='text',lastLayout=null,zoom=.8,reading=false,dirty=false,cloud={id:0,revision:0},saving=false,readOnly=!!C.readOnly,remoteFonts=false;
 let undo=[],redo=[],lastHistory=0,layoutTimer,saveTimer,toastTimer,workspaceGeneration=0;
 let mover=null,moveSnapshot=null,moveWasDirty=false,movePreviousSelection='',layoutRevision=0,fitMode=true;
 let fieldSequence=0;
 let panelState={left:false,right:false};
 try{const ui=JSON.parse(localStorage.getItem('daf-studio-ui-v1')||'{}');panelState.left=!!ui.left;panelState.right=!!ui.right;}catch(_){}
 const disclosureState=new Map(),zoneNames={main:'Center',auto:'Auto margin',left:'Left margin',right:'Right margin',end:'Endnote'};
 let store; try {store=C.rest?sessionStorage:localStorage;} catch (_) {store={getItem:()=>null,setItem:()=>{throw Error('Storage unavailable');}};}
 const key='daf-studio-v1-'+(C.userId || 'demo');
 try{if(!C.fresh){const saved=JSON.parse(store.getItem(key));const legacySeed=saved?.doc?.title==='מסכת לקסיקון'&&Array.isArray(saved.doc.blocks)&&saved.doc.blocks.some(b=>b.id==='inquiry')&&saved.doc.blocks.some(b=>b.id==='moshia');if(saved?.doc && !readOnly && !legacySeed){doc=D.normalize(saved.doc);cloud=saved.cloud || cloud;selected=doc.blocks[0]?.id;}}}catch(_){}
 root.innerHTML=`
 <header class="appbar">
   <div class="brand"><div class="brand-symbol" aria-hidden="true">&#1491;</div><div><strong>Daf Studio</strong><small>Texts in conversation</small></div></div>
   <div class="document-meta"><button class="title-button" data-action="sheet" id="document-title" title="Edit sheet title"></button><div class="status" id="save-status" role="status">Local workspace</div></div>
   <nav class="toolbar" aria-label="Sheet actions">
     <div class="history-buttons"><button data-action="undo" class="icon-button" title="Undo (Ctrl/Cmd+Z)" aria-label="Undo">&#8630;</button><button data-action="redo" class="icon-button" title="Redo (Ctrl/Cmd+Shift+Z)" aria-label="Redo">&#8631;</button></div>
     <button data-action="file">File <span aria-hidden="true">&#8964;</span></button><button data-action="save">Save</button><button data-action="export" class="primary">Export</button>
   </nav>
 </header>
 <nav class="mobile-toggles" aria-label="Workspace panels"><button data-action="sources">Sources</button><button data-tab="text">Edit text</button><button data-tab="page">Sheet</button><button data-tab="design">Style</button></nav>
 <div class="workspace">
   <aside class="panel sources-panel" aria-label="Sources">
     <div class="panel-head"><h2>Sources <span id="source-count" class="count-badge"></span></h2><div class="panel-head-actions"><button class="panel-collapse icon-button" data-action="collapse-left" aria-label="Collapse sources panel" title="Collapse sources panel">&#x2039;</button><button class="mobile-close icon-button" data-action="dismiss" aria-label="Close sources">&#215;</button></div></div>
     <div class="panel-actions"><button class="primary" data-action="add">+ Add text</button><button data-action="import">Import</button></div>
     <p class="list-hint"><span aria-hidden="true">${window.DafMove.icon}</span> Drag the cross to move a source.</p>
     <ol class="source-list" id="source-list" aria-label="Sources in order"></ol>
     <div class="sources-footer"><button data-action="sefaria" class="quiet-button">Add from Sefaria <span aria-hidden="true">&#8599;</span></button></div>
   </aside>
   <main class="canvas-pane" id="canvas-pane" aria-label="Sheet preview">
     <div class="canvas-toolbar"><div class="zoom-controls"><button data-action="fit">Fit width</button><input aria-label="Canvas zoom" type="range" id="zoom" min="25" max="140" value="80"><span id="zoom-label">80%</span></div><span id="page-count"></span><div class="view-controls"><button data-action="reading" aria-pressed="false">Reading view</button><button data-action="focus" aria-pressed="false" class="focus-button">Focus</button></div></div>
     <div id="scale-box"><div class="preview-stack" id="preview"></div></div><div id="reading" class="reading-view hidden"></div>
   </main>
   <aside class="panel inspector" aria-label="Selected source and sheet settings">
     <nav class="tabs" aria-label="Edit settings"><button data-tab="text" class="active">Text</button><button data-tab="page">Sheet</button><button data-tab="design">Style</button><button class="panel-collapse icon-button" data-action="collapse-right" aria-label="Collapse settings panel" title="Collapse settings panel">&#x203a;</button><button class="mobile-close icon-button" data-action="dismiss" aria-label="Close settings">&#215;</button></nav>
     <div id="inspector-body" class="inspector-body"></div>
   </aside>
   <button class="panel-reopen panel-reopen-left" data-action="collapse-left" aria-label="Show sources panel" title="Show sources panel">&#x203a;</button>
   <button class="panel-reopen panel-reopen-right" data-action="collapse-right" aria-label="Show settings panel" title="Show settings panel">&#x2039;</button>
 </div>
 <footer class="bottom-bar"><span id="layout-status">Composing your sheet...</span><button data-action="warnings" id="warning-status">Check sheet</button><button data-action="help" class="quiet-button" aria-label="Help and keyboard shortcuts">Help</button><span id="plan-status" class="hidden"></span></footer>
 <button id="panel-scrim" class="panel-scrim hidden" data-action="dismiss" aria-label="Close side panel"></button>
 <input type="file" id="file-input" class="hidden" multiple accept=".txt,.md,.markdown,.html,.htm,.docx,.pdf,.json,.png,.jpg,.jpeg,.webp">
 <dialog id="modal" aria-labelledby="modal-title"><div class="dialog-head"><h2 id="modal-title"></h2><button id="modal-close" aria-label="Close dialog">&#215;</button></div><div class="dialog-body" id="modal-body"></div></dialog>
 <div id="toast" role="status" class="toast hidden"></div>
 <div id="rich-toolbar" class="rich-toolbar hidden" role="toolbar" aria-label="Text formatting"><button type="button" data-format="bold" aria-label="Bold" title="Bold"><strong>B</strong></button><button type="button" data-format="italic" aria-label="Italic" title="Italic"><em>I</em></button><button type="button" data-format="underline" aria-label="Underline" title="Underline"><u>U</u></button></div>
 <div id="move-feedback" class="move-feedback" aria-hidden="true"></div><div id="move-announcer" role="status" aria-live="polite" aria-atomic="true" class="sr-only"></div>
 <p id="move-help" class="sr-only">Drag this handle to reorder sources or place one on the page. Click for move controls. With the keyboard, press Space or Enter to pick up, Up or Down to reorder, then Enter to place or Escape to cancel.</p>`;
 const $=id=>document.getElementById(id);
 function toast(message,actionLabel='',onAction=null){const notice=$('toast');notice.replaceChildren(E('span','',message));if(actionLabel&&onAction){const action=button(actionLabel,()=>{notice.classList.add('hidden');onAction();},'toast-action');notice.append(action);}notice.classList.remove('hidden');clearTimeout(toastTimer);toastTimer=setTimeout(()=>notice.classList.add('hidden'),6500);}
 function modal(title,build){$('modal-title').textContent=title;const body=$('modal-body');body.replaceChildren();build(body);if(!$('modal').open)$('modal').showModal();}
 function close(){$('modal').close();}
 $('modal-close').onclick=close;
 $('rich-toolbar').addEventListener('pointerdown',e=>e.preventDefault());$('rich-toolbar').addEventListener('click',e=>{const b=e.target.closest('[data-format]');if(b)applyInlineFormat(b.dataset.format);});document.addEventListener('selectionchange',()=>requestAnimationFrame(updateRichToolbar));
 function button(label,fn,cls=''){const b=E('button',cls,label);b.type='button';b.onclick=fn;return b;}
 function explain(parent,txt){parent.append(E('p','muted',txt));}
 async function api(path,options={}){
   if(!C.rest)throw Error('Cloud storage is available after installing the WordPress plugin. JSON backup works in this demo.');
   const headers={...(C.nonce?{'X-WP-Nonce':C.nonce}:{}),...(options.headers || {})};
   if(options.body && !(options.body instanceof FormData)){headers['Content-Type']='application/json';options.body=JSON.stringify(options.body);}
   const response=await fetch(C.rest+path,{credentials:'same-origin',...options,headers});
   const data=await response.json().catch(()=>({message:'The server did not return JSON.'}));
   if(!response.ok){const e=Error(data.message || 'Request failed');e.status=response.status;throw e;}return data;
 }
 function persist(){if(readOnly || moveSnapshot)return;try{store.setItem(key,JSON.stringify({doc,cloud,updated:new Date().toISOString()}));$('save-status').textContent=C.rest?(dirty?'Changes in this browser session':'Saved to WordPress'):'Saved on this device';}catch(_){$('save-status').textContent='Local storage full: download a JSON backup';}}
 function history(force=false){const now=Date.now();if(force || now-lastHistory>450){undo.push(D.clone(doc));if(undo.length>35)undo.shift();redo=[];lastHistory=now;}}
 function change(fn,{inspector=false}={}){
   if(readOnly)return;history();fn();dirty=true;persist();list();if(inspector)inspect();schedule();
   queueSave();
 }
 function replace(newDoc,newCloud={id:0,revision:0}){mover?.cancel();clearTimeout(saveTimer);workspaceGeneration++;history();doc=D.normalize(newDoc);cloud=newCloud;selected=doc.blocks[0]?.id;dirty=false;lastHistory=0;persist();list();inspect();schedule();}
 function selectedBlock(){return doc.blocks.find(b=>b.id===selected);}
 function add(b=D.block()){dismissPanels();if(doc.blocks.length>=100)return toast('100-block limit reached.');change(()=>{doc.blocks.push(b);selected=b.id;},{inspector:true});tab='text';inspect();if(matchMedia('(max-width: 960px)').matches)openPanel('inspector');}
 function queueSave(){clearTimeout(saveTimer);if(cloud.id && C.rest && !moveSnapshot)saveTimer=setTimeout(()=>saveCloud(false).catch(e=>toast(e.message)),2000);}
 function syncChrome(){
   $('document-title').textContent=doc.title || 'Untitled sheet';
   $('document-title').dir='auto';$('source-count').textContent=doc.blocks.length;
   root.querySelector('[data-action="undo"]').disabled=!undo.length || readOnly;
   root.querySelector('[data-action="redo"]').disabled=!redo.length || readOnly;
 }
 function dragHandle(id,title,cls=''){
   const h=button('',null,'drag-handle '+cls);h.innerHTML=window.DafMove.icon;h.dataset.dragId=id;
   h.setAttribute('aria-label','Move '+(title || 'untitled source'));h.setAttribute('aria-describedby','move-help');h.setAttribute('aria-pressed','false');h.setAttribute('aria-keyshortcuts','Space Enter');
   h.title='Drag to move. Click for move controls.';h.disabled=readOnly;return h;
 }
 function list(){
   const ol=$('source-list');ol.replaceChildren();syncChrome();
   doc.blocks.forEach((b,i)=>{
     const li=E('li','source-card'+(selected===b.id?' selected':''));li.dataset.id=b.id;
     li.setAttribute('aria-posinset',i+1);li.setAttribute('aria-setsize',doc.blocks.length);
     const top=E('div','card-top');
     const choose=button('',()=>select(b.id),'source-select');const title=E('strong','',b.title || 'Untitled source');title.dir='auto';choose.append(title);choose.setAttribute('aria-label','Edit '+(b.title || 'untitled source'));choose.setAttribute('aria-pressed',String(selected===b.id));
     const more=button('\u22ef',()=>sourceActions(b.id),'source-more icon-button');more.setAttribute('aria-label','Options for '+(b.title || 'untitled source'));more.title='Move, duplicate, or delete';
     top.append(dragHandle(b.id,b.title),choose);if(!readOnly)top.append(more);
     const snippet=E('p','card-excerpt',b.text.slice(0,90) || (b.image?'Image source':'Click to add text'));snippet.dir='auto';
     const bottom=E('div','card-bottom');bottom.append(E('span','badge',zoneNames[b.zone]),E('span','source-number',String(i+1).padStart(2,'0')));
     li.append(top,snippet,bottom);li.onclick=e=>{if(!e.target.closest('button'))select(b.id);};ol.append(li);
   });
   if(!doc.blocks.length){const empty=E('li','sources-empty');empty.append(E('h3','','Begin with a text'),E('p','','Paste a passage, add a commentary, or import a file. Your daf takes shape as you go.'));ol.append(empty);}
 }
 function syncListOrder(animate=true){
   const ol=$('source-list'),cards=[...ol.querySelectorAll('.source-card')],before=new Map(cards.map(e=>[e.dataset.id,e.getBoundingClientRect()]));
   const byId=new Map(cards.map(e=>[e.dataset.id,e]));
   doc.blocks.forEach((b,i)=>{const li=byId.get(b.id);if(!li)return;ol.append(li);li.querySelector('.badge').textContent=zoneNames[b.zone];li.querySelector('.source-number').textContent=String(i+1).padStart(2,'0');li.setAttribute('aria-posinset',i+1);li.classList.toggle('moving',b.id===mover?.state()?.id);li.classList.toggle('selected',b.id===selected);});
   if(animate&&!matchMedia('(prefers-reduced-motion: reduce)').matches){for(const li of cards){const old=before.get(li.dataset.id),now=li.getBoundingClientRect();const dy=old.top-now.top;if(Math.abs(dy)>1&&li.animate){li.getAnimations().forEach(a=>a.cancel());li.animate([{transform:'translateY('+dy+'px)'},{transform:'translateY(0)'}],{duration:140,easing:'ease-out'});}}}
 }
 function move(id,n){const i=doc.blocks.findIndex(b=>b.id===id),j=i+n;if(j<0 || j>=doc.blocks.length)return;lastHistory=0;change(()=>{[doc.blocks[i],doc.blocks[j]]=[doc.blocks[j],doc.blocks[i]];});lastHistory=0;toast('Source moved to position '+(j+1)+'.');}
 function select(id,{drawer=true}={}){
   selected=id;tab='text';list();inspect();markSelection();
   if(drawer && matchMedia('(max-width: 960px)').matches)openPanel('inspector');
 }
 function markSelection(){document.querySelectorAll('#preview [data-source-id]').forEach(e=>e.classList.toggle('selected-source',e.dataset.sourceId===selected));}
 function deleteSource(id){const b=doc.blocks.find(b=>b.id===id);if(!b || !confirm('Delete this source? Undo is available.'))return;change(()=>{doc.blocks=doc.blocks.filter(x=>x.id!==id);doc.blocks.forEach(x=>{if(x.parentId===id)x.parentId='';});selected=doc.blocks[0]?.id;},{inspector:true});close();}
 function sourceActions(id){
   if(readOnly)return;const b=doc.blocks.find(x=>x.id===id);if(!b)return;
   modal('Move source',p=>{
     const heading=E('p','dialog-source-title',b.title || 'Untitled source');heading.dir='auto';p.append(heading);
     explain(p,'Drag the four-arrow handle, or use these controls without dragging.');
     const row=E('div','button-row'),i=doc.blocks.indexOf(b);p.append(row);
     const up=button('Move up',()=>{move(id,-1);sourceActions(id);}),down=button('Move down',()=>{move(id,1);sourceActions(id);});up.disabled=i===0;down.disabled=i===doc.blocks.length-1;row.append(up,down);
     field(p,'Position in source list',String(i),v=>{lastHistory=0;change(()=>{const at=doc.blocks.findIndex(x=>x.id===id);const [item]=doc.blocks.splice(at,1);doc.blocks.splice(+v,0,item);});lastHistory=0;sourceActions(id);},'select',doc.blocks.map((x,n)=>[String(n),String(n+1)+' of '+doc.blocks.length]));
     field(p,'Place on page',b.zone,v=>{lastHistory=0;change(()=>b.zone=v,{inspector:true});lastHistory=0;},'select',Object.entries(zoneNames));
     explain(p,'Source order applies within each page region. Auto margins may rebalance; choose Left or Right to pin a side.');
     divider(p,'More actions');const buttons=E('div','button-row');p.append(buttons);
     buttons.append(button('Duplicate',()=>{add({...D.clone(b),id:D.uid(),title:b.title+' (copy)'});close();}),button('Delete source',()=>deleteSource(id),'danger'));
     p.append(button('Done',close,'primary dialog-done'));
   });
 }
 function syncPanelState(){
   root.classList.toggle('left-panel-collapsed',panelState.left);root.classList.toggle('right-panel-collapsed',panelState.right);
   const l=root.querySelector('[data-action="collapse-left"]'),r=root.querySelector('[data-action="collapse-right"]');
   if(l)l.setAttribute('aria-expanded',String(!panelState.left));if(r)r.setAttribute('aria-expanded',String(!panelState.right));
   try{localStorage.setItem('daf-studio-ui-v1',JSON.stringify(panelState));}catch(_){}
   requestAnimationFrame(()=>{if(fitMode&&lastLayout)fitZoom();});
 }
 function togglePanel(side){if(matchMedia('(max-width: 960px)').matches){openPanel(side==='left'?'sources':'inspector');return;}panelState[side]=!panelState[side];syncPanelState();}
 function openPanel(name){document.querySelectorAll('.panel.open').forEach(e=>e.classList.remove('open'));document.querySelector(name==='sources'?'.sources-panel':'.inspector').classList.add('open');$('panel-scrim').classList.remove('hidden');}
 function dismissPanels(){document.querySelectorAll('.panel.open').forEach(e=>e.classList.remove('open'));$('panel-scrim').classList.add('hidden');}
 function showTab(name){tab=name;inspect();if(matchMedia('(max-width: 960px)').matches)openPanel('inspector');}
 function field(parent,label,value,fn,type='text',options=null,help=''){
   const l=E('label','field');l.append(E('span','',label));let input;
   if(type==='select'){input=E('select');for(const o of options){const [v,t]=Array.isArray(o)?o:[o,o];const item=E('option','',t);item.value=v;input.append(item);}}
   else input=E(type==='textarea'?'textarea':'input');
   if(input.tagName==='INPUT')input.type=type;
   if(type==='checkbox'){l.classList.add('check-field');input.checked=!!value;}else input.value=value ?? '';
   if(type==='textarea' || type==='text')input.dir='auto';
   input.disabled=readOnly;input.setAttribute('aria-label',label);
   input.addEventListener(type==='select'||type==='checkbox'||type==='color'?'change':'input',()=>fn(type==='checkbox'?input.checked:(type==='number'||type==='range')?+input.value:input.value));
   l.append(input);if(help){const hint=E('small','',help);hint.id='daf-field-help-'+(++fieldSequence);input.setAttribute('aria-describedby',hint.id);l.append(hint);}parent.append(l);return input;
 }
 function fontPicker(parent,label,value,fn,options,kind='latin'){
   const wrap=E('div','field font-field');wrap.append(E('span','',label));
   const details=E('details','font-picker');const summary=E('summary','font-picker-current');summary.style.fontFamily='"'+value+'", serif';summary.setAttribute('aria-label',label);
   summary.append(E('span','font-preview-sample',kind==='hebrew'?'אבגדהוז':'Aa Bb Cc'),E('span','font-current-name',value));details.append(summary);
   const menu=E('div','font-menu');
   for(const name of options){const item=button('',()=>{fn(name);details.open=false;},'font-option');item.style.fontFamily='"'+name+'", serif';item.dataset.font=name;item.append(E('span','font-option-sample',kind==='hebrew'?'אבגדהוז נִקּוּד':'Aa Bb — The quick brown fox'),E('span','font-option-name',name));if(name===value)item.setAttribute('aria-current','true');menu.append(item);}
   details.append(menu);wrap.append(details);parent.append(wrap);return details;
 }
 function mergeMarks(marks,max){
   const out=[];for(const type of ['bold','italic','underline']){const rows=marks.filter(m=>m.type===type).map(m=>({start:Math.max(0,Math.min(max,m.start)),end:Math.max(0,Math.min(max,m.end)),type})).filter(m=>m.end>m.start).sort((a,b)=>a.start-b.start||a.end-b.end);for(const m of rows){const last=out[out.length-1];if(last&&last.type===type&&m.start<=last.end)last.end=Math.max(last.end,m.end);else out.push(m);}}
   return out.sort((a,b)=>a.start-b.start||a.end-b.end||a.type.localeCompare(b.type));
 }
 function serializeRichEditor(editor){
   let text='',marks=[];
   const walk=(node,active=[])=>{
     if(node.nodeType===Node.TEXT_NODE){const val=node.nodeValue.replace(/\u00a0/g,' '),start=text.length;text+=val;const end=text.length;if(end>start)for(const type of active)marks.push({start,end,type});return;}
     if(node.nodeType!==Node.ELEMENT_NODE)return;
     const tag=node.tagName,block=node!==editor&&(tag==='DIV'||tag==='P');if(block&&text&&!text.endsWith('\n'))text+='\n';
     if(tag==='BR'){text+='\n';return;}
     const next=[...active];if((tag==='B'||tag==='STRONG')&&!next.includes('bold'))next.push('bold');if((tag==='I'||tag==='EM')&&!next.includes('italic'))next.push('italic');if(tag==='U'&&!next.includes('underline'))next.push('underline');
     for(const child of node.childNodes)walk(child,next);
     if(block&&node.nextSibling&&!text.endsWith('\n'))text+='\n';
   };
   for(const child of editor.childNodes)walk(child,[]);
   while(text.endsWith('\n\n'))text=text.slice(0,-1);
   marks=mergeMarks(marks,text.length);return {text,marks};
 }
 function richTextField(parent,label,b,onChange,help=''){
   const wrap=E('div','field rich-field');wrap.append(E('span','',label));const editor=E('div','rich-editor');editor.id='source-richtext';editor.contentEditable=readOnly?'false':'true';editor.setAttribute('role','textbox');editor.setAttribute('aria-multiline','true');editor.setAttribute('aria-label',label);editor.dir='auto';editor.spellcheck=true;D.scriptRichText(editor,b.text,b.marks||[],0);
   const commit=()=>{const data=serializeRichEditor(editor);if(data.text.length>100000){toast('100,000 characters per block maximum.');editor.replaceChildren();D.scriptRichText(editor,b.text,b.marks||[],0);return;}onChange(data);};
   editor.addEventListener('input',commit);editor.addEventListener('paste',e=>{e.preventDefault();const t=e.clipboardData.getData('text/plain');document.execCommand('insertText',false,t);});editor.addEventListener('mouseup',updateRichToolbar);editor.addEventListener('keyup',updateRichToolbar);editor.addEventListener('focus',updateRichToolbar);editor.addEventListener('blur',()=>setTimeout(updateRichToolbar,0));
   wrap.append(editor);if(help){const hint=E('small','',help);wrap.append(hint);}parent.append(wrap);return editor;
 }
 function activeRichEditor(){const editor=$('source-richtext'),sel=window.getSelection();if(!editor||!sel||!sel.rangeCount||sel.isCollapsed)return null;const r=sel.getRangeAt(0);return editor.contains(r.commonAncestorContainer)?editor:null;}
 function updateRichToolbar(){
   const bar=$('rich-toolbar'),editor=activeRichEditor();if(!bar||!editor){bar?.classList.add('hidden');return;}
   const sel=window.getSelection(),rect=sel.getRangeAt(0).getBoundingClientRect();if(!rect.width&&!rect.height){bar.classList.add('hidden');return;}
   bar.classList.remove('hidden');bar.style.left=Math.max(8,Math.min(innerWidth-bar.offsetWidth-8,rect.left+rect.width/2-bar.offsetWidth/2))+'px';bar.style.top=Math.max(8,rect.top-bar.offsetHeight-8)+'px';
   for(const btn of bar.querySelectorAll('[data-format]')){let active=false;try{active=document.queryCommandState(btn.dataset.format);}catch(_){}btn.setAttribute('aria-pressed',String(active));}
 }
 function applyInlineFormat(type){const editor=activeRichEditor();if(!editor||readOnly)return;const b=selectedBlock();if(!b)return;history(true);editor.focus();try{document.execCommand(type,false,null);}catch(_){return toast('This browser could not apply that inline style.');}const data=serializeRichEditor(editor);b.text=data.text;b.marks=data.marks;dirty=true;persist();list();schedule();queueSave();updateRichToolbar();}

 function divider(parent,title){parent.append(E('hr','section-divider'),E('h3','',title));}
 function group(parent,id,title,description=''){
   const details=E('details','settings-group'),summary=E('summary','',title);details.open=disclosureState.get(id)||false;details.append(summary);
   details.addEventListener('toggle',()=>disclosureState.set(id,details.open));const body=E('div','group-body');if(description)explain(body,description);details.append(body);parent.append(details);return body;
 }
 function inspect(){
   document.querySelectorAll('[data-tab]').forEach(b=>{b.classList.toggle('active',b.dataset.tab===tab);b.setAttribute('aria-pressed',String(b.dataset.tab===tab));});
   const p=$('inspector-body');p.replaceChildren();const s=doc.settings,b=selectedBlock();
   if(tab==='text'){
     if(!b){p.append(E('div','empty-inspector','Select a source to edit it, or add your first text.'));return;}
     const edit=(k,v)=>change(()=>b[k]=v);
     const head=E('div','inspector-heading');head.append(E('h2','','Edit source'));if(!readOnly)head.append(button('More \u22ef',()=>sourceActions(b.id),'quiet-button'));p.append(head);
     field(p,'Heading',b.title,v=>edit('title',v));
     const ta=richTextField(p,'Text',b,data=>change(()=>{b.text=data.text;b.marks=data.marks;}),'Hebrew, English, or both. Select text for bold, italic, or underline. Direction is automatic.');ta.style.setProperty('--latin','"'+s.latin+'"');ta.style.setProperty('--hebrew','"'+s.hebrew+'"');
     if(b.image){field(p,'Image description (alt text)',b.alt,v=>edit('alt',v));p.append(button('Remove image',()=>change(()=>b.image='',{inspector:true}),'small'));}
     field(p,'Place on page',b.zone,v=>edit('zone',v),'select',Object.entries(zoneNames)).id='source-placement';
     const attribution=group(p,'text-attribution','Attribution & links');
     field(attribution,'Speaker / author',b.byline,v=>edit('byline',v));field(attribution,'Speaker / author link',b.authorUrl,v=>edit('authorUrl',v),'url');
     field(attribution,'Heading / source link',b.url,v=>edit('url',v),'url');field(attribution,'Source reference / edition',b.source,v=>edit('source',v));field(attribution,'License / rights note',b.license,v=>edit('license',v));
     const formatting=group(p,'text-format','Text appearance','These settings override the sheet style for this source only.');
     field(formatting,'Source type',b.role,v=>edit('role',v),'select',D.ROLES);
     field(formatting,'Paragraph direction',b.direction,v=>edit('direction',v),'select',[['auto','Automatic'],['rtl','Right to left'],['ltr','Left to right']]);
     const size=field(formatting,'Type size (px; 0 = inherit)',b.size,v=>edit('size',v>0?D.clamp(v,11,32,11):0),'number');size.min=0;size.max=32;
     field(formatting,'Source ink override',b.color || s.ink,v=>edit('color',v),'color');formatting.append(button('Use sheet ink',()=>change(()=>b.color='',{inspector:true}),'small'));
     const flow=group(p,'text-flow','Flow & relationships');
     const width=field(flow,'Margin width factor',b.width,v=>edit('width',D.clamp(v,.7,1.4,1)),'number');width.min=.7;width.max=1.4;width.step=.05;
     field(flow,'Related source',b.parentId,v=>edit('parentId',v),'select',[['','None'],...doc.blocks.filter(x=>x.id!==b.id).map(x=>[x.id,x.title])],'Creates a link, not a guarantee of same-folio placement.');
     field(flow,'Keep source together',b.keepTogether,v=>edit('keepTogether',v),'checkbox',null,'An oversized block can split, with a warning.');field(flow,'Start on a new folio',b.breakBefore,v=>edit('breakBefore',v),'checkbox');
   }else if(tab==='page'){
     const edit=(k,v)=>change(()=>s[k]=v);p.append(E('h2','inspector-heading','Your sheet'));
     field(p,'Sheet title',doc.title,v=>change(()=>doc.title=v));field(p,'Chapter / subtitle',doc.subtitle,v=>change(()=>doc.subtitle=v));
     field(p,'Layout',s.layout,v=>edit('layout',v),'select',[['stepped','Adaptive daf'],['columns','Classic columns'],['reading','Single column']]);
     field(p,'Paper size',s.paper,v=>edit('paper',v),'select',Object.entries(D.PAPERS));
     const attribution=group(p,'sheet-links','Author & links');for(const [k,label] of [['author','Prepared by'],['titleUrl','Title link'],['authorUrl','Author link']])field(attribution,label,doc[k],v=>change(()=>doc[k]=v),k.endsWith('Url')?'url':'text');
     const flow=group(p,'sheet-flow','Overflow & page breaks','The default continues onto more folios. No text is silently discarded.');
     field(flow,'When content overruns',s.overflow,v=>edit('overflow',v),'select',[['continue','Continue onto more folios'],['shrink','Shrink to a limit, then continue'],['endnotes','Move margin texts to endnotes'],['strict','One folio: flag unplaced text']]);
     const min=field(flow,'Minimum shrink size (px)',s.minSize,v=>edit('minSize',D.clamp(v,10,20,12)),'number',null,'Used only with the shrink option. 12 px = 9 pt.');min.min=10;min.max=20;
     const numbering=group(p,'sheet-numbering','Numbering & footer');field(numbering,'Numbering',s.numbering,v=>edit('numbering',v),'select',[['daf','Daf: 2a, 2b, 3a...'],['page','Page: 1, 2, 3...']]);
     const n=E('div','field-row');numbering.append(n);field(n,'Starting daf',s.folio,v=>edit('folio',D.clamp(v,1,999,2)),'number');field(n,'Starting side',s.side,v=>edit('side',v),'select',['a','b']);
     field(numbering,'Mirror facing margins',s.mirror,v=>edit('mirror',v),'checkbox');field(numbering,'Footer',s.footer,v=>edit('footer',v));field(numbering,'Show references & licenses',s.showSources,v=>edit('showSources',v),'checkbox');
     const spacing=group(p,'sheet-spacing','Spacing & diagnostics');const r=E('div','field-row');spacing.append(r);field(r,'Margins (px)',s.margin,v=>edit('margin',D.clamp(v,20,65,34)),'number');field(r,'Text gutter (px)',s.gap,v=>edit('gap',D.clamp(v,8,30,15)),'number');field(spacing,'Show layout diagnostics',s.debug,v=>edit('debug',v),'checkbox');
   }else{
     const edit=(k,v)=>change(()=>s[k]=v);p.append(E('h2','inspector-heading','Sheet style'));
     const presets=E('div','style-presets');p.append(presets);
     const choices=[['Vilna','Warm paper',{ink:'#25221f',accent:'#65513a',paperColor:'#fffdf7',mainSize:16,noteSize:14,leading:1.48}],['Modern','Deep teal',{ink:'#22322f',accent:'#17655e',paperColor:'#ffffff',mainSize:17,noteSize:15,leading:1.55}],['Print','Black & white',{ink:'#202020',accent:'#202020',paperColor:'#ffffff',mainSize:16,noteSize:14,leading:1.5}]];
     for(const [name,caption,values] of choices){const btn=button('',()=>change(()=>Object.assign(s,values),{inspector:true}),'style-preset');const swatch=E('span','preset-swatch','Aa');swatch.style.backgroundColor=values.paperColor;swatch.style.color=values.accent;btn.append(swatch,E('strong','',name),E('small','',caption));btn.setAttribute('aria-label','Apply '+name+' style');btn.setAttribute('aria-pressed',String(s.paperColor===values.paperColor&&s.accent===values.accent&&s.ink===values.ink));presets.append(btn);}
     fontPicker(p,'Latin typeface',s.latin,v=>change(()=>s.latin=v,{inspector:true}),D.LATIN,'latin');fontPicker(p,'Hebrew typeface',s.hebrew,v=>change(()=>s.hebrew=v,{inspector:true}),D.HEBREW,'hebrew');
     const typography=group(p,'style-type','Type sizes & spacing');const row=E('div','field-row');typography.append(row);field(row,'Center (px)',s.mainSize,v=>edit('mainSize',D.clamp(v,12,28,16)),'number');field(row,'Margins (px)',s.noteSize,v=>edit('noteSize',D.clamp(v,11,24,14)),'number');
     field(typography,'Line height',s.leading,v=>edit('leading',D.clamp(v,1.25,2,1.48)),'number',null,'Allow room for niqqud and cantillation.').step=.05;field(typography,'Heading size (px)',s.headerSize,v=>edit('headerSize',D.clamp(v,18,38,28)),'number');
     const palette=group(p,'style-colors','Colors');for(const [k,label] of [['ink','Text'],['accent','Headings & citations'],['paperColor','Paper']])field(palette,label,s[k],v=>edit(k,v),'color');
     const fonts=group(p,'style-fonts','Font loading & specimen','Font names use installed fonts or a fallback. Loading optional web fonts contacts Google, but does not send your source text.');fonts.append(button(remoteFonts?'Reload optional web fonts':'Load optional web fonts',loadFonts,'small'));
     const specimen=E('p','font-specimen');specimen.dir='auto';specimen.style.setProperty('--latin','"'+s.latin+'"');specimen.style.setProperty('--hebrew','"'+s.hebrew+'"');D.scriptText(specimen,'\u05d1\u05bc\u05b0\u05e8\u05b5\u05d0\u05e9\u05c1\u05b4\u0596\u05d9\u05ea \u05d1\u05bc\u05b8\u05e8\u05b8\u05a3\u05d0 \u05d0\u05b1\u05dc\u05b9\u05d4\u05b4\u0591\u05d9\u05dd / In the beginning');fonts.append(specimen);
   }
   if(readOnly)p.querySelectorAll('button').forEach(x=>x.disabled=true);
 }
 function loadFonts(){
   if(!confirm('Load the available Google-hosted fonts (Crimson Text, EB Garamond, Roboto, Frank Ruhl Libre, and Noto Hebrew families)? This makes a third-party network request, but does not send your source text.'))return;
   const link=E('link');link.rel='stylesheet';link.href='https://fonts.googleapis.com/css2?family=Crimson+Text:ital,wght@0,400;0,600;0,700;1,400;1,600&family=EB+Garamond:ital,wght@0,400;0,600;0,700;1,400;1,600&family=Roboto:ital,wght@0,400;0,700;1,400;1,700&family=Frank+Ruhl+Libre:wght@400;600;700&family=Noto+Serif+Hebrew:wght@400;700&family=Noto+Sans+Hebrew:wght@400;700&family=Noto+Rashi+Hebrew:wght@400;700&display=swap';
   link.onload=async()=>{remoteFonts=true;await document.fonts.ready;schedule();toast('Web fonts loaded. Recheck pagination before exporting.');};link.onerror=()=>toast('Fonts could not load. Local fallback fonts are still available.');document.head.append(link);
 }
 function schedule(live=false){
   layoutRevision++;lastLayout=null;
   if(live&&layoutTimer)return;clearTimeout(layoutTimer);layoutTimer=setTimeout(()=>{layoutTimer=null;render();},live?100:200);
 }
 async function render(){
   clearTimeout(layoutTimer);layoutTimer=null;const revision=layoutRevision,snapshot=D.clone(doc);
   $('layout-status').textContent=mover?.isActive()?'Live layout preview...':'Composing...';
   try{
     const result=await window.DafLayout.render(snapshot,$('preview'));if(!result||revision!==layoutRevision)return;lastLayout=result;
     installPageTools();if(fitMode)fitZoom();else applyZoom();readingContent();const {pages,remaining}=result;
     $('page-count').textContent=pages+' folio'+(pages===1?'':'s');
     $('layout-status').textContent=remaining.length?'Unplaced text: review overflow':doc.blocks.length+' sources \u00b7 All text placed';
     const warnings=preflight();$('warning-status').textContent=warnings.length?'Review '+warnings.length+' note'+(warnings.length===1?'':'s'):'Layout checked';
     $('warning-status').classList.toggle('warning-dot',warnings.length>0);markSelection();mover?.refresh();
   }catch(e){$('layout-status').textContent='Layout error: '+e.message;toast(e.message);}
 }
 function installPageTools(){
   if(readOnly)return;
   for(const paper of $('preview').querySelectorAll('.daf-page')){
     const overlay=E('div','page-dropzones editor-only');overlay.setAttribute('aria-hidden','true');const body=paper.querySelector('.daf-body');if(body)overlay.style.top=body.offsetTop+'px';
     const single=doc.settings.layout==='reading'||doc.settings.overflow==='endnotes';if(single)overlay.classList.add('single-dropzone');
     for(const zone of single?['main']:['left','main','right']){const lane=E('div','drop-lane');lane.dataset.physicalZone=zone;lane.append(E('span','',zoneNames[zone]));overlay.append(lane);}paper.append(overlay);
     for(const fragment of paper.querySelectorAll('.daf-fragment')){const b=doc.blocks.find(x=>x.id===fragment.dataset.sourceId);if(b){const handle=dragHandle(b.id,b.title,'page-handle editor-only');handle.tabIndex=-1;fragment.prepend(handle);}}
   }
 }
 function fitZoom(){zoom=Math.min(1.1,Math.max(.25,($('canvas-pane').clientWidth-(matchMedia('(max-width: 600px)').matches?32:64))/(lastLayout?.width || 816)));applyZoom();}
 function applyZoom(){const p=$('preview');p.style.transform=`scale(${zoom})`;$('zoom').value=zoom*100;$('zoom-label').textContent=Math.round(zoom*100)+'%';root.style.setProperty('--handle-scale',String(1/zoom));if(lastLayout){$('scale-box').style.width=lastLayout.width*zoom+'px';$('scale-box').style.height=(lastLayout.height+30)*lastLayout.pages*zoom+'px';$('scale-box').style.margin='0 auto';}}
 function readingContent(){
   const view=$('reading');view.replaceChildren(E('h1','',doc.title),E('p','muted',doc.subtitle));
   doc.blocks.forEach(b=>{const section=E('section');section.append(D.linked(b.title,b.url));if(b.byline)section.append(D.linked(b.byline,b.authorUrl,'byline'));if(b.image){const i=E('img');i.src=b.image;i.alt=b.alt;section.append(i);}let offset=0;b.text.split('\n').forEach(t=>{const p=E('p');p.dir=b.direction==='auto'?D.paragraphDirection(t):b.direction;D.scriptRichText(p,t,b.marks||[],offset);section.append(p);offset+=t.length+1;});if(b.source)section.append(E('small','muted',b.source));view.append(section);});
 }
 function preflight(){
   const warnings=[...(lastLayout?.notices || [])];
   for(const b of doc.blocks){if(b.image && !b.alt.trim())warnings.push('Image "'+b.title+'" needs alternative text.');if(b.url && !D.safeUrl(b.url))warnings.push('Invalid link on "'+b.title+'" will be omitted.');}
   const contrast=(a,b)=>{const lum=h=>{const c=h.match(/[a-f0-9]{2}/gi).map(x=>parseInt(x,16)/255).map(x=>x<=.04045?x/12.92:((x+.055)/1.055)**2.4);return .2126*c[0]+.7152*c[1]+.0722*c[2];};const x=lum(a),y=lum(b);return (Math.max(x,y)+.05)/(Math.min(x,y)+.05);};
   if(contrast(doc.settings.ink,doc.settings.paperColor)<4.5)warnings.push('Body text / paper contrast is below 4.5:1.');
   if(doc.blocks.some(b=>!b.license && b.source))warnings.push('Some source editions lack a rights note. Check redistribution rights before publishing.');
   return [...new Set(warnings)];
 }
 function download(name,value,type){const a=E('a');const url=URL.createObjectURL(new Blob([value],{type}));a.href=url;a.download=name;a.click();setTimeout(()=>URL.revokeObjectURL(url),30000);}
 function safeName(){return (doc.title.replace(/[^\p{L}\p{N}_-]+/gu,'-').slice(0,80) || 'daf-sheet');}
 function jsonExport(){download(safeName()+'.daf.json',JSON.stringify(doc,null,2),'application/json');}
 async function saveCloud(show=true){
   if(readOnly)return;
   if(moveSnapshot){if(show)toast('Finish or cancel the move before saving.');return;}
   if(!C.rest){persist();if(show)jsonExport();return;}
   if(saving){if(show)toast('A save is already in progress.');return;} saving=true;
   const generation=workspaceGeneration,snapshot=D.clone(doc),payload={document:snapshot,revision:cloud.revision};let saved=false;$('save-status').textContent='Saving to WordPress...';
   try{const r=await api(cloud.id?'sheets/'+cloud.id:'sheets',{method:'POST',body:payload});if(generation!==workspaceGeneration)return;saved=true;cloud={id:r.id,revision:r.revision};dirty=JSON.stringify(doc)!==JSON.stringify(snapshot);persist();if(show)toast('Saved privately to your WordPress account.');}
   catch(e){
     if(generation!==workspaceGeneration)return;
     $('save-status').textContent='Not saved to WordPress';
     if(e.status===409){clearTimeout(saveTimer);modal('A newer version exists',p=>{explain(p,'Another tab or device saved changes. Your local text is untouched. Download a backup, then reload the current server version; no silent overwrite is performed.');p.append(button('Download my local copy',jsonExport),button('Reload server copy',async()=>{const r=await api('sheets/'+cloud.id);replace(r.document,{id:r.id,revision:r.revision});close();}));});}
     else throw e;
   }finally{saving=false;if(saved && dirty && generation===workspaceGeneration){clearTimeout(saveTimer);saveTimer=setTimeout(()=>saveCloud(false).catch(e=>toast(e.message)),1000);}}
 }
 async function library(){
   if(!C.rest){modal('Your local workspace',p=>{explain(p,'This standalone edition keeps one draft on this device. Download JSON files to save multiple editable sheets. The WordPress plugin adds private account storage, history and sharing.');p.append(button('Download this sheet',jsonExport),button('Open a JSON sheet',()=>{close();$('file-input').click();}));});return;}
   const data=await api('sheets');
   modal('My private sheets',p=>{
     explain(p,`Showing up to 200 sheets. ${data.plan==='pro'?'Pro account':'Free account'}.`);
     if(!data.items.length)explain(p,'No cloud sheets yet. Close this panel and press Save.');
     data.items.forEach(item=>{const row=E('div','library-row');row.append(E('strong','',item.title));row.append(button('Open',async()=>{try{if(dirty && !confirm('Open another sheet? Download a JSON backup first if needed.'))return;const r=await api('sheets/'+item.id);replace(r.document,{id:r.id,revision:r.revision});close();}catch(e){toast(e.message);}},'small'),button('History',()=>versions(item.id),'small'),button('Delete',async()=>{if(confirm('Permanently delete this sheet, its history and share link?')){await api('sheets/'+item.id,{method:'DELETE'});if(cloud.id===item.id){cloud={id:0,revision:0};persist();}close();library();}},'small danger'));p.append(row);});
     if(C.upgradeUrl)p.append(D.linked('Membership and billing',C.upgradeUrl));
   });
 }
 async function versions(id){try{const data=await api('sheets/'+id+'/revisions');close();modal('Saved history',p=>{explain(p,'Restore creates a new current revision; it does not erase the intervening edits.');for(const v of data.items){p.append(button('Restore revision '+v.revision+' | '+v.created_at,async()=>{const current=await api('sheets/'+id);replace(v.document,{id,revision:current.revision});dirty=true;await saveCloud();close();},'choice'));}});}catch(e){toast(e.message);}}
 function canPrint(){if(moveSnapshot){toast('Finish the move before exporting.');return false;}if(!lastLayout || lastLayout.remaining.length){toast('Unplaced content: resolve overflow before exporting. JSON includes all text.');return false;}if(doc.blocks.some(b=>b.image && !b.alt.trim())){toast('Add alternative text to every image before exporting.');return false;}return true;}
 function printSheet(){if(!canPrint())return;document.querySelector('.print-root')?.remove();const p=E('div','print-root');for(const page of $('preview').children)p.append(page.cloneNode(true));p.querySelectorAll('.editor-only').forEach(n=>n.remove());p.querySelectorAll('.selected-source,.layout-band').forEach(n=>{n.classList.remove('selected-source');if(n.classList.contains('layout-band'))n.remove();});document.body.append(p);const style=E('style');style.textContent='@page{size:'+({halfletter:'5.5in 8.5in',letter:'letter',legal:'legal',tabloid:'11in 17in',a5:'A5',a4:'A4',b5:'176mm 250mm',a3:'A3'}[doc.settings.paper])+';margin:0}';p.append(style);window.print();setTimeout(()=>p.remove(),1000);}
 function htmlExport(){if(!canPrint())return;const container=E('div','print-root');for(const page of $('preview').children)container.append(page.cloneNode(true));container.querySelectorAll('.editor-only').forEach(e=>e.remove());container.querySelectorAll('.layout-band').forEach(e=>e.remove());container.querySelectorAll('.selected-source').forEach(e=>e.classList.remove('selected-source'));
   const title=E('title','',doc.title),note=E('p','screen-note','Daf Studio source sheet. Browser print: matching paper, 100% scale, no margins or browser headers.');
   const css=(window.DAF_PRINT_CSS || '')+'\nbody{margin:24px;background:#ececf0}.daf-page{margin:20px auto;box-shadow:0 3px 20px #0002}.screen-note{text-align:center;font:14px system-ui}@media print{.screen-note{display:none!important}}@page{size:'+({halfletter:'5.5in 8.5in',letter:'letter',legal:'legal',tabloid:'11in 17in',a5:'A5',a4:'A4',b5:'176mm 250mm',a3:'A3'}[doc.settings.paper])+';margin:0}';
   download(safeName()+'.html','<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'+title.outerHTML+'<style>'+css+'</style></head><body>'+note.outerHTML+container.outerHTML+'</body></html>','text/html');
 }
 function exportDialog(){modal('Export your source sheet',p=>{explain(p,'Layout exports use the current browser fonts. Review preflight notes and print at 100% with matching paper, no margins, and browser headers/footers off.');p.append(button('Print / Save as PDF',()=>{close();printSheet();},'choice'),button('HTML source sheet (fixed layout)',()=>{htmlExport();},'choice'),button('Editable JSON backup',jsonExport,'choice'),button('Plain-text reading copy',()=>download(safeName()+'.txt',[doc.title,doc.subtitle,...doc.blocks.map(b=>[b.title,b.byline,b.text,b.source,b.license].filter(Boolean).join('\n'))].join('\n\n'),'text/plain'),'choice'));});}
 async function share(){
   if(!C.rest)return toast('Private sharing is available in the installed WordPress edition. Export HTML to share from this demo.');
   if(!canPrint())return;await saveCloud(false);if(!cloud.id || dirty)return;
   modal('Share a read-only sheet',p=>{explain(p,'A private link shows the latest saved version and can be opened by anyone who has it. It expires after 30 days; creating a new link revokes the old one. Verify your sources\u2019 licenses before distributing them.');
     let consent=false;field(p,'I have checked the rights to share these texts and images',false,v=>consent=v,'checkbox');
     p.append(button('Create / rotate link',async()=>{if(!consent)return toast('Confirm that you have checked the source rights.');try{const r=await api('sheets/'+cloud.id+'/share',{method:'POST',body:{rightsConfirmed:true}});const url=C.appUrl+'#share='+r.token;const box=field(p,'Read-only URL',url,()=>{});box.readOnly=true;box.select();toast('Share link created. Copy the URL.');}catch(e){toast(e.message);}},'primary'),button('Revoke link',async()=>{try{await api('sheets/'+cloud.id+'/share',{method:'DELETE'});toast('Share link revoked.');close();}catch(e){toast(e.message);}}));});
 }
 async function importFiles(files){
   if(readOnly)return;for(const file of Array.from(files).slice(0,20)){
     try{toast('Importing '+file.name+'...');const r=await window.DafImports.file(file,C.rest?api:null);
       if(r.document){if(confirm('Replace this workspace with '+file.name+'?'))replace(r.document);}
       else{change(()=>{if(doc.blocks.length+r.blocks.length>100)throw Error('100-block limit reached.');doc.blocks.push(...r.blocks);selected=r.blocks[0]?.id;},{inspector:true});}
       toast(r.notice || 'Sheet opened.');
     }catch(e){toast(file.name+': '+e.message);}
   }
 }
 function importDialog(){modal('Bring sources into the conversation',p=>{explain(p,'Drop files onto the canvas, paste into a source, or select files. DOCX and HTML become plain text; complex tables and footnotes need review. Files are parsed locally except PDFs, which use your private converter.');p.append(button('Choose files',()=>{close();$('file-input').click();},'primary'));divider(p,'Paste as a new source');let value='';field(p,'Pasted text','',v=>value=v,'textarea');p.append(button('Add pasted text',()=>{if(!value.trim())return;add(D.block({text:D.clean(value),title:'Pasted source'}));close();}));});}
 function sefaria(){modal('Add a Sefaria source',p=>{explain(p,'Retrieve a reference, review the specific edition and license, then add only the versions you need. API availability does not mean every translation permits commercial redistribution.');let ref='Genesis 1:1',version='source';field(p,'Reference',ref,v=>ref=v);field(p,'Version selector',version,v=>version=v,'select',[['source','Original / source language'],['translation','Preferred translation']]);p.append(button('Retrieve',async()=>{
     try{
       if(!C.rest)throw Error('Sefaria retrieval uses the WordPress plugin\u2019s allowlisted proxy. This offline demo does not contact Sefaria.');
       const r=await api('sefaria?ref='+encodeURIComponent(ref)+'&version='+version);const holder=E('div');p.append(holder);
       r.versions.forEach(v=>{const h=E('h3','',v.title),info=E('p','muted',[v.language,v.license || 'License not supplied'].filter(Boolean).join(' | ')),preview=E('p','',v.text.slice(0,550));holder.append(h,info,preview,button('Add this version',()=>{add(D.block({title:r.ref,text:v.text,source:v.title,url:r.url,license:v.license}));close();}));});
     }catch(e){toast(e.message);}
   },'primary'));});}
 function help(){modal('A familiar daf, an editable canvas',p=>{
   for(const [h,t] of [['1. Put texts in conversation','Add any number of sources (up to 100). Set primary texts to Center; send explanations, questions and parallels to Auto margin or either side. Use the four-arrow handles to move sources. The list reorders and the page recomposes before you release. Click a handle or the source menu for non-drag controls. Press Space on a focused handle, use Up/Down, then Enter to place or Escape to cancel.'],['2. Shape the page','Text, Sheet and Style separate the basic tasks; expandable sections hold advanced settings. The stepped layout measures real text, stacks notes, and lets the central text widen when margins end. Font size and zoom are separate: zoom never changes pagination.'],['3. Keep control of overflow','Continue is the safe default. Smaller-type mode respects a minimum. Endnote mode frees the central text. One-folio mode blocks layout export rather than hiding text. Keep-together is relaxed only for an oversized block, with a warning.'],['4. Work in two writing directions','Each paragraph gets a first-strong-character base direction. Hebrew uses its own font, including Unicode vowel and cantillation marks. Override direction for ambiguous paragraphs. No transliteration, translation or text reversal is performed.'],['5. Save and publish','JSON is your editable, portable master. HTML and browser PDF preserve the current composition, not an editable canvas. The WordPress version saves privately, handles conflict checks, and adds expiring Pro share links.'],['Limits of this beta','Not a facsimile compositor or full Canva replacement. Notes are linked, not guaranteed to stay on the same folio as their source. There is no simultaneous collaboration, OCR, table editor, LaTeX export or billing checkout inside the plugin. PDF conversion is an optional private Docker service.']]){p.append(E('h3','',h),E('p','',t));}
 });}
 function fileMenu(){modal('File',p=>{
   const row=(label,fn,description)=>{const b=button('',()=>{close();fn();},'choice');b.append(E('strong','',label),E('small','',description));p.append(b);};
   if(!readOnly){if(undo.length)row('Undo',actions.undo,'Reverse the most recent edit or move.');if(redo.length)row('Redo',actions.redo,'Restore the last undone change.');row('New blank sheet',actions.new,'Start with an empty daf.');row('Start from site default',actions.newDefault,'Reload the administrator-configured starter conversation and design.');row('My sheets',()=>library().catch(e=>toast(e.message)),C.rest?'Open private account sheets.':'Open or back up a local JSON sheet.');row('Import sources',importDialog,'Paste text or upload files.');row('Share',()=>share().catch(e=>toast(e.message)),C.rest?'Create or revoke a read-only link.':'Available in the WordPress edition.');}
   row('Help & shortcuts',help,'Moving sources, saving, and layout options.');
 });}
 function applyHistory(which){if(readOnly)return;mover?.cancel();const stack=which==='undo'?undo:redo,other=which==='undo'?redo:undo;if(!stack.length)return;other.push(D.clone(doc));doc=stack.pop();if(!doc.blocks.some(b=>b.id===selected))selected=doc.blocks[0]?.id;dirty=true;lastHistory=0;persist();list();inspect();schedule();queueSave();}
 const actions={new:()=>{if(confirm('Start a blank sheet? Download a JSON backup of unsaved work first.'))replace(D.empty());},newDefault:()=>{if(confirm('Replace this workspace with the site default? Download a JSON backup of unsaved work first.'))replace(defaultDocument());},add:()=>add(D.block({zone:doc.blocks.length?'auto':'main',role:doc.blocks.length?'commentary':'primary'})),library,save:()=>saveCloud(),share,import:importDialog,sefaria,export:exportDialog,help,file:fileMenu,sheet:()=>showTab('page'),dismiss:dismissPanels,
   undo:()=>applyHistory('undo'),redo:()=>applyHistory('redo'),fit:()=>{fitMode=true;fitZoom();},
   reading:()=>{reading=!reading;$('scale-box').classList.toggle('hidden',reading);$('reading').classList.toggle('hidden',!reading);root.querySelector('[data-action="reading"]').setAttribute('aria-pressed',String(reading));},
   focus:()=>{const active=root.classList.toggle('focus-mode');root.querySelector('[data-action="focus"]').setAttribute('aria-pressed',String(active));root.querySelector('[data-action="focus"]').textContent=active?'Exit focus':'Focus';requestAnimationFrame(()=>{if(fitMode)fitZoom();});},
   sources:()=>{if(document.querySelector('.sources-panel').classList.contains('open'))dismissPanels();else openPanel('sources');},inspector:()=>openPanel('inspector'),
   'collapse-left':()=>togglePanel('left'),'collapse-right':()=>togglePanel('right'),
   warnings:()=>modal('Check your sheet',p=>{const list=E('ul');for(const w of preflight())list.append(E('li','',w));p.append(list);if(!list.children.length)explain(p,'All source text is placed. Review printed Hebrew marks and source licenses before distribution.');explain(p,'Importing text does not verify factual accuracy, transliteration, or scholarly citations.');})};
 root.addEventListener('click',e=>{const tabButton=e.target.closest('[data-tab]');if(tabButton){showTab(tabButton.dataset.tab);return;}const b=e.target.closest('[data-action]');if(b){if(readOnly && !['export','help','fit','reading','warnings','sources','inspector','focus','file','dismiss','collapse-left','collapse-right','collapse-left','collapse-right'].includes(b.dataset.action))return;Promise.resolve().then(()=>actions[b.dataset.action]?.()).catch(e=>toast(e.message));}});
 mover=window.DafMove.create({root,enabled:()=>!readOnly&&!$('modal').open,items:()=>doc.blocks,settings:()=>doc.settings,menu:sourceActions,
   begin:id=>{clearTimeout(saveTimer);moveSnapshot=D.clone(doc);moveWasDirty=dirty;movePreviousSelection=selected;selected=id;tab='text';syncListOrder(false);inspect();markSelection();},
   listTarget:id=>{const b=doc.blocks.find(x=>x.id===id),original=moveSnapshot?.blocks.find(x=>x.id===id);if(b&&original&&b.zone!==original.zone){b.zone=original.zone;if($('source-placement'))$('source-placement').value=b.zone;syncListOrder(false);schedule(true);}},
   pageTarget:()=>{if(moveSnapshot && doc.blocks.some((b,i)=>b.id!==moveSnapshot.blocks[i].id)){const current=new Map(doc.blocks.map(b=>[b.id,b]));doc.blocks=moveSnapshot.blocks.map(b=>current.get(b.id));syncListOrder(false);schedule(true);}},
   reorder:(id,index)=>{const at=doc.blocks.findIndex(b=>b.id===id);if(at<0)return;const [b]=doc.blocks.splice(at,1);doc.blocks.splice(index,0,b);syncListOrder();schedule(true);},
   zone:(id,zone)=>{const b=doc.blocks.find(x=>x.id===id);if(!b)return;b.zone=zone;if($('source-placement'))$('source-placement').value=zone;syncListOrder(false);schedule(true);},
   end:(commit,id)=>{const original=moveSnapshot;moveSnapshot=null;if(!original)return;const changed=JSON.stringify(original)!==JSON.stringify(doc);
     if(commit&&changed){undo.push(original);if(undo.length>35)undo.shift();redo=[];dirty=true;lastHistory=0;toast('Source moved.','Undo',()=>actions.undo());}
     else{doc=original;dirty=moveWasDirty;if(!commit)selected=movePreviousSelection;}
     persist();list();inspect();markSelection();schedule();queueSave();}
 });
 $('preview').onclick=e=>{if(e.target.closest('[data-drag-id]'))return;const jump=e.target.closest('[data-jump]');if(jump){e.preventDefault();document.getElementById('src-'+jump.dataset.jump)?.scrollIntoView({behavior:'smooth',block:'center'});return;}const source=e.target.closest('[data-source-id]');if(source)select(source.dataset.sourceId);};
 $('preview').ondblclick=e=>{if(e.target.closest('[data-drag-id]'))return;const el=e.target.closest('[data-source-id]');if(el){select(el.dataset.sourceId);$('source-richtext')?.focus();}};
 $('zoom').oninput=e=>{fitMode=false;zoom=+e.target.value/100;applyZoom();};$('file-input').onchange=e=>{importFiles(e.target.files);e.target.value='';};
 // Native file/text drop remains separate from pointer-based source moving.
 // Source cards deliberately do not use HTML draggable/DataTransfer.
 $('canvas-pane').ondragover=e=>{if(!readOnly && !mover.isActive()){e.preventDefault();$('canvas-pane').classList.add('file-hover');}};
 $('canvas-pane').ondragleave=e=>{if(!$('canvas-pane').contains(e.relatedTarget))$('canvas-pane').classList.remove('file-hover');};
 $('canvas-pane').ondrop=e=>{
   $('canvas-pane').classList.remove('file-hover');if(readOnly||mover.isActive())return;e.preventDefault();
   if(e.dataTransfer.files.length)return importFiles(e.dataTransfer.files);
   const text=e.dataTransfer.getData('text/plain');if(text)add(D.block({text:D.clean(text),title:'Dropped text'}));
 };
 document.addEventListener('paste',e=>{if(readOnly || e.target.closest('input,textarea,[contenteditable]'))return;const t=e.clipboardData.getData('text/plain');if(t){e.preventDefault();add(D.block({text:D.clean(t),title:'Pasted text'}));}});
 document.addEventListener('keydown',e=>{if(e.key==='Escape')dismissPanels();if(mover.isActive())return;if((e.ctrlKey||e.metaKey) && e.key==='s'){e.preventDefault();saveCloud().catch(x=>toast(x.message));}if((e.ctrlKey||e.metaKey) && e.key==='z' && !e.target.closest('input,textarea') && !readOnly){e.preventDefault();actions[e.shiftKey?'redo':'undo']();}});
 window.addEventListener('beforeunload',e=>{if(C.rest && dirty){e.preventDefault();e.returnValue='';}});
 $('plan-status').textContent=C.rest?(C.plan==='pro'?'Pro workspace':'Free workspace'):'Local demo | no account required';
 async function boot(){
   const token=new URLSearchParams(location.hash.slice(1)).get('share');
   if(token && C.rest){readOnly=true;try{const r=await api('shared',{headers:{'X-Daf-Share':token}});doc=D.normalize(r.document);selected=doc.blocks[0]?.id;}catch(e){root.replaceChildren(E('p','tip','This share link is unavailable: '+e.message));return;}}
   if(readOnly){root.querySelectorAll('[data-action]').forEach(b=>{if(!['export','help','fit','reading','warnings','sources','inspector','focus','file','dismiss','collapse-left','collapse-right','collapse-left','collapse-right'].includes(b.dataset.action))b.classList.add('hidden');});$('save-status').textContent='Read-only shared sheet';}
   syncPanelState();list();inspect();await render();actions.fit();
 }
 new ResizeObserver(()=>{if(fitMode&&lastLayout)fitZoom();}).observe($('canvas-pane'));
 window.DafApp={getDocument:()=>D.clone(doc),setDocument:replace,getLayout:()=>lastLayout,render,preflight,api,getMoveState:()=>mover.state(),cancelMove:()=>mover.cancel()};
 boot();
})();
