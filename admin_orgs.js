/* 組織審核後台(平台方) */
const $ = (s) => document.querySelector(s);
function esc(v) {
  return String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}
function toast(msg) {
  const t = document.createElement('div');
  t.textContent = msg;
  t.style.cssText = 'position:fixed;top:64px;left:50%;transform:translateX(-50%);background:rgba(74,56,38,.92);color:#fff;padding:10px 20px;border-radius:999px;font-size:13.5px;font-weight:700;z-index:200;box-shadow:0 6px 16px rgba(0,0,0,.2)';
  document.body.appendChild(t);
  setTimeout(() => { t.style.opacity = '0'; t.style.transition = 'opacity .4s'; }, 2400);
  setTimeout(() => t.remove(), 2900);
}

let curSt = 'pending_review';
let orgs = [];
let act = null; // {org_id, action, needNote, label}

async function load() {
  $('#list').innerHTML = '<p class="hint" style="margin:8px 2px">載入中…</p>';
  try {
    const res = await fetch('api/org_admin.php?status=' + curSt, { cache: 'no-store' });
    const d = await res.json();
    if (!d.ok) { $('#list').innerHTML = '<p class="hint" style="margin:8px 2px">' + esc(d.message || '載入失敗') + '</p>'; return; }
    orgs = d.orgs || [];
    if (!orgs.length) { $('#list').innerHTML = '<p class="hint" style="margin:8px 2px">目前沒有資料</p>'; return; }
    const stTxt = { pending_review: '<span style="color:#B7791F;font-weight:800">待審核</span>', active: '<span style="color:#2F855A;font-weight:800">啟用</span>', suspended: '<span style="color:#C53030;font-weight:800">停權/駁回</span>' };
    $('#list').innerHTML = orgs.map((o) => {
      const docs = (o.documents || []).map((d) =>
        '<div style="font-size:12px;color:var(--brown2);margin:2px 0">📄 ' + (d.doc_type === 'broker_certificate' ? '經紀人證書' : esc(d.doc_type)) +
        ' — ' + ({ pending: '待審', approved: '✅已核准', rejected: '❌已駁回', needs_update: '⚠️需補件' }[d.status] || d.status) +
        (d.note ? '<br>　備註:' + esc(d.note) : '') +
        (d.file_path ? ' <a href="uploads/' + esc(d.file_path) + '" target="_blank" style="color:#8A6D3B;font-weight:700">[查看文件]</a>' : '') + '</div>'
      ).join('');
      const ops = [];
      if (o.status === 'pending_review') {
        ops.push('<button class="btn small primary" data-act="approve" data-id="' + o.id + '">✅ 核准</button>');
        ops.push('<button class="btn small ghost" data-act="needs_update" data-id="' + o.id + '">⚠️ 要求補件</button>');
        ops.push('<button class="btn small ghost" data-act="reject" data-id="' + o.id + '">❌ 駁回</button>');
      } else if (o.status === 'active') {
        ops.push('<button class="btn small ghost" data-act="suspend" data-id="' + o.id + '">⛔ 停權</button>');
      } else if (o.status === 'suspended') {
        ops.push('<button class="btn small primary" data-act="restore" data-id="' + o.id + '">♻️ 恢復啟用</button>');
      }
      return '<div style="border:1.5px solid var(--beige2);border-radius:16px;padding:12px 14px;margin:10px 2px">' +
        '<div style="display:flex;align-items:center;gap:8px"><b style="font-size:15px;color:var(--brown)">' + esc(o.name) + '</b>' + (stTxt[o.status] || '') + '</div>' +
        '<div style="font-size:12.5px;color:var(--brown2);margin:4px 0">統編 ' + esc(o.tax_id) + '|負責人:' + esc(o.owner_name) + ' ' + esc(o.owner_phone) + '|成員 ' + o.members + ' 人|房屋 ' + o.properties + ' 戶</div>' +
        (o.address ? '<div style="font-size:12px;color:var(--brown2)">📍' + esc(o.address) + '</div>' : '') +
        docs +
        '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px">' + ops.join('') + '</div></div>';
    }).join('');
  } catch (e) {
    $('#list').innerHTML = '<p class="hint" style="margin:8px 2px">連線失敗,請重試</p>';
  }
}

document.addEventListener('click', (ev) => {
  const tab = ev.target.closest('.tab');
  if (tab) {
    document.querySelectorAll('.tab').forEach((t) => t.classList.remove('on'));
    tab.classList.add('on');
    curSt = tab.dataset.st;
    load();
    return;
  }
  const btn = ev.target.closest('button[data-act]');
  if (btn) {
    const o = orgs.find((x) => x.id === Number(btn.dataset.id));
    if (!o) return;
    const labels = { approve: '核准', needs_update: '要求補件', reject: '駁回', suspend: '停權', restore: '恢復啟用' };
    act = { org_id: o.id, action: btn.dataset.act, label: labels[btn.dataset.act] };
    $('#mTitle').textContent = act.label + ' — ' + o.name;
    $('#mOrg').textContent = '負責人:' + o.owner_name + '(' + o.owner_phone + ')|統編 ' + o.tax_id;
    $('#mNote').value = '';
    $('#mask').classList.add('show');
    $('.sheet').classList.add('show');
  }
});

function closeSheet() {
  $('#mask').classList.remove('show');
  $('.sheet').classList.remove('show');
}
$('#mask').addEventListener('click', (ev) => { if (ev.target === $('#mask')) closeSheet(); });

$('#mGo').addEventListener('click', async () => {
  if (!act) return;
  const note = $('#mNote').value.trim();
  if ((act.action === 'needs_update' || act.action === 'reject') && !note) { toast('請填寫說明'); return; }
  try {
    const res = await fetch('api/org_admin.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ org_id: act.org_id, action: act.action, note }) });
    const d = await res.json();
    toast(d.message || (d.ok ? '完成' : '失敗'));
    if (d.ok) { closeSheet(); load(); }
  } catch (e) { toast('連線失敗'); }
});

load();
