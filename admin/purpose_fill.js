// Laporan: modal "Lengkapi peruntukan" (supervisor). Memanggil purpose_fill.php bertahap sampai semua ID dicek;
// halaman dimuat ulang saat modal ditutup bila ada peruntukan yang terisi. Auto-refresh laporan berhenti selama modal terbuka.
(function(){
  const modal = document.getElementById('purp-modal');
  if(!modal) return;
  const $ = id => document.getElementById(id);
  let running = false, stop = false, changed = 0;
  const all = () => $('purp-all').checked ? '&all=1' : '';
  function msg(t, cls){ $('purp-msg').textContent = t; $('purp-msg').className = 'small ' + (cls || ''); }
  function counts(j){
    $('purp-pending').textContent = j.pending;
    ['found', 'notfound', 'error'].forEach(k => $('purp-' + k).textContent = j[k]);
  }
  async function status(keep){
    try{
      const r = await fetch('purpose_fill.php?act=status' + all(), {cache: 'no-store'});
      const j = await r.json().catch(() => ({ok: false, error: 'HTTP ' + r.status}));
      if(!j.ok) throw new Error(j.error);
      counts(j);
      $('purp-run').disabled = !j.pending || !j.url_ok;
      if(!keep || !j.url_ok) msg(!j.url_ok ? 'Link cek peruntukan di menu Pengaturan tidak valid (harus https dan berisi {id}).' : (j.pending ? '' : 'Semua ID sudah dicek.'), j.url_ok ? 'muted' : 'bad');
      return j;
    }catch(e){ msg('Gagal memuat status: ' + e.message, 'bad'); }
  }
  async function run(){
    running = true; stop = false;
    const btn = $('purp-run'), bar = modal.querySelector('.purp-prog');
    btn.textContent = 'Hentikan'; $('purp-all').disabled = true; bar.hidden = false;
    let total = null, first = 1, filled = 0;
    try{
      while(!stop){
        const r = await fetch('purpose_fill.php', {method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'},
          body: 'act=run&csrf=' + encodeURIComponent(modal.dataset.csrf) + all() + (first ? '&first=1' : '')});
        const j = await r.json().catch(() => ({ok: false, error: 'HTTP ' + r.status}));
        if(!j.ok) throw new Error(j.error || 'gagal');
        first = 0;
        counts(j);
        total = total ?? j.pending + j.batch.checked;
        filled += j.batch.scans; changed += j.batch.scans;
        bar.firstElementChild.style.width = (total ? Math.round((total - j.pending) / total * 100) : 100) + '%';
        msg('Dicek ' + (total - j.pending) + ' dari ' + total + ' ID · ' + filled + ' scan terisi peruntukan'
          + (j.batch.last_error ? ' · gagal terakhir: ' + j.batch.last_error : ''), 'muted');
        if(!j.pending || !j.batch.checked) break;
      }
      msg((stop ? 'Dihentikan. ' : 'Selesai. ') + filled + ' scan terisi peruntukan.' + (changed ? ' Laporan dimuat ulang saat modal ditutup.' : ''), stop ? 'muted' : 'ok');
    }catch(e){ msg('Berhenti: ' + e.message, 'bad'); }
    finally{
      running = false;
      btn.textContent = 'Mulai'; $('purp-all').disabled = false;
      if(modal.hidden && changed) location.reload(); else await status(true);
    }
  }
  document.addEventListener('click', e => {
    if(e.target.closest('[data-open-modal="purp-modal"]')){ msg(''); status(); return; }
    if(e.target.closest('#purp-run')){ if(running) stop = true; else run(); }
  });
  $('purp-all').addEventListener('change', () => status());
  // modal ditutup (tombol / Esc / klik luar, ditangani report.js): muat ulang bila ada yang berubah
  new MutationObserver(() => {
    if(modal.hidden){ stop = true; if(changed && !running) location.reload(); }
  }).observe(modal, {attributes: true, attributeFilter: ['hidden']});
})();
