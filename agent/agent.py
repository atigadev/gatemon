"""
GateMon Agent - dipasang di setiap laptop gate (Windows).
Tiap beberapa detik melapor ke server dan menjalankan perintah dari dashboard.
Jalankan dengan pythonw.exe agar tidak ada jendela hitam.
"""
import base64, collections, ctypes, hashlib, io, ipaddress, json, logging, os, platform, re, shutil, socket, subprocess, sys, threading, time
import psutil, requests
from urllib.parse import parse_qs, urlparse

VERSION = "1.9.0"
# Kunci publik rilis update (Ed25519). Hanya rilis yang ditandatangani kunci rahasia pasangannya yang mau dipasang.
# Diisi oleh admin-tools/rilis-agent.bat kunci-baru; kosong = update otomatis mati.
UPDATE_PUBKEY = "8dc4a02e3d91eb05e2547cc10f7d0338dc04a38d089206af52aa39cdc4112524"
BASE = os.path.dirname(os.path.abspath(__file__))
CFG_PATH = os.path.join(BASE, "config.json")
LOG_PATH = os.path.join(BASE, "agent.log")

logging.basicConfig(filename=LOG_PATH, level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
log = logging.getLogger("agent")

with open(CFG_PATH, encoding="utf-8") as f:
    CFG = json.load(f)

SERVER = CFG["server_url"].rstrip("/")
HEADERS = {"X-Device-Key": CFG["device_key"], "X-Device-Token": CFG["token"], "Content-Type": "application/json"}
IS_WIN = platform.system() == "Windows"
# Koneksi keep-alive: satu koneksi dipakai ulang, tidak buka-tutup TCP/TLS tiap heartbeat (hemat trafik & CPU)
SRV = requests.Session()                      # ke server GateMon
SRV.headers.update(HEADERS)
WEB = requests.Session()                      # ke halaman gate (internet)
WEB.headers["User-Agent"] = "GateMon-Agent/" + VERSION
gate_check_every = 30                         # detik, diatur di menu Pengaturan (dikirim server lewat heartbeat)

BROWSERS = {
    "chrome": {"proc": "chrome.exe", "exe": [
        r"C:\Program Files\Google\Chrome\Application\chrome.exe",
        r"C:\Program Files (x86)\Google\Chrome\Application\chrome.exe",
        os.path.expandvars(r"%LOCALAPPDATA%\Google\Chrome\Application\chrome.exe")]},
    "msedge": {"proc": "msedge.exe", "exe": [
        r"C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe",
        r"C:\Program Files\Microsoft\Edge\Application\msedge.exe"]},
}
BROWSER = BROWSERS.get(CFG.get("browser", "chrome"), BROWSERS["chrome"])
gate_url = CFG.get("gate_url", "")  # bisa dikosongkan: server yang memberi tahu
gate_urls = []    # semua halaman dari server; >1 = dibuka berdampingan kiri-kanan
mode = "gate"     # "monitor" = hanya pantau koneksi, tanpa browser/halaman gate (diatur di menu Devices)
net_ms = None     # waktu respon heartbeat terakhir ke server


# Browser gate memakai profil tersendiri, sehingga bisa dibedakan dari Chrome/Edge biasa milik petugas
# (Chrome biasa yang terbuka tidak dianggap "browser gate aktif", dan tidak ikut ditutup saat restart).
PROFILE = os.path.join(BASE, "browser-profile")


def gate_browser_procs():
    name, prof = BROWSER["proc"].lower(), PROFILE.lower()
    found = []
    for p in psutil.process_iter(["name"]):
        try:
            if (p.info["name"] or "").lower() == name and any(prof in a.lower() for a in p.cmdline()):
                found.append(p)
        except (psutil.NoSuchProcess, psutil.AccessDenied):
            pass
    return found


# ---------- pengumpulan status ----------
def browser_running():
    return bool(gate_browser_procs())


RADMIN_PROC = CFG.get("radmin_process", "rserver3.exe").lower()
RADMIN_PORT = int(CFG.get("radmin_port", 4899))


def radmin_ok():
    """Radmin Server siap menerima koneksi: prosesnya jalan atau port-nya listening."""
    for p in psutil.process_iter(["name"]):
        try:
            if (p.info["name"] or "").lower() == RADMIN_PROC:
                return True
        except (psutil.NoSuchProcess, psutil.AccessDenied):
            pass
    try:
        for c in psutil.net_connections(kind="tcp"):
            if c.status == psutil.CONN_LISTEN and c.laddr and c.laddr.port == RADMIN_PORT:
                return True
    except Exception:
        pass
    return False


_gate = {"t": 0.0, "url": None, "ok": False, "ms": None}


def gate_check(url):
    """Cek halaman gate bisa diakses dari laptop ini. Mengembalikan (ok, ms).
    Hemat internet: hanya tiap gate_check_every detik (di antaranya dipakai hasil terakhir), memakai HEAD
    (status saja, tanpa isi halaman ~15 KB) lewat koneksi keep-alive."""
    if not url:
        return False, None
    now = time.time()
    if url == _gate["url"] and now - _gate["t"] < gate_check_every:
        return _gate["ok"], _gate["ms"]
    t = time.time()
    try:
        r = WEB.head(url, timeout=5, allow_redirects=True)
        if r.status_code in (405, 501):                  # server tidak mendukung HEAD: GET tanpa membaca isi
            r = WEB.get(url, timeout=5, stream=True)
            r.close()
        ok, ms = r.status_code < 500, int((time.time() - t) * 1000)
    except Exception:
        ok, ms = False, None
    _gate.update(t=now, url=url, ok=ok, ms=ms)
    return ok, ms


def lan_ip():
    """IP LAN laptop ini. Server di hosting hanya melihat IP publik router, jadi agent melaporkannya sendiri."""
    host = urlparse(SERVER).hostname or "8.8.8.8"
    try:
        # UDP connect tidak mengirim paket; hanya memilih kartu jaringan yang dipakai menuju server
        with socket.socket(socket.AF_INET, socket.SOCK_DGRAM) as s:
            s.connect((host, 80))
            ip = s.getsockname()[0]
            if ip and not ip.startswith(("127.", "0.")):
                return ip
    except OSError:
        pass
    # cadangan: IPv4 privat pertama dari kartu jaringan yang aktif
    stats = psutil.net_if_stats()
    for name, addrs in psutil.net_if_addrs().items():
        if name in stats and not stats[name].isup:
            continue
        for a in addrs:
            if a.family == socket.AF_INET and a.address.startswith(("10.", "192.168.", "172.")):
                return a.address
    return None


# ---------- Wake-on-LAN ----------
_SKIP_NIC = ("loopback", "bluetooth", "vpn", "virtual", "vmware", "virtualbox", "hyper-v", "vethernet", "teredo",
             "isatap", "pseudo", "tap-", "tunnel", "wan miniport", "npcap")


def nic_info():
    """Kartu jaringan fisik: nama, jenis (ethernet/wifi), MAC, aktif. MAC ini dipakai server sebagai tujuan WoL."""
    stats = psutil.net_if_stats()
    out = []
    for name, addrs in psutil.net_if_addrs().items():
        low = name.lower()
        if "*" in name or any(k in low for k in _SKIP_NIC):        # "Local Area Connection* N" = adaptor virtual Wi-Fi Direct
            continue
        mac = next((a.address for a in addrs if a.family == psutil.AF_LINK), "")
        if not MAC_RE.match(mac or ""):
            continue
        typ = "wifi" if any(k in low for k in ("wi-fi", "wifi", "wireless", "wlan")) else \
              ("ethernet" if any(k in low for k in ("ethernet", "lan")) else "other")
        out.append({"name": name[:60], "type": typ, "mac": mac.replace("-", ":").upper(),
                    "up": bool(name in stats and stats[name].isup)})
    return out[:8]


_wol = {"t": 0.0, "info": None, "sent": 0.0, "last": None}


def wol_check():
    """Kesiapan WoL di Windows (Fast Startup & Wake on Magic Packet per kartu), dicek tiap 30 menit."""
    if not IS_WIN or time.time() - _wol["t"] < 1800:
        return _wol["info"]
    _wol["t"] = time.time()
    ps = ("$ErrorActionPreference='SilentlyContinue';"
          "$h=(Get-ItemProperty 'HKLM:\\SYSTEM\\CurrentControlSet\\Control\\Session Manager\\Power').HiberbootEnabled;"
          "$m=@{}; Get-NetAdapterPowerManagement | ForEach-Object { $m[$_.Name]=[string]$_.WakeOnMagicPacket };"
          "@{fast_startup=($h -eq 1); magic=$m} | ConvertTo-Json -Compress")
    try:
        r = subprocess.run(["powershell", "-NoProfile", "-NonInteractive", "-Command", ps], capture_output=True, text=True,
                           timeout=30, creationflags=getattr(subprocess, "CREATE_NO_WINDOW", 0))
        _wol["info"] = json.loads(r.stdout.strip() or "null")
    except Exception as e:
        log.warning("cek kesiapan WoL gagal: %s", e)
    return _wol["info"]


MAC_RE = re.compile(r"^[0-9A-Fa-f]{2}([:-])[0-9A-Fa-f]{2}(\1[0-9A-Fa-f]{2}){4}$")


def wake(payload):
    """Relay WoL: kirim magic packet untuk MAC dari server ke broadcast jaringan laptop ini SAJA (port 9 & 7).
    Tidak ada alamat tujuan dari luar; MAC divalidasi ulang (unicast, bukan kosong)."""
    data = json.loads(payload or "{}")
    pkts = []
    for m in list(data.get("macs") or [])[:8]:
        h = re.sub("[^0-9A-Fa-f]", "", str(m))
        if len(h) != 12 or h in ("0" * 12, "F" * 12, "f" * 12) or int(h[1], 16) & 1:
            continue
        pkts.append(b"\xff" * 6 + bytes.fromhex(h) * 16)
    if not pkts:
        raise RuntimeError("tidak ada MAC valid")
    dst = {"255.255.255.255"}
    stats = psutil.net_if_stats()
    for name, addrs in psutil.net_if_addrs().items():
        if name in stats and not stats[name].isup:
            continue
        for a in addrs:
            if a.family == socket.AF_INET and a.netmask and ipaddress.ip_address(a.address).is_private \
                    and not a.address.startswith(("127.", "169.254.")):
                dst.add(str(ipaddress.IPv4Network(f"{a.address}/{a.netmask}", strict=False).broadcast_address))
    with socket.socket(socket.AF_INET, socket.SOCK_DGRAM) as s:
        s.setsockopt(socket.SOL_SOCKET, socket.SO_BROADCAST, 1)
        for _ in range(3):                                   # diulang 3x: UDP bisa hilang
            for p in pkts:
                for b in dst:
                    for port in (9, 7):
                        try:
                            s.sendto(p, (b, port))
                        except OSError:
                            pass
            time.sleep(0.2)
    return "magic packet untuk %s (%d MAC) dikirim ke %s" % (str(data.get("name", "?"))[:60], len(pkts), ", ".join(sorted(dst)))


def go_sleep():
    """Sleep (bukan hibernate) setelah 3 detik, agar hasil perintah sempat terkirim ke server."""
    def _s():
        time.sleep(3)
        ctypes.windll.powrprof.SetSuspendState(False, True, False)
    if IS_WIN:
        threading.Thread(target=_s, daemon=True).start()
    return "sleep dalam 3 detik"


def collect():
    batt = psutil.sensors_battery() if hasattr(psutil, "sensors_battery") else None
    gate = mode != "monitor"
    ok, ms = gate_check(gate_url) if gate else (False, None)
    extra = {}
    nics = nic_info()                                         # dikirim saat berubah atau tiap 10 menit
    if nics != _wol["last"] or time.time() - _wol["sent"] > 600:
        extra = {"nics": nics, "wol": wol_check()}
        _wol["last"], _wol["sent"] = nics, time.time()
    return {
        **extra,
        "hostname": socket.gethostname(),
        "lan_ip": lan_ip(),
        "os_user": os.environ.get("USERNAME") or os.environ.get("USER", ""),
        "cpu": psutil.cpu_percent(interval=None),
        "ram": psutil.virtual_memory().percent,
        "disk": psutil.disk_usage("C:\\" if IS_WIN else "/").percent,
        "battery": int(batt.percent) if batt else None,
        "battery_plugged": bool(batt.power_plugged) if batt else None,
        "uptime_sec": int(time.time() - psutil.boot_time()),
        "browser_running": browser_running() if gate else False,
        "gate_url_ok": ok,
        "gate_url_ms": ms,
        "net_ms": net_ms,
        "radmin_ok": radmin_ok(),
        "agent_version": VERSION,
        "wallpaper": _wp_state().get("sha", ""),
        "wallpaper_err": _wp["err"],
        **({"update_result": _upd["result"]} if _upd["result"] else {}),
    }


# ---------- wallpaper dari server (menu Wallpaper) ----------
# Heartbeat hanya membawa sha256 + gaya; file diunduh sekali saat berubah, dicek sha256-nya, lalu dipasang.
# Wallpaper asli laptop dicatat dulu agar bisa dikembalikan bila server tidak lagi mengatur wallpaper.
WP_DIR = os.path.join(BASE, "wallpaper")
WP_STATE = os.path.join(WP_DIR, "state.json")
WP_MAX = 25 * 1024 * 1024
WP_STYLES = {"fill": ["10", "0"], "fit": ["6", "0"], "stretch": ["2", "0"], "center": ["0", "0"], "tile": ["0", "1"], "span": ["22", "0"]}
_wp = {"busy": False, "err": "", "fail_at": 0, "state": None}


def _wp_state():
    if _wp["state"] is None:
        try:
            with open(WP_STATE, encoding="utf-8") as f:
                _wp["state"] = json.load(f)
        except Exception:
            _wp["state"] = {}
    return _wp["state"]


def _wp_save():
    os.makedirs(WP_DIR, exist_ok=True)
    with open(WP_STATE, "w", encoding="utf-8") as f:
        json.dump(_wp["state"], f)


def wp_current():
    buf = ctypes.create_unicode_buffer(1024)
    ctypes.windll.user32.SystemParametersInfoW(0x73, len(buf), buf, 0)    # SPI_GETDESKWALLPAPER
    return buf.value


def _wp_style_get():
    import winreg
    try:
        with winreg.OpenKey(winreg.HKEY_CURRENT_USER, r"Control Panel\Desktop") as k:
            return [str(winreg.QueryValueEx(k, n)[0]) for n in ("WallpaperStyle", "TileWallpaper")]
    except OSError:
        return None


def wp_set(path, style):
    import winreg
    if style:
        with winreg.OpenKey(winreg.HKEY_CURRENT_USER, r"Control Panel\Desktop", 0, winreg.KEY_SET_VALUE) as k:
            winreg.SetValueEx(k, "WallpaperStyle", 0, winreg.REG_SZ, style[0])
            winreg.SetValueEx(k, "TileWallpaper", 0, winreg.REG_SZ, style[1])
    # SPI_SETDESKWALLPAPER, simpan ke profil + beri tahu Explorer
    if not ctypes.windll.user32.SystemParametersInfoW(0x14, 0, path, 0x01 | 0x02):
        raise RuntimeError("Windows menolak memasang wallpaper (kebijakan/Group Policy?)")


def _wp_clean(keep=None):
    for n in os.listdir(WP_DIR) if os.path.isdir(WP_DIR) else []:
        p = os.path.join(WP_DIR, n)
        if n != "state.json" and p != keep:
            try:
                os.remove(p)
            except OSError:
                pass


def _wp_download(sha, path, size):
    if size > WP_MAX:
        raise RuntimeError("file wallpaper terlalu besar")
    r = requests.get(f"{SERVER}/api/wallpaper.php", headers=HEADERS, timeout=(10, 120), stream=True)
    if r.status_code != 200:
        raise RuntimeError(f"unduh wallpaper gagal (HTTP {r.status_code})")
    os.makedirs(WP_DIR, exist_ok=True)
    h, n, tmp = hashlib.sha256(), 0, path + ".part"
    with open(tmp, "wb") as f:
        for chunk in r.iter_content(65536):
            n += len(chunk)
            if n > WP_MAX:
                raise RuntimeError("file wallpaper terlalu besar")
            h.update(chunk)
            f.write(chunk)
    if h.hexdigest() != sha:                     # file rusak di jalan / bukan file yang dimaksud server
        os.remove(tmp)
        raise RuntimeError("file wallpaper tidak cocok (sha256)")
    os.replace(tmp, path)


def _wp_apply(sha, path, style, size):
    st = _wp_state()
    if not os.path.isfile(path):
        _wp_download(sha, path, size)
    if "orig" not in st:                          # catat wallpaper asli sekali, sebelum pertama kali diganti
        cur = wp_current()
        st["orig"] = "" if cur.lower().startswith(WP_DIR.lower()) else cur
        st["orig_style"] = _wp_style_get()
    wp_set(path, style)
    st["sha"], st["style"] = sha, style
    _wp_save()
    _wp_clean(keep=path)
    log.info("wallpaper dipasang: %s", sha[:12])


def _wp_restore():
    st = _wp_state()
    orig = st.get("orig") or ""
    wp_set(orig if os.path.isfile(orig) else "", st.get("orig_style"))
    _wp["state"] = {}
    _wp_save()
    _wp_clean()
    log.info("wallpaper asli dikembalikan: %s", orig or "(kosong)")


def _wp_run(fn, *a):
    def _go():
        try:
            fn(*a)
            _wp["err"] = ""
        except Exception as e:
            log.warning("wallpaper: %s", e)
            _wp["err"], _wp["fail_at"] = str(e)[:200], time.time()
        finally:
            _wp["busy"] = False
    _wp["busy"] = True
    threading.Thread(target=_go, daemon=True).start()    # unduhan tidak menahan heartbeat


def wallpaper_sync(want):
    if not IS_WIN or _wp["busy"] or (_wp["err"] and time.time() - _wp["fail_at"] < 60):
        return
    st = _wp_state()
    if not want:                                  # server tidak mengatur wallpaper laptop ini
        if st.get("sha"):
            _wp_run(_wp_restore)
        return
    sha = str(want.get("sha", ""))
    if not re.fullmatch(r"[0-9a-f]{64}", sha):
        return
    path = os.path.join(WP_DIR, sha + (".png" if want.get("ext") == "png" else ".jpg"))
    style = WP_STYLES.get(want.get("style"), WP_STYLES["fill"])
    if st.get("sha") == sha and st.get("style") == style:
        # sudah terpasang; bila dikunci dan petugas menggantinya, pasang kembali
        if not want.get("lock") or wp_current().lower() == path.lower():
            return
        log.info("wallpaper diganti di laptop, dipasang kembali (dikunci server)")
    _wp_run(_wp_apply, sha, path, style, int(want.get("bytes") or 0))


# ---------- update agent dari server (menu Agent) ----------
# Rilis = agent/release.json (daftar file + sha256) yang ditandatangani Ed25519 dengan kunci rahasia admin (bukan di
# server). Agent hanya memasang rilis bertanda tangan sah dan versinya lebih baru. Versi lama dicadangkan; skrip
# penjaga menjalankan ulang tugas terjadwal dan mengembalikan versi lama bila versi baru tidak tersambung ke server.
TASK_NAME = CFG.get("task_name", "GateMonAgent")
UPD_DIR = os.path.join(BASE, "update")
UPD_PENDING, UPD_OK, UPD_FAILED = (os.path.join(UPD_DIR, n) for n in ("pending.json", "ok.flag", "failed.json"))
UPD_WAIT = 150        # detik: versi baru harus berhasil heartbeat dalam waktu ini
_upd = {"busy": False, "restart": False, "result": "", "pending": None, "failed": False}

_EP = 2 ** 255 - 19
_EL = 2 ** 252 + 27742317777372353535851937790883648493
_ED = -121665 * pow(121666, _EP - 2, _EP) % _EP
_EI = pow(2, (_EP - 1) // 4, _EP)


def _ed_add(A, B):
    x1, y1, z1, t1 = A
    x2, y2, z2, t2 = B
    a, b = (y1 - x1) * (y2 - x2) % _EP, (y1 + x1) * (y2 + x2) % _EP
    c, d = t1 * 2 * _ED * t2 % _EP, z1 * 2 * z2 % _EP
    e, f, g, h = b - a, d - c, d + c, b + a
    return (e * f % _EP, g * h % _EP, f * g % _EP, e * h % _EP)


def _ed_mul(s, A):
    Q = (0, 1, 1, 0)
    while s > 0:
        if s & 1:
            Q = _ed_add(Q, A)
        A, s = _ed_add(A, A), s >> 1
    return Q


def _ed_point(b):
    if len(b) != 32:
        return None
    y = int.from_bytes(b, "little")
    sign, y = y >> 255, y & ((1 << 255) - 1)
    if y >= _EP:
        return None
    x2 = (y * y - 1) * pow(_ED * y * y + 1, _EP - 2, _EP) % _EP
    if x2 == 0:
        if sign:
            return None
        x = 0
    else:
        x = pow(x2, (_EP + 3) // 8, _EP)
        if (x * x - x2) % _EP:
            x = x * _EI % _EP
        if (x * x - x2) % _EP:
            return None
        if (x & 1) != sign:
            x = _EP - x
    return (x, y, 1, x * y % _EP)


_EG = _ed_point((4 * pow(5, _EP - 2, _EP) % _EP).to_bytes(32, "little"))


def ed25519_verify(pub, msg, sig):
    """Verifikasi tanda tangan Ed25519 (RFC 8032) tanpa library tambahan."""
    if len(pub) != 32 or len(sig) != 64:
        return False
    A, R = _ed_point(pub), _ed_point(sig[:32])
    s = int.from_bytes(sig[32:], "little")
    if A is None or R is None or s >= _EL:
        return False
    h = int.from_bytes(hashlib.sha512(sig[:32] + pub + msg).digest(), "little") % _EL
    P, Q = _ed_mul(s, _EG), _ed_add(R, _ed_mul(h, A))
    return (P[0] * Q[2] - Q[0] * P[2]) % _EP == 0 and (P[1] * Q[2] - Q[1] * P[2]) % _EP == 0


def _vtuple(v):
    return tuple(int(x) for x in re.findall(r"\d+", str(v))[:3])


def _upd_get(params, limit):
    r = requests.get(f"{SERVER}/api/update.php", params=params, headers=HEADERS, timeout=(10, 120), stream=True)
    if r.status_code != 200:
        raise RuntimeError(f"unduh {list(params.values())[0]} gagal (HTTP {r.status_code})")
    buf = io.BytesIO()
    for chunk in r.iter_content(65536):
        buf.write(chunk)
        if buf.tell() > limit:
            raise RuntimeError("file update terlalu besar")
    return buf.getvalue()


def _wheel_ok(fn):
    """Wheel cocok untuk Python laptop ini? (py3-none-any, cpXY sama, atau abi3 cpXY <= versi Python)"""
    parts = fn[:-4].split("-")
    if len(parts) < 5:
        return False
    py, abi, plat = parts[-3], parts[-2], parts[-1]
    if not set(plat.split(".")) & {"any", "win_amd64" if sys.maxsize > 2 ** 32 else "win32"}:
        return False
    for t in py.split("."):
        if t.startswith("py3"):
            return True
        if t.startswith("cp") and t[2:].isdigit() and len(t) >= 4:
            v = (int(t[2]), int(t[3:]))
            if v == sys.version_info[:2] or (abi == "abi3" and v <= sys.version_info[:2]):
                return True
    return False


def _sha_file(p):
    try:
        with open(p, "rb") as f:
            return hashlib.sha256(f.read()).hexdigest()
    except OSError:
        return ""


def self_update():
    """Unduh, periksa tanda tangan & sha256, pasang library baru bila perlu, ganti file (versi lama dicadangkan)."""
    if not IS_WIN:
        raise RuntimeError("update otomatis hanya untuk Windows")
    if not UPDATE_PUBKEY:
        raise RuntimeError("agent ini tidak punya kunci publik update (pasang ulang lewat /install/ID)")
    raw = _upd_get({"f": "manifest"}, 1 << 20)
    sig = bytes.fromhex(_upd_get({"f": "sig"}, 1024).decode("ascii").strip())
    if not ed25519_verify(bytes.fromhex(UPDATE_PUBKEY), raw, sig):
        raise RuntimeError("tanda tangan rilis TIDAK SAH, update ditolak")
    m = json.loads(raw)
    if m.get("app") != "gatemon-agent":
        raise RuntimeError("rilis bukan untuk GateMon Agent")
    if _vtuple(m.get("version")) <= _vtuple(VERSION):
        return f"sudah versi terbaru ({VERSION}, rilis {m.get('version')})", False
    files = m.get("files") or []
    if not any(f.get("path") == "agent.py" for f in files):
        raise RuntimeError("rilis tidak berisi agent.py")
    stage = os.path.join(UPD_DIR, "baru")
    shutil.rmtree(stage, ignore_errors=True)
    os.makedirs(stage)
    for f in files:
        name = str(f.get("path", ""))
        if not re.fullmatch(r"[A-Za-z0-9_\-]+\.(py|txt)", name):
            raise RuntimeError("nama file rilis tidak diizinkan: " + name)
        data = _upd_get({"f": name}, 5 << 20)
        if hashlib.sha256(data).hexdigest() != f.get("sha256"):
            raise RuntimeError(f"{name} tidak cocok dengan rilis (sha256)")
        with open(os.path.join(stage, name), "wb") as fh:
            fh.write(data)
        if name.endswith(".py"):
            compile(data, name, "exec")                  # file rusak/syntax error ketahuan sebelum dipasang
    # library: hanya bila requirements.txt berubah (pip memakai wheel bertanda tangan dari server, tanpa internet)
    req = os.path.join(stage, "requirements.txt")
    if os.path.isfile(req) and _sha_file(req) != _sha_file(os.path.join(BASE, "requirements.txt")):
        wdir = os.path.join(stage, "wheels")
        os.makedirs(wdir)
        for w in m.get("wheels") or []:
            fn = str(w.get("file", ""))
            if not re.fullmatch(r"[A-Za-z0-9_.\-]+\.whl", fn) or not _wheel_ok(fn):
                continue
            data = _upd_get({"w": fn}, 64 << 20)
            if hashlib.sha256(data).hexdigest() != w.get("sha256"):
                raise RuntimeError(f"{fn} tidak cocok dengan rilis (sha256)")
            with open(os.path.join(wdir, fn), "wb") as fh:
                fh.write(data)
        py = os.path.join(os.path.dirname(sys.executable), "python.exe")
        r = subprocess.run([py, "-m", "pip", "install", "--disable-pip-version-check", "--no-warn-script-location",
                            "--no-index", "--find-links", wdir, "--only-binary=:all:", "-r", req],
                           capture_output=True, text=True, timeout=900, creationflags=getattr(subprocess, "CREATE_NO_WINDOW", 0))
        if r.returncode:
            raise RuntimeError("pip gagal: " + (r.stderr or r.stdout).strip()[-300:])
        log.info("library diperbarui dari %d wheel", len(os.listdir(wdir)))
    # cadangkan versi sekarang lalu ganti
    old = os.path.join(UPD_DIR, "lama")
    shutil.rmtree(old, ignore_errors=True)
    os.makedirs(old)
    names = [str(f["path"]) for f in files]
    for n in names:
        if os.path.isfile(os.path.join(BASE, n)):
            shutil.copy2(os.path.join(BASE, n), os.path.join(old, n))
    with open(UPD_PENDING, "w", encoding="utf-8") as fh:
        json.dump({"from": VERSION, "to": m["version"], "files": names, "t": int(time.time())}, fh)
    for n in names:
        os.replace(os.path.join(stage, n), os.path.join(BASE, n))
    shutil.rmtree(stage, ignore_errors=True)
    log.info("update %s -> %s dipasang, restart", VERSION, m["version"])
    return f"update {VERSION} -> {m['version']} dipasang, agent restart", True


# Skrip penjaga (berjalan setelah agent lama keluar): jalankan tugas terjadwal, tunggu versi baru berhasil heartbeat
# (ok.flag); bila tidak dalam UPD_WAIT detik, kembalikan file versi lama dan jalankan lagi.
_GUARD_PS1 = r"""param([int]$OldPid, [string]$Base, [string]$Task, [int]$Wait)
$ErrorActionPreference = 'SilentlyContinue'
$u = Join-Path $Base 'update'
Wait-Process -Id $OldPid -Timeout 30
schtasks.exe /Run /TN $Task | Out-Null
$end = (Get-Date).AddSeconds($Wait)
while ((Get-Date) -lt $end) {
    if (Test-Path (Join-Path $u 'ok.flag')) { Remove-Item (Join-Path $u 'ok.flag') -Force; exit 0 }
    Start-Sleep -Seconds 3
}
$p = Get-Content (Join-Path $u 'pending.json') -Raw | ConvertFrom-Json
if (-not $p) { exit 0 }
schtasks.exe /End /TN $Task | Out-Null
Start-Sleep -Seconds 3
foreach ($f in $p.files) {
    $b = Join-Path (Join-Path $u 'lama') $f; $t = Join-Path $Base $f
    if (Test-Path $b) { Copy-Item $b $t -Force } else { Remove-Item $t -Force }
}
$r = @{ from = $p.from; to = $p.to; reason = "versi $($p.to) tidak tersambung ke server dalam $Wait detik" }
[IO.File]::WriteAllText((Join-Path $u 'failed.json'), ($r | ConvertTo-Json -Compress))
Remove-Item (Join-Path $u 'pending.json') -Force
schtasks.exe /Run /TN $Task | Out-Null
"""


def restart_for_update():
    guard = os.path.join(UPD_DIR, "penjaga.ps1")
    with open(guard, "w", encoding="ascii") as fh:
        fh.write(_GUARD_PS1)
    args = ["powershell.exe", "-NoProfile", "-NonInteractive", "-ExecutionPolicy", "Bypass", "-WindowStyle", "Hidden",
            "-File", guard, "-OldPid", str(os.getpid()), "-Base", BASE, "-Task", TASK_NAME, "-Wait", str(UPD_WAIT)]
    flags = getattr(subprocess, "CREATE_NO_WINDOW", 0) | getattr(subprocess, "CREATE_NEW_PROCESS_GROUP", 0)
    try:    # lepas dari job Task Scheduler agar penjaga tetap hidup saat tugas agent berhenti
        subprocess.Popen(args, creationflags=flags | 0x01000000, close_fds=True)   # CREATE_BREAKAWAY_FROM_JOB
    except OSError:
        subprocess.Popen(args, creationflags=flags, close_fds=True)
    logging.shutdown()
    os._exit(0)


def start_update(cmd_id):
    """Perintah UPDATE_AGENT: dikerjakan di thread agar heartbeat tetap jalan (pip bisa butuh waktu)."""
    def _go():
        try:
            msg, restart = self_update()
            st = "done"
        except Exception as e:
            log.exception("update gagal")
            msg, restart, st = "update gagal: " + str(e)[:300], False, "failed"
        try:
            requests.post(f"{SERVER}/api/result.php", headers=HEADERS, timeout=15,
                          json={"command_id": cmd_id, "status": st, "result": msg})
        except Exception as e:
            log.error("gagal lapor hasil update #%s: %s", cmd_id, e)
        _upd["busy"], _upd["restart"] = False, restart
    if _upd["busy"]:
        return
    _upd["busy"] = True
    threading.Thread(target=_go, daemon=True).start()


def update_startup():
    """Saat agent start: hasil update sebelumnya (berhasil / dikembalikan penjaga) dilaporkan lewat heartbeat."""
    try:
        os.remove(UPD_OK)
    except OSError:
        pass
    for path, ok in ((UPD_PENDING, True), (UPD_FAILED, False)):
        try:
            with open(path, encoding="utf-8") as fh:
                p = json.load(fh)
        except (OSError, ValueError):
            continue
        if ok and str(p.get("to")) == VERSION:
            _upd["pending"], _upd["result"] = p, f"ok:{p.get('from')}>{VERSION}"
        elif not ok:
            _upd["failed"], _upd["result"] = True, f"fail:{p.get('to')}:{p.get('reason', '')}"[:250]


def update_confirmed():
    """Dipanggil setelah heartbeat sukses: versi baru dinyatakan sehat (penjaga berhenti), laporan dihapus."""
    if _upd["pending"]:
        open(UPD_OK, "w").close()
        try:
            os.remove(UPD_PENDING)
        except OSError:
            pass
        log.info("update ke %s dikonfirmasi server", VERSION)
    if _upd["failed"]:
        try:
            os.remove(UPD_FAILED)
        except OSError:
            pass
    _upd["pending"], _upd["failed"], _upd["result"] = None, False, ""


# ---------- eksekusi perintah ----------
def find_browser_exe():
    for p in BROWSER["exe"]:
        if os.path.exists(p):
            return p
    return None


def open_gate(url=None):
    """Buka halaman gate. 1 URL = layar penuh (kiosk). >1 URL (menu Devices) = jendela berdampingan kiri-kanan.
    url diisi = buka URL itu saja (perintah "Buka halaman gate" dengan URL khusus)."""
    urls = [url] if url else list(gate_urls or ([gate_url] if gate_url else []))
    if not urls:
        raise RuntimeError("URL halaman gate belum diketahui")
    if len(urls) > 1:
        return open_multi(urls)
    exe = find_browser_exe()
    if not exe:
        raise RuntimeError("browser tidak ditemukan")
    args = [exe, "--user-data-dir=" + PROFILE]
    if scan_capture:
        # port debug untuk membaca hasil scan; Chrome/Edge hanya membukanya di 127.0.0.1 (tidak bisa diakses dari jaringan)
        args.append(f"--remote-debugging-port={CDP_PORT}")
    if CFG.get("kiosk", True):
        args.append("--kiosk")
    args += ["--new-window", "--disable-session-crashed-bubble", "--no-first-run", "--no-default-browser-check", urls[0]]
    subprocess.Popen(args, creationflags=getattr(subprocess, "DETACHED_PROCESS", 0))
    return "browser dibuka: " + urls[0]


# ---------- beberapa halaman berdampingan kiri-kanan ----------
# Tiap URL dibuka sebagai jendela aplikasi (tanpa address bar), lalu disusun lewat DevTools Protocol
# (port lokal 127.0.0.1, sama dengan pemantau scan) agar lebarnya sama dan tidak menutupi taskbar.
def work_area():
    """Area layar tanpa taskbar (x, y, lebar, tinggi) dalam satuan yang sama dengan Chrome (DIP)."""
    if IS_WIN:
        class RECT(ctypes.Structure):
            _fields_ = [("l", ctypes.c_long), ("t", ctypes.c_long), ("r", ctypes.c_long), ("b", ctypes.c_long)]
        r = RECT()
        if ctypes.windll.user32.SystemParametersInfoW(0x30, 0, ctypes.byref(r), 0):   # SPI_GETWORKAREA
            return r.l, r.t, r.r - r.l, r.b - r.t
    return 0, 0, 1366, 728


def _ukey(u):
    p = urlparse(u or "")
    return (p.netloc.lower(), p.path.lower())


def page_targets():
    return [t for t in LOCAL.get(f"http://127.0.0.1:{CDP_PORT}/json/list", timeout=2).json() if t.get("type") == "page"]


def match_pages(urls, targets):
    """Pasangkan tiap URL dengan jendelanya: path sama dulu, lalu host sama (mis. diarahkan ke halaman login)."""
    used, res = set(), {}
    for i, u in enumerate(urls):
        for rule in (lambda t: _ukey(t.get("url")) == _ukey(u), lambda t: _ukey(t.get("url"))[0] == _ukey(u)[0]):
            t = next((t for t in targets if t["id"] not in used and rule(t)), None)
            if t:
                used.add(t["id"]); res[i] = t
                break
    return res


def layout_windows(urls):
    """Susun jendela berdampingan: lebar layar dibagi rata sesuai urutan URL."""
    import websocket
    pages = {}
    for _ in range(40):                                  # tunggu semua jendela muncul (maks ~20 detik)
        try:
            pages = match_pages(urls, page_targets())
            if len(pages) == len(urls):
                break
        except Exception:
            pass
        time.sleep(0.5)
    if not pages:
        return
    x, y, w, h = work_area()
    n = len(urls)
    ver = LOCAL.get(f"http://127.0.0.1:{CDP_PORT}/json/version", timeout=2).json()
    ws = websocket.create_connection(ver["webSocketDebuggerUrl"], timeout=5, suppress_origin=True)
    seq = [0]

    def call(method, params):
        seq[0] += 1
        ws.send(json.dumps({"id": seq[0], "method": method, "params": params}))
        while True:
            m = json.loads(ws.recv())
            if m.get("id") == seq[0]:
                if "error" in m:
                    raise RuntimeError(m["error"].get("message"))
                return m.get("result", {})
    try:
        for i, t in sorted(pages.items()):
            win = call("Browser.getWindowForTarget", {"targetId": t["id"]})["windowId"]
            left = x + i * (w // n)
            width = w - (n - 1) * (w // n) if i == n - 1 else w // n
            call("Browser.setWindowBounds", {"windowId": win, "bounds": {"windowState": "normal"}})
            call("Browser.setWindowBounds", {"windowId": win, "bounds": {"left": left, "top": y, "width": width, "height": h}})
        log.info("%d halaman disusun berdampingan (%dx%d per jendela)", len(pages), w // n, h)
    finally:
        ws.close()


def open_multi(urls, only=None):
    exe = find_browser_exe()
    if not exe:
        raise RuntimeError("browser tidak ditemukan")
    base = [exe, "--user-data-dir=" + PROFILE, f"--remote-debugging-port={CDP_PORT}", "--no-first-run",
            "--no-default-browser-check", "--disable-session-crashed-bubble"]
    for i, u in enumerate(urls):
        if only is None or i in only:
            subprocess.Popen(base + ["--app=" + u], creationflags=getattr(subprocess, "DETACHED_PROCESS", 0))
            time.sleep(1.5)                              # beri waktu browser start sebelum jendela berikutnya
    threading.Thread(target=lambda: _safe(layout_windows, urls), daemon=True).start()
    return "%d halaman dibuka berdampingan: %s" % (len(urls) if only is None else len(only), " | ".join(urls))


def _safe(fn, *a):
    try:
        fn(*a)
    except Exception as e:
        log.warning("menyusun jendela gagal: %s", e)


def kill_browser():
    """Tutup hanya browser gate (profil GateMon), bukan Chrome/Edge lain milik petugas."""
    n = 0
    for p in gate_browser_procs():
        try:
            p.kill(); n += 1
        except (psutil.NoSuchProcess, psutil.AccessDenied):
            pass
    time.sleep(2)
    return n


# ---------- buka halaman gate otomatis ----------
_auto = {"opened": False, "last_open": 0.0, "closed_since": None, "urls": None, "missing_since": None}


def multi_watch(j):
    """Beberapa halaman: buka ulang hanya jendela yang tertutup, dan buka ulang semua bila daftar URL berubah."""
    now = time.time()
    try:
        found = match_pages(gate_urls, page_targets())
    except Exception:
        found = None                                      # port debug tidak ada (dibuka agent lama): buka ulang sekali
    missing = list(range(len(gate_urls))) if found is None else [i for i in range(len(gate_urls)) if i not in found]
    if not missing:
        _auto["missing_since"] = None
        return
    _auto["missing_since"] = _auto["missing_since"] or now
    # jeda 40 dtk sejak terakhir membuka: browser (terutama profil baru) bisa lambat memunculkan jendela
    if j.get("auto_reopen", 1) and now - _auto["missing_since"] >= int(j.get("reopen_after", 15)) and now - _auto["last_open"] >= 40:
        if found is None:
            kill_browser(); open_gate()
        else:
            log.info("membuka ulang %d halaman yang tertutup", len(missing))
            open_multi(gate_urls, only=missing)
        _auto["last_open"], _auto["missing_since"] = now, None


def auto_gate(j):
    """Dipanggil tiap heartbeat sukses. Buka halaman gate saat pertama terhubung ke server,
    dan buka ulang bila jendela gate tertutup lebih lama dari reopen_after (diatur di menu Pengaturan)."""
    if mode != "gate" or not (gate_urls or gate_url) or not j.get("auto_open", 1):
        return
    if browser_running():
        _auto["opened"], _auto["closed_since"] = True, None
        if _auto["urls"] is None:
            _auto["urls"] = list(gate_urls)
        elif _auto["urls"] != gate_urls and time.time() - _auto["last_open"] >= 20:
            log.info("daftar halaman berubah di menu Devices, browser gate dibuka ulang")
            kill_browser()
            _safe(open_gate)
            _auto["urls"], _auto["last_open"] = list(gate_urls), time.time()
        elif len(gate_urls) > 1:
            multi_watch(j)
        return
    now = time.time()
    if _auto["closed_since"] is None:
        _auto["closed_since"] = now
    first = not _auto["opened"]
    reopen = j.get("auto_reopen", 1) and now - _auto["closed_since"] >= int(j.get("reopen_after", 15))
    if (first or reopen) and now - _auto["last_open"] >= 20:   # beri waktu browser untuk start
        log.info("membuka halaman gate otomatis (%s)", "terhubung ke server" if first else "browser gate tertutup")
        try:
            open_gate()
            _auto["urls"] = list(gate_urls)
        except Exception as e:
            log.error("gagal membuka halaman gate: %s", e)
        _auto["opened"], _auto["last_open"] = True, now


# ---------- tangkap hasil scan halaman gate ----------
# Agent mendengarkan balasan proses_scan_gate.php di browser gate lewat Chrome DevTools Protocol (port lokal).
# Yang disimpan HANYA: status, kategori, gate, ID QR/barcode (yang di-scan), waktu. Nama & perusahaan diabaikan.
# Antrean bertahan selama server tidak terjangkau dan dikirim bersama heartbeat berikutnya.
CDP_PORT = int(CFG.get("cdp_port", 9333))
scan_capture = False                                   # hanya "laptop scan" (ditentukan server lewat heartbeat)
SCAN_PAGE = ["form-scanning-gate"]                    # pola URL halaman scan  (menu Pengaturan)
SCAN_API = ["proses_scan_gate"]                        # pola URL proses scan   (menu Pengaturan)


def url_match(url, patterns):
    url = (url or "").lower()
    return any(p and p in url for p in patterns)
SCAN_Q = collections.deque(maxlen=5000)
_scan_lock = threading.Lock()
LOCAL = requests.Session()
LOCAL.trust_env = False                                # akses 127.0.0.1 tidak boleh lewat proxy sistem


def add_scan(info, status, category=""):
    item = {"sid": "%d-%s" % (int(info["t"] * 1000), os.urandom(3).hex()), "t": int(info["t"]),
            "gate": str(info.get("gate", ""))[:50], "status": status,
            "category": str(category or "")[:100], "code": str(info.get("code", ""))[:100]}
    with _scan_lock:
        SCAN_Q.append(item)
    log.info("scan %s %s %s", item["status"], item["code"], item["category"])


def scan_status(resp):
    st = str(resp.get("status", ""))
    return "success" if st == "success" else ("belum" if st == "belum" else "notfound")


def scan_watcher():
    import websocket
    no_port_since = None
    while True:
        try:
            if not scan_capture or mode != "gate":
                time.sleep(5)
                continue
            try:
                targets = LOCAL.get(f"http://127.0.0.1:{CDP_PORT}/json/list", timeout=2).json()
            except Exception:
                # browser gate terbuka tanpa port debug (dibuka agent lama / manual): tutup sekali agar dibuka ulang
                if browser_running():
                    no_port_since = no_port_since or time.time()
                    if time.time() - no_port_since > 30:
                        log.info("browser gate dibuka ulang agar hasil scan bisa dibaca")
                        kill_browser()
                        no_port_since = None
                time.sleep(5)
                continue
            no_port_since = None
            page = next((t for t in targets if t.get("type") == "page" and url_match(t.get("url"), SCAN_PAGE)), None)
            if not page:
                time.sleep(5)
                continue
            ws = websocket.create_connection(page["webSocketDebuggerUrl"], timeout=5, suppress_origin=True)
            ws.send(json.dumps({"id": 1, "method": "Network.enable"}))
            ws.settimeout(10)
            log.info("pemantau scan terhubung ke halaman gate")
            pending, bodies, seq = {}, {}, 1
            while scan_capture and mode == "gate":
                try:
                    msg = json.loads(ws.recv())
                except websocket.WebSocketTimeoutException:
                    continue
                m, p = msg.get("method"), msg.get("params", {})
                if m == "Network.requestWillBeSent" and url_match(p.get("request", {}).get("url"), SCAN_API):
                    q = parse_qs(p["request"].get("postData") or "")
                    pending[p["requestId"]] = {"t": time.time(), "code": q.get("checking", [""])[0], "gate": q.get("gate", [""])[0]}
                elif m == "Network.loadingFinished" and p.get("requestId") in pending:
                    seq += 1
                    bodies[seq] = p["requestId"]
                    ws.send(json.dumps({"id": seq, "method": "Network.getResponseBody", "params": {"requestId": p["requestId"]}}))
                elif m == "Network.loadingFailed" and p.get("requestId") in pending:
                    add_scan(pending.pop(p["requestId"]), "error")          # gagal terkirim ke server halaman gate
                elif msg.get("id") in bodies:
                    info = pending.pop(bodies.pop(msg["id"]), None)
                    if not info:
                        continue
                    res = msg.get("result") or {}
                    body = res.get("body", "")
                    if res.get("base64Encoded"):
                        body = base64.b64decode(body).decode("utf-8", "replace")
                    try:
                        resp = json.loads(body.strip().lstrip("﻿"))
                        st = scan_status(resp)
                        add_scan(info, st, resp.get("category", "") if st == "success" else "")
                    except Exception:
                        add_scan(info, "error")
                for rid in [r for r, i in pending.items() if time.time() - i["t"] > 60]:
                    pending.pop(rid, None)                              # balasan tidak pernah datang
            ws.close()
        except Exception as e:
            log.debug("pemantau scan: %s", e)
            time.sleep(3)


def show_message(text):
    # MessageBox di thread terpisah agar agent tetap melapor
    def _box():
        if IS_WIN:
            ctypes.windll.user32.MessageBoxW(0, text, "Pesan dari Tim IT", 0x40 | 0x1000 | 0x40000)  # info | system modal | topmost
        else:
            log.info("MESSAGE: %s", text)
    threading.Thread(target=_box, daemon=True).start()
    return "pesan ditampilkan"


def screenshot_b64():
    from PIL import ImageGrab
    img = ImageGrab.grab()
    img.thumbnail((1280, 800))
    buf = io.BytesIO()
    img.convert("RGB").save(buf, format="JPEG", quality=70, optimize=True)   # JPEG: ~4x lebih kecil dari PNG
    return base64.b64encode(buf.getvalue()).decode()


def run_command(cmd):
    t = cmd["type"]
    payload = cmd.get("payload") or ""
    if t == "PING":
        return "pong dari " + socket.gethostname()
    if t == "MESSAGE":
        return show_message(payload)
    if t == "OPEN_GATE":
        return open_gate(payload or None)
    if t == "RESTART_BROWSER":
        n = kill_browser()
        return f"{n} proses browser ditutup; " + open_gate(payload or None)
    if t == "SCREENSHOT":
        return screenshot_b64()
    if t == "LOCK":
        if IS_WIN:
            ctypes.windll.user32.LockWorkStation()
        return "layar dikunci"
    if t == "REBOOT":
        if IS_WIN:
            subprocess.Popen(["shutdown", "/r", "/t", "10", "/c", "Restart oleh Tim IT (GateMon)"])
        return "reboot dalam 10 detik"
    if t == "SHUTDOWN":
        if IS_WIN:
            subprocess.Popen(["shutdown", "/s", "/t", "10", "/c", "Laptop dimatikan oleh Tim IT (GateMon)"])
        return "shutdown dalam 10 detik"
    if t == "SLEEP":
        return go_sleep()
    if t == "WAKE":
        return wake(payload)
    raise RuntimeError("perintah tidak dikenal: " + t)


def report(cmd_id, status, result):
    try:
        SRV.post(f"{SERVER}/api/result.php",
                 json={"command_id": cmd_id, "status": status, "result": result}, timeout=15)
    except Exception as e:
        log.error("gagal lapor hasil #%s: %s", cmd_id, e)


# ---------- loop utama ----------
def main():
    global gate_url, gate_urls, mode, net_ms, gate_check_every, scan_capture
    interval = int(CFG.get("interval", 5))
    log.info("agent %s start, server %s, device %s", VERSION, SERVER, CFG["device_key"])
    psutil.cpu_percent(interval=None)
    update_startup()
    threading.Thread(target=scan_watcher, daemon=True).start()
    while True:
        try:
            body = collect()
            with _scan_lock:
                batch = list(SCAN_Q)[:200]                       # scan yang belum terkirim (maks 200 per heartbeat)
            if batch:
                body["scans"] = batch
            t = time.time()
            r = SRV.post(f"{SERVER}/api/heartbeat.php", json=body, timeout=10)
            net_ms = int((time.time() - t) * 1000)
            if r.status_code == 401:
                log.error("ditolak server (401): periksa device_key/token di config.json")
            else:
                j = r.json()
                if r.status_code == 200 and "update_result" in body:
                    update_confirmed()                           # versi baru sehat / laporan rollback terkirim
                interval = max(2, min(300, int(j.get("interval", interval))))  # diatur di menu Pengaturan
                gate_check_every = max(10, min(600, int(j.get("gate_check_every", gate_check_every))))
                scan_capture = bool(j.get("scan_capture", 0))
                if j.get("scan_page_match"):
                    SCAN_PAGE[:] = [str(p).lower() for p in j["scan_page_match"]]
                if j.get("scan_api_match"):
                    SCAN_API[:] = [str(p).lower() for p in j["scan_api_match"]]
                ack = set(j.get("scans_ack") or [])
                if ack:                                          # hapus dari antrean hanya yang sudah diterima server
                    with _scan_lock:
                        keep = [s for s in SCAN_Q if s["sid"] not in ack]
                        SCAN_Q.clear()
                        SCAN_Q.extend(keep)
                if j.get("mode") in ("gate", "monitor") and j["mode"] != mode:
                    mode = j["mode"]
                    log.info("mode laptop: %s", mode)
                if isinstance(j.get("gate_urls"), list):
                    gate_urls = [str(u) for u in j["gate_urls"] if str(u).startswith(("http://", "https://"))][:4]
                if j.get("gate_url"):
                    gate_url = j["gate_url"]
                for cmd in j.get("commands", []):
                    log.info("perintah #%s %s", cmd["id"], cmd["type"])
                    if cmd["type"] == "UPDATE_AGENT":            # hasil dilaporkan thread update sendiri
                        start_update(cmd["id"])
                        continue
                    try:
                        report(cmd["id"], "done", run_command(cmd))
                    except Exception as e:
                        log.exception("perintah #%s gagal", cmd["id"])
                        report(cmd["id"], "failed", str(e))
                auto_gate(j)
                if "wallpaper" in j:                             # server lama tidak mengirim: wallpaper tidak disentuh
                    wallpaper_sync(j["wallpaper"])
            if _upd["restart"]:
                restart_for_update()                             # hasil update sudah dilaporkan; penjaga mengambil alih
        except Exception as e:
            net_ms = None
            log.warning("heartbeat gagal: %s", e)
        time.sleep(interval)


if __name__ == "__main__":
    main()
