// GateMon PWA: daftar service worker (sw.js), tombol "Pasang aplikasi", push notification (halaman Notifikasi).
// Service worker & push hanya jalan lewat HTTPS (atau localhost). Langganan push disimpan server per akun (push_api.php).
(function(){
  const me = document.currentScript, CSRF = me.dataset.csrf || '';
  const EP_KEY = 'gm_push_ep', PREF_KEY = 'gm_push_pref';
  const ok = 'serviceWorker' in navigator && window.isSecureContext;
  const get = k => { try{ return localStorage.getItem(k); }catch(e){ return null; } };
  const put = (k, v) => { try{ v === null ? localStorage.removeItem(k) : localStorage.setItem(k, v); }catch(e){} };
  let reg = null, installEvt = null;

  async function api(act, data){
    const opt = data ? {method: 'POST', headers: {'X-CSRF': CSRF}, body: new URLSearchParams(Object.assign({act, csrf: CSRF}, data))} : {};
    const r = await fetch('push_api.php' + (data ? '' : '?act=' + act), Object.assign({credentials: 'same-origin', cache: 'no-store'}, opt));
    const j = await r.json().catch(() => ({ok: false, error: 'HTTP ' + r.status}));
    if(!j.ok) throw new Error(j.error || 'gagal');
    return j;
  }
  const b64key = s => { const p = '='.repeat((4 - s.length % 4) % 4), b = atob((s + p).replace(/-/g, '+').replace(/_/g, '/')); return Uint8Array.from(b, c => c.charCodeAt(0)); };
  async function sha(s){ const h = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(s)); return [...new Uint8Array(h)].map(b => b.toString(16).padStart(2, '0')).join(''); }
  const prefs = () => { try{ return Object.assign({emg: 1, chat: 1}, JSON.parse(get(PREF_KEY) || '{}')); }catch(e){ return {emg: 1, chat: 1}; } };

  if(ok){
    navigator.serviceWorker.register('sw.js').then(r => { reg = r; resync(); page && page.refresh(); }).catch(() => {});
    // push masuk saat aplikasi terbuka: cek Support sekarang (alarm / suara GateMon berbunyi lewat support_alert.js)
    navigator.serviceWorker.addEventListener('message', e => { if(e.data && e.data.type === 'gm-push' && window.GMSupport) GMSupport.refresh(); });
  }
  // langganan push diperbarui browser (endpoint berganti): kirim ulang ke server dengan pilihan yang sama
  async function resync(){
    if(!CSRF || !reg || !('PushManager' in window) || !('Notification' in window) || Notification.permission !== 'granted') return;
    const s = await reg.pushManager.getSubscription(); const was = get(EP_KEY);
    if(!s || !was || s.endpoint === was) return;
    try{ await api('subscribe', Object.assign({sub: JSON.stringify(s)}, prefs())); put(EP_KEY, s.endpoint); }catch(e){}
  }

  // ---------- tombol "Pasang aplikasi" (Chrome / Edge Android & PC) ----------
  const standalone = () => matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;
  function paintInstall(){ document.querySelectorAll('[data-pwa-install]').forEach(b => b.hidden = !installEvt || standalone()); }
  window.addEventListener('beforeinstallprompt', e => { e.preventDefault(); installEvt = e; paintInstall(); });
  window.addEventListener('appinstalled', () => { installEvt = null; paintInstall(); page && page.refresh(); });
  document.addEventListener('click', async e => {
    if(!e.target.closest('[data-pwa-install]') || !installEvt) return;
    installEvt.prompt(); await installEvt.userChoice.catch(() => null); installEvt = null; paintInstall();
  });

  // ---------- halaman Notifikasi ----------
  const box = document.getElementById('notif');
  const page = box && (function(){
    const $ = id => document.getElementById(id);
    let info = null, sub = null, busy = false;
    function msg(t, cls){ $('nt-msg').textContent = t; $('nt-msg').className = 'small nt-msg ' + (cls || ''); }
    function state(id, good, text){ const el = $(id); el.className = 'nt-state ' + (good === null ? 'warn' : good ? 'ok' : 'bad'); el.textContent = text; }
    async function refresh(){
      const iOS = /iPhone|iPad|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
      state('nt-app', standalone() ? true : null, standalone() ? 'Terpasang (dibuka sebagai aplikasi)' : (iOS ? 'Belum: Safari → Bagikan → Tambah ke Layar Utama' : 'Belum dipasang'));
      state('nt-https', window.isSecureContext, window.isSecureContext ? (location.protocol === 'https:' ? 'Aman (HTTPS)' : 'Aman (localhost)') : 'Belum HTTPS: push & aplikasi tidak bisa dipakai');
      paintInstall();
      try{ info = await api('info'); }catch(e){ msg('Gagal memuat: ' + e.message, 'bad'); return; }
      $('nt-chat').disabled = !info.can_chat;
      $('nt-chat-row').title = info.can_chat ? '' : 'Akun viewer tidak menerima chat (tidak bisa membalas)';
      const support = ok && reg && 'PushManager' in window && 'Notification' in window;
      sub = support ? await reg.pushManager.getSubscription() : null;
      const mine = sub ? await sha(sub.endpoint) : '';
      const row = info.subs.find(s => s.ep === mine);
      let st, good;
      if(!window.isSecureContext){ st = 'Butuh HTTPS'; good = false; }
      else if(!info.ready){ st = 'Server belum siap: ' + info.why; good = false; }
      else if(!support){ st = iOS && !standalone() ? 'Di iPhone/iPad: pasang ke Layar Utama dulu (iOS 16.4+), lalu buka dari ikon GateMon' : 'Browser ini tidak mendukung push'; good = false; }
      else if(Notification.permission === 'denied'){ st = 'Diblokir di pengaturan browser: izinkan notifikasi untuk situs ini'; good = false; }
      else if(sub && row){ st = 'Aktif di browser ini'; good = true; }
      else { st = 'Belum aktif di browser ini'; good = null; }
      state('nt-push', good, st);
      const on = !!(sub && row);
      $('nt-on').hidden = on; $('nt-off').hidden = !on; $('nt-test').hidden = !on;
      $('nt-on').disabled = !support || !info.ready || Notification.permission === 'denied';
      if(row){ $('nt-emg').checked = !!row.emg; $('nt-chat').checked = !!row.chat; }
      else { const p = prefs(); $('nt-emg').checked = !!p.emg; $('nt-chat').checked = info.can_chat && !!p.chat; }
      const list = $('nt-list'); list.textContent = '';
      info.subs.forEach(s => {
        const li = document.createElement('li');
        const t = document.createElement('div');
        const b = document.createElement('b'); b.textContent = uaName(s.ua) + (s.ep === mine ? ' (browser ini)' : '');
        const sm = document.createElement('small'); sm.className = 'muted';
        sm.textContent = [s.emg ? 'DARURAT' : '', s.chat ? 'chat' : ''].filter(Boolean).join(' + ') + ' · sejak ' + s.created_at.slice(0, 16)
          + (s.last_ok ? ' · terkirim terakhir ' + s.last_ok.slice(0, 16) : '') + (s.last_err ? ' · gagal: ' + s.last_err : '');
        t.append(b, sm);
        const x = document.createElement('button'); x.type = 'button'; x.className = 'btn btn-ghost'; x.textContent = 'Hapus'; x.dataset.ntDel = s.id;
        li.append(t, x); list.append(li);
      });
      $('nt-none').hidden = info.subs.length > 0;
    }
    function uaName(ua){
      const os = /Android/.test(ua) ? 'Android' : /iPhone|iPad/.test(ua) ? 'iPhone/iPad' : /Windows/.test(ua) ? 'Windows' : /Mac OS/.test(ua) ? 'Mac' : /Linux/.test(ua) ? 'Linux' : 'Perangkat';
      const br = /Edg\//.test(ua) ? 'Edge' : /SamsungBrowser/.test(ua) ? 'Samsung Internet' : /Firefox\//.test(ua) ? 'Firefox' : /Chrome\//.test(ua) ? 'Chrome' : /Safari\//.test(ua) ? 'Safari' : 'browser';
      return br + ' · ' + os;
    }
    const chosen = () => ({emg: $('nt-emg').checked ? 1 : 0, chat: $('nt-chat').checked ? 1 : 0});
    async function run(fn){
      if(busy) return; busy = true;
      try{ await fn(); }catch(e){ msg(e.message, 'bad'); }
      finally{ busy = false; refresh(); }
    }
    box.addEventListener('click', e => {
      if(e.target.closest('#nt-on')) run(async () => {
        msg('Meminta izin notifikasi…', 'muted');
        if(await Notification.requestPermission() !== 'granted') throw new Error('Izin notifikasi tidak diberikan.');
        const s = (await reg.pushManager.getSubscription()) || await reg.pushManager.subscribe({userVisibleOnly: true, applicationServerKey: b64key(info.key)});
        put(PREF_KEY, JSON.stringify(chosen()));
        await api('subscribe', Object.assign({sub: JSON.stringify(s)}, chosen()));
        put(EP_KEY, s.endpoint);
        msg('Push notification aktif. Tekan "Kirim notifikasi uji" untuk mencoba.', 'ok');
      });
      if(e.target.closest('#nt-off')) run(async () => {
        if(sub){ await api('unsubscribe', {endpoint: sub.endpoint}); await sub.unsubscribe().catch(() => {}); }
        put(EP_KEY, null);
        msg('Push notification dimatikan di browser ini.', 'muted');
      });
      if(e.target.closest('#nt-test')) run(async () => {
        msg('Mengirim notifikasi uji…', 'muted');
        await api('test', {endpoint: sub.endpoint});
        msg('Terkirim. Notifikasi uji akan muncul dalam beberapa detik (coba juga saat aplikasi ditutup).', 'ok');
      });
      const del = e.target.closest('[data-nt-del]');
      if(del) run(async () => { await api('unsubscribe', {id: del.dataset.ntDel}); msg('Browser dihapus dari daftar.', 'muted'); });
    });
    box.addEventListener('change', e => {
      if(e.target.id !== 'nt-emg' && e.target.id !== 'nt-chat') return;
      put(PREF_KEY, JSON.stringify(chosen()));
      if(sub && !$('nt-off').hidden) run(async () => { await api('prefs', Object.assign({endpoint: sub.endpoint}, chosen())); msg('Pilihan disimpan.', 'ok'); });
    });
    if(!ok) refresh();
    return {refresh};
  })();
})();
