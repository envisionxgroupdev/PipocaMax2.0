<?php
/**
 * "Continuar assistindo"
 *  - Usuário com conta: fica no banco (tabela watch_history), aparece em qualquer aparelho.
 *  - Visitante: fica no cookie pm_cw do próprio navegador ("conteudo-episodio-segundos-duracao", separados por _).
 *  - Quando o visitante entra na conta, o que estava no cookie passa para a conta.
 */

const CW_MAX = 20;
const CW_COOKIE = 'pm_cw';

/** Lê o cookie do visitante: [[c, e, t, d], ...] do mais recente para o mais antigo */
function cw_cookie_items(): array
{
    $raw = (string)($_COOKIE[CW_COOKIE] ?? '');
    if ($raw === '' || strlen($raw) > 2000) return [];
    $out = [];
    foreach (explode('_', $raw) as $part) {
        if (!preg_match('/^(\d{1,9})-(\d{0,9})-(\d{1,6})-(\d{1,6})$/', $part, $m)) continue;
        $c = (int)$m[1];
        if ($c <= 0 || isset($out[$c])) continue;
        $out[$c] = [$c, $m[2] === '' ? null : (int)$m[2], (int)$m[3], (int)$m[4]];
        if (count($out) >= CW_MAX) break;
    }
    return array_values($out);
}

/** Salva/atualiza um título no histórico da conta */
function cw_save(int $uid, int $cid, ?int $eid, int $t, int $d, ?string $when = null): bool
{
    if (!q_val("SELECT 1 FROM contents WHERE id = ? AND status = 'published'", [$cid])) return false;
    if ($eid && !q_val('SELECT 1 FROM episodes WHERE id = ? AND content_id = ?', [$eid, $cid])) $eid = null;
    $t = max(0, min($t, 999999));
    $d = max(0, min($d, 999999));
    $when = $when ?: date('Y-m-d H:i:s');
    $row = q_one('SELECT episode_id, position, duration FROM watch_history WHERE user_id = ? AND content_id = ?', [$uid, $cid]);
    if ($row) {
        // Mesmo episódio sem tempo novo (ex.: só abriu de novo): mantém onde parou
        if ($t === 0 && (int)$row['episode_id'] === (int)$eid) {
            $t = (int)$row['position'];
            $d = $d ?: (int)$row['duration'];
        }
        q('UPDATE watch_history SET episode_id = ?, position = ?, duration = ?, updated_at = ? WHERE user_id = ? AND content_id = ?',
            [$eid, $t, $d, $when, $uid, $cid]);
    } else {
        q('INSERT INTO watch_history (user_id, content_id, episode_id, position, duration, updated_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$uid, $cid, $eid, $t, $d, $when]);
        // Guarda só os mais recentes
        $old = q_all('SELECT content_id FROM watch_history WHERE user_id = ? ORDER BY updated_at DESC LIMIT 100 OFFSET ' . (CW_MAX + 10), [$uid]);
        foreach ($old as $o) q('DELETE FROM watch_history WHERE user_id = ? AND content_id = ?', [$uid, $o['content_id']]);
    }
    return true;
}

function cw_remove(int $uid, int $cid): void
{
    q('DELETE FROM watch_history WHERE user_id = ? AND content_id = ?', [$uid, $cid]);
}

/** Passa o que o visitante tinha no cookie para a conta (chamar antes de enviar o HTML) */
function cw_merge_cookie(int $uid): void
{
    $items = cw_cookie_items();
    if (!$items) return;
    $now = time();
    foreach ($items as $i => [$c, $e, $t, $d]) {
        $has = q_val('SELECT updated_at FROM watch_history WHERE user_id = ? AND content_id = ?', [$uid, $c]);
        if ($has) continue; // a conta já tem esse título: vale o da conta
        cw_save($uid, $c, $e, $t, $d, date('Y-m-d H:i:s', $now - 60 - $i));
    }
    if (!headers_sent()) setcookie(CW_COOKIE, '', ['expires' => time() - 3600, 'path' => (BASE_URL ?: '') . '/', 'samesite' => 'Lax']);
}

/** Lista pronta para mostrar: [[c, e, t, d], ...] */
function cw_list_items(): array
{
    if ($u = current_user()) {
        $rows = q_all('SELECT content_id, episode_id, position, duration FROM watch_history WHERE user_id = ? ORDER BY updated_at DESC LIMIT ' . CW_MAX, [$u['id']]);
        return array_map(fn($r) => [(int)$r['content_id'], $r['episode_id'] ? (int)$r['episode_id'] : null, (int)$r['position'], (int)$r['duration']], $rows);
    }
    return cw_cookie_items();
}

/** Monta a fileira "Continuar assistindo" (vazia = não mostra nada) */
function cw_render_row(): string
{
    $items = cw_list_items();
    if (!$items) return '';
    $cids = array_map(fn($i) => $i[0], $items);
    $eids = array_filter(array_map(fn($i) => $i[1], $items));
    $in = fn(array $ids) => implode(',', array_map('intval', $ids));
    $contents = [];
    foreach (q_all("SELECT id, type, title, slug, poster, backdrop, year, runtime FROM contents WHERE status = 'published' AND id IN (" . $in($cids) . ')') as $c) {
        $contents[(int)$c['id']] = $c;
    }
    $eps = [];
    if ($eids) {
        foreach (q_all('SELECT e.id, e.content_id, e.episode_number, e.name, e.still, e.runtime, s.season_number FROM episodes e JOIN seasons s ON s.id = e.season_id WHERE e.id IN (' . $in($eids) . ')') as $e) {
            $eps[(int)$e['id']] = $e;
        }
    }
    $cards = '';
    $n = 0;
    foreach ($items as [$cid, $eid, $t, $d]) {
        $c = $contents[$cid] ?? null;
        if (!$c) continue;
        $ep = $eid && isset($eps[$eid]) && (int)$eps[$eid]['content_id'] === $cid ? $eps[$eid] : null;
        $cards .= cw_render_card($c, $ep, $t, $d, $n++ >= 5);
    }
    if ($cards === '') return '';
    return '<section class="row cw-row" id="continuar" data-cw><div class="row-head"><h2>Continuar assistindo'
        . '</h2></div><div class="row-wrap"><button class="row-nav prev" aria-label="Anterior">‹</button><div class="row-track">'
        . $cards . '</div><button class="row-nav next" aria-label="Próximo">›</button></div></section>';
}

function cw_render_card(array $c, ?array $ep, int $t, int $d, bool $defer): string
{
    $isSeries = ($c['type'] ?? 'movie') !== 'movie';
    $href = content_url($c) . ($isSeries && $ep ? '#ep-' . (int)$ep['id'] : '#continuar');
    $imgPath = $ep && $ep['still'] ? $ep['still'] : ($c['backdrop'] ?: $c['poster']);
    $src = img($imgPath, 'w500');
    // Duração: a que o player informou, ou a do cadastro (minutos)
    if ($d <= 0) $d = (int)(($ep['runtime'] ?? 0) ?: (!$isSeries ? ($c['runtime'] ?? 0) : 0)) * 60;
    $pct = $d > 0 && $t > 0 ? max(2, min(100, (int)round($t / $d * 100))) : 0;
    $sub = '';
    if ($isSeries && $ep) {
        $sub = ((int)$ep['season_number'] === 0 ? 'Especial' : 'T' . (int)$ep['season_number']) . ':E' . (int)$ep['episode_number']
            . ($ep['name'] ? ' • ' . $ep['name'] : '');
    } else {
        $sub = type_label($c['type']) . ($c['year'] ? ' • ' . (int)$c['year'] : '');
    }
    $left = '';
    if ($d > 0 && $t > 0) {
        $min = max(1, (int)ceil(($d - $t) / 60));
        $left = $min >= 60 ? 'Faltam ' . intdiv($min, 60) . 'h' . ($min % 60 ? sprintf('%02d', $min % 60) : '') : 'Faltam ' . $min . ' min';
    } elseif ($t === 0 && $isSeries && $ep) {
        $left = 'Próximo episódio';
    }
    $imgTag = $defer
        ? '<img class="lz" decoding="async" src="data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw==" data-src="' . e($src) . '" alt="">'
        : '<img loading="lazy" decoding="async" src="' . e($src) . '" alt="">';
    return '<a class="card cw-card" href="' . e($href) . '" title="' . e($c['title']) . '" data-cw-id="' . (int)$c['id'] . '">'
        . '<div class="card-img cw-img">' . $imgTag
        . '<div class="card-hover"><span class="play-ico"></span></div>'
        . ($left ? '<span class="cw-left">' . e($left) . '</span>' : '')
        . ($pct ? '<span class="cw-bar"><i style="width:' . $pct . '%"></i></span>' : '')
        . '<button type="button" class="cw-del" data-cw-del="' . (int)$c['id'] . '" aria-label="Tirar de Continuar assistindo" title="Tirar da lista"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M6 6l12 12M18 6 6 18"/></svg></button>'
        . '</div><div class="card-info"><h3>' . e($c['title']) . '</h3><p>' . e($sub) . '</p></div></a>';
}
