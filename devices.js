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
  const roleName = { gate: '大門', door: '房門', unit_meter: '戶別智慧電錶', main_meter: '樓層總錶' };
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
            '<div style="display:flex;gap:8px;margin-top:10px;flex-wrap:wrap">' +
            (L.can_change
              ? '<button type="button" class="btn small" data-open="' + L.id + '">修改' + esc(roleName[L.role]) + '密碼</button>'
              : '<span style="font-size:12px;color:var(--brown2);background:var(--cream2);border:1px dashed var(--beige2);border-radius:10px;padding:8px 12px;line-height:1.7">🔒 ' + esc(roleName[L.role]) + '密碼由房東管理,如需變更請聯絡房東</span>') +
            (L.adapter === 'dev_mock' ? '<button type="button" class="btn small ghost" data-test="' + L.id + '" data-slot="' + L.role + '">試開(模擬)</button>' : '') +
            '</div>' +
            '<div id="pf-' + L.id + '" style="display:none;margin-top:10px;background:var(--cream2);border:1.5px solid var(--beige2);border-radius:12px;padding:12px">' +
            '<div style="font-size:12px;color:var(--brown2);margin-bottom:6px">新的' + esc(roleName[L.role]) + '密碼(6 位數字,不可連號/重複)</div>' +
            '<input id="pin-' + L.id + '" type="tel" inputmode="numeric" maxlength="6" autocomplete="off" placeholder="輸入 6 位數字" ' +
            'style="width:100%;border:1.5px solid var(--beige2);border-radius:10px;padding:10px 12px;font-size:20px;letter-spacing:6px;font-family:monospace;background:var(--surface);color:var(--brown);box-sizing:border-box">' +
            '<div style="display:flex;gap:8px;margin-top:8px">' +
            '<button type="button" class="btn small" data-submit="' + L.credential_id + '" data-dev="' + L.id + '">確認修改</button>' +
            '<button type="button" class="btn small ghost" data-cancel="' + L.id + '">取消</button>' +
            '</div></div>'
          : '<p style="font-size:12.5px;color:var(--err);margin-top:8px">尚未開通密碼,請聯絡管理員重新發碼。</p>') +
        '</div>';
    });

    html += '<div class="sect">改碼規則</div>' +
      '<div class="card flat" style="padding:14px 16px"><p style="font-size:12.5px;color:var(--brown2);line-height:2;margin:0">' +
      '・6 位數字,不可連號或重複(如 123456、000000)<br>' +
      '・新碼不可與目前相同;改完舊碼立即失效<br>' +
      '・同一組密碼 24 小時內最多修改 3 次<br>' +
      '・改碼會即時通知房東/管理員(避免同住人不知情被鎖在門外)<br>' +
      '・大門(公共門鎖)由房東管理,房客僅能修改自己入戶門的密碼<br>' +
      '・退租或租約到期,所有密碼自動全部撤銷</p></div>';

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
    c.querySelectorAll('[data-open]').forEach(b => b.onclick = () => {
      const f = document.getElementById('pf-' + b.dataset.open);
      if (!f) return;
      const open = f.style.display === 'none';
      f.style.display = open ? 'block' : 'none';
      if (open) { const i = document.getElementById('pin-' + b.dataset.open); if (i) { i.value = ''; i.focus(); } }
    });
    c.querySelectorAll('[data-cancel]').forEach(b => b.onclick = () => {
      const f = document.getElementById('pf-' + b.dataset.cancel);
      if (f) f.style.display = 'none';
    });
    c.querySelectorAll('[data-submit]').forEach(b => b.onclick = () => {
      const devId = b.dataset.dev;
      const inp = document.getElementById('pin-' + devId);
      const pin = (inp ? inp.value : '').replace(/\D/g, '');
      if (pin.length !== 6) { toast('請輸入 6 位數字'); return; }
      if (b.disabled) return;
      b.disabled = true;
      api('api/devices.php', { action: 'change_pin', credential_id: Number(b.dataset.submit), new_pin: pin })
        .then(d => {
          b.disabled = false;
          toast(d.message);
          if (d.ok) { state.showPin[Number(devId)] = true; loadTenant(); }
        })
        .catch(() => { b.disabled = false; toast('連線失敗,請稍後再試'); });
    });
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

  function accessSection(logs) {
    let h = '<div class="sect">進出紀錄(開門稽核)</div>' +
      '<div class="card" style="padding:14px 16px">' +
      '<p style="font-size:12.5px;color:var(--brown2);margin:0 0 10px;line-height:1.9">' +
      '每位房客在大門與房門各有一組專屬密碼,開門紀錄會對應到「哪一組密碼 = 哪一位房客」。有狀況時(財物遺失、鄰居反映、押金爭議)可在下面直接輸入密碼反查。</p>' +
      '<div style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap">' +
      '<div class="field" style="flex:1;min-width:150px;margin:0"><label for="pinQ">用密碼反查(6 位數)</label>' +
      '<input id="pinQ" inputmode="numeric" maxlength="6" placeholder="例:047210"></div>' +
      '<button type="button" class="btn primary" id="pinQLookup" style="height:44px">查是誰</button></div>' +
      '<div id="pinQResult" style="margin-top:10px"></div></div>';
    h += '<div class="card flat" style="padding:6px 16px">';
    if (!logs || !logs.length) {
      h += '<p style="font-size:12.5px;color:var(--brown2);padding:10px 0;margin:0;line-height:1.9">尚無開門紀錄。房客用密碼開門後會自動記錄;模擬鎖可在裝置卡按「同步狀態」旁的試開驗證。接上正式鎖後會由鎖端上傳。</p>';
    } else {
      logs.forEach(g => {
        h += '<div style="display:flex;justify-content:space-between;gap:10px;padding:8px 0;border-bottom:1px dashed var(--beige2);font-size:12.5px">' +
          '<span style="flex:1;min-width:0"><b>' + dt(g.unlocked_at) + '</b><br>' + esc(g.device_name || '') +
          '・' + (g.slot_label || (String(g.slot_key||'').indexOf('gate') === 0 ? '大門' : '房門')) + '・' + esc(g.method_label) +
          (g.pin_masked ? ' ' + esc(g.pin_masked) : '') + '</span>' +
          '<span style="text-align:right;min-width:70px"><b>' + esc(g.user_name || '未對應') + '</b>' +
          (g.lease_id ? '<br><span style="color:var(--brown2);font-size:11px">租約 #' + g.lease_id + '</span>' : '') +
          '</span></div>';
      });
    }
    h += '</div>';
    return h;
  }

  function renderManager() {
    const c = $('#content');
    const props = state.properties;
    if (!props.length) {
      c.innerHTML = '<div class="card"><b>沒有可管理的房源</b>' +
        '<p style="font-size:13px;color:var(--brown2);margin-top:8px;line-height:1.9">智慧門鎖/智慧電錶是「綁在某一戶房源」上加購的,所以要先有房源。<br>' +
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
      '本戶帳單負擔 <b>NT$' + money(fee.amount) + '/月</b>(本戶專用 ' + (fee.unit_count || 0) + ' 台' +
      ((fee.shared_count || 0) > 0 ? ' + 指定由本戶負擔的共用 ' + fee.shared_count + ' 台' : '') + ')<br>' +
      ((p.fee && p.fee.owner_cost && p.fee.owner_cost.count > 0)
        ? '房東自行負擔的共用裝置 ' + p.fee.owner_cost.count + ' 台 = NT$' + money(p.fee.owner_cost.amount) + '/月(不併入房客帳單)<br>'
        : '') +
      '共用裝置(樓層/整棟)只收一次,不會因為多間房而重複計費</p></div>';

    /* 有生效租約卻還沒發碼 → 一鍵補發(舊租約在門鎖加裝前就生效的情況) */
    const L = p.active_lease;
    const locks = p.devices.filter(d => d.device_type === 'lock');
    const issuedCount = locks.filter(d => d.tenant_issued).length;
    if (L && locks.length && issuedCount < locks.length) {
      html += '<div class="card" style="padding:14px 16px;border:1.5px solid var(--wood)">' +
        '<b style="font-size:14.5px">⚠️ 這戶有生效租約,但門鎖還沒發碼</b>' +
        '<p style="font-size:12.5px;color:var(--brown2);margin:8px 0 0;line-height:1.9">' +
        '房客:' + esc(L.tenant_name) + '|租期 ' + dstr(L.start_date) + ' ~ ' + (L.end_date ? dstr(L.end_date) : '不定期') + '<br>' +
        ('尚未發碼:' + locks.filter(d => !d.tenant_issued).map(d => esc(d.name)).join('、') + '<br>可能是租約在加裝門鎖前就生效了。按下面按鈕會立即產生並發送入住密碼給房客(同時通知您)。') + '</p>' +
        '<div style="display:flex;gap:8px;margin-top:10px">' +
        '<button type="button" class="btn small" data-issue="' + L.id + '">立即發碼給房客</button>' +
        '<button type="button" class="btn small ghost" data-mgr-all="1">先設管理密碼</button>' +
        '</div></div>';
    } else if (L) {
      html += '<div class="card flat" style="padding:12px 16px"><p style="font-size:12.5px;color:var(--brown2);margin:0;line-height:1.9">' +
        '住戶:' + esc(L.tenant_name) + '|租期 ' + dstr(L.start_date) + ' ~ ' + (L.end_date ? dstr(L.end_date) : '不定期') +
        '|門鎖密碼已發放(見下方各鎖的憑證)</p></div>';
    }

    {
      const mp = p.meter_plan || { plan: 'none', fee: 0, label: '未選擇', bridges: [], cloud_fee: 150, local_fee: 100 };
      const pid = state.propertyId || (p.property && p.property.id) || '';
      html += '<div class="sect">智慧電錶介接方案</div><div class="card" style="padding:14px 16px">' +
        '<p style="font-size:12.5px;color:var(--brown2);margin:0 0 10px;line-height:1.95">' +
        '<b>雲端 API</b>:智慧電錶本身連網,直接打台科電雲端,現場不必裝主機。<br>' +
        '<b>地端橋接</b>:智慧電錶在地下室或無網路時,現場一台小主機走 Modbus 把讀值推上來。<br>' +
        '硬體(智慧電錶、閘道器)另計,不列入系統帳單。</p>' +
        '<div style="display:flex;gap:8px;flex-wrap:wrap">' +
        '<button type="button" class="btn small' + (mp.plan === 'cloud' ? '' : ' ghost') + '" data-mplan="cloud" data-pid="' + pid + '">雲端 API・NT$' + (mp.cloud_fee || 150) + '/月</button>' +
        '<button type="button" class="btn small' + (mp.plan === 'local' ? '' : ' ghost') + '" data-mplan="local" data-pid="' + pid + '">地端橋接・NT$' + (mp.local_fee || 100) + '/月</button>' +
        (mp.plan !== 'none' ? '<button type="button" class="btn small ghost" data-mplan="none" data-pid="' + pid + '">改回逐台計費</button>' : '') +
        '</div>' +
        '<p style="font-size:12px;color:var(--wood-d);margin:10px 0 0">目前:' + esc(mp.label) +
        (mp.plan !== 'none' ? '・' + (mp.billable_meters || 0) + ' 顆計費錶 × NT$' + money(mp.fee_per_meter || 0) + ' = 每月 NT$' + money(mp.fee || 0) : '') +
        '</p>' +
        ((mp.free_meters || 0) > 0 ? '<p style="font-size:11.5px;color:var(--brown2);margin:4px 0 0">另有 ' + mp.free_meters + ' 顆總錶/公共錶不計費</p>' : '') +
        '<button type="button" class="btn small ghost" style="margin-top:10px" data-mfee="1">查看費用與合約條款</button>' +
        '<div id="mfeeBox" style="display:none;margin-top:12px;border-top:1px dashed var(--beige2);padding-top:12px">' +
        '<p style="font-size:12.5px;color:var(--brown2);margin:0 0 8px;line-height:1.9"><b>計價方式</b>:按「該房源內計費智慧電錶顆數」計價;總錶、公共錶、車道錶不收費。</p>' +
        '<table style="width:100%;border-collapse:collapse;font-size:12px">' +
        '<tr style="background:var(--cream2)"><th style="text-align:left;padding:6px 8px">項目</th><th style="text-align:right;padding:6px 8px">雲端 API</th><th style="text-align:right;padding:6px 8px">地端橋接</th></tr>' +
        '<tr><td style="padding:6px 8px;border-top:1px solid var(--beige2)">月費(每顆計費錶)</td>' +
        '<td style="text-align:right;padding:6px 8px;border-top:1px solid var(--beige2)">NT$' + money(mp.cloud_fee || 180) + '</td>' +
        '<td style="text-align:right;padding:6px 8px;border-top:1px solid var(--beige2)">NT$' + money(mp.local_fee || 150) + '</td></tr>' +
        '<tr><td style="padding:6px 8px;border-top:1px solid var(--beige2)">一次性設定費</td>' +
        '<td style="text-align:right;padding:6px 8px;border-top:1px solid var(--beige2)">NT$' + money(mp.setup_cloud || 500) + '</td>' +
        '<td style="text-align:right;padding:6px 8px;border-top:1px solid var(--beige2)">NT$' + money(mp.setup_local || 2000) + '</td></tr>' +
        '<tr><td style="padding:6px 8px;border-top:1px solid var(--beige2)">硬體</td>' +
        '<td colspan="2" style="text-align:right;padding:6px 8px;border-top:1px solid var(--beige2);color:var(--brown2)">另計,保固由原廠提供</td></tr>' +
        '<tr><td style="padding:6px 8px;border-top:1px solid var(--beige2)">到府服務(保固外)</td>' +
        '<td colspan="2" style="text-align:right;padding:6px 8px;border-top:1px solid var(--beige2)">NT$' + money(mp.visit_fee || 800) + '/次</td></tr>' +
        '<tr><td style="padding:6px 8px;border-top:1px solid var(--beige2)">大量優惠</td>' +
        '<td colspan="2" style="text-align:right;padding:6px 8px;border-top:1px solid var(--beige2)">20 顆以上 9 折・50 顆以上 85 折</td></tr>' +
        '</table>' +
        '<div style="margin-top:12px;background:var(--cream2);border-radius:10px;padding:10px 12px">' +
        '<p style="font-size:12px;color:var(--brown2);margin:0 0 6px"><b>試算</b>:本房源目前 ' + (mp.billable_meters || 0) + ' 顆計費錶</p>' +
        '<p style="font-size:12.5px;margin:0;line-height:1.95">雲端:NT$' + money((mp.cloud_fee || 180) * (mp.billable_meters || 0)) + '/月(設定費 NT$' + money(mp.setup_cloud || 500) + ')<br>' +
        '地端:NT$' + money((mp.local_fee || 150) * (mp.billable_meters || 0)) + '/月(設定費 NT$' + money(mp.setup_local || 2000) + ')</p></div>' +
        '<div style="margin-top:12px">' +
        '<p style="font-size:12px;color:var(--brown2);margin:0 0 6px"><b>合約必要條款</b>(安裝前須告知房東)</p>' +
        '<ol style="font-size:12px;color:var(--brown2);margin:0;padding-left:18px;line-height:1.95">' +
        '<li>地端方案之計費以現場主機帳本為準,APP 顯示為最後同步資料。</li>' +
        '<li>地端方案之儲值於現場主機連線後生效,不保證即時復電。</li>' +
        '<li>硬體保固由原廠提供;系統設定與軟體維護不含人為損壞與現場網路環境問題。</li>' +
        '</ol></div></div>';
      if (mp.plan === 'local') {
        html += '<div style="margin-top:10px;border-top:1px dashed var(--beige2);padding-top:10px">' +
          '<p style="font-size:12px;color:var(--brown2);margin:0 0 8px">現場橋接器(金鑰只在產生時顯示一次)</p>';
        (mp.bridges || []).forEach(bg => {
          html += '<div style="display:flex;justify-content:space-between;align-items:center;font-size:12.5px;padding:5px 0">' +
            '<span>' + esc(bg.name) + '・<span class="st ' + (bg.online ? 'ok' : 'warn') + '" style="font-size:10.5px">' + esc(bg.status_label || '') + '</span>' +
            (bg.last_seen_at ? '<span style="color:var(--brown2);font-size:11px"> ' + dt(bg.last_seen_at) + '</span>' : '') + '</span>' +
            '<button type="button" class="btn small ghost" style="padding:5px 9px;font-size:11.5px;color:var(--err)" data-bridge-off="' + bg.id + '" data-pid="' + pid + '">停用</button>' +
            '</div>';
        });
        html += '<button type="button" class="btn small" style="margin-top:8px" data-bridge-add="1" data-pid="' + pid + '">產生橋接器金鑰</button>' +
          '<p style="font-size:11.5px;color:var(--brown2);margin:8px 0 0;line-height:1.8">把金鑰貼進現場小主機的 <code>config.json</code>,執行 <code>python3 bridge.py</code> 即會自動上報。</p></div>';
      }
      html += '</div>';
    }

    {
      const plans = { taipower: '台電帳單(房客依台電帳單繳費)', flat: '固定單價(每度 NT$' + ((p.property && p.property.elec_rate) || 5) + ')', taipower_ladder: '台電累進級距' };
      const hasMeter = (p.meters || []).length > 0;
      if (!hasMeter) {
        html += '<div class="sect">智慧電錶與抄表</div><div class="card" style="padding:14px 16px">' +
          '<p style="font-size:12.5px;color:var(--brown2);margin:0;line-height:1.95">' +
          '此房源<b>尚未加裝智慧電錶</b>。沒有智慧電錶也能正常收租與出帳,只是電費不會自動抄表,可選:<br>' +
          '・<b>台電帳單</b>:房客自行依台電帳單繳費,系統不計電費<br>' +
          '・<b>固定金額/固定度數</b>:每月固定收一筆,或固定度數 × 單價<br>' +
          '・<b>人工抄表</b>:現場看傳統指針錶,每月把度數輸入帳單<br>' +
          '要自動抄表才需加裝「智慧電錶」(雲端 NT$180/顆/月、地端 NT$150/顆/月)。' +
          '</p></div>';
      }
    }

    if (p.meters && p.meters.length) {
      html += '<div class="sect">智慧電錶與抄表</div>';
      p.meters.forEach(m => {
        const modeTxt = m.mode === 'prepaid' ? '儲值型(預付,餘額不足自動斷電)' : '後付型(併入每期帳單)';
        const last = m.last_reading != null ? money(m.last_reading) + ' 度' : '尚未抄表';
        html += '<div class="card" style="padding:14px 16px">' +
          '<div style="display:flex;justify-content:space-between;align-items:flex-start">' +
          '<div><b style="font-size:15px">⚡ ' + esc(m.name) + '</b>' +
          '<div style="font-size:11.5px;color:var(--brown2);margin-top:4px">' + esc(m.adapter_label) + '・' + esc(m.serial_no || '') +
            (m.role === 'main_meter' ? '・<b style="color:var(--wood-d)">樓層總錶・不計費</b>' : '') + '</div></div>' +
          '<span class="st ' + (m.status === 'active' ? 'ok' : 'warn') + '">' + (m.status === 'active' ? '在線' : '離線') + '</span></div>' +
          '<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:10px">' +
          '<div style="background:var(--cream2);border-radius:10px;padding:9px 11px"><div style="font-size:11px;color:var(--brown2)">最新讀值</div><b style="font-size:16px">' + last + '</b></div>' +
          '<div style="background:var(--cream2);border-radius:10px;padding:9px 11px"><div style="font-size:11px;color:var(--brown2)">' + (m.mode === 'prepaid' ? '儲值餘額' : '計費模式') + '</div><b style="font-size:16px">' + (m.mode === 'prepaid' ? 'NT$' + money(m.balance) : '後付') + '</b></div>' +
          '</div>' +
          '<p style="font-size:11.5px;color:var(--brown2);margin:8px 0 0;line-height:1.8">' + modeTxt +
          (m.last_read_at ? '・上次抄表 ' + dt(m.last_read_at) : '') +
          (m.mode === 'prepaid' ? '・電源 ' + (m.relay_state === 'on' ? '供電中' : '已斷電') : '') + '</p>' +
          (m.pending_bill ? '<p style="font-size:11.5px;color:var(--wood-d);margin:6px 0 0">待寫入帳單:' + esc(m.pending_bill.period) + ' 期(' + (m.pending_bill.status === 'draft' ? '草稿' : '待繳') + ')</p>' : '') +
          '<div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:10px">' +
          '<button type="button" class="btn small" data-msync="' + m.id + '">立即抄表</button>' +
          (m.pending_bill ? '<button type="button" class="btn small ghost" data-mpush="' + m.pending_bill.id + '">寫入本期帳單</button>' : '') +
          (m.mode === 'prepaid'
            ? '<button type="button" class="btn small ghost" data-mtop="' + m.id + '">儲值</button>' +
              '<button type="button" class="btn small ghost" data-mrelay="' + m.id + '" data-on="' + (m.relay_state === 'on' ? '0' : '1') + '">' + (m.relay_state === 'on' ? '斷電' : '復電') + '</button>'
            : '<button type="button" class="btn small ghost" data-mmode="' + m.id + '" data-mode="prepaid">改為儲值型</button>') +
          '</div></div>';
      });
      html += '<div class="sect">裝置清單</div>';
    } else {
      html += '<div class="sect">裝置清單</div>';
    }

    if (!p.devices.length) {
      html += '<p style="padding:12px 4px;color:var(--brown2);font-size:13px;margin:0">尚未加裝裝置。建議先加「大門門鎖」與「房門門鎖」兩台(模擬器),就能在 XAMPP 完整演示簽約發碼流程。</p>';
    }
    /* 本戶裝置與共用裝置分開列:總錶/樓道門屬於整棟或樓層,不屬於這一戶,
       混在「本戶」清單裡會讓房東以為是 301 房的錶。 */
    const sortedDevices = p.devices.slice().sort((a, b) => (a.is_shared ? 1 : 0) - (b.is_shared ? 1 : 0));
    let sharedHeaderAdded = false;
    sortedDevices.forEach(d => {
      if (d.is_shared && !sharedHeaderAdded) {
        sharedHeaderAdded = true;
        html += '<div class="sect">共用裝置(樓層／整棟,同棟各戶都看得到)</div>';
      }
      html += '<div class="card" style="padding:14px 16px">' +
        '<div style="display:flex;justify-content:space-between;align-items:flex-start">' +
        '<div><b style="font-size:15px">' + roleIcon[d.role] + ' ' + esc(d.name) + '</b>' +
        '<div style="font-size:11.5px;color:var(--brown2);margin-top:4px">' + esc(d.adapter_label) + '・' + esc(d.serial_no || '') + '・' + (roleName[d.role] || '') + '</div>' +
        '<div style="margin-top:5px"><span class="st ' + (d.is_shared ? 'warn' : '') + '" style="font-size:10.5px">' + esc(d.shared_label) +
        (d.is_shared ? '・只收一次' : '') + '</span>' +
        (d.is_shared && d.charge_property_id ? '<span style="font-size:10.5px;color:var(--brown2);margin-left:6px">費用計入 #' + d.charge_property_id + '</span>' : '') +
        (d.is_shared && !d.charge_property_id ? '<span style="font-size:10.5px;color:var(--brown2);margin-left:6px">房東負擔</span>' : '') +
        '</div></div>' +
        '<span class="st ' + (d.online ? 'ok' : 'warn') + '">' + (d.online ? '在線' : '離線') + '</span></div>' +
        '<p style="font-size:12px;color:var(--brown2);margin:8px 0 0">' + esc(d.adapter_message) + (d.last_sync_at ? '・同步 ' + dt(d.last_sync_at) : '') + '</p>';
      if (d.credentials && d.credentials.length) {
        html += '<div style="margin-top:10px;border-top:1px dashed var(--beige2);padding-top:8px">';
        d.credentials.slice(0, 6).forEach(cr => {
          const active = cr.status === 'active';
          html += '<div style="display:flex;justify-content:space-between;align-items:center;gap:8px;font-size:12.5px;padding:6px 0">' +
            '<span style="flex:1;min-width:0">' + esc(cr.label || '') + (cr.user_name ? '・' + esc(cr.user_name) : '') +
            (cr.valid_to ? '<span style="color:var(--brown2);font-size:11px"> 至 ' + dstr(cr.valid_to) + '</span>' : '') + '</span>' +
            (active
              ? '<b style="font-family:monospace;letter-spacing:2px">' + esc(cr.pin || '••••••') + '</b>' +
                '<button type="button" class="btn small ghost" style="padding:5px 9px;font-size:11.5px" data-cred-change="' + cr.id + '" data-cred-label="' + esc(cr.label || '密碼') + '">重設</button>' +
                (cr.user_name ? '<button type="button" class="btn small ghost" style="padding:5px 9px;font-size:11.5px;color:var(--err)" data-cred-revoke="' + cr.id + '">撤銷</button>' : '')
              : '<span class="st ' + (cr.status === 'expired' ? 'warn' : '') + '" style="font-size:11px">' + (cr.status === 'expired' ? '已到期' : '已撤銷') + '</span>') +
            '</div>';
        });
        html += '</div>';
      }
      html += '<div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:10px">' +
        (d.device_type === 'lock' ? '<button type="button" class="btn small" data-mgr="' + d.id + '" data-mgrname="' + esc(d.name) + '">設定管理密碼</button>' : '') +
        (d.device_type === 'lock'
          ? '<button type="button" class="btn small ghost" data-editable="' + d.id + '" data-cur="' + (d.tenant_editable ? '1' : '0') + '">' +
            (d.tenant_editable ? '🔓 房客可改碼:開' : '🔒 房客可改碼:關') + '</button>'
          : '') +
        (d.device_type === 'lock' && d.role === 'gate' ? '<button type="button" class="btn small ghost" data-shared="' + d.id + '" data-cur="' + (d.shared_pin ? '1' : '0') + '">' + (d.shared_pin ? '👥 共用碼:開' : '👥 共用碼:關') + '</button>' : '') +
        '<button type="button" class="btn small ghost" data-scope="' + d.id + '" data-cur="' + d.scope_type + '">改範圍</button>' +
        '<button type="button" class="btn small ghost" data-sync="' + d.id + '">同步狀態</button>' +
        '<button type="button" class="btn small ghost" data-del="' + d.id + '" data-name="' + esc(d.name) + '">移除</button>' +
        '</div></div>';
    });

    html += '<div class="sect">加裝裝置</div>' +
      '<div class="card" style="padding:14px 16px">' +
      '<div class="field"><label for="dProp">加裝在哪一戶房源</label><select id="dProp" style="font-size:14.5px;padding:11px">' + propOptions() + '</select></div>' +
      '<div style="background:rgba(200,155,107,.12);border-radius:12px;padding:9px 12px;margin:6px 0 12px;font-size:12.5px;line-height:1.8">' +
      '即將加裝於:<b>' + esc(p.property.title) + '</b>(編號 #' + p.property.id + ')<br>每台每月加購 NT$' + money(fee.unit_price) + ',會自動併入該戶帳單</div>' +
      '<div class="field"><label for="dScope">安裝範圍(費用怎麼算)</label><select id="dScope" style="font-size:14.5px;padding:11px">' +
      '<option value="unit">本戶專用(只服務這一戶,計入本戶帳單)</option>' +
      '<option value="floor">樓層共用(同層房客共用,全層只收一次)</option>' +
      '<option value="building">整棟共用(例:1F 大門,全棟只收一次)</option></select></div>' +
      '<div id="dChargeWrap" style="display:none">' +
      '<div class="field"><label for="dCharge">費用歸屬(共用裝置只收一次)</label><select id="dCharge" style="font-size:14.5px;padding:11px">' +
      '<option value="0">房東自行負擔(不併入房客帳單)</option>' +
      (p.siblings || []).map(sb => '<option value="' + sb.id + '">計入 ' + esc(sb.title) + '</option>').join('') +
      '</select></div></div>' +
      '<div class="field"><label for="dType">類型</label><select id="dType"><option value="lock">智慧門鎖</option><option value="meter">智慧電錶</option></select></div>' +
      '<div class="field" id="dLinkWrap" style="display:none"><label for="dLink">智慧電錶介接方式</label><select id="dLink">' +
      '<option value="cloud">雲端 API(每顆 NT$180/月)</option>' +
      '<option value="local">地端 Modbus 橋接(每顆 NT$150/月)</option>' +
      '<option value="mock">模擬智慧電錶(測試用,不收費)</option></select>' +
      '<p style="font-size:11.5px;color:var(--brown2);margin:6px 0 0;line-height:1.8">選「總智慧電錶」= 整棟共用:系統自動設為不計費,建立後同棟各戶都看得到,且全棟只能有一顆。</p></div>' +
      '<div class="field"><label for="dRole">位置</label><select id="dRole">' +
      '<option value="gate">大門</option><option value="door">房門</option>' +
      '<option value="unit_meter">戶別智慧電錶</option><option value="main_meter">總智慧電錶</option></select></div>' +
      '<div class="field"><label for="dName">名稱(可留空)</label><input id="dName" maxlength="60" placeholder="例:1F 大門門鎖"></div>' +
      '<button type="button" class="btn primary" id="addDev" style="margin-top:10px">加裝(模擬器,NT$' + money(fee.unit_price) + '/月)</button>' +
      '<p style="font-size:11.5px;color:var(--brown2);margin-top:8px;line-height:1.8">目前僅提供模擬器;Aqara 真機需完成代理商簽約、取得 API 憑證後切換 Adapter(介面已預留,流程不用改)。</p></div>';

    html += accessSection(p.access_logs);
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
    const qBtn = $('#pinQLookup');
    if (qBtn) qBtn.onclick = () => {
      const v = String(($('#pinQ') || {}).value || '').trim();
      const box = $('#pinQResult');
      if (!/^\d{6}$/.test(v)) { box.innerHTML = '<p style="font-size:12.5px;color:var(--err);margin:0">請輸入 6 位數字</p>'; return; }
      box.innerHTML = '<p style="font-size:12.5px;color:var(--brown2);margin:0">查詢中…</p>';
      api('api/devices.php', { action: 'lookup_pin', property_id: Number(state.propertyId), pin: v }).then(d => {
        if (!d.ok) { box.innerHTML = '<p style="font-size:12.5px;color:var(--err);margin:0">' + esc(d.message || '查無此密碼') + '</p>'; return; }
        const m = d.match || {};
        let h = '<div style="background:rgba(200,155,107,.12);border-radius:12px;padding:10px 12px;font-size:12.5px;line-height:1.9">' +
          '<b>' + esc(m.user_name || '未綁定房客') + '</b>・' + esc(m.device_name || '') + '・' + esc(m.label || '') +
          '<br>狀態:' + (m.status === 'active' ? '使用中' : (m.status === 'revoked' ? '已撤銷' : '已到期')) +
          (m.lease_id ? '・租約 #' + m.lease_id : '') +
          (m.valid_to ? '<br>有效至 ' + dstr(m.valid_to) : '') + '</div>';
        if (d.logs && d.logs.length) {
          h += '<div style="margin-top:8px;font-size:12px;color:var(--brown2)">這組密碼最近開門:</div>';
          d.logs.forEach(g => {
            h += '<div style="padding:6px 0;border-bottom:1px dashed var(--beige2);font-size:12px">' +
              dt(g.unlocked_at) + '・' + esc(g.device_name || '') + '・' + (g.slot_label || '門') + '</div>';
          });
        } else {
          h += '<p style="font-size:12px;color:var(--brown2);margin:8px 0 0">這組密碼目前沒有開門紀錄。</p>';
        }
        box.innerHTML = h;
      }).catch(() => { box.innerHTML = '<p style="font-size:12.5px;color:var(--err);margin:0">連線失敗</p>'; });
    };
    bindPropSel();
    const dScope = $('#dScope'), dChargeWrap = $('#dChargeWrap');
    if (dScope && dChargeWrap) {
      dScope.onchange = () => { dChargeWrap.style.display = dScope.value === 'unit' ? 'none' : 'block'; };
    }
    const add = $('#addDev');
    if (add) add.onclick = () => {
      const type = $('#dType').value;
      const linkWrap = document.getElementById('dLinkWrap');
      if (linkWrap) { linkWrap.style.display = (type === 'meter' ? 'block' : 'none'); }
      const roleSel = $('#dRole').value;
      const role = type === 'lock' ? (roleSel === 'gate' ? 'gate' : 'door') : (roleSel === 'main_meter' ? 'main_meter' : 'unit_meter');
      api('api/devices.php', {
        action: 'add', property_id: Number(state.propertyId), device_type: type, role: role,
        meter_link: type === 'meter' ? ($('#dLink') ? $('#dLink').value : 'cloud') : '',
        adapter: type === 'meter'
          ? ({ cloud: 'taike', local: 'local_bridge', mock: 'dev_mock_meter' }[($('#dLink') ? $('#dLink').value : 'cloud')] || 'dev_mock_meter')
          : 'dev_mock',
        name: $('#dName').value.trim(), adapter: 'dev_mock',
        scope_type: ($('#dScope') ? $('#dScope').value : 'unit'),
        charge_property_id: ($('#dCharge') ? Number($('#dCharge').value) : 0)
      }).then(d => { toast(d.message); if (d.ok) loadProperty(); }).catch(() => toast('連線失敗'));
    };
    const refreshMeters = () => loadProperty();
    c.querySelectorAll('[data-msync]').forEach(b => b.onclick = () => {
      b.disabled = true;
      const mid = Number(b.dataset.msync);
      api('api/meters.php', { action: 'sync', property_id: Number(state.propertyId) })
        .then(d => {
          b.disabled = false;
          const mine = (d.results || []).find(x => Number(x.device_id) === mid) || (d.results || [])[0];
          const detail = mine && mine.reading != null ? ':讀值 ' + money(mine.reading) + ' 度' : (mine ? ':' + mine.message : '');
          toast((d.ok ? '抄表完成' : '抄表失敗') + detail + (mine && mine.read_at ? '・' + mine.read_at : ''));
          return refreshMeters();
        })
        .catch(() => { b.disabled = false; toast('連線失敗'); });
    });
    c.querySelectorAll('[data-mpush]').forEach(b => b.onclick = () => {
      api('api/meters.php', { action: 'push_bill', bill_id: Number(b.dataset.mpush) })
        .then(d => { toast(d.message); refreshMeters(); }).catch(() => toast('連線失敗'));
    });
    c.querySelectorAll('[data-mtop]').forEach(b => b.onclick = () => {
      const v = prompt('儲值金額(NT$)', '1000');
      if (v === null) return;
      api('api/meters.php', { action: 'topup', device_id: Number(b.dataset.mtop), amount: Number(v) })
        .then(d => { toast(d.message); refreshMeters(); }).catch(() => toast('連線失敗'));
    });
    c.querySelectorAll('[data-mrelay]').forEach(b => b.onclick = () => {
      const on = b.dataset.on === '1';
      if (!confirm(on ? '復電?' : '斷電?房客將立即無電可用。')) return;
      api('api/meters.php', { action: 'relay', device_id: Number(b.dataset.mrelay), on: on ? '1' : '0' })
        .then(d => { toast(d.message); refreshMeters(); }).catch(() => toast('連線失敗'));
    });
    c.querySelectorAll('[data-mmode]').forEach(b => b.onclick = () => {
      if (!confirm('改為儲值型?之後以餘額扣抵用電,不足會自動斷電。')) return;
      api('api/meters.php', { action: 'set_mode', device_id: Number(b.dataset.mmode), mode: b.dataset.mode })
        .then(d => { toast(d.message); refreshMeters(); }).catch(() => toast('連線失敗'));
    });
    c.querySelectorAll('[data-scope]').forEach(b => b.onclick = () => {
      const cur = b.dataset.cur || 'unit';
      const next = cur === 'unit' ? 'floor' : (cur === 'floor' ? 'building' : 'unit');
      const names = { unit: '本戶專用', floor: '樓層共用', building: '整棟共用' };
      if (!confirm('改為「' + names[next] + '」?' + (next !== 'unit' ? '\n共用裝置只收一次費用。' : ''))) return;
      let charge = 0;
      if (next !== 'unit') {
        const v = prompt('費用歸屬戶:輸入房源編號(留空或 0 = 房東自行負擔)\n同棟房源:' + (state.property.siblings || []).map(s2 => s2.id + '=' + s2.title).join('、'), '0');
        if (v === null) return;
        charge = Number(v) || 0;
      }
      api('api/devices.php', { action: 'set_scope', device_id: Number(b.dataset.scope), scope_type: next, charge_property_id: charge })
        .then(d => { toast(d.message); if (d.ok) loadProperty(); }).catch(() => toast('連線失敗'));
    });
    c.querySelectorAll('[data-editable]').forEach(b => b.onclick = () => {
      const next = b.dataset.cur === '1' ? '0' : '1';
      api('api/devices.php', { action: 'set_tenant_editable', device_id: Number(b.dataset.editable), enabled: next })
        .then(d => { toast(d.message); if (d.ok) loadProperty(); }).catch(() => toast('連線失敗'));
    });
    c.querySelectorAll('[data-issue]').forEach(b => b.onclick = () => {
      if (!confirm('立即產生入住密碼並發給房客?')) return;
      api('api/devices.php', { action: 'issue', lease_id: Number(b.dataset.issue) })
        .then(d => {
          if (d.ok && d.issued && d.issued.length) {
            toast('已發碼:' + d.issued.map(x => x.label + ' ' + x.pin).join('、'));
          } else { toast(d.message); }
          loadProperty();
        }).catch(() => toast('連線失敗'));
    });
    c.querySelectorAll('[data-mgr-all]').forEach(b => b.onclick = () => {
      const pin = prompt('設定管理密碼(6 位數字;留空 = 自動產生)\n會套用到本戶所有門鎖,退租不會撤銷');
      if (pin === null) return;
      const v = String(pin).trim();
      Promise.all((state.property.devices || []).filter(d => d.device_type === 'lock')
        .map(d => api('api/devices.php', { action: 'set_manager_pin', device_id: Number(d.id), pin: v })))
        .then(() => { toast('管理密碼已設定'); loadProperty(); })
        .catch(() => toast('連線失敗'));
    });
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
    c.querySelectorAll('[data-shared]').forEach(b => b.onclick = () => {
      const on = b.dataset.cur === '1';
      const msg = on
        ? '關閉共用碼?系統會撤銷目前這組共用碼,改為每位在租房客各發一組個人密碼。'
        : '開啟共用碼?大門/樓道門會改成全體共用同一組密碼(現有各人的大門碼會立即失效,並改發一組新碼)。';
      if (!confirm(msg)) return;
      api('api/devices.php', { action: 'set_shared_pin', device_id: Number(b.dataset.shared), enabled: on ? '0' : '1' })
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
      state.tenant = (d && d.ok) ? d : { lease: null, locks: [], events: [] };
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
    state.tenant = (my && my.ok) ? my : null;
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


/* 智慧電錶介接方案 / 橋接器(獨立委派,不依賴主渲染流程) */
document.addEventListener('click', function (e) {
  const feeBtn = e.target && e.target.closest ? e.target.closest('[data-mfee]') : null;
  if (feeBtn) {
    e.preventDefault();
    const box = document.getElementById('mfeeBox');
    if (box) { box.style.display = (box.style.display === 'none' ? 'block' : 'none'); }
    return;
  }
  const b = e.target && e.target.closest ? e.target.closest('[data-mplan],[data-bridge-add],[data-bridge-off]') : null;
  if (!b) { return; }
  e.preventDefault();
  const pid = Number(b.dataset.pid || 0);
  let payload = null; let confirmText = '';
  if (b.dataset.mplan) {
    if (b.dataset.mplan === 'none' && !confirm('改回逐台計費(每台智慧電錶 NT$100/月)?')) { return; }
    payload = { action: 'set_plan', property_id: pid, plan: b.dataset.mplan };
    confirmText = '智慧電錶方案已更新,下期帳單起以新月費計算。';
  } else if (b.dataset.bridgeAdd) {
    payload = { action: 'bridge_create', property_id: pid, name: '現場橋接器' };
  } else if (b.dataset.bridgeOff) {
    if (!confirm('停用後該橋接器金鑰立即失效,現場需重新產生。確定?')) { return; }
    payload = { action: 'bridge_disable', bridge_id: Number(b.dataset.bridgeOff) };
  }
  if (!payload) { return; }
  fetch('api/meters.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) })
    .then(r => r.json())
    .then(j => {
      if (j.token) {
        alert('橋接器金鑰(只顯示這一次,請立即複製):\n\n' + j.token + '\n\n貼進現場 config.json 的 token 欄位。');
      } else {
        alert(j.message || (j.ok ? confirmText || '完成' : '失敗'));
      }
      if (j.ok) { location.reload(); }
    })
    .catch(() => alert('連線失敗,請稍後再試'));
});
