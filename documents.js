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
  const dstr = (d) => d ? String(d).replace(/-/g, '/').slice(0, 16) : '';
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const typeNames = { lease_contract: '📜 租賃契約書', repair_charge: '🧾 維修自費確認函' };

  let cache = null;
  let openDocId = null;

  function chip(d) {
    if (d.status === 'signed') return '<span class="st ok">已簽署</span>';
    if (d.status === 'rejected') return '<span class="st idle">已拒絕</span>';
    return '<span class="st warn">待簽署</span>';
  }

  function render() {
    const listEl = $('#list');
    const docs = (cache && cache.docs) || [];
    if (!docs.length) {
      listEl.innerHTML = '<p style="padding:18px 4px;text-align:center;color:var(--brown2);font-size:13px;margin:0">🌿 目前沒有文件</p>';
      return;
    }
    listEl.innerHTML = docs.map(d => {
      const st = statusText(d);
      return '<div class="lrow doc-row" data-id="' + d.id + '" style="cursor:pointer">' +
        '<div class="ltop"><div class="linfo">' +
        '<b>' + (typeNames[d.type] || '📄') + ' ' + esc(d.title) + '</b>' +
        '<span>' + (d.amount ? '金額 NT$' + Number(d.amount).toLocaleString('zh-TW') + '|' : '') + '建立 ' + dstr(d.created_at) +
        (d.status === 'signed' ? '|✍ ' + esc(d.sign_name || '') + ' 於 ' + dstr(d.signed_at) : '') + '</span>' +
        '</div>' + chip(d) + '</div>' +
        '</div>';
    }).join('');
    listEl.querySelectorAll('.doc-row').forEach(row => {
      row.addEventListener('click', () => openDoc(Number(row.dataset.id)));
    });
  }

  function statusText(d) {
    if (d.status === 'signed') return '已於 ' + dstr(d.signed_at) + ' 簽署';
    if (d.status === 'rejected') return '已拒絕簽署';
    return '待您簽署';
  }

  async function openDoc(id) {
    let d;
    try {
      const res = await fetch('api/documents.php?id=' + id, { cache: 'no-store' });
      const out = await res.json();
      if (!out.ok) { showToast(out.message); return; }
      d = out.doc;
    } catch (e) {
      showToast('連線異常,請稍後再試');
      return;
    }
    $('#docTitle').textContent = d.title;
    $('#docBody').textContent = d.content;
    const foot = $('#docFoot');
    const isSigner = Number(d.signer_user_id) === Number(cache.my_id);
    const isManager = ['landlord', 'manager', 'company_owner', 'admin_staff', 'accountant'].includes(cache.my_role);
    if (d.status === 'pending' && isSigner) {
      foot.innerHTML = '<input id="signName" placeholder="請輸入您的全名作為電子簽名" style="width:100%;border:1.8px solid var(--beige2);background:var(--surface);border-radius:14px;padding:12px 14px;font-size:15px;color:var(--brown);font-family:inherit;margin-bottom:10px">' +
        '<div style="display:flex;gap:10px"><button class="btn primary" id="signBtn" style="flex:1">🖊 同意並簽署</button><button class="btn ghost" id="rejectBtn" style="flex:1">拒絕簽署</button></div>';
      foot.querySelector('#signBtn').addEventListener('click', async () => {
        const n = foot.querySelector('#signName').value.trim();
        await postAction({ doc_id: id, action: 'sign', sign_name: n });
      });
      foot.querySelector('#rejectBtn').addEventListener('click', async () => {
        await postAction({ doc_id: id, action: 'reject' });
      });
    } else if (d.status === 'pending' && isManager) {
      foot.innerHTML = '<p style="font-size:12.5px;color:var(--brown2);text-align:center;margin:0">等待房客 ' + '簽署中(簽署後收費/生效)</p>';
    } else if (d.status === 'signed') {
      foot.innerHTML = '<p style="font-size:12.5px;color:var(--green-d);text-align:center;margin:0">✓ 已由「' + esc(d.sign_name) + '」於 ' + dstr(d.signed_at) + ' 電子簽署,文件生效</p>';
    } else {
      foot.innerHTML = '<p style="font-size:12.5px;color:var(--brown2);text-align:center;margin:0">此文件已拒絕簽署</p>';
    }
    $('#docMask').classList.add('show');
  }

  async function postAction(body) {
    try {
      const res = await fetch('api/documents.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body)
      });
      const d = await res.json();
      showToast(d.message);
      if (d.ok) {
        $('#docMask').classList.remove('show');
        load();
      }
    } catch (e) {
      showToast('連線異常,請稍後再試');
    }
  }

  async function load() {
    let data;
    try {
      const res = await fetch('api/documents.php', { cache: 'no-store' });
      data = await res.json();
    } catch (e) {
      showToast('連線異常,請稍後再試');
      return;
    }
    if (!data.ok || !data.authenticated) { location.href = 'login.html'; return; }
    cache = data;
    const backMap = { landlord: 'dashboard.html', manager: 'dashboard_manager.html', tenant: 'dashboard_tenant.html', company_owner: 'dashboard_company.html', admin_staff: 'dashboard_company.html', accountant: 'dashboard_company.html', vendor: 'repairs.html', agent: 'dashboard_agent.html' };
    if (backMap[data.my_role]) $('#navBack').setAttribute('href', backMap[data.my_role]);
    render();
  }

  $('#docClose').addEventListener('click', () => $('#docMask').classList.remove('show'));
  $('#docMask').addEventListener('click', (e) => { if (e.target === $('#docMask')) $('#docMask').classList.remove('show'); });

  load();
})();
