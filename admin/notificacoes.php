<?php
require __DIR__ . '/_init.php';

if (is_post()) {
    csrf_check();
    $action = in_str('action');
    $id = in_int('id');
    try {
        if ($action === 'send') {
            if (in_str('title') === '') throw new RuntimeException('Escreva o título da notificação.');
            $cid = in_int('content_id') ?: null;
            notif_create([
                'title' => in_str('title'), 'message' => (string)($_POST['message'] ?? ''), 'link' => in_str('link'),
                'image' => in_str('image'), 'content_id' => $cid, 'audience' => in_str('audience'), 'days' => in_int('days'),
            ]);
            flash('success', 'Notificação enviada! Ela já aparece no sininho do site.');
        }
        if ($action === 'toggle') { q('UPDATE notifications SET active = 1 - active WHERE id = ?', [$id]); cache_bump(); }
        if ($action === 'delete') { q('DELETE FROM notifications WHERE id = ?', [$id]); cache_bump(); flash('success', 'Notificação apagada.'); }
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }
    redirect('admin/notificacoes.php');
}

$rows = q_all('SELECT * FROM notifications ORDER BY id DESC LIMIT 200');
$recent = q_all('SELECT id, title, type, year, slug, backdrop, poster FROM contents WHERE status = ? ORDER BY id DESC LIMIT 300', ['published']);
$typeName = ['movie' => 'Filme', 'series' => 'Série', 'anime' => 'Anime'];
$audName = ['all' => 'Todos', 'users' => 'Só registrados', 'guests' => 'Só visitantes'];

admin_header('Notificações', 'notificacoes');
?>
<div class="cols notif-cols">
<section class="panel">
  <div class="panel-head"><h2><?= icon('bell') ?> Nova notificação</h2></div>
  <p class="muted small">Aparece no sininho do topo do site, com a bolinha vermelha de "não lida", igual à Netflix.</p>
  <div class="notif-quick">
    <span class="muted small">Modelos:</span>
    <button type="button" class="btn btn-sm" data-tpl="novo">🎬 Título novo</button>
    <button type="button" class="btn btn-sm" data-tpl="manut">🔧 Manutenção</button>
    <button type="button" class="btn btn-sm" data-tpl="aviso">📢 Aviso geral</button>
  </div>
  <form method="post" class="stack" id="notif-form">
    <?= csrf_field() ?><input type="hidden" name="action" value="send">
    <input type="hidden" name="content_id" id="nt-cid">
    <label class="field" id="nt-pick-f"><span>Sobre um título do site (opcional)</span>
      <select id="nt-pick">
        <option value="">— Nenhum —</option>
        <?php foreach ($recent as $c): ?>
          <option value="<?= (int)$c['id'] ?>" data-type="<?= e($typeName[$c['type']] ?? '') ?>" data-title="<?= e($c['title']) ?>" data-link="<?= e(content_url($c)) ?>" data-img="<?= e(abs_img($c['backdrop'] ?: $c['poster'], 'w300') ?? '') ?>"><?= e($c['title']) ?><?= $c['year'] ? ' (' . (int)$c['year'] . ')' : '' ?> — <?= e($typeName[$c['type']] ?? '') ?></option>
        <?php endforeach; ?>
      </select>
      <small class="muted">Escolha um filme, série ou anime e o título, a imagem e o link são preenchidos sozinhos.</small>
    </label>
    <label class="field"><span>Título *</span><input name="title" id="nt-title" maxlength="150" required placeholder="Ex: Novo filme: Coringa"></label>
    <label class="field"><span>Mensagem</span><textarea name="message" id="nt-msg" rows="3" placeholder="Ex: Já disponível dublado e legendado. Aproveite!"></textarea></label>
    <div class="cols">
      <label class="field"><span>Link ao clicar (opcional)</span><input name="link" id="nt-link" placeholder="https://…"></label>
      <label class="field"><span>Imagem (opcional)</span><input name="image" id="nt-img" placeholder="https://…/imagem.jpg"></label>
    </div>
    <div class="cols">
      <label class="field"><span>Para quem</span>
        <select name="audience">
          <option value="all">Todos (com e sem conta)</option>
          <option value="users">Só usuários registrados</option>
          <option value="guests">Só visitantes (sem conta)</option>
        </select></label>
      <label class="field"><span>Sumir depois de</span>
        <select name="days" id="nt-days">
          <option value="0">Nunca (fica até eu apagar)</option>
          <option value="1">1 dia</option><option value="3">3 dias</option><option value="7">7 dias</option><option value="30">30 dias</option>
        </select></label>
    </div>
    <div class="notif-preview" aria-hidden="true">
      <span class="muted small">Prévia</span>
      <div class="np-item"><img id="np-img" alt="" hidden><span class="np-ico" id="np-ico"><?= icon('bell') ?></span><span><b id="np-title">Título da notificação</b><span id="np-msg"></span><small>agora</small></span></div>
    </div>
    <button class="btn btn-red btn-lg"><?= icon('bell') ?> Enviar notificação</button>
  </form>
</section>

<section class="panel">
  <div class="panel-head"><h2>Enviadas</h2></div>
  <?php if (!$rows): ?>
    <p class="muted">Nenhuma notificação enviada ainda.</p>
  <?php else: ?>
  <ul class="notif-sent">
    <?php foreach ($rows as $n): $expired = $n['expires_at'] && strtotime($n['expires_at']) < time(); ?>
      <li class="<?= !$n['active'] || $expired ? 'off' : '' ?>">
        <?php if ($n['image']): ?><img src="<?= e($n['image']) ?>" alt="" loading="lazy"><?php else: ?><span class="np-ico"><?= icon('bell') ?></span><?php endif; ?>
        <div class="ns-txt">
          <b><?= e($n['title']) ?></b>
          <?php if ($n['message']): ?><span><?= e(mb_strimwidth($n['message'], 0, 120, '…')) ?></span><?php endif; ?>
          <small><?= e(time_ago($n['created_at'])) ?> • <?= e($audName[$n['audience']] ?? $n['audience']) ?><?= $expired ? ' • expirou' : ($n['expires_at'] ? ' • some em ' . e(date('d/m H:i', strtotime($n['expires_at']))) : '') ?><?= !$n['active'] ? ' • escondida' : '' ?></small>
        </div>
        <div class="ns-act">
          <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$n['id'] ?>"><button class="btn btn-sm" title="<?= $n['active'] ? 'Esconder' : 'Mostrar de novo' ?>"><?= $n['active'] ? 'Esconder' : 'Mostrar' ?></button></form>
          <form method="post" data-confirm="Apagar esta notificação?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$n['id'] ?>"><button class="btn btn-sm btn-danger" title="Apagar"><?= icon('trash') ?></button></form>
        </div>
      </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</section>
</div>
<?php admin_footer();
