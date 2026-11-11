<?php
/**
 * Proteções de cadastro e login (Painel › Segurança):
 * limite por IP, campo-armadilha + tempo mínimo, CAPTCHA (Turnstile / reCAPTCHA / hCaptcha),
 * e-mails descartáveis, verificação do domínio do e-mail, senhas comuns e IPs bloqueados.
 */

function sec_defaults(): array
{
    return [
        'reg_per_ip_day' => 3,        // contas novas por IP em 24h
        'reg_tries_hour' => 10,       // tentativas de cadastro por IP por hora
        'login_max' => 8,             // senhas erradas antes de bloquear
        'login_minutes' => 15,        // minutos de bloqueio
        'honeypot' => 1,              // campo invisível + tempo mínimo para preencher
        'min_seconds' => 3,
        'block_disposable' => 1,      // e-mails temporários
        'check_mx' => 1,              // domínio do e-mail precisa existir
        'block_common_pass' => 1,     // senhas fracas conhecidas
        'captcha' => 'none',          // none | turnstile | recaptcha | hcaptcha
        'captcha_site' => '',
        'captcha_login' => 0,         // pedir CAPTCHA também no login
        'cloudflare' => 0,            // site atrás do Cloudflare: usa o IP real do visitante
        'extra_domains' => '',        // mais domínios bloqueados (um por linha)
        'blocked_ips' => '',          // IPs bloqueados (um por linha; aceita 1.2.3.* e 1.2.3.0/24)
    ];
}

function sec(string $key)
{
    static $cfg = null;
    if ($cfg === null) {
        $saved = json_decode((string)setting('security_config', ''), true);
        $cfg = array_merge(sec_defaults(), is_array($saved) ? array_intersect_key($saved, sec_defaults()) : []);
    }
    return $cfg[$key] ?? null;
}

/* ---------- IP ---------- */

function sec_ip_matches(string $ip, string $rule): bool
{
    $rule = trim($rule);
    if ($rule === '' || $rule[0] === '#') return false;
    if (str_contains($rule, '*')) {
        return (bool)preg_match('/^' . str_replace('\*', '[0-9a-f:.]*', preg_quote($rule, '/')) . '$/i', $ip);
    }
    if (str_contains($rule, '/')) {
        [$net, $bits] = explode('/', $rule, 2);
        $a = @inet_pton($ip); $b = @inet_pton($net); $bits = (int)$bits;
        if ($a === false || $b === false || strlen($a) !== strlen($b)) return false;
        $bytes = intdiv($bits, 8); $rest = $bits % 8;
        if (substr($a, 0, $bytes) !== substr($b, 0, $bytes)) return false;
        if ($rest === 0) return true;
        $mask = chr((0xFF << (8 - $rest)) & 0xFF);
        return ($a[$bytes] & $mask) === ($b[$bytes] & $mask);
    }
    return strcasecmp($ip, $rule) === 0;
}

function sec_ip_blocked(?string $ip = null): bool
{
    $ip = $ip ?? client_ip();
    foreach (preg_split('/\R/', (string)sec('blocked_ips')) as $rule) {
        if (sec_ip_matches($ip, $rule)) return true;
    }
    return false;
}

/* ---------- Limite por IP ---------- */

function sec_hit(string $action): void
{
    try {
        q('INSERT INTO security_events (ip, action, created_at) VALUES (?, ?, NOW())', [client_ip(), $action]);
        if (mt_rand(1, 50) === 1) q('DELETE FROM security_events WHERE created_at < ?', [date('Y-m-d H:i:s', time() - 7 * 86400)]);
    } catch (Throwable $e) {}
}

function sec_count(string $action, int $seconds): int
{
    try {
        return (int)q_val('SELECT COUNT(*) FROM security_events WHERE ip = ? AND action = ? AND created_at > ?', [client_ip(), $action, date('Y-m-d H:i:s', time() - $seconds)]);
    } catch (Throwable $e) {
        return 0;
    }
}

/* ---------- Campo-armadilha (robôs preenchem, pessoas não veem) ---------- */

function honeypot_field(): string
{
    if (!sec('honeypot')) return '';
    $t = time();
    $sig = hash_hmac('sha256', (string)$t, session_id() . 'pm-hp');
    return '<div class="hp-field" aria-hidden="true"><label>Não preencha este campo<input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>'
        . '<input type="hidden" name="_t" value="' . $t . '.' . substr($sig, 0, 16) . '">';
}

/** Retorna o erro, ou null se passou */
function honeypot_check(): ?string
{
    if (!sec('honeypot')) return null;
    if (trim((string)($_POST['website'] ?? '')) !== '') return 'Não foi possível concluir. Tente de novo.';
    [$t, $sig] = array_pad(explode('.', (string)($_POST['_t'] ?? ''), 2), 2, '');
    if (!ctype_digit($t) || !hash_equals(substr(hash_hmac('sha256', $t, session_id() . 'pm-hp'), 0, 16), $sig)) {
        return 'O formulário expirou. Recarregue a página e tente de novo.';
    }
    $age = time() - (int)$t;
    if ($age < max(0, (int)sec('min_seconds'))) return 'Você preencheu rápido demais. Aguarde um instante e envie de novo.';
    if ($age > 7200) return 'O formulário expirou. Recarregue a página e tente de novo.';
    return null;
}

/* ---------- CAPTCHA ---------- */

function captcha_on(string $where): bool
{
    $p = sec('captcha');
    if (!in_array($p, ['turnstile', 'recaptcha', 'hcaptcha'], true) || sec('captcha_site') === '' || (string)setting('security_captcha_secret') === '') return false;
    return $where === 'register' || ($where === 'login' && sec('captcha_login'));
}

function captcha_widget(string $where): string
{
    if (!captcha_on($where)) return '';
    $key = e(sec('captcha_site'));
    switch (sec('captcha')) {
        case 'turnstile':
            return '<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script><div class="cf-turnstile captcha-box" data-sitekey="' . $key . '" data-theme="dark" data-language="pt-br"></div>';
        case 'recaptcha':
            return '<script src="https://www.google.com/recaptcha/api.js?hl=pt-BR" async defer></script><div class="g-recaptcha captcha-box" data-sitekey="' . $key . '" data-theme="dark"></div>';
        case 'hcaptcha':
            return '<script src="https://js.hcaptcha.com/1/api.js?hl=pt" async defer></script><div class="h-captcha captcha-box" data-sitekey="' . $key . '" data-theme="dark"></div>';
    }
    return '';
}

/** Confere o CAPTCHA no servidor do provedor. Retorna o erro, ou null se passou */
function captcha_check(string $where): ?string
{
    if (!captcha_on($where)) return null;
    $field = ['turnstile' => 'cf-turnstile-response', 'recaptcha' => 'g-recaptcha-response', 'hcaptcha' => 'h-captcha-response'][sec('captcha')];
    $token = (string)($_POST[$field] ?? '');
    if ($token === '') return 'Confirme que você não é um robô.';
    $url = ['turnstile' => 'https://challenges.cloudflare.com/turnstile/v0/siteverify', 'recaptcha' => 'https://www.google.com/recaptcha/api/siteverify', 'hcaptcha' => 'https://api.hcaptcha.com/siteverify'][sec('captcha')];
    $post = http_build_query(['secret' => (string)setting('security_captcha_secret'), 'response' => $token, 'remoteip' => client_ip()]);
    $body = false;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $post, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 5]);
        $body = curl_exec($ch);
        curl_close($ch);
    } else {
        $body = @file_get_contents($url, false, stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/x-www-form-urlencoded\r\n", 'content' => $post, 'timeout' => 10]]));
    }
    if ($body === false) {
        pm_log_error('CAPTCHA: sem resposta do provedor (' . sec('captcha') . ')');
        return null; // provedor fora do ar: não trava o site (as outras proteções continuam valendo)
    }
    $j = json_decode((string)$body, true);
    return !empty($j['success']) ? null : 'Confirme que você não é um robô.';
}

/* ---------- E-mail ---------- */

function sec_disposable_domains(): array
{
    $base = ['10minutemail.com', '10minutemail.net', '20minutemail.com', 'guerrillamail.com', 'guerrillamail.net', 'guerrillamail.org', 'guerrillamailblock.com', 'sharklasers.com', 'grr.la', 'mailinator.com', 'mailinator.net', 'mailinator2.com', 'yopmail.com', 'yopmail.net', 'yopmail.fr', 'temp-mail.org', 'temp-mail.io', 'tempmail.com', 'tempmail.net', 'tempmailo.com', 'tempmail.dev', 'tempail.com', 'throwawaymail.com', 'trashmail.com', 'trashmail.net', 'trashmail.de', 'getnada.com', 'nada.email', 'dispostable.com', 'maildrop.cc', 'mailnesia.com', 'mintemail.com', 'mohmal.com', 'emailondeck.com', 'fakeinbox.com', 'fakemail.net', 'spamgourmet.com', 'mailcatch.com', 'spambox.us', 'mytemp.email', 'tempinbox.com', 'burnermail.io', 'mail.tm', 'mail.gw', 'emailfake.com', 'fakemailgenerator.com', 'inboxkitten.com', 'dropmail.me', '1secmail.com', '1secmail.net', '1secmail.org', 'harakirimail.com', 'mailpoof.com', 'tmail.ws', 'tmpmail.org', 'tmpmail.net', 'moakt.com', 'luxusmail.org', 'mailsac.com', 'tempr.email', 'discard.email', 'spam4.me', 'byom.de', 'anonbox.net', 'linshiyouxiang.net', 'emltmp.com', 'cuvox.de', 'armyspy.com', 'dayrep.com', 'einrot.com', 'fleckens.hu', 'gustr.com', 'jourrapide.com', 'rhyta.com', 'superrito.com', 'teleworm.us', 'tempmailaddress.com', 'mailtemp.net', 'emailtemporanea.com', 'emailtemporario.com.br', 'tempemail.co', 'tempmails.net', 'mail-temp.com', 'temporary-mail.net', 'disposablemail.com', 'getairmail.com', 'airmail.cc', 'mvrht.net', 'vomoto.com', 'tafmail.com', 'zetmail.com', 'kzccv.com', 'qiott.com', 'wuuvo.com', 'tmails.net', 'trbvm.com', 'boximail.com', 'mail7.io', 'minuteinbox.com', 'firemailbox.club', 'smailpro.com', 'emailnax.com', 'tempmailer.com', 'crazymailing.com', 'mailforspam.com'];
    $extra = array_filter(array_map(fn($d) => strtolower(trim($d)), preg_split('/\R/', (string)sec('extra_domains'))));
    return array_flip(array_merge($base, $extra));
}

/** Retorna o erro, ou null se o e-mail está ok */
function sec_email_problem(string $email): ?string
{
    $domain = strtolower(substr(strrchr($email, '@') ?: '', 1));
    if ($domain === '') return 'E-mail inválido.';
    if (sec('block_disposable') || trim((string)sec('extra_domains')) !== '') {
        $list = sec_disposable_domains();
        $parts = explode('.', $domain);
        for ($i = 0; $i < count($parts) - 1; $i++) {
            if (isset($list[implode('.', array_slice($parts, $i))])) return 'Use um e-mail permanente (e-mails temporários não são aceitos).';
        }
    }
    if (sec('check_mx') && function_exists('checkdnsrr') && !in_array($domain, ['gmail.com', 'hotmail.com', 'outlook.com', 'yahoo.com', 'yahoo.com.br', 'icloud.com', 'live.com', 'bol.com.br', 'uol.com.br'], true)) {
        if (!@checkdnsrr($domain, 'MX') && !@checkdnsrr($domain, 'A')) {
            // Só recusa se a consulta de DNS do servidor estiver funcionando (evita barrar gente de verdade)
            if (@checkdnsrr('gmail.com', 'MX')) return 'Esse e-mail não parece existir. Confira o endereço.';
        }
    }
    return null;
}

/* ---------- Senha ---------- */

function sec_password_problem(string $pass, string $email = '', string $name = ''): ?string
{
    if (strlen($pass) < 8) return 'A senha precisa ter pelo menos 8 caracteres.';
    if (!sec('block_common_pass')) return null;
    $p = mb_strtolower($pass);
    $common = ['12345678', '123456789', '1234567890', '12345678910', '87654321', '11111111', '00000000', '88888888', '12341234', '11223344', 'password', 'password1', 'password123', 'senha123', 'senha1234', 'senhasenha', 'qwertyui', 'qwerty123', 'qwertyuiop', 'asdfghjk', 'abcdefgh', 'abc12345', 'abcd1234', 'iloveyou', 'sunshine', 'princess', 'football', 'baseball', 'superman', 'batman123', 'flamengo', 'corinthians', 'palmeiras', 'saopaulo', 'vasco123', 'gremio123', 'brasil123', 'mudar123', 'admin123', 'administrador', 'welcome1', 'changeme', 'pipocamax', 'netflix123', 'minhasenha', 'teste123', 'testando', '1q2w3e4r', '1q2w3e4r5t', 'q1w2e3r4', 'zaq12wsx', 'aaaaaaaa', 'a1b2c3d4'];
    if (in_array($p, $common, true)) return 'Essa senha é muito comum. Escolha outra.';
    if (preg_match('/^(.)\1+$/u', $p)) return 'Essa senha é fácil demais. Escolha outra.';
    $user = mb_strtolower(strstr($email, '@', true) ?: '');
    if ($user !== '' && mb_strlen($user) >= 4 && str_contains($p, $user)) return 'A senha não pode conter o seu e-mail.';
    return null;
}
