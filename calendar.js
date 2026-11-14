/* 帶看行事曆(業務) */
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

  let month = new Date().toISOString().slice(0, 7);
  let props = [];
  let editId = null;
  let allViewings = [];
  let fromLead = null;

  async function applyFromLead() {
    const leadId = new URLSearchParams(location.search).get('from_lead');
    if (!leadId) return;
    try {
      const res = await fetch('api/leads_list.php?status=all', { cache: 'no-store' });
      const d = await res.json();
      const l = (d.leads || []).find((x) => Number(x.id) === Number(leadId));
      if (!l) return;
      fromLead = l;
      if (props.length) openSheet(null, l);
      else toast('請先於房源管理建立房屋,再替客戶排帶看');
    } catch (e) { /* 忽略 */ }
  }
  function pad(n) { return n < 10 ? '0' + n : '' + n; }

  /* Google 行事曆連結(主要工具) */
  function gcalUrl(v) {
    const start = new Date(v.scheduled_at.replace(' ', 'T'));
    const end = new Date(start.getTime() + (Number(v.duration_min) || 60) * 60000);
    const fmt = (d) => d.getFullYear() + pad(d.getMonth() + 1) + pad(d.getDate()) + 'T' + pad(d.getHours()) + pad(d.getMinutes()) + '00';
    const loc = [v.city, v.district, v.address].filter(Boolean).join('');
    const params = new URLSearchParams({
      action: 'TEMPLATE',
      text: '帶看|' + v.property_title + '(' + v.client_name + ')',
      dates: fmt(start) + '/' + fmt(end),
      details: '安家生活GO 帶看行程\n客戶:' + v.client_name + (v.client_phone ? '(' + v.client_phone + ')' : '') + (v.note ? '\n備註:' + v.note : ''),
      location: loc,
      ctz: 'Asia/Taipei'
    });
    return 'https://calendar.google.com/calendar/render?' + params.toString();
  }

  function stHtml(st) {
    return { scheduled: '<span class="st warn">排定</span>', done: '<span class="st ok">已完成</span>', no_show: '<span class="st idle">客戶未到</span>', cancelled: '<span class="st idle">已取消</span>' }[st] || '';
  }

  async function load() {
    try {
      const res = await fetch('api/viewings_list.php?month=' + month, { cache: 'no-store' });
      const d = await res.json();
      if (!d.ok || !d.authenticated) { location.href = 'login.html'; return; }
      props = d.properties || [];
      allViewings = d.viewings || [];
      $('#mLabel').textContent = month.replace('-', ' 年 ') + ' 月';
      const vs = d.viewings || [];
      $('#sumLine').textContent = '本月 ' + vs.length + ' 筆' + (d.next ? '|下一筆:' + (d.next.client_name) + ' ' + (d.next.scheduled_at || '').slice(5, 16) : '');
      if (!vs.length) {
        $('#list').innerHTML = '<p style="padding:14px 2px;color:var(--brown2);font-size:13px;margin:0">本月尚無帶看,按「+ 新增帶看」排定行程。</p>';
        return;
      }
      let lastDate = '';
      $('#list').innerHTML = vs.map((v) => {
        const dt = new Date(v.scheduled_at.replace(' ', 'T'));
        const dateStr = dt.getFullYear() + '/' + pad(dt.getMonth() + 1) + '/' + pad(dt.getDate()) + '(' + ['日', '一', '二', '三', '四', '五', '六'][dt.getDay()] + ')';
        const head = dateStr !== lastDate ? '<div class="sect" style="margin:10px 2px 4px">' + dateStr + '</div>' : '';
        lastDate = dateStr;
        const timeStr = pad(dt.getHours()) + ':' + pad(dt.getMinutes()) + ' ~ ' + pad(new Date(dt.getTime() + v.duration_min * 60000).getHours()) + ':' + pad(new Date(dt.getTime() + v.duration_min * 60000).getMinutes());
        const addr = [v.city, v.district].filter(Boolean).join('');
        const ops = [];
        ops.push('<button type="button" class="mini-btn line act-edit" data-id="' + v.id + '">✏️ 編輯</button>');
        if (v.status === 'scheduled') {
          ops.push('<a class="mini-btn" style="text-decoration:none" target="_blank" rel="noopener" href="' + esc(gcalUrl(v)) + '">📅 加入Google行事曆</a>');
          ops.push('<button type="button" class="mini-btn line act-st" data-id="' + v.id + '" data-st="done">✅ 已完成</button>');
          ops.push('<button type="button" class="mini-btn line act-st" data-id="' + v.id + '" data-st="no_show">💤 未到</button>');
          ops.push('<button type="button" class="mini-btn line act-del" data-id="' + v.id + '">🗑</button>');
        }
        return head + '<div class="list-item"><span class="dot" style="background:' + (v.status === 'done' ? 'var(--green)' : v.status === 'scheduled' ? 'var(--wood)' : 'var(--beige2)') + '"></span>' +
          '<div><b>' + timeStr + ' ' + esc(v.client_name) + (v.client_phone ? ' ' + esc(v.client_phone) : '') + '</b>' +
          '<span>' + esc(v.property_title) + (addr ? '|' + esc(addr) : '') + (v.note ? '|' + esc(v.note) : '') + '</span></div>' +
          stHtml(v.status) +
          (ops.length ? '</div><div style="display:flex;gap:6px;flex-wrap:wrap;margin:0 2px 4px 26px">' + ops.join('') + '</div>' : '') +
          '</div>';
      }).join('');
    } catch (e) {
      $('#list').innerHTML = '<p style="padding:14px 2px;color:var(--brown2);font-size:13px;margin:0">連線失敗,請重試</p>';
    }
  }

  function openSheet(v, lead) {
    editId = v ? Number(v.id) : null;
    const L = lead || fromLead;
    $('#fTitle').textContent = v ? '編輯帶看' : (L ? '新增帶看(線索:' + L.name + ')' : '新增帶看');
    $('#fGo').textContent = v ? '儲存變更' : '排定帶看';
    $('#fProp').innerHTML = props.length
      ? props.map((p) => '<option value="' + p.id + '"' + (v && Number(v.property_id) === Number(p.id) ? ' selected' : '') + '>' + esc(p.title) + (p.city || p.district ? '(' + esc([p.city, p.district].filter(Boolean).join('')) + ')' : '') + '</option>').join('')
      : '<option value="">(尚無可管理的房屋)</option>';
    $('#fName').value = v ? v.client_name : (L ? L.name : '');
    $('#fPhone').value = v ? (v.client_phone || '') : (L ? (L.phone || '') : '');
    $('#fWhen').value = v ? v.scheduled_at.replace(' ', 'T').slice(0, 16) : '';
    $('#fDur').value = v ? String(v.duration_min || 60) : '60';
    $('#fNote').value = v ? (v.note || '') : (L ? '【線索跟進】' + ((L.requirement || '') + (L.note ? ';' + L.note : '')).slice(0, 180) : '');
    $('#mask').classList.add('show');
    $('.sheet').classList.add('show');
  }
  function closeSheet() {
    $('#mask').classList.remove('show');
    $('.sheet').classList.remove('show');
  }
  window.closeSheet = closeSheet;

  $('#addBtn').addEventListener('click', () => openSheet(null));
  $('#mask').addEventListener('click', (ev) => { if (ev.target === $('#mask')) closeSheet(); });
  $('#prevM').addEventListener('click', () => { const d = new Date(month + '-01'); d.setMonth(d.getMonth() - 1); month = d.getFullYear() + '-' + pad(d.getMonth() + 1); load(); });
  $('#nextM').addEventListener('click', () => { const d = new Date(month + '-01'); d.setMonth(d.getMonth() + 1); month = d.getFullYear() + '-' + pad(d.getMonth() + 1); load(); });

  $('#fGo').addEventListener('click', async () => {
    const propertyId = $('#fProp').value;
    const name = $('#fName').value.trim();
    const when = $('#fWhen').value;
    if (!propertyId) { toast('請先選擇房屋'); return; }
    if (!name) { toast('請輸入客戶姓名'); return; }
    if (!when) { toast('請選擇日期時間'); return; }
    const payload = { action: editId ? 'update' : 'add', property_id: Number(propertyId), client_name: name, client_phone: $('#fPhone').value.trim(), scheduled_at: when.replace('T', ' ') + ':00', duration_min: Number($('#fDur').value), note: $('#fNote').value.trim() };
    if (editId) payload.id = editId;
    try {
      const res = await fetch('api/viewings.php', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      });
      const r = await res.json();
      toast(r.message);
      if (r.ok) {
        closeSheet();
        /* 線索帶看:自動推進狀態 新線索→跟進中 */
        if (!editId && fromLead && fromLead.status === 'new') {
          try {
            await fetch('api/leads.php', {
              method: 'POST', headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({ action: 'status', id: fromLead.id, status: 'following' })
            });
            toast('已排定帶看,線索「' + fromLead.name + '」自動轉為跟進中');
          } catch (e2) { /* 忽略 */ }
        }
        load();
      }
      else if (editId) { closeSheet(); load(); } /* 紀錄已被刪除等狀況:刷新清單清掉過期畫面 */
    } catch (e) { toast('連線異常'); }
  });

  document.addEventListener('click', async (ev) => {
    const editBtn = ev.target.closest('.act-edit');
    if (editBtn) {
      const v = allViewings.find((x) => Number(x.id) === Number(editBtn.dataset.id));
      if (v) openSheet(v);
      return;
    }
    const stBtn = ev.target.closest('.act-st');
    if (stBtn) {
      try {
        const res = await fetch('api/viewings.php', {
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
    if (delBtn && confirm('確定刪除這筆帶看紀錄?')) {
      try {
        const res = await fetch('api/viewings.php', {
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
  applyFromLead();
})();
