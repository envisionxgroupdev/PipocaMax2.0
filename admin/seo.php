<?php
require __DIR__ . '/_init.php';

$clean_verif = function (string $v): string {
    if (preg_match('/content="([^"]+)"/', $v, $m)) $v = $m[1]; // aceita a tag inteira
    return preg_replace('/[^A-Za-z0-9_\-]/', '', $v);
};

if (is_post()) {
    csrf_check();
    $section = in_str('section');
    switch ($section) {
        case 'titulos':
            set_setting('site_description', mb_substr(in_str('site_description'), 0, 300));
            set_setting('seo_home_title', mb_substr(in_str('seo_home_title'), 0, 90));
            $tpl = [];
            foreach (array_keys(seo_templates_default()) as $k) {
                $v = trim(preg_replace('/\s+/', ' ', (string)($_POST['tpl'][$k] ?? '')));
                if ($v !== '' && $v !== seo_templates_default()[$k]) $tpl[$k] = mb_substr($v, 0, 300);
            }
            set_setting('seo_templates', json_encode($tpl, JSON_UNESCAPED_UNICODE));
            break;
        case 'buscadores':
            set_setting('pretty_urls', isset($_POST['pretty_urls']) ? '1' : '0');
            set_setting('google_verification', $clean_verif(in_str('google_verification')));
            set_setting('bing_verification', $clean_verif(in_str('bing_verification')));
            set_setting('yandex_verification', $clean_verif(in_str('yandex_verification')));
            $ga = strtoupper(in_str('google_analytics'));
            set_setting('google_analytics', preg_match('/^G-[A-Z0-9]+$/', $ga) ? $ga : '');
            if (in_str('google_analytics') !== '' && !preg_match('/^G-[A-Z0-9]+$/', $ga)) flash('error', 'O ID do Google Analytics precisa ser no formato G-XXXXXXXXXX.');
            break;
        case 'sitemap':
            $lines = array_filter(array_map('trim', preg_split('/\R/', (string)($_POST['robots_extra'] ?? ''))), fn($l) => $l === '' || preg_match('/^(User-agent|Disallow|Allow|Crawl-delay|Sitemap|#)/i', $l));
            set_setting('robots_extra', mb_substr(trim(implode("\n", $lines)), 0, 5000));
            break;
        case 'social':
            set_setting('seo_og_image', clean_url(in_str('seo_og_image')) ?: '');
            set_setting('seo_twitter', preg_replace('/[^A-Za-z0-9_]/', '', ltrim(in_str('seo_twitter'), '@')));
            break;
        case 'checksitemap':
        if (setting('maintenance') === '1') {
            flash('info', 'O site está em modo manutenção, por isso o sitemap fica pausado de propósito (o Google entende que é temporário e não tira suas páginas da busca). Desative a manutenção e verifique de novo.');
            redirect('admin/seo.php#sitemap');
        }
        // Testa o sitemap do jeito que o Google acessa
        $smUrl = site_url(pretty_urls() ? url('sitemap.xml') : url('sitemap.php'));
        $body = false; $code = 0;
        if (function_exists('curl_init')) {
            $ch = curl_init($smUrl);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_FOLLOWLOCATION => true, CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; Googlebot/2.1)']);
            $body = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
        }
        if ($body !== false && $code === 200 && str_starts_with(ltrim((string)$body), '<?xml')) {
            libxml_use_internal_errors(true);
            $xml = simplexml_load_string((string)$body);
            if ($xml !== false) {
                $n = isset($xml->url) ? count($xml->url) : count($xml->sitemap);
                flash('success', "Sitemap funcionando! O Google vai encontrar $n endereço(s) em $smUrl");
            } else {
                flash('error', 'O sitemap abriu, mas o conteúdo veio com defeito. Me mande um print desta mensagem.');
            }
        } elseif ($body !== false && $code === 404 && pretty_urls()) {
            flash('error', "O endereço $smUrl deu \"não encontrado\" (404): o servidor não está aplicando os links amigáveis. Envie ao Google o endereço " . site_url(url('sitemap.php')) . ' enquanto isso.');
        } elseif ($body !== false) {
            flash('error', "O sitemap respondeu com erro $code. Me mande um print desta mensagem.");
        } else {
            flash('info', 'Não consegui testar daqui (a hospedagem bloqueia o site de acessar ele mesmo). O sitemap tem ' . ((int)q_val("SELECT COUNT(*) FROM contents WHERE status = 'published'") + 6) . ' endereços. Confira pelo Google Search Console.');
        }
        redirect('admin/seo.php#sitemap');

    }
    flash('success', 'SEO salvo.');
    redirect('admin/seo.php#' . preg_replace('/[^a-z]/', '', $section));
}

// Checklist
$smUrl = site_url(pretty_urls() ? url('sitemap.xml') : url('sitemap.php'));
$pub = (int)q_val("SELECT COUNT(*) FROM contents WHERE status = 'published'");
$noOverview = (int)q_val("SELECT COUNT(*) FROM contents WHERE status = 'published' AND (overview IS NULL OR overview = '')");
$noPoster = (int)q_val("SELECT COUNT(*) FROM contents WHERE status = 'published' AND (poster IS NULL OR poster = '')");
$checks = [
    ['Descrição do site preenchida', setting('site_description') !== '', 'titulos'],
    ['Google Search Console verificado', setting('google_verification') !== '', 'buscadores'],
    ['Bing Webmaster verificado', setting('bing_verification') !== '', 'buscadores'],
    ['Google Analytics ligado', setting('google_analytics') !== '', 'buscadores'],
    ['URLs amigáveis ligadas', pretty_urls(), 'buscadores'],
    ['Imagem padrão para compartilhar', setting('seo_og_image') !== '', 'social'],
    ['Site fora do modo manutenção', setting('maintenance') !== '1', 'sitemap'],
    [$noOverview ? "$noOverview título(s) sem sinopse" : 'Todos os títulos têm sinopse', $noOverview === 0, 'titulos'],
    [$noPoster ? "$noPoster título(s) sem pôster" : 'Todos os títulos têm pôster', $noPoster === 0, 'titulos'],
];
$score = (int)round(count(array_filter($checks, fn($c) => $c[1])) / count($checks) * 100);
$sample = q_one("SELECT * FROM contents WHERE status = 'published' AND type = 'movie' ORDER BY id DESC LIMIT 1");
$sampleS = q_one("SELECT * FROM contents WHERE status = 'published' AND type <> 'movie' ORDER BY id DESC LIMIT 1");
$tplLabels = [
    'movie' => ['Filmes', 'movie_title', 'movie_desc', 'Moana', '2016'],
    'series' => ['Séries', 'series_title', 'series_desc', 'Safir', '2023'],
    'anime' => ['Animes', 'anime_title', 'anime_desc', 'One Piece', '1999'],
    'channel' => ['Canais de TV', 'channel_title', 'channel_desc', 'Globo', ''],
    'list' => ['Listas (Filmes, Séries, Animes, Lançamentos)', 'list_title', 'list_desc', 'Filmes', ''],
    'tv' => ['Página TV Online', 'tv_title', 'tv_desc', '', ''],
];
admin_header('SEO', 'seo');
?>
<div class="tabs mb big-tabs seo-tabs" role="tablist">
  <a href="#visao" data-seo-tab="visao"><?= icon('grid') ?> Visão geral</a>
  <a href="#titulos" data-seo-tab="titulos"><?= icon('edit') ?> Títulos e descrições</a>
  <a href="#buscadores" data-seo-tab="buscadores"><?= icon('search') ?> Google, Bing e Analytics</a>
  <a href="#sitemap" data-seo-tab="sitemap"><?= icon('database') ?> Sitemap e robots</a>
  <a href="#social" data-seo-tab="social"><?= icon('external') ?> Redes sociais</a>
  <a href="#schema" data-seo-tab="schema"><?= icon('star') ?> Dados estruturados</a>
</div>

<!-- VISÃO GERAL -->
<section class="panel seo-pane" id="pane-visao">
  <div class="seo-score">
    <div class="seo-ring" style="--p:<?= $score ?>"><b><?= $score ?>%</b></div>
    <div><h2>Saúde do SEO</h2><p class="muted"><?= $pub ?> títulos publicados • sitemap com <?= $pub + 6 ?> endereços</p></div>
  </div>
  <ul class="seo-checks">
    <?php foreach ($checks as [$label, $ok, $tab]): ?>
      <li class="<?= $ok ? 'ok' : 'no' ?>"><span><?= $ok ? icon('check') : '!' ?></span><?= e($label) ?><?php if (!$ok): ?> <a href="#<?= $tab ?>" data-seo-go="<?= $tab ?>">Resolver ›</a><?php endif; ?></li>
    <?php endforeach; ?>
  </ul>
  <?php if ($sample): [$st, $sd] = seo_for_title($sample); ?>
    <h3 class="sub-title">Assim um filme seu aparece no Google</h3>
    <div class="gprev"><small><?= e(site_url(content_url($sample))) ?></small><b><?= e($st) ?></b><span><?= e($sd) ?></span></div>
  <?php endif; ?>
</section>

<!-- TÍTULOS -->
<form method="post" class="panel seo-pane" id="pane-titulos">
  <?= csrf_field() ?><input type="hidden" name="section" value="titulos">
  <h2>Títulos e descrições</h2>
  <p class="muted">O título é o texto azul no Google e o nome da aba do navegador. A descrição é o texto cinza abaixo. Use as variáveis abaixo — o site troca pelo dado de cada página.</p>
  <div class="seo-vars">
    <?php foreach (['{titulo}' => 'nome do filme/série', '{ano}' => 'ano', '{titulo_original}' => 'nome original', '{genero}' => '1º gênero', '{temporadas}' => 'nº de temporadas', '{sinopse}' => 'sinopse', '{canal}' => 'nome do canal', '{lista}' => 'nome da lista', '{pagina}' => '" - Página 2"...', '{site}' => site_name()] as $v => $d): ?>
      <button type="button" class="seo-var" data-var="<?= e($v) ?>" title="Clique para inserir no campo selecionado"><code><?= e($v) ?></code><small><?= e($d) ?></small></button>
    <?php endforeach; ?>
  </div>

  <h3 class="sub-title">Página inicial</h3>
  <label class="field"><span>Título da página inicial <em class="muted" data-len></em></span><input name="seo_home_title" maxlength="90" value="<?= e(setting('seo_home_title')) ?>" placeholder="<?= e(site_name() . ' - Filmes, Séries e Animes Online Grátis em HD') ?>" data-seo-field></label>
  <label class="field"><span>Descrição do site <em class="muted" data-len></em></span><textarea name="site_description" rows="2" maxlength="300" data-seo-field data-kind="desc"><?= e(setting('site_description')) ?></textarea></label>

  <?php foreach ($tplLabels as $k => [$label, $tk, $dk, $ex, $ey]): ?>
    <div class="seo-tpl" data-ex-titulo="<?= e($ex) ?>" data-ex-ano="<?= e($ey) ?>">
      <h3 class="sub-title"><?= e($label) ?></h3>
      <label class="field"><span>Modelo do título <em class="muted" data-len></em></span><input name="tpl[<?= $tk ?>]" value="<?= e(seo_tpl($tk)) ?>" data-default="<?= e(seo_templates_default()[$tk]) ?>" data-seo-field></label>
      <label class="field"><span>Modelo da descrição <em class="muted" data-len></em></span><textarea name="tpl[<?= $dk ?>]" rows="2" data-default="<?= e(seo_templates_default()[$dk]) ?>" data-seo-field data-kind="desc"><?= e(seo_tpl($dk)) ?></textarea></label>
      <div class="gprev" data-gprev><small><?= e(site_url(url(['movie' => 'filme/moana', 'series' => 'serie/safir', 'anime' => 'anime/one-piece', 'channel' => 'canal/globo', 'list' => 'filmes', 'tv' => 'tv'][$k]))) ?></small><b data-gp-title></b><span data-gp-desc></span></div>
      <button type="button" class="btn btn-sm" data-seo-reset>Voltar ao padrão</button>
    </div>
  <?php endforeach; ?>
  <input type="hidden" name="tpl[other_title]" value="<?= e(seo_tpl('other_title')) ?>">
  <p class="muted small">Cada título também pode ter um SEO próprio: abra o título em <b>Conteúdos</b> › <b>SEO deste título</b>.</p>
  <div class="seo-save"><button class="btn btn-red btn-lg"><?= icon('check') ?> Salvar títulos e descrições</button></div>
</form>

<!-- BUSCADORES -->
<form method="post" class="panel seo-pane" id="pane-buscadores">
  <?= csrf_field() ?><input type="hidden" name="section" value="buscadores">
  <h2>Google, Bing, Yandex e Analytics</h2>
  <label class="field"><span><b>Google</b> Search Console — código de verificação (ou a tag inteira)</span><input name="google_verification" value="<?= e(setting('google_verification')) ?>" placeholder='<meta name="google-site-verification" content="...">'></label>
  <label class="field"><span><b>Bing</b> Webmaster Tools — código de verificação (ou a tag inteira)</span><input name="bing_verification" value="<?= e(setting('bing_verification')) ?>" placeholder='<meta name="msvalidate.01" content="...">'></label>
  <label class="field"><span><b>Yandex</b> Webmaster — código de verificação (ou a tag inteira)</span><input name="yandex_verification" value="<?= e(setting('yandex_verification')) ?>" placeholder='<meta name="yandex-verification" content="...">'></label>
  <label class="field"><span><b>Google Analytics</b> — ID da métrica</span><input name="google_analytics" value="<?= e(setting('google_analytics')) ?>" placeholder="G-XXXXXXXXXX"></label>
  <label class="toggle-row"><div><b>URLs amigáveis</b><small>Links como <code><?= e(site_url(url('filme/coringa'))) ?></code> em vez de <code>titulo.php?id=123</code>. Deixe sempre ligado.</small></div><input type="checkbox" name="pretty_urls" value="1" <?= pretty_urls() ? 'checked' : '' ?>><span class="switch-ui"></span></label>
  <div class="help">Painéis: <a href="https://search.google.com/search-console" target="_blank" rel="noopener">Google Search Console</a> • <a href="https://www.bing.com/webmasters" target="_blank" rel="noopener">Bing Webmaster</a> • <a href="https://webmaster.yandex.com" target="_blank" rel="noopener">Yandex Webmaster</a> • <a href="https://analytics.google.com" target="_blank" rel="noopener">Google Analytics</a></div>
  <div class="seo-save"><button class="btn btn-red btn-lg"><?= icon('check') ?> Salvar</button></div>
</form>

<!-- SITEMAP -->
<form method="post" class="panel seo-pane" id="pane-sitemap">
  <?= csrf_field() ?><input type="hidden" name="section" value="sitemap">
  <h2>Sitemap e robots.txt</h2>
  <div class="help">
    <b>Seu sitemap:</b> <a href="<?= e($smUrl) ?>" target="_blank"><?= e($smUrl) ?></a> <button type="button" class="btn btn-sm" data-copy="<?= e($smUrl) ?>">Copiar</button><br>
    Envie esse endereço em <b>Sitemaps</b> no Google Search Console, no Bing e no Yandex. Ele se atualiza sozinho a cada título publicado.<br>
    <span class="muted">Aberto no celular ele pode aparecer em branco — é normal, ele é feito para o Google ler.</span>
  </div>
  <div class="btn-row" style="margin:12px 0 20px"><button class="btn" name="section" value="checksitemap" formnovalidate><?= icon('check') ?> Verificar sitemap</button><a class="btn" href="<?= e(site_url(url('robots.txt'))) ?>" target="_blank"><?= icon('external') ?> Ver robots.txt</a></div>
  <p class="muted small">O robots.txt já bloqueia o painel, a busca, o login e as páginas de conta, e aponta o sitemap. Se precisar de alguma regra a mais, coloque aqui:</p>
  <label class="field"><span>Regras extras do robots.txt</span><textarea name="robots_extra" rows="4" placeholder="User-agent: GPTBot&#10;Disallow: /"><?= e(setting('robots_extra')) ?></textarea></label>
  <div class="seo-save"><button class="btn btn-red btn-lg"><?= icon('check') ?> Salvar</button></div>
</form>

<!-- SOCIAL -->
<form method="post" class="panel seo-pane" id="pane-social">
  <?= csrf_field() ?><input type="hidden" name="section" value="social">
  <h2>Redes sociais (WhatsApp, Facebook, X)</h2>
  <p class="muted">Quando alguém compartilha um link do site, aparece um cartão com título, descrição e imagem. Em filmes e séries a imagem é a do próprio título; nas outras páginas usa a imagem padrão abaixo.</p>
  <label class="field"><span>Imagem padrão (1200×630, .jpg ou .png)</span><input name="seo_og_image" value="<?= e(setting('seo_og_image')) ?>" placeholder="https://…/capa-do-site.jpg"></label>
  <label class="field"><span>Perfil no X / Twitter (opcional)</span><input name="seo_twitter" value="<?= e(setting('seo_twitter')) ?>" placeholder="@pipocamax"></label>
  <div class="seo-save"><button class="btn btn-red btn-lg"><?= icon('check') ?> Salvar</button></div>
</form>

<!-- SCHEMA -->
<section class="panel seo-pane" id="pane-schema">
  <h2>Dados estruturados (Schema.org)</h2>
  <p class="muted">O site já informa ao Google o que cada página é — automático, nada para configurar:</p>
  <ul class="seo-checks">
    <li class="ok"><span><?= icon('check') ?></span>Filmes como <b>Movie</b> e séries/animes como <b>TVSeries</b> (com temporadas, episódios, trailer e IMDb)</li>
    <li class="ok"><span><?= icon('check') ?></span>Listas como <b>ItemList</b> (formato do carrossel de filmes do Google)</li>
    <li class="ok"><span><?= icon('check') ?></span>Canais como <b>TelevisionChannel</b></li>
    <li class="ok"><span><?= icon('check') ?></span><b>Breadcrumbs</b> (Início › Filmes › Nome) e <b>busca do site</b></li>
    <li class="ok"><span><?= icon('check') ?></span><b>Organization</b> com o logo do site</li>
  </ul>
  <h3 class="sub-title">Testar no Google</h3>
  <div class="btn-row">
    <?php if ($sample): ?><a class="btn" target="_blank" rel="noopener" href="https://search.google.com/test/rich-results?url=<?= rawurlencode(site_url(content_url($sample))) ?>">Testar um filme</a><?php endif; ?>
    <?php if ($sampleS): ?><a class="btn" target="_blank" rel="noopener" href="https://search.google.com/test/rich-results?url=<?= rawurlencode(site_url(content_url($sampleS))) ?>">Testar uma série</a><?php endif; ?>
    <a class="btn" target="_blank" rel="noopener" href="https://search.google.com/test/rich-results?url=<?= rawurlencode(site_url(page_url('filmes'))) ?>">Testar a lista de filmes</a>
  </div>
</section>
<script type="application/json" id="seo-site"><?= json_encode(['site' => site_name()], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?></script>
<?php admin_footer();
