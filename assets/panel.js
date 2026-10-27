'use strict';
(function () {
  var M = window.MPI, eur = M.eur, esc = M.esc;
  var $ = function (id) { return document.getElementById(id); };
  var CAJAS_A_CERO = { 57000004: 'Debería estar a 0', 55500000: 'Debería estar a 0' };
  var NOMBRES = {
    57200001: 'Banco', 52000003: 'Tarjeta de crédito', 40700001: 'Monedero Waylet', 57000004: 'Pagos Waylet',
    57000003: 'Pagos Ballenoil', 57000001: 'Pagos ElParking', 57000005: 'Pagos Telpark', 55500000: 'Partidas 555'
  };

  function api(url, metodo, cuerpo) { return M.api(url, metodo, cuerpo, '/panel.php'); }

  /* ── Tarjeta: banco y previsión al día 11 ── */
  function pintaBanco(d, totalPagos) {
    var b = (d.saldos || []).filter(function (x) { return x.num === 57200001; })[0];
    if (!b) { $('banco').textContent = '—'; $('banco-nota').textContent = 'No se pudo leer el saldo del banco.'; return; }
    $('banco').textContent = eur(b.saldo);
    $('banco-nota').textContent = b.nota ? b.nota.replace('Contable', 'Saldo contable').replace(' · diferencia ', ', diferencia ') : 'Saldo contable';
    var queda = b.saldo - totalPagos;
    $('prev-txt').textContent = 'El ' + M.fechaLarga(d.limite) + ', después de los pagos';
    $('prev-v').textContent = eur(queda);
    $('prev-v').className = queda < 0 ? 'neg' : '';
    $('prevision').hidden = false;
  }

  /* ── Línea de tiempo de pagos con saldo restante ── */
  function pintaPagos(d) {
    var i = d.impuestos, lista = (d.fijos || []).slice();
    if (i && d.plazo <= d.limite) {
      var imp = Math.max(0, i.m303) + i.m130;
      if (imp > 0) { lista.push({ fecha: d.plazo, concepto: 'Modelos 303 y 130 (estimado)', importe: imp, imp: true }); }
    }
    lista.sort(function (a, b) { return a.fecha < b.fecha ? -1 : a.fecha > b.fecha ? 1 : 0; });
    var b = (d.saldos || []).filter(function (x) { return x.num === 57200001; })[0];
    var saldo = b ? b.saldo : null, total = 0, h = '';
    lista.forEach(function (x) {
      total += x.importe;
      if (saldo !== null) { saldo -= x.importe; }
      h += '<li' + (x.imp ? ' class="imp"' : '') + '><div class="dia"><b>' + (+x.fecha.slice(8, 10)) + '</b><small>' + M.MESES[+x.fecha.slice(5, 7) - 1] + '</small></div>'
        + '<div class="que">' + esc(x.concepto) + '</div>'
        + '<div class="cuanto">' + eur(x.importe) + (saldo !== null ? '<small class="' + (saldo < 0 ? 'neg' : '') + '">quedan ' + eur(saldo) + '</small>' : '') + '</div></li>';
    });
    $('pagos-bajo').textContent = 'Hasta el ' + M.fechaLarga(d.limite) + ': cargos ya programados en Holded, pagos fijos y la hoja de asientos.';
    $('pagos').innerHTML = lista.length
      ? '<ol class="linea-t">' + h + '</ol><div class="total-t"><span>Total</span><span>' + eur(total) + '</span></div>'
      : '<p class="vacio">No hay pagos previstos hasta el ' + M.fechaLarga(d.limite) + '.</p>';
    return total;
  }

  /* ── Impuestos ── */
  function pintaImpuestos(d) {
    var i = d.impuestos;
    if (!i) { $('imp').innerHTML = '<p class="vacio">No se pudieron calcular.</p>'; return; }
    var total = Math.max(0, i.m303) + i.m130, dias = M.diasHasta(d.domicilia, d.hoy);
    $('imp-bajo').textContent = 'Estimación del ' + d.trimestre + ' con los asientos de Holded.';
    $('imp').innerHTML =
      '<div class="par">'
      + '<div class="mod"><div class="n">Modelo 303 (IVA)</div><div class="v">' + eur(i.m303) + '</div><div class="c">Repercutido ' + eur(i.repercutido) + ' menos soportado ' + eur(i.soportado) + '</div></div>'
      + '<div class="mod"><div class="n">Modelo 130 (IRPF)</div><div class="v">' + eur(i.m130) + '</div><div class="c">20 % de ' + eur(i.rendimiento) + ' menos ' + eur(i.pagosCuenta) + ' ya pagados</div></div>'
      + '</div>'
      + '<div class="plazo"><div class="dias"><div><b>' + Math.max(0, dias) + '</b><small>' + (dias === 1 ? 'día' : 'días') + '</small></div></div>'
      + '<p>Total estimado <b>' + eur(total) + '</b>.<br>Domiciliar hasta el <b>' + M.fechaLarga(d.domicilia) + '</b>; el plazo acaba el ' + M.fechaLarga(d.plazo) + '.</p></div>';
  }

  /* ── Resultado ── */
  function pintaResultado(d) {
    var i = d.impuestos;
    if (!i) { $('res').innerHTML = ''; return; }
    $('t-res').textContent = 'Resultado de ' + d.hoy.slice(0, 4);
    $('res').innerHTML = '<div class="resultado"><span class="v ' + (i.resultado < 0 ? 'neg' : 'pos') + '">' + eur(i.resultado) + '</span>'
      + '<span class="d">Ingresos ' + eur(i.ingresos) + ', gastos ' + eur(i.gastos) + (i.noDeducibles ? ' (' + eur(i.noDeducibles) + ' no deducibles)' : '') + '</span></div>';
    var g = d.gastosCuenta || [];
    if (!g.length) { $('reparto').innerHTML = ''; return; }
    var max = g.reduce(function (m, x) { return Math.max(m, x.importe); }, 0) || 1, h = '<h3>En qué se van los gastos</h3>';
    g.forEach(function (x) {
      var w = Math.max(1.5, x.importe / max * 100).toFixed(1);
      h += '<div class="fila-b"><span>' + esc(x.nombre) + '</span><b>' + eur(x.importe) + '</b>'
        + '<svg aria-hidden="true"><rect class="pista" x="0" y="0" width="100%" height="7" rx="3.5"/><rect class="val" x="0" y="0" width="' + w + '%" height="7" rx="3.5"/></svg></div>';
    });
    $('reparto').innerHTML = h;
  }

  /* ── Cajas ── */
  function pintaCajas(d) {
    var h = '';
    (d.saldos || []).forEach(function (x) {
      if (x.num === 57200001) { return; }
      var mal = CAJAS_A_CERO[x.num] && Math.abs(x.saldo) > 0.004;
      var nota = x.num === 52000003 ? (x.saldo < 0 ? 'Deuda de la tarjeta' : 'Sin deuda')
        : mal ? CAJAS_A_CERO[x.num] : CAJAS_A_CERO[x.num] ? 'Correcto, a 0' : x.num === 40700001 ? 'Saldo de la app' : x.saldo > 0.004 ? 'Esperando factura' : 'Al día';
      h += '<div class="caja' + (mal ? ' mal' : '') + '"><span>' + esc(NOMBRES[x.num] || x.nombre) + '</span><b class="' + (x.saldo < 0 ? 'neg' : '') + '">' + eur(x.saldo) + '</b><small>' + nota + '</small></div>';
    });
    $('cajas').innerHTML = h ? '<div class="cajas">' + h + '</div>' : '<p class="vacio">No se pudieron leer.</p>';
  }

  /* ── Pendientes ── */
  function pintaPendientes(d) {
    var c = d.cobros || [], p = d.pagosDocs || [], h = '';
    var suma = function (l) { return l.reduce(function (s, x) { return s + x.pendiente; }, 0); };
    h += '<ul class="filas"><li><span><b>Te deben</b></span><b class="pos">' + eur(suma(c)) + '</b></li>';
    c.forEach(function (x) { h += '<li><span>' + esc(x.contacto) + '</span><b>' + eur(x.pendiente) + '</b></li>'; });
    h += '</ul><ul class="filas"><li><span><b>Debes</b></span><b>' + eur(suma(p)) + '</b></li>';
    p.forEach(function (x) { h += '<li><span>' + esc(x.contacto) + '</span><b>' + eur(x.pendiente) + '</b></li>'; });
    if (!p.length) { h += '<li><span class="vacio">Ningún proveedor pendiente</span></li>'; }
    h += '</ul>';
    $('pend').innerHTML = h;
  }

  /* ── Gráfico mensual: barras agrupadas ingresos / gastos ── */
  function pintaMeses(r) {
    var meses = r.meses || [], cont = $('meses');
    if (!meses.length) { cont.innerHTML = '<p class="vacio">Sin datos mensuales.</p>'; return; }
    ultimo = r;
    var W = Math.max(280, Math.round(cont.clientWidth || 600)), H = W < 500 ? 190 : 230, L = 38, B = 22, T = 8, iw = W - L - 4, ih = H - B - T;
    var max = meses.reduce(function (m, x) { return Math.max(m, x.ing, x.gas); }, 0) || 1;
    var paso = Math.pow(10, Math.floor(Math.log10(max))), tope = Math.ceil(max / paso) * paso;
    if (tope / paso < 3) { paso /= 2; tope = Math.ceil(max / paso) * paso; }
    var y = function (v) { return T + ih - v / tope * ih; }, k = function (v) { return v >= 1000 ? (v / 1000).toLocaleString('es-ES') + ' k' : String(v); };
    var s = '<svg width="' + W + '" height="' + H + '" viewBox="0 0 ' + W + ' ' + H + '" role="img" aria-label="Ingresos y gastos por mes de ' + r.anio + '">';
    for (var g = 0; g <= tope + 0.5; g += paso) {
      s += '<line class="rej" x1="' + L + '" x2="' + (W - 4) + '" y1="' + y(g).toFixed(1) + '" y2="' + y(g).toFixed(1) + '"/>'
        + '<text class="eje" x="' + (L - 8) + '" y="' + (y(g) + 4).toFixed(1) + '" text-anchor="end">' + k(g) + '</text>';
    }
    var col = iw / 12, bw = Math.max(4, Math.min(18, (col - 6) / 2));
    var barra = function (x, v, cls) {
      var h = Math.max(0, ih - (y(v) - T)), r = Math.min(4, h, bw / 2), y0 = T + ih;
      if (h < 0.5) { return ''; }
      return '<path class="' + cls + '" d="M' + x + ' ' + y0 + 'V' + (y0 - h + r) + 'Q' + x + ' ' + (y0 - h) + ' ' + (x + r) + ' ' + (y0 - h) + 'H' + (x + bw - r) + 'Q' + (x + bw) + ' ' + (y0 - h) + ' ' + (x + bw) + ' ' + (y0 - h + r) + 'V' + y0 + 'Z"/>';
    };
    meses.forEach(function (m, n) {
      var cx = L + col * n + col / 2;
      s += barra(cx - bw - 1, m.ing, 's-ing') + barra(cx + 1, m.gas, 's-gas');
      s += '<text class="eje" x="' + cx + '" y="' + (H - 6) + '" text-anchor="middle">' + M.MESES[n] + '</text>';
      s += '<rect class="zona" data-i="' + n + '" x="' + (L + col * n) + '" y="' + T + '" width="' + col + '" height="' + ih + '" tabindex="0"/>';
    });
    s += '</svg>';
    var tabla = '<table class="datos" hidden><thead><tr><th>Mes</th><th>Ingresos</th><th>Gastos</th><th>Diferencia</th></tr></thead><tbody>'
      + meses.map(function (m, n) { return '<tr><td>' + M.MESES[n] + '</td><td>' + eur(m.ing) + '</td><td>' + eur(m.gas) + '</td><td>' + eur(m.ing - m.gas) + '</td></tr>'; }).join('') + '</tbody></table>';
    cont.innerHTML = '<div class="leyenda"><span><i class="ing"></i>Ingresos</span><span><i class="gas"></i>Gastos</span></div>' + s
      + '<div class="tip" role="status"></div><button type="button" class="ver-tabla">Ver como tabla</button>' + tabla;
    var tip = cont.querySelector('.tip'), svg = cont.querySelector('svg');
    var muestra = function (el) {
      var n = +el.getAttribute('data-i'), m = meses[n];
      cont.querySelectorAll('.zona.on').forEach(function (z) { z.classList.remove('on'); });
      el.classList.add('on');
      tip.innerHTML = '<b>' + M.MESES[n] + ' ' + r.anio + '</b><br>Ingresos ' + eur(m.ing) + '<br>Gastos ' + eur(m.gas) + '<br>Diferencia <b>' + eur(m.ing - m.gas, true) + '</b>';
      var top = svg.getBoundingClientRect().top - cont.getBoundingClientRect().top;
      tip.style.left = Math.min(Math.max(L + col * n + col / 2, 74), W - 74) + 'px';
      tip.style.top = (top + y(Math.max(m.ing, m.gas)) - 6) + 'px';
      tip.classList.add('on');
    };
    var oculta = function () { tip.classList.remove('on'); cont.querySelectorAll('.zona.on').forEach(function (z) { z.classList.remove('on'); }); };
    cont.querySelectorAll('.zona').forEach(function (z) {
      z.addEventListener('mouseenter', function () { muestra(z); });
      z.addEventListener('focus', function () { muestra(z); });
      z.addEventListener('click', function () { muestra(z); });
      z.addEventListener('blur', oculta);
    });
    svg.addEventListener('mouseleave', oculta);
    cont.querySelector('.ver-tabla').addEventListener('click', function (ev) {
      var t = cont.querySelector('table'); t.hidden = !t.hidden; ev.target.textContent = t.hidden ? 'Ver como tabla' : 'Ocultar tabla';
    });
  }

  var ultimo = null, tRes = null;
  window.addEventListener('resize', function () {
    clearTimeout(tRes);
    tRes = setTimeout(function () { if (ultimo) { pintaMeses(ultimo); } }, 150);
  });

  function pinta(d) {
    var total = pintaPagos(d);
    pintaBanco(d, total);
    pintaImpuestos(d);
    pintaResultado(d);
    pintaCajas(d);
    pintaPendientes(d);
    $('errores').innerHTML = d.errores && d.errores.length ? '<p class="aviso">No se pudo cargar todo: ' + esc(d.errores.join('; ')) + '</p>' : '';
    var ahora = new Date();
    $('estado').textContent = 'Actualizado a las ' + ahora.toLocaleTimeString('es-ES', { hour: '2-digit', minute: '2-digit' }) + ', ' + d.trimestre;
    document.body.classList.remove('cargando');
  }

  function carga() {
    document.body.classList.add('cargando');
    $('estado').textContent = 'Cargando…';
    var a = api('/api/panel.php').then(pinta).catch(function (x) { $('estado').textContent = x.message; $('banco-nota').textContent = x.message; });
    var b = api('/api/panel.php?parte=meses').then(pintaMeses).catch(function (x) { $('meses').innerHTML = '<p class="vacio">' + esc(x.message) + '</p>'; });
    return Promise.all([a, b]);
  }

  $('ref').addEventListener('click', function () {
    var btn = $('ref'); btn.disabled = true; $('estado').textContent = 'Actualizando…';
    api('/api/panel.php', 'POST', { accion: 'refrescar' }).then(carga).catch(function (x) { $('estado').textContent = x.message; }).then(function () { btn.disabled = false; });
  });
  carga();
})();
