# C# 版橋接器(骨架)

> ⚠️ **未經編譯驗證**:本沙箱沒有 dotnet SDK。已驗證可運作的是 Python 版(`tools/meter-bridge/bridge.py`)。
> 這份是移植骨架:資料表結構、uuid 規則、API 契約都與 Python 版一致,可與 Python 版共用同一個 `bridge.db`。

## 為什麼是「骨架」而不是成品

`Meters.cs` 的 `Read()` / `Relay()` 尚未接上 NModbus 實際 client(只留 `NotSupportedException`)。
原因:TCP 與序列埠要分別建立 `ModbusIpMaster` / `ModbusSerialMaster` 並管理生命週期,
在無法編譯測試的環境裡寫死反而是風險。接法:

```csharp
// TCP
var factory = new ModbusFactory();
var client = new TcpClient(host, port);
_tcp = factory.CreateMaster(client);

// 序列埠(RS485 轉 USB)
var port = new SerialPort(comPort, baud, Parity.None, 8, StopBits.One);
port.Open();
_rtu = factory.CreateRtuMaster(port);

// 讀值
ushort[] regs = m.Function == 4
    ? master.ReadInputRegisters(m.UnitId, m.Register, (ushort)m.Words)
    : master.ReadHoldingRegisters(m.UnitId, m.Register, (ushort)m.Words);
```

## 與 Python 版的行為對齊點(不可自行改動)

| 規則 | 說明 |
|---|---|
| uuid 每筆一組 | 讀值與帳務都要;雲端靠它去重,重送一百次只入帳一次 |
| 有未同步帳本時不套用雲端餘額 | 否則現場已扣的錢會「復活」 |
| 扣款後餘額 ≤ 0 先寫 ledger 再斷電 | 斷電失敗也要留下「該斷」的紀錄 |
| 政策先套 credits 再套 balance | 離線期間房東儲值的錢才會補上 |
| 離線不假裝成功 | 斷電指令執行失敗要 `ok=false` 回報 |

## 部署

```bash
dotnet publish -c Release -r linux-arm64 --self-contained false
# 產出 bin/Release/net8.0/linux-arm64/publish/
# 複製 config.json(與 Python 版共用格式)到該目錄,設為 systemd 服務
```

樹莓派建議用 `--self-contained true` 免裝 runtime。
