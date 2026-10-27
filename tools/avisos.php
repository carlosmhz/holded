<?php
/**
 * Revisión diaria de avisos. La ejecuta la tarea programada (cron) de Hostinger, p. ej. a las 9:00.
 * Solo por línea de comandos (por URL está bloqueado). No llama a la API de Holded.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
define('SIN_SESION', true);
require dirname(__DIR__) . '/src/bootstrap.php';

$p = new Panel(holded(), contabilidad(), store());
$foto = $p->foto();
$hecho = (new Avisos(store()))->diario(new Tareas(store()), $foto ? $p->datos($foto) : null);
store()->escribir('cron', ['t' => date('c'), 'resultado' => $hecho]);
echo date('c') . ' ' . implode('; ', $hecho) . PHP_EOL;
