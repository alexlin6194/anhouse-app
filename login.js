(function () {
  'use strict';
  const phoneEl = document.getElementById('phone');
  const passEl = document.getElementById('password');
  const toast = document.getElementById('toast');
  let timer;

  let checkedAuth = false;
  function bounceIfAuthed() {
    checkedAuth = true;
    fetch('api/me.php', { cache: 'no-store' }).then(r => r.json()).then(d => {
      if (d && d.ok && d.authenticated) {
        const routes = { landlord: 'dashboard.html', manager: 'dashboard_manager.html', agent: 'dashboard_agent.html', tenant: 'dashboard_tenant.html', company_owner: 'dashboard_company.html', admin_staff: 'dashboard_company.html', accountant: 'dashboard_company.html', vendor: 'repairs.html' };
        location.replace(routes[d.user.registration_role] || 'welcome.html');
      }
    }).catch(() => {});
  }
  bounceIfAuthed();
  window.addEventListener('pageshow', function (e) {
    if (e.persisted && checkedAuth) bounceIfAuthed();
  });

  function showToast(msg) {
    toast.textContent = msg;
    toast.classList.add('show');
    clearTimeout(timer);
    timer = setTimeout(() => toast.classList.remove('show'), 2200);
  }

  phoneEl.addEventListener('input', () => {
    phoneEl.value = phoneEl.value.replace(/\D/g, '').slice(0, 10);
  });

  document.getElementById('loginForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    if (!/^09\d{8}$/.test(phoneEl.value)) { showToast('請輸入正確的手機號碼(09 開頭 10 碼)'); phoneEl.focus(); return; }
    if (!passEl.value) { showToast('請輸入密碼'); passEl.focus(); return; }
    const btn = document.getElementById('loginBtn');
    btn.disabled = true;
    try {
      const res = await fetch('api/login.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ phone: phoneEl.value, password: passEl.value })
      });
      const data = await res.json();
      showToast(data.message);
      if (data.ok && data.redirect) {
        setTimeout(() => location.href = data.redirect, 1000);
      } else {
        btn.disabled = false;
      }
    } catch (err) {
      btn.disabled = false;
      showToast('連線異常,請檢查網路或伺服器設定');
    }
  });
})();
