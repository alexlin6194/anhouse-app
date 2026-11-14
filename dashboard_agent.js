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

  const typeNames = { whole_house: '整層住家', independent_suite: '獨立套房', shared_suite: '分租套房', studio: '雅房', apartment: '公寓', building: '電梯大樓', townhouse: '透天', other: '其他' };

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  }

  function layoutText(l) {
    if (l.layout_rooms === null && l.layout_living === null && l.layout_bath === null) return '';
    return (l.layout_rooms ?? '-') + '房' + (l.layout_living ?? '-') + '廳' + (l.layout_bath ?? '-') + '衛';
  }

  async function load() {
    let data;
    try {
      const res = await fetch('api/agent_dashboard.php');
      data = await res.json();
    } catch (e) { return; }
    if (!data.ok || !data.authenticated) { location.href = 'login.html'; return; }
    if (!data.agent) { location.href = 'welcome.html'; return; }

    $('#avatar').textContent = data.name ? data.name.charAt(0) : '?';
    $('#greet').textContent = '您好,' + data.name;
    $('#orgLine').textContent = data.org ? '所屬:' + data.org.name : '尚未加入組織(由公司邀請加入)';

    $('#stats').innerHTML =
      '<div class="stat"><b>' + data.stats.total + '</b><span>我的房源</span></div>' +
      '<div class="stat"><b>' + data.stats.rented + '</b><span>出租中</span></div>' +
      '<div class="stat"><b>' + data.stats.for_sale + '</b><span>出售中</span></div>';

    const invEl = $('#invites');
    const invites = data.invites || [];
    if (!invites.length) {
      invEl.innerHTML = '';
    } else {
      invEl.innerHTML = '<div class="card" style="border:2px solid var(--wood)"><div class="row"><div class="ico">💼</div>' +
        '<div><span class="tagline">組織邀請 ' + invites.length + ' 筆待回覆</span>' +
        invites.map((iv) => '<div style="margin:6px 0"><b>' + esc(iv.org_name) + '</b><span>職務:' + esc(iv.roles_text) + '|邀請人:' + esc(iv.inviter_name) + '|效期 ' + esc((iv.expires_at || '').slice(0, 10)) + '</span></div>').join('') +
        '</div><span class="st warn">待回覆</span></div>' +
        '<div style="display:flex;gap:10px;margin-top:10px">' +
        invites.map((iv) => '<button type="button" class="mini-btn act-inv" data-id="' + iv.id + '" data-act="accept" style="flex:1">✅ 接受 ' + esc(iv.org_name) + '</button><button type="button" class="mini-btn line act-inv" data-id="' + iv.id + '" data-act="decline" style="flex:1">婉拒</button>').join('') +
        '</div></div>';
    }

    if (data.org) {
      $('#orgName').textContent = data.org.name;
      const pending = data.org.status === 'pending_review';
      $('#orgSt').textContent = pending ? '待審核' : '啟用';
      $('#orgSt').className = 'st ' + (pending ? 'warn' : 'ok');
      $('#orgNote').textContent = pending ? '組織申請審核中;審核通過後完整功能開放。' : '組織已啟用,可進行房源建檔與帶看流程。';
    }

    $('#listingList').innerHTML = data.listings.length
      ? data.listings.map(l => {
          const isSale = l.listing_type === 'sale';
          const st = isSale
            ? '<span class="st ' + (l.status === 'sold' ? 'ok' : 'warn') + '">' + (l.status === 'sold' ? '已售出' : '出售中') + '</span>'
            : '<span class="st ' + (l.status === 'rented' ? 'ok' : 'warn') + '">' + (l.status === 'rented' ? '出租中' : '待租') + '</span>';
          const price = isSale ? '開價 ' + money(l.sale_price) + '元' : '月租 ' + money(l.rent) + '元';
          const info = [typeNames[l.property_type] || '', layoutText(l), price].filter(Boolean).join('|');
          const dot = isSale ? 'var(--pink)' : (l.status === 'rented' || l.status === 'sold' ? 'var(--green)' : 'var(--beige2)');
          return '<div class="list-item"><span class="dot" style="background:' + dot + '"></span>' +
            '<div><b>' + (isSale ? '[售] ' : '[租] ') + l.title + '</b><span>' + info + '</span></div>' + st + '</div>';
        }).join('')
      : '<p style="padding:14px 2px;color:var(--brown2);font-size:13px;margin:0">尚無房源:點右上「+ 建立房源」開始建檔(591 規格欄位,支援出租/出售)。</p>';
  }

  async function loadPA() {
    let d = null;
    let fetchErr = '';
    try {
      const res = await fetch('api/property_agents.php?mine=1', { cache: 'no-store' });
      d = await res.json();
    } catch (e) { fetchErr = (e && e.message) ? e.message : '連線失敗'; }
    $('#paSect').style.display = '';
    const card = $('#paCard');
    card.style.display = '';
    if (fetchErr) { card.innerHTML = '<p style="font-size:12px;color:#B00020">載入失敗:' + esc(fetchErr) + '</p>'; return; }
    if (!d || !d.ok) { card.innerHTML = '<p style="font-size:12px;color:#B00020">API:' + esc(d && d.message ? d.message : '無回應') + '</p>'; return; }
    const items = d.items || [];
    if (!items.length) { card.innerHTML = '<p style="font-size:12px;color:var(--brown2)">目前沒有委託資料(受邀清單空)</p>'; return; }
    const dealTxt = { rent: '出租', sale: '出售', both: '出租/出售' };
    let html = '';
    try {
      html = items.map(a => {
        const price = a.listing_type === 'sale' ? '開價 ' + money(a.sale_price) + '元' : '月租 ' + money(a.rent) + '元';
        const st = a.status === 'invited'
          ? '<span class="st warn">邀請中</span>'
          : '<span class="st ' + (Number(a.is_listed) ? 'ok' : 'idle') + '">' + (Number(a.is_listed) ? '已上架' : '未上架') + '</span>';
        const btns = a.status === 'invited'
          ? '<div style="display:flex;gap:6px;margin-top:6px"><button type="button" class="mini-btn act-paok" data-id="' + a.id + '">✅ 接受委託</button><button type="button" class="mini-btn line act-pano" data-id="' + a.id + '">婉拒</button></div>'
          : '<div style="display:flex;gap:6px;margin-top:6px;flex-wrap:wrap"><button type="button" class="mini-btn act-patoggle" data-pid="' + a.property_id + '" data-on="' + Number(a.is_listed) + '">' + (Number(a.is_listed) ? '⬇️ 下架' : '⬆️ 上架') + '</button>' +
            '<a class="mini-btn line" style="text-decoration:none" href="property.html?id=' + a.property_id + '">查看房屋</a></div>';
        return '<div class="list-item" style="flex-wrap:wrap"><div style="flex:1;min-width:150px"><b>' + esc(a.title) + '</b>' +
          '<span>' + (dealTxt[a.deal_type] || a.deal_type) + '|' + price + '|可改價:' + (Number(a.can_edit_price) ? '是' : '否') + '</span></div>' + st + btns + '</div>';
      }).join('');
    } catch (err) {
      html = '<p style="font-size:12px;color:#B00020">渲染錯誤:' + esc(err && err.message ? err.message : err) + '</p>';
    }
    if (!html) html = '<p style="font-size:12px;color:var(--brown2)">目前沒有委託資料</p>';
    card.innerHTML = html;

    card.querySelectorAll('.act-paok, .act-pano').forEach(b => b.addEventListener('click', async () => {
      const accept = b.classList.contains('act-paok');
      if (!accept && !confirm('婉拒此委託?')) return;
      try {
        const res = await fetch('api/property_agents.php', {
          method: 'POST', headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ action: 'respond', id: Number(b.dataset.id), accept: accept ? 1 : 0 })
        });
        const r = await res.json();
        toast(r.message);
        if (r.ok) loadPA();
      } catch (e) { toast('連線異常'); }
    }));
    card.querySelectorAll('.act-patoggle').forEach(b => b.addEventListener('click', async () => {
      try {
        const res = await fetch('api/listings.php', {
          method: 'POST', headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ action: 'toggle', id: Number(b.dataset.pid) })
        });
        const r = await res.json();
        toast(r.message);
        if (r.ok) loadPA();
      } catch (e) { toast('連線異常'); }
    }));
  }

  (function () {
    const __pa = $('#paCard');
    if (__pa) { __pa.style.display = ''; __pa.innerHTML = '<p style="font-size:12px;color:var(--brown2)">載入中…(vC)</p>'; }
    else { const s = $('#paSect'); if (s) { s.style.display = ''; s.insertAdjacentHTML('afterend', '<div class="card" style="padding:6px 16px"><p style="font-size:12px;color:#B00020">找不到 paCard 元素(HTML 與 JS 版本不符)</p></div>'); } }
  })();

  document.addEventListener('click', async (ev) => {
    const b = ev.target.closest('.act-inv');
    if (!b) return;
    b.disabled = true;
    try {
      const res = await fetch('api/respond_invite.php', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ invitation_id: Number(b.dataset.id), action: b.dataset.act })
      });
      const r = await res.json();
      toast(r.message);
      if (r.ok) setTimeout(load, 1000);
      else b.disabled = false;
    } catch (e) { toast('連線異常'); b.disabled = false; }
  });

  load();
  loadPA();
})();
