/* Imports are plain text by design; no untrusted HTML is inserted into the app. */
(function(){
 'use strict';const D=window.Daf;
 function fromHTML(html){
   const template=document.createElement('template');template.innerHTML=html;const doc=template.content;
   doc.querySelectorAll('script,style,iframe,object,embed,link,meta,svg,math').forEach(e=>e.remove());
   doc.querySelectorAll('br').forEach(e=>e.replaceWith('\n'));
   doc.querySelectorAll('p,div,h1,h2,h3,h4,li,tr,section').forEach(e=>e.append('\n\n'));
   return D.clean(doc.textContent).replace(/\n{3,}/g,'\n\n').trim();
 }
 async function unzipDocument(buffer){
   // Central-directory inspection BEFORE inflation; only word/document.xml is read.
   const a=new Uint8Array(buffer),v=new DataView(buffer);let e=-1;
   for(let i=a.length-22;i>=Math.max(0,a.length-65557);i--){if(v.getUint32(i,true)===0x06054b50){e=i;break;}}
   if(e<0)throw Error('This is not a supported DOCX ZIP container.');
   const entries=v.getUint16(e+10,true);if(entries>3000)throw Error('DOCX has too many archive entries.');
   let p=v.getUint32(e+16,true),found=null;
   for(let i=0;i<entries;i++){
     if(p+46>a.length || v.getUint32(p,true)!==0x02014b50)throw Error('Malformed DOCX archive.');
     const flags=v.getUint16(p+8,true),method=v.getUint16(p+10,true),compressed=v.getUint32(p+20,true),uncompressed=v.getUint32(p+24,true);
     const nl=v.getUint16(p+28,true),xl=v.getUint16(p+30,true),cl=v.getUint16(p+32,true),local=v.getUint32(p+42,true);
     const name=new TextDecoder().decode(a.slice(p+46,p+46+nl));
     if(name==='word/document.xml'){
       if(found)throw Error('Duplicate document XML is not accepted.');
       if(flags&1 || ![0,8].includes(method) || uncompressed>8388608 || compressed>8388608 || uncompressed/Math.max(1,compressed)>400)throw Error('Encrypted, oversized or unusual DOCX compression.');
       if(local+30>a.length || v.getUint32(local,true)!==0x04034b50)throw Error('Invalid DOCX local header.');
       const start=local+30+v.getUint16(local+26,true)+v.getUint16(local+28,true);
       if(start+compressed>a.length)throw Error('Truncated DOCX file.');found={method,uncompressed,data:a.slice(start,start+compressed)};
     }
     p+=46+nl+xl+cl;
   }
   if(!found)throw Error('DOCX contains no word/document.xml.');
   let out=found.data;
   if(found.method===8){
     if(!window.DecompressionStream)throw Error('DOCX import needs a browser with DecompressionStream support. Use a current Chrome, Edge, Firefox or Safari, or paste plain text.');
     const stream=new Blob([found.data]).stream().pipeThrough(new DecompressionStream('deflate-raw'));
     const reader=stream.getReader(),chunks=[];let total=0;
     const timer=setTimeout(()=>reader.cancel('timeout'),8000);
     try{while(true){const {done,value}=await reader.read();if(done)break;total+=value.length;if(total>8388608){await reader.cancel();throw Error('Expanded DOCX text exceeds 8 MB.');}chunks.push(value);}}finally{clearTimeout(timer);}
     if(total!==found.uncompressed)throw Error('DOCX length mismatch or decompression timeout.');
     out=new Uint8Array(total);let pos=0;for(const chunk of chunks){out.set(chunk,pos);pos+=chunk.length;}
   }
   const xml=new TextDecoder().decode(out);
   if(/<!DOCTYPE|<!ENTITY/i.test(xml))throw Error('XML entities are not supported.');
   const doc=new DOMParser().parseFromString(xml,'application/xml');
   if(doc.querySelector('parsererror'))throw Error('DOCX XML could not be parsed.');
   const NS='http://schemas.openxmlformats.org/wordprocessingml/2006/main';
   return Array.from(doc.getElementsByTagNameNS(NS,'p'),p=>{
     let t='';const walker=document.createTreeWalker(p,NodeFilter.SHOW_ELEMENT);let n;
     while((n=walker.nextNode())){if(n.namespaceURI!==NS)continue;if(n.localName==='t')t+=n.textContent;else if(n.localName==='tab')t+='\t';else if(n.localName==='br')t+='\n';}
     return t;
   }).join('\n\n');
 }
 async function imageBlock(file){
   const bitmap=await createImageBitmap(file);if(bitmap.width*bitmap.height>25000000){bitmap.close();throw Error('Image exceeds 25 megapixels.');}
   const scale=Math.min(1,1500/Math.max(bitmap.width,bitmap.height));const c=document.createElement('canvas');c.width=Math.round(bitmap.width*scale);c.height=Math.round(bitmap.height*scale);
   c.getContext('2d').drawImage(bitmap,0,0,c.width,c.height);bitmap.close();const image=c.toDataURL('image/jpeg',.84);
   if(image.length>700000)throw Error('Image remains too large. Resize it before uploading.');
   return D.block({title:file.name.replace(/\.[^.]+$/,''),image,keepTogether:true,alt:''});
 }
 async function file(file,api){
   if(file.size>D.LIMITS.fileBytes)throw Error('File exceeds 8 MB. Split or compress it first.');
   const ext=file.name.split('.').pop().toLowerCase();let value,notice='';
   if(['png','jpg','jpeg','webp'].includes(ext))return {blocks:[await imageBlock(file)],notice:'Image imported. Add alternative text before sharing or exporting.'};
   if(ext==='json')return {document:D.normalize(JSON.parse(await file.text()))};
   if(ext==='docx'){value=await unzipDocument(await file.arrayBuffer());notice='DOCX text imported. Layout, tables, notes, tracked changes and inline formatting are not preserved; review the extracted text.';}
   else if(ext==='pdf'){
     if(!api)throw Error('PDF import uses the optional private converter attached to WordPress. DOCX, text, HTML and images work locally.');
     const form=new FormData();form.append('file',file);const r=await api('import-pdf',{method:'POST',body:form});value=r.text;notice=r.notice;
   }
   else if(['html','htm'].includes(ext)){value=fromHTML(await file.text());notice='HTML imported as text. Scripts, styling and embedded media are discarded.';}
   else if(['txt','md','markdown','csv'].includes(ext)){value=await file.text();notice='Text imported without changing the original source file.';}
   else throw Error('Supported: TXT, Markdown, HTML, DOCX, PDF (converter), JSON, PNG, JPEG and WebP.');
   value=D.clean(value);
   if(!value.trim())throw Error('No text could be extracted. Scanned pages require OCR, which is not included.');
   if(value.length>D.LIMITS.perBlock)throw Error('Extracted text exceeds 100,000 characters. Import a shorter selection.');
   return {blocks:[D.block({title:file.name.replace(/\.[^.]+$/,''),text:value,source:file.name})],notice};
 }
 window.DafImports={file,fromHTML,unzipDocument};
})();
