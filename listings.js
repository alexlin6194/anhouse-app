(function () {
  'use strict';
  const $ = (s) => document.querySelector(s);
  const listEl = $('#list');
  const editBox = $('#editBox');
  let editingId = null;
  let toastTimer;

  function showToast(msg) {
    const t = $('#toast');
    t.textContent = msg;
    t.classList.add('show');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => t.classList.remove('show'), 2400);
  }

  const typeNames = { rental: '出租', sale: '出售' };
  const ptypeNames = { whole_house: '整層住家', independent_suite: '獨立套房', shared_suite: '分租套房', studio: '雅房', apartment: '公寓/電梯大樓', building: '大樓/華廈', townhouse: '透天厝', other: '其他' };

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  }

  async function load() {
    let data;
    try {
      const res = await fetch('api/listings.php', { cache: 'no-store' });
      data = await res.json();
    } catch (e) {
      showToast('連線異常,請稍後再試');
      return;
    }
    if (!data.ok || !data.authenticated) { location.href = 'login.html'; return; }

    if (!data.items.length) {
      listEl.innerHTML = '<div class="lrow" style="text-align:center;color:var(--brown2);font-size:13.5px">🌿 還沒有房源刊登<br><br><button type="button" class="mini-btn" onclick="location.href=\'create_property.html\'">+ 刊登第一筆房源</button></div>';
      return;
    }

    listEl.innerHTML = data.items.map(p => {
      const meta = ['<span class="chip' + (p.listing_type === 'sale' ? ' sale' : '') + '">' + (typeNames[p.listing_type] || p.listing_type) + '</span>'];
      if (p.property_type && ptypeNames[p.property_type]) meta.push('<span class="chip">' + ptypeNames[p.property_type] + '</span>');
      if (p.layout_text) meta.push('<span class="chip">' + esc(p.layout_text) + '</span>');
      if (p.area_ping) meta.push('<span class="chip">' + esc(parseFloat(p.area_ping)) + ' 坪</span>');
      const loc = [p.city, p.district].filter(Boolean).join('');
      const photos = (p.photos || []);
      const cover = photos.length
        ? '<div class="lthumb" style="background-image:url(\'uploads/' + esc(photos[0].file_path) + '\');cursor:zoom-in" data-full="uploads/' + esc(photos[0].file_path) + '" data-title="' + esc(p.title) + '"></div>'
        : '<div class="lthumb empty">🏠</div>';
      const photoStrip = photos.length > 1
        ? '<div class="lstrip">' + photos.slice(0, 5).map((ph, i) => '<span class="mini-thumb" style="background-image:url(\'uploads/' + esc(ph.file_path) + '\');cursor:zoom-in" data-full="uploads/' + esc(ph.file_path) + '" data-title="' + esc(p.title) + '">' + (i === 0 ? '' : '') + '</span>').join('') + (photos.length > 5 ? '<span class="mini-thumb more">+' + (photos.length - 5) + '</span>' : '') + '</div>'
        : '';
      return '<div class="lrow" data-id="' + p.id + '">' +
        '<div class="ltop">' + cover + '<div class="linfo">' +
        '<b>' + esc(p.title) + '</b>' +
        '<span>' + esc(p.price_text) + (loc ? '|' + esc(loc) : '') + (p.floor_current != null && p.floor_current !== '' ? '|' + p.floor_current + ' 樓' : '') + '</span>' +
        '</div><span class="st ' + p.state_class + '">' + p.state_label + '</span></div>' +
        photoStrip +
        '<div class="lmeta">' + meta.join('') + '</div>' +
        (p.is_delegated
          ? '<div class="lbtns"><button type="button" class="mini-btn line act-edit">編輯屋況/租金</button><button type="button" class="mini-btn line act-life">📍 生活圈</button><button type="button" class="mini-btn" disabled style="opacity:.55">上下架與照片由管理員管理</button></div>'
          : '<div class="lbtns"><button type="button" class="mini-btn line act-edit">編輯</button><button type="button" class="mini-btn line act-life">📍 生活圈</button><button type="button" class="mini-btn act-toggle">' + (p.is_listed ? '下架' : '重新上架') + '</button></div>') +
        '</div>';
    }).join('');

    listEl.querySelectorAll('.lrow').forEach(row => {
      const id = Number(row.dataset.id);
      const p = data.items.find(x => Number(x.id) === id);
      row.querySelector('.act-toggle')?.addEventListener('click', async () => {
        try {
          const res = await fetch('api/listings.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id, action: 'toggle' })
          });
          const d = await res.json();
          showToast(d.message);
          if (d.ok) load();
        } catch (e) { showToast('連線異常,請稍後再試'); }
      });
      row.querySelector('.act-life')?.addEventListener('click', () => {
        location.href = 'life.html?property_id=' + id;
      });
      row.querySelector('.act-edit')?.addEventListener('click', () => openEdit(p));
      row.querySelectorAll('[data-full]').forEach(t => {
        t.addEventListener('click', (e) => {
          e.stopPropagation();
          if (window.showLightbox) window.showLightbox(t.dataset.full, t.dataset.title);
        });
      });
    });
  }

  function openEdit(p) {
    editingId = p.id;
    const del = !!Number(p.is_delegated);
    const photoBox = $('#photoManager');
    if (photoBox) photoBox.style.display = del ? 'none' : '';
    $('#delegatedHint')?.remove();
    if (del) {
      const hint = document.createElement('p');
      hint.id = 'delegatedHint';
      hint.className = 'hint';
      hint.style.margin = '0 2px 8px';
      hint.textContent = '此屋代管中:上下架與照片由管理員維護;您可修改租金與屋況資料(房客議價時請在此調整月租金)';
      const box = document.querySelector('#editBox');
      if (box) box.prepend(hint);
    }
    $('#f_id').value = p.id;
    $('#f_title').value = p.title || '';
    $('#f_city').value = p.city || '';
    $('#f_district').value = p.district || '';
    $('#f_address').value = p.address || '';
    $('#f_type').value = p.listing_type;
    $('#f_ptype').value = p.property_type || '';
    $('#f_rooms').value = p.layout_rooms == null ? '' : p.layout_rooms;
    $('#f_living').value = p.layout_living == null ? '' : p.layout_living;
    $('#f_bath').value = p.layout_bath == null ? '' : p.layout_bath;
    $('#f_area').value = p.area_ping == null ? '' : String(parseFloat(p.area_ping));
    $('#f_floorcur').value = p.floor_current == null ? '' : p.floor_current;
    $('#f_floortotal').value = p.floor_total == null ? '' : p.floor_total;
    $('#f_rent').value = p.rent ? p.rent : '';
    $('#f_mgmt').value = p.mgmt_fee == null ? '' : p.mgmt_fee;
    $('#f_rate').value = p.elec_rate == null ? '' : p.elec_rate;
    document.querySelectorAll('#editBox .mode-opt').forEach(b => {
      const on = (p.elec_mode || 'flat') === b.dataset.mode;
      b.classList.toggle('on', on);
      b.classList.toggle('line', !on);
    });
    $('#f_rate').disabled = (p.elec_mode || 'flat') === 'taipower';
    $('#modeHint').textContent = $('#f_rate').disabled ? '台電累進計價:依台電公告級距(夏月/非夏月)計算' : '固定單價:用電度數 × 此單價';
    $('#f_minlease').value = p.min_lease || '';
    $('#f_legaluse').value = p.legal_use || '';
    const dep0 = p.deposit == null ? 0 : Number(p.deposit);
    const rent0 = Number(p.rent || 0);
    depMonths = null;
    document.querySelectorAll('#depOpts .dep-opt').forEach(b => b.classList.remove('on'));
    depEl.setAttribute('readonly', '');
    depEl.style.background = '#FBF0DA';
    if (dep0 > 0 && rent0 > 0 && dep0 % rent0 === 0 && [1, 2, 3].indexOf(dep0 / rent0) !== -1) {
      depMonths = dep0 / rent0;
      const onBtn = document.querySelector('#depOpts .dep-opt[data-m="' + depMonths + '"]');
      if (onBtn) onBtn.classList.add('on');
    } else if (dep0 > 0) {
      const freeBtn = document.querySelector('#depOpts .dep-opt[data-m="0"]');
      if (freeBtn) freeBtn.classList.add('on');
      depEl.removeAttribute('readonly');
      depEl.style.background = '';
    }
    refreshDepHint();
    $('#f_sale').value = p.sale_price == null ? '' : p.sale_price;
    $('#f_status').value = p.status;
    $('#f_available').value = p.available_date || '';
    $('#f_facilities').value = p.facilities || '';
    $('#f_note').value = p.note || '';
    $('#f_parking').checked = Number(p.parking) === 1;
    $('#f_pet').checked = Number(p.pet_friendly) === 1;
    $('#f_cook').checked = Number(p.cooking_allowed) === 1;
    renderPhotoManager(p.photos || []);
    syncTypeUI();
    $('#f_status').value = p.status;
    $('#f_ptype').value = p.property_type || '';
    editBox.style.display = '';
    editBox.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  let editPhotos = [];
  function renderPhotoManager(photos) {
    editPhotos = photos.slice();
    const wrap = $('#photoManager');
    wrap.innerHTML = editPhotos.length ? '' : '<p class="hint" style="margin:0 2px 8px">尚無照片,下方可新增(最多 12 張)</p>';
    editPhotos.forEach((ph, i) => {
      const box = document.createElement('div');
      box.style.cssText = 'position:relative;width:74px;height:74px;border-radius:12px;overflow:hidden;border:2px solid var(--beige2)';
      const img = document.createElement('img');
      img.style.cssText = 'width:100%;height:100%;object-fit:cover';
      img.src = 'uploads/' + ph.file_path;
      const coverTag = document.createElement('span');
      coverTag.textContent = Number(ph.is_cover) ? '封面' : '';
      coverTag.style.cssText = 'position:absolute;left:0;bottom:0;right:0;font-size:10px;font-weight:800;color:#fff;background:rgba(74,56,38,.75);text-align:center;padding:1px 0';
      const ops = document.createElement('span');
      ops.style.cssText = 'position:absolute;top:2px;right:2px;display:flex;flex-direction:column;gap:2px';
      const star = document.createElement('button');
      star.type = 'button';
      star.textContent = '★';
      star.title = '設為封面';
      star.style.cssText = btnCss();
      star.addEventListener('click', async () => {
        const res = await fetch('api/listings.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id: editingId, action: 'set_cover', photo_id: ph.id }) });
        const d = await res.json();
        showToast(d.message);
        if (d.ok) { ph.is_cover = 1; editPhotos.forEach((x, j) => { if (j !== i) x.is_cover = 0; }); renderPhotoManager(editPhotos); }
      });
      const del = document.createElement('button');
      del.type = 'button';
      del.textContent = '✕';
      del.title = '刪除照片';
      del.style.cssText = btnCss();
      del.addEventListener('click', async () => {
        const res = await fetch('api/listings.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id: editingId, action: 'delete_photo', photo_id: ph.id }) });
        const d = await res.json();
        showToast(d.message);
        if (d.ok) { editPhotos.splice(i, 1); renderPhotoManager(editPhotos); load(); }
      });
      ops.append(star, del);
      box.append(img, ops, coverTag);
      wrap.appendChild(box);
    });
  }
  function btnCss() {
    return 'width:20px;height:20px;border:0;border-radius:50%;background:rgba(74,56,38,.85);color:#fff;font-size:11px;cursor:pointer;line-height:1';
  }
  $('#f_addphoto').addEventListener('change', async () => {
    const f = $('#f_addphoto').files[0];
    $('#f_addphoto').value = '';
    if (!f || !editingId) return;
    const fd = new FormData();
    fd.append('id', editingId);
    fd.append('action', 'add_photo');
    fd.append('photo', f, f.name);
    const res = await fetch('api/listings.php', { method: 'POST', body: fd });
    const d = await res.json();
    showToast(d.message);
    if (d.ok) load();
  });

  const depEl = $('#f_deposit');
  const depHint = $('#depHint');
  const rentIn = $('#f_rent');
  let depMonths = null;

  function refreshDepHint() {
    if (depMonths === null) {
      depHint.textContent = depEl.value && rentIn.value
        ? '自填金額(相當於 ' + (Number(depEl.value) / Number(rentIn.value)).toFixed(1) + ' 個月租金)'
        : '';
      return;
    }
    const r = Number(rentIn.value || 0);
    depHint.textContent = r > 0
      ? '押金 ' + depMonths + ' 個月 = NT$' + (r * depMonths).toLocaleString('zh-TW') + '元'
      : '請先填寫每月租金,系統將自動計算';
  }

  document.querySelectorAll('#depOpts .dep-opt').forEach(btn => {
    btn.addEventListener('click', () => {
      const m = Number(btn.dataset.m);
      document.querySelectorAll('#depOpts .dep-opt').forEach(b => b.classList.remove('on'));
      if (m === 0) {
        depMonths = null;
        btn.classList.add('on');
        depEl.removeAttribute('readonly');
        depEl.style.background = '';
        depEl.focus();
      } else {
        depMonths = m;
        btn.classList.add('on');
        depEl.setAttribute('readonly', '');
        depEl.style.background = '#FBF0DA';
        const r = Number(rentIn.value || 0);
        depEl.value = r > 0 ? String(r * m) : '';
      }
      refreshDepHint();
    });
  });

  rentIn.addEventListener('input', () => {
    rentIn.value = rentIn.value.replace(/\D/g, '').slice(0, 9);
    if (depMonths !== null) {
      const r = Number(rentIn.value || 0);
      depEl.value = r > 0 ? String(r * depMonths) : '';
    }
    refreshDepHint();
  });

  function syncTypeUI() {
    const isSale = $('#f_type').value === 'sale';
    $('#rentWrap').style.display = isSale ? 'none' : '';
    $('#depositWrap').style.display = isSale ? 'none' : '';
    $('#saleWrap').style.display = isSale ? '' : 'none';
    $('#availWrap').style.display = isSale ? 'none' : '';
    $('#f_status').innerHTML = isSale
      ? '<option value="for_sale">銷售中</option><option value="sold">已售出</option>'
      : '<option value="vacant">空置中</option><option value="rented">已出租</option>';
    $('#f_status').value = isSale ? 'for_sale' : 'vacant';
    if ($('#mgmtWrap')) $('#mgmtWrap').style.display = isSale ? 'none' : '';
    if ($('#erateWrap')) $('#erateWrap').style.display = isSale ? 'none' : '';
  }

  document.querySelectorAll('#editBox .mode-opt').forEach(btn => {
    btn.addEventListener('click', () => {
      document.querySelectorAll('#editBox .mode-opt').forEach(b => { b.classList.remove('on'); b.classList.add('line'); });
      btn.classList.add('on');
      btn.classList.remove('line');
      const tp = btn.dataset.mode === 'taipower';
      $('#f_rate').disabled = tp;
      if (tp) { $('#f_rate').value = ''; $('#modeHint').textContent = '台電累進計價:依台電公告級距(夏月/非夏月)計算'; }
      else { $('#modeHint').textContent = '固定單價:用電度數 × 此單價'; }
    });
  });

  $('#f_type').addEventListener('change', syncTypeUI);

  $('#cancelBtn').addEventListener('click', () => {
    editBox.style.display = 'none';
    editingId = null;
  });

  $('#editForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    if (!editingId) return;
    if ($('#f_title').value.trim().length < 2) { showToast('請輸入房屋名稱或地址(2–100 字)'); return; }
    const isSale = $('#f_type').value === 'sale';
    const num = (v) => v.replace(/\D/g, '');
    const body = {
      id: editingId,
      action: 'update',
      title: $('#f_title').value.trim(),
      city: $('#f_city').value.trim(),
      district: $('#f_district').value.trim(),
      address: $('#f_address').value.trim(),
      listing_type: $('#f_type').value,
      property_type: $('#f_ptype').value,
      rooms: num($('#f_rooms').value),
      living: num($('#f_living').value),
      bath: num($('#f_bath').value),
      area_ping: $('#f_area').value.trim(),
      floor_current: num($('#f_floorcur').value),
      floor_total: num($('#f_floortotal').value),
      rent: isSale ? '' : num($('#f_rent').value),
      deposit: isSale ? '' : num($('#f_deposit').value),
      sale_price: isSale ? num($('#f_sale').value) : '',
      mgmt_fee: isSale ? '' : num($('#f_mgmt').value),
      elec_rate: isSale ? '' : $('#f_rate').value.trim(),
      elec_mode: isSale ? 'flat' : ((document.querySelector('#editBox .mode-opt.on') || {}).dataset ? ((document.querySelector('#editBox .mode-opt.on') || {}).dataset.mode || 'flat') : 'flat'),
      min_lease: isSale ? '' : $('#f_minlease').value,
      legal_use: $('#f_legaluse').value,
      status: $('#f_status').value,
      parking: $('#f_parking').checked ? 1 : 0,
      pet_friendly: $('#f_pet').checked ? 1 : 0,
      cooking_allowed: $('#f_cook').checked ? 1 : 0,
      gender_limit: 'any',
      available_date: isSale ? '' : $('#f_available').value,
      facilities: $('#f_facilities').value.trim(),
      note: $('#f_note').value.trim()
    };
    const btn = $('#saveBtn');
    btn.disabled = true;
    try {
      const res = await fetch('api/listings.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body)
      });
      const d = await res.json();
      showToast(d.message);
      if (d.ok) {
        editBox.style.display = 'none';
        editingId = null;
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
