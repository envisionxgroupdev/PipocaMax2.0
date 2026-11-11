<?php
require __DIR__ . '/_init.php';

$myIp = client_ip();
if (is_post()) {
    csrf_check();
    $action = in_str('action');
    $cfg = json_decode((string)setting('security_config', ''), true) ?: [];
    try {
        if ($action === 'save') {
            $d = sec_defaults();
            foreach (['honeypot', 'block_disposable', 'check_mx', 'block_common_pass', 'captcha_login', 'cloudflare'] as $k) $cfg[$k] = !empty($_POST[$k]) ? 1 : 0;
            $cfg['reg_per_ip_day'] = max(0, min(50, in_int('reg_per_ip_day', $d['reg_per_ip_day'])));
            $cfg['reg_tries_hour'] = max(1, min(200, in_int('reg_tries_hour', $d['reg_tries_hour'])));
            $cfg['login_max'] = max(3, min(50, in_int('login_max', $d['login_max'])));
            $cfg['login_minutes'] = max(1, min(1440, in_int('login_minutes', $d['login_minutes'])));
            $cfg['min_seconds'] = max(0, min(30, in_int('min_seconds', $d['min_seconds'])));
            $cfg['captcha'] = in_array(in_str('captcha'), ['none', 'turnstile', 'recaptcha', 'hcaptcha'], true) ? in_str('captcha') : 'none';
            $cfg['captcha_site'] = mb_substr(in_str('captcha_site'), 0, 200);
            $secret = in_str('captcha_secret');
            if ($secret !== '' && !str_contains($secret, '•')) set_setting('security_captcha_secret', mb_substr($secret, 0, 200));
            if ($cfg['captcha'] !== 'none' && ($cfg['captcha_site'] === '' || (string)setting('security_captcha_secret') === '')) {
                flash('error', 'Para ligar o CAPTCHA, preencha a chave do site e a chave secreta. O CAPTCHA fica desligado até lá.');
            }
            $cfg['extra_domains'] = mb_substr(implode("\n", array_filter(array_map(fn($l) => strtolower(trim($l)), preg_split('/\R/', (string)($_POST['extra_domains'] ?? ''))))), 0, 20000);
            $ips = array_values(array_unique(array_filter(array_map('trim', preg_split('/\R/', (string)($_POST['blocked_ips'] ?? ''))))));
            $mine = array_filter($ips, fn($r) => sec_ip_matches($myIp, $r));
            if ($mine) {
                $ips = array_values(array_diff($ips, $mine));
                flash('error', 'Não bloqueei ' . implode(', ', $mine) . ' porque inclui o seu próprio IP (' . $myIp . ') — você ficaria sem acesso.');
            }
            $cfg['blocked_ips'] = mb_substr(implode("\n", $ips), 0, 20000);
            set_setting('security_config', json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            flash('success', 'Proteções salvas.');
        }
        if ($action === 'block_ip') {
            $ip = in_str('ip');
            if (!filter_var($ip, FILTER_VALIDATE_IP)) throw new RuntimeException('IP inválido.');
            if ($ip === $myIp) throw new RuntimeException('Esse é o seu próprio IP.');
            $list = array_filter(array_map('trim', preg_split('/\R/', (string)($cfg['blocked_ips'] ?? ''))));
            if (!in_array($ip, $list, true)) $list[] = $ip;
            $cfg['blocked_ips'] = implode("\n", $list);
            set_setting('security_config', json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            if (in_str('ban') === '1') {
                q("UPDATE users SET status = 'banned' WHERE register_ip = ? AND role <> 'admin'", [$ip]);
                flash('success', "IP $ip bloqueado e as contas criadas por ele foram banidas.");
            } else {
                flash('success', "IP $ip bloqueado para cadastro e login.");
            }
        }
        if ($action === 'unblock_ip') {
            $ip = in_str('ip');
            $cfg['blocked_ips'] = implode("\n", array_filter(array_map('trim', preg_split('/\R/', (string)($cfg['blocked_ips'] ?? ''))), fn($r) => $r !== $ip));
            set_setting('security_config', json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            flash('success', "IP $ip desbloqueado.");
        }
        if ($action === 'clear_login') {
            q('DELETE FROM login_attempts');
            flash('success', 'Bloqueios de login liberados.');
        }
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }
    redirect('admin/seguranca.php');
}

$c = array_merge(sec_defaults(), array_intersect_key(json_decode((string)setting('security_config', ''), true) ?: [], sec_defaults()));
$secretSet = (string)setting('security_captcha_secret') !== '';
$since = date('Y-m-d H:i:s', time() - 86400);
$stats = [
    'novas' => (int)q_val('SELECT COUNT(*) FROM users WHERE created_at > ?', [$since]),
    'tent' => (int)q_val("SELECT COUNT(*) FROM security_events WHERE action = 'register_try' AND created_at > ?", [$since]),
    'login' => (int)q_val('SELECT COUNT(*) FROM login_attempts WHERE attempts >= ?', [max(3, (int)$c['login_max'])]),
];
$topIps = q_all("SELECT register_ip ip, COUNT(*) n, MAX(created_at) ultimo FROM users WHERE register_ip IS NOT NULL AND created_at > ? GROUP BY register_ip HAVING COUNT(*) > 1 ORDER BY n DESC LIMIT 15", [date('Y-m-d H:i:s', time() - 7 * 86400)]);
$recent = q_all('SELECT id, name, email, register_ip, status, created_at FROM users WHERE register_ip IS NOT NULL ORDER BY id DESC LIMIT 20');
$blocked = array_values(array_filter(array_map('trim', preg_split('/\R/', (string)$c['blocked_ips']))));
$tog = function (string $k, string $title, string $help) use ($c) {
    echo '<label class="toggle-row"><div><b>' . e($title) . '</b><small>' . e($help) . '</small></div><input type="checkbox" name="' . $k . '" value="1"' . (!empty($c[$k]) ? ' checked' : '') . '><span class="switch-ui"></span></label>';
};
admin_header('Segurança', 'seguranca');
?>
<div class="imp-stats">
  <div><b><?= $stats['novas'] ?></b><span>contas novas em 24h</span></div>
  <div><b><?= $stats['tent'] ?></b><span>tentativas de cadastro em 24h</span></div>
  <div><b><?= $stats['login'] ?></b><span>IPs com login bloqueado agora</span></div>
  <div><b><?= count($blocked) ?></b><span>IPs na lista de bloqueio</span></div>
</div>

<div class="cols sec-cols">
<form method="post" class="panel stack">
  <?= csrf_field() ?><input type="hidden" name="action" value="save">
  <h3 class="sub-title">Limites por IP</h3>
  <div class="cols">
    <label class="field"><span>Contas novas por IP (24h)</span><input type="number" name="reg_per_ip_day" min="0" max="50" value="<?= (int)$c['reg_per_ip_day'] ?>"><small class="muted">0 = sem limite</small></label>
    <label class="field"><span>Tentativas de cadastro por IP (1h)</span><input type="number" name="reg_tries_hour" min="1" max="200" value="<?= (int)$c['reg_tries_hour'] ?>"></label>
  </div>
  <div class="cols">
    <label class="field"><span>Senhas erradas antes de bloquear o login</span><input type="number" name="login_max" min="3" max="50" value="<?= (int)$c['login_max'] ?>"></label>
    <label class="field"><span>Minutos de bloqueio do login</span><input type="number" name="login_minutes" min="1" max="1440" value="<?= (int)$c['login_minutes'] ?>"></label>
  </div>

  <h3 class="sub-title">Contra robôs</h3>
  <?php $tog('honeypot', 'Campo-armadilha e tempo mínimo', 'Um campo invisível que só robôs preenchem, e recusa formulários enviados rápido demais. Não incomoda ninguém.'); ?>
  <label class="field"><span>Tempo mínimo para preencher (segundos)</span><input type="number" name="min_seconds" min="0" max="30" value="<?= (int)$c['min_seconds'] ?>" class="w-num"></label>
  <label class="field"><span>CAPTCHA</span>
    <select name="captcha" id="sec-captcha">
      <?php foreach (['none' => 'Desligado', 'turnstile' => 'Cloudflare Turnstile (recomendado, grátis e quase invisível)', 'recaptcha' => 'Google reCAPTCHA v2 ("Não sou um robô")', 'hcaptcha' => 'hCaptcha'] as $k => $l): ?>
        <option value="<?= $k ?>" <?= $c['captcha'] === $k ? 'selected' : '' ?>><?= e($l) ?></option>
      <?php endforeach; ?>
    </select></label>
  <div class="cols" data-captcha-keys>
    <label class="field"><span>Chave do site (site key)</span><input name="captcha_site" value="<?= e($c['captcha_site']) ?>" autocomplete="off"></label>
    <label class="field"><span>Chave secreta (secret key)</span><input name="captcha_secret" value="<?= $secretSet ? '••••••••' : '' ?>" autocomplete="off" placeholder="cole aqui"></label>
  </div>
  <p class="muted small" data-captcha-keys>Pegue as chaves grátis em: Turnstile — <b>dash.cloudflare.com › Turnstile</b>; reCAPTCHA — <b>google.com/recaptcha/admin</b> (tipo v2 "caixa de seleção"); hCaptcha — <b>dashboard.hcaptcha.com</b>. Coloque o domínio <b><?= e($_SERVER['HTTP_HOST'] ?? '') ?></b>.</p>
  <?php $tog('captcha_login', 'Pedir CAPTCHA também no login', 'Além do cadastro. O limite de senhas erradas já protege o login.'); ?>

  <h3 class="sub-title">E-mail e senha</h3>
  <?php
  $tog('block_disposable', 'Bloquear e-mails temporários', 'Recusa endereços de 10minutemail, mailinator, yopmail, temp-mail e outros ~100 serviços.');
  $tog('check_mx', 'Conferir se o domínio do e-mail existe', 'Recusa e-mails com domínio inventado (ex.: fulano@asdfgh.com).');
  $tog('block_common_pass', 'Recusar senhas fracas', 'Bloqueia senhas como 12345678, senha123, flamengo e senhas com o próprio e-mail.');
  ?>
  <label class="field"><span>Outros domínios bloqueados (um por linha)</span><textarea name="extra_domains" rows="3" placeholder="exemplo.com"><?= e($c['extra_domains']) ?></textarea></label>

  <h3 class="sub-title">IPs bloqueados</h3>
  <label class="field"><span>Um por linha — bloqueia cadastro e login</span><textarea name="blocked_ips" rows="5" placeholder="203.0.113.7&#10;198.51.100.*&#10;192.0.2.0/24"><?= e($c['blocked_ips']) ?></textarea>
    <small class="muted">Aceita IP exato, faixa com * (198.51.100.*) ou CIDR (192.0.2.0/24). Seu IP agora: <b><?= e($myIp) ?></b> (nunca é bloqueado).</small></label>
  <?php $tog('cloudflare', 'Meu site usa Cloudflare', 'Ligue só se o domínio passa pelo Cloudflare (nuvem laranja). Assim o site enxerga o IP real de cada visitante.'); ?>

  <button class="btn btn-red btn-lg"><?= icon('check') ?> Salvar proteções</button>
</form>

<div class="stack">
  <section class="panel">
    <div class="panel-head"><h2>IPs com várias contas (7 dias)</h2></div>
    <?php if (!$topIps): ?><p class="muted">Nenhum IP criou mais de uma conta.</p><?php else: ?>
    <table class="table"><thead><tr><th>IP</th><th>Contas</th><th>Última</th><th></th></tr></thead><tbody>
      <?php foreach ($topIps as $t): $isB = sec_ip_blocked($t['ip']); ?>
        <tr><td><code><?= e($t['ip']) ?></code></td><td><b><?= (int)$t['n'] ?></b></td><td class="muted small"><?= e(time_ago($t['ultimo'])) ?></td>
          <td class="right"><?php if ($isB): ?><span class="pill pill-red">bloqueado</span><?php elseif ($t['ip'] !== $myIp): ?>
            <form method="post" data-confirm="Bloquear <?= e($t['ip']) ?> e banir as contas criadas por ele?"><?= csrf_field() ?><input type="hidden" name="action" value="block_ip"><input type="hidden" name="ban" value="1"><input type="hidden" name="ip" value="<?= e($t['ip']) ?>"><button class="btn btn-sm btn-danger">Bloquear e banir</button></form>
          <?php endif; ?></td></tr>
      <?php endforeach; ?>
    </tbody></table>
    <?php endif; ?>
  </section>

  <section class="panel">
    <div class="panel-head"><h2>Cadastros recentes</h2></div>
    <?php if (!$recent): ?><p class="muted">Nenhum cadastro com IP registrado ainda.</p><?php else: ?>
    <div class="table-wrap"><table class="table"><thead><tr><th>Usuário</th><th>IP</th><th>Quando</th><th></th></tr></thead><tbody>
      <?php foreach ($recent as $u): ?>
        <tr><td><b><?= e($u['name']) ?></b><br><small class="muted"><?= e($u['email']) ?></small><?= $u['status'] === 'banned' ? ' <span class="pill pill-red">banido</span>' : '' ?></td>
          <td><code><?= e($u['register_ip']) ?></code></td><td class="muted small"><?= e(time_ago($u['created_at'])) ?></td>
          <td class="right"><?php if (!sec_ip_blocked($u['register_ip']) && $u['register_ip'] !== $myIp): ?><form method="post" data-confirm="Bloquear o IP <?= e($u['register_ip']) ?>?"><?= csrf_field() ?><input type="hidden" name="action" value="block_ip"><input type="hidden" name="ip" value="<?= e($u['register_ip']) ?>"><button class="btn btn-sm" title="Bloquear IP">Bloquear IP</button></form><?php endif; ?></td></tr>
      <?php endforeach; ?>
    </tbody></table></div>
    <?php endif; ?>
  </section>

  <?php if ($blocked): ?>
  <section class="panel">
    <div class="panel-head"><h2>Lista de bloqueio</h2></div>
    <ul class="sec-blocked">
      <?php foreach ($blocked as $b): ?>
        <li><code><?= e($b) ?></code><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="unblock_ip"><input type="hidden" name="ip" value="<?= e($b) ?>"><button class="btn btn-sm">Desbloquear</button></form></li>
      <?php endforeach; ?>
    </ul>
  </section>
  <?php endif; ?>

  <section class="panel">
    <div class="panel-head"><h2>Login</h2></div>
    <p class="muted small">Alguém da sua equipe ficou bloqueado por errar a senha?</p>
    <form method="post" data-confirm="Liberar todos os bloqueios de login agora?"><?= csrf_field() ?><input type="hidden" name="action" value="clear_login"><button class="btn">Liberar bloqueios de login</button></form>
  </section>
</div>
</div>
<?php admin_footer();
