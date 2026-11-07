<?php
/** @var string $pageTitle  @var string $active */
require_once __DIR__ . '/icons.php';
$pageTitle = isset($pageTitle) && $pageTitle ? $pageTitle . ' • ' . site_name() : (setting('seo_home_title') ?: site_name() . ' • ' . setting('site_description'));
$metaDescription = trim(preg_replace('/\s+/', ' ', (string)($metaDescription ?? setting('site_description'))));
$canonical = $canonical ?? null;
$metaImage = $metaImage ?? null;
$ogType = $ogType ?? 'website';
$noindex = $noindex ?? false;
$jsonLd = $jsonLd ?? [];
$active = $active ?? '';
$user = current_user();
$nav = [
    'home' => [url(''), 'Início', 'home'],
    'movie' => [page_url('filmes'), 'Filmes', 'film'],
    'series' => [page_url('series'), 'Séries', 'series'],
    'anime' => [page_url('animes'), 'Animes', 'anime'],
    'tv' => [page_url('tv'), 'TV Online', 'live'],
];
?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#0b0b0b">
<title><?= e($pageTitle) ?></title>
<meta name="description" content="<?= e(mb_strimwidth($metaDescription, 0, 160, '…')) ?>">
<?php if ($noindex): ?><meta name="robots" content="noindex, follow"><?php else: ?><meta name="robots" content="index, follow, max-image-preview:large"><?php endif; ?>
<?php if ($canonical): ?><link rel="canonical" href="<?= e($canonical) ?>"><?php endif; ?>
<meta property="og:site_name" content="<?= e(site_name()) ?>">
<meta property="og:locale" content="pt_BR">
<meta property="og:type" content="<?= e($ogType) ?>">
<meta property="og:title" content="<?= e($pageTitle) ?>">
<meta property="og:description" content="<?= e(mb_strimwidth($metaDescription, 0, 200, '…')) ?>">
<?php if ($canonical): ?><meta property="og:url" content="<?= e($canonical) ?>"><?php endif; ?>
<?php if ($metaImage): ?><meta property="og:image" content="<?= e($metaImage) ?>"><?php endif; ?>
<meta name="twitter:card" content="<?= $metaImage ? 'summary_large_image' : 'summary' ?>">
<?php if (setting('google_verification')): ?><meta name="google-site-verification" content="<?= e(setting('google_verification')) ?>"><?php endif; ?>
<?php if (setting('bing_verification')): ?><meta name="msvalidate.01" content="<?= e(setting('bing_verification')) ?>"><?php endif; ?>
<?php if (setting('yandex_verification')): ?><meta name="yandex-verification" content="<?= e(setting('yandex_verification')) ?>"><?php endif; ?>
<?php foreach ($jsonLd as $ld): ?><script type="application/ld+json"><?= json_encode($ld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php endforeach; ?>
<?php if (preg_match('/^G-[A-Z0-9]+$/', (string)setting('google_analytics'))): ?>
<script async src="https://www.googletagmanager.com/gtag/js?id=<?= e(setting('google_analytics')) ?>"></script>
<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config',<?= json_encode(setting('google_analytics')) ?>);</script>
<?php endif; ?>
<link rel="icon" href="<?= e(asset('img/favicon.svg')) ?>" type="image/svg+xml">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="preconnect" href="https://image.tmdb.org">
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(asset('css/style.css')) ?>">
<?= custom_code('head') ?>
</head>
<body class="<?= e($bodyClass ?? '') ?>">
<?= custom_code('body') ?>
<header class="topbar" id="topbar">
  <div class="topbar-inner">
    <?= logo_html() ?>
    <nav class="mainnav">
      <?php foreach ($nav as $k => [$href, $label]): ?>
        <a href="<?= e($href) ?>" class="<?= $active === $k ? 'active' : '' ?>"><?= e($label) ?></a>
      <?php endforeach; ?>
      <?php if ($user): ?><a href="<?= e(page_url('minha-lista')) ?>" class="<?= $active === 'list' ? 'active' : '' ?>">Minha Lista</a><?php endif; ?>
    </nav>
    <div class="topbar-right">
      <form class="search" action="<?= e(page_url('busca')) ?>" method="get" role="search">
        <button type="button" class="search-toggle" aria-label="Buscar"><?= icon('search') ?></button>
        <input type="search" name="q" placeholder="Títulos, gêneros..." value="<?= e($_GET['q'] ?? '') ?>" autocomplete="off">
      </form>
      <?php if ($user): ?>
        <div class="usermenu">
          <button class="avatar" style="background:<?= e($user['avatar_color']) ?>" aria-label="Minha conta"><?= e(mb_strtoupper(mb_substr($user['name'], 0, 1))) ?></button>
          <div class="usermenu-drop">
            <div class="usermenu-head"><strong><?= e($user['name']) ?></strong><small><?= e($user['email']) ?></small></div>
            <a href="<?= e(page_url('minha-lista')) ?>"><?= icon('heart') ?> Minha Lista</a>
            <a href="<?= e(page_url('conta')) ?>"><?= icon('user') ?> Minha conta</a>
            <?php if (is_admin()): ?><a href="<?= e(url('admin/')) ?>"><?= icon('settings') ?> Painel administrativo</a><?php endif; ?>
            <a href="<?= e(url('logout.php?t=' . csrf_token())) ?>"><?= icon('logout') ?> Sair</a>
          </div>
        </div>
      <?php else: ?>
        <a class="btn btn-red btn-sm" href="<?= e(page_url('login')) ?>">Entrar</a>
      <?php endif; ?>
    </div>
  </div>
</header>
<?php if (setting('maintenance') === '1' && is_admin()): ?>
  <div class="maint-bar">Modo manutenção ATIVO — só administradores estão vendo o site. <a href="<?= e(url('admin/configuracoes.php')) ?>">Desativar</a></div>
<?php endif; ?>
<main class="main">
<?php $fl = flashes(); if ($fl): ?><div class="container flash-wrap"><?= $fl ?></div><?php endif; ?>
