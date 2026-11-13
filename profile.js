(function () {
  'use strict';
  const $ = (s) => document.querySelector(s);
  let toastTimer;
  function showToast(msg) {
    const t = $('#toast');
    t.textContent = msg;
    t.classList.add('show');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => t.classList.remove('show'), 2400);
  }

  const roleNames = { landlord: '房東', manager: '管理員', agent: '業務', tenant: '房客', company_owner: '公司負責人', admin_staff: '行政人員', accountant: '會計人員' };

  async function load() {
    let data;
    try {
      const res = await fetch('api/me.php', { cache: 'no-store' });
      data = await res.json();
    } catch (e) { return; }
    if (!data.ok || !data.authenticated) { location.href = 'login.html'; return; }
    const u = data.user;
    const roles = [u.registration_role, ...((data.memberships || []).map(m => m.role))]
      .filter((v, i, a) => v && a.indexOf(v) === i)
      .map(r => roleNames[r] || r).join('・');
    $('#accCard').innerHTML =
      '<div class="list-item" style="padding:4px 0"><div><b>' + (u.name || '') + '</b><span>' + (roles || '') + '</span></div></div>' +
      '<div class="list-item" style="padding:4px 0"><div><b>📱 ' + u.phone + '</b><span>登入帳號</span></div><span class="st ok">已驗證</span></div>' +
      '<div class="list-item" style="padding:4px 0"><div><b>✉️ ' + (u.email || '未填') + '</b><span>聯絡信箱</span></div><span class="st ok">已驗證</span></div>';

    const backMap = { landlord: 'dashboard.html', manager: 'dashboard_manager.html', agent: 'dashboard_agent.html', tenant: 'dashboard_tenant.html', company_owner: 'dashboard_company.html', admin_staff: 'dashboard_company.html', accountant: 'dashboard_company.html' };
    $('#navBack').setAttribute('href', backMap[u.registration_role] || 'dashboard.html');
    const subRoles = ['vendor', 'landlord', 'company_owner', 'manager'];
    if (subRoles.includes(u.registration_role)) {
      const subRow = $('#subRow');
      if (subRow) {
        subRow.querySelector('b').textContent = u.registration_role === 'vendor' ? '廠商訂閱(月費)' : '會員訂閱方案';
        subRow.style.display = '';
        subRow.addEventListener('click', () => { location.href = 'subscription.html'; });
      }
    }
  }

  const RE_PASS = /^(?=.*[A-Za-z])(?=.*\d).{8,}$/;
  $('#npw').addEventListener('input', () => {
    const v = $('#npw').value;
    $('#hint-npw').textContent = !v ? '' : (RE_PASS.test(v) ? '✓ 格式正確' : '至少 8 碼,含英文與數字');
    $('#hint-npw').classList.toggle('err', !!v && !RE_PASS.test(v));
  });
  $('#cpw').addEventListener('input', () => {
    const same = $('#cpw').value === $('#npw').value;
    $('#hint-cpw').textContent = !$('#cpw').value ? '' : (same ? '✓ 兩次輸入一致' : '兩次輸入不一致');
    $('#hint-cpw').classList.toggle('err', !same && !!$('#cpw').value);
  });

  $('#pwForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const cur = $('#cur').value, npw = $('#npw').value, cpw = $('#cpw').value;
    if (!cur) { showToast('請輸入目前的密碼'); return; }
    if (!RE_PASS.test(npw)) { showToast('新密碼須至少 8 碼,且包含英文與數字'); return; }
    if (npw !== cpw) { showToast('兩次輸入的新密碼不一致'); return; }
    const btn = $('#saveBtn');
    btn.disabled = true;
    try {
      const res = await fetch('api/change_password.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ current_password: cur, new_password: npw })
      });
      const d = await res.json();
      showToast(d.message);
      if (d.ok) { $('#cur').value = ''; $('#npw').value = ''; $('#cpw').value = ''; $('#hint-npw').textContent = ''; $('#hint-cpw').textContent = ''; }
    } catch (err) {
      showToast('連線異常,請稍後再試');
    } finally {
      btn.disabled = false;
    }
  });

  $('#logoutBtn')?.addEventListener('click', async () => {
    try { await fetch('api/logout.php', { method: 'POST' }); } catch (e) { /* 仍導回登入頁 */ }
    location.href = 'login.html';
  });

  load();
})();
