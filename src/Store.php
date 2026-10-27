<?php
declare(strict_types=1);
defined('APP_ROOT') || exit;

/**
 * Archivos de datos y caché, en data_dir (fuera de public_html).
 * Escrituras atómicas (tmp + rename) y bloqueo con flock para leer-modificar-escribir.
 */
final class Store
{
    public function __construct(private string $dir)
    {
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('No puedo crear la carpeta de datos');
        }
        // Por si alguien la pone dentro de public_html: que no se pueda leer por URL.
        $ht = $dir . '/.htaccess';
        if (!is_file($ht)) {
            @file_put_contents($ht, "Require all denied\n");
        }
        foreach (['cache', 'sessions'] as $sub) {
            if (!is_dir("$dir/$sub")) {
                @mkdir("$dir/$sub", 0700, true);
            }
        }
    }

    public function dir(string $sub = ''): string
    {
        return $sub === '' ? $this->dir : $this->dir . '/' . $sub;
    }

    private function ruta(string $nombre): string
    {
        if (!preg_match('/^[a-z0-9_\-\/]+$/i', $nombre) || str_contains($nombre, '..')) {
            throw new InvalidArgumentException('Nombre de archivo no válido');
        }
        return $this->dir . '/' . $nombre . '.json';
    }

    public function leer(string $nombre, mixed $defecto = null): mixed
    {
        $r = $this->ruta($nombre);
        if (!is_file($r)) {
            return $defecto;
        }
        $d = json_decode((string) @file_get_contents($r), true);
        return $d ?? $defecto;
    }

    public function escribir(string $nombre, mixed $datos): void
    {
        $r = $this->ruta($nombre);
        $tmp = $r . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION), LOCK_EX) === false) {
            throw new RuntimeException('No puedo escribir en la carpeta de datos');
        }
        @chmod($tmp, 0600);
        rename($tmp, $r);
    }

    /** Leer-modificar-escribir con bloqueo exclusivo. $fn recibe el valor y devuelve el nuevo. */
    public function actualizar(string $nombre, callable $fn, mixed $defecto = null): mixed
    {
        $lock = fopen($this->ruta($nombre) . '.lock', 'c');
        flock($lock, LOCK_EX);
        try {
            $nuevo = $fn($this->leer($nombre, $defecto));
            $this->escribir($nombre, $nuevo);
            return $nuevo;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** Caché con caducidad (segundos). Equivale a cacheado_ del script. */
    public function cache(string $clave, int $segundos, callable $fn): mixed
    {
        $nombre = 'cache/' . preg_replace('/[^a-z0-9_\-]/i', '_', $clave);
        $g = $this->leer($nombre);
        if (is_array($g) && isset($g['t']) && time() - (int) $g['t'] < $segundos) {
            return $g['v'];
        }
        $v = $fn();
        try {
            $this->escribir($nombre, ['t' => time(), 'v' => $v]);
        } catch (Throwable) {
        }
        return $v;
    }

    /** Botón "refrescar": borra toda la caché. Devuelve cuántos archivos ha borrado. */
    public function limpiarCache(): int
    {
        $n = 0;
        foreach (glob($this->dir . '/cache/*.json') ?: [] as $f) {
            $n += @unlink($f) ? 1 : 0;
        }
        return $n;
    }
}
