(async function () {
  const $ = s => document.querySelector(s);
  const fmt = n => 'NT$' + Number(n || 0).toLocaleString();
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  }
  const stTxt = { confirmed: '✅ 已出表', paid: '💵 已發放' };

  try {
    const res = await fetch('api/payroll.php?act=mine', { cache: 'no-store' });
    const d = await res.json();
    if (!d.ok) { toast(d.message || '載入失敗'); return; }
    const slips = d.payslips || [];
    if (!slips.length) {
      $('#list').innerHTML = '<p style="padding:14px 2px;color:var(--brown2);font-size:13px;margin:0">目前尚無薪資條 — 公司會計每月出表後,薪資條會顯示在這裡</p>';
      return;
    }
    $('#sumLine').textContent = slips[0].org_name + '・共 ' + slips.length + ' 期';
    $('#list').innerHTML = slips.map(p =>
      '<div class="list-item"><div style="flex:1;min-width:0">' +
      '<b>' + esc(p.period).replace('-', ' 年 ') + ' 月|應發 ' + fmt(p.gross) + '</b>' +
      '<span>底薪 ' + fmt(p.base_salary) +
      (Number(p.bonus_amount) ? '・獎金 ' + fmt(p.bonus_amount) + (p.bonus_detail ? '(' + esc(p.bonus_detail) + ')' : '') : '') +
      (Number(p.extra_amount) ? '・' + esc(p.extra_label || '其他') + ' ' + (p.extra_amount > 0 ? '+' : '') + fmt(p.extra_amount).replace('NT$', '$') : '') +
      (Number(p.ins_deduct) ? '・勞健保自付 -' + fmt(p.ins_deduct).replace('NT$', '$') : '') +
      (Number(p.pension_employer) ? '・🏛 勞退 6% 提撥 ' + fmt(p.pension_employer) + '(公司提繳至您的退休金帳戶)' : '') +
      (p.paid_at ? '・發放於 ' + esc(String(p.paid_at).slice(0, 10)) : '') + '</span></div>' +
      '<span class="st ' + (p.status === 'paid' ? 'ok' : 'idle') + '">' + (stTxt[p.status] || esc(p.status)) + '</span></div>'
    ).join('');
  } catch (e) { toast('連線異常'); }
})();
