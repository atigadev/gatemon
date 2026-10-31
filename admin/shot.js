// Screenshot: layar progress saat perintah dikirim, lalu penampil gambar yang bisa di-zoom
// (roda mouse / cubit dua jari / tombol / klik ganda) dan digeser dengan drag. Dipakai index.php dan monitor.php
// setelah app.js (memakai CSRF, esc, refresh dari app.js).
window.shotV = (function(){
  let el = null, poll = null, img = null, pinch = null;
  const view = {s:1, x:0, y:0, fit:1}, ptrs = new Map();
  const STEPS = ['Perintah dikirim ke server', 'Laptop mengambil perintah', 'Laptop memotret layar & mengirim gambar'];
  const stage = () => el.querySelector('.shotv-stage');

  function build(){
    el = document.createElement('div'); el.className = 'shotv'; el.hidden = true;
    el.setAttribute('role', 'dialog'); el.setAttribute('aria-modal', 'true');
    el.innerHTML = `<div class="shotv-bar"><b class="shotv-title"></b><span class="shotv-meta"></span>
      <span class="shotv-tools" hidden><button class="btn" data-z="out" title="Perkecil (-)">−</button><button class="btn shotv-pct" data-z="one" title="Ukuran asli (1)">100%</button><button class="btn" data-z="in" title="Perbesar (+)">+</button><button class="btn" data-z="fit" title="Pas layar (0)">Pas layar</button><a class="btn shotv-dl" download>Unduh</a></span>
      <button class="btn shotv-x" data-z="close" title="Tutup (Esc)" aria-label="Tutup">✕</button></div>
      <div class="shotv-stage"></div><div class="shotv-hint">Scroll / cubit untuk zoom · drag untuk geser · klik ganda: perbesar / pas layar</div>`;
    document.body.append(el);
    el.addEventListener('click', e=>{
      const b = e.target.closest('[data-z]'); if(!b) return;
      const z = b.dataset.z;
      if(z === 'close') close(); else if(z === 'fit') fit(); else if(z === 'one') zoomTo(1); else zoomBy(z === 'in' ? 1.25 : 0.8);
    });
    const st = stage();
    st.addEventListener('wheel', e=>{ if(!img) return; e.preventDefault(); zoomBy(Math.exp(-e.deltaY * 0.0015), e.clientX, e.clientY); }, {passive:false});
    st.addEventListener('dblclick', e=>{ if(!img) return; if(view.s > view.fit * 1.05) fit(); else zoomTo(Math.max(1, view.fit * 2.5), e.clientX, e.clientY); });
    st.addEventListener('pointerdown', e=>{ if(!img) return; st.setPointerCapture(e.pointerId); ptrs.set(e.pointerId, {x:e.clientX, y:e.clientY}); pinch = null; st.classList.add('drag'); });
    st.addEventListener('pointermove', e=>{
      const p = ptrs.get(e.pointerId); if(!p) return;
      if(ptrs.size >= 2){
        p.x = e.clientX; p.y = e.clientY;
        const [a, b] = [...ptrs.values()], d = Math.hypot(a.x - b.x, a.y - b.y), cx = (a.x + b.x) / 2, cy = (a.y + b.y) / 2;
        if(pinch){ view.x += cx - pinch.cx; view.y += cy - pinch.cy; zoomBy(d / pinch.d, cx, cy); }
        pinch = {d, cx, cy};
      } else { view.x += e.clientX - p.x; view.y += e.clientY - p.y; p.x = e.clientX; p.y = e.clientY; apply(); }
    });
    const up = e=>{ ptrs.delete(e.pointerId); pinch = null; if(!ptrs.size) st.classList.remove('drag'); };
    st.addEventListener('pointerup', up); st.addEventListener('pointercancel', up);
    window.addEventListener('resize', ()=>{ if(img && !el.hidden) fit(); });
    document.addEventListener('keydown', e=>{
      if(el.hidden) return;
      if(e.key === 'Escape'){ e.stopImmediatePropagation(); close(); return; }
      if(!img) return;
      if(e.key === '+' || e.key === '=') zoomBy(1.25);
      else if(e.key === '-') zoomBy(0.8);
      else if(e.key === '0') fit();
      else if(e.key === '1') zoomTo(1);
    }, true);
  }
  function apply(){
    if(!img) return;
    const r = stage().getBoundingClientRect(), w = img.naturalWidth * view.s, h = img.naturalHeight * view.s;
    // gambar tidak bisa digeser keluar layar; bila lebih kecil dari layar tetap di tengah
    view.x = w <= r.width ? (r.width - w) / 2 : Math.min(0, Math.max(r.width - w, view.x));
    view.y = h <= r.height ? (r.height - h) / 2 : Math.min(0, Math.max(r.height - h, view.y));
    img.style.transform = `translate(${view.x}px,${view.y}px) scale(${view.s})`;
    el.querySelector('.shotv-pct').textContent = Math.round(view.s * 100) + '%';
  }
  // zoom ke skala s dengan titik (cx, cy) di layar tetap di tempatnya (default: tengah)
  function zoomTo(s, cx, cy){
    const r = stage().getBoundingClientRect();
    s = Math.max(Math.min(view.fit, 1) * 0.5, Math.min(8, s));
    const px = (cx ?? r.left + r.width / 2) - r.left, py = (cy ?? r.top + r.height / 2) - r.top;
    view.x = px - (px - view.x) * s / view.s; view.y = py - (py - view.y) * s / view.s; view.s = s;
    apply();
  }
  const zoomBy = (f, cx, cy) => zoomTo(view.s * f, cx, cy);
  function fit(){
    const r = stage().getBoundingClientRect();
    view.fit = Math.min(r.width / img.naturalWidth, r.height / img.naturalHeight);
    view.s = view.fit; view.x = 0; view.y = 0;
    apply();
  }
  function open(title){
    if(!el) build();
    clearTimeout(poll); img = null; ptrs.clear();
    el.querySelector('.shotv-title').textContent = title;
    el.querySelector('.shotv-meta').textContent = '';
    el.querySelector('.shotv-tools').hidden = true;
    el.classList.remove('has-img');
    el.hidden = false;
  }
  function close(){
    clearTimeout(poll); poll = null; img = null;
    if(el){ el.hidden = true; stage().innerHTML = ''; }
  }
  function progress(step, sec, note, bad){
    stage().innerHTML = `<div class="shotv-prog${bad ? ' bad' : ''}">${bad ? '<div class="shotv-err">!</div>' : '<div class="shotv-spin"></div>'}
      <ol>${STEPS.map((s, i)=>`<li class="${i < step ? 'done' : (i === step && !bad ? 'now' : '')}">${s}</li>`).join('')}</ol>
      ${sec != null ? `<p class="shotv-sec">${sec} detik</p>` : ''}${note ? `<p class="shotv-note">${esc(note)}</p>` : ''}</div>`;
  }
  function show(id, meta){
    el.querySelector('.shotv-meta').textContent = meta || '';
    stage().innerHTML = '<div class="shotv-prog"><div class="shotv-spin"></div><p>Memuat gambar…</p></div>';
    const src = 'screenshot.php?id=' + encodeURIComponent(id);
    const im = new Image(); im.className = 'shotv-img'; im.alt = 'Screenshot'; im.draggable = false;
    im.onload = ()=>{
      if(el.hidden) return;
      stage().innerHTML = ''; stage().append(im); img = im;
      el.querySelector('.shotv-tools').hidden = false; el.classList.add('has-img');
      fit();
    };
    im.onerror = ()=>{ if(!el.hidden) progress(3, null, 'Gambar tidak ada atau sudah dihapus otomatis (menu Pengaturan: simpan screenshot).', true); };
    im.src = src;
    const dl = el.querySelector('.shotv-dl'); dl.href = src; dl.download = 'screenshot-' + id + '.jpg';
  }
  // Kirim SCREENSHOT ke satu laptop, tampilkan progress sampai gambar datang
  async function request(devId, name){
    open('Screenshot · ' + name);
    progress(0, 0);
    let j;
    try {
      const r = await fetch('command.php', {method:'POST', headers:{'Content-Type':'application/json', 'X-CSRF':CSRF}, body:JSON.stringify({type:'SCREENSHOT', device_id:devId})});
      j = await r.json().catch(()=>({ok:false, error:'HTTP ' + r.status}));
    } catch(err){ j = {ok:false, error:err.message}; }
    if(el.hidden) return;
    if(!j.ok || !(j.cmd_ids || []).length){ progress(0, null, 'Gagal mengirim perintah: ' + (j.error || 'tidak diketahui'), true); return; }
    if(typeof refresh === 'function') refresh();
    const cid = j.cmd_ids[0], t0 = Date.now(), LIMIT = 120;
    const tick = async ()=>{
      if(el.hidden) return;
      const sec = Math.round((Date.now() - t0) / 1000);
      let c = null;
      try { const r = await fetch('cmd_status.php?id=' + cid, {cache:'no-store'}); const k = await r.json(); if(k.ok) c = k.cmd; } catch(err){}
      if(el.hidden) return;
      if(c && c.status === 'done'){ if(typeof refresh === 'function') refresh(); show(cid, 'diambil ' + String(c.done_at || '').slice(11, 19)); return; }
      if(c && c.status === 'failed'){ progress(2, sec, 'Laptop gagal mengambil screenshot: ' + (c.result || 'tanpa keterangan'), true); return; }
      const step = c && c.status === 'sent' ? 2 : 1;
      if(sec >= LIMIT){ progress(step, sec, 'Belum ada gambar setelah ' + LIMIT + ' detik. Perintah tetap tersimpan: bila laptop online lagi, gambar bisa dibuka dari daftar perintah (lihat).', true); return; }
      progress(step, sec, step === 1 && sec >= 15 ? 'Laptop belum mengambil perintah: mungkin offline atau koneksi lambat. Perintah tetap menunggu.' : '');
      poll = setTimeout(tick, 1000);
    };
    poll = setTimeout(tick, 600);
  }
  return {request, view(id, title){ open(title || 'Screenshot'); show(id, ''); }, close};
})();
