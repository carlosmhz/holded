'use strict';
(function () {
  var M = window.MPI, eur = M.eur, esc = M.esc;
  var $ = function (id) { return document.getElementById(id); };
  function api(metodo, cuerpo) { return M.api('/api/panel.php', metodo, cuerpo, '/panel.php'); }
  function fCorta(iso) { return (+iso.slice(8, 10)) + ' ' + M.MESES[+iso.slice(5, 7) - 1]; }

  function cajas(d) {
    var h = '', adelantado = 0, mon = null;
    d.cajas.forEach(function (x) {
      if (x.tipo === 'monedero') { mon = x; return; }
      var chip;
      if (x.tipo === 'factura' || (x.tipo === 'cero' && x.facturaRecibida)) {
        if (x.saldo > 0.004) { adelantado += x.saldo; chip = x.facturaRecibida ? '<span class="chip mal">Factura recibida: págala</span>' : '<span class="chip espera">Esperando factura</span>'; }
        else if (x.saldo < -0.004) { chip = '<span class="chip mal">Negativa, revisar</span>'; }
        else { chip = '<span class="chip bien">Al día</span>'; }
      } else if (x.tipo === 'cero') {
        chip = Math.abs(x.saldo) > 0.004 ? '<span class="chip mal">Debería estar a 0</span>' : '<span class="chip bien">A 0, correcto</span>';
      } else {
        chip = x.saldo < -0.004 ? '<span class="chip info">Se carga el ' + fCorta(x.cargo) + '</span>' : '<span class="chip bien">Sin deuda</span>';
      }
      h += '<div class="caja"><div class="n">' + esc(x.nombre) + '</div><div class="v' + (x.saldo < 0 ? ' neg' : '') + '">' + eur(x.saldo) + '</div>' + chip + '</div>';
    });
    $('cajas').innerHTML = (mon ? '<div class="monedero"><div><div class="n">' + esc(mon.nombre) + '</div><div class="v">' + eur(mon.saldo) + '</div></div><a href="/waylet.php">Abrir Waylet</a></div>' : '')
      + '<div class="cajas">' + h + '</div>'
      + '<div class="resumen"><span>Adelantado sin factura</span><b>' + eur(adelantado) + '</b></div>';
  }

  function pagos(d) {
    var i = d.impuestos, l = d.fijos.slice(), total = 0;
    if (i && d.plazo <= d.limite) {
      var imp = Math.max(0, i.m303) + i.m130;
      if (imp > 0) { l.push({ fecha: d.plazo, concepto: 'Modelos 303 y 130 (estimado)', importe: imp, imp: true }); }
    }
    l.sort(function (a, b) { return a.fecha < b.fecha ? -1 : a.fecha > b.fecha ? 1 : 0; });
    $('t-pagos').textContent = 'Pagos hasta el ' + M.fechaLarga(d.limite);
    var h = l.map(function (x) {
      total += x.importe;
      return '<li' + (x.imp ? ' class="imp"' : '') + '><span class="f">' + fCorta(x.fecha) + '</span><span>' + esc(x.concepto) + '</span><b>' + eur(x.importe) + '</b></li>';
    }).join('');
    $('pagos').innerHTML = (l.length ? '<ul class="pagos">' + h + '</ul><div class="tot"><span>Total</span><span>' + eur(total) + '</span></div>' : '<p class="vacio">No hay pagos previstos.</p>')
      + '<p class="nota">Banco (contable) ' + eur(d.banco) + '; tras estos pagos, ' + eur(d.banco - total) + '.'
      + (d.hojaAsientos ? '' : ' Aún no han llegado las filas de la hoja de asientos.') + '</p>';
  }

  function impuestos(d) {
    var i = d.impuestos, dias = M.diasHasta(d.domicilia, d.hoy);
    $('t-imp').textContent = 'Impuestos ' + d.trimestre.split(' ')[0];
    $('imp').innerHTML = '<div class="par"><div><small>303 IVA</small><b>' + eur(i.m303) + '</b></div><div><small>130 IRPF</small><b>' + eur(i.m130) + '</b></div>'
      + '<div><small>Total</small><b>' + eur(Math.max(0, i.m303) + i.m130) + '</b></div></div>'
      + '<p class="nota">Domiciliar hasta el ' + M.fechaLarga(d.domicilia) + (dias >= 0 ? ' (faltan ' + dias + (dias === 1 ? ' día' : ' días') + ')' : '') + '. '
      + '303: repercutido ' + eur(i.repercutido) + ' menos soportado ' + eur(i.soportado) + '. 130: 20 % de ' + eur(i.rendimiento) + ' menos ' + eur(i.pagosCuenta) + ' ya pagados. Estimación, no asesoría fiscal.</p>';
  }

  function resultado(d) {
    var i = d.impuestos, g = d.gastosCuenta || [];
    $('t-res').textContent = 'Resultado ' + d.hoy.slice(0, 4);
    var max = g.reduce(function (m, x) { return Math.max(m, x.importe); }, 0) || 1;
    $('res').innerHTML = '<div class="res"><span class="v ' + (i.resultado < 0 ? 'neg' : 'pos') + '">' + eur(i.resultado) + '</span><span>Ingresos ' + eur(i.ingresos) + '<br>Gastos ' + eur(i.gastos) + '</span></div>'
      + '<div class="barras">' + g.map(function (x) {
        return '<div class="fb"><span>' + esc(x.nombre) + '</span><b>' + eur(x.importe) + '</b><svg aria-hidden="true"><rect class="pista" width="100%" height="6" rx="3"/><rect class="val" width="' + Math.max(1.5, x.importe / max * 100).toFixed(1) + '%" height="6" rx="3"/></svg></div>';
      }).join('') + '</div><p class="nota">Hasta el fin del trimestre, con lo ya programado en Holded.</p>';
  }

  function pendientes(d) {
    var c = d.cobros, p = d.pagosDocs;
    $('pend').innerHTML = (c.length ? '<ul class="filas">' + c.map(function (x) { return '<li><span>' + esc(x.contacto) + '</span><b>' + eur(x.pendiente) + '</b></li>'; }).join('') + '</ul>' : '<p class="vacio">Nadie te debe nada.</p>')
      + (p.length ? '<p class="nota">Debes a proveedores:</p><ul class="filas">' + p.map(function (x) { return '<li><span>' + esc(x.contacto) + '</span><b>' + eur(x.pendiente) + '</b></li>'; }).join('') + '</ul>'
        : '<p class="nota">Ningún proveedor pendiente de pago.</p>');
  }

  function cambios(d) {
    var c = d.cambios || [];
    if (!c.length) { $('cambios').innerHTML = ''; return; }
    var n = { compras: 0, facturas: 0, pagos: 0, otros: 0 }, nombres = [];
    c.forEach(function (e) {
      var t = (e.tipo || '').split('.')[0];
      if (t === 'purchase' || t === 'purchaserefund') { n.compras++; } else if (t === 'invoice' || t === 'creditnote') { n.facturas++; } else if (t === 'payment') { n.pagos++; } else { n.otros++; }
      if (e.contacto && nombres.indexOf(e.contacto) < 0 && nombres.length < 3) { nombres.push(e.contacto); }
    });
    var partes = [];
    if (n.compras) { partes.push(n.compras + (n.compras === 1 ? ' compra' : ' compras')); }
    if (n.facturas) { partes.push(n.facturas + (n.facturas === 1 ? ' factura de venta' : ' facturas de venta')); }
    if (n.pagos) { partes.push(n.pagos + (n.pagos === 1 ? ' pago' : ' pagos')); }
    if (n.otros) { partes.push(n.otros + ' otros cambios'); }
    $('cambios').innerHTML = '<div class="cambios"><div><b>Hay cambios en Holded desde la foto</b>' + esc(partes.join(', '))
      + (nombres.length ? ' (' + esc(nombres.join(', ')) + ')' : '') + '.</div><button type="button" id="act2">Actualizar</button></div>';
    $('act2').addEventListener('click', function () { $('act').click(); });
  }

  function banco(d) {
    if (d.bancoPend === null || d.bancoPend === undefined) { $('banco').innerHTML = ''; return; }
    var sync = d.bancoSync ? new Date(d.bancoSync) : null;
    var txt = d.bancoPend ? '<b>' + d.bancoPend + (d.bancoPend === 1 ? ' movimiento sin conciliar' : ' movimientos sin conciliar') + '</b>' : 'Todo conciliado';
    $('banco').innerHTML = '<div class="banco"><span>Banco Santander: ' + txt
      + (sync ? '<small>Holded lo sincronizó el ' + sync.toLocaleDateString('es-ES', { day: 'numeric', month: 'short' }) + ' a las ' + sync.toLocaleTimeString('es-ES', { hour: '2-digit', minute: '2-digit' }) + '</small>' : '') + '</span>'
      + (d.bancoPend ? '<a class="chip espera" href="https://app.holded.com/" target="_blank" rel="noopener">Abrir Holded</a>' : '<span class="chip bien">Al día</span>') + '</div>';
  }

  function pinta(d) {
    var t = new Date(d.foto), hoy = new Date();
    var cuando = t.toDateString() === hoy.toDateString() ? 'hoy' : t.toLocaleDateString('es-ES', { day: 'numeric', month: 'short' });
    $('foto').textContent = 'Datos de Holded de ' + cuando + ', ' + t.toLocaleTimeString('es-ES', { hour: '2-digit', minute: '2-digit' })
      + (d.ajustes ? ' (con ' + d.ajustes + (d.ajustes === 1 ? ' operación' : ' operaciones') + ' de Waylet)' : '');
    cambios(d); banco(d); cajas(d); pagos(d); impuestos(d); resultado(d); pendientes(d);
    $('uso').textContent = 'Llamadas a Holded de esta web este mes: ' + d.uso + ' (límite de la cuenta: 7.500)';
    $('errores').innerHTML = '';
  }
  function fallo(x) { $('errores').innerHTML = '<p class="aviso">' + esc(x.message) + '</p>'; $('foto').textContent = ''; }

  $('act').addEventListener('click', function () {
    var b = $('act'); b.disabled = true; b.textContent = 'Actualizando…';
    api('POST', { accion: 'actualizar' }).then(pinta, fallo).then(function () { b.disabled = false; b.textContent = 'Actualizar'; });
  });
  api('GET').then(pinta, fallo);
})();
