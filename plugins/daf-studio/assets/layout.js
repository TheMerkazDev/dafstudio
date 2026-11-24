/* Measured, stepped-band compositor. Browser shaping preserves niqqud/te'amim. */
(function(){
  'use strict'; const D=window.Daf, E=D.element;
  const SIZES={halfletter:[528,816],letter:[816,1056],legal:[816,1344],tabloid:[1056,1632],a5:[559,794],a4:[794,1123],b5:[665,945],a3:[1123,1587]};
  let runId=0;
  function stylePage(el,s,scale) {
    el.style.setProperty('--latin',`"${s.latin}", Georgia, serif`);
    el.style.setProperty('--hebrew',`"${s.hebrew}", "Noto Sans Hebrew", "Times New Roman", serif`);
    el.style.setProperty('--ink',s.ink); el.style.setProperty('--accent',s.accent);
    el.style.setProperty('--paper',s.paperColor); el.style.setProperty('--leading',s.leading);
    el.style.setProperty('--main-size',s.mainSize*scale+'px');
    el.style.setProperty('--note-size',s.noteSize*scale+'px');
  }
  function fragment(cursor,count,s,scale,rehead=false) {
    const b=cursor.b, first=cursor.offset===0, wrap=E('section','daf-fragment '+(b.zone==='main'?'primary':'note'));
    wrap.dataset.sourceId=b.id; wrap.dataset.offset=cursor.offset; wrap.dataset.count=count;
    wrap.setAttribute('aria-label',b.title || b.role);
    const base=b.size || (b.zone==='main'?s.mainSize:s.noteSize);
    wrap.style.fontSize=base*scale+'px'; if(b.color)wrap.style.color=b.color;
    if(first || rehead){
      const h=E('h3','source-heading'); h.dir=b.direction; h.append(D.linked(b.title || b.role,b.url));
      if(!first) h.append(E('small','continued',' (continued)'));
      wrap.append(h);
      if(b.byline) wrap.append(D.linked(b.byline,b.authorUrl,'byline'));
      if(b.parentId){const ref=E('a','related','Related source'); ref.textContent='Related source';ref.href='#src-'+b.parentId;ref.dataset.jump=b.parentId;wrap.append(ref);}
    }
    if(first && b.image){const image=E('img','source-image');image.src=b.image;image.alt=b.alt;wrap.append(image);}
    const slice=b.text.slice(cursor.offset,cursor.offset+count);
    const parts=[];let start=0;
    for(const m of slice.matchAll(/\n+/g)){parts.push({text:slice.slice(start,m.index),at:cursor.offset+start});start=m.index+m[0].length;}
    parts.push({text:slice.slice(start),at:cursor.offset+start});
    parts.forEach((part,i)=>{
      const p=part.text;
      if(!p && parts.length===1)return;
      // A continuation fragment inherits the ORIGINAL paragraph base direction.
      // Do not redetect it from a Hebrew/Latin phrase at the next band boundary.
      const ps=b.text.lastIndexOf('\n',part.at-1)+1,pe=b.text.indexOf('\n',part.at);
      const originalParagraph=b.text.slice(ps,pe<0?undefined:pe);
      const el=E('p'); el.dir=b.direction==='auto'?D.paragraphDirection(originalParagraph):b.direction;
      if(p) D.scriptRichText(el,p,b.marks||[],part.at); else el.append(document.createElement('br'));
      if(cursor.offset>0 && i===0 && b.text[cursor.offset-1]!=='\n')el.classList.add('continued-paragraph');
      wrap.append(el);
    });
    if(s.showSources && cursor.offset+count>=b.text.length && (b.source || b.license)) {
      const foot=E('div','source-citation');foot.dir='auto';foot.append(D.linked([b.source,b.license].filter(Boolean).join(' | '),b.url));wrap.append(foot);
    }
    return wrap;
  }
  function reservoir(blocks){return blocks.map(b=>({b,offset:0,page:-1}));}
  function weight(b){return b.text.length+(b.image?1100:0)+b.title.length*3+70;}
  function lanes(d) {
    let main=[],left=[],right=[],end=[],wl=0,wr=0;
    const all=d.blocks.filter(b=>b.title || b.text || b.image);
    if(d.settings.layout==='reading')return {main:reservoir(all.map(b=>({...b,zone:'main'}))),left:[],right:[]};
    for(const b0 of all){const b={...b0};
      if(b.zone==='main')main.push(b);
      else if(b.zone==='end' || d.settings.overflow==='endnotes')end.push({...b,zone:'main'});
      else if(b.zone==='left'){left.push(b);wl+=weight(b);}
      else if(b.zone==='right'){right.push(b);wr+=weight(b);}
      else if(wl<=wr){left.push(b);wl+=weight(b);}else{right.push(b);wr+=weight(b);}
    }
    if(!main.length && !end.length)return {main:reservoir(all.map(b=>({...b,zone:'main'}))),left:[],right:[]};
    main.push(...end);
    return {main:reservoir(main),left:reservoir(left),right:reservoir(right)};
  }
  async function compose(d,host,scale,job) {
    const s=d.settings, [PW,PH]=SIZES[s.paper], W=PW-2*s.margin;
    const scratch=E('div','daf-page measure-page');stylePage(scratch,s,scale);
    scratch.style.width=PW+'px';document.body.append(scratch);
    const meter=E('div');scratch.append(meter);
    const notices=new Set();const sourcePages=Object.create(null);const q=lanes(d);const pages=[];
    const totalWeight=Object.values(q).flat().reduce((n,c)=>n+weight(c.b),0);
    const measure=(c,n,w,rehead=false)=>{
      meter.style.width=w+'px';meter.replaceChildren(fragment(c,n,s,scale,rehead));
      return meter.firstChild.getBoundingClientRect().height;
    };
    function fit(c,w,h,rehead=false,force=false) {
      const remaining=c.b.text.length-c.offset;
      const full=measure(c,remaining,w,rehead);
      if(full<=h+.2)return {n:remaining,h:full,complete:true};
      if(h<40 || (c.b.image && c.offset===0 && h<460))return null;
      if(c.b.keepTogether && !force)return null;
      let candidates=D.boundaries(c.b.text.slice(c.offset)),best=-1,lo=1,hi=candidates.length-1;
      while(lo<=hi){const m=(lo+hi)>>1; if(measure(c,candidates[m],w,rehead)<=h){best=m;lo=m+1;}else hi=m-1;}
      if(best<1){candidates=D.boundaries(c.b.text.slice(c.offset),true);lo=1;hi=candidates.length-1;
        while(lo<=hi){const m=(lo+hi)>>1;if(measure(c,candidates[m],w,rehead)<=h){best=m;lo=m+1;}else hi=m-1;}}
      if(best<1)return null;
      let n=candidates[best],mh=measure(c,n,w,rehead);
      // Soft two-line minimum: headings never left without meaningful text.
      const bare=measure(c,0,w,rehead),line=(c.b.size || (c.b.zone==='main'?s.mainSize:s.noteSize))*scale*s.leading;
      if(n<remaining && mh-bare<(c.offset===0 || rehead ? line*1.5 : line*.9))return null;
      if(c.b.keepTogether)notices.add('An oversized keep-together block was split; no text was discarded.');
      return {n,h:mh,complete:false};
    }
    function commit(c,f,parent,x,y,w,page,rehead) {
      const node=fragment(c,f.n,s,scale,rehead);node.style.cssText+=';position:absolute;left:'+x+'px;top:'+y+'px;width:'+w+'px;';
      if(!sourcePages[c.b.id]){sourcePages[c.b.id]=[];node.id='src-'+c.b.id;}
      if(!sourcePages[c.b.id].includes(page+1))sourcePages[c.b.id].push(page+1);
      parent.append(node); c.offset+=f.n;c.page=page;
      return node;
    }
    try{
      for(let page=0;Object.values(q).some(v=>v.length);page++){
        if(job!==runId)throw Error('cancelled');
        if(page>=D.LIMITS.pages){notices.add('60-page safety limit reached. Export is blocked; split this sheet.');break;}
        if(s.overflow==='strict' && page>0)break;
        const paper=E('article','daf-page');stylePage(paper,s,scale);paper.style.width=PW+'px';paper.style.height=PH+'px';paper.style.padding=s.margin+'px';
        paper.setAttribute('aria-label','Folio '+D.folio(s,page));
        const header=E('header','daf-header');const meta=E('div','daf-running');
        meta.append(E('span','',D.folio(s,page)),D.linked(d.author,d.authorUrl));header.append(meta);
        const title=E('h1');title.dir='auto';title.style.fontSize=s.headerSize+'px';title.append(D.linked(d.title,d.titleUrl));header.append(title);
        if(d.subtitle){const sub=E('div','daf-subtitle');sub.dir='auto';D.scriptText(sub,d.subtitle);header.append(sub);}
        paper.append(header); host.append(paper);
        const hh=header.getBoundingClientRect().height;
        const H=PH-2*s.margin-hh-40;
        const body=E('div','daf-body');body.style.height=H+'px';paper.append(body);
        const footer=E('footer','daf-footer');footer.append(E('span','',s.footer),E('span','',D.folio(s,page)));paper.append(footer);
        if(H<200){notices.add('Header leaves too little room for text. Shorten it or enlarge the paper.');break;}
        let progress=0;const placed={left:[],right:[]};
        const mirror=s.mirror && (page+(s.side==='b'?1:0))%2===1;
        for(const side of ['left','right']){
          const a=q[side];let y=0;
          while(a.length && y<H-55){
            const c=a[0];if(c.b.breakBefore && c.offset===0 && (y>0))break;
            const share=weight(c.b)/Math.max(totalWeight,1);
            const raw=s.layout==='columns'?.23:(.17+Math.min(.095,share*.34));
            const w=Math.min(W*.29,Math.max(W*.135,W*raw*c.b.width));
            const rehead=c.offset>0 && c.page!==page;
            let f=fit(c,w,H-y,rehead,false);
            if(!f && y===0)f=fit(c,w,H,rehead,true);
            if(!f)break;
            const physical=mirror?(side==='left'?'right':'left'):side;
            commit(c,f,body,physical==='left'?0:W-w,y,w,page,rehead);
            placed[physical].push({top:y,bottom:y+f.h,width:w});progress++;
            y+=f.h+s.gap;
            if(f.complete)a.shift();else break;
          }
        }
        let edges=[0,H];
        if(s.layout==='columns'){
          for(const side of ['left','right'])if(placed[side].length)placed[side]=[{top:0,bottom:H,width:Math.max(...placed[side].map(x=>x.width))}];
        }else for(const p of Object.values(placed).flat())edges.push(p.top,Math.min(H,p.bottom+s.gap));
        edges=[...new Set(edges)].sort((a,b)=>a-b);
        let forceNext=false,mainProgress=0;
        for(let band=0;band<edges.length-1 && !forceNext;band++){
          const top=edges[band],bottom=edges[band+1];if(bottom-top<35)continue;
          const l=placed.left.find(p=>p.top<=top+.5 && p.bottom+s.gap>top+.5);
          const r=placed.right.find(p=>p.top<=top+.5 && p.bottom+s.gap>top+.5);
          const x=l?l.width+s.gap:0,w=W-x-(r?r.width+s.gap:0);let y=top;
          if(s.debug){const box=E('div','layout-band');box.style.cssText=`left:${x}px;top:${top}px;width:${w}px;height:${bottom-top}px`;body.append(box);}
          while(q.main.length && y<bottom-25){
            const c=q.main[0],rehead=c.offset>0 && c.page!==page;
            if(c.b.breakBefore && c.offset===0 && sourcePages[c.b.id]===undefined && mainProgress>0){forceNext=true;break;}
            let f=fit(c,w,bottom-y,rehead,false);
            // At the top of a fresh page, relax keep-together only if it cannot fit a full page.
            if(!f && measure(c,c.b.text.length-c.offset,w,rehead)>H)f=fit(c,w,bottom-y,rehead,true);
            if(!f)break;
            commit(c,f,body,x,y,w,page,rehead);progress++;mainProgress++;
            y+=f.h+(f.complete?s.gap*.7:0);
            if(f.complete)q.main.shift();else break;
          }
        }
        // If an empty band geometry strands a kept block, carry it to a full-width page.
        if(!progress && q.main.length){
          const c=q.main[0],f=fit(c,W,H,c.offset>0,true);
          if(f){commit(c,f,body,0,0,W,page,c.offset>0);progress++;if(f.complete)q.main.shift();}
        }
        if(!progress){paper.remove();notices.add('A block cannot fit these settings. Increase the page size or reduce type.');break;}
        pages.push(paper);
        if(page%3===0)await new Promise(r=>setTimeout(r,0));
      }
      const remaining=Object.values(q).flat().map(c=>({id:c.b.id,title:c.b.title,characters:c.b.text.length-c.offset}));
      if(remaining.length)notices.add(`${remaining.length} source block(s) have unplaced content. Printing is blocked; JSON backup remains complete.`);
      if(!pages.length && !d.blocks.length){const blank=E('article','daf-page blank-page','Add a primary text to begin your daf.');blank.style.width=PW+'px';blank.style.height=PH+'px';host.append(blank);pages.push(blank);}
      for(const b of d.blocks){if(b.parentId && sourcePages[b.id] && sourcePages[b.parentId] && sourcePages[b.id][0]!==sourcePages[b.parentId][0])notices.add('Some related notes begin on a different folio from their source. Relation links remain intact.');}
      if(!D.LATIN.includes(s.latin) || !D.HEBREW.includes(s.hebrew))notices.add('A font was replaced by a safe fallback.');
      return {pages:pages.length,notices:[...notices],remaining,scale,sourcePages,width:PW,height:PH};
    }finally{scratch.remove();}
  }
  async function render(d,target) {
    const job=++runId;await document.fonts.ready;
    // Offscreen composition is unscaled: zoom never changes pagination.
    const host=E('div','composition-host');host.style.cssText='position:fixed;left:-20000px;top:0;visibility:hidden;';document.body.append(host);
    try{
      let result=await compose(d,host,1,job);
      if(d.settings.overflow==='shrink' && result.pages>1){
        const min=Math.min(1,d.settings.minSize/Math.min(d.settings.mainSize,d.settings.noteSize,...d.blocks.filter(b=>b.size>0).map(b=>b.size)));
        const scales=[.95,.9,.85,.8,.75,.7,.65,.6,.55,.5].filter(v=>v>min);if(min<1)scales.push(min);
        for(const scale of scales){
          host.replaceChildren();result=await compose(d,host,Math.max(min,scale),job);if(result.pages===1)break;
        }
        if(result.scale<1)result.notices.unshift('Type reduced to '+Math.round(result.scale*100)+'% within the configured minimum.');
        if(result.pages>1)result.notices.unshift('Content still needs multiple folios at the minimum type size.');
      }
      if(job!==runId)return null;
      target.replaceChildren(...host.childNodes);return result;
    }catch(err){if(err.message==='cancelled')return null;throw err;}finally{host.remove();}
  }
  window.DafLayout={render,fragment,SIZES};
})();
