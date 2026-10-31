// Alarm DARURAT & pesan baru dari laptop (menu Support) di semua halaman admin.
// Darurat: banner merah + sirene berulang + kalimat dibacakan sampai ditangani (atau didiamkan di browser ini).
// Berbunyi walau suara peringatan lain dimatikan. Browser baru mengizinkan suara setelah halaman diklik sekali.
(function(){
  const me = document.currentScript, CSRF = me.dataset.csrf, SUP = me.dataset.role === 'supervisor';
  const KEY = 'gm_sup_quiet';               // id darurat yang didiamkan di browser ini
  const onSupport = !!document.getElementById('sup');
  let quiet = load(), emg = [], lastId = null, ctx = null, nextSay = 0, stopped = false;

  function load(){ try{ return JSON.parse(localStorage.getItem(KEY) || '[]'); }catch(e){ return []; } }
  function save(){ try{ localStorage.setItem(KEY, JSON.stringify(quiet.slice(-50))); }catch(e){} }
  const el = (tag, cls, txt) => { const e = document.createElement(tag); if(cls) e.className = cls; if(txt != null) e.textContent = txt; return e; };
  const hm = s => (s || '').slice(11, 16);
  const unlocked = () => !navigator.userActivation || navigator.userActivation.hasBeenActive;

  // ---------- suara ----------
  function audio(){
    if(!ctx){ try{ ctx = new (window.AudioContext || window.webkitAudioContext)(); }catch(e){ return null; } }
    if(ctx.state === 'suspended') ctx.resume();
    return ctx;
  }
  function note(a, t, f, dur, type, vol, f2){
    const o = a.createOscillator(), g = a.createGain();
    o.type = type; o.frequency.setValueAtTime(f, t); if(f2) o.frequency.linearRampToValueAtTime(f2, t + dur);
    g.gain.setValueAtTime(0, t); g.gain.linearRampToValueAtTime(vol, t + .02); g.gain.linearRampToValueAtTime(0, t + dur);
    o.connect(g); g.connect(a.destination); o.start(t); o.stop(t + dur + .02);
  }
  function siren(){ const a = audio(); if(!a) return; const t = a.currentTime + .02;
    for(let i = 0; i < 2; i++){ note(a, t + i * 1.1, 650, .55, 'sawtooth', .16, 1300); note(a, t + i * 1.1 + .55, 1300, .55, 'sawtooth', .16, 650); } }
  function chime(){ const a = audio(); if(!a) return; const t = a.currentTime + .02; note(a, t, 880, .25, 'sine', .22); note(a, t + .15, 1320, .4, 'sine', .22); }
  function say(text){
    if(!window.speechSynthesis) return;
    const u = new SpeechSynthesisUtterance(text);
    const v = speechSynthesis.getVoices().find(v => /^id([-_]|$)/i.test(v.lang)); if(v){ u.voice = v; u.lang = v.lang; } else u.lang = 'id-ID';
    speechSynthesis.speak(u);
  }

  // ---------- tampilan ----------
  function badge(n, hot){
    document.querySelectorAll('[data-sup-badge]').forEach(b => { b.textContent = n > 99 ? '99+' : n; b.hidden = !n; b.classList.toggle('hot', hot); });
  }
  function banner(){
    let box = document.getElementById('sup-alarm');
    if(!emg.length){ if(box) box.remove(); document.body.classList.remove('sup-alarm-on'); return; }
    if(!box){ box = el('div', 'sup-alarm'); box.id = 'sup-alarm'; box.setAttribute('role', 'alert'); document.body.append(box); }
    document.body.classList.add('sup-alarm-on');
    box.replaceChildren();
    emg.forEach(e => {
      const row = el('div', 'sup-alarm-row' + (quiet.includes(+e.id) ? ' quiet' : ''));
      row.append(el('b', '', '🚨 DARURAT'), el('span', 'sup-alarm-who', e.name + [e.category_name, e.gate].filter(Boolean).map(x => ' · ' + x).join('')), el('small', '', 'sejak ' + hm(e.created_at)));
      const go = el('a', 'btn', 'Buka chat'); go.href = 'support.php?dev=' + e.device_id; row.append(go);
      if(SUP){ const a = el('button', 'btn btn-light', 'Tangani'); a.type = 'button'; a.dataset.supAck = e.device_id; row.append(a); }
      if(!quiet.includes(+e.id)){ const q = el('button', 'btn btn-ghost', 'Diamkan'); q.type = 'button'; q.dataset.supQuiet = e.id; q.title = 'Matikan bunyi darurat ini di browser ini saja'; row.append(q); }
      box.append(row);
    });
    if(!unlocked() && emg.some(e => !quiet.includes(+e.id))) box.append(el('div', 'sup-alarm-hint', 'Klik di mana saja pada halaman ini agar alarm berbunyi.'));
  }

  // ---------- polling ----------
  async function poll(){
    if(stopped) return;
    try{
      const r = await fetch('support_api.php?act=alert', {credentials: 'same-origin', cache: 'no-store'});
      if(r.status === 401){ stopped = true; return; }
      const j = await r.json(); if(!j.ok) return;
      const before = emg.map(e => +e.id);
      emg = j.emergencies;
      quiet = quiet.filter(id => emg.some(e => +e.id === id) || !before.includes(id)); save();
      badge(j.unread + emg.length, emg.length > 0);
      const fresh = emg.filter(e => !before.includes(+e.id));
      if(fresh.length) nextSay = 0;   // darurat baru: langsung bunyi
      fresh.forEach(e => window.gmPopup && gmPopup({level: 'bad', title: '🚨 DARURAT: ' + e.name, text: [e.category_name, e.gate, 'minta bantuan sejak ' + hm(e.created_at)].filter(Boolean).join(' · '), href: 'support.php?dev=' + e.device_id}));
      banner();
      const last = j.last;
      if(last && lastId !== null && +last.id > lastId && !emg.some(e => +e.device_id === +last.device_id && +e.id === +last.id)){
        const here = onSupport && new URL(location.href).searchParams.get('dev') == last.device_id;
        if(!here){ chime(); if(window.gmPopup) gmPopup({level: 'info', title: '💬 Pesan dari ' + last.name, text: last.body.slice(0, 140), href: 'support.php?dev=' + last.device_id}); }
      }
      if(last) lastId = Math.max(lastId || 0, +last.id); else if(lastId === null) lastId = 0;
    }catch(e){}
  }
  // sirene tiap 4 detik, kalimat tiap 20 detik selama ada darurat yang tidak didiamkan
  setInterval(() => {
    const loud = emg.filter(e => !quiet.includes(+e.id));
    if(!loud.length) return;
    siren();
    if(Date.now() >= nextSay){
      nextSay = Date.now() + 20000;
      setTimeout(() => say(loud.length > 1 ? `Darurat. ${loud.length} laptop membutuhkan bantuan.` : `Darurat. ${speakName(loud[0].name)}${loud[0].gate ? ', ' + speakName(loud[0].gate) : ''}, membutuhkan bantuan.`), 2300);
    }
  }, 4000);

  document.addEventListener('click', async e => {
    audio();
    const q = e.target.closest('[data-sup-quiet]');
    if(q){ quiet.push(+q.dataset.supQuiet); save(); if(window.speechSynthesis) speechSynthesis.cancel(); banner(); return; }
    const a = e.target.closest('[data-sup-ack]');
    if(a){
      a.disabled = true;
      try{
        const r = await fetch('support_api.php', {method: 'POST', credentials: 'same-origin', headers: {'X-CSRF': CSRF},
          body: new URLSearchParams({act: 'ack', dev: a.dataset.supAck, csrf: CSRF})});
        const j = await r.json(); if(!j.ok) throw new Error(j.error);
        if(window.speechSynthesis) speechSynthesis.cancel();
      }catch(err){ alert('Gagal menangani: ' + err.message); }
      poll();
    }
    if(document.getElementById('sup-alarm')) banner();     // petunjuk "klik agar berbunyi" hilang setelah klik pertama
  });
  window.GMSupport = {refresh: poll};
  poll();
  setInterval(() => { if(!document.hidden || emg.length) poll(); }, 4000);
})();
