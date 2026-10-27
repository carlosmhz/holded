<?php
/**
 * Webhook de Holded: POST /api/webhook.php?k=<clave secreta> (la clave se ve en /diag.php).
 * Guarda el evento (compras, facturas, pagos…) para que el panel avise de "cambios desde la foto"
 * y, si es una factura de un proveedor con caja pendiente, envía un aviso al móvil. Sin llamadas a Holded.
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
try {
    $raw = file_get_contents('php://input', false, null, 0, 65537);
    $in = json_decode((string) $raw, true);
    if (!is_array($in) && is_string($raw)) {
        parse_str($raw, $in); // por si llega como formulario
    }
    $p = new Panel(holded(), contabilidad(), store());
    $foto = $p->foto();
    $a->eventoHolded(is_array($in) ? $in : [], (string) ($_SERVER['HTTP_X_HOLDED_EVENT'] ?? $_SERVER['HTTP_X_EVENT'] ?? ''), $foto ? $p->datos($foto) : null);
    jsonOut(['ok' => true]);
} catch (Throwable $e) {
    error_log('[holded] webhook: ' . $e);
    jsonOut(['ok' => false], 500);
}
