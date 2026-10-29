// Halaman Laporan: klik ID QR -> riwayat scan QR itu dimuat ke modal (tanpa pindah halaman).
(function(){
  const modal = document.getElementById('hist-modal'), body = document.getElementById('hist-body');
  if(!modal) return;

  function close(){
    modal.hidden = true;
    // modal yang terbuka lewat link ?code= tidak muncul lagi saat halaman di-refresh
    const u = new URL(location.href);
    if(u.searchParams.has('code')){ u.searchParams.delete('code'); history.replaceState(null, '', u); }
  }
  async function open(code, href){
    body.innerHTML = '<div class="modal-head"><h3>Riwayat scan</h3><button type="button" class="icon-btn sm" data-hist-close aria-label="Tutup">✕</button></div>'
      + '<p class="muted hist-body">Memuat riwayat…</p>';
    modal.hidden = false;
    try{
      const r = await fetch('report.php?history=' + encodeURIComponent(code), {credentials: 'same-origin', cache: 'no-store'});
      if(!r.ok || r.headers.get('X-GateMon-History') !== '1') throw new Error('sesi');   // sesi habis -> halaman login
      body.innerHTML = await r.text();
      body.querySelector('[data-hist-close]')?.focus();
    }catch(e){ location.href = href; }   // cadangan: buka lewat halaman biasa
  }

  document.addEventListener('click', e => {
    const a = e.target.closest('a.rep-code');
    if(a && !e.ctrlKey && !e.metaKey && !e.shiftKey && e.button === 0){ e.preventDefault(); open(a.dataset.code, a.href); return; }
    if(e.target === modal || e.target.closest('[data-hist-close]')) close();
  });
  document.addEventListener('keydown', e => { if(e.key === 'Escape' && !modal.hidden) close(); });

  // modal "Atur group kategori" (supervisor)
  document.addEventListener('click', e => {
    const op = e.target.closest('[data-open-modal]');
    if(op){ const m = document.getElementById(op.dataset.openModal); if(m){ m.hidden = false; m.querySelector('input[name="grp[]"]')?.focus(); } return; }
    const gm = e.target.closest('.modal');
    if(gm && gm.id !== 'hist-modal' && (e.target === gm || e.target.closest('[data-close-modal]'))) gm.hidden = true;
  });
  document.addEventListener('keydown', e => { if(e.key === 'Escape') document.querySelectorAll('.modal:not(#hist-modal)').forEach(m => m.hidden = true); });

  // ---------- daftar bertingkat halaman (server-side): halaman, baris per halaman, urut kolom, cari ----------
  // State tiap daftar disimpan di URL ({t}p,{t}n,{t}o,{t}d,{t}q) sehingga refresh / muat ulang otomatis tetap di halaman yang sama.
  let listBusy = 0;
  const qTimers = {};
  async function loadList(sec, changes){
    const t = sec.dataset.list, u = new URL(location.href);
    u.searchParams.delete('code'); u.searchParams.delete('auto');
    for(const [k, v] of Object.entries(changes)){
      // q kosong tetap ditulis (uq=) agar tidak kembali memakai kotak "Cari ID QR" utama
      if(k !== 'q' && (v === '' || v === null)) u.searchParams.delete(t + k); else u.searchParams.set(t + k, v);
    }
    history.replaceState(null, '', u);
    const req = new URL(u); req.searchParams.set('list', t);
    listBusy++; sec.classList.add('loading');
    try{
      const r = await fetch(req, {credentials: 'same-origin', cache: 'no-store'});
      if(!r.ok || r.headers.get('X-GateMon-List') !== '1') throw new Error('sesi');
      const box = document.createElement('div'); box.innerHTML = await r.text();
      const fresh = box.querySelector('.rep-list');
      const cur = document.getElementById(sec.id);
      if(fresh && cur){
        const hadFocus = document.activeElement?.classList.contains('rep-list-q') && cur.contains(document.activeElement);
        const pos = hadFocus ? document.activeElement.selectionStart : null;
        cur.replaceWith(fresh);
        if(hadFocus){ const q = fresh.querySelector('.rep-list-q'); q.focus(); try{ q.setSelectionRange(pos, pos); }catch(e){} }
      }
    }catch(e){ location.href = u; }   // sesi habis / gagal: buka lewat halaman biasa
    finally{ listBusy--; document.getElementById(sec.id)?.classList.remove('loading'); }
  }
  document.addEventListener('click', e => {
    const sec = e.target.closest('.rep-list'); if(!sec) return;
    const pg = e.target.closest('button[data-page]');
    if(pg && !pg.disabled){ loadList(sec, {p: pg.dataset.page}); return; }
    const th = e.target.closest('th[data-sort]');
    if(th){ const col = th.dataset.sort, same = sec.dataset.o === col;
      loadList(sec, {o: col, d: same ? (sec.dataset.d === 'asc' ? 'desc' : 'asc') : (col === 'n' || col.endsWith('_at') && sec.dataset.list === 'f' ? 'desc' : 'asc'), p: 1}); }
  });
  document.addEventListener('keydown', e => {
    const th = e.target.closest?.('.rep-list th[data-sort]');
    if(th && (e.key === 'Enter' || e.key === ' ')){ e.preventDefault(); th.click(); }
  });
  document.addEventListener('change', e => {
    if(e.target.classList.contains('rep-list-n')) loadList(e.target.closest('.rep-list'), {n: e.target.value, p: 1});
  });
  document.addEventListener('input', e => {
    if(!e.target.classList.contains('rep-list-q')) return;
    const sec = e.target.closest('.rep-list'), v = e.target.value.trim();
    clearTimeout(qTimers[sec.id]);
    qTimers[sec.id] = setTimeout(() => loadList(sec, {q: v, p: 1}), 350);   // tunggu selesai mengetik
  });

  // ---------- muat ulang otomatis (kehadiran terbaru tanpa reload halaman) ----------
  // Isi <main> diambil ulang dari URL yang sama lalu ditukar; ditunda selama modal terbuka, sedang mengetik di filter,
  // atau tab tidak terlihat. Jeda disimpan per browser; ?auto=30 di URL memaksa jeda (untuk layar TV/monitor).
  const KEY = 'gm_report_auto';
  const sel = () => document.getElementById('auto-refresh');
  const st = () => document.getElementById('auto-status');
  let every = 0, next = 0, busy = false, last = null, failed = 0, expired = false;
  function saved(){ try{ return localStorage.getItem(KEY); }catch(e){ return null; } }
  function save(v){ try{ localStorage.setItem(KEY, String(v)); }catch(e){} }
  function clock(d){ return d.toLocaleTimeString('id-ID', {hour: '2-digit', minute: '2-digit', second: '2-digit'}); }
  function paused(){
    return document.hidden || [...document.querySelectorAll('.modal')].some(m => !m.hidden)
      || !!document.activeElement?.closest('.rep-filters, .rep-list-tools') || listBusy > 0;
  }
  function show(){
    const s = st(); if(!s) return;
    if(expired){ s.textContent = 'Sesi login habis, muat ulang halaman'; s.className = 'rep-auto-st on err'; return; }
    if(!every){ s.textContent = last ? 'Diperbarui ' + clock(last) : ''; s.className = 'rep-auto-st'; return; }
    const left = Math.max(0, Math.ceil((next - Date.now()) / 1000));
    s.className = 'rep-auto-st on' + (failed ? ' err' : '');
    s.textContent = (failed ? 'Gagal memuat, dicoba lagi' : (busy ? 'Memuat…' : (paused() ? 'Ditunda' : left + ' dtk lagi')))
      + (last ? ' · diperbarui ' + clock(last) : '');
  }
  const num = el => parseInt(el?.textContent, 10) || 0;
  async function refresh(){
    if(busy) return;
    busy = true; show();
    try{
      const u = new URL(location.href); u.searchParams.delete('code'); u.searchParams.delete('auto');
      const r = await fetch(u, {credentials: 'same-origin', cache: 'no-store'});
      const doc = new DOMParser().parseFromString(await r.text(), 'text/html');
      const nm = doc.querySelector('main.page'), om = document.querySelector('main.page');
      if(!r.ok || !nm || !nm.querySelector('.summary')) throw new Error(r.url.includes('login') ? 'sesi' : 'muat');
      if(paused()) return;                                   // pengguna mulai membuka modal / mengetik selama memuat
      const before = num(om.querySelector('.summary .stat.ok .value'));
      const vals = [...om.querySelectorAll('.stat .value')].map(v => v.textContent);
      om.innerHTML = nm.innerHTML;
      // tandai angka yang berubah + jumlah QR kehadiran baru
      om.querySelectorAll('.stat .value').forEach((v, i) => { if(vals[i] !== undefined && vals[i] !== v.textContent){ v.classList.remove('bump'); void v.offsetWidth; v.classList.add('bump'); } });
      const added = num(om.querySelector('.summary .stat.ok .value')) - before;
      if(added > 0){ const b = document.createElement('span'); b.className = 'badge sup rep-new'; b.textContent = '+' + added + ' QR baru'; om.querySelector('.summary .stat.ok .label')?.after(b); }
      failed = 0; last = new Date();
    }catch(e){
      failed++;
      if(e.message === 'sesi'){ expired = true; every = 0; }   // berhenti: jangan terus memuat halaman login
    }finally{
      busy = false;
      const s = sel(); if(s) s.value = String(every);           // kontrol ikut tertukar bersama <main>
      next = Date.now() + every * 1000 * (failed ? Math.min(4, failed) : 1);
      show();
    }
  }
  function set(v){
    every = [0, 10, 30, 60, 120, 300].includes(+v) ? +v : 0;
    if(every) expired = false;
    next = Date.now() + every * 1000;
    const s = sel(); if(s) s.value = String(every);
    show();
  }
  document.addEventListener('change', e => { if(e.target.id === 'auto-refresh'){ save(e.target.value); set(e.target.value); } });
  document.addEventListener('visibilitychange', () => { if(!document.hidden && every && Date.now() >= next) refresh(); });
  setInterval(() => {
    if(every && !busy && Date.now() >= next){ if(paused()) next = Date.now() + 2000; else refresh(); }
    show();
  }, 1000);
  // awal: ?auto= di URL > pilihan tersimpan > 30 dtk bila laporan mencakup hari ini, selain itu mati
  const s0 = sel();
  if(s0){
    const fromUrl = s0.dataset.url, keep = saved();
    set(fromUrl !== '' ? fromUrl : (keep !== null ? keep : (s0.dataset.today === '1' ? 30 : 0)));
    if(fromUrl !== '') save(every);
    last = new Date(); show();
  }
  if(!modal.hidden) body.querySelector('[data-hist-close]')?.focus();
})();
