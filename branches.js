(async function () {
  const $ = s => document.querySelector(s);
  let cache = null;
  const roleTxt = { agent: '業務', manager: '管理員', accountant: '會計人員', admin_staff: '行政人員' };
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  }
  window.toast = function (m) {
    const t = document.getElementById('toast');
    t.textContent = m; t.classList.add('show');
    setTimeout(() => t.classList.remove('show'), 2600);
  };

  function render() {
    $('#orgLine').textContent = '員工/房屋可歸屬分公司,未指派則為「未分派」';
    const list = $('#list');
    const brs = cache.branches || [];
    if (!brs.length) {
      list.innerHTML = '<div class="card"><p style="padding:14px 2px;color:var(--brown2);font-size:13px;margin:0">目前無分公司 — 點「➕ 新增分公司」建立第一間分店,再將員工與房屋指派過去</p></div>';
      return;
    }
    list.innerHTML = brs.map(b =>
      '<div class="card" style="padding:12px 16px;margin-bottom:10px">' +
      '<div style="display:flex;align-items:center;gap:8px"><b style="font-size:15px;color:var(--brown)">' + esc(b.name) + '</b>' +
      (b.status === 'active' ? '<span class="st ok">啟用中</span>' : '<span class="st idle">已停用</span>') + '</div>' +
      '<div style="font-size:12.5px;color:var(--brown2);margin-top:4px">' +
      '👥 員工 ' + b.member_count + ' 位・🏠 房屋 ' + b.property_count + ' 間' +
      (b.manager_name ? '・店長 ' + esc(b.manager_name) : '') +
      (b.phone ? '・☎ ' + esc(b.phone) : '') + '</div>' +
      (b.address ? '<div style="font-size:12px;color:var(--brown2)">' + esc(b.address) + '</div>' : '') +
      '<div style="display:flex;gap:8px;margin-top:8px">' +
      '<button class="btn ghost" style="padding:6px 12px" onclick="openEdit(' + b.id + ')">✏️ 編輯</button>' +
      '<button class="btn line" style="padding:6px 12px" onclick="toggleBr(' + b.id + ')">' + (b.status === 'active' ? '⏸ 停用' : '▶ 啟用') + '</button>' +
      '</div></div>'
    ).join('');
  }

  async function load() {
    try {
      const res = await fetch('api/branches.php', { cache: 'no-store' });
      const d = await res.json();
      if (!d.ok) { toast(d.message || '載入失敗'); return; }
      cache = d;
      render();
    } catch (e) { toast('連線異常'); }
  }

  function openMask(title, html) {
    $('#ttl').textContent = title;
    $('#body').innerHTML = html;
    document.getElementById('mask').classList.add('show');
    document.querySelector('.sheet').classList.add('show');
  }
  window.closeMask = function () {
    document.getElementById('mask').classList.remove('show');
    document.querySelector('.sheet').classList.remove('show');
  };

  window.openCreate = function () {
    openMask('➕ 新增分公司',
      '<label style="font-size:12.5px;color:var(--brown2);font-weight:700">分公司名稱</label>' +
      '<input id="brName" placeholder="例:台中分公司" style="width:100%;padding:12px;border:1.5px solid var(--beige2);border-radius:12px;font-size:14px">' +
      '<label style="font-size:12.5px;color:var(--brown2);font-weight:700;display:block;margin-top:10px">電話(選填)</label>' +
      '<input id="brPhone" style="width:100%;padding:12px;border:1.5px solid var(--beige2);border-radius:12px;font-size:14px">' +
      '<label style="font-size:12.5px;color:var(--brown2);font-weight:700;display:block;margin-top:10px">地址(選填)</label>' +
      '<input id="brAddr" style="width:100%;padding:12px;border:1.5px solid var(--beige2);border-radius:12px;font-size:13px">' +
      '<label style="font-size:12.5px;color:var(--brown2);font-weight:700;display:block;margin-top:10px">店長(選填,從員工中選)</label>' +
      '<select id="brMgr" style="width:100%;padding:12px;border:1.5px solid var(--beige2);border-radius:12px;font-size:14px">' +
      '<option value="0">暫不指派</option>' +
      (cache.members || []).map(m => '<option value="' + m.user_id + '">' + esc(m.name) + '(' + (roleTxt[m.role] || m.role) + ')</option>').join('') +
      '</select>' +
      '<div style="display:flex;gap:10px;margin-top:14px"><button class="btn ghost" style="flex:1" onclick="closeMask()">取消</button>' +
      '<button class="btn primary" style="flex:1" id="brGo">建立</button></div>');
    document.getElementById('brGo').addEventListener('click', async () => {
      try {
        const res = await fetch('api/branches.php', {
          method: 'POST', headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ action: 'create', name: $('#brName').value.trim(), phone: $('#brPhone').value.trim(), address: $('#brAddr').value.trim(), manager_user_id: Number($('#brMgr').value || 0) })
        });
        const r = await res.json();
        toast(r.message);
        if (r.ok) { window.closeMask(); load(); }
      } catch (e) { toast('連線異常'); }
    });
  };

  window.openEdit = function (id) {
    const b = (cache.branches || []).find(x => Number(x.id) === id);
    if (!b) return;
    openMask('✏️ 編輯「' + b.name + '」',
      '<label style="font-size:12.5px;color:var(--brown2);font-weight:700">分公司名稱</label>' +
      '<input id="brName" value="' + esc(b.name) + '" style="width:100%;padding:12px;border:1.5px solid var(--beige2);border-radius:12px;font-size:14px">' +
      '<label style="font-size:12.5px;color:var(--brown2);font-weight:700;display:block;margin-top:10px">電話</label>' +
      '<input id="brPhone" value="' + esc(b.phone || '') + '" style="width:100%;padding:12px;border:1.5px solid var(--beige2);border-radius:12px;font-size:14px">' +
      '<label style="font-size:12.5px;color:var(--brown2);font-weight:700;display:block;margin-top:10px">地址</label>' +
      '<input id="brAddr" value="' + esc(b.address || '') + '" style="width:100%;padding:12px;border:1.5px solid var(--beige2);border-radius:12px;font-size:13px">' +
      '<label style="font-size:12.5px;color:var(--brown2);font-weight:700;display:block;margin-top:10px">店長</label>' +
      '<select id="brMgr" style="width:100%;padding:12px;border:1.5px solid var(--beige2);border-radius:12px;font-size:14px">' +
      '<option value="0">暫不指派</option>' +
      (cache.members || []).map(m => '<option value="' + m.user_id + '"' + (Number(b.manager_user_id) === Number(m.user_id) ? ' selected' : '') + '>' + esc(m.name) + '(' + (roleTxt[m.role] || m.role) + ')</option>').join('') +
      '</select>' +
      '<div style="display:flex;gap:10px;margin-top:14px"><button class="btn ghost" style="flex:1" onclick="closeMask()">取消</button>' +
      '<button class="btn primary" style="flex:1" id="brGo">儲存</button></div>');
    document.getElementById('brGo').addEventListener('click', async () => {
      try {
        const res = await fetch('api/branches.php', {
          method: 'POST', headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ action: 'update', id, name: $('#brName').value.trim(), phone: $('#brPhone').value.trim(), address: $('#brAddr').value.trim(), manager_user_id: Number($('#brMgr').value || 0) })
        });
        const r = await res.json();
        toast(r.message);
        if (r.ok) { window.closeMask(); load(); }
      } catch (e) { toast('連線異常'); }
    });
  };

  window.toggleBr = async function (id) {
    try {
      const res = await fetch('api/branches.php', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'update', id, toggle_status: 1 })
      });
      const r = await res.json();
      toast(r.message);
      if (r.ok) load();
    } catch (e) { toast('連線異常'); }
  };

  window.openAssign = async function () {
    try {
      const pRes = await fetch('api/assign_staff.php', { cache: 'no-store' });
      const pd = await pRes.json();
      const props = (pd.ok ? pd.properties : []);
      const brs = (cache.branches || []).filter(b => b.status === 'active');
      const un = cache.unassigned || { members: 0, properties: 0 };
      openMask('📌 指派員工/房屋至分公司',
        '<label style="font-size:12.5px;color:var(--brown2);font-weight:700">員工(' + (un.members || 0) + ' 位未分派)</label>' +
        '<select id="asMem" style="width:100%;padding:12px;border:1.5px solid var(--beige2);border-radius:12px;font-size:14px">' +
        (cache.members || []).map(m => '<option value="' + m.user_id + '">' + esc(m.name) + '(' + (roleTxt[m.role] || m.role) + (m.branch_id ? '・已分派' : '・未分派') + ')</option>').join('') +
        '</select>' +
        '<select id="asMemBr" style="width:100%;padding:12px;border:1.5px solid var(--beige2);border-radius:12px;font-size:14px;margin-top:8px">' +
        '<option value="0">未分派</option>' + brs.map(b => '<option value="' + b.id + '">' + esc(b.name) + '</option>').join('') + '</select>' +
        '<button class="btn primary" id="memGo" style="width:100%;margin-top:8px">指派員工</button>' +
        '<hr style="border:0;border-top:1.5px solid var(--beige2);margin:16px 0">' +
        '<label style="font-size:12.5px;color:var(--brown2);font-weight:700">房屋(' + (un.properties || 0) + ' 間未分派)</label>' +
        '<select id="asProp" style="width:100%;padding:12px;border:1.5px solid var(--beige2);border-radius:12px;font-size:14px">' +
        props.map(p => '<option value="' + p.id + '">' + esc(p.title) + '</option>').join('') +
        '</select>' +
        '<select id="asPropBr" style="width:100%;padding:12px;border:1.5px solid var(--beige2);border-radius:12px;font-size:14px;margin-top:8px">' +
        '<option value="0">未分派</option>' + brs.map(b => '<option value="' + b.id + '">' + esc(b.name) + '</option>').join('') + '</select>' +
        '<button class="btn primary" id="propGo" style="width:100%;margin-top:8px">指派房屋</button>');
      if (!brs.length) { toast('請先建立分公司'); }
      document.getElementById('memGo').addEventListener('click', async () => {
        try {
          const res = await fetch('api/branches.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'assign_member', user_id: Number($('#asMem').value || 0), branch_id: Number($('#asMemBr').value || 0) })
          });
          const r = await res.json();
          toast(r.message);
          if (r.ok) { window.closeMask(); load(); }
        } catch (e) { toast('連線異常'); }
      });
      document.getElementById('propGo').addEventListener('click', async () => {
        try {
          const res = await fetch('api/branches.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'assign_property', property_id: Number($('#asProp').value || 0), branch_id: Number($('#asPropBr').value || 0) })
          });
          const r = await res.json();
          toast(r.message);
          if (r.ok) { window.closeMask(); load(); }
        } catch (e) { toast('連線異常'); }
      });
    } catch (e) { toast('連線異常'); }
  };

  $('#addBr').addEventListener('click', () => window.openCreate());
  $('#assignBr').addEventListener('click', () => window.openAssign());
  const mk = document.getElementById('mask');
  if (mk) mk.addEventListener('click', (ev) => { if (ev.target === mk) window.closeMask(); });

  load();
})();
