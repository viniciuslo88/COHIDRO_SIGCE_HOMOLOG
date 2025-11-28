// service-worker.js básico para cache de shell da aplicação
const CACHE_NAME = 'cohidro-gerenciamento-v1';

// Ajuste esta lista para os principais arquivos do app
const APP_SHELL = [
  '/',
  '/index.php',
  '/assets/css/app.css',         // ajuste para o seu CSS principal
  '/assets/css/custom.css',      // se aplicar
  '/assets/js/app.js',           // seu JS principal (se houver)
  '/assets/Logo_Cohidro_Alta.png'
];

self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(CACHE_NAME)
      .then(cache => cache.addAll(APP_SHELL))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys().then(keys => {
      return Promise.all(
        keys
          .filter(key => key !== CACHE_NAME)
          .map(key => caches.delete(key))
      );
    })
  );
  return self.clients.claim();
});

self.addEventListener('fetch', event => {
  const request = event.request;

  // Estrategia: cache-first para navegação básica
  event.respondWith(
    caches.match(request).then(response => {
      return response || fetch(request);
    })
  );
});
