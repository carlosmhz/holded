<?php
/**
 * API del panel (solo lectura en Holded, con sesión).
 *   GET  → datos del panel
 *   POST {accion: "refrescar"} con X-CSRF-Token → borra la caché (años y trimestres cerrados)
 */
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';

Auth::exigirApi();

try {
    $metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($metodo === 'POST') {
        Auth::comprobarCsrf();
        $in = leerJson(1024);
        if (($in['accion'] ?? '') !== 'refrescar') {
            throw new ErrorUsuario('Acción no válida');
        }
        jsonOut(['ok' => true, 'borrados' => store()->limpiarCache()]);
    }
    if ($metodo !== 'GET') {
        jsonOut(['error' => 'Método no permitido'], 405);
    }
    @set_time_limit(90);
    $p = new Panel(holded(), contabilidad(), store());
    jsonOut(($_GET['parte'] ?? '') === 'meses' ? $p->meses() : $p->datos());
} catch (ErrorUsuario $e) {
    jsonOut(['error' => $e->getMessage()], 400);
} catch (Throwable $e) {
    error_log('[holded] panel: ' . $e);
    jsonOut(['error' => 'Error interno'], 500);
}
