(async function () {
  const $ = s => document.querySelector(s);
  let type = 'out';
  let cache = null;
  const fmt = n => 'NT$' + Number(n || 0).toLocaleString();
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  }
  const catTxt = { office: '辦公用品', transport: '交通費', repair_mat: '修繕材料', tax_fee: '稅金/規費', event: '活動/公關', replenish: '公司撥補', other: '其他' };
  window.toast = function (m) {
    const t = document.getElementById('toast');
    t.textContent = m; t.classList.add('show');
    setTimeout(() => t.classList.remove('show'), 2600);
  };

  function render() {
    $('#orgLine').textContent = cache.org_name;
    $('#balCards').innerHTML =
      '<div class="stat"><b>' + fmt(cache.balance) + '</b><span>零用金餘額</span></div>' +
      '<div class="stat"><b>' + fmt(cache.month_spent) + '</b><span>本月支出</span></div>';
    const rows = cache.rows || [];
    if (!rows.length) {
      $('#list').innerHTML = '<p style="padding:14px 2px;color:var(--brown2);font-size:13px;margin:0">目前無零用金紀錄 — 請先「登錄撥補」存入公司零用金,之後的支出即可登錄</p>';
      return;
    }
    $('#list').innerHTML = rows.map(r =>
      '<div class="list-item"><span class="dot" style="background:' + (r.type === 'in' ? 'var(--green)' : '#d9776b') + '"></span>' +
      '<div style="flex:1;min-width:0"><b>' + (r.type === 'in' ? '➕ ' : '➖ ') + fmt(r.amount) + '|' + (catTxt[r.category] || esc(r.category)) + '</b>' +
      '<span>' + esc(String(r.created_at).slice(0, 10)) + (r.note ? '・' + esc(r.note) : '') + (r.doc_no ? '・單據 ' + esc(r.doc_no) : '') + (r.by_name ? '・經手 ' + esc(r.by_name) : '') + '</span></div>' +
      '<button class="mini-btn line" onclick="delRow(' + r.id + ')">刪除</button></div>'
    ).join('');
  }

  async function load() {
    try {
      const res = await fetch('api/petty_cash.php', { cache: 'no-store' });
      const d = await res.json();
      if (!d.ok) { toast(d.message || '載入失敗'); return; }
      cache = d;
      render();
    } catch (e) { toast('連線異常'); }
  }

  window.openSheet = function (t) {
    type = t;
    $('#ttl').textContent = t === 'in' ? '➕ 登錄撥補(公司存入)' : '➖ 登錄支出(零用金支付)';
    if (t === 'in') $('#fCat').value = 'replenish';
    $('#fAmt').value = ''; $('#fNote').value = ''; $('#fDoc').value = '';
    document.getElementById('mask').classList.add('show');
    document.querySelector('.sheet').classList.add('show');
  };
  window.closeSheet = function () {
    document.getElementById('mask').classList.remove('show');
    document.querySelector('.sheet').classList.remove('show');
  };
  window.delRow = async function (id) {
    if (!confirm('確定刪除這筆紀錄?(餘額將回算)')) return;
    try {
      const res = await fetch('api/petty_cash.php', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'delete', id })
      });
      const r = await res.json();
      toast(r.message);
      if (r.ok) load();
    } catch (e) { toast('連線異常'); }
  };

  $('#addIn').addEventListener('click', () => window.openSheet('in'));
  $('#addOut').addEventListener('click', () => window.openSheet('out'));
  $('#fGo').addEventListener('click', async () => {
    const amt = Number($('#fAmt').value || 0);
    if (amt <= 0) { toast('請輸入金額'); return; }
    try {
      const res = await fetch('api/petty_cash.php', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'add', type, amount: amt, category: $('#fCat').value, note: $('#fNote').value.trim(), doc_no: $('#fDoc').value.trim() })
      });
      const r = await res.json();
      toast(r.message);
      if (r.ok) { window.closeSheet(); load(); }
    } catch (e) { toast('連線異常'); }
  });
  const mk = document.getElementById('mask');
  if (mk) mk.addEventListener('click', (ev) => { if (ev.target === mk) window.closeSheet(); });

  load();
})();
