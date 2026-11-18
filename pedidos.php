<?php
/** Pedidos de filmes, séries e animes */
require __DIR__ . '/includes/bootstrap.php';
check_maintenance();

$u = current_user();
$uid = $u ? (int)$u['id'] : 0;
$tab = in_str('aba');
if (!in_array($tab, ['top', 'chegaram', 'meus'], true)) $tab = 'top';
if ($tab === 'meus' && !$u) $tab = 'top';

$cols = 'r.id, r.tmdb_id, r.media, r.type, r.title, r.year, r.poster, r.votes, r.status, r.note, r.content_id, r.created_at, r.done_at';
if ($tab === 'chegaram') {
    $rows = q_all("SELECT $cols FROM requests r WHERE r.status = 'added' ORDER BY r.done_at DESC LIMIT 40");
} elseif ($tab === 'meus') {
    $rows = q_all("SELECT $cols FROM requests r JOIN request_votes v ON v.request_id = r.id AND v.user_id = ? ORDER BY (r.status = 'pending') DESC, v.created_at DESC LIMIT 100", [$uid]);
} else {
    $rows = q_all("SELECT $cols FROM requests r WHERE r.status = 'pending' ORDER BY r.votes DESC, r.created_at ASC LIMIT 60");
}
$myVotes = [];
if ($uid && $rows) {
    $ids = implode(',', array_map(fn($r) => (int)$r['id'], $rows));
    foreach (q_all("SELECT request_id FROM request_votes WHERE user_id = ? AND request_id IN ($ids)", [$uid]) as $v) $myVotes[(int)$v['request_id']] = true;
}
$contents = [];
$cids = array_filter(array_map(fn($r) => (int)$r['content_id'], $rows));
if ($cids) {
    foreach (q_all('SELECT id, type, slug FROM contents WHERE id IN (' . implode(',', $cids) . ')') as $c) $contents[(int)$c['id']] = $c;
}
$counts = [
    'top' => (int)q_val("SELECT COUNT(*) FROM requests WHERE status = 'pending'"),
    'chegaram' => (int)q_val("SELECT COUNT(*) FROM requests WHERE status = 'added'"),
];

$pageTitle = 'Pedidos de filmes e séries - ' . site_name();
$noindex = true;
$active = 'pedidos';
$canonical = site_url(page_url('pedidos'));
require __DIR__ . '/includes/header.php';
?>
<div class="container page req-page" data-req>
  <div class="req-hero">
    <h1>Pedidos</h1>
    <p>Não achou o que queria? Peça aqui. Os títulos mais pedidos entram primeiro, e você recebe um aviso no <?= icon('bell') ?> quando chegar.</p>
    <form class="req-search" data-req-form autocomplete="off">
      <?= icon('search') ?>
      <input type="search" name="q" placeholder="Digite o nome do filme, série ou anime" maxlength="80" data-req-q aria-label="Nome do título">
      <button class="btn btn-red" type="submit">Buscar</button>
    </form>
    <?php if (!$u): ?>
      <p class="req-login"><a href="<?= e(page_url('login', 'voltar=' . rawurlencode(page_url('pedidos')))) ?>">Entre</a> ou <a href="<?= e(page_url('cadastro')) ?>">crie sua conta grátis</a> para pedir e ser avisado.</p>
    <?php else: ?>
      <p class="req-login muted">Você pode fazer até <?= REQ_DAILY_LIMIT ?> pedidos por dia.</p>
    <?php endif; ?>
  </div>

  <div class="req-results" data-req-results hidden></div>

  <nav class="req-tabs">
    <a href="<?= e(page_url('pedidos')) ?>" class="<?= $tab === 'top' ? 'active' : '' ?>">Mais pedidos <small><?= $counts['top'] ?></small></a>
    <a href="<?= e(page_url('pedidos', 'aba=chegaram')) ?>" class="<?= $tab === 'chegaram' ? 'active' : '' ?>">Já chegaram <small><?= $counts['chegaram'] ?></small></a>
    <?php if ($u): ?><a href="<?= e(page_url('pedidos', 'aba=meus')) ?>" class="<?= $tab === 'meus' ? 'active' : '' ?>">Meus pedidos</a><?php endif; ?>
  </nav>

  <?php if (!$rows): ?>
    <div class="empty"><?= icon('film', 'ico ico-xl') ?><p><?= $tab === 'meus' ? 'Você ainda não pediu nada. Busque um título acima.' : ($tab === 'chegaram' ? 'Nenhum pedido atendido ainda.' : 'Nenhum pedido ainda. Seja o primeiro!') ?></p></div>
  <?php else: ?>
    <ol class="req-list">
      <?php foreach ($rows as $i => $r): $rid = (int)$r['id']; $voted = isset($myVotes[$rid]); $c = $contents[(int)$r['content_id']] ?? null; ?>
        <li class="req-item req-<?= e($r['status']) ?>">
          <?php if ($tab === 'top'): ?><span class="req-rank"><?= $i + 1 ?></span><?php endif; ?>
          <img src="<?= e(img($r['poster'], 'w185')) ?>" width="60" height="90" loading="lazy" alt="">
          <div class="req-info">
            <b><?= e($r['title']) ?></b>
            <small><?= e(req_type_name($r['type'])) ?><?= $r['year'] ? ' • ' . (int)$r['year'] : '' ?></small>
            <?php if ($r['status'] === 'added'): ?>
              <span class="req-tag ok"><?= icon('check') ?> Chegou<?= $r['done_at'] ? ' ' . e(time_ago($r['done_at'])) : '' ?></span>
            <?php elseif ($r['status'] === 'rejected'): ?>
              <span class="req-tag no">Não disponível<?= $r['note'] ? ': ' . e($r['note']) : '' ?></span>
            <?php else: ?>
              <span class="req-votes" data-req-count="<?= $rid ?>"><?= (int)$r['votes'] ?> <?= (int)$r['votes'] === 1 ? 'pedido' : 'pedidos' ?></span>
            <?php endif; ?>
          </div>
          <div class="req-act">
            <?php if ($r['status'] === 'added' && $c): ?>
              <a class="btn btn-white btn-sm" href="<?= e(content_url($c)) ?>"><?= icon('play') ?> Assistir</a>
            <?php elseif ($r['status'] === 'pending'): ?>
              <button type="button" class="btn btn-sm req-vote<?= $voted ? ' on' : '' ?>" data-req-vote="<?= $rid ?>">
                <?= $voted ? icon('check') . ' Pedido' : icon('plus') . ' Eu também quero' ?>
              </button>
            <?php endif; ?>
          </div>
        </li>
      <?php endforeach; ?>
    </ol>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
