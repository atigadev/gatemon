// Layar live: minta laptop mengirim gambar layar, tampilkan gambar terbaru terus-menerus.
// Sesi diperpanjang tiap 10 dtk; berhenti saat halaman ditutup/disembunyikan atau setelah batas waktu.
(function(){
  const box = document.getElementById('live'); if(!box) return;
  const dev = box.dataset.dev, CSRF = document.querySelector('meta[name=csrf]').content;
  const $ = id => document.getElementById(id);
  const img = $('live-img'), statusEl = $('live-status'), btn = $('live-toggle'), modeSel = $('live-mode');
  let running = false, seq = 0, url = null, keepTimer = null, info = null, hidden = false;
  const stats = [];                                   // [waktu, byte] gambar 10 dtk terakhir

  async function post(act){
    const r = await fetch('live_api.php', {method: 'POST', credentials: 'same-origin', headers: {'X-CSRF': CSRF},
      body: new URLSearchParams({act, dev, mode: modeSel.value, csrf: CSRF})});
    const j = await r.json().catch(() => ({ok: false, error: 'sesi login habis, muat ulang halaman'}));
    return j;
  }
  function say(t, bad){ statusEl.textContent = t; statusEl.classList.toggle('bad', !!bad); }
  function paint(){
    if(!running) return;
    const now = Date.now();
    while(stats.length && now - stats[0][0] > 10000) stats.shift();
    const fps = stats.length / 10, kbps = stats.reduce((a, s) => a + s[1], 0) * 8 / 10 / 1000;
    if(!info) return;
    if(info.err) return say('Layar laptop tidak bisa diambil: ' + info.err, true);
    if(!info.online) return say('Laptop offline: menunggu laptop tersambung lagi…', true);
    if(!seq) return say('Menunggu laptop mulai mengirim (paling lama ±' + (info.heartbeat + 2) + ' detik)…');
    const left = info.left != null ? Math.ceil(info.left / 60) : null;
    // agent hanya mengirim gambar bila layar berubah: 0 gambar = layar diam, bukan putus
    say(`Live · ${img.naturalWidth || info.w}×${img.naturalHeight || info.h} · `
      + (stats.length ? `${fps.toFixed(1)} gambar/dtk · ${kbps < 1000 ? Math.round(kbps) + ' kbps' : (kbps / 1000).toFixed(1) + ' Mbps'}` : 'layar tidak berubah')
      + (info.frame_age > 5 ? ` · gambar terakhir ${info.frame_age} dtk lalu` : '') + (left != null ? ` · sisa sesi ±${left} mnt` : ''));
  }
  async function keep(first){
    const j = await post(first ? 'start' : 'keep');
    if(!j.ok){ stop(j.expired ? 'Sesi ' + (j.error || 'selesai') + '. Klik Mulai untuk melanjutkan.' : 'Gagal: ' + j.error, true); return false; }
    info = j; paint(); return true;
  }
  async function loop(){
    while(running){
      const t = Date.now();
      try{
        const r = await fetch(`live_api.php?act=frame&dev=${dev}&since=${seq}`, {credentials: 'same-origin', cache: 'no-store'});
        if(r.status === 200){
          const b = await r.blob();
          if(!running) break;
          seq = +r.headers.get('X-Live-Seq') || seq + 1;
          const u = URL.createObjectURL(b);
          img.onload = () => { if(url) URL.revokeObjectURL(url); url = u; };
          img.src = u; img.hidden = false; $('live-empty').hidden = true; $('live-save').disabled = false;
          stats.push([Date.now(), b.size]);
          $('live-dot').classList.add('on');
        } else if(r.status === 401 || r.status === 403){ stop('Sesi login habis, muat ulang halaman.', true); break; }
      }catch(e){}
      paint();
      await new Promise(res => setTimeout(res, Math.max(150, 350 - (Date.now() - t))));
    }
  }
  async function start(){
    if(running) return;
    running = true; seq = 0; stats.length = 0;
    btn.textContent = 'Hentikan'; btn.classList.remove('btn-primary'); btn.classList.add('btn-danger');
    say('Memulai…');
    if(!await keep(true)) return;
    keepTimer = setInterval(() => keep(false), 10000);
    loop();
  }
  function stop(msg, bad){
    if(running){
      const fd = new FormData(); fd.append('act', 'stop'); fd.append('dev', dev); fd.append('csrf', CSRF);
      navigator.sendBeacon('live_api.php', fd);         // tetap terkirim walau halaman sedang ditutup
    }
    running = false; clearInterval(keepTimer); keepTimer = null;
    btn.textContent = 'Mulai'; btn.classList.add('btn-primary'); btn.classList.remove('btn-danger');
    $('live-dot').classList.remove('on');
    say(msg || 'Dihentikan. Tanda di layar laptop hilang dalam beberapa detik.', bad);
  }

  btn.addEventListener('click', () => running ? stop() : start());
  modeSel.addEventListener('change', () => { if(running) keep(false); });
  $('live-full').addEventListener('click', () => { const s = $('live-screen'); document.fullscreenElement ? document.exitFullscreen() : s.requestFullscreen?.(); });
  $('live-save').addEventListener('click', () => {
    if(!img.src) return;
    const a = document.createElement('a'); a.href = img.src;
    a.download = 'layar-' + (document.title.split(' - ')[0].replace(/^Layar /, '').replace(/[^\w.-]+/g, '_')) + '-' + new Date().toISOString().slice(0, 19).replace(/[:T]/g, '') + '.jpg';
    a.click();
  });
  // tab disembunyikan: hentikan (hemat trafik & laptop tidak terus menampilkan tanda), lanjut saat terlihat lagi
  document.addEventListener('visibilitychange', () => {
    if(document.hidden && running){ hidden = true; stop('Dijeda karena tab tidak terlihat.'); }
    else if(!document.hidden && hidden){ hidden = false; start(); }
  });
  window.addEventListener('pagehide', () => { if(running) stop(); });
  if(box.dataset.ok === '1') start(); else { btn.disabled = true; modeSel.disabled = true; }
})();
