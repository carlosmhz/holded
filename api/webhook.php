<?php
/**
 * Webhook de Holded: POST /api/webhook.php?k=<clave> (la URL completa se ve en /diag.php).
 * Holded manda el evento en la cabecera x-holded-webhook-event y un resumen del documento en el cuerpo,
 * firmado con HMAC-SHA256 (x-holded-webhook-signature) usando el secreto whsec_ que se guarda en /diag.php.
 * Guarda el evento para el panel y, si toca, avisa al móvil. Sin llamadas a la API de Holded.
 */
declare(strict_types=1);
define('SIN_SESION', true);
require dirname(__DIR__) . '/src/bootstrap.php';

$a = new Avisos(store());
if (!hash_equals($a->secretos()['webhook'], (string) ($_GET['k'] ?? ''))) {
    usleep(random_int(200000, 500000));
    jsonOut(['ok' => false], 401);
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    jsonOut(['ok' => true]); // comprobación de la URL
}
$cuerpo = (string) file_get_contents('php://input', false, null, 0, 65537);
if (!$a->firmaValida($cuerpo, (string) ($_SERVER['HTTP_X_HOLDED_WEBHOOK_DATE'] ?? ''), (string) ($_SERVER['HTTP_X_HOLDED_WEBHOOK_SIGNATURE'] ?? ''))) {
    error_log('[holded] webhook: firma no válida');
    jsonOut(['ok' => false], 401);
}
try {
    $in = json_decode($cuerpo, true);
    $p = new Panel(holded(), contabilidad(), store());
    $foto = $p->foto();
    $a->eventoHolded(is_array($in) ? $in : [], (string) ($_SERVER['HTTP_X_HOLDED_WEBHOOK_EVENT'] ?? ''),
        (string) ($_SERVER['HTTP_X_HOLDED_WEBHOOK_ID'] ?? ''), $foto ? $p->datos($foto) : null);
    jsonOut(['ok' => true]);
} catch (Throwable $e) {
    error_log('[holded] webhook: ' . $e);
    jsonOut(['ok' => true]); // ya está guardado lo que se pudo; evitar reintentos en bucle
}
