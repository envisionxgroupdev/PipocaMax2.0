<?php
/**
 * Chamadas para o grupo do Telegram (topo, página inicial, página do título, rodapé e botão flutuante).
 * Configura em Painel › Telegram.
 */

const TG_DEFAULT_URL = 'https://t.me/PipocaMax';

function tg_places(): array
{
    // chave => [nome, explicação, padrão]
    return [
        'header' => ['Ícone no topo', 'Ícone do Telegram ao lado do sininho, em todas as páginas.', '1'],
        'home' => ['Faixa na página inicial', 'Uma faixa com convite entre as fileiras de títulos.', '1'],
        'title' => ['Convite embaixo do player', 'Linha discreta embaixo do player de filmes, séries e animes.', '1'],
        'footer' => ['Botão no rodapé', 'Botão “Entre no Telegram” no rodapé do site.', '1'],
        'float' => ['Botão flutuante', 'Botão redondo azul fixo no canto da tela (alguns acham insistente).', '0'],
    ];
}

function tg_url(): string
{
    $u = trim((string)setting('telegram_url', TG_DEFAULT_URL));
    return preg_match('~^https://(t\.me|telegram\.me)/[A-Za-z0-9_+/-]+$~', $u) ? $u : '';
}

function tg_on(string $place): bool
{
    if (tg_url() === '') return false;
    $p = tg_places()[$place] ?? null;
    return $p && setting('telegram_' . $place, $p[2]) === '1';
}

function tg_text(string $key): string
{
    $def = [
        'title' => 'Entre no nosso grupo do Telegram',
        'sub' => 'Lançamentos, novidades e avisos do site em primeira mão. É grátis!',
        'btn' => 'Entrar no grupo',
    ];
    $v = trim((string)setting('telegram_' . $key, ''));
    return $v !== '' ? $v : $def[$key];
}

function tg_logo(int $size = 20): string
{
    return '<svg class="tg-logo" viewBox="0 0 24 24" width="' . $size . '" height="' . $size . '" fill="currentColor" aria-hidden="true"><path d="M21.9 4.3 18.7 19.4c-.2 1-.9 1.3-1.8.8l-4.8-3.6-2.3 2.2c-.3.3-.5.5-1 .5l.3-4.9 8.9-8.1c.4-.3-.1-.5-.6-.2L6.4 13.1l-4.7-1.5c-1-.3-1-1 .2-1.5L20.5 3c.9-.3 1.6.2 1.4 1.3z"/></svg>';
}

/** HTML da chamada para cada lugar ('' quando desligado) */
function tg_render(string $place): string
{
    if (!tg_on($place)) return '';
    $u = e(tg_url());
    $a = 'href="' . $u . '" target="_blank" rel="noopener" data-tg="' . $place . '"';
    switch ($place) {
        case 'header':
            return '<a class="tg-head" ' . $a . ' title="Grupo do Telegram" aria-label="Grupo do Telegram">' . tg_logo(19) . '</a>';
        case 'home':
            return '<section class="tg-band"><div class="tg-band-in">'
                . '<span class="tg-band-ico">' . tg_logo(30) . '</span>'
                . '<div class="tg-band-txt"><b>' . e(tg_text('title')) . '</b><span>' . e(tg_text('sub')) . '</span></div>'
                . '<a class="btn tg-btn" ' . $a . '>' . tg_logo(18) . ' ' . e(tg_text('btn')) . '</a></div></section>';
        case 'title':
            return '<a class="tg-line" ' . $a . '>' . tg_logo(16) . '<span>Receba os lançamentos e avisos no nosso <b>grupo do Telegram</b></span><em>Entrar ›</em></a>';
        case 'footer':
            return '<a class="btn tg-btn tg-foot" ' . $a . '>' . tg_logo(16) . ' Entre no Telegram</a>';
        case 'float':
            return '<a class="tg-float" ' . $a . ' title="Grupo do Telegram" aria-label="Grupo do Telegram">' . tg_logo(26) . '</a>';
    }
    return '';
}
