<?php
declare(strict_types=1);
defined('APP_ROOT') || exit;

function cabecera(string $titulo, array $js = []): void
{
    $csrf = Auth::logueado() ? Auth::csrf() : '';
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="es"><head><meta charset="utf-8">',
        '<meta name="viewport" content="width=device-width, initial-scale=1">',
        '<meta name="theme-color" content="#ff7b00">',
        '<meta name="robots" content="noindex, nofollow">',
        '<meta name="csrf" content="', e($csrf), '">',
        '<title>', e($titulo), '</title>',
        '<link rel="manifest" href="/manifest.webmanifest">',
        '<link rel="icon" href="/assets/icon.svg" type="image/svg+xml">',
        '<link rel="apple-touch-icon" href="/assets/icon-180.png">',
        '<link rel="stylesheet" href="/assets/app.css?v=1">';
    foreach ($js as $f) {
        echo '<script src="/assets/', e($f), '?v=1" defer></script>';
    }
    echo '</head><body>';
}

function pie(): void
{
    echo '</body></html>';
}

/** Formulario de salir (POST + CSRF). */
function botonSalir(): string
{
    return '<form method="post" action="/logout.php" class="salir"><input type="hidden" name="csrf" value="'
        . e(Auth::csrf()) . '"><button type="submit" title="Salir">Salir</button></form>';
}
