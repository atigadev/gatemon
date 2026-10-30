"""
GateMon Agent - dipasang di setiap laptop gate (Windows).
Tiap beberapa detik melapor ke server dan menjalankan perintah dari dashboard.
Jalankan dengan pythonw.exe agar tidak ada jendela hitam.
"""
import base64, collections, ctypes, hashlib, io, ipaddress, json, logging, os, platform, re, shutil, socket, subprocess, sys, threading, time
import psutil, requests
from urllib.parse import parse_qs, urlparse

VERSION = "1.14.0"
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
        "power_state": _pol_state().get("power", ""),
        "winupdate_state": _pol_state().get("winupdate", ""),
        "policy_err": _pol["err"],
        **({"update_result": _upd["result"]} if _upd["result"] else {}),
        "support_ui": support_ui_state(),
        **({"support_err": _sup["err"]} if _sup["err"] else {}),
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


# ---------- kebijakan daya & Windows Update (menu Daya) ----------
# Server mengirim {"power": ..., "winupdate": ...} tiap heartbeat; "" = jangan ubah. Agent menerapkan hanya saat
# berubah (yang terakhir diterapkan disimpan di policy-state.json) lewat powercfg dan service wuauserv. Perlu Administrator.
POL_STATE = os.path.join(BASE, "policy-state.json")
_pol = {"busy": False, "err": "", "state": None}
# menit timeout untuk rencana daya yang sedang aktif: (monitor-ac, monitor-dc, standby-ac, standby-dc, hibernate-ac, hibernate-dc)
POWER_PLANS = {
    "always_on": (0, 0, 0, 0, 0, 0),
    "balanced": (10, 5, 30, 15, 0, 0),
    "saver": (5, 2, 15, 10, 20, 15),
}
WU_SERVICES = ("wuauserv", "UsoSvc", "WaaSMedicSvc")   # layanan Windows Update
WU_AU_KEY = r"HKLM\SOFTWARE\Policies\Microsoft\Windows\WindowsUpdate\AU"


def _pol_state():
    if _pol["state"] is None:
        try:
            with open(POL_STATE, encoding="utf-8") as f:
                _pol["state"] = json.load(f)
        except Exception:
            _pol["state"] = {}
    return _pol["state"]


def _run(args, ok_rc=(0,)):
    r = subprocess.run(args, capture_output=True, text=True, timeout=120,
                       creationflags=getattr(subprocess, "CREATE_NO_WINDOW", 0))
    if r.returncode not in ok_rc:
        raise RuntimeError("%s (rc=%s) %s" % (args[0] if not isinstance(args, str) else args, r.returncode, (r.stderr or r.stdout).strip()[:160]))
    return r


def apply_power(mode):
    keys = ("monitor-timeout-ac", "monitor-timeout-dc", "standby-timeout-ac", "standby-timeout-dc",
            "hibernate-timeout-ac", "hibernate-timeout-dc")
    for k, v in zip(keys, POWER_PLANS[mode]):
        _run(["powercfg", "/change", k, str(v)])


def apply_winupdate(state):
    if state == "off":
        for svc in WU_SERVICES:
            _run(["sc", "config", svc, "start=", "disabled"], ok_rc=(0, 1060, 1072))   # 1060/1072 = layanan tidak ada/ditandai hapus
            _run(["sc", "stop", svc], ok_rc=(0, 1062, 1060, 1072))                      # 1062 = memang belum jalan
        _run(["reg", "add", WU_AU_KEY, "/v", "NoAutoUpdate", "/t", "REG_DWORD", "/d", "1", "/f"])
    else:   # on: kembalikan ke bawaan (manual/otomatis) dan cabut kebijakan NoAutoUpdate
        _run(["reg", "add", WU_AU_KEY, "/v", "NoAutoUpdate", "/t", "REG_DWORD", "/d", "0", "/f"])
        for svc, start in (("wuauserv", "demand"), ("UsoSvc", "delayed-auto"), ("WaaSMedicSvc", "demand")):
            _run(["sc", "config", svc, "start=", start], ok_rc=(0, 1060, 1072))
        _run(["sc", "start", "wuauserv"], ok_rc=(0, 1056, 1060, 1072))                  # 1056 = sudah jalan


def policy_sync(pol):
    if not IS_WIN or _pol["busy"] or not isinstance(pol, dict):
        return
    power = pol.get("power") if pol.get("power") in POWER_PLANS else ""
    wu = pol.get("winupdate") if pol.get("winupdate") in ("on", "off") else ""
    st = _pol_state()
    todo = []
    if power and st.get("power") != power:
        todo.append(("power", power, lambda: apply_power(power)))
    if wu and st.get("winupdate") != wu:
        todo.append(("winupdate", wu, lambda: apply_winupdate(wu)))
    if not todo:
        return

    def _go():
        errs = []
        for key, val, fn in todo:
            try:
                fn()
                _pol_state()[key] = val
                log.info("kebijakan %s -> %s diterapkan", key, val)
            except Exception as e:
                errs.append(str(e)[:120])
                log.warning("kebijakan %s gagal: %s", key, e)
        try:
            with open(POL_STATE, "w", encoding="utf-8") as f:
                json.dump(_pol["state"], f)
        except OSError:
            pass
        _pol["err"] = " | ".join(errs)[:200]
        _pol["busy"] = False
    _pol["busy"] = True
    threading.Thread(target=_go, daemon=True).start()   # powercfg/sc tidak menahan heartbeat


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
    if t == "SUPPORT_SHOW":
        return support_show(payload == "chat")
    raise RuntimeError("perintah tidak dikenal: " + t)


def report(cmd_id, status, result):
    try:
        SRV.post(f"{SERVER}/api/result.php",
                 json={"command_id": cmd_id, "status": status, "result": result}, timeout=15)
    except Exception as e:
        log.error("gagal lapor hasil #%s: %s", cmd_id, e)


# ---------- chat support & tombol DARURAT (agent >= 1.11) ----------
# Dua tombol kecil selalu di atas, pojok kanan bawah layar: "Chat Support" membuka jendela chat dengan tim support
# (menu Support di server), "DARURAT" langsung mengirim tanda darurat yang membunyikan alarm di halaman admin.
# Win32 murni lewat ctypes (tanpa tkinter). Tombol tidak mengambil fokus keyboard: scanner barcode di halaman gate
# tetap mengetik ke browser walau tombol diklik. Pesan yang gagal terkirim (jaringan putus) dikirim ulang terus.
SUP_STATE = os.path.join(BASE, "support-state.json")
SUP = requests.Session()                      # sesi sendiri: thread support tidak berbagi koneksi dengan heartbeat
SUP.headers.update(HEADERS)
_sup = {"on": False, "last": 0, "max": 0, "seen": 0, "emg": "", "msgs": [], "status": "", "err": "", "started": False, "visible": False}
_sup_lock = threading.Lock()
_sup_out = collections.deque()                # kiriman yang belum diterima server
_sup_wake = threading.Event()
_sup_hwnd = [None]                            # jendela tombol (untuk membangunkan thread UI dari thread lain)
_sup_ui_thread = [None, 0.0]                  # thread tombol + waktu terakhir dijalankan
_sup_keep = []                                # objek UI lama tetap disimpan: callback jendelanya tidak boleh dibuang GC


def _sup_load():
    try:
        with open(SUP_STATE, encoding="utf-8") as f:
            _sup["seen"] = int(json.load(f).get("seen", 0))
    except Exception:
        pass


def _sup_save():
    try:
        with open(SUP_STATE, "w", encoding="utf-8") as f:
            json.dump({"seen": _sup["seen"]}, f)
    except Exception as e:
        log.debug("support-state: %s", e)


def _sup_post():
    if _sup_hwnd[0]:
        ctypes.windll.user32.PostMessageW(_sup_hwnd[0], 0x8001, 0, 0)   # WM_APP+1: gambar ulang


def sup_unread():
    return sum(1 for m in _sup["msgs"] if m["sender"] != "agent" and m["id"] > _sup["seen"])


def sup_send(kind, text=""):
    cid = "%x%s" % (int(time.time() * 1000), os.urandom(4).hex())
    with _sup_lock:
        _sup_out.append({"kind": kind, "text": text, "cid": cid, "user": os.environ.get("USERNAME", "")})
        if kind == "emergency":
            _sup["emg"] = "sending"
        _sup["status"] = "Mengirim…"
    _sup_wake.set()
    _sup_post()


def sup_worker():
    _sup_load()
    first = True
    while True:
        _sup_wake.wait(3 if _sup["visible"] or _sup_out else 20)
        _sup_wake.clear()
        if not _sup["on"]:
            first = True
            continue
        try:
            while _sup_out:
                m = _sup_out[0]
                r = SUP.post(f"{SERVER}/api/support.php", json=m, timeout=10)
                try:
                    j = r.json()
                except ValueError:
                    j = {}
                if r.status_code == 200 and j.get("ok"):
                    with _sup_lock:
                        _sup_out.popleft()
                        if m["kind"] == "emergency":
                            _sup["emg"] = "open"
                        _sup["status"] = "Darurat terkirim, support segera datang" if m["kind"] == "emergency" else "Terkirim"
                    _sup_wake.set()                              # ambil pesan yang baru tersimpan
                elif r.status_code == 429:                       # terlalu cepat: dicoba lagi nanti
                    _sup["status"] = j.get("error") or "Tunggu sebentar"
                    break
                elif r.status_code in (400, 403):                # ditolak server: tidak dikirim ulang
                    with _sup_lock:
                        _sup_out.popleft()
                        if m["kind"] == "emergency":
                            _sup["emg"] = ""
                        _sup["status"] = "Gagal: " + str(j.get("error") or r.status_code)
                else:
                    raise RuntimeError("HTTP %s" % r.status_code)
            show = _sup["visible"]
            if first or show or _sup["last"] > _sup["max"]:
                r = SUP.get(f"{SERVER}/api/support.php", params={"since": _sup["max"], "read": 1 if show else 0}, timeout=10)
                j = r.json()
                if j.get("ok"):
                    with _sup_lock:
                        for m in j.get("msgs") or []:
                            if int(m["id"]) > _sup["max"]:
                                _sup["msgs"].append(m)
                                _sup["max"] = int(m["id"])
                        del _sup["msgs"][:-200]
                        if _sup["emg"] != "sending":
                            _sup["emg"] = str(j.get("emg") or "")
                        if show and _sup["seen"] < _sup["max"]:
                            _sup["seen"] = _sup["max"]
                            _sup_save()
                    first = False
            if _sup["status"].startswith("Tidak terhubung"):
                _sup["status"] = ""
        except Exception as e:
            _sup["status"] = "Tidak terhubung ke server, dicoba lagi…"
            log.debug("support: %s", e)
        _sup_post()


def support_sync(s):
    """Dari balasan heartbeat: aktif/tidak (menu Pengaturan), id pesan support terakhir, status darurat."""
    on = bool(s.get("on")) and IS_WIN
    with _sup_lock:
        _sup["last"] = int(s.get("last") or 0)
        if on and _sup["emg"] != "sending":
            _sup["emg"] = str(s.get("emg") or "")
    _sup["on"] = on
    if on and not _sup["started"]:
        _sup["started"] = True
        threading.Thread(target=sup_worker, daemon=True).start()
    if on:
        _sup_ensure_ui()
    if _sup["last"] > _sup["max"]:
        _sup_wake.set()
    _sup_post()


def _sup_ensure_ui(force=False):
    """Jalankan (ulang) tombol bila belum ada atau berhenti karena error. Dicoba lagi paling cepat tiap 60 detik,
    langsung bila diminta dari server (menu Support -> Tampilkan di layar)."""
    t = _sup_ui_thread[0]
    if t and t.is_alive():
        return
    if not force and time.time() - _sup_ui_thread[1] < 60:
        return
    _sup_ui_thread[1] = time.time()
    _sup_ui_thread[0] = threading.Thread(target=_sup_ui_main, daemon=True)
    _sup_ui_thread[0].start()


def _sup_ui_main():
    ui = SupportUI()
    _sup_keep.append(ui)
    try:
        ui.run()
    except Exception as e:
        _sup["err"] = str(e)[:200]
        log.exception("tombol support gagal tampil")
    finally:
        _sup_hwnd[0] = None


def support_ui_state():
    """Dilaporkan ke server (menu Support): shown / off / starting / error:..."""
    if not _sup["on"]:
        return "off"
    t = _sup_ui_thread[0]
    if _sup["err"] and not (t and t.is_alive()):
        return "error:" + _sup["err"]
    h = _sup_hwnd[0]
    return "shown" if h and ctypes.windll.user32.IsWindowVisible(h) else "starting"


def support_show(chat):
    """Perintah SUPPORT_SHOW dari server: munculkan lagi tombol (dan buka jendela chat)."""
    if not IS_WIN:
        raise RuntimeError("hanya untuk Windows")
    if not _sup["on"]:
        raise RuntimeError("tombol support sedang dinonaktifkan untuk laptop ini")
    _sup_ensure_ui(force=True)
    for _ in range(50):
        if _sup_hwnd[0]:
            break
        time.sleep(0.1)
    if not _sup_hwnd[0]:
        raise RuntimeError("tombol gagal tampil: " + (_sup["err"] or "belum siap"))
    ctypes.windll.user32.PostMessageW(_sup_hwnd[0], 0x8002 if chat else 0x8001, 0, 0)
    return "tombol support tampil" + (", jendela chat dibuka" if chat else "")


class SupportUI:
    BAR_W, CHAT_W, SOS_W, GAP, BAR_H, MARGIN = 150, 150, 124, 8, 40, 14
    KEY = 0xFF00FF                                     # warna transparan di sekitar tombol (COLORREF 0x00BBGGRR)
    C = {"blue": 0xEB6325, "amber": 0x0B9EF5, "amber2": 0x0677D9, "red": 0x2626DC, "red2": 0x1C1C99, "green": 0x4AA316}
    ID_SEND, ID_CANCEL, ID_LOG, ID_INPUT, ID_STATUS = 1, 2, 10, 11, 12

    def __init__(self):
        import ctypes.wintypes as W
        self.W = W
        u = self.u = ctypes.WinDLL("user32", use_last_error=True)   # salinan sendiri: argtypes tidak mengubah ctypes.windll
        g = self.g = ctypes.WinDLL("gdi32")
        k = self.k = ctypes.WinDLL("kernel32", use_last_error=True)
        P, I, LR = ctypes.POINTER, ctypes.c_int, ctypes.c_ssize_t
        self.WNDPROC = ctypes.WINFUNCTYPE(LR, W.HWND, W.UINT, W.WPARAM, W.LPARAM)

        class WNDCLASSEXW(ctypes.Structure):
            _fields_ = [("cbSize", W.UINT), ("style", W.UINT), ("lpfnWndProc", self.WNDPROC), ("cbClsExtra", I), ("cbWndExtra", I),
                        ("hInstance", W.HINSTANCE), ("hIcon", W.HICON), ("hCursor", W.HANDLE), ("hbrBackground", W.HBRUSH),
                        ("lpszMenuName", W.LPCWSTR), ("lpszClassName", W.LPCWSTR), ("hIconSm", W.HICON)]

        class PAINTSTRUCT(ctypes.Structure):
            _fields_ = [("hdc", W.HDC), ("fErase", W.BOOL), ("rcPaint", W.RECT), ("fRestore", W.BOOL), ("fIncUpdate", W.BOOL), ("rgb", ctypes.c_byte * 32)]
        self.WNDCLASSEXW, self.PAINTSTRUCT = WNDCLASSEXW, PAINTSTRUCT
        for fn, args, res in [
            ("DefWindowProcW", [W.HWND, W.UINT, W.WPARAM, W.LPARAM], LR), ("RegisterClassExW", [P(WNDCLASSEXW)], W.ATOM),
            ("CreateWindowExW", [W.DWORD, W.LPCWSTR, W.LPCWSTR, W.DWORD, I, I, I, I, W.HWND, W.HMENU, W.HINSTANCE, W.LPVOID], W.HWND),
            ("SendMessageW", [W.HWND, W.UINT, W.WPARAM, W.LPARAM], LR), ("SetWindowPos", [W.HWND, W.HWND, I, I, I, I, W.UINT], W.BOOL),
            ("ShowWindow", [W.HWND, I], W.BOOL), ("IsWindowVisible", [W.HWND], W.BOOL), ("BeginPaint", [W.HWND, P(PAINTSTRUCT)], W.HDC),
            ("EndPaint", [W.HWND, P(PAINTSTRUCT)], W.BOOL), ("FillRect", [W.HDC, P(W.RECT), W.HBRUSH], I),
            ("DrawTextW", [W.HDC, W.LPCWSTR, I, P(W.RECT), W.UINT], I), ("InvalidateRect", [W.HWND, P(W.RECT), W.BOOL], W.BOOL),
            ("SetLayeredWindowAttributes", [W.HWND, W.COLORREF, W.BYTE, W.DWORD], W.BOOL), ("GetMessageW", [P(W.MSG), W.HWND, W.UINT, W.UINT], W.BOOL),
            ("IsDialogMessageW", [W.HWND, P(W.MSG)], W.BOOL), ("TranslateMessage", [P(W.MSG)], W.BOOL), ("DispatchMessageW", [P(W.MSG)], LR),
            ("SetTimer", [W.HWND, ctypes.c_size_t, W.UINT, W.LPVOID], ctypes.c_size_t), ("LoadCursorW", [W.HINSTANCE, W.LPVOID], W.HANDLE),
            ("SetWindowTextW", [W.HWND, W.LPCWSTR], W.BOOL), ("GetWindowTextW", [W.HWND, W.LPWSTR, I], I), ("GetWindowTextLengthW", [W.HWND], I),
            ("SetForegroundWindow", [W.HWND], W.BOOL), ("SetFocus", [W.HWND], W.HWND), ("MessageBeep", [W.UINT], W.BOOL),
            ("GetDlgItem", [W.HWND, I], W.HWND), ("SystemParametersInfoW", [W.UINT, W.UINT, W.LPVOID, W.UINT], W.BOOL),
            ("AdjustWindowRectEx", [P(W.RECT), W.DWORD, W.BOOL, W.DWORD], W.BOOL),
            ("UnregisterClassW", [W.LPCWSTR, W.HINSTANCE], W.BOOL), ("RegisterHotKey", [W.HWND, I, W.UINT, W.UINT], W.BOOL)]:
            f = getattr(u, fn)
            f.argtypes, f.restype = args, res
        for fn, args, res in [
            ("CreateSolidBrush", [W.COLORREF], W.HBRUSH), ("CreatePen", [I, I, W.COLORREF], W.HPEN), ("SelectObject", [W.HDC, W.HGDIOBJ], W.HGDIOBJ),
            ("DeleteObject", [W.HGDIOBJ], W.BOOL), ("RoundRect", [W.HDC, I, I, I, I, I, I], W.BOOL), ("SetBkMode", [W.HDC, I], I),
            ("SetTextColor", [W.HDC, W.COLORREF], W.COLORREF), ("SetBkColor", [W.HDC, W.COLORREF], W.COLORREF), ("GetStockObject", [I], W.HGDIOBJ),
            ("CreateFontW", [I, I, I, I, I, W.DWORD, W.DWORD, W.DWORD, W.DWORD, W.DWORD, W.DWORD, W.DWORD, W.DWORD, W.LPCWSTR], W.HFONT)]:
            f = getattr(g, fn)
            f.argtypes, f.restype = args, res
        k.GetModuleHandleW.argtypes, k.GetModuleHandleW.restype = [W.LPCWSTR], W.HMODULE
        self.hinst = k.GetModuleHandleW(None)
        self.blink = False
        self.tick = 0
        self.beeped = _sup["seen"]
        self.bar = self.chat = None
        self.log_text = None
        self.last_pos = None

    # ---------- persiapan: DPI, gaya kontrol Windows modern ----------
    def _setup(self):
        u, W = self.u, self.W
        self.dpi = 96
        try:   # hanya thread ini yang DPI-aware (tajam di layar 125-150%); thread lain (layout jendela browser) tidak berubah
            u.SetThreadDpiAwarenessContext.argtypes, u.SetThreadDpiAwarenessContext.restype = [W.LPVOID], W.LPVOID
            u.SetThreadDpiAwarenessContext(ctypes.c_void_p(-2))   # DPI_AWARENESS_CONTEXT_SYSTEM_AWARE
            self.dpi = u.GetDpiForSystem() or 96
        except Exception:
            pass
        try:   # kontrol versi 6 (tombol & kotak isian bergaya Windows 10/11, teks petunjuk di kotak isian)
            man = os.path.join(BASE, "support-ui.manifest")
            if not os.path.exists(man):
                with open(man, "w", encoding="utf-8") as f:
                    f.write('<?xml version="1.0" encoding="UTF-8" standalone="yes"?><assembly xmlns="urn:schemas-microsoft-com:asm.v1" manifestVersion="1.0">'
                            '<dependency><dependentAssembly><assemblyIdentity type="win32" name="Microsoft.Windows.Common-Controls" version="6.0.0.0" '
                            'processorArchitecture="*" publicKeyToken="6595b64144ccf1df" language="*"/></dependentAssembly></dependency></assembly>')

            class ACTCTXW(ctypes.Structure):
                _fields_ = [("cbSize", W.ULONG), ("dwFlags", W.DWORD), ("lpSource", W.LPCWSTR), ("wProcessorArchitecture", W.USHORT),
                            ("wLangId", W.LANGID), ("lpAssemblyDirectory", W.LPCWSTR), ("lpResourceName", W.LPCWSTR),
                            ("lpApplicationName", W.LPCWSTR), ("hModule", W.HMODULE)]
            ac = ACTCTXW(cbSize=ctypes.sizeof(ACTCTXW), lpSource=man)
            self.k.CreateActCtxW.argtypes, self.k.CreateActCtxW.restype = [ctypes.POINTER(ACTCTXW)], W.HANDLE
            self.k.ActivateActCtx.argtypes = [W.HANDLE, ctypes.POINTER(ctypes.c_size_t)]
            h = self.k.CreateActCtxW(ctypes.byref(ac))
            if h and h != W.HANDLE(-1).value:
                self.k.ActivateActCtx(h, ctypes.byref(ctypes.c_size_t()))
        except Exception as e:
            log.debug("support: gaya kontrol: %s", e)

    def s(self, v):
        return int(round(v * self.dpi / 96))

    def font(self, pt, bold=False):
        return self.g.CreateFontW(-int(round(pt * self.dpi / 72)), 0, 0, 0, 700 if bold else 400, 0, 0, 0, 1, 0, 0, 5, 0, "Segoe UI")

    def work_area(self):
        r = self.W.RECT()
        if self.u.SystemParametersInfoW(0x30, 0, ctypes.byref(r), 0):
            return r.left, r.top, r.right, r.bottom
        return 0, 0, 1366, 728

    def register(self, name, proc, cursor):
        self.u.UnregisterClassW(name, self.hinst)          # tombol dijalankan ulang: kelas lama memakai callback lama
        wc = self.WNDCLASSEXW(cbSize=ctypes.sizeof(self.WNDCLASSEXW), style=0, lpfnWndProc=proc, hInstance=self.hinst,
                              hCursor=self.u.LoadCursorW(None, ctypes.c_void_p(cursor)),
                              hbrBackground=self.W.HBRUSH(16), lpszClassName=name)   # COLOR_BTNFACE + 1
        if not self.u.RegisterClassExW(ctypes.byref(wc)) and ctypes.get_last_error() != 1410:   # 1410 = kelas sudah ada
            raise ctypes.WinError(ctypes.get_last_error())

    def run(self):
        u = self.u
        self._setup()
        self.f_bar, self.f_ui = self.font(10, True), self.font(10)
        self._bar_proc, self._chat_proc = self.WNDPROC(self.bar_proc), self.WNDPROC(self.chat_proc)   # disimpan: jangan sampai dibuang GC
        self.register("GateMonSupportBar", self._bar_proc, 32649)     # IDC_HAND
        self.register("GateMonSupportChat", self._chat_proc, 32512)   # IDC_ARROW
        bw, bh = self.s(self.CHAT_W + self.GAP + self.SOS_W), self.s(self.BAR_H)
        # WS_EX_TOPMOST | TOOLWINDOW (tanpa ikon taskbar) | NOACTIVATE (tidak mengambil fokus) | LAYERED (sudut transparan)
        self.bar = u.CreateWindowExW(0x8 | 0x80 | 0x08000000 | 0x80000, "GateMonSupportBar", "GateMon Support", 0x80000000,
                                     0, 0, bw, bh, None, None, self.hinst, None)
        if not self.bar:
            raise ctypes.WinError(ctypes.get_last_error())
        u.SetLayeredWindowAttributes(self.bar, self.KEY, 235, 0x1 | 0x2)   # LWA_COLORKEY | LWA_ALPHA
        self.make_chat()
        _sup_hwnd[0] = self.bar
        u.SetTimer(self.bar, 1, 500, None)
        # Ctrl+Alt+S: buka/tutup jendela chat (juga memunculkan tombol), berlaku di mana saja
        if not u.RegisterHotKey(self.bar, 1, 0x1 | 0x2 | 0x4000, 0x53):       # MOD_ALT | MOD_CONTROL | MOD_NOREPEAT, "S"
            log.info("support: shortcut Ctrl+Alt+S sudah dipakai program lain")
        _sup["err"] = ""
        self.refresh()
        msg = self.W.MSG()
        while u.GetMessageW(ctypes.byref(msg), None, 0, 0) > 0:
            if self.chat and u.IsDialogMessageW(self.chat, ctypes.byref(msg)):   # Enter = kirim, Esc = tutup, Tab
                continue
            u.TranslateMessage(ctypes.byref(msg))
            u.DispatchMessageW(ctypes.byref(msg))

    def make_chat(self):
        u, s = self.u, self.s
        cw, ch = s(380), s(470)
        style, ex = 0x00C00000 | 0x00080000 | 0x00020000, 0x8   # CAPTION | SYSMENU | MINIMIZEBOX, TOPMOST
        r = self.W.RECT(0, 0, cw, ch)
        u.AdjustWindowRectEx(ctypes.byref(r), style, False, ex)
        self.chat_size = (r.right - r.left, r.bottom - r.top)
        self.chat = u.CreateWindowExW(ex, "GateMonSupportChat", "Chat Tim Support - " + socket.gethostname(), style,
                                      0, 0, self.chat_size[0], self.chat_size[1], None, None, self.hinst, None)
        p, m = s(10), s(8)
        mk = lambda cls, text, st, exs, x, y, w, h, cid: u.CreateWindowExW(exs, cls, text, 0x40000000 | 0x10000000 | st, x, y, w, h,
                                                                          self.chat, self.W.HMENU(cid), self.hinst, None)
        self.h_log = mk("EDIT", "", 0x00200000 | 0x4 | 0x800 | 0x40, 0x200, p, p, cw - 2 * p, ch - s(100), self.ID_LOG)   # VSCROLL MULTILINE READONLY AUTOVSCROLL
        self.h_status = mk("STATIC", "", 0, 0, p, ch - s(86), cw - 2 * p, s(20), self.ID_STATUS)
        self.h_input = mk("EDIT", "", 0x00010000 | 0x80, 0x200, p, ch - s(60), cw - 2 * p - s(90) - m, s(30), self.ID_INPUT)   # TABSTOP AUTOHSCROLL
        self.h_send = mk("BUTTON", "Kirim", 0x00010000 | 0x1, 0, cw - p - s(90), ch - s(60), s(90), s(30), self.ID_SEND)  # DEFPUSHBUTTON
        mk("STATIC", "Enter = kirim  ·  Esc = tutup  ·  Darurat: tombol merah", 0, 0, p, ch - s(24), cw - 2 * p, s(18), 13)
        for h in (self.h_log, self.h_status, self.h_input, self.h_send, u.GetDlgItem(self.chat, 13)):
            u.SendMessageW(h, 0x30, self.f_ui or 0, 1)                           # WM_SETFONT
        u.SendMessageW(self.h_input, 0xC5, 500, 0)                               # EM_SETLIMITTEXT
        cue = ctypes.create_unicode_buffer("Tulis pesan untuk Tim Support…")
        self._cue = cue
        u.SendMessageW(self.h_input, 0x1501, 1, ctypes.addressof(cue))           # EM_SETCUEBANNER

    # ---------- tampilan ----------
    def buttons(self):
        n = sup_unread()
        if n:
            chat = ("Pesan baru (%d)" % n, self.C["amber"] if self.blink else self.C["amber2"])
        else:
            chat = ("Chat Support", self.C["blue"])
        e = _sup["emg"]
        sos = {"sending": ("MENGIRIM…", self.C["red2"] if self.blink else self.C["red"]),
               "open": ("TERKIRIM ✓", self.C["red"] if self.blink else self.C["red2"]),
               "ack": ("DITANGANI ✓", self.C["green"])}.get(e, ("DARURAT", self.C["red"]))
        s = self.s
        return [(0, s(self.CHAT_W)) + chat, (s(self.CHAT_W + self.GAP), s(self.CHAT_W + self.GAP + self.SOS_W)) + sos]

    def paint_bar(self, hwnd):
        u, g = self.u, self.g
        ps = self.PAINTSTRUCT()
        hdc = u.BeginPaint(hwnd, ctypes.byref(ps))
        h = self.s(self.BAR_H)
        full = self.W.RECT(0, 0, self.s(self.CHAT_W + self.GAP + self.SOS_W), h)
        key = g.CreateSolidBrush(self.KEY)
        u.FillRect(hdc, ctypes.byref(full), key)
        g.DeleteObject(key)
        g.SetBkMode(hdc, 1)                                                      # TRANSPARENT
        g.SetTextColor(hdc, 0xFFFFFF)
        old_font = g.SelectObject(hdc, self.f_bar)
        for x1, x2, text, color in self.buttons():
            br, pen = g.CreateSolidBrush(color), g.CreatePen(0, 1, color)
            ob, op = g.SelectObject(hdc, br), g.SelectObject(hdc, pen)
            g.RoundRect(hdc, x1, 0, x2, h, h, h)
            g.SelectObject(hdc, ob), g.SelectObject(hdc, op)
            g.DeleteObject(br), g.DeleteObject(pen)
            r = self.W.RECT(x1, 0, x2, h)
            u.DrawTextW(hdc, text, -1, ctypes.byref(r), 0x1 | 0x4 | 0x20)       # CENTER | VCENTER | SINGLELINE
        g.SelectObject(hdc, old_font)
        u.EndPaint(hwnd, ctypes.byref(ps))

    def place(self, force=False):
        u, s = self.u, self.s
        l, t, r, b = self.work_area()
        bw, bh = s(self.CHAT_W + self.GAP + self.SOS_W), s(self.BAR_H)
        x, y = r - bw - s(self.MARGIN), b - bh - s(self.MARGIN)
        u.SetWindowPos(self.bar, self.W.HWND(-1), x, y, bw, bh, 0x10 | 0x40)    # HWND_TOPMOST, NOACTIVATE | SHOWWINDOW
        if force or self.last_pos != (x, y):
            self.last_pos = (x, y)
            cw, chh = self.chat_size
            u.SetWindowPos(self.chat, self.W.HWND(-1), max(l, r - cw - s(self.MARGIN)), max(t, y - chh - s(8)), 0, 0, 0x1 | 0x10)  # NOSIZE | NOACTIVATE

    def transcript(self):
        with _sup_lock:
            msgs, pend = list(_sup["msgs"]), [m for m in _sup_out if m["kind"] == "chat"]
        out = []
        for m in msgs:
            who = {"agent": "Anda", "system": "Info"}.get(m["sender"], "Support" + (" (%s)" % m["author"] if m.get("author") else ""))
            body = str(m.get("body", ""))
            if m.get("kind") == "emergency" and not body.upper().startswith("DARURAT"):
                body = "[DARURAT] " + body
            out.append("%s  %s:\r\n%s" % (str(m.get("created_at", ""))[11:16], who, body.replace("\r", "").replace("\n", "\r\n")))
        for m in pend:
            out.append("…  Anda (mengirim):\r\n" + m["text"].replace("\n", "\r\n"))
        if not out:
            out.append("Belum ada pesan.\r\nTulis pesan untuk Tim Support di bawah, lalu tekan Enter.")
        return "\r\n\r\n".join(out)

    def refresh(self):
        u = self.u
        if not _sup["on"]:
            u.ShowWindow(self.bar, 0)
            u.ShowWindow(self.chat, 0)
            _sup["visible"] = False
            return
        self.place()
        u.InvalidateRect(self.bar, None, False)
        n = sup_unread()
        if _sup["max"] > self.beeped and n and not _sup["visible"]:
            u.MessageBeep(0x40)                                                  # pesan baru dari support (jendela chat tertutup)
        self.beeped = max(self.beeped, _sup["max"])
        if _sup["visible"]:
            txt = self.transcript()
            if txt != self.log_text:
                self.log_text = txt
                u.SetWindowTextW(self.h_log, txt)
                u.SendMessageW(self.h_log, 0xB1, len(txt), len(txt))               # EM_SETSEL ke akhir
                u.SendMessageW(self.h_log, 0xB7, 0, 0)                             # EM_SCROLLCARET
            st = _sup["status"]
            if _sup["emg"] == "open":
                st = "DARURAT terkirim, menunggu support…" + ("" if st.startswith("Darurat") or not st else "  ·  " + st)
            elif _sup["emg"] == "ack":
                st = "DARURAT ditangani, support menuju lokasi."
            u.SetWindowTextW(self.h_status, st)

    def toggle_chat(self):
        u = self.u
        if _sup["visible"]:
            self.hide_chat()
            return
        self.place(True)
        _sup["visible"] = True
        with _sup_lock:
            if _sup["seen"] < _sup["max"]:
                _sup["seen"] = _sup["max"]
                _sup_save()
        self.log_text = None
        self.refresh()
        u.ShowWindow(self.chat, 5)                                               # SW_SHOW
        u.SetForegroundWindow(self.chat)
        u.SetFocus(self.h_input)
        _sup_wake.set()                                                          # ambil pesan terbaru + tandai dibaca

    def hide_chat(self):
        _sup["visible"] = False
        self.u.ShowWindow(self.chat, 0)
        self.u.InvalidateRect(self.bar, None, False)

    def send_input(self):
        u = self.u
        n = u.GetWindowTextLengthW(self.h_input)
        buf = ctypes.create_unicode_buffer(n + 1)
        u.GetWindowTextW(self.h_input, buf, n + 1)
        text = buf.value.strip()
        if text:
            sup_send("chat", text)
            u.SetWindowTextW(self.h_input, "")
        u.SetFocus(self.h_input)

    def emergency(self):
        if _sup["emg"] == "sending":
            return
        log.warning("tombol DARURAT ditekan")
        self.u.MessageBeep(0x30)
        sup_send("emergency")

    # ---------- pesan jendela ----------
    def bar_proc(self, hwnd, msg, wp, lp):
        try:
            if msg == 0x21:                                                      # WM_MOUSEACTIVATE: jangan ambil fokus
                return 3                                                         # MA_NOACTIVATE
            if msg == 0x0F:                                                      # WM_PAINT
                self.paint_bar(hwnd)
                return 0
            if msg == 0x202:                                                     # WM_LBUTTONUP
                x = ctypes.c_short(lp & 0xFFFF).value
                if x < self.s(self.CHAT_W):
                    self.toggle_chat()
                elif x >= self.s(self.CHAT_W + self.GAP):
                    self.emergency()
                return 0
            if msg == 0x113:                                                     # WM_TIMER: kedip + tetap paling atas
                self.tick += 1
                self.blink = not self.blink
                if self.tick % 4 == 0 or sup_unread() or _sup["emg"] in ("sending", "open"):
                    self.refresh()
                return 0
            if msg == 0x8001:                                                    # WM_APP+1 dari thread lain
                self.refresh()
                return 0
            if msg == 0x8002:                                                    # WM_APP+2: server minta jendela chat dibuka
                if _sup["on"]:
                    if _sup["visible"]:
                        self.u.SetForegroundWindow(self.chat)
                    else:
                        self.toggle_chat()
                return 0
            if msg == 0x312:                                                     # WM_HOTKEY Ctrl+Alt+S
                if _sup["on"]:
                    self.toggle_chat()
                return 0
            if msg == 0x10:                                                      # WM_CLOSE: tombol tidak bisa ditutup
                return 0
        except Exception:
            log.exception("support: tombol")
        return self.u.DefWindowProcW(hwnd, msg, wp, lp)

    def chat_proc(self, hwnd, msg, wp, lp):
        try:
            if msg == 0x111:                                                     # WM_COMMAND
                cid = wp & 0xFFFF
                if cid == self.ID_SEND:
                    self.send_input()
                    return 0
                if cid == self.ID_CANCEL:
                    self.hide_chat()
                    return 0
            if msg == 0x138 and lp == (self.h_log or 0):                         # WM_CTLCOLORSTATIC: riwayat chat berlatar putih
                self.g.SetBkColor(wp, 0xFFFFFF)
                return self.g.GetStockObject(0) or 0                             # WHITE_BRUSH
            if msg == 0x10:                                                      # WM_CLOSE: sembunyikan saja
                self.hide_chat()
                return 0
        except Exception:
            log.exception("support: jendela chat")
        return self.u.DefWindowProcW(hwnd, msg, wp, lp)


# ---------- layar live (agent >= 1.14) ----------
# Saat supervisor membuka "Layar live" di dashboard, heartbeat membawa live.on=1: agent mengirim gambar layar (JPEG
# kecil, 1-2 per detik, hanya bila berubah) ke api/live.php sampai server menjawab on=0 (penonton pergi). Selama itu
# tanda "Layar sedang dilihat Tim IT" tampil di tengah atas layar: tidak mengambil fokus, bisa ditembus klik mouse,
# dan tidak ikut terekam. Koneksi dari laptop ke server (sama seperti heartbeat), jadi tetap jalan bila server di hosting.
LIVE = requests.Session()
LIVE.headers.update({"X-Device-Key": CFG["device_key"], "X-Device-Token": CFG["token"]})
_live = {"cfg": {}, "thread": None, "badge": None, "show": False}


def live_sync(s):
    """Dari heartbeat: mulai mengirim bila ada penonton. Berhenti diputuskan worker dari jawaban api/live.php."""
    if not (s.get("on") and IS_WIN):
        return
    _live["cfg"] = s
    t = _live["thread"]
    if not (t and t.is_alive()):
        _live["thread"] = threading.Thread(target=live_worker, daemon=True)
        _live["thread"].start()


def _live_grab(max_w, quality):
    from PIL import ImageGrab
    img = ImageGrab.grab()
    if img.width > max_w:
        img = img.resize((max_w, max(1, round(img.height * max_w / img.width))))
    buf = io.BytesIO()
    img.convert("RGB").save(buf, format="JPEG", quality=quality)
    return buf.getvalue()


def live_worker():
    log.info("layar live mulai (dilihat %s)", _live["cfg"].get("by", ""))
    _live["show"] = True
    if IS_WIN and not (_live["badge"] and _live["badge"].is_alive()):
        _live["badge"] = threading.Thread(target=_live_badge_main, daemon=True)
        _live["badge"].start()
    last, sent_at, fails = None, 0, 0
    try:
        while True:
            cfg = _live["cfg"]
            t0 = time.time()
            fps = max(0.5, min(4.0, float(cfg.get("fps", 2) or 2)))
            headers, data = {"Content-Type": "image/jpeg"}, b""
            try:
                jpg = _live_grab(max(320, min(3840, int(cfg.get("w", 1280)))), max(30, min(90, int(cfg.get("q", 60)))))
                h = hashlib.md5(jpg).digest()
                if h != last or t0 - sent_at > 15:          # layar sama: cukup tanda masih berjalan (body kosong)
                    data, last = jpg, h
            except Exception as e:                          # mis. layar kunci Windows / UAC
                headers["X-Live-Err"] = ("layar tidak bisa diambil (%s)" % e)[:180]
                last = None
            try:
                r = LIVE.post(f"{SERVER}/api/live.php", data=data, headers=headers, timeout=15)
                j = r.json()
                fails = 0
                if data:
                    sent_at = t0
                if not j.get("on"):
                    break
                _live["cfg"] = j
            except Exception as e:
                fails += 1
                log.debug("layar live: %s", e)
                if fails >= 20:                             # server tidak terjangkau: berhenti, heartbeat memulai lagi bila perlu
                    break
                time.sleep(2)
                continue
            time.sleep(max(0.05, 1.0 / fps - (time.time() - t0)))
    finally:
        _live["show"] = False
        log.info("layar live selesai")


def _live_badge_main():
    try:
        LiveBadge().run()
    except Exception:
        log.exception("tanda layar live gagal tampil")


class LiveBadge:
    """Tanda merah kecil di tengah atas layar selama layar live dilihat."""
    W_, H_ = 360, 30

    def __init__(self):
        import ctypes.wintypes as W
        self.W = W
        u = self.u = ctypes.WinDLL("user32", use_last_error=True)
        g = self.g = ctypes.WinDLL("gdi32")
        P, I, LR = ctypes.POINTER, ctypes.c_int, ctypes.c_ssize_t
        self.WNDPROC = ctypes.WINFUNCTYPE(LR, W.HWND, W.UINT, W.WPARAM, W.LPARAM)

        class WNDCLASSEXW(ctypes.Structure):
            _fields_ = [("cbSize", W.UINT), ("style", W.UINT), ("lpfnWndProc", self.WNDPROC), ("cbClsExtra", I), ("cbWndExtra", I),
                        ("hInstance", W.HINSTANCE), ("hIcon", W.HICON), ("hCursor", W.HANDLE), ("hbrBackground", W.HBRUSH),
                        ("lpszMenuName", W.LPCWSTR), ("lpszClassName", W.LPCWSTR), ("hIconSm", W.HICON)]

        class PAINTSTRUCT(ctypes.Structure):
            _fields_ = [("hdc", W.HDC), ("fErase", W.BOOL), ("rcPaint", W.RECT), ("fRestore", W.BOOL), ("fIncUpdate", W.BOOL), ("rgb", ctypes.c_byte * 32)]
        self.WNDCLASSEXW, self.PAINTSTRUCT = WNDCLASSEXW, PAINTSTRUCT
        for fn, args, res in [
            ("DefWindowProcW", [W.HWND, W.UINT, W.WPARAM, W.LPARAM], LR), ("RegisterClassExW", [P(WNDCLASSEXW)], W.ATOM),
            ("UnregisterClassW", [W.LPCWSTR, W.HINSTANCE], W.BOOL),
            ("CreateWindowExW", [W.DWORD, W.LPCWSTR, W.LPCWSTR, W.DWORD, I, I, I, I, W.HWND, W.HMENU, W.HINSTANCE, W.LPVOID], W.HWND),
            ("SetWindowPos", [W.HWND, W.HWND, I, I, I, I, W.UINT], W.BOOL), ("ShowWindow", [W.HWND, I], W.BOOL),
            ("IsWindowVisible", [W.HWND], W.BOOL), ("BeginPaint", [W.HWND, P(PAINTSTRUCT)], W.HDC), ("EndPaint", [W.HWND, P(PAINTSTRUCT)], W.BOOL),
            ("FillRect", [W.HDC, P(W.RECT), W.HBRUSH], I), ("DrawTextW", [W.HDC, W.LPCWSTR, I, P(W.RECT), W.UINT], I),
            ("InvalidateRect", [W.HWND, P(W.RECT), W.BOOL], W.BOOL), ("SetLayeredWindowAttributes", [W.HWND, W.COLORREF, W.BYTE, W.DWORD], W.BOOL),
            ("GetMessageW", [P(W.MSG), W.HWND, W.UINT, W.UINT], W.BOOL), ("TranslateMessage", [P(W.MSG)], W.BOOL),
            ("DispatchMessageW", [P(W.MSG)], LR), ("SetTimer", [W.HWND, ctypes.c_size_t, W.UINT, W.LPVOID], ctypes.c_size_t),
            ("SystemParametersInfoW", [W.UINT, W.UINT, W.LPVOID, W.UINT], W.BOOL)]:
            f = getattr(u, fn)
            f.argtypes, f.restype = args, res
        for fn, args, res in [
            ("CreateSolidBrush", [W.COLORREF], W.HBRUSH), ("SelectObject", [W.HDC, W.HGDIOBJ], W.HGDIOBJ), ("DeleteObject", [W.HGDIOBJ], W.BOOL),
            ("SetBkMode", [W.HDC, I], I), ("SetTextColor", [W.HDC, W.COLORREF], W.COLORREF),
            ("CreateFontW", [I, I, I, I, I, W.DWORD, W.DWORD, W.DWORD, W.DWORD, W.DWORD, W.DWORD, W.DWORD, W.DWORD, W.LPCWSTR], W.HFONT)]:
            f = getattr(g, fn)
            f.argtypes, f.restype = args, res
        k = ctypes.WinDLL("kernel32")
        k.GetModuleHandleW.argtypes, k.GetModuleHandleW.restype = [W.LPCWSTR], W.HMODULE
        self.hinst = k.GetModuleHandleW(None)
        self.dpi = 96
        try:   # hanya thread ini yang DPI-aware (tajam di layar 125-150%)
            u.SetThreadDpiAwarenessContext.argtypes, u.SetThreadDpiAwarenessContext.restype = [W.LPVOID], W.LPVOID
            u.SetThreadDpiAwarenessContext(ctypes.c_void_p(-2))
            self.dpi = u.GetDpiForSystem() or 96
        except Exception:
            pass
        self.text = ""

    def s(self, v):
        return int(round(v * self.dpi / 96))

    def run(self):
        u, W = self.u, self.W
        self._proc = self.WNDPROC(self.proc)
        u.UnregisterClassW("GateMonLiveBadge", self.hinst)
        wc = self.WNDCLASSEXW(cbSize=ctypes.sizeof(self.WNDCLASSEXW), lpfnWndProc=self._proc, hInstance=self.hinst, lpszClassName="GateMonLiveBadge")
        u.RegisterClassExW(ctypes.byref(wc))
        self.font = self.g.CreateFontW(-int(round(10 * self.dpi / 72)), 0, 0, 0, 700, 0, 0, 0, 1, 0, 0, 5, 0, "Segoe UI")
        # TOPMOST | TOOLWINDOW | NOACTIVATE | LAYERED | TRANSPARENT (klik mouse menembus ke halaman gate di bawahnya)
        self.hwnd = u.CreateWindowExW(0x8 | 0x80 | 0x08000000 | 0x80000 | 0x20, "GateMonLiveBadge", "GateMon Live", 0x80000000,
                                      0, 0, self.s(self.W_), self.s(self.H_), None, None, self.hinst, None)
        if not self.hwnd:
            raise ctypes.WinError(ctypes.get_last_error())
        u.SetLayeredWindowAttributes(self.hwnd, 0, 225, 0x2)                   # LWA_ALPHA
        try:   # tidak ikut terekam di gambar layar live (Windows 10 2004+); versi lama: tanda ikut terlihat penonton
            u.SetWindowDisplayAffinity(ctypes.c_void_p(self.hwnd), 0x11)       # WDA_EXCLUDEFROMCAPTURE
        except Exception:
            pass
        u.SetTimer(self.hwnd, 1, 500, None)
        msg = W.MSG()
        while u.GetMessageW(ctypes.byref(msg), None, 0, 0) > 0:
            u.TranslateMessage(ctypes.byref(msg))
            u.DispatchMessageW(ctypes.byref(msg))

    def tick(self):
        u = self.u
        if not _live["show"]:
            if u.IsWindowVisible(self.hwnd):
                u.ShowWindow(self.hwnd, 0)
            return
        by = str(_live["cfg"].get("by") or "")
        text = "●  Layar sedang dilihat Tim IT" + (" (%s)" % by[:20] if by else "")
        if text != self.text:
            self.text = text
            u.InvalidateRect(self.hwnd, None, True)
        r = self.W.RECT()
        u.SystemParametersInfoW(0x30, 0, ctypes.byref(r), 0)                     # area kerja (tanpa taskbar)
        w, h = self.s(self.W_), self.s(self.H_)
        u.SetWindowPos(self.hwnd, self.W.HWND(-1), r.left + (r.right - r.left - w) // 2, r.top + self.s(4), w, h, 0x10 | 0x40)   # TOPMOST, NOACTIVATE|SHOWWINDOW

    def paint(self):
        u, g = self.u, self.g
        ps = self.PAINTSTRUCT()
        hdc = u.BeginPaint(self.hwnd, ctypes.byref(ps))
        rc = self.W.RECT(0, 0, self.s(self.W_), self.s(self.H_))
        br = g.CreateSolidBrush(0x1C1CB9)                                       # merah (#b91c1c)
        u.FillRect(hdc, ctypes.byref(rc), br)
        g.DeleteObject(br)
        g.SetBkMode(hdc, 1)
        g.SetTextColor(hdc, 0xFFFFFF)
        old = g.SelectObject(hdc, self.font)
        u.DrawTextW(hdc, self.text, -1, ctypes.byref(rc), 0x1 | 0x4 | 0x20 | 0x8000)   # CENTER|VCENTER|SINGLELINE|END_ELLIPSIS
        g.SelectObject(hdc, old)
        u.EndPaint(self.hwnd, ctypes.byref(ps))

    def proc(self, hwnd, msg, wp, lp):
        try:
            if msg == 0x113:                                                     # WM_TIMER
                self.tick()
                return 0
            if msg == 0x0F:                                                      # WM_PAINT
                self.paint()
                return 0
            if msg == 0x21:                                                      # WM_MOUSEACTIVATE
                return 3                                                         # MA_NOACTIVATE
            if msg == 0x10:                                                      # WM_CLOSE: tidak bisa ditutup
                return 0
        except Exception:
            log.exception("tanda layar live")
        return self.u.DefWindowProcW(hwnd, msg, wp, lp)


# ---------- pindah server (agent >= 1.13) ----------
# Perintah SET_SERVER dari server lama: agent menguji dulu server baru dengan ID + token-nya (api/ping.php, harus
# sudah berisi database yang sama). Berhasil -> hasil dilaporkan ke server LAMA, lalu config.json diganti. Server lama
# disimpan sebagai cadangan: bila server baru tidak pernah berhasil dihubungi dalam 10 menit, agent kembali ke server lama.
MOVE_WAIT = 600


def _cfg_save():
    tmp = CFG_PATH + ".tmp"
    with open(tmp, "w", encoding="utf-8") as fh:
        json.dump(CFG, fh, indent=2)
    os.replace(tmp, CFG_PATH)


def server_url_ok(u):
    """https://host[/path] untuk server internet; http:// hanya untuk IP LAN (tanpa enkripsi tidak aman lewat internet)."""
    try:
        p = urlparse(u)
    except ValueError:
        return False
    if p.scheme not in ("http", "https") or not p.hostname or p.username or p.password or p.query or p.fragment:
        return False
    if p.scheme == "http":
        try:
            return ipaddress.ip_address(p.hostname).is_private
        except ValueError:
            return False
    return True


def set_server_check(payload):
    try:
        url = json.loads(payload).get("url", "")
    except (ValueError, AttributeError):
        url = payload
    url = str(url or "").strip().rstrip("/")
    if not server_url_ok(url):
        raise RuntimeError("alamat server tidak valid: wajib https://... (http:// hanya untuk IP LAN)")
    if url == SERVER:
        raise RuntimeError("laptop ini sudah memakai server tersebut")
    s = requests.Session()
    s.headers.update(HEADERS)
    try:
        r = s.get(url + "/api/ping.php", timeout=20, allow_redirects=False)
    except requests.exceptions.SSLError as e:
        raise RuntimeError("sertifikat HTTPS server baru tidak valid: %s" % str(e)[:120])
    except requests.RequestException as e:
        raise RuntimeError("server baru tidak bisa dihubungi dari laptop ini: %s" % str(e)[:120])
    if r.status_code == 401:
        raise RuntimeError("server baru menolak ID/token laptop ini (database belum dipindah ke server baru?)")
    try:
        j = r.json()
    except ValueError:
        j = {}
    if r.status_code != 200 or j.get("app") != "gatemon" or j.get("device") != CFG["device_key"]:
        raise RuntimeError("alamat itu bukan server GateMon yang mengenal laptop ini (HTTP %s)" % r.status_code)
    return url


def set_server_apply(url):
    global SERVER
    CFG["server_url_prev"], CFG["server_url"], CFG["server_moved_at"] = SERVER, url, int(time.time())
    _cfg_save()
    log.warning("pindah server: %s -> %s", SERVER, url)
    SERVER = url


def server_move_ok():
    """Heartbeat ke server baru berhasil: pindah server selesai, cadangan dihapus."""
    if "server_url_prev" in CFG:
        log.info("pindah server selesai, server lama %s tidak dipakai lagi", CFG["server_url_prev"])
        CFG.pop("server_url_prev", None)
        CFG.pop("server_moved_at", None)
        _cfg_save()


def server_move_failed():
    """Heartbeat gagal: bila baru pindah dan server baru belum pernah berhasil selama MOVE_WAIT, kembali ke server lama."""
    global SERVER
    prev = CFG.get("server_url_prev")
    if prev and time.time() - int(CFG.get("server_moved_at", 0)) > MOVE_WAIT:
        log.error("server baru %s tidak bisa dihubungi %d menit: kembali ke %s", SERVER, MOVE_WAIT // 60, prev)
        CFG["server_url"] = prev
        CFG.pop("server_url_prev", None)
        CFG.pop("server_moved_at", None)
        _cfg_save()
        SERVER = prev

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
                server_move_failed()
            else:
                j = r.json()
                if r.status_code == 200:
                    server_move_ok()
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
                if isinstance(j.get("support"), dict):          # tombol Chat Support & DARURAT (server lama tidak mengirim)
                    support_sync(j["support"])
                if isinstance(j.get("live"), dict):             # layar live (server lama tidak mengirim)
                    live_sync(j["live"])
                for cmd in j.get("commands", []):
                    log.info("perintah #%s %s", cmd["id"], cmd["type"])
                    if cmd["type"] == "UPDATE_AGENT":            # hasil dilaporkan thread update sendiri
                        start_update(cmd["id"])
                        continue
                    if cmd["type"] == "SET_SERVER":              # hasil dilaporkan ke server lama SEBELUM pindah
                        try:
                            new = set_server_check(cmd.get("payload") or "")
                        except Exception as e:
                            report(cmd["id"], "failed", str(e))
                            continue
                        report(cmd["id"], "done", "server baru tersambung, laptop pindah ke " + new)
                        set_server_apply(new)
                        break                                    # perintah lain diambil dari server baru
                    try:
                        report(cmd["id"], "done", run_command(cmd))
                    except Exception as e:
                        log.exception("perintah #%s gagal", cmd["id"])
                        report(cmd["id"], "failed", str(e))
                auto_gate(j)
                if "wallpaper" in j:                             # server lama tidak mengirim: wallpaper tidak disentuh
                    wallpaper_sync(j["wallpaper"])
                if "policy" in j:                                # daya & Windows Update (server lama tidak mengirim)
                    policy_sync(j["policy"])
            if _upd["restart"]:
                restart_for_update()                             # hasil update sudah dilaporkan; penjaga mengambil alih
        except Exception as e:
            net_ms = None
            server_move_failed()
            log.warning("heartbeat gagal: %s", e)
        time.sleep(interval)


if __name__ == "__main__":
    main()
