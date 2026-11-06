<?php
require __DIR__ . '/includes/bootstrap.php';
// Sem check_maintenance: administradores precisam conseguir entrar.

$back = safe_back(in_str('voltar'));
if (current_user()) {
    redirect($back);
}
$error = '';
$email = '';
if (is_post()) {
    csrf_check();
    $email = mb_strtolower(in_str('email'));
    $pass = (string)($_POST['senha'] ?? '');
    if (login_throttled()) {
        $error = 'Muitas tentativas. Aguarde alguns minutos e tente de novo.';
    } else {
        $u = q_one('SELECT * FROM users WHERE email = ?', [$email]);
        if (!$u || !password_verify($pass, $u['password'])) {
            login_failed();
            $error = 'E-mail ou senha incorretos.';
        } elseif ($u['status'] !== 'active') {
            login_failed();
            $error = 'E-mail ou senha incorretos.';
        } else {
            if (password_needs_rehash($u['password'], PASSWORD_DEFAULT)) {
                q('UPDATE users SET password = ? WHERE id = ?', [password_hash($pass, PASSWORD_DEFAULT), $u['id']]);
            }
            login_reset();
            login_user($u);
            if (setting('maintenance') === '1' && $u['role'] !== 'admin') {
                logout_user();
                session_start();
                $error = 'O site está em manutenção. Tente mais tarde.';
            } else {
                redirect($back);
            }
        }
    }
}
$pageTitle = 'Entrar';
$noindex = true;
$bodyClass = 'auth-page';
require __DIR__ . '/includes/header.php';
?>
<div class="auth">
  <form class="auth-box" method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="voltar" value="<?= e($back) ?>">
    <h1>Entrar</h1>
    <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
    <label class="field"><span>E-mail</span><input type="email" name="email" value="<?= e($email) ?>" required autocomplete="email" autofocus></label>
    <label class="field"><span>Senha</span><input type="password" name="senha" required autocomplete="current-password"></label>
    <button class="btn btn-red btn-block">Entrar</button>
    <?php if (setting('allow_register') === '1'): ?>
      <p class="auth-alt">Novo por aqui? <a href="<?= e(page_url('cadastro')) ?>">Crie sua conta</a>.</p>
    <?php endif; ?>
  </form>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
