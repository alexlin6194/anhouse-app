(function () {
  'use strict';
  const $ = (s) => document.querySelector(s);
  let toastTimer;
  function showToast(msg) {
    const t = $('#toast');
    t.textContent = msg;
    t.classList.add('show');
    clearTimeout(toastTimer);
    setTimeout(() => t.classList.remove('show'), 2600);
  }
  const money = (n) => Number(n || 0).toLocaleString('zh-TW');
  const dstr = (d) => d ? String(d).replace(/-/g, '/') : '';
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  let propOptions = null;

  function formulaText(row, prop) {
    const rate = (prop.elec_rate != null && Number(prop.elec_rate) > 0) ? Number(prop.elec_rate) : 5;
    if (prop.elec_mode === 'taipower') {
      const season = row.summer ? '夏月' : '非夏月';
      if (row.breakdown && row.breakdown.length) {
        return row.breakdown.map(b => b.label + ' × NT$' + b.rate.toFixed(2) + '元 = NT$' + money(b.subtotal) + '元').join('<br>') +
          '<br><b>小計 NT$' + money(row.electric_amount) + '元(台電' + season + '累進計價)</b>';
      }
      return '台電' + season + '累進計價 = NT$' + money(row.electric_amount) + '元';
    }
    return money(row.usage) + ' 度 × NT$' + (rate % 1 ? rate.toFixed(1) : rate) + '元/度 = <b>NT$' + money(row.electric_amount) + '元</b>';
  }

  function render(data) {
    const c = $('#content');
    if (!data.lease) {
      c.innerHTML = '<p style="padding:20px 4px;text-align:center;color:var(--brown2);font-size:13.5px;margin:0">尚無生效中的租約,簽約後這裡會顯示電費計算明細。</p>';
      return;
    }
    const prop = data.property;
    const L = data.lease;
    const modeTxt = prop.elec_mode === 'taipower'
      ? '⚡ 台電累進計價(夏月 6–9 月級距較高)'
      : '⚡ 固定每度 NT$' + ((prop.elec_rate != null && Number(prop.elec_rate) > 0) ? (Number(prop.elec_rate) % 1 ? Number(prop.elec_rate).toFixed(1) : prop.elec_rate) : '5') + '元';
    let html = '<div class="card" style="padding:14px 16px">' +
      '<b style="font-size:15.5px">🏠 ' + esc(prop.title) + '</b>' +
      '<p style="font-size:12.5px;color:var(--brown2);margin:6px 0 0;line-height:1.9">' +
      '計價方式:' + modeTxt + '<br>' +
      '租期:' + dstr(L.start_date) + '~' + (L.end_date ? dstr(L.end_date) : '不定期') + '|每月 ' + L.payment_day + ' 日繳租<br>' +
      '🔢 <b>簽約原始度數(計算基準):' + (L.meter_start != null ? money(L.meter_start) + ' 度' : '未記錄') + '</b>' +
      (L.meter_start != null ? '<br>每期電費 = (本期讀數 − 上期讀數)× 單價,首期以簽約原始度數為上期' : '') + '</p></div>';

    html += '<div class="sect">各期電費明細</div>';
    if (!data.rows.length) {
      html += '<p style="padding:14px 4px;color:var(--brown2);font-size:13px;margin:0">尚未有任何帳單:第一期帳單將於首個繳租日 0 點產生(含該期電費)。</p>';
    } else {
      html += data.rows.map(r => {
        const statusChip = r.status === 'paid' ? '<span class="st ok">已繳</span>' : '<span class="st warn">待繳</span>';
        const hasElec = r.electric_amount != null;
        const formula = hasElec
          ? '<div style="background:rgba(200,155,107,.1);border-radius:10px;padding:8px 12px;margin-top:6px;font-size:12.5px;line-height:1.8">' +
            '📐 ' + formulaText(r, prop) + '</div>'
          : '<div style="font-size:12.5px;color:var(--brown2);margin-top:6px">電費(待抄錄):房東/管理員將於繳租日前抄錄電錶,金額自動更新</div>';
        const photo = r.electric_photo
          ? '<img src="uploads/' + esc(r.electric_photo) + '" data-full="uploads/' + esc(r.electric_photo) + '" alt="電錶照片" style="width:72px;height:72px;object-fit:cover;border-radius:10px;border:1.5px solid var(--beige2);cursor:zoom-in;margin-top:8px">'
          : '';
        return '<div class="lrow">' +
          '<div class="ltop"><div class="linfo">' +
          '<b>' + esc(r.period) + '月</b>' +
          '<span>本期讀數:' + (r.meter_reading != null ? money(r.meter_reading) + ' 度' : '未抄錄') +
          (r.prev_reading != null ? '|上期:' + money(r.prev_reading) + ' 度' : '') +
          (r.usage != null ? '|用電 ' + money(r.usage) + ' 度' : '') + '</span>' +
          '</div>' + statusChip + '</div>' +
          formula + photo +
          '</div>';
      }).join('');
    }
    c.innerHTML = html;
    c.querySelectorAll('[data-full]').forEach(im => {
      im.addEventListener('click', () => { if (window.showLightbox) window.showLightbox(im.dataset.full, '電錶照片'); });
    });
  }

  async function load(propId) {
    let data;
    try {
      const res = await fetch('api/electricity.php' + (propId ? '?property_id=' + propId : ''), { cache: 'no-store' });
      data = await res.json();
    } catch (e) {
      showToast('連線異常,請稍後再試');
      return;
    }
    if (!data.ok || !data.authenticated) { location.href = 'login.html'; return; }
    const backMap = { landlord: 'dashboard.html', manager: 'dashboard_manager.html', tenant: 'dashboard_tenant.html', company_owner: 'dashboard_company.html' };
    try {
      const me = await (await fetch('api/me.php', { cache: 'no-store' })).json();
      if (me.ok && me.authenticated && backMap[me.user.registration_role]) {
        $('#navBack').setAttribute('href', backMap[me.user.registration_role]);
      }
    } catch (e) { /* 忽略 */ }
    if (data.mode === 'pick') {
      propOptions = data.properties || [];
      if (propOptions.length) {
        $('#pickerWrap').style.display = '';
        $('#propSel').innerHTML = propOptions.map(p => '<option value="' + p.id + '">' + esc(p.title) + '</option>').join('');
        $('#propSel').addEventListener('change', () => load($('#propSel').value));
        if (propOptions.length === 1) {
          load(propOptions[0].id);
          return;
        }
        $('#content').innerHTML = '<p style="padding:14px 4px;color:var(--brown2);font-size:13px;margin:0">請選擇房屋以查看電費明細。</p>';
      } else {
        $('#content').innerHTML = '<p style="padding:18px 4px;text-align:center;color:var(--brown2);font-size:13px;margin:0">尚無房屋資料</p>';
      }
      return;
    }
    render(data);
  }

  load();
})();
