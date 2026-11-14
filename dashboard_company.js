(function () {
  'use strict';
  const $ = (s) => document.querySelector(s);
  const money = (n) => 'NT$' + Number(n).toLocaleString('zh-TW');
  const roleNames = { company_owner: '公司負責人', landlord: '房東(只讀)', manager: '管理員', agent: '業務', tenant: '房客(只讀)', accountant: '會計人員', admin_staff: '行政人員' };
  const toastEl = $('#toast');
  let timer;
  window.toast = function (msg) {
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
    const paid = sub.paid || {};
    const paidOn = paid.active === true;
    const planName = { s150p: '151 名以上(加購制)', s150: '公司級(121–150 名)', s120: '公司級(101–120 名)', s100: '公司級(81–100 名)', s80: '公司級(51–80 名)', s50: '公司級(31–50 名)', s30: '公司級(11–30 名)', s10: '公司級(4–10 名)', s3: '公司級(1–3 名)' }[paid.plan] || paid.plan;
    if (paidOn) {
      $('#subTier').textContent = '已購方案:' + planName + '|有效期至 ' + paid.ends;
      const nextTxt = sub.fee > 0 ? paid.ends + ' 次日起 NT$' + money(sub.fee) + '/月(依 ' + sub.employees + ' 名員工)' : paid.ends + ' 次日起為免費級距(依 ' + sub.employees + ' 名員工)';
      $('#subDesc').textContent = '現周期 NT$' + money(paid.fee) + '/月;' + nextTxt;
      $('#subFee').innerHTML = '<b>' + money(paid.fee) + '元</b><span>本期月費</span>';
    } else {
      $('#subTier').textContent = sub.tier_name + '|房屋 ' + sub.units + ' 戶';
      $('#subDesc').textContent = '目前 ' + (sub.employees ?? sub.seats ?? 0) + ' 名員工|' + (sub.fee > 0 ? '月費 NT$' + sub.fee : '免費級距') + '|級距以全公司員工名額計算(管理+會計+業務全部計入)';
      $('#subFee').innerHTML = sub.fee === 0 ? '<b>免費</b><span>入門級</span>' : '<b>' + money(sub.fee) + '元</b><span>月費</span>';
    }

    $('#memberList').innerHTML = data.members.map(m =>
      '<div class="list-item"><span class="dot" style="background:' + (m.status === 'active' ? 'var(--green)' : 'var(--beige2)') + '"></span>' +
      '<div><b>' + m.name + '|' + (m.role_name || m.role) + '</b><span>' + m.phone + '</span></div>' +
      '<span class="st ' + (m.status === 'active' ? 'ok' : 'idle') + '">' + (m.status === 'active' ? '啟用' : m.status === 'pending' ? '待處理' : '已移除') + '</span></div>'
    ).join('');
  }

  load();
})();
