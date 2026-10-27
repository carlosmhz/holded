<?php
declare(strict_types=1);
require __DIR__ . '/src/bootstrap.php';

Auth::exigirPagina();
cabecera('Panel · MPI Holded', ['panel.js'], 'cargando');
echo barra('/panel.php');
?>
<main class="pagina">
  <section class="tarjeta" aria-labelledby="t-banco">
    <p class="etq" id="t-banco">Banco Santander</p>
    <p class="cifra" id="banco">—</p>
    <p class="nota" id="banco-nota">Cargando saldos de Holded…</p>
    <div class="flecha" id="prevision" hidden><span id="prev-txt"></span><b id="prev-v"></b></div>
  </section>

  <div class="estado"><span id="estado" aria-live="polite">Cargando…</span>
    <button type="button" class="refrescar" id="ref">Actualizar datos</button></div>

  <div class="rejilla">
    <section class="hoja" id="h-pagos" aria-labelledby="t-pagos">
      <h2 id="t-pagos">Próximos pagos</h2><p class="bajo" id="pagos-bajo"></p>
      <div id="pagos"></div>
    </section>

    <section class="hoja" id="h-imp" aria-labelledby="t-imp">
      <h2 id="t-imp">Impuestos del trimestre</h2><p class="bajo" id="imp-bajo"></p>
      <div id="imp"></div>
    </section>

    <section class="hoja ancho" id="h-res" aria-labelledby="t-res">
      <h2 id="t-res">Resultado del año</h2><p class="bajo">Ingresos menos gastos según Holded, sin el resultado extraordinario (67 y 77).</p>
      <div id="res"></div>
      <div id="meses" class="graf"><p class="vacio">Cargando la evolución mensual…</p></div>
      <div id="reparto" class="reparto"></div>
    </section>

    <section class="hoja" aria-labelledby="t-cajas">
      <h2 id="t-cajas">Cajas y monederos</h2><p class="bajo">Lo que llevas adelantado y aún espera factura.</p>
      <div id="cajas"></div>
    </section>

    <section class="hoja" aria-labelledby="t-pend">
      <h2 id="t-pend">Pendiente de cobro y de pago</h2><p class="bajo">Saldo de las cuentas de clientes (430/440) y proveedores (400/410).</p>
      <div id="pend"></div>
    </section>
  </div>

  <div id="errores"></div>
  <p class="pie">Estimaciones a partir de Holded. No son asesoría fiscal.</p>
</main>
<?php pie();
