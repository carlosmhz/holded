<?php
/**
 * POST /api/asientos.php — Apps Script empuja aquí las filas pendientes de la hoja "Asientos"
 * (solo cuenta 57200001, importe del haber). Sin sesión: autenticado con la cabecera X-Token.
 *
 *   Cuerpo: {"generado":"yyyy-mm-ddThh:mm:ss","filas":[{"fecha":"yyyy-mm-dd","concepto":"…","importe":123.45}]}
 *   200 {"ok":true,"filas":N} · 401 token incorrecto · 405 método · 422 JSON no válido
 *
 * Se guarda en data_dir/asientos.json (fuera de public_html). Cada envío reemplaza al anterior.
 */
declare(strict_types=1);
define('SIN_SESION', true);
require dirname(__DIR__) . '/src/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    jsonOut(['ok' => false, 'error' => 'Solo POST'], 405);
}

$esperado = (string) cfg()['asientos_token'];
$recibido = (string) ($_SERVER['HTTP_X_TOKEN'] ?? '');
if (strlen($esperado) < 32 || !hash_equals($esperado, $recibido)) {
    usleep(random_int(200000, 500000));
    jsonOut(['ok' => false, 'error' => 'Token no válido'], 401);
}

$fallo = fn(string $m) => jsonOut(['ok' => false, 'error' => $m], 422);

try {
    $in = leerJson(262144);
} catch (ErrorUsuario $e) {
    $fallo($e->getMessage());
}

$gen = $in['generado'] ?? null;
if (!is_string($gen) || !preg_match('/^(\d{4}-\d{2}-\d{2})T\d{2}:\d{2}:\d{2}$/', $gen, $mg) || !fechaValida($mg[1])) {
    $fallo('"generado" debe ser yyyy-mm-ddThh:mm:ss');
}
if (!isset($in['filas']) || !is_array($in['filas']) || !array_is_list($in['filas'])) {
    $fallo('"filas" debe ser una lista');
}
if (count($in['filas']) > 1000) {
    $fallo('Demasiadas filas (máx. 1000)');
}

$filas = [];
foreach ($in['filas'] as $i => $f) {
    $n = $i + 1;
    if (!is_array($f)) {
        $fallo("Fila $n: no es un objeto");
    }
    $fecha = fechaValida($f['fecha'] ?? null);
    if ($fecha === null) {
        $fallo("Fila $n: fecha no válida (yyyy-mm-dd)");
    }
    $concepto = $f['concepto'] ?? null;
    if (!is_string($concepto) || mb_strlen($concepto) > 200) {
        $fallo("Fila $n: concepto no válido (texto, máx. 200)");
    }
    $imp = $f['importe'] ?? null;
    if (!(is_int($imp) || is_float($imp)) || !is_finite((float) $imp) || $imp <= 0 || $imp > 1e7) {
        $fallo("Fila $n: importe no válido (número > 0)");
    }
    $filas[] = [
        'fecha'    => $fecha,
        'concepto' => trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $concepto) ?? ''),
        'importe'  => r2((float) $imp),
    ];
}

try {
    store()->escribir('asientos', ['generado' => $gen, 'recibido' => date('c'), 'filas' => $filas]);
} catch (Throwable $e) {
    error_log('[holded] asientos: ' . $e->getMessage());
    jsonOut(['ok' => false, 'error' => 'No se pudo guardar'], 500);
}
jsonOut(['ok' => true, 'filas' => count($filas)]);
