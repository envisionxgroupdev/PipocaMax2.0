<?php
/**
 * Pipoca Max - Instalador
 * Abra /install/ no navegador, preencha os dados do MySQL e pronto.
 */
declare(strict_types=1);
session_start();
$root = dirname(__DIR__);
$configFile = $root . '/config.php';

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$base = str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/install/index.php')));
$base = ($base === '/' || $base === '.') ? '' : rtrim($base, '/');

$installed = is_file($configFile);
$errors = [];
$done = false;

$req = [
    'PHP 8.0 ou superior' => version_compare(PHP_VERSION, '8.0.0', '>='),
    'Extensão PDO MySQL' => extension_loaded('pdo_mysql'),
    'Extensão mbstring' => extension_loaded('mbstring'),
    'Extensão cURL (para o TMDB)' => extension_loaded('curl') || ini_get('allow_url_fopen'),
    'Pasta do site com permissão de escrita' => is_writable($root) || ($installed && is_writable($configFile)),
];

$f = [
    'db_host' => $_POST['db_host'] ?? 'localhost',
    'db_port' => $_POST['db_port'] ?? '3306',
    'db_name' => $_POST['db_name'] ?? '',
    'db_user' => $_POST['db_user'] ?? '',
    'site_name' => $_POST['site_name'] ?? 'Pipoca Max',
    'admin_name' => $_POST['admin_name'] ?? '',
    'admin_email' => $_POST['admin_email'] ?? '',
    'tmdb' => $_POST['tmdb'] ?? '',
];

if (!$installed && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!hash_equals($_SESSION['inst_csrf'] ?? '', (string)($_POST['_csrf'] ?? ''))) {
        $errors[] = 'Sessão expirada, tente novamente.';
    }
    $dbPass = (string)($_POST['db_pass'] ?? '');
    $adminPass = (string)($_POST['admin_pass'] ?? '');
    if ($f['db_name'] === '' || $f['db_user'] === '') $errors[] = 'Preencha o nome do banco e o usuário do MySQL.';
    if (mb_strlen(trim($f['admin_name'])) < 2) $errors[] = 'Informe o nome do administrador.';
    if (!filter_var($f['admin_email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'E-mail do administrador inválido.';
    if (strlen($adminPass) < 8) $errors[] = 'A senha do administrador precisa ter pelo menos 8 caracteres.';

    if (!$errors) {
        try {
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $f['db_host'], (int)$f['db_port'], $f['db_name']);
            $pdo = new PDO($dsn, $f['db_user'], $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $pdo->exec('SET NAMES utf8mb4');

            $sql = file_get_contents(__DIR__ . '/database.sql');
            $sql = preg_replace('/^--.*$/m', '', $sql);
            foreach (preg_split('/;\s*(\r?\n|$)/', $sql) as $stmt) {
                if (trim($stmt) !== '') $pdo->exec($stmt);
            }

            $email = mb_strtolower(trim($f['admin_email']));
            $st = $pdo->prepare('SELECT id FROM users WHERE email = ?');
            $st->execute([$email]);
            $hash = password_hash($adminPass, PASSWORD_DEFAULT);
            if ($id = $st->fetchColumn()) {
                $pdo->prepare("UPDATE users SET name = ?, password = ?, role = 'admin', status = 'active' WHERE id = ?")->execute([trim($f['admin_name']), $hash, $id]);
            } else {
                $pdo->prepare("INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, 'admin')")->execute([trim($f['admin_name']), $email, $hash]);
            }
            $up = $pdo->prepare('INSERT INTO settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)');
            $up->execute(['site_name', trim($f['site_name']) ?: 'Pipoca Max']);
            if (trim($f['tmdb']) !== '') $up->execute(['tmdb_api_key', trim($f['tmdb'])]);

            $config = "<?php\n// Configuração do Pipoca Max (gerado pelo instalador em " . date('d/m/Y H:i') . ")\nreturn " . var_export([
                'db' => [
                    'host' => $f['db_host'],
                    'port' => (int)$f['db_port'],
                    'name' => $f['db_name'],
                    'user' => $f['db_user'],
                    'pass' => $dbPass,
                ],
                'base_url' => $base,
                'debug' => false,
            ], true) . ";\n";
            if (@file_put_contents($configFile, $config) === false) {
                $errors[] = 'Não consegui criar o arquivo config.php. Dê permissão de escrita na pasta do site (755/775) e tente de novo.';
            } else {
                @chmod($configFile, 0640);
                $done = true;
            }
        } catch (PDOException $e) {
            $msg = $e->getMessage();
            if (str_contains($msg, 'Access denied')) $errors[] = 'Usuário ou senha do MySQL incorretos (ou sem permissão nesse banco).';
            elseif (str_contains($msg, 'Unknown database')) $errors[] = 'O banco "' . $f['db_name'] . '" não existe. Crie ele no painel da hospedagem primeiro.';
            elseif (str_contains($msg, 'getaddrinfo') || str_contains($msg, 'Connection refused') || str_contains($msg, 'timed out')) $errors[] = 'Não consegui conectar no servidor MySQL "' . $f['db_host'] . '". Confira o host e a porta.';
            else $errors[] = 'Erro no MySQL: ' . $msg;
        }
    }
}
$_SESSION['inst_csrf'] = $_SESSION['inst_csrf'] ?? bin2hex(random_bytes(16));
$reqOk = !in_array(false, $req, true);
?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Instalação • Pipoca Max</title>
<style>
*{box-sizing:border-box}body{margin:0;font:15px/1.5 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;background:radial-gradient(circle at 20% 0,#3b0508,#0b0b0b 55%);color:#f2f2f2;min-height:100vh;padding:40px 16px}
.box{max-width:640px;margin:0 auto;background:#141414;border:1px solid #262626;border-radius:12px;padding:34px}
h1{margin:0 0 4px;font-size:30px;letter-spacing:.5px}h1 span{color:#e50914}h2{font-size:16px;margin:28px 0 12px;color:#e50914;text-transform:uppercase;letter-spacing:1px}
p.sub{color:#999;margin:0 0 20px}.grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}.full{grid-column:1/-1}
label{display:block}label span{display:block;font-size:13px;color:#aaa;margin-bottom:5px}
input{width:100%;height:44px;background:#222;border:1px solid #333;border-radius:6px;color:#fff;padding:0 12px;font:inherit}input:focus{outline:0;border-color:#e50914}
.btn{display:inline-block;background:#e50914;color:#fff;border:0;border-radius:6px;height:48px;padding:0 26px;font-weight:700;font-size:16px;cursor:pointer;text-decoration:none;line-height:48px;margin-top:22px}
.btn:hover{background:#b20710}.btn.sec{background:#333}
.err{background:#5c1015;border:1px solid #e50914;padding:12px 14px;border-radius:6px;margin-bottom:10px}
.ok{background:#12391f;border:1px solid #2ea043;padding:14px;border-radius:6px}
ul.req{list-style:none;padding:0;margin:0}ul.req li{padding:6px 0;border-bottom:1px solid #222;display:flex;justify-content:space-between}
.y{color:#3fb950}.n{color:#ff6b6b}small.h{color:#888;display:block;margin-top:4px}
@media(max-width:560px){.grid{grid-template-columns:1fr}.box{padding:24px}}
</style>
</head>
<body>
<div class="box">
  <h1>🍿 <span>Pipoca</span> Max</h1>
  <p class="sub">Instalação do site</p>

<?php if ($done): ?>
  <div class="ok"><b>Tudo pronto!</b> O banco foi criado e sua conta de administrador está ativa.</div>
  <p>Por segurança, apague a pasta <b>install</b> do servidor (ou renomeie).</p>
  <a class="btn" href="<?= h($base) ?>/admin/">Abrir o painel</a>
  <a class="btn sec" href="<?= h($base) ?>/">Ver o site</a>

<?php elseif ($installed): ?>
  <div class="ok">O Pipoca Max já está instalado.</div>
  <p>Para reinstalar, apague o arquivo <b>config.php</b> da pasta do site e abra esta página de novo. Recomendamos apagar a pasta <b>install</b>.</p>
  <a class="btn" href="<?= h($base) ?>/">Ir para o site</a>

<?php else: ?>
  <?php foreach ($errors as $er): ?><div class="err"><?= h($er) ?></div><?php endforeach; ?>

  <h2>Requisitos</h2>
  <ul class="req">
    <?php foreach ($req as $k => $ok): ?><li><?= h($k) ?> <b class="<?= $ok ? 'y' : 'n' ?>"><?= $ok ? 'OK' : 'Falta' ?></b></li><?php endforeach; ?>
  </ul>

  <form method="post" autocomplete="off">
    <input type="hidden" name="_csrf" value="<?= h($_SESSION['inst_csrf']) ?>">
    <h2>Banco de dados MySQL</h2>
    <div class="grid">
      <label><span>Servidor (host)</span><input name="db_host" value="<?= h($f['db_host']) ?>" required><small class="h">Geralmente "localhost" ou o endereço que a hospedagem informou.</small></label>
      <label><span>Porta</span><input name="db_port" value="<?= h($f['db_port']) ?>" required></label>
      <label class="full"><span>Nome do banco</span><input name="db_name" value="<?= h($f['db_name']) ?>" required></label>
      <label><span>Usuário</span><input name="db_user" value="<?= h($f['db_user']) ?>" required></label>
      <label><span>Senha</span><input type="password" name="db_pass"></label>
    </div>

    <h2>Site</h2>
    <div class="grid">
      <label class="full"><span>Nome do site</span><input name="site_name" value="<?= h($f['site_name']) ?>" required></label>
      <label class="full"><span>Chave da API do TMDB (pode colocar depois)</span><input name="tmdb" value="<?= h($f['tmdb']) ?>"><small class="h">Chave da API (v3) ou Token de leitura (v4) de themoviedb.org → Configurações → API.</small></label>
    </div>

    <h2>Sua conta de administrador</h2>
    <div class="grid">
      <label class="full"><span>Seu nome</span><input name="admin_name" value="<?= h($f['admin_name']) ?>" required></label>
      <label><span>E-mail</span><input type="email" name="admin_email" value="<?= h($f['admin_email']) ?>" required></label>
      <label><span>Senha</span><input type="password" name="admin_pass" minlength="8" required></label>
    </div>
    <button class="btn" <?= $reqOk ? '' : 'disabled' ?>>Instalar o Pipoca Max</button>
  </form>
<?php endif; ?>
</div>
</body>
</html>
