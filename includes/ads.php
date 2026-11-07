<?php
/** Espaços de anúncio e códigos personalizados (head/body/footer) */

function ad_slots(): array
{
    return [
        'home_top' => ['Início — abaixo do destaque', 'Aparece logo depois do banner grande da página inicial.'],
        'home_middle' => ['Início — entre as fileiras', 'No meio das fileiras de filmes, séries e animes.'],
        'list_top' => ['Catálogos — topo', 'Topo das páginas Filmes, Séries, Animes, Lançamentos e Busca.'],
        'player_above' => ['Acima do player', 'Em filmes, episódios e canais, logo acima do vídeo.'],
        'player_below' => ['Abaixo do player', 'Logo abaixo do vídeo.'],
        'footer' => ['Rodapé (todas as páginas)', 'Antes do rodapé, em todas as páginas do site.'],
    ];
}

function ads_visible(): bool
{
    return !(setting('ads_hide_admin', '1') === '1' && is_admin());
}

function ad_slot(string $name): string
{
    if (setting('ad_' . $name . '_on') !== '1' || !ads_visible()) return '';
    $code = (string)setting('ad_' . $name);
    if (trim($code) === '') return '';
    $dev = setting('ad_' . $name . '_dev', 'all');
    return '<div class="ad-slot ad-' . e($name) . ' ad-dev-' . e($dev) . '">' . $code . '</div>';
}

function custom_code(string $where): string
{
    if (setting('code_' . $where . '_on', '1') !== '1') return '';
    if (setting('code_skip_admin') === '1' && is_admin()) return '';
    return (string)setting('code_' . $where);
}
