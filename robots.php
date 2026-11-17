<?php
/** robots.txt dinâmico (as regras ficam em includes/seo.php → robots_build) */
require __DIR__ . '/includes/bootstrap.php';
if (setting('maintenance') === '1') {
    // 503 faz o Google só pausar a visita, sem tirar o site do índice
    http_response_code(503);
    header('Retry-After: 3600');
    exit;
}
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: public, max-age=3600');
echo robots_build();
