<?php
/**
 * Pipoca Max - inicialização comum a todas as páginas.
 */
declare(strict_types=1);

define('PM_ROOT', dirname(__DIR__));
define('PM_VERSION', '2.0.3');
define('PM_LOG', PM_ROOT . '/includes/logs/erros.log');

/** Registro de erros: grava em includes/logs/erros.log (protegido) e mostra uma página amigável */
function pm_log_error(string $msg): string
{
    $code = substr(md5($msg . microtime()), 0, 6);
    $dir = dirname(PM_LOG);
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    if (is_file(PM_LOG) && filesize(PM_LOG) > 512000) @rename(PM_LOG, PM_LOG . '.old');
    @file_put_contents(PM_LOG, '[' . date('d/m/Y H:i:s') . '] [' . $code . '] ' . ($_SERVER['REQUEST_METHOD'] ?? '') . ' ' . ($_SERVER['REQUEST_URI'] ?? '') . ' — ' . $msg . "\n", FILE_APPEND | LOCK_EX);
    return $code;
}
function pm_error_page(string $code): void
{
    if (!headers_sent()) http_response_code(500);
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><body style="background:#0b0b0b;color:#eee;font-family:sans-serif;padding:40px;text-align:center">'
       . '<h2 style="color:#e50914">Ops, algo deu errado</h2><p>Código do erro: <b>' . htmlspecialchars($code) . '</b></p>'
       . '<p style="color:#999">O erro foi registrado no painel (Configurações › Sistema).</p><p><a style="color:#e50914" href="javascript:history.back()">Voltar</a></p></body>';
}
set_exception_handler(function (Throwable $e) {
    pm_error_page(pm_log_error(get_class($e) . ': ' . $e->getMessage() . ' em ' . basename($e->getFile()) . ':' . $e->getLine()));
});
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        pm_error_page(pm_log_error('Erro fatal: ' . $e['message'] . ' em ' . basename($e['file']) . ':' . $e['line']));
    }
});

mb_internal_encoding('UTF-8');
date_default_timezone_set('America/Sao_Paulo');

$configFile = PM_ROOT . '/config.php';
if (!is_file($configFile)) {
    // Ainda não instalado: manda para o instalador
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/');
    $base = preg_replace('#/(admin|install)(/.*)?$#', '', dirname($script));
    header('Location: ' . rtrim($base, '/') . '/install/');
    exit;
}
$CONFIG = require $configFile;

if (!empty($CONFIG['debug'])) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
}

define('BASE_URL', rtrim($CONFIG['base_url'] ?? '', '/'));

// Sessão segura
// Cabeçalhos de segurança
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
    header_remove('X-Powered-By');
}

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_name('pipocamax');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => BASE_URL === '' ? '/' : BASE_URL . '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// Formulário inteiro enviado "embrulhado" pelo painel (_p), para o firewall da hospedagem não barrar
if (isset($_POST['_p']) && is_string($_POST['_p'])) {
    $raw = base64_decode(strrev($_POST['_p']), true);
    if ($raw !== false) {
        $data = [];
        parse_str($raw, $data);
        $csrf = $_POST['_csrf'] ?? null;
        $_POST = $data;
        if ($csrf !== null) $_POST['_csrf'] = $csrf;
    }
}
// Campos enviados codificados pelo painel (b64:...) para não serem barrados pelo firewall da hospedagem
array_walk_recursive($_POST, function (&$v) {
    if (is_string($v) && str_starts_with($v, 'b64:')) {
        $d = base64_decode(substr($v, 4), true);
        if ($d !== false && mb_check_encoding($d, 'UTF-8')) $v = $d;
    }
});

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/xtream.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/tmdb.php';
require_once __DIR__ . '/autoimport.php';
require_once __DIR__ . '/ads.php';
require_once __DIR__ . '/tvimport.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/seo.php';

try {
    db();
} catch (Throwable $e) {
    http_response_code(500);
    echo '<!doctype html><meta charset="utf-8"><body style="background:#0b0b0b;color:#fff;font-family:sans-serif;padding:40px">'
       . '<h2 style="color:#e50914">Erro de conexão com o banco de dados</h2>'
       . '<p>Confira os dados no arquivo <b>config.php</b> (host, nome do banco, usuário e senha).</p>'
       . (!empty($CONFIG['debug']) ? '<pre>' . htmlspecialchars($e->getMessage()) . '</pre>' : '')
       . '</body>';
    exit;
}

load_settings();
// Atualiza o banco automaticamente quando sai versão nova
if ((int)setting('db_version', '1') < 2) {
    q("CREATE TABLE IF NOT EXISTS login_attempts (
        ip VARCHAR(45) NOT NULL PRIMARY KEY,
        attempts INT UNSIGNED NOT NULL DEFAULT 0,
        last_attempt DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    set_setting('db_version', '2');
}
if ((int)setting('db_version', '1') < 3) {
    try { q('ALTER TABLE channels ADD COLUMN source VARCHAR(40) NULL'); } catch (Throwable $e) {}
    try { q('ALTER TABLE channels ADD COLUMN ext_key VARCHAR(64) NULL'); } catch (Throwable $e) {}
    try { q('CREATE INDEX idx_ch_source ON channels (source, ext_key)'); } catch (Throwable $e) {}
    set_setting('db_version', '3');
}
if ((int)setting('db_version', '1') < 4) {
    foreach ([
        'CREATE INDEX idx_c_created ON contents (status, created_at)',
        'CREATE INDEX idx_c_release ON contents (status, is_release, release_date)',
        'CREATE INDEX idx_c_views ON contents (status, views)',
        'CREATE INDEX idx_c_featured ON contents (status, featured, updated_at)',
        'CREATE INDEX idx_c_typeupd ON contents (type, status, updated_at)',
        'CREATE INDEX idx_ch_status ON channels (status, sort, name)',
    ] as $ix) { try { q($ix); } catch (Throwable $e) {} }
    set_setting('db_version', '4');
}
if ((int)setting('db_version', '1') < 5 && (int)setting('mig5_lock', '0') < time() - 120) {
    set_setting('mig5_lock', (string)time());
    @set_time_limit(120);
    try { q('ALTER TABLE channels ADD COLUMN slug VARCHAR(140) NULL'); } catch (Throwable $e) {}
    try { q('CREATE INDEX idx_ch_slug ON channels (slug)'); } catch (Throwable $e) {}
    // endereços sem número: os mais vistos ficam com o nome "limpo"
    $used = [];
    $pdo = db();
    $pdo->beginTransaction();
    foreach (q_all('SELECT id, type, title, year FROM contents ORDER BY views DESC, id ASC') as $r) {
        $base = slugify($r['title']);
        $s = $base;
        if (isset($used[$r['type']][$s]) && $r['year']) $s = $base . '-' . (int)$r['year'];
        $n = 2;
        $root = $s;
        while (isset($used[$r['type']][$s])) $s = $root . '-' . $n++;
        $used[$r['type']][$s] = true;
        q('UPDATE contents SET slug = ? WHERE id = ?', [$s, $r['id']]);
    }
    $usedCh = [];
    foreach (q_all('SELECT id, name FROM channels ORDER BY views DESC, id ASC') as $r) {
        $base = slugify($r['name']);
        $s = $base; $n = 2;
        while (isset($usedCh[$s])) $s = $base . '-' . $n++;
        $usedCh[$s] = true;
        q('UPDATE channels SET slug = ? WHERE id = ?', [$s, $r['id']]);
    }
    $pdo->commit();
    set_setting('db_version', '5');
    if (function_exists('cache_bump')) cache_bump();
}
if ((int)setting('db_version', '1') < 6) {
    set_setting('db_version', '6');
}
if ((int)setting('db_version', '1') < 7) {
    q("CREATE TABLE IF NOT EXISTS notifications (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(150) NOT NULL,
        message TEXT NULL,
        link VARCHAR(255) NULL,
        image VARCHAR(255) NULL,
        content_id INT UNSIGNED NULL,
        audience VARCHAR(10) NOT NULL DEFAULT 'all',
        active TINYINT(1) NOT NULL DEFAULT 1,
        expires_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_active (active, id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    try { q('ALTER TABLE users ADD COLUMN notif_seen INT UNSIGNED NOT NULL DEFAULT 0'); } catch (Throwable $e) { /* já existe */ }
    set_setting('db_version', '7');
}
if ((int)setting('db_version', '1') < 8) {
    // MixDrop removido: apaga a fila e os dados da conta
    q('DROP TABLE IF EXISTS mixdrop_jobs');
    q("DELETE FROM settings WHERE `key` LIKE 'mixdrop\\_%'");
    set_setting('db_version', '8');
}
if ((int)setting('db_version', '1') < 9) {
    // Novo tipo de player: "Player próprio (Video.js)". Os tipos antigos continuam iguais.
    q("ALTER TABLE players MODIFY kind ENUM('embed','video','hls','videojs') NOT NULL DEFAULT 'embed'");
    q("ALTER TABLE channels MODIFY kind ENUM('embed','video','hls','videojs') NOT NULL DEFAULT 'hls'");
    set_setting('db_version', '9');
}
if ((int)setting('db_version', '1') < 10) {
    // O mapa de associação Xtream pode conter milhares de episódios.
    q('ALTER TABLE settings MODIFY `value` MEDIUMTEXT NULL');
    set_setting('db_version', '10');
}
if ((int)setting('db_version', '1') < 11) {
    // Tira o aviso "em teste" do player próprio
    set_setting('vjs_api_test', '0');
    set_setting('db_version', '11');
}
if ((int)setting('db_version', '1') < 12) {
    // Proteções de cadastro: eventos por IP e IP de cadastro dos usuários
    q("CREATE TABLE IF NOT EXISTS security_events (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        ip VARCHAR(45) NOT NULL,
        action VARCHAR(20) NOT NULL,
        created_at DATETIME NOT NULL,
        KEY idx_ip_action (ip, action, created_at),
        KEY idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    try { q('ALTER TABLE users ADD COLUMN register_ip VARCHAR(45) NULL'); } catch (Throwable $e) {}
    try { q('CREATE INDEX idx_users_regip ON users (register_ip, created_at)'); } catch (Throwable $e) {}
    set_setting('db_version', '12');
}
if ((int)setting('db_version', '1') < 13) {
    // SEO por título (opcional): título e descrição personalizados
    try { q('ALTER TABLE contents ADD COLUMN seo_title VARCHAR(160) NULL'); } catch (Throwable $e) {}
    try { q('ALTER TABLE contents ADD COLUMN seo_desc VARCHAR(320) NULL'); } catch (Throwable $e) {}
    set_setting('db_version', '13');
}
refresh_current_user();
