<?php
/**
 * API de la página de Waylet (solo con sesión).
 *   GET  → {saldo}
 *   POST → {accion: anadir|quitar|ajustar, …} con cabecera X-CSRF-Token
 */
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';

Auth::exigirApi();
$w = new Waylet(contabilidad(), holded(), cfg()['escritura_holded']);

try {
    $metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($metodo === 'GET') {
        $r = ['saldo' => $w->saldo(), 'prueba' => !cfg()['escritura_holded']];
        if (isset($_GET['mov'])) {
            try {
                $r['movimientos'] = $w->movimientos();
            } catch (Throwable $e) {
                $r['movimientos'] = null;
                error_log('[holded] movimientos Waylet: ' . $e->getMessage());
            }
        }
        jsonOut($r);
    }
    if ($metodo !== 'POST') {
        jsonOut(['error' => 'Método no permitido'], 405);
    }
    Auth::comprobarCsrf();
    $in = leerJson(8192);
    $r = match ($in['accion'] ?? '') {
        'anadir'  => $w->anadir($in['importe'] ?? null, $in['fecha'] ?? null),
        'quitar'  => $w->quitar($in['importe'] ?? null, $in['tipo'] ?? null, $in['concepto'] ?? '', $in['fecha'] ?? null),
        'ajustar' => $w->ajustar($in['saldo'] ?? null),
        default   => throw new ErrorUsuario('Acción no válida'),
    };
    jsonOut($r);
} catch (ErrorUsuario $e) {
    jsonOut(['error' => $e->getMessage()], 400);
} catch (ErrorHolded $e) {
    error_log('[holded] ' . $e->getMessage());
    jsonOut(['error' => $e->getMessage()], 502);
} catch (Throwable $e) {
    error_log('[holded] ' . $e);
    jsonOut(['error' => 'Error interno'], 500);
}
