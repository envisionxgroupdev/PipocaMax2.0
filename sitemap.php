<?php
/**
 * Mapa do site para o Google, organizado por tipo:
 *   sitemap_v2.xml         → índice (lista as partes abaixo)
 *   sitemap_v2-paginas.xml    → início, listas e TV
 *   sitemap_v2-filmes.xml     → filmes   (divide em -2, -3... a cada 5.000)
 *   sitemap_v2-series.xml     → séries
 *   sitemap_v2-animes.xml     → animes
 *   sitemap_v2-canais.xml     → canais de TV
 */
require __DIR__ . '/includes/bootstrap.php';

if (setting('maintenance') === '1') {
    http_response_code(503);
    header('Retry-After: 3600');
    exit;
}
while (ob_get_level() > 0) ob_end_clean();
header('Content-Type: application/xml; charset=utf-8');
header('X-Robots-Tag: noindex');

const SM_PER = 5000;
$t = in_str('t');
$p = max(1, in_int('p', 1));
$types = sitemap_groups();

// Endereço antigo (sitemap.xml / sitemap-filmes.xml) → nome novo, com 301
if (isset($_GET['antigo']) && pretty_urls()) {
    header('Location: ' . sitemap_url(isset($types[$t]) ? $t : '', $p), true, 301);
    exit;
}

// Parte que não existe (ex.: sitemap_v2-filmes-9.xml) → 404
if (isset($types[$t]) && $p > max(1, (int)ceil($types[$t]['count'] / SM_PER))) {
    http_response_code(404);
    exit;
}

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";

// ---------- Índice ----------
if ($t === '' || !isset($types[$t])) {
    echo '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    foreach ($types as $key => $g) {
        if ($g['count'] === 0) continue;
        $parts = max(1, (int)ceil($g['count'] / SM_PER));
        for ($i = 1; $i <= $parts; $i++) {
            echo '  <sitemap><loc>' . e(sitemap_url($key, $i)) . '</loc>' . ($g['lastmod'] ? '<lastmod>' . e(date('Y-m-d', strtotime($g['lastmod']))) . '</lastmod>' : '') . "</sitemap>\n";
        }
    }
    echo '</sitemapindex>';
    exit;
}

// ---------- Uma parte ----------
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . "\n";
$line = function (string $loc, ?string $mod = null, ?string $image = null, ?string $title = null) {
    echo '  <url><loc>' . e($loc) . '</loc>' . ($mod ? '<lastmod>' . e(date('Y-m-d', strtotime($mod))) . '</lastmod>' : '')
        . ($image ? '<image:image><image:loc>' . e($image) . '</image:loc>' . ($title ? '<image:title>' . e($title) . '</image:title>' : '') . '</image:image>' : '') . "</url>\n";
};
$off = ($p - 1) * SM_PER;
if ($t === 'paginas') {
    $line(site_url());
    foreach (['lancamentos', 'filmes', 'series', 'animes'] as $pg) $line(site_url(page_url($pg)));
    if ($types['canais']['count'] > 0) $line(site_url(page_url('tv')));
} elseif ($t === 'canais') {
    foreach (q_all("SELECT id, name, slug, created_at FROM channels WHERE status = 'active' ORDER BY name LIMIT " . SM_PER . " OFFSET $off") as $ch) {
        $line(site_url(channel_url($ch)), $ch['created_at']);
    }
} else {
    $type = ['filmes' => 'movie', 'series' => 'series', 'animes' => 'anime'][$t];
    $st = q("SELECT id, type, slug, title, poster, updated_at FROM contents WHERE status = 'published' AND type = ? ORDER BY updated_at DESC, id DESC LIMIT " . SM_PER . " OFFSET $off", [$type]);
    while ($c = $st->fetch()) {
        $line(site_url(content_url($c)), $c['updated_at'], abs_img($c['poster'], 'w500'), $c['title']);
    }
}
echo '</urlset>';
