// Menu Support: daftar percakapan per laptop + isi chat. Diperbarui berkala (pesan baru, tanda dibaca, darurat).
(function(){
  const root = document.getElementById('sup'); if(!root) return;
  const CSRF = document.querySelector('meta[name=csrf]').content;
  const SUP = document.querySelector('meta[name=role]').content === 'supervisor';
  const convsEl = document.getElementById('sup-convs'), main = document.getElementById('sup-main'), pick = document.getElementById('sup-pick');
  const QUICK = ['Baik, tim support segera ke lokasi.', 'Mohon tunggu sebentar.', 'Sudah teratasi? Mohon dicek kembali.', 'Silakan restart browser gate.'];
  let dev = +root.dataset.dev || 0, since = 0, seenUpto = 0, info = null, busy = false, devList = null;

  const el = (tag, cls, txt) => { const e = document.createElement(tag); if(cls) e.className = cls; if(txt != null) e.textContent = txt; return e; };
  const hm = s => (s || '').slice(11, 16);
  const day = s => { const d = new Date((s || '').replace(' ', 'T')); return isNaN(d) ? '' : d.toLocaleDateString('id-ID', {weekday: 'short', day: 'numeric', month: 'short'}); };
  async function api(params, post){
    const opt = {credentials: 'same-origin', cache: 'no-store'};
    if(post){ opt.method = 'POST'; opt.headers = {'X-CSRF': CSRF}; opt.body = new URLSearchParams(Object.assign({csrf: CSRF}, params)); }
    const r = await fetch('support_api.php' + (post ? '' : '?' + new URLSearchParams(params)), opt);
    const j = await r.json().catch(() => ({ok: false, error: 'sesi login habis'}));
    if(!j.ok) throw new Error(j.error || 'gagal');
    return j;
  }

  // ---------- daftar percakapan ----------
  async function loadConvs(){
    try{
      const j = await api({act: 'convs'});
      if(!devList){
        devList = j.devices;
        j.devices.forEach(d => { const o = el('option', '', d.name + (d.gate ? ' · ' + d.gate : '') + (d.category_name ? ' (' + d.category_name + ')' : '')); o.value = d.id; pick.append(o); });
      }
      convsEl.replaceChildren();
      if(!j.convs.length) convsEl.append(el('p', 'muted', 'Belum ada pesan 7 hari terakhir.'));
      j.convs.forEach(c => {
        const b = el('button', 'sup-conv' + (c.device_id === dev ? ' cur' : '') + (c.emg ? ' emg' : '')); b.type = 'button'; b.dataset.dev = c.device_id;
        const top = el('div', 'sup-conv-top');
        top.append(el('span', 'dot ' + (c.state === 'online' ? 'ok' : 'off')), el('b', '', c.name));
        if(c.emg) top.append(el('span', 'badge bad', 'DARURAT'));
        else if(c.unread) top.append(el('span', 'badge sup', c.unread));
        top.append(el('small', 'muted', hm(c.last_at)));
        const sub = el('div', 'sup-conv-sub muted', [c.category_name, c.gate].filter(Boolean).join(' · '));
        const last = el('div', 'sup-conv-last', (c.last_sender === 'agent' ? '' : c.last_sender === 'admin' ? 'Anda: ' : '• ') + c.last_body);
        b.append(top, sub, last); convsEl.append(b);
      });
      if(!dev && j.convs.length) open(j.convs[0].device_id);
    }catch(e){ convsEl.replaceChildren(el('p', 'notice error', 'Gagal memuat: ' + e.message)); }
  }
  convsEl.addEventListener('click', e => { const b = e.target.closest('.sup-conv'); if(b) open(+b.dataset.dev); });
  pick.addEventListener('change', () => { if(pick.value) open(+pick.value); pick.value = ''; });

  // ---------- isi chat ----------
  function open(id){
    if(id === dev && info) return;
    dev = id; since = 0; seenUpto = 0; info = null;
    const u = new URL(location.href); u.searchParams.set('dev', id); history.replaceState(null, '', u);
    convsEl.querySelectorAll('.sup-conv').forEach(b => b.classList.toggle('cur', +b.dataset.dev === id));
    main.replaceChildren(el('p', 'muted sup-empty', 'Memuat…'));
    loadThread();
  }
  function frame(d){
    main.replaceChildren();
    const head = el('div', 'sup-head');
    const t = el('div'); t.append(el('h2', '', d.name));
    t.append(el('small', 'muted', [d.category_name, d.gate, d.ip, d.state === 'online' ? 'online' : 'offline', d.agent_version ? 'agent ' + d.agent_version : ''].filter(Boolean).join(' · ')));
    head.append(t);
    const emg = el('div', 'sup-emg'); emg.hidden = true; emg.id = 'sup-emg';
    emg.append(el('b', '', 'DARURAT belum ditangani'), el('span', 'muted', ''));
    if(SUP){ const a = el('button', 'btn btn-danger', 'Tangani sekarang'); a.type = 'button'; a.id = 'sup-ack'; emg.append(a); }
    const warn = [];
    if(!d.supported) warn.push('Agent di laptop ini versi ' + (d.agent_version || '?') + ': belum punya tombol chat. Update agent ke 1.11 (menu Agent) agar pesan terlihat di laptop.');
    const list = el('div', 'sup-msgs'); list.id = 'sup-msgs';
    main.append(head, emg);
    warn.forEach(w => main.append(el('p', 'notice warn-notice small', w)));
    main.append(list);
    if(SUP){
      const f = el('form', 'sup-form'); f.id = 'sup-form';
      const q = el('div', 'sup-quick'); QUICK.forEach(x => { const b = el('button', 'sup-chip', x); b.type = 'button'; b.dataset.quick = x; q.append(b); });
      const row = el('div', 'sup-row');
      const ta = el('textarea'); ta.name = 'text'; ta.rows = 2; ta.maxLength = 1000; ta.placeholder = 'Tulis balasan… (Enter kirim, Shift+Enter baris baru)'; ta.required = true;
      const s = el('button', 'btn btn-primary', 'Kirim');
      row.append(ta, s); f.append(q, row); main.append(f);
    }
  }
  function bubble(m){
    const mine = m.sender === 'admin', sys = m.sender === 'system';
    const b = el('div', 'msg ' + (sys ? 'sys' : mine ? 'me' : 'them') + (m.kind === 'emergency' ? ' emg' : '')); b.dataset.id = m.id;
    if(!sys) b.append(el('div', 'msg-who', mine ? 'Support' + (m.author ? ' (' + m.author + ')' : '') : 'Laptop' + (m.author ? ' · ' + m.author : '')));
    b.append(el('div', 'msg-body', (m.kind === 'emergency' ? '🚨 ' : '') + m.body));
    const meta = el('div', 'msg-meta', hm(m.created_at));
    if(m.kind === 'emergency') meta.textContent += m.ack_at ? ' · ditangani ' + (m.ack_by || '') + ' ' + hm(m.ack_at) : ' · belum ditangani';
    if(mine){ const s = el('span', 'msg-seen', ''); meta.append(s); }
    b.append(meta);
    return b;
  }
  function paintSeen(){
    main.querySelectorAll('.msg.me').forEach(b => { const s = b.querySelector('.msg-seen'); if(s) s.textContent = +b.dataset.id <= seenUpto ? ' · dibaca ✓✓' : ' · terkirim ✓'; });
  }
  async function loadThread(){
    if(!dev || busy) return;
    busy = true;
    const want = dev;
    try{
      const j = await api({act: 'thread', dev, since});
      if(want !== dev) return;
      if(!info){ info = j.device; frame(info); }
      const list = document.getElementById('sup-msgs');
      const atBottom = since === 0 || list.scrollHeight - list.scrollTop - list.clientHeight < 40;
      let lastDay = list.dataset.day || '';
      j.msgs.forEach(m => {
        const dd = day(m.created_at); if(dd !== lastDay){ list.append(el('div', 'msg-day', dd)); lastDay = dd; }
        list.append(bubble(m)); since = Math.max(since, m.id);
      });
      list.dataset.day = lastDay;
      if(!list.children.length) list.append(el('p', 'muted sup-empty', 'Belum ada pesan dengan laptop ini.'));
      else list.querySelector('.sup-empty')?.remove();
      seenUpto = j.seen_upto; paintSeen();
      const box = document.getElementById('sup-emg');   // diatur sebelum menggulir: kotak ini mengubah tinggi daftar pesan
      if(box){ box.hidden = !j.emg_open; if(j.emg_open) box.querySelector('span').textContent = 'sejak ' + hm(j.emg_open); }
      if(j.msgs.length && atBottom) list.scrollTop = list.scrollHeight;
    }catch(e){
      if(want === dev && !info) main.replaceChildren(el('p', 'notice error', 'Gagal memuat: ' + e.message));
    }finally{ busy = false; }
  }
  main.addEventListener('click', async e => {
    const q = e.target.closest('[data-quick]');
    if(q){ const ta = main.querySelector('textarea'); ta.value = q.dataset.quick; ta.focus(); return; }
    if(e.target.id === 'sup-ack'){
      e.target.disabled = true;
      // dimuat ulang penuh: pesan darurat yang sudah tampil ikut berubah menjadi "ditangani"
      try{ await api({act: 'ack', dev}, true); window.GMSupport?.refresh(); info = null; since = 0; await loadThread(); loadConvs(); }
      catch(err){ alert('Gagal: ' + err.message); }
      finally{ e.target.disabled = false; }
    }
  });
  main.addEventListener('keydown', e => {
    if(e.target.tagName === 'TEXTAREA' && e.key === 'Enter' && !e.shiftKey && !e.isComposing){ e.preventDefault(); e.target.form.requestSubmit(); }
  });
  main.addEventListener('submit', async e => {
    e.preventDefault();
    const f = e.target, ta = f.elements.text, btn = f.querySelector('button.btn-primary'), text = ta.value.trim();
    if(!text) return;
    btn.disabled = true;
    try{ await api({act: 'send', dev, text}, true); ta.value = ''; await loadThread(); loadConvs(); }
    catch(err){ alert('Gagal mengirim: ' + err.message); }
    finally{ btn.disabled = false; ta.focus(); }
  });

  loadConvs();
  if(dev) open(dev);
  setInterval(() => { if(!document.hidden) loadThread(); }, 2500);
  setInterval(() => { if(!document.hidden) loadConvs(); }, 5000);
})();
