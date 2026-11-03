// Halaman Pengaturan: penanda perubahan belum disimpan, "Pakai bawaan" per pengaturan, validasi langsung,
// perkiraan beban, pengaturan yang bergantung sakelar lain, cari pengaturan, dan navigasi bagian.
(function(){
  const form = document.getElementById('set-form');
  if (!form) return;
  const rows = [...form.querySelectorAll('.st-row')];
  const inp = r => r.querySelector('input');
  const val = r => { const i = inp(r); return i.type === 'checkbox' ? (i.checked ? '1' : '0') : i.value.trim(); };
  const set = (r, v) => { const i = inp(r); if (i.type === 'checkbox') i.checked = v === '1'; else i.value = v; };
  const orig = new Map(rows.map(r => [r, val(r)]));
  const byKey = k => rows.find(r => r.dataset.key === k);
  const num = k => +(inp(byKey(k)).value) || 1;
  const bar = document.getElementById('st-bar'), msg = document.getElementById('st-msg'), revert = document.getElementById('st-revert');
  let submitting = false;

  // perkiraan beban
  const L = document.getElementById('load'), n = +L.dataset.n, ng = +L.dataset.ng;
  const fmt = x => (x >= 100 ? Math.round(x) : Math.round(x * 10) / 10) + ' request/menit';
  const put = (id, t) => { document.getElementById(id).textContent = t; };
  function calc(){
    put('l-hb', fmt(n * 60 / num('heartbeat_interval')));
    put('l-gate', fmt(ng * 60 / num('gate_check_interval')));
    // HEAD lewat koneksi keep-alive: ~1 KB per cek
    const kbps = ng * 8 / num('gate_check_interval');
    put('l-gatebw', (kbps < 1 ? kbps.toFixed(2) : kbps.toFixed(1)) + ' kbps');
    put('l-dash', fmt(60 / num('dashboard_refresh')));
    put('l-off', num('offline_after') + ' detik');
  }

  // validasi yang sama dengan server: batas offline minimal 3x heartbeat
  function check(){
    const off = byKey('offline_after'), min = 3 * num('heartbeat_interval');
    const bad = +inp(off).value < min;
    inp(off).setCustomValidity(bad ? 'Minimal ' + min + ' detik (3x interval heartbeat)' : '');
    const e = off.querySelector('.st-err');
    e.hidden = !bad; e.textContent = bad ? 'Minimal ' + min + ' detik (3x interval heartbeat), agar laptop tidak salah dianggap offline.' : '';
    rows.forEach(r => {
      const i = inp(r);
      if (r === off || i.type === 'checkbox') return;
      r.classList.toggle('st-invalid', !i.checkValidity());
    });
    off.classList.toggle('st-invalid', bad);
  }

  function refresh(){
    let changed = 0;
    const perSec = {};
    rows.forEach(r => {
      const v = val(r), ch = v !== orig.get(r);
      r.classList.toggle('st-changed', ch);
      const u = r.querySelector('.st-undo'); if (u) u.hidden = v === r.dataset.def;
      const st = r.querySelector('.st-state'); if (st) st.textContent = v === '1' ? 'Aktif' : 'Mati';
      if (r.dataset.dep) r.classList.toggle('st-dim', val(byKey(r.dataset.dep)) !== '1');
      if (ch) { changed++; const s = r.closest('.st-sec').id; perSec[s] = (perSec[s] || 0) + 1; }
    });
    document.querySelectorAll('.st-nav a[data-sec]').forEach(a => {
      const c = a.querySelector('.st-cnt'); if (!c) return;
      c.hidden = !perSec[a.dataset.sec]; c.textContent = perSec[a.dataset.sec] || '';
    });
    if (bar) {
      bar.classList.toggle('dirty', changed > 0);
      msg.textContent = changed ? changed + ' perubahan belum disimpan' : 'Semua pengaturan tersimpan';
      revert.disabled = !changed;
    }
    calc(); check();
    return changed;
  }
  form.addEventListener('input', refresh);
  form.addEventListener('change', refresh);
  form.addEventListener('click', e => {
    const u = e.target.closest('.st-undo');
    if (u) { const r = u.closest('.st-row'); set(r, r.dataset.def); refresh(); inp(r).focus(); }
  });
  if (revert) revert.addEventListener('click', () => { rows.forEach(r => set(r, orig.get(r))); refresh(); });
  form.addEventListener('submit', () => { submitting = true; });
  // isian yang belum valid: tunjukkan bagiannya (browser hanya memberi tooltip di input pertama)
  form.addEventListener('invalid', e => { const r = e.target.closest('.st-row'); if (r) r.classList.add('st-invalid'); }, true);
  window.addEventListener('beforeunload', e => {
    if (!submitting && bar && rows.some(r => val(r) !== orig.get(r))) { e.preventDefault(); e.returnValue = ''; }
  });

  // cari pengaturan
  const q = document.getElementById('st-q'), empty = document.getElementById('st-empty');
  const secs = [...document.querySelectorAll('.st-sec')];
  q.addEventListener('input', () => {
    const t = q.value.trim().toLowerCase();
    let any = false;
    secs.forEach(s => {
      const rs = [...s.querySelectorAll('.st-row')];
      let show;
      if (rs.length) {
        rs.forEach(r => { r.hidden = t !== '' && !r.textContent.toLowerCase().includes(t); });
        show = rs.some(r => !r.hidden);
        const ld = s.querySelector('.st-load'); if (ld) ld.hidden = t !== '';
      } else show = t === '' || s.textContent.toLowerCase().includes(t);
      s.hidden = !show; any = any || show;
      const a = document.querySelector('.st-nav a[data-sec="' + s.id + '"]'); if (a) a.classList.toggle('st-off', !show);
    });
    empty.hidden = any;
  });

  // tandai bagian yang sedang dibaca di navigasi: bagian terakhir yang judulnya sudah lewat di bawah topbar / navigasi
  const nav = document.getElementById('st-nav');
  const links = new Map([...nav.querySelectorAll('a[data-sec]')].map(a => [a.dataset.sec, a]));
  let cur = null, tick = false;
  function spy(){
    tick = false;
    const lim = (nav.getBoundingClientRect().bottom > 0 && getComputedStyle(nav).flexDirection === 'row' ? nav.getBoundingClientRect().bottom : 76) + 40;
    const vis = secs.filter(s => !s.hidden);
    let on = vis[0];
    vis.forEach(s => { if (s.getBoundingClientRect().top <= lim) on = s; });
    if (innerHeight + scrollY >= document.documentElement.scrollHeight - 4) on = vis[vis.length - 1];
    if (!on || on.id === cur) return;
    cur = on.id;
    links.forEach((a, id) => a.classList.toggle('on', id === cur));
    const a = links.get(cur);
    if (a && nav.scrollWidth > nav.clientWidth) nav.scrollTo({left: a.offsetLeft - 16, behavior: 'smooth'});
  }
  addEventListener('scroll', () => { if (!tick) { tick = true; requestAnimationFrame(spy); } }, {passive: true});
  q.addEventListener('input', () => { cur = null; spy(); });
  spy();
  refresh();
})();
