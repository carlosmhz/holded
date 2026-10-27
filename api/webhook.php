<?php
/**
 * Webhook de Holded: POST /api/webhook.php?k=<clave> (la URL completa se ve en /diag.php).
 * Holded manda el evento en la cabecera x-holded-webhook-event y un resumen del documento en el cuerpo,
 * firmado con HMAC-SHA256 (x-holded-webhook-signature) usando el secreto whsec_ que se guarda en /diag.php.
 * Guarda el evento para el panel y, si toca, avisa al móvil. Los rechazos quedan anotados para /diag.php.
 * Sin llamadas a la API de Holded.
 */
declare(strict_types=1);
define('SIN_SESION', true);
require dirname(__DIR__) . '/src/bootstrap.php';

$a = new Avisos(store());
$cab = fn(string $n) => (string) ($_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $n))] ?? '');
$info = fn(string $cuerpo = '') => [
    'metodo' => $_SERVER['REQUEST_METHOD'] ?? '', 'evento' => mb_substr($cab('x-holded-webhook-event'), 0, 40),
    'id' => mb_substr($cab('x-holded-webhook-id'), 0, 60), 'fecha' => mb_substr($cab('x-holded-webhook-date'), 0, 40),
    'firma' => mb_substr($cab('x-holded-webhook-signature'), 0, 14) . '…', 'tamano' => strlen($cuerpo),
    'agente' => mb_substr($cab('user-agent'), 0, 60),
];

if (!hash_equals($a->secretos()['webhook'], (string) ($_GET['k'] ?? ''))) {
    $a->anotarRechazo('clave de la URL incorrecta', $info());
    usleep(random_int(200000, 500000));
    jsonOut(['ok' => false], 401);
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    jsonOut(['ok' => true]); // comprobación de la URL
}
$cuerpo = (string) file_get_contents('php://input', false, null, 0, 65537);
$variante = $a->comprobarFirma($cuerpo, $cab('x-holded-webhook-date'), $cab('x-holded-webhook-id'), $cab('x-holded-webhook-signature'));
if ($variante === null) {
    $a->anotarRechazo('firma no válida', $info($cuerpo));
    jsonOut(['ok' => false], 401);
}
try {
    $in = json_decode($cuerpo, true);
    $p = new Panel(holded(), contabilidad(), store());
    $foto = $p->foto();
    $a->eventoHolded(is_array($in) ? $in : [], $cab('x-holded-webhook-event'), $cab('x-holded-webhook-id'), $foto ? $p->datos($foto) : null, $variante);
    jsonOut(['ok' => true]);
} catch (Throwable $e) {
    error_log('[holded] webhook: ' . $e);
    jsonOut(['ok' => true]); // ya está guardado lo que se pudo; evitar reintentos en bucle
}
