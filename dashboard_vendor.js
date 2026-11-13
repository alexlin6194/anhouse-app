(async function () {
  const $ = (s) => document.querySelector(s);

  $('#goRepairs').addEventListener('click', () => { location.href = 'repairs.html'; });
  $('#goSub').addEventListener('click', () => { location.href = 'subscription.html'; });
  $('#subCard').addEventListener('click', () => { location.href = 'subscription.html'; });

  try {
    const res = await fetch('api/vendor_dashboard.php', { credentials: 'same-origin' });
    const d = await res.json();
    if (!d.ok || !d.authenticated !== false) {
      if (d.message === '尚未登入') { location.href = 'login.html'; return; }
      if (d.message === '僅廠商可操作') { location.replace('dashboard.html'); return; }
    }
    const p = d.profile || {};
    $('#hello').textContent = p.company || '我的廠商服務';
    $('#subline').textContent = (p.specialty || '維修服務') + '|服務半徑 ' + (p.service_radius_km || 5) + ' 公里' +
      ((p.lat != null && p.lng != null) ? '' : '|⚠ 尚未設定服務位置');

    const s = d.stats || {};
    $('#stLeads').textContent = s.leads_new ?? 0;
    $('#stWorking').textContent = s.working ?? 0;
    $('#stDone').textContent = s.done_month ?? 0;

    const badge = $('#subBadge');
    let title = '', detail = '';
    if (p.sub_status === 'active') {
      badge.textContent = '訂閱中'; badge.className = 'st ok';
      title = (p.plan === 'pro' ? '進階' : '基本') + '方案';
      detail = '有效期至 ' + (p.sub_ends || '-') + ',到期前可續訂。';
    } else if (p.sub_status === 'trial') {
      const left = p.trial_ends ? Math.ceil((new Date(p.trial_ends) - new Date()) / 86400000) : 0;
      badge.textContent = '試用中'; badge.className = 'st warn';
      title = '試用期剩 ' + Math.max(left, 0) + ' 天';
      detail = '試用至 ' + (p.trial_ends || '-') + '。訂閱後持續接單不中斷。';
    } else {
      badge.textContent = '已停用'; badge.className = 'st bad';
      title = '訂閱已過期';
      detail = '續訂立即恢復承接資格。';
    }
    $('#subTitle').textContent = title;
    $('#subDetail').textContent = detail;

    const list = d.recent || [];
    $('#recent').innerHTML = list.length
      ? list.map(r => '<div class="list-item act-open" data-id="' + r.id + '" style="padding:8px 2px;cursor:pointer;flex-wrap:wrap"><div style="flex:1;min-width:0"><b>' +
          (r.property_title || '') + '</b><span>' + (r.description || '').slice(0, 24) +
          (r.updated_at ? '|' + r.updated_at.slice(5, 10) : '') + '</span></div>' +
          '<span class="st ' + (['assigned', 'inspected', 'repairing'].includes(r.status) ? 'warn' : (['done', 'billed'].includes(r.status) ? 'ok' : '')) + '">' +
          ({ pending: '待處理', assigned: '施工中', inspected: '已查修', repairing: '施工中', done: '已完工', billed: '已請款', cancelled: '已取消' }[r.status] || r.status) +
          '</span></div>').join('')
      : '<p style="color:#a09070;margin:0;font-size:13.5px">尚無工單,接到商機後會顯示在這裡。</p>';
    document.querySelectorAll('.act-open').forEach(el => el.addEventListener('click', () => {
      location.href = 'repairs.html?id=' + el.dataset.id;
    }));
  } catch (e) { /* 保留骨架 */ }
})();
