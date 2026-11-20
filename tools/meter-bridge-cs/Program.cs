// 安家生活GO 地端電錶橋接器(C# 版 骨架)
//
// ⚠️ 這份是給你們 C# 團隊的移植骨架:本沙箱沒有 dotnet SDK,未經編譯驗證。
//    已驗證過的是 Python 版(tools/meter-bridge/bridge.py),兩者行為與 API 契約完全一致。
//    移植時請照 LocalStore.cs 的資料表結構與 Program.cs 的流程,不要自行改 uuid 規則。
using System.Text.Json;
using System.Text.Json.Nodes;

var cfgPath = Path.Combine(AppContext.BaseDirectory, "config.json");
var cfg = JsonNode.Parse(File.ReadAllText(cfgPath))!;
var store = new LocalStore(Path.Combine(AppContext.BaseDirectory, "bridge.db"));
var sync = new SyncClient(cfg);
var meters = new Meters(cfg, args.Contains("--dry-run"));

// 1) 開機先拉政策(單價、餘額、誰是帳務權威)
if (await sync.CallAsync("hello") is { } hello && hello["ok"]?.GetValue<bool>() == true
    && await sync.CallAsync("policy", null, "GET") is { } pol && pol["ok"]?.GetValue<bool>() == true)
{
    store.ApplyPolicy(pol["data"]!);
    Console.WriteLine($"已連上雲端,電錶 {pol["data"]!["meters"]!.AsArray().Count} 顆");
}

var pushSec = cfg["push_seconds"]?.GetValue<int>() ?? 300;
var pollSec = cfg["poll_seconds"]?.GetValue<int>() ?? 60;
using var cts = new CancellationTokenSource();
Console.CancelKeyPress += (_, e) => { e.Cancel = true; cts.Cancel(); };

var lan = cfg["lan_enabled"]?.GetValue<bool>() ?? true;
if (lan) LanServer.Start(cfg, store, meters, sync);

var lastPush = DateTime.MinValue;
var lastPoll = DateTime.MinValue;

while (!cts.Token.IsCancellationRequested)
{
    // 讀值 + 離線扣款 + 上報
    if ((DateTime.Now - lastPush).TotalSeconds >= pushSec)
    {
        foreach (var m in meters.Items)
        {
            try
            {
                var reading = meters.Read(m.Serial);
                store.AddReading(m.Serial, reading, m.RelayOn);
                store.AccrueOffline(m.Serial, reading);   // 見 LocalStore.AccrueOffline
            }
            catch (Exception ex) { Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] 讀取 {m.Serial} 失敗:{ex.Message}"); }
        }
        await sync.FlushAsync(store);   // 失敗不拋錯,下次再試
        lastPush = DateTime.Now;
    }

    // 拉指令(雲端在線才拉)
    if (sync.Online && (DateTime.Now - lastPoll).TotalSeconds >= pollSec)
    {
        await sync.CommandsAsync(store, meters);
        lastPoll = DateTime.Now;
    }

    await Task.Delay(5000, cts.Token).ContinueWith(_ => { });
}
