using System.Net.Http.Json;
using System.Text.Json.Nodes;

/// <summary>
/// 雲端同步。契約與 Python 版完全相同:
///   GET  ?action=hello / policy / poll
///   POST ?action=push(讀值) / ledger(帳本,以 uuid 冪等) / ack(指令回報)
/// 注意:主機端有 CSRF 來源檢查,必須帶 Origin / Referer。
/// </summary>
public sealed class SyncClient
{
    private readonly HttpClient _http;
    private readonly string _url;
    public bool Online { get; private set; }
    public string? LastOk { get; private set; }

    public SyncClient(JsonNode cfg)
    {
        _url = cfg["server"]!.GetValue<string>();
        _http = new HttpClient { Timeout = TimeSpan.FromSeconds(20) };
        _http.DefaultRequestHeaders.Add("Authorization", "Bearer " + cfg["token"]!.GetValue<string>());
        _http.DefaultRequestHeaders.Add("Origin", cfg["origin"]?.GetValue<string>() ?? "https://itdc.tw");
        _http.DefaultRequestHeaders.Add("Referer", (cfg["origin"]?.GetValue<string>() ?? "https://itdc.tw") + "/anhouse/devices.html");
        _http.DefaultRequestHeaders.Add("User-Agent", "anhouse-bridge-cs/" + (cfg["agent_version"]?.GetValue<string>() ?? "1.1.0"));
    }

    public async Task<JsonNode?> CallAsync(string action, JsonNode? payload = null, string method = "POST")
    {
        try
        {
            var resp = method == "GET"
                ? await _http.GetAsync($"{_url}?action={action}")
                : await _http.PostAsJsonAsync($"{_url}?action={action}", payload ?? new JsonObject());
            var node = JsonNode.Parse(await resp.Content.ReadAsStringAsync());
            if (node?["ok"]?.GetValue<bool>() == true) { Online = true; LastOk = DateTime.Now.ToString("yyyy-MM-dd HH:mm:ss"); }
            return node;
        }
        catch (Exception ex)
        {
            if (Online) Console.WriteLine($"雲端連線失敗({action}):{ex.Message} → 離線模式");
            Online = false;
            return null;
        }
    }

    public async Task FlushAsync(LocalStore store)
    {
        var rd = store.UnsyncedReadings();
        if (rd.Count > 0)
        {
            var arr = new JsonArray();
            foreach (var r in rd) arr.Add(new JsonObject { ["serial"] = r["serial"]?.ToString(), ["reading"] = Convert.ToDouble(r["reading"]),
                ["read_at"] = r["read_at"]?.ToString(), ["relay"] = Convert.ToInt32(r["relay"]) == 1, ["uuid"] = r["uuid"]?.ToString() });
            if ((await CallAsync("push", new JsonObject { ["readings"] = arr }))?["ok"]?.GetValue<bool>() == true)
                store.MarkReadingsSynced(rd.Select(r => r["uuid"]!.ToString()!));
        }
        var lg = store.UnsyncedLedger();
        if (lg.Count > 0)
        {
            var arr = new JsonArray();
            foreach (var r in lg) arr.Add(new JsonObject { ["uuid"] = r["uuid"]?.ToString(), ["serial"] = r["serial"]?.ToString(),
                ["kind"] = r["kind"]?.ToString(), ["usage"] = Convert.ToDouble(r["usage"]), ["amount"] = Convert.ToInt32(r["amount"]),
                ["balance_after"] = Convert.ToInt32(r["balance_after"]), ["rate"] = r["rate"] is null ? null : Convert.ToDouble(r["rate"]),
                ["occurred_at"] = r["occurred_at"]?.ToString(), ["topup_id"] = r["topup_id"] is null ? null : Convert.ToInt32(r["topup_id"]) });
            if ((await CallAsync("ledger", new JsonObject { ["entries"] = arr }))?["ok"]?.GetValue<bool>() == true)
                store.MarkLedgerSynced(lg.Select(r => r["uuid"]!.ToString()!));
        }
    }

    public async Task CommandsAsync(LocalStore store, Meters meters)
    {
        var res = await CallAsync("poll", null, "GET");
        foreach (var c in res?["data"]?["commands"]?.AsArray() ?? new JsonArray())
        {
            var serial = c!["serial_no"]!.GetValue<string>();
            var cmd = c["command"]!.GetValue<string>();
            var ok = false; var msg = "找不到此序號";
            if (meters.Items.Any(m => m.Serial == serial) && cmd is "relay_on" or "relay_off")
            {
                ok = meters.Relay(serial, cmd == "relay_on");
                msg = ok ? (cmd == "relay_on" ? "已復電" : "已斷電") : "繼電器寫入失敗";
            }
            else if (cmd == "read_now") { ok = true; msg = "已排入下次讀值"; }
            await CallAsync("ack", new JsonObject { ["command_id"] = c["id"]!.GetValue<int>(), ["ok"] = ok, ["result"] = msg });
        }
    }
}
