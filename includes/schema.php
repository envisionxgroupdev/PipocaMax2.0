<?php
/**
 * Dados estruturados (Schema.org / JSON-LD) do site de streaming.
 * Todas as páginas saem num único @graph: Organization + WebSite + o que a página definir em $jsonLd.
 */

function schema_org_id(): string { return site_url() . '#organization'; }
function schema_site_id(): string { return site_url() . '#website'; }

function schema_base(): array
{
    $logo = site_url('assets/img/logo-512.png');
    return [
        [
            '@type' => 'Organization',
            '@id' => schema_org_id(),
            'name' => site_name(),
            'url' => site_url(),
            'logo' => ['@type' => 'ImageObject', 'url' => $logo, 'width' => 512, 'height' => 512],
        ],
        array_filter([
            '@type' => 'WebSite',
            '@id' => schema_site_id(),
            'name' => site_name(),
            'url' => site_url(),
            'description' => setting('site_description') ?: null,
            'inLanguage' => 'pt-BR',
            'publisher' => ['@id' => schema_org_id()],
            'potentialAction' => [
                '@type' => 'SearchAction',
                'target' => ['@type' => 'EntryPoint', 'urlTemplate' => site_url(page_url('busca')) . '?q={search_term_string}'],
                'query-input' => 'required name=search_term_string',
            ],
        ]),
    ];
}

/** Página comum (WebPage / CollectionPage) ligada ao site */
function schema_page(string $type, string $url, string $name, ?string $description = null, ?array $mainEntity = null): array
{
    return array_filter([
        '@type' => $type,
        '@id' => $url . '#webpage',
        'url' => $url,
        'name' => $name,
        'description' => $description ?: null,
        'inLanguage' => 'pt-BR',
        'isPartOf' => ['@id' => schema_site_id()],
        'mainEntity' => $mainEntity,
    ]);
}

/** Lista de títulos (carrossel de filmes do Google) */
function schema_item_list(array $items, string $name): array
{
    $els = [];
    foreach (array_values($items) as $i => $c) {
        $els[] = ['@type' => 'ListItem', 'position' => $i + 1, 'url' => site_url(content_url($c)), 'name' => $c['title']];
    }
    return ['@type' => 'ItemList', 'name' => $name, 'numberOfItems' => count($els), 'itemListElement' => $els];
}

/** Ação "Assistir" (grátis, no navegador do PC e do celular) */
function schema_watch_action(string $url): array
{
    return [
        '@type' => 'WatchAction',
        'target' => [
            '@type' => 'EntryPoint',
            'urlTemplate' => $url,
            'actionPlatform' => ['https://schema.org/DesktopWebPlatform', 'https://schema.org/MobileWebPlatform'],
        ],
        'expectsAcceptanceOf' => [
            '@type' => 'Offer',
            'category' => 'free',
            'price' => 0,
            'priceCurrency' => 'BRL',
            'availability' => 'https://schema.org/InStock',
            'eligibleRegion' => ['@type' => 'Country', 'name' => 'BR'],
        ],
    ];
}

/** Filme, série ou anime */
function schema_title(array $c, string $url, array $seasons = [], array $episodesBySeason = []): array
{
    $isMovie = $c['type'] === 'movie';
    $images = array_values(array_filter([abs_img($c['poster'], 'w500'), abs_img($c['backdrop'], 'w1280')]));
    $sameAs = array_values(array_filter([
        $c['imdb_id'] ? 'https://www.imdb.com/title/' . $c['imdb_id'] . '/' : null,
        $c['tmdb_id'] ? 'https://www.themoviedb.org/' . ($isMovie ? 'movie' : 'tv') . '/' . (int)$c['tmdb_id'] : null,
    ]));
    $ld = [
        '@type' => $isMovie ? 'Movie' : 'TVSeries',
        '@id' => $url . '#titulo',
        'name' => $c['title'],
        'alternateName' => $c['original_title'] && $c['original_title'] !== $c['title'] ? $c['original_title'] : null,
        'url' => $url,
        'mainEntityOfPage' => ['@id' => $url . '#webpage'],
        'description' => $c['overview'] ?: null,
        'image' => $images ? (count($images) === 1 ? $images[0] : $images) : null,
        'thumbnailUrl' => abs_img($c['poster'], 'w342'),
        'genre' => $c['genres'] ? array_values(array_filter(array_map('trim', explode(',', $c['genres'])))) : null,
        'inLanguage' => 'pt-BR',
        'contentRating' => $c['age_rating'] ?: null,
        'sameAs' => $sameAs ?: null,
        'potentialAction' => schema_watch_action($url),
        'provider' => ['@id' => schema_org_id()],
    ];
    if ($c['type'] === 'anime') $ld['keywords'] = 'anime';
    if ($isMovie) {
        $ld['datePublished'] = $c['release_date'] ?: ($c['year'] ? (string)(int)$c['year'] : null);
        $ld['duration'] = $c['runtime'] ? 'PT' . (int)$c['runtime'] . 'M' : null;
    } else {
        $ld['startDate'] = $c['release_date'] ?: ($c['year'] ? (string)(int)$c['year'] : null);
        if ($seasons) {
            $total = 0;
            $list = [];
            foreach ($seasons as $s) {
                $n = count($episodesBySeason[(int)$s['id']] ?? []);
                $total += $n;
                $list[] = array_filter([
                    '@type' => 'TVSeason',
                    'seasonNumber' => (int)$s['season_number'],
                    'name' => $s['name'] ?: 'Temporada ' . (int)$s['season_number'],
                    'numberOfEpisodes' => $n ?: null,
                ], fn($v) => $v !== null && $v !== '');
            }
            $ld['numberOfSeasons'] = count($seasons);
            $ld['numberOfEpisodes'] = $total ?: null;
            $ld['containsSeason'] = $list;
        }
    }
    if ($c['trailer']) {
        $ld['trailer'] = [
            '@type' => 'VideoObject',
            'name' => 'Trailer — ' . $c['title'],
            'description' => $c['overview'] ? mb_strimwidth($c['overview'], 0, 300, '…') : 'Trailer de ' . $c['title'],
            'embedUrl' => 'https://www.youtube.com/embed/' . $c['trailer'],
            'thumbnailUrl' => 'https://i.ytimg.com/vi/' . $c['trailer'] . '/hqdefault.jpg',
            'uploadDate' => $c['release_date'] ?: date('Y-m-d', strtotime($c['created_at'])),
        ];
    }
    return array_filter($ld, fn($v) => $v !== null && $v !== '' && $v !== []);
}

function schema_breadcrumb(array $trail): array
{
    $els = [];
    foreach (array_values($trail) as $i => [$name, $url]) {
        $els[] = ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $name, 'item' => $url];
    }
    return ['@type' => 'BreadcrumbList', 'itemListElement' => $els];
}

/** Junta tudo num único bloco JSON-LD */
function schema_render(array $pageItems): string
{
    $graph = schema_base();
    foreach ($pageItems as $it) {
        if (!is_array($it) || !$it) continue;
        unset($it['@context']);
        $graph[] = $it;
    }
    return '<script type="application/ld+json">' . json_encode(['@context' => 'https://schema.org', '@graph' => $graph], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) . '</script>';
}
