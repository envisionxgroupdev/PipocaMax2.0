<?php
/**
 * SEO central (Painel › SEO): modelos de título e descrição por tipo de página.
 * Variáveis: {titulo} {titulo_original} {ano} {genero} {sinopse} {temporadas} {canal} {categoria} {lista} {pagina} {site}
 */

function seo_templates_default(): array
{
    return [
        'movie_title'  => 'Assistir {titulo} Online Gratis (Filme HD) - {site}',
        'movie_desc'   => 'Assistir {titulo} ({ano}) online grátis em HD, dublado e legendado. {sinopse}',
        'series_title' => 'Assistir {titulo} Online Gratis (Serie HD) - {site}',
        'series_desc'  => 'Assistir {titulo} online grátis em HD, todas as temporadas e episódios, dublado e legendado. {sinopse}',
        'anime_title'  => 'Assistir {titulo} Online Gratis (Anime HD) - {site}',
        'anime_desc'   => 'Assistir {titulo} online grátis em HD, todos os episódios, dublado e legendado. {sinopse}',
        'channel_title' => 'Assistir {canal} Ao Vivo Online Gratis (TV HD) - {site}',
        'channel_desc' => 'Assistir {canal} ao vivo online grátis em HD no {site}.',
        'list_title'   => '{lista} Online Gratis{pagina} - {site}',
        'list_desc'    => '{lista} para assistir online grátis em HD, dublados e legendados, no {site}.',
        'tv_title'     => 'TV Online Gratis - Canais Ao Vivo{pagina} - {site}',
        'tv_desc'      => 'Assista canais de TV ao vivo online grátis em HD no {site}.',
        'other_title'  => '{pagina_nome} - {site}',
    ];
}

function seo_tpl(string $key): string
{
    $saved = json_decode((string)setting('seo_templates', ''), true);
    $v = is_array($saved) && isset($saved[$key]) && trim((string)$saved[$key]) !== '' ? (string)$saved[$key] : (seo_templates_default()[$key] ?? '');
    return $v;
}

/** Preenche um modelo; variáveis vazias somem sem deixar "()" ou espaços sobrando */
function seo_fill(string $tpl, array $vars): string
{
    $vars += ['site' => site_name()];
    $out = preg_replace_callback('/\{(\w+)\}/', fn($m) => (string)($vars[$m[1]] ?? ''), $tpl);
    $out = preg_replace(['/\(\s*\)/', '/\s+([,.)])/', '/\(\s+/', '/\s{2,}/', '/^\s*[-–|•]\s*|\s*[-–|•]\s*$/u'], ['', '$1', '(', ' ', ''], $out);
    return trim($out);
}

function seo_cut_desc(string $s, int $max = 160): string
{
    $s = trim(preg_replace('/\s+/', ' ', $s));
    if (mb_strlen($s) <= $max) return $s;
    $cut = mb_substr($s, 0, $max - 1);
    $sp = mb_strrpos($cut, ' ');
    return rtrim($sp > $max * 0.6 ? mb_substr($cut, 0, $sp) : $cut, ' ,.;:-') . '…';
}

/** Variáveis de um filme/série/anime */
function seo_title_vars(array $c, int $seasons = 0): array
{
    $genre = $c['genres'] ? trim(explode(',', $c['genres'])[0]) : '';
    return [
        'titulo' => $c['title'], 'titulo_original' => $c['original_title'] ?? '', 'ano' => $c['year'] ? (string)(int)$c['year'] : '',
        'genero' => $genre, 'sinopse' => trim((string)($c['overview'] ?? '')),
        'temporadas' => $seasons ? $seasons . ' temporada' . ($seasons > 1 ? 's' : '') : '',
    ];
}

/** [título, descrição] de um filme/série/anime (com o título/descrição personalizados do cadastro, se houver) */
function seo_for_title(array $c, int $seasons = 0): array
{
    $type = in_array($c['type'], ['movie', 'series', 'anime'], true) ? $c['type'] : 'movie';
    $vars = seo_title_vars($c, $seasons);
    $title = trim((string)($c['seo_title'] ?? '')) !== '' ? seo_fill((string)$c['seo_title'], $vars) : seo_fill(seo_tpl($type . '_title'), $vars);
    $desc = trim((string)($c['seo_desc'] ?? '')) !== '' ? seo_fill((string)$c['seo_desc'], $vars) : seo_fill(seo_tpl($type . '_desc'), $vars);
    return [$title, seo_cut_desc($desc)];
}

function seo_page_suffix(int $page): string
{
    return $page > 1 ? ' - Página ' . $page : '';
}
