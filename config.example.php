<?php
/**
 * EJEMPLO de configuración. NO pongas valores reales aquí.
 *
 * Copia este archivo como config.php UN NIVEL POR ENCIMA de public_html
 * (p. ej. /home/uXXXX/domains/holded.mpi-sala.es/config.php) con permisos 600,
 * y rellena los valores. Ese archivo no está en el repo.
 *
 *  - password_hash: abre https://holded.mpi-sala.es/setup.php mientras esté vacío.
 *    Te genera el hash y también un token aleatorio para asientos_token.
 *  - holded_api_key: Holded → Configuración → Desarrolladores → API.
 */
return [
    'holded_api_key'   => '',
    'usuario'          => 'carlos',
    'password_hash'    => '',
    'asientos_token'   => '',        // mínimo 32 caracteres; Apps Script lo envía en la cabecera X-Token
    'escritura_holded' => false,     // false = modo prueba: la página de Waylet no crea asientos
    'sesion_dias'      => 14,        // días que dura la sesión sin volver a entrar
    // 'data_dir'      => __DIR__ . '/holded-data',   // caché, sesiones y datos (por defecto, junto a este archivo)
];
