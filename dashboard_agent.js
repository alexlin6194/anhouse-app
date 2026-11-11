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
          const price = isSale ? '開價 ' + money(l.sale_price) : '月租 ' + money(l.rent);
          const info = [typeNames[l.property_type] || '', layoutText(l), price].filter(Boolean).join('|');
          const dot = isSale ? 'var(--pink)' : (l.status === 'rented' || l.status === 'sold' ? 'var(--green)' : 'var(--beige2)');
          return '<div class="list-item"><span class="dot" style="background:' + dot + '"></span>' +
            '<div><b>' + (isSale ? '[售] ' : '[租] ') + l.title + '</b><span>' + info + '</span></div>' + st + '</div>';
        }).join('')
      : '<p style="padding:14px 2px;color:var(--brown2);font-size:13px;margin:0">尚無房源:點右上「+ 建立房源」開始建檔(591 規格欄位,支援出租/出售)。</p>';
  }

  load();
})();
