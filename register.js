(function () {
  'use strict';

  let checkedAuth = false;
  function bounceIfAuthed() {
    checkedAuth = true;
    fetch('api/me.php', { cache: 'no-store' }).then(r => r.json()).then(d => {
      if (d && d.ok && d.authenticated) {
        const routes = { landlord: 'dashboard.html', manager: 'dashboard_manager.html', agent: 'dashboard_agent.html', tenant: 'dashboard_tenant.html', company_owner: 'dashboard_company.html', admin_staff: 'dashboard_company.html', accountant: 'dashboard_company.html' };
        location.replace(routes[d.user.registration_role] || 'welcome.html');
      }
    }).catch(() => {});
  }
  bounceIfAuthed();
  window.addEventListener('pageshow', function (e) {
    if (e.persisted && checkedAuth) bounceIfAuthed();
  });

  const $ = (s) => document.querySelector(s);
  const RE_PHONE = /^09\d{8}$/;
  const RE_EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
  const RE_PASS = /^(?=.*[A-Za-z])(?=.*\d).{8,}$/;

  const state = { phoneOk: false, phoneFree: false, emailOk: false, passOk: false, confirmOk: false, role: '' };
  let checkTimer = null;

  function hint(id, type, text) {
    const el = $(id);
    el.textContent = text || '';
    el.classList.toggle('err', type === 'err');
    el.classList.toggle('ok', type === 'ok');
  }

  function markInput(el, ok) {
    el.classList.toggle('good', ok);
    el.classList.toggle('bad', !ok);
  }

  const nameEl = $('#name');
  const phoneEl = $('#phone');
  const emailEl = $('#email');
  const passEl = $('#password');
  const confirmEl = $('#confirm_password');

  nameEl.addEventListener('input', () => {
    const ok = nameEl.value.trim().length >= 2;
    markInput(nameEl, ok);
    hint('#hint-name', ok ? 'ok' : '', ok ? '' : '請輸入真實姓名(至少 2 字)');
  });

  phoneEl.addEventListener('input', () => {
    phoneEl.value = phoneEl.value.replace(/\D/g, '').slice(0, 10);
    state.phoneOk = false;
    state.phoneFree = false;
    if (!RE_PHONE.test(phoneEl.value)) {
      markInput(phoneEl, false);
      hint('#hint-phone', phoneEl.value ? 'err' : '', phoneEl.value ? '手機須為 09 開頭共 10 碼數字' : '');
      return;
    }
    markInput(phoneEl, true);
    hint('#hint-phone', '', '檢查中…');
    clearTimeout(checkTimer);
    checkTimer = setTimeout(async () => {
      try {
        const res = await fetch('api/check_phone.php?phone=' + phoneEl.value);
        const data = await res.json();
        if (!data.valid) { hint('#hint-phone', 'err', '手機格式不符'); return; }
        if (data.exists) { state.phoneFree = false; markInput(phoneEl, false); hint('#hint-phone', 'err', '此手機已註冊,請直接登入'); }
        else { state.phoneFree = true; state.phoneOk = true; markInput(phoneEl, true); hint('#hint-phone', 'ok', '✓ 可註冊'); }
      } catch (e) {
        hint('#hint-phone', '', '');
      }
    }, 400);
  });

  emailEl.addEventListener('input', () => {
    const v = emailEl.value.trim().toLowerCase();
    emailEl.value = v;
    state.emailOk = RE_EMAIL.test(v);
    markInput(emailEl, state.emailOk);
    hint('#hint-email', state.emailOk ? 'ok' : (v ? 'err' : ''), state.emailOk ? '✓ 格式正確' : (v ? '電子郵件格式不正確' : ''));
  });

  function checkPass() {
    state.passOk = RE_PASS.test(passEl.value);
    markInput(passEl, passEl.value ? state.passOk : false);
    hint('#hint-password', passEl.value ? (state.passOk ? 'ok' : 'err') : '', passEl.value ? (state.passOk ? '✓ 密碼格式符合' : '需 8 碼以上,含英文與數字') : '');
    if (!confirmEl.value) { state.confirmOk = false; hint('#hint-confirm', '', ''); confirmEl.classList.remove('bad', 'good'); return; }
    state.confirmOk = confirmEl.value === passEl.value;
    markInput(confirmEl, state.confirmOk);
    hint('#hint-confirm', state.confirmOk ? 'ok' : 'err', state.confirmOk ? '✓ 兩次輸入一致' : '兩次輸入的密碼不一致');
  }
  passEl.addEventListener('input', checkPass);
  confirmEl.addEventListener('input', checkPass);

  const hints = {
    landlord: '您將以「房東」身分註冊:可自行管理租務,或委託管理員/公司代管,自己保留檢視版。',
    manager: '您將以「管理員/物管」身分註冊:實際可管理範圍由公司或房東指派房屋。',
    agent: '「業務」身分需上傳身分證明與經紀營業員證明,資格審核通過後才開通業務功能。',
    tenant: '「房客」身分免費使用:由房東或公司發送邀請綁定租約後,即可查看帳單與報修。'
  };
  const roleErr = $('#roleErr');

  document.querySelectorAll('.role[data-role]').forEach(btn => {
    btn.addEventListener('click', () => {
      state.role = btn.dataset.role;
      document.querySelectorAll('.role').forEach(x => x.classList.remove('sel'));
      btn.classList.add('sel');
      roleErr.style.display = 'none';
      const h = $('#roleHint');
      h.textContent = '💡 ' + hints[state.role];
      h.classList.add('show');
    });
  });

  const mask = $('#mask');
  let currentSheet = '';
  function openSheet(id) {
    currentSheet = id;
    $('#' + id).classList.add('show');
    mask.classList.add('show');
  }
  function closeSheets() {
    document.querySelectorAll('.sheet').forEach(s => s.classList.remove('show'));
    mask.classList.remove('show');
  }
  document.querySelectorAll('.link[data-sheet]').forEach(a => {
    a.addEventListener('click', (e) => { e.preventDefault(); openSheet('sheet-' + a.dataset.sheet); });
  });
  document.querySelectorAll('[data-close]').forEach(b => b.addEventListener('click', closeSheets));
  mask.addEventListener('click', closeSheets);
  $('#agreeFromSheet').addEventListener('click', () => { $('#agree').checked = true; closeSheets(); });
  $('#agreeFromSheet2').addEventListener('click', () => { $('#agree').checked = true; closeSheets(); });

  const toast = $('#toast');
  let toastTimer;
  function showToast(msg) {
    toast.textContent = msg;
    toast.classList.add('show');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => toast.classList.remove('show'), 2200);
  }

  const form = $('#regForm');
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    if (nameEl.value.trim().length < 2) { showToast('請輸入真實姓名'); nameEl.focus(); return; }
    if (!state.phoneOk || !state.phoneFree) { showToast('請輸入正確且未註冊的手機號碼'); phoneEl.focus(); return; }
    if (!state.emailOk) { showToast('請輸入正確的電子郵件'); emailEl.focus(); return; }
    if (!state.passOk) { showToast('密碼需 8 碼以上,含英文與數字'); passEl.focus(); return; }
    if (!state.confirmOk) { showToast('兩次輸入的密碼不一致'); confirmEl.focus(); return; }
    if (!state.role) { roleErr.textContent = '請先選擇您的身份(房東/管理員/業務/房客)'; roleErr.style.display = 'block'; roleErr.scrollIntoView({ block: 'center' }); return; }
    if (!$('#agree').checked) { showToast('請先閱讀並同意《服務條款》與《隱私政策》'); return; }

    const btn = $('#submitBtn');
    btn.disabled = true;
    try {
      const res = await fetch('api/register.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          name: nameEl.value.trim(),
          phone: phoneEl.value,
          email: emailEl.value.trim(),
          password: passEl.value,
          confirm_password: confirmEl.value,
          role: state.role,
          agree: '1'
        })
      });
      const data = await res.json();
      showToast(data.message);
      if (data.ok && data.redirect) {
        setTimeout(() => location.href = data.redirect, 1200);
      } else {
        btn.disabled = false;
        if (String(data.message).includes('手機')) { hint('#hint-phone', 'err', data.message); markInput(phoneEl, false); }
        if (String(data.message).includes('郵件')) { hint('#hint-email', 'err', data.message); markInput(emailEl, false); }
      }
    } catch (err) {
      btn.disabled = false;
      showToast('連線異常,請檢查網路或伺服器設定');
    }
  });
})();
