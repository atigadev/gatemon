// Halaman Kategori: pemilih warna (kotak warna <-> kode hex <-> palet) saling sinkron.
(function(){
  const HEX = /^#[0-9a-f]{6}$/i;
  function set(field, c){
    c = c.toLowerCase();
    field.querySelector('.cswatch').style.setProperty('--c', c);
    field.querySelector('input[type=color]').value = c;
    const hex = field.querySelector('.chex'); hex.value = c; hex.classList.remove('bad');
    const pal = field.nextElementSibling;
    if(pal && pal.classList.contains('cpresets')) pal.querySelectorAll('.cdot').forEach(b => b.classList.toggle('on', b.dataset.c === c));
  }
  document.querySelectorAll('[data-cfield]').forEach(field => {
    const pick = field.querySelector('input[type=color]'), hex = field.querySelector('.chex');
    pick.addEventListener('input', () => set(field, pick.value));
    hex.addEventListener('input', () => {
      let v = hex.value.trim(); if(v && v[0] !== '#') v = '#' + v;
      if(HEX.test(v)) set(field, v); else hex.classList.add('bad');
    });
    hex.addEventListener('blur', () => { if(!HEX.test(hex.value)) set(field, pick.value); }); // kode salah: kembali ke warna terakhir
    const pal = field.nextElementSibling;
    if(pal && pal.classList.contains('cpresets')){
      pal.addEventListener('click', e => { const b = e.target.closest('.cdot'); if(b) set(field, b.dataset.c); });
      set(field, hex.value);
    }
  });
})();
