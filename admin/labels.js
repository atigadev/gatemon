// Cetak label komputer (labels.php): pilih laptop, ukuran label, isi; pratinjau dan cetak (1 label = 1 halaman).
(function(){
  const $ = id => document.getElementById(id);
  const area = $('lb-print-area');
  if(!area) return;
  const DEVS = JSON.parse(area.dataset.devs || '[]');
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const KEY = 'gm_labels2';                                  // v2: pengaturan lama (arah diputar) tidak dipakai lagi
  // ?ids=1,2 (tombol cetak per laptop di menu Devices): hanya laptop itu yang dipilih; tanpa itu semua laptop
  const want = (new URLSearchParams(location.search).get('ids') || '').split(',').map(Number).filter(id => DEVS.some(d => d.id === id));
  const sel = new Set(want.length ? want : DEVS.map(d => d.id));

  // ---------- barcode Code 128 (set B), sebagai SVG ----------
  const C128 = ('212222 222122 222221 121223 121322 131222 122213 122312 132212 221213 221312 231212 112232 122132 122231 113222 123122 123221 223211 221132 '
    + '221231 213212 223112 312131 311222 321122 321221 312212 322112 322211 212123 212321 232121 111323 131123 131321 112313 132113 132311 211313 '
    + '231113 231311 112133 112331 132131 113123 113321 133121 313121 211331 231131 213113 213311 213131 311123 311321 331121 312113 312311 332111 '
    + '314111 221411 431111 111224 111422 121124 121421 141122 141221 112214 112412 122114 122411 142112 142211 241211 221114 413111 241112 134111 '
    + '111242 121142 121241 114212 124112 124211 411212 421112 421211 212141 214121 412121 111143 111341 131141 114113 114311 411113 411311 113141 '
    + '114131 311141 411131 211412 211214 211232 2331112').split(' ');
  function code128(text){
    const vals = [104];                                         // Start B
    for(const ch of String(text)){ const c = ch.charCodeAt(0); if(c < 32 || c > 126) return ''; vals.push(c - 32); }
    let sum = vals[0]; for(let i = 1; i < vals.length; i++) sum += vals[i] * i;
    vals.push(sum % 103, 106);                                  // checksum + stop
    let x = 10, rects = '';                                     // 10 modul zona sepi kiri & kanan
    for(const v of vals){
      const p = C128[v];
      for(let i = 0; i < p.length; i++){ const w = +p[i]; if(i % 2 === 0) rects += `<rect x="${x}" y="0" width="${w}" height="10"/>`; x += w; }
    }
    return `<svg class="lb-bar" viewBox="0 0 ${x + 10} 10" preserveAspectRatio="none" shape-rendering="crispEdges" aria-hidden="true">${rects}</svg>`;
  }
  window.gmCode128 = code128;                                   // dipakai uji otomatis

  // ---------- pilihan (disimpan di browser ini) ----------
  const opt = () => {
    const size = $('lb-size').value;
    const clamp = (v, a, b, d) => { v = parseFloat(v); return isFinite(v) ? Math.min(b, Math.max(a, v)) : d; };
    const m = /^(?:b:)?(\d+)x(\d+)$/.exec(size);                  // nilai pilihan: [b:]LEBARxTINGGI kertas seperti keluar dari printer (mm)
    const [pw, ph] = size === 'custom' || !m ? [clamp($('lb-w').value, 15, 200, 50), clamp($('lb-h').value, 12, 200, 30)] : [+m[1], +m[2]];
    const ro = $('lb-rot').value, lh = $('lb-lh').value.trim();
    // otomatis: rol Brother sempit (29 / 38 mm) diputar agar nama besar; rol 62 mm & printer lain: mendatar searah keluar kertas
    const rot = ro === 'rot' || (ro === 'auto' && size.startsWith('b:') && pw < 50 && ph > pw);
    let w, h, pg = ph;
    if(rot){ w = ph; h = pw; }                                    // label dibaca sepanjang kertas, halaman pw × ph
    else {                                                        // mendatar selebar kertas; halaman setinggi label (tanpa ruang kosong)
      w = pw;
      h = lh !== '' ? clamp(lh, 12, 200, 30) : (ph > pw * 0.6 ? Math.round(pw * 0.5) : ph);
      pg = h;
    }
    return {size, pw, ph: pg, w, h, rot, ro, lh, top: $('lb-top').value.trim(), key: $('lb-key').checked, cat: $('lb-cat').checked, gate: $('lb-gate').checked,
      bar: $('lb-bar').checked, border: $('lb-border').checked, copies: clamp($('lb-copies').value, 1, 10, 1) | 0, pad: clamp($('lb-pad').value, 0, 8, 1.5),
      fn: $('lb-fn').value.trim() === '' ? 0 : clamp($('lb-fn').value, 2, 60, 0), fm: $('lb-fm').value.trim() === '' ? 0 : clamp($('lb-fm').value, 1.5, 15, 0)};
  };
  function load(){
    let o = null; try { o = JSON.parse(localStorage.getItem(KEY) || 'null'); } catch(e){}
    if(!o) return;
    if(o.size && $('lb-size').querySelector(`option[value="${o.size}"]`)) $('lb-size').value = o.size;
    if(o.w) $('lb-w').value = o.w; if(o.h) $('lb-h').value = o.h;
    if(o.lh) $('lb-lh').value = o.lh;
    if(o.fn) $('lb-fn').value = o.fn; if(o.fm) $('lb-fm').value = o.fm;
    if(o.ro && $('lb-rot').querySelector(`option[value="${o.ro}"]`)) $('lb-rot').value = o.ro;
    $('lb-top').value = o.top || '';
    for(const k of ['key','cat','gate','bar','border']) if(typeof o[k] === 'boolean') $('lb-' + k).checked = o[k];
    if(o.copies) $('lb-copies').value = o.copies; if(o.pad != null) $('lb-pad').value = o.pad;
  }
  const save = o => { try { localStorage.setItem(KEY, JSON.stringify(o)); } catch(e){} };

  // ---------- daftar laptop ----------
  function renderList(){
    const cf = $('lb-catf').value, q = $('lb-q').value.trim().toLowerCase();
    $('lb-list').innerHTML = DEVS.map(d => {
      const hide = (cf !== '' && String(d.cid) !== cf) || (q && !(d.name + ' ' + d.key + ' ' + d.gate).toLowerCase().includes(q));
      return `<label class="lb-item"${hide ? ' hidden' : ''}><input type="checkbox" value="${d.id}"${sel.has(d.id) ? ' checked' : ''}>
        <b>${esc(d.name)}</b> <code class="muted">${esc(d.key)}</code><small class="muted">${esc(d.cat || 'Tanpa kategori')}${d.gate ? ' · ' + esc(d.gate) : ''}</small></label>`;
    }).join('');
  }
  const visibleIds = () => [...$('lb-list').querySelectorAll('.lb-item:not([hidden]) input')].map(i => +i.value);

  // ---------- label ----------
  function labelHtml(d, o){
    const meta = [o.key ? d.key : '', o.cat ? d.cat : '', o.gate ? d.gate : ''].filter(Boolean);
    const tiny = o.h < 20;                                      // label sangat pendek (mis. DK-11204 17 mm): tanpa teks atas & barcode
    return `<div class="lb-label${o.border ? ' lb-border' : ''}" style="width:${o.w}mm;height:${o.h}mm;padding:${o.pad}mm">`
      + (o.top && !tiny ? `<div class="lb-top">${esc(o.top)}</div>` : '')
      + `<div class="lb-name-box"><div class="lb-name">${esc(d.name)}</div></div>`
      + (meta.length ? `<div class="lb-meta${o.fm ? ' lb-fixed' : ''}" style="font-size:${o.fm || Math.min(4.2, Math.max(2.2, o.h * 0.075)).toFixed(2)}mm">${meta.map(esc).join(' · ')}</div>` : '')
      + (o.bar && !tiny ? `<div class="lb-bar-box" style="height:${Math.max(4, o.h * 0.22).toFixed(1)}mm">${code128(d.key)}</div>` : '')
      + '</div>';
  }
  // nama sebesar mungkin: satu baris, atau beberapa baris (pecah di spasi / tanda -) bila hasilnya lebih besar
  function fit(el){
    if(cur.fn){                                                 // ukuran huruf manual: pecah baris (spasi / -) hanya bila tidak muat selebar label
      const txt = el.textContent;
      el.innerHTML = esc(txt); el.style.whiteSpace = 'nowrap'; el.style.fontSize = cur.fn + 'mm';
      if(el.scrollWidth > el.parentElement.clientWidth + 0.5){ el.innerHTML = esc(txt).replace(/-/g, '-<wbr>'); el.style.whiteSpace = 'normal'; }
      el.classList.toggle('lb-over', el.scrollHeight > el.parentElement.clientHeight + 0.5 || el.scrollWidth > el.parentElement.clientWidth + 0.5);
      return;
    }
    const box = el.parentElement, W = box.clientWidth, H = box.clientHeight;
    if(!W || !H) return;
    const ok = () => el.scrollWidth <= W + 0.5 && el.scrollHeight <= H + 0.5;
    const txt = el.textContent;
    // <wbr> (boleh pecah setelah -) hanya saat mode beberapa baris: Chrome tetap memecah di <wbr> walau nowrap
    const set = wrap => { el.innerHTML = wrap ? esc(txt).replace(/-/g, '-<wbr>') : esc(txt); el.style.whiteSpace = wrap ? 'normal' : 'nowrap'; };
    const best = wrap => {
      set(wrap);
      let lo = 4, hi = 200;
      while(hi - lo > 0.5){ const m = (lo + hi) / 2; el.style.fontSize = m + 'px'; if(ok()) lo = m; else hi = m; }
      return lo;
    };
    const one = best(false), two = /[\s-]/.test(el.textContent.trim()) ? best(true) : 0;
    // utamakan satu baris; pecah di spasi bila jauh lebih besar, di tanda - hanya bila satu baris terlalu kecil (GATE-IN-01 tetap satu baris)
    const wrap = two > one * (/\s/.test(el.textContent.trim()) ? 1.3 : 1.8);
    set(wrap);
    el.style.fontSize = (wrap ? two : one) + 'px';
  }

  // teks kecil (atas / info) diperkecil sampai muat selebar label, tidak terpotong "…"
  function shrink(el){
    let fs = parseFloat(getComputedStyle(el).fontSize);
    while(el.scrollWidth > el.clientWidth + 0.5 && fs > 6){ fs -= 0.5; el.style.fontSize = fs + 'px'; }
  }
  // satu halaman = satu kertas seperti keluar dari printer (pw × ph); kotak label di dalamnya, mendatar atau diputar 90°
  const pageHtml = (d, o) => `<div class="lb-page" style="width:${o.pw}mm;height:${o.ph}mm">`
    + (o.rot ? `<div class="lb-rotate" style="transform:translateX(${o.pw}mm) rotate(90deg)">${labelHtml(d, o)}</div>` : labelHtml(d, o)) + '</div>';

  let pageStyle = null;
  let cur = {};
  function render(){
    const o = cur = opt();
    save(o);
    $('lb-custom').hidden = o.size !== 'custom';
    $('lb-hint-brother').hidden = !o.size.startsWith('b:');
    $('lb-hint-other').hidden = o.size.startsWith('b:');
    const picked = DEVS.filter(d => sel.has(d.id));
    const n = picked.length * o.copies;
    $('lb-count').textContent = n;
    $('lb-print').disabled = !n;
    $('lb-lh-f').hidden = o.rot;
    $('lb-dim').textContent = `kertas ${o.pw} × ${o.ph} mm (seperti keluar dari printer)${o.rot ? ` · label ${o.w} × ${o.h} mm diputar 90°` : ''}`
      + ` · ${picked.length} laptop${o.copies > 1 ? ' × ' + o.copies + ' rangkap' : ''}`
      + (o.h < 20 && (o.top || o.bar) ? ' · label pendek: teks atas & barcode tidak dicetak' : '');
    $('lb-preview').innerHTML = picked.length ? picked.map(d => pageHtml(d, o)).join('') : '<p class="muted">Pilih laptop di atas.</p>';
    const all = []; for(const d of picked) for(let i = 0; i < o.copies; i++) all.push(pageHtml(d, o));
    area.innerHTML = all.join('');
    document.querySelectorAll('#lb-preview .lb-name, #lb-print-area .lb-name').forEach(fit);
    document.querySelectorAll('.lb-label .lb-meta:not(.lb-fixed), .lb-label .lb-top').forEach(shrink);
    const over = [...new Set([...document.querySelectorAll('#lb-preview .lb-name.lb-over')].map(e => e.textContent))];
    if(over.length) $('lb-dim').textContent += ` · huruf nama terlalu besar, terpotong: ${over.join(', ')}`;
    if(!pageStyle){ pageStyle = document.createElement('style'); document.head.appendChild(pageStyle); }
    pageStyle.textContent = `@page{size:${o.pw}mm ${o.ph}mm;margin:0}`;
  }

  // ---------- event ----------
  load(); renderList(); render();
  document.querySelector('.lb-opts').addEventListener('input', render);
  document.querySelector('.lb-opts').addEventListener('change', render);
  $('lb-catf').addEventListener('change', renderList);
  $('lb-q').addEventListener('input', renderList);
  $('lb-list').addEventListener('change', e => {
    const i = e.target.closest('input'); if(!i) return;
    i.checked ? sel.add(+i.value) : sel.delete(+i.value); render();
  });
  $('lb-all').addEventListener('click', () => { visibleIds().forEach(id => sel.add(id)); renderList(); render(); });
  $('lb-none').addEventListener('click', () => { visibleIds().forEach(id => sel.delete(id)); renderList(); render(); });
  $('lb-print').addEventListener('click', () => { render(); window.print(); });
  if(document.fonts && document.fonts.ready) document.fonts.ready.then(render);
})();
