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
  const typeNames = { lease_contract: '📜 租賃契約書', repair_charge: '🧾 維修自費確認函', mandate: '📄 房源委託管理授權書' };
  const eventNames = { created: '發起契約', sent: '發出簽署邀請', tenant_signed: '房客完成電子簽名', countersigned: '屋主完成副署(契約生效)', signed: '完成簽署', declined: '拒簽', rejected: '拒絕簽署', cancelled: '撤回契約' };

  let cache = null;
  let padDone = null;

  function chip(d) {
    if (d.status === 'signed') return '<span class="st ok">已生效</span>';
    if (d.status === 'rejected') return '<span class="st idle">已結案</span>';
    if (d.status === 'partially_signed') return '<span class="st warn">待屋主副署</span>';
    return '<span class="st warn">待簽署</span>';
  }

  function mySide(d) {
    if (!cache) return 0;
    if (Number(d.signer_user_id) === Number(cache.my_id)) return 1;
    if (Number(d.signer2_user_id) === Number(cache.my_id)) return 2;
    return 0;
  }

  function statusText(d) {
    const s = mySide(d);
    if (d.status === 'signed') return '契約已生效|' + dstr(d.signed_at || d.sign2_at);
    if (d.status === 'partially_signed') return s === 2 ? '房客已簽名,待您副署' : '已送出屋主副署';
    if (d.status === 'rejected') return '已結案(未完成簽署)';
    return s === 1 ? '待您電子簽名' : '待房客電子簽名';
  }

  function render() {
    const listEl = $('#list');
    const docs = (cache && cache.docs) || [];
    if (!docs.length) {
      listEl.innerHTML = '<p style="padding:18px 4px;text-align:center;color:var(--brown2);font-size:13px;margin:0">🌿 目前沒有文件</p>';
      return;
    }
    listEl.innerHTML = docs.map(d => {
      const signedBoth = d.status === 'signed' && (d.type === 'lease_contract' || d.type === 'mandate');
      return '<div class="lrow doc-row" data-id="' + d.id + '" style="cursor:pointer">' +
        '<div class="ltop"><div class="linfo">' +
        '<b>' + (typeNames[d.type] || '📄') + ' ' + esc(d.title) + '</b>' +
        '<span>' + (d.amount ? '金額 NT$' + Number(d.amount).toLocaleString('zh-TW') + '元|' : '') + '建立 ' + dstr(d.created_at) +
        (signedBoth ? '|' + (d.type === 'mandate' ? '✍ 房東 ' : '✍ 承租 ') + esc(d.sign_name || '') + '|' + esc(d.signer2_name || d.sign2_name || '') + ' 副署' : '') +
        '</span>' +
        '</div>' + chip(d) + '</div>' +
        '</div>';
    }).join('');
    listEl.querySelectorAll('.doc-row').forEach(row => {
      row.addEventListener('click', () => openDoc(Number(row.dataset.id)));
    });
    /* 深連結:?doc=N 直接開啟該文件 */
    const want = Number(new URLSearchParams(location.search).get('doc') || 0);
    if (want > 0 && docs.some(d => Number(d.id) === want)) {
      openDoc(want);
      history.replaceState(null, '', 'documents.html');
    }
  }

  function sigBlock(d) {
    if (!(d.type === 'lease_contract' || d.type === 'mandate') || d.status !== 'signed') return '';
    const p1 = d.type === 'mandate' ? '委託人(房東)' : '承租人';
    const p2 = d.type === 'mandate' ? '受託公司負責人' : '出租人';
    let h = '<div style="margin-top:14px;border-top:1.5px dashed var(--beige2);padding-top:10px">' +
      '<b>🖊 雙方電子簽名</b><div style="display:flex;gap:14px;flex-wrap:wrap;margin-top:8px">';
    if (d.signature_img) h += '<div style="text-align:center"><img src="uploads/' + esc(d.signature_img) + '" alt="簽名" style="width:150px;height:64px;object-fit:contain;background:#fff;border:1.5px solid var(--beige2);border-radius:10px"><div style="font-size:11.5px;color:var(--brown2);margin-top:2px">' + p1 + ':' + esc(d.sign_name || '') + '<br>' + dstr(d.signed_at) + '</div></div>';
    if (d.signature2_img) h += '<div style="text-align:center"><img src="uploads/' + esc(d.signature2_img) + '" alt="副署簽名" style="width:150px;height:64px;object-fit:contain;background:#fff;border:1.5px solid var(--beige2);border-radius:10px"><div style="font-size:11.5px;color:var(--brown2);margin-top:2px">' + p2 + ':' + esc(d.sign2_name || '') + '<br>' + dstr(d.sign2_at) + '</div></div>';
    h += '</div><div style="font-size:11px;color:var(--brown2);margin-top:6px">簽署紀錄:' + p1 + ' IP ' + esc(d.sign_ip || '-') + '|' + p2 + ' IP ' + esc(d.sign2_ip || '-') + '(依電子簽章法與書面簽名具同等效力)</div></div>';
    return h;
  }

  function trailBlock(d) {
    let trail = [];
    try { trail = d.trail ? (JSON.parse(d.trail) || []) : []; } catch (e) { trail = []; }
    if (!trail.length) return '';
    return '<div style="margin-top:12px;font-size:11.5px;color:var(--brown2);border-top:1.5px dashed var(--beige2);padding-top:8px"><b>🕒 簽署軌跡</b>' +
      trail.map(t => '<div style="margin-top:3px">' + esc(eventNames[t.event] || t.event) + '|' + esc(t.name || '') + '|' + dstr(t.at) + (t.ip ? '|IP ' + esc(t.ip) : '') + '</div>').join('') + '</div>';
  }

  /* 簽名完成 → 回到契約頁預覽簽名,檢查無誤才送出 */
  function renderSignConfirm(foot, id, n, sig, roleLabel) {
    foot.innerHTML = '<div style="text-align:center;margin-bottom:6px"><b style="font-size:13px">請檢查您的電子簽名</b></div>' +
      '<div style="display:flex;justify-content:center;margin:8px 0"><img src="' + sig + '" alt="簽名預覽" style="max-width:240px;width:70%;height:70px;object-fit:contain;background:#fff;border:1.5px solid var(--beige2);border-radius:10px"></div>' +
      '<div style="font-size:12px;color:var(--brown2);text-align:center;margin-bottom:10px">' + esc(n) + '(' + roleLabel + ')|請往上滑詳閱契約全文,確認無誤再送出</div>' +
      '<div style="display:flex;gap:10px"><button class="btn primary" id="confirmSignBtn" style="flex:1.4">✅ 檢查無誤,送出簽署</button><button class="btn ghost" id="resignBtn" style="flex:1">重新簽名</button></div>';
    foot.querySelector('#confirmSignBtn').addEventListener('click', () => {
      postAction({ doc_id: id, action: 'sign', sign_name: n, signature: sig, reviewed: true });
    });
    foot.querySelector('#resignBtn').addEventListener('click', async () => {
      const s2 = await openPad(roleLabel + '重新簽名');
      if (s2) renderSignConfirm(foot, id, n, s2, roleLabel);
    });
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
    const body = $('#docBody');
    body.textContent = d.content;
    const extra = sigBlock(d) + trailBlock(d);
    if (extra) {
      const wrap = document.createElement('div');
      wrap.innerHTML = extra;
      body.appendChild(wrap);
    }
    let att = [];
    try { att = d.attachments ? (JSON.parse(d.attachments) || []) : []; } catch (e) { att = []; }
    if (att.length) {
      const wrap = document.createElement('div');
      wrap.style.cssText = 'margin-top:14px';
      wrap.innerHTML = '<b>📎 附件(現場照片/報價單/佐證資料)</b><div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px">' +
        att.map(p => '<img src="uploads/' + esc(p) + '" data-full="uploads/' + esc(p) + '" alt="附件" style="width:88px;height:88px;object-fit:cover;border-radius:10px;cursor:zoom-in;border:1.5px solid rgba(255,255,255,.15)">').join('') + '</div>';
      body.appendChild(wrap);
      wrap.querySelectorAll('img').forEach(im => {
        im.addEventListener('click', () => { if (window.showLightbox) window.showLightbox(im.dataset.full, '文件附件'); });
      });
    }

    const foot = $('#docFoot');
    const side = mySide(d);
    const isLease = d.type === 'lease_contract';
    const isManager = ['landlord', 'manager', 'company_owner', 'admin_staff', 'accountant', 'agent'].includes(cache.my_role);
    const isCreator = Number(d.created_by) === Number(cache.my_id);

    if (d.status === 'pending' && side === 1) {
      const myName = esc(cache.my_name || '');
      foot.innerHTML = (isLease
        ? '<input id="signName" value="' + myName + '" placeholder="請輸入您的全名" style="width:100%;border:1.8px solid var(--beige2);background:var(--surface);border-radius:14px;padding:12px 14px;font-size:15px;color:var(--brown);font-family:inherit;margin-bottom:8px">' +
          '<label style="display:flex;align-items:flex-start;gap:8px;font-size:13px;color:var(--brown);margin:2px 2px 10px;font-weight:700"><input type="checkbox" id="signReviewed" style="width:19px;height:19px;accent-color:var(--wood-d);margin-top:1px;flex:none">我已詳閱契約全文並同意全部條款(勾選時間將留存紀錄)</label>' +
          '<button class="btn primary" id="signBtn" style="width:100%">🖊 手寫電子簽名</button>' +
          '<p style="font-size:11.5px;color:var(--brown2);text-align:center;margin:8px 0 0">簽名前請詳閱契約全文;簽名後由屋主副署生效</p>'
        : '<input id="signName" value="' + myName + '" placeholder="請輸入您的全名作為電子簽名" style="width:100%;border:1.8px solid var(--beige2);background:var(--surface);border-radius:14px;padding:12px 14px;font-size:15px;color:var(--brown);font-family:inherit;margin-bottom:8px">' +
          '<label style="display:flex;align-items:flex-start;gap:8px;font-size:13px;color:var(--brown);margin:2px 2px 10px;font-weight:700"><input type="checkbox" id="signReviewed" style="width:19px;height:19px;accent-color:var(--wood-d);margin-top:1px;flex:none">我已詳閱文件內容並同意(勾選時間將留存紀錄)</label>' +
          '<div style="display:flex;gap:10px"><button class="btn primary" id="signBtn" style="flex:1">🖊 同意並簽署</button><button class="btn ghost" id="declineBtn" style="flex:1">拒絕簽署</button></div>');
      foot.querySelector('#signBtn').addEventListener('click', async () => {
        const rev = foot.querySelector('#signReviewed');
        if (rev && !rev.checked) { showToast('請先勾選「我已詳閱契約全文並同意全部條款」'); return; }
        if (isLease) {
          const inp = foot.querySelector('#signName');
          const n = (inp.value.trim() || cache.my_name || '').trim();
          if (n.length < 2) { showToast('請先輸入您的全名'); return; }
          inp.blur();
          const sig = await openPad('承租人(房客)簽名');
          if (!sig) return;
          renderSignConfirm(foot, id, n, sig, '承租人');
        } else {
          const n = foot.querySelector('#signName').value.trim();
          await postAction({ doc_id: id, action: 'sign', sign_name: n, reviewed: true });
        }
      });
      const db = foot.querySelector('#declineBtn');
      if (db) db.addEventListener('click', async () => {
        if (!confirm('確定拒絕簽署此文件?')) return;
        await postAction({ doc_id: id, action: 'decline' });
      });
    } else if (d.status === 'partially_signed' && side === 2) {
      foot.innerHTML = '<input id="signName" value="' + esc(cache.my_name || '') + '" placeholder="請輸入您的全名" style="width:100%;border:1.8px solid var(--beige2);background:var(--surface);border-radius:14px;padding:12px 14px;font-size:15px;color:var(--brown);font-family:inherit;margin-bottom:8px">' +
        '<label style="display:flex;align-items:flex-start;gap:8px;font-size:13px;color:var(--brown);margin:2px 2px 10px;font-weight:700"><input type="checkbox" id="signReviewed" style="width:19px;height:19px;accent-color:var(--wood-d);margin-top:1px;flex:none">我已詳閱契約全文並同意全部條款(勾選時間將留存紀錄)</label>' +
        '<div style="display:flex;gap:10px"><button class="btn primary" id="signBtn" style="flex:1">🖊 屋主副署(契約生效)</button><button class="btn ghost" id="declineBtn" style="flex:1">拒簽</button></div>';
      foot.querySelector('#signBtn').addEventListener('click', async () => {
        const rev = foot.querySelector('#signReviewed');
        if (rev && !rev.checked) { showToast('請先勾選「我已詳閱契約全文並同意全部條款」'); return; }
        const inp = foot.querySelector('#signName');
        const n = (inp.value.trim() || cache.my_name || '').trim();
        if (n.length < 2) { showToast('請先輸入您的全名'); return; }
        inp.blur();
        const sig = await openPad('出租人(屋主)簽名');
        if (!sig) return;
        renderSignConfirm(foot, id, n, sig, '出租人');
      });
      foot.querySelector('#declineBtn').addEventListener('click', async () => {
        if (!confirm('確定拒簽此契約?')) return;
        await postAction({ doc_id: id, action: 'decline' });
      });
    } else if ((d.status === 'pending' || d.status === 'partially_signed') && isManager) {
      foot.innerHTML = '<p style="font-size:12.5px;color:var(--brown2);text-align:center;margin:0 0 8px">' + (d.status === 'pending' ? '等待房客完成電子簽名' : '房客已簽名,等待屋主副署') + '</p>' +
        (isCreator ? '<button class="btn ghost" id="cancelBtn" style="width:100%">撤回此契約</button>' : '');
      const cb = foot.querySelector('#cancelBtn');
      if (cb) cb.addEventListener('click', async () => {
        if (!confirm('確定撤回此契約?對方將收到通知。')) return;
        await postAction({ doc_id: id, action: 'cancel' });
      });
    } else if (d.status === 'signed') {
      foot.innerHTML = '<p style="font-size:12.5px;color:var(--green-d);text-align:center;margin:0 0 8px">✓ 契約已生效' + (isLease ? ',雙方簽名與軌跡已歸檔留存' : '') + '</p>' +
        (isLease ? '<button class="btn primary" id="printBtn" style="width:100%">🖨 列印/存成 PDF</button>' : '');
      const pb = foot.querySelector('#printBtn');
      if (pb) pb.addEventListener('click', () => printDoc(d));
    } else if (d.status === 'rejected') {
      foot.innerHTML = '<p style="font-size:12.5px;color:var(--brown2);text-align:center;margin:0">此文件未完成簽署已結案' + (isLease && isCreator ? '(可回到租約重新發起)' : '') + '</p>';
    }
    $('#docMask').classList.add('show');
    document.querySelector('.sheet').classList.add('show');
  }

  function printDoc(d) {
    const area = $('#printArea');
    area.innerHTML = '<h2 style="font-size:16px">' + esc(d.title) + '</h2>' +
      '<pre>' + esc(d.content) + '</pre>' + sigBlock(d).replace(/class="st[^"]*"/g, '');
    const oldTitle = document.title;
    document.title = '租賃合約_安家生活GO';
    window.print();
    setTimeout(() => { document.title = oldTitle; }, 2000);
  }

  /* ───────── 手寫簽名板 ───────── */
  let ctx = null, drawing = false, hasInk = false;
  function padInit() {
    const c = $('#padCanvas');
    const dpr = window.devicePixelRatio || 1;
    const rect = c.getBoundingClientRect();
    c.width = rect.width * dpr;
    c.height = 190 * dpr;
    ctx = c.getContext('2d');
    ctx.scale(dpr, dpr);
    ctx.fillStyle = '#fff';
    ctx.fillRect(0, 0, rect.width, 190);
    ctx.lineWidth = 2.6;
    ctx.lineCap = 'round';
    ctx.lineJoin = 'round';
    ctx.strokeStyle = '#1a1a1a';
    hasInk = false;
  }
  function padPos(e) {
    const r = $('#padCanvas').getBoundingClientRect();
    return { x: e.clientX - r.left, y: e.clientY - r.top };
  }
  function openPad(title) {
    $('#padWho').textContent = '✍ ' + title;
    $('#padMask').classList.add('show');
    document.querySelector('#padMask .sheet').classList.add('show');
    setTimeout(padInit, 30);
    return new Promise((resolve) => { padDone = resolve; });
  }
  function closePad(val) {
    $('#padMask').classList.remove('show');
    document.querySelector('#padMask .sheet').classList.remove('show');
    if (padDone) { padDone(val); padDone = null; }
  }
  const padC = $('#padCanvas');
  padC.addEventListener('pointerdown', (e) => {
    drawing = true;
    const p = padPos(e);
    ctx.beginPath();
    ctx.moveTo(p.x, p.y);
    padC.setPointerCapture(e.pointerId);
  });
  padC.addEventListener('pointermove', (e) => {
    if (!drawing) return;
    const p = padPos(e);
    ctx.lineTo(p.x, p.y);
    ctx.stroke();
    hasInk = true;
  });
  padC.addEventListener('pointerup', () => { drawing = false; });
  $('#padClear').addEventListener('click', padInit);
  $('#padOk').addEventListener('click', () => {
    if (!hasInk) { showToast('請先簽名'); return; }
    const url = padC.toDataURL('image/png');
    closePad(url);
  });
  $('#padClose').addEventListener('click', () => closePad(null));
  $('#padMask').addEventListener('click', (e) => { if (e.target === $('#padMask')) closePad(null); });

  /* ───────── 發起租約簽約 ───────── */
  async function openNewContract(preLease) {
    const form = $('#newContractForm');
    form.style.display = '';
    form.scrollIntoView({ behavior: 'smooth', block: 'start' });
    if (form.dataset.loaded) {
      if (preLease) $('#ncLease').value = preLease;
      return;
    }
    try {
      const res = await fetch('api/leases.php', { cache: 'no-store' });
      const data = await res.json();
      if (!data.ok) return;
      const leases = (data.leases || []).filter(l => l.status === 'pending' || l.status === 'active');
      $('#ncLease').innerHTML = leases.length
        ? leases.map(l => '<option value="' + l.id + '">' + esc(l.property_title) + '|房客 ' + esc(l.tenant_name) + '(' + (l.status === 'active' ? '已生效租約' : '待接受') + ')</option>').join('')
        : '<option value="">(目前沒有可簽約的租約)</option>';
      form.dataset.loaded = '1';
      if (preLease) $('#ncLease').value = preLease;
    } catch (e) { /* ignore */ }
  }

  $('#newContractBtn').addEventListener('click', () => {
    const f = $('#newContractForm');
    if (f.style.display === 'none') openNewContract();
    else { f.style.display = 'none'; }
  });
  $('#ncCancel').addEventListener('click', () => { $('#newContractForm').style.display = 'none'; });
  $('#newContractForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const leaseId = Number($('#ncLease').value || 0);
    if (!leaseId) { showToast('請先選擇租約'); return; }
    try {
      const res = await fetch('api/documents.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          action: 'create_lease_contract',
          lease_id: leaseId,
          extra_terms: $('#ncExtra').value.trim(),
          fees: {
            water: $('#ncWater').value.trim(),
            gas: $('#ncGas').value.trim(),
            mgmt: $('#ncMgmt').value.trim(),
            network: $('#ncNetwork').value.trim(),
            parking: $('#ncParking').value.trim(),
            misc: $('#ncMisc').value.trim()
          }
        })
      });
      const d = await res.json();
      showToast(d.message);
      if (d.ok) {
        $('#newContractForm').style.display = 'none';
        load();
      }
    } catch (e) { showToast('連線異常,請稍後再試'); }
  });

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
        document.querySelector('.sheet').classList.remove('show');
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
    const isManager = ['landlord', 'manager', 'company_owner', 'admin_staff', 'agent'].includes(data.my_role);
    $('#newContractBtn').style.display = isManager ? '' : 'none';
    render();
    const params = new URLSearchParams(location.search);
    if (params.get('new_lease')) {
      openNewContract(params.get('new_lease'));
      params.delete('new_lease');
      history.replaceState(null, '', location.pathname);
    }
  }

  function closeDoc() {
    $('#docMask').classList.remove('show');
    document.querySelector('#docMask .sheet').classList.remove('show');
  }
  $('#docClose').addEventListener('click', closeDoc);
  $('#docMask').addEventListener('click', (e) => { if (e.target === $('#docMask')) closeDoc(); });

  load();
})();
