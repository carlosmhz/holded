# holded.mpi-sala.es

Web de contabilidad de MPI sobre la API REST de Holded (v2). Sustituye a las páginas web
del proyecto de Apps Script. Los automatismos (correo/Drive → escáner, asientos programados,
subvenciones, escáner → compras) siguen en Apps Script.

- **Fase 1 (esta):** página de Waylet (`/waylet.php`), login, diagnóstico y endpoint de asientos.
- **Fase 2:** panel de consulta.

PHP 8.1+ sin Composer (cURL y JSON nativos).

## Estructura

Hostinger despliega `main` entero en `public_html`, así que lo interno se bloquea por URL
(`.htaccess` raíz + `src/.htaccess`) y los secretos viven **fuera** de `public_html`:

```
domains/holded.mpi-sala.es/
├── config.php        ← secretos (NO está en el repo; copia de config.example.php, permisos 600)
├── holded-data/      ← caché, sesiones, intentos de login, asientos.json (se crea sola)
└── public_html/      ← este repo
    ├── index.php, login.php, logout.php, waylet.php, diag.php, setup.php
    ├── api/waylet.php      (con sesión + CSRF)
    ├── api/asientos.php    (POST de Apps Script con X-Token)
    ├── assets/ manifest.webmanifest
    └── src/          ← código interno (403 por URL)
```

## Puesta en marcha

1. Sube `config.php` a `domains/holded.mpi-sala.es/` (un nivel por encima de `public_html`),
   copiando `config.example.php`. Pon la API key de Holded.
2. Abre `https://holded.mpi-sala.es/setup.php`, escribe la contraseña y copia el `password_hash`
   y el `asientos_token` que te da al `config.php`. Desde ese momento `setup.php` da 404.
3. Entra y abre `/diag.php`: todo debe salir en verde (PHP, HTTPS, config fuera de public_html,
   carpeta de datos escribible, conexión con Holded).
4. `escritura_holded` empieza en `false` (modo prueba: la página dice qué asiento crearía pero
   no lo crea). Ponlo a `true` cuando lo hayas revisado.

## Endpoint de asientos (para Apps Script)

`POST https://holded.mpi-sala.es/api/asientos.php`, cabecera `X-Token: <asientos_token>`,
`Content-Type: application/json`:

```json
{"generado":"2026-10-28T07:00:00","filas":[{"fecha":"2026-11-03","concepto":"…","importe":123.45}]}
```

Respuestas: `200 {"ok":true,"filas":N}` · `401` token incorrecto · `405` no es POST · `422` JSON no válido.
Cada envío reemplaza al anterior.

## Seguridad

- Un único usuario; contraseña con `password_hash`. 5 fallos por IP en 15 min o 20 en una hora → bloqueo.
- Sesión en cookie `__Host-` (Secure, HttpOnly, SameSite=Lax), guardada en `holded-data/sessions`.
- CSRF en todos los POST (formulario o cabecera `X-CSRF-Token`).
- Solo HTTPS (redirección 301 + HSTS). CSP sin scripts en línea.
- La API key de Holded solo se usa en el servidor.
