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

  async function load() {
    let data;
    try {
      const res = await fetch('api/tenant_dashboard.php', { cache: 'no-store' });
      data = await res.json();
    } catch (e) { return; }
    if (!data.ok || !data.authenticated) { location.href = 'login.html'; return; }
    if (!data.tenant) { location.href = 'welcome.html'; return; }
    $('#avatar').textContent = data.name ? data.name.charAt(0) : '?';
    $('#greet').textContent = '您好,' + data.name;

    const pc = $('#pendingLeaseCard');
    if (data.pending_leases.length) {
      pc.style.display = 'block';
      pc.innerHTML = data.pending_leases.map(l =>
        '<div class="row" style="margin-bottom:6px"><div class="ico">📄</div>' +
        '<div><span class="tagline">租約邀請待您回覆</span><h4>' + l.property_title + '</h4>' +
        '<p>月租 NT$' + money(l.rent_amount) + '元' + (l.deposit_amount ? '|押金 NT$' + money(l.deposit_amount) + '元' : '') +
        '|' + dstr(l.start_date) + ' 起租' + (l.end_date ? '|' + dstr(l.end_date) + ' 到期' : '') +
        (l.landlord_name ? '|屋主 ' + l.landlord_name : '') + '</p></div></div>' +
        '<div style="display:flex;gap:8px"><button type="button" class="mini-btn act-accept" data-id="' + l.id + '">接受租約</button>' +
        '<button type="button" class="mini-btn line act-decline" data-id="' + l.id + '">婉拒</button></div>'
      ).join('');
      pc.querySelectorAll('.act-accept').forEach(b => b.addEventListener('click', () => respond(b.dataset.id, 'accept')));
      pc.querySelectorAll('.act-decline').forEach(b => b.addEventListener('click', () => respond(b.dataset.id, 'decline')));
    } else {
      pc.style.display = 'none';
    }

    const lc = $('#leaseCard');
    if (data.active_leases.length) {
      const l = data.active_leases[0];
      const cb = l.current_bill;
      const dueTxt = cb
        ? (cb.status === 'paid'
          ? '<span class="st ok">本月已繳</span>'
          : '<span class="st warn">本月應繳 NT$' + money(cb.amount) + '元' + (cb.due_date ? '|' + dstr(cb.due_date) + ' 前' : '') + '</span>')
        : '<span class="st idle">本月無帳單</span>';
      const eRate = (l.elec_rate != null && Number(l.elec_rate) > 0) ? Number(l.elec_rate) : 5;
      const billRows = (l.bills || []).map(b => {
        const startMonth = l.start_date ? String(l.start_date).slice(0, 7) : '';
        const periodTxt = String(b.period) + '月';
        const isSign = b.status === 'paid' && b.period === startMonth;
        const parts = [];
        if (b.rent_amount != null) parts.push('租金 NT$' + money(b.rent_amount) + '元');
        if (isSign) {
          parts.push('電費:併入下期(簽約基準 ' + money(l.meter_start ?? 0) + ' 度)');
        } else if (b.electric_amount == null) {
          parts.push('電費(待抄錄)');
        } else {
          const prev = b.prev_reading != null ? Number(b.prev_reading) : null;
          const cur = b.meter_reading != null ? Number(b.meter_reading) : null;
          if (cur != null && prev != null) {
            const usage = cur - prev;
            const f = l.elec_mode === 'taipower'
              ? '電費 NT$' + money(b.electric_amount) + '元' + '(用電 ' + money(usage) + ' 度,台電累進計價)'
              : '電費 NT$' + money(b.electric_amount) + '元' + '(用電 ' + money(usage) + ' 度 × NT$' + (eRate % 1 ? eRate.toFixed(1) : eRate) + '元/度)';
            parts.push(f);
          } else {
            parts.push('電費 NT$' + money(b.electric_amount) + '元');
          }
        }
        if (b.mgmt_amount) parts.push('管理費 NT$' + money(b.mgmt_amount) + '元');
        if (b.parking_amount) parts.push('車位費 NT$' + money(b.parking_amount) + '元');
        if (b.deposit_amount) parts.push('押金 NT$' + money(b.deposit_amount) + '元');
        if (b.misc_amount) parts.push('雜費 NT$' + money(b.misc_amount) + '元' + (b.misc_note ? '(' + b.misc_note + ')' : ''));
        const totalTxt = b.electric_amount == null || isSign
          ? '應繳 NT$' + money(b.amount) + '元'
          : '總計 NT$' + money(b.amount) + '元';
        const payBtn = b.status !== 'paid'
          ? '<div style="margin-top:6px;display:flex;gap:6px;flex-wrap:wrap"><button type="button" class="mini-btn pay-online-btn" data-id="' + b.id + '" style="background:var(--wood-d);color:#FFF9EC">💳 線上付款(藍新)</button><button type="button" class="mini-btn line pay-btn" data-id="' + b.id + '">轉帳證明回報</button></div>'
          : '';
        const chipTxt = isSign ? '簽約已繳' : (b.status === 'paid' ? '已繳' : '待繳');
        return '<div class="list-item" style="padding:8px 2px;flex-wrap:wrap"><div style="flex:1;min-width:0"><b>' + periodTxt + '|' + totalTxt + '</b><span>' + parts.join(' + ') + '</span>' + payBtn + '</div>' +
          '<span class="st ' + (b.status === 'paid' ? 'ok' : 'warn') + '">' + chipTxt + '</span></div>';
      }).join('');
      lc.innerHTML = '<div class="row"><div class="ico">🔑</div>' +
        '<div style="flex:1;min-width:0"><span class="tagline">我的租約|生效中</span><h4>' + l.property_title + ' ' + dueTxt + '</h4>' +
        '<p>' + (l.location ? l.location + '|' : '') + '月租 NT$' + money(l.rent_amount) +
        (l.deposit_amount ? '|押金 NT$' + money(l.deposit_amount) : '') +
        '|' + dstr(l.start_date) + ' 起租' + (l.end_date ? '|' + dstr(l.end_date) + ' 到期' : '|不定期') +
        (l.payment_day ? '|每月 ' + l.payment_day + ' 日繳租' : '') +
        (l.landlord_name ? '|屋主 ' + l.landlord_name : '') + '</p></div></div>' +
        '<div style="display:flex;gap:8px;margin-top:8px"><a class="mini-btn line" href="life.html?property_id=' + l.property_id + '" style="text-decoration:none">📍 生活圈(周邊食衣住行)</a></div>' +
        ((l.bills || []).length
          ? '<div class="sect" style="margin-top:10px">繳費紀錄</div><div style="max-height:260px;overflow:auto">' + billRows + '</div>'
          : '');
      bindPay(lc);
    } else if (!data.pending_leases.length) {
      lc.innerHTML = '<div class="row"><div class="ico">🔑</div>' +
        '<div><span class="tagline">我的租約</span><h4>尚未綁定租約</h4><p>請由房東或公司發送邀請綁定租約後,即可查看帳單、電費明細與線上報修。</p></div></div>';
    }

    const fnLife = $('#fnLife');
    if (fnLife) fnLife.addEventListener('click', () => {
      const l = (data.active_leases || [])[0];
      if (l && l.property_id) location.href = 'life.html?property_id=' + l.property_id;
      else showToast('請先綁定租約,再使用生活資訊');
    });
  }

  async function respond(id, action) {
    try {
      const res = await fetch('api/leases.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ lease_id: Number(id), action })
      });
      const d = await res.json();
      showToast(d.message);
      if (d.ok) setTimeout(load, 800);
    } catch (e) {
      showToast('連線異常,請稍後再試');
    }
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
    payFile.value = '';
    if (!f || !payBillId) return;
    const fd = new FormData();
    fd.append('action', 'pay_proof');
    fd.append('bill_id', String(payBillId));
    fd.append('photo', f);
    showToast('上傳中…');
    try {
      const res = await fetch('api/bills.php', { method: 'POST', body: fd });
      const d = await res.json();
      showToast(d.message);
      if (d.ok) setTimeout(load, 800);
    } catch (e) {
      showToast('連線異常,請稍後再試');
    }
  });
  function bindPay(scope) {
    (scope || document).querySelectorAll('.pay-btn:not([data-bound])').forEach(b => {
      b.dataset.bound = '1';
      b.addEventListener('click', () => {
        payBillId = Number(b.dataset.id);
        payFile.click();
      });
    });
    (scope || document).querySelectorAll('.pay-online-btn:not([data-bound])').forEach(b => {
      b.dataset.bound = '1';
      b.addEventListener('click', async () => {
        b.disabled = true;
        try {
          const res = await fetch('api/bills.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ bill_id: Number(b.dataset.id), action: 'pay_link' })
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
        } catch (e) { showToast('連線異常,請稍後再試'); }
        b.disabled = false;
      });
    });
  }

  load();
})();
