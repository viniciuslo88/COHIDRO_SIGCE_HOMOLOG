<?php
  // mantém seu path atual
  $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '';

  // carrega diretorias para o seletor de download (se for usar em outro lugar)
  require_once __DIR__ . '/../php/conn.php';
  $dirs = [];
  $resDirs = $conn->query("
    SELECT DISTINCT Diretoria
    FROM emop_contratos
    WHERE Diretoria IS NOT NULL
      AND Diretoria <> ''
    ORDER BY Diretoria
  ");
  if ($resDirs) {
      while ($r = $resDirs->fetch_row()) {
          $dirs[] = $r[0];
      }
      $resDirs->free();
  }
?>
<aside class="coh-sidebar p-3 d-flex flex-column" aria-label="Menu lateral" role="navigation">

  <!-- Cabeçalho da Sidebar: título + botão recolher/mostrar -->
  <div class="d-flex align-items-center justify-content-between mb-3 coh-sidebar-header">
    <div class="d-flex align-items-center">
      <i class="bi bi-grid-1x2-fill me-2"></i>
      <span class="fw-semibold coh-sidebar-title">Menu</span>
    </div>
    <button
      type="button"
      id="btnSidebarCollapse"
      class="btn btn-sm btn-light border-0 rounded-circle"
      title="Recolher / expandir menu"
    >
      <i class="bi bi-chevron-double-left"></i>
    </button>
  </div>

  <!-- BLOCO FIXO (Menu + Dashboard) -->
  <div>
    <hr class="my-2">
    <ul class="nav nav-pills flex-column gap-1 mb-3">

      <!-- Dashboard -->
      <li class="nav-item">
        <a href="/index.php"
           class="nav-link js-splash-nav <?= ($path === '/index.php' || $path === '/') ? 'active' : '' ?>">
          <i class="bi bi-speedometer2 me-2"></i>
          <span class="label-text">Dashboard</span>
        </a>
      </li>

      <!-- Atualização de Contratos -->
      <li class="nav-item">
        <a href="/form_contratos.php"
           class="nav-link js-splash-nav <?= ($path === '/form_contratos.php') ? 'active' : '' ?>">
          <i class="bi bi-pencil-square me-2"></i>
          <span class="label-text">Informações de Contratos</span>
        </a>
      </li>
    </ul>
  </div>

</aside>

<!-- Backdrop (mantido só por compatibilidade, mas não é usado pelo botão novo) -->
<div class="coh-sidebar-backdrop" id="cohSidebarBackdrop"></div>

<!-- Marca transições entre index.php e form_contratos.php para mostrar o splash -->
<script>
document.addEventListener('click', function (e) {
  var link = e.target.closest('a.js-splash-nav');
  if (!link) return;

  var href = link.getAttribute('href') || '';
  if (!href) return;

  try {
    var url  = new URL(href, window.location.origin);
    var page = url.pathname.split('/').pop(); // index.php ou form_contratos.php

    if (page === 'index.php' || page === 'form_contratos.php') {
      if (window.sessionStorage) {
        window.sessionStorage.setItem('cohSplashNext', '1');
        window.sessionStorage.setItem('cohSplashTarget', page);
      }
    }
  } catch (err) {
    // silencioso
  }
}, true);
</script>
