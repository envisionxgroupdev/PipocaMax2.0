<?php
require __DIR__ . '/includes/bootstrap.php';
check_maintenance();

// As consultas da página inicial ficam guardadas por 5 minutos (renovadas na hora quando algo muda no painel)
[$hero, $releases, $movies, $series, $animes, $top, $channels] = cache_remember('home', 300, function () {
    $cols = 'id, type, title, slug, poster, backdrop, logo, year, rating, overview, genres, runtime, age_rating, is_release';
    $hero = q_all("SELECT $cols FROM contents WHERE status = 'published' AND featured = 1 AND backdrop IS NOT NULL ORDER BY updated_at DESC LIMIT 6");
    if (!$hero) {
        $hero = q_all("SELECT $cols FROM contents WHERE status = 'published' AND backdrop IS NOT NULL ORDER BY is_release DESC, created_at DESC LIMIT 5");
    }
    $releases = q_all("SELECT $cols FROM contents WHERE status = 'published' AND is_release = 1 ORDER BY COALESCE(release_date, created_at) DESC LIMIT 24");
    $movies = q_all("SELECT $cols FROM contents WHERE status = 'published' AND type = 'movie' ORDER BY created_at DESC LIMIT 24");
    $series = q_all("SELECT $cols FROM contents WHERE status = 'published' AND type = 'series' ORDER BY updated_at DESC LIMIT 24");
    $animes = q_all("SELECT $cols FROM contents WHERE status = 'published' AND type = 'anime' ORDER BY updated_at DESC LIMIT 24");
    $top = q_all("SELECT $cols FROM contents WHERE status = 'published' AND views > 0 ORDER BY views DESC LIMIT 10");
    $channels = q_all("SELECT * FROM channels WHERE status = 'active' ORDER BY sort, name LIMIT 20");
    return [$hero, $releases, $movies, $series, $animes, $top, $channels];
});

$active = 'home';
$bodyClass = 'has-hero';
$canonical = site_url();
$metaImage = $hero ? abs_img($hero[0]['backdrop'], 'w1280') : null;
$preloadHero = $hero ? $hero[0]['backdrop'] : null;
$homeList = $releases ?: ($movies ?: $series);
$jsonLd = [
    schema_page('WebPage', site_url(), site_name() . ' — ' . (setting('site_description') ?: 'Filmes, séries, animes e TV online'), setting('site_description') ?: null,
        $homeList ? ['@id' => site_url() . '#destaques'] : null),
    $homeList ? ['@id' => site_url() . '#destaques'] + schema_item_list(array_slice($homeList, 0, 20), 'Lançamentos') : null,
];
require __DIR__ . '/includes/header.php';
?>

<h1 class="sr-only"><?= e(site_name()) ?> — <?= e(setting('site_description')) ?></h1>
<?php if ($hero): ?>
<section class="hero" data-hero>
  <?php foreach ($hero as $i => $h): ?>
  <div class="hero-slide<?= $i === 0 ? ' active' : '' ?>">
    <div class="hero-bg" <?= $i === 0 ? 'style="' . bg_vars($h['backdrop']) . '"' : 'data-bg="' . bg_vars($h['backdrop']) . '"' ?>></div>
    <div class="hero-shade"></div>
    <div class="hero-content container">
      <?php if ($h['logo']): ?>
        <img class="hero-logo" src="<?= e(img($h['logo'], 'w500')) ?>" alt="<?= e($h['title']) ?>">
      <?php else: ?>
        <h2 class="hero-title"><?= e($h['title']) ?></h2>
      <?php endif; ?>
      <div class="meta">
        <?php if ($h['is_release']): ?><span class="tag tag-red">Lançamento</span><?php endif; ?>
        <span class="tag"><?= e(type_label($h['type'])) ?></span>
        <?php if ($h['rating']): ?><span class="meta-rating">★ <?= e(number_format((float)$h['rating'], 1)) ?></span><?php endif; ?>
        <?php if ($h['year']): ?><span><?= (int)$h['year'] ?></span><?php endif; ?>
        <?php if ($h['age_rating']): ?><span class="age"><?= e($h['age_rating']) ?></span><?php endif; ?>
        <?php if ($h['genres']): ?><span class="muted"><?= e($h['genres']) ?></span><?php endif; ?>
      </div>
      <p class="hero-overview"><?= e(mb_strimwidth((string)$h['overview'], 0, 240, '…')) ?></p>
      <div class="hero-actions">
        <a class="btn btn-white" href="<?= e(content_url($h)) ?>#assistir"><?= icon('play') ?> Assistir</a>
        <a class="btn btn-glass" href="<?= e(content_url($h)) ?>"><?= icon('info') ?> Mais informações</a>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
  <?php if (count($hero) > 1): ?>
  <div class="hero-dots">
    <?php foreach ($hero as $i => $h): ?><button class="<?= $i === 0 ? 'active' : '' ?>" data-go="<?= $i ?>" aria-label="Destaque <?= $i + 1 ?>"></button><?php endforeach; ?>
  </div>
  <?php endif; ?>
</section>
<?php else: ?>
<section class="hero hero-empty">
  <div class="hero-shade"></div>
  <div class="hero-content container">
    <h2 class="hero-title">Bem-vindo ao <?= e(site_name()) ?></h2>
    <p class="hero-overview"><?= e(setting('site_description')) ?></p>
    <?php if (is_admin()): ?>
      <a class="btn btn-red" href="<?= e(url('admin/importar.php')) ?>"><?= icon('plus') ?> Adicionar os primeiros títulos</a>
    <?php endif; ?>
  </div>
</section>
<?php endif; ?>

<div class="rows">
  <?= ad_slot('home_top') ?>
  <?= render_row('Lançamentos', $releases, page_url('lancamentos'), 'lancamentos') ?>
  <?= render_row('Em alta no ' . site_name(), $top) ?>
  <?= ad_slot('home_middle') ?>
  <?= render_row('Filmes', $movies, page_url('filmes'), 'filmes') ?>
  <?= render_row('Séries', $series, page_url('series'), 'series') ?>
  <?= render_row('Animes', $animes, page_url('animes'), 'animes') ?>

  <?php if ($channels): ?>
  <section class="row" id="tv">
    <div class="row-head"><h2><span class="live-pill">● AO VIVO</span> TV Online</h2><a class="row-more" href="<?= e(page_url('tv')) ?>">Ver todos ›</a></div>
    <div class="row-wrap"><button class="row-nav prev" aria-label="Anterior">‹</button>
      <div class="row-track channels-track">
        <?php foreach ($channels as $ch) echo render_channel_card($ch); ?>
      </div>
    <button class="row-nav next" aria-label="Próximo">›</button></div>
  </section>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
