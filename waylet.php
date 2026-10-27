<?php
declare(strict_types=1);
require __DIR__ . '/src/bootstrap.php';

Auth::exigirPagina();
$prueba = !cfg()['escritura_holded'];
cabecera('Waylet · MPI Holded', ['waylet.js']);
echo barra('/waylet.php');
?>
<main class="pagina estrecha">
  <section class="saldo" aria-labelledby="t-mon">
    <div class="n" id="t-mon">Monedero Waylet</div>
    <div class="v" id="h">—</div>
    <div class="s" id="hs">Saldo en Holded; debe coincidir con la app de Waylet.</div>
  </section>
  <?php if ($prueba): ?><p class="aviso">Modo prueba: no se crea nada en Holded (escritura_holded está en false).</p><?php endif; ?>

  <div class="seg" role="tablist" aria-label="Operación">
    <button type="button" role="tab" id="t1" aria-selected="true" aria-controls="p1" data-tab="1">Añadir</button>
    <button type="button" role="tab" id="t2" aria-selected="false" aria-controls="p2" data-tab="2">Quitar</button>
    <button type="button" role="tab" id="t3" aria-selected="false" aria-controls="p3" data-tab="3">Ajustar</button>
  </div>

  <section class="sec form">
    <div id="p1" class="p on" role="tabpanel" aria-labelledby="t1">
      <label for="a">Bonificación o cupón que entra</label>
      <input id="a" class="importe" inputmode="decimal" placeholder="0,00" autocomplete="off">
      <p class="ayuda">Se apunta como bonificación (629).</p>
    </div>
    <div id="p2" class="p" role="tabpanel" aria-labelledby="t2">
      <label for="q">Importe pagado con el saldo</label>
      <input id="q" class="importe" inputmode="decimal" placeholder="0,00" autocomplete="off">
      <span class="campo">¿Tiene factura?</span>
      <div class="tipo">
        <button type="button" id="k1" aria-pressed="true" data-tipo="factura">Con factura<small>Va a Pagos Waylet</small></button>
        <button type="button" id="k2" aria-pressed="false" data-tipo="gasto">Sin factura<small>Lavados: va a la 550</small></button>
      </div>
      <label for="cq">Concepto (opcional)</label>
      <input id="cq" maxlength="100" placeholder="Repsol Ciudad Rodrigo" autocomplete="off">
    </div>
    <div id="p3" class="p" role="tabpanel" aria-labelledby="t3">
      <label for="s">Saldo que marca ahora la app</label>
      <input id="s" class="importe" inputmode="decimal" placeholder="0,00" autocomplete="off">
      <p class="ayuda">Consulta el saldo actual en Holded y apunta la diferencia como bonificación (629), con fecha de hoy.</p>
    </div>
    <div id="fw"><label for="f">Fecha</label><input id="f" type="date"></div>
    <button class="boton" id="b" type="button">Añadir al monedero</button>
    <div id="m" class="res-op" aria-live="polite"></div>
  </section>

  <section class="sec" aria-labelledby="t-movs">
    <h2 id="t-movs">Movimientos del monedero</h2>
    <div id="movs"><p class="nota">No se cargan solos para no gastar llamadas a Holded.</p></div>
    <button type="button" class="mas" id="vermov">Ver movimientos</button>
  </section>
</main>
<?php pie();
