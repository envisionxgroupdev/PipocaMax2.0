<?php
require __DIR__ . '/_init.php';

if (is_post()) {
    csrf_check();
    $section = in_str('section');
    switch ($section) {
        case 'geral':
            set_setting('site_name', mb_substr(in_str('site_name'), 0, 60) ?: 'Pipoca Max');
            set_setting('footer_text', mb_substr(in_str('footer_text'), 0, 300));
            set_setting('items_per_page', (string)max(12, min(120, in_int('items_per_page', 30))));
            break;
        case 'clearlog':
            @unlink(PM_LOG);
            break;
        case 'acesso':
            set_setting('allow_register', isset($_POST['allow_register']) ? '1' : '0');
            set_setting('require_login', isset($_POST['require_login']) ? '1' : '0');
            break;
        case 'manutencao':
            set_setting('maintenance', isset($_POST['maintenance']) ? '1' : '0');
            set_setting('maintenance_message', mb_substr(in_str('maintenance_message'), 0, 1000));
            break;
        case 'tmdb':
            set_setting('tmdb_api_key', in_str('tmdb_api_key'));
            set_setting('tmdb_language', in_str('tmdb_language') ?: 'pt-BR');
            if (in_str('test') === '1') {
                try {
                    tmdb_request('configuration');
                    flash('success', 'Conexão com o TMDB funcionando!');
                } catch (Throwable $e) {
                    flash('error', 'Teste falhou: ' . $e->getMessage());
                }
                redirect('admin/configuracoes.php#tmdb');
            }
            break;
        case 'players':
            set_setting('movie_player_templates', (string)($_POST['movie_player_templates'] ?? ''));
            set_setting('episode_player_templates', (string)($_POST['episode_player_templates'] ?? ''));
            break;
    }
    flash('success', 'Configurações salvas.');
    redirect('admin/configuracoes.php#' . preg_replace('/[^a-z]/', '', $section));
}

admin_header('Configurações', 'config');
?>
<div class="settings">
  <nav class="settings-nav">
    <a href="#geral"><?= icon('settings') ?> Geral</a>
    <a href="<?= e(url('admin/seo.php')) ?>"><?= icon('search') ?> SEO <small class="muted">(nova aba)</small></a>
    <a href="#acesso"><?= icon('users') ?> Acesso e cadastro</a>
    <a href="#manutencao"><?= icon('wrench') ?> Manutenção</a>
    <a href="#tmdb"><?= icon('database') ?> API do TMDB</a>
    <a href="#players"><?= icon('live') ?> API de players</a>
  </nav>

  <div class="settings-body">
    <section class="panel" id="geral">
      <div class="panel-head"><h2>Geral</h2></div>
      <form method="post" class="form-grid">
        <?= csrf_field() ?><input type="hidden" name="section" value="geral">
        <label class="field"><span>Nome do site</span><input name="site_name" value="<?= e(site_name()) ?>" required></label>
        <label class="field"><span>Itens por página</span><input type="number" min="12" max="120" name="items_per_page" value="<?= e(setting('items_per_page', 30)) ?>"></label>
        <span></span>
        <label class="field span-3"><span>Texto do rodapé</span><input name="footer_text" value="<?= e(setting('footer_text')) ?>"></label>
        <div class="span-3"><button class="btn btn-red">Salvar</button></div>
      </form>
    </section>

    <section class="panel" id="seo">
      <div class="panel-head"><h2>SEO</h2></div>
      <p class="muted">Tudo de SEO agora fica num lugar só: títulos, descrições, Google/Bing/Yandex, Analytics, sitemap, robots e redes sociais.</p>
      <a class="btn btn-red" href="<?= e(url('admin/seo.php')) ?>"><?= icon('search') ?> Abrir Painel › SEO</a>
    </section>

    <section class="panel" id="acesso">
      <div class="panel-head"><h2>Acesso e cadastro</h2></div>
      <form method="post">
        <?= csrf_field() ?><input type="hidden" name="section" value="acesso">
        <label class="toggle-row"><div><b>Permitir novos cadastros</b><small>Visitantes podem criar conta no site.</small></div><input type="checkbox" name="allow_register" value="1" <?= setting('allow_register') === '1' ? 'checked' : '' ?>><span class="switch-ui"></span></label>
        <label class="toggle-row"><div><b>Exigir login para assistir</b><small>Os players só aparecem para quem estiver logado. O catálogo continua visível.</small></div><input type="checkbox" name="require_login" value="1" <?= setting('require_login') === '1' ? 'checked' : '' ?>><span class="switch-ui"></span></label>
        <button class="btn btn-red">Salvar</button>
      </form>
    </section>

    <section class="panel" id="manutencao">
      <div class="panel-head"><h2>Modo manutenção</h2><?php if (setting('maintenance') === '1'): ?><span class="pill pill-warn">ATIVO</span><?php endif; ?></div>
      <form method="post">
        <?= csrf_field() ?><input type="hidden" name="section" value="manutencao">
        <label class="toggle-row"><div><b>Colocar o site em manutenção</b><small>Visitantes e usuários veem só a página de manutenção. Você (admin) continua vendo o site normalmente.</small></div><input type="checkbox" name="maintenance" value="1" <?= setting('maintenance') === '1' ? 'checked' : '' ?>><span class="switch-ui"></span></label>
        <label class="field"><span>Mensagem exibida</span><textarea name="maintenance_message" rows="3"><?= e(setting('maintenance_message')) ?></textarea></label>
        <button class="btn btn-red">Salvar</button>
        <a class="btn" href="<?= e(url('manutencao.php')) ?>" target="_blank">Ver página de manutenção</a>
      </form>
    </section>

    <section class="panel" id="tmdb">
      <div class="panel-head"><h2>API do TMDB</h2><span class="pill <?= tmdb_configured() ? 'pill-ok' : 'pill-warn' ?>"><?= tmdb_configured() ? 'Configurada' : 'Não configurada' ?></span></div>
      <form method="post" class="form-grid">
        <?= csrf_field() ?><input type="hidden" name="section" value="tmdb">
        <label class="field span-2"><span>Chave da API (v3) ou Token de leitura (v4)</span><input name="tmdb_api_key" value="<?= e(setting('tmdb_api_key')) ?>" autocomplete="off" spellcheck="false"></label>
        <label class="field"><span>Idioma dos dados</span>
          <select name="tmdb_language">
            <?php foreach (['pt-BR' => 'Português (Brasil)', 'pt-PT' => 'Português (Portugal)', 'es-ES' => 'Espanhol', 'en-US' => 'Inglês'] as $k => $l): ?>
              <option value="<?= $k ?>" <?= setting('tmdb_language', 'pt-BR') === $k ? 'selected' : '' ?>><?= $l ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <div class="span-3 help">
          <b>Como conseguir a chave:</b> crie uma conta grátis em <a href="https://www.themoviedb.org/signup" target="_blank" rel="noopener">themoviedb.org</a>, vá em <i>Configurações › API</i>, peça uma chave (uso pessoal) e cole aqui a <i>Chave da API</i> ou o <i>Token de leitura da API</i>.
        </div>
        <div class="span-3 btn-row">
          <button class="btn btn-red">Salvar</button>
          <button class="btn" name="test" value="1">Salvar e testar conexão</button>
        </div>
      </form>
    </section>

    <section class="panel" id="players">
      <div class="panel-head"><h2>API de players</h2><span class="pill <?= api_has_movie_players() || api_has_episode_players() ? 'pill-ok' : 'pill-warn' ?>"><?= api_has_movie_players() || api_has_episode_players() ? 'Ativa' : 'Não configurada' ?></span></div>
      <div class="steps-help">
        <p><b>Como funciona:</b> cole o link de um filme da sua API e troque o número do filme pelo botão <b>ID TMDB</b> (ou <b>ID IMDb</b>). O site faz o mesmo para todos os títulos, sozinho.</p>
        <p class="muted small">Exemplo: o link <code>https://suaapi.com/filme/550</code> vira <code>https://suaapi.com/filme/{tmdb_id}</code>.</p>
      </div>
      <form method="post" data-api-form>
        <?= csrf_field() ?><input type="hidden" name="section" value="players">

        <h3 class="sub-title"><?= icon('film') ?> Players de filmes</h3>
        <div class="api-builder" data-builder="movie" data-vars="tmdb_id,imdb_id"></div>
        <textarea name="movie_player_templates" hidden><?= e(setting('movie_player_templates')) ?></textarea>

        <h3 class="sub-title"><?= icon('series') ?> Players de episódios (séries e animes)</h3>
        <div class="api-builder" data-builder="episode" data-vars="tmdb_id,imdb_id,temporada,episodio,temporada2,episodio2"></div>
        <textarea name="episode_player_templates" hidden><?= e(setting('episode_player_templates')) ?></textarea>

        <button class="btn btn-red btn-lg">Salvar players</button>
      </form>
    </section>

    <section class="panel">
      <div class="panel-head"><h2>Sistema</h2><a class="btn btn-sm" href="<?= e(url('admin/diagnostico.php')) ?>"><?= icon('wrench') ?> Diagnóstico</a></div>
      <ul class="sys">
        <li><span>Versão do <?= e(site_name()) ?></span><b><?= PM_VERSION ?></b></li>
        <li><span>PHP</span><b><?= PHP_VERSION ?></b></li>
        <li><span>MySQL</span><b><?= e((string)q_val('SELECT VERSION()')) ?></b></li>
        <?php
          global $CONFIG;
          $dbHost = (string)($CONFIG['db']['host'] ?? '');
          $isLocal = in_array(strtolower($dbHost), ['localhost', '127.0.0.1', '::1'], true);
          $outIp = '';
          if (function_exists('curl_init')) {
              $ch = curl_init('https://api.ipify.org');
              curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3, CURLOPT_CONNECTTIMEOUT => 2]);
              $outIp = trim((string)curl_exec($ch));
              curl_close($ch);
              if (!filter_var($outIp, FILTER_VALIDATE_IP)) $outIp = '';
          }
        ?>
        <li><span>Servidor do banco (config.php)</span><b><?= e($dbHost) ?> <span class="pill <?= $isLocal ? 'pill-ok' : 'pill-warn' ?>"><?= $isLocal ? 'local ✓' : 'remoto' ?></span></b></li>
        <li><span>IP de saída deste site</span><b><?= $outIp !== '' ? e($outIp) : '<span class="muted">não identificado</span>' ?></b></li>
        <li><span>Últimos erros registrados</span><b><?= is_file(PM_LOG) ? count(file(PM_LOG)) : 0 ?></b></li>
        <li><span>Instalador (/install)</span><b><span class="pill pill-ok">Trancado ✓</span></b></li>
      </ul>
      <?php if (is_file(PM_LOG)): $lines = array_slice(file(PM_LOG, FILE_IGNORE_NEW_LINES), -15); ?>
        <h3 class="sub-title">Registro de erros (mais recentes embaixo)</h3>
        <pre class="errlog"><?= e(implode("\n", $lines)) ?></pre>
        <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="section" value="clearlog"><button class="btn btn-sm">Limpar registro</button></form>
      <?php endif; ?>
    </section>
  </div>
</div>
<?php admin_footer(); ?>
