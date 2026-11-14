/* 資格審核(公司端):文件狀態+補件重傳 */
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

  const DOC_TXT = { broker_certificate: '經紀人證書', id_card: '身分證明文件', salesperson_cert: '營業員證書' };
  const ST_MAP = { pending: ['待平台審核', 'warn'], approved: ['✅ 審核通過', 'ok'], needs_update: ['⚠️ 需補件', 'warn'], rejected: ['❌ 已駁回', 'bad'] };

  async function load() {
    try {
      const res = await fetch('api/company_dashboard.php', { cache: 'no-store' });
      const d = await res.json();
      if (!d.ok || !d.authenticated) { location.href = 'login.html'; return; }
      if (!d.company) { location.href = 'dashboard.html'; return; }
      const org = d.org || {};
      const stTxt = { pending_review: '待平台審核', active: '已啟用', suspended: '已停權' }[org.status] || org.status;
      $('#orgLine').textContent = (org.name || '') + '|組織狀態:' + stTxt;

      $('#statusCard').innerHTML = '<div class="row"><div class="ico">🏢</div>' +
        '<div style="flex:1"><span class="tagline">組織狀態</span><h4>' + esc(org.name || '') + ' <span class="st ' + (org.status === 'active' ? 'ok' : org.status === 'suspended' ? 'bad' : 'warn') + '">' + stTxt + '</span></h4>' +
        '<p>統編 ' + esc(org.tax_id || '-') + '|' + (org.status === 'pending_review' ? '平台審核經紀人證書通過後,員工邀請與完整功能即開放。' : org.status === 'active' ? '資格有效,完整功能開放中。' : '已停權:請聯絡平台處理。') + '</p></div></div>';

      const docs = d.documents || [];
      $('#docList').innerHTML = docs.length
        ? '<div class="bd-sec" style="margin-bottom:6px">📄 資格文件(' + docs.length + ' 份)</div>' + docs.map(doc =>
          '<div class="list-item" style="flex-wrap:wrap"><span class="dot" style="background:' + (doc.status === 'approved' ? 'var(--green)' : doc.status === 'pending' ? 'var(--wood)' : 'var(--pink)') + '"></span>' +
          '<div style="flex:1;min-width:160px"><b>' + (DOC_TXT[doc.doc_type] || esc(doc.doc_type)) + '</b>' +
          '<span>上傳:' + esc(String(doc.created_at).slice(0, 10)) + (doc.reviewed_at ? '|審核:' + esc(String(doc.reviewed_at).slice(0, 10)) : '') + '</span>' +
          (doc.note ? '<span style="color:var(--wood-d);font-weight:700">💬 平台備註:' + esc(doc.note) + '</span>' : '') +
          '</div>' +
          (doc.file_path ? '<a href="' + esc(doc.file_path) + '" target="_blank" style="color:#8A6D3B;font-weight:700;font-size:12.5px;text-decoration:none">[查看文件]</a>' : '') +
          '<span class="st ' + ((ST_MAP[doc.status] || ['', 'idle'])[1]) + '">' + ((ST_MAP[doc.status] || [doc.status])[0]) + '</span></div>').join('')
        : '<p style="padding:10px 2px;color:var(--brown2);font-size:13px;margin:0">目前沒有資料可供查看:尚無任何資格文件。</p>';

      const latest = docs[0];
      const needUpload = !docs.length || (latest && (latest.status === 'needs_update' || latest.status === 'rejected')) || org.status === 'pending_review';
      if (needUpload) {
        $('#uploadCard').style.display = '';
        $('#uploadCard').innerHTML = '<div class="bd-sec">📤 補件/重新上傳經紀人證書</div>' +
          '<p style="font-size:12.5px;color:var(--brown2);margin:2px 0 8px">' + (latest && latest.status === 'needs_update' ? '平台要求補件(見上方備註)。' : latest && latest.status === 'rejected' ? '申請已駁回,修正後可重新上傳送審。' : '重新上傳將送平台重新審核。') + '</p>' +
          '<input type="file" id="certFile" accept="image/*,.pdf" style="display:none">' +
          '<button type="button" class="btn primary" id="certBtn" style="width:100%">📤 選擇檔案並送審(JPG/PNG/PDF,5MB 內)</button>';
        $('#certBtn').addEventListener('click', () => $('#certFile').click());
        $('#certFile').addEventListener('change', async () => {
          const f = $('#certFile').files[0];
          $('#certFile').value = '';
          if (!f) return;
          const fd = new FormData();
          fd.append('cert_file', f);
          try {
            const res = await fetch('api/company_resubmit.php', { method: 'POST', body: fd });
            const r = await res.json();
            toast(r.message);
            if (r.ok) setTimeout(load, 1000);
          } catch (e) { toast('連線異常,請稍後再試'); }
        });
      } else {
        $('#uploadCard').style.display = 'none';
      }
    } catch (e) {
      $('#statusCard').innerHTML = '<p style="color:#c0392b;font-size:13px">畫面載入異常:' + esc(e.message || e) + '(請重新整理)</p>';
    }
  }

  load();
})();
