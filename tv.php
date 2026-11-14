<?php
require __DIR__ . '/includes/bootstrap.php';
check_maintenance();

$cat = in_str('categoria');
$categories = q_all("SELECT DISTINCT category FROM channels WHERE status = 'active' AND category IS NOT NULL AND category <> '' ORDER BY category");
$params = [];
$where = "status = 'active'";
if ($cat !== '') {
    $where .= ' AND category = ?';
    $params[] = $cat;
}
$perPage = 60;
$page = max(1, in_int('p', 1));
$totalCh = (int)q_val("SELECT COUNT(*) FROM channels WHERE $where", $params);
$channels = q_all("SELECT * FROM channels WHERE $where ORDER BY sort, name LIMIT $perPage OFFSET " . (($page - 1) * $perPage), $params);

$fullTitle = seo_fill(seo_tpl('tv_title'), ['pagina' => seo_page_suffix($page)]);
$pageTitle = $fullTitle;
$canonical = site_url(page_url('tv'));
$metaDescription = seo_cut_desc(seo_fill(seo_tpl('tv_desc'), []));
$noindex = $cat !== '';
$chList = [];
foreach ($channels as $i => $ch) {
    $chList[] = ['@type' => 'ListItem', 'position' => ($page - 1) * $perPage + $i + 1, 'url' => site_url(channel_url($ch)), 'name' => $ch['name']];
}
$jsonLd = [
    schema_page('CollectionPage', $canonical, $fullTitle, $metaDescription, $chList ? ['@id' => $canonical . '#canais'] : null),
    $chList ? ['@type' => 'ItemList', '@id' => $canonical . '#canais', 'name' => 'Canais de TV ao vivo', 'numberOfItems' => $totalCh, 'itemListElement' => $chList] : null,
    schema_breadcrumb([['Início', site_url()], ['TV Online', $canonical]]),
];
$active = 'tv';
require __DIR__ . '/includes/header.php';
?>
<div class="container page">
  <div class="page-head">
    <h1><span class="live-pill">● AO VIVO</span> TV Online <small><?= $totalCh ?> canais</small></h1>
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
    <?= paginate($totalCh, $perPage, $page, page_url('tv', $cat !== '' ? 'categoria=' . urlencode($cat) : '')) ?>
  <?php else: ?>
    <div class="empty"><?= icon('live', 'ico ico-xl') ?><p>Nenhum canal disponível no momento.</p></div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
