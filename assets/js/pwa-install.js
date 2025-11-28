// /assets/js/pwa-install.js

(function () {
  const btn = document.getElementById('btnInstallApp');
  if (!btn) return;

  // Detecta se está rodando como app já instalado (PWA)
  const isStandalone =
    (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) ||
    (window.navigator.standalone === true); // iOS

  // Verifica flag de instalado no localStorage (via appinstalled)
  let alreadyInstalled = false;
  try {
    alreadyInstalled = localStorage.getItem('coh_pwa_installed') === '1';
  } catch (e) {
    alreadyInstalled = false;
  }

  // Se já está instalado ou rodando em standalone, nunca mostra o botão
  if (isStandalone || alreadyInstalled) {
    btn.style.display = 'none';
    return;
  }

  let deferredPrompt = null;

  // Quando o app for instalado (Android / Chrome / Edge)
  window.addEventListener('appinstalled', () => {
    try {
      localStorage.setItem('coh_pwa_installed', '1');
    } catch (e) {}
    deferredPrompt = null;
    btn.style.display = 'none';
  });

  // Android / navegadores que suportam beforeinstallprompt
  window.addEventListener('beforeinstallprompt', (event) => {
    console.log('[PWA] beforeinstallprompt disparado');
    event.preventDefault();
    deferredPrompt = event;

    btn.style.display = 'inline-flex';
    btn.disabled = false;

    btn.addEventListener('click', async () => {
      console.log('[PWA] Botão instalar clicado');
      btn.disabled = true;

      if (!deferredPrompt) {
        alert('Seu navegador não disponibilizou a instalação automática. Use "Adicionar à tela inicial" do navegador.');
        btn.disabled = false;
        return;
      }

      deferredPrompt.prompt();
      const choiceResult = await deferredPrompt.userChoice;
      console.log('[PWA] Resultado da escolha:', choiceResult.outcome);

      deferredPrompt = null;

      if (choiceResult.outcome === 'accepted') {
        // Esconde o botão se o usuário aceitou
        btn.style.display = 'none';
      } else {
        btn.disabled = false;
      }
    }, { once: true });
  });

  // Fallback para iOS e navegadores sem beforeinstallprompt
  window.addEventListener('load', () => {
    const isMobile = /Mobi|Android|iPhone|iPad|iPod/i.test(navigator.userAgent);

    // Se já tem fluxo normal (deferredPrompt), não faz fallback
    if (deferredPrompt || !isMobile) return;

    // Se já está instalado ou standalone, não mostra
    if (alreadyInstalled || isStandalone) {
      btn.style.display = 'none';
      return;
    }

    // Mostra botão com instruções
    btn.style.display = 'inline-flex';

    const isiOS = /iPhone|iPad|iPod/i.test(navigator.userAgent);

    btn.addEventListener('click', () => {
      if (isiOS) {
        alert(
          'Em iPhone/iPad, a instalação é feita pelo Safari:\n\n' +
          '1. Abra este site no Safari\n' +
          '2. Toque no ícone de compartilhar (quadrado com seta para cima)\n' +
          '3. Escolha "Adicionar à Tela de Início"\n' +
          '4. Abra pelo novo ícone que aparecer na tela inicial.'
        );
      } else {
        alert(
          'Se o navegador não mostrar o prompt automático, use a opção "Adicionar à tela inicial" no menu do navegador.'
        );
      }
    }, { once: true });
  });

})();

// Registro do Service Worker (funciona igual em Android e iOS)
if ('serviceWorker' in navigator) {
  window.addEventListener('load', function () {
    navigator.serviceWorker.register('/service-worker.js')
      .then(() => console.log('[PWA] Service worker registrado'))
      .catch(function (err) {
        console.error('SW registration failed:', err);
      });
  });
}
