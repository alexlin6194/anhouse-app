(function () {
  'use strict';
  var path = location.pathname.split('/').pop() || 'dashboard.html';
  var isHome = /^dashboard(_manager|_agent|_tenant|_company)?\.html$/.test(path);
  var bar = document.createElement('nav');
  bar.className = 'btab';
  bar.setAttribute('aria-label', '主選單');
  bar.innerHTML =
    '<a class="bt' + (isHome ? ' on' : '') + '" data-bt="home" href="dashboard.html"><span>🏠</span>首頁</a>' +
    '<a class="bt' + (path === 'messages.html' || path === 'messages_thread.html' ? ' on' : '') + '" data-bt="msg" href="messages.html"><span>💬</span>訊息</a>' +
    '<a class="bt' + (path === 'notifications.html' ? ' on' : '') + '" data-bt="ntf" href="notifications.html"><span>🔔</span>通知</a>' +
    '<button type="button" class="bt" data-bt="me"><span>👤</span>我的</button>';
  document.body.appendChild(bar);
  document.body.classList.add('has-tabbar');

  bar.querySelector('[data-bt="me"]').addEventListener('click', function () {
    location.href = 'profile.html';
  });

  fetch('api/me.php').then(function (r) { return r.json(); }).then(function (d) {
    if (d && d.ok && d.authenticated && d.user && d.user.registration_role) {
      var homes = {
        landlord: 'dashboard.html',
        manager: 'dashboard_manager.html',
        agent: 'dashboard_agent.html',
        tenant: 'dashboard_tenant.html',
        company_owner: 'dashboard_company.html',
        admin_staff: 'dashboard_company.html',
        accountant: 'dashboard_company.html'
      };
      var home = homes[d.user.registration_role];
      if (home) bar.querySelector('[data-bt="home"]').setAttribute('href', home);
    }
  }).catch(function () {});
})();
