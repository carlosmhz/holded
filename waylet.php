<?php
declare(strict_types=1);
require __DIR__ . '/src/bootstrap.php';

Auth::exigirPagina();
$prueba = !cfg()['escritura_holded'];
cabecera('Saldo Waylet', ['waylet.js']);
?>
<div class="c">
  <div class="top"><h1>Saldo Waylet</h1><span class="acc"><a href="/panel.php">📊 Panel</a> <?= botonSalir() ?></span></div>
  <p class="sub">Holded: <b id="h">…</b></p>
  <?php if ($prueba): ?><p class="av">MODO PRUEBA: no se crea nada en Holded (escritura_holded = false en el config).</p><?php endif; ?>

  <div class="tabs" role="tablist">
    <button type="button" id="t1" class="on" data-tab="1">➕ Añadir</button>
    <button type="button" id="t2" data-tab="2">➖ Quitar</button>
    <button type="button" id="t3" data-tab="3">🎯 Ajustar</button>
  </div>

  <div id="p1" class="p on">
    <label for="a">Importe que se suma (bonificación, cupón…)</label>
    <input id="a" class="big" inputmode="decimal" placeholder="0,00" autocomplete="off">
  </div>

  <div id="p2" class="p">
    <label for="q">Importe gastado</label>
    <input id="q" class="big" inputmode="decimal" placeholder="0,00" autocomplete="off">
    <label>¿En qué?</label>
    <div class="tipo">
      <button type="button" id="k1" class="on" data-tipo="factura">⛽ Pago con factura</button>
      <button type="button" id="k2" data-tipo="gasto">🧽 Sin factura</button>
    </div>
    <p class="hint" id="hk">Va a la caja Pagos Waylet. Cuando llegue la factura, págala desde esa caja.</p>
    <label for="cq">Concepto (opcional)</label>
    <input id="cq" maxlength="100" placeholder="Ej.: Repsol Ciudad Rodrigo" autocomplete="off">
  </div>

  <div id="p3" class="p">
    <label for="s">Saldo actual en la app de Waylet</label>
    <input id="s" class="big" inputmode="decimal" placeholder="0,00" autocomplete="off">
    <p class="hint">La diferencia con Holded se apunta como bonificación (629).</p>
  </div>

  <div id="fw"><label for="f">Fecha</label><input id="f" type="date"></div>
  <button class="go" id="b" type="button">Guardar en Holded</button>
  <div id="m" aria-live="polite"></div>
</div>
<?php pie();
