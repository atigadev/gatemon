// Interaksi UI bersama semua halaman: ganti tema gelap/terang dan menu hamburger di layar kecil.
document.addEventListener('click', e=>{
  if(e.target.closest('[data-theme-toggle]')){
    const t = document.documentElement.getAttribute('data-theme')==='light' ? 'dark' : 'light';
    document.documentElement.setAttribute('data-theme', t);
    try{ localStorage.setItem('gm_theme', t); }catch(err){}
    return;
  }
  const nt = e.target.closest('[data-nav-toggle]');
  const bar = document.querySelector('.topbar');
  if(nt){ const open = bar.classList.toggle('nav-open'); nt.setAttribute('aria-expanded', open); return; }
  if(bar && bar.classList.contains('nav-open') && !e.target.closest('.nav')) bar.classList.remove('nav-open');
});
// Konfirmasi aksi berbahaya: <button data-confirm="..."> atau <form data-confirm="...">. Teks dibaca dari atribut
// (bukan onclick inline): aman untuk nama berisi tanda kutip dan sesuai Content-Security-Policy.
document.addEventListener('click', e=>{
  const b = e.target.closest('[data-confirm]');
  if(b && b.tagName !== 'FORM' && !confirm(b.dataset.confirm)){ e.preventDefault(); e.stopImmediatePropagation(); }
}, true);
document.addEventListener('submit', e=>{
  const f = e.target;
  if(f.matches && f.matches('form[data-confirm]') && !confirm(f.dataset.confirm)){ e.preventDefault(); e.stopImmediatePropagation(); }
}, true);
// Popup notifikasi di tengah layar: tertutup sendiri setelah 10 detik (berhenti sementara saat kursor di atasnya).
// gmPopup({title, text, level: 'ok'|'warn'|'bad'|'info', href}). Teks diisi lewat textContent (aman dari HTML).
window.gmPopup = (function(){
  const LIFE = 10000, MAX = 4, ICON = {ok:'✓', warn:'!', bad:'!', info:'i'};
  let box = null;
  return function(o){
    if(!box || !box.isConnected){ box = document.createElement('div'); box.className = 'gm-pops'; box.setAttribute('aria-live', 'polite'); document.body.append(box); }
    const level = ICON[o.level] ? o.level : 'info';
    const p = document.createElement(o.href ? 'a' : 'div');
    p.className = 'gm-pop ' + level; p.setAttribute('role', level === 'bad' ? 'alert' : 'status');
    if(o.href) p.href = o.href;
    const ic = document.createElement('span'); ic.className = 'gm-pop-ic'; ic.textContent = ICON[level];
    const body = document.createElement('div'); body.className = 'gm-pop-body';
    const t = document.createElement('b'); t.textContent = o.title || 'GateMon';
    const s = document.createElement('span'); s.textContent = o.text || '';
    body.append(t, s);
    const x = document.createElement('button'); x.type = 'button'; x.className = 'gm-pop-x'; x.textContent = '✕'; x.title = 'Tutup'; x.setAttribute('aria-label', 'Tutup');
    const bar = document.createElement('span'); bar.className = 'gm-pop-bar'; bar.style.animationDuration = LIFE + 'ms';
    p.append(ic, body, x, bar);
    box.prepend(p);
    while(box.children.length > MAX) box.lastChild.remove();
    let left = LIFE, start = Date.now(), timer = null;
    const close = () => { clearTimeout(timer); p.classList.add('out'); setTimeout(() => p.remove(), 400); };
    const run = () => { start = Date.now(); timer = setTimeout(close, left); };
    p.addEventListener('mouseenter', () => { clearTimeout(timer); left = Math.max(1500, left - (Date.now() - start)); });
    p.addEventListener('mouseleave', run);
    x.addEventListener('click', e => { e.preventDefault(); e.stopPropagation(); close(); });
    run();
    return p;
  };
})();
// Nama laptop/kategori untuk dibacakan suara: "TICKET-BOX-01" -> "Ticket Box 1". Kata kapital semua dibaca mesin suara
// sebagai singkatan (dieja huruf per huruf), jadi kata berhuruf vokal diubah ke huruf awal kapital; tanpa vokal ("RC") tetap dieja.
window.speakName = function(s){
  return String(s ?? '')
    .replace(/[-_.\/\\]+/g, ' ')
    .replace(/([A-Za-z])(\d)/g, '$1 $2').replace(/(\d)([A-Za-z])/g, '$1 $2')
    .replace(/\b[A-Z]{2,}\b/g, w => /[AIUEO]/.test(w) ? w[0] + w.slice(1).toLowerCase() : w)
    .replace(/\b0+(\d)/g, '$1')
    .replace(/\s+/g, ' ').trim();
};
