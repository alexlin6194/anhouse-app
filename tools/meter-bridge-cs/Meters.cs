using System.Text.Json.Nodes;
using NModbus;

/// <summary>Modbus RTU / TCP 讀值與繼電器(NModbus)。參數全部來自 config.json。</summary>
public sealed class Meters
{
    public sealed record MeterCfg(string Serial, string Type, string? Host, int Port, string? ComPort,
        int Baud, int UnitId, ushort Register, byte Function, int Words, string WordOrder, double Scale, bool RelayOn);

    private readonly IModbusMaster? _tcp;
    private readonly IModbusSerialMaster? _rtu;
    private readonly IModbusFactory _factory = new ModbusFactory();
    private readonly bool _dry;
    private readonly Dictionary<string, double> _fake = new();

    public List<MeterCfg> Items { get; } = new();

    public Meters(JsonNode cfg, bool dry)
    {
        _dry = dry;
        foreach (var m in cfg["meters"]!.AsArray())
        {
            var tr = m!["transport"]!;
            Items.Add(new MeterCfg(
                m["serial"]!.GetValue<string>(),
                tr["type"]!.GetValue<string>(),
                tr["host"]?.GetValue<string>(), tr["port"]?.GetValue<int>() ?? 502,
                tr["port"]?.GetValue<string>(), tr["baudrate"]?.GetValue<int>() ?? 9600,
                m["unit_id"]?.GetValue<int>() ?? 1, (ushort)(m["register"]?.GetValue<int>() ?? 0),
                (byte)(m["function"]?.GetValue<int>() ?? 3), m["words"]?.GetValue<int>() ?? 2,
                m["word_order"]?.GetValue<string>() ?? "big", m["scale"]?.GetValue<double>() ?? 1.0,
                true));
        }
    }

    public double Read(string serial)
    {
        var m = Items.First(x => x.Serial == serial);
        if (_dry) { _fake[serial] = _fake.GetValueOrDefault(serial, 1000) + 0.33; return Math.Round(_fake[serial], 1); }
        if (m.Type == "tcp") throw new NotSupportedException("TCP 需在 SyncClient 建立 ModbusIpMaster 後注入,見 README 移植說明");
        throw new NotSupportedException("序列埠需建立 ModbusSerialMaster 並注入,見 README 移植說明");
    }

    public bool Relay(string serial, bool on) => _dry || throw new NotSupportedException("同 Read()");
}
