<?php
/** Tareas del mes (con sesión). GET → estado; POST {id, hecha} con X-CSRF-Token → marca o desmarca. Sin llamadas a Holded. */
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';

Auth::exigirApi();
$t = new Tareas(store());
try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        Auth::comprobarCsrf();
        $in = leerJson(1024);
        jsonOut($t->marcar((string) ($in['id'] ?? ''), !empty($in['hecha'])));
    }
    jsonOut($t->estado());
} catch (ErrorUsuario $e) {
    jsonOut(['error' => $e->getMessage()], 400);
} catch (Throwable $e) {
    error_log('[holded] tareas: ' . $e);
    jsonOut(['error' => 'Error interno'], 500);
}
