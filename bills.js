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
  const dstr = (d) => d ? String(d).replace(/-/g, '/') : '';
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const today = new Date();
  const todayStr = today.getFullYear() + '-' + String(today.getMonth() + 1).padStart(2, '0') + '-' + String(today.getDate()).padStart(2, '0');
  const dayDiff = (d) => Math.floor((new Date(todayStr) - new Date(d)) / 86400000);

  function overdueDays(b) { return b.status === 'pending' && b.due_date ? dayDiff(b.due_date) : 0; }

  function sortBills(arr) {
    const rank = (b) => {
      if (overdueDays(b) > 0) return 0;
      if (b.status === 'draft') return 1;
      if (b.status === 'pending') return 2;
      return 3;
    };
    return arr.slice().sort((a, b) => {
      const ra = rank(a), rb = rank(b);
      if (ra !== rb) return ra - rb;
      if (overdueDays(a) > 0 && overdueDays(b) > 0) return String(a.due_date).localeCompare(String(b.due_date));
      return String(a.period).localeCompare(String(b.period)) || a.id - b.id;
    });
  }

  function chip(b) {
    const od = overdueDays(b);
    if (b.status === 'draft') return '<span class="st idle">草稿|待抄錄</span>';
    if (b.status === 'paid' && b.lease_start && String(b.period) === String(b.lease_start).slice(0, 7)) return '<span class="st ok">簽約已繳</span>';
    if (b.status === 'paid') return '<span class="st ok">已繳</span>';
    if (od > 0) return '<span class="st overdue">逾期 ' + od + ' 天</span>';
    return '<span class="st warn">待繳</span>';
  }

  let cache = null;
  let openId = null;

  function render() {
    const data = cache;
    const mine = data.bills && data.bills.length ? data.bills : (data.my_bills || []);
    const list = sortBills(mine);
    let od = 0, pend = 0, paid = 0;
    mine.forEach(b => {
      if (b.status === 'paid') paid++;
      else {
        pend += Number(b.amount || 0);
        if (overdueDays(b) > 0) od++;
      }
    });
    $('#stOverdue').textContent = od;
    $('#stPending').textContent = '$' + money(pend) + '元';
    $('#stPaid').textContent = paid;

    const listEl = $('#list');
    if (!list.length) {
      listEl.innerHTML = '<p style="padding:18px 4px;text-align:center;color:var(--brown2);font-size:13px;margin:0">🌿 目前沒有帳單</p>';
      return;
    }
    listEl.innerHTML = list.map(b => {
      const od2 = overdueDays(b);
      const isSign = b.status === 'paid' && b.lease_start && String(b.period) === String(b.lease_start).slice(0, 7);
      const dd = od2 > 0 ? '|到期 ' + dstr(b.due_date) : (b.status === 'pending' ? '|' + dstr(b.due_date) + ' 到期' : '');
      return '<div class="lrow bill-row" data-id="' + b.id + '" style="cursor:pointer">' +
        '<div class="ltop"><div class="linfo">' +
        '<b>🏠 ' + esc(b.property_title) + '<span style="font-weight:400;font-size:12px;color:var(--brown2)">|' + esc(b.period) + '月</span></b>' +
        '<span>' + (b.status === 'draft' ? '草稿 NT$' : (b.electric_amount == null ? '應收 NT$' : '總計 NT$')) + money(b.amount) + '元' + (isSign ? '|簽約當天已收' : '') + dd + '</span>' +
        '</div>' + chip(b) + '</div>' +
        '<div class="bdetail" id="bd-' + b.id + '" style="display:none"></div>' +
        '</div>';
    }).join('');
    listEl.querySelectorAll('.bill-row').forEach(row => {
      row.addEventListener('click', () => toggleDetail(row, Number(row.dataset.id)));
    });
  }

  async function toggleDetail(row, id) {
    const panel = row.querySelector('.bdetail');
    if (openId === id) {
      panel.style.display = 'none';
      openId = null;
      return;
    }
    document.querySelectorAll('.bdetail').forEach(p => { p.style.display = 'none'; });
    openId = id;
    panel.style.display = '';
    panel.innerHTML = '<p style="padding:8px 2px;color:var(--brown2);font-size:12.5px;margin:0">載入中…</p>';
    let d;
    try {
      const res = await fetch('api/bills.php?id=' + id, { cache: 'no-store' });
      d = await res.json();
    } catch (e) {
      panel.innerHTML = '<p style="color:var(--err);font-size:12.5px">載入失敗,請稍後再試</p>';
      return;
    }
    if (!d.ok) {
      panel.innerHTML = '<p style="color:var(--err);font-size:12.5px">' + esc(d.message) + '</p>';
      return;
    }
    const b = d.bill;
    const addr = [b.city, b.district, b.address].filter(Boolean).join('');
    const propSt = b.property_status === 'rented' ? '出租中' : (b.property_status === 'vacant' ? '空置' : (b.property_status === 'for_sale' ? '出售中' : (b.property_status === 'sold' ? '已售出' : String(b.property_status || ''))));
    const items = [];
    items.push(['租金', b.rent_amount]);
    const isSignDetail = b.start_date && String(b.period) === String(b.start_date).slice(0, 7);
    if (isSignDetail) {
      items.push(['電費:併入下期(簽約基準 ' + money(b.lease_meter_start ?? 0) + ' 度)', null, '']);
    } else {
      const eRateD = (b.elec_rate != null && Number(b.elec_rate) > 0) ? Number(b.elec_rate) : 5;
      let eLabel;
      if (b.electric_amount === null || b.electric_amount === '') {
        eLabel = ['電費', null, '(待抄錄)'];
      } else {
        const prev = b.prev_reading != null ? Number(b.prev_reading) : null;
        const cur = b.meter_reading != null ? Number(b.meter_reading) : null;
        if (cur != null && prev != null) {
          const usage = cur - prev;
          eLabel = b.elec_mode === 'taipower'
            ? ['電費(用電 ' + money(usage) + ' 度,台電累進計價)', b.electric_amount, '']
            : ['電費(用電 ' + money(usage) + ' 度 × NT$' + (eRateD % 1 ? eRateD.toFixed(1) : eRateD) + '元/度)', b.electric_amount, ''];
        } else {
          eLabel = ['電費', b.electric_amount, ''];
        }
      }
      items.push(eLabel);
    }
    items.push(['管理費', b.mgmt_amount]);
    items.push(['車位費', b.parking_amount]);
    items.push(['雜費', b.misc_amount]);
    const rows = items.filter(i => (i[1] !== null && i[1] !== '' && Number(i[1]) !== 0) || i[2])
      .map(i => '<div style="display:flex;justify-content:space-between"><span>' + i[0] + (i[2] || '') + '</span><b>' + (i[1] === null || i[1] === '' ? '—' : 'NT$' + money(i[1]) + '元') + '</b></div>').join('');
    const isTenantView = !(cache.bills && cache.bills.length) && !!(cache.my_bills && cache.my_bills.length);
    const canRemind = b.status === 'pending' && b.tenant_name && !isTenantView;
    const draftBanner = b.status === 'draft'
      ? '<div style="background:var(--cream2);border:1.5px dashed var(--beige2);border-radius:12px;padding:8px 12px;font-size:12px;color:var(--brown2);margin:8px 0">📋 草稿帳單:房客還看不到,' + dstr(b.due_date) + ' 0 點自動轉正式並通知房客。請先完成電錶抄錄。</div>'
      : '';
    const meterBtn = !isTenantView && b.status !== 'paid' && b.electric_amount == null && !isSignDetail
      ? '<button type="button" class="mini-btn act-meter" data-id="' + b.id + '">⚡ 抄錄電費(須拍電錶照片)</button>'
      : '';
    const proofImg = b.paid_proof
      ? '<div class="bd-sec">🧾 轉帳證明</div><img src="uploads/' + esc(b.paid_proof) + '" alt="轉帳證明" data-proof="uploads/' + esc(b.paid_proof) + '" style="max-width:100%;max-height:220px;border-radius:12px;border:1.5px solid var(--beige2);cursor:zoom-in">'
      : '';
    const undoBtn = b.status === 'paid' && b.paid_by === 'tenant' && !isTenantView
      ? '<button type="button" class="mini-btn line act-undo" data-id="' + b.id + '">↩ 未收到錢?退回為待繳</button>'
      : '';
    const payBtn2 = isTenantView && b.status === 'pending'
      ? '<button type="button" class="mini-btn act-payonline" data-id="' + b.id + '" style="background:var(--wood-d);color:#FFF9EC">💳 線上付款(藍新)</button><button type="button" class="mini-btn act-pay" data-id="' + b.id + '">轉帳證明回報</button>'
      : '';
    panel.innerHTML =
      '<div style="border-top:1.5px dashed var(--beige2);margin-top:10px;padding-top:10px">' +
      '<div class="bd-sec">🏠 房屋狀況</div>' +
      '<div style="font-size:12.5px;line-height:1.8">' + esc(addr || b.title) + '<br>狀態:<b>' + esc(propSt) + '</b>' +
      (b.start_date ? '|租期 ' + dstr(b.start_date) + '~' + (b.end_date ? dstr(b.end_date) : '不定期') : '') + '</div>' +
      (b.tenant_name
        ? '<div class="bd-sec">👤 房客資料</div><div style="font-size:12.5px;line-height:1.8">' + esc(b.tenant_name) + '(' + esc(b.tenant_phone) + ')' +
          (b.payment_day ? '|每月 ' + b.payment_day + ' 日繳租' : '') + '</div>'
        : '') +
      '<div class="bd-sec">🧾 帳單明細|' + esc(b.period) + '月</div>' +
      '<div style="font-size:12.5px;line-height:1.9;display:grid;gap:2px">' + rows +
      '<div style="display:flex;justify-content:space-between;border-top:1.5px solid var(--beige2);padding-top:4px"><span><b>應收總額</b></span><b style="color:var(--wood-d)">NT$' + money(b.amount) + '元</b></div>' +
      '<div style="color:var(--brown2);font-size:11.5px">到期日:' + dstr(b.due_date) + (b.status === 'paid' ? '|已於 ' + dstr(b.paid_at) + ' 繳納' + (b.paid_by === 'tenant' ? '(房客回報)' : '') : '') + '</div></div>' +
      proofImg + meterBtn + payBtn2 + undoBtn +
      (canRemind ? '<button type="button" class="mini-btn act-remind" data-id="' + b.id + '">📣 發送' + (overdueDays(b) > 0 ? '催繳' : '繳費提醒') + '通知</button>' : '') +
      '</div>';
    panel.querySelector('.act-remind')?.addEventListener('click', async (e) => {
      e.stopPropagation();
      const btn = e.currentTarget;
      btn.disabled = true;
      try {
        const res = await fetch('api/bills.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ bill_id: Number(btn.dataset.id), action: 'remind' })
        });
        const r = await res.json();
        showToast(r.message);
      } catch (err) {
        showToast('連線異常,請稍後再試');
      } finally {
        btn.disabled = false;
      }
    });

    panel.querySelector('.act-undo')?.addEventListener('click', async (e) => {
      e.stopPropagation();
      const btn = e.currentTarget;
      btn.disabled = true;
      try {
        const res = await fetch('api/bills.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ bill_id: Number(btn.dataset.id), action: 'undo_paid' })
        });
        const r = await res.json();
        showToast(r.message);
        if (r.ok) { openId = null; load(); }
      } catch (err) {
        showToast('連線異常,請稍後再試');
      } finally {
        btn.disabled = false;
      }
    });

    panel.querySelector('.act-pay')?.addEventListener('click', (e) => {
      e.stopPropagation();
      payBillId = Number(e.currentTarget.dataset.id);
      payFile.click();
    });

    panel.querySelector('.act-payonline')?.addEventListener('click', async (e) => {
      e.stopPropagation();
      const btn = e.currentTarget;
      btn.disabled = true;
      try {
        const res = await fetch('api/bills.php', {
          method: 'POST', headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ bill_id: Number(btn.dataset.id), action: 'pay_link' })
        });
        const r = await res.json();
        if (r.ok && r.pay_url) { location.href = r.pay_url; return; }
        if (r.ok && r.gateway && r.trade_info) {
          const f = document.createElement('form');
          f.method = 'POST'; f.action = r.gateway;
          for (const [k, v] of Object.entries(r.trade_info)) {
            const hid = document.createElement('input');
            hid.type = 'hidden'; hid.name = k; hid.value = v;
            f.appendChild(hid);
          }
          document.body.appendChild(f); f.submit(); return;
        }
        showToast(r.message || '付款連結產生失敗');
      } catch (err) { showToast('連線異常'); }
      btn.disabled = false;
    });

    panel.querySelector('.act-meter')?.addEventListener('click', (e) => {
      e.stopPropagation();
      if (window.meterPrompt) window.meterPrompt(e.currentTarget.dataset.id, () => { openId = null; load(); });
    });

    panel.querySelector('img[data-proof]')?.addEventListener('click', (e) => {
      e.stopPropagation();
      if (window.showLightbox) window.showLightbox(e.currentTarget.dataset.proof, '轉帳證明|' + b.period + '月');
    });
  }


  let payBillId = null;
  const payFile = document.createElement('input');
  payFile.type = 'file';
  payFile.accept = 'image/*';
  payFile.setAttribute('capture', 'environment');
  payFile.style.display = 'none';
  document.body.appendChild(payFile);
  payFile.addEventListener('change', async () => {
    const f = payFile.files && payFile.files[0];
    if (!f || !payBillId) return;
    const billIdAtPick = payBillId;
    payBillId = null;
    let buf, mime, fname;
    try {
      buf = await f.arrayBuffer();
      mime = f.type || 'image/jpeg';
      fname = f.name || 'proof.jpg';
    } catch (err) {
      showToast('讀取照片失敗,請重新拍攝');
      return;
    }
    payFile.value = '';
    const fd = new FormData();
    fd.append('action', 'pay_proof');
    fd.append('bill_id', String(billIdAtPick));
    fd.append('photo', new Blob([buf], { type: mime }), fname);
    fd.append('pay_meta', JSON.stringify({ last_modified: f.lastModified || Date.now(), picked_at: Date.now() }));
    showToast('上傳中…');
    try {
      const res = await fetch('api/bills.php', { method: 'POST', body: fd });
      const d = await res.json();
      showToast(d.message);
      if (d.ok) { openId = null; load(); }
    } catch (e) {
      showToast('連線異常,請稍後再試');
    }
  });

  async function load() {
    let data;
    try {
      const res = await fetch('api/bills.php', { cache: 'no-store' });
      data = await res.json();
    } catch (e) {
      showToast('連線異常,請稍後再試');
      return;
    }
    if (!data.ok || !data.authenticated) { location.href = 'login.html'; return; }
    if (data.my_role) { /* noop */ }
    const roleBack = { manager: 'dashboard_manager.html', tenant: 'dashboard_tenant.html', company_owner: 'dashboard_company.html', admin_staff: 'dashboard_company.html', accountant: 'dashboard_company.html' };
    cache = data;
    const isTenant = !(data.bills && data.bills.length) && data.my_bills && data.my_bills.length;
    fetch('api/me.php', { cache: 'no-store' }).then(r => r.json()).then(me => {
      if (me.ok && me.authenticated && roleBack[me.user.registration_role]) {
        $('#navBack').setAttribute('href', roleBack[me.user.registration_role]);
      }
    });
    render();
  }

  load();
})();
