<?php
/**
 * Avisos/banners do site (Painel › Avisos): faixa no topo, barra no rodapé ou janela (popup).
 */

function ann_styles(): array
{
    return ['red' => 'Vermelho (destaque)', 'promo' => 'Promoção (degradê)', 'warn' => 'Amarelo (atenção)', 'info' => 'Azul (informação)', 'ok' => 'Verde (novidade boa)', 'dark' => 'Escuro (discreto)'];
}

function ann_positions(): array
{
    return ['top' => 'Faixa no topo', 'bottom' => 'Barra no rodapé', 'popup' => 'Janela no meio da tela (popup)'];
}

function ann_pages(): array
{
    return ['home' => 'Página inicial', 'titles' => 'Filmes/séries/animes (página do título)', 'lists' => 'Listas e busca', 'tv' => 'TV Online e canais', 'account' => 'Login, cadastro e conta'];
}

/** Página atual (para filtrar onde o aviso aparece) */
function ann_current_page(): string
{
    $s = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    return match ($s) {
        'index.php' => 'home',
        'titulo.php' => 'titles',
        'filmes.php', 'series.php', 'animes.php', 'lancamentos.php', 'busca.php', 'genero.php', 'minha-lista.php' => 'lists',
        'tv.php', 'canal.php' => 'tv',
        'login.php', 'cadastro.php', 'conta.php', 'perfil.php', 'recuperar.php' => 'account',
        default => 'other',
    };
}

/** Avisos ativos para quem está vendo e para esta página */
function ann_active(): array
{
    $aud = current_user() ? 'users' : 'guests';
    $all = cache_remember('ann_' . $aud, 120, function () use ($aud) {
        try {
            $now = date('Y-m-d H:i:s');
            return q_all("SELECT * FROM announcements WHERE active = 1 AND audience IN ('all', ?) AND (starts_at IS NULL OR starts_at <= ?) AND (ends_at IS NULL OR ends_at > ?) ORDER BY sort, id DESC", [$aud, $now, $now]);
        } catch (Throwable $e) {
            return [];
        }
    });
    $page = ann_current_page();
    $out = ['top' => null, 'bottom' => null, 'popup' => null];
    foreach ($all as $a) {
        // a data é conferida de novo aqui (o cache pode ter até 2 minutos)
        if ($a['starts_at'] && strtotime($a['starts_at']) > time()) continue;
        if ($a['ends_at'] && strtotime($a['ends_at']) <= time()) continue;
        $pages = array_filter(explode(',', (string)$a['pages']));
        if ($pages && !in_array($page, $pages, true)) continue;
        if (!$pages && $a['position'] === 'popup' && $page === 'account') continue; // janela não atrapalha login/cadastro (só se marcar essa página)
        if (!array_key_exists($a['position'], $out) || $out[$a['position']] !== null) continue; // um de cada tipo por vez (o 1º da ordem)
        $out[$a['position']] = $a;
    }
    return array_filter($out);
}

/** Versão do aviso: se você editar, ele aparece de novo para quem tinha fechado */
function ann_ver(array $a): string
{
    return $a['id'] . '-' . substr(md5($a['title'] . '|' . $a['message'] . '|' . $a['btn_text'] . '|' . $a['btn_url']), 0, 6);
}

function ann_render(array $a, bool $preview = false): string
{
    $pos = $a['position'];
    $style = isset(ann_styles()[$a['style']]) ? $a['style'] : 'red';
    $btn = trim((string)$a['btn_text']) !== '' && trim((string)$a['btn_url']) !== ''
        ? '<a class="ann-btn" href="' . e($a['btn_url']) . '"' . (!empty($a['new_tab']) ? ' target="_blank" rel="noopener"' : '') . ' data-ann-click>' . e($a['btn_text']) . '</a>' : '';
    $close = !empty($a['dismissible']) ? '<button type="button" class="ann-x" aria-label="Fechar aviso" data-ann-close>&times;</button>' : '';
    $text = ($a['title'] !== '' ? '<b>' . e($a['title']) . '</b> ' : '') . '<span>' . nl2br(e($a['message'])) . '</span>';
    $attrs = ' data-ann="' . e(ann_ver($a)) . '" data-pos="' . $pos . '"' . ($pos === 'popup' ? ' data-freq="' . e($a['freq'] ?: 'once') . '" data-delay="' . (int)$a['delay_sec'] . '"' : '') . ($preview ? ' data-preview' : '');
    if ($pos === 'popup') {
        return '<div class="ann-pop ann-' . $style . '"' . $attrs . ' role="dialog" aria-modal="true" aria-label="Aviso"' . ($preview ? '' : ' hidden') . '><div class="ann-pop-box">' . $close
            . ($a['image'] ? '<img class="ann-img" src="' . e($a['image']) . '" alt="">' : '')
            . '<div class="ann-pop-body">' . ($a['title'] !== '' ? '<h3>' . e($a['title']) . '</h3>' : '') . '<p>' . nl2br(e($a['message'])) . '</p>' . $btn . '</div></div></div>';
    }
    return '<div class="ann-bar ann-' . $pos . ' ann-' . $style . '"' . $attrs . ' role="region" aria-label="Aviso"><div class="ann-inner"><div class="ann-text">' . $text . '</div>' . $btn . '</div>' . $close . '</div>';
}
