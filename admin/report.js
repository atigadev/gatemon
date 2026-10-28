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
  if(!modal.hidden) body.querySelector('[data-hist-close]')?.focus();
})();
