(function () {
  'use strict';
  const $ = (s) => document.querySelector(s);
  let toastTimer;
  function showToast(msg) {
    const t = $('#toast');
    t.textContent = msg;
    t.classList.add('show');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => t.classList.remove('show'), 2600);
  }
  const money = (n) => Number(n || 0).toLocaleString('zh-TW');
  const dstr = (d) => d ? String(d).replace(/-/g, '/').replace('T', ' ').slice(0, 16) : '';
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  const catIcon = { '水電': '💡', '門鎖': '🔑', '家電': '🔌', '網路': '📡', '漏水': '💧', '其他': '🔧' };
  const statusMap = {
    pending: { label: '待處理', cls: 'warn' },
    assigned: { label: '已派工', cls: 'idle' },
    inspected: { label: '已查修·報價待核', cls: 'warn' },
    repairing: { label: '維修中', cls: 'idle' },
    done: { label: '完工待收費', cls: 'warn' },
    billed: { label: '已收費結案', cls: 'ok' },
    cancelled: { label: '已取消', cls: 'idle' }
  };

  let cache = null;
  let openId = null;
  let canManageAny = false;
  let urgent = 'normal';
  let photoFiles = [];

  function render() {
    const q = ($('#repairSearch').value || '').trim().toLowerCase();
    let rows = (cache && cache.repairs) || [];
    if (q) {
      rows = rows.filter(r =>
        String(r.property_title || '').toLowerCase().includes(q) ||
        String(r.description || '').toLowerCase().includes(q) ||
        String(r.category || '').includes(q));
    }
    const listEl = $('#list');
    if (!rows.length) {
      listEl.innerHTML = '<p style="padding:18px 4px;text-align:center;color:var(--brown2);font-size:13px;margin:0">' +
        (q ? '找不到符合的報修' : '🌿 目前沒有報修紀錄:點右上「+ 新增報修」開始') + '</p>';
      return;
    }
    listEl.innerHTML = rows.map(r => {
      const st = statusMap[r.status] || { label: r.status, cls: 'idle' };
      const urgentChip = r.urgency === 'urgent' && !['done', 'cancelled', 'billed'].includes(r.status) ? '<span class="st overdue">緊急</span>' : '';
      const vendorTxt = r.vendor_user_id && r.company_name ? '|🧰 ' + esc(r.company_name) : '';
      const preview = r.photos && r.photos.length
        ? '<div class="lstrip">' + r.photos.slice(0, 3).map(ph => '<span class="mini-thumb" style="background-image:url(\'uploads/' + esc(ph) + '\');cursor:zoom-in" data-full="uploads/' + esc(ph) + '"></span>').join('') + '</div>'
        : '';
      return '<div class="lrow repair-row" data-id="' + r.id + '" style="cursor:pointer">' +
        '<div class="ltop"><div class="linfo">' +
        '<b>' + (catIcon[r.category] || '🔧') + ' ' + esc(r.category) + '|' + esc(r.property_title) + '</b>' +
        '<span>' + esc(String(r.description || '').slice(0, 36)) + '…' + vendorTxt + '</span>' +
        '</div><span style="display:flex;gap:4px;flex-shrink:0">' + urgentChip + '<span class="st ' + st.cls + '">' + st.label + '</span></span></div>' +
        preview +
        '<div class="bdetail" id="rd-' + r.id + '" style="display:none"></div>' +
        '</div>';
    }).join('');
    listEl.querySelectorAll('.repair-row').forEach(row => {
      row.addEventListener('click', () => toggleDetail(row, Number(row.dataset.id)));
    });
    listEl.querySelectorAll('[data-full]').forEach(t => {
      t.addEventListener('click', (e) => {
        e.stopPropagation();
        if (window.showLightbox) window.showLightbox(t.dataset.full, '報修照片');
      });
    });
  }

  function toggleDetail(row, id) {
    const panel = row.querySelector('.bdetail');
    if (openId === id) {
      panel.style.display = 'none';
      openId = null;
      return;
    }
    document.querySelectorAll('.bdetail').forEach(p => { p.style.display = 'none'; });
    openId = id;
    panel.style.display = '';
    const r = (cache.repairs || []).find(x => Number(x.id) === id);
    if (!r) { panel.innerHTML = ''; return; }
    const st = statusMap[r.status] || { label: r.status, cls: 'idle' };
    const cm = canManageAny;
    const photoHtml = r.photos && r.photos.length
      ? '<div class="bd-sec">📸 現場照片</div><div style="display:flex;gap:8px;flex-wrap:wrap">' +
        r.photos.map(ph => '<img src="uploads/' + esc(ph) + '" data-full="uploads/' + esc(ph) + '" alt="報修照片" style="width:96px;height:96px;object-fit:cover;border-radius:12px;border:1.5px solid var(--beige2);cursor:zoom-in">').join('') + '</div>'
      : '';
    const solutionHtml = r.solution
      ? '<div class="bd-sec">✅ 完工說明</div><div style="font-size:12.5px;line-height:1.8">' + esc(r.solution) + '</div>'
      : '';
    const quoteHtml = (r.quote_text || r.quote_amount != null)
      ? '<div class="bd-sec">📋 查修報告</div><div style="font-size:12.5px;line-height:1.8">' + esc(r.quote_text || '') +
        (r.quote_amount != null ? '<br>預估費用:NT$' + money(r.quote_amount) : '') + '</div>'
      : '';
    const vendorHtml = r.vendor_user_id
      ? '<div class="bd-sec">🧰 維修廠商</div><div style="font-size:12.5px;line-height:1.8">' + esc(r.company_name || '') +
        (r.visit_at ? '|約定 ' + dstr(r.visit_at) : '') +
        (r.actual_amount != null ? '|廠商工款 NT$' + money(r.actual_amount) + (r.vendor_paid === 'yes' ? '(✓ 已付清' + dstr(r.vendor_paid_at) + ')' : '(未付)') : '') +
        (r.tenant_charge ? '|房客端:' + (r.tenant_charge === 'none' ? '屋主吸收' : (r.tenant_charge === 'bill' ? '併入帳單 NT$' + money(r.tenant_amount || 0) : '現金收取 NT$' + money(r.tenant_amount || 0))) : '') +
        (r.paid_method ? '' : '') + '</div>'
      : '';
    const btns = [];
    const amIWorker = (cache.my_role === 'vendor') && Number(r.vendor_user_id) === Number(cache.my_id);
    if (cm && r.status === 'pending') {
      btns.push('<button type="button" class="mini-btn act-assign" data-id="' + r.id + '">🧰 派工給廠商</button>');
      btns.push('<button type="button" class="mini-btn line act-cancel" data-id="' + r.id + '">取消報修</button>');
    }
    if (cm && r.status === 'inspected') {
      btns.push('<button type="button" class="mini-btn act-doc-charge" data-id="' + r.id + '">📄 發自費確認函(房客同意後施工)</button>');
      btns.push('<button type="button" class="mini-btn act-approve" data-id="' + r.id + '">✅ 直接核准施工</button>');
      btns.push('<button type="button" class="mini-btn line act-reject" data-id="' + r.id + '">↩ 退回重新評估</button>');
    }
    if (amIWorker && r.status === 'assigned') btns.push('<button type="button" class="mini-btn act-quote" data-id="' + r.id + '">📋 查修回報(原因+報價)</button>');
    if (amIWorker && ['repairing', 'assigned', 'inspected'].includes(r.status)) btns.push('<button type="button" class="mini-btn act-complete" data-id="' + r.id + '">🏁 完工回報(費用+說明+照片)</button>');
    if (cm && r.status === 'done') {
      btns.push('<button type="button" class="mini-btn act-doc-charge" data-id="' + r.id + '">📄 發自費確認函</button>');
      btns.push('<button type="button" class="mini-btn act-charge-none" data-id="' + r.id + '">🏠 房東吸收(不向房客收)</button>');
    }
    if (cm && r.vendor_user_id && r.vendor_paid !== 'yes' && ['inspected', 'repairing', 'done', 'billed'].includes(r.status)) {
      const fee = r.actual_amount != null ? r.actual_amount : r.quote_amount;
      btns.push('<button type="button" class="mini-btn line act-pay-vendor" data-id="' + r.id + '">🧾 付清廠商工款' + (fee ? '(NT$' + money(fee) + ')' : '') + '</button>');
    }
    if (cm && r.creator_phone && !amIWorker) btns.push('<button type="button" class="mini-btn line act-chat" data-phone="' + esc(r.creator_phone) + '">💬 聯絡報修人</button>');
    if (cm && r.vendor_user_id) {
      const vp = ((cache.vendors || []).find(v => Number(v.user_id) === Number(r.vendor_user_id)) || {}).phone;
      if (vp) btns.push('<button type="button" class="mini-btn line act-chat" data-phone="' + esc(vp) + '">📞 聯絡廠商</button>');
    }
    if (!cm && r.status === 'pending' && Number(r.created_by) === Number(cache.my_id || 0)) btns.push('<button type="button" class="mini-btn line act-cancel" data-id="' + r.id + '">取消報修</button>');
    panel.innerHTML =
      '<div style="border-top:1.5px dashed var(--beige2);margin-top:10px;padding-top:10px">' +
      '<div style="font-size:12.5px;line-height:1.9;color:var(--brown)">' + esc(r.description) + '</div>' +
      '<div style="font-size:11.5px;color:var(--brown2);margin-top:6px">狀態:' + st.label + '|建立:' + dstr(r.created_at) +
      (r.creator_name && !cm ? '|報修人:' + esc(r.creator_name) : '') + '</div>' +
      photoHtml + quoteHtml + vendorHtml + solutionHtml +
      (btns.length ? '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px">' + btns.join('') + '</div>' : '') +
      '</div>';
    panel.querySelectorAll('.act-start, .act-assign, .act-approve, .act-reject, .act-charge-bill, .act-charge-cash, .act-charge-none, .act-pay-vendor, .act-doc-charge, .act-cancel').forEach(b => {
      b.addEventListener('click', (e) => { e.stopPropagation(); handleStatus(b, e); });
    });
    panel.querySelector('.act-quote')?.addEventListener('click', (e) => {
      e.stopPropagation();
      const text = prompt('查修結果(原因診斷):');
      if (text === null || text.trim().length < 3) { showToast('請填寫查修結果(至少 3 字)'); return; }
      const amt = prompt('預估維修費用(NT$):');
      if (amt === null) return;
      act({ repair_id: Number(e.currentTarget.dataset.id), action: 'quote', quote_text: text.trim(), quote_amount: Number(String(amt).replace(/\D/g, '')) || 0 });
    });
    panel.querySelector('.act-complete')?.addEventListener('click', (e) => {
      e.stopPropagation();
      const text = prompt('完工說明(做了什麼):');
      if (text === null || text.trim().length < 3) { showToast('請填寫完工說明(至少 3 字)'); return; }
      const amt = prompt('實際費用(NT$):');
      if (amt === null) return;
      completeWithPhotos(Number(e.currentTarget.dataset.id), text.trim(), Number(String(amt).replace(/\D/g, '')) || 0);
    });
    panel.querySelector('.act-chat')?.addEventListener('click', async (e) => {
      e.stopPropagation();
      e.currentTarget.disabled = true;
      try {
        const res = await fetch('api/messages.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ phone: e.currentTarget.dataset.phone })
        });
        const d2 = await res.json();
        if (d2.ok && d2.redirect) location.href = d2.redirect;
        else { showToast(d2.message); e.currentTarget.disabled = false; }
      } catch (err) {
        showToast('連線異常,請稍後再試');
        e.currentTarget.disabled = false;
      }
    });
  }

  async function act(body) {
    try {
      const res = await fetch('api/repairs.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body)
      });
      const d = await res.json();
      showToast(d.message);
      if (d.ok) { openId = null; load(); }
    } catch (e) {
      showToast('連線異常,請稍後再試');
    }
  }

  async function load() {
    let data;
    try {
      const res = await fetch('api/repairs.php', { cache: 'no-store' });
      data = await res.json();
    } catch (e) {
      showToast('連線異常,請稍後再試');
      return;
    }
    if (!data.ok || !data.authenticated) { location.href = 'login.html'; return; }
    cache = data;
    const isVendor = data.my_role === 'vendor';
    canManageAny = !isVendor && data.my_role !== 'tenant';
    $('#vendorBtn').style.display = canManageAny ? '' : 'none';
    $('#newRepairBtn').style.display = isVendor ? 'none' : '';
    $('#searchWrap').style.display = isVendor ? 'none' : '';
    $('#listTitle').textContent = isVendor ? '我的維修工單' : '報修列表';
    if (isVendor && !$('#vBanner')) {
      const b = document.createElement('p');
      b.id = 'vBanner';
      b.style.cssText = 'font-size:12px;color:var(--brown2);margin:0 2px 6px';
      b.textContent = '🧰 維修廠商您好:這裡是派給您的工單,請依狀態回報查修/完工';
      $('#listTitle').after(b);
    }
    try {
      const me = await (await fetch('api/me.php', { cache: 'no-store' })).json();
      if (me.ok && me.authenticated) {
        const role = me.user.registration_role;
        const backMap = { manager: 'dashboard_manager.html', tenant: 'dashboard_tenant.html', company_owner: 'dashboard_company.html', admin_staff: 'dashboard_company.html', accountant: 'dashboard_company.html', landlord: 'dashboard.html' };
        if (backMap[role]) $('#navBack').setAttribute('href', backMap[role]);
        canManageAny = role !== 'tenant';
      }
    } catch (e) { /* 無 me 不影響 */ }
    render();
    fillProperties();
    renderVendors();
  }

  function renderVendors() {
    const box = $('#vendorList');
    if (!box) return;
    const vs = (cache && cache.vendors) || [];
    box.innerHTML = vs.length
      ? vs.map(v => '<div class="list-item" style="padding:8px 2px"><div><b>' + esc(v.company || v.name) + '</b><span>' + esc(v.name) + '|' + esc(v.phone || '') + (v.specialty ? '|' + esc(v.specialty) : '') + '</span></div></div>').join('')
      : '<p style="font-size:12px;color:var(--brown2);margin:4px 0">尚無廠商:點「+ 新增廠商」建立(系統會給一組臨時密碼,請轉告廠商)</p>';
  }

  $('#vendorBtn')?.addEventListener('click', () => {
    const c = $('#vendorCard');
    c.style.display = c.style.display === 'none' ? '' : 'none';
  });
  $('#vendorCloseBtn')?.addEventListener('click', () => { $('#vendorCard').style.display = 'none'; });
  $('#vendorAddBtn')?.addEventListener('click', async () => {
    const name = prompt('廠商聯絡人姓名:');
    if (name === null || name.trim().length < 2) { if (name !== null) showToast('請輸入姓名'); return; }
    const phone = prompt('廠商手機(09 開頭 10 碼,作為登入帳號):');
    if (phone === null || phone.trim() === '') return;
    const company = prompt('公司名稱(可留空):');
    if (company === null) return;
    const specialty = prompt('專長(例:水電/冷氣/木工,可留空):');
    if (specialty === null) return;
    const fd = new FormData();
    fd.append('action', 'create_vendor');
    fd.append('vendor_name', name.trim());
    fd.append('vendor_phone', phone.trim());
    fd.append('vendor_company', company.trim());
    fd.append('vendor_specialty', specialty.trim());
    try {
      const res = await fetch('api/repairs.php', { method: 'POST', body: fd });
      const d = await res.json();
      showToast(d.message + (d.temp_password ? '|臨時密碼:' + d.temp_password : ''));
      if (d.ok) { propOptions = null; load(); }
    } catch (e) {
      showToast('連線異常,請稍後再試');
    }
  });

  let propOptions = null;
  async function fillProperties() {
    if (propOptions === null) {
      try {
        const res = await fetch('api/leases.php', { cache: 'no-store' });
        const d = await res.json();
        if (d.ok) {
          propOptions = (d.properties || []).map(h => ({ id: h.id, title: h.title }));
          if (!propOptions.length && d.my_leases) {
            propOptions = d.my_leases.filter(x => x.property_id).map(x => ({ id: x.property_id, title: x.property_title }));
          }
        } else propOptions = [];
      } catch (e) {
        propOptions = [];
      }
    }
    const sel = $('#r_property');
    if (propOptions.length) {
      sel.innerHTML = propOptions.map(h => '<option value="' + h.id + '">' + esc(h.title) + '</option>').join('');
      $('#r_sendBtn').disabled = false;
    } else {
      sel.innerHTML = '<option value="">尚無可報修的房屋</option>';
      $('#r_sendBtn').disabled = true;
    }
  }

  document.querySelectorAll('.urg-opt').forEach(btn => {
    btn.addEventListener('click', () => {
      document.querySelectorAll('.urg-opt').forEach(b => { b.classList.remove('on'); b.classList.add('line'); });
      btn.classList.add('on');
      btn.classList.remove('line');
      urgent = btn.dataset.u;
    });
  });

  const photoBox = $('#r_photoBox');
  const photoInput = document.createElement('input');
  photoInput.type = 'file';
  photoInput.accept = 'image/*';
  photoInput.multiple = true;
  photoInput.style.display = 'none';
  document.body.appendChild(photoInput);
  photoBox.addEventListener('click', () => photoInput.click());
  photoInput.addEventListener('change', () => {
    const f = photoInput.files && photoInput.files[0];
    if (!f) return;
    if (photoFiles.length >= 3) { showToast('照片最多 3 張'); return; }
    photoFiles.push(f);
    photoInput.value = '';
    renderPreviews();
  });
  function renderPreviews() {
    $('#r_previews').innerHTML = photoFiles.map((f, i) =>
      '<span class="mini-thumb" style="width:56px;height:56px;display:flex;align-items:center;justify-content:center;font-size:11px;color:var(--brown2);background:var(--cream2);cursor:pointer" data-i="' + i + '">✕ 圖' + (i + 1) + '</span>').join('');
    $('#r_previews').querySelectorAll('.mini-thumb').forEach(t => {
      t.addEventListener('click', () => { photoFiles.splice(Number(t.dataset.i), 1); renderPreviews(); });
    });
  }

  $('#newRepairBtn').addEventListener('click', () => {
    const f = $('#repairForm');
    f.style.display = '';
    $('#newRepairBtn').style.display = 'none';
    f.scrollIntoView({ behavior: 'smooth', block: 'start' });
  });

  function handleStatus(btn, e) {
    const id = Number(btn.dataset.id);
    if (btn.classList.contains('act-assign')) {
      const vendors = cache.vendors || [];
      if (!vendors.length) { showToast('尚無廠商名單:請先點「🔧 廠商名單」新增'); return; }
      const list = vendors.map((v, i) => (i + 1) + '. ' + (v.company || v.name) + '(' + v.name + ')' + (v.specialty ? '-' + v.specialty : '')).join('\n');
      const pick = prompt('選擇廠商,輸入編號:\n' + list);
      if (pick === null) return;
      const idx = Number(String(pick).replace(/\D/g, '')) - 1;
      if (!vendors[idx]) { showToast('編號不正確'); return; }
      const when = prompt('約定查修時間(例:2026-11-15 14:00,可留空):');
      if (when === null) return;
      act({ repair_id: id, action: 'assign', vendor_user_id: Number(vendors[idx].user_id), visit_at: when.trim() });
      return;
    }
    if (btn.classList.contains('act-approve')) { act({ repair_id: id, action: 'approve' }); return; }
    if (btn.classList.contains('act-reject')) {
      const note = prompt('退回說明(可留空):');
      if (note === null) return;
      act({ repair_id: id, action: 'reject_quote', note: note.trim() });
      return;
    }
    if (btn.classList.contains('act-doc-charge')) {
      const r = (cache.repairs || []).find(x => Number(x.id) === id);
      const fee = r ? ((r.actual_amount != null ? r.actual_amount : r.quote_amount) || 0) : 0;
      const amt = prompt('向房客收取的金額(NT$):', fee ? String(fee) : '');
      if (amt === null) return;
      const how = prompt('收取方式:輸入 1=併入下期帳單 / 2=現金或轉帳\n(房客簽署確認函後才會收取)', '1');
      if (how === null) return;
      const payMethod = String(how).trim() === '2' ? 'cash' : 'bill';
      docWork = { fd: null, meta: { repair_id: id, amount: Number(String(amt).replace(/\D/g, '')) || 0, pay_method: payMethod } };
      const fd = new FormData();
      fd.append('action', 'create_repair_charge');
      fd.append('repair_id', String(id));
      fd.append('amount', String(docWork.meta.amount));
      fd.append('pay_method', payMethod);
      docWork.fd = fd;
      evidenceInput.click();
      return;
    }
    if (btn.classList.contains('act-charge-none')) { act({ repair_id: id, action: 'charge_tenant', method: 'none' }); return; }
    if (btn.classList.contains('act-pay-vendor')) {
      const r = (cache.repairs || []).find(x => Number(x.id) === id);
      const fee = r ? ((r.actual_amount != null ? r.actual_amount : r.quote_amount) || 0) : 0;
      const amt = prompt('付給廠商的金額(NT$):', fee ? String(fee) : '');
      if (amt === null) return;
      act({ repair_id: id, action: 'pay_vendor', amount: Number(String(amt).replace(/\D/g, '')) || 0 });
      return;
    }
    if (btn.classList.contains('act-charge-cash')) {
      const r = (cache.repairs || []).find(x => Number(x.id) === id);
      const amt = prompt('向房客收取的金額(NT$):', r && r.actual_amount != null ? String(r.actual_amount) : '');
      if (amt === null) return;
      act({ repair_id: id, action: 'charge_tenant', method: 'cash', amount: Number(String(amt).replace(/\D/g, '')) || 0 });
      return;
    }
    if (btn.classList.contains('act-cancel')) { act({ repair_id: id, action: 'cancel' }); }
  }

  let docWork = null;
  const evidenceInput = document.createElement('input');
  evidenceInput.type = 'file';
  evidenceInput.accept = 'image/*';
  evidenceInput.multiple = true;
  evidenceInput.style.display = 'none';
  document.body.appendChild(evidenceInput);
  evidenceInput.addEventListener('change', async () => {
    if (!docWork) return;
    const work = docWork;
    docWork = null;
    const files = evidenceInput.files || [];
    for (const f of files) {
      if (!f) continue;
      try {
        const buf = await f.arrayBuffer();
        work.fd.append('evidence', new Blob([buf], { type: f.type || 'image/jpeg' }), f.name || 'evidence.jpg');
        if (work.fd.getAll('evidence').length >= 5) break;
      } catch (e) { /* 跳過壞檔 */ }
    }
    evidenceInput.value = '';
    showToast('上傳中…');
    try {
      const res = await fetch('api/documents.php', { method: 'POST', body: work.fd });
      const d = await res.json();
      showToast(d.message);
      if (d.ok) { openId = null; load(); }
    } catch (e) {
      showToast('連線異常,請稍後再試');
    }
  });

  let completeWork = null;
  const completeInput = document.createElement('input');
  completeInput.type = 'file';
  completeInput.accept = 'image/*';
  completeInput.multiple = true;
  completeInput.style.display = 'none';
  document.body.appendChild(completeInput);
  completeInput.addEventListener('change', async () => {
    const work = completeWork;
    if (!work) return;
    completeWork = null;
    const files = completeInput.files || [];
    for (const f of files) {
      if (!f) continue;
      try {
        const buf = await f.arrayBuffer();
        work.fd.append('photos[]', new Blob([buf], { type: f.type || 'image/jpeg' }), f.name || 'photo.jpg');
      } catch (e) { /* 跳過壞檔 */ }
    }
    completeInput.value = '';
    showToast('上傳中…');
    try {
      const res = await fetch('api/repairs.php', { method: 'POST', body: work.fd });
      const d = await res.json();
      showToast(d.message);
      if (d.ok) { openId = null; load(); }
    } catch (e) {
      showToast('連線異常,請稍後再試');
    }
  });
  function completeWithPhotos(id, solution, amount) {
    const fd = new FormData();
    fd.append('action', 'complete');
    fd.append('repair_id', String(id));
    fd.append('solution', solution);
    fd.append('actual_amount', String(amount));
    completeWork = { fd };
    completeInput.click();
  }

  $('#repairSearch').addEventListener('input', render);

  $('#repairForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const pid = $('#r_property').value;
    if (!pid) { showToast('請先選擇房屋'); return; }
    const desc = $('#r_desc').value.trim();
    if (desc.length < 3) { showToast('請描述問題(至少 3 字)'); return; }
    const fd = new FormData();
    fd.append('action', 'create');
    fd.append('property_id', String(Number(pid)));
    fd.append('category', $('#r_category').value);
    fd.append('urgency', urgent);
    fd.append('description', desc);
    photoFiles.forEach((f, i) => fd.append('photos[]', f));
    const btn = $('#r_sendBtn');
    btn.disabled = true;
    try {
      const res = await fetch('api/repairs.php', { method: 'POST', body: fd });
      const d = await res.json();
      showToast(d.message);
      if (d.ok) {
        $('#r_desc').value = '';
        photoFiles = [];
        renderPreviews();
        $('#repairForm').style.display = 'none';
        $('#newRepairBtn').style.display = '';
        load();
      }
    } catch (err) {
      showToast('連線異常,請稍後再試');
    } finally {
      btn.disabled = false;
    }
  });

  load();
})();
