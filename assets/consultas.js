'use strict';
/* Consultas de gasto por concepto y periodo (sin IA). */
(function () {
  var M = window.MPI, eur = M.eur, esc = M.esc;
  var $ = function (id) { return document.getElementById(id); };
  if (!$('consulta')) { return; }
  function api(metodo, cuerpo) { return M.api('/api/consultas.php', metodo, cuerpo, '/panel.php'); }
  function fCorta(iso) { return (+iso.slice(8, 10)) + ' ' + M.MESES[+iso.slice(5, 7) - 1] + ' ' + iso.slice(0, 4); }
  function plural(n, s, p) { return n + ' ' + (n === 1 ? s : p); }

  var atajo = 'dia11', ultima = null;

  function pintaAtajos(atajos) {
    $('c-atajos').innerHTML = atajos.map(function (a) {
      return '<button type="button" data-id="' + esc(a.id) + '" data-desde="' + esc(a.desde) + '" data-hasta="' + esc(a.hasta) + '" aria-pressed="' + (a.id === atajo) + '">' + esc(a.nombre) + '</button>';
    }).join('') + '<button type="button" data-id="otras" aria-pressed="false">Otras fechas</button>';
  }

  function periodo() {
    if (atajo === 'otras') { return { desde: $('c-desde').value, hasta: $('c-hasta').value }; }
    var b = $('c-atajos').querySelector('[data-id="' + atajo + '"]');
    return { desde: b.dataset.desde, hasta: b.dataset.hasta };
  }

  function pintaTotal(d) {
    ultima = d;
    var varias = d.lineas.length > 1;
    var h = '<div class="c-total"><div><small>' + esc(d.concepto) + '</small><span>Del ' + fCorta(d.desde) + ' al ' + fCorta(d.hasta) + '</span></div>'
      + '<b class="v">' + eur(d.total) + '</b></div>';
    h += '<ul class="filas c-lineas">' + d.lineas.map(function (l) {
      return '<li><button type="button" class="c-linea" data-cuenta="' + l.num + '" aria-expanded="false">'
        + '<span>' + esc(l.nombre) + '<small>' + l.num + ' · ver apuntes</small></span><b>' + eur(l.importe) + '</b></button>'
        + '<div class="c-det" id="det-' + l.num + '" hidden></div></li>';
    }).join('') + '</ul>';
    if (d.sinFactura.length) {
      h += '<p class="nota">Además, pagado y aún sin factura (con IVA, saldo de hoy): '
        + d.sinFactura.map(function (x) { return esc(x.nombre) + ' ' + eur(x.importe); }).join(', ') + '.</p>';
    }
    h += '<p class="nota">Importes sin IVA, según la contabilidad de Holded' + (varias ? '; el total suma todas las cuentas' : '') + '. '
      + (d.llamadas ? 'Consulta: ' + plural(d.llamadas, 'llamada', 'llamadas') + ' a Holded.' : 'Sin llamadas: ya estaba consultado con esta foto.') + '</p>';
    $('c-res').innerHTML = h;
    usoActual(d.uso);
  }

  function usoActual(n) {
    if ($('uso') && typeof n === 'number') { $('uso').textContent = 'Llamadas a Holded de esta web este mes: ' + n + ' (límite de la cuenta: 7.500)'; }
  }

  function pintaDetalle(caja, d) {
    if (!d.apuntes.length) { caja.innerHTML = '<p class="vacio">Sin apuntes en el periodo.</p>'; return; }
    caja.innerHTML = '<ul class="c-apuntes">' + d.apuntes.map(function (a) {
      return '<li><span class="f">' + (+a.fecha.slice(8, 10)) + ' ' + M.MESES[+a.fecha.slice(5, 7) - 1] + '</span><span>' + esc(a.concepto || a.doc || ('Asiento ' + a.asiento))
        + (a.doc && a.doc !== a.concepto ? '<small>' + esc(a.doc) + '</small>' : '') + '</span><b>' + eur(a.importe) + '</b></li>';
    }).join('') + '</ul><p class="nota">' + plural(d.apuntes.length, 'apunte', 'apuntes')
      + (d.llamadas ? ' · ' + plural(d.llamadas, 'llamada', 'llamadas') + ' a Holded' : ' · sin llamadas') + '</p>';
    usoActual(d.uso);
  }

  $('c-atajos').addEventListener('click', function (e) {
    var b = e.target.closest('button');
    if (!b) { return; }
    atajo = b.dataset.id;
    Array.prototype.forEach.call(this.children, function (x) { x.setAttribute('aria-pressed', String(x === b)); });
    $('c-fechas').hidden = atajo !== 'otras';
  });

  $('c-concepto').addEventListener('change', function () { $('c-cuenta-caja').hidden = this.value !== 'cuenta'; });

  $('consulta').addEventListener('submit', function (e) {
    e.preventDefault();
    var p = periodo(), boton = $('c-ir');
    if (!p.desde || !p.hasta) { $('c-res').innerHTML = '<p class="aviso">Pon las dos fechas.</p>'; return; }
    if (p.desde > p.hasta) { $('c-res').innerHTML = '<p class="aviso">La fecha de inicio es posterior a la de fin.</p>'; return; }
    boton.disabled = true; boton.textContent = 'Consultando…';
    api('POST', { accion: 'total', concepto: $('c-concepto').value, cuenta: +$('c-cuenta').value || null, desde: p.desde, hasta: p.hasta })
      .then(pintaTotal, function (x) { $('c-res').innerHTML = '<p class="aviso">' + esc(x.message) + '</p>'; })
      .then(function () { boton.disabled = false; boton.textContent = 'Consultar'; });
  });

  $('c-res').addEventListener('click', function (e) {
    var b = e.target.closest('.c-linea');
    if (!b || !ultima) { return; }
    var caja = $('det-' + b.dataset.cuenta), abierto = b.getAttribute('aria-expanded') === 'true';
    b.setAttribute('aria-expanded', String(!abierto));
    caja.hidden = abierto;
    if (abierto || caja.dataset.cargado) { return; }
    caja.innerHTML = '<p class="vacio">Cargando apuntes…</p>';
    api('POST', { accion: 'detalle', cuenta: +b.dataset.cuenta, desde: ultima.desde, hasta: ultima.hasta })
      .then(function (d) { caja.dataset.cargado = '1'; pintaDetalle(caja, d); }, function (x) { caja.innerHTML = '<p class="aviso">' + esc(x.message) + '</p>'; });
  });

  api('GET').then(function (d) {
    $('c-concepto').innerHTML = d.conceptos.map(function (c) { return '<option value="' + esc(c.id) + '">' + esc(c.nombre) + '</option>'; }).join('')
      + (d.cuentas.length ? '<option value="cuenta">Otra cuenta de gasto…</option>' : '');
    $('c-cuenta').innerHTML = d.cuentas.map(function (c) { return '<option value="' + c.num + '">' + esc(c.nombre) + ' (' + c.num + ')</option>'; }).join('');
    pintaAtajos(d.atajos);
    ['c-desde', 'c-hasta'].forEach(function (id) { $(id).min = d.min; $(id).max = d.max; });
    $('c-desde').value = d.atajos[0].desde; $('c-hasta').value = d.hoy;
  }, function (x) { $('c-res').innerHTML = '<p class="aviso">' + esc(x.message) + '</p>'; });
})();
