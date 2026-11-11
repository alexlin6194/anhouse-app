(function () {
  'use strict';
  const $ = (s) => document.querySelector(s);
  let toastTimer;
  function showToast(msg) {
    const t = $('#toast');
    t.textContent = msg;
    t.classList.add('show');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => t.classList.remove('show'), 2400);
  }
  const money = (n) => Number(n || 0).toLocaleString('zh-TW');
  const dstr = (d) => d ? d.replace(/-/g, '/') : '';
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  }

  const id = new URLSearchParams(location.search).get('id');
  const ptypeNames = { whole_house: '整層住家', independent_suite: '獨立套房', shared_suite: '分租套房', studio: '雅房', apartment: '公寓/電梯大樓', building: '大樓/華廈', townhouse: '透天厝', other: '其他' };

  async function load() {
    let data;
    try {
      const res = await fetch('api/property_detail.php?id=' + encodeURIComponent(id || ''), { cache: 'no-store' });
      data = await res.json();
    } catch (e) {
      showToast('連線異常');
      return;
    }
    if (!data.ok || !data.authenticated) { location.href = 'login.html'; return; }
    if (data.view === 'manager') $('#navBack').setAttribute('href', 'dashboard_manager.html');
    if (data.view === 'tenant') $('#navBack').setAttribute('href', 'dashboard_tenant.html');
    render(data);
  }

  function render(data) {
    const p = data.property;
    const photos = data.photos || [];
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
    if (p.gender_limit && p.gender_limit !== 'any') chips.push('<span class="chip">' + (p.gender_limit === 'female' ? '限女性' : '限男性') + '</span>');
    if (p.legal_use) chips.push('<span class="chip">' + esc(p.legal_use) + '</span>');

    const priceLine = p.listing_type === 'sale'
      ? '售價 NT$' + money(p.sale_price)
      : '月租 NT$' + money(p.rent) + (p.mgmt_fee ? '|管理費 NT$' + money(p.mgmt_fee) : '') + (p.deposit ? '|押金 NT$' + money(p.deposit) : '');
    const loc = [p.city, p.district, p.address].filter(Boolean).join('');

    let stateHtml = '';
    if (p.pending_manager_id != null && p.pending_manager_name) {
      stateHtml = '<p style="color:var(--brown2);font-size:13px">⏳ 管理員 ' + esc(p.pending_manager_name) + ' 申請代管中,待屋主授權</p>';
    } else if (p.delegated_user_id != null && p.manager_name) {
      stateHtml = '<p style="color:var(--brown2);font-size:13px">🧑‍🔧 由 ' + esc(p.manager_name) + ' 代管中</p>';
    }

    let leaseHtml = '';
    const act = (data.leases || []).find(l => l.status === 'active');
    const pend = (data.leases || []).find(l => l.status === 'pending');
    if (act) {
      const startMonth = act.start_date ? String(act.start_date).slice(0, 7) : '';
      const eRate = (p.elec_rate != null && Number(p.elec_rate) > 0) ? Number(p.elec_rate) : 5;
      const rows = (act.bills || []).map(b => {
        const items = [];
        if (b.rent_amount != null) items.push('租金 ' + money(b.rent_amount));
        const isSignBill = b.period === startMonth;
        if (isSignBill) {
          items.push('電費:併入下期(簽約基準 ' + money(act.meter_start ?? 0) + ' 度)');
        } else if (b.electric_amount == null) {
          items.push('電費(待抄錄)');
        } else {
          const prev = b.prev_reading != null ? Number(b.prev_reading) : null;
          const cur = b.meter_reading != null ? Number(b.meter_reading) : null;
          if (cur != null && prev != null) {
            const usage = cur - prev;
            const f = p.elec_mode === 'taipower'
              ? '電費 ' + money(b.electric_amount) + '(用電 ' + money(usage) + ' 度,台電累進計價)'
              : '電費 ' + money(b.electric_amount) + '(用電 ' + money(usage) + ' 度 × NT$' + (eRate % 1 ? eRate.toFixed(1) : eRate) + '/度)';
            items.push(f);
          } else {
            items.push('電費 ' + money(b.electric_amount));
          }
        }
        if (b.mgmt_amount != null) items.push('管理費 ' + money(b.mgmt_amount));
        if (b.parking_amount) items.push('車位費 ' + money(b.parking_amount));
        if (b.misc_amount) items.push('雜費 ' + money(b.misc_amount) + (b.misc_note ? '(' + esc(b.misc_note) + ')' : ''));
        const meterBtn = data.can_manage && b.status !== 'paid' && !isSignBill
          ? '<div style="margin-top:6px"><button type="button" class="mini-btn act-meter" data-id="' + b.id + '">⚡ 抄錄電費(拍照辨識度數)</button></div>'
          : '';
        const remindBtn = data.can_manage && b.status === 'pending'
          ? '<button type="button" class="mini-btn line act-remind" data-id="' + b.id + '">📣 催繳通知</button>'
          : '';
        return '<div class="leasebox"><b>' + b.period + '月|' + (b.electric_amount == null ? '應收 NT$' + money(b.amount) : '總計 NT$' + money(b.amount)) + '</b>' +
          (b.status === 'paid' ? '<span class="st ok" style="float:right">已繳</span>' : (b.status === 'draft' ? '<span class="st idle" style="float:right">草稿|待抄錄</span>' : '<span class="st warn" style="float:right">待繳</span>')) +
          '<div style="font-size:12.5px;color:#4A3826;margin-top:4px">' + items.join(' + ') + '</div>' +
          (b.due_date && b.status !== 'paid' ? '<div style="font-size:12px;color:#7A6A55">' + dstr(b.due_date) + ' 前繳納' + (b.status === 'draft' ? '(屆時自動生效)' : '') + '</div>' : '') +
          meterBtn + remindBtn +
          '</div>';
      }).join('');
      leaseHtml = '<div class="sect">租約與帳單</div>' +
        '<p style="font-size:13.5px;color:var(--brown);margin:4px 2px">房客:' + esc(act.tenant_name) + '(' + esc(act.tenant_phone) + ')|租期 ' + dstr(act.start_date) + '~' + (act.end_date ? dstr(act.end_date) : '不定期') + '|月租 NT$' + money(act.rent_amount) + '</p>' +
        (data.can_manage && !data.is_tenant_here
          ? '<button type="button" class="mini-btn act-chat" data-uid="' + act.tenant_user_id + '">💬 直接聯絡房客</button>'
          : '') + rows;
    } else if (pend) {
      leaseHtml = '<div class="sect">租約</div><p style="font-size:13.5px;color:var(--brown2);margin:4px 2px">已發出租約邀請給 ' + esc(pend.tenant_name) + '(' + esc(pend.tenant_phone) + '),待房客回覆。</p>' +
        (data.can_manage && !data.is_tenant_here
          ? '<button type="button" class="mini-btn act-chat" data-uid="' + pend.tenant_user_id + '">💬 直接聯絡房客</button>'
          : '');
    } else if (data.can_manage) {
      leaseHtml = '<div class="sect">租約</div><p style="font-size:13.5px;color:var(--brown2);margin:4px 2px">尚未簽約。找到房客後即可發出租約邀請。</p>' +
        '<button type="button" class="btn primary" onclick="location.href=\'leases.html?property_id=' + p.id + '\'">簽租約(發邀請)</button>';
    }

    const facilities = p.facilities ? '<div class="sect">生活機能/設備</div><p style="font-size:13.5px;color:var(--brown);margin:2px">' + esc(p.facilities) + '</p>' : '';
    const noteHtml = p.note ? '<div class="sect">備註</div><p style="font-size:13.5px;color:var(--brown);margin:2px">' + esc(p.note) + '</p>' : '';
    const avail = (p.available_date && p.listing_type === 'rental') ? '<div class="sect">可遷入日</div><p style="font-size:13.5px;color:var(--brown);margin:2px">' + dstr(p.available_date) + '</p>' : '';

    $('#detail').innerHTML =
      '<div class="cover" style="' + (cover ? 'background-image:url(\'uploads/' + esc(cover.file_path) + '\')' : '') + '">' + (cover ? '' : '🏠') + '</div>' +
      gallery +
      '<div style="display:flex;align-items:flex-start;gap:10px"><h3 style="margin:0;font-size:19px;color:var(--brown)">' + esc(p.title) + '</h3></div>' +
      stateHtml +
      '<p style="font-size:14px;color:var(--brown);margin:6px 2px"><b>' + priceLine + '</b></p>' +
      (loc ? '<p style="font-size:13px;color:var(--brown2);margin:2px">📍' + esc(loc) + '</p>' : '') +
      '<div class="kv">' + chips.join('') + '</div>' +
      (p.min_lease ? '<p style="font-size:13px;color:var(--brown2);margin:2px">最短租期:' + esc(p.min_lease) + '</p>' : '') +
      avail + facilities + noteHtml + leaseHtml;

    const coverEl = document.querySelector('#detail .cover');
    if (coverEl && cover) {
      coverEl.style.cursor = 'zoom-in';
      coverEl.addEventListener('click', () => {
        if (window.showLightbox) window.showLightbox('uploads/' + cover.file_path, p.title);
      });
    }
    document.querySelectorAll('#detail .gallery img').forEach(g => {
      g.addEventListener('click', (e) => {
        e.stopPropagation();
        if (window.showLightbox) window.showLightbox(g.dataset.full, p.title);
      });
    });

    document.querySelectorAll('.act-chat').forEach(btn => {
      btn.addEventListener('click', async () => {
        btn.disabled = true;
        try {
          const res = await fetch('api/messages.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ user_id: Number(btn.dataset.uid) })
          });
          const d = await res.json();
          if (d.ok && d.redirect) location.href = d.redirect;
          else { showToast(d.message); btn.disabled = false; }
        } catch (e) {
          showToast('連線異常,請稍後再試');
          btn.disabled = false;
        }
      });
    });

    document.querySelectorAll('.act-meter').forEach(btn => {
      btn.addEventListener('click', (e) => {
        e.stopPropagation();
        if (window.meterPrompt) window.meterPrompt(btn.dataset.id, load);
      });
    });

    document.querySelectorAll('.act-remind').forEach(btn => {
      btn.addEventListener('click', async (e) => {
        e.stopPropagation();
        btn.disabled = true;
        try {
          const res = await fetch('api/bills.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ bill_id: Number(btn.dataset.id), action: 'remind' })
          });
          const d = await res.json();
          showToast(d.message);
        } catch (err) {
          showToast('連線異常,請稍後再試');
        } finally {
          btn.disabled = false;
        }
      });
    });
  }

  load();
})();
