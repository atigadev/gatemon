// Halaman Devices: modal tambah/edit laptop, pencarian + filter tabel, tampilkan/salin token.
(function(){
  const modal = document.getElementById('dev-modal');
  const $ = id => document.getElementById(id);

  // ---------- modal tambah / edit ----------
  function syncMode(){
    if(!modal) return;
    const m = modal.querySelector('input[name=mode]:checked');
    $('f-url-row').hidden = m && m.value === 'monitor'; // URL gate tidak dipakai di mode monitor
    const c = modal.querySelector('input[name=cam_mode]:checked'), cm = c ? c.value : 'none';
    $('f-cam-row').hidden = cm !== 'custom';
    $('f-cam-auto').hidden = cm !== 'auto';
  }
  function openForm(d){
    const edit = !!d;
    $('dev-title').textContent = edit ? 'Edit laptop ' + d.device_key : 'Tambah laptop';
    $('dev-submit').querySelector('span').textContent = edit ? 'Simpan' : 'Tambah';
    $('f-act').value = edit ? 'edit' : 'add';
    $('f-id').value = edit ? d.id : '';
    const key = $('f-key');
    key.readOnly = edit; key.required = !edit;
    if(edit){
      key.value = d.device_key; $('f-name').value = d.name; $('f-gate').value = d.gate; $('f-url').value = d.gate_url;
      const cu = d.camera_url || '', cm = cu === '' ? 'none' : (cu === 'auto' ? 'auto' : 'custom');
      modal.querySelectorAll('input[name=cam_mode]').forEach(r => r.checked = r.value === cm);
      $('f-cam').value = cm === 'custom' ? cu : '';
      modal.querySelector('select[name=category_id]').value = String(d.category_id || 0);
      modal.querySelectorAll('input[name=mode]').forEach(r => r.checked = r.value === d.mode);
      modal.querySelectorAll('.notice.error').forEach(n => n.remove());
    }
    syncMode();
    modal.hidden = false;
    setTimeout(() => (edit ? $('f-name') : key).focus(), 30);
  }
  function resetAdd(){
    $('dev-form').reset();
    modal.querySelectorAll('.notice.error').forEach(n => n.remove());
    openForm(null);
  }
  function close(){ if(modal) modal.hidden = true; }

  if(modal){
    // gagal simpan: modal dibuka lagi dengan isian sebelumnya
    if(modal.dataset.open === 'add'){ openForm(null); }
    modal.addEventListener('change', e => { if(e.target.name === 'mode' || e.target.name === 'cam_mode') syncMode(); });
    modal.addEventListener('click', e => { if(e.target === modal || e.target.closest('[data-close]')) close(); });
    $('f-key').addEventListener('input', e => { const s = e.target.selectionStart; e.target.value = e.target.value.toUpperCase(); e.target.setSelectionRange(s, s); });
  }
  document.addEventListener('keydown', e => { if(e.key === 'Escape'){ close(); document.getElementById('enroll-modal')?.remove(); } });

  // modal kode instalasi (muncul sekali setelah kode dibuat)
  const em = document.getElementById('enroll-modal');
  if(em) em.addEventListener('click', e => { if(e.target === em || e.target.closest('[data-close-enroll]')) em.remove(); });

  document.addEventListener('click', e => {
    const cp = e.target.closest('[data-copy-text]');
    if(cp){ navigator.clipboard?.writeText(cp.dataset.copyText).then(() => { const s = cp.querySelector('span'); s.textContent = 'Tersalin'; setTimeout(() => s.textContent = 'Salin semua', 1500); }); return; }
    if(e.target.closest('[data-dev-add]')){ resetAdd(); return; }
    const ed = e.target.closest('[data-dev-edit]');
    if(ed){ openForm(JSON.parse(ed.dataset.devEdit)); return; }
    // token: klik pertama tampilkan + salin, klik kedua sembunyikan
    const tk = e.target.closest('[data-token]');
    if(tk){
      if(tk.classList.toggle('shown')){
        tk.textContent = tk.dataset.token;
        navigator.clipboard?.writeText(tk.dataset.token).then(() => { tk.title = 'Token disalin ke clipboard'; });
      } else tk.textContent = '••••••••';
    }
  });

  // ---------- pencarian + filter kategori ----------
  const q = $('dev-q'), cat = $('dev-cat'), table = $('dev-table');
  function filter(){
    if(!table) return;
    const term = q.value.trim().toLowerCase(), c = cat.value;
    let n = 0;
    table.querySelectorAll('tbody tr').forEach(tr => {
      const ok = (!term || tr.dataset.q.includes(term)) && (!c || tr.dataset.cat === c);
      tr.hidden = !ok; if(ok) n++;
    });
    $('dev-count').textContent = n;
    $('dev-none').hidden = n > 0;
  }
  if(q){ q.addEventListener('input', filter); cat.addEventListener('change', filter); }
})();
