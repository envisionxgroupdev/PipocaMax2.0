<?php
/**
 * Autenticação de usuários.
 */

$GLOBALS['PM_USER'] = null;

function refresh_current_user(): void
{
    if (empty($_SESSION['uid'])) {
        return;
    }
    $u = q_one('SELECT id, name, email, role, status, avatar_color FROM users WHERE id = ?', [(int)$_SESSION['uid']]);
    if (!$u || $u['status'] !== 'active') {
        unset($_SESSION['uid']);
        return;
    }
    $GLOBALS['PM_USER'] = $u;
}

function current_user(): ?array
{
    return $GLOBALS['PM_USER'];
}

function is_admin(): bool
{
    $u = current_user();
    return $u && $u['role'] === 'admin';
}

function login_user(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['uid'] = (int)$user['id'];
    q('UPDATE users SET last_login = NOW() WHERE id = ?', [(int)$user['id']]);
    refresh_current_user();
}

function logout_user(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'] ?? '', $p['secure'], $p['httponly']);
    }
    session_destroy();
}

function client_ip(): string
{
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
}

/** Bloqueia o IP por 15 minutos depois de 8 senhas erradas (protege contra força bruta) */
function login_throttled(): bool
{
    $r = q_one('SELECT attempts, last_attempt FROM login_attempts WHERE ip = ?', [client_ip()]);
    if (!$r) return false;
    if (strtotime($r['last_attempt']) < time() - 900) {
        q('DELETE FROM login_attempts WHERE ip = ?', [client_ip()]);
        return false;
    }
    return (int)$r['attempts'] >= 8;
}

function login_failed(): void
{
    q('INSERT INTO login_attempts (ip, attempts, last_attempt) VALUES (?, 1, NOW())
       ON DUPLICATE KEY UPDATE attempts = attempts + 1, last_attempt = NOW()', [client_ip()]);
    usleep(400000); // atrasa um pouco cada erro
}

function login_reset(): void
{
    q('DELETE FROM login_attempts WHERE ip = ?', [client_ip()]);
}

function require_admin(): void
{
    if (!current_user()) {
        redirect(page_url('login', 'voltar=' . urlencode($_SERVER['REQUEST_URI'] ?? '')));
    }
    if (!is_admin()) {
        http_response_code(403);
        exit('Acesso restrito aos administradores.');
    }
}

function require_user(): void
{
    if (!current_user()) {
        redirect(page_url('login', 'voltar=' . urlencode($_SERVER['REQUEST_URI'] ?? '')));
    }
}

/** Só permite voltar para caminhos internos */
function safe_back(string $back): string
{
    if ($back === '' || !str_starts_with($back, '/') || str_starts_with($back, '//')) {
        return url('index.php');
    }
    return $back;
}
