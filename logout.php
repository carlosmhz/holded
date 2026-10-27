<?php
declare(strict_types=1);
require __DIR__ . '/src/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    exit;
}
Auth::comprobarCsrf();
Auth::logout();
header('Location: /login.php', true, 303);
