'use strict';
/* Service worker de MPI Holded.
 * Solo guarda los archivos estáticos (estilos, scripts, fuente, iconos) y una página sin conexión.
 * Las páginas PHP y la API van SIEMPRE a la red: llevan datos y el token de sesión. */
const VERSION = 'mpi-holded-10';
const ESTATICOS = [
  '/offline.html',
  '/assets/app.css?v=8', '/assets/app.js?v=8', '/assets/panel.js?v=8', '/assets/consultas.js?v=8', '/assets/waylet.js?v=8', '/assets/tareas.js?v=8',
  '/assets/fonts/archivo.woff2', '/assets/favicon.png?v=4', '/assets/icon-192.png?v=4',
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

/* Avisos (Web Push) */
self.addEventListener('push', (e) => {
  let d = {};
  try { d = e.data ? e.data.json() : {}; } catch (x) { d = { titulo: 'MPI Holded', cuerpo: e.data ? e.data.text() : '' }; }
  e.waitUntil(self.registration.showNotification(d.titulo || 'MPI Holded', {
    body: d.cuerpo || '', icon: '/assets/icon-192.png?v=4', badge: '/assets/badge-96.png?v=1',
    tag: d.tag || undefined, data: { url: d.url || '/panel.php' },
  }));
});

self.addEventListener('notificationclick', (e) => {
  e.notification.close();
  const url = new URL((e.notification.data && e.notification.data.url) || '/panel.php', self.location.origin).href;
  e.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((ws) => {
    for (const w of ws) { if (w.url.startsWith(self.location.origin) && 'focus' in w) { w.navigate(url); return w.focus(); } }
    return self.clients.openWindow(url);
  }));
});
