// GateMon - peringatan suara: nada + kalimat yang dibacakan (Web Speech API) saat ada masalah.
// Pengaturan disimpan per browser (localStorage), jadi layar monitor & laptop admin bisa berbeda.
const Alerts = (function(){
  const KEY = 'gm_sound';
  const EVENTS = {
    offline:   {label:'Laptop terputus dari koneksi', text:'Peringatan. {nama} terputus dari koneksi.',          many:'Peringatan. {n} laptop terputus dari koneksi.'},
    browser:   {label:'Browser gate tertutup',        text:'Peringatan. Browser gate di {nama} tertutup.',        many:'Peringatan. Browser gate tertutup di {n} laptop.'},
    page:      {label:'Halaman gate tidak bisa diakses', text:'Peringatan. Halaman gate di {nama} tidak bisa diakses.', many:'Peringatan. Halaman gate tidak bisa diakses di {n} laptop.'},
    battery:   {label:'Baterai lemah (< 20%, tidak dicas)', text:'Perhatian. Baterai {nama} tinggal {baterai} persen.', many:'Perhatian. Baterai lemah di {n} laptop.'},
    recovered: {label:'Laptop kembali terhubung',     text:'{nama} kembali terhubung.',                            many:'{n} laptop kembali terhubung.'},
    server:    {label:'Server monitoring tidak terjangkau', text:'Peringatan. Server monitoring tidak terjangkau.', many:''},
    scanfail:  {label:'Banyak scan tidak ditemukan (ambang di menu Pengaturan)', text:'Peringatan. {nama}, {n} scan tidak ditemukan dalam satu menit.', many:'Peringatan. Banyak scan tidak ditemukan di {n} laptop.'},
  };
  const TONES = {beep:'Beep', chime:'Chime (lembut)', siren:'Sirene', alarm:'Alarm (tegas)', none:'Tanpa nada'};
  const DEF = {mode:'voice', tone:'chime', voice:'', rate:1, volume:1, repeat:0, tabOnly:false, mutedUntil:0,
               on:{offline:true, browser:true, page:true, battery:true, recovered:true, server:true, scanfail:true}, text:{}};
  let S = load();
  let prev = null;           // status per laptop pada poll sebelumnya
  const lastSaid = {};       // "id:jenis" -> waktu terakhir diumumkan (untuk pengingat berulang)
  let serverDown = false;
  let ctx = null;

  function load(){
    let s = {};
    try{ s = JSON.parse(localStorage.getItem(KEY) || '{}'); }catch(e){}
    return Object.assign({}, DEF, s, {on:Object.assign({}, DEF.on, s.on||{}), text:Object.assign({}, s.text||{})});
  }
  function save(){ try{ localStorage.setItem(KEY, JSON.stringify(S)); }catch(e){} paintButton(); }
  const textOf = k => (S.text[k] || EVENTS[k].text);
  const muted = () => S.mode==='off' || Date.now() < S.mutedUntil;
  const fill = (t, d) => t.replace(/\{(\w+)\}/g, (m,k) => ({nama:d.name, id:d.device_key, kategori:d.category_name||'tanpa kategori', pos:d.gate||'', baterai:d.battery??'', n:d.n}[k] ?? m));

  // ---------- nada (Web Audio, tanpa file) ----------
  function audio(){
    if(!ctx){ try{ ctx = new (window.AudioContext||window.webkitAudioContext)(); }catch(e){ return null; } }
    if(ctx.state==='suspended') ctx.resume();
    return ctx;
  }
  function note(a, t, f, dur, type, vol, f2){
    const o=a.createOscillator(), g=a.createGain();
    o.type=type; o.frequency.setValueAtTime(f, t); if(f2) o.frequency.linearRampToValueAtTime(f2, t+dur);
    g.gain.setValueAtTime(0, t); g.gain.linearRampToValueAtTime(vol, t+0.02); g.gain.linearRampToValueAtTime(0, t+dur);
    o.connect(g); g.connect(a.destination); o.start(t); o.stop(t+dur+0.02);
  }
  // memutar nada, mengembalikan durasinya (ms) agar suara dibacakan setelahnya
  function tone(name, s=S){
    const a = audio(); if(!a || name==='none') return 0;
    const t=a.currentTime+0.02, v=0.25*s.volume;
    if(name==='beep'){ note(a,t,880,.25,'sine',v); return 320; }
    if(name==='chime'){ note(a,t,660,.35,'sine',v); note(a,t+.18,990,.5,'sine',v); return 750; }
    if(name==='siren'){ note(a,t,600,.55,'sawtooth',v*.5,1200); note(a,t+.55,1200,.55,'sawtooth',v*.5,600); return 1150; }
    if(name==='alarm'){ for(let i=0;i<3;i++) note(a,t+i*.22,1000,.14,'square',v*.4); return 720; }
    return 0;
  }

  // ---------- suara (Web Speech API) ----------
  function voices(){ return window.speechSynthesis ? speechSynthesis.getVoices() : []; }
  function pickVoice(s=S){
    const vs = voices();
    return vs.find(v=>v.voiceURI===s.voice) || vs.find(v=>/^id([-_]|$)/i.test(v.lang)) || null;
  }
  function say(text, s=S){
    if(!window.speechSynthesis || !text) return;
    const u = new SpeechSynthesisUtterance(text);
    const v = pickVoice(s); if(v){ u.voice=v; u.lang=v.lang; } else u.lang='id-ID';
    u.rate=s.rate; u.volume=s.volume;
    speechSynthesis.speak(u);
  }
  function announce(lines, force, s=S){
    if(!lines.length || (!force && muted())) return;
    const wait = tone(s.tone, s);
    if(s.mode!=="voice") return;
    setTimeout(()=>lines.forEach(l=>say(l, s)), wait);
  }

  // ---------- deteksi kejadian dari data status ----------
  function problems(d){
    const on = d.state!=='offline', gate = d.mode!=='monitor';
    return {
      offline: !on,
      browser: on && gate && !+d.browser_running,
      page:    on && gate && !+d.gate_url_ok,
      battery: on && d.battery!=null && +d.battery<20 && !+d.battery_plugged,
    };
  }
  function update(devices, inScope, scanFailLimit){
    if(serverDown){ serverDown=false; }
    const list = S.tabOnly && inScope ? devices.filter(inScope) : devices;
    const cur = {}, found = {offline:[], browser:[], page:[], battery:[], recovered:[], scanfail:[]};
    // scan "tidak ditemukan" melonjak dalam 1 menit (ambang di menu Pengaturan, 0 = mati); maks 1x per 2 menit per laptop
    if(scanFailLimit > 0 && prev !== null) list.forEach(d=>{   // mulai refresh kedua: refresh pertama hanya ringkasan
      const n = d.scan ? d.scan.nf_1m : 0, key = d.id+':scanfail';
      if(n >= scanFailLimit && (!lastSaid[key] || Date.now()-lastSaid[key] >= 120000)){ found.scanfail.push(Object.assign({}, d, {n})); lastSaid[key] = Date.now(); }
    });
    const now = Date.now(), rep = S.repeat*60000;
    list.forEach(d=>{
      const p = problems(d); cur[d.id] = p;
      const was = prev && prev[d.id];
      for(const k of ['offline','browser','page','battery']){
        const key = d.id+':'+k;
        if(!p[k]){ delete lastSaid[key]; continue; }
        const isNew = prev && (!was || !was[k]);
        const again = rep>0 && lastSaid[key] && now-lastSaid[key] >= rep;
        if(isNew || again){ found[k].push(d); lastSaid[key]=now; }
        else if(!lastSaid[key]) lastSaid[key]=now;
      }
      if(was && was.offline && !p.offline) found.recovered.push(d);
    });
    const first = prev===null;
    prev = cur;
    if(first){
      // saat halaman baru dibuka: ringkasan singkat bila ada masalah
      const n = list.filter(d=>{ const p=cur[d.id]; return p.offline||p.browser||p.page; }).length;
      if(n) announce([`Perhatian. Saat ini ${n} laptop bermasalah.`]);
      return;
    }
    const lines = [];
    for(const k of ['offline','browser','page','battery','recovered','scanfail']){
      const ds = found[k]; if(!ds.length || !S.on[k]) continue;
      if(ds.length>3) lines.push(fill(EVENTS[k].many, {n:ds.length}));
      else ds.forEach(d=>lines.push(fill(textOf(k), d)));
    }
    announce(lines);
  }
  function serverLost(){
    if(serverDown) return;
    serverDown = true;
    if(S.on.server) announce([textOf('server')]);
  }

  // ---------- tombol + panel pengaturan ----------
  const BELL = '<svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/></svg>';
  const BELL_OFF = '<svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M13.7 21a2 2 0 0 1-3.4 0M18.6 13A17 17 0 0 1 18 8M6.3 6.3A6 6 0 0 0 6 8c0 7-3 9-3 9h14M18 8a6 6 0 0 0-9.3-5M2 2l20 20"/></svg>';
  function paintButton(){
    document.querySelectorAll('[data-sound]').forEach(b=>{
      const m = muted();
      const txt = S.mode==='off' ? 'Suara mati' : (Date.now()<S.mutedUntil ? 'Senyap s/d '+new Date(S.mutedUntil).toTimeString().slice(0,5) : (S.mode==='voice'?'Suara aktif':'Nada aktif'));
      b.innerHTML = (m?BELL_OFF:BELL) + '<span>'+txt+'</span>';
      b.classList.toggle('off', m);
    });
  }
  function esc(s){ return String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
  function panelHtml(){
    const vs = voices();
    const idv = vs.some(v=>/^id([-_]|$)/i.test(v.lang));
    return `<form class="modal-box form-modal snd" id="snd-form">
      <div class="modal-head"><h3>Pengaturan suara peringatan</h3><button type="button" class="icon-btn sm" data-snd-close aria-label="Tutup">✕</button></div>
      <div class="form-grid">
        <div class="full field"><span class="field-lbl">Mode</span><div class="seg seg3">
          ${[['off','Mati'],['tone','Nada saja'],['voice','Nada + suara']].map(([v,l])=>`<label class="seg-opt"><input type="radio" name="mode" value="${v}" ${S.mode===v?'checked':''}><span>${l}</span></label>`).join('')}
        </div></div>
        <label>Nada peringatan <select name="tone">${Object.entries(TONES).map(([v,l])=>`<option value="${v}" ${S.tone===v?'selected':''}>${l}</option>`).join('')}</select></label>
        <label>Suara pembaca <select name="voice"><option value="">Otomatis (Bahasa Indonesia)</option>
          ${vs.map(v=>`<option value="${esc(v.voiceURI)}" ${S.voice===v.voiceURI?'selected':''}>${esc(v.name)} (${esc(v.lang)})</option>`).join('')}</select>
          ${idv?'':'<small class="hint warn-hint">Suara Bahasa Indonesia tidak ditemukan di browser ini; kalimat akan dibaca dengan logat bahasa lain. Pasang "Indonesian" di Windows Settings → Time &amp; language → Speech.</small>'}</label>
        <label>Kecepatan bicara <input type="range" name="rate" min="0.6" max="1.5" step="0.1" value="${S.rate}"></label>
        <label>Volume <input type="range" name="volume" min="0.1" max="1" step="0.1" value="${S.volume}"></label>
        <label>Ulangi pengingat selama masih bermasalah <select name="repeat">${[[0,'Tidak'],[1,'Tiap 1 menit'],[2,'Tiap 2 menit'],[5,'Tiap 5 menit'],[10,'Tiap 10 menit']].map(([v,l])=>`<option value="${v}" ${+S.repeat===v?'selected':''}>${l}</option>`).join('')}</select></label>
        <label class="check"><input type="checkbox" name="tabOnly" ${S.tabOnly?'checked':''}> Hanya laptop di tab kategori yang sedang dibuka</label>
        <div class="full field"><span class="field-lbl">Kejadian & kalimat yang dibacakan</span>
          <small class="hint">Isian: <code>{nama}</code> <code>{id}</code> <code>{kategori}</code> <code>{pos}</code> <code>{baterai}</code></small>
          <div class="ev-list">${Object.entries(EVENTS).map(([k,e])=>`<div class="ev">
            <label class="check"><input type="checkbox" name="on_${k}" ${S.on[k]?'checked':''}> ${e.label}</label>
            <div class="ev-row"><input name="text_${k}" value="${esc(textOf(k))}" placeholder="${esc(e.text)}"><button type="button" class="btn" data-snd-test="${k}" title="Dengarkan">▶</button></div>
          </div>`).join('')}</div>
        </div>
      </div>
      <div class="actions">
        <button type="button" class="btn" data-snd-mute>${Date.now()<S.mutedUntil?'Batalkan senyap':'Senyapkan 15 menit'}</button>
        <button type="button" class="btn" data-snd-reset title="Kembalikan kalimat bawaan">Reset kalimat</button>
        <span class="spacer"></span>
        <button type="button" class="btn" data-snd-close>Tutup</button><button class="btn btn-primary">Simpan</button>
      </div></form>`;
  }
  let modal = null;
  function openPanel(){
    if(!modal){
      modal = document.createElement('div'); modal.className='modal'; modal.id='snd-modal';
      document.body.appendChild(modal);
      modal.addEventListener('click', e=>{
        if(e.target===modal || e.target.closest('[data-snd-close]')){ modal.hidden=true; return; }
        const t = e.target.closest('[data-snd-test]');
        if(t){ // tes memakai isian panel saat ini (nada, suara, kecepatan, volume) walau belum disimpan
          const s = readForm(), k = t.dataset.sndTest, d = {name:'Operator Cetak', device_key:'G004', category_name:'Lain-lain', gate:'Pos 1', battery:15, n:6};
          s.mode='voice'; // tes selalu membacakan kalimat
          announce([fill(modal.querySelector(`[name=text_${k}]`).value || EVENTS[k].text, d)], true, s); return; }
        if(e.target.closest('[data-snd-mute]')){ S.mutedUntil = Date.now()<S.mutedUntil ? 0 : Date.now()+15*60000; save(); modal.innerHTML=panelHtml(); return; }
        if(e.target.closest('[data-snd-reset]')){ Object.keys(EVENTS).forEach(k=>{ modal.querySelector(`[name=text_${k}]`).value = EVENTS[k].text; }); }
      });
      modal.addEventListener('submit', e=>{ e.preventDefault(); S = readForm(); save(); hint(); modal.hidden=true; });
    }
    modal.innerHTML = panelHtml(); modal.hidden = false;
  }
  // isian panel -> objek pengaturan (belum disimpan)
  function readForm(){
    const f = modal.querySelector('form'), g = n => f.elements[n];
    const next = Object.assign({}, S, {mode:f.querySelector('[name=mode]:checked').value, tone:g('tone').value, voice:g('voice').value,
      rate:+g('rate').value, volume:+g('volume').value, repeat:+g('repeat').value, tabOnly:g('tabOnly').checked, on:{}, text:{}});
    for(const k in EVENTS){
      next.on[k] = g('on_'+k).checked;
      const v = g('text_'+k).value.trim(); if(v && v!==EVENTS[k].text) next.text[k]=v;
    }
    return next;
  }

  // browser hanya mengizinkan suara setelah ada interaksi (klik) di halaman
  function unlocked(){ return !navigator.userActivation || navigator.userActivation.hasBeenActive; }
  function hint(){
    let h = document.getElementById('snd-hint');
    const need = S.mode!=='off' && !unlocked();
    if(need && !h){ h=document.createElement('button'); h.id='snd-hint'; h.type='button'; h.className='snd-hint'; h.innerHTML=BELL+' Klik di sini untuk mengaktifkan suara peringatan'; document.body.appendChild(h); }
    if(!need && h) h.remove();
  }
  document.addEventListener('click', e=>{
    audio(); hint();
    if(e.target.closest('#snd-hint')){ tone(S.tone); }
    if(e.target.closest('[data-sound]')) openPanel();
  });
  if(window.speechSynthesis) speechSynthesis.onvoiceschanged = ()=>{ if(modal && !modal.hidden && !modal.querySelector(':focus')) modal.innerHTML = panelHtml(); };
  document.addEventListener('keydown', e=>{ if(e.key==='Escape' && modal) modal.hidden=true; });
  document.addEventListener('DOMContentLoaded', ()=>{ paintButton(); hint(); });
  setInterval(paintButton, 30000); // label "senyap s/d" kembali normal setelah waktunya habis

  return {update, serverLost};
})();
