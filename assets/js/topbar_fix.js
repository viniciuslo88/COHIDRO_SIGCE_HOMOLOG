// Strong dropdown opener for user menu in topbar
(function(){
  function closeAll(){
    document.querySelectorAll('.coh-topbar .dropdown-menu.show').forEach(function(m){
      m.classList.remove('show');
      m.style.display = '';
    });
  }

  document.addEventListener('click', function(ev){
    // If clicking toggle
    var btn = ev.target.closest('.coh-topbar .dropdown-toggle[data-bs-toggle="dropdown"]');
    if (btn){
      ev.preventDefault();
      ev.stopPropagation();
      try {
        if (window.bootstrap && bootstrap.Dropdown){
          var dd = bootstrap.Dropdown.getOrCreateInstance(btn);
          dd.toggle();
        } else {
          // fallback manual
          var menu = btn.nextElementSibling;
          if (menu && menu.classList.contains('dropdown-menu')){
            var open = menu.classList.contains('show');
            closeAll();
            if (!open){
              menu.classList.add('show');
              menu.style.display = 'block';
            }
          }
        }
      } catch(e){}
      return;
    }

    // Click outside closes
    if (!ev.target.closest('.coh-topbar .dropdown-menu')){
      closeAll();
    }
  });

  // ESC closes
  document.addEventListener('keydown', function(ev){
    if (ev.key === 'Escape'){ closeAll(); }
  });
})();
