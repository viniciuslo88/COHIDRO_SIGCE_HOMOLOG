        </div>
      </main>
    </div> <!-- /.coh-app-main -->

        <footer class="coh-footer mt-auto text-center text-secondary small py-2" style="border-top: 1px solid var(--bs-border-color);">
          <div class="container-fluid">
            COHIDRO · Todos os direitos reservados
            <br>Desenvolvido por <a href="https://cortex360.com.br/" target="_blank" style="color: inherit; text-decoration: none;">Cortex360º</a>
          </div>
        </footer>

  </div> <!-- /.coh-app-wrapper -->


  <!-- ===== JS Global ===== -->

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
  <script src="/assets/js/coordenador_inbox_global.js?v=2025-11-07-4" defer></script>
  <script src="/assets/js/fiscal_inbox_global.js?v=2025-11-07" defer></script>
  <script src="/assets/js/mobile_sidebar.js" defer></script>

  <!-- PWA: botão de instalar (SW é registrado dentro do pwa-install.js) -->
  <script src="/assets/js/pwa-install.js"></script>

  <!-- ===== JS específico das páginas de contratos ===== -->
  <?php
    $page = basename($_SERVER['PHP_SELF']);
    if ($page === 'form_contratos.php') {
        echo '
          <script src="/assets/js/form_contratos.js"></script>
          <script src="/assets/js/form_contratos_datas.js"></script>
        ';
    }
  ?>

  <script>
    // Tema claro/escuro
    (function() {
      const html = document.documentElement;
      const btn = document.getElementById('themeToggle');
      const iconSun = '<i class="bi bi-sun"></i>';
      const iconMoon = '<i class="bi bi-moon"></i>';
      const saved = localStorage.getItem('theme-bs');
      if (saved) html.setAttribute('data-bs-theme', saved);
      const updateIcon = () => {
        const isDark = html.getAttribute('data-bs-theme') === 'dark';
        if (btn) btn.innerHTML = isDark ? iconSun : iconMoon;
      };
      updateIcon();
      if (btn) {
        btn.addEventListener('click', () => {
          const cur = html.getAttribute('data-bs-theme') || 'light';
          const next = cur === 'light' ? 'dark' : 'light';
          html.setAttribute('data-bs-theme', next);
          localStorage.setItem('theme-bs', next);
          updateIcon();
        });
      }
    })();
  </script>

  <?php $__MAX_IDLE = (int)($_SESSION['MAX_IDLE'] ?? 1800); ?>
  <script>
    // Logout automático por inatividade
    (function(){
      var MAX_IDLE_MS = <?php echo $__MAX_IDLE * 1000; ?>;
      var last = Date.now();
      function reset(){ last = Date.now(); }
      ['click','mousemove','keydown','scroll','touchstart','wheel'].forEach(function(ev){
        document.addEventListener(ev, reset, {passive:true});
      });
      setInterval(function(){
        if (Date.now() - last > MAX_IDLE_MS) {
          window.location.href = '/php/logout.php?reason=idle';
        }
      }, 15000);
    })();
  </script>

  <!-- Enter no campo 'open_id' -->
  <script>
  (function(){
    const input = document.querySelector('input[name="open_id"]');
    if (!input) return;
    input.addEventListener('keydown', function(e){
      if (e.key === 'Enter'){
        e.preventDefault();
        const v = (input.value || '').trim();
        if (v && !isNaN(v)){
          window.location.href = 'form_contratos.php?id=' + encodeURIComponent(v);
        }
      }
    });
  })();
  </script>

  <!-- ===== Fix discreto: Dropdown + Hero ===== -->
  <style>
    .navbar, .navbar * { overflow: visible !important; }
    .navbar .dropdown-menu { z-index: 2000 !important; }
    .dropdown-menu.show { display: block; }

    .hero, .coh-hero, #hero, .coh-wrap-hero {
      max-width: none !important;
      width: 100% !important;
    }
  </style>

  <!-- Inicialização leve do dropdown -->
  <script>
    document.addEventListener('DOMContentLoaded', function () {
      if (!window.bootstrap || !window.bootstrap.Dropdown) return;
      document.querySelectorAll('.navbar [data-bs-toggle="dropdown"], .navbar .dropdown-toggle')
        .forEach(function (btn) {
          try { bootstrap.Dropdown.getOrCreateInstance(btn); } catch(e){}
        });
    });
    document.addEventListener('click', function (e) {
      const btn = e.target.closest('.navbar [data-bs-toggle="dropdown"], .navbar .dropdown-toggle');
      if (!btn) return;
      try {
        const inst = bootstrap.Dropdown.getOrCreateInstance(btn);
        inst.toggle();
      } catch(e){}
    }, true);
  </script>

  <!-- Seus módulos -->
  <script type="module" src="/assets/js/coordenador_inbox.js"></script>
  <script src="/assets/js/topbar_fix.js" defer></script>

  <!-- Splash: só quando <html> tiver .coh-show-splash, com tempo mínimo visível -->
  <script>
    (function () {
      var html   = document.documentElement;
      var splash = document.getElementById('coh-splash');
      if (!splash) return;

      // Se não foi marcada navegação com splash, remove o overlay e sai
      if (!html.classList.contains('coh-show-splash')) {
        if (splash.parentNode) splash.parentNode.removeChild(splash);
        return;
      }

      var MIN_DURATION = 1200; // 1.2s
      var shownAt = Date.now();

      window.addEventListener('load', function () {
        var elapsed = Date.now() - shownAt;
        var wait = Math.max(0, MIN_DURATION - elapsed);

        setTimeout(function () {
          splash.classList.add('coh-splash-hide');
          setTimeout(function () {
            if (splash && splash.parentNode) {
              splash.parentNode.removeChild(splash);
            }
          }, 400);
        }, wait);
      });
    })();
  </script>
  </body>
</html>
