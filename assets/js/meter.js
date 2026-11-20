(function () {
  'use strict';
  let meterFile = null;
  let tessPromise = null;

  function ensureInput() {
    if (!meterFile) {
      meterFile = document.createElement('input');
      meterFile.type = 'file';
      meterFile.accept = 'image/*';
      meterFile.setAttribute('capture', 'environment');
      meterFile.style.display = 'none';
      document.body.appendChild(meterFile);
    }
    return meterFile;
  }

  function loadTesseract() {
    if (window.Tesseract) return Promise.resolve();
    if (!loadTesseract.p) {
      loadTesseract.p = new Promise((resolve, reject) => {
        const s = document.createElement('script');
        s.src = 'https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/tesseract.min.js';
        s.onload = resolve;
        s.onerror = reject;
        document.head.appendChild(s);
        setTimeout(() => reject(new Error('timeout')), 12000);
      });
    }
    return loadTesseract.p;
  }

  async function ocrMeter(blob) {
    try {
      const imgUrl = await new Promise((res, rej) => {
        const r = new FileReader();
        r.onload = () => res(r.result);
        r.onerror = rej;
        r.readAsDataURL(blob);
      });
      const img = await new Promise((res, rej) => {
        const im = new Image();
        im.onload = () => res(im);
        im.onerror = rej;
        im.src = imgUrl;
      });
      const scale = Math.min(2.5, Math.max(1.5, 1200 / Math.max(img.width, img.height)));
      const cv = document.createElement('canvas');
      cv.width = Math.round(img.width * scale);
      cv.height = Math.round(img.height * scale);
      const cx = cv.getContext('2d');
      cx.filter = 'grayscale(1) contrast(1.9) brightness(1.1)';
      cx.drawImage(img, 0, 0, cv.width, cv.height);
      await loadTesseract();
      const ret = await Promise.race([
        window.Tesseract.recognize(cv.toDataURL('image/png'), 'eng', { tessedit_char_whitelist: '0123456789' }),
        new Promise((_, rej) => setTimeout(() => rej(new Error('ocr timeout')), 20000))
      ]);
      const text = String(ret && ret.data && ret.data.text ? ret.data.text : '').replace(/\D/g, '');
      if (!text) return '';
      return text.length > 7 ? text.slice(-7) : text;
    } catch (e) {
      return '';
    }
  }

  function toast(msg) {
    let t = document.getElementById('toast');
    if (!t) {
      t = document.createElement('div');
      t.id = 'toast';
      t.className = 'toast';
      document.body.appendChild(t);
    }
    t.textContent = msg;
    t.classList.add('show');
    clearTimeout(toast.timer);
    toast.timer = setTimeout(() => t.classList.remove('show'), 2600);
  }

  /**
   * window.meterPrompt(billId, onDone)
   * 拍照 → OCR 帶入度數(可修)→ 送 set_electric;無基準度數時改問金額。
   */
  window.meterPrompt = function (billId, onDone) {
    const file = ensureInput();
    const fd = new FormData();
    fd.append('action', 'set_electric');
    fd.append('bill_id', String(billId));
    file.addEventListener('change', async function h() {
      file.removeEventListener('change', h);
      const f = file.files && file.files[0];
      if (!f) return;
      let buf, mime, fname;
      try {
        buf = await f.arrayBuffer();
        mime = f.type || 'image/jpeg';
        fname = f.name || 'meter.jpg';
      } catch (err) {
        toast('讀取照片失敗,請重新拍攝');
        return;
      }
      file.value = '';
      const blob = new Blob([buf], { type: mime });
      fd.append('photo', blob, fname);
      toast('照片辨識中…');
      const ocrVal = await ocrMeter(blob).catch(() => null);
      let raw;
      if (ocrVal !== null && ocrVal !== '') {
        raw = prompt('已從照片辨識智慧電錶度數,請確認或修正:\n(辨識僅供參考,請核對智慧電錶實際數字)', String(ocrVal));
      } else {
        raw = prompt('請輸入本期智慧電錶度數(度):');
      }
      if (raw === null) return;
      const reading = Number(String(raw).replace(/\D/g, ''));
      if (isNaN(reading)) { toast('度數不正確'); return; }
      fd.append('meter_reading', String(reading));
      toast('上傳中…');
      fetch('api/bills.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
          if (d.ok) { toast(d.message); if (typeof onDone === 'function') onDone(); return; }
          if (String(d.message).indexOf('基準') !== -1) {
            const amtRaw = prompt(d.message + '\n請直接輸入本期電費金額(NT$):');
            if (amtRaw === null) return;
            const fd2 = new FormData();
            fd2.append('action', 'set_electric');
            fd2.append('bill_id', String(billId));
            fd2.append('electric_amount', String(Number(String(amtRaw).replace(/\D/g, '')) || 0));
            fd2.append('photo', blob, fname);
            toast('上傳中…');
            fetch('api/bills.php', { method: 'POST', body: fd2 })
              .then(r => r.json())
              .then(d2 => { toast(d2.message); if (d2.ok && typeof onDone === 'function') onDone(); })
              .catch(() => toast('連線異常,請稍後再試'));
            return;
          }
          toast(d.message);
        })
        .catch(() => toast('連線異常,請稍後再試'));
    });
    file.click();
  };
})();
