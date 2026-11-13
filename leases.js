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
  const statusMap = {
    pending: { label: '待房客回覆', cls: 'warn' },
    active: { label: '生效中', cls: 'ok' },
    declined: { label: '房客婉拒', cls: 'idle' },
    ended: { label: '已結束', cls: 'idle' }
  };

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  }

  async function load() {
    let data;
    try {
      const res = await fetch('api/leases.php', { cache: 'no-store' });
      data = await res.json();
    } catch (e) {
      showToast('連線異常,請稍後再試');
      return;
    }
    if (!data.ok || !data.authenticated) { location.href = 'login.html'; return; }
    if (data.my_role === 'manager') $('#navBack').setAttribute('href', 'dashboard_manager.html');
    else if (data.my_role === 'tenant') $('#navBack').setAttribute('href', 'dashboard_tenant.html');
    else if (data.my_role === 'company_owner' || data.my_role === 'admin_staff' || data.my_role === 'accountant') $('#navBack').setAttribute('href', 'dashboard_company.html');

    const params = new URLSearchParams(location.search);
    const preset = params.get('property_id');
    propList = data.properties || [];
    const sel = $('#property_id');
    if (propList.length) {
      sel.innerHTML = propList.map(h => '<option value="' + h.id + '">' + esc(h.title) + (h.status === 'rented' ? '(出租中)' : '') + '</option>').join('');
    if (preset && propList.some(h => String(h.id) === preset)) {
      sel.value = preset;
      $('#leaseForm').style.display = '';
      $('#newLeaseBtn').style.display = 'none';
    }
      fillRentFromProperty();
      $('#sendBtn').disabled = false;
    } else {
      sel.innerHTML = '<option value="">尚無可管理的房屋</option>';
      $('#propHint').textContent = '您目前沒有屋主或代管權限的房屋;請先由屋主授權代管,或建立房源。';
      $('#sendBtn').disabled = true;
    }

    renderList(data);
    lastData = data;
  }

  function renderList(data) {
    const listEl = $('#list');
    const q = ($('#leaseSearch').value || '').trim().toLowerCase();
    let leases = data.leases || [];
    if (q) {
      leases = leases.filter(l =>
        String(l.property_title || '').toLowerCase().includes(q) ||
        String(l.address || '').toLowerCase().includes(q) ||
        String(l.tenant_name || '').toLowerCase().includes(q) ||
        String(l.tenant_phone || '').includes(q) ||
        String(l.landlord_name || '').toLowerCase().includes(q));
    }
    if (!leases.length) {
      listEl.innerHTML = '<p style="padding:18px 4px;text-align:center;color:var(--brown2);font-size:13px;margin:0">' +
        (q ? '找不到符合「' + esc(q) + '」的租約' : '🌿 目前沒有租約紀錄:點右上「+ 新增租約」開始') + '</p>';
      return;
    }
    const groups = new Map();
    leases.forEach(l => {
      const key = l.landlord_name || '其他房屋';
      if (!groups.has(key)) groups.set(key, []);
      groups.get(key).push(l);
    });
    const keys = Array.from(groups.keys()).sort((a, b) => a.localeCompare(b, 'zh-TW'));
    listEl.innerHTML = keys.map(k => {
      const arr = groups.get(k).slice().sort((a, b) =>
        String(a.property_title).localeCompare(String(b.property_title), 'zh-TW') || a.id - b.id);
      const houses = new Set(arr.map(l => l.property_id)).size;
      const rows = arr.map(l => {
        const st = statusMap[l.status] || { label: l.status, cls: 'idle' };
        const span = [l.start_date ? dstr(l.start_date) + ' 起' : '', l.end_date ? dstr(l.end_date) + ' 止' : '不定期'].filter(Boolean).join('~');
        const endBtn = l.status === 'active'
          ? '<div style="display:flex;gap:8px;margin-top:8px"><button type="button" class="mini-btn line act-end" data-id="' + l.id + '">結束租約(退租)</button></div>'
          : '';
        return '<div class="lrow" style="margin-bottom:10px">' +
          '<div class="ltop"><div class="linfo">' +
          '<b>🏠 ' + esc(l.property_title) + '</b>' +
          '<span>房客:' + esc(l.tenant_name) + '(' + esc(l.tenant_phone) + ')|月租 NT$' + money(l.rent_amount) + '元' +
          (l.deposit_amount ? '|押金 NT$' + money(l.deposit_amount) + '元' : '') + '|' + esc(span) +
          (l.payment_day ? '|每月 ' + l.payment_day + ' 日繳' : '') +
          (l.creator_name ? '|由 ' + esc(l.creator_name) + ' 發送' : '') + '</span>' +
          '</div><span class="st ' + st.cls + '">' + st.label + '</span></div>' + endBtn + '</div>';
      }).join('');
      return '<div class="lgroup"><button type="button" class="ghead" data-g="' + esc(k) + '">' +
        '<span class="gname">👤 ' + esc(k) + '</span>' +
        '<span class="gmeta">' + houses + ' 間出租中<i class="caret">▾</i></span></button>' +
        '<div class="gbody">' + rows + '</div></div>';
    }).join('');
    listEl.querySelectorAll('.act-end').forEach(b => b.addEventListener('click', () => endLease(b.dataset.id)));
    listEl.querySelectorAll('.ghead').forEach(h => h.addEventListener('click', () => {
      h.classList.toggle('collapsed');
      const body = h.nextElementSibling;
      body.style.display = h.classList.contains('collapsed') ? 'none' : '';
    }));
  }

  async function endLease(id) {
    try {
      const res = await fetch('api/leases.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ lease_id: Number(id), action: 'end' })
      });
      const d = await res.json();
      showToast(d.message);
      if (d.ok) load();
    } catch (e) {
      showToast('連線異常,請稍後再試');
    }
  }

  const phoneEl = $('#phone');
  phoneEl.addEventListener('input', () => {
    phoneEl.value = phoneEl.value.replace(/\D/g, '').slice(0, 10);
  });

  let propList = [];
  function fillRentFromProperty() {
    const p = propList.find(x => String(x.id) === String($('#property_id').value));
    const rentEl = $('#rent_amount');
    if (p && Number(p.rent) > 0) {
      rentEl.value = String(p.rent);
      $('#propHint').textContent = '租金依屋主「' + p.title + '」的刊登租金 NT$' + Number(p.rent).toLocaleString('zh-TW') + '元 訂定;若房客議價,請與屋主討論後由屋主於房源管理調整租金';
    }
  }
  $('#property_id').addEventListener('change', fillRentFromProperty);

  const rentEl = $('#rent_amount');
  const depEl = $('#deposit_amount');
  const depHint = $('#hint-deposit');
  let depMonths = null;

  function refreshDepositHint() {
    if (depMonths === null) {
      depHint.textContent = depEl.value && rentEl.value
        ? '自填金額(相當於 ' + (Number(depEl.value) / Number(rentEl.value)).toFixed(1) + ' 個月租金)'
        : '';
      return;
    }
    const rent = Number(rentEl.value || 0);
    depHint.textContent = rent > 0
      ? '押金 ' + depMonths + ' 個月 = NT$' + (rent * depMonths).toLocaleString('zh-TW') + '元'
      : '請先填寫每月租金,系統將自動計算';
  }

  depEl.addEventListener('click', () => {
    $('#depOptions').style.display = 'flex';
  });
  depEl.addEventListener('focus', () => {
    $('#depOptions').style.display = 'flex';
  });

  document.querySelectorAll('.dep-opt').forEach(btn => {
    btn.addEventListener('click', () => {
      const m = Number(btn.dataset.m);
      document.querySelectorAll('.dep-opt').forEach(b => b.classList.remove('on'));
      if (m === 0) {
        depMonths = null;
        btn.classList.add('on');
        depEl.removeAttribute('readonly');
        depEl.style.background = '';
        depEl.focus();
        refreshDepositHint();
      } else {
        depMonths = m;
        btn.classList.add('on');
        depEl.setAttribute('readonly', '');
        depEl.style.background = '#FBF0DA';
        const rent = Number(rentEl.value || 0);
        depEl.value = rent > 0 ? String(rent * m) : '';
        depHint.textContent = rent > 0
          ? '押金 ' + m + ' 個月 = NT$' + (rent * m).toLocaleString('zh-TW') + '元'
          : '請先填寫每月租金,系統將自動計算';
      }
    });
  });

  rentEl.addEventListener('input', () => {
    rentEl.value = rentEl.value.replace(/\D/g, '').slice(0, 9);
    if (depMonths !== null) {
      const rent = Number(rentEl.value || 0);
      depEl.value = rent > 0 ? String(rent * depMonths) : '';
    }
    refreshDepositHint();
  });

  $('#leaseForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const pid = $('#property_id').value;
    if (!pid) { showToast('請先選擇房屋'); return; }
    if (!/^09\d{8}$/.test(phoneEl.value)) { showToast('請輸入房客註冊的手機號碼'); return; }
    if (!$('#start_date').value) { showToast('請選擇起租日'); return; }
    if ($('#rent_amount').value.replace(/\D/g, '') === '') { showToast('請輸入每月租金'); return; }
    const body = {
      action: 'create',
      property_id: Number(pid),
      phone: phoneEl.value,
      start_date: $('#start_date').value,
      end_date: $('#end_date').value,
      rent_amount: $('#rent_amount').value.replace(/\D/g, ''),
      deposit_amount: $('#deposit_amount').value.replace(/\D/g, ''),
      payment_day: $('#payment_day').value.replace(/\D/g, ''),
      meter_start: $('#meter_start').value.replace(/\D/g, '')
    };
    const btn = $('#sendBtn');
    btn.disabled = true;
    try {
      const res = await fetch('api/leases.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body)
      });
      const d = await res.json();
      showToast(d.message);
      if (d.ok) {
        phoneEl.value = '';
        $('#leaseForm').style.display = 'none';
        $('#newLeaseBtn').style.display = '';
        window.scrollTo({ top: 0, behavior: 'smooth' });
        load();
      }
    } catch (err) {
      showToast('連線異常,請稍後再試');
    } finally {
      btn.disabled = false;
    }
  });

  $('#newLeaseBtn').addEventListener('click', () => {
    const f = $('#leaseForm');
    f.style.display = '';
    $('#newLeaseBtn').style.display = 'none';
    f.scrollIntoView({ behavior: 'smooth', block: 'start' });
  });

  let lastData = null;
  $('#leaseSearch').addEventListener('input', () => {
    if (lastData) renderList(lastData);
  });

  load();
})();
