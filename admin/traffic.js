// Menu Traffic: grafik garis download / upload (bit per detik) ke host URL gate, dengan garis bantu + tooltip saat disentuh.
(function(){
  const box = document.getElementById('trf-chart');
  if(!box) return;
  const P = JSON.parse(box.dataset.pts || '[]');               // [waktu ms, download bps, upload bps, byte masuk, byte keluar]
  const BK = +box.dataset.bk || 60;
  const NS = 'http://www.w3.org/2000/svg';
  const bps = v => { const u = ['bps','kbps','Mbps','Gbps']; let i = 0; while(v >= 1000 && i < 3){ v /= 1000; i++; }
    return v.toLocaleString('id-ID', {maximumFractionDigits: i && v < 10 ? 2 : (i && v < 100 ? 1 : 0)}) + ' ' + u[i]; };
  const bytes = v => { const u = ['B','KB','MB','GB','TB']; let i = 0; while(v >= 1024 && i < 4){ v /= 1024; i++; }
    return (i ? v.toLocaleString('id-ID', {maximumFractionDigits: v < 10 ? 2 : (v < 100 ? 1 : 0)}) : Math.round(v)) + ' ' + u[i]; };
  const two = n => String(n).padStart(2, '0');
  const tlabel = (t, long) => { const d = new Date(t); return (long ? two(d.getDate()) + '/' + two(d.getMonth() + 1) + ' ' : '') + two(d.getHours()) + ':' + two(d.getMinutes()); };
  const el = (tag, attrs, parent) => { const e = document.createElementNS(NS, tag); for(const k in attrs) e.setAttribute(k, attrs[k]); if(parent) parent.appendChild(e); return e; };

  // batas atas sumbu: 1 / 2 / 2,5 / 5 x 10^n
  function niceMax(v){
    if(v <= 0) return 1000;
    const p = Math.pow(10, Math.floor(Math.log10(v)));
    for(const m of [1, 2, 2.5, 5, 10]) if(m * p >= v) return m * p;
    return 10 * p;
  }

  const tip = document.createElement('div');
  tip.className = 'trf-tip'; tip.hidden = true;
  box.appendChild(tip);
  let svg = null;

  function draw(){
    if(svg) svg.remove();
    const W = Math.max(280, box.clientWidth), H = 260, L = 64, R = W < 520 ? 14 : 72, T = 12, B = 28;
    const iw = W - L - R, ih = H - T - B;
    const max = niceMax(Math.max(0, ...P.map(p => Math.max(p[1] || 0, p[2] || 0))));
    const n = P.length;
    const x = i => L + (n > 1 ? i * iw / (n - 1) : iw / 2);
    const y = v => T + ih - v / max * ih;
    svg = el('svg', {width: W, height: H, viewBox: `0 0 ${W} ${H}`, class: 'trf-svg'});
    box.insertBefore(svg, tip);

    // grid & sumbu y (4 garis tipis)
    for(let k = 0; k <= 4; k++){
      const v = max * k / 4, yy = Math.round(y(v)) + .5;
      el('line', {x1: L, x2: W - R, y1: yy, y2: yy, class: k ? 'trf-grid' : 'trf-axis'}, svg);
      el('text', {x: L - 8, y: yy + 4, 'text-anchor': 'end', class: 'trf-tick'}, svg).textContent = bps(v);
    }
    // label waktu: maks ±6
    const long = (P[n - 1]?.[0] - P[0]?.[0]) > 86400000;
    const step = Math.max(1, Math.ceil(n / 6));
    for(let i = 0; i < n; i += step) el('text', {x: x(i), y: H - 8, 'text-anchor': 'middle', class: 'trf-tick'}, svg).textContent = tlabel(P[i][0], long);

    // garis: putus di bagian tanpa data (agent offline / belum terpasang)
    const ends = [];
    for(const [col, cls, name] of [[1, 'in', 'Download'], [2, 'out', 'Upload']]){
      let d = '', pen = false, last = null;
      P.forEach((p, i) => {
        if(p[col] === null){ pen = false; return; }
        d += (pen ? 'L' : 'M') + x(i).toFixed(1) + ' ' + y(p[col]).toFixed(1);
        pen = true; last = i;
      });
      el('path', {d, class: 'trf-line ' + cls}, svg);
      if(last !== null) ends.push({y: y(P[last][col]), x: x(last), name, cls});
    }
    // label langsung di ujung garis (digeser bila bertumpuk)
    if(ends.length === 2 && Math.abs(ends[0].y - ends[1].y) < 14){
      const up = ends[0].y <= ends[1].y ? 0 : 1;
      ends[up].y -= 7; ends[1 - up].y += 7;
    }
    if(R > 40) for(const e of ends){                            // layar sempit: cukup legenda di atas
      el('text', {x: e.x + 8, y: Math.max(T + 10, Math.min(T + ih, e.y + 4)), class: 'trf-end'}, svg).textContent = e.name;
    }

    // garis bantu + titik + tooltip
    const cross = el('line', {y1: T, y2: T + ih, class: 'trf-cross', visibility: 'hidden'}, svg);
    const dots = ['in', 'out'].map(c => el('circle', {r: 4, class: 'trf-dot ' + c, visibility: 'hidden'}, svg));
    const hit = el('rect', {x: L, y: T, width: iw, height: ih, fill: 'transparent', class: 'trf-hit'}, svg);
    const hide = () => { tip.hidden = true; cross.setAttribute('visibility', 'hidden'); dots.forEach(d => d.setAttribute('visibility', 'hidden')); };
    const show = ev => {
      const r = svg.getBoundingClientRect();
      const cx = (ev.touches ? ev.touches[0].clientX : ev.clientX) - r.left;
      const i = Math.max(0, Math.min(n - 1, Math.round((cx - L) / (n > 1 ? iw / (n - 1) : 1))));
      const p = P[i];
      cross.setAttribute('x1', x(i)); cross.setAttribute('x2', x(i)); cross.setAttribute('visibility', 'visible');
      [1, 2].forEach((c, k) => {
        if(p[c] === null){ dots[k].setAttribute('visibility', 'hidden'); return; }
        dots[k].setAttribute('cx', x(i)); dots[k].setAttribute('cy', y(p[c])); dots[k].setAttribute('visibility', 'visible');
      });
      const end = new Date(p[0] + BK * 1000);
      tip.innerHTML = `<b>${tlabel(p[0], long)} – ${two(end.getHours())}:${two(end.getMinutes())}</b>`
        + (p[1] === null ? '<span class="muted">tidak ada data</span>'
          : `<span><i class="trf-key in"></i>Download ${bps(p[1])} <small>(${bytes(p[3])})</small></span>`
          + `<span><i class="trf-key out"></i>Upload ${bps(p[2])} <small>(${bytes(p[4])})</small></span>`);
      tip.hidden = false;
      const tw = tip.offsetWidth;
      tip.style.left = Math.max(0, Math.min(W - tw, x(i) + (x(i) + 14 + tw > W ? -tw - 14 : 14))) + 'px';
      tip.style.top = T + 'px';
    };
    hit.addEventListener('mousemove', show);
    hit.addEventListener('touchstart', show, {passive: true});
    hit.addEventListener('touchmove', show, {passive: true});
    hit.addEventListener('mouseleave', hide);
  }

  draw();
  let rt = 0;
  window.addEventListener('resize', () => { clearTimeout(rt); rt = setTimeout(draw, 150); });
})();
