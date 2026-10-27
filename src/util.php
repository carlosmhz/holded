<?php
declare(strict_types=1);
defined('APP_ROOT') || exit;

/** Error que se puede enseñar tal cual al usuario (dato no válido, etc.). */
final class ErrorUsuario extends RuntimeException {}

function e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Redondeo a céntimos (equivale a redondea_ del script). */
function r2(float $v): float
{
    return round($v, 2, PHP_ROUND_HALF_UP);
}

/** Número de Holded o del formulario: "1.234,56", "1234.56", 12.5 → float. */
function num(mixed $v): float
{
    if (is_int($v) || is_float($v)) {
        return (float) $v;
    }
    $s = trim((string) $v);
    if (str_contains($s, ',')) {
        $s = str_replace(['.', ','], ['', '.'], $s);
    }
    return is_numeric($s) ? (float) $s : 0.0;
}

/** "1.234,56 €" con signo menos tipográfico (eur_ del script). */
function eur(float $x): string
{
    return ($x < 0 ? '−' : '') . number_format(abs($x), 2, ',', '.') . ' €';
}

function hoyISO(): string
{
    return (new DateTimeImmutable('now'))->format('Y-m-d');
}

/** Fecha aaaa-mm-dd válida o null. */
function fechaValida(?string $f): ?string
{
    if (!is_string($f) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $f, $m)) {
        return null;
    }
    return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $f : null;
}

function esHttps(): bool
{
    return (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443')
        || (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https');
}

/** Servidor de pruebas local (php -S): se permite HTTP. */
function esLocal(): bool
{
    return PHP_SAPI === 'cli-server';
}

function forzarHttps(): void
{
    if (esHttps() || esLocal() || PHP_SAPI === 'cli') {
        return;
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        header('Location: https://' . ($_SERVER['HTTP_HOST'] ?? 'holded.mpi-sala.es') . ($_SERVER['REQUEST_URI'] ?? '/'), true, 301);
    } else {
        http_response_code(403);
    }
    exit;
}

function cabecerasSeguridad(): void
{
    if (PHP_SAPI === 'cli') {
        return;
    }
    header_remove('X-Powered-By');
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; manifest-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'; object-src 'none'");
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header('Cache-Control: no-store');
    // HSTS lo pone el .htaccess (también para los archivos estáticos).
}

function jsonOut(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Cuerpo JSON de la petición (máx. 256 KB). */
function leerJson(int $max = 262144): array
{
    $raw = file_get_contents('php://input', false, null, 0, $max + 1);
    if ($raw === false || strlen($raw) > $max) {
        throw new ErrorUsuario('Petición demasiado grande');
    }
    $d = json_decode($raw, true);
    if (!is_array($d)) {
        throw new ErrorUsuario('JSON no válido');
    }
    return $d;
}
