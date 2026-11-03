// GateMon service worker (PWA): push notification DARURAT & chat dari laptop, juga saat aplikasi tertutup.
// Halaman TIDAK disimpan di cache (data dashboard & sesi login tidak boleh tertinggal di HP); saat jaringan putus
// hanya ditampilkan halaman "tidak terhubung". Isi push terenkripsi dari server (push.php), dibuka otomatis oleh browser.
const OFFLINE = '<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
  + '<title>GateMon - tidak terhubung</title><style>body{margin:0;min-height:100vh;display:grid;place-items:center;background:#070d1a;color:#e2e8f0;'
  + 'font:16px system-ui,sans-serif;text-align:center;padding:24px}a{color:#38bdf8}</style></head><body><div><h1>Tidak terhubung ke server GateMon</h1>'
  + '<p>Periksa WiFi / jaringan HP, lalu <a href="">muat ulang</a>. Notifikasi DARURAT tetap masuk bila HP terhubung internet.</p></div></body></html>';

self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', e => e.waitUntil(self.clients.claim()));

self.addEventListener('fetch', e => {
  if(e.request.mode !== 'navigate') return;
  e.respondWith(fetch(e.request).catch(() => new Response(OFFLINE, {headers: {'Content-Type': 'text/html; charset=utf-8'}})));
});

self.addEventListener('push', e => {
  let m = {};
  try{ m = e.data ? e.data.json() : {}; }catch(x){}
  const emg = m.kind === 'emergency';
  const opts = {
    body: m.body || '', tag: m.tag || 'gatemon', renotify: true, silent: false,
    requireInteraction: emg,                                   // DARURAT tetap di layar sampai disentuh
    icon: 'icons/icon-192.png', badge: 'icons/badge-96.png', timestamp: m.ts || Date.now(),
    vibrate: emg ? [600, 200, 600, 200, 600, 200, 1200] : [200, 100, 200],
    data: {url: m.url || 'index.php', kind: m.kind || ''},
  };
  if(emg) opts.actions = [{action: 'open', title: 'Buka Support'}];
  e.waitUntil(Promise.all([
    self.registration.showNotification(m.title || 'GateMon', opts),
    // aplikasi sedang terbuka: halaman langsung memeriksa Support dan membunyikan alarm GateMon
    self.clients.matchAll({type: 'window', includeUncontrolled: true})
      .then(cs => cs.forEach(c => c.postMessage({type: 'gm-push', kind: m.kind || '', id: m.id || 0}))),
  ]));
});

self.addEventListener('notificationclick', e => {
  e.notification.close();
  const url = new URL((e.notification.data && e.notification.data.url) || 'index.php', self.registration.scope).href;
  e.waitUntil(self.clients.matchAll({type: 'window', includeUncontrolled: true}).then(cs => {
    const c = cs.find(w => w.url.startsWith(self.registration.scope));
    if(!c) return self.clients.openWindow(url);
    return c.focus().then(w => (w && w.navigate ? w.navigate(url) : null)).catch(() => self.clients.openWindow(url));
  }));
});
