<?php
declare(strict_types=1);
require __DIR__ . '/src/bootstrap.php';

Auth::exigirPagina();
cabecera('Panel MPI', ['panel.js']);
?>
<div class="panel">
  <div class="top">
    <h1>Panel MPI</h1>
    <span class="acc"><a href="/waylet.php">⛽ Waylet</a> <button type="button" id="ref" title="Refrescar (vacía la caché)">↻</button> <?= botonSalir() ?></span>
  </div>
  <p class="sub" id="sub">Cargando…</p>
  <div id="z"></div>
  <p class="mut pie">Estimaciones orientativas a partir de Holded; no son asesoría fiscal.</p>
</div>
<?php pie();
