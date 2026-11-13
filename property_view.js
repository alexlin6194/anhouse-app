(function () {
  'use strict';
  const $ = (s) => document.querySelector(s);
  const money = (n) => Number(n || 0).toLocaleString('zh-TW');
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  }
  const dstr = (d) => d ? String(d).replace(/-/g, '/') : '';
  const ptypeNames = { whole_house: '整層住家', independent_suite: '獨立套房', shared_suite: '分租套房', studio: '雅房', apartment: '公寓/電梯大樓', building: '大樓/華廈', townhouse: '透天厝', other: '其他' };
  const token = new URLSearchParams(location.search).get('t') || '';

  async function load() {
    if (!token) { $('#pv').innerHTML = '<p style="padding:20px;text-align:center;color:var(--brown2)">連結無效</p>'; return; }
    let d;
    try {
      const res = await fetch('api/property_share.php?t=' + encodeURIComponent(token), { cache: 'no-store' });
      d = await res.json();
    } catch (e) { $('#pv').innerHTML = '<p style="padding:20px;text-align:center;color:var(--brown2)">連線異常,請稍後再試</p>'; return; }
    if (!d.ok) { $('#pv').innerHTML = '<p style="padding:20px;text-align:center;color:var(--brown2)">' + esc(d.message || '連結無效') + '</p>'; return; }

    const p = d.property;
    const photos = d.photos || [];
    const cover = photos.length ? photos.find(x => Number(x.is_cover)) || photos[0] : null;
    const gallery = photos.length > 1
      ? '<div class="gallery">' + photos.map(ph => '<img src="uploads/' + esc(ph.file_path) + '" alt="" data-full="uploads/' + esc(ph.file_path) + '" style="cursor:zoom-in">').join('') + '</div>'
      : '';

    const chips = [];
    chips.push('<span class="chip' + (p.listing_type === 'sale' ? ' sale' : '') + '">' + (p.listing_type === 'sale' ? '出售' : '出租') + '</span>');
    if (p.property_type && ptypeNames[p.property_type]) chips.push('<span class="chip">' + ptypeNames[p.property_type] + '</span>');
    if (p.layout_rooms != null && Number(p.layout_rooms) > 0) chips.push('<span class="chip">' + p.layout_rooms + '房' + p.layout_living + '廳' + p.layout_bath + '衛</span>');
    if (p.area_ping != null) chips.push('<span class="chip">' + p.area_ping + ' 坪</span>');
    if (p.floor_current != null && p.floor_current !== '') chips.push('<span class="chip">' + p.floor_current + (p.floor_total != null && p.floor_total !== '' ? '/' + p.floor_total : '') + ' 樓</span>');
    if (Number(p.parking)) chips.push('<span class="chip">車位</span>');
    if (Number(p.pet_friendly)) chips.push('<span class="chip">寵物</span>');
    if (Number(p.cooking_allowed)) chips.push('<span class="chip">開伙</span>');

    const statusTxt = p.listing_type === 'sale'
      ? (p.status === 'sold' ? '<span class="st ok">已售出</span>' : '<span class="st warn">銷售中</span>')
      : (p.status === 'rented' ? '<span class="st ok">已出租</span>' : '<span class="st warn">' + (Number(p.is_listed) ? '出租中' : '暫不刊登') + '</span>');

    const priceLine = p.listing_type === 'sale'
      ? '售價 NT$' + money(p.sale_price) + '元'
      : '月租 NT$' + money(p.rent) + '元' + (Number(p.mgmt_fee) > 0 ? '|管理費 NT$' + money(p.mgmt_fee) + '元' : '') + (Number(p.deposit) > 0 ? '|押金 NT$' + money(p.deposit) + '元' : '');
    const feeParts = [];
    if (Number(p.water_fee) > 0) feeParts.push('水費 ' + money(p.water_fee) + '元/月');
    if (Number(p.parking_fee) > 0) feeParts.push('車位費 ' + money(p.parking_fee) + '元/月');
    if (Number(p.cleaning_fee) > 0) feeParts.push('清潔費 ' + money(p.cleaning_fee) + '元/月');
    if (Number(p.internet_fee) > 0) feeParts.push('網路費 ' + money(p.internet_fee) + '元/月');
    if (Number(p.cable_fee) > 0) feeParts.push('第四台 ' + money(p.cable_fee) + '元/月');
    const feeLine = feeParts.length ? '<p style="font-size:12.5px;color:var(--brown2);margin:2px">💰 另計:' + feeParts.join('|') + '</p>' : '';

    $('#pv').innerHTML =
      '<div class="cover" style="min-height:170px;display:flex;align-items:center;justify-content:center;font-size:44px;' + (cover ? 'background-image:url(\'uploads/' + esc(cover.file_path) + '\');font-size:0' : '') + '">' + (cover ? '' : '🏠') + '</div>' +
      gallery +
      '<div style="display:flex;align-items:center;gap:10px"><h3 style="margin:0;font-size:19px;color:var(--brown)">' + esc(p.title) + '</h3>' + statusTxt + '</div>' +
      '<p style="font-size:14px;color:var(--brown);margin:6px 2px"><b>' + priceLine + '</b></p>' + feeLine +
      (p.city || p.district ? '<p style="font-size:13px;color:var(--brown2);margin:2px">📍' + esc([p.city, p.district].filter(Boolean).join('')) + '(詳細地址請洽業務)</p>' : '') +
      '<div class="kv">' + chips.join('') + '</div>' +
      (d.contact ? '<div style="background:var(--cream2);border-radius:14px;padding:12px 16px;margin:10px 2px;font-size:13.5px;color:var(--brown);line-height:1.9">📞 聯絡窗口:<b>' + esc(d.contact.name) + '</b> ' + esc(d.contact.phone) + '<br><span style="font-size:11.5px;color:var(--brown2)">看房請先來電預約</span></div>' : '') +
      (p.available_date && p.listing_type === 'rental' ? '<div class="sect">可遷入日</div><p style="font-size:13.5px;color:var(--brown);margin:2px">' + dstr(p.available_date) + '</p>' : '') +
      (p.facilities ? '<div class="sect">生活機能/設備</div><p style="font-size:13.5px;color:var(--brown);margin:2px">' + esc(p.facilities) + '</p>' : '') +
      (p.note ? '<div class="sect">備註</div><p style="font-size:13.5px;color:var(--brown);margin:2px">' + esc(p.note) + '</p>' : '');

    const coverEl = document.querySelector('#pv .cover');
    if (coverEl && cover) coverEl.addEventListener('click', () => { if (window.showLightbox) window.showLightbox('uploads/' + cover.file_path, p.title); });
    document.querySelectorAll('#pv .gallery img').forEach(g => {
      g.addEventListener('click', () => { if (window.showLightbox) window.showLightbox(g.dataset.full, p.title); });
    });
  }

  load();
})();
