<?php
declare(strict_types=1);
require __DIR__ . '/src/bootstrap.php';

/** Solo rutas locales como destino tras el login (evita redirecciones abiertas). */
function destino(?string $n): string
{
    $n = (string) $n;
    return preg_match('#^/[A-Za-z0-9_\-./?=&%]*$#', $n) && !str_starts_with($n, '//') && !str_contains($n, '\\') ? $n : '/waylet.php';
}

$next = destino($_GET['next'] ?? $_POST['next'] ?? null);
if (Auth::logueado()) {
    header('Location: ' . $next);
    exit;
}

$error = null;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    Auth::comprobarCsrf();
    $error = Auth::login((string) ($_POST['usuario'] ?? ''), (string) ($_POST['clave'] ?? ''));
    if ($error === null) {
        header('Location: ' . $next, true, 303);
        exit;
    }
} elseif (($s = Auth::bloqueo()) > 0) {
    $error = 'Demasiados intentos. Espera ' . (int) ceil($s / 60) . ' min.';
}

cabecera('Entrar · MPI Holded');
?>
<main class="acceso">
  <div class="caja-login">
    <img class="logo" src="/assets/logo-tarjeta.png" alt="" width="120" height="78">
    <h1>MPI Holded</h1>
    <p class="sub">Contabilidad de MPI. Acceso privado.</p>
    <form class="form" method="post" action="/login.php" autocomplete="on">
      <input type="hidden" name="csrf" value="<?= e(Auth::csrf()) ?>">
      <input type="hidden" name="next" value="<?= e($next) ?>">
      <label for="u">Usuario</label>
      <input id="u" name="usuario" autocomplete="username" autocapitalize="none" required>
      <label for="p">Contraseña</label>
      <input id="p" name="clave" type="password" autocomplete="current-password" required>
      <button class="guardar" type="submit">Entrar</button>
      <?php if ($error): ?><p class="error" role="alert"><?= e($error) ?></p><?php endif; ?>
    </form>
  </div>
</main>
<?php pie();
