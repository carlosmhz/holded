'use strict';
(function () {
  var $ = function (id) { return document.getElementById(id); };
  var csrf = document.querySelector('meta[name="csrf"]').content;
  var sub = $('sub'), z = $('z'), ref = $('ref');

  function e(v) { return (v < 0 ? '−' : '') + Math.abs(v).toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, '.') + ' €'; }
  function f(s) { return s ? s.slice(8, 10) + '/' + s.slice(5, 7) : ''; }
  function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function row(a, b, cl) { return '<div class="r"><span>' + a + '</span><b class="' + (cl || '') + '">' + b + '</b></div>'; }
  function card(t, h) { return '<div class="c"><h2>' + t + '</h2>' + h + '</div>'; }

  function api(metodo, cuerpo) {
    var op = { method: metodo, credentials: 'same-origin', headers: { 'Accept': 'application/json' } };
    if (cuerpo) { op.headers['Content-Type'] = 'application/json'; op.headers['X-CSRF-Token'] = csrf; op.body = JSON.stringify(cuerpo); }
    return fetch('/api/panel.php', op).then(function (r) {
      return r.json().catch(function () { return { error: 'Respuesta no válida (' + r.status + ')' }; }).then(function (d) {
        if (r.status === 401 && d.login) { location.href = '/login.php?next=' + encodeURIComponent('/panel.php'); }
        if (!r.ok || d.error) { throw new Error(d.error || ('Error ' + r.status)); }
        return d;
      });
    });
  }

  function pinta(d) {
    var h = '', i = d.impuestos, tc = 0, tp = 0, tf = 0;
    sub.textContent = 'Actualizado ' + f(d.hoy) + ' · trimestre ' + d.trimestre;
    if (d.saldos) {
      var s = '';
      d.saldos.forEach(function (x) { s += row(esc(x.nombre), e(x.saldo), x.saldo < 0 ? 'neg' : '') + (x.nota ? '<div class="mut">' + esc(x.nota) + '</div>' : ''); });
      var p = d.saldos.filter(function (x) { return x.num === 55500000; })[0];
      if (p && Math.abs(p.saldo) > 0.004) { s += '<div class="av">La 555 no está a 0: hay pagos esperando factura (o pendientes de pasar a la 550).</div>'; }
      h += card('💰 Saldos contables', s + '<div class="mut">Negativo en la tarjeta = deuda.</div>');
    }
    if (i) {
      h += '<div class="grid">' + card('📈 Resultado ' + d.hoy.slice(0, 4), '<div class="big ' + (i.resultado < 0 ? 'neg' : 'pos') + '">' + e(i.resultado) + '</div>' + row('Ingresos', e(i.ingresos)) + row('Gastos', e(i.gastos)) + (i.noDeducibles ? row('No deducibles', e(i.noDeducibles)) : ''))
        + card('🧾 Impuestos ' + esc(d.trimestre), row('303 IVA', e(i.m303), 't') + '<div class="mut">Repercutido ' + e(i.repercutido) + ' − soportado ' + e(i.soportado) + '</div>'
        + row('130 IRPF', e(i.m130), 't') + '<div class="mut">20 % de ' + e(i.rendimiento) + ' − pagos a cuenta ' + e(i.pagosCuenta) + '</div>'
        + row('Total a pagar', e(Math.max(0, i.m303) + i.m130), 't') + '<div class="mut">Domiciliar hasta ' + f(d.domicilia) + ' · plazo ' + f(d.plazo) + '</div>') + '</div><div class="sep"></div>';
    }
    if (d.cobros) {
      var c = '';
      d.cobros.forEach(function (x) { tc += x.pendiente; c += row(esc(x.contacto), e(x.pendiente)); });
      h += card('📥 Clientes pendientes de cobro · ' + e(tc), c || '<div class="mut">Nada pendiente</div>');
    }
    if (d.pagosDocs) {
      var q = '';
      d.pagosDocs.forEach(function (x) { tp += x.pendiente; q += row(esc(x.contacto), e(x.pendiente)); });
      h += card('📤 Proveedores pendientes de pago · ' + e(tp), q || '<div class="mut">Nada pendiente</div>');
    }
    if (d.fijos) {
      var g = '', imp = i && d.plazo <= d.limite ? Math.max(0, i.m303) + i.m130 : 0;
      d.fijos.forEach(function (x) { tf += x.importe; g += row(f(x.fecha) + ' · ' + esc(x.concepto), e(x.importe)); });
      if (imp) { tf += imp; g += row(f(d.plazo) + ' · 303 + 130 (estimado)', e(imp)); }
      h += card('📅 Pagos hasta el ' + f(d.limite) + ' · ' + e(tf), (g || '<div class="mut">Ninguno</div>') + (d.hojaAsientos ? '' : '<div class="mut">Aún no han llegado las filas de la hoja «Asientos».</div>'));
    }
    if (d.errores && d.errores.length) { h += '<div class="av">No se pudo cargar: ' + esc(d.errores.join(' · ')) + '</div>'; }
    z.innerHTML = h;
  }

  function carga() {
    sub.textContent = 'Cargando…';
    return api('GET').then(pinta).catch(function (x) { sub.textContent = 'Error: ' + x.message; });
  }

  ref.addEventListener('click', function () {
    ref.disabled = true; sub.textContent = 'Refrescando…';
    api('POST', { accion: 'refrescar' }).then(carga).catch(function (x) { sub.textContent = 'Error: ' + x.message; }).then(function () { ref.disabled = false; });
  });
  carga();
})();
