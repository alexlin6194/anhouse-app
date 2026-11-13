(async function () {
  const qs = new URLSearchParams(location.search);
  let data = null;
  let isVendor = true;

  function statusOf(p) {
    if (isVendor) return p.sub_status || 'expired';
    if (p.sub_ends && p.sub_ends >= new Date().toISOString().slice(0, 10)) return 'active';
    return 'none';
  }
  function planLabel(plan) {
    const hit = (data.plans || []).find(x => x.plan === plan);
    return hit ? hit.label : (data.audience === 'vendor' ? plan : plan + '會員');
  }

  function planCard(p) {
    const perk = data.audience === 'vendor'
      ? (p.plan === 'pro' ? '優先排序 + 自訂承接半徑 15 公里' : '承接案件數不限 + 5 公里廣播')
      : (p.plan === 'pro' ? '多物件管理 + 藍新自動對帳 + 進階報表' : '基本租賃管理 + 電子簽約');
    return '<div class="list-item" style="align-items:center;flex-wrap:wrap"><div style="flex:1;min-width:0">' +
      '<b>' + p.label + '</b><span>NT$' + p.amount.toLocaleString() + '元 / ' + (p.months === 12 ? '年' : '月') + '</span>' +
      '<span style="color:#8a7a5c">' + perk + '</span></div>' +
      '<button type="button" class="mini-btn act-pay" data-plan="' + p.plan + '" data-months="' + p.months + '">訂閱</button></div>';
  }

  function render() {
    const st = document.getElementById('statusCard');
    const p = data.profile || {};
    const sub = statusOf(p);
    let badge = '', detail = '';
    if (sub === 'active') {
      badge = '<span class="st ok">訂閱中|' + planLabel(p.plan) + '</span>';
      detail = '有效期至 <b>' + (p.sub_ends || '-') + '</b>,到期前可續訂加天數。';
    } else if (sub === 'trial') {
      const left = p.trial_ends ? Math.ceil((new Date(p.trial_ends) - new Date()) / 86400000) : 0;
      badge = '<span class="st warn">試用中|剩 ' + Math.max(left, 0) + ' 天</span>';
      detail = '試用至 <b>' + (p.trial_ends || '-') + '</b>。' + (left <= 7 ? '快到期了,續訂才會持續出現在廠商列表與收到廣播商機。' : '試用期內功能完整。');
    } else {
      badge = '<span class="st ' + (isVendor ? 'bad' : 'idle') + '">' + (isVendor ? '已停用' : '未訂閱') + '</span>';
      detail = isVendor ? '訂閱已過期,暫時無法承接案件與收到廣播,續訂立即恢復。' : '訂閱會員享完整功能與優先支援。';
    }
    st.innerHTML = '<div class="row"><div class="ico">' + (isVendor ? '💼' : '🏠') + '</div>' +
      '<div style="flex:1;min-width:0"><span class="tagline">' + (p.company || '我的' + (isVendor ? '廠商服務' : '會員')) + '</span><h4>' + badge + '</h4>' +
      '<p style="font-size:13px">' + detail + '</p></div></div>' +
      (qs.get('result') === 'ok' ? '<p style="color:#2e7d32;font-size:13.5px">✅ 付款成功,訂閱已開通!</p>' : '') +
      (qs.get('result') === 'fail' ? '<p style="color:#c0392b;font-size:13.5px">❌ 付款未完成,可再試一次。</p>' : '');

    document.getElementById('plans').innerHTML = data.plans.map(planCard).join('');
    document.querySelectorAll('.act-pay').forEach(b => b.addEventListener('click', () => pay(b)));

    const h = data.history || [];
    document.getElementById('history').innerHTML = h.length
      ? h.map(x => '<div class="list-item" style="padding:7px 2px"><div style="flex:1"><b>' +
          planLabel(x.plan) + ' NT$' + Number(x.amount).toLocaleString() + '元</b>' +
          '<span>' + (x.trade_no || '') + (x.paid_at ? '|' + x.paid_at.slice(0, 10) : '') + '</span></div>' +
          '<span class="st ' + (x.status === 'paid' ? 'ok' : (x.status === 'pending' ? 'warn' : 'bad')) + '">' +
          ({paid: '已付款', pending: '未完成', failed: '失敗'}[x.status] || x.status) + '</span></div>').join('')
      : '<p style="color:#a09070;margin:0">尚無紀錄</p>';

    document.getElementById('note').textContent =
      data.pay_mode === 'mock'
        ? '目前為「模擬付款」測試模式,不會真的扣款;填入藍新測試憑證後自動改走藍新刷卡頁。'
        : (data.pay_mode === 'test' ? '藍新測試環境(ccore):請用測試卡號付款。' : '藍新正式環境,將真實扣款!');
  }

  async function pay(btn) {
    btn.disabled = true;
    try {
      const res = await fetch('api/newebpay_mpg.php', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=start&plan=' + btn.dataset.plan + '&months=' + btn.dataset.months
      });
      const j = await res.json();
      if (!j.ok) { alert(j.message || '付款啟動失敗'); btn.disabled = false; return; }
      if (j.mode === 'mock') { location.href = j.pay_url; return; }
      const form = document.createElement('form');
      form.method = 'POST'; form.action = j.gateway;
      ['MerchantID', 'TradeInfo', 'TradeSha', 'Version'].forEach(k => {
        const i = document.createElement('input'); i.type = 'hidden'; i.name = k; i.value = j[k]; form.appendChild(i);
      });
      document.body.appendChild(form); form.submit();
    } catch (e) { alert('連線失敗,請稍後再試'); btn.disabled = false; }
  }

  const res = await fetch('api/newebpay_mpg.php?status=1', { credentials: 'same-origin' });
  const j = await res.json();
  if (!j.ok) {
    if (j.message === '尚未登入') { location.href = 'login.html'; return; }
    alert(j.message || '載入失敗'); return;
  }
  data = j;
  isVendor = data.audience === 'vendor';
  render();
})();
