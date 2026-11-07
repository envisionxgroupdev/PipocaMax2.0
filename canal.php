<?php
require __DIR__ . '/includes/bootstrap.php';
check_maintenance();

$pub = is_admin() ? 1 : 0;
$cslug = preg_replace('/[^a-z0-9-]/', '', strtolower(in_str('slug')));
$ch = null;
if ($cslug !== '') {
    $ch = q_one("SELECT * FROM channels WHERE slug = ? AND (status = 'active' OR ? = 1)", [$cslug, $pub]);
    if (!$ch && preg_match('/^(\d+)(-|$)/', $cslug, $m)) {
        $old = q_one("SELECT * FROM channels WHERE id = ? AND (status = 'active' OR ? = 1)", [(int)$m[1], $pub]);
        if ($old && !empty($old['slug'])) { header('Location: ' . channel_url($old), true, 301); exit; }
        $ch = $old;
    }
} elseif (in_int('id')) {
    $ch = q_one("SELECT * FROM channels WHERE id = ? AND (status = 'active' OR ? = 1)", [in_int('id'), $pub]);
    if ($ch && pretty_urls() && !empty($ch['slug']) && str_contains($_SERVER['REQUEST_URI'] ?? '', 'canal.php')) {
        header('Location: ' . channel_url($ch), true, 301);
        exit;
    }
}
$id = $ch ? (int)$ch['id'] : 0;
if (!$ch) {
    redirect(page_url('tv'));
}
check_require_login();

if (empty($_SESSION['viewed_ch'][$id])) {
    q('UPDATE channels SET views = views + 1 WHERE id = ?', [$id]);
    $_SESSION['viewed_ch'][$id] = 1;
}
$others = q_all("SELECT * FROM channels WHERE status = 'active' AND id <> ? ORDER BY (category = ?) DESC, sort, name LIMIT 24", [$id, (string)$ch['category']]);

$pageTitle = $ch['name'] . ' ao vivo';
$canonical = site_url(channel_url($ch));
$metaDescription = 'Assista ' . $ch['name'] . ' ao vivo no ' . site_name() . '.';
$metaImage = abs_img($ch['logo']);
$active = 'tv';
require __DIR__ . '/includes/header.php';
$player = ['id' => (int)$ch['id'], 'name' => $ch['name'], 'url' => $ch['url'], 'kind' => $ch['kind'], 'audio' => '', 'quality' => ''];
?>
<div class="container page">
  <a class="back" href="<?= e(page_url('tv')) ?>"><?= icon('arrow-left') ?> Canais</a>
  <div class="watch-head">
    <h1 class="channel-title"><span class="live-pill">● AO VIVO</span> <?= e($ch['name']) ?></h1>
    <button class="link-btn" data-report data-channel="<?= $id ?>"><?= icon('flag') ?> Reportar problema</button>
  </div>
  <?= ad_slot('player_above') ?>
  <div class="player" data-autoplay='<?= e(json_encode($player, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?>' data-player-box></div>
  <?= ad_slot('player_below') ?>

  <?php if ($others): ?>
    <h2 class="section-title">Outros canais</h2>
    <div class="channel-grid">
      <?php foreach ($others as $o) echo render_channel_card($o); ?>
    </div>
  <?php endif; ?>
</div>

<div class="modal" id="report-modal" hidden>
  <div class="modal-box">
    <button class="modal-close" aria-label="Fechar"><?= icon('close') ?></button>
    <h3>Reportar problema no canal</h3>
    <form data-report-form>
      <input type="hidden" name="channel_id" value="<?= $id ?>">
      <label class="field"><span>O que aconteceu?</span>
        <select name="message"><option>O canal não carrega</option><option>Travando muito</option><option>Canal errado</option><option>Outro problema</option></select>
      </label>
      <label class="field"><span>Detalhes (opcional)</span><input name="details" maxlength="300"></label>
      <button class="btn btn-red btn-block">Enviar</button>
    </form>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
