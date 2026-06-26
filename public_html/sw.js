/*
 * Минимальный service worker: нужен только для «установить на экран» (PWA).
 * Ничего не кэширует — все запросы идут в сеть как обычно, поэтому сайт
 * всегда свежий (частые деплои не залипают в кэше).
 */
self.addEventListener('install', function () {
  self.skipWaiting();
});
self.addEventListener('activate', function (e) {
  e.waitUntil(self.clients.claim());
});
self.addEventListener('fetch', function () {
  // намеренно пусто — браузер обрабатывает запрос сам (сеть)
});
