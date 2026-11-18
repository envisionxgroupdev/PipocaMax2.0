<?php
require __DIR__ . '/_init.php';

if (is_post()) {
    csrf_check();
    $action = in_str('action');
    $id = in_int('id');
    $st = in_str('st');
    try {
        $r = $id ? q_one('SELECT * FROM requests WHERE id = ?', [$id]) : null;
        if ($action === 'import' && $r) {
            if (!tmdb_configured()) throw new RuntimeException('Configure a chave do TMDB em Configurações primeiro.');
            @set_time_limit(120);
            $cid = tmdb_import((int)$r['tmdb_id'], $r['type'], true, ['season_limit' => 5]);
            req_fulfill($id, $cid); // garante (caso já existisse como rascunho, por exemplo)
            $done = q_val('SELECT status FROM requests WHERE id = ?', [$id]) === 'added';
            flash('success', '“' . $r['title'] . '” importado' . ($done ? ' e ' . (int)$r['votes'] . ' pessoa(s) avisada(s) no sininho. Confira os players em Atendidos › Abrir título.' : '. Ele está como rascunho: publique para avisar quem pediu.'));
        }
        if ($action === 'reject' && $r) {
            q("UPDATE requests SET status = 'rejected', note = ?, updated_at = ?, done_at = ? WHERE id = ?",
                [mb_substr(in_str('note'), 0, 200) ?: null, date('Y-m-d H:i:s'), date('Y-m-d H:i:s'), $id]);
            flash('success', 'Pedido marcado como não disponível.');
        }
        if ($action === 'reopen' && $r) {
            q("UPDATE requests SET status = 'pending', note = NULL, done_at = NULL, updated_at = ? WHERE id = ?", [date('Y-m-d H:i:s'), $id]);
            flash('success', 'Pedido voltou para a fila.');
        }
        if ($action === 'delete' && $r) {
            q('DELETE FROM requests WHERE id = ?', [$id]);
            flash('success', 'Pedido apagado.');
        }
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }
    redirect('admin/pedidos.php' . ($st ? '?st=' . urlencode($st) : ''));
}

$synced = req_sync(); // pedidos que já entraram no site por outro caminho
$st = in_str('st', 'pending');
if (!in_array($st, ['pending', 'added', 'rejected'], true)) $st = 'pending';
$order = $st === 'pending' ? 'r.votes DESC, r.created_at ASC' : 'r.done_at DESC';
$rows = q_all("SELECT r.*, u.name AS uname FROM requests r LEFT JOIN users u ON u.id = r.user_id WHERE r.status = ? ORDER BY $order LIMIT 300", [$st]);
$cnt = [];
foreach (q_all('SELECT status, COUNT(*) n FROM requests GROUP BY status') as $c) $cnt[$c['status']] = (int)$c['n'];
$weekVotes = (int)q_val('SELECT COUNT(*) FROM request_votes WHERE created_at > ?', [date('Y-m-d H:i:s', time() - 7 * 86400)]);

admin_header('Pedidos', 'pedidos');
?>
<div class="toolbar">
  <div class="tabs">
    <a class="<?= $st === 'pending' ? 'active' : '' ?>" href="<?= e(url('admin/pedidos.php')) ?>">Na fila <b class="count"><?= $cnt['pending'] ?? 0 ?></b></a>
    <a class="<?= $st === 'added' ? 'active' : '' ?>" href="<?= e(url('admin/pedidos.php?st=added')) ?>">Atendidos (<?= $cnt['added'] ?? 0 ?>)</a>
    <a class="<?= $st === 'rejected' ? 'active' : '' ?>" href="<?= e(url('admin/pedidos.php?st=rejected')) ?>">Não disponíveis (<?= $cnt['rejected'] ?? 0 ?>)</a>
  </div>
  <a class="btn btn-sm" href="<?= e(page_url('pedidos')) ?>" target="_blank"><?= icon('external') ?> Ver a página de pedidos</a>
</div>
<p class="muted small">Pedidos dos usuários, dos mais pedidos para os menos. <b>Importar</b> traz o título do TMDB e avisa no sininho quem pediu.
  Títulos importados por outro caminho (importação manual ou automática) também marcam o pedido como atendido sozinhos.
  <?= $weekVotes ? ' Nos últimos 7 dias: ' . $weekVotes . ' pedido(s).' : '' ?><?= $synced ? ' <b>' . $synced . ' pedido(s) já estavam no site e foram atendidos agora.</b>' : '' ?></p>

<div class="req-admin">
  <?php foreach ($rows as $i => $r): ?>
    <div class="req-a-item">
      <?php if ($st === 'pending'): ?><span class="req-a-rank"><?= $i + 1 ?></span><?php endif; ?>
      <img src="<?= e(img($r['poster'], 'w92')) ?>" width="46" height="69" loading="lazy" alt="">
      <div class="req-a-info">
        <b><?= e($r['title']) ?></b>
        <small><?= e(req_type_name($r['type'])) ?><?= $r['year'] ? ' • ' . (int)$r['year'] : '' ?><?= $r['original_title'] && $r['original_title'] !== $r['title'] ? ' • ' . e($r['original_title']) : '' ?>
          • <a href="https://www.themoviedb.org/<?= $r['media'] === 'movie' ? 'movie' : 'tv' ?>/<?= (int)$r['tmdb_id'] ?>" target="_blank" rel="noopener">TMDB</a></small>
        <small class="muted">Pedido <?= e(time_ago($r['created_at'])) ?><?= $r['uname'] ? ' por ' . e($r['uname']) : '' ?><?= $r['note'] ? ' • Motivo: ' . e($r['note']) : '' ?></small>
      </div>
      <div class="req-a-votes"><b><?= (int)$r['votes'] ?></b><small><?= (int)$r['votes'] === 1 ? 'pedido' : 'pedidos' ?></small></div>
      <div class="req-a-act">
        <?php if ($st === 'pending'): ?>
          <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="import"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
            <button class="btn btn-red btn-sm" data-loading="Importando do TMDB…"><?= icon('download') ?> Importar</button></form>
          <details class="req-a-rej"><summary class="btn btn-sm">Não disponível</summary>
            <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="reject"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <input type="text" name="note" maxlength="200" placeholder="Motivo (opcional), ex.: ainda no cinema">
              <button class="btn btn-sm">Confirmar</button></form>
          </details>
        <?php elseif ($st === 'added'): ?>
          <?php if ($r['content_id']): ?><a class="btn btn-sm" href="<?= e(url('admin/conteudo.php?id=' . (int)$r['content_id'])) ?>"><?= icon('film') ?> Abrir título</a><?php endif; ?>
        <?php else: ?>
          <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="reopen"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="st" value="<?= e($st) ?>"><button class="btn btn-sm">Voltar para a fila</button></form>
        <?php endif; ?>
        <?= delete_button('', ['action' => 'delete', 'id' => $r['id'], 'st' => $st]) ?>
      </div>
    </div>
  <?php endforeach; ?>
  <?php if (!$rows): ?><div class="empty-cell"><?= $st === 'pending' ? 'Nenhum pedido na fila. 🎉' : 'Nada aqui ainda.' ?></div><?php endif; ?>
</div>
<?php admin_footer(); ?>
