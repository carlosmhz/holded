'use strict';
(function () {
  var M = window.MPI, eur = M.eur, esc = M.esc;
  var $ = function (id) { return document.getElementById(id); };
  var T = 1, K = 'factura';
  var m = $('m'), b = $('b'), f = $('f');
  var BOTON = { 1: 'Añadir al monedero', 2: 'Quitar del monedero', 3: 'Ajustar al saldo de la app' };

  var hoy = new Date();
  f.value = new Date(hoy.getTime() - hoy.getTimezoneOffset() * 60000).toISOString().slice(0, 10);

  function api(metodo, cuerpo) { return M.api('/api/waylet.php' + (metodo === 'GET' ? '?mov=1' : ''), metodo, cuerpo, '/waylet.php'); }
  function msg(cl, t) { m.className = 'resultado-op ' + cl; m.textContent = t; }

  function pintaMovs(l) {
    var ul = $('movs');
    if (l === null || l === undefined) { ul.innerHTML = '<li><span class="vacio">No se pudieron leer los movimientos.</span></li>'; return; }
    if (!l.length) { ul.innerHTML = '<li><span class="vacio">Aún no hay movimientos este año.</span></li>'; $('ultimo').hidden = true; return; }
    ul.innerHTML = l.map(function (x) {
      return '<li><span>' + esc(x.concepto || 'Movimiento') + '<small>' + M.fechaLarga(x.fecha) + (x.n ? ', asiento ' + x.n : '') + '</small></span><b class="' + (x.importe < 0 ? 'neg' : 'pos') + '">' + eur(x.importe, true) + '</b></li>';
    }).join('');
    var u = l[0];
    $('ult-txt').textContent = 'Último movimiento, ' + M.fechaLarga(u.fecha);
    $('ult-v').textContent = eur(u.importe, true);
    $('ultimo').hidden = false;
  }

  function carga() {
    return api('GET').then(function (d) { $('h').textContent = eur(d.saldo); pintaMovs(d.movimientos); })
      .catch(function (e) { $('h').textContent = '—'; msg('err', e.message); });
  }

  function tab(n) {
    T = n;
    [1, 2, 3].forEach(function (i) {
      $('t' + i).setAttribute('aria-selected', i === n ? 'true' : 'false');
      $('p' + i).className = 'p' + (i === n ? ' on' : '');
    });
    $('fw').hidden = n === 3;
    b.textContent = BOTON[n];
    m.className = 'resultado-op';
    ($('a q s'.split(' ')[n - 1])).focus();
  }

  function tipo(t) {
    K = t;
    $('k1').setAttribute('aria-pressed', t === 'factura' ? 'true' : 'false');
    $('k2').setAttribute('aria-pressed', t === 'gasto' ? 'true' : 'false');
    $('hk').textContent = t === 'factura'
      ? 'Cuando llegue la factura, págala desde la caja Pagos Waylet.'
      : 'Uso personal (cuenta 550): no espera factura.';
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
    b.disabled = true; msg('', ''); b.textContent = 'Guardando…';
    api('POST', cuerpo).then(function (d) {
      msg(d.prueba ? 'prueba' : 'ok', d.mensaje);
      if (!d.prueba) { $('a').value = $('q').value = $('s').value = $('cq').value = ''; }
      return carga();
    }).catch(function (e) { msg('err', e.message); }).then(function () { b.disabled = false; b.textContent = BOTON[T]; });
  }

  document.querySelectorAll('[data-tab]').forEach(function (el) { el.addEventListener('click', function () { tab(+el.dataset.tab); }); });
  document.querySelectorAll('[data-tipo]').forEach(function (el) { el.addEventListener('click', function () { tipo(el.dataset.tipo); }); });
  b.addEventListener('click', go);
  ['a', 'q', 's'].forEach(function (id) { $(id).addEventListener('keydown', function (e) { if (e.key === 'Enter') { go(); } }); });
  carga();
})();
