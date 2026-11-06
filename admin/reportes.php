<?php
require __DIR__ . '/_init.php';

if (is_post()) {
    csrf_check();
    $action = in_str('action');
    $id = in_int('id');
    if ($action === 'solve') q("UPDATE reports SET status = IF(status = 'open', 'solved', 'open') WHERE id = ?", [$id]);
    if ($action === 'delete') q('DELETE FROM reports WHERE id = ?', [$id]);
    if ($action === 'clear') { q("DELETE FROM reports WHERE status = 'solved'"); flash('success', 'Reportes resolvidos apagados.'); }
    redirect('admin/reportes.php' . (in_str('st') ? '?st=' . urlencode(in_str('st')) : ''));
}

$st = in_str('st', 'open');
$rows = q_all(
    "SELECT r.*, c.title, c.type, e.episode_number, s.season_number, ch.name AS channel, u.name AS uname
     FROM reports r
     LEFT JOIN contents c ON c.id = r.content_id
     LEFT JOIN episodes e ON e.id = r.episode_id
     LEFT JOIN seasons s ON s.id = e.season_id
     LEFT JOIN channels ch ON ch.id = r.channel_id
     LEFT JOIN users u ON u.id = r.user_id
     WHERE r.status = ? ORDER BY r.created_at DESC LIMIT 300",
    [$st === 'solved' ? 'solved' : 'open']
);

admin_header('Reportes', 'reportes');
?>
<div class="toolbar">
  <div class="tabs">
    <a class="<?= $st !== 'solved' ? 'active' : '' ?>" href="<?= e(url('admin/reportes.php')) ?>">Abertos</a>
    <a class="<?= $st === 'solved' ? 'active' : '' ?>" href="<?= e(url('admin/reportes.php?st=solved')) ?>">Resolvidos</a>
  </div>
  <?php if ($st === 'solved' && $rows): ?>
    <form method="post" data-confirm="Apagar todos os reportes resolvidos?"><?= csrf_field() ?><input type="hidden" name="action" value="clear"><button class="btn btn-sm btn-danger">Limpar resolvidos</button></form>
  <?php endif; ?>
</div>
<p class="muted small">Avisos enviados pelos usuários quando um player ou canal não funciona.</p>
<div class="table-wrap">
<table class="table">
  <thead><tr><th>Onde</th><th>Problema</th><th>Quem</th><th>Quando</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr>
      <td>
        <?php if ($r['channel']): ?>
          <a href="<?= e(url('admin/canais.php?id=' . $r['channel_id'])) ?>"><b><?= e($r['channel']) ?></b></a><small class="block">Canal de TV</small>
        <?php elseif ($r['title']): ?>
          <a href="<?= e(url($r['episode_id'] ? 'admin/episodio.php?id=' . $r['episode_id'] : 'admin/conteudo.php?id=' . $r['content_id'] . '#players')) ?>"><b><?= e($r['title']) ?></b></a>
          <small class="block"><?= e(type_label($r['type'])) ?><?= $r['episode_number'] ? ' • T' . (int)$r['season_number'] . ':E' . (int)$r['episode_number'] : '' ?></small>
        <?php else: ?><span class="muted">(removido)</span><?php endif; ?>
      </td>
      <td><?= e($r['message']) ?></td>
      <td class="muted"><?= e($r['uname'] ?: 'Visitante') ?></td>
      <td class="muted"><?= e(time_ago($r['created_at'])) ?></td>
      <td class="actions">
        <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="solve"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="st" value="<?= e($st) ?>"><button class="btn btn-sm"><?= $r['status'] === 'open' ? 'Resolvido' : 'Reabrir' ?></button></form>
        <?= delete_button('', ['action' => 'delete', 'id' => $r['id'], 'st' => $st]) ?>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="5" class="empty-cell">Nenhum reporte <?= $st === 'solved' ? 'resolvido' : 'aberto' ?>. 🎉</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
<?php admin_footer(); ?>
