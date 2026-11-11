(function () {
  'use strict';
  const $ = (s) => document.querySelector(s);
  const state = { phoneOk: false, exists: false, houses: [] };
  let checkTimer = null;

  const toast = $('#toast');
  let toastTimer;
  function showToast(msg) {
    toast.textContent = msg;
    toast.classList.add('show');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => toast.classList.remove('show'), 2400);
  }
  function hint(type, text) {
    const el = $('#hint-phone');
    el.textContent = text || '';
    el.classList.toggle('err', type === 'err');
    el.classList.toggle('ok', type === 'ok');
  }

  async function loadHouses() {
    let data;
    try {
      const res = await fetch('api/landlord_dashboard.php');
      data = await res.json();
    } catch (e) { location.href = 'login.html'; return; }
    if (!data.ok || !data.authenticated) { location.href = 'login.html'; return; }
    if (!data.landlord) { location.href = 'welcome.html'; return; }
    state.houses = data.houses.filter(h => h.manager_name === null);
    const list = $('#houseList');
    if (!state.houses.length) {
      list.innerHTML = '<p style="padding:14px 2px;color:var(--brown2);font-size:13px;margin:0">所有房屋都已在代管中,沒有可指派的房屋。</p>';
      $('#sendBtn').disabled = true;
      return;
    }
    list.innerHTML = state.houses.map(h =>
      '<label class="list-item"><input type="checkbox" class="houseChk" value="' + h.id + '">' +
      '<div><b>' + h.title + '</b><span>' + (h.status === 'rented' ? '出租中|月租 NT$' + Number(h.rent).toLocaleString('zh-TW') : '空置中') + '</span></div></label>'
    ).join('');
    document.querySelectorAll('.houseChk').forEach(c => c.addEventListener('change', syncCount));
    $('#selAll').addEventListener('change', () => {
      document.querySelectorAll('.houseChk').forEach(c => c.checked = $('#selAll').checked);
      syncCount();
    });
    syncCount();
  }

  function syncCount() {
    const n = document.querySelectorAll('.houseChk:checked').length;
    $('#selCount').textContent = '已選 ' + n + ' 間房屋';
    $('#selAll').checked = n > 0 && n === state.houses.length;
  }

  const phoneEl = $('#phone');
  phoneEl.addEventListener('input', () => {
    phoneEl.value = phoneEl.value.replace(/\D/g, '').slice(0, 10);
    state.phoneOk = false; state.exists = false;
    if (!/^09\d{8}$/.test(phoneEl.value)) {
      hint(phoneEl.value ? 'err' : '', phoneEl.value ? '手機須為 09 開頭共 10 碼' : '');
      return;
    }
    hint('', '檢查中…');
    clearTimeout(checkTimer);
    checkTimer = setTimeout(async () => {
      try {
        const res = await fetch('api/check_phone.php?phone=' + phoneEl.value);
        const data = await res.json();
        if (!data.valid) { hint('err', '手機格式不符'); return; }
        if (data.exists) { state.phoneOk = true; state.exists = true; hint('ok', '✓ 已註冊,可發送邀請'); }
        else { state.phoneOk = true; state.exists = false; hint('err', '✗ 此號碼尚未註冊,無法接收邀請;請先請對方下載「安家房屋管理 APP」註冊'); }
      } catch (e) { hint('', ''); }
    }, 400);
  });

  $('#sendBtn').addEventListener('click', async () => {
    const ids = [...document.querySelectorAll('.houseChk:checked')].map(c => Number(c.value));
    if (!ids.length) { showToast('請先勾選要委託的房屋'); return; }
    if (!state.phoneOk) { showToast('請輸入正確的管理員手機號碼'); return; }
    if (!state.exists) { showToast('此號碼尚未註冊,無法接收邀請'); return; }
    const btn = $('#sendBtn');
    btn.disabled = true;
    try {
      const res = await fetch('api/create_manager_invite.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ property_ids: ids, phone: phoneEl.value })
      });
      const data = await res.json();
      showToast(data.message);
      if (data.ok) setTimeout(() => location.href = 'dashboard.html', 1600);
      else btn.disabled = false;
    } catch (e) {
      btn.disabled = false;
      showToast('連線異常,請稍後再試');
    }
  });

  loadHouses();
})();
