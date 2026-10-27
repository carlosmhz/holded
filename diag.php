<?php
/** Diagnóstico (con login): versión de PHP, extensiones, config, carpeta de datos y conexión con Holded. */
declare(strict_types=1);
require __DIR__ . '/src/bootstrap.php';

Auth::exigirPagina();
$c = cfg();

$filas = [
    'PHP'                => PHP_VERSION . ' (' . PHP_SAPI . ')',
    'Extensiones'        => implode(' · ', array_map(fn($x) => $x . (extension_loaded($x) ? ' ✓' : ' ✗'), ['curl', 'json', 'mbstring', 'openssl'])),
    'HTTPS'              => esHttps() ? 'sí' : 'NO',
    'Config'             => 'cargado (fuera de public_html: ' . (str_starts_with(realpath(Config::ruta()) ?: '', APP_ROOT . '/') ? 'NO ⚠️' : 'sí') . ')',
    'Carpeta de datos'   => (is_writable($c['data_dir']) ? 'escribible' : 'NO escribible ⚠️') . ' · dentro de public_html: ' . (str_starts_with(realpath($c['data_dir']) ?: '', APP_ROOT . '/') ? 'sí ⚠️' : 'no'),
    'API key de Holded'  => $c['holded_api_key'] !== '' ? 'puesta' : 'FALTA',
    'Token de asientos'  => strlen((string) $c['asientos_token']) >= 32 ? 'puesto' : 'FALTA (mín. 32 caracteres)',
    'Escritura en Holded'=> $c['escritura_holded'] ? 'ACTIVADA' : 'desactivada (modo prueba)',
    'Sesión'             => $c['sesion_dias'] . ' días',
];

$t = microtime(true);
try {
    $n = count(holded()->cuentas(null, null, '57200001'));
    $filas['Conexión Holded'] = 'OK (' . round((microtime(true) - $t) * 1000) . ' ms, ' . $n . ' cuenta)';
} catch (Throwable $e) {
    $filas['Conexión Holded'] = 'FALLO: ' . $e->getMessage();
}

$a = store()->leer('asientos');
$filas['Hoja Asientos'] = $a ? count($a['filas']) . ' filas · generado ' . $a['generado'] . ' · recibido ' . $a['recibido'] : 'aún no se ha recibido nada';
$filas['Caché'] = count(glob($c['data_dir'] . '/cache/*.json') ?: []) . ' archivos';

cabecera('Diagnóstico');
?>
<div class="c">
  <div class="top"><h1>Diagnóstico</h1><?= botonSalir() ?></div>
  <p class="sub"><a href="/waylet.php">← Waylet</a></p>
  <table class="diag">
    <?php foreach ($filas as $k => $v): ?><tr><td><?= e($k) ?></td><td><?= e($v) ?></td></tr><?php endforeach; ?>
  </table>
</div>
<?php pie();
