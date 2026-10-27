<?php
/**
 * API del panel (solo lectura en Holded, con sesión).
 *   GET  → datos del panel a partir de la foto guardada (0 llamadas; solo la primera vez crea la foto)
 *   POST {accion: "actualizar"} con X-CSRF-Token → renueva la foto (3 llamadas a Holded)
 */
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';

Auth::exigirApi();

try {
    $p = new Panel(holded(), contabilidad(), store());
    $metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    @set_time_limit(90);
    if ($metodo === 'POST') {
        Auth::comprobarCsrf();
        $in = leerJson(1024);
        if (($in['accion'] ?? '') !== 'actualizar') {
            throw new ErrorUsuario('Acción no válida');
        }
        jsonOut($p->datos($p->actualizar()));
    }
    if ($metodo !== 'GET') {
        jsonOut(['error' => 'Método no permitido'], 405);
    }
    jsonOut($p->datos($p->fotoOCrear()));
} catch (ErrorUsuario $e) {
    jsonOut(['error' => $e->getMessage()], 400);
} catch (ErrorHolded $e) {
    error_log('[holded] panel: ' . $e->getMessage());
    jsonOut(['error' => $e->getMessage()], 502);
} catch (Throwable $e) {
    error_log('[holded] panel: ' . $e);
    jsonOut(['error' => 'Error interno'], 500);
}
