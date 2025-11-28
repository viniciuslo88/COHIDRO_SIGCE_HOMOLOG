/**
 * COHIDRO — mobile_sidebar.js
 * Atualizado: 2025-10-21
 * Responsável por abrir/fechar a sidebar no mobile e lidar com backdrop.
 * Requisitos no HTML:
 * - Um botão com id "#cohSidebarToggle" ou classe ".coh-sidebar-toggle"
 * - Um <aside class="coh-sidebar"> ... </aside>
 * - (Opcional) <div class="coh-sidebar-backdrop" id="cohSidebarBackdrop"></div> logo após a sidebar
 */
document.addEventListener('DOMContentLoaded', function(){
  const btn = document.querySelector('#cohSidebarToggle') || document.querySelector('.coh-sidebar-toggle');
  const sidebar = document.querySelector('.coh-sidebar');
  const backdrop = document.querySelector('#cohSidebarBackdrop');

  if (btn && sidebar) {
    btn.addEventListener('click', function (e) {
      e.preventDefault();
      sidebar.classList.toggle('is-open');
    });
  }

  if (backdrop && sidebar) {
    backdrop.addEventListener('click', function(){
      sidebar.classList.remove('is-open');
    });
  }

  // Se você adiciona classe no <body> quando sidebar está aberta no desktop,
  // em mobile garantimos que ela não interfira.
  const mq = window.matchMedia('(max-width: 991.98px)');
  function resetBodyPad(e){
    if (e.matches) document.body.classList.remove('coh--sidebar-open');
  }
  if (mq.addEventListener) mq.addEventListener('change', resetBodyPad);
  else mq.addListener(resetBodyPad);
  resetBodyPad(mq);
});
