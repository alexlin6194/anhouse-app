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
  function hint(type, text) {
    const el = $('#hint-title');
    el.textContent = text || '';
    el.classList.toggle('err', type === 'err');
    el.classList.toggle('ok', type === 'ok');
  }

  let listingType = 'rental';
  let isManagerMode = false;
  const params = new URLSearchParams(location.search);
  const presetOrg = params.get('for_org');

  (async function initManagerMode() {
    try {
      const res = await fetch('api/create_property.php', { cache: 'no-store' });
      const d = await res.json();
      if (!d.ok || !d.authenticated) return;
      const list = d.for_landlords || [];
      if (!list.length) return;
      isManagerMode = true;
      $('#forOrgWrap').style.display = '';
      $('#navBack').setAttribute('href', 'dashboard_manager.html');
      $('#navTitle').textContent = '幫房東建立房源';
      const sel = $('#forOrg');
      sel.innerHTML = list.map(o => '<option value="' + o.org_id + '">' + esc(o.org_name) + '</option>').join('');
      if (presetOrg && list.some(o => String(o.org_id) === presetOrg)) sel.value = presetOrg;
    } catch (e) {}
  })();

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  }

  document.querySelectorAll('#typeSeg .role').forEach(btn => {
    btn.addEventListener('click', () => {
      document.querySelectorAll('#typeSeg .role').forEach(b => b.classList.remove('on'));      btn.classList.add('on');
      listingType = btn.dataset.t;
      const isSale = listingType === 'sale';
      $('#rentWrap').style.display = isSale ? 'none' : '';
      $('#depositWrap').style.display = isSale ? 'none' : '';
      ['mgmtWrap','waterWrap','pfeeWrap','cfeeWrap','ifeeWrap','cableWrap'].forEach(id => { $('#' + id).style.display = isSale ? 'none' : ''; });
      $('#erateWrap').style.display = isSale ? 'none' : '';
      $('#erate').disabled = $('.mode-opt.on') && $('.mode-opt.on').dataset.mode === 'taipower';
      if ($('#erate').disabled) $('#erate').value = '';
      $('#minleaseWrap').style.display = isSale ? 'none' : '';
      $('#saleWrap').style.display = isSale ? '' : 'none';
      $('#genderWrap').style.display = isSale ? 'none' : '';
      $('#availWrap').style.display = isSale ? 'none' : '';
      $('#statusWrap').innerHTML = isSale
        ? '<label for="status">刊登現況</label><select id="status"><option value="for_sale">銷售中</option><option value="sold">已售出</option></select>'
        : '<label for="status">刊登現況</label><select id="status"><option value="vacant">空置中(可看房)</option><option value="rented">已出租</option></select>';
    });
  });

  const MAX_PHOTOS = 12;
  const MAX_BYTES = 5 * 1024 * 1024;
  const RE_TITLE = /^.{2,100}$/;
  const num = (v) => v.replace(/\D/g, '');

  ['rent', 'deposit', 'sale', 'mgmt'].forEach(id => {
    const el = $('#' + id);
    if (el) el.addEventListener('input', () => { el.value = el.value.replace(/[^\d]/g, '').slice(0, 12); });
  });

  document.querySelectorAll('.mode-opt').forEach(btn => {
    btn.addEventListener('click', () => {
      document.querySelectorAll('.mode-opt').forEach(b => { b.classList.remove('on'); b.classList.add('line'); });
      btn.classList.add('on');
      btn.classList.remove('line');
      const tp = btn.dataset.mode === 'taipower';
      $('#erate').disabled = tp;
      if (tp) { $('#erate').value = ''; $('#modeHint').textContent = '台電累進計價:依台電公告級距(夏月/非夏月)計算'; }
      else { $('#modeHint').textContent = '固定單價:用電度數 × 此單價'; }
    });
  });

  $('#title').addEventListener('input', () => {
    const v = $('#title').value.trim();
    if (!v) { hint('', ''); return; }
    hint(RE_TITLE.test(v) ? 'ok' : 'err', RE_TITLE.test(v) ? '✓' : '請輸入 2–100 字');
  });

  let files = [];
  $('#photos').addEventListener('change', () => {
    const incoming = [...($('#photos').files || [])];
    for (const f of incoming) {
      if (files.length >= MAX_PHOTOS) { showToast('照片最多 12 張'); break; }
      if (!/^image\/(jpeg|png|webp)$/.test(f.type)) { showToast('照片格式僅限 JPG/PNG/WEBP'); continue; }
      if (f.size > MAX_BYTES) { showToast('「' + f.name + '」超過 5MB'); continue; }
      files.push(f);
    }
    renderPreviews();
    $('#photos').value = '';
  });

  function renderPreviews() {
    $('#preview').innerHTML = '';
    files.forEach((f, i) => {
      const box = document.createElement('div');
      box.style.cssText = 'position:relative;width:74px;height:74px;border-radius:12px;overflow:hidden;border:2px solid var(--beige2)';
      const img = document.createElement('img');
      img.style.cssText = 'width:100%;height:100%;object-fit:cover';
      img.src = URL.createObjectURL(f);
      const del = document.createElement('button');
      del.type = 'button';
      del.textContent = '✕';
      del.style.cssText = 'position:absolute;top:2px;right:2px;width:20px;height:20px;border:0;border-radius:50%;background:rgba(74,56,38,.85);color:#fff;font-size:11px;cursor:pointer';
      del.addEventListener('click', () => { files.splice(i, 1); renderPreviews(); });
      const cover = document.createElement('span');
      cover.textContent = i === 0 ? '封面' : '';
      cover.style.cssText = 'position:absolute;left:0;bottom:0;right:0;font-size:10px;font-weight:800;color:#fff;background:rgba(74,56,38,.75);text-align:center;padding:1px 0';
      box.append(img, del, cover);
      $('#preview').appendChild(box);
    });
    $('#hint-photos').textContent = files.length ? '已選 ' + files.length + ' 張(首張為封面)' : '';
  }

  $('#propForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const title = $('#title').value.trim();
    if (!RE_TITLE.test(title)) { showToast('請輸入房屋名稱或地址(2–100 字)'); return; }
    const isSale = listingType === 'sale';
    if (!isSale && num($('#rent').value) === '') { showToast('請輸入月租金'); return; }
    if (isSale && num($('#sale').value) === '') { showToast('請輸入售價'); return; }
    if (!$('#confirmOk').checked) { showToast('請先勾選確認事項'); return; }

    const fd = new FormData();
    fd.append('title', title);
    fd.append('city', $('#city').value.trim());
    fd.append('district', $('#district').value.trim());
    fd.append('address', $('#address').value.trim());
    fd.append('listing_type', listingType);
    fd.append('property_type', $('#ptype').value);
    fd.append('rooms', num($('#rooms').value));
    fd.append('living', num($('#living').value));
    fd.append('bath', num($('#bath').value));
    fd.append('area_ping', $('#area').value.trim());
    fd.append('floor_current', num($('#floorcur').value));
    fd.append('floor_total', num($('#floortotal').value));
    fd.append('legal_use', $('#legaluse').value);
    fd.append('rent', isSale ? '' : num($('#rent').value));
    fd.append('deposit', isSale ? '' : num($('#deposit').value));
    fd.append('sale_price', isSale ? num($('#sale').value) : '');
    fd.append('mgmt_fee', isSale ? '' : num($('#mgmt').value));
    fd.append('water_fee', isSale ? '' : num($('#water').value));
    fd.append('parking_fee', isSale ? '' : num($('#pfee').value));
    fd.append('cleaning_fee', isSale ? '' : num($('#cfee').value));
    fd.append('internet_fee', isSale ? '' : num($('#ifee').value));
    fd.append('cable_fee', isSale ? '' : num($('#cable').value));
    fd.append('elec_rate', isSale ? '' : $('#erate').value.trim());
    fd.append('elec_mode', (document.querySelector('.mode-opt.on') || {}).dataset ? ((document.querySelector('.mode-opt.on') || {}).dataset.mode || 'flat') : 'flat');
    fd.append('min_lease', isSale ? '' : $('#minlease').value);
    fd.append('status', $('#status').value);
    fd.append('parking', $('#parking').checked ? '1' : '0');
    fd.append('pet_friendly', $('#pet').checked ? '1' : '0');
    fd.append('cooking_allowed', $('#cook').checked ? '1' : '0');
    fd.append('gender_limit', $('#gender').value);
    fd.append('available_date', isSale ? '' : $('#available').value);
    fd.append('facilities', $('#facilities').value.trim());
    fd.append('note', $('#note').value.trim());
    if (isManagerMode) fd.append('for_org', $('#forOrg').value);
    files.slice(0, MAX_PHOTOS).forEach(f => fd.append('photos[]', f, f.name));

    const btn = $('#submitBtn');
    btn.disabled = true;
    try {
      const res = await fetch('api/create_property.php', { method: 'POST', body: fd });
      const data = await res.json();
      showToast(data.message + (data.upgrade_notice ? '' : ''));
      if (data.ok) {
        setTimeout(() => location.href = data.redirect || 'dashboard.html', 1200);
      } else {
        btn.disabled = false;
      }
    } catch (err) {
      btn.disabled = false;
      showToast('連線異常,請檢查網路或稍後再試');
    }
  });
})();
