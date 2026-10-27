'use strict';
(function () {
  var M = window.MPI, esc = M.esc;
  var $ = function (id) { return document.getElementById(id); };
  function api(url, metodo, cuerpo) { return M.api(url, metodo, cuerpo, '/tareas.php'); }

  /* ── Tareas ── */
  function pinta(d) {
    var hechas = d.tareas.filter(function (t) { return t.hecha; }).length;
    $('t-tareas').textContent = 'Tareas de ' + d.nombre.toLowerCase();
    $('prog').setAttribute('width', (d.tareas.length ? hechas / d.tareas.length * 100 : 0).toFixed(0) + '%');
    $('lista').innerHTML = d.tareas.map(function (t) {
      return '<li' + (t.hecha ? ' class="hecha"' : '') + '><label><input type="checkbox" data-id="' + esc(t.id) + '"' + (t.hecha ? ' checked' : '') + '>'
        + '<span class="t">' + esc(t.titulo) + '<small>' + esc(t.ayuda) + '</small></span></label></li>';
    }).join('');
    $('reinicio').textContent = (hechas === d.tareas.length ? 'Todo hecho. ' : 'Hechas ' + hechas + ' de ' + d.tareas.length + '. ')
      + 'La lista se reinicia el ' + M.fechaLarga(d.reinicio) + '. Google Play no está porque sus facturas llegan sueltas.';
  }
  $('lista').addEventListener('change', function (e) {
    var c = e.target;
    if (!c.dataset.id) { return; }
    c.disabled = true;
    api('/api/tareas.php', 'POST', { id: c.dataset.id, hecha: c.checked }).then(pinta, function (x) { c.checked = !c.checked; c.disabled = false; alerta(x.message); });
  });
  function alerta(t) { $('estado-avisos').textContent = t; }
  api('/api/tareas.php', 'GET').then(pinta, function (x) { $('lista').innerHTML = '<li><span class="vacio">' + esc(x.message) + '</span></li>'; });

  /* ── Avisos (Web Push) ── */
  var cfg = null, sub = null;
  var soporta = 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
  function clave(b64) {
    var p = '='.repeat((4 - b64.length % 4) % 4), s = atob((b64 + p).replace(/-/g, '+').replace(/_/g, '/'));
    return Uint8Array.from(s, function (c) { return c.charCodeAt(0); });
  }
  function estado() {
    var activo = !!(sub && cfg && cfg.dispositivos.indexOf(sub.endpoint) >= 0);
    $('activar').hidden = activo || !soporta;
    $('probar').hidden = $('desactivar').hidden = !activo;
    $('estado-avisos').textContent = !soporta ? 'Este navegador no admite avisos. En Android, usa Chrome.'
      : Notification.permission === 'denied' ? 'Chrome tiene bloqueados los avisos de esta web: actívalos en los ajustes del sitio.'
      : activo ? 'Activados en este móvil.' : 'Desactivados en este móvil.';
    $('tipos').innerHTML = cfg.tipos.map(function (t) {
      return '<label class="aviso-l"><span>' + esc(t.texto) + '</span><input type="checkbox" class="sw" data-tipo="' + t.id + '"' + (t.on ? ' checked' : '') + '></label>';
    }).join('');
  }
  function cargaAvisos() {
    return api('/api/avisos.php', 'GET').then(function (c) {
      cfg = c;
      if (!soporta) { estado(); return; }
      return navigator.serviceWorker.ready.then(function (r) { return r.pushManager.getSubscription(); }).then(function (s) { sub = s; estado(); });
    }, function (x) { alerta(x.message); });
  }
  $('tipos').addEventListener('change', function (e) {
    var c = e.target;
    if (!c.dataset.tipo) { return; }
    api('/api/avisos.php', 'POST', { accion: 'pref', tipo: c.dataset.tipo, on: c.checked }).then(function (x) { cfg = x; estado(); }, function (x) { c.checked = !c.checked; alerta(x.message); });
  });
  $('activar').addEventListener('click', function () {
    var b = $('activar'); b.disabled = true;
    Notification.requestPermission().then(function (p) {
      if (p !== 'granted') { throw new Error('No has permitido los avisos. Puedes cambiarlo en los ajustes del sitio en Chrome.'); }
      return navigator.serviceWorker.ready;
    }).then(function (r) {
      return r.pushManager.getSubscription().then(function (s) { return s || r.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: clave(cfg.publica) }); });
    }).then(function (s) {
      sub = s;
      return api('/api/avisos.php', 'POST', { accion: 'suscribir', sub: s.toJSON() });
    }).then(function (c) { cfg = c; estado(); }).catch(function (x) { alerta(x.message); }).then(function () { b.disabled = false; });
  });
  $('probar').addEventListener('click', function () {
    api('/api/avisos.php', 'POST', { accion: 'probar' }).then(function (c) {
      cfg = c; estado(); alerta(c.enviados ? 'Aviso de prueba enviado. Debería llegarte en unos segundos.' : 'No se pudo enviar el aviso de prueba.');
    }, function (x) { alerta(x.message); });
  });
  $('desactivar').addEventListener('click', function () {
    var ep = sub && sub.endpoint;
    (sub ? sub.unsubscribe() : Promise.resolve()).then(function () { return api('/api/avisos.php', 'POST', { accion: 'baja', endpoint: ep || '' }); })
      .then(function (c) { cfg = c; sub = null; estado(); }, function (x) { alerta(x.message); });
  });
  cargaAvisos();
})();
