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

// Campos enviados codificados pelo painel (b64:...) para não serem barrados pelo firewall da hospedagem
array_walk_recursive($_POST, function (&$v) {
    if (is_string($v) && str_starts_with($v, 'b64:')) {
        $d = base64_decode(substr($v, 4), true);
        if ($d !== false && mb_check_encoding($d, 'UTF-8')) $v = $d;
    }
});

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
