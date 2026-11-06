<?php
/** Mapa do site para o Google (sitemap.xml) */
require __DIR__ . '/includes/bootstrap.php';

if (setting('maintenance') === '1') {
    http_response_code(503);
    exit;
}
header('Content-Type: application/xml; charset=utf-8');

$per = 40000;
$total = (int)q_val("SELECT COUNT(*) FROM contents WHERE status = 'published'");
$pages = max(1, (int)ceil($total / $per));
$p = in_int('p');

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";

// Muitos títulos: gera um índice de sitemaps
if ($pages > 1 && $p === 0) {
    echo '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    for ($i = 1; $i <= $pages; $i++) {
        echo '  <sitemap><loc>' . e(site_url(url('sitemap.php?p=' . $i))) . '</loc></sitemap>' . "\n";
    }
    echo '</sitemapindex>';
    exit;
}
$p = max(1, $p);

echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . "\n";
$line = function (string $loc, ?string $mod = null, ?string $image = null) {
    echo '  <url><loc>' . e($loc) . '</loc>' . ($mod ? '<lastmod>' . e(date('Y-m-d', strtotime($mod))) . '</lastmod>' : '')
        . ($image ? '<image:image><image:loc>' . e($image) . '</image:loc></image:image>' : '') . "</url>\n";
};

if ($p === 1) {
    $line(site_url());
    foreach (['filmes', 'series', 'animes', 'lancamentos', 'tv'] as $pg) {
        $line(site_url(page_url($pg)));
    }
    foreach (q_all("SELECT id, name, created_at FROM channels WHERE status = 'active'") as $ch) {
        $line(site_url(channel_url($ch)), $ch['created_at']);
    }
}
$st = q("SELECT id, type, slug, poster, updated_at FROM contents WHERE status = 'published' ORDER BY id LIMIT $per OFFSET " . (($p - 1) * $per));
while ($c = $st->fetch()) {
    $line(site_url(content_url($c)), $c['updated_at'], abs_img($c['poster'], 'w500'));
}
echo '</urlset>';
