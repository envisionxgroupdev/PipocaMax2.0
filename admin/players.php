<?php
/** Ações dos players (adicionar, salvar, excluir, reordenar) */
require __DIR__ . '/_init.php';
if (!is_post()) redirect('admin/');
csrf_check();

$action = in_str('action');
$back = safe_back(in_str('back'));
$kinds = ['embed', 'video', 'hls'];

$name = mb_substr(in_str('name'), 0, 100) ?: 'Player';
$urlv = clean_url(in_str('url'));
if (in_str('url') !== '' && $urlv === '') {
    flash('error', 'Link inválido: use um endereço começando com http:// ou https://');
    redirect($back);
}
$kind = in_str('kind');
$audio = mb_substr(in_str('audio'), 0, 30);
$quality = mb_substr(in_str('quality'), 0, 20);

if ($action === 'add') {
    $cid = in_int('content_id') ?: null;
    $eid = in_int('episode_id') ?: null;
    if ((!$cid && !$eid) || $urlv === '') {
        flash('error', 'Informe a URL do player.');
        redirect($back);
    }
    if ($kind === 'auto' || !in_array($kind, $kinds, true)) {
        $kind = player_kind_from_url($urlv);
    }
    $sort = (int)q_val('SELECT COALESCE(MAX(sort), -1) + 1 FROM players WHERE ' . ($cid ? 'content_id' : 'episode_id') . ' = ?', [$cid ?: $eid]);
    q('INSERT INTO players (content_id, episode_id, name, url, kind, audio, quality, sort) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        [$cid, $eid, $name, $urlv, $kind, $audio, $quality, $sort]);
    flash('success', 'Player adicionado.');
    redirect($back);
}

$id = in_int('id');
$p = q_one('SELECT * FROM players WHERE id = ?', [$id]);
if (!$p) {
    flash('error', 'Player não encontrado.');
    redirect($back);
}

switch ($action) {
    case 'save':
        if ($urlv === '') { flash('error', 'A URL não pode ficar vazia.'); break; }
        if (!in_array($kind, $kinds, true)) $kind = player_kind_from_url($urlv);
        q('UPDATE players SET name = ?, url = ?, kind = ?, audio = ?, quality = ? WHERE id = ?', [$name, $urlv, $kind, $audio, $quality, $id]);
        flash('success', 'Player salvo.');
        break;
    case 'delete':
        q('DELETE FROM players WHERE id = ?', [$id]);
        flash('success', 'Player excluído.');
        break;
    case 'up':
    case 'down':
        $col = $p['content_id'] ? 'content_id' : 'episode_id';
        $list = q_all("SELECT id FROM players WHERE $col = ? ORDER BY sort, id", [$p[$col]]);
        $ids = array_map('intval', array_column($list, 'id'));
        $pos = array_search($id, $ids, true);
        $swap = $action === 'up' ? $pos - 1 : $pos + 1;
        if ($pos !== false && isset($ids[$swap])) {
            [$ids[$pos], $ids[$swap]] = [$ids[$swap], $ids[$pos]];
        }
        foreach ($ids as $i => $pid) {
            q('UPDATE players SET sort = ? WHERE id = ?', [$i, $pid]);
        }
        break;
}
redirect($back);
