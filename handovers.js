/* 移交紀錄(業務=我移交的;房東/管理員=收到的) */
(function () {
  'use strict';
  const $ = (s) => document.querySelector(s);
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  }
  const money = (n) => 'NT$' + Number(n).toLocaleString('zh-TW');

  async function load() {
    try {
      const res = await fetch('api/handovers_list.php', { cache: 'no-store' });
      const d = await res.json();
      if (!d.ok || !d.authenticated) { location.href = 'login.html'; return; }
      if (d.role !== 'agent') $('#navHome').setAttribute('href', d.role === 'tenant' ? 'dashboard_tenant.html' : 'dashboard.html');
      const list = d.handovers || [];
      $('#sumLine').textContent = '共 ' + list.length + ' 筆';
      if (!list.length) {
        $('#list').innerHTML = '<p style="padding:18px 4px;text-align:center;color:var(--brown2);font-size:13px;margin:0">🌿 目前沒有移交紀錄:業務在「我的委託房源」對已簽約房屋按「🎉 成交移交」後,紀錄會留在這裡。</p>';
        return;
      }
      $('#list').innerHTML = list.map((h) => {
        const loc = [h.city, h.district].filter(Boolean).join('');
        return '<div class="list-item" style="flex-wrap:wrap"><span class="dot" style="background:var(--green)"></span>' +
          '<div style="flex:1;min-width:180px"><b>📦 ' + esc(h.property_title) + '</b>' +
          '<span>業務:' + esc(h.agent_name) + '|移交時間:' + esc(h.created_at) + '</span>' +
          (h.tenant_name ? '<span>承租:' + esc(h.tenant_name) + '|月租 ' + money(h.rent_amount) + '元|' + esc(String(h.start_date).slice(0, 10)) + ' 起租</span>' : '') +
          (h.note ? '<span>🔑 交接說明:' + esc(h.note) + '</span>' : '') +
          '</div><span class="st ok">已移交</span></div>';
      }).join('');
    } catch (e) {
      $('#list').innerHTML = '<p style="padding:18px 4px;text-align:center;color:var(--brown2);font-size:13px;margin:0">連線失敗,請重試</p>';
    }
  }

  load();
})();
