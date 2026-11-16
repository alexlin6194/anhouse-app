(function () {
  'use strict';
  const $ = (s) => document.querySelector(s);
  const listEl = $('#list');
  const icons = { bill_overdue: '🧾', invite_pending: '🧑‍🔧', invite_accepted: '🤝', bill_due_soon: '⏰', system: '🏠', message: '💬', property_created: '🏠', property_authorized: '🤝', property_declined: '🙅', lease_pending: '📄', lease_accepted: '🤝', lease_declined: '🙅' };
  const links = {
    message: (ref) => 'messages_thread.html?id=' + ref,
    lease_pending: () => 'dashboard_tenant.html',
    lease_accepted: () => 'leases.html',
    lease_declined: () => 'leases.html',
    property_authorized: () => 'ROLE',
    pa_invite: () => 'dashboard_agent.html',
    payroll: () => 'payroll.html',
    bill_overdue: () => 'bills.html',
    bill_due_soon: () => 'bills.html',
    property_created: () => 'dashboard.html'
  };
  let filter = '';
  let toastTimer;
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  }

  function showToast(msg) {
    const t = $('#toast');
    t.textContent = msg;
    t.classList.add('show');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => t.classList.remove('show'), 2200);
  }

  function timeAgo(dt) {
    const diff = Math.floor((Date.now() - new Date(dt.replace(' ', 'T')).getTime()) / 1000);
    if (diff < 60) return '剛剛';
    if (diff < 3600) return Math.floor(diff / 60) + ' 分鐘前';
    if (diff < 86400) return Math.floor(diff / 3600) + ' 小時前';
    if (diff < 172800) return '昨天';
    return dt.slice(0, 10);
  }

  async function load() {
    let data;
    try {
      const res = await fetch('api/notifications.php' + (filter ? '?filter=' + filter : ''));
      data = await res.json();
    } catch (e) {
      showToast('連線異常,請稍後再試');
      return;
    }
    if (!data.ok || !data.authenticated) { location.href = 'login.html'; return; }

    if (!data.items.length) {
      listEl.innerHTML = '<p style="padding:22px 4px;text-align:center;color:var(--brown2);font-size:13px;margin:0">🌿 目前沒有' + (filter === 'unread' ? '未讀' : '') + '通知</p>';
      return;
    }
    listEl.innerHTML = data.items.map(n =>
      '<div class="notif' + (Number(n.is_read) ? ' read' : '') + '" data-id="' + n.id + '" data-type="' + n.type + '" data-ref="' + (n.ref_id || '') + '" data-read="' + n.is_read + '">' +
      '<div class="nic">' + (icons[n.type] || '🏠') + '</div>' +
      '<div class="nt"><b>' + n.title + '</b><span>' + n.body + '</span><em>' + n.created_at.replace(/-/g, '/').slice(0, 19) + '</em><em style="color:var(--brown2)" data-ago="1">' + timeAgo(n.created_at) + '</em></div>' +
      (Number(n.is_read) ? '' : '<span class="dot"></span>') +
      '</div>'
    ).join('');

    listEl.querySelectorAll('.notif').forEach(item => {
      item.addEventListener('click', async () => {
        const wasRead = item.dataset.read === '1';
        item.dataset.read = '1';
        item.classList.add('read');
        const d = item.querySelector('.dot');
        if (d) d.remove();
        if (!wasRead) {
          try {
            await fetch('api/notifications_read.php', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({ id: Number(item.dataset.id) })
            });
          } catch (e) {}
        }
        openSheet(item);
      });
    });
  }

  function openSheet(item) {
    const type = item.dataset.type;
    const ref = item.dataset.ref;
    $('#nIcon').innerHTML = (icons[type] || '🏠') + ' <span>' + esc(item.querySelector('.nt b').textContent) + '</span>';
    $('#nBody').textContent = item.querySelector('.nt span').textContent;
    const ems = item.querySelectorAll('.nt em');
    const fullTs = ems[0] ? ems[0].textContent : '';
    const agoTxt = ems.length > 1 ? ems[1].textContent : '';
    $('#nTime').textContent = '系統發送:' + fullTs + '(' + agoTxt + ')';
    const go = $('#nGo');
    if (links[type] && ref) {
      go.style.display = '';
      go.textContent = '前往處理';
      go.onclick = () => {
        closeSheet();
        const dest = links[type](ref);
        if (dest === 'ROLE') {
          /* 依身份導向:業務→業務工作台,管理員→管理員工作台,其他→公司首頁 */
          fetch('api/me.php', { cache: 'no-store' }).then(r => r.json()).then(d => {
            const rr = d && d.user ? d.user.registration_role : '';
            location.href = rr === 'agent' ? 'dashboard_agent.html' : rr === 'manager' ? 'dashboard_manager.html' : rr === 'tenant' ? 'dashboard_tenant.html' : 'dashboard_company.html';
          }).catch(() => { location.href = 'dashboard.html'; });
        } else {
          location.href = dest;
        }
      };
    } else {
      go.style.display = 'none';
    }
    $('#nMask').classList.add('show');
    $('#nSheet').classList.add('show');
  }

  function closeSheet() {
    $('#nMask').classList.remove('show');
    $('#nSheet').classList.remove('show');
  }
  $('#nMask').addEventListener('click', closeSheet);
  window.nCloseSheet = closeSheet;

  document.querySelectorAll('.filter button').forEach(b => {
    b.addEventListener('click', () => {
      document.querySelectorAll('.filter button').forEach(x => x.classList.remove('on'));
      b.classList.add('on');
      filter = b.dataset.f;
      load();
    });
  });

  $('#markAll').addEventListener('click', async () => {
    try {
      const res = await fetch('api/notifications_read.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ all: true })
      });
      const data = await res.json();
      showToast(data.message);
      load();
    } catch (e) {
      showToast('連線異常,請稍後再試');
    }
  });

  load();
})();
