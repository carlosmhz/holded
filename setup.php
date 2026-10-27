<?php
/**
 * Ayuda para rellenar el config la primera vez. Solo funciona mientras password_hash esté vacío
 * en el config; después devuelve 404. No guarda nada: solo muestra el hash de la contraseña
 * que escribas y un token aleatorio para X-Token, para que los pegues tú en ../config.php.
 */
declare(strict_types=1);
require __DIR__ . '/src/bootstrap.php';

if (cfg()['password_hash'] !== '') {
    http_response_code(404);
    exit;
}

$hash = $error = null;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    Auth::comprobarCsrf();
    $p1 = (string) ($_POST['p1'] ?? '');
    $p2 = (string) ($_POST['p2'] ?? '');
    if (mb_strlen($p1) < 12) {
        $error = 'Mínimo 12 caracteres.';
    } elseif (!hash_equals($p1, $p2)) {
        $error = 'Las contraseñas no coinciden.';
    } else {
        $hash = password_hash($p1, PASSWORD_DEFAULT);
    }
}
cabecera('Configuración inicial · MPI Holded');
?>
<main class="acceso">
  <div class="caja-login">
    <img class="logo" src="/assets/logo-tarjeta.png" alt="" width="120" height="78">
    <h1>Configuración inicial</h1>
    <p class="sub">No se guarda nada aquí: copia los valores en config.php, fuera de public_html.</p>
    <div class="form">
    <?php if ($hash): ?>
      <label>password_hash</label><p><code><?= e($hash) ?></code></p>
      <label>asientos_token (para la cabecera X-Token)</label><p><code><?= e(bin2hex(random_bytes(32))) ?></code></p>
      <p class="ayuda">Cuando pegues el hash en config.php, esta página dejará de funcionar.</p>
    <?php else: ?>
      <form method="post" action="/setup.php">
        <input type="hidden" name="csrf" value="<?= e(Auth::csrf()) ?>">
        <label for="p1">Contraseña (mínimo 12 caracteres)</label><input id="p1" name="p1" type="password" autocomplete="new-password" required>
        <label for="p2">Repite la contraseña</label><input id="p2" name="p2" type="password" autocomplete="new-password" required>
        <button class="guardar" type="submit">Generar hash</button>
        <?php if ($error): ?><p class="error" role="alert"><?= e($error) ?></p><?php endif; ?>
      </form>
    <?php endif; ?>
    </div>
  </div>
</main>
<?php pie();
