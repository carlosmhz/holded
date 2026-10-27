<?php
/**
 * Avisos en el móvil (con sesión). Sin llamadas a Holded.
 *   GET  → {clave pública VAPID, preferencias, nº de dispositivos}
 *   POST {accion: suscribir|baja|pref|probar, …} con X-CSRF-Token
 */
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';

Auth::exigirApi();
$a = new Avisos(store());
try {
    $salida = function () use ($a) {
        $c = $a->config();
        $tipos = [];
        foreach (Avisos::TIPOS as $k => $txt) {
            $tipos[] = ['id' => $k, 'texto' => $txt, 'on' => !empty($c['prefs'][$k])];
        }
        return ['publica' => $a->secretos()['vapid']['publica'], 'tipos' => $tipos, 'dispositivos' => array_column($c['subs'], 'endpoint')];
    };
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        Auth::comprobarCsrf();
        $in = leerJson(4096);
        switch ($in['accion'] ?? '') {
            case 'suscribir':
                $a->suscribir(is_array($in['sub'] ?? null) ? $in['sub'] : []);
                break;
            case 'baja':
                $a->baja((string) ($in['endpoint'] ?? ''));
                break;
            case 'pref':
                $a->preferencia((string) ($in['tipo'] ?? ''), !empty($in['on']));
                break;
            case 'probar':
                $n = $a->enviar('prueba', 'Aviso de prueba', 'Los avisos de MPI Holded funcionan en este móvil.', '/tareas.php');
                jsonOut($salida() + ['enviados' => $n]);
            default:
                throw new ErrorUsuario('Acción no válida');
        }
    }
    jsonOut($salida());
} catch (ErrorUsuario $e) {
    jsonOut(['error' => $e->getMessage()], 400);
} catch (Throwable $e) {
    error_log('[holded] avisos: ' . $e);
    jsonOut(['error' => 'Error interno'], 500);
}
