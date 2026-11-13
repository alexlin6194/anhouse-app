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

  /* ── 工款請款/付款(藍新) ─────────────────── */
  let payItems = [];
  let payByRepair = {};
  let billRepairId = 0;
  let billProofFiles = [];
  let billPhotoFiles = [];
  let billNeedComplete = false;
  const payState = { pending: { label: '待付', cls: 'warn' }, reminded: { label: '已催收', cls: 'warn' }, paid: { label: '已付', cls: 'ok' }, failed: { label: '付款失敗', cls: 'bad' } };

  function payReqHtml(cm, r, amIWorker) {
    const vp = payByRepair[r.id];
    if (amIWorker && r.vendor_paid !== 'yes' && ['assigned', 'inspected', 'repairing', 'done', 'billed'].includes(r.status)) {
      if (!vp) {
        if (['assigned', 'inspected', 'repairing'].includes(r.status)) {
          return '<div class="bd-sec">🧾 完工+請款</div><div style="margin-top:4px"><button type="button" class="mini-btn act-complete-bill" data-id="' + r.id + '">🏁 完工+請款(說明+照片+憑證)</button><div style="font-size:11.5px;color:var(--brown2);margin-top:4px">一次完成:完工回報+工款請款,屋主查看憑證後直接線上付款</div></div>';
        }
        return '<div class="bd-sec">🧾 請款</div><div style="margin-top:4px"><button type="button" class="mini-btn act-bill" data-id="' + r.id + '">向屋主/管理員請款(附憑證)</button></div>';
      }
      const ps = payState[vp.status] || { label: vp.status, cls: 'warn' };
      return '<div class="bd-sec">🧾 請款</div><div style="font-size:12.5px;line-height:1.9">已請款 <b>NT$' + money(vp.amount) + '元</b>' +
        (vp.paid_at ? '|✅ 已付款(' + dstr(vp.paid_at) + ')' : '|' + ps.label + '(' + dstr(vp.created_at) + ')' +
        (vp.status !== 'reminded' ? ' <button type="button" class="mini-btn line act-remind" data-id="' + vp.id + '">發送催收提醒</button>' : '')) + '</div>';
    }
    if (cm && vp) {
      const ps = payState[vp.status] || { label: vp.status, cls: 'warn' };
      const proofs = vp.proof ? (JSON.parse(vp.proof) || []) : [];
      return '<div class="bd-sec">🧾 廠商請款</div><div style="font-size:12.5px;line-height:1.9">廠商請款 <b>NT$' + money(vp.amount) + '元</b>|' + ps.label +
        (vp.note ? '|備註:' + esc(vp.note) : '') +
        (proofs.length ? '<div style="display:flex;gap:6px;flex-wrap:wrap;margin:6px 0">' + proofs.map(ph => '<img src="uploads/' + esc(ph) + '" data-full="uploads/' + esc(ph) + '" alt="請款憑證" style="width:84px;height:84px;object-fit:cover;border-radius:10px;border:1.5px solid var(--beige2);cursor:zoom-in">').join('') + '</div>' : '') +
        (vp.status !== 'paid' ? '<button type="button" class="mini-btn act-payonline" data-id="' + r.id + '">💳 藍新付款 NT$' + money(vp.amount) + '元</button>' : '') +
        '</div>';
    }
    return '';
  }

  async function loadPayLedger(isVendor) {
    try {
      const res = await fetch('api/vendor_payments.php?list=1', { cache: 'no-store' });
      const d = await res.json();
      if (!d.ok) return;
      payItems = d.items || [];
      payByRepair = {};
      payItems.forEach(p => { if (p.status === 'pending' || p.status === 'reminded') payByRepair[p.repair_id] = p; });
      if (cache) render();
      const wrap = $('#payLedger');
      const list = $('#payLedgerList');
      if (!payItems.length) { wrap.style.display = 'none'; return; }
      wrap.style.display = '';
      const openFirst = payItems.filter(p => p.status === 'pending' || p.status === 'reminded');
      list.innerHTML = '<div style="font-size:12px;color:var(--brown2);margin-bottom:4px">' +
        (isVendor ? '我的請款' : '待付 ' + openFirst.length + ' 筆') + '|共 ' + payItems.length + ' 筆</div>' +
        payItems.map(p => {
          const ps = payState[p.status] || { label: p.status, cls: 'warn' };
          return '<div class="list-item" style="padding:7px 2px;flex-wrap:wrap"><div style="flex:1;min-width:0">' +
            '<b>' + esc(p.property_title || '') + '|工單#' + p.repair_id + '</b>' +
            '<span>NT$' + money(p.amount) + '元|' + dstr(p.created_at) + (isVendor ? '' : '|廠商 ' + esc(p.vendor_name || '')) + '</span></div>' +
            '<span class="st ' + ps.cls + '">' + ps.label + '</span>' +
            (!isVendor && (p.status === 'pending' || p.status === 'reminded')
              ? '<button type="button" class="mini-btn act-payonline" data-id="' + p.repair_id + '" style="margin-left:6px">💳 付款</button>' : '') +
            (isVendor && p.status === 'pending'
              ? '<button type="button" class="mini-btn line act-remind" data-id="' + p.id + '" style="margin-left:6px">催收</button>' : '') +
            '</div>';
        }).join('');
      bindPayActions();
    } catch (e) { /* 靜默 */ }
  }

  function bindPayActions() {
    document.querySelectorAll('.act-remind').forEach(b => b.addEventListener('click', async () => {
      b.disabled = true;
      try {
        const res = await fetch('api/vendor_payments.php', {
          method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: 'action=remind&id=' + b.dataset.id
        });
        const j = await res.json();
        showToast(j.message);
        if (j.ok) load(true);
      } catch (e) { showToast('連線異常'); }
    }));
    document.querySelectorAll('.act-payonline').forEach(b => b.addEventListener('click', async () => {
      b.disabled = true;
      try {
        const res = await fetch('api/vendor_payments.php', {
          method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: 'action=pay_link&repair_id=' + b.dataset.repair_id
        });
        const j = await res.json();
        if (!j.ok) { showToast(j.message); b.disabled = false; return; }
        if (j.mode === 'mock') { location.href = j.pay_url; return; }
        const form = document.createElement('form');
        form.method = 'POST'; form.action = j.gateway;
        ['MerchantID', 'TradeInfo', 'TradeSha', 'Version'].forEach(k => {
          const i = document.createElement('input'); i.type = 'hidden'; i.name = k; i.value = j[k]; form.appendChild(i);
        });
        document.body.appendChild(form); form.submit();
      } catch (e) { showToast('連線異常'); b.disabled = false; }
    }));
  }

  function openBillModal(repairId, defaultAmount, needComplete) {
    billRepairId = repairId;
    billProofFiles = [];
    billPhotoFiles = [];
    billNeedComplete = !!needComplete;
    $('#billAmount').value = defaultAmount ? String(defaultAmount) : '';
    $('#billNote').value = '';
    $('#billSolution').value = '';
    $('#billPhotos').value = '';
    $('#billProof').value = '';
    $('#billProofPrev').innerHTML = '';
    $('#billPhotosPrev').innerHTML = '';
    $('#billSolutionWrap').style.display = needComplete ? '' : 'none';
    $('#billPhotosWrap').style.display = needComplete ? '' : 'none';
    $('#billModal').style.display = 'flex';
  }

  function bindBillModal() {
    $('#billProof').addEventListener('change', () => {
      billProofFiles = Array.from($('#billProof').files || []).slice(0, 3);
      $('#billProofPrev').innerHTML = billProofFiles.map(f => '<img src="' + URL.createObjectURL(f) + '" style="width:64px;height:64px;object-fit:cover;border-radius:8px;border:1.5px solid var(--beige2)">').join('');
    });
    $('#billPhotos').addEventListener('change', () => {
      billPhotoFiles = Array.from($('#billPhotos').files || []).slice(0, 3);
      $('#billPhotosPrev').innerHTML = billPhotoFiles.map(f => '<img src="' + URL.createObjectURL(f) + '" style="width:64px;height:64px;object-fit:cover;border-radius:8px;border:1.5px solid var(--beige2)">').join('');
    });
    $('#billCancel').addEventListener('click', () => { $('#billModal').style.display = 'none'; });
    $('#billSubmit').addEventListener('click', async () => {
      const amount = parseInt($('#billAmount').value, 10);
      if (!billRepairId) return;
      if (billNeedComplete) {
        const sol = $('#billSolution').value.trim();
        if (sol.length < 3) { showToast('請填寫完工說明(至少 3 字)'); return; }
      }
      if (!amount || amount <= 0) { showToast('請輸入工款金額'); return; }
      if (!billProofFiles.length) { showToast('請上傳請款憑證照片(收據/發票)'); return; }
      const fd = new FormData();
      fd.append('action', billNeedComplete ? 'complete_bill' : 'request');
      fd.append('repair_id', billRepairId);
      fd.append('amount', amount);
      fd.append('note', $('#billNote').value);
      if (billNeedComplete) fd.append('solution', $('#billSolution').value);
      billProofFiles.forEach(f => fd.append('proof[]', f));
      billPhotoFiles.forEach(f => fd.append('photos[]', f));
      const btn = $('#billSubmit');
      btn.disabled = true;
      try {
        const res = await fetch('api/vendor_payments.php', { method: 'POST', body: fd });
        const j = await res.json();
        showToast(j.message);
        if (j.ok) {
          $('#billModal').style.display = 'none';
          load(true);
        }
      } catch (e) { showToast('連線異常,請稍後再試'); }
      btn.disabled = false;
    });
  }

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

  let vendorsFetchedOnce = false;
  async function loadVendorsFresh() {
    try {
      const res = await fetch('api/repairs.php', { cache: 'no-store' });
      const d = await res.json();
      if (d.ok && (d.vendors || []).length) { cache.vendors = d.vendors; cache.leads = d.leads || cache.leads; render(); }
    } catch (e) { /* 忽略 */ }
  }
  function toggleDetail(row, id) {
    const panel = row.querySelector('.bdetail');
    if (openId === id) {
      panel.style.display = 'none';
      openId = null;
      return;
    }
    if (!vendorsFetchedOnce) { vendorsFetchedOnce = true; if (!(cache.vendors || []).length) loadVendorsFresh(); }
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
        (r.quote_amount != null ? '<br>預估費用:NT$' + money(r.quote_amount) + '元' : '') + '</div>'
      : '';
    const vendorHtml = r.vendor_user_id
      ? '<div class="bd-sec">🧰 維修廠商</div><div style="font-size:12.5px;line-height:1.8">' + esc(r.company_name || '') +
        (r.visit_at ? '|約定 ' + dstr(r.visit_at) : '') +
        (r.actual_amount != null ? '|廠商工款 NT$' + money(r.actual_amount) + '元' + (r.vendor_paid === 'yes' ? '(✓ 已付清' + dstr(r.vendor_paid_at) + ')' : '(未付)') : '') +
        (r.tenant_charge ? '|房客端:' + (r.tenant_charge === 'none' ? '屋主吸收' : (r.tenant_charge === 'bill' ? '併入帳單 NT$' + money(r.tenant_amount || 0) + '元' : '現金收取 NT$' + money(r.tenant_amount || 0) + '元')) : '') +
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
    if (cm && r.status === 'done') {
      btns.push('<button type="button" class="mini-btn act-doc-charge" data-id="' + r.id + '">📄 發自費確認函</button>');
      btns.push('<button type="button" class="mini-btn act-charge-none" data-id="' + r.id + '">🏠 房東吸收(不向房客收)</button>');
    }
    if (cm && r.vendor_user_id && r.vendor_paid !== 'yes' && ['inspected', 'repairing', 'done', 'billed'].includes(r.status) && !payByRepair[r.id]) {
      const fee = r.actual_amount != null ? r.actual_amount : r.quote_amount;
      btns.push('<button type="button" class="mini-btn line act-pay-vendor" data-id="' + r.id + '">🧾 付清廠商工款' + (fee ? '(NT$' + money(fee) + '元)' : '') + '</button>');
    }
    if (cm && r.creator_phone && !amIWorker) btns.push('<button type="button" class="mini-btn line act-chat" data-phone="' + esc(r.creator_phone) + '">💬 聯絡報修人</button>');
    if (cm && r.vendor_user_id) {
      const vp = ((cache.vendors || []).find(v => Number(v.user_id) === Number(r.vendor_user_id)) || {}).phone;
      if (vp) btns.push('<button type="button" class="mini-btn line act-chat" data-phone="' + esc(vp) + '">📞 聯絡廠商</button>');
    }
    if (!cm && r.status === 'pending' && Number(r.created_by) === Number(cache.my_id || 0)) btns.push('<button type="button" class="mini-btn line act-cancel" data-id="' + r.id + '">取消報修</button>');
    let marketHtml = '';
    const myLeads = (cache.leads || []).filter(l => Number(l.repair_id) === Number(r.id));
    const hasWon = myLeads.some(l => l.status === 'won');
    const vendorCount = (cache.vendors || []).length;
    if (cm && r.status === 'pending' && !hasWon) {
      const leadRows = myLeads.map(l => {
        const ls = leadStatus[l.status] || { label: l.status, cls: 'idle' };
        const when = l.responded_at || l.created_at;
        return '<div style="display:flex;justify-content:space-between;align-items:center;gap:8px;padding:5px 0;border-bottom:1px dashed var(--beige2)">' +
          '<span style="font-size:12.5px">🧰 ' + esc(l.company || l.vendor_name || '') +
          (l.source === 'radius' ? '(廣播)' : '(指名)') + '|' + ls.label +
          (l.quote_amount != null ? '|估價 <b>NT$' + money(l.quote_amount) + '元</b>' + (l.quote_memo ? '(' + esc(l.quote_memo) + ')' : '') : '') +
          (l.status === 'declined' && l.decline_reason ? '|理由:' + esc(l.decline_reason) : '') +
          '<span style="color:var(--brown2)">' + dstr(when) + '</span></span>' +
          (l.status === 'accepted' && l.quote_amount != null
            ? '<button type="button" class="mini-btn act-award" data-lead="' + l.id + '">🏆 選定得標</button>'
            : '') +
          '</div>';
      }).join('');
      marketHtml = '<div class="bd-sec">📡 比價/派發' + (myLeads.length ? '(' + myLeads.length + ' 家回應中)' : '') + '</div>' +
        (leadRows ? '<div style="margin-bottom:6px">' + leadRows + '</div>' : '<div style="font-size:12px;color:var(--brown2);margin-bottom:6px">尚未派發:可指名發送給特定廠商,或廣播給方圓5公里內所有註冊廠商比價</div>') +
        '<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">' +
        '<select id="selVendor-' + r.id + '" style="flex:1;min-width:150px;border:1.5px solid var(--beige2);border-radius:10px;padding:8px;font-family:inherit;color:var(--brown);background:var(--surface)">' +
        '<option value="">— 指名廠商(' + vendorCount + ' 家)—</option>' +
        (cache.vendors || []).map(v => '<option value="' + v.user_id + '">' + esc(v.company || v.name) + (v.specialty ? '(' + esc(v.specialty) + ')' : '') + '</option>').join('') +
        '</select>' +
        '<button type="button" class="mini-btn act-send" data-id="' + r.id + '">發送</button>' +
        '<button type="button" class="mini-btn line act-broadcast" data-id="' + r.id + '">📡 廣播方圓5公里</button>' +
        '</div>' +
        (!vendorCount ? '<p style="font-size:11.5px;color:var(--brown2);margin:6px 2px 0">廠商名單載入中或尚無廠商:請稍後重開本頁,或到「🔧 廠商名單」新增</p>' : '');
    }
    panel.innerHTML =
      '<div style="border-top:1.5px dashed var(--beige2);margin-top:10px;padding-top:10px">' +
      '<div style="font-size:12.5px;line-height:1.9;color:var(--brown)">' + esc(r.description) + '</div>' +
      '<div style="font-size:11.5px;color:var(--brown2);margin-top:6px">狀態:' + st.label + '|建立:' + dstr(r.created_at) +
      (r.creator_name && !cm ? '|報修人:' + esc(r.creator_name) : '') + '</div>' +
      photoHtml + quoteHtml + vendorHtml + solutionHtml + marketHtml + payReqHtml(cm, r, amIWorker) +
      (btns.length ? '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px">' + btns.join('') + '</div>' : '') +
      '</div>';
    panel.querySelectorAll('.act-start, .act-assign, .act-approve, .act-reject, .act-charge-bill, .act-charge-cash, .act-charge-none, .act-pay-vendor, .act-doc-charge, .act-cancel, .act-award, .act-send, .act-broadcast, .act-bill, .act-complete-bill').forEach(b => {
      b.addEventListener('click', (e) => {
        e.stopPropagation();
        if (b.classList.contains('act-award')) {
          if (confirm('確認選此廠商得標?其他未得標廠商將收到通知,報修進入施工流程。')) act({ action: 'award_lead', lead_id: Number(b.dataset.lead) });
          return;
        }
        if (b.classList.contains('act-send')) {
          const sel = panel.querySelector('select[id^="selVendor-"]');
          if (!sel || !sel.value) { showToast('請先選擇廠商'); return; }
          act({ action: 'send_to_vendor', repair_id: Number(b.dataset.id), vendor_user_id: Number(sel.value) });
          return;
        }
        if (b.classList.contains('act-broadcast')) {
          if (confirm('廣播給房屋方圓5公里內所有註冊廠商?')) act({ action: 'broadcast_radius', repair_id: Number(b.dataset.id) });
          return;
        }
        handleStatus(b, e);
      });
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

  async function load(force) {
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
      $('#listTitle').after(b);
    }
    if (isVendor && $('#vBanner')) {
      const p = data.my_profile || {};
      let subTxt = '服務中';
      if (p.sub_status === 'trial') subTxt = '試用中(至 ' + (p.trial_ends || '') + ')';
      if (p.sub_status === 'expired' || (p.sub_status === 'trial' && p.trial_ends && p.trial_ends < new Date().toISOString().slice(0, 10))) subTxt = '⚠ 已到期,請支付月費';
      const hasGps = p.lat != null && p.lng != null;
      $('#vBanner').innerHTML = '🧰 ' + esc(p.company || '') + '|' + subTxt +
        ' <button type="button" class="mini-btn line" id="vSubBtn" style="margin:2px 0">訂閱管理</button>' +
        (hasGps ? '|服務位置已設定' : '|尚未設定服務位置') +
        ' <button type="button" class="mini-btn line" id="vGpsBtn" style="margin:2px 0">' + (hasGps ? '更新服務位置' : '📍 設定服務位置(GPS)') + '</button>';
      $('#vSubBtn').addEventListener('click', () => { location.href = 'subscription.html'; });
      $('#vGpsBtn').addEventListener('click', () => {
        if (!navigator.geolocation) { showToast('此裝置不支援定位'); return; }
        showToast('定位中…');
        navigator.geolocation.getCurrentPosition(async (pos) => {
          try {
            const res = await fetch('api/repairs.php', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({ action: 'vendor_location', lat: pos.coords.latitude, lng: pos.coords.longitude })
            });
            const d2 = await res.json();
            showToast(d2.message);
            if (d2.ok) load();
          } catch (err) { showToast('連線異常,請稍後再試'); }
        }, () => showToast('無法取得定位,請允許瀏覽器使用位置權限'), { enableHighAccuracy: true, timeout: 10000 });
      });
      renderLeads(data.leads || []);
    }
    loadPayLedger(isVendor);
    try {
      const me = await (await fetch('api/me.php', { cache: 'no-store' })).json();
      if (me.ok && me.authenticated) {
        const role = me.user.registration_role;
        const backMap = { manager: 'dashboard_manager.html', tenant: 'dashboard_tenant.html', company_owner: 'dashboard_company.html', admin_staff: 'dashboard_company.html', accountant: 'dashboard_company.html', landlord: 'dashboard.html', vendor: 'dashboard_vendor.html' };
        if (backMap[role]) $('#navBack').setAttribute('href', backMap[role]);
        canManageAny = role !== 'tenant';
      }
    } catch (e) { /* 無 me 不影響 */ }
    const wantId = Number(new URLSearchParams(location.search).get('id') || 0);
    render();
    if (wantId) {
      const row = document.querySelector('.repair-row[data-id="' + wantId + '"]');
      if (row) {
        openId = null;
        toggleDetail(row, wantId);
        try { row.scrollIntoView({ behavior: 'smooth', block: 'center' }); } catch (e) {}
      }
    }
    fillProperties();
    renderVendors();
  }

  const leadStatus = {
    sent: { label: '邀請中', cls: 'idle' },
    accepted: { label: '已承接', cls: 'warn' },
    declined: { label: '已婉拒', cls: 'idle' },
    won: { label: '🏆 得標', cls: 'ok' },
    lost: { label: '未得標', cls: 'idle' }
  };

  function renderLeads(leads) {
    let wrap = $('#leadWrap');
    if (!wrap) {
      wrap = document.createElement('div');
      wrap.id = 'leadWrap';
      $('#list').before(wrap);
    }
    const active = leads.filter(l => l.status === 'sent');
    if (!leads.length) {
      wrap.innerHTML = '';
      return;
    }
    wrap.innerHTML = '<div class="sect" style="margin-top:18px">📡 維修商機(' + active.length + ' 筆待回應)</div>' + leads.map(l => {
      const ls = leadStatus[l.status] || { label: l.status, cls: 'idle' };
      const dist = l.distance_m != null ? (l.distance_m >= 1000 ? (l.distance_m / 1000).toFixed(1) + ' 公里' : l.distance_m + ' 公尺') : '';
      let btns = '';
      if (l.status === 'sent') {
        btns = '<button type="button" class="mini-btn act-lead-accept" data-id="' + l.id + '">✋ 承接</button>' +
          '<button type="button" class="mini-btn line act-lead-decline" data-id="' + l.id + '">婉拒</button>';
      } else if (l.status === 'accepted' && l.quote_amount == null) {
        btns = '<button type="button" class="mini-btn act-lead-quote" data-id="' + l.id + '">📋 提交估價</button>';
      }
      return '<div class="lrow" style="align-items:flex-start">' +
        '<div class="linfo"><b>' + (catIcon[l.category] || '🔧') + ' ' + esc(l.property_title) + '</b>' +
        '<span>' + esc(String(l.description || '').slice(0, 40)) + '…</span>' +
        '<span>' + (dist ? '📍 距離約 ' + dist + '|' : '') + '報價:' + (l.quote_amount != null ? 'NT$' + money(l.quote_amount) + '元' : '—') +
        (l.quote_memo ? '|' + esc(l.quote_memo) : '') + '</span></div>' +
        '<span style="display:flex;gap:4px;flex-shrink:0;flex-wrap:wrap;justify-content:flex-end"><span class="st ' + ls.cls + '">' + ls.label + '</span>' + btns + '</span></div>';
    }).join('');
    wrap.querySelectorAll('.act-lead-accept').forEach(b => b.addEventListener('click', () => act({ action: 'accept_lead', lead_id: Number(b.dataset.id) })));
    wrap.querySelectorAll('.act-lead-decline').forEach(b => b.addEventListener('click', () => {
      const reason = prompt('婉拒原因(可留空):');
      if (reason === null) return;
      act({ action: 'decline_lead', lead_id: Number(b.dataset.id), reason: reason.trim() });
    }));
    wrap.querySelectorAll('.act-lead-quote').forEach(b => b.addEventListener('click', () => {
      const amt = prompt('估價金額(NT$):');
      if (amt === null) return;
      const n = Number(String(amt).replace(/\D/g, ''));
      if (!n || n < 100) { showToast('請輸入合理金額'); return; }
      const memo = prompt('估價說明(材料/工資/天數):');
      if (memo === null) return;
      act({ action: 'submit_quote', lead_id: Number(b.dataset.id), quote_amount: n, quote_memo: memo.trim() });
    }));
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
    if (btn.classList.contains('act-bill')) {
      const r = (cache.repairs || []).find(x => Number(x.id) === id);
      openBillModal(id, r ? (r.actual_amount != null ? r.actual_amount : r.quote_amount) : 0, false);
      return;
    }
    if (btn.classList.contains('act-complete-bill')) {
      const r = (cache.repairs || []).find(x => Number(x.id) === id);
      openBillModal(id, r ? (r.actual_amount != null ? r.actual_amount : r.quote_amount) : 0, true);
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
  bindBillModal();
})();
