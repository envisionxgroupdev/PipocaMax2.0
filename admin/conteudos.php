<?php
require __DIR__ . '/_init.php';

if (is_post()) {
    csrf_check();
    $id = in_int('id');
    $action = in_str('action');
    $back = safe_back(in_str('back'));
    if ($action === 'delete') {
        q('DELETE FROM contents WHERE id = ?', [$id]);
        flash('success', 'Conteúdo excluído.');
    } elseif (in_array($action, ['featured', 'is_release'], true)) {
        q("UPDATE contents SET `$action` = 1 - `$action` WHERE id = ?", [$id]);
    } elseif ($action === 'status') {
        q("UPDATE contents SET status = IF(status = 'published', 'draft', 'published') WHERE id = ?", [$id]);
    } elseif ($action === 'bulk') {
        $ids = array_map('intval', (array)($_POST['ids'] ?? []));
        $op = in_str('op');
        if ($ids) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $ops = [
                'publish' => "UPDATE contents SET status = 'published' WHERE id IN ($in)",
                'draft' => "UPDATE contents SET status = 'draft' WHERE id IN ($in)",
                'release_on' => "UPDATE contents SET is_release = 1 WHERE id IN ($in)",
                'release_off' => "UPDATE contents SET is_release = 0 WHERE id IN ($in)",
                'featured_on' => "UPDATE contents SET featured = 1 WHERE id IN ($in)",
                'featured_off' => "UPDATE contents SET featured = 0 WHERE id IN ($in)",
                'delete' => "DELETE FROM contents WHERE id IN ($in)",
            ];
            if (isset($ops[$op])) {
                q($ops[$op], $ids);
                flash('success', count($ids) . ' item(ns) atualizado(s).');
            }
        }
    }
    redirect($back);
}

$tipo = in_str('tipo');
$status = in_str('status');
$busca = in_str('q');
$semPlayer = in_int('sem_player');
$where = ['1=1'];
$params = [];
if (in_array($tipo, ['movie', 'series', 'anime'], true)) { $where[] = 'c.type = ?'; $params[] = $tipo; }
if (in_array($status, ['published', 'draft'], true)) { $where[] = 'c.status = ?'; $params[] = $status; }
if ($status === 'release') { $where[] = 'c.is_release = 1'; }
if ($status === 'featured') { $where[] = 'c.featured = 1'; }
if ($busca !== '') { $where[] = '(c.title LIKE ? OR c.original_title LIKE ? OR c.tmdb_id = ?)'; array_push($params, "%$busca%", "%$busca%", (int)$busca); }
if ($semPlayer) { $where[] = "c.type = 'movie' AND NOT EXISTS (SELECT 1 FROM players p WHERE p.content_id = c.id)"; }
$w = implode(' AND ', $where);

$perPage = 30;
$page = max(1, in_int('p', 1));
$total = (int)q_val("SELECT COUNT(*) FROM contents c WHERE $w", $params);
$rows = q_all(
    "SELECT c.*,
       (SELECT COUNT(*) FROM players p WHERE p.content_id = c.id) AS nplayers,
       (SELECT COUNT(*) FROM seasons s WHERE s.content_id = c.id) AS nseasons,
       (SELECT COUNT(*) FROM episodes e WHERE e.content_id = c.id) AS neps
     FROM contents c WHERE $w ORDER BY c.created_at DESC LIMIT $perPage OFFSET " . (($page - 1) * $perPage),
    $params
);
$qs = http_build_query(array_filter(['tipo' => $tipo, 'status' => $status, 'q' => $busca, 'sem_player' => $semPlayer ?: null]));
$self = url('admin/conteudos.php') . ($qs ? '?' . $qs : '');
$back = $self . ($page > 1 ? ($qs ? '&' : '?') . 'p=' . $page : '');

admin_header('Conteúdos', 'conteudos');
?>
<div class="toolbar">
  <div class="tabs">
    <?php foreach (['' => 'Todos', 'movie' => 'Filmes', 'series' => 'Séries', 'anime' => 'Animes'] as $k => $l): ?>
      <a class="<?= $tipo === $k ? 'active' : '' ?>" href="<?= e(url('admin/conteudos.php' . ($k ? '?tipo=' . $k : ''))) ?>"><?= $l ?></a>
    <?php endforeach; ?>
  </div>
  <div class="toolbar-right">
    <a class="btn btn-red" href="<?= e(url('admin/importar.php')) ?>"><?= icon('download') ?> Importar do TMDB</a>
    <a class="btn" href="<?= e(url('admin/conteudo.php?novo=' . ($tipo ?: 'movie'))) ?>"><?= icon('plus') ?> Novo título</a>
  </div>
</div>

<form class="filterbar" method="get">
  <input type="hidden" name="tipo" value="<?= e($tipo) ?>">
  <input type="search" name="q" value="<?= e($busca) ?>" placeholder="Buscar por título ou ID do TMDB">
  <select name="status">
    <option value="">Qualquer status</option>
    <option value="published" <?= $status === 'published' ? 'selected' : '' ?>>Publicados</option>
    <option value="draft" <?= $status === 'draft' ? 'selected' : '' ?>>Rascunhos</option>
    <option value="release" <?= $status === 'release' ? 'selected' : '' ?>>Lançamentos</option>
    <option value="featured" <?= $status === 'featured' ? 'selected' : '' ?>>Destaques</option>
  </select>
  <button class="btn">Filtrar</button>
  <span class="muted"><?= $total ?> resultado(s)</span>
</form>

<form method="post" id="bulk">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="bulk">
  <input type="hidden" name="back" value="<?= e($back) ?>">
  <div class="bulkbar">
    <select name="op">
      <option value="">Ações em massa…</option>
      <option value="publish">Publicar</option>
      <option value="draft">Mover para rascunho</option>
      <option value="release_on">Marcar como lançamento</option>
      <option value="release_off">Tirar dos lançamentos</option>
      <option value="featured_on">Colocar em destaque</option>
      <option value="featured_off">Tirar do destaque</option>
      <option value="delete">Excluir</option>
    </select>
    <button class="btn btn-sm" data-confirm-click="Aplicar a ação aos itens selecionados?">Aplicar</button>
  </div>
</form>

<div class="table-wrap">
<table class="table">
  <thead><tr>
    <th class="chk"><input type="checkbox" data-check-all></th>
    <th>Título</th><th>Tipo</th><th>Players / Episódios</th><th>Lançamento</th><th>Destaque</th><th>Status</th><th>Views</th><th></th>
  </tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr>
      <td class="chk"><input type="checkbox" name="ids[]" value="<?= (int)$r['id'] ?>" form="bulk"></td>
      <td><div class="title-cell"><img src="<?= e(img($r['poster'], 'w92')) ?>" alt=""><div><a href="<?= e(url('admin/conteudo.php?id=' . $r['id'])) ?>"><b><?= e($r['title']) ?></b></a><small><?= $r['year'] ? (int)$r['year'] : '—' ?><?= $r['tmdb_id'] ? ' • TMDB ' . (int)$r['tmdb_id'] : '' ?></small></div></div></td>
      <td><span class="pill"><?= e(type_label($r['type'])) ?></span></td>
      <td>
        <?php if ($r['type'] === 'movie'): ?>
          <span class="pill <?= $r['nplayers'] ? 'pill-ok' : 'pill-warn' ?>"><?= (int)$r['nplayers'] ?> player(s)</span>
        <?php else: ?>
          <span class="pill"><?= (int)$r['nseasons'] ?> temp. • <?= (int)$r['neps'] ?> ep.</span>
        <?php endif; ?>
      </td>
      <td><?= toggle_btn($r, 'is_release', $back) ?></td>
      <td><?= toggle_btn($r, 'featured', $back) ?></td>
      <td><form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="action" value="status"><input type="hidden" name="back" value="<?= e($back) ?>"><button class="pill <?= $r['status'] === 'published' ? 'pill-ok' : '' ?>"><?= $r['status'] === 'published' ? 'Publicado' : 'Rascunho' ?></button></form></td>
      <td><?= number_format((int)$r['views'], 0, ',', '.') ?></td>
      <td class="actions">
        <a class="btn-ghost" href="<?= e(content_url($r)) ?>" target="_blank" title="Ver no site"><?= icon('eye') ?></a>
        <a class="btn-ghost" href="<?= e(url('admin/conteudo.php?id=' . $r['id'])) ?>" title="Editar"><?= icon('edit') ?></a>
        <?= delete_button('', ['id' => $r['id'], 'action' => 'delete', 'back' => $back], 'Excluir', 'Excluir "' . $r['title'] . '" e todos os seus players/episódios?') ?>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="9" class="empty-cell">Nenhum conteúdo encontrado. <a href="<?= e(url('admin/importar.php')) ?>">Importe do TMDB</a>.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
<?= paginate($total, $perPage, $page, $self) ?>
<?php
admin_footer();

function toggle_btn(array $r, string $field, string $back): string
{
    $on = (int)$r[$field] === 1;
    return '<form method="post" class="inline">' . csrf_field()
        . '<input type="hidden" name="id" value="' . (int)$r['id'] . '"><input type="hidden" name="action" value="' . $field . '">'
        . '<input type="hidden" name="back" value="' . e($back) . '">'
        . '<button class="switch sm' . ($on ? ' on' : '') . '" title="Alternar"><span></span></button></form>';
}
