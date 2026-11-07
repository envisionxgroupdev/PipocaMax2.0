<?php
require __DIR__ . '/includes/bootstrap.php';
check_maintenance();

$term = in_str('q');
$items = [];
$channels = [];
if (mb_strlen($term) >= 2) {
    $like = '%' . $term . '%';
    $items = q_all(
        "SELECT id, type, title, slug, poster, year, rating, is_release FROM contents
         WHERE status = 'published' AND (title LIKE ? OR original_title LIKE ? OR genres LIKE ?)
         ORDER BY (title LIKE ?) DESC, views DESC LIMIT 60",
        [$like, $like, $like, $term . '%']
    );
    $channels = q_all("SELECT * FROM channels WHERE status = 'active' AND name LIKE ? ORDER BY name LIMIT 12", [$like]);
}
$pageTitle = $term !== '' ? 'Busca: ' . $term : 'Buscar';
$noindex = true;
require __DIR__ . '/includes/header.php';
?>
<div class="container page">
  <?= ad_slot('list_top') ?>
  <form class="big-search" action="" method="get">
    <?= icon('search') ?>
    <input type="search" name="q" value="<?= e($term) ?>" placeholder="Busque por filmes, séries, animes ou canais" autofocus>
  </form>
  <?php if ($term !== '' && mb_strlen($term) < 2): ?>
    <p class="muted">Digite pelo menos 2 letras.</p>
  <?php elseif ($term !== ''): ?>
    <h1 class="section-title">Resultados para “<?= e($term) ?>”</h1>
    <?php if ($channels): ?>
      <div class="channel-grid mb"><?php foreach ($channels as $ch) echo render_channel_card($ch); ?></div>
    <?php endif; ?>
    <?php if ($items): ?>
      <div class="grid"><?php foreach ($items as $c) echo render_card($c); ?></div>
    <?php elseif (!$channels): ?>
      <div class="empty"><?= icon('search', 'ico ico-xl') ?><p>Nada encontrado. Tente outro nome.</p></div>
    <?php endif; ?>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
