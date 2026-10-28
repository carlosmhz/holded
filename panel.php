<?php
declare(strict_types=1);
require __DIR__ . '/src/bootstrap.php';

Auth::exigirPagina();
cabecera('Panel · MPI Holded', ['panel.js', 'consultas.js']);
echo barra('/panel.php');
?>
<main class="pagina">
  <div class="foto"><span id="foto" aria-live="polite">Cargando…</span>
    <button type="button" id="act" title="Vuelve a leer los datos de Holded (4 llamadas)">Actualizar</button></div>
  <div id="errores"></div>
  <div id="cambios"></div>
  <div id="banco"></div>

  <section class="sec" aria-labelledby="t-cajas">
    <h2 id="t-cajas">Cajas y monederos</h2>
    <div id="cajas"><p class="vacio">Cargando…</p></div>
  </section>

  <div class="columnas">
    <div>
      <section class="sec" aria-labelledby="t-pagos">
        <h2 id="t-pagos">Pagos próximos</h2>
        <div id="pagos"></div>
      </section>
      <section class="sec" aria-labelledby="t-imp">
        <h2 id="t-imp">Impuestos</h2>
        <div id="imp"></div>
      </section>
    </div>
    <div>
      <section class="sec" aria-labelledby="t-res">
        <h2 id="t-res">Resultado</h2>
        <div id="res"></div>
      </section>
      <section class="sec" aria-labelledby="t-pend">
        <h2 id="t-pend">Te deben</h2>
        <div id="pend"></div>
      </section>
    </div>
  </div>
  <section class="sec" aria-labelledby="t-cons">
    <h2 id="t-cons">¿Cuánto he gastado?</h2>
    <form class="form consulta" id="consulta" novalidate>
      <label for="c-concepto">Concepto</label>
      <select id="c-concepto"><option>Cargando…</option></select>
      <div id="c-cuenta-caja" hidden>
        <label for="c-cuenta">Cuenta</label>
        <select id="c-cuenta"></select>
      </div>
      <span class="campo" id="c-periodo-t">Periodo</span>
      <div class="atajos" id="c-atajos" role="group" aria-labelledby="c-periodo-t"></div>
      <div class="fechas" id="c-fechas" hidden>
        <div><label for="c-desde">Desde</label><input type="date" id="c-desde"></div>
        <div><label for="c-hasta">Hasta</label><input type="date" id="c-hasta"></div>
      </div>
      <button type="submit" class="boton" id="c-ir">Consultar</button>
    </form>
    <div id="c-res" aria-live="polite"></div>
  </section>
  <p class="uso" id="uso"></p>
</main>
<?php pie();
