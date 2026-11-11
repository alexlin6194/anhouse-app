(function () {
  'use strict';
  const $ = (s) => document.querySelector(s);
  const RE_PHONE = /^09\d{8}$/;
  const RE_EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
  const RE_PASS = /^(?=.*[A-Za-z])(?=.*\d).{8,}$/;

  const state = { taxOk: false, phoneFree: false, emailOk: false, passOk: false, confirmOk: false, certName: '' };
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
  const toast = $('#toast');
  let toastTimer;
  function showToast(msg) {
    toast.textContent = msg;
    toast.classList.add('show');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => toast.classList.remove('show'), 2200);
  }

  const taxEl = $('#tax_id');
  taxEl.addEventListener('input', () => {
    taxEl.value = taxEl.value.replace(/\D/g, '').slice(0, 8);
    state.taxOk = /^\d{8}$/.test(taxEl.value);
    markInput(taxEl, state.taxOk);
    hint('#hint-tax', state.taxOk ? 'ok' : (taxEl.value ? 'err' : ''), state.taxOk ? '✓' : (taxEl.value ? '統一編號須為 8 位數字' : ''));
  });

  const phoneEl = $('#phone');
  phoneEl.addEventListener('input', () => {
    phoneEl.value = phoneEl.value.replace(/\D/g, '').slice(0, 10);
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
        if (data.exists) { state.phoneFree = false; markInput(phoneEl, false); hint('#hint-phone', 'err', '此手機已註冊,請直接登入'); }
        else { state.phoneFree = true; markInput(phoneEl, true); hint('#hint-phone', 'ok', '✓ 可註冊'); }
      } catch (e) { hint('#hint-phone', '', ''); }
    }, 400);
  });

  const emailEl = $('#email');
  emailEl.addEventListener('input', () => {
    const v = emailEl.value.trim().toLowerCase();
    emailEl.value = v;
    state.emailOk = RE_EMAIL.test(v);
    markInput(emailEl, state.emailOk);
    hint('#hint-email', state.emailOk ? 'ok' : (v ? 'err' : ''), state.emailOk ? '✓ 格式正確' : (v ? '電子郵件格式不正確' : ''));
  });

  const passEl = $('#password');
  const confirmEl = $('#confirm_password');
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

  const certFile = $('#cert_file');
  certFile.addEventListener('change', () => {
    const f = certFile.files[0];
    if (!f) return;
    state.certName = f.name;
    $('#certBox').classList.add('has');
    hint('#hint-cert', 'ok', '已選擇:' + f.name);
  });

  $('#companyForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    if ($('#company_name').value.trim().length < 2) { showToast('請輸入公司/組織名稱'); return; }
    if (!state.taxOk) { showToast('統一編號須為 8 位數字'); taxEl.focus(); return; }
    if ($('#name').value.trim().length < 2) { showToast('請輸入負責人真實姓名'); $('#name').focus(); return; }
    if (!state.phoneFree) { showToast('請輸入正確且未註冊的手機號碼'); phoneEl.focus(); return; }
    if (!state.emailOk) { showToast('請輸入正確的電子郵件'); emailEl.focus(); return; }
    if (!state.passOk) { showToast('密碼需 8 碼以上,含英文與數字'); passEl.focus(); return; }
    if (!state.confirmOk) { showToast('兩次輸入的密碼不一致'); confirmEl.focus(); return; }
    if (!$('#broker_name').value.trim() || !$('#broker_cert_no').value.trim()) { showToast('請填寫指定經紀人姓名與證書字號'); return; }
    if (!$('#agree').checked) { showToast('請先同意《服務條款》與《隱私政策》'); return; }

    const btn = $('#submitBtn');
    btn.disabled = true;
    const fd = new FormData();
    fd.append('company_name', $('#company_name').value.trim());
    fd.append('tax_id', taxEl.value);
    fd.append('company_phone', $('#company_phone').value.trim());
    fd.append('company_address', $('#company_address').value.trim());
    fd.append('name', $('#name').value.trim());
    fd.append('phone', phoneEl.value);
    fd.append('email', emailEl.value.trim());
    fd.append('password', passEl.value);
    fd.append('confirm_password', confirmEl.value);
    fd.append('broker_name', $('#broker_name').value.trim());
    fd.append('broker_cert_no', $('#broker_cert_no').value.trim());
    fd.append('agree', '1');
    if (certFile.files[0]) fd.append('cert_file', certFile.files[0]);

    try {
      const res = await fetch('api/register_company.php', { method: 'POST', body: fd });
      const data = await res.json();
      showToast(data.message);
      if (data.ok && data.redirect) {
        setTimeout(() => location.href = data.redirect, 1400);
      } else {
        btn.disabled = false;
      }
    } catch (err) {
      btn.disabled = false;
      showToast('連線異常,請檢查網路或伺服器設定');
    }
  });
})();
