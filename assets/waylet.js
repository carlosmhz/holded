'use strict';
(function () {
  var M = window.MPI, eur = M.eur, esc = M.esc;
  var $ = function (id) { return document.getElementById(id); };
  var T = 1, K = 'factura';
  var m = $('m'), b = $('b'), f = $('f');
  var BOTON = { 1: 'Añadir al monedero', 2: 'Quitar del monedero', 3: 'Ajustar al saldo de la app' };

  var hoy = new Date();
  f.value = new Date(hoy.getTime() - hoy.getTimezoneOffset() * 60000).toISOString().slice(0, 10);

  function api(url, metodo, cuerpo) { return M.api(url, metodo, cuerpo, '/waylet.php'); }
  function msg(cl, t) { m.className = 'res-op ' + cl; m.textContent = t; }
  function fechaFoto(iso) {
    var t = new Date(iso), h = new Date();
    return (t.toDateString() === h.toDateString() ? 'hoy' : t.toLocaleDateString('es-ES', { day: 'numeric', month: 'short' })) + ', ' + t.toLocaleTimeString('es-ES', { hour: '2-digit', minute: '2-digit' });
  }

  function carga() {
    return api('/api/waylet.php', 'GET').then(function (d) {
      $('h').textContent = eur(d.saldo);
      $('hs').textContent = 'Saldo en Holded según los datos de ' + fechaFoto(d.foto) + '. Debe coincidir con la app de Waylet.';
    }).catch(function (e) { $('h').textContent = '—'; msg('err', e.message); });
  }

  function tab(n) {
    T = n;
    [1, 2, 3].forEach(function (i) {
      $('t' + i).setAttribute('aria-selected', i === n ? 'true' : 'false');
      $('p' + i).className = 'p' + (i === n ? ' on' : '');
    });
    $('fw').hidden = n === 3;
    b.textContent = BOTON[n];
    m.className = 'res-op';
  }

  function tipo(t) {
    K = t;
    $('k1').setAttribute('aria-pressed', t === 'factura' ? 'true' : 'false');
    $('k2').setAttribute('aria-pressed', t === 'gasto' ? 'true' : 'false');
    $('cq').placeholder = t === 'factura' ? 'Repsol Ciudad Rodrigo' : 'Lavado';
  }

  function num(el) { return parseFloat(el.value.trim().replace(',', '.')); }

  function go() {
    var campo = T === 1 ? $('a') : T === 2 ? $('q') : $('s');
    var v = num(campo);
    if (isNaN(v)) { msg('err', 'Escribe un importe.'); campo.focus(); return; }
    var cuerpo = T === 1 ? { accion: 'anadir', importe: v, fecha: f.value }
      : T === 2 ? { accion: 'quitar', importe: v, tipo: K, concepto: $('cq').value, fecha: f.value }
      : { accion: 'ajustar', saldo: v };
    b.disabled = true; b.textContent = 'Guardando…'; m.className = 'res-op';
    api('/api/waylet.php', 'POST', cuerpo).then(function (d) {
      msg(d.prueba ? 'prueba' : 'ok', d.mensaje);
      if (!d.prueba) {
        $('a').value = $('q').value = $('s').value = $('cq').value = '';
        if (typeof d.saldo === 'number') { $('h').textContent = eur(d.saldo); }
      }
    }).catch(function (e) { msg('err', e.message); }).then(function () { b.disabled = false; b.textContent = BOTON[T]; });
  }

  $('vermov').addEventListener('click', function () {
    var btn = $('vermov'); btn.disabled = true; btn.textContent = 'Cargando…';
    api('/api/waylet.php?mov=1', 'GET').then(function (d) {
      var l = d.movimientos;
      $('movs').innerHTML = !l ? '<p class="vacio">No se pudieron leer los movimientos.</p>'
        : !l.length ? '<p class="vacio">Aún no hay movimientos este año.</p>'
        : '<ul class="filas">' + l.map(function (x) {
          return '<li><span>' + esc(x.concepto || 'Movimiento') + '<small>' + M.fechaLarga(x.fecha) + (x.n ? ', asiento ' + x.n : '') + '</small></span><b class="' + (x.importe < 0 ? 'neg' : 'pos') + '">' + eur(x.importe, true) + '</b></li>';
        }).join('') + '</ul>';
      btn.hidden = true;
    }).catch(function (e) { msg('err', e.message); btn.disabled = false; btn.textContent = 'Ver movimientos'; });
  });

  document.querySelectorAll('[data-tab]').forEach(function (el) { el.addEventListener('click', function () { tab(+el.dataset.tab); }); });
  document.querySelectorAll('[data-tipo]').forEach(function (el) { el.addEventListener('click', function () { tipo(el.dataset.tipo); }); });
  b.addEventListener('click', go);
  ['a', 'q', 's'].forEach(function (id) { $(id).addEventListener('keydown', function (e) { if (e.key === 'Enter') { go(); } }); });
  carga();
})();
