<?php
/**
 * Consultas de gasto (solo lectura en Holded, con sesión).
 *   GET  → conceptos, cuentas y atajos de periodo (0 llamadas)
 *   POST {accion:"total", concepto, cuenta?, desde, hasta}   con X-CSRF-Token → 1 llamada por año (o 0 si ya está guardada)
 *   POST {accion:"detalle", cuenta, desde, hasta}            con X-CSRF-Token → apuntes de una cuenta
 */
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';

Auth::exigirApi();

try {
    $p = new Panel(holded(), contabilidad(), store());
    $c = new Consultas(holded(), store(), $p->foto());
    $metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($metodo === 'POST') {
        Auth::comprobarCsrf();
        $in = leerJson(2048);
        @set_time_limit(60);
        switch ($in['accion'] ?? '') {
            case 'total':
                jsonOut($c->total((string) ($in['concepto'] ?? ''), $in['cuenta'] ?? null, $in['desde'] ?? null, $in['hasta'] ?? null));
            case 'detalle':
                jsonOut($c->detalle($in['cuenta'] ?? null, $in['desde'] ?? null, $in['hasta'] ?? null));
            default:
                throw new ErrorUsuario('Acción no válida');
        }
    }
    if ($metodo !== 'GET') {
        jsonOut(['error' => 'Método no permitido'], 405);
    }
    jsonOut($c->opciones());
} catch (ErrorUsuario $e) {
    jsonOut(['error' => $e->getMessage()], 400);
} catch (ErrorHolded $e) {
    error_log('[holded] consultas: ' . $e->getMessage());
    jsonOut(['error' => $e->getMessage()], 502);
} catch (Throwable $e) {
    error_log('[holded] consultas: ' . $e);
    jsonOut(['error' => 'Error interno'], 500);
}
