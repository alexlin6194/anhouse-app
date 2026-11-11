(function () {
  'use strict';
  const $ = (s) => document.querySelector(s);
  const convId = new URLSearchParams(location.search).get('id');
  const threadEl = $('#thread');
  const inputEl = $('#msgInput');
  const state = { lastId: 0, myLastId: 0, otherRead: 0, polling: null };
  let toastTimer;

  function showToast(msg) {
    const t = $('#toast');
    t.textContent = msg;
    t.classList.add('show');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => t.classList.remove('show'), 2200);
  }

  function render(m) {
    const div = document.createElement('div');
    div.className = 'bubble ' + (Number(m.mine) ? 'me' : 'you');
    div.dataset.id = m.id;
    const readMark = Number(m.mine) && m.id <= state.otherRead ? ' | 已讀' : '';
    let inner = '';
    if (m.attachment) {
      const isImg = /\.(jpg|jpeg|png|webp|gif)$/i.test(m.attachment);
      const safe = String(m.attachment).replace(/&/g, '&amp;').replace(/"/g, '%22');
      if (isImg) {
        inner += '<img src="uploads/' + safe + '" alt="圖片" style="max-width:100%;max-height:240px;border-radius:12px;margin-bottom:6px;cursor:zoom-in">';
      } else {
        inner += '<a href="uploads/' + safe + '" download="' + String(m.att_name || '檔案').replace(/"/g, '') + '" style="display:flex;align-items:center;gap:6px;font-weight:700;text-decoration:none;padding:4px 0">📎 ' + (m.att_name || '檔案') + '</a>';
      }
    }
    if (m.body && (!m.attachment || !/^\[(圖片|檔案)\]$/.test(m.body))) {
      inner += m.body.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }
    div.innerHTML = inner + '<em>' + m.created_at.slice(11, 16) + (Number(m.mine) ? readMark : '') + '</em>';
    const img = div.querySelector('img');
    if (img) img.addEventListener('click', (e) => { e.stopPropagation(); showLightbox(img.src); });
    if (m.body && !m.attachment) bindCopy(div, m.body);
    threadEl.appendChild(div);
    state.lastId = Math.max(state.lastId, m.id);
    if (Number(m.mine)) state.myLastId = Math.max(state.myLastId, m.id);
    div.scrollIntoView({ block: 'end' });
  }

  function copyText(text) {
    const done = () => showToast('已複製訊息');
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(done).catch(() => fallbackCopy(text, done));
    } else {
      fallbackCopy(text, done);
    }
  }
  function fallbackCopy(text, done) {
    const ta = document.createElement('textarea');
    ta.value = text;
    ta.style.cssText = 'position:fixed;opacity:0';
    document.body.appendChild(ta);
    ta.select();
    try { document.execCommand('copy'); done(); } catch (e) { showToast('此裝置無法自動複製,請長按全選'); }
    ta.remove();
  }
  function bindCopy(div, text) {
    let lp = null;
    div.addEventListener('contextmenu', (e) => { e.preventDefault(); copyText(text); });
    div.addEventListener('touchstart', () => {
      lp = setTimeout(() => copyText(text), 550);
    }, { passive: true });
    ['touchend', 'touchmove', 'touchcancel'].forEach(ev =>
      div.addEventListener(ev, () => { if (lp) { clearTimeout(lp); lp = null; } }, { passive: true }));
  }

  async function poll(first) {
    let data;
    try {
      const res = await fetch('api/messages_thread.php?id=' + convId + (first ? '' : '&after_id=' + state.lastId));
      data = await res.json();
    } catch (e) { return; }
    if (!data.ok || !data.authenticated) { location.href = 'login.html'; return; }
    $('#otherName').textContent = data.other_name;
    state.otherRead = Math.max(state.otherRead, data.other_last_read_msg_id);
    if (first) threadEl.innerHTML = '';
    data.messages.forEach(render);
    if (!first && data.messages.length) {
      threadEl.querySelectorAll('.bubble.me em').forEach(em => {
        const id = Number(em.parentElement.dataset.id);
        if (id <= state.otherRead && !em.textContent.includes('已讀')) em.textContent += ' | 已讀';
      });
    }
  }

  async function send() {
    const body = inputEl.value.trim();
    if (!body && !pendingFile) return;
    $('#sendBtn').disabled = true;
    try {
      let data;
      if (pendingFile) {
        const fd = new FormData();
        fd.append('id', String(Number(convId)));
        if (body) fd.append('body', body);
        fd.append('file', pendingFile);
        const res = await fetch('api/messages_thread.php', { method: 'POST', body: fd });
        data = await res.json();
      } else {
        const res = await fetch('api/messages_thread.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ id: Number(convId), body })
        });
        data = await res.json();
      }
      if (data.ok) {
        inputEl.value = '';
        clearFile();
        await poll(false);
      } else {
        showToast(data.message);
      }
    } catch (e) {
      showToast('連線異常,訊息未送出');
    }
    $('#sendBtn').disabled = false;
    inputEl.focus();
  }

  let pendingFile = null;
  const fileInput = document.createElement('input');
  fileInput.type = 'file';
  fileInput.style.display = 'none';
  document.body.appendChild(fileInput);
  function clearFile() {
    pendingFile = null;
    fileInput.value = '';
    const chip = $('#fileChip');
    if (chip) chip.style.display = 'none';
  }
  fileInput.addEventListener('change', () => {
    const f = fileInput.files && fileInput.files[0];
    if (!f) return;
    pendingFile = f;
    let chip = $('#fileChip');
    if (!chip) {
      chip = document.createElement('div');
      chip.id = 'fileChip';
      const bar = document.querySelector('.inputbar');
      (bar && bar.parentNode || document.body).insertBefore(chip, bar);
    }
    chip.style.display = '';
    chip.textContent = '📎 ' + f.name + '(再點 📎 可更換,送出即傳)';
  });
  const attBtn = $('#attBtn');
  if (attBtn) attBtn.addEventListener('click', () => fileInput.click());

  if (!/^\d+$/.test(convId || '')) {
    document.getElementById('otherName').textContent = '對話編號錯誤';
  } else {
    poll(true).then(() => {
      state.polling = setInterval(() => poll(false), 5000);
    });
    $('#sendBtn').addEventListener('click', send);
    inputEl.addEventListener('keydown', (e) => { if (e.key === 'Enter') send(); });
  }
})();
