(function () {
  'use strict';
  let lastId = 0;
  let lastMsgId = 0;
  let baseline = false;
  let timer = null;
  let tip = null;
  let tipTimer = null;

  function ensureTip() {
    if (tip) return tip;
    tip = document.createElement('div');
    tip.style.cssText = 'position:fixed;left:50%;bottom:calc(96px + env(safe-area-inset-bottom));transform:translateX(-50%) translateY(12px);' +
      'background:rgba(12,9,6,.94);color:#FFF7E8;border:1px solid rgba(234,209,160,.25);padding:11px 18px;border-radius:16px;' +
      'font-size:13px;line-height:1.6;max-width:92vw;z-index:98;opacity:0;transition:.3s;pointer-events:none;box-shadow:0 10px 26px rgba(0,0,0,.35);font-family:inherit';
    document.body.appendChild(tip);
    return tip;
  }

  function popTip(title, body) {
    const t = ensureTip();
    t.innerHTML = '<b>🔔 ' + title + '</b>' + (body ? '<br><span style="opacity:.85">' + body + '</span>' : '');
    t.style.opacity = '1';
    t.style.transform = 'translateX(-50%) translateY(0)';
    clearTimeout(tipTimer);
    tipTimer = setTimeout(() => {
      t.style.opacity = '0';
      t.style.transform = 'translateX(-50%) translateY(12px)';
    }, 4200);
  }

  function badge(el, count) {
    if (!el) return;
    el.style.position = 'relative';
    let dot = el.querySelector('.nbadge');
    if (!count) {
      if (dot) dot.remove();
      return;
    }
    if (!dot) {
      dot = document.createElement('span');
      dot.className = 'nbadge';
      el.appendChild(dot);
    }
    dot.textContent = count > 99 ? '99+' : String(count);
  }

  function updateBadges(d) {
    badge(document.querySelector('[data-bt="ntf"]'), d.unread_notifs);
    badge(document.querySelector('[data-bt="msg"]'), d.unread_msgs);
  }

  async function tick() {
    let d;
    try {
      const res = await fetch('api/poll.php?last_notif_id=' + lastId + '&last_msg_id=' + lastMsgId, { cache: 'no-store' });
      d = await res.json();
    } catch (e) {
      return;
    }
    if (!d.ok || !d.authenticated) return;
    if (!baseline) {
      lastId = d.last_notif_id;
      lastMsgId = d.last_msg_id;
      baseline = true;
      updateBadges(d);
      return;
    }
    lastId = d.last_notif_id;
    lastMsgId = d.last_msg_id;
    if (d.new_msgs && d.new_msgs.length) {
      const m = d.new_msgs[d.new_msgs.length - 1];
      popTip('💬 ' + (m.sender_name || '新訊息'), String(m.body || '').slice(0, 60));
    } else if (d.new_notifs && d.new_notifs.length) {
      const n = d.new_notifs[d.new_notifs.length - 1];
      popTip(n.title || '新通知', n.body || '');
    }
    updateBadges(d);
  }

  function start() {
    if (timer) return;
    tick();
    timer = setInterval(tick, 10000);
    document.addEventListener('visibilitychange', () => {
      if (!document.hidden) tick();
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }
})();
