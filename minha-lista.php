<?php
require __DIR__ . '/includes/bootstrap.php';
check_maintenance();
require_user();

$items = q_all(
    "SELECT c.id, c.type, c.title, c.slug, c.poster, c.year, c.rating, c.is_release FROM favorites f
     JOIN contents c ON c.id = f.content_id WHERE f.user_id = ? AND c.status = 'published' ORDER BY f.created_at DESC",
    [current_user()['id']]
);
$pageTitle = 'Minha Lista';
$noindex = true;
$active = 'list';
require __DIR__ . '/includes/header.php';
?>
<div class="container page">
  <div class="page-head"><h1>Minha Lista <small><?= count($items) ?> títulos</small></h1></div>
  <?php if ($items): ?>
    <div class="grid"><?php foreach ($items as $c) echo render_card($c); ?></div>
  <?php else: ?>
    <div class="empty"><?= icon('heart', 'ico ico-xl') ?><p>Sua lista está vazia. Toque no <b>+</b> em qualquer título para salvar aqui.</p></div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
