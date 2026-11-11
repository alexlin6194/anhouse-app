(function () {
  'use strict';
  const $ = (s) => document.querySelector(s);
  const RE_PHONE = /^09\d{8}$/;
  const state = { exists: false };
  let toastTimer;

  function showToast(msg) {
    const t = $('#toast');
    t.textContent = msg;
    t.classList.add('show');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => t.classList.remove('show'), 2400);
  }
  function hint(type, text) {
    const el = $('#hint-phone');
    el.textContent = text || '';
    el.classList.toggle('err', type === 'err');
    el.classList.toggle('ok', type === 'ok');
  }
  function timeAgo(dt) {
    if (!dt) return '';
    const diff = Math.floor((Date.now() - new Date(dt.replace(' ', 'T')).getTime()) / 1000);
    if (diff < 60) return '剛剛';
    if (diff < 3600) return Math.floor(diff / 60) + ' 分前';
    if (diff < 86400) return Math.floor(diff / 3600) + ' 時前';
    if (diff < 172800) return '昨天';
    return dt.slice(5, 10);
  }

  async function load() {
    let data;
    try {
      const res = await fetch('api/messages.php');
      data = await res.json();
    } catch (e) { showToast('連線異常'); return; }
    if (!data.ok || !data.authenticated) { location.href = 'login.html'; return; }

    const list = $('#convList');
    if (!data.conversations.length) {
      list.innerHTML = '<p style="padding:22px 4px;text-align:center;color:var(--brown2);font-size:13px;margin:0">💬 還沒有對話:點右上「＋ 新對話」,輸入對方手機開始聊天</p>';
      return;
    }
    list.innerHTML = data.conversations.map(c =>
      '<div class="conv" data-id="' + c.id + '" data-name="' + c.other_name + '">' +
      '<div class="cav">' + (c.other_name || '?').charAt(0) + '</div>' +
      '<div><b>' + c.other_name + '</b><span>' + (c.last_body ? c.last_body : '(還沒有訊息)') + '</span></div>' +
      '<div class="ct">' + timeAgo(c.last_time) + (Number(c.unread) > 0 ? '<br><span class="cb">' + c.unread + '</span>' : '') + '</div>' +
      '</div>'
    ).join('');
    list.querySelectorAll('.conv').forEach(el => {
      el.addEventListener('click', () => {
        location.href = 'messages_thread.html?id=' + el.dataset.id;
      });
    });
  }

  $('#newBtn').addEventListener('click', () => {
    $('#newConv').classList.toggle('show');
  });

  const phoneEl = $('#ncPhone');
  phoneEl.addEventListener('input', () => {
    phoneEl.value = phoneEl.value.replace(/\D/g, '').slice(0, 10);
    state.exists = false;
    if (!RE_PHONE.test(phoneEl.value)) { hint(phoneEl.value ? 'err' : '', phoneEl.value ? '手機須為 09 開頭共 10 碼' : ''); return; }
    hint('', '檢查中…');
    clearTimeout(checkTimer);
    checkTimer = setTimeout(async () => {
      try {
        const res = await fetch('api/check_phone.php?phone=' + phoneEl.value);
        const data = await res.json();
        if (data.exists) { state.exists = true; hint('ok', '✓ 已註冊,可開始對話'); }
        else { state.exists = false; hint('err', '✗ 此號碼尚未註冊,無法對話'); }
      } catch (e) { hint('', ''); }
    }, 400);
  });

  let checkTimer;
  $('#createBtn').addEventListener('click', async () => {
    if (!RE_PHONE.test(phoneEl.value)) { showToast('請輸入正確的對方手機號碼'); return; }
    if (!state.exists) { showToast('此號碼尚未註冊,無法對話'); return; }
    const btn = $('#createBtn');
    btn.disabled = true;
    try {
      const res = await fetch('api/messages.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ phone: phoneEl.value, first_message: $('#ncFirst').value.trim() })
      });
      const data = await res.json();
      showToast(data.message);
      if (data.ok && data.redirect) setTimeout(() => location.href = data.redirect, 900);
      else btn.disabled = false;
    } catch (e) {
      btn.disabled = false;
      showToast('連線異常');
    }
  });

  load();
})();
