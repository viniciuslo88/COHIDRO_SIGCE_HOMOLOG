<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// Lê estado salvo no cookie (0=aberta, 1=recolhida)
$isSidebarCollapsed = (isset($_COOKIE['coh_sidebar_state']) && $_COOKIE['coh_sidebar_state'] === '1');

// Página atual (para CSS específico e splash)
$currentPage = basename($_SERVER['PHP_SELF']);
?>
<!doctype html>
<html lang="pt-br" data-bs-theme="light">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Cohidro BI - SIGCE - Sistema de Informação Gerencial de Contratos EMOP</title>

    <!-- Decide cedo se o splash deve aparecer nesta navegação -->
    <script>
      (function () {
        try {
          var page = <?php echo json_encode($currentPage); ?>;
          var show = false;

          if (window.sessionStorage) {
            var next   = window.sessionStorage.getItem('cohSplashNext');
            var target = window.sessionStorage.getItem('cohSplashTarget');
            if (next === '1' && target === page) {
              show = true;
              window.sessionStorage.removeItem('cohSplashNext');
              window.sessionStorage.removeItem('cohSplashTarget');
            }
          }

          if (show) {
            document.documentElement.classList.add('coh-show-splash');
          }
        } catch (e) {}
      })();
    </script>

    <!-- PWA -->
    <link rel="manifest" href="/manifest.webmanifest">
    <meta name="theme-color" content="#004a9f">
    
    <!-- Ícone específico para iOS -->
    <link rel="apple-touch-icon" href="/assets/pwa/icon-ios-180.png">
    
    <!-- Habilita modo “app” no iOS -->
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">

    <!-- Aplica o estado colapsado o mais cedo possível -->
    <script>
      (function () {
        try {
          if (document.cookie.indexOf('coh_sidebar_state=1') !== -1) {
            document.documentElement.classList.add('sidebar-collapsed');
            document.body.classList.add('sidebar-collapsed');
          }
        } catch (e) {}
      })();
    </script>

    <!-- Favicon (navegador / desktop app) -->
    <link rel="icon" href="/assets/pwa/icon-512.png" type="image/png">
    <link rel="shortcut icon" href="/assets/pwa/icon-512.png" type="image/png">

    <!-- Bootstrap CSS -->
    <link
      href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
      rel="stylesheet"
      crossorigin="anonymous"
    />
    <!-- Bootstrap Icons -->
    <link
      href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"
      rel="stylesheet"
    />

    <!-- Seu CSS global -->
    <link rel="stylesheet" href="/assets/app.css" />

    <!-- Garantias mínimas sem alterar layout -->
    <style>
      .navbar.sticky-top, .navbar.fixed-top { z-index: 1030; }
      .navbar .dropdown-menu { z-index: 1055; }
      .coh-app-wrapper { min-height: 100vh; display: flex; flex-direction: column; }
      .coh-app-main { display: flex; align-items: stretch; width: 100%; flex: 1 1 auto; }
      .coh-content { flex: 1 1 auto; min-width: 0; }
      .coh-wrap { max-width: 1480px; margin: 0 auto; padding: 0 12px; width: 100%; }

      /* Splash: só aparece quando <html> tiver .coh-show-splash */
      .coh-splash { display: none; }
      .coh-show-splash .coh-splash { display: flex; }
    </style>
  </head>
  <body>

  <?php if (in_array($currentPage, ['index.php', 'form_contratos.php', 'ajuda_faq.php'], true)) : ?>
    <!-- Splash de abertura (apenas index.php e form_contratos.php) -->
    <div id="coh-splash" class="coh-splash">
      <div class="coh-splash-inner">
        <img src="/assets/pwa/icon-cohidro-splash.png" alt="Cohidro" class="coh-splash-logo">
        <div class="coh-splash-spinner"></div>
      </div>
    </div>
  <?php endif; ?>

  <div class="coh-app-wrapper">

    <!-- Topbar (SEU partial) -->
    <?php include __DIR__ . '/topbar.php'; ?>

    <div class="coh-app-main">
      <!-- Sidebar (já contém o <aside class="coh-sidebar"> e o backdrop) -->
      <?php include __DIR__ . '/sidebar.php'; ?>

      <!-- Script único do botão de recolher/mostrar dentro da sidebar -->
      <script>
      document.addEventListener('DOMContentLoaded', function () {
        var btn = document.getElementById('btnSidebarCollapse');
        if (!btn) return;

        function setCookie(val) {
          try {
            document.cookie = 'coh_sidebar_state=' + val + '; Path=/; Max-Age=' + (365*86400) + '; SameSite=Lax';
          } catch (e) {}
        }

        function syncIcon() {
          var html = document.documentElement;
          var collapsed = html.classList.contains('sidebar-collapsed');
          var icon = btn.querySelector('i');
          if (!icon) return;

          if (collapsed) {
            icon.classList.remove('bi-chevron-double-left');
            icon.classList.add('bi-chevron-double-right');
          } else {
            icon.classList.remove('bi-chevron-double-right');
            icon.classList.add('bi-chevron-double-left');
          }
        }

        btn.addEventListener('click', function (e) {
          e.preventDefault();
          var html = document.documentElement;
          var body = document.body;
          var willCollapse = !html.classList.contains('sidebar-collapsed');

          html.classList.toggle('sidebar-collapsed', willCollapse);
          body.classList.toggle('sidebar-collapsed', willCollapse);

          setCookie(willCollapse ? '1' : '0');
          syncIcon();
        });

        // Ajusta o ícone de acordo com o estado inicial (cookie)
        syncIcon();
      });
      </script>

      <!-- Conteúdo -->
      <main class="coh-content">
        <div class="coh-wrap">
