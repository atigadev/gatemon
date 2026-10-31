// GateMon dashboard: polling status tiap 3 detik, kirim perintah ke server.
// Dipakai index.php (dashboard lengkap) dan monitor.php (layar penuh, hanya card).
const CSRF = document.querySelector('meta[name=csrf]').content;
const ROLE = document.querySelector('meta[name=role]').content;
const SUP = ROLE === 'supervisor';
const OP = SUP || ROLE === 'operator';   // petugas: boleh kirim perintah kecuali reboot/shutdown (dicek juga di server)
// versi agent a >= b (mis. '1.14.0')
const verGe = (a, b) => { const x = String(a||'0').split('.').map(Number), y = b.split('.').map(Number); for(let i=0;i<3;i++){ if((x[i]||0)!==(y[i]||0)) return (x[i]||0)>(y[i]||0); } return true; };
const canLive = d => SUP && verGe(d.agent_version, '1.14.0');   // layar live: agent 1.14+
const UNINSTALL_MIN = '1.17.0';   // uninstall agent dari server (supervisor)
const WALL = document.body.classList.contains('wall');
const $ = id => document.getElementById(id);
let lastData = null;
let detailId = null; // id laptop yang sedang dibuka panel detailnya
// Tab aktif: 'all', 'none' (tanpa kategori) atau id kategori. Diingat per browser.
let activeTab = 'all';
try { activeTab = localStorage.getItem('gm_tab') || 'all'; } catch(e){}

const LABEL = {PING:'Ping', MESSAGE:'Kirim pesan', OPEN_GATE:'Buka halaman gate', RESTART_BROWSER:'Restart browser', SCREENSHOT:'Screenshot', LOCK:'Kunci layar', SLEEP:'Sleep laptop', REBOOT:'Reboot laptop', SHUTDOWN:'Shutdown laptop', WAKE:'Nyalakan (Wake-on-LAN)'};
const NEED_TEXT = {MESSAGE:'Pesan untuk petugas di laptop'};
const CONFIRM = {SLEEP:'Laptop akan masuk mode Sleep. Halaman gate tidak bisa dipakai sampai dibangunkan (Wake-on-LAN atau tombol power).', REBOOT:'Laptop akan RESTART dan halaman gate tertutup beberapa menit.', SHUTDOWN:'Laptop akan DIMATIKAN dan tidak bisa dinyalakan lagi dari dashboard. Petugas harus menyalakannya secara manual.', LOCK:'Layar laptop akan terkunci; petugas harus login Windows lagi.', RESTART_BROWSER:'Browser akan ditutup lalu halaman gate dibuka ulang.'};
const GATE_CMDS = ['OPEN_GATE','RESTART_BROWSER'];
const DANGER = ['REBOOT','SHUTDOWN'];   // ditampilkan merah dan dipisah di menu
// perintah yang tampil untuk laptop ini: WAKE hanya untuk laptop offline yang MAC-nya tercatat (agent 1.7+)
const canWake = d => d.state==='offline' && (d.nics||[]).length > 0;
function cmdsFor(d){
  const gate = d.mode !== 'monitor';
  const list = Object.keys(LABEL).filter(t => t!=='WAKE' && (gate || !GATE_CMDS.includes(t)));
  const ok = SUP ? list : list.filter(t => !DANGER.includes(t));
  return canWake(d) ? ['WAKE'].concat(ok) : ok;
}

if (OP && $('toolbar')){ $('toolbar').hidden = false; if(!SUP) document.querySelectorAll('#toolbar [data-all]').forEach(b=>{ if(DANGER.includes(b.dataset.all)) b.remove(); }); }

function fmtAge(s){ if(s==null) return 'belum pernah'; if(s<60) return s+' dtk lalu'; if(s<3600) return Math.floor(s/60)+' mnt lalu'; return Math.floor(s/3600)+' jam lalu'; }
function fmtUp(s){ if(s==null) return '-'; const h=Math.floor(s/3600), m=Math.floor(s%3600/60); return h+'j '+m+'m'; }
// Warna teks yang terbaca di atas warna kategori
function fgFor(hex){ const m=/^#?([0-9a-f]{6})$/i.exec(hex||''); if(!m) return '#fff'; const n=parseInt(m[1],16), r=n>>16, g=n>>8&255, b=n&255; return (r*299+g*587+b*114)/1000 > 150 ? '#10213a' : '#fff'; }
function tabOf(d){ return d.category_id==null ? 'none' : String(d.category_id); }
function esc(s){ return String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }

function problemsOf(d){
  const p=[];
  if(d.state==='offline') p.push('Tidak melapor '+fmtAge(d.age));
  else if(d.mode!=='monitor'){ if(!d.browser_running) p.push('Browser gate tertutup'); if(!d.gate_url_ok) p.push('Halaman gate tidak bisa diakses'); }
  return p;
}
const line = (label, val, bad) => `<div class="line"><span>${label}</span><b class="${bad?'bad':''}">${val}</b></div>`;
const netLine = d => line('Koneksi ke server', d.state==='offline'?'terputus':(d.net_ms!=null?d.net_ms+' ms':'terhubung'), d.state==='offline');

// ---------- hasil scan halaman gate (agent >= 1.6) ----------
const SCAN_LABEL = {success:'Berhasil', belum:'Belum waktunya', notfound:'Tidak ditemukan', error:'Gagal kirim'};
function scanChips(s){
  if(!s) return '<span class="muted">belum ada</span>';
  return `<span class="sc ok" title="Berhasil">✓ ${s.ok}</span><span class="sc be" title="Belum waktunya">⏳ ${s.belum}</span><span class="sc nf" title="Tidak ditemukan">✗ ${s.nf}</span>`
    + (s.err ? `<span class="sc er" title="Gagal kirim ke server halaman gate">⚠ ${s.err}</span>` : '');
}
const scanCache = {};   // riwayat scan per laptop (panel detail), agar tidak berkedip saat data refresh
function scanHistHtml(id){
  const rows = scanCache[id];
  if(!rows) return '<p class="muted small">Memuat riwayat scan…</p>';
  if(!rows.length) return '<p class="muted small">Belum ada scan tercatat.</p>';
  return `<table class="table small"><tr><th>Waktu</th><th>Status</th><th>Kategori</th><th>ID QR / barcode</th></tr>${rows.map(r=>`<tr>
    <td>${esc(r.scanned_at.slice(11,19))}</td><td><span class="sc-st ${esc(r.status)}">${esc(SCAN_LABEL[r.status]||r.status)}</span></td>
    <td>${esc(r.category||'–')}</td><td><code>${esc(r.code||'–')}</code></td></tr>`).join('')}</table>`;
}
async function loadScans(id){
  try{
    const j = await (await fetch(`scans.php?device=${id}&limit=15`, {cache:'no-store'})).json();
    if(!j.ok) return;
    scanCache[id] = j.scans;
    const box = document.getElementById('scan-hist'); if(box && detailId===id) box.innerHTML = scanHistHtml(id);
  }catch(e){}
}

// Card ringkas: hanya info utama. Klik card untuk detail + menu aksi.
function cardHtml(d){
  const gate = d.mode !== 'monitor';
  const batt = d.battery==null ? '-' : d.battery+'%'+(d.battery_plugged?' ⚡':'');
  const cat = d.category_color || '#9aa5b1';
  return `<div class="dev ${d.state}" data-dev="${d.id}" tabindex="0" title="Klik: detail · Klik kanan: menu aksi" style="--cat:${esc(cat)};--cat-fg:${fgFor(cat)}">
    <div class="band">${esc(d.category_name || 'Tanpa kategori')}</div>
    <div class="head"><span class="dot"></span><h3>${esc(d.name)}</h3>${d.camera_url?'<span class="cam-flag" title="Ada live camera">'+CAM_IC+'</span>':''}<span class="key">${esc(d.device_key)}${d.gate?' · '+esc(d.gate):''}</span></div>
    ${d.state==='offline'?`<div class="bad">Tidak melapor ${fmtAge(d.age)}</div>`:''}
    ${gate ? line('Browser gate', d.browser_running?'aktif':'tertutup', !d.browser_running)
           + line('Halaman gate', d.gate_url_ok?'OK '+(d.gate_url_ms??'')+' ms':'gagal', !d.gate_url_ok)
           + (d.scan_enabled ? line('Scan hari ini', scanChips(d.scan)) : '')
           : netLine(d)}
    ${line('IP / user', esc(d.ip||'-')+' / '+esc(d.os_user||'-'))}
    ${line('Radmin Server', d.radmin_ok?'siap':'tidak aktif', !d.radmin_ok)}
    ${line('Baterai / uptime', batt+' / '+fmtUp(d.uptime_sec), d.battery!=null&&d.battery<20&&!d.battery_plugged)}
  </div>`;
}

// Panel detail (muncul saat card diklik): semua info + tombol aksi
function detailHtml(d){
  const gate = d.mode !== 'monitor';
  const p = problemsOf(d);
  const radmin = d.ip ? `<a class="btn btn-primary" href="radmin://${esc(d.ip)}" title="Buka Radmin Viewer ke ${esc(d.ip)}">Remote via Radmin</a><button class="btn" data-copy="${esc(d.ip)}">Salin IP</button>`
    + `<a class="radmin-setup" href="radmin_setup.php" title="Unduh & jalankan sekali di laptop admin ini agar tombol Remote via Radmin membuka Radmin Viewer">Belum terbuka? Pasang link Radmin</a>` : '';
  const cmds = OP ? cmdsFor(d)
    .map(t=>`<button class="btn ${DANGER.includes(t)?'btn-danger':(t==='WAKE'?'btn-primary':'')}" data-cmd="${t}" data-id="${d.id}" data-name="${esc(d.name)}">${LABEL[t]}</button>`).join('') : '';
  return `<div class="detail">
    ${p.length?`<div class="bad">${p.map(esc).join(' · ')}</div>`:`<div class="good">Sehat · lapor ${fmtAge(d.age)}</div>`}
    ${line('Kategori / pos', esc(d.category_name||'Tanpa kategori')+(d.gate?' / '+esc(d.gate):''))}
    ${line('Mode', gate?'gate (browser + halaman gate)':'monitor koneksi')}
    ${gate && (d.gate_urls||[]).length ? line('Halaman otomatis', (d.gate_urls.length>1 ? d.gate_urls.length+' halaman berdampingan: ' : 'layar penuh: ') + d.gate_urls.map(u=>esc(u.replace(/^https?:\/\/[^/]+/,'').split('?')[0]||u)).join(' | ')) : ''}
    ${gate ? line('Browser gate', d.browser_running?'aktif':'tertutup', !d.browser_running)
           + line('Halaman gate', d.gate_url_ok?'OK '+(d.gate_url_ms??'')+' ms':'gagal', !d.gate_url_ok) : ''}
    ${netLine(d)}
    ${line('IP / user', esc(d.ip||'-')+' / '+esc(d.os_user||'-'))}
    ${line('Hostname', esc(d.hostname||'-'))}
    ${(d.nics||[]).length ? line('Kartu jaringan', d.nics.map(n=>`${n.type==='ethernet'?'Kabel':n.type==='wifi'?'Wi-Fi':'Lain'}${n.up?'':' (mati)'} <span class="muted">${esc(n.mac)}</span>`).join(' · ')) : ''}
    ${line('Wake-on-LAN', `<span class="wol-${esc(d.wol_info?.level||'unknown')}">${esc(d.wol_info?.text||'-')}</span>`)}
    ${d.public_ip && d.public_ip!==d.ip ? line('IP publik (terlihat server)', esc(d.public_ip)) : ''}
    ${line('CPU / RAM / Disk', `${d.cpu??'-'}% / ${d.ram??'-'}% / ${d.disk??'-'}%`)}
    ${line('Radmin Server', d.radmin_ok?'siap':'tidak aktif', !d.radmin_ok)}
    ${line('Baterai / uptime', (d.battery==null?'-':d.battery+'%'+(d.battery_plugged?' ⚡':''))+' / '+fmtUp(d.uptime_sec))}
    ${line('Terakhir lapor / agent', esc(d.last_seen||'belum pernah')+' / '+esc(d.agent_version||'-'))}
    ${radmin||cmds ? `<div class="actions">${canLive(d) ? `<a class="btn btn-primary" href="live.php?dev=${d.id}" target="_blank" rel="noopener" title="Lihat layar laptop secara langsung (tanpa Radmin)">Layar live</a>` : ''}${radmin}${cmds}</div>` : ''}
    ${uninstallBox(d)}
    ${recentCmds(d)}
    ${d.scan_enabled ? `<div class="scan-box"><h4>Scan hari ini <span class="scan-chips">${scanChips(d.scan)}</span>
      ${d.scan&&d.scan.last?`<small class="muted">terakhir ${esc(d.scan.last.slice(11,19))}</small>`:''}</h4>
      <div id="scan-hist">${scanHistHtml(d.id)}</div></div>` : ''}
  </div>`;
}

// Uninstall agent dari server: hanya supervisor, satu laptop, konfirmasi password (dicek di server)
function uninstallBox(d){
  if(!SUP) return '';
  if(d.uninstall_pending) return `<div class="uninst-box"><span><b>Uninstall agent menunggu dikirim</b> · dijalankan saat laptop online</span><button class="btn" data-uninst-cancel="${d.id}">Batalkan uninstall</button></div>`;
  if(!verGe(d.agent_version, UNINSTALL_MIN)) return `<p class="muted small">Uninstall dari server perlu agent ${UNINSTALL_MIN}+ (laptop ini ${esc(d.agent_version||'?')}): update lewat menu Agent, atau jalankan uninstall_agent.bat di laptop.</p>`;
  return `<div class="uninst-box"><span>Lepas GateMon Agent dari laptop ini (folder agent dihapus, perlu pasang ulang untuk memantau lagi).</span><button class="btn btn-danger" data-uninst="${d.id}" data-name="${esc(d.name)}">Uninstall agent</button></div>`;
}
async function uninstApi(params){
  const r = await fetch('uninstall_api.php', {method:'POST', headers:{'X-CSRF':CSRF}, body:new URLSearchParams(Object.assign({csrf:CSRF}, params))});
  const j = await r.json().catch(()=>({ok:false, error:'HTTP '+r.status}));
  if(!j.ok) throw new Error(j.error || 'gagal');
  return j;
}
function askUninstall(id, name){
  openModal(`Uninstall agent → ${name}`, `<p>Agent di laptop <b>${esc(name)}</b> akan <b>dihapus</b>: tugas otomatis dihapus, browser gate ditutup, wallpaper asli dan Windows Update dikembalikan, lalu seluruh folder agent dihapus.</p>
    <p class="bad">Setelah dijalankan laptop, tidak bisa dibatalkan. Laptop tidak terpantau lagi sampai agent dipasang ulang dengan kode instalasi.</p>
    <label class="check"><input type="checkbox" id="m-remove"> Sekalian hapus laptop ini dari daftar Devices</label>
    <p>Password akun Anda:</p><input type="password" id="m-pass" autocomplete="current-password" style="padding:6px;width:220px">`, ()=>{
    const pass = $('m-pass').value; if(!pass){ alert('Isi password akun Anda'); return false; }
    const remove = $('m-remove').checked ? '1' : '';
    mOk.disabled = true;
    uninstApi({act:'send', dev:id, password:pass, remove}).then(()=>{
      closeModal(); gmPopup({level:'warn', title:'Uninstall dikirim: '+name, text:'Dijalankan saat laptop mengambil perintah (beberapa detik bila online).'}); refresh();
    }).catch(err=>alert('Gagal: '+err.message)).finally(()=>{ mOk.disabled = false; });
    return false;
  });
  mOk.textContent = 'Uninstall'; mOk.classList.replace('btn-primary', 'btn-danger');
  setTimeout(()=>$('m-pass')?.focus(), 50);
}

// Perintah terakhir untuk laptop ini (agar hasil terlihat juga di layar monitor)
function recentCmds(d){
  const cs = (lastData?.commands||[]).filter(c=>String(c.device_id)===String(d.id)).slice(0,4);
  if(!cs.length) return '';
  return `<table class="table small"><tr><th>Waktu</th><th>Perintah</th><th>Status</th><th>Hasil</th></tr>${cs.map(c=>`<tr><td>${esc(c.created_at.slice(11,16))}</td>
    <td>${esc(LABEL[c.type]||c.type)}</td><td class="st-${c.status}">${c.status}</td>
    <td>${c.type==='SCREENSHOT'&&c.status==='done'?(+c.has_shot?`<a href="#" data-shot="${c.id}">lihat</a>`:'<span class="muted">dihapus</span>'):esc(c.result||'')}</td></tr>`).join('')}</table>`;
}

// ---------- live camera (Agent DVR) ----------
const CAM_IC = '<svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 7l-7 5 7 5V7z"/><rect x="1" y="5" width="15" height="14" rx="2"/></svg>';
let camTimer = null;
// mjpeg = stream bergerak di <img>; snap = gambar diam yang diperbarui; page = halaman web di <iframe>
function camType(u){
  if(/video\.mjpg|\.mjpe?g(\?|$)|mjpeg|faststream|action=stream/i.test(u)) return 'mjpeg';
  if(/grab\.jpg|snapshot|\.(jpe?g|png)(\?|$)/i.test(u)) return 'snap';
  return 'page';
}
function camHtml(d){
  if(!d.camera_url) return '';
  // camera_link = URL final dari server (mode otomatis: http://IP-laptop:8090/...)
  const u = d.camera_link;
  if(!u) return `<div class="cam down"><div class="cam-head">${CAM_IC}<b>Live camera</b><span class="cam-live">MENUNGGU</span></div>
    <div class="cam-view err"><div class="cam-msg">IP laptop belum diketahui. Kamera tampil setelah agent di laptop ini melapor.</div></div></div>`;
  const t = camType(u);
  const view = t==='page' ? `<iframe src="${esc(u)}" title="Live camera ${esc(d.name)}" allow="fullscreen" referrerpolicy="no-referrer"></iframe>`
                          : `<img alt="Live camera ${esc(d.name)}" data-src="${esc(u)}">`;
  return `<div class="cam" data-type="${t}">
    <div class="cam-head">${CAM_IC}<b>Live camera</b><span class="cam-live">${t==='snap'?'SNAPSHOT':'LIVE'}</span>
      <a href="${esc(u)}" target="_blank" rel="noopener" class="cam-open">Buka ↗</a></div>
    <div class="cam-view">${view}<div class="cam-msg">Menghubungkan ke kamera…</div></div>
  </div>`;
}
function startCam(){
  const box = mBody.querySelector('.cam'); if(!box) return;
  const view = box.querySelector('.cam-view'), msg = box.querySelector('.cam-msg');
  const fail = ()=>{ view.classList.add('err'); box.querySelector('.cam-live').textContent='OFFLINE'; box.classList.add('down'); msg.textContent = 'Kamera tidak bisa dimuat. Pastikan Agent DVR menyala dan URL benar.'; };
  const img = box.querySelector('img');
  if(img){
    img.onload = ()=>view.classList.add('ok');
    img.onerror = fail;
    const url = img.dataset.src;
    const bust = ()=> url + (url.includes('?')?'&':'?') + '_t=' + Date.now();
    img.src = box.dataset.type==='snap' ? bust() : url;
    if(box.dataset.type==='snap') camTimer = setInterval(()=>{ if(view.classList.contains('ok')) img.src = bust(); }, 1000);
  } else {
    const fr = box.querySelector('iframe'); fr.onload = ()=>view.classList.add('ok');
  }
}
// hentikan stream saat panel ditutup / diganti agar koneksi ke DVR tidak tertinggal
function stopCam(){
  clearInterval(camTimer); camTimer = null;
  mBody.querySelectorAll('.cam img').forEach(i=>{ i.onerror=null; i.removeAttribute('src'); });
  mBody.querySelectorAll('.cam iframe').forEach(f=>{ f.src='about:blank'; });
}

function showDetail(id){
  const d = lastData && lastData.devices.find(x=>String(x.id)===String(id)); if(!d) return;
  // kamera di luar #d-live: hanya data status yang diperbarui tiap 3 detik, stream tidak terputus
  openModal(`${d.name} (${d.device_key})`, camHtml(d) + `<div id="d-live">${detailHtml(d)}</div>`, null);
  modal.querySelector('.modal-box').classList.toggle('has-cam', !!d.camera_url);
  detailId = d.id;
  startCam();
  if(d.scan_enabled) loadScans(d.id);
}

async function refresh(){
  try{
    const r = await fetch('status.php',{cache:'no-store'});
    if(r.status===401){ location.href='login.php'; return; }
    const j = await r.json();
    lastData = j;
    $('clock').textContent = 'live · '+j.now; $('clock').classList.remove('down');
    renderDevices();
    // panel detail yang sedang terbuka ikut diperbarui
    if(detailId!==null && !modal.hidden && $('d-live')){ const d=j.devices.find(x=>x.id===detailId); if(d){ $('d-live').innerHTML=detailHtml(d); if(d.scan_enabled) loadScans(d.id); } }
    if($('log')) $('log').innerHTML = j.logs.map(l=>`<li class="${l.level}"><span class="t">${esc(l.created_at.slice(11))}</span>${esc(l.message)}</li>`).join('');
    if($('cmds')) $('cmds').innerHTML = '<tr><th>Waktu</th><th>Laptop</th><th>Perintah</th><th>Status</th><th>Hasil</th></tr>'+
      j.commands.map(c=>`<tr><td>${esc(c.created_at.slice(11,16))}</td><td>${esc(c.device_name)}</td><td>${esc(LABEL[c.type]||c.type)}</td><td class="st-${c.status}">${c.status}</td>
        <td>${c.type==='SCREENSHOT'&&c.status==='done'?(+c.has_shot?`<a href="#" data-shot="${c.id}">lihat</a>`:'<span class="muted">dihapus</span>'):esc(c.result||'')}</td></tr>`).join('');
    const problem = j.summary.warn + j.summary.offline;
    try{ if(typeof Alerts!=='undefined') Alerts.update(j.devices, d=>activeTab==='all' || tabOf(d)===activeTab, j.scan_fail_alert); }catch(err){ console.error(err); }
    document.title = (problem?`(${problem}) `:'')+(WALL?'Monitor':'Dashboard')+' - GateMon';
  }catch(e){ $('clock').textContent='server tidak terjangkau'; $('clock').classList.add('down'); if(typeof Alerts!=='undefined') Alerts.serverLost(); }
}

// Tab per kategori + kartu/summary sesuai tab aktif
function renderDevices(){
  const j = lastData; if(!j) return;
  const tabs = [{id:'all', name:'Semua', color:'#10213a'}].concat(j.categories.map(c=>({id:String(c.id), name:c.name, color:c.color})));
  if(j.devices.some(d=>d.category_id==null)) tabs.push({id:'none', name:'Tanpa kategori', color:'#9aa5b1'});
  if(!tabs.some(t=>t.id===activeTab)) activeTab='all';
  const inTab = (d,id)=> id==='all' || tabOf(d)===id;
  $('tabs').innerHTML = tabs.map(t=>{
    const ds = j.devices.filter(d=>inTab(d,t.id));
    const bad = ds.filter(d=>d.state!=='ok').length;
    return `<button class="tab ${t.id===activeTab?'active':''}" data-tab="${esc(t.id)}" style="--cat:${esc(t.color)}">
      ${t.id==='all'?'':'<span class="sw"></span>'}${esc(t.name)} <span class="n">${ds.length}</span>${bad?`<span class="alert" title="perlu perhatian / offline">${bad}</span>`:''}</button>`;
  }).join('');
  const list = j.devices.filter(d=>inTab(d,activeTab));
  const s = {total:list.length, ok:0, warn:0, offline:0};
  list.forEach(d=>s[d.state]++);
  for(const k of ['total','ok','warn','offline']) $('s-'+k).textContent=s[k];
  // total scan hari ini untuk tab aktif
  const sc = {ok:0, belum:0, nf:0, err:0};
  list.forEach(d=>{ if(d.scan) for(const k in sc) sc[k]+=d.scan[k]; });
  for(const k in sc) if($('sc-'+k)) $('sc-'+k).textContent=sc[k];
  const tab = tabs.find(t=>t.id===activeTab);
  if($('scope')) $('scope').textContent = activeTab==='all' ? 'semua laptop' : 'semua laptop di '+tab.name;
  $('grid').innerHTML = list.length ? list.map(cardHtml).join('')
    : `<p class="muted">${j.devices.length ? 'Belum ada laptop di kategori ini. Atur kategori di menu Devices.' : 'Belum ada laptop. Tambahkan di menu Devices.'}</p>`;
  if(WALL) fitGrid(list.length);
}

// Layar penuh: pilih jumlah kolom + skala huruf agar semua card muat tanpa scroll
const CARD_W = 300, CARD_H = 276, GAP = 12; // ukuran natural card (skala 1), termasuk baris "Tidak melapor"
function fitGrid(n){
  const g = $('grid');
  // layar HP: tidak dipaksa muat 1 layar (CSS membuat 1 kolom + scroll)
  if(!n || innerWidth<=700){ g.style.gridTemplateColumns = g.style.gridTemplateRows = ''; return; }
  const cs = getComputedStyle(g);
  const W = g.clientWidth - parseFloat(cs.paddingLeft) - parseFloat(cs.paddingRight);
  const H = g.clientHeight - parseFloat(cs.paddingTop) - parseFloat(cs.paddingBottom);
  let best = {s:0, c:1, r:n};
  for(let c=1; c<=n; c++){
    const r = Math.ceil(n/c);
    const s = Math.min((W-GAP*(c-1))/c/CARD_W, (H-GAP*(r-1))/r/CARD_H);
    if(s>best.s) best = {s, c, r};
  }
  g.style.gridTemplateColumns = `repeat(${best.c}, minmax(0,1fr))`;
  g.style.gridTemplateRows = `repeat(${best.r}, minmax(0,1fr))`;
  g.style.setProperty('--s', Math.max(0.5, Math.min(best.s, 1.8)).toFixed(3));
}
if(WALL) window.addEventListener('resize', ()=>{ if(lastData) renderDevices(); });


async function send(type, device_id, payload, category=null){
  const r = await fetch('command.php',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF':CSRF},body:JSON.stringify({type,device_id,payload,category})});
  const j = await r.json();
  if(!j.ok) alert('Gagal: '+j.error);
  else { if(j.skipped && j.skipped.length) alert('Terkirim ke '+j.count+' laptop. Dilewati:\n- '+j.skipped.join('\n- ')); refresh(); }
}

const modal=$('modal'), mTitle=$('m-title'), mBody=$('m-body'), mOk=$('m-ok');
function closeModal(){ stopCam(); modal.hidden=true; }
function openModal(title, bodyHtml, onOk){ stopCam(); detailId=null; modal.querySelector('.modal-box').classList.remove('has-cam'); mTitle.textContent=title; mBody.innerHTML=bodyHtml; mOk.hidden=!onOk; mOk.textContent='Kirim'; mOk.classList.remove('btn-danger'); mOk.classList.add('btn-primary'); mOk.disabled=false; mOk.onclick=()=>{ if(onOk()!==false) closeModal(); }; modal.hidden=false; }
$('m-cancel').onclick=closeModal;
modal.addEventListener('click', e=>{ if(e.target===modal) closeModal(); });
document.addEventListener('keydown', e=>{
  if(e.key==='Escape' && !modal.hidden) closeModal();
  const c=e.target.closest && e.target.closest('[data-dev]'); if(c && (e.key==='Enter'||e.key===' ')){ e.preventDefault(); showDetail(c.dataset.dev); }
});

function askAndSend(type, device_id, name){
  const target = device_id==='all' ? $('scope').textContent.toUpperCase() : name;
  // perintah "semua" hanya untuk laptop di tab aktif
  const cat = device_id==='all' && activeTab!=='all' ? (activeTab==='none' ? 0 : +activeTab) : null;
  if(NEED_TEXT[type]){
    openModal(`${LABEL[type]} → ${target}`, `<p>${NEED_TEXT[type]}:</p><textarea id="m-text" placeholder="Contoh: Mohon jangan tutup browser, tunggu instruksi IT."></textarea>`, ()=>{
      const t=$('m-text').value.trim(); if(!t){alert('Pesan kosong');return false;} send(type,device_id,t,cat); });
  } else if(CONFIRM[type]){
    openModal(`${LABEL[type]} → ${target}`, `<p>${CONFIRM[type]}</p><p>Ketik <b>YA</b> untuk melanjutkan:</p><input id="m-yes" style="padding:6px;width:120px">`, ()=>{
      if($('m-yes').value.trim().toUpperCase()!=='YA'){alert('Ketik YA untuk konfirmasi');return false;} send(type,device_id,null,cat); });
  } else { closeModal(); send(type,device_id,null,cat); }
}

document.addEventListener('click', e=>{
  const tb=e.target.closest('[data-tab]'); if(tb){ activeTab=tb.dataset.tab; try{ localStorage.setItem('gm_tab',activeTab); }catch(err){} renderDevices(); return; }
  const u=e.target.closest('[data-uninst]'); if(u){ askUninstall(u.dataset.uninst, u.dataset.name); return; }
  const uc=e.target.closest('[data-uninst-cancel]'); if(uc){ uc.disabled=true; uninstApi({act:'cancel', dev:uc.dataset.uninstCancel}).then(()=>{ gmPopup({level:'ok', title:'Uninstall dibatalkan', text:'Agent tetap terpasang.'}); refresh(); }).catch(err=>{ alert('Gagal: '+err.message); uc.disabled=false; }); return; }
  const b=e.target.closest('[data-cmd]'); if(b){ askAndSend(b.dataset.cmd, b.dataset.id, b.dataset.name); return; }
  const a=e.target.closest('[data-all]'); if(a){ askAndSend(a.dataset.all, 'all', null); return; }
  const c=e.target.closest('[data-copy]'); if(c){ navigator.clipboard?.writeText(c.dataset.copy); c.textContent='IP disalin'; setTimeout(()=>c.textContent='Salin IP',1500); return; }
  const s=e.target.closest('[data-shot]'); if(s){ e.preventDefault(); openModal('Screenshot', `<img src="screenshot.php?id=${s.dataset.shot}&t=${Date.now()}">`, null); return; }
  const fs=e.target.closest('[data-fullscreen]'); if(fs){ if(document.fullscreenElement) document.exitFullscreen(); else document.documentElement.requestFullscreen?.(); return; }
  const dv=e.target.closest('[data-dev]'); if(dv){ showDetail(dv.dataset.dev); }
});

// jeda refresh mengikuti menu Pengaturan (dikirim server lewat status.php), bawaan 3 detik
(function loop(){ refresh().finally(()=> setTimeout(loop, Math.max(1, +(lastData?.refresh) || 3) * 1000)); })();

// ---------- menu klik kanan pada card laptop (aksi sama dengan panel detail) ----------
const CTX_IC = {
  detail:'<circle cx="12" cy="12" r="9"/><path d="M12 16v-4M12 8h.01"/>',
  live:'<rect x="2" y="4" width="20" height="13" rx="2"/><path d="M8 21h8M12 17v4"/><circle cx="12" cy="10.5" r="2.5"/>',
  radmin:'<rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/>',
  copy:'<rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>',
  PING:'<path d="M3 12h4l3-8 4 16 3-8h4"/>',
  MESSAGE:'<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
  OPEN_GATE:'<path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6M15 3h6v6M10 14L21 3"/>',
  RESTART_BROWSER:'<path d="M21 12a9 9 0 1 1-3-6.7L21 8"/><path d="M21 3v5h-5"/>',
  SCREENSHOT:'<rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="12" cy="12" r="3"/>',
  LOCK:'<rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
  REBOOT:'<path d="M21 12a9 9 0 1 1-3-6.7L21 8"/><path d="M21 3v5h-5"/><path d="M12 8v4l2 2"/>',
  SLEEP:'<path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/>',
  WAKE:'<path d="M12 2v10"/><path d="M18.4 6.6a9 9 0 1 1-12.8 0"/><path d="M9 5l3-3 3 3"/>',
  SHUTDOWN:'<path d="M12 2v10M18.4 6.6a9 9 0 1 1-12.8 0"/>',
};
const ctxIc = k => `<svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">${CTX_IC[k]||''}</svg>`;
let ctxEl = null;
function closeCtx(){ if(ctxEl){ ctxEl.remove(); ctxEl = null; } }
function openCtx(d, x, y){
  closeCtx();
  const gate = d.mode !== 'monitor';
  const items = [{a:'detail', l:'Detail laptop'}];
  if(canLive(d)) items.push({a:'live', l:'Layar live'});
  if(d.ip) items.push({a:'radmin', l:'Remote via Radmin'}, {a:'copy', l:'Salin IP', hint:d.ip});
  if(OP){
    items.push('sep');
    cmdsFor(d).forEach(t=>{
      if(t==='REBOOT' || (t!=='WAKE' && items[items.length-1] && items[items.length-1].c==='WAKE')) items.push('sep');   // WAKE di atas, aksi berbahaya di bawah
      items.push({a:'cmd', c:t, l:LABEL[t], danger:DANGER.includes(t)});
    });
  }
  ctxEl = document.createElement('div');
  ctxEl.className = 'ctx'; ctxEl.setAttribute('role','menu');
  ctxEl.innerHTML = `<div class="ctx-head ${d.state}"><span class="dot"></span><b>${esc(d.name)}</b><code>${esc(d.device_key)}</code></div>` +
    items.map(it => it==='sep' ? '<div class="ctx-sep" role="separator"></div>' :
      `<button type="button" role="menuitem" class="ctx-item${it.danger?' danger':''}" data-ctx="${it.a}" data-c="${it.c||''}">${ctxIc(it.c||it.a)}<span>${esc(it.l)}</span>${it.hint?`<small>${esc(it.hint)}</small>`:''}</button>`).join('');
  ctxEl.dataset.id = d.id;
  document.body.appendChild(ctxEl);
  // jaga agar menu tetap di dalam layar
  const r = ctxEl.getBoundingClientRect();
  ctxEl.style.left = Math.max(8, Math.min(x, innerWidth - r.width - 8)) + 'px';
  ctxEl.style.top = Math.max(8, Math.min(y, innerHeight - r.height - 8)) + 'px';
  ctxEl.querySelector('.ctx-item').focus();
}
function runCtx(btn){
  const d = lastData && lastData.devices.find(x=>String(x.id)===ctxEl.dataset.id); closeCtx(); if(!d) return;
  const a = btn.dataset.ctx;
  if(a==='detail') showDetail(d.id);
  else if(a==='live') window.open('live.php?dev=' + d.id, '_blank', 'noopener');
  else if(a==='radmin') location.href = 'radmin://' + d.ip;
  else if(a==='copy') navigator.clipboard?.writeText(d.ip);
  else if(a==='cmd') askAndSend(btn.dataset.c, String(d.id), d.name);
}
document.addEventListener('contextmenu', e=>{
  const card = e.target.closest('[data-dev]');
  if(!card){ closeCtx(); return; }
  const d = lastData && lastData.devices.find(x=>String(x.id)===card.dataset.dev); if(!d) return;
  e.preventDefault();
  openCtx(d, e.clientX, e.clientY);
});
document.addEventListener('mousedown', e=>{ if(ctxEl && !ctxEl.contains(e.target)) closeCtx(); });
document.addEventListener('click', e=>{ const b = ctxEl && e.target.closest('.ctx-item'); if(b){ e.stopPropagation(); runCtx(b); } }, true);
window.addEventListener('resize', closeCtx); window.addEventListener('blur', closeCtx);
document.addEventListener('scroll', closeCtx, true);
document.addEventListener('keydown', e=>{
  if(ctxEl){
    const its = [...ctxEl.querySelectorAll('.ctx-item')], i = its.indexOf(document.activeElement);
    if(e.key==='Escape'){ e.preventDefault(); closeCtx(); }
    else if(e.key==='ArrowDown'){ e.preventDefault(); its[(i+1)%its.length].focus(); }
    else if(e.key==='ArrowUp'){ e.preventDefault(); its[(i-1+its.length)%its.length].focus(); }
    else if(e.key==='Tab'){ closeCtx(); }
    return;
  }
  // tombol Menu / Shift+F10 pada card yang sedang fokus
  const card = e.target.closest && e.target.closest('[data-dev]');
  if(card && (e.key==='ContextMenu' || (e.shiftKey && e.key==='F10'))){
    e.preventDefault();
    const d = lastData.devices.find(x=>String(x.id)===card.dataset.dev), r = card.getBoundingClientRect();
    if(d) openCtx(d, r.left + 24, r.top + 40);
  }
});
