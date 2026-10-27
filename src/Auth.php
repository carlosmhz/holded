<?php
declare(strict_types=1);
defined('APP_ROOT') || exit;

/**
 * Login de un único usuario, sesión segura, límite de intentos y CSRF.
 *  - Cookie __Host-…: Secure, HttpOnly, SameSite=Lax, path=/, sin dominio.
 *  - Sesiones guardadas en data_dir/sessions (fuera de public_html, no en el /tmp compartido).
 *  - Límite: 5 fallos por IP en 15 min, 20 fallos en total por hora → bloqueo temporal.
 */
final class Auth
{
    private const FALLOS_IP = 5, VENTANA_IP = 900;
    private const FALLOS_TOTAL = 20, VENTANA_TOTAL = 3600;

    private static array $cfg;
    private static Store $store;

    public static function iniciarSesion(array $cfg, Store $store): void
    {
        self::$cfg = $cfg;
        self::$store = $store;
        if (PHP_SAPI === 'cli' || session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $vida = $cfg['sesion_dias'] * 86400;
        $segura = !esLocal();
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.gc_maxlifetime', (string) $vida);
        ini_set('session.gc_probability', '1');
        ini_set('session.gc_divisor', '100');
        ini_set('session.sid_length', '48');
        ini_set('session.sid_bits_per_character', '6');
        session_save_path($store->dir('sessions'));
        session_name($segura ? '__Host-mpiholded' : 'mpiholded');
        session_set_cookie_params([
            'lifetime' => $vida, 'path' => '/', 'secure' => $segura,
            'httponly' => true, 'samesite' => 'Lax',
        ]);
        session_start();
        if (!empty($_SESSION['usuario']) && time() - (int) ($_SESSION['ultimo'] ?? 0) > $vida) {
            $_SESSION = [];
            session_regenerate_id(true);
        }
        if (!empty($_SESSION['usuario'])) {
            $_SESSION['ultimo'] = time();
        }
    }

    public static function logueado(): bool
    {
        return !empty($_SESSION['usuario']);
    }

    /** Para páginas: si no hay sesión, al login. */
    public static function exigirPagina(): void
    {
        if (!self::logueado()) {
            $next = $_SERVER['REQUEST_URI'] ?? '/';
            header('Location: /login.php?next=' . rawurlencode($next));
            exit;
        }
    }

    /** Para la API: 401 en JSON. */
    public static function exigirApi(): void
    {
        if (!self::logueado()) {
            jsonOut(['error' => 'Sesión caducada: vuelve a entrar', 'login' => true], 401);
        }
    }

    /* ── CSRF ── */

    public static function csrf(): string
    {
        return $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    }

    public static function comprobarCsrf(?string $token = null): void
    {
        $token ??= $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf'] ?? '');
        if (!is_string($token) || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $token)) {
            if (str_starts_with((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')) {
                jsonOut(['error' => 'Token CSRF no válido: recarga la página'], 403);
            }
            http_response_code(403);
            exit('Token CSRF no válido. Recarga la página.');
        }
    }

    /* ── Login ── */

    private static function ip(): string
    {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '?');
    }

    /** Segundos que quedan de bloqueo (0 = puede intentarlo). */
    public static function bloqueo(): int
    {
        $d = self::$store->leer('intentos', []);
        $ahora = time();
        $ip = array_filter($d['ip'][self::ip()] ?? [], fn($t) => $t > $ahora - self::VENTANA_IP);
        $tot = array_filter($d['total'] ?? [], fn($t) => $t > $ahora - self::VENTANA_TOTAL);
        $espera = 0;
        if (count($ip) >= self::FALLOS_IP) {
            $espera = max($espera, min($ip) + self::VENTANA_IP - $ahora);
        }
        if (count($tot) >= self::FALLOS_TOTAL) {
            $espera = max($espera, min($tot) + self::VENTANA_TOTAL - $ahora);
        }
        return $espera;
    }

    private static function anotarFallo(): void
    {
        $ip = self::ip();
        self::$store->actualizar('intentos', function ($d) use ($ip) {
            $ahora = time();
            $d = is_array($d) ? $d : [];
            $d['total'] = array_values(array_filter($d['total'] ?? [], fn($t) => $t > $ahora - self::VENTANA_TOTAL));
            $d['total'][] = $ahora;
            foreach ($d['ip'] ?? [] as $k => $ts) { // limpia IPs viejas
                $d['ip'][$k] = array_values(array_filter($ts, fn($t) => $t > $ahora - self::VENTANA_IP));
                if (!$d['ip'][$k]) {
                    unset($d['ip'][$k]);
                }
            }
            $d['ip'][$ip][] = $ahora;
            return $d;
        }, []);
    }

    private static function limpiarFallosIp(): void
    {
        $ip = self::ip();
        self::$store->actualizar('intentos', function ($d) use ($ip) {
            $d = is_array($d) ? $d : [];
            unset($d['ip'][$ip]);
            return $d;
        }, []);
    }

    /** Devuelve null si entra, o el mensaje de error. */
    public static function login(string $usuario, string $clave): ?string
    {
        if (self::$cfg['password_hash'] === '') {
            return 'Falta la contraseña en el config (usa setup.php)';
        }
        if (($s = self::bloqueo()) > 0) {
            return 'Demasiados intentos. Espera ' . (int) ceil($s / 60) . ' min.';
        }
        $okUsuario = hash_equals(strtolower((string) self::$cfg['usuario']), strtolower(trim($usuario)));
        $okClave = password_verify($clave, self::$cfg['password_hash']); // siempre se ejecuta (tiempo constante)
        if (!$okUsuario || !$okClave) {
            self::anotarFallo();
            usleep(random_int(400000, 900000));
            return 'Usuario o contraseña incorrectos';
        }
        self::limpiarFallosIp();
        session_regenerate_id(true);
        $_SESSION = ['usuario' => self::$cfg['usuario'], 'ultimo' => time(), 'csrf' => bin2hex(random_bytes(32))];
        return null;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        $p = session_get_cookie_params();
        setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => '/', 'secure' => $p['secure'], 'httponly' => true, 'samesite' => 'Lax']);
        session_destroy();
    }
}
