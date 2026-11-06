<?php
/**
 * Pipoca Max - inicialização comum a todas as páginas.
 */
declare(strict_types=1);

define('PM_ROOT', dirname(__DIR__));
define('PM_VERSION', '2.0.0');

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

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/tmdb.php';

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
refresh_current_user();
