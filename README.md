# GateMon - Monitoring Laptop Gate

Web app untuk memantau dan mengendalikan laptop validasi gate di satu LAN event.
Bukan sistem validasi: halaman validasi yang sudah ada (form-scanning-gate.php) tetap dipakai apa adanya.

## Struktur
- `config.php`, `db.php`, `schema.php`, `setup.php` - server (PHP 7.4+/8, MySQL)
- `api/` - endpoint untuk agent (heartbeat, result)
- `admin/` - dashboard web (login, status, perintah, devices, admins)
- `agent/` - agent Python untuk tiap laptop gate (Windows)

## Kebutuhan server
- Wajib: PHP 7.4+, ekstensi `pdo_mysql`, MySQL/MariaDB, Apache (`.htaccess` aktif).
- Opsional (ada cadangan bawaan): `zip` (tanpa ini paket agent dibuat tanpa kompresi), `mbstring`, `zlib`,
  `mod_rewrite` (tanpa ini pakai `install/index.php?id=ID`). `setup.php` menampilkan pemeriksaan sistem.

## Instalasi singkat
1. Salin folder `gatemon` ke `htdocs` (XAMPP) atau `www` (Laragon) di laptop server.
2. Salin `config.local.example.php` menjadi `config.local.php` dan isi user/pass MySQL, lalu buka
   `http://localhost/gatemon/setup.php` di laptop server: pemeriksaan sistem + buat akun supervisor pertama.
3. Login dengan akun itu, tambah laptop di menu Devices.
4. Di server sudah disiapkan `agent/installer/`: installer Python 64-bit dan folder `wheels\` berisi semua library
   (Python 3.9-3.15, 64-bit) + `SHA256SUMS.txt`. Instalasi agent berjalan **offline**; installer memverifikasi
   hash setiap file dan menolak file yang berubah/tidak terdaftar. Bila Python di laptop tidak cocok (32-bit atau
   versi lain), Python dari paket dipasang. Perbarui wheels dengan `agent/buat-wheels.ps1` (lihat BACA-SAYA di folder itu).
5. Di menu Devices klik **Kode instalasi** (ikon masuk) pada laptop: muncul link, username (= ID laptop) dan
   password sekali pakai (berlaku `enroll_valid_hours`, bawaan 24 jam, terkunci setelah 5x salah).
6. Di laptop gate buka `http://IP-SERVER/gatemon/install/ID-LAPTOP`, masukkan username & password: paket ZIP
   (agent, config.json, Python, wheels) terunduh. Klik kanan → **Extract All**, lalu klik dua kali `install_agent.bat`.
   Installer tidak mengunduh apa pun (installer .bat pengunduh lama diblokir antivirus sebagai "Virus detected"), memasang Python bila belum ada, library, menyalin ke `C:\GateMon-Agent`, dan menjalankan agent
   (tugas terjadwal saat login, tetap jalan saat baterai, restart otomatis). Link unduhan berlaku 30 menit.
   Kode langsung hangus dan token lama tidak berlaku lagi.
   Alternatif: **Installer umum (ZIP)** (login di jendela installer) atau **ZIP per laptop** (config.json berisi token, tanpa login).

Manual lengkap ada di dokumen "Manual GateMon".

## Update kode & database
- Struktur database ada di `schema.php` (idempoten: tabel/kolom yang sudah ada dilewati, data tidak diubah).
  Versinya disimpan di `settings.schema_version`.
- Setelah kode diperbarui (`git pull` / salin file), **tidak perlu membuka `setup.php`**: request pertama ke server
  menjalankan migrasi otomatis sekali (request lain yang bersamaan menunggu, tidak dobel), lalu mencatat di log
  "Struktur database diperbarui ke versi N". Bila gagal (mis. user MySQL tanpa hak `ALTER`), pesan ada di error log PHP;
  buka `setup.php` dari laptop server untuk melihat detailnya.
- Kategori bawaan hanya dibuat untuk database baru; akun `admin`/`viewer` awal hanya dibuat `setup.php` bila belum ada akun.
- Developer: setiap menambah tabel/kolom, tulis di `schema.php` dan **naikkan `SCHEMA_VERSION`**.

## Pindah / cadangkan server
1. Di server lama: **Pengaturan → Pindah / cadangkan server → Unduh paket server** (hanya supervisor, tercatat di log).
   ZIP berisi seluruh aplikasi (kode, `config.php`, agent, installer Python + wheels) dan `database.sql`
   (laptop, kategori, pengaturan, akun admin, token; riwayat log/perintah/scan opsional).
2. Di server tujuan (Windows + XAMPP, Apache & MySQL sudah Start): ekstrak ZIP, jalankan `INSTALL-SERVER.bat`.
   Folder `htdocs\gatemon` lama dipindah ke `gatemon-lama-TANGGAL`, database lama dicadangkan ke
   `migrasi\backup-*.sql` sebelum ditimpa (ditanya dulu), koneksi MySQL ditanyakan bila `config.php` tidak cocok,
   ditawarkan mengaktifkan ekstensi zip dan `KeepAliveTimeout 15` (file asli dicadangkan `.bak-*`).
   `database.sql` dihapus setelah impor. Impor manual: `php migrasi\import.php [C:\xampp] [--yes] [--no-tune] [--keep-sql]`.
3. Restart Apache, buka `setup.php` (pemeriksaan sistem), lalu hapus. Login dengan akun yang sama.
- Agent menyimpan alamat server lama: beri server baru **IP yang sama** (IP statis/DHCP reservation),
  atau pasang ulang agent lewat `/install/ID-LAPTOP`.
- Paket berisi hash password & token laptop: simpan di tempat aman, hapus setelah dipakai.
  Folder `migrasi/` ditutup dari web (`.htaccess`).

## Kategori lokasi
Menu **Kategori** (supervisor) untuk mengatur lokasi laptop: Gate, Tiket Box, Convention, Opening, Lain-lain
(bisa ditambah, diubah nama/warna/urutan). Pilih kategori tiap laptop di menu Devices.
Dashboard menampilkan tab per kategori (jumlah laptop + jumlah yang bermasalah), card diberi pita warna kategorinya,
dan tombol "Kirim ke semua" hanya berlaku untuk laptop di tab yang sedang dibuka.

## Pengaturan waktu
Menu **Pengaturan** (supervisor): interval heartbeat agent, batas dianggap offline (min. 3x heartbeat),
refresh dashboard/monitor, dan masa berlaku kode instalasi. Langsung berlaku: agent mengikuti interval baru pada
heartbeat berikutnya, dashboard yang terbuka ikut menyesuaikan. Nilai bawaan ada di `config.php`.
Panel "Perkiraan beban" menghitung request/menit untuk server (penting di hosting bersama).

## Update agent dari server (agent 1.9+)
Agent diperbarui langsung dari menu **Agent**, tanpa memasang ulang. Agent di bawah 1.9 belum punya fitur ini:
pasang ulang sekali lewat `/install/ID-LAPTOP`, setelah itu update cukup dari server.
- **Keamanan**: rilis (`agent/release.json` = daftar file + sha256) ditandatangani **Ed25519** dengan kunci rahasia admin
  yang disimpan di flashdisk, **bukan di server**. Agent memegang kunci publik (`UPDATE_PUBKEY` di `agent.py`) dan hanya
  memasang rilis bertanda tangan sah, versinya lebih baru, dan setiap file cocok sha256-nya. Server yang dibobol atau
  jaringan yang disadap tidak bisa memasang program ke laptop.
- **Membuat rilis** (di PC yang memegang kunci, butuh PHP dari XAMPP):
  1. Sekali: `admin-tools\rilis-agent.bat kunci-baru E:\gatemon-update.key` (kunci publik ditulis ke `agent.py`).
     Simpan cadangan kunci; bila hilang, semua laptop harus dipasang ulang manual dengan kunci baru.
  2. Tiap versi: naikkan `VERSION` di `agent/agent.py`, lalu `admin-tools\rilis-agent.bat tanda-tangan E:\gatemon-update.key`.
     Alat menolak kunci yang tidak cocok, kunci di dalam folder GateMon, dan agent.py yang berubah tanpa naik versi.
  3. Commit `agent/release.json` + `agent/release.sig`.
- **Mengirim**: menu Agent → pilih laptop → **Update yang dipilih** (coba 1 laptop dulu) atau **Update semua**.
  Laptop offline mengambil perintah saat online. Status per laptop: Terbaru / Perlu update / Sedang update / Gagal.
- **Di laptop**: agent mengunduh lewat `api/update.php` (token laptop), memeriksa tanda tangan & sha256, memasang library
  baru bila `requirements.txt` berubah (pip offline dari wheel bertanda tangan), mencadangkan versi lama ke `update\lama\`,
  lalu restart lewat tugas terjadwal. Heartbeat tetap jalan selama mengunduh.
- **Rollback otomatis**: bila versi baru tidak berhasil tersambung ke server dalam 150 detik, skrip penjaga
  mengembalikan versi lama, menjalankannya lagi, dan status laptop menjadi "Gagal: ... kembali ke versi lama".

## Chat support & tombol DARURAT (agent 1.11+)
Di pojok kanan bawah layar laptop ada dua tombol kecil yang selalu di atas jendela lain:
- **Chat Support**: membuka jendela chat dengan tim support. Balasan dari server membuat tombol berkedip
  oranye "Pesan baru (N)" dan berbunyi.
- **DARURAT**: sekali klik langsung terkirim (tanpa konfirmasi). Status di tombol: MENGIRIM… → TERKIRIM ✓ → DITANGANI ✓.
  Bila jaringan putus, agent terus mencoba mengirim ulang sampai diterima server.
- Tombol **tidak mengambil fokus keyboard**, jadi scanner barcode tetap mengetik ke halaman gate.
- Tombol **tidak bisa ditutup** dari laptop: tidak ada tombol X, Alt+F4 tidak mengenainya, dan tidak muncul di taskbar/Alt+Tab.
  Hanya jendela chat yang bisa ditutup (X / Esc), lalu dibuka lagi lewat tombol Chat Support atau **Ctrl+Alt+S** (agent 1.12+).
  Bila tampilan tombol berhenti karena error, agent memunculkannya lagi otomatis dalam ±1 menit.

Di menu **Support** (agent 1.12+), tiap laptop punya pilihan **Ikut Pengaturan / Selalu tampil / Sembunyikan**, status tombol
yang dilaporkan laptop (tampil di layar / tersembunyi / gagal), serta tombol **Tampilkan di layar** dan **Buka chat di layar laptop**.
**Tampilkan tombol di semua laptop** memunculkan ulang tombol di semua laptop yang tombolnya aktif.

Di server, menu **Support** berisi percakapan per laptop (balas, tanda dibaca ✓✓, balasan cepat). Darurat yang belum
ditangani memunculkan banner merah, sirene, dan kalimat yang dibacakan di **semua halaman admin** yang terbuka
(Dashboard, Monitor, dan lainnya), sampai supervisor klik **Tangani**. Klik "Diamkan" hanya membisukan browser itu.
Agar alarm berbunyi di PC server, biarkan halaman Dashboard atau Monitor terbuka di sana dan klik halaman sekali
(browser baru mengizinkan suara setelah ada klik). Tombol di laptop bisa dimatikan di **Pengaturan → Chat support & darurat**.
Pesan disimpan 90 hari.

## Daya & Windows Update laptop (agent 1.10+)
Menu **Daya** mengatur dua hal untuk semua laptop dari server (global, "Jangan ubah" = GateMon tidak menyentuh).
Agent harus berjalan sebagai Administrator (installer bawaan sudah begitu).
- **Rencana daya**: Selalu aktif (tidak tidur, layar tetap menyala - untuk laptop gate), Seimbang, atau Hemat daya.
  Diterapkan ke rencana daya aktif (AC &amp; baterai) lewat `powercfg /change`.
- **Windows Update**: Aktif / Nonaktif. Nonaktif menghentikan & men-disable layanan (`wuauserv`, `UsoSvc`, `WaaSMedicSvc`)
  dan menyetel policy `NoAutoUpdate=1`, agar laptop tidak mengunduh/restart saat acara. Aktif mengembalikannya.
- Agent menerapkan hanya saat nilainya berubah (disimpan di `policy-state.json`) dan melaporkan status lewat heartbeat.
  Tabel status per laptop: Diterapkan / Sedang diterapkan / Menunggu online / Gagal / Perlu agent 1.10+.

## Wallpaper laptop (agent 1.9+)
- Menu **Wallpaper**: unggah gambar JPG/PNG (maks. 10 MB, min. 640x480) ke pustaka, lalu atur **wallpaper bawaan**
  untuk semua laptop dan bila perlu wallpaper lain **per kategori** ("Ikut bawaan" / "Jangan ubah" / gambar tertentu),
  posisi gambar (Fill/Fit/Stretch/Center/Tile/Span), dan **Kunci wallpaper** (bila petugas menggantinya, agent memasang kembali).
- Bisa juga diatur **per laptop** di form tambah/edit di menu Devices (berlapis: laptop → kategori → bawaan).
- Heartbeat hanya membawa sha256 wallpaper; agent mengunduh file sekali saat berubah lewat `api/wallpaper.php`
  (token laptop, hanya file milik laptop itu), mencocokkan sha256, lalu memasangnya. Gagal → dicoba lagi tiap 60 detik.
- Wallpaper asli laptop dicatat; bila diubah ke "Jangan ubah", agent mengembalikannya.
- Tabel status menunjukkan per laptop: Terpasang / Sedang dipasang / Menunggu online / Gagal / Perlu agent 1.9+.
- File disimpan di folder `wallpaper/` (ditutup dari web) dan ikut dalam paket pindah server.

## Beberapa halaman berdampingan (agent 1.8+)
- Di Devices, "URL halaman yang dibuka otomatis" diisi 1 URL per baris (maks 4). 1 URL = layar penuh (kiosk);
  lebih dari 1 = tiap halaman dibuka sebagai jendela aplikasi Chrome/Edge (tanpa address bar) dan disusun
  berdampingan kiri-kanan dengan lebar sama, tidak menutupi taskbar (disusun lewat port debug lokal 127.0.0.1).
- Bila satu jendela tertutup, hanya jendela itu yang dibuka ulang lalu disusun lagi; bila daftar URL diubah, browser
  gate dibuka ulang otomatis. Cek halaman gate memakai URL pertama; tangkap scan aktif bila salah satu URL halaman scan.

## Wake-on-LAN & Sleep (agent 1.7+)
- Agent melaporkan kartu jaringan fisik (kabel / Wi-Fi + MAC) dan kesiapan WoL Windows. Panel detail menampilkan status:
  "siap (kabel LAN)", "Wi-Fi saja: hanya bisa dibangunkan dari Sleep", atau apa yang belum siap.
- Installer menyiapkan Windows: Fast Startup dimatikan, Wake on Magic Packet kartu jaringan fisik diaktifkan
  (lewati dengan `-NoWoL`). **WoL di BIOS/UEFI harus diaktifkan manual** per laptop; laptop sebaiknya pakai kabel LAN & charger.
- Menu "Nyalakan (Wake-on-LAN)" muncul untuk laptop OFFLINE yang MAC-nya tercatat; tombol "Nyalakan yang offline" per tab.
  Magic packet dikirim lewat laptop online (agent 1.7+) di jaringan /24 yang sama (bekerja juga bila server di hosting),
  dan langsung dari server bila ekstensi PHP `sockets` aktif.
- Keamanan: hanya supervisor + CSRF; MAC tujuan diambil dari database (laporan agent), bukan dari isian; agent relay hanya
  mengirim magic packet ke broadcast jaringannya sendiri (port 9/7) dan memvalidasi ulang MAC; maks 1x per 30 detik per laptop; tercatat di log.
- Perintah "Sleep laptop" untuk laptop Wi-Fi (banyak yang hanya bisa dibangunkan dari Sleep, bukan dari mati total).

## Hasil scan halaman gate (agent 1.6+)
- Agent membaca balasan `proses_scan_gate.php` di browser gate (Chrome DevTools Protocol, port `cdp_port` bawaan 9333,
  hanya 127.0.0.1) tanpa mengubah halaman sevenems. Status: Berhasil / Belum waktunya / Tidak ditemukan / Gagal kirim.
- Hanya **laptop scan** yang ditangkap: mode gate + URL halaman gate mengandung pola `scan_page_match`
  (menu Pengaturan, bawaan `form-scanning-gate`, boleh beberapa dipisah koma). Laptop dengan link lain / mode monitor
  tidak membuka port debug, datanya ditolak server, dan tidak menampilkan data scan. Laptop scan diberi label "Scan" di Devices.
- Disimpan di tabel `scans`: status, kategori (hanya bila berhasil), gate, ID QR/barcode, waktu. Nama & perusahaan TIDAK disimpan.
- Scan ditampung di agent saat server tidak terjangkau dan dikirim bersama heartbeat; kiriman ulang tidak tercatat dobel.
- Dashboard: "Scan hari ini" per card + total, riwayat 15 scan di panel detail, peringatan suara bila scan
  "tidak ditemukan" >= ambang per menit. Pengaturan: tangkap scan on/off, lama simpan (bawaan 30 hari), ambang (bawaan 5).
- Setelah update ke 1.6, browser gate yang masih terbuka tanpa port debug ditutup sekali lalu dibuka ulang otomatis.

## Hemat trafik (agent 1.5+)
- Cek halaman gate (internet) tiap `gate_check_interval` detik (bawaan 30, menu Pengaturan) memakai HEAD (status saja)
  lewat koneksi keep-alive: ~0,2 KB per cek, bukan halaman penuh ~15 KB tiap heartbeat.
- Heartbeat & laporan perintah memakai koneksi keep-alive ke server. Agar efektif, `KeepAliveTimeout` Apache harus
  lebih besar dari interval heartbeat (XAMPP bawaan 5 detik = sama dengan heartbeat): ubah di
  `C:\xampp\apache\conf\extra\httpd-default.conf` menjadi `KeepAliveTimeout 15`, lalu restart Apache.
- Data JSON besar (dashboard) dikirim gzip (~86% lebih kecil).
- Screenshot JPEG (~70% lebih kecil dari PNG) dan dihapus otomatis setelah `screenshot_keep_days` hari (bawaan 7).

## Halaman gate otomatis (agent 1.3+)
- Laptop mode gate langsung membuka halaman gate (kiosk) begitu agent terhubung ke server setelah login Windows.
- Bila jendela gate tertutup, agent membukanya lagi setelah jeda (bawaan 15 detik). Keduanya bisa dimatikan di menu
  Pengaturan (mis. saat perawatan laptop).
- Browser gate memakai profil sendiri `C:\GateMon-Agent\browser-profile`, jadi Chrome/Edge biasa milik petugas tidak
  dianggap "browser gate", dan "Restart browser" hanya menutup jendela gate.

## Server di hosting / IP lokal
Agent 1.2+ melaporkan **IP LAN**-nya sendiri, jadi IP, link Radmin dan live camera tetap benar walau server di hosting
(server hanya melihat IP publik router; disimpan terpisah sebagai "IP publik"). Agent lama tetap jalan memakai
alamat pengirim. Catatan: Radmin & kamera tetap hanya bisa dibuka dari laptop yang berada di LAN yang sama.

## Live camera (Agent DVR)
Pilih **Live camera** saat tambah/edit laptop di menu Devices; kamera tampil di panel detail laptop
(klik card di Dashboard/Monitor). Card dan tabel yang punya kamera diberi ikon kamera.
- **Agent DVR di laptop ini** (otomatis): link dibentuk dari IP laptop yang dilaporkan agent, mengikuti
  template `camera_auto_url` di `config.php` (bawaan `http://{ip}:8090/?viewIndex=0#Live`). Bila IP laptop berubah, link ikut berubah.
- **URL custom**: isi bebas, boleh memakai `{ip}`. Contoh:
- MJPEG live: `http://IP-DVR:8090/video.mjpg?oids=ID` (opsional `&size=640x480`)
- Snapshot (diperbarui tiap detik): `http://IP-DVR:8090/grab.jpg?oid=ID`
- URL lain ditampilkan dalam frame (bisa ditolak bila server kamera melarang embed; pakai tombol "Buka").
Stream hanya berjalan selama panel detail terbuka. Laptop yang membuka dashboard harus bisa menjangkau IP Agent DVR.
Bila dashboard dibuka lewat https, URL kamera juga harus https.

## Suara peringatan
Tombol **Suara** di Dashboard/Monitor membuka pengaturan (disimpan per browser):
- Mode mati / nada saja / nada + suara; pilihan nada (beep, chime, sirene, alarm), suara pembaca, kecepatan, volume.
- Kalimat per kejadian bisa diubah, isian `{nama}` `{id}` `{kategori}` `{pos}` `{baterai}`.
  Contoh bawaan: "Peringatan. {nama} terputus dari koneksi."
- Kejadian: laptop terputus, browser gate tertutup, halaman gate gagal, baterai lemah, kembali terhubung, server tidak terjangkau.
  Bila lebih dari 3 laptop bermasalah sekaligus, diringkas ("Peringatan. 8 laptop terputus dari koneksi.").
- Opsi ulangi pengingat tiap N menit, senyapkan 15 menit, dan hanya untuk tab kategori yang dibuka.
- Browser baru mengizinkan suara setelah halaman diklik sekali (muncul tombol "Klik di sini untuk mengaktifkan suara").
- Agar logat Indonesia benar di LAN tanpa internet, pasang suara Indonesia di laptop layar monitor:
  Windows Settings → Time & language → Speech → Add voices → **Indonesian** (sekali, perlu internet saat mengunduh).

## Keamanan akses web
- **Rahasia di luar git**: password database dan pengaturan server ditulis di `config.local.php` (contoh:
  `config.local.example.php`), bukan di `config.php`. File ini tidak ikut repo dan tidak bisa dibuka lewat web.
- **HTTPS wajib** di server publik (`force_https => true`): HTTP dialihkan ke HTTPS, POST lewat HTTP ditolak (token tidak
  terkirim tanpa enkripsi), HSTS aktif, cookie sesi hanya lewat HTTPS.
- **Login**: token CSRF di form login, 10 gagal per IP / 8 gagal per username dalam 15 menit = diblokir 15 menit (tercatat di log),
  pesan gagal selalu sama, ID sesi diganti saat login. Sesi berakhir setelah 240 menit tanpa aktivitas atau 24 jam
  (`session_idle_minutes`, `session_max_hours`), dan langsung gugur bila password/role diganti atau akun dihapus.
- **Password admin**: minimal 10 karakter, tidak memuat username / kata umum. Password lemah (mis. bawaan `viewer123`)
  dipaksa diganti saat login. Ganti password wajib memasukkan password lama; supervisor bisa mereset password akun lain
  (pemiliknya wajib menggantinya). Supervisor terakhir tidak bisa dihapus.
- **Aksi berbahaya** (unduh paket server berisi token, pindah server agent) wajib memasukkan ulang password.
- **Header keamanan** dari PHP (berlaku walau mod_headers mati): Content-Security-Policy dengan nonce (tanpa skrip/handler
  inline), X-Frame-Options, nosniff, Referrer-Policy, Permissions-Policy, no-store, noindex. Pesan error PHP tidak tampil ke
  pengunjung (dicatat di error log).
- **Endpoint agent**: token 128-bit; banjir request token salah ditahan per IP. Kode instalasi: 5 salah per laptop = terkunci,
  20 salah per IP dalam 15 menit = ditahan.
- `.htaccess` memblokir file konfigurasi/library, `.git/`, `agent/`, `admin-tools/`, `migrasi/`, `wallpaper/`, file `.md/.json/.py/.bat/.sql/.zip`.
- `setup.php`: hanya dari localhost, atau dengan `setup_key` (>= 20 karakter) di `config.local.php` lewat HTTPS. Tidak ada
  password bawaan: akun supervisor pertama dibuat di form setup.
- **Pemeriksaan keamanan** (Pengaturan → Pemeriksaan keamanan, supervisor): memeriksa PHP, HTTPS, cookie, konfigurasi,
  akun, file tertinggal, dan menguji dari luar bahwa file sensitif ditolak (403/404). Jalankan setiap selesai unggah/ubah.

## Server publik (cPanel)
1. Server lama: menu **Agent** → update semua laptop ke agent **1.13+** (bisa pindah server tanpa instal ulang).
2. Pengaturan → **Pindah / cadangkan server** → Tujuan *Hosting cPanel* → masukkan password → unduh paket.
3. Ikuti `BACA-SAYA-CPANEL.txt` di paket: SSL, PHP 8.x + ekstensi, buat database & user, impor `database.sql`
   lewat phpMyAdmin, unggah folder `gatemon`, buat `config.local.php` (database, `force_https => true`, `agent_server_url`).
4. Di hosting: login, buka **Pemeriksaan keamanan**: semua baris OK.
5. Server lama: Pengaturan → **Pindahkan agent ke server lain** → `https://domain/gatemon` + password. Tiap laptop menguji
   server baru dengan ID + tokennya dulu; gagal = tetap di server lama dengan alasan di tabel. Server baru tidak tersambung
   dalam 10 menit pertama = laptop kembali ke server lama.
6. Yang tidak bisa dari internet: Radmin & live camera (IP LAN), Wake-on-LAN langsung dari server (tetap bisa lewat laptop
   lain yang menyala di LAN yang sama). Di hosting, naikkan interval heartbeat ke 10–15 detik dan batas offline ke 60 detik.
## Tampilan
- Tema gelap (default) dan terang; tombol matahari/bulan di kanan atas, pilihan disimpan per browser.
- Responsive: di HP menu menjadi hamburger, tabel Devices menjadi daftar kartu, modal muncul dari bawah.
- Kerangka halaman bersama ada di `admin/layout.php` (head, topbar, ikon); warna di token `:root` pada `admin/style.css`.
- Tidak memakai font/CDN dari internet, jadi tetap rapi di LAN event tanpa internet.

## Monitor layar penuh
Menu **Monitor layar penuh** (`admin/monitor.php`) untuk layar ruang kontrol: hanya card laptop, ukuran card
otomatis menyesuaikan agar semua muat satu layar (pakai tab kategori bila laptop sangat banyak). Tombol
"Layar penuh" atau F11 untuk full screen. Card hanya berisi info utama; klik card untuk detail lengkap dan
menu aksi (Radmin, ping, pesan, screenshot, reboot, dst). Hal yang sama berlaku di Dashboard biasa.

## Mode laptop
Atur di menu Devices per laptop:
- **Gate**: agent memantau browser dan halaman gate; laptop jadi kuning bila browser tertutup atau halaman gagal.
- **Monitor koneksi saja**: untuk laptop tanpa halaman web. Hanya dipantau online/offline dan waktu respon
  ke server; perintah "Buka halaman gate"/"Restart browser" tidak dikirim ke laptop ini.

Upgrade dari versi lama: buka `setup.php` sekali lagi (menambah kolom `mode` dan `net_ms`), lalu hapus lagi.

## Remote desktop dengan Radmin
- Agent melaporkan apakah Radmin Server (rserver3.exe / port 4899) siap di tiap laptop.
- Tombol "Remote via Radmin" di dashboard membuka link `radmin://IP`. Sekali di tiap laptop admin: buka panel detail
  laptop > "Belum terbuka? Pasang link Radmin" > jalankan `Setup-Radmin-GateMon.bat` (tanpa hak Administrator,
  hanya untuk user Windows itu). Hapus: `reg delete HKCU\Software\Classes\radmin /f`.
- Peluncur (`admin-tools/radmin-launch.ps1`) memvalidasi alamat (IPv4/nama host + port) sebelum Radmin Viewer 3
  dijalankan, sehingga link radmin:// dari halaman lain tidak bisa menjalankan perintah apa pun.
- Syarat di laptop gate: Radmin Server terpasang & berjalan (dashboard: "Radmin Server siap"), port 4899 tidak diblokir firewall.
