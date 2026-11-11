(function(){
  document.querySelectorAll('.pw-eye').forEach(function(btn){
    btn.addEventListener('click', function(){
      var input = document.getElementById(btn.getAttribute('data-for'));
      if (!input) return;
      var show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      btn.classList.toggle('off', show);
      btn.setAttribute('aria-label', show ? '隱藏密碼' : '顯示密碼');
    });
  });
})();
