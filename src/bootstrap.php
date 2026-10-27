<?php
/**
 * Arranque común de todas las páginas y endpoints.
 * Carga el config (FUERA de public_html), aplica cabeceras de seguridad, fuerza HTTPS
 * y abre la sesión.
 */
declare(strict_types=1);

if (PHP_VERSION_ID < 80100) {
    http_response_code(500);
    exit('Se necesita PHP 8.1 o superior.');
}

define('APP_ROOT', dirname(__DIR__));
date_default_timezone_set('Europe/Madrid');
mb_internal_encoding('UTF-8');
ini_set('display_errors', '0');
ini_set('log_errors', '1');

require __DIR__ . '/util.php';
require __DIR__ . '/Config.php';
require __DIR__ . '/Store.php';
require __DIR__ . '/Auth.php';
require __DIR__ . '/Holded.php';
require __DIR__ . '/Contabilidad.php';
require __DIR__ . '/Waylet.php';
require __DIR__ . '/vistas.php';

cabecerasSeguridad();
forzarHttps();

/** Config cargado. Si falta, corta con un mensaje (sin mostrar secretos). */
function cfg(): array
{
    static $c = null;
    if ($c === null) {
        try {
            $c = Config::cargar();
        } catch (Throwable $e) {
            error_log('[holded] ' . $e->getMessage());
            http_response_code(500);
            exit('Configuración incompleta: ' . e($e->getMessage()));
        }
    }
    return $c;
}

function store(): Store
{
    static $s = null;
    return $s ??= new Store(cfg()['data_dir']);
}

function holded(): Holded
{
    static $h = null;
    return $h ??= new Holded(cfg()['holded_api_key'], cfg()['holded_base']);
}

function contabilidad(): Contabilidad
{
    static $c = null;
    return $c ??= new Contabilidad(holded(), store());
}

if (!defined('SIN_SESION')) {
    Auth::iniciarSesion(cfg(), store());
}
