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
    // 1) Proteções contra robôs e abuso (Painel › Segurança)
    $limitTries = max(1, (int)sec('reg_tries_hour'));
    $limitDay = max(0, (int)sec('reg_per_ip_day'));
    if (sec_ip_blocked()) {
        $errors[] = 'Não é possível criar conta a partir desta rede.';
    } elseif (sec_count('register_try', 3600) >= $limitTries) {
        $errors[] = 'Muitas tentativas de cadastro. Aguarde um pouco e tente de novo.';
    } elseif ($hp = honeypot_check()) {
        $errors[] = $hp;
    } elseif ($cp = captcha_check('register')) {
        $errors[] = $cp;
    } elseif ($limitDay > 0 && (int)q_val('SELECT COUNT(*) FROM users WHERE register_ip = ? AND created_at > ?', [client_ip(), date('Y-m-d H:i:s', time() - 86400)]) >= $limitDay) {
        $errors[] = 'Limite de contas novas por conexão atingido. Tente de novo amanhã.';
    }
    if (!$errors) sec_hit('register_try');
    // 2) Dados do formulário
    if (!$errors) {
        if (mb_strlen($name) < 2) $errors[] = 'Informe seu nome.';
        if (preg_match('~https?://|www\.|<|>~i', $name)) $errors[] = 'O nome não pode ter links ou símbolos.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) $errors[] = 'E-mail inválido.';
        elseif ($em = sec_email_problem($email)) $errors[] = $em;
        if ($pp = sec_password_problem($pass, $email, $name)) $errors[] = $pp;
        if ($pass !== $pass2) $errors[] = 'As senhas não conferem.';
    }
    if (!$errors && q_val('SELECT 1 FROM users WHERE email = ?', [$email])) $errors[] = 'Já existe uma conta com esse e-mail.';
    if (!$errors) {
        $colors = ['#e50914', '#b81d24', '#f5a623', '#2ea043', '#1f6feb', '#8957e5', '#db61a2'];
        q('INSERT INTO users (name, email, password, avatar_color, register_ip) VALUES (?, ?, ?, ?, ?)',
            [mb_substr($name, 0, 120), $email, password_hash($pass, PASSWORD_DEFAULT), $colors[array_rand($colors)], client_ip()]);
        sec_hit('register_ok');
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
    <?= honeypot_field() ?>
    <?= captcha_widget('register') ?>
    <button class="btn btn-red btn-block">Criar conta</button>
    <p class="auth-alt">Já tem conta? <a href="<?= e(page_url('login')) ?>">Entrar</a>.</p>
  </form>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
