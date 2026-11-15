(async function () {
  const $ = s => document.querySelector(s);
  let month = '';
  let cache = null;
  const fmt = n => 'NT$' + Number(n || 0).toLocaleString();
  const roleTxt = { agent: '業務', manager: '管理員', accountant: '會計人員', admin_staff: '行政人員' };
  const stTxt = { draft: '📝 草稿', confirmed: '✅ 已出表', paid: '💵 已發放' };
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  }
  function pad2(n) { return n < 10 ? '0' + n : '' + n; }
  function shiftMonth(delta) {
    const [y, m] = month.split('-').map(Number);
    const d = new Date(y, m - 1 + delta, 1);
    month = d.getFullYear() + '-' + pad2(d.getMonth() + 1);
    load();
  }
  window.toast = function (m) {
    const t = document.getElementById('toast');
    t.textContent = m; t.classList.add('show');
    setTimeout(() => t.classList.remove('show'), 2600);
  };

  function render() {
    $('#monthLabel').textContent = month.replace('-', ' 年 ') + ' 月';
    $('#orgLine').textContent = cache.org_name + '・薪資冊';
    const box = $('#roster');
    if (!cache.roster.length) {
      box.innerHTML = '<div class="card"><p style="padding:14px 2px;color:var(--brown2);font-size:13px;margin:0">目前無員工薪資資料 — 請先於「邀請員工」加入業務/會計/行政人員,即可在此設定底薪與出薪</p></div>';
      return;
    }
    box.innerHTML = cache.roster.map((r, i) => {
      const st = r.pay_status ? '<span class="st ' + (r.pay_status === 'paid' ? 'ok' : r.pay_status === 'confirmed' ? 'ok' : 'idle') + '">' + (stTxt[r.pay_status] || '') + '</span>' : '<span class="st idle">未出表</span>';
      return '<div class="card" style="padding:12px 16px;margin-bottom:10px">' +
        '<div style="display:flex;align-items:center;gap:8px"><b style="font-size:15px;color:var(--brown)">' + esc(r.name) + '</b>' +
        '<span style="font-size:11.5px;color:var(--brown2)">' + (roleTxt[r.role] || esc(r.role)) + '</span>' + st + '</div>' +
        '<div style="font-size:12.5px;color:var(--brown2);margin-top:4px">制度:底薪 ' + fmt(r.base_salary) +
        (r.role === 'agent' ? '・成交獎金 ' + (Number(r.bonus_pct) ? '<b>' + r.bonus_pct + '%</b>(房客仲介費)' : (Number(r.bonus_per_deal) ? '每件 ' + fmt(r.bonus_per_deal) + '(舊制)' : '<b style="color:#c0392b">未設定</b>')) : '') + '</div>' +
        (r.role === 'agent' ? '<div style="font-size:12.5px;color:var(--brown2);margin-top:2px">本月:成交 ' + r.deals + ' 件・租金合計 ' + fmt(r.rent_sum) + '・公司仲介費 ' + fmt(r.company_fee * 2) + '(房客+房東各半)・獎金 ' + fmt(r.bonus_amount) + '</div>' : '') +
        '<div style="font-size:12.5px;color:var(--brown2);margin-top:2px">本月:底薪 ' + fmt(r.base_salary_pay) +
        (r.role === 'agent' ? '・成交 ' + r.deals + ' 件・獎金 ' + fmt(r.bonus_amount) : '') +
        (r.extra_amount ? '・其他加減 ' + (r.extra_amount > 0 ? '+' : '') + fmt(r.extra_amount).replace('NT$', '$') : '') +
        (r.ins_deduct ? '・勞健保自付 -' + fmt(r.ins_deduct).replace('NT$', '$') : '') +
        (r.pension_employer ? '・勞退提撥 ' + fmt(r.pension_employer) : '') + '</div>' +
        (r.no_scheme && r.deals > 0 ? '<p style="font-size:12px;color:#c0392b;margin:6px 2px 0">⚠ 已成交 ' + r.deals + ' 件,但獎金制度未設定——請點 ⚙ 設定成交獎金 %,或在編輯中手動填獎金</p>' : '') +
        (r.bonus_stale ? '<p style="font-size:12px;color:#8A6D3B;margin:6px 2px 0">ℹ 已存草稿的獎金與本月業績不同步——點「✏️ 編輯」重新儲存即可更新</p>' : '') +
        '<div style="display:flex;align-items:center;gap:8px;margin-top:8px">' +
        '<b style="font-size:16px;color:var(--brown)">應發 ' + fmt(r.gross) + '</b>' +
        '<button class="btn ghost" style="padding:6px 12px;margin-left:auto" onclick="openScheme(' + i + ')">⚙ 制度</button>' +
        '<button class="btn ghost" style="padding:6px 12px" onclick="openEdit(' + i + ')">✏️ 編輯</button>' +
        (r.pay_status === 'draft' ? '<button class="btn primary" style="padding:6px 12px" onclick="doPay(\'' + r.user_id + '\',\'confirm\')">出表</button>' : '') +
        (r.pay_status === 'confirmed' ? '<button class="btn primary" style="padding:6px 12px" onclick="doPay(\'' + r.user_id + '\',\'mark_paid\')">已發放</button>' : '') +
        '</div></div>';
    }).join('');
  }

  window.openScheme = function (i) {
    const r = cache.roster[i];
    $('#edTitle').textContent = '⚙ 薪資制度|' + r.name + '(' + (roleTxt[r.role] || r.role) + ')';
    $('#edBody').innerHTML =
      '<p style="font-size:12.5px;color:var(--brown2);margin:0 2px 10px">制度為長期設定,每月出薪自動套用;單月份要特別調整時再用「✏️ 編輯」。</p>' +
      '<label style="font-size:12.5px;color:var(--brown2);font-weight:700">每月底薪(NT$)</label>' +
      '<input id="scBase" type="number" min="0" value="' + r.base_salary + '" style="width:100%;padding:12px;border:1.5px solid var(--beige2);border-radius:12px;font-size:14px">' +
      (r.role === 'agent' ?
        '<label style="font-size:12.5px;color:var(--brown2);font-weight:700;display:block;margin-top:10px">成交獎金比例 %(向房客收取仲介費的 %)</label>' +
        '<input id="scPct" type="number" min="0" max="100" value="' + (r.bonus_pct || 40) + '" style="width:100%;padding:12px;border:1.5px solid var(--beige2);border-radius:12px;font-size:14px">' +
        '<p style="font-size:11.5px;color:var(--brown2);margin:8px 2px 0">公司收費制:仲介費 = 月租金 100%(房客 50% + 房東 50%)。業務獎金 = 房客 50% × 此比例。<br>例:租金 $12,000 → 公司收 $12,000(房客 $6,000)→ 業務 40% = <b>$2,400</b>。設 0 = 不自動發獎金。</p>' +
        '<input id="scPer" type="hidden" value="' + r.bonus_per_deal + '">'
        : '<input id="scPct" type="hidden" value="' + (r.bonus_pct || 0) + '"><input id="scPer" type="hidden" value="' + r.bonus_per_deal + '">') +
      '<div style="display:flex;gap:10px;margin-top:14px">' +
      '<button class="btn ghost" style="flex:1" onclick="closeEdit()">取消</button>' +
      '<button class="btn primary" style="flex:1" id="scGo">儲存制度</button></div>';
    document.getElementById('editMask').classList.add('show');
    document.querySelector('.sheet').classList.add('show');
    document.getElementById('scGo').addEventListener('click', async () => {
      try {
        const res = await fetch('api/payroll.php', {
          method: 'POST', headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ action: 'set_salary', user_id: Number(r.user_id), base_salary: Number($('#scBase').value || 0), bonus_pct: Number($('#scPct') ? $('#scPct').value || 0 : 0), bonus_per_deal: Number($('#scPer') ? $('#scPer').value || 0 : 0) })
        });
        const rr = await res.json();
        toast(rr.message);
        if (rr.ok) { window.closeEdit(); load(); }
      } catch (e) { toast('連線異常'); }
    });
  };

  window.openEdit = function (i) {
    const r = cache.roster[i];
    $('#edTitle').textContent = '✏️ ' + r.name + '|' + (roleTxt[r.role] || r.role) + '・' + month;
    $('#edBody').innerHTML =
      '<label style="font-size:12.5px;color:var(--brown2);font-weight:700">底薪(每月固定)</label>' +
      '<input id="edBase" type="number" min="0" value="' + r.base_salary_pay + '" style="width:100%;padding:12px;border:1.5px solid var(--beige2);border-radius:12px;font-size:14px">' +
      (r.role === 'agent' ?
        '<label style="font-size:12.5px;color:var(--brown2);font-weight:700;display:block;margin-top:10px">業績獎金(本月成交 ' + r.deals + ' 件・租金合計 ' + fmt(r.rent_sum) + '・制度自動算 ' + fmt(r.bonus_amount) + ')</label>' +
        '<input id="edBonus" type="number" min="0" value="' + r.bonus_amount + '" style="width:100%;padding:12px;border:1.5px solid var(--beige2);border-radius:12px;font-size:14px">' +
        '<input id="edDetail" placeholder="獎金說明(選填)" value="' + esc(r.bonus_detail) + '" style="width:100%;padding:12px;border:1.5px solid var(--beige2);border-radius:12px;font-size:13px;margin-top:8px">'
        : '<input id="edBonus" type="hidden" value="' + r.bonus_amount + '"><input id="edDetail" type="hidden" value="' + esc(r.bonus_detail) + '">') +
      '<label style="font-size:12.5px;color:var(--brown2);font-weight:700;display:block;margin-top:10px">其他加減項(獎金/扣款,負號=扣)</label>' +
      '<input id="edExtraL" placeholder="項目說明,例:全勤獎金/交通費" value="' + esc(r.extra_label) + '" style="width:100%;padding:12px;border:1.5px solid var(--beige2);border-radius:12px;font-size:13px">' +
      '<input id="edExtraA" type="number" value="' + r.extra_amount + '" style="width:100%;padding:12px;border:1.5px solid var(--beige2);border-radius:12px;font-size:14px;margin-top:8px">' +
      '<label style="font-size:12.5px;color:var(--brown2);font-weight:700;display:block;margin-top:10px">勞健保自付(從薪資扣)</label>' +
      '<input id="edIns" type="number" min="0" value="' + (r.ins_deduct || 0) + '" style="width:100%;padding:12px;border:1.5px solid var(--beige2);border-radius:12px;font-size:14px">' +
      '<label style="font-size:12.5px;color:var(--brown2);font-weight:700;display:block;margin-top:10px">勞退 6% 公司提撥(進員工退休金帳戶,不影響應發)</label>' +
      '<input id="edPension" type="number" min="0" value="' + (r.pension_employer || 0) + '" style="width:100%;padding:12px;border:1.5px solid var(--beige2);border-radius:12px;font-size:14px">' +
      '<p id="edGross" style="font-size:14px;color:var(--brown);font-weight:700;margin:10px 2px 0"></p>' +
      '<div style="display:flex;gap:10px;margin-top:14px">' +
      '<button class="btn ghost" style="flex:1" onclick="closeEdit()">取消</button>' +
      '<button class="btn primary" style="flex:1" onclick="savePay(\'' + r.user_id + '\')">儲存</button></div>';
    const upd = () => {
      const g = Math.max(0, Number($('#edBase').value || 0) + Number($('#edBonus').value || 0) + Number($('#edExtraA').value || 0) - Number($('#edIns').value || 0));
      $('#edGross').textContent = '應發合計:' + fmt(g) + '(勞退提撥另計 ' + fmt(Number($('#edPension').value || 0)) + ')';
    };
    ['edBase', 'edBonus', 'edExtraA', 'edIns'].forEach(id => { const el = document.getElementById(id); if (el) el.addEventListener('input', upd); });
    upd();
    document.getElementById('editMask').classList.add('show');
    document.querySelector('.sheet').classList.add('show');
  };
  window.closeEdit = function () {
    document.getElementById('editMask').classList.remove('show');
    document.querySelector('.sheet').classList.remove('show');
  };
  window.savePay = async function (uid) {
    try {
      const res = await fetch('api/payroll.php', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          action: 'save', user_id: Number(uid), month,
          base_salary: Number($('#edBase').value || 0),
          bonus_amount: Number($('#edBonus').value || 0),
          bonus_detail: $('#edDetail') ? $('#edDetail').value : '',
          extra_label: $('#edExtraL') ? $('#edExtraL').value : '',
          extra_amount: Number($('#edExtraA').value || 0),
          ins_deduct: Number($('#edIns').value || 0),
          pension_employer: Number($('#edPension').value || 0)
        })
      });
      const r = await res.json();
      toast(r.message);
      if (r.ok) { window.closeEdit(); load(); }
    } catch (e) { toast('連線異常'); }
  };
  window.doPay = async function (uid, act2) {
    try {
      const res = await fetch('api/payroll.php', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: act2, user_id: Number(uid), month })
      });
      const r = await res.json();
      toast(r.message);
      if (r.ok) load();
    } catch (e) { toast('連線異常'); }
  };

  async function load() {
    try {
      const res = await fetch('api/payroll.php?month=' + encodeURIComponent(month), { cache: 'no-store' });
      const d = await res.json();
      if (!d.ok) { toast(d.message || '載入失敗'); return; }
      cache = d;
      render();
    } catch (e) { toast('連線異常'); }
  }
  $('#prevM').addEventListener('click', () => shiftMonth(-1));
  $('#nextM').addEventListener('click', () => shiftMonth(1));
  const mk = document.getElementById('editMask');
  if (mk) mk.addEventListener('click', (ev) => { if (ev.target === mk) window.closeEdit(); });

  const now = new Date();
  month = now.getFullYear() + '-' + pad2(now.getMonth() + 1);
  load();
})();
