(function () {
  'use strict';
  let lb = null;
  let lbImg = null;
  let lbCap = null;

  function ensure() {
    if (lb) return;
    lb = document.createElement('div');
    lb.style.cssText = 'position:fixed;inset:0;z-index:120;background:rgba(10,7,5,.92);display:none;' +
      'align-items:center;justify-content:center;flex-direction:column;gap:14px;padding:20px';
    lbImg = document.createElement('img');
    lbImg.alt = '檢視圖片';
    lbImg.style.cssText = 'max-width:94vw;max-height:80dvh;border-radius:14px;box-shadow:0 18px 60px rgba(0,0,0,.5);object-fit:contain';
    lbCap = document.createElement('div');
    lbCap.style.cssText = 'color:#F2E7D5;font-size:12.5px;opacity:.85;text-align:center;max-width:90vw;word-break:break-all';
    lb.appendChild(lbImg);
    lb.appendChild(lbCap);
    lb.addEventListener('click', () => { lb.style.display = 'none'; });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') lb.style.display = 'none'; });
    document.body.appendChild(lb);
  }

  window.showLightbox = function (src, caption) {
    ensure();
    lbImg.src = src;
    lbCap.textContent = caption || '點任意處關閉';
    lb.style.display = 'flex';
  };
})();
