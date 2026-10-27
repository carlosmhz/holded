<?php
declare(strict_types=1);
require __DIR__ . '/src/bootstrap.php';

Auth::exigirPagina();
cabecera('Tareas · MPI Holded', ['tareas.js']);
echo barra('/tareas.php');
?>
<main class="pagina estrecha">
  <section class="sec" aria-labelledby="t-tareas">
    <h2 id="t-tareas">Tareas del mes</h2>
    <div class="prog" aria-hidden="true"><svg><rect class="pista" width="100%" height="6" rx="3"/><rect class="val" id="prog" width="0%" height="6" rx="3"/></svg></div>
    <ul class="tareas" id="lista"><li><span class="vacio">Cargando…</span></li></ul>
    <p class="nota" id="reinicio"></p>
  </section>

  <section class="sec" aria-labelledby="t-avisos">
    <h2 id="t-avisos">Avisos en este móvil</h2>
    <div id="tipos"></div>
    <p class="nota" id="estado-avisos">Comprobando…</p>
    <button type="button" class="secundario" id="activar" hidden>Activar avisos</button>
    <button type="button" class="secundario" id="probar" hidden>Enviar un aviso de prueba</button>
    <button type="button" class="secundario" id="desactivar" hidden>Desactivar en este móvil</button>
  </section>
</main>
<?php pie();
