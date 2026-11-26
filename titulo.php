<?php
require __DIR__ . '/includes/bootstrap.php';
check_maintenance();

$slug = preg_replace('/[^a-z0-9-]/', '', strtolower(in_str('slug')));
$tipoMap = ['filme' => 'movie', 'serie' => 'series', 'anime' => 'anime'];
$tipo = $tipoMap[in_str('tipo')] ?? null;
$pub = is_admin() ? 1 : 0;
$c = null;
if ($slug !== '' && $tipo) {
    $c = q_one("SELECT * FROM contents WHERE slug = ? AND type = ? AND (status = 'published' OR ? = 1)", [$slug, $tipo, $pub]);
    // endereço antigo com número na frente (/filme/123-nome): manda para o novo
    if (!$c && preg_match('/^(\d+)(-|$)/', $slug, $m)) {
        $old = q_one("SELECT * FROM contents WHERE id = ? AND (status = 'published' OR ? = 1)", [(int)$m[1], $pub]);
        if ($old && $old['slug'] !== '') { header('Location: ' . content_url($old), true, 301); exit; }
        $c = $old;
    }
} elseif (in_int('id')) {
    $c = q_one("SELECT * FROM contents WHERE id = ? AND (status = 'published' OR ? = 1)", [in_int('id'), $pub]);
    if ($c && pretty_urls() && $c['slug'] !== '' && str_contains($_SERVER['REQUEST_URI'] ?? '', 'titulo.php')) {
        header('Location: ' . content_url($c), true, 301);
        exit;
    }
}
$id = $c ? (int)$c['id'] : 0;
if (!$c) {
    http_response_code(404);
    $pageTitle = 'Não encontrado';
    $noindex = true;
    require __DIR__ . '/includes/header.php';
    echo '<div class="container page"><div class="empty">' . icon('film', 'ico ico-xl') . '<p>Esse título não existe ou foi removido.</p><a class="btn btn-red" href="' . e(url('index.php')) . '">Voltar ao início</a></div></div>';
    require __DIR__ . '/includes/footer.php';
    exit;
}

// Conta visualização uma vez por sessão
if (empty($_SESSION['viewed'][$id])) {
    q('UPDATE contents SET views = views + 1 WHERE id = ?', [$id]);
    $_SESSION['viewed'][$id] = 1;
}

$user = current_user();
$canWatch = setting('require_login') !== '1' || $user;
$isMovie = $c['type'] === 'movie';
$isFav = $user ? (bool)q_val('SELECT 1 FROM favorites WHERE user_id = ? AND content_id = ?', [$user['id'], $id]) : false;

$players = [];
$seasons = [];
$episodesBySeason = [];
if ($isMovie) {
    $players = $canWatch ? players_in_order('movie', vjs_api_player($c), api_players_movie($c), q_all('SELECT * FROM players WHERE content_id = ? ORDER BY sort, id', [$id])) : [];
} else {
    $seasons = q_all('SELECT * FROM seasons WHERE content_id = ? ORDER BY season_number', [$id]);
    $apiEpisodes = (api_has_episode_players() || setting('vjs_api_on', '1') === '1' || setting('xtream_on', '0') === '1') && ($c['tmdb_id'] || $c['imdb_id']);
    $eps = q_all(
        'SELECT e.id, e.season_id, e.episode_number, e.name, e.overview, e.still, e.runtime, e.air_date,
                (SELECT COUNT(*) FROM players p WHERE p.episode_id = e.id) AS nplayers
         FROM episodes e WHERE e.content_id = ? ORDER BY e.episode_number',
        [$id]
    );
    foreach ($eps as $ep) {
        $episodesBySeason[(int)$ep['season_id']][] = [
            'id' => (int)$ep['id'],
            'n' => (int)$ep['episode_number'],
            'name' => $ep['name'],
            'overview' => $ep['overview'],
            'still' => $ep['still'] ? img($ep['still'], 'w300') : null,
            'runtime' => $ep['runtime'] ? format_runtime((int)$ep['runtime']) : '',
            'air' => $ep['air_date'] ? date('d/m/Y', strtotime($ep['air_date'])) : '',
            'available' => (int)$ep['nplayers'] > 0 || $apiEpisodes,
        ];
    }
}

// Títulos parecidos
$firstGenre = trim(explode(',', (string)$c['genres'])[0] ?? '');
$similar = q_all(
    "SELECT id, type, title, slug, poster, year, rating, is_release FROM contents
     WHERE status = 'published' AND id <> ? AND type = ? AND (? = '' OR genres LIKE ?)
     ORDER BY views DESC, created_at DESC LIMIT 18",
    [$id, $c['type'], $firstGenre, '%' . $firstGenre . '%']
);

[$fullTitle, $metaDescription] = seo_for_title($c, count($seasons));
$pageTitle = $fullTitle;
$canonical = site_url(content_url($c));
$metaImage = abs_img($c['backdrop'] ?: $c['poster'], 'w1280');
$ogType = $isMovie ? 'video.movie' : 'video.tv_show';
$preloadHero = $c['backdrop'] ?: $c['poster'];
$noindex = $c['status'] !== 'published';
$listName = type_label($c['type'], true);
$listPage = ['movie' => 'filmes', 'series' => 'series', 'anime' => 'animes'][$c['type']];
$jsonLd = [
    schema_page('ItemPage', $canonical, $pageTitle, $metaDescription, ['@id' => $canonical . '#titulo']),
    schema_title($c, $canonical, $isMovie ? [] : $seasons, $episodesBySeason ?? []),
    schema_breadcrumb([['Início', site_url()], [$listName, site_url(page_url($listPage))], [$c['title'], $canonical]]),
];
$active = $c['type'];
$bodyClass = 'has-hero';
require __DIR__ . '/includes/header.php';
?>
<section class="detail-hero">
  <div class="hero-bg" style="<?= bg_vars($c['backdrop'] ?: $c['poster']) ?>"></div>
  <div class="hero-shade"></div>
  <div class="container detail-inner">
    <img class="detail-poster" src="<?= e(img($c['poster'], 'w342')) ?>" width="250" height="375" decoding="async" alt="<?= e($c['title']) ?>">
    <div class="detail-info">
      <?php if ($c['logo']): ?>
        <img class="hero-logo" src="<?= e(img($c['logo'], 'w500')) ?>" alt="<?= e($c['title']) ?>">
        <h1 class="sr-only"><?= e($c['title']) ?></h1>
      <?php else: ?>
        <h1 class="hero-title"><?= e($c['title']) ?></h1>
      <?php endif; ?>
      <?php if ($c['original_title'] && $c['original_title'] !== $c['title']): ?><p class="muted orig"><?= e($c['original_title']) ?></p><?php endif; ?>
      <div class="meta">
        <?php if ($c['is_release']): ?><span class="tag tag-red">Lançamento</span><?php endif; ?>
        <span class="tag"><?= e(type_label($c['type'])) ?></span>
        <?php if ($c['rating']): ?><span class="meta-rating">★ <?= e(number_format((float)$c['rating'], 1)) ?></span><?php endif; ?>
        <?php if ($c['year']): ?><span><?= (int)$c['year'] ?></span><?php endif; ?>
        <?php if ($c['age_rating']): ?><span class="age"><?= e($c['age_rating']) ?></span><?php endif; ?>
        <?php if ($isMovie && $c['runtime']): ?><span><?= e(format_runtime((int)$c['runtime'])) ?></span><?php endif; ?>
        <?php if (!$isMovie && $seasons): ?><span><?= count($seasons) ?> temporada<?= count($seasons) > 1 ? 's' : '' ?></span><?php endif; ?>
      </div>
      <?php if ($c['genres']): ?><p class="genres"><?= e($c['genres']) ?></p><?php endif; ?>
      <p class="detail-overview"><?= nl2br(e($c['overview'] ?: 'Sinopse não disponível.')) ?></p>
      <div class="hero-actions">
        <a class="btn btn-white" href="#assistir" data-scroll><?= icon('play') ?> Assistir</a>
        <?php if ($c['trailer']): ?>
          <button class="btn btn-glass" data-trailer="<?= e($c['trailer']) ?>"><?= icon('film') ?> Trailer</button>
        <?php endif; ?>
        <button class="btn btn-icon<?= $isFav ? ' on' : '' ?>" data-fav="<?= $id ?>" title="Minha Lista" aria-label="Adicionar à Minha Lista">
          <span class="when-off"><?= icon('plus') ?></span><span class="when-on"><?= icon('check') ?></span>
        </button>
        <button class="btn btn-icon" data-share title="Compartilhar" aria-label="Compartilhar"><?= icon('share') ?></button>
        <?php if (is_admin()): ?>
          <a class="btn btn-icon" href="<?= e(url('admin/conteudo.php?id=' . $id)) ?>" title="Editar no painel"><?= icon('edit') ?></a>
        <?php endif; ?>
      </div>
    </div>
  </div>
</section>

<div class="container" id="assistir">
<?php if (!$canWatch): ?>
  <div class="locked">
    <?= icon('user', 'ico ico-xl') ?>
    <h2>Entre na sua conta para assistir</h2>
    <p>É rápido e grátis.</p>
    <div class="hero-actions center">
      <a class="btn btn-red" href="<?= e(page_url('login', 'voltar=' . urlencode($_SERVER['REQUEST_URI']))) ?>">Entrar</a>
      <?php if (setting('allow_register') === '1'): ?><a class="btn btn-glass" href="<?= e(page_url('cadastro')) ?>">Criar conta</a><?php endif; ?>
    </div>
  </div>

<?php elseif ($isMovie): ?>
  <section class="watch" data-watch data-cid="<?= $id ?>">
    <div class="watch-head">
      <h2>Assistir <?= e($c['title']) ?></h2>
      <button class="link-btn" data-report data-content="<?= $id ?>"><?= icon('flag') ?> Reportar problema</button>
    </div>
    <?php if ($players): ?>
      <div class="servers" data-servers>
        <?php
          $fps = players_for_front($players);
          $firstOk = 0;
          foreach ($fps as $k => $fp) { if (($fp['maint'] ?? 0) !== 1) { $firstOk = $k; break; } }
          foreach ($fps as $k => $fp): $mt = $fp['maint'] ?? 0;
        ?>
          <button class="server<?= $k === $firstOk ? ' active' : '' ?><?= $mt ? ' is-maint' : '' ?>" data-player='<?= e(json_encode($fp, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?>'>
            <?= icon('play') ?> <span><?= e($fp['name']) ?></span>
            <?php if ($fp['audio']): ?><em><?= e($fp['audio']) ?></em><?php endif; ?>
            <?php if ($fp['quality']): ?><em class="q"><?= e($fp['quality']) ?></em><?php endif; ?>
            <?php if ($mt && empty($fp['soon'])): ?><em class="mt"><?= $mt === 'admin' ? 'Manutenção • só você vê' : 'Manutenção' ?></em><?php endif; ?>
          </button>
        <?php endforeach; ?>
      </div>
      <?= ad_slot('player_above') ?>
      <div class="player" data-player-box data-poster="<?= e(img($c['backdrop'] ?: $c['poster'], 'w1280')) ?>">
        <div class="player-cover" style="background-image:url('<?= e(img($c['backdrop'] ?: $c['poster'], 'w780')) ?>')">
          <button class="big-play" aria-label="Reproduzir"><?= icon('play') ?></button>
          <span class="player-hint">Escolha um player acima e clique para assistir</span>
        </div>
      </div>
      <?= ad_slot('player_below') ?>
      <?= tg_render('title') ?>
    <?php else: ?>
      <div class="empty small"><p>Nenhum player disponível para este filme ainda. Volte em breve!</p></div>
    <?php endif; ?>
  </section>

<?php else: ?>
  <section class="watch" data-series data-cid="<?= $id ?>">
    <div class="player-panel" data-episode-panel hidden>
      <div class="watch-head">
        <h2 data-ep-title></h2>
        <div class="watch-tools">
          <button class="btn btn-sm btn-glass" data-ep-prev>‹ Anterior</button>
          <button class="btn btn-sm btn-red" data-ep-next>Próximo ›</button>
          <button class="link-btn" data-report data-content="<?= $id ?>"><?= icon('flag') ?> Reportar</button>
        </div>
      </div>
      <div class="servers" data-servers></div>
      <?= ad_slot('player_above') ?>
      <div class="player" data-player-box data-poster="<?= e(img($c['backdrop'] ?: $c['poster'], 'w1280')) ?>"></div>
      <?= ad_slot('player_below') ?>
      <?= tg_render('title') ?>
    </div>

    <?php if ($seasons): ?>
      <div class="season-head">
        <h2>Episódios</h2>
        <select class="season-select" data-season-select aria-label="Temporada">
          <?php foreach ($seasons as $s): ?>
            <option value="<?= (int)$s['id'] ?>"><?= e($s['season_number'] == 0 ? 'Especiais' : 'Temporada ' . $s['season_number']) ?> (<?= count($episodesBySeason[(int)$s['id']] ?? []) ?> ep.)</option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="episodes" data-episodes></div>
      <script type="application/json" id="series-data"><?= json_encode([
          'title' => $c['title'],
          'fallback' => img($c['backdrop'], 'w300'),
          'seasons' => array_map(fn($s) => ['id' => (int)$s['id'], 'n' => (int)$s['season_number']], $seasons),
          'episodes' => $episodesBySeason,
      ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
    <?php else: ?>
      <div class="empty small"><p>Os episódios ainda não foram adicionados.</p></div>
    <?php endif; ?>
  </section>
<?php endif; ?>
</div>

<div class="rows">
  <?= render_row('Você também pode gostar', $similar) ?>
</div>

<div class="modal" id="trailer-modal" hidden>
  <div class="modal-box modal-video">
    <button class="modal-close" aria-label="Fechar"><?= icon('close') ?></button>
    <div class="ratio"></div>
  </div>
</div>

<?php
$shareText = 'Assista ' . $c['title'] . ($c['year'] ? ' (' . $c['year'] . ')' : '') . ' no ' . site_name();
$shareTo = [
    'whatsapp' => ['WhatsApp', 'https://wa.me/?text=' . rawurlencode($shareText . ' ' . $canonical)],
    'telegram' => ['Telegram', 'https://t.me/share/url?url=' . rawurlencode($canonical) . '&text=' . rawurlencode($shareText)],
    'facebook' => ['Facebook', 'https://www.facebook.com/sharer/sharer.php?u=' . rawurlencode($canonical)],
    'x'        => ['X', 'https://x.com/intent/post?text=' . rawurlencode($shareText) . '&url=' . rawurlencode($canonical)],
];
$shareLogos = [
    'whatsapp' => '<path d="M12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2zm5.3 14.1c-.2.6-1.3 1.2-1.8 1.3-.5.1-1 .1-1.7-.1-.4-.1-.9-.3-1.5-.6-2.7-1.2-4.4-3.9-4.6-4.1-.1-.2-1.1-1.5-1.1-2.8s.7-2 1-2.2c.2-.3.5-.3.7-.3h.5c.2 0 .4 0 .6.5l.8 2c.1.1.1.3 0 .5l-.3.5-.4.4c-.1.1-.3.3-.1.6.2.3.7 1.2 1.6 2 1.1 1 2 1.3 2.3 1.4.3.1.5.1.6-.1l.9-1c.2-.3.4-.2.6-.1l1.9.9c.3.1.5.2.5.3.1.2.1.6-.1 1.2z"/>',
    'telegram' => '<path d="M21.9 4.3 18.7 19.4c-.2 1-.9 1.3-1.8.8l-4.8-3.6-2.3 2.2c-.3.3-.5.5-1 .5l.3-4.9 8.9-8.1c.4-.3-.1-.5-.6-.2L6.4 13.1l-4.7-1.5c-1-.3-1-1 .2-1.5L20.5 3c.9-.3 1.6.2 1.4 1.3z"/>',
    'facebook' => '<path d="M14 8V6.2c0-.9.2-1.4 1.6-1.4H17V2.1C16.7 2 15.8 2 14.8 2 12.5 2 11 3.4 11 5.9V8H8.5v3H11v11h3V11h2.6l.4-3z"/>',
    'x'        => '<path d="M17.8 3h3.1l-6.8 7.7L22 21h-6.2l-4.9-6.4L5.3 21H2.2l7.2-8.3L1.8 3h6.4l4.4 5.8zm-1.1 16.2h1.7L7.4 4.7H5.6z"/>',
];
?>
<div class="modal" id="share-modal" hidden>
  <div class="modal-box share-box">
    <button class="modal-close" aria-label="Fechar"><?= icon('close') ?></button>
    <h3>Compartilhar</h3>
    <div class="share-head">
      <img src="<?= e(img($c['poster'], 'w185')) ?>" width="54" height="81" loading="lazy" alt="">
      <div><b><?= e($c['title']) ?></b><small><?= e(type_label($c['type'] ?? 'movie')) ?><?= $c['year'] ? ' · ' . (int)$c['year'] : '' ?></small></div>
    </div>
    <div class="share-grid">
      <?php foreach ($shareTo as $k => [$name, $href]): ?>
        <a class="share-to share-<?= $k ?>" href="<?= e($href) ?>" target="_blank" rel="noopener nofollow" data-share-to="<?= $k ?>">
          <span><svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor" aria-hidden="true"><?= $shareLogos[$k] ?></svg></span><?= e($name) ?>
        </a>
      <?php endforeach; ?>
      <a class="share-to share-mail" href="mailto:?subject=<?= e(rawurlencode($shareText)) ?>&amp;body=<?= e(rawurlencode($shareText . "\n" . $canonical)) ?>" data-share-to="email"><span><?= icon('mail') ?></span>E-mail</a>
      <button type="button" class="share-to share-more" data-share-native hidden><span><?= icon('share') ?></span>Mais</button>
    </div>
    <div class="share-link">
      <input type="text" value="<?= e($canonical) ?>" readonly aria-label="Link do título" data-share-url>
      <button type="button" class="btn btn-red btn-sm" data-share-copy><?= icon('link') ?> Copiar</button>
    </div>
  </div>
</div>
<script type="application/json" id="share-data"><?= json_encode(['title' => $c['title'], 'text' => $shareText, 'url' => $canonical], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?></script>

<div class="modal" id="report-modal" hidden>
  <div class="modal-box">
    <button class="modal-close" aria-label="Fechar"><?= icon('close') ?></button>
    <h3>Reportar problema</h3>
    <form data-report-form>
      <input type="hidden" name="content_id" value="<?= $id ?>">
      <input type="hidden" name="episode_id" value="">
      <label class="field"><span>O que aconteceu?</span>
        <select name="message">
          <option>O player não carrega</option>
          <option>Áudio ou legenda errados</option>
          <option>Episódio/filme errado</option>
          <option>Qualidade ruim</option>
          <option>Outro problema</option>
        </select>
      </label>
      <label class="field"><span>Detalhes (opcional)</span><input name="details" maxlength="300"></label>
      <button class="btn btn-red btn-block">Enviar</button>
    </form>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
