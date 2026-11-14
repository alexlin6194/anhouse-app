(async function () {
  let month = '';
  let tab = 'bills';
  let cache = null;

  const $ = s => document.querySelector(s);
  const fmt = n => 'NT$' + Number(n || 0).toLocaleString();
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  }
  const stTxt = { paid: '✅ 已繳', pending: '⏳ 待繳', failed: '❌ 失敗', cancelled: '✖ 已取消' };
  const stCls = { paid: 'ok', pending: 'idle', failed: 'bad', cancelled: 'idle' };
  const rpTxt = { pending: '待處理', assigned: '已派工', inspected: '已勘察', repairing: '施工中', done: '已完成', billed: '已請款', cancelled: '已取消' };
  const catTxt = { water: '漏水', electric: '電力', appliance: '家電', door_lock: '門鎖', pest: '蟲害', network: '網路', other: '其他' };

  function pad2(n) { return n < 10 ? '0' + n : '' + n; }
  function shiftMonth(delta) {
    const [y, m] = month.split('-').map(Number);
    const d = new Date(y, m - 1 + delta, 1);
    month = d.getFullYear() + '-' + pad2(d.getMonth() + 1);
    load();
  }

  function renderStats(d) {
    const s = d.stats;
    $('#monthLabel').textContent = d.month.replace('-', ' 年 ') + ' 月';
    $('#statCards').innerHTML =
      '<div class="stat"><b>' + fmt(s.paid_sum) + '</b><span>本月已收(' + s.paid_count + ' 筆)</span></div>' +
      '<div class="stat"><b>' + fmt(s.pending_sum) + '</b><span>待收(' + s.pending_count + ' 筆)</span></div>' +
      '<div class="stat"><b>' + fmt(s.overdue_sum) + '</b><span>逾期(' + s.overdue_count + ' 筆)</span></div>' +
      '<div class="stat"><b>' + fmt(s.sub_sum) + '</b><span>訂閱消費(' + s.sub_count + ' 筆)</span></div>' +
      '<div class="stat"><b>' + s.repair_count + ' 件</b><span>本月報修</span></div>' +
      '<div class="stat"><b>' + fmt(s.repair_amount) + '</b><span>報修金額</span></div>';
  }

  function renderList(d) {
    const box = $('#list');
    if (tab === 'bills') {
      if (!d.bills.length) { box.innerHTML = '<p style="padding:14px 2px;color:var(--brown2);font-size:13px;margin:0">目前無帳單資料 — 帳單會在租約簽訂與每月固定日自動產生</p>'; return; }
      box.innerHTML = d.bills.map(b => {
        const dp = Number(b.deposit_amount || 0);
        return '<div class="list-item"><div style="flex:1;min-width:0">' +
          '<b>' + esc(b.period) + '|' + esc(b.property_title) + '</b>' +
          '<span>' + (b.tenant_name ? '房客 ' + esc(b.tenant_name) + '・' : '') + '應繳 ' + fmt(b.amount) + (dp ? '(含押金 ' + fmt(dp) + ')' : '') +
          (b.paid_at ? '・繳於 ' + esc(String(b.paid_at).slice(0, 10)) + (b.pay_mode ? '(' + (b.pay_mode === 'online' ? '線上' : b.pay_mode === 'manual' ? '人工確認' : esc(b.pay_mode)) + ')' : '') : '・期限 ' + esc(String(b.due_date).slice(0, 10))) + '</span></div>' +
          '<span class="st ' + (stCls[b.status] || 'idle') + '">' + (stTxt[b.status] || esc(b.status)) + '</span></div>';
      }).join('');
    } else if (tab === 'repairs') {
      if (!d.repairs.length) { box.innerHTML = '<p style="padding:14px 2px;color:var(--brown2);font-size:13px;margin:0">目前無報修資料 — 房客或管理員報修後會顯示在此</p>'; return; }
      box.innerHTML = d.repairs.map(r => '<div class="list-item"><div style="flex:1;min-width:0">' +
        '<b>' + esc(r.property_title) + '|' + (catTxt[r.category] || esc(r.category)) + '</b>' +
        '<span>' + esc(String(r.created_at).slice(0, 10)) + '・報價 ' + fmt(r.quote_amount) + '・實支 ' + fmt(r.actual_amount) + '</span></div>' +
        '<span class="st idle">' + (rpTxt[r.status] || esc(r.status)) + '</span></div>').join('');
    } else {
      if (!d.subs.length) { box.innerHTML = '<p style="padding:14px 2px;color:var(--brown2);font-size:13px;margin:0">目前無訂閱消費 — 公司訂閱方案付款後會顯示在此</p>'; return; }
      box.innerHTML = d.subs.map(s2 => '<div class="list-item"><div style="flex:1;min-width:0">' +
        '<b>公司訂閱|' + fmt(s2.amount) + '</b>' +
        '<span>' + esc(String(s2.paid_at || s2.created_at).slice(0, 10)) + '・' + esc(s2.trade_no || '') + '</span></div>' +
        '<span class="st ' + (s2.status === 'paid' ? 'ok' : 'idle') + '">' + (s2.status === 'paid' ? '✅ 已付' : esc(s2.status)) + '</span></div>').join('');
    }
  }

  async function load() {
    try {
      const res = await fetch('api/company_audit.php?month=' + encodeURIComponent(month), { cache: 'no-store' });
      const d = await res.json();
      if (!d.ok) {
        toast(d.message || '載入失敗');
        if (String(d.message || '').includes('登入')) location.href = 'login.html';
        return;
      }
      cache = d;
      renderStats(d);
      renderList(d);
    } catch (e) { toast('連線異常'); }
  }

  document.querySelectorAll('.tab').forEach(b => b.addEventListener('click', () => {
    tab = b.dataset.tab;
    document.querySelectorAll('.tab').forEach(x => { x.classList.remove('primary'); x.classList.add('ghost'); });
    b.classList.remove('ghost'); b.classList.add('line');
    if (cache) renderList(cache);
  }));
  $('#prevM').addEventListener('click', () => shiftMonth(-1));
  $('#nextM').addEventListener('click', () => shiftMonth(1));

  const now = new Date();
  month = now.getFullYear() + '-' + pad2(now.getMonth() + 1);
  load();
})();
