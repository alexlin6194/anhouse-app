(function () {
  'use strict';
  const $ = (s) => document.querySelector(s);
  const money = (n) => 'NT$' + Number(n).toLocaleString('zh-TW');
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
      const res = await fetch('api/landlord_dashboard.php');
      data = await res.json();
    } catch (e) {
      toast('連線異常,請稍後再試');
      return;
    }
    if (!data.ok || !data.authenticated) { location.href = 'login.html'; return; }
    if (!data.landlord) {
      try {
        const me = await (await fetch('api/me.php', { cache: 'no-store' })).json();
        const role = me.ok && me.authenticated ? me.user.registration_role : null;
        if (role === 'vendor') { location.replace('dashboard_vendor.html'); return; }
        if (me.ok && me.authenticated) { location.replace('welcome.html'); return; }
      } catch (e) { /* fallthrough */ }
      location.href = 'login.html';
      return;
    }

    const name = data.name || '';
    $('#avatar').textContent = name ? name.charAt(0) : '?';
    $('#greet').textContent = '您好,' + name;
    $('#orgName').textContent = data.org.name;

    try {
      const me = await (await fetch('api/me.php', { cache: 'no-store' })).json();
      if (me.ok && me.user && me.user.platform_admin) {
        const b = document.createElement('button');
        b.className = 'btn small ghost';
        b.textContent = '🛡 組織審核';
        b.style.cssText = 'position:fixed;right:14px;bottom:84px;z-index:55';
        b.addEventListener('click', () => { location.href = 'admin_orgs.html'; });
        document.body.appendChild(b);
      }
    } catch (e) { /* ignore */ }

    const badge = $('#bellBadge');
    if (data.unread > 0) {
      badge.textContent = data.unread > 99 ? '99+' : data.unread;
      badge.style.display = 'flex';
      badge.style.alignItems = 'center';
      badge.style.justifyContent = 'center';
      badge.style.top = '-6px';
      badge.style.right = '-6px';
      badge.style.width = '18px';
      badge.style.height = '18px';
      badge.style.background = 'var(--pink-d)';
      badge.style.color = '#FFFDF7';
      badge.style.fontSize = '10px';
      badge.style.fontWeight = '700';
    }

    const s = data.stats;
    $('#stats').innerHTML =
      '<div class="stat"><b>' + s.self_units + '</b><span>自管計費戶數</span></div>' +
      '<div class="stat"><b>' + money(s.month_receivable) + '元</b><span>本月應收租金</span></div>' +
      '<div class="stat"><b>' + s.pending + '</b><span>待處理</span></div>';

    const sub = data.subscription;
    $('#subTier').textContent = sub.tier_name + '|計費 ' + sub.units + ' 戶';
    $('#subDesc').textContent = sub.fee === 0
      ? '入門級永久免費且功能完整;退租釋出後可免費新增房屋(同時在管制)' + (sub.next_tier_name ? ';新增至 ' + sub.next_tier_name.replace(/\(.*/, '') + ' 月費 ' + money(sub.next_fee) + '元' : '')
      : '超出入門級後按級距月費;退租釋出戶數會重新計算級距';
    $('#subFee').innerHTML = sub.fee === 0 ? '<b>免費</b><span>入門級</span>' : '<b>' + money(sub.fee) + '元</b><span>月費</span>';

    const pc = $('#pendingCard');
    const parts = [];
    if (data.overdue_bills.length) {
      const rows = data.overdue_bills.map(b =>
        '<div class="list-item" style="cursor:pointer" data-bill="' + b.id + '"><span class="dot" style="background:var(--pink)"></span>' +
        '<div><b>' + b.title + '|' + b.period + ' 帳單</b><span>應繳 ' + money(b.amount) + '元|原訂 ' + b.due_date + ' 繳款,已逾期</span></div>' +
        '<span class="st warn">逾期 ›</span></div>').join('');
      parts.push('<div class="sect" style="margin-top:4px">逾期帳單(點看明細+催繳)</div>' + rows);
    }
    if (data.pending_invites.length) {
      const rows = data.pending_invites.map(v =>
        '<div class="list-item"><span class="dot" style="background:var(--beige2)"></span>' +
        '<div><b>管理員邀請|' + v.phone + '</b><span>邀請碼 ' + v.code + '|待對方同意(7 天內有效)</span></div>' +
        '<span class="st idle">待接受</span></div>').join('');
      parts.push('<div class="sect" style="margin-top:4px">待對方接受的邀請</div>' + rows);
    }
    if (parts.length) {
      pc.innerHTML = '<div class="row" style="margin-bottom:4px"><div class="ico">📌</div><div><span class="tagline">待處理 ' + data.stats.pending + ' 項</span><h4>需要您留意</h4></div></div>' + parts.join('');
      pc.style.display = 'block';
      pc.querySelectorAll('[data-bill]').forEach(el => {
        el.addEventListener('click', () => { location.href = 'bills.html'; });
      });
    }

    $('#houseList').innerHTML = data.houses.length
      ? data.houses.map(h => {
          const pendingAuth = h.pending_manager_name !== null && h.pending_manager_name !== undefined;
          const delegated = h.manager_name !== null;
          const rented = h.tenant_name !== null && h.tenant_name !== undefined;
          const depositHold = !rented && h.pending_tenant_name !== null && h.pending_tenant_name !== undefined;
          const st = pendingAuth
            ? '<span class="st warn">待授權|' + h.pending_manager_name + '</span>'
            : (delegated ? '<span class="st idle">代管中|' + h.manager_name + '</span>' : '') +
              (rented ? '<span class="st ok">出租中</span>'
                : (depositHold ? '<span class="st warn">已收定金|待簽約</span>'
                  : (h.status === 'rented' ? '<span class="st ok">出租中</span>' : '<span class="st warn">空置|待出租</span>')));
          const dot = pendingAuth ? 'var(--wood)' : (rented || depositHold ? 'var(--green)' : (delegated ? 'var(--beige2)' : 'var(--pink)'));
          const detailParts = [];
          detailParts.push(delegated ? '由 ' + h.manager_name + ' 代管' : '自行管理');
          if (rented) detailParts.push('租客:' + h.tenant_name);
          else if (depositHold) detailParts.push('租約邀請:' + h.pending_tenant_name + '(回覆中)');
          detailParts.push('月租 ' + money(h.rent) + '元');
          const authBtns = pendingAuth
            ? '<div style="display:flex;gap:8px;margin-top:8px"><button type="button" class="mini-btn act-auth" data-id="' + h.id + '">授權代管</button><button type="button" class="mini-btn line act-decline" data-id="' + h.id + '">婉拒(自行管理)</button></div>'
            : '';
          return '<div class="list-item" style="flex-wrap:wrap;cursor:pointer" data-id="' + h.id + '"><span class="dot" style="background:' + dot + '"></span>' +
            '<div style="flex:1;min-width:0"><b>' + h.title + '</b><span>' + detailParts.join('|') + '</span></div>' + st + authBtns + '</div>';
        }).join('')
      : '<p style="padding:14px 2px;color:var(--brown2);font-size:13px;margin:0">尚無房屋:點右上「+ 建立房源」新增您的第一間房屋。</p>';

    $('#houseList').querySelectorAll('.act-auth').forEach(b => b.addEventListener('click', (e) => { e.stopPropagation(); decideAuth(b.dataset.id, 'authorize'); }));
    $('#houseList').querySelectorAll('.act-decline').forEach(b => b.addEventListener('click', (e) => { e.stopPropagation(); decideAuth(b.dataset.id, 'decline'); }));
    $('#houseList').querySelectorAll('.list-item[data-id]').forEach(item => {
      if (item.querySelector('.act-auth')) return;
      item.addEventListener('click', () => { location.href = 'property.html?id=' + item.dataset.id; });
    });
  }

  async function decideAuth(id, action) {
    try {
      const res = await fetch('api/authorize_property.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ property_id: Number(id), action })
      });
      const d = await res.json();
      showToast(d.message);
      if (d.ok) setTimeout(load, 600);
    } catch (e) {
      showToast('連線異常,請稍後再試');
    }
  }

  load();
})();
