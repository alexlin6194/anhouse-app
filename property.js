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
    if (data.view === 'agent') $('#navBack').setAttribute('href', 'dashboard_agent.html');
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
    if (Number(p.no_smoking)) chips.push('<span class="chip">禁菸</span>');
    if (Number(p.tax_ok)) chips.push('<span class="chip">可報稅</span>');
    if (Number(p.household_ok)) chips.push('<span class="chip">可入籍</span>');
    if (p.gender_limit && p.gender_limit !== 'any') chips.push('<span class="chip">' + (p.gender_limit === 'female' ? '限女性' : '限男性') + '</span>');
    if (p.legal_use) chips.push('<span class="chip">' + esc(p.legal_use) + '</span>');

    const priceLine = p.listing_type === 'sale'
      ? '售價 NT$' + money(p.sale_price) + '元'
      : '月租 NT$' + money(p.rent) + '元' + (p.mgmt_fee ? '|管理費 NT$' + money(p.mgmt_fee) + '元' : '') + (p.deposit ? '|押金 NT$' + money(p.deposit) + '元' : '');
    const feeParts = [];
    if (Number(p.water_fee) > 0) feeParts.push('水費 ' + money(p.water_fee) + '元/月');
    if (Number(p.parking_fee) > 0) feeParts.push('車位費 ' + money(p.parking_fee) + '元/月');
    if (Number(p.cleaning_fee) > 0) feeParts.push('清潔費 ' + money(p.cleaning_fee) + '元/月');
    if (Number(p.internet_fee) > 0) feeParts.push('網路費 ' + money(p.internet_fee) + '元/月');
    if (Number(p.cable_fee) > 0) feeParts.push('第四台 ' + money(p.cable_fee) + '元/月');
    const feeLine = feeParts.length ? '<p style="font-size:12.5px;color:var(--brown2);margin:2px">💰 另計:' + feeParts.join('|') + '</p>' : '';
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
        if (b.rent_amount != null) items.push('租金 ' + money(b.rent_amount) + '元');
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
            items.push('電費 ' + money(b.electric_amount) + '元');
          }
        }
        if (b.mgmt_amount != null) items.push('管理費 ' + money(b.mgmt_amount) + '元');
        if (b.parking_amount) items.push('車位費 ' + money(b.parking_amount) + '元');
        if (b.misc_amount) items.push('雜費 ' + money(b.misc_amount) + '元' + (b.misc_note ? '(' + esc(b.misc_note) + ')' : ''));
        const meterBtn = data.can_manage && b.status !== 'paid' && !isSignBill
          ? '<div style="margin-top:6px"><button type="button" class="mini-btn act-meter" data-id="' + b.id + '">⚡ 抄錄電費(拍照辨識度數)</button></div>'
          : '';
        const remindBtn = data.can_manage && b.status === 'pending'
          ? '<button type="button" class="mini-btn line act-remind" data-id="' + b.id + '">📣 催繳通知</button>'
          : '';
        return '<div class="leasebox"><b>' + b.period + '月|' + (b.electric_amount == null ? '應收 NT$' + money(b.amount) + '元' : '總計 NT$' + money(b.amount) + '元') + '</b>' +
          (b.status === 'paid' ? '<span class="st ok" style="float:right">已繳</span>' : (b.status === 'draft' ? '<span class="st idle" style="float:right">草稿|待抄錄</span>' : '<span class="st warn" style="float:right">待繳</span>')) +
          '<div style="font-size:12.5px;color:#4A3826;margin-top:4px">' + items.join(' + ') + '</div>' +
          (b.due_date && b.status !== 'paid' ? '<div style="font-size:12px;color:#7A6A55">' + dstr(b.due_date) + ' 前繳納' + (b.status === 'draft' ? '(屆時自動生效)' : '') + '</div>' : '') +
          meterBtn + remindBtn +
          '</div>';
      }).join('');
      leaseHtml = '<div class="sect">租約與帳單</div>' +
        '<p style="font-size:13.5px;color:var(--brown);margin:4px 2px">房客:' + esc(act.tenant_name) + '(' + esc(act.tenant_phone) + ')|租期 ' + dstr(act.start_date) + '~' + (act.end_date ? dstr(act.end_date) : '不定期') + '|月租 NT$' + money(act.rent_amount) + '元</p>' +
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
    const paSection = data.can_manage ? '<div class="sect">🤝 委託業務(一屋可委託多位業務)</div><div id="paBox" style="margin:4px 2px">載入中…</div>' : '';
    /* 📎 所有權狀 */
    const ownDocs = data.ownership_docs || [];
    const isDelegatedMgr = data.view === 'manager';
    let ownHtml;
    if (ownDocs.length) {
      ownHtml = '<div class="sect">📎 所有權狀</div><p style="font-size:13px;color:var(--green);margin:2px;font-weight:700">✅ 已上傳 ' + ownDocs.length + ' 份</p>' +
        ownDocs.map((od, i) => '<a href="' + esc(od.file_path) + '" target="_blank" style="font-size:12.5px;color:#8A6D3B;font-weight:700;margin:2px 6px 2px 2px;display:inline-block">[權狀 ' + (i + 1) + (od.note ? '|' + esc(od.note) : '') + ']</a>').join('');
    } else if (data.can_manage && !isDelegatedMgr) {
      ownHtml = '<div class="sect">📎 所有權狀</div><p style="font-size:12.5px;color:#B7791F;margin:2px">⚠️ 尚未上傳。依平台規範應提供房屋所有權狀。</p>' +
        '<input type="file" id="ownFile" accept="image/*,.pdf" style="display:none"><button type="button" class="mini-btn" id="ownUpload">📤 上傳所有權狀</button>';
    } else if (data.can_manage && isDelegatedMgr) {
      ownHtml = '<div class="sect">📎 所有權狀</div><p style="font-size:12.5px;color:#B7791F;margin:2px">⚠️ 屋主尚未提供所有權狀。代管代租依規範應向屋主索取。</p>' +
        '<button type="button" class="mini-btn" id="ownRequest">📨 向屋主索取(開啟訊息)</button>';
    } else {
      ownHtml = ownDocs.length ? '' : '';
    }
    const leaseAgentSection = data.view === 'agent'
      ? '<div style="margin:10px 2px"><a class="btn line" href="leases.html?property_id=' + p.id + '" style="text-decoration:none;display:block;text-align:center">📜 租約與電子簽約(代客發起:邀房客簽→屋主副署)</a></div>'
      : '';
    const shareSection = (data.can_manage || data.view === 'agent')
      ? '<div class="sect">🔗 推廣連結(免註冊即可查看)</div><div style="margin:4px 2px"><button type="button" class="btn line" id="shareBtn" style="width:100%;text-align:center">🔗 複製房源推廣連結</button><p style="font-size:11px;color:var(--brown2);margin:6px 2px 0">連結給準房客/買家開啟,毋需帳號即可看房(顯示城市/區域,完整地址不公開)。</p></div>'
      : '';

    $('#detail').innerHTML =
      '<div class="cover" style="' + (cover ? 'background-image:url(\'uploads/' + esc(cover.file_path) + '\')' : '') + '">' + (cover ? '' : '🏠') + '</div>' +
      gallery +
      '<div style="display:flex;align-items:flex-start;gap:10px"><h3 style="margin:0;font-size:19px;color:var(--brown)">' + esc(p.title) + '</h3></div>' +
      stateHtml +
      '<p style="font-size:14px;color:var(--brown);margin:6px 2px"><b>' + priceLine + '</b></p>' +
      feeLine +
      (loc ? '<p style="font-size:13px;color:var(--brown2);margin:2px">📍' + esc(loc) + '</p>' : '') +
      '<div style="margin:10px 2px"><a class="btn line" href="life.html?property_id=' + p.id + '" style="text-decoration:none;display:block;text-align:center">📍 生活圈(周邊 5 公里:食衣住行/醫院/學校/娛樂)</a></div>' +
      '<div class="kv">' + chips.join('') + '</div>' +
      (p.min_lease ? '<p style="font-size:13px;color:var(--brown2);margin:2px">最短租期:' + esc(p.min_lease) + '</p>' : '') +
      (p.special_terms ? '<div class="sect">📌 特約條款</div><p style="font-size:13.5px;color:var(--brown);margin:2px">' + esc(p.special_terms) + '</p>' : '') +
      avail + facilities + noteHtml + ownHtml + leaseHtml + leaseAgentSection + paSection + shareSection;

    if (data.can_manage) loadAgents(p);
    const ownUpload = $('#ownUpload');
    if (ownUpload) ownUpload.addEventListener('click', () => $('#ownFile').click());
    const ownFile = $('#ownFile');
    if (ownFile) ownFile.addEventListener('change', async () => {
      const f = ownFile.files[0];
      ownFile.value = '';
      if (!f || !p.id) return;
      const fd = new FormData();
      fd.append('id', String(p.id));
      fd.append('action', 'add_ownership');
      fd.append('doc', f);
      try {
        const res = await fetch('api/listings.php', { method: 'POST', body: fd });
        const r = await res.json();
        showToast(r.message);
        if (r.ok) location.reload();
      } catch (e) { showToast('連線異常'); }
    });
    const ownRequest = $('#ownRequest');
    if (ownRequest) ownRequest.addEventListener('click', async () => {
      const ownerUid = Number(p.owner_user_id || 0);
      try {
        const res = await fetch('api/messages.php', {
          method: 'POST', headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ user_id: ownerUid, first_message: '您好,依平台規範代管代租需留存「房屋所有權狀」影像,麻煩您用訊息右下角 📎 拍照傳給我,謝謝!' })
        });
        const r = await res.json();
        if (r.ok && r.redirect) location.href = r.redirect;
        else showToast(r.message);
      } catch (e) { showToast('連線異常'); }
    });
    const shareBtn = $('#shareBtn');
    if (shareBtn) shareBtn.addEventListener('click', async () => {
      shareBtn.disabled = true;
      try {
        const res = await fetch('api/property_share.php', {
          method: 'POST', headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ action: 'link', property_id: p.id })
        });
        const r = await res.json();
        if (r.ok && r.url) {
          try { await navigator.clipboard.writeText(r.url); showToast('✅ 連結已複製:' + r.url); }
          catch (e) { showToast('連結:' + r.url); }
        } else { showToast(r.message); }
      } catch (e) { showToast('連線異常'); }
      shareBtn.disabled = false;
    });

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

  async function loadAgents(p) {
    const box = $('#paBox');
    if (!box) return;
    let d;
    try {
      const res = await fetch('api/property_agents.php?property_id=' + p.id, { cache: 'no-store' });
      d = await res.json();
    } catch (e) { box.innerHTML = '<p style="font-size:12px;color:var(--brown2)">載入失敗</p>'; return; }
    if (!d.ok) { box.innerHTML = '<p style="font-size:12px;color:var(--brown2)">' + esc(d.message || '') + '</p>'; return; }
    const items = d.items || [];
    const dealTxt = { rent: '出租', sale: '出售', both: '出租/出售' };
    const rows = items.length
      ? items.map(a =>
        '<div class="lrow"><div class="linfo"><b>' + esc(a.name) + '(' + esc(a.phone) + ')</b>' +
        '<span>' + (dealTxt[a.deal_type] || '') + '|可改價:' + (Number(a.can_edit_price) ? '是' : '否') +
        '|' + (a.status === 'active' ? '<b style="color:var(--green)">生效中</b>' : '<span style="color:var(--brown2)">邀請中</span>') + '</span></div>' +
        '<button type="button" class="mini-btn line act-paend" data-id="' + a.id + '">解除</button></div>').join('')
      : '<p style="font-size:12px;color:var(--brown2);margin:2px">尚未委託任何業務。</p>';
    box.innerHTML = rows +
      '<div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:8px;align-items:center">' +
      '<input id="paPhone" inputmode="numeric" placeholder="業務手機號碼" style="flex:1;min-width:130px;border:1.5px solid var(--beige2);border-radius:10px;padding:8px;font-family:inherit">' +
      '<select id="paDeal" style="border:1.5px solid var(--beige2);border-radius:10px;padding:8px;font-family:inherit;color:var(--brown);background:var(--surface)"><option value="rent">協助出租</option><option value="sale">協助出售</option><option value="both">出租/出售</option></select>' +
      '<select id="paPrice" style="border:1.5px solid var(--beige2);border-radius:10px;padding:8px;font-family:inherit;color:var(--brown);background:var(--surface)"><option value="0">不可改價</option><option value="1">可改價</option></select>' +
      '<button type="button" class="mini-btn act-painvite">邀請</button></div>' +
      '<p style="font-size:11px;color:var(--brown2);margin:6px 2px 0">業務直接使用您建好的房源資料(免重建);未選「可改價」時業務不能動租金/售價。</p>';

    box.querySelector('.act-painvite').addEventListener('click', async () => {
      const phone = box.querySelector('#paPhone').value.trim();
      if (!phone) { showToast('請輸入業務手機號碼'); return; }
      try {
        const res = await fetch('api/property_agents.php', {
          method: 'POST', headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ action: 'invite', property_id: p.id, phone, deal_type: box.querySelector('#paDeal').value, can_edit_price: Number(box.querySelector('#paPrice').value) })
        });
        const r = await res.json();
        showToast(r.message);
        if (r.ok) loadAgents(p);
      } catch (e) { showToast('連線異常'); }
    });
    box.querySelectorAll('.act-paend').forEach(b => b.addEventListener('click', async () => {
      if (!confirm('解除此業務的委託?')) return;
      try {
        const res = await fetch('api/property_agents.php', {
          method: 'POST', headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ action: 'end', id: Number(b.dataset.id) })
        });
        const r = await res.json();
        showToast(r.message);
        if (r.ok) loadAgents(p);
      } catch (e) { showToast('連線異常'); }
    }));
  }

  load();
})();
