<?php
require __DIR__ . '/_init.php';

if (is_post()) {
    csrf_check();
    $section = in_str('section');
    switch ($section) {
        case 'geral':
            set_setting('site_name', mb_substr(in_str('site_name'), 0, 60) ?: 'Pipoca Max');
            set_setting('site_description', mb_substr(in_str('site_description'), 0, 300));
            set_setting('footer_text', mb_substr(in_str('footer_text'), 0, 300));
            set_setting('items_per_page', (string)max(12, min(120, in_int('items_per_page', 30))));
            break;
        case 'seo':
            set_setting('pretty_urls', isset($_POST['pretty_urls']) ? '1' : '0');
            set_setting('seo_home_title', mb_substr(in_str('seo_home_title'), 0, 70));
            $gv = in_str('google_verification');
            if (preg_match('/content="([^"]+)"/', $gv, $m)) $gv = $m[1]; // aceita a tag inteira
            set_setting('google_verification', preg_replace('/[^A-Za-z0-9_\-]/', '', $gv));
            $ga = strtoupper(in_str('google_analytics'));
            set_setting('google_analytics', preg_match('/^G-[A-Z0-9]+$/', $ga) ? $ga : '');
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
    <a href="#seo"><?= icon('search') ?> SEO e Google</a>
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
        <label class="field span-3"><span>Descrição (aparece no Google e na página inicial)</span><input name="site_description" value="<?= e(setting('site_description')) ?>"></label>
        <label class="field span-3"><span>Texto do rodapé</span><input name="footer_text" value="<?= e(setting('footer_text')) ?>"></label>
        <div class="span-3"><button class="btn btn-red">Salvar</button></div>
      </form>
    </section>

    <section class="panel" id="seo">
      <div class="panel-head"><h2>SEO e Google</h2></div>
      <form method="post">
        <?= csrf_field() ?><input type="hidden" name="section" value="seo">
        <label class="toggle-row"><div><b>URLs amigáveis</b><small>Links como <code><?= e(site_url(url('filme/123-nome-do-filme'))) ?></code> em vez de <code>titulo.php?id=123</code>. Melhor para o Google. Desative se os títulos derem "página não encontrada" (servidor sem mod_rewrite).</small></div><input type="checkbox" name="pretty_urls" value="1" <?= pretty_urls() ? 'checked' : '' ?>><span class="switch-ui"></span></label>
        <div class="form-grid">
          <label class="field span-3"><span>Título da página inicial no Google (até 60 letras)</span><input name="seo_home_title" maxlength="70" value="<?= e(setting('seo_home_title')) ?>" placeholder="<?= e(site_name() . ' • ' . setting('site_description')) ?>"></label>
          <label class="field span-2"><span>Verificação do Google Search Console (código ou a tag inteira)</span><input name="google_verification" value="<?= e(setting('google_verification')) ?>" placeholder='<meta name="google-site-verification" content="...">'></label>
          <label class="field"><span>Google Analytics (ID G-XXXX)</span><input name="google_analytics" value="<?= e(setting('google_analytics')) ?>" placeholder="G-XXXXXXXXXX"></label>
        </div>
        <div class="help" style="margin:14px 0">
          <b>Mapa do site para o Google:</b> <a href="<?= e(site_url(pretty_urls() ? url('sitemap.xml') : url('sitemap.php'))) ?>" target="_blank"><?= e(site_url(pretty_urls() ? url('sitemap.xml') : url('sitemap.php'))) ?></a><br>
          Envie esse endereço no <a href="https://search.google.com/search-console" target="_blank" rel="noopener">Google Search Console</a> › Sitemaps. Ele se atualiza sozinho a cada título publicado.
        </div>
        <button class="btn btn-red">Salvar</button>
      </form>
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
      <p class="muted small">Coloque aqui o link da API que fornece os players. O site monta o link de cada filme e episódio sozinho, usando o ID do TMDB. Assim, <b>todo título importado já aparece com player</b>, sem cadastrar nada. Se a API mudar de endereço, é só trocar aqui que muda no site inteiro.</p>
      <div class="settings-example">
        <b>Formato:</b> um player por linha → <code>Nome|Link|Áudio|Qualidade</code> (áudio e qualidade são opcionais)<br>
        <b>Exemplo de filme:</b> <code>Dublado|https://suaapi.com/filme/{tmdb_id}|Dublado|HD</code><br>
        <b>Exemplo de episódio:</b> <code>Dublado|https://suaapi.com/serie/{tmdb_id}/{temporada}/{episodio}|Dublado|HD</code>
      </div>
      <form method="post">
        <?= csrf_field() ?><input type="hidden" name="section" value="players">
        <label class="field"><span>Players de <b>filmes</b> — variáveis: <code>{tmdb_id}</code> <code>{imdb_id}</code></span>
          <textarea name="movie_player_templates" rows="4" placeholder="Dublado|https://suaapi.com/filme/{tmdb_id}|Dublado|HD"><?= e(setting('movie_player_templates')) ?></textarea></label>
        <label class="field"><span>Players de <b>episódios</b> (séries e animes) — variáveis: <code>{tmdb_id}</code> <code>{imdb_id}</code> <code>{temporada}</code> <code>{episodio}</code> <code>{temporada2}</code> <code>{episodio2}</code> (com zero: 01, 02…)</span>
          <textarea name="episode_player_templates" rows="4" placeholder="Dublado|https://suaapi.com/serie/{tmdb_id}/{temporada}/{episodio}|Dublado|HD"><?= e(setting('episode_player_templates')) ?></textarea></label>
        <button class="btn btn-red">Salvar</button>
      </form>
    </section>

    <section class="panel">
      <div class="panel-head"><h2>Sistema</h2></div>
      <ul class="sys">
        <li><span>Versão do <?= e(site_name()) ?></span><b><?= PM_VERSION ?></b></li>
        <li><span>PHP</span><b><?= PHP_VERSION ?></b></li>
        <li><span>MySQL</span><b><?= e((string)q_val('SELECT VERSION()')) ?></b></li>
        <li><span>Pasta de instalação</span><b><?= is_dir(PM_ROOT . '/install') ? '<span class="pill pill-warn">Apague a pasta /install</span>' : 'Removida ✓' ?></b></li>
      </ul>
    </section>
  </div>
</div>
<?php admin_footer(); ?>
