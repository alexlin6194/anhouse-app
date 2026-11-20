#!/usr/bin/env python3
"""
安家生活GO 地端電錶橋接器(離線優先)

三段式設計,對應三種案場:
  A. 有網路      ── 每次讀值即上報,指令即時拉取
  B. 訊號差      ── 有網路就跑 A;斷網時自動進入 C
  C. 完全無網    ── 本地 SQLite 記帳 + 自行扣款 + 自行斷電;
                    房東到現場用手機連本機 Wi-Fi(http://<本機IP>:8787)即可看度數/儲值狀態,
                    等本機恢復網路(4G 天線移位置 / 手機熱點)再把帳本補傳回雲端。

核心原則:
  * 現場是「離線期間唯一的帳務權威」,雲端不得重複扣款(authority=agent)
  * 每筆讀值與帳務都有 uuid,重送一百次也只會入帳一次
  * 沒做的事不假裝:離線時 APP 顯示「最後同步時間」與「目前為離線帳本」

安裝:pip3 install pymodbus requests
執行:python3 bridge.py            讀同目錄 config.json
      python3 bridge.py --dry-run  無硬體,模擬讀值(仍可測完整離線流程)
"""
import json
import os
import sqlite3
import sys
import threading
import time
import uuid
import datetime as dt
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

try:
    import requests
except ImportError:
    sys.exit("缺少 requests:pip3 install requests")

BASE = os.path.dirname(os.path.abspath(__file__))
CFG_PATH = os.path.join(BASE, "config.json")
DB_PATH = os.path.join(BASE, "bridge.db")
DRY = "--dry-run" in sys.argv


def now_str():
    return dt.datetime.now().strftime("%Y-%m-%d %H:%M:%S")


def log(msg):
    print(f"[{dt.datetime.now().strftime('%H:%M:%S')}] {msg}", flush=True)


# ────────────────────────── 本地儲存 ──────────────────────────
class Store:
    def __init__(self, path=DB_PATH):
        self.db = sqlite3.connect(path, check_same_thread=False)
        self.db.row_factory = sqlite3.Row
        self.lock = threading.Lock()
        self.db.executescript("""
        CREATE TABLE IF NOT EXISTS policy (serial TEXT PRIMARY KEY, data TEXT NOT NULL, updated_at TEXT);
        CREATE TABLE IF NOT EXISTS readings (
            uuid TEXT PRIMARY KEY, serial TEXT, reading REAL, read_at TEXT, relay INTEGER,
            synced INTEGER DEFAULT 0, created_at TEXT);
        CREATE TABLE IF NOT EXISTS ledger (
            uuid TEXT PRIMARY KEY, serial TEXT, kind TEXT, usage REAL DEFAULT 0, amount INTEGER DEFAULT 0,
            balance_after INTEGER DEFAULT 0, rate REAL, occurred_at TEXT, topup_id INTEGER,
            synced INTEGER DEFAULT 0);
        CREATE TABLE IF NOT EXISTS kv (k TEXT PRIMARY KEY, v TEXT);
        """)
        self.db.commit()

    def q(self, sql, args=()):
        with self.lock:
            return self.db.execute(sql, args).fetchall()

    def x(self, sql, args=()):
        with self.lock:
            self.db.execute(sql, args)
            self.db.commit()

    # 政策
    def put_policy(self, serial, data):
        self.x("INSERT INTO policy(serial,data,updated_at) VALUES(?,?,?) ON CONFLICT(serial) DO UPDATE SET data=excluded.data, updated_at=excluded.updated_at",
               (serial, json.dumps(data, ensure_ascii=False), now_str()))

    def policy(self, serial):
        r = self.q("SELECT data FROM policy WHERE serial=?", (serial,))
        return json.loads(r[0]["data"]) if r else None

    def policies(self):
        return {r["serial"]: json.loads(r["data"]) for r in self.q("SELECT serial,data FROM policy")}

    def set_kv(self, k, v):
        self.x("INSERT INTO kv(k,v) VALUES(?,?) ON CONFLICT(k) DO UPDATE SET v=excluded.v", (k, str(v)))

    def get_kv(self, k, default=None):
        r = self.q("SELECT v FROM kv WHERE k=?", (k,))
        return r[0]["v"] if r else default

    # 讀值
    def add_reading(self, serial, reading, relay):
        u = str(uuid.uuid4())
        self.x("INSERT INTO readings(uuid,serial,reading,read_at,relay,synced,created_at) VALUES(?,?,?,?,?,0,?)",
               (u, serial, float(reading), now_str(), 1 if relay else 0, now_str()))
        return u

    def unsynced_readings(self, limit=200):
        return self.q("SELECT * FROM readings WHERE synced=0 ORDER BY created_at LIMIT ?", (limit,))

    def unsynced_ledger(self, limit=200):
        return self.q("SELECT * FROM ledger WHERE synced=0 ORDER BY occurred_at LIMIT ?", (limit,))

    # 帳本
    def add_ledger(self, serial, kind, usage=0.0, amount=0, balance_after=0, rate=None, topup_id=None, occurred_at=None, uid=None):
        u = uid or str(uuid.uuid4())
        self.x("""INSERT INTO ledger(uuid,serial,kind,usage,amount,balance_after,rate,occurred_at,topup_id,synced)
                  VALUES(?,?,?,?,?,?,?,?,?,0)""",
               (u, serial, kind, float(usage), int(amount), int(balance_after),
                float(rate) if rate is not None else None, occurred_at or now_str(), topup_id))
        return u

    def last_reading(self, serial):
        r = self.q("SELECT reading FROM readings WHERE serial=? ORDER BY created_at DESC, rowid DESC LIMIT 1", (serial,))
        return float(r[0]["reading"]) if r else None

    def balance(self, serial):
        r = self.q("SELECT balance_after FROM ledger WHERE serial=? ORDER BY occurred_at DESC, rowid DESC LIMIT 1", (serial,))
        return int(r[0]["balance_after"]) if r else None

    def pending(self):
        return {"readings": len(self.unsynced_readings(9999)), "ledger": len(self.unsynced_ledger(9999))}


STORE = Store()


# ────────────────────────── 通訊 ──────────────────────────
class Link:
    """與雲端的連線;失敗不拋錯,只回 ok=False(離線是常態,不是例外)"""

    def __init__(self, cfg):
        self.cfg = cfg
        self.online = None
        self.last_ok = None
        self.session = requests.Session()
        self.session.headers.update({
            "Authorization": "Bearer " + cfg["token"],
            "Origin": cfg.get("origin", "https://itdc.tw"),
            "Referer": cfg.get("origin", "https://itdc.tw") + "/anhouse/devices.html",
            "User-Agent": "anhouse-bridge/" + cfg.get("agent_version", "1.1.0"),
        })

    def call(self, action, payload=None, method=None, timeout=20):
        url = self.cfg["server"]
        try:
            if method == "GET" or payload is None:
                r = self.session.get(url, params={"action": action}, timeout=timeout)
            else:
                body = dict(payload)
                body["agent_version"] = self.cfg.get("agent_version", "1.1.0")
                r = self.session.post(url, params={"action": action}, json=body, timeout=timeout)
            data = r.json()
            if data.get("ok"):
                self.online = True
                self.last_ok = now_str()
            return data
        except Exception as e:
            if self.online is not False:
                log(f"雲端連線失敗({action}):{e} → 進入離線模式")
            self.online = False
            return {"ok": False, "offline": True, "message": str(e)}


# ────────────────────────── Modbus ──────────────────────────
def build_client(transport):
    from pymodbus.client import ModbusSerialClient, ModbusTcpClient
    if transport["type"] == "tcp":
        return ModbusTcpClient(host=transport["host"], port=int(transport.get("port", 502)), timeout=5)
    return ModbusSerialClient(
        port=transport["port"], baudrate=int(transport.get("baudrate", 9600)),
        parity=transport.get("parity", "N"), stopbits=int(transport.get("stopbits", 1)),
        bytesize=int(transport.get("bytesize", 8)), timeout=3)


class Meters:
    def __init__(self, meters_cfg):
        self.cfg = {m["serial"]: m for m in meters_cfg}
        self.clients = {}
        self.fake = {}

    def _client(self, serial):
        if serial not in self.clients:
            self.clients[serial] = build_client(self.cfg[serial]["transport"])
            self.clients[serial].connect()
        return self.clients[serial]

    def read(self, serial):
        m = self.cfg[serial]
        if DRY:
            self.fake[serial] = self.fake.get(serial, 1000.0) + 0.33
            return round(self.fake[serial], 1)
        c = self._client(serial)
        fn = int(m.get("function", 3))
        words = 2 if m.get("words", 2) == 2 else 1
        rr = (c.read_input_registers if fn == 4 else c.read_holding_registers)(
            int(m.get("register", 0)), count=words, slave=int(m.get("unit_id", 1)))
        if rr.isError():
            raise RuntimeError(str(rr))
        regs = rr.registers
        if words == 2:
            hi, lo = (regs[0], regs[1]) if m.get("word_order", "big") == "big" else (regs[1], regs[0])
            raw = (hi << 16) | lo
        else:
            raw = regs[0]
        return round(raw * float(m.get("scale", 1.0)), 1)

    def relay(self, serial, on):
        if DRY:
            return True
        c = self._client(serial)
        return not c.write_coil(int(self.cfg[serial].get("relay_coil", 0)), bool(on), slave=int(self.cfg[serial].get("unit_id", 1))).isError()


# ────────────────────────── 離線計費核心 ──────────────────────────
def apply_policy(pol):
    """把雲端政策寫進本地;有未同步帳本時不覆蓋餘額(否則現場扣的錢會復活)"""
    for m in pol.get("meters", []):
        serial = m["serial"]
        pend = STORE.q("SELECT COUNT(*) c FROM ledger WHERE serial=? AND synced=0", (serial,))[0]["c"]
        if pend == 0:
            STORE.x("INSERT INTO ledger(uuid,serial,kind,balance_after,amount,occurred_at,synced) VALUES(?,?,?,?,0,?,1) "
                    "ON CONFLICT(uuid) DO NOTHING", (f"seed-{serial}", serial, "topup", int(m.get("balance", 0)), now_str()))
        STORE.put_policy(serial, m)
    for c in pol.get("credits", []):
        serial = c["serial"]
        if STORE.q("SELECT 1 FROM ledger WHERE topup_id=?", (c["topup_id"],)):
            continue
        b = STORE.balance(serial) or 0
        STORE.add_ledger(serial, "topup", amount=int(c["amount"]), balance_after=b + int(c["amount"]), topup_id=c["topup_id"])
        log(f"套用離線期間儲值 {serial} +NT${c['amount']}(儲值單 #{c['topup_id']})")


def meter_cycle(meters, link):
    """一輪:讀值 → 離線扣款/斷電 → 上報"""
    policy = STORE.policies()
    for serial in meters.cfg:
        pol = policy.get(serial) or {}
        try:
            reading = meters.read(serial)
        except Exception as e:
            log(f"讀取 {serial} 失敗:{e}")
            meters.clients.pop(serial, None)
            continue

        STORE.add_reading(serial, reading, pol.get("relay", "on") == "on")
        prev = STORE.q("SELECT reading FROM readings WHERE serial=? ORDER BY created_at DESC, rowid DESC LIMIT 2", (serial,))
        if len(prev) >= 2:
            usage = round(float(prev[0]["reading"]) - float(prev[1]["reading"]), 1)
            if usage > 0 and pol.get("mode") == "prepaid":
                rate = float(pol.get("rate") or 5.0)
                balance = STORE.balance(serial)
                if balance is None:
                    balance = int(pol.get("balance", 0))
                cost = int(round(usage * rate))
                balance -= cost
                STORE.add_ledger(serial, "deduct", usage=usage, amount=-cost, balance_after=balance, rate=rate)
                log(f"{serial} 用電 {usage} 度 × {rate} = NT${cost},餘額 NT${balance}")
                if balance <= 0 and pol.get("relay", "on") == "on":
                    ok = meters.relay(serial, False)
                    STORE.add_ledger(serial, "cut", amount=0, balance_after=balance)
                    if ok:
                        pol["relay"] = "off"
                        STORE.put_policy(serial, pol)
                        log(f"{serial} 餘額不足 → 已斷電")
        if link.online is not False:
            sync(meters, link)


def sync(meters, link):
    rd = STORE.unsynced_readings()
    if rd:
        res = link.call("push", {"readings": [{
            "serial": r["serial"], "reading": r["reading"], "read_at": r["read_at"],
            "relay": bool(r["relay"]), "uuid": r["uuid"]} for r in rd]}, "POST")
        if res.get("ok"):
            for r in rd:
                STORE.x("UPDATE readings SET synced=1 WHERE uuid=?", (r["uuid"],))
            log(f"上報讀值 {len(rd)} 筆")
    lg = STORE.unsynced_ledger()
    if lg:
        res = link.call("ledger", {"entries": [{
            "uuid": r["uuid"], "serial": r["serial"], "kind": r["kind"], "usage": r["usage"],
            "amount": r["amount"], "balance_after": r["balance_after"], "rate": r["rate"],
            "occurred_at": r["occurred_at"], "topup_id": r["topup_id"]} for r in lg]}, "POST")
        if res.get("ok"):
            for r in lg:
                STORE.x("UPDATE ledger SET synced=1 WHERE uuid=?", (r["uuid"],))
            log(f"補傳帳本 {len(lg)} 筆(重複 {res.get('data', {}).get('duplicate', 0)})")


def command_cycle(meters, link):
    res = link.call("poll", None, "GET")
    for c in (res.get("data", {}) or {}).get("commands", []) or []:
        serial, cmd = c.get("serial_no"), c["command"]
        ok, msg = False, "找不到此序號"
        if serial in meters.cfg:
            if cmd in ("relay_on", "relay_off"):
                on = cmd == "relay_on"
                ok = meters.relay(serial, on)
                pol = STORE.policy(serial) or {}
                pol["relay"] = "on" if on else "off"
                STORE.put_policy(serial, pol)
                STORE.add_ledger(serial, cmd, balance_after=STORE.balance(serial) or 0)
                msg = "繼電器已" + ("復電" if on else "斷電") if ok else "繼電器寫入失敗"
            else:
                ok, msg = True, "已排入下次讀值"
        link.call("ack", {"command_id": c["id"], "ok": ok, "result": msg}, "POST")
        log(f"指令 #{c['id']} {cmd} {serial} → {msg}")


# ────────────────────────── 現場區網服務(給手機 App 直連)──────────────────────────
def make_lan_handler(meters, link, cfg):
    class H(BaseHTTPRequestHandler):
        def _json(self, obj, code=200):
            body = json.dumps(obj, ensure_ascii=False).encode()
            self.send_response(code)
            self.send_header("Content-Type", "application/json; charset=utf-8")
            self.send_header("Content-Length", str(len(body)))
            self.end_headers()
            self.wfile.write(body)

        def log_message(self, *a):
            pass

        def _auth(self):
            tok = cfg.get("lan_token", "")
            if not tok:
                return True
            q = self.path.split("token=")[-1].split("&")[0] if "token=" in self.path else ""
            return q == tok

        def do_GET(self):
            if not self._auth():
                return self._json({"ok": False, "message": "token 不正確"}, 403)
            if self.path.startswith("/status"):
                pol = STORE.policies()
                out = []
                for serial in meters.cfg:
                    p = pol.get(serial) or {}
                    out.append({
                        "serial": serial, "name": p.get("name"),
                        "reading": STORE.last_reading(serial),
                        "balance": STORE.balance(serial),
                        "mode": p.get("mode"), "rate": p.get("rate"),
                        "relay": p.get("relay", "on"),
                    })
                return self._json({
                    "ok": True, "bridge": cfg.get("name", "現場橋接器"),
                    "online": bool(link.online), "last_sync": link.last_ok,
                    "pending": STORE.pending(), "meters": out,
                })
            if self.path.startswith("/ledger"):
                rows = STORE.q("SELECT kind,usage,amount,balance_after,occurred_at,serial,synced FROM ledger ORDER BY occurred_at DESC LIMIT 80")
                return self._json({"ok": True, "rows": [dict(r) for r in rows]})
            return self._json({"ok": False, "message": "not found"}, 404)

        def do_POST(self):
            if not self._auth():
                return self._json({"ok": False, "message": "token 不正確"}, 403)
            n = int(self.headers.get("Content-Length", 0))
            try:
                body = json.loads(self.rfile.read(n) or b"{}")
            except Exception:
                return self._json({"ok": False, "message": "JSON 格式錯誤"}, 400)
            if self.path.startswith("/relay"):
                serial, on = body.get("serial"), bool(body.get("on"))
                if serial not in meters.cfg:
                    return self._json({"ok": False, "message": "沒有這顆電錶"}, 404)
                ok = meters.relay(serial, on)
                p = STORE.policy(serial) or {}
                p["relay"] = "on" if on else "off"
                STORE.put_policy(serial, p)
                STORE.add_ledger(serial, "relay_on" if on else "relay_off", balance_after=STORE.balance(serial) or 0)
                return self._json({"ok": ok, "message": "已" + ("復電" if on else "斷電") if ok else "繼電器寫入失敗", "queued_for_sync": True})
            return self._json({"ok": False, "message": "not found"}, 404)

    return H


def lan_server(meters, link, cfg):
    port = int(cfg.get("lan_port", 8787))
    srv = ThreadingHTTPServer(("0.0.0.0", port), make_lan_handler(meters, link, cfg))
    threading.Thread(target=srv.serve_forever, daemon=True).start()
    log(f"現場區網服務啟動:http://0.0.0.0:{port}/status(手機連同一 Wi-Fi 即可查看)")


def main():
    if not os.path.isfile(CFG_PATH):
        sys.exit(f"找不到 {CFG_PATH}")
    cfg = json.load(open(CFG_PATH, encoding="utf-8"))
    meters, link = Meters(cfg["meters"]), Link(cfg)
    push_s, poll_s = int(cfg.get("push_seconds", 300)), int(cfg.get("poll_seconds", 60))
    log(f"橋接器啟動 {'DRY-RUN' if DRY else 'Modbus'} / server={cfg['server']}")

    if cfg.get("lan_enabled", True):
        lan_server(meters, link, cfg)

    hello = link.call("hello", {})
    if hello.get("ok"):
        pol = link.call("policy", None, "GET")
        if pol.get("ok"):
            apply_policy(pol["data"])
        log(f"已連上雲端,電錶 {len((hello.get('data') or {}).get('meters', []))} 顆")

    last_poll = last_push = 0
    while True:
        if time.time() - last_push >= push_s:
            meter_cycle(meters, link)
            last_push = time.time()
        if link.online and time.time() - last_poll >= poll_s:
            command_cycle(meters, link)
            last_poll = time.time()
        elif link.online is False and time.time() - last_poll >= 300:
            # 離線時每 5 分鐘試一次重連(含政策與儲值補套用)
            r = link.call("policy", None, "GET")
            if r.get("ok"):
                apply_policy(r["data"])
            last_poll = time.time()
        time.sleep(5)


if __name__ == "__main__":
    try:
        main()
    except KeyboardInterrupt:
        print("\n已停止")
