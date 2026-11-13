(function () {
  'use strict';
  let lb = null;
  let lbImg = null;
  let lbCap = null;
  let zoomed = false;

  function setZoom(z) {
    zoomed = z;
    if (zoomed) {
      lbImg.style.maxWidth = 'none';
      lbImg.style.maxHeight = 'none';
      lbImg.style.width = 'auto';
      lbImg.style.height = 'auto';
      lbImg.style.touchAction = 'pan-x pan-y pinch-zoom';
      lbCap.textContent = '🔍 已放大|拖曳滑動看細節,點圖片還原';
    } else {
      lbImg.style.maxWidth = '94vw';
      lbImg.style.maxHeight = '76dvh';
      lbImg.style.width = '';
      lbImg.style.height = '';
      lbCap.textContent = '📷 點圖片可放大,點旁邊關閉';
    }
    lb.scrollTo({ top: 0, behavior: 'smooth' });
  }

  function ensure() {
    if (lb) return;
    lb = document.createElement('div');
    lb.style.cssText = 'position:fixed;inset:0;z-index:120;background:rgba(10,7,5,.92);display:none;' +
      'overflow:auto;-webkit-overflow-scrolling:touch;overscroll-behavior:contain';
    var inner = document.createElement('div');
    inner.style.cssText = 'min-height:100%;display:flex;align-items:center;justify-content:center;' +
      'flex-direction:column;gap:14px;padding:20px;box-sizing:border-box';
    lbImg = document.createElement('img');
    lbImg.alt = '檢視圖片';
    lbImg.style.cssText = 'max-width:94vw;max-height:76dvh;border-radius:14px;box-shadow:0 18px 60px rgba(0,0,0,.5);object-fit:contain;cursor:zoom-in';
    lbCap = document.createElement('div');
    lbCap.style.cssText = 'color:#F2E7D5;font-size:12.5px;opacity:.85;text-align:center;max-width:90vw;word-break:break-all;flex-shrink:0';
    inner.appendChild(lbImg);
    inner.appendChild(lbCap);
    lb.appendChild(inner);
    lb.addEventListener('click', (e) => {
      if (e.target === lbImg || lbImg.contains(e.target)) {
        e.stopPropagation();
        setZoom(!zoomed);
        return;
      }
      lb.style.display = 'none';
    });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') lb.style.display = 'none'; });
    document.body.appendChild(lb);
  }

  window.showLightbox = function (src, caption) {
    ensure();
    setZoom(false);
    lbImg.src = src;
    lbCap.textContent = caption || '📷 點圖片可放大,點旁邊關閉';
    lb.style.display = 'block';
  };
})();
