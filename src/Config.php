<?php
declare(strict_types=1);
defined('APP_ROOT') || exit;

/**
 * El config real NO está en el repo: vive un nivel por encima de public_html
 * (…/config.php). Se puede indicar otra ruta con la variable de entorno MPI_HOLDED_CONFIG.
 */
final class Config
{
    public static function ruta(): string
    {
        $env = getenv('MPI_HOLDED_CONFIG');
        return $env !== false && $env !== '' ? $env : dirname(APP_ROOT) . '/config.php';
    }

    public static function cargar(): array
    {
        $ruta = self::ruta();
        if (!is_file($ruta)) {
            throw new RuntimeException('falta el archivo config.php fuera de public_html (copia config.example.php)');
        }
        $c = require $ruta;
        if (!is_array($c)) {
            throw new RuntimeException('config.php debe devolver un array');
        }
        $c += [
            'holded_api_key'   => '',
            'holded_base'      => 'https://api.holded.com/api/v2',
            'usuario'          => 'carlos',
            'password_hash'    => '',
            'asientos_token'   => '',
            'data_dir'         => dirname($ruta) . '/holded-data',
            'escritura_holded' => false,
            'sesion_dias'      => 14,
        ];
        $c['escritura_holded'] = $c['escritura_holded'] === true;
        $c['sesion_dias'] = max(1, min(60, (int) $c['sesion_dias']));
        return $c;
    }
}
