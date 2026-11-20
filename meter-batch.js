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
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  let cache = null;
  const rows = {}; // billId -> {blob, mime, fname, prev, rate, mode}

  let tessPromise = null;
  function loadTesseract() {
    if (window.Tesseract) return Promise.resolve();
    if (!tessPromise) {
      tessPromise = new Promise((resolve, reject) => {
        const s = document.createElement('script');
        s.src = 'https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/tesseract.min.js';
        s.onload = resolve;
        s.onerror = reject;
        document.head.appendChild(s);
        setTimeout(() => reject(new Error('timeout')), 12000);
      });
    }
    return tessPromise;
  }

  async function ocrMeter(blob) {
    try {
      const imgUrl = await new Promise((res, rej) => {
        const r = new FileReader();
        r.onload = () => res(r.result);
        r.onerror = rej;
        r.readAsDataURL(blob);
      });
      const img = await new Promise((res, rej) => {
        const im = new Image();
        im.onload = () => res(im);
        im.onerror = rej;
        im.src = imgUrl;
      });
      const scale = Math.min(2.5, Math.max(1.5, 1200 / Math.max(img.width, img.height)));
      const cv = document.createElement('canvas');
      cv.width = Math.round(img.width * scale);
      cv.height = Math.round(img.height * scale);
      const cx = cv.getContext('2d');
      cx.filter = 'grayscale(1) contrast(1.9) brightness(1.1)';
      cx.drawImage(img, 0, 0, cv.width, cv.height);
      await loadTesseract();
      const ret = await Promise.race([
        window.Tesseract.recognize(cv.toDataURL('image/png'), 'eng', { tessedit_char_whitelist: '0123456789' }),
        new Promise((_, rej) => setTimeout(() => rej(new Error('ocr timeout')), 20000))
      ]);
      const text = String(ret && ret.data && ret.data.text ? ret.data.text : '').replace(/\D/g, '');
      return text ? (text.length > 7 ? text.slice(-7) : text) : '';
    } catch (e) {
      return '';
    }
  }

  function pickRow(bid) {
    return (cache && cache.bills || []).find(b => Number(b.id) === Number(bid));
  }

  function render() {
    const listEl = $('#list');
    const pending = (cache.bills || []).filter(b => b.electric_amount == null && ['draft', 'pending'].includes(b.status));
    $('#submitAll').textContent = '📤 全部送出(' + pending.length + ' 筆)';
    if (!pending.length) {
      listEl.innerHTML = '<p style="padding:18px 4px;text-align:center;color:var(--brown2);font-size:13px;margin:0">🌿 目前沒有待抄錄的智慧電錶:每期帳單產生前 5 天,這裡會列出該抄的房屋。</p>';
      return;
    }
    listEl.innerHTML = pending.map(b => {
      const prev = b.prev_reading != null ? Number(b.prev_reading) : (b.lease_meter_start != null ? Number(b.lease_meter_start) : null);
      const rate = (b.elec_rate != null && Number(b.elec_rate) > 0) ? Number(b.elec_rate) : 5;
      const mode = b.elec_mode || 'flat';
      rows[b.id] = rows[b.id] || { blob: null, mime: null, fname: null, prev, rate, mode, done: false };
      const row = rows[b.id];
      return '<div class="mrow" data-id="' + b.id + '">' +
        '<div class="mhead"><b>🏠 ' + esc(b.property_title) + '</b><span>' + esc(b.period) + '月|' + (b.status === 'draft' ? '草稿' : '待繳') + '</span>' +
        (row.done ? '<span class="st ok" style="margin-left:auto">✓ 已送出</span>' : '<span style="margin-left:auto;font-size:11.5px;color:var(--brown2)">上期基準 ' + (prev != null ? money(prev) : '?') + ' 度</span>') + '</div>' +
        '<div style="display:flex;gap:8px;align-items:center">' +
        '<input class="mread" inputmode="numeric" placeholder="本期智慧電錶度數" value="' + (row.reading || '') + '">' +
        (row.blob ? '<img class="mthumb" src="' + row.objUrl + '" data-full="' + row.objUrl + '" alt="智慧電錶照片">' : '') +
        '</div>' +
        '<div class="mact">' +
        '<button type="button" class="mini-btn line act-photo" data-id="' + b.id + '">📷 拍智慧電錶</button>' +
        '<button type="button" class="mini-btn act-one" data-id="' + b.id + '">送出此筆</button>' +
        '</div></div>';
    }).join('');

    listEl.querySelectorAll('.mrow').forEach(rowEl => {
      const bid = Number(rowEl.dataset.id);
      const input = rowEl.querySelector('.mread');
      input.addEventListener('input', () => { rows[bid].reading = input.value.replace(/\D/g, ''); if (input.value !== rows[bid].reading) input.value = rows[bid].reading; });
      rowEl.querySelector('.act-photo').addEventListener('click', () => photoFlow(bid, rowEl));
      rowEl.querySelector('.act-one').addEventListener('click', () => submitOne(bid));
      rowEl.querySelector('img.mthumb')?.addEventListener('click', (e) => {
        if (window.showLightbox) window.showLightbox(e.currentTarget.dataset.full, '智慧電錶照片');
      });
    });
  }

  let batchTarget = null;
  const fileInput = document.createElement('input');
  fileInput.type = 'file';
  fileInput.accept = 'image/*';
  fileInput.setAttribute('capture', 'environment');
  fileInput.style.display = 'none';
  document.body.appendChild(fileInput);
  fileInput.addEventListener('change', async () => {
    const f = fileInput.files && fileInput.files[0];
    fileInput.value = '';
    if (!f || !batchTarget) return;
    const bid = batchTarget;
    let buf;
    try { buf = await f.arrayBuffer(); } catch (e) { showToast('讀取照片失敗'); return; }
    const row = rows[bid];
    row.mime = f.type || 'image/jpeg';
    row.fname = f.name || 'meter.jpg';
    row.blob = new Blob([buf], { type: row.mime });
    row.lastModified = f.lastModified || Date.now();
    row.pickedAt = Date.now();
    if (row.objUrl) URL.revokeObjectURL(row.objUrl);
    row.objUrl = URL.createObjectURL(row.blob);
    showToast('照片辨識中…');
    const val = await ocrMeter(row.blob);
    if (val !== '') {
      const confirmVal = prompt('已辨識度數,請確認或修正:', val);
      if (confirmVal === null) { render(); return; }
      row.reading = String(Number(String(confirmVal).replace(/\D/g, '')) || '');
    }
    render();
  });

  function photoFlow(bid, rowEl) {
    batchTarget = bid;
    fileInput.click();
  }

  function buildFd(bid) {
    const row = rows[bid];
    const b = pickRow(bid);
    if (!row || !b) return null;
    if (!row.blob) { showToast('「' + b.property_title + '」請先拍智慧電錶照片'); return null; }
    const reading = Number(row.reading || 0);
    if (!reading || (row.prev != null && reading < row.prev)) {
      showToast('「' + b.property_title + '」度數不正確(不可小於上期 ' + (row.prev != null ? money(row.prev) : 0) + ')');
      return null;
    }
    const fd = new FormData();
    fd.append('action', 'set_electric');
    fd.append('bill_id', String(bid));
    fd.append('meter_reading', String(reading));
    fd.append('photo', row.blob, row.fname);
    fd.append('photo_meta_client', JSON.stringify({ last_modified: row.lastModified || Date.now(), picked_at: row.pickedAt || Date.now() }));
    return fd;
  }

  async function submitOne(bid) {
    const fd = buildFd(bid);
    if (!fd) return;
    showToast('上傳中…');
    try {
      const res = await fetch('api/bills.php', { method: 'POST', body: fd });
      const d = await res.json();
      showToast(d.message);
      if (d.ok) { rows[bid].done = true; render(); }
    } catch (e) {
      showToast('連線異常,請稍後再試');
    }
  }

  $('#submitAll').addEventListener('click', async () => {
    const pending = (cache.bills || []).filter(b => b.electric_amount == null && ['draft', 'pending'].includes(b.status));
    let ok = 0, fail = 0;
    for (const b of pending) {
      const fd = buildFd(Number(b.id));
      if (!fd) { fail++; continue; }
      try {
        const res = await fetch('api/bills.php', { method: 'POST', body: fd });
        const d = await res.json();
        if (d.ok) { ok++; rows[Number(b.id)].done = true; }
        else { fail++; showToast(d.message); }
      } catch (e) {
        fail++;
        showToast('連線異常,請稍後再試');
      }
    }
    showToast('批次完成:成功 ' + ok + ' 筆' + (fail ? ',未完成 ' + fail + ' 筆(缺照片或度數)' : ''));
    if (ok) { render(); }
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
    cache = data;
    const backMap = { landlord: 'dashboard.html', manager: 'dashboard_manager.html', tenant: 'dashboard_tenant.html', company_owner: 'dashboard_company.html' };
    try {
      const me = await (await fetch('api/me.php', { cache: 'no-store' })).json();
      if (me.ok && me.authenticated && backMap[me.user.registration_role]) {
        $('#navBack').setAttribute('href', backMap[me.user.registration_role]);
      }
    } catch (e) { /* 忽略 */ }
    render();
  }

  load();
})();
