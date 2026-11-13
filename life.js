(function () {
  'use strict';
  const $ = (s) => document.querySelector(s);
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  let toastTimer;
  function showToast(msg) {
    const t = $('#toast');
    t.textContent = msg;
    t.classList.add('show');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => t.classList.remove('show'), 3000);
  }

  const CATS = [
    { id: 'food', label: '🍜 食' },
    { id: 'cloth', label: '👗 衣' },
    { id: 'house', label: '🏠 住' },
    { id: 'transport', label: '🚌 行' },
    { id: 'hospital', label: '🏥 醫院' },
    { id: 'school', label: '🏫 學校' },
    { id: 'fun', label: '🎤 娛樂' },
    { id: 'gas', label: '⛽ 加油站' },
    { id: 'other', label: '🧩 其他' }
  ];
  const CAT_LABEL = { food: '食|餐廳・美食', cloth: '衣|服飾・鞋包', house: '住|其他註冊房源', transport: '行|公車・捷運・車站', hospital: '醫院', school: '學校', fun: '娛樂|KTV・酒吧・影城', gas: '⛽ 加油站', other: '🧩 其他|超商・藥局・銀行・郵局・公園' };

  let propId = new URLSearchParams(location.search).get('property_id');
  let current = null; // null = 全部;否則只顯示該分類
  let cache = null;

  function fmtDist(m) {
    if (m == null) return '';
    return m >= 1000 ? (m / 1000).toFixed(1) + ' 公里' : m + ' 公尺';
  }

  function mapsUrl(item) {
    return 'https://www.google.com/maps/search/?api=1&query=' + encodeURIComponent(item.name + ' ' + (item.vicinity || ''));
  }

  let ubikeHtml = '';
  let motoHtml = '';

  function buildMoto() {
    const lat = cache && cache.property ? cache.property.lat : '';
    const lng = cache && cache.property ? cache.property.lng : '';
    if (!lat || !lng) { motoHtml = ''; return; }
    const q = (kw) => 'https://www.google.com/maps/search/' + encodeURIComponent(kw) + '/' + lat + ',' + lng + ',17z';
    motoHtml = '<div class="sect" style="margin-top:12px">🛵 共享運具</div>' +
      '<p style="font-size:11.5px;color:var(--brown2);margin:2px 2px">即時車輛位置與租借請使用各家官方 App(無公開資料可介接)</p>' +
      '<div style="display:flex;gap:8px;flex-wrap:wrap;margin:6px 2px">' +
      '<a class="mini-btn line" style="text-decoration:none" target="_blank" rel="noopener" href="' + q('WeMo 電動機車租借') + '">🛵 WeMo 附近</a>' +
      '<a class="mini-btn line" style="text-decoration:none" target="_blank" rel="noopener" href="' + q('GoShare 租車') + '">⚡ GoShare 附近</a>' +
      '<a class="mini-btn line" style="text-decoration:none" target="_blank" rel="noopener" href="' + q('iRent 機車租賃') + '">🚗 iRent 附近</a>' +
      '<a class="mini-btn line" style="text-decoration:none" target="_blank" rel="noopener" href="' + q('GoSmart 租車') + '">🔑 GoSmart 附近</a>' +
      '</div>';
  }

  function render() {
    const catsEl = $('#cats');
    catsEl.innerHTML = CATS.map(c =>
      '<button type="button" class="pill ' + (current === c.id ? '' : 'line') + '" data-cat="' + c.id + '">' + c.label + '</button>'
    ).join('');
    catsEl.querySelectorAll('.pill').forEach(b => b.addEventListener('click', () => {
      const id = b.dataset.cat;
      current = (current === id) ? null : id;
      render();
      fetchSections();
    }));

    const listEl = $('#list');
    const secs = cache && cache.sections || {};
    const shown = current ? CATS.filter(c => c.id === current) : CATS;
    const html = [];
    for (const c of shown) {
      const s = secs[c.id];
      if (!s) continue;
      const items = (s.items || []).map(it =>
        '<a class="lrow" href="' + mapsUrl(it) + '" target="_blank" rel="noopener" style="text-decoration:none">' +
        '<div class="linfo"><b>' + esc(it.name) + '</b>' +
        '<span>' + esc(it.vicinity || '') + (it.distance_m != null ? '|' + fmtDist(it.distance_m) : '') +
        (it.rating ? '|⭐ ' + it.rating : '') +
        (it.rent ? '|月租 NT$' + Number(it.rent).toLocaleString('zh-TW') + '元' : '') +
        (it.sale_price ? '|開價 NT$' + Number(it.sale_price).toLocaleString('zh-TW') + '元' : '') +
        '</span></div><span class="st idle">📍</span></a>'
      ).join('');
      html.push('<div class="sect" style="margin-top:14px">' + CAT_LABEL[c.id] + '(' + (s.items || []).length + ')</div>' +
        (s.note ? '<p style="font-size:12px;color:var(--brown2);margin:4px 2px">' + esc(s.note) + '</p>' : '') +
        (items || '<p style="font-size:12.5px;color:var(--brown2);margin:4px 2px">附近 5 公里內沒有資料</p>'));
      if (c.id === 'transport') html.push(ubikeHtml + motoHtml);
    }
    listEl.innerHTML = html.join('');
  }

  async function fetchUbike() {
    try {
      const res = await fetch('api/life.php?property_id=' + propId + '&ubike=1', { cache: 'no-store' });
      const d = await res.json();
      if (!d.ok) return;
      if (!d.city_ok) {
        ubikeHtml = '';
        render();
        return;
      }
      ubikeHtml = '<div class="sect" style="margin-top:12px">🚴 UBike 站點即時</div>' +
        ((d.ubike || []).length
          ? d.ubike.map(s =>
            '<div class="lrow"><div class="linfo"><b>' + esc(s.name) + '</b>' +
            '<span>可借 ' + s.bikes + ' 台|空位 ' + s.slots + ' 格|' + fmtDist(s.dist) + '</span></div>' +
            '<a class="st idle" target="_blank" rel="noopener" style="text-decoration:none" href="https://www.google.com/maps/search/?api=1&query=' + s.lat + ',' + s.lng + '">📍</a></div>').join('')
          : '<p style="font-size:12.5px;color:var(--brown2);margin:4px 2px">附近沒有 UBike 站點資料</p>');
      render();
    } catch (e) { /* 靜默 */ }
  }

  async function fetchSections() {
    if (!cache) return;
    const cats = current || CATS.map(c => c.id).join(',');
    try {
      const res = await fetch('api/life.php?property_id=' + propId + '&cats=' + cats, { cache: 'no-store' });
      const d = await res.json();
      if (!d.ok) { showToast(d.message); return; }
      cache.sections = Object.assign(cache.sections || {}, d.sections || {});
      render();
    } catch (e) {
      showToast('連線異常,請稍後再試');
    }
  }

  async function load() {
    if (!propId) { showToast('請從房屋頁進入生活圈'); return; }
    let d;
    try {
      const res = await fetch('api/life.php?property_id=' + propId, { cache: 'no-store' });
      d = await res.json();
    } catch (e) {
      showToast('連線異常,請稍後再試');
      return;
    }
    if (!d.ok || !d.authenticated) { location.href = 'login.html'; return; }
    cache = d;
    $('#propTitle').textContent = '🏠 ' + d.property.title;
    $('#propAddr').textContent = (d.property.address || '') + '|半徑 ' + (d.radius_m / 1000) + ' 公里' + (d.property.lat ? '' : '|⚠ 尚未定位成功');
    if (d.geo_error) showToast(d.geo_error);
    try {
      const me = await (await fetch('api/me.php', { cache: 'no-store' })).json();
      if (me.ok && me.authenticated) {
        const role = me.user.registration_role;
        const backMap = { manager: 'dashboard_manager.html', tenant: 'dashboard_tenant.html', company_owner: 'dashboard_company.html', admin_staff: 'dashboard_company.html', accountant: 'dashboard_company.html', landlord: 'dashboard.html', agent: 'dashboard_agent.html' };
        if (backMap[role]) $('#navBack').setAttribute('href', backMap[role]);
      }
    } catch (e) { /* 忽略 */ }
    buildMoto();
    render();
    fetchUbike();
  }

  load();
})();
