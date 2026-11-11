(function () {
  'use strict';
  const $ = (s) => document.querySelector(s);
  const money = (n) => 'NT$' + Number(n).toLocaleString('zh-TW');
  const roleNames = { company_owner: '公司負責人', landlord: '房東(只讀)', manager: '管理員', agent: '業務', tenant: '房客(只讀)', accountant: '會計人員', admin_staff: '行政人員' };
  const toastEl = $('#toast');
  let timer;
  function toast(msg) {
    toastEl.textContent = msg;
    toastEl.classList.add('show');
    clearTimeout(timer);
    timer = setTimeout(() => toastEl.classList.remove('show'), 2400);
  }

  async function load() {
    let data;
    try {
      const res = await fetch('api/company_dashboard.php');
      data = await res.json();
    } catch (e) { return; }
    if (!data.ok || !data.authenticated) { location.href = 'login.html'; return; }
    if (!data.company) { location.href = 'welcome.html'; return; }

    $('#avatar').textContent = data.name ? data.name.charAt(0) : '?';
    $('#greet').textContent = '您好,' + data.name;
    $('#rolePill').textContent = roleNames[data.my_role] || '公司';
    $('#orgLine').textContent = data.org.name + (data.org.tax_id ? '(統編 ' + data.org.tax_id + ')' : '');

    $('#stats').innerHTML =
      '<div class="stat"><b>' + data.stats.units + '</b><span>組織房屋</span></div>' +
      '<div class="stat"><b>' + data.stats.members + '</b><span>組織成員</span></div>' +
      '<div class="stat"><b>' + data.stats.pending_docs + '</b><span>待審文件</span></div>';

    const pending = data.org.status === 'pending_review';
    $('#orgSt').textContent = pending ? '待審核' : '啟用';
    $('#orgSt').className = 'st ' + (pending ? 'warn' : 'ok');
    $('#orgNote').textContent = pending
      ? '公司申請審核中:平台審核經紀人證書通過後,員工邀請與完整功能即開放。'
      : '組織已啟用;可邀請員工(職務權限可複選)並分派房屋。';

    const sub = data.subscription;
    $('#subTier').textContent = sub.tier_name + '|組織房屋 ' + sub.units + ' 戶';
    $('#subDesc').textContent = '員工帳號額度 ' + (sub.seats === null ? '不限' : sub.seats) + '(已用 ' + sub.employees + ')|級距以全公司戶數合計(跨分店)';
    $('#subFee').innerHTML = sub.fee === 0 ? '<b>免費</b><span>入門級</span>' : '<b>' + money(sub.fee) + '</b><span>月費</span>';

    $('#memberList').innerHTML = data.members.map(m =>
      '<div class="list-item"><span class="dot" style="background:' + (m.status === 'active' ? 'var(--green)' : 'var(--beige2)') + '"></span>' +
      '<div><b>' + m.name + '|' + (m.role_name || m.role) + '</b><span>' + m.phone + '</span></div>' +
      '<span class="st ' + (m.status === 'active' ? 'ok' : 'idle') + '">' + (m.status === 'active' ? '啟用' : m.status === 'pending' ? '待處理' : '已移除') + '</span></div>'
    ).join('');
  }

  load();
})();
