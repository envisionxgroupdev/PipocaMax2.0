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

/**
 * Robôs do robots.txt, separados por grupo.
 * fixed = sempre liberado (não dá para desligar no painel)
 * def   = padrão quando o admin ainda não mexeu ('1' liberado, '0' bloqueado)
 */
function robots_groups(): array
{
    return [
        'search' => ['name' => 'Buscadores', 'fixed' => true, 'def' => '1',
            'desc' => 'Google, Bing, Apple, DuckDuckGo e Yandex. São eles que trazem visitas pela busca.',
            'bots' => ['Googlebot', 'Googlebot-Image', 'Googlebot-Video', 'Googlebot-News', 'Storebot-Google', 'Google-InspectionTool', 'Bingbot', 'BingPreview', 'MSNBot', 'Applebot', 'DuckDuckBot', 'YandexBot', 'YandexImages']],
        'social' => ['name' => 'Redes sociais e apps de mensagem', 'fixed' => true, 'def' => '1',
            'desc' => 'Geram a prévia (capa, título e descrição) quando alguém compartilha um link no WhatsApp, Facebook, Telegram, X etc.',
            'bots' => ['facebookexternalhit', 'Facebot', 'meta-externalfetcher', 'WhatsApp', 'TelegramBot', 'Twitterbot', 'LinkedInBot', 'Pinterestbot', 'Discordbot', 'Slackbot']],
        'ai_search' => ['name' => 'Buscas com IA', 'fixed' => false, 'def' => '1',
            'desc' => 'ChatGPT, Perplexity e Claude quando buscam na internet para responder alguém. Podem mostrar e linkar o seu site.',
            'bots' => ['OAI-SearchBot', 'ChatGPT-User', 'PerplexityBot', 'Perplexity-User', 'Claude-SearchBot', 'Claude-User']],
        'ai_train' => ['name' => 'Treinamento de IA', 'fixed' => false, 'def' => '0',
            'desc' => 'Copiam o conteúdo para treinar inteligência artificial. Não trazem visitas. Bloquear não afeta o Google.',
            'bots' => ['GPTBot', 'ClaudeBot', 'anthropic-ai', 'Google-Extended', 'Applebot-Extended', 'CCBot', 'Bytespider', 'meta-externalagent', 'Amazonbot', 'cohere-ai', 'Diffbot', 'omgili', 'Timpibot']],
        'seo_tools' => ['name' => 'Ferramentas de SEO', 'fixed' => false, 'def' => '0',
            'desc' => 'Ahrefs, Semrush, Moz, Majestic. Só servem se você usa uma delas; senão gastam servidor à toa.',
            'bots' => ['AhrefsBot', 'SemrushBot', 'rogerbot', 'dotbot', 'MJ12bot', 'BLEXBot', 'DataForSeoBot', 'serpstatbot', 'Barkrowler', 'SeekportBot']],
        'heavy' => ['name' => 'Buscadores estrangeiros pesados', 'fixed' => false, 'def' => '0',
            'desc' => 'Baidu (China), Sogou, Petal (Huawei) e outros sem público no Brasil, que visitam muitas páginas.',
            'bots' => ['Baiduspider', 'Sogou', 'PetalBot', 'YisouSpider', '360Spider', 'MegaIndex.ru']],
    ];
}

function robots_group_on(string $key): bool
{
    $g = robots_groups()[$key] ?? null;
    if (!$g) return false;
    if ($g['fixed']) return true;
    return setting('robots_' . $key, $g['def']) === '1';
}

/** Monta o texto completo do robots.txt */
function robots_build(): string
{
    $b = BASE_URL;
    $private = ['/admin/', '/install/', '/includes/', '/cron.php', '/api.php', '/busca', '/busca.php', '/login', '/login.php', '/cadastro', '/cadastro.php', '/conta', '/conta.php', '/minha-lista', '/minha-lista.php', '/logout.php'];
    $rules = function (array $bots, bool $allow, int $delay = 0) use ($b, $private): string {
        $o = '';
        foreach ($bots as $bot) $o .= 'User-agent: ' . $bot . "\n";
        if (!$allow) return $o . "Disallow: /\n";
        foreach ($private as $d) $o .= 'Disallow: ' . $b . $d . "\n";
        if ($delay > 0) $o .= 'Crawl-delay: ' . $delay . "\n";
        return $o . 'Allow: ' . ($b ?: '') . "/\n";
    };
    $out = '# robots.txt - ' . site_name() . "\n# Gerado pelo painel (SEO > Sitemap e robots)\n\n";
    foreach (robots_groups() as $k => $g) {
        $on = robots_group_on($k);
        $out .= '# ' . $g['name'] . ($on ? '' : ' (bloqueado)') . "\n" . $rules($g['bots'], $on) . "\n";
    }
    $delay = (int)setting('robots_delay', '0');
    $out .= "# Todos os outros robôs\n" . $rules(['*'], true, in_array($delay, [2, 5, 10], true) ? $delay : 0);
    $extra = trim(str_replace("\r", '', (string)setting('robots_extra')));
    if ($extra !== '') $out .= "\n# Regras extras\n" . $extra . "\n";
    return $out . "\nSitemap: " . sitemap_url() . "\n";
}

/** Partes do sitemap: chave => [nome, quantidade, última atualização] */
function sitemap_groups(): array
{
    $g = ['paginas' => ['name' => 'Páginas principais', 'count' => 0, 'lastmod' => null]];
    foreach (['filmes' => ['movie', 'Filmes'], 'series' => ['series', 'Séries'], 'animes' => ['anime', 'Animes']] as $k => [$type, $name]) {
        $r = q_one("SELECT COUNT(*) n, MAX(updated_at) m FROM contents WHERE status = 'published' AND type = ?", [$type]);
        $g[$k] = ['name' => $name, 'count' => (int)($r['n'] ?? 0), 'lastmod' => $r['m'] ?? null];
    }
    try {
        $r = q_one("SELECT COUNT(*) n, MAX(created_at) m FROM channels WHERE status = 'active'");
        $g['canais'] = ['name' => 'Canais de TV', 'count' => (int)($r['n'] ?? 0), 'lastmod' => $r['m'] ?? null];
    } catch (Throwable $e) {
        $g['canais'] = ['name' => 'Canais de TV', 'count' => 0, 'lastmod' => null];
    }
    $g['paginas']['count'] = 5 + ($g['canais']['count'] > 0 ? 1 : 0);
    $g['paginas']['lastmod'] = max(array_filter(array_column($g, 'lastmod')) ?: [null]);
    return $g;
}

/** Endereço de uma parte do sitemap (com ou sem URLs amigáveis) */
function sitemap_url(string $key = '', int $part = 1): string
{
    if ($key === '') return site_url(pretty_urls() ? url('sitemap.xml') : url('sitemap.php'));
    if (pretty_urls()) return site_url(url('sitemap-' . $key . ($part > 1 ? '-' . $part : '') . '.xml'));
    return site_url(url('sitemap.php?t=' . $key . ($part > 1 ? '&p=' . $part : '')));
}

function seo_page_suffix(int $page): string
{
    return $page > 1 ? ' - Página ' . $page : '';
}
