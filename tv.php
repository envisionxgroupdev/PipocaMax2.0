<?php
require __DIR__ . '/includes/bootstrap.php';
check_maintenance();

$cat = in_str('categoria');
$categories = q_all("SELECT DISTINCT category FROM channels WHERE status = 'active' AND category IS NOT NULL AND category <> '' ORDER BY category");
$params = [];
$sql = "SELECT * FROM channels WHERE status = 'active'";
if ($cat !== '') {
    $sql .= ' AND category = ?';
    $params[] = $cat;
}
$channels = q_all($sql . ' ORDER BY sort, name', $params);

$pageTitle = 'TV Online';
$canonical = site_url(page_url('tv'));
$metaDescription = 'Canais de TV ao vivo no ' . site_name() . '.';
$noindex = $cat !== '';
$active = 'tv';
require __DIR__ . '/includes/header.php';
?>
<div class="container page">
  <div class="page-head">
    <h1><span class="live-pill">● AO VIVO</span> TV Online <small><?= count($channels) ?> canais</small></h1>
  </div>
  <?php if ($categories): ?>
  <div class="chips">
    <a class="chip<?= $cat === '' ? ' active' : '' ?>" href="<?= e(page_url('tv')) ?>">Todos</a>
    <?php foreach ($categories as $c): ?>
      <a class="chip<?= $cat === $c['category'] ? ' active' : '' ?>" href="<?= e(page_url('tv', 'categoria=' . urlencode($c['category']))) ?>"><?= e($c['category']) ?></a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
  <?php if ($channels): ?>
    <div class="channel-grid">
      <?php foreach ($channels as $ch) echo render_channel_card($ch); ?>
    </div>
  <?php else: ?>
    <div class="empty"><?= icon('live', 'ico ico-xl') ?><p>Nenhum canal disponível no momento.</p></div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
