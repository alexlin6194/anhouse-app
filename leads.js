/* 客戶線索(業務) */
(function () {
  'use strict';
  const $ = (s) => document.querySelector(s);
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  }
  let toastTimer;
  function toast(msg) {
    const t = $('#toast');
    t.textContent = msg;
    t.classList.add('show');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => t.classList.remove('show'), 3000);
  }
  window.closeSheet = function () {
    $('#mask').classList.remove('show');
    $('.sheet').classList.remove('show');
  };

  const SOURCE_TXT = { walk_in: '走入店面', phone: '來電', referral: '親友介紹', web_591: '網路房產平台', facebook: 'FB/社群', other: '其他' };
  const ST_TXT = { new: ['新線索', 'warn'], following: ['跟進中', 'warn'], viewed: ['已帶看', 'ok'], dealt: ['成交', 'ok'], lost: ['已流失', 'idle'] };

  let filter = 'all';
  let leads = [];
  let editId = null;

  function money(n) { return 'NT$' + Number(n).toLocaleString('zh-TW'); }

  function budgetText(l) {
    if (l.budget_min && l.budget_max) return money(l.budget_min) + '~' + money(l.budget_max);
    if (l.budget_max) return '上限 ' + money(l.budget_max);
    if (l.budget_min) return '以上 ' + money(l.budget_min);
    return '';
  }

  async function load() {
    try {
      const res = await fetch('api/leads_list.php?status=' + filter, { cache: 'no-store' });
      const d = await res.json();
      if (!d.ok || !d.authenticated) { location.href = 'login.html'; return; }
      leads = d.leads || [];
      const c = d.counts || {};
      $('#sumLine').textContent = '新 ' + (c.new || 0) + '|跟進 ' + (c.following || 0) + '|已帶看 ' + (c.viewed || 0) + '|成交 ' + (c.dealt || 0);
      if (!leads.length) {
        $('#list').innerHTML = '<p style="padding:14px 2px;color:var(--brown2);font-size:13px;margin:0">尚無線索:按「+ 新增線索」記下每位上門/來電客戶。</p>';
        return;
      }
      $('#list').innerHTML = leads.map((l) => {
        const st = ST_TXT[l.status] || ['', 'idle'];
        const budget = budgetText(l);
        const intent = l.intent === 'buy' ? '買' : '租';
        const follow = l.next_follow_at ? '🗓 跟進:' + l.next_follow_at.slice(5, 16).replace(' ', ' ') : '';
        return '<div class="list-item"><span class="dot" style="background:' + (l.status === 'dealt' ? 'var(--green)' : l.status === 'lost' ? 'var(--beige2)' : 'var(--wood)') + '"></span>' +
          '<div><b>' + esc(l.name) + (l.phone ? ' <a href="tel:' + esc(l.phone) + '" style="text-decoration:none">📞' + esc(l.phone) + '</a>' : '') + '</b>' +
          '<span>' + intent + (l.source !== 'other' ? '|' + SOURCE_TXT[l.source] : '') + (budget ? '|' + budget : '') + (l.city || l.district ? '|📍' + esc([l.city, l.district].filter(Boolean).join('')) : '') + '</span>' +
          (l.requirement ? '<span>' + esc(l.requirement) + '</span>' : '') +
          (l.note ? '<span>' + esc(l.note) + '</span>' : '') +
          (follow ? '<span style="color:var(--wood-d);font-weight:700">' + follow + '</span>' : '') +
          '</div><span class="st ' + st[1] + '">' + st[0] + '</span></div>' +
          '<div style="display:flex;gap:6px;flex-wrap:wrap;margin:0 2px 4px 26px">' +
          '<button type="button" class="mini-btn line act-edit" data-id="' + l.id + '">✏️ 編輯</button>' +
          (l.status !== 'dealt' && l.status !== 'lost' ? '<button type="button" class="mini-btn act-visit" data-id="' + l.id + '">📅 排帶看</button>' : '') +
          (l.status === 'new' ? '<button type="button" class="mini-btn act-st" data-id="' + l.id + '" data-st="following">開始跟進</button>' : '') +
          (l.status === 'following' ? '<button type="button" class="mini-btn act-st" data-id="' + l.id + '" data-st="viewed">已帶看</button>' : '') +
          (l.status === 'viewed' ? '<button type="button" class="mini-btn act-st" data-id="' + l.id + '" data-st="dealt">🎉 成交</button>' : '') +
          (l.status !== 'lost' && l.status !== 'dealt' ? '<button type="button" class="mini-btn line act-st" data-id="' + l.id + '" data-st="lost">流失</button>' : '') +
          '<button type="button" class="mini-btn line act-del" data-id="' + l.id + '">🗑</button>' +
          '</div>';
      }).join('');
    } catch (e) {
      $('#list').innerHTML = '<p style="padding:14px 2px;color:var(--brown2);font-size:13px;margin:0">連線失敗,請重試</p>';
    }
  }

  function openSheet(l) {
    editId = l ? Number(l.id) : null;
    $('#fTitle').textContent = l ? '編輯線索 — ' + l.name : '新增線索';
    $('#fGo').textContent = l ? '儲存變更' : '儲存';
    $('#fName').value = l ? l.name : '';
    $('#fPhone').value = l ? (l.phone || '') : '';
    $('#fIntent').value = l ? l.intent : 'rent';
    $('#fSource').value = l ? l.source : 'other';
    $('#fBmin').value = l && l.budget_min ? l.budget_min : '';
    $('#fBmax').value = l && l.budget_max ? l.budget_max : '';
    $('#fCity').value = l ? [l.city, l.district].filter(Boolean).join(' ') : '';
    $('#fStatus').value = l ? l.status : 'new';
    $('#fReq').value = l ? (l.requirement || '') : '';
    $('#fNote').value = l ? (l.note || '') : '';
    $('#fFollow').value = l && l.next_follow_at ? l.next_follow_at.slice(0, 16).replace(' ', 'T') : '';
    $('#mask').classList.add('show');
    $('.sheet').classList.add('show');
  }

  $('#addBtn').addEventListener('click', () => openSheet(null));
  $('#mask').addEventListener('click', (ev) => { if (ev.target === $('#mask')) window.closeSheet(); });

  $('#fGo').addEventListener('click', async () => {
    const name = $('#fName').value.trim();
    if (!name) { toast('請輸入客戶姓名'); return; }
    const cityParts = $('#fCity').value.trim().split(/\s+/);
    const payload = {
      action: editId ? 'update' : 'add',
      name,
      phone: $('#fPhone').value.trim(),
      intent: $('#fIntent').value,
      source: $('#fSource').value,
      budget_min: $('#fBmin').value.trim(),
      budget_max: $('#fBmax').value.trim(),
      city: cityParts[0] || '',
      district: cityParts.slice(1).join('') || '',
      requirement: $('#fReq').value.trim(),
      note: $('#fNote').value.trim(),
      status: $('#fStatus').value,
      next_follow_at: $('#fFollow').value ? $('#fFollow').value.replace('T', ' ') + ':00' : ''
    };
    if (editId) payload.id = editId;
    try {
      const res = await fetch('api/leads.php', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      });
      const r = await res.json();
      toast(r.message);
      if (r.ok) { window.closeSheet(); load(); }
      else if (editId) { window.closeSheet(); load(); }
    } catch (e) { toast('連線異常'); }
  });

  document.querySelectorAll('.tab').forEach((t) => t.addEventListener('click', () => {
    document.querySelectorAll('.tab').forEach((x) => x.classList.remove('on'));
    t.classList.add('on');
    filter = t.dataset.st;
    load();
  }));

  document.addEventListener('click', async (ev) => {
    const editBtn = ev.target.closest('.act-edit');
    if (editBtn) {
      const l = leads.find((x) => Number(x.id) === Number(editBtn.dataset.id));
      if (l) openSheet(l);
      return;
    }
    const visitBtn = ev.target.closest('.act-visit');
    if (visitBtn) {
      location.href = 'calendar.html?from_lead=' + visitBtn.dataset.id;
      return;
    }
    const stBtn = ev.target.closest('.act-st');
    if (stBtn) {
      try {
        const res = await fetch('api/leads.php', {
          method: 'POST', headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ action: 'status', id: Number(stBtn.dataset.id), status: stBtn.dataset.st })
        });
        const r = await res.json();
        toast(r.message);
        if (r.ok) load();
      } catch (e) { toast('連線異常'); }
      return;
    }
    const delBtn = ev.target.closest('.act-del');
    if (delBtn && confirm('確定刪除這筆客戶線索?')) {
      try {
        const res = await fetch('api/leads.php', {
          method: 'POST', headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ action: 'delete', id: Number(delBtn.dataset.id) })
        });
        const r = await res.json();
        toast(r.message);
        if (r.ok) load();
      } catch (e) { toast('連線異常'); }
    }
  });

  load();
})();
