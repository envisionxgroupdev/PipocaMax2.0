<?php
require __DIR__ . '/includes/bootstrap.php';
check_maintenance();

if (current_user()) {
    redirect('index.php');
}
if (setting('allow_register') !== '1') {
    flash('info', 'Os cadastros estão fechados no momento.');
    redirect('login.php');
}
$errors = [];
$name = $email = '';
if (is_post()) {
    csrf_check();
    $name = in_str('nome');
    $email = mb_strtolower(in_str('email'));
    $pass = (string)($_POST['senha'] ?? '');
    $pass2 = (string)($_POST['senha2'] ?? '');
    if (mb_strlen($name) < 2) $errors[] = 'Informe seu nome.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'E-mail inválido.';
    if (strlen($pass) < 8) $errors[] = 'A senha precisa ter pelo menos 8 caracteres.';
    if ($pass !== $pass2) $errors[] = 'As senhas não conferem.';
    if (!$errors && q_val('SELECT 1 FROM users WHERE email = ?', [$email])) $errors[] = 'Já existe uma conta com esse e-mail.';
    if (!$errors) {
        $colors = ['#e50914', '#b81d24', '#f5a623', '#2ea043', '#1f6feb', '#8957e5', '#db61a2'];
        q('INSERT INTO users (name, email, password, avatar_color) VALUES (?, ?, ?, ?)',
            [mb_substr($name, 0, 120), $email, password_hash($pass, PASSWORD_DEFAULT), $colors[array_rand($colors)]]);
        $u = q_one('SELECT * FROM users WHERE id = ?', [(int)db()->lastInsertId()]);
        login_user($u);
        flash('success', 'Conta criada! Bem-vindo ao ' . site_name() . '.');
        redirect('index.php');
    }
}
$pageTitle = 'Criar conta';
$noindex = true;
$bodyClass = 'auth-page';
require __DIR__ . '/includes/header.php';
?>
<div class="auth">
  <form class="auth-box" method="post">
    <?= csrf_field() ?>
    <h1>Criar conta</h1>
    <?php foreach ($errors as $er): ?><div class="alert alert-error"><?= e($er) ?></div><?php endforeach; ?>
    <label class="field"><span>Nome</span><input name="nome" value="<?= e($name) ?>" required maxlength="120" autocomplete="name"></label>
    <label class="field"><span>E-mail</span><input type="email" name="email" value="<?= e($email) ?>" required autocomplete="email"></label>
    <label class="field"><span>Senha</span><input type="password" name="senha" required minlength="8" autocomplete="new-password"></label>
    <label class="field"><span>Repita a senha</span><input type="password" name="senha2" required minlength="8" autocomplete="new-password"></label>
    <button class="btn btn-red btn-block">Criar conta</button>
    <p class="auth-alt">Já tem conta? <a href="<?= e(page_url('login')) ?>">Entrar</a>.</p>
  </form>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
