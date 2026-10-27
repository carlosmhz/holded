'use strict';
/* Service worker de MPI Holded.
 * Solo guarda los archivos estáticos (estilos, scripts, fuente, iconos) y una página sin conexión.
 * Las páginas PHP y la API van SIEMPRE a la red: llevan datos y el token de sesión. */
const VERSION = 'mpi-holded-3';
const ESTATICOS = [
  '/offline.html',
  '/assets/app.css?v=3', '/assets/app.js?v=3', '/assets/panel.js?v=3', '/assets/waylet.js?v=3',
  '/assets/fonts/archivo.woff2', '/assets/logo-tarjeta.png', '/assets/favicon.png', '/assets/icon-192.png',
];

self.addEventListener('install', (e) => {
  e.waitUntil(caches.open(VERSION).then((c) => c.addAll(ESTATICOS)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (e) => {
  e.waitUntil(caches.keys()
    .then((ks) => Promise.all(ks.filter((k) => k !== VERSION).map((k) => caches.delete(k))))
    .then(() => self.clients.claim()));
});

self.addEventListener('fetch', (e) => {
  const req = e.request;
  const url = new URL(req.url);
  if (req.method !== 'GET' || url.origin !== self.location.origin || url.pathname.startsWith('/api/')) {
    return; // red directa
  }
  if (req.mode === 'navigate') {
    e.respondWith(fetch(req).catch(() => caches.match('/offline.html')));
    return;
  }
  if (url.pathname.startsWith('/assets/')) {
    e.respondWith(caches.match(req).then((hit) => hit || fetch(req).then((res) => {
      if (res.ok) { const copia = res.clone(); caches.open(VERSION).then((c) => c.put(req, copia)); }
      return res;
    })));
  }
});
