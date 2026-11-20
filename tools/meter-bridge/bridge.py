#!/usr/bin/env python3
"""
安家生活GO 地端電錶橋接器(Modbus RTU / TCP)

現場小主機(樹莓派 / 工控機 / 公司電腦)執行這一支,它會:
  1. 依 config.json 用 Modbus 讀每顆電錶的累計度數
  2. 批次 POST 到 /api/bridge.php?action=push(推讀值)
  3. 定期 GET  ?action=poll 拉指令(relay_on / relay_off / read_now)並執行、回報 ack

不需要在現場開 Port、不需要固定 IP —— 全部由現場主動往外連。

安裝:pip3 install pymodbus requests
執行:python3 bridge.py            (讀同目錄 config.json)
      python3 bridge.py --dry-run  (無硬體也能測整條連線)
"""
import json
import os
import sys
import time
import datetime as dt

try:
    import requests
except ImportError:
    sys.exit("缺少 requests:pip3 install requests")

CFG_PATH = os.path.join(os.path.dirname(os.path.abspath(__file__)), "config.json")


def load_cfg():
    if not os.path.isfile(CFG_PATH):
        sys.exit(f"找不到 {CFG_PATH}(請複製 config.example.json 改名為 config.json 並填入金鑰)")
    with open(CFG_PATH, "r", encoding="utf-8") as f:
        return json.load(f)


def http(cfg, action, payload=None, method=None):
    url = cfg["server"]
    headers = {
        "Authorization": "Bearer " + cfg["token"],
        # 主機端會檢查來源(CSRF 防護),對外 API 以站台本身作為來源即可
        "Origin": cfg.get("origin", "https://itdc.tw"),
        "Referer": cfg.get("origin", "https://itdc.tw") + "/anhouse/devices.html",
        "User-Agent": "anhouse-bridge/" + cfg.get("agent_version", "1.0.0"),
    }
    params = {"action": action}
    try:
        if method == "GET" or payload is None:
            r = requests.get(url, params=params, headers=headers, timeout=30)
        else:
            body = dict(payload)
            body["agent_version"] = cfg.get("agent_version", "1.0.0")
            r = requests.post(url, params=params, headers=headers, json=body, timeout=30)
        data = r.json()
        if not data.get("ok"):
            print(f"[{now()}] {action} 失敗:{data.get('message')}")
        return data
    except Exception as e:
        print(f"[{now()}] {action} 連線錯誤:{e}")
        return {"ok": False}


def now():
    return dt.datetime.now().strftime("%H:%M:%S")


def build_client(transport):
    from pymodbus.client import ModbusSerialClient, ModbusTcpClient
    if transport["type"] == "tcp":
        return ModbusTcpClient(host=transport["host"], port=int(transport.get("port", 502)), timeout=5)
    return ModbusSerialClient(
        port=transport["port"],
        baudrate=int(transport.get("baudrate", 9600)),
        parity=transport.get("parity", "N"),
        stopbits=int(transport.get("stopbits", 1)),
        bytesize=int(transport.get("bytesize", 8)),
        timeout=3,
    )


def read_reading(client, meter):
    """讀累計度數。支援 32 位元(兩個 register)與 16 位元。"""
    fn = int(meter.get("function", 3))
    unit = int(meter.get("unit_id", 1))
    addr = int(meter.get("register", 0))
    words = 2 if meter.get("words", 2) == 2 else 1
    if fn == 4:
        rr = client.read_input_registers(addr, count=words, slave=unit)
    else:
        rr = client.read_holding_registers(addr, count=words, slave=unit)
    if rr.isError():
        raise RuntimeError(str(rr))
    regs = rr.registers
    if words == 2:
        hi, lo = (regs[0], regs[1]) if meter.get("word_order", "big") == "big" else (regs[1], regs[0])
        raw = (hi << 16) | lo
    else:
        raw = regs[0]
    return raw * float(meter.get("scale", 1.0))


def write_relay(client, meter, on):
    unit = int(meter.get("unit_id", 1))
    coil = int(meter.get("relay_coil", 0))
    rr = client.write_coil(coil, bool(on), slave=unit)
    return not rr.isError()


def main():
    cfg = load_cfg()
    dry = "--dry-run" in sys.argv or bool(cfg.get("dry_run"))
    poll_s = int(cfg.get("poll_seconds", 60))
    push_s = int(cfg.get("push_seconds", 300))
    print(f"[{now()}] 橋接器啟動,server={cfg['server']} 模式={'DRY-RUN' if dry else 'Modbus'}")

    hello = http(cfg, "hello", {})
    if hello.get("ok"):
        print(f"[{now()}] 已連上系統,房源 {hello['bridge'].get('property_id')},電錶 {len(hello.get('meters', []))} 顆")
    fake = {}
    clients = {}
    last_push = 0.0
    last_poll = 0.0

    while True:
        # ── 讀值並上報 ──
        if time.time() - last_push >= push_s:
            items = []
            for m in cfg["meters"]:
                serial = m["serial"]
                try:
                    if dry:
                        fake[serial] = fake.get(serial, 1000.0) + 0.32
                        val = fake[serial]
                        relay = True
                    else:
                        key = serial
                        if key not in clients:
                            clients[key] = build_client(m["transport"])
                            clients[key].connect()
                        val = read_reading(clients[key], m)
                        relay = True
                    items.append({
                        "serial": serial,
                        "reading": round(val, 1),
                        "read_at": dt.datetime.now().strftime("%Y-%m-%d %H:%M:%S"),
                        "relay": relay,
                    })
                    print(f"[{now()}] 讀取 {serial} = {val:.1f} 度")
                except Exception as e:
                    print(f"[{now()}] 讀取 {serial} 失敗:{e}")
                    clients.pop(serial, None)
            if items:
                res = http(cfg, "push", {"readings": items})
                if res.get("ok"):
                    d = res.get("data", {})
                    print(f"[{now()}] 上報 {d.get('accepted', 0)} 筆(忽略 {d.get('ignored', 0)}),未知序號 {d.get('unknown_serials') or '無'}")
            last_push = time.time()

        # ── 拉指令 ──
        if time.time() - last_poll >= poll_s:
            res = http(cfg, "poll", None, method="GET")
            for c in (res.get("data", {}) or {}).get("commands", []) or []:
                serial = c.get("serial_no")
                meter = next((m for m in cfg["meters"] if m["serial"] == serial), None)
                ok, msg = False, "找不到此序號的設定"
                if meter is None:
                    pass
                elif dry:
                    ok, msg = True, "dry-run 模擬執行"
                elif c["command"] in ("relay_on", "relay_off"):
                    try:
                        ok = write_relay(clients.setdefault(serial, build_client(meter["transport"])), meter, c["command"] == "relay_on")
                        msg = "繼電器已" + ("復電" if c["command"] == "relay_on" else "斷電") if ok else "繼電器寫入失敗"
                    except Exception as e:
                        msg = f"繼電器錯誤:{e}"
                elif c["command"] == "read_now":
                    ok, msg = True, "已排入下次讀值"
                http(cfg, "ack", {"command_id": c["id"], "ok": ok, "result": msg})
                print(f"[{now()}] 指令 #{c['id']} {c['command']} → {msg}")
            last_poll = time.time()

        time.sleep(5)


if __name__ == "__main__":
    try:
        main()
    except KeyboardInterrupt:
        print("\n已停止")
