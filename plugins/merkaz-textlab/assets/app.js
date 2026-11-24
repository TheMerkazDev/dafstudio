(() => {
  'use strict';
  const cfg = window.MTL_CONFIG || {};
  const app = document.getElementById('mtl-app');
  if (!app) return;

  const debug = !!cfg.debug;
  const dlog = (...args) => { if (debug && window.console) console.debug('[Merkaz TextLab]', ...args); };
  const dwarn = (...args) => { if (debug && window.console) console.warn('[Merkaz TextLab]', ...args); };
  dlog('Debug mode enabled', {requestId: cfg.debugRequestId || '', restUrl: cfg.restUrl || ''});

  const state = {
    me: null,
    projects: [],
    projectId: Number(cfg.initialProject || 0),
    workspace: null,
    currentRef: 'Genesis 1:1',
    refInfo: null,
    sefaria: {he:'', en:'', heMeta:null, enMeta:null},
    versionList: [],
    versionIndex: '',
    comparison: null,
    links: [],
    members: [],
    dirty: false,
    saveTimer: null,
    loadingRef: false,
    deleteSegmentIds: [],
  };

  const esc = s => String(s ?? '').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
  const strip = s => String(s ?? '').replace(/<[^>]*>/g, '').trim();
  const parseJson = (s, fallback=[]) => { try { const x = typeof s === 'string' ? JSON.parse(s || '') : s; return x ?? fallback; } catch { return fallback; } };
  const feature = name => !!state.me?.features?.[name];
  const role = () => state.workspace?.role || '';
  const canComment = () => !!state.workspace?.permissions?.comment;
  const canEdit = () => !!state.workspace?.permissions?.edit;
  const canAdmin = () => !!state.workspace?.permissions?.admin;
  const canManage = () => !!state.workspace?.permissions?.manage;

  async function api(path, options={}) {
    const headers = Object.assign({'X-WP-Nonce': cfg.nonce}, options.headers || {});
    const init = Object.assign({credentials:'same-origin'}, options, {headers});
    const method = String(init.method || 'GET').toUpperCase();
    let bodySummary = null;
    if (init.body && typeof init.body !== 'string') {
      bodySummary = {keys:Object.keys(init.body || {})};
      headers['Content-Type'] = 'application/json';
      init.body = JSON.stringify(init.body);
    } else if (typeof init.body === 'string') {
      bodySummary = {bytes:new Blob([init.body]).size};
    }
    const url = cfg.restUrl + String(path).replace(/^\//,'');
    const started = performance.now();
    dlog('REST request', {method, path, body:bodySummary});
    try {
      const res = await fetch(url, init);
      const data = await res.json().catch(() => ({}));
      const elapsedMs = Math.round((performance.now() - started) * 10) / 10;
      const requestId = res.headers.get('X-MTL-Request-ID') || '';
      dlog('REST response', {method, path, status:res.status, elapsedMs, requestId, responseKeys:data && typeof data === 'object' ? Object.keys(data) : []});
      if (!res.ok) {
        dwarn('REST request failed', {method, path, status:res.status, elapsedMs, requestId, code:data?.code, message:data?.message});
        throw new Error(data?.message || `Request failed (${res.status})`);
      }
      return data;
    } catch (err) {
      dwarn('REST transport/processing error', {method, path, message:err?.message || String(err)});
      throw err;
    }
  }

  function setStatus(text, kind='') {
    const el = app.querySelector('#mtl-status');
    if (!el) return;
    el.textContent = text;
    el.className = `mtl-status ${kind}`;
  }

  function markDirty() {
    state.dirty = true;
    setStatus('Unsaved changes');
    clearTimeout(state.saveTimer);
    state.saveTimer = setTimeout(() => saveWorkspace(true), 1200);
  }

  async function init() {
    try {
      state.me = await api('me');
      state.projects = await api('projects');
      if (!state.projectId || !state.projects.some(p => Number(p.id) === state.projectId)) state.projectId = Number(state.projects[0]?.id || 0);
      if (!state.projectId) {
        const created = await api('projects', {method:'POST', body:{title:'My Biblical Text Project'}});
        state.projects = [created];
        state.projectId = Number(created.id);
      }
      renderShell();
      bindShell();
      await loadProject(state.projectId);
      await loadRef(state.currentRef);
    } catch (e) {
      dwarn('Application initialization failed', {message:e?.message || String(e), stack:e?.stack || ''});
      app.innerHTML = `<div class="mtl-error"><strong>TextLab could not start.</strong><div>${esc(e.message)}</div>${debug ? `<div class="mtl-tip">Debug mode is on. Check your browser console and WordPress → TextLab → Debug Log. Request ID: ${esc(cfg.debugRequestId || '')}</div>` : ''}</div>`;
    }
  }

  function renderShell() {
    app.innerHTML = `
      <div class="mtl-topbar">
        <div class="mtl-brand"><strong>Merkaz TextLab</strong><span>Sefaria-connected research & homework workbench</span></div>
        <div class="mtl-project-tools">
          <select id="mtl-project-select"></select>
          <button id="mtl-new-project">+ Project</button>
          <button id="mtl-save">Save</button>
          <span id="mtl-status" class="mtl-status">Starting…</span>
        </div>
      </div>
      <div id="mtl-export-panel" class="mtl-export-panel"></div>
      <div class="mtl-tabs">
        <button class="mtl-tab active" data-tab="study">Study</button>
        <button class="mtl-tab" data-tab="outline">Outline</button>
        ${feature('sources') ? '<button class="mtl-tab" data-tab="sources">Sources</button>' : ''}
        ${feature('collaborate') ? '<button class="mtl-tab" data-tab="access">Access</button>' : ''}
        ${feature('manuscript_read') ? '<button class="mtl-tab" data-tab="manuscript">Manuscript</button>' : ''}
      </div>

      <section class="mtl-page active" data-page="study">
        <div class="mtl-workbench-grid">
          <aside class="mtl-card mtl-navigator">
            <h3>Passage</h3>
            <form id="mtl-ref-form" class="mtl-ref-form"><input id="mtl-ref-input" value="Genesis 1:1" placeholder="Genesis 1:1"><button>Go</button></form>
            <div class="mtl-tip">References are normalized through Sefaria before loading.</div>
            <div class="mtl-project-meta">
              <label>Project title<input id="mtl-project-title"></label>
              <label>Visibility<select id="mtl-project-visibility"><option value="private">Private</option><option value="shared">Named collaborators</option><option value="group">BuddyPress group</option><option value="members">Logged-in members</option><option value="public">Public</option></select></label>
              <label id="mtl-group-row" class="mtl-hidden">BuddyPress group ID<input id="mtl-project-group" inputmode="numeric"></label>
              <div class="mtl-provenance-box"><strong>Authorship</strong><p>Sefaria text and connected sources are external. Translation, notes, and outline titles saved here are user-authored. TextLab automates structure and formatting.</p></div>
            </div>
          </aside>

          <main class="mtl-maincol">
            <div class="mtl-refbar"><div><strong id="mtl-ref-label">Genesis 1:1</strong><span id="mtl-version-label"></span></div><a id="mtl-open-sefaria" target="_blank" rel="noopener">Open on Sefaria ↗</a></div>
            <div class="mtl-text-grid">
              <div class="mtl-card"><div class="mtl-cardhead"><h3>Source text</h3><span class="mtl-badge">Sefaria</span></div><div id="mtl-hebrew" class="mtl-hebrew" dir="rtl"></div></div>
              <div class="mtl-card"><div class="mtl-cardhead"><h3>Sefaria translation</h3><span id="mtl-primary-version" class="mtl-badge">Sefaria</span></div><div id="mtl-primary-translation" class="mtl-readonly-translation"></div></div>
            </div>

            <div class="mtl-card">
              <div class="mtl-cardhead"><h3>Compare another Sefaria version</h3><span id="mtl-comparison-license" class="mtl-badge">not selected</span></div>
              <div class="mtl-version-toolbar"><select id="mtl-comparison-select"><option value="">Choose a version…</option></select><span id="mtl-comparison-meta" class="mtl-tip"></span></div>
              <div id="mtl-comparison-text" class="mtl-readonly-translation mtl-comparison"><span class="mtl-empty">Select a version to compare. Comparison text is not copied into your project.</span></div>
            </div>

            ${feature('translate') ? `
            <div class="mtl-card">
              <div class="mtl-cardhead"><div><h3>My translation</h3><p>Segments can divide a traditional verse into clauses without changing the Sefaria reference.</p></div><button id="mtl-add-segment">+ Segment</button></div>
              <div id="mtl-segment-editor"></div>
            </div>` : ''}

            <div class="mtl-card">
              <div class="mtl-cardhead"><div><h3>Commentary / annotations</h3><p>Keep questions, observations, translation notes, and research notes distinct.</p></div>${feature('annotate') ? '<button id="mtl-add-note">+ Note</button>' : ''}</div>
              <div id="mtl-notes"></div>
            </div>
          </main>
        </div>
      </section>

      <section class="mtl-page" data-page="outline">
        <div class="mtl-two-col">
          <div class="mtl-card"><div class="mtl-cardhead"><div><h3>Outline builder</h3><p>Numbering is generated automatically from hierarchy.</p></div><button id="mtl-add-outline">+ Current passage</button></div><div id="mtl-outline-editor"></div></div>
          <div class="mtl-card"><h3>Homework preview</h3><div id="mtl-outline-preview" class="mtl-paper"></div></div>
        </div>
      </section>

      ${feature('sources') ? `<section class="mtl-page" data-page="sources"><div class="mtl-two-col"><div class="mtl-card"><div class="mtl-cardhead"><div><h3>Sefaria connected sources</h3><p>Preview external material and deliberately save a citation record.</p></div><button id="mtl-refresh-sources">Refresh</button></div><div id="mtl-source-results"></div></div><div class="mtl-card"><h3>Saved project sources</h3><div id="mtl-saved-sources"></div></div></div></section>` : ''}

      ${feature('collaborate') ? `<section class="mtl-page" data-page="access"><div class="mtl-card"><h3>Collaborators</h3><p id="mtl-access-help">Named users can be viewers, commenters, contributors, or editors. Global advanced-feature entitlements remain separate from project roles.</p><div id="mtl-access-editor"></div></div></section>` : ''}

      ${feature('manuscript_read') ? `<section class="mtl-page" data-page="manuscript"><div class="mtl-two-col"><div class="mtl-card"><h3>Manuscript preview</h3><div id="mtl-manuscript-preview" class="mtl-manuscript"></div></div><div class="mtl-card"><h3>Enabled features</h3><div id="mtl-entitlement-list" class="mtl-entitlement-list"></div><p class="mtl-tip">Feature access and project access are intentionally separate.</p></div></div></section>` : ''}
    `;
    renderProjectSelect();
    renderExportPanel();
  }

  function bindShell() {
    app.querySelectorAll('.mtl-tab').forEach(btn => btn.addEventListener('click', async () => {
      app.querySelectorAll('.mtl-tab').forEach(x => x.classList.toggle('active', x === btn));
      app.querySelectorAll('.mtl-page').forEach(x => x.classList.toggle('active', x.dataset.page === btn.dataset.tab));
      if (btn.dataset.tab === 'sources') await loadSources(false);
      if (btn.dataset.tab === 'access') await loadMembers();
      if (btn.dataset.tab === 'manuscript') renderManuscript();
    }));
    app.querySelector('#mtl-project-select').addEventListener('change', async e => { await flushIfDirty(); await loadProject(Number(e.target.value)); await loadRef(state.currentRef); });
    app.querySelector('#mtl-new-project').addEventListener('click', createProject);
    app.querySelector('#mtl-save').addEventListener('click', () => saveWorkspace(false));
    app.querySelector('#mtl-ref-form').addEventListener('submit', e => { e.preventDefault(); loadRef(app.querySelector('#mtl-ref-input').value.trim()); });
    app.querySelector('#mtl-project-title').addEventListener('change', updateProjectMeta);
    app.querySelector('#mtl-project-visibility').addEventListener('change', () => { toggleGroup(); updateProjectMeta(); });
    app.querySelector('#mtl-project-group').addEventListener('change', updateProjectMeta);
    app.querySelector('#mtl-comparison-select').addEventListener('change', loadComparison);
    app.querySelector('#mtl-add-note')?.addEventListener('click', addNote);
    app.querySelector('#mtl-add-outline')?.addEventListener('click', addOutline);
    app.querySelector('#mtl-add-segment')?.addEventListener('click', addSegment);
    app.querySelector('#mtl-refresh-sources')?.addEventListener('click', () => loadSources(true));
  }

  function renderProjectSelect() {
    const sel = app.querySelector('#mtl-project-select');
    if (!sel) return;
    sel.innerHTML = state.projects.map(p => `<option value="${Number(p.id)}" ${Number(p.id)===state.projectId?'selected':''}>${esc(p.title)}</option>`).join('');
  }

  async function createProject() {
    const title = window.prompt('Project title', 'New Biblical Text Project');
    if (!title) return;
    try {
      const p = await api('projects', {method:'POST', body:{title}});
      state.projects.unshift(p); state.projectId = Number(p.id); renderProjectSelect();
      await loadProject(state.projectId); await loadRef(state.currentRef);
    } catch (e) { setStatus(e.message, 'error'); }
  }

  async function flushIfDirty() { if (state.dirty) await saveWorkspace(true); }

  async function loadProject(id) {
    state.projectId = Number(id);
    state.deleteSegmentIds = [];
    setStatus('Loading project…');
    state.workspace = await api(`projects/${state.projectId}/workspace`);
    const project = state.workspace.project;
    app.querySelector('#mtl-project-title').value = project.title || '';
    app.querySelector('#mtl-project-visibility').value = project.visibility || 'private';
    app.querySelector('#mtl-project-group').value = project.buddy_group_id || '';
    app.querySelector('#mtl-project-title').disabled = !canAdmin();
    app.querySelector('#mtl-project-visibility').disabled = !canAdmin();
    app.querySelector('#mtl-project-group').disabled = !canAdmin();
    app.querySelector('#mtl-add-note') && (app.querySelector('#mtl-add-note').disabled = !canComment());
    app.querySelector('#mtl-add-outline') && (app.querySelector('#mtl-add-outline').disabled = !canEdit());
    app.querySelector('#mtl-add-segment') && (app.querySelector('#mtl-add-segment').disabled = !(canEdit() && feature('translate')));
    toggleGroup();
    renderNotes(); renderOutline(); renderSegments(); renderSavedSources(); renderManuscript(); renderAccess(); renderExportPanel();
    state.dirty = false;
    setStatus(`Loaded · ${role()}`, 'ok');
  }

  function toggleGroup() {
    const row = app.querySelector('#mtl-group-row');
    if (row) row.classList.toggle('mtl-hidden', app.querySelector('#mtl-project-visibility').value !== 'group');
  }

  async function updateProjectMeta() {
    if (!canAdmin()) return;
    try {
      const p = await api(`projects/${state.projectId}`, {method:'PUT', body:{
        title:app.querySelector('#mtl-project-title').value,
        visibility:app.querySelector('#mtl-project-visibility').value,
        buddy_group_id:Number(app.querySelector('#mtl-project-group').value || 0),
      }});
      state.workspace.project = p;
      const item = state.projects.find(x => Number(x.id) === state.projectId); if (item) Object.assign(item,p);
      renderProjectSelect(); setStatus('Project settings saved','ok');
    } catch(e) { setStatus(e.message,'error'); }
  }

  function sefariaUrl(ref) {
    const path = String(ref || '').trim().replace(/\s+/g,'.').replace(/:/g,'.');
    return (cfg.sefariaBase || 'https://www.sefaria.org/') + encodeURI(path);
  }

  function flattenText(value) {
    if (Array.isArray(value)) return value.map(flattenText).filter(Boolean).join(' ');
    return strip(value || '');
  }

  function extractTextVersions(data) {
    const versions = Array.isArray(data?.versions) ? data.versions : [];
    let he='', en='', heMeta=null, enMeta=null;
    for (const v of versions) {
      const text = flattenText(v.text);
      const lang = String(v.language || v.lang || '').toLowerCase();
      const dir = String(v.direction || '').toLowerCase();
      if (!he && text && (dir === 'rtl' || lang === 'he' || lang === 'hebrew')) { he=text; heMeta=v; continue; }
      if (!en && text) { en=text; enMeta=v; }
    }
    if (!he && data?.he) he = flattenText(data.he);
    if (!en && data?.text) en = flattenText(data.text);
    return {he,en,heMeta,enMeta,raw:data};
  }

  function indexFromRefInfo(info, ref) {
    if (info?.indexTitle) return info.indexTitle;
    if (info?.book) return info.book;
    const m = String(ref).match(/^(.+?)\s+\d/); return m ? m[1] : String(ref).split(':')[0];
  }

  async function loadRef(ref) {
    if (!ref || state.loadingRef) return;
    state.loadingRef = true;
    try {
      setStatus('Normalizing reference…');
      let info = null;
      try { info = await api(`sefaria/ref?ref=${encodeURIComponent(ref)}`); } catch (_) {}
      state.refInfo = info;
      const normalized = info?.ref || info?.normalizedRef || info?.normalized_ref || ref;
      state.currentRef = normalized;
      app.querySelector('#mtl-ref-input').value = normalized;
      app.querySelector('#mtl-ref-label').textContent = normalized;
      app.querySelector('#mtl-open-sefaria').href = sefariaUrl(normalized);

      setStatus('Loading Sefaria text…');
      let data;
      try { data = await api(`sefaria/text?ref=${encodeURIComponent(normalized)}&version=${encodeURIComponent('source|~|translation')}`); }
      catch (_) { data = await api(`sefaria/text?ref=${encodeURIComponent(normalized)}`); }
      state.sefaria = extractTextVersions(data);
      app.querySelector('#mtl-hebrew').textContent = state.sefaria.he || 'No source-language text returned.';
      app.querySelector('#mtl-primary-translation').textContent = state.sefaria.en || 'No translation returned.';
      app.querySelector('#mtl-version-label').textContent = state.sefaria.heMeta?.versionTitle ? ` · ${state.sefaria.heMeta.versionTitle}` : '';
      app.querySelector('#mtl-primary-version').textContent = state.sefaria.enMeta?.versionTitle || 'Sefaria translation';

      await loadVersionList(indexFromRefInfo(info, normalized));
      renderSegments(); renderNotes();
      if (feature('sources')) loadSources(false);
      setStatus('Ready','ok');
    } catch(e) {
      app.querySelector('#mtl-hebrew').textContent = 'Unable to load this reference.';
      app.querySelector('#mtl-primary-translation').textContent = '';
      setStatus(e.message,'error');
    } finally { state.loadingRef = false; }
  }

  function collectVersions(value, out=[]) {
    if (Array.isArray(value)) { value.forEach(v => collectVersions(v,out)); return out; }
    if (!value || typeof value !== 'object') return out;
    if (value.versionTitle) out.push(value);
    else Object.values(value).forEach(v => collectVersions(v,out));
    return out;
  }

  async function loadVersionList(index) {
    const select = app.querySelector('#mtl-comparison-select');
    if (!select) return;
    if (!index) { select.innerHTML='<option value="">No version list available</option>'; return; }
    try {
      if (state.versionIndex !== index) {
        const data = await api(`sefaria/versions?index=${encodeURIComponent(index)}`);
        state.versionList = collectVersions(data).filter((v,i,a) => v.versionTitle && a.findIndex(x => x.versionTitle===v.versionTitle && String(x.language||x.lang)===String(v.language||v.lang))===i);
        state.versionIndex = index;
      }
      const options = state.versionList
        .sort((a,b) => String(a.versionTitle).localeCompare(String(b.versionTitle)))
        .map((v,i) => `<option value="${i}">${esc(v.versionTitle)}${v.language ? ` · ${esc(v.language)}` : ''}${v.license ? ` · ${esc(v.license)}` : ''}</option>`).join('');
      select.innerHTML = `<option value="">Choose a version…</option>${options}`;
      app.querySelector('#mtl-comparison-text').innerHTML = '<span class="mtl-empty">Select a version to compare. Comparison text is not copied into your project.</span>';
      app.querySelector('#mtl-comparison-license').textContent = 'not selected';
      app.querySelector('#mtl-comparison-meta').textContent = '';
    } catch(e) {
      select.innerHTML = '<option value="">Version list unavailable</option>';
    }
  }

  async function loadComparison() {
    const idx = app.querySelector('#mtl-comparison-select').value;
    if (idx === '') return;
    const meta = state.versionList[Number(idx)]; if (!meta) return;
    const rawLang = String(meta.languageFamilyName || meta.actualLanguage || meta.language || meta.lang || 'english').toLowerCase();
    const languageNames = {en:'english',he:'hebrew',heb:'hebrew',ar:'arabic',ara:'aramaic',yi:'yiddish',es:'spanish',fr:'french',de:'german'};
    const lang = languageNames[rawLang] || rawLang;
    try {
      app.querySelector('#mtl-comparison-text').textContent = 'Loading…';
      const data = await api(`sefaria/text?ref=${encodeURIComponent(state.currentRef)}&version=${encodeURIComponent(`${lang}|${meta.versionTitle}`)}`);
      const extracted = extractTextVersions(data);
      const text = extracted.en || extracted.he || flattenText(data?.versions?.[0]?.text) || 'No text returned for this passage.';
      app.querySelector('#mtl-comparison-text').textContent = text;
      app.querySelector('#mtl-comparison-license').textContent = meta.license || 'license not stated';
      const source = meta.versionSource || meta.source || meta.shortVersionTitle || '';
      app.querySelector('#mtl-comparison-meta').textContent = source ? `Source: ${source}` : '';
      state.comparison = {meta,text};
    } catch(e) { app.querySelector('#mtl-comparison-text').textContent = e.message; }
  }

  function currentSegments() {
    return (state.workspace?.segments || []).filter(s => String(s.sefaria_ref) === String(state.currentRef));
  }

  function renderSegments() {
    const host = app.querySelector('#mtl-segment-editor');
    if (!host || !state.workspace || !feature('translate')) return;
    const segments = currentSegments();
    if (!segments.length) {
      host.innerHTML = `<div class="mtl-empty">No user translation saved for ${esc(state.currentRef)}. Add a segment when you are ready to translate it.</div>`;
      return;
    }
    const editable = canEdit();
    host.innerHTML = segments.map((s,idx) => `
      <div class="mtl-segment-card" data-key="${esc(String(s.id || s._local || s.segment_key))}">
        <div class="mtl-segment-head"><span class="mtl-badge">segment ${idx+1}</span><span class="mtl-tip">${esc(s.segment_key || '')}</span>${editable?'<button data-seg-act="delete" class="mtl-danger">Remove</button>':''}</div>
        <label>Hebrew/source span<textarea data-seg-field="source_hebrew" dir="rtl" class="mtl-segment-hebrew" rows="2" ${editable?'':'readonly'}>${esc(s.source_hebrew || (segments.length===1 ? state.sefaria.he : ''))}</textarea></label>
        <label>My translation<textarea data-seg-field="translation" rows="3" ${editable?'':'readonly'}>${esc(strip(s.translation || ''))}</textarea></label>
        <label>Status <select data-seg-field="translation_status" ${editable?'':'disabled'}><option value="draft" ${s.translation_status==='draft'?'selected':''}>Draft</option><option value="review" ${s.translation_status==='review'?'selected':''}>Review</option><option value="final" ${s.translation_status==='final'?'selected':''}>Final</option></select></label>
      </div>`).join('');
    host.querySelectorAll('.mtl-segment-card').forEach(card => {
      card.querySelectorAll('[data-seg-field]').forEach(el => el.addEventListener('input', () => syncSegment(card)));
      card.querySelector('[data-seg-act="delete"]')?.addEventListener('click', () => deleteSegment(card));
    });
  }

  function findSegment(card) {
    const key = card.dataset.key;
    return (state.workspace?.segments || []).find(s => String(s.id || s._local || s.segment_key) === key);
  }

  function syncSegment(card) {
    const s = findSegment(card); if (!s || !canEdit()) return;
    s.source_hebrew = card.querySelector('[data-seg-field="source_hebrew"]').value;
    s.translation = card.querySelector('[data-seg-field="translation"]').value;
    s.translation_status = card.querySelector('[data-seg-field="translation_status"]').value;
    markDirty(); renderManuscript();
  }

  function addSegment() {
    if (!(feature('translate') && canEdit())) return;
    const all = state.workspace.segments || (state.workspace.segments=[]);
    const maxOrder = all.reduce((m,s) => Math.max(m,Number(s.segment_order||0)),0);
    const local = `local-seg-${Date.now()}`;
    all.push({id:0,_local:local,sefaria_ref:state.currentRef,segment_key:`${state.currentRef}::user-${Date.now()}`,segment_order:maxOrder+10,source_hebrew:currentSegments().length ? '' : state.sefaria.he,translation:'',translation_status:'draft',metadata_json:'{}'});
    renderSegments(); markDirty();
  }

  function deleteSegment(card) {
    const s = findSegment(card); if (!s || !canEdit()) return;
    if (s.id) state.deleteSegmentIds.push(Number(s.id));
    state.workspace.segments.splice(state.workspace.segments.indexOf(s),1);
    renderSegments(); markDirty();
  }

  function noteMatchesCurrent(a) {
    return [a.segment_ref,a.start_ref,a.end_ref].filter(Boolean).some(x => String(x) === String(state.currentRef));
  }
  function notesForCurrent() { return (state.workspace?.annotations || []).filter(noteMatchesCurrent); }

  function renderNotes() {
    const host=app.querySelector('#mtl-notes'); if(!host || !state.workspace) return;
    const notes=notesForCurrent();
    if (!notes.length) { host.innerHTML='<div class="mtl-empty">No commentary attached to this passage yet.</div>'; return; }
    host.innerHTML=notes.map(a=>{
      const own=!a.id || Number(a.user_id)===Number(state.me.id); const editable=own&&canComment();
      const tags=(a.tags||parseJson(a.tags_json,[])).join(', '); const targets=a.targets||parseJson(a.targets_json,['homework']);
      return `<div class="mtl-note ${editable?'':'mtl-readonly'}" data-key="${esc(String(a.id||a._local))}">
        <div class="mtl-note-side"><span class="mtl-badge">${esc(a.annotation_type||'observation')}</span><span class="mtl-author">${own?'mine':'collaborator'}</span>${editable?'<button data-note-act="up">↑</button><button data-note-act="down">↓</button><button data-note-act="delete" class="mtl-danger">×</button>':''}</div>
        <div class="mtl-note-fields">
          <div class="mtl-note-row"><select data-note-field="annotation_type" ${editable?'':'disabled'}>${['observation','question','translation_note','historical','literary','source_criticism','class_note','research'].map(x=>`<option value="${x}" ${x===a.annotation_type?'selected':''}>${esc(x.replace(/_/g,' '))}</option>`).join('')}</select><input data-note-field="title" value="${esc(a.title||'')}" placeholder="Title" ${editable?'':'readonly'}><input data-note-field="start_ref" value="${esc(a.start_ref||a.segment_ref||'')}" placeholder="Reference" ${editable?'':'readonly'}></div>
          <textarea data-note-field="body" rows="4" ${editable?'':'readonly'}>${esc(strip(a.body||''))}</textarea>
          <div class="mtl-note-row"><input data-note-field="tags" value="${esc(tags)}" placeholder="tags, comma separated" ${editable?'':'readonly'}><label><input type="checkbox" data-target="homework" ${targets.includes('homework')?'checked':''} ${editable?'':'disabled'}> homework</label>${feature('advanced_annotations')?`<label><input type="checkbox" data-target="research" ${targets.includes('research')?'checked':''} ${editable?'':'disabled'}> research</label><label><input type="checkbox" data-target="book" ${targets.includes('book')?'checked':''} ${editable?'':'disabled'}> book</label><label><input type="checkbox" data-target="website" ${targets.includes('website')?'checked':''} ${editable?'':'disabled'}> website</label>`:''}</div>
        </div>
      </div>`;
    }).join('');
    host.querySelectorAll('.mtl-note').forEach(el=>{
      el.querySelectorAll('[data-note-field], [data-target]').forEach(i=>i.addEventListener('input',()=>syncNote(el)));
      el.querySelectorAll('[data-note-act]').forEach(b=>b.addEventListener('click',()=>noteAction(el,b.dataset.noteAct)));
    });
  }

  function findNote(el){const key=el.dataset.key;return state.workspace.annotations.find(a=>String(a.id||a._local)===key);}
  function syncNote(el){const a=findNote(el);if(!a||(!canComment())||(a.id&&Number(a.user_id)!==Number(state.me.id)))return;a.annotation_type=el.querySelector('[data-note-field="annotation_type"]').value;a.title=el.querySelector('[data-note-field="title"]').value;a.start_ref=el.querySelector('[data-note-field="start_ref"]').value;a.segment_ref=a.start_ref;a.body=el.querySelector('[data-note-field="body"]').value;a.tags=el.querySelector('[data-note-field="tags"]').value.split(',').map(x=>x.trim()).filter(Boolean);a.targets=[...el.querySelectorAll('[data-target]:checked')].map(x=>x.dataset.target);markDirty();renderManuscript();}
  function addNote(){if(!canComment())return;const all=state.workspace.annotations||(state.workspace.annotations=[]);all.push({id:0,_local:`note-${Date.now()}`,user_id:state.me.id,segment_ref:state.currentRef,start_ref:state.currentRef,end_ref:'',annotation_type:'observation',title:'',body:'',tags:[],targets:['homework'],sort_order:(all.length+1)*10});renderNotes();markDirty();}
  function noteAction(el,act){const a=findNote(el);if(!a)return;const all=state.workspace.annotations,i=all.indexOf(a);if(act==='delete')all.splice(i,1);if(act==='up'&&i>0)[all[i-1],all[i]]=[all[i],all[i-1]];if(act==='down'&&i<all.length-1)[all[i+1],all[i]]=[all[i],all[i+1]];all.forEach((x,k)=>x.sort_order=(k+1)*10);renderNotes();markDirty();}

  function addOutline(){if(!canEdit())return;const all=state.workspace.outline||(state.workspace.outline=[]);all.push({id:0,_local:`outline-${Date.now()}`,user_id:state.me.id,level:1,title:'',start_ref:state.currentRef,end_ref:state.currentRef,sort_order:(all.length+1)*10});renderOutline();markDirty();}
  function roman(n){const map=[[1000,'M'],[900,'CM'],[500,'D'],[400,'CD'],[100,'C'],[90,'XC'],[50,'L'],[40,'XL'],[10,'X'],[9,'IX'],[5,'V'],[4,'IV'],[1,'I']];let s='';for(const [v,t] of map)while(n>=v){s+=t;n-=v}return s;}
  function outlineLabel(level,n){if(level===1)return roman(n)+'.';if(level===2)return String.fromCharCode(64+((n-1)%26)+1)+'.';if(level===3)return n+'.';if(level===4)return String.fromCharCode(96+((n-1)%26)+1)+'.';return roman(n).toLowerCase()+'.';}
  function numberedOutline(){const counters=Array(10).fill(0);return(state.workspace?.outline||[]).map(o=>{const l=Math.max(1,Math.min(8,Number(o.level)||1));counters[l]++;for(let j=l+1;j<counters.length;j++)counters[j]=0;return{o,label:outlineLabel(l,counters[l])};});}
  function renderOutline(){const editor=app.querySelector('#mtl-outline-editor'),preview=app.querySelector('#mtl-outline-preview');if(!editor||!state.workspace)return;const rows=state.workspace.outline||[];editor.innerHTML=rows.length?rows.map(o=>{const own=!o.id||Number(o.user_id)===Number(state.me.id),editable=own&&canEdit();return `<div class="mtl-outline-row ${editable?'':'mtl-readonly'}" data-key="${esc(String(o.id||o._local))}"><span class="mtl-level">L${Number(o.level)||1}</span><input data-outline-field="title" value="${esc(o.title||'')}" placeholder="Outline heading" ${editable?'':'readonly'}><div class="mtl-outline-range"><input data-outline-field="start_ref" value="${esc(o.start_ref||'')}" placeholder="Start"><input data-outline-field="end_ref" value="${esc(o.end_ref||'')}" placeholder="End"></div><div class="mtl-outline-actions">${editable?'<button data-outline-act="out">←</button><button data-outline-act="in">→</button><button data-outline-act="up">↑</button><button data-outline-act="down">↓</button><button data-outline-act="delete" class="mtl-danger">×</button>':''}</div></div>`}).join(''):'<div class="mtl-empty">Start by adding the current passage.</div>';
    editor.querySelectorAll('.mtl-outline-row').forEach(el=>{el.querySelectorAll('[data-outline-field]').forEach(i=>i.addEventListener('input',()=>syncOutline(el)));el.querySelectorAll('[data-outline-act]').forEach(b=>b.addEventListener('click',()=>outlineAction(el,b.dataset.outlineAct)));});
    preview.innerHTML=numberedOutline().map(({o,label})=>`<div class="mtl-outline-preview-row" style="margin-left:${(Number(o.level)-1)*1.45}em"><span>${esc(label)}</span> ${esc(o.title||'Untitled')} ${o.start_ref?`<small>(${esc(o.start_ref)}${o.end_ref&&o.end_ref!==o.start_ref?'–'+esc(o.end_ref):''})</small>`:''}</div>`).join('')||'<div class="mtl-empty">Outline preview will appear here.</div>';
  }
  function findOutline(el){const key=el.dataset.key;return state.workspace.outline.find(o=>String(o.id||o._local)===key);}
  function syncOutline(el){const o=findOutline(el);if(!o||!canEdit())return;o.title=el.querySelector('[data-outline-field="title"]').value;o.start_ref=el.querySelector('[data-outline-field="start_ref"]').value;o.end_ref=el.querySelector('[data-outline-field="end_ref"]').value;markDirty();renderManuscript();}
  function outlineAction(el,act){const o=findOutline(el);if(!o)return;const all=state.workspace.outline,i=all.indexOf(o);if(act==='in')o.level=Math.min(8,Number(o.level)+1);if(act==='out')o.level=Math.max(1,Number(o.level)-1);if(act==='delete')all.splice(i,1);if(act==='up'&&i>0)[all[i-1],all[i]]=[all[i],all[i-1]];if(act==='down'&&i<all.length-1)[all[i+1],all[i]]=[all[i],all[i+1]];all.forEach((x,k)=>x.sort_order=(k+1)*10);renderOutline();markDirty();}

  async function saveWorkspace(silent=false){if(!state.workspace||!state.projectId)return;clearTimeout(state.saveTimer);try{if(!silent)setStatus('Saving…');const payload={};if(feature('translate')&&canEdit()){payload.segments=(state.workspace.segments||[]).map(s=>({id:Number(s.id)||0,sefaria_ref:s.sefaria_ref,segment_key:s.segment_key,segment_order:Number(s.segment_order)||0,source_hebrew:s.source_hebrew||'',translation:s.translation||'',translation_status:s.translation_status||'draft',metadata:parseJson(s.metadata_json,s.metadata||{})}));payload.delete_segment_ids=state.deleteSegmentIds;}
      if(feature('annotate')&&canComment())payload.annotations=(state.workspace.annotations||[]).filter(a=>!a.id||Number(a.user_id)===Number(state.me.id)).map(a=>({id:Number(a.id)||0,segment_ref:a.segment_ref||'',start_ref:a.start_ref||'',end_ref:a.end_ref||'',annotation_type:a.annotation_type||'observation',title:a.title||'',body:a.body||'',tags:a.tags||parseJson(a.tags_json,[]),targets:a.targets||parseJson(a.targets_json,['homework']),sort_order:Number(a.sort_order)||0}));
      if(feature('outline')&&canEdit())payload.outline=(state.workspace.outline||[]).filter(o=>!o.id||Number(o.user_id)===Number(state.me.id)).map(o=>({id:Number(o.id)||0,parent_id:Number(o.parent_id)||0,level:Number(o.level)||1,title:o.title||'',start_ref:o.start_ref||'',end_ref:o.end_ref||'',sort_order:Number(o.sort_order)||0}));
      state.workspace=await api(`projects/${state.projectId}/workspace`,{method:'PUT',body:payload});state.deleteSegmentIds=[];state.dirty=false;renderSegments();renderNotes();renderOutline();renderSavedSources();renderManuscript();setStatus('Saved','ok');
    }catch(e){setStatus(`Save failed: ${e.message}`,'error');}}

  async function loadSources(force=false){if(!feature('sources')||!state.currentRef)return;const host=app.querySelector('#mtl-source-results');if(host)host.innerHTML='<div class="mtl-empty">Loading connected sources…</div>';try{const data=await api(`sefaria/links?ref=${encodeURIComponent(state.currentRef)}&categories=${encodeURIComponent('Commentary,Midrash,Quoting Commentary')}${force?'&r='+Date.now():''}`);state.links=Array.isArray(data)?data:(data.links||[]);renderSources();}catch(e){if(host)host.innerHTML=`<div class="mtl-error">${esc(e.message)}</div>`;}}
  function sourceRef(x){return x.sourceRef||x.source_ref||x.ref||x.toRef||x.linkedRef||x.anchorRef||'';}
  function sourceText(x){return flattenText(x.text||x.he||x.sourceText||x.highlightedWords||'');}
  function renderSources(){const host=app.querySelector('#mtl-source-results');if(!host)return;host.innerHTML=state.links.length?state.links.slice(0,80).map((x,i)=>{const ref=sourceRef(x),txt=sourceText(x),cat=x.category||x.type||'Related';return `<div class="mtl-source-result"><div><span class="mtl-badge">${esc(cat)}</span> <strong>${esc(ref||x.index_title||'Connected source')}</strong></div>${txt?`<p>${esc(txt.slice(0,700))}</p>`:''}<div class="mtl-source-actions"><a target="_blank" rel="noopener" href="${sefariaUrl(ref)}">Open ↗</a><button data-source="${i}">Save citation</button></div></div>`}).join(''):'<div class="mtl-empty">No matching connected sources returned for this reference.</div>';host.querySelectorAll('[data-source]').forEach(b=>b.addEventListener('click',()=>saveSource(Number(b.dataset.source))));renderSavedSources();}
  async function saveSource(index){const x=state.links[index];if(!x)return;try{const ref=sourceRef(x),txt=sourceText(x);await api(`projects/${state.projectId}/source`,{method:'POST',body:{sefaria_ref:state.currentRef,source_ref:ref,source_type:x.category||x.type||'related',version_title:x.versionTitle||x.version_title||'',license:x.license||'',title:x.index_title||ref,snippet:txt,metadata:x}});state.workspace=await api(`projects/${state.projectId}/workspace`);renderSavedSources();setStatus('Citation saved','ok');}catch(e){setStatus(e.message,'error');}}
  function renderSavedSources(){const host=app.querySelector('#mtl-saved-sources');if(!host||!state.workspace)return;const sources=state.workspace.sources||[];host.innerHTML=sources.length?sources.map(s=>`<div class="mtl-saved-source"><strong>${esc(s.source_ref||s.title||'Source')}</strong><div>${esc(s.source_type||'')}</div>${s.version_title?`<small>${esc(s.version_title)}</small>`:''}${s.license?`<small>License: ${esc(s.license)}</small>`:''}</div>`).join(''):'<div class="mtl-empty">No citations saved yet.</div>';}

  async function loadMembers(){if(!feature('collaborate')||!canManage())return renderAccess();try{state.members=await api(`projects/${state.projectId}/members`);renderAccess();}catch(e){setStatus(e.message,'error');}}
  function renderAccess(){const host=app.querySelector('#mtl-access-editor');if(!host)return;if(!state.workspace){host.innerHTML='';return;}if(!canManage()){host.innerHTML='<div class="mtl-empty">You can view this project, but collaborator management is limited to the owner/editor with collaboration access.</div>';return;}host.innerHTML=`<div class="mtl-collab-add"><input id="mtl-collab-user" list="mtl-user-suggestions" placeholder="email, username, or user ID"><datalist id="mtl-user-suggestions"></datalist><select id="mtl-collab-role"><option>viewer</option><option>commenter</option><option>contributor</option><option>editor</option></select><button id="mtl-collab-add">Add collaborator</button></div><div id="mtl-member-list">${state.members.length?state.members.map(m=>`<div class="mtl-member-row" data-user="${Number(m.user_id)}"><div><strong>${esc(m.display_name||m.user_login||'User')}</strong><small>${esc(m.user_email||'')}</small></div><select data-member-role>${['viewer','commenter','contributor','editor'].map(r=>`<option value="${r}" ${r===m.role?'selected':''}>${r}</option>`).join('')}</select><button data-member-remove class="mtl-danger">Remove</button></div>`).join(''):'<div class="mtl-empty">No named collaborators yet.</div>'}</div>`;const input=host.querySelector('#mtl-collab-user');let timer;input.addEventListener('input',()=>{clearTimeout(timer);timer=setTimeout(()=>searchUsers(input.value),300);});host.querySelector('#mtl-collab-add').addEventListener('click',addMember);host.querySelectorAll('.mtl-member-row').forEach(row=>{row.querySelector('[data-member-role]').addEventListener('change',()=>updateMember(row));row.querySelector('[data-member-remove]').addEventListener('click',()=>removeMember(row));});}
  async function searchUsers(q){if(q.trim().length<2)return;try{const users=await api(`users/search?q=${encodeURIComponent(q.trim())}`);const list=app.querySelector('#mtl-user-suggestions');if(list)list.innerHTML=users.map(u=>`<option value="${esc(u.email||u.login||u.id)}">${esc(u.display_name)} (${esc(u.login)})</option>`).join('');}catch(_) {}}
  async function addMember(){const user=app.querySelector('#mtl-collab-user').value.trim(),r=app.querySelector('#mtl-collab-role').value;if(!user)return;try{state.members=await api(`projects/${state.projectId}/members`,{method:'POST',body:{user,role:r}});renderAccess();setStatus('Collaborator added','ok');}catch(e){setStatus(e.message,'error');}}
  async function updateMember(row){try{state.members=await api(`projects/${state.projectId}/members/${Number(row.dataset.user)}`,{method:'PUT',body:{role:row.querySelector('[data-member-role]').value}});renderAccess();setStatus('Collaborator role updated','ok');}catch(e){setStatus(e.message,'error');}}
  async function removeMember(row){if(!window.confirm('Remove this collaborator from the project?'))return;try{state.members=await api(`projects/${state.projectId}/members/${Number(row.dataset.user)}`,{method:'DELETE'});renderAccess();setStatus('Collaborator removed','ok');}catch(e){setStatus(e.message,'error');}}

  function renderManuscript(){const host=app.querySelector('#mtl-manuscript-preview');if(!host||!state.workspace)return;const segs=state.workspace.segments||[],notes=state.workspace.annotations||[];host.innerHTML=`<h2>${esc(state.workspace.project.title)}</h2>`+(segs.length?segs.map(s=>{const relevant=notes.filter(a=>(a.segment_ref||a.start_ref)===s.sefaria_ref&&(a.targets||parseJson(a.targets_json,[])).includes('book'));return `<section><div class="mtl-man-ref">${esc(s.sefaria_ref)}</div>${s.source_hebrew?`<div class="mtl-hebrew" dir="rtl">${esc(s.source_hebrew)}</div>`:''}${s.translation?`<p class="mtl-man-translation">${esc(strip(s.translation))}</p>`:''}${relevant.map(n=>`<div class="mtl-man-note"><strong>${esc(n.title||n.annotation_type)}</strong> ${esc(strip(n.body))}</div>`).join('')}</section>`}).join(''):'<p class="mtl-empty">Translated segments will compile here as you work.</p>');const ent=app.querySelector('#mtl-entitlement-list');if(ent){const enabled=Object.entries(state.me.features).filter(([,v])=>v).map(([k])=>k);ent.innerHTML=enabled.map(k=>`<span>${esc(k)}</span>`).join('');}}

  function renderExportPanel(){const panel=app.querySelector('#mtl-export-panel');if(!panel)return;const pdfLabel=state.me?.pdf?.direct_download?'PDF':'PDF / Save as PDF';panel.innerHTML=`<div class="mtl-export-inner"><strong>Export</strong><label><input type="checkbox" id="mtl-ex-outline" checked> outline</label><label><input type="checkbox" id="mtl-ex-notes" checked> commentary</label>${feature('translate')?'<label><input type="checkbox" id="mtl-ex-trans"> my translation</label>':''}<label><input type="checkbox" id="mtl-ex-he"> Hebrew</label>${feature('sources')?'<label><input type="checkbox" id="mtl-ex-sources"> citations</label>':''}<span class="mtl-export-sep"></span><button data-format="docx">DOCX</button><button data-format="rtf">RTF</button><button data-format="pdf">${esc(pdfLabel)}</button>${feature('advanced_export')?'<select id="mtl-ex-profile"><option value="homework">Homework</option><option value="manuscript">Manuscript</option></select>':''}</div>`;panel.querySelectorAll('[data-format]').forEach(b=>b.addEventListener('click',()=>doExport(b.dataset.format)));}
  function doExport(format){if(!state.projectId)return;const q=new URLSearchParams({action:'mtl_export',project_id:String(state.projectId),format,profile:app.querySelector('#mtl-ex-profile')?.value||'homework',outline:app.querySelector('#mtl-ex-outline')?.checked?'1':'0',annotations:app.querySelector('#mtl-ex-notes')?.checked?'1':'0',translation:app.querySelector('#mtl-ex-trans')?.checked?'1':'0',hebrew:app.querySelector('#mtl-ex-he')?.checked?'1':'0',sources:app.querySelector('#mtl-ex-sources')?.checked?'1':'0',_wpnonce:cfg.exportNonce});const url=cfg.adminPostUrl+'?'+q.toString();if(format==='pdf')window.open(url,'_blank','noopener');else window.location.href=url;}

  window.addEventListener('beforeunload', e => { if(state.dirty){e.preventDefault();e.returnValue='';} });
  init();
})();
