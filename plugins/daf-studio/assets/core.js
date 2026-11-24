/* Daf Studio 0.4.0 | GPL-2.0-or-later | No third-party runtime dependencies. */
(function (global) {
  'use strict';
  const CONFIG=global.DAF_CONFIG||{};
  const LIMITS = Object.freeze({blocks:100, chars:300000, perBlock:100000, pages:60, fileBytes:8388608});
  const LATIN = ['Arial','Times New Roman','Crimson Text','EB Garamond','Roboto','Calibri','Georgia','Verdana'];
  const HEBREW = ['Ezra SIL','SBL Hebrew','David','Frank Ruhl Libre','Noto Rashi Hebrew','Noto Sans Hebrew','Noto Serif Hebrew'];
  const PAPERS = Object.freeze({halfletter:'US Half Letter (5.5 × 8.5 in)',letter:'US Letter (8.5 × 11 in)',legal:'US Legal (8.5 × 14 in)',tabloid:'US Tabloid (11 × 17 in)',a5:'A5 (148 × 210 mm)',a4:'A4 (210 × 297 mm)',b5:'B5 (176 × 250 mm)',a3:'A3 (297 × 420 mm)'});
  const BASE_SETTINGS = {paper:'letter', margin:34, mainSize:16, noteSize:14, minSize:12,
    leading:1.48, gap:15, layout:'stepped', overflow:'continue', latin:'EB Garamond',
    hebrew:'Noto Serif Hebrew', ink:'#25221f', accent:'#65513a', paperColor:'#fffdf7',
    headerSize:28, folio:2, side:'a', numbering:'daf', mirror:false, showSources:true,
    footer:'A source sheet for learning and discussion', debug:false};
  function runtimeSettings(raw){
    const out={...BASE_SETTINGS},r=raw&&typeof raw==='object'?raw:{};
    const enumMap={paper:Object.keys(PAPERS),layout:['stepped','columns','reading'],overflow:['continue','shrink','endnotes','strict'],latin:LATIN,hebrew:HEBREW,side:['a','b'],numbering:['daf','page']};
    for(const [k,values] of Object.entries(enumMap))if(values.includes(r[k]))out[k]=r[k];
    for(const [k,a,b] of [['margin',20,65],['mainSize',12,28],['noteSize',11,24],['minSize',10,20],['leading',1.25,2],['gap',8,30],['headerSize',18,38],['folio',1,999]])if(Number.isFinite(+r[k]))out[k]=Math.min(b,Math.max(a,+r[k]));
    for(const k of ['ink','accent','paperColor'])if(/^#[a-f0-9]{6}$/i.test(r[k]||''))out[k]=r[k];
    for(const k of ['mirror','showSources'])if(typeof r[k]==='boolean')out[k]=r[k];
    if(typeof r.footer==='string')out.footer=r.footer.slice(0,160);
    return out;
  }
  const SETTINGS = runtimeSettings(CONFIG.defaultSettings);
  const ZONES = ['main','auto','left','right','end'];
  const ROLES = ['primary','mishnah','gemara','rashi','tosafot','commentary','question','sources','conclusion'];
  const uid = () => 's' + (global.crypto?.randomUUID?.() || Math.random().toString(36).slice(2) + Date.now().toString(36));
  const clone = v => JSON.parse(JSON.stringify(v));
  const clamp = (n,a,b,f) => Number.isFinite(+n) ? Math.min(b,Math.max(a,+n)) : f;
  const safeUrl = value => {try {const u=new URL(String(value)); return ['https:','http:'].includes(u.protocol) ? u.href : '';} catch (_) {return '';}};
  const clean = v => String(v ?? '').replace(/\r\n?/g,'\n').replace(/[\u0000-\u0008\u000B\u000C\u000E-\u001F\u202A-\u202E]/g,'');
  const text = (v,max) => clean(v).slice(0,max);
  const color = (s,f) => /^#[a-f0-9]{6}$/i.test(s || '') ? s : f;
  function block(data={}) {
    return {id:uid(), title:'New source', text:'', role:'commentary', zone:'auto', byline:'', url:'',
      authorUrl:'', source:'', license:'', direction:'auto', size:0, width:1, color:'',
      keepTogether:false, breakBefore:false, parentId:'', image:'', alt:'', marks:[], ...data};
  }
  function empty() {return {schemaVersion:1, title:'A new conversation', subtitle:'Texts in conversation',
    author:'', authorUrl:'', titleUrl:'', settings:{...SETTINGS}, blocks:[]};}
  function normalize(input) {
    if (!input || input.schemaVersion!==1 || !Array.isArray(input.blocks)) throw Error('Not a Daf Studio sheet (schemaVersion 1 required).');
    if (input.blocks.length>LIMITS.blocks) throw Error('A sheet can contain at most 100 blocks. Split this collection into sheets.');
    const d=empty(), s=input.settings || {};
    ['title','subtitle','author'].forEach(k=>d[k]=text(input[k],240));
    ['authorUrl','titleUrl'].forEach(k=>d[k]=safeUrl(input[k]));
    const enums={paper:Object.keys(PAPERS),layout:['stepped','columns','reading'],overflow:['continue','shrink','endnotes','strict'],side:['a','b'],numbering:['daf','page']};
    for(const [k,v] of Object.entries(enums)) if(v.includes(s[k])) d.settings[k]=s[k];
    for(const [k,a,b] of [['margin',20,65],['mainSize',12,28],['noteSize',11,24],['minSize',10,20],['leading',1.25,2],['gap',8,30],['headerSize',18,38],['folio',1,999]]) d.settings[k]=clamp(s[k],a,b,SETTINGS[k]);
    ['mirror','showSources','debug'].forEach(k=>d.settings[k]=typeof s[k]==='boolean' ? s[k] : SETTINGS[k]);
    for(const k of ['ink','accent','paperColor']) d.settings[k]=color(s[k],SETTINGS[k]);
    d.settings.footer=text(s.footer ?? SETTINGS.footer,160);
    if(LATIN.includes(s.latin)) d.settings.latin=s.latin;
    if(HEBREW.includes(s.hebrew)) d.settings.hebrew=s.hebrew;
    const ids=new Set(); let total=0;
    d.blocks=input.blocks.map(raw=>{
      if(!raw || typeof raw!=='object') throw Error('Invalid source block.');
      const b=block();
      b.id=/^[a-zA-Z0-9_-]{1,80}$/.test(raw.id || '') && !ids.has(raw.id) ? raw.id : uid(); ids.add(b.id);
      if(clean(raw.text).length>LIMITS.perBlock) throw Error('A block exceeds 100,000 characters. Split it before importing.');
      for(const k of ['title','byline','source','license','alt']) b[k]=text(raw[k],k==='source'?500:240);
      b.text=clean(raw.text); total+=b.text.length;
      for(const k of ['url','authorUrl']) b[k]=safeUrl(raw[k]);
      b.role=ROLES.includes(raw.role)?raw.role:'commentary'; b.zone=ZONES.includes(raw.zone)?raw.zone:'auto';
      b.direction=['auto','ltr','rtl'].includes(raw.direction)?raw.direction:'auto';
      b.size=clamp(raw.size,0,32,0); if(b.size && b.size<11) b.size=11;
      b.width=clamp(raw.width,.7,1.4,1); b.color=color(raw.color,'');
      b.keepTogether=raw.keepTogether===true; b.breakBefore=raw.breakBefore===true;
      b.parentId=/^[a-zA-Z0-9_-]{1,80}$/.test(raw.parentId || '')?raw.parentId:'';
      const markTypes=new Set(['bold','italic','underline']);
      b.marks=Array.isArray(raw.marks)?raw.marks.slice(0,4000).map(m=>({start:Math.max(0,Math.min(b.text.length,Math.floor(+m.start||0))),end:Math.max(0,Math.min(b.text.length,Math.floor(+m.end||0))),type:markTypes.has(m.type)?m.type:''})).filter(m=>m.type&&m.end>m.start):[];
      if(raw.image){
        if(!/^data:image\/(png|jpeg|webp);base64,[A-Za-z0-9+/=]+$/.test(raw.image) || raw.image.length>700000) throw Error('Image must be a PNG, JPEG or WebP smaller than 500 KB after import.');
        b.image=raw.image;
      }
      return b;
    });
    if(total>LIMITS.chars) throw Error('This sheet exceeds 300,000 characters. Use more than one sheet.');
    d.blocks.forEach(b=>{if(!ids.has(b.parentId) || b.parentId===b.id)b.parentId='';});
    return d;
  }
  function element(tag,cls,txt) {const e=document.createElement(tag); if(cls)e.className=cls; if(txt!==undefined)e.textContent=txt; return e;}
  function scriptText(parent, value) {
    // Font selection is independent of direction. Do NOT reverse Hebrew strings.
    for(const run of String(value).split(/([\u0590-\u05FF\uFB1D-\uFB4F]+)/u)) {
      if(!run) continue;
      if(/[\u0590-\u05FF\uFB1D-\uFB4F]/u.test(run)) {const s=element('span','he',run); s.lang='he'; parent.append(s);}
      else parent.append(document.createTextNode(run));
    }
    return parent;
  }

  function scriptRichText(parent, value, marks=[], absoluteStart=0) {
    const str=String(value), end=absoluteStart+str.length;
    const relevant=(marks||[]).filter(m=>m.end>absoluteStart&&m.start<end&&['bold','italic','underline'].includes(m.type));
    const points=new Set([0,str.length]);
    for(const m of relevant){points.add(Math.max(0,m.start-absoluteStart));points.add(Math.min(str.length,m.end-absoluteStart));}
    const cuts=[...points].sort((a,b)=>a-b);
    for(let i=0;i<cuts.length-1;i++){
      const a=cuts[i],b=cuts[i+1];if(b<=a)continue;
      const active=relevant.filter(m=>m.start<=absoluteStart+a&&m.end>=absoluteStart+b).map(m=>m.type);
      let node=parent;
      for(const type of ['bold','italic','underline'])if(active.includes(type)){const el=element(type==='bold'?'strong':type==='italic'?'em':'u');node.append(el);node=el;}
      scriptText(node,str.slice(a,b));
    }
    return parent;
  }

  function linked(label,url,cls='') {
    const e=element(safeUrl(url)?'a':'span',cls); if(safeUrl(url)){e.href=safeUrl(url); e.target='_blank'; e.rel='noopener noreferrer';}
    const bi=element('bdi'); bi.dir='auto'; scriptText(bi,label); e.append(bi); return e;
  }
  function paragraphDirection(value) {
    // First strong LETTER, not first digit, punctuation, vowel, or cantillation mark.
    for(const c of value){if(/\p{L}/u.test(c)) return /[\u0590-\u05FF\uFB1D-\uFB4F]/u.test(c)?'rtl':'ltr';}
    return 'ltr';
  }
  function boundaries(value, grapheme=false) {
    if(global.Intl?.Segmenter) return [0,...Array.from(new Intl.Segmenter(undefined,{granularity:grapheme?'grapheme':'word'}).segment(value), s=>s.index+s.segment.length)];
    // Code-point fallback keeps combining marks attached.
    let at=0,out=[0]; for(const c of value){at+=c.length;if(!/\p{M}/u.test(c))out.push(at);else out[out.length-1]=at;} return out;
  }
  function folio(settings,page) {
    if(settings.numbering==='page') return String(page+1);
    const n=(settings.side==='b'?1:0)+page;
    return `${settings.folio+Math.floor(n/2)}${n%2?'b':'a'}`;
  }
  global.Daf={LIMITS,LATIN,HEBREW,PAPERS,SETTINGS,ZONES,ROLES,uid,clone,clamp,safeUrl,clean,block,empty,normalize,element,scriptText,scriptRichText,linked,paragraphDirection,boundaries,folio};
  if(typeof module!=='undefined')module.exports=global.Daf;
})(typeof window!=='undefined'?window:globalThis);
