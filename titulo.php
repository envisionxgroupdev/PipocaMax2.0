<?php
require __DIR__ . '/includes/bootstrap.php';
check_maintenance();

$id = in_int('id');
$c = q_one("SELECT * FROM contents WHERE id = ? AND (status = 'published' OR ? = 1)", [$id, is_admin() ? 1 : 0]);
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
    $players = $canWatch ? array_merge(api_players_movie($c), q_all('SELECT * FROM players WHERE content_id = ? ORDER BY sort, id', [$id])) : [];
} else {
    $seasons = q_all('SELECT * FROM seasons WHERE content_id = ? ORDER BY season_number', [$id]);
    $apiEpisodes = api_has_episode_players() && ($c['tmdb_id'] || $c['imdb_id']);
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

$pageTitle = $c['title'] . ($c['year'] ? ' (' . (int)$c['year'] . ')' : '');
$metaDescription = $c['overview'] ?: (type_label($c['type']) . ' ' . $c['title'] . ' no ' . site_name() . '.');
$canonical = site_url(content_url($c));
$metaImage = abs_img($c['backdrop'] ?: $c['poster'], 'w1280');
$ogType = $isMovie ? 'video.movie' : 'video.tv_show';
$noindex = $c['status'] !== 'published';
$ld = array_filter([
    '@context' => 'https://schema.org',
    '@type' => $isMovie ? 'Movie' : 'TVSeries',
    'name' => $c['title'],
    'alternateName' => $c['original_title'] && $c['original_title'] !== $c['title'] ? $c['original_title'] : null,
    'url' => $canonical,
    'description' => $c['overview'] ?: null,
    'image' => abs_img($c['poster'], 'w500'),
    'datePublished' => $c['release_date'] ?: ($c['year'] ? (string)$c['year'] : null),
    'genre' => $c['genres'] ? array_map('trim', explode(',', $c['genres'])) : null,
    'duration' => $isMovie && $c['runtime'] ? 'PT' . (int)$c['runtime'] . 'M' : null,
    'contentRating' => $c['age_rating'] ?: null,
    'numberOfSeasons' => !$isMovie && $seasons ? count($seasons) : null,
    'trailer' => $c['trailer'] ? ['@type' => 'VideoObject', 'name' => 'Trailer — ' . $c['title'], 'embedUrl' => 'https://www.youtube.com/embed/' . $c['trailer'], 'thumbnailUrl' => 'https://i.ytimg.com/vi/' . $c['trailer'] . '/hqdefault.jpg', 'uploadDate' => $c['release_date'] ?: date('Y-m-d', strtotime($c['created_at']))] : null,
], fn($v) => $v !== null && $v !== '');
$listName = type_label($c['type'], true);
$listPage = ['movie' => 'filmes', 'series' => 'series', 'anime' => 'animes'][$c['type']];
$jsonLd = [$ld, [
    '@context' => 'https://schema.org',
    '@type' => 'BreadcrumbList',
    'itemListElement' => [
        ['@type' => 'ListItem', 'position' => 1, 'name' => 'Início', 'item' => site_url()],
        ['@type' => 'ListItem', 'position' => 2, 'name' => $listName, 'item' => site_url(page_url($listPage))],
        ['@type' => 'ListItem', 'position' => 3, 'name' => $c['title'], 'item' => $canonical],
    ],
]];
$active = $c['type'];
$bodyClass = 'has-hero';
require __DIR__ . '/includes/header.php';
?>
<section class="detail-hero">
  <div class="hero-bg" style="background-image:url('<?= e(img($c['backdrop'] ?: $c['poster'], 'original')) ?>')"></div>
  <div class="hero-shade"></div>
  <div class="container detail-inner">
    <img class="detail-poster" src="<?= e(img($c['poster'], 'w500')) ?>" alt="<?= e($c['title']) ?>">
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
  <section class="watch" data-watch>
    <div class="watch-head">
      <h2>Assistir <?= e($c['title']) ?></h2>
      <button class="link-btn" data-report data-content="<?= $id ?>"><?= icon('flag') ?> Reportar problema</button>
    </div>
    <?php if ($players): ?>
      <div class="servers" data-servers>
        <?php foreach ($players as $i => $p): ?>
          <button class="server<?= $i === 0 ? ' active' : '' ?>" data-player='<?= e(json_encode(players_for_front([$p])[0], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?>'>
            <?= icon('play') ?> <span><?= e($p['name']) ?></span>
            <?php if ($p['audio']): ?><em><?= e($p['audio']) ?></em><?php endif; ?>
            <?php if ($p['quality']): ?><em class="q"><?= e($p['quality']) ?></em><?php endif; ?>
          </button>
        <?php endforeach; ?>
      </div>
      <?= ad_slot('player_above') ?>
      <div class="player" data-player-box>
        <div class="player-cover" style="background-image:url('<?= e(img($c['backdrop'] ?: $c['poster'], 'w1280')) ?>')">
          <button class="big-play" aria-label="Reproduzir"><?= icon('play') ?></button>
          <span class="player-hint">Escolha um player acima e clique para assistir</span>
        </div>
      </div>
      <?= ad_slot('player_below') ?>
    <?php else: ?>
      <div class="empty small"><p>Nenhum player disponível para este filme ainda. Volte em breve!</p></div>
    <?php endif; ?>
  </section>

<?php else: ?>
  <section class="watch" data-series>
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
      <div class="player" data-player-box></div>
      <?= ad_slot('player_below') ?>
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
