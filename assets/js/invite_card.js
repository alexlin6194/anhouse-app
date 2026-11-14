/* 公司邀請卡(通用:房客/管理員等首頁) */
(async function () {
  'use strict';
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  }
  function toast(msg) {
    if (window.toast) return window.toast(msg);
    const t = document.createElement('div');
    t.textContent = msg;
    t.style.cssText = 'position:fixed;top:64px;left:50%;transform:translateX(-50%);background:rgba(74,56,38,.92);color:#fff;padding:10px 20px;border-radius:999px;font-size:13.5px;font-weight:700;z-index:200';
    document.body.appendChild(t);
    setTimeout(() => t.remove(), 2600);
  }
  try {
    const res = await fetch('api/my_invites.php', { cache: 'no-store' });
    const d = await res.json();
    if (!d.ok || !d.invites || !d.invites.length) return;
    const box = document.getElementById('invites');
    if (!box) return;
    const list = d.invites;
    box.innerHTML = '<div class="card" style="border:2px solid var(--wood)"><div class="row"><div class="ico">💼</div>' +
      '<div><span class="tagline">組織邀請 ' + list.length + ' 筆待回覆</span>' +
      list.map((iv) => '<div style="margin:6px 0"><b>' + esc(iv.org_name) + '</b><span>職務:' + esc(iv.roles_text) + '|邀請人:' + esc(iv.inviter_name) + '|效期 ' + esc((iv.expires_at || '').slice(0, 10)) + '</span></div>').join('') +
      '</div><span class="st warn">待回覆</span></div>' +
      '<div style="display:flex;gap:10px;margin-top:10px">' +
      list.map((iv) => '<button type="button" class="mini-btn act-inv" data-id="' + iv.id + '" data-act="accept" style="flex:1">✅ 接受 ' + esc(iv.org_name) + '</button><button type="button" class="mini-btn line act-inv" data-id="' + iv.id + '" data-act="decline" style="flex:1">婉拒</button>').join('') +
      '</div></div>';
    box.querySelectorAll('.act-inv').forEach(b => b.addEventListener('click', async () => {
      b.disabled = true;
      try {
        const res = await fetch('api/respond_invite.php', {
          method: 'POST', headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ invitation_id: Number(b.dataset.id), action: b.dataset.act })
        });
        const r = await res.json();
        toast(r.message + (b.dataset.act === 'accept' ? ';身份已更新,請重新登入進入您的新後台' : ''));
        if (r.ok) setTimeout(() => location.reload(), 1800); else b.disabled = false;
      } catch (e) { toast('連線異常'); b.disabled = false; }
    }));
  } catch (e) { /* 靜默:無邀請 */ }
})();
