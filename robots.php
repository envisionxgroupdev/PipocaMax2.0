<?php
/** robots.txt dinâmico */
require __DIR__ . '/includes/bootstrap.php';
if (setting('maintenance') === '1') {
    // 503 faz o Google só pausar a visita, sem tirar o site do índice
    http_response_code(503);
    header('Retry-After: 3600');
    exit;
}
header('Content-Type: text/plain; charset=utf-8');
$b = BASE_URL;
echo "User-agent: *\n";
foreach (['/admin/', '/install/', '/cron.php', '/includes/', '/api.php', '/busca', '/busca.php', '/login', '/login.php', '/cadastro', '/cadastro.php', '/conta', '/conta.php', '/minha-lista', '/minha-lista.php', '/logout.php'] as $d) {
    echo 'Disallow: ' . $b . $d . "\n";
}
echo "Allow: /\n\n";
echo 'Sitemap: ' . site_url(pretty_urls() ? url('sitemap.xml') : url('sitemap.php')) . "\n";
