<?php
declare(strict_types=1);
defined('APP_ROOT') || exit;

const VERSION_ESTATICOS = '4';

function cabecera(string $titulo, array $js = [], string $claseBody = ''): void
{
    $csrf = Auth::logueado() ? Auth::csrf() : '';
    $v = VERSION_ESTATICOS;
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="es"><head><meta charset="utf-8">',
        '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">',
        '<meta name="theme-color" content="#2B43B1" media="(prefers-color-scheme: light)">',
        '<meta name="theme-color" content="#0A1130" media="(prefers-color-scheme: dark)">',
        '<meta name="robots" content="noindex, nofollow">',
        '<meta name="apple-mobile-web-app-capable" content="yes">',
        '<meta name="apple-mobile-web-app-title" content="MPI Holded">',
        '<meta name="csrf" content="', e($csrf), '">',
        '<title>', e($titulo), '</title>',
        '<link rel="manifest" href="/manifest.webmanifest">',
        '<link rel="icon" href="/assets/favicon.png?v=4" type="image/png">',
        '<link rel="apple-touch-icon" href="/assets/icon-180.png?v=4">',
        '<link rel="preload" href="/assets/fonts/archivo.woff2" as="font" type="font/woff2" crossorigin>',
        '<link rel="stylesheet" href="/assets/app.css?v=', $v, '">',
        '<script src="/assets/app.js?v=', $v, '" defer></script>';
    foreach ($js as $f) {
        echo '<script src="/assets/', e($f), '?v=', $v, '" defer></script>';
    }
    echo '</head><body', $claseBody !== '' ? ' class="' . e($claseBody) . '"' : '', '>';
}

function pie(): void
{
    echo '</body></html>';
}

/** Formulario de salir (POST + CSRF). */
function botonSalir(): string
{
    return '<form method="post" action="/logout.php" class="salir"><input type="hidden" name="csrf" value="'
        . e(Auth::csrf()) . '"><button type="submit">Salir</button></form>';
}

/** Barra superior con la marca y la navegación entre Panel y Waylet. */
function barra(string $actual): string
{
    $enlace = fn(string $url, string $txt) => '<a href="' . $url . '"' . ($actual === $url ? ' aria-current="page"' : '') . '>' . $txt . '</a>';
    return '<header class="barra"><a class="marca" href="/panel.php"><img src="/assets/logo-tarjeta.png?v=4" alt="" width="38" height="25"><span>MPI Holded</span></a>'
        . '<nav class="nav" aria-label="Secciones">' . $enlace('/panel.php', 'Panel') . $enlace('/waylet.php', 'Waylet') . '</nav>'
        . botonSalir() . '</header>';
}
