(function () {
  'use strict';
  const $ = (s) => document.querySelector(s);
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const money = (n) => Number(n || 0).toLocaleString('zh-TW');
  const dt = (s) => s ? String(s).replace(/-/g, '/').slice(0, 16) : '';
  const dstr = (s) => s ? String(s).slice(0, 10).replace(/-/g, '/') : '';
  let toastTimer;
  function toast(msg) {
    const t = $('#toast');
    t.textContent = msg;
    t.classList.add('show');
    clearTimeout(toastTimer);
    setTimeout(() => t.classList.remove('show'), 3000);
  }
  function api(url, body) {
    return fetch(url, body ? {
      method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body)
    } : { cache: 'no-store' }).then(r => r.json());
  }
  const roleName = { gate: '大門', door: '房門', unit_meter: '戶別電錶', main_meter: '總電錶' };
  const roleIcon = { gate: '🚪', door: '🚪', unit_meter: '⚡', main_meter: '⚡' };

  let state = { view: 'tenant', tenant: null, properties: [], propertyId: 0, property: null, showPin: {} };

  /* ────────── 租客檢視 ────────── */
  function renderTenant(v) {
    const c = $('#content');
    if (!v.lease) {
      c.innerHTML = '<div class="card"><b>尚未有生效中的租約</b><p style="font-size:13px;color:var(--brown2);line-height:1.9;margin-top:8px">簽約完成後,系統會即時把大門與房門的限時入住密碼發到這裡,並可自行修改。</p></div>';
      return;
    }
    let html = '<div class="card" style="padding:14px 16px"><b style="font-size:15.5px">🏠 ' + esc(v.lease.title) + '</b>' +
      '<p style="font-size:12.5px;color:var(--brown2);margin:6px 0 0;line-height:1.9">' +
      (v.lease.address ? esc(v.lease.address) + '<br>' : '') +
      '租期 ' + dstr(v.lease.start_date) + ' ~ ' + (v.lease.end_date ? dstr(v.lease.end_date) : '不定期') + '<br>' +
      '🔐 密碼於簽約當下自動發送,退租當日自動失效</p></div>';

    html += '<div class="sect">我的門鎖</div>';
    if (!v.locks.length) {
      html += '<p style="padding:12px 4px;color:var(--brown2);font-size:13px;margin:0">此房源尚未安裝智慧門鎖。若房東已升級,請聯絡管理員完成配對。</p>';
    }
    v.locks.forEach(L => {
      const shown = state.showPin[L.id];
      const pinTxt = L.pin ? (shown ? L.pin : '••••••') : '—';
      html += '<div class="card" style="padding:14px 16px">' +
        '<div style="display:flex;justify-content:space-between;align-items:center">' +
        '<b style="font-size:15px">' + roleIcon[L.role] + ' ' + esc(L.name) + '</b>' +
        '<span class="st ' + (L.status === 'active' ? 'ok' : 'warn') + '">' + (L.status === 'active' ? '已開通' : '離線') + '</span></div>' +
        '<p style="font-size:12px;color:var(--brown2);margin:6px 0 0">' + esc(L.adapter_label) + '・' + (roleName[L.role] || '') + '</p>' +
        (L.pin
          ? '<div style="background:rgba(200,155,107,.12);border-radius:12px;padding:12px;margin-top:10px;display:flex;justify-content:space-between;align-items:center">' +
            '<div><div style="font-size:11.5px;color:var(--brown2)">目前密碼</div>' +
            '<div style="font-size:26px;letter-spacing:6px;font-weight:700;font-family:monospace">' + pinTxt + '</div></div>' +
            '<button type="button" class="btn small ghost" data-toggle="' + L.id + '">' + (shown ? '隱藏' : '顯示') + '</button></div>' +
            '<p style="font-size:11.5px;color:var(--brown2);margin:6px 0 0">有效至 ' + (L.valid_to ? dt(L.valid_to) : '退租') +
            (L.last_changed_at ? '・上次改碼 ' + dt(L.last_changed_at) : '') + '</p>' +
            '<div style="display:flex;gap:8px;margin-top:10px">' +
            '<button type="button" class="btn small" data-change="' + L.credential_id + '" data-label="' + esc(roleName[L.role]) + '">修改' + esc(roleName[L.role]) + '密碼</button>' +
            (L.adapter === 'dev_mock' ? '<button type="button" class="btn small ghost" data-test="' + L.id + '" data-slot="' + L.role + '">試開(模擬)</button>' : '') +
            '</div>'
          : '<p style="font-size:12.5px;color:var(--err);margin-top:8px">尚未開通密碼,請聯絡管理員重新發碼。</p>') +
        '</div>';
    });

    html += '<div class="sect">改碼規則</div>' +
      '<div class="card flat" style="padding:14px 16px"><p style="font-size:12.5px;color:var(--brown2);line-height:2;margin:0">' +
      '・6 位數字,不可連號或重複(如 123456、000000)<br>' +
      '・新碼不可與目前相同;改完舊碼立即失效<br>' +
      '・同一組密碼 24 小時內最多修改 3 次<br>' +
      '・改碼會即時通知房東/管理員(避免同住人不知情被鎖在門外)<br>' +
      '・退租或租約到期,大門與房門密碼自動全部撤銷</p></div>';

    if (v.events && v.events.length) {
      html += '<div class="sect">最近紀錄</div><div class="card flat" style="padding:10px 16px">';
      v.events.forEach(e => {
        html += '<div style="padding:8px 0;border-bottom:1px dashed var(--beige2);font-size:12.5px;line-height:1.7">' +
          '<b>' + dt(e.created_at) + '</b> ' + esc(e.detail || e.event_type) + '</div>';
      });
      html += '</div>';
    }
    html += '<p style="font-size:11.5px;color:var(--brown2);text-align:center;margin-top:14px;line-height:1.9">智慧門鎖為加購服務(NT$' +
      money(v.fee && v.fee.count ? 100 : 100) + '/台/月),由房東負擔,不另向房客收費。</p>';
    c.innerHTML = html;

    c.querySelectorAll('[data-toggle]').forEach(b => b.onclick = () => {
      state.showPin[b.dataset.toggle] = !state.showPin[b.dataset.toggle];
      renderTenant(state.tenant);
    });
    c.querySelectorAll('[data-change]').forEach(b => b.onclick = () => changePin(b.dataset.change, b.dataset.label));
    c.querySelectorAll('[data-test]').forEach(b => b.onclick = () => testUnlock(b.dataset.test, b.dataset.slot));
  }

  function changePin(credentialId, label) {
    const input = prompt('輸入新的' + label + '密碼(6 位數字)\n提醒:不可連號、不可與目前相同;24 小時上限 3 次');
    if (input === null) return;
    api('api/devices.php', { action: 'change_pin', credential_id: Number(credentialId), new_pin: String(input).trim() })
      .then(d => {
        toast(d.message);
        if (d.ok) loadTenant();
      }).catch(() => toast('連線失敗,請稍後再試'));
  }

  function testUnlock(deviceId, slot) {
    const pin = prompt('輸入要測試的密碼(模擬鎖會檢查是否在有效期內)');
    if (pin === null) return;
    api('api/devices.php', { action: 'mock_unlock', device_id: Number(deviceId), slot_key: slot, pin: String(pin).trim() })
      .then(d => toast(d.message)).catch(() => toast('連線失敗'));
  }

  /* ────────── 管理端檢視 ────────── */
  function propOptions() {
    return state.properties.map(p => '<option value="' + p.id + '"' + (Number(p.id) === Number(state.propertyId) ? ' selected' : '') + '>' +
      esc(p.title) + (p.city || p.district ? '・' + esc((p.city || '') + (p.district || '')) : '') + '（' + p.device_count + ' 台裝置）</option>').join('');
  }

  function renderManager() {
    const c = $('#content');
    const props = state.properties;
    if (!props.length) {
      c.innerHTML = '<div class="card"><b>沒有可管理的房源</b>' +
        '<p style="font-size:13px;color:var(--brown2);margin-top:8px;line-height:1.9">智慧門鎖/電錶是「綁在某一戶房源」上加購的,所以要先有房源。<br>' +
        '目前這個帳號名下沒有可管理的房源(或此帳號不是房東/管理員)。</p>' +
        '<a class="btn small" href="create_property.html" style="margin-top:10px;text-decoration:none;text-align:center">建立房源</a></div>';
      return;
    }
    const sel = state.property ? state.property.property : null;
    let html = '<div class="sect">① 選擇房源</div>' +
      '<div class="card" style="padding:14px 16px">' +
      '<div class="field" style="margin:0"><label for="propSel">要加裝／管理裝置的房源</label>' +
      '<select id="propSel" style="font-size:15px;padding:12px">' + propOptions() + '</select></div>' +
      (sel
        ? '<p style="font-size:12.5px;color:var(--brown2);margin:8px 0 0;line-height:1.9">目前選取:<b style="color:var(--brown)">' + esc(sel.title) + '</b>' +
          (sel.city || sel.district ? '｜' + esc((sel.city || '') + (sel.district || '')) : '') + '｜編號 #' + sel.id + '</p>'
        : '<p style="font-size:12.5px;color:var(--brown2);margin:8px 0 0">請先從上方選一戶房源,下方才會出現該戶的裝置與加裝按鈕。</p>') +
      '</div>';

    const p = state.property;
    if (!p) {
      c.innerHTML = html;
      bindPropSel();
      return;
    }
    const fee = p.fee || { count: 0, amount: 0, unit_price: 100 };
    html += '<div class="card" style="padding:14px 16px"><b style="font-size:15.5px">' + esc(p.property.title) + '</b>' +
      '<p style="font-size:12.5px;color:var(--brown2);margin:6px 0 0;line-height:1.9">' +
      '裝置 ' + fee.count + ' 台・智慧硬體加購 <b>NT$' + money(fee.amount) + '/月</b>(每台 NT$' + money(fee.unit_price) + ')' +
      '<br>加購費會自動併入每期帳單(device_amount 欄位)</p></div>';

    html += '<div class="sect">裝置清單</div>';
    if (!p.devices.length) {
      html += '<p style="padding:12px 4px;color:var(--brown2);font-size:13px;margin:0">尚未加裝裝置。建議先加「大門門鎖」與「房門門鎖」兩台(模擬器),就能在 XAMPP 完整演示簽約發碼流程。</p>';
    }
    p.devices.forEach(d => {
      html += '<div class="card" style="padding:14px 16px">' +
        '<div style="display:flex;justify-content:space-between;align-items:flex-start">' +
        '<div><b style="font-size:15px">' + roleIcon[d.role] + ' ' + esc(d.name) + '</b>' +
        '<div style="font-size:11.5px;color:var(--brown2);margin-top:4px">' + esc(d.adapter_label) + '・' + esc(d.serial_no || '') + '・' + (roleName[d.role] || '') + '</div></div>' +
        '<span class="st ' + (d.online ? 'ok' : 'warn') + '">' + (d.online ? '在線' : '離線') + '</span></div>' +
        '<p style="font-size:12px;color:var(--brown2);margin:8px 0 0">' + esc(d.adapter_message) + (d.last_sync_at ? '・同步 ' + dt(d.last_sync_at) : '') + '</p>';
      if (d.credentials && d.credentials.length) {
        html += '<div style="margin-top:10px;border-top:1px dashed var(--beige2);padding-top:8px">';
        d.credentials.slice(0, 6).forEach(cr => {
          const active = cr.status === 'active';
          html += '<div style="display:flex;justify-content:space-between;align-items:center;gap:8px;font-size:12.5px;padding:6px 0">' +
            '<span style="flex:1;min-width:0">' + esc(cr.label || '') + '・' + esc(cr.user_name || '未綁定') +
            (cr.valid_to ? '<span style="color:var(--brown2);font-size:11px"> 至 ' + dstr(cr.valid_to) + '</span>' : '') + '</span>' +
            (active
              ? '<b style="font-family:monospace;letter-spacing:2px">' + esc(cr.pin || '••••••') + '</b>' +
                '<button type="button" class="btn small ghost" style="padding:5px 9px;font-size:11.5px" data-cred-change="' + cr.id + '" data-cred-label="' + esc(cr.label || '密碼') + '">重設</button>' +
                '<button type="button" class="btn small ghost" style="padding:5px 9px;font-size:11.5px;color:var(--err)" data-cred-revoke="' + cr.id + '">撤銷</button>'
              : '<span class="st ' + (cr.status === 'expired' ? 'warn' : '') + '" style="font-size:11px">' + (cr.status === 'expired' ? '已到期' : '已撤銷') + '</span>') +
            '</div>';
        });
        html += '</div>';
      }
      html += '<div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:10px">' +
        (d.device_type === 'lock' ? '<button type="button" class="btn small" data-mgr="' + d.id + '" data-mgrname="' + esc(d.name) + '">設定管理密碼</button>' : '') +
        '<button type="button" class="btn small ghost" data-sync="' + d.id + '">同步狀態</button>' +
        '<button type="button" class="btn small ghost" data-del="' + d.id + '" data-name="' + esc(d.name) + '">移除</button>' +
        '</div></div>';
    });

    html += '<div class="sect">加裝裝置</div>' +
      '<div class="card" style="padding:14px 16px">' +
      '<div class="field"><label for="dProp">加裝在哪一戶房源</label><select id="dProp" style="font-size:14.5px;padding:11px">' + propOptions() + '</select></div>' +
      '<div style="background:rgba(200,155,107,.12);border-radius:12px;padding:9px 12px;margin:6px 0 12px;font-size:12.5px;line-height:1.8">' +
      '即將加裝於:<b>' + esc(p.property.title) + '</b>(編號 #' + p.property.id + ')<br>每台每月加購 NT$' + money(fee.unit_price) + ',會自動併入該戶帳單</div>' +
      '<div class="field"><label for="dType">類型</label><select id="dType"><option value="lock">智慧門鎖</option><option value="meter">智慧電錶</option></select></div>' +
      '<div class="field"><label for="dRole">位置</label><select id="dRole">' +
      '<option value="gate">大門</option><option value="door">房門</option>' +
      '<option value="unit_meter">戶別電錶</option><option value="main_meter">總電錶</option></select></div>' +
      '<div class="field"><label for="dName">名稱(可留空)</label><input id="dName" maxlength="60" placeholder="例:1F 大門門鎖"></div>' +
      '<button type="button" class="btn primary" id="addDev" style="margin-top:10px">加裝(模擬器,NT$' + money(fee.unit_price) + '/月)</button>' +
      '<p style="font-size:11.5px;color:var(--brown2);margin-top:8px;line-height:1.8">目前僅提供模擬器;Aqara 真機需完成代理商簽約、取得 API 憑證後切換 Adapter(介面已預留,流程不用改)。</p></div>';

    if (p.events && p.events.length) {
      html += '<div class="sect">裝置紀錄</div><div class="card flat" style="padding:10px 16px">';
      p.events.slice(0, 15).forEach(e => {
        html += '<div style="padding:7px 0;border-bottom:1px dashed var(--beige2);font-size:12px;line-height:1.7">' +
          '<b>' + dt(e.created_at) + '</b> ' + esc(e.detail || e.event_type) +
          (e.actor_name ? '・' + esc(e.actor_name) : '') + '</div>';
      });
      html += '</div>';
    }
    c.innerHTML = html;
    bindPropSel();
    const add = $('#addDev');
    if (add) add.onclick = () => {
      const type = $('#dType').value;
      const roleSel = $('#dRole').value;
      const role = type === 'lock' ? (roleSel === 'gate' ? 'gate' : 'door') : (roleSel === 'main_meter' ? 'main_meter' : 'unit_meter');
      api('api/devices.php', {
        action: 'add', property_id: Number(state.propertyId), device_type: type, role: role,
        name: $('#dName').value.trim(), adapter: 'dev_mock'
      }).then(d => { toast(d.message); if (d.ok) loadProperty(); }).catch(() => toast('連線失敗'));
    };
    c.querySelectorAll('[data-mgr]').forEach(b => b.onclick = () => {
      const pin = prompt('設定「' + b.dataset.mgrname + '」的管理密碼(6 位數字)\n房東/管理員自己進出、帶看、清潔維修時使用;退租不會被撤銷。\n留空 = 系統自動產生');
      if (pin === null) return;
      api('api/devices.php', { action: 'set_manager_pin', device_id: Number(b.dataset.mgr), pin: String(pin).trim() })
        .then(d => { toast(d.message); if (d.ok) loadProperty(); }).catch(() => toast('連線失敗'));
    });
    c.querySelectorAll('[data-cred-change]').forEach(b => b.onclick = () => {
      const pin = prompt('輸入新的' + b.dataset.credLabel + '(6 位數字;留空 = 系統自動產生)');
      if (pin === null) return;
      let v = String(pin).trim();
      if (v === '') v = String(Math.floor(100000 + Math.random() * 900000));
      api('api/devices.php', { action: 'change_pin', credential_id: Number(b.dataset.credChange), new_pin: v })
        .then(d => { toast(d.message); if (d.ok) loadProperty(); }).catch(() => toast('連線失敗'));
    });
    c.querySelectorAll('[data-cred-revoke]').forEach(b => b.onclick = () => {
      if (!confirm('撤銷這組密碼?鎖端會立即失效。')) return;
      api('api/devices.php', { action: 'revoke_pin', credential_id: Number(b.dataset.credRevoke), reason: 'manager_revoke' })
        .then(d => { toast(d.message); if (d.ok) loadProperty(); }).catch(() => toast('連線失敗'));
    });
    c.querySelectorAll('[data-sync]').forEach(b => b.onclick = () => {
      api('api/devices.php', { action: 'sync', device_id: Number(b.dataset.sync) })
        .then(d => { toast(d.message); loadProperty(); }).catch(() => toast('連線失敗'));
    });
    c.querySelectorAll('[data-del]').forEach(b => b.onclick = () => {
      if (!confirm('確定移除「' + b.dataset.name + '」?相關密碼會一併撤銷。')) return;
      api('api/devices.php', { action: 'remove', device_id: Number(b.dataset.del) })
        .then(d => { toast(d.message); loadProperty(); }).catch(() => toast('連線失敗'));
    });
  }

  function bindPropSel() {
    const change = (e) => {
      const v = Number(e.target.value);
      if (!v || v === Number(state.propertyId)) return;
      state.propertyId = v;
      loadProperty();
    };
    ['#propSel', '#dProp'].forEach(s => {
      const el = $(s);
      if (el) el.onchange = change;
    });
  }

  /* ────────── 載入 ────────── */
  function loadTenant() {
    return api('api/devices.php?action=my').then(d => {
      state.tenant = d.view || { lease: null, locks: [], events: [] };
      renderTenant(state.tenant);
      return d;
    });
  }
  function loadProperty() {
    if (!state.propertyId) return Promise.resolve();
    return api('api/devices.php?action=property&property_id=' + state.propertyId).then(d => {
      state.property = d.ok ? d : null;
      if (!d.ok) toast(d.message);
      renderManager();
    });
  }
  function setView(v) {
    const hasTenant = !!(state.tenant && state.tenant.lease);
    const hasProps = state.properties.length > 0;
    if (v === 'tenant' && !hasTenant) v = 'manager';
    if (v === 'manager' && !hasProps) v = hasTenant ? 'tenant' : 'manager';
    state.view = v;
    const bar = $('#roleBar');
    const showBar = hasTenant && hasProps;
    bar.style.display = showBar ? 'flex' : 'none';
    if (showBar) {
      bar.innerHTML =
        '<button type="button" class="btn small ' + (v === 'tenant' ? '' : 'ghost') + '" data-v="tenant" style="flex:1">我的門鎖</button>' +
        '<button type="button" class="btn small ' + (v === 'manager' ? '' : 'ghost') + '" data-v="manager" style="flex:1">管理／加裝裝置</button>';
      bar.querySelectorAll('[data-v]').forEach(b => b.onclick = () => setView(b.dataset.v));
    }
    if (v === 'tenant') { loadTenant(); } else { renderManager(); }
  }

  Promise.all([
    api('api/devices.php?action=my').catch(() => null),
    api('api/devices.php?action=properties').catch(() => null)
  ]).then(([my, pr]) => {
    state.tenant = my && my.ok ? (my.view || null) : null;
    state.properties = pr && pr.ok ? pr.properties : [];
    if (state.properties.length) {
      state.propertyId = Number(state.properties[0].id);
      state.property = null;
      loadProperty();
    }
    if (state.properties.length) {
      setView('manager');
    } else if (state.tenant && state.tenant.lease) {
      setView('tenant');
    } else {
      $('#content').innerHTML = '<div class="card"><b>尚未有可檢視的裝置</b><p style="font-size:13px;color:var(--brown2);margin-top:8px">房東/管理員可先建立房源並加裝門鎖;房客於簽約後會看到入住密碼。</p></div>';
    }
  }).catch(() => {
    $('#content').innerHTML = '<div class="card"><b>載入失敗</b><p style="font-size:13px;color:var(--brown2);margin-top:8px">請確認已登入後重新整理。</p></div>';
  });
})();
