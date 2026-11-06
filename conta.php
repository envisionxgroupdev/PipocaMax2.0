<?php
require __DIR__ . '/includes/bootstrap.php';
check_maintenance();
require_user();

$u = current_user();
$errors = [];
if (is_post()) {
    csrf_check();
    $full = q_one('SELECT * FROM users WHERE id = ?', [$u['id']]);
    $name = in_str('nome');
    $current = (string)($_POST['senha_atual'] ?? '');
    $new = (string)($_POST['senha_nova'] ?? '');
    if (mb_strlen($name) < 2) $errors[] = 'Informe seu nome.';
    if ($new !== '') {
        if (!password_verify($current, $full['password'])) $errors[] = 'Senha atual incorreta.';
        if (strlen($new) < 8) $errors[] = 'A nova senha precisa ter pelo menos 8 caracteres.';
    }
    if (!$errors) {
        q('UPDATE users SET name = ? WHERE id = ?', [mb_substr($name, 0, 120), $u['id']]);
        if ($new !== '') {
            q('UPDATE users SET password = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $u['id']]);
        }
        flash('success', 'Dados atualizados.');
        redirect('conta.php');
    }
}
$pageTitle = 'Minha conta';
$noindex = true;
$bodyClass = 'auth-page';
require __DIR__ . '/includes/header.php';
?>
<div class="auth">
  <form class="auth-box" method="post">
    <?= csrf_field() ?>
    <h1>Minha conta</h1>
    <?php foreach ($errors as $er): ?><div class="alert alert-error"><?= e($er) ?></div><?php endforeach; ?>
    <label class="field"><span>Nome</span><input name="nome" value="<?= e($u['name']) ?>" required></label>
    <label class="field"><span>E-mail</span><input value="<?= e($u['email']) ?>" disabled></label>
    <hr>
    <p class="muted small">Para trocar a senha, preencha os campos abaixo.</p>
    <label class="field"><span>Senha atual</span><input type="password" name="senha_atual" autocomplete="current-password"></label>
    <label class="field"><span>Nova senha</span><input type="password" name="senha_nova" minlength="8" autocomplete="new-password"></label>
    <button class="btn btn-red btn-block">Salvar</button>
  </form>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
