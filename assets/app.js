'use strict';
/* Utilidades comunes y registro de la PWA. */
window.MPI = (function () {
  var csrf = (document.querySelector('meta[name="csrf"]') || {}).content || '';

  function eur(v, signo) {
    var s = Math.abs(v).toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, '.') + ' €';
    if (v < 0) { return '−' + s; }
    return (signo && v > 0 ? '+' : '') + s;
  }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  var MESES = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
  var MESES_L = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
  function fechaLarga(iso) { return +iso.slice(8, 10) + ' de ' + MESES_L[+iso.slice(5, 7) - 1]; }
  function diasHasta(iso, hoy) { return Math.round((Date.parse(iso + 'T12:00:00') - Date.parse(hoy + 'T12:00:00')) / 864e5); }

  function api(url, metodo, cuerpo, volver) {
    var op = { method: metodo || 'GET', credentials: 'same-origin', headers: { 'Accept': 'application/json' } };
    if (cuerpo) {
      op.headers['Content-Type'] = 'application/json';
      op.headers['X-CSRF-Token'] = csrf;
      op.body = JSON.stringify(cuerpo);
    }
    return fetch(url, op).then(function (r) {
      return r.json().catch(function () { return { error: 'Respuesta no válida del servidor (' + r.status + ')' }; }).then(function (d) {
        if (r.status === 401 && d.login) { location.href = '/login.php?next=' + encodeURIComponent(volver || location.pathname); }
        if (!r.ok || d.error) { throw new Error(d.error || ('Error ' + r.status)); }
        return d;
      });
    }, function () { throw new Error('Sin conexión. Comprueba la red y vuelve a intentarlo.'); });
  }

  if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () { navigator.serviceWorker.register('/sw.js').catch(function () {}); });
  }

  return { eur: eur, esc: esc, api: api, MESES: MESES, fechaLarga: fechaLarga, diasHasta: diasHasta };
})();
