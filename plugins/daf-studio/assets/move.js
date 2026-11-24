/* Daf Studio 0.4.0 | Pointer + keyboard reorder. No external runtime dependency.
 * Capture on the stable application root, NEVER on a row that may move/re-render.
 * Preview changes are transactional: commit once on a valid release, cancel otherwise.
 */
(function () {
  'use strict';
  const MOVE_SVG = '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v18M3 12h18M9 6l3-3 3 3M9 18l3 3 3-3M6 9l-3 3 3 3M18 9l3 3-3 3"/></svg>';
  function create(options) {
    const {root} = options;
    let session = null, frame = 0, ghost = null, ignoreClickUntil = 0;
    const list = () => document.getElementById('source-list');
    const announce = text => { document.getElementById('move-announcer').textContent = text; };
    const hint = text => { document.getElementById('move-feedback').textContent = text; };
    const title = id => options.items().find(b => b.id === id)?.title || 'Untitled source';
    const focusHandle = id => root.querySelector('#source-list [data-drag-id="'+id+'"]')?.focus({preventScroll:true});
    const describe = () => 'Position '+(options.items().findIndex(b=>b.id===session.id)+1)+' of '+options.items().length;
    function begin() {
      session.started = true;
      options.begin(session.id);
      root.classList.add('is-moving');
      root.classList.toggle('keyboard-moving',session.mode==='keyboard');
      document.body.classList.add('source-moving');
      root.querySelectorAll('[data-drag-id="'+session.id+'"]').forEach(el=>el.setAttribute('aria-pressed','true'));
      if(session.mode === 'pointer') {
        ghost=document.createElement('div');ghost.className='move-ghost';ghost.setAttribute('aria-hidden','true');
        ghost.innerHTML=MOVE_SVG+'<div><strong></strong><small></small></div>';
        ghost.querySelector('strong').textContent=title(session.id);document.body.append(ghost);
      }
      announce('Picked up '+title(session.id)+'. '+describe()+'. '+(session.mode==='keyboard'?'Up and down to move. Enter to place. Escape to cancel.':'Drag to reorder or choose a page area. Escape cancels.'));
      hint(session.mode==='keyboard'?'Moving source: Up / Down to reorder. Enter to place. Esc to cancel.':'Drag to reorder or choose a page area. Release to place. Esc cancels.');
      if(session.mode==='keyboard')focusHandle(session.id);
    }
    function clearTargets() {
      root.querySelectorAll('.drop-active').forEach(e=>e.classList.remove('drop-active'));
      list()?.classList.remove('receiving');
    }
    function feedback(text,valid=true) {
      if(!session)return;
      session.valid=valid;session.targetLabel=text;
      if(ghost){ghost.classList.toggle('invalid',!valid);ghost.querySelector('small').textContent=text;}
      hint(text+' '+(valid?'Release to place. Esc cancels.':'Release here to cancel.'));
    }
    function reorder(index) {
      const items=options.items(),current=items.findIndex(b=>b.id===session.id);
      index=Math.max(0,Math.min(items.length-1,index));
      if(current!==index){options.reorder(session.id,index);announce(title(session.id)+'. '+describe()+'.');}
    }
    function pointerTarget() {
      if(!session?.started || session.mode!=='pointer')return;
      const {x,y}=session;
      if(ghost){const w=ghost.offsetWidth;ghost.style.left=Math.min(window.innerWidth-w-8,Math.max(8,x+15))+'px';ghost.style.top=Math.min(window.innerHeight-70,Math.max(8,y+15))+'px';}
      clearTargets();
      const hit=document.elementFromPoint(x,y),ol=list();
      if(hit && ol?.contains(hit)) {
        if(session.targetKind!=='list')options.listTarget?.(session.id);session.targetKind='list';
        const r=ol.getBoundingClientRect();const yy=y-r.top+ol.scrollTop;
        // offsetTop is unanimated layout geometry; FLIP transforms never affect hit thresholds.
        const others=[...ol.querySelectorAll('.source-card')].filter(e=>e.dataset.id!==session.id);
        let index=0;for(const row of others)if(yy>=row.offsetTop+row.offsetHeight/2)index++;
        session.physical=null;reorder(index);ol.classList.add('receiving');
        feedback('Reorder: '+describe());return;
      }
      const paper=hit?.closest('#preview .daf-page');
      if(paper){
        if(session.targetKind!=='page')options.pageTarget?.(session.id);session.targetKind='page';
        const pages=[...document.querySelectorAll('#preview .daf-page')],pageIndex=pages.indexOf(paper);
        const r=paper.getBoundingClientRect(),settings=options.settings();
        const single=settings.layout==='reading'||settings.overflow==='endnotes';
        const frac=(x-r.left)/r.width,physical=single?'main':frac<.27?'left':frac>.73?'right':'main';
        const mirror=settings.mirror && (pageIndex+(settings.side==='b'?1:0))%2===1;
        const zone=mirror&&physical!=='main'?(physical==='left'?'right':'left'):physical;
        paper.querySelector('[data-physical-zone="'+physical+'"]')?.classList.add('drop-active');
        if(options.items().find(b=>b.id===session.id)?.zone!==zone){options.zone(session.id,zone);announce(title(session.id)+' moved to '+(physical==='main'?'center':physical+' margin')+'.');}
        session.pageIndex=pageIndex;session.physical=physical;
        feedback('Place in '+(physical==='main'?'center':physical+' margin')+(mirror&&physical!=='main'?' on this mirrored folio':''));return;
      }
      session.physical=null;feedback('Not a drop area.',false);
    }
    function autoscroll() {
      if(!session?.started || session.mode!=='pointer')return;
      const {x,y}=session;
      for(const el of [list(),document.getElementById('canvas-pane')]) {
        if(!el || el.scrollHeight<=el.clientHeight)continue;
        const r=el.getBoundingClientRect();if(x<r.left||x>r.right||y<r.top||y>r.bottom)continue;
        // Do not scroll the canvas underneath an open mobile drawer.
        const hit=document.elementFromPoint(x,y);if(!hit||!el.contains(hit))continue;
        const edge=46;let delta=0;
        if(y<r.top+edge)delta=-Math.ceil((r.top+edge-y)/edge*13);
        if(y>r.bottom-edge)delta=Math.ceil((y-(r.bottom-edge))/edge*13);
        const before=el.scrollTop;if(delta)el.scrollTop+=delta;
        if(el.scrollTop!==before)pointerTarget();
      }
      frame=requestAnimationFrame(autoscroll);
    }
    function finish(commit) {
      if(!session)return;
      const current=session;session=null;cancelAnimationFrame(frame);
      if(current.pointerId!==undefined && root.hasPointerCapture?.(current.pointerId))root.releasePointerCapture(current.pointerId);
      if(!current.started)return;
      ignoreClickUntil=Date.now()+300;
      clearTargets();ghost?.remove();ghost=null;
      root.classList.remove('is-moving','keyboard-moving');document.body.classList.remove('source-moving');
      root.querySelectorAll('[data-drag-id]').forEach(e=>e.setAttribute('aria-pressed','false'));
      options.end(!!commit,current.id);hint('');
      const index=options.items().findIndex(b=>b.id===current.id);
      announce(commit?'Placed '+title(current.id)+'. Position '+(index+1)+' of '+options.items().length+'.':'Move cancelled. Original order and placement restored.');
      requestAnimationFrame(()=>focusHandle(current.id));
    }
    root.addEventListener('pointerdown',e=>{
      const handle=e.target.closest('[data-drag-id]');
      if(!handle || !options.enabled() || e.button!==0 || e.isPrimary===false || session)return;
      e.preventDefault();handle.focus({preventScroll:true});
      session={id:handle.dataset.dragId,mode:'pointer',started:false,pointerId:e.pointerId,startX:e.clientX,startY:e.clientY,x:e.clientX,y:e.clientY,valid:false,threshold:e.pointerType==='touch'?9:6};
      try{root.setPointerCapture(e.pointerId);}catch(_){session=null;}
    });
    root.addEventListener('pointermove',e=>{
      if(!session || session.mode!=='pointer' || session.pointerId!==e.pointerId)return;
      session.x=e.clientX;session.y=e.clientY;
      if(!session.started){if(Math.hypot(e.clientX-session.startX,e.clientY-session.startY)<session.threshold)return;begin();autoscroll();}
      e.preventDefault();pointerTarget();
    });
    root.addEventListener('pointerup',e=>{
      if(session?.mode!=='pointer'||session.pointerId!==e.pointerId)return;
      if(!session.started){const id=session.id;finish(false);ignoreClickUntil=Date.now()+300;options.menu(id);return;}
      session.x=e.clientX;session.y=e.clientY;pointerTarget();finish(session.valid);
    });
    root.addEventListener('pointercancel',e=>{if(session?.pointerId===e.pointerId)finish(false);});
    root.addEventListener('lostpointercapture',e=>{if(session?.pointerId===e.pointerId)finish(false);});
    root.addEventListener('dragstart',e=>{if(e.target.closest('[data-drag-id],.source-card'))e.preventDefault();});
    root.addEventListener('click',e=>{
      if(Date.now()<ignoreClickUntil && (e.target===root || e.target.closest('[data-drag-id]'))){e.preventDefault();e.stopImmediatePropagation();return;}
      const h=e.target.closest('[data-drag-id]');if(h && options.enabled()){e.preventDefault();e.stopImmediatePropagation();options.menu(h.dataset.dragId);}
    },true);
    document.addEventListener('keydown',e=>{
      if(e.key==='Escape'&&session){e.preventDefault();e.stopImmediatePropagation();finish(false);return;}
      if(session?.mode==='pointer')return;
      const handle=e.target.closest?.('[data-drag-id]');
      if(!session && handle && options.enabled() && [' ','Enter'].includes(e.key)){
        e.preventDefault();e.stopImmediatePropagation();session={id:handle.dataset.dragId,mode:'keyboard',started:false,valid:true};begin();return;
      }
      if(session?.mode!=='keyboard')return;
      const index=options.items().findIndex(b=>b.id===session.id);
      if([' ','Enter'].includes(e.key)){e.preventDefault();e.stopImmediatePropagation();finish(true);}
      else if(['ArrowUp','ArrowDown','Home','End'].includes(e.key)){
        e.preventDefault();e.stopImmediatePropagation();reorder(e.key==='Home'?0:e.key==='End'?options.items().length-1:index+(e.key==='ArrowDown'?1:-1));
        focusHandle(session.id);root.querySelector('.source-card[data-id="'+session.id+'"]')?.scrollIntoView({block:'nearest',behavior:'instant'});
        hint(describe()+'. Up / Down to reorder. Enter to place. Esc cancels.');
      }else if(e.key==='Tab')finish(false);
    },true);
    window.addEventListener('blur',()=>finish(false));
    return {cancel:()=>finish(false),isActive:()=>!!session?.started,
      state:()=>session?.started?{id:session.id,mode:session.mode,valid:session.valid}:null,
      refresh:()=>{if(!session?.started)return;root.classList.add('is-moving');
        root.querySelectorAll('.source-card').forEach(e=>e.classList.toggle('moving',e.dataset.id===session.id));
        if(session.mode==='pointer'&&session.valid&&session.physical){document.querySelectorAll('#preview .daf-page')[session.pageIndex]?.querySelector('[data-physical-zone="'+session.physical+'"]')?.classList.add('drop-active');}
      }};
  }
  window.DafMove={create,icon:MOVE_SVG};
})();
