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
      const res = await fetch('api/manager_dashboard.php');
      data = await res.json();
    } catch (e) { return; }
    if (!data.ok || !data.authenticated) { location.href = 'login.html'; return; }
    if (!data.manager) { location.href = 'welcome.html'; return; }

    $('#avatar').textContent = data.name ? data.name.charAt(0) : '?';
    $('#greet').textContent = '您好,' + data.name;
    $('#subline').textContent = '代管 ' + data.stats.units + ' 戶|來自多位房東';

    $('#stats').innerHTML =
      '<div class="stat"><b>' + data.stats.units + '</b><span>代管戶數</span></div>' +
      '<div class="stat"><b>' + data.stats.pending_invites + '</b><span>待接受邀請</span></div>' +
      '<div class="stat"><b>' + data.stats.overdue + '</b><span>代管屋逾期帳單</span></div>';

    const sub = data.subscription;
    $('#subTier').textContent = sub.tier_name + '|計費 ' + sub.units + ' 戶';
    $('#subFee').innerHTML = sub.fee === 0 ? '<b>免費</b><span>入門級</span>' : '<b>' + money(sub.fee) + '</b><span>月費</span>';

    const card = $('#inviteCard');
    if (data.pending_invites.length) {
      card.innerHTML = data.pending_invites.map(v =>
        '<div class="row" style="margin-bottom:10px"><div class="ico">📨</div>' +
        '<div><span class="tagline">房東邀請|' + v.owner_name + '(' + v.org_name + ')</span>' +
        '<h4>委託 ' + v.property_count + ' 間房屋</h4>' +
        '<p>' + (v.property_titles.join('、') || '房屋明細見邀請內容') + '</p></div></div>' +
        '<button class="mini-btn" data-accept="' + v.id + '">接受邀請</button>' +
        '<button class="mini-btn line" data-decline="' + v.id + '">婉拒</button>'
      ).join('');
      card.style.display = 'block';
      card.querySelectorAll('[data-accept]').forEach(b => b.addEventListener('click', () => respond(b.dataset.accept, 'accept')));
      card.querySelectorAll('[data-decline]').forEach(b => b.addEventListener('click', () => respond(b.dataset.decline, 'decline')));
    }

    $('#houseList').innerHTML = data.houses.length
      ? data.houses.map(h =>
          '<div class="list-item" style="cursor:pointer" data-id="' + h.id + '"><span class="dot" style="background:' + (h.status === 'rented' ? 'var(--green)' : 'var(--pink)') + '"></span>' +
          '<div><b>' + h.title + '</b><span>房東:' + h.owner_name + '|' + (h.status === 'rented' ? '出租中|月租 ' + money(h.rent) : '空置中') + '</span></div>' +
          '<span class="st ' + (h.status === 'rented' ? 'ok' : 'warn') + '">' + (h.status === 'rented' ? '出租中' : '空置') + '</span></div>'
        ).join('')
      : '<p style="padding:14px 2px;color:var(--brown2);font-size:13px;margin:0">尚未代管房屋:接受房東邀請後,房屋會出現在這裡。</p>';
    $('#houseList').querySelectorAll('.list-item').forEach(item => {
      item.addEventListener('click', () => { location.href = 'property.html?id=' + item.dataset.id; });
    });

    const paEl = $('#pendingAuth');
    const list = data.pending_authorizations || [];
    if (!list.length) {
      paEl.style.display = 'none';
      paEl.innerHTML = '';
    } else {
      paEl.style.display = 'block';
      paEl.innerHTML = '<div class="row" style="margin-bottom:4px"><div class="ico">⏳</div><div><span class="tagline">待房東授權 ' + list.length + ' 筆</span><h4>您建立的房源正在等待房東審核</h4></div></div>' +
        list.map(p => '<div class="list-item"><span class="dot" style="background:var(--wood)"></span>' +
          '<div><b>' + p.title + '</b><span>屋主:' + p.owner_org + '|房東授權後即可管理</span></div>' +
          '<span class="st warn">待授權</span></div>').join('');
    }
  }

  async function respond(id, action) {
    try {
      const res = await fetch('api/respond_invite.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ invitation_id: Number(id), action })
      });
      const data = await res.json();
      toast(data.message);
      if (data.ok) setTimeout(load, 1200);
    } catch (e) {
      toast('連線異常,請稍後再試');
    }
  }

  load();
})();
