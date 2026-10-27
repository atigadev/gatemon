// Interaksi UI bersama semua halaman: ganti tema gelap/terang dan menu hamburger di layar kecil.
document.addEventListener('click', e=>{
  if(e.target.closest('[data-theme-toggle]')){
    const t = document.documentElement.getAttribute('data-theme')==='light' ? 'dark' : 'light';
    document.documentElement.setAttribute('data-theme', t);
    try{ localStorage.setItem('gm_theme', t); }catch(err){}
    return;
  }
  const nt = e.target.closest('[data-nav-toggle]');
  const bar = document.querySelector('.topbar');
  if(nt){ const open = bar.classList.toggle('nav-open'); nt.setAttribute('aria-expanded', open); return; }
  if(bar && bar.classList.contains('nav-open') && !e.target.closest('.nav')) bar.classList.remove('nav-open');
});
