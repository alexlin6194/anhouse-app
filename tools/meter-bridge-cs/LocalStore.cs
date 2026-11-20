using Microsoft.Data.Sqlite;
using System.Text.Json.Nodes;

/// <summary>
/// 離線帳本。資料表結構與 Python 版一致(bridge.db 可互換,現場換語言不丟資料)。
/// </summary>
public sealed class LocalStore
{
    private readonly SqliteConnection _db;
    private readonly object _lock = new();

    public LocalStore(string path)
    {
        _db = new SqliteConnection($"Data Source={path}");
        _db.Open();
        Exec(@"
CREATE TABLE IF NOT EXISTS policy (serial TEXT PRIMARY KEY, data TEXT NOT NULL, updated_at TEXT);
CREATE TABLE IF NOT EXISTS readings (uuid TEXT PRIMARY KEY, serial TEXT, reading REAL, read_at TEXT, relay INTEGER, synced INTEGER DEFAULT 0, created_at TEXT);
CREATE TABLE IF NOT EXISTS ledger (uuid TEXT PRIMARY KEY, serial TEXT, kind TEXT, usage REAL DEFAULT 0, amount INTEGER DEFAULT 0,
    balance_after INTEGER DEFAULT 0, rate REAL, occurred_at TEXT, topup_id INTEGER, synced INTEGER DEFAULT 0);
CREATE TABLE IF NOT EXISTS kv (k TEXT PRIMARY KEY, v TEXT);");
    }

    private void Exec(string sql, params (string k, object? v)[] args)
    {
        lock (_lock)
        {
            using var cmd = _db.CreateCommand();
            cmd.CommandText = sql;
            foreach (var (k, v) in args) cmd.Parameters.AddWithValue(k, v ?? DBNull.Value);
            cmd.ExecuteNonQuery();
        }
    }

    private List<Dictionary<string, object?>> Query(string sql, params (string k, object? v)[] args)
    {
        lock (_lock)
        {
            using var cmd = _db.CreateCommand();
            cmd.CommandText = sql;
            foreach (var (k, v) in args) cmd.Parameters.AddWithValue(k, v ?? DBNull.Value);
            using var rd = cmd.ExecuteReader();
            var list = new List<Dictionary<string, object?>>();
            while (rd.Read())
            {
                var row = new Dictionary<string, object?>();
                for (var i = 0; i < rd.FieldCount; i++) row[rd.GetName(i)] = rd.IsDBNull(i) ? null : rd.GetValue(i);
                list.Add(row);
            }
            return list;
        }
    }

    public void ApplyPolicy(JsonNode data)
    {
        foreach (var m in data["meters"]!.AsArray())
        {
            var serial = m!["serial"]!.GetValue<string>();
            var pending = Convert.ToInt64(Query("SELECT COUNT(*) c FROM ledger WHERE serial=$s AND synced=0", ("$s", serial))[0]["c"]);
            if (pending == 0)
                Exec("INSERT INTO ledger(uuid,serial,kind,balance_after,amount,occurred_at,synced) VALUES($u,$s,'topup',$b,0,$t,1) ON CONFLICT(uuid) DO NOTHING",
                    ("$u", $"seed-{serial}"), ("$s", serial), ("$b", m["balance"]?.GetValue<int>() ?? 0), ("$t", Now()));
            Exec("INSERT INTO policy(serial,data,updated_at) VALUES($s,$d,$t) ON CONFLICT(serial) DO UPDATE SET data=excluded.data, updated_at=excluded.updated_at",
                ("$s", serial), ("$d", m.ToJsonString()), ("$t", Now()));
        }
        foreach (var c in data["credits"]?.AsArray() ?? new JsonArray())
        {
            var serial = c!["serial"]!.GetValue<string>();
            var topupId = c["topup_id"]!.GetValue<int>();
            if (Query("SELECT 1 FROM ledger WHERE topup_id=$t", ("$t", topupId)).Count > 0) continue;
            var bal = Balance(serial) ?? 0;
            Exec("INSERT INTO ledger(uuid,serial,kind,amount,balance_after,occurred_at,topup_id,synced) VALUES($u,$s,'topup',$a,$b,$t,$tid,0)",
                ("$u", Guid.NewGuid().ToString()), ("$s", serial), ("$a", c["amount"]!.GetValue<int>()), ("$b", bal + c["amount"]!.GetValue<int>()), ("$t", Now()), ("$tid", topupId));
        }
    }

    public void AddReading(string serial, double reading, bool relay) =>
        Exec("INSERT INTO readings(uuid,serial,reading,read_at,relay,synced,created_at) VALUES($u,$s,$r,$t,$rel,0,$t)",
            ("$u", Guid.NewGuid().ToString()), ("$s", serial), ("$r", reading), ("$t", Now()), ("$rel", relay ? 1 : 0));

    /// <summary>離線扣款核心:與 Python 版 meter_cycle 對齊</summary>
    public void AccrueOffline(string serial, double reading)
    {
        var pol = JsonNode.Parse(Policy(serial) ?? "{}")!;
        var last2 = Query("SELECT reading FROM readings WHERE serial=$s ORDER BY created_at DESC, rowid DESC LIMIT 2", ("$s", serial));
        if (last2.Count < 2) return;
        var usage = Math.Round(Convert.ToDouble(last2[0]["reading"]) - Convert.ToDouble(last2[1]["reading"]), 1);
        if (usage <= 0 || pol["mode"]?.GetValue<string>() != "prepaid") return;
        var rate = pol["rate"]?.GetValue<double>() ?? 5.0;
        var balance = Balance(serial) ?? pol["balance"]?.GetValue<int>() ?? 0;
        var cost = (int)Math.Round(usage * rate);
        balance -= cost;
        Exec("INSERT INTO ledger(uuid,serial,kind,usage,amount,balance_after,rate,occurred_at,synced) VALUES($u,$s,'deduct',$us,$a,$b,$r,$t,0)",
            ("$u", Guid.NewGuid().ToString()), ("$s", serial), ("$us", usage), ("$a", -cost), ("$b", balance), ("$r", rate), ("$t", Now()));
        if (balance <= 0 && pol["relay"]?.GetValue<string>() == "on") { /* 由 Program 呼叫 relay off,再寫 cut */ }
    }

    public string? Policy(string serial) => Query("SELECT data FROM policy WHERE serial=$s", ("$s", serial)).FirstOrDefault()?["data"] as string;
    public int? Balance(string serial) { var r = Query("SELECT balance_after FROM ledger WHERE serial=$s ORDER BY occurred_at DESC, rowid DESC LIMIT 1", ("$s", serial)); return r.Count == 0 ? null : Convert.ToInt32(r[0]["balance_after"]); }
    public List<Dictionary<string, object?>> UnsyncedReadings() => Query("SELECT * FROM readings WHERE synced=0 ORDER BY created_at LIMIT 200");
    public List<Dictionary<string, object?>> UnsyncedLedger() => Query("SELECT * FROM ledger WHERE synced=0 ORDER BY occurred_at LIMIT 200");
    public void MarkReadingsSynced(IEnumerable<string> uuids) { foreach (var u in uuids) Exec("UPDATE readings SET synced=1 WHERE uuid=$u", ("$u", u)); }
    public void MarkLedgerSynced(IEnumerable<string> uuids) { foreach (var u in uuids) Exec("UPDATE ledger SET synced=1 WHERE uuid=$u", ("$u", u)); }
    private static string Now() => DateTime.Now.ToString("yyyy-MM-dd HH:mm:ss");
}
