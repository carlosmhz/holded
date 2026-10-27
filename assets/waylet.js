'use strict';
(function () {
  var $ = function (id) { return document.getElementById(id); };
  var csrf = document.querySelector('meta[name="csrf"]').content;
  var T = 1, K = 'factura';
  var h = $('h'), m = $('m'), b = $('b'), f = $('f');

  var hoy = new Date();
  f.value = new Date(hoy.getTime() - hoy.getTimezoneOffset() * 60000).toISOString().slice(0, 10);

  function eur(v) {
    return (v < 0 ? '−' : '') + Math.abs(v).toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, '.') + ' €';
  }
  function msg(cl, t) { m.className = cl; m.textContent = t; }

  function api(metodo, cuerpo) {
    var op = { method: metodo, credentials: 'same-origin', headers: { 'Accept': 'application/json' } };
    if (cuerpo) {
      op.headers['Content-Type'] = 'application/json';
      op.headers['X-CSRF-Token'] = csrf;
      op.body = JSON.stringify(cuerpo);
    }
    return fetch('/api/waylet.php', op).then(function (r) {
      return r.json().catch(function () { return { error: 'Respuesta no válida (' + r.status + ')' }; }).then(function (d) {
        if (r.status === 401 && d.login) { location.href = '/login.php?next=' + encodeURIComponent('/waylet.php'); }
        if (!r.ok || d.error) { throw new Error(d.error || ('Error ' + r.status)); }
        return d;
      });
    });
  }

  function carga() {
    api('GET').then(function (d) { h.textContent = eur(d.saldo); }).catch(function () { h.textContent = 'error'; });
  }

  function tab(n) {
    T = n;
    [1, 2, 3].forEach(function (i) {
      $('t' + i).className = i === n ? 'on' : '';
      $('p' + i).className = 'p' + (i === n ? ' on' : '');
    });
    $('fw').style.display = n === 3 ? 'none' : 'block';
    msg('', '');
  }

  function tipo(t) {
    K = t;
    $('k1').className = t === 'factura' ? 'on' : '';
    $('k2').className = t === 'gasto' ? 'on' : '';
    $('hk').textContent = t === 'factura'
      ? 'Va a la caja Pagos Waylet. Cuando llegue la factura, págala desde esa caja.'
      : 'Va a la 550 (uso personal): lavados y otros gastos sin factura.';
    $('cq').placeholder = t === 'factura' ? 'Ej.: Repsol Ciudad Rodrigo' : 'Ej.: Lavado';
  }

  function num(el) { return parseFloat(el.value.trim().replace(',', '.')); }

  function go() {
    var campo = T === 1 ? $('a') : T === 2 ? $('q') : $('s');
    var v = num(campo);
    if (isNaN(v)) { msg('e', 'Escribe un importe'); return; }
    var cuerpo = T === 1 ? { accion: 'anadir', importe: v, fecha: f.value }
      : T === 2 ? { accion: 'quitar', importe: v, tipo: K, concepto: $('cq').value, fecha: f.value }
      : { accion: 'ajustar', saldo: v };
    b.disabled = true; msg('', 'Guardando…');
    api('POST', cuerpo).then(function (d) {
      msg(d.prueba ? 'w' : 'k', d.mensaje);
      if (!d.prueba) { $('a').value = $('q').value = $('s').value = $('cq').value = ''; }
      if (typeof d.saldo === 'number' && !d.prueba) { h.textContent = eur(d.saldo); } else { carga(); }
    }).catch(function (e) { msg('e', e.message); }).then(function () { b.disabled = false; });
  }

  document.querySelectorAll('[data-tab]').forEach(function (el) {
    el.addEventListener('click', function () { tab(+el.dataset.tab); });
  });
  document.querySelectorAll('[data-tipo]').forEach(function (el) {
    el.addEventListener('click', function () { tipo(el.dataset.tipo); });
  });
  b.addEventListener('click', go);
  carga();
})();
