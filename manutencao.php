<?php
if (!defined('PM_ROOT')) {
    require __DIR__ . '/includes/bootstrap.php';
    if (setting('maintenance') !== '1') {
        redirect('index.php');
    }
}
require_once __DIR__ . '/includes/icons.php';
?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= e(site_name()) ?> • Em manutenção</title>
<link rel="icon" href="<?= e(asset('img/favicon.svg')) ?>" type="image/svg+xml">
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Inter:wght@400;600;800&display=swap" rel="stylesheet" media="print" onload="this.media='all'">
<link rel="stylesheet" href="<?= e(asset('css/style.css')) ?>">
</head>
<body class="maintenance-page">
  <div class="maint">
    <?= logo_html('logo logo-lg') ?>
    <div class="maint-icon"><?= icon('wrench', 'ico ico-xl') ?></div>
    <h1>Voltamos já já!</h1>
    <p><?= nl2br(e(setting('maintenance_message', 'Estamos em manutenção.'))) ?></p>
    <a class="link-btn" href="<?= e(page_url('login')) ?>">Acesso do administrador</a>
  </div>
</body>
</html>
