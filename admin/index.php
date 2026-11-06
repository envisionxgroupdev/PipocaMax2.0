<?php
require __DIR__ . '/_init.php';

if (is_post() && in_str('action') === 'maintenance') {
    csrf_check();
    set_setting('maintenance', setting('maintenance') === '1' ? '0' : '1');
    flash('success', setting('maintenance') === '1' ? 'Modo manutenção ATIVADO. Só administradores veem o site.' : 'Modo manutenção desativado. O site está no ar.');
    redirect('admin/');
}

$stats = q_one("SELECT
    SUM(type = 'movie') AS movies, SUM(type = 'series') AS series, SUM(type = 'anime') AS animes,
    SUM(is_release = 1) AS releases, COALESCE(SUM(views), 0) AS views, SUM(status = 'draft') AS drafts
    FROM contents");
$episodes = (int)q_val('SELECT COUNT(*) FROM episodes');
$epNoPlayer = (int)q_val('SELECT COUNT(*) FROM episodes e WHERE NOT EXISTS (SELECT 1 FROM players p WHERE p.episode_id = e.id)');
$apiOn = api_has_movie_players() || api_has_episode_players();
$movieNoPlayer = api_has_movie_players()
    ? (int)q_val("SELECT COUNT(*) FROM contents c WHERE c.type = 'movie' AND c.tmdb_id IS NULL AND c.imdb_id IS NULL AND NOT EXISTS (SELECT 1 FROM players p WHERE p.content_id = c.id)")
    : (int)q_val("SELECT COUNT(*) FROM contents c WHERE c.type = 'movie' AND NOT EXISTS (SELECT 1 FROM players p WHERE p.content_id = c.id)");
if (api_has_episode_players()) {
    $epNoPlayer = (int)q_val('SELECT COUNT(*) FROM episodes e JOIN contents c ON c.id = e.content_id WHERE c.tmdb_id IS NULL AND c.imdb_id IS NULL AND NOT EXISTS (SELECT 1 FROM players p WHERE p.episode_id = e.id)');
}
$channels = (int)q_val("SELECT COUNT(*) FROM channels WHERE status = 'active'");
$users = (int)q_val('SELECT COUNT(*) FROM users');
$newUsers = (int)q_val('SELECT COUNT(*) FROM users WHERE created_at >= (NOW() - INTERVAL 7 DAY)');
$reports = (int)q_val("SELECT COUNT(*) FROM reports WHERE status = 'open'");
$recent = q_all('SELECT id, type, title, poster, year, status, created_at FROM contents ORDER BY created_at DESC LIMIT 8');
$top = q_all('SELECT id, type, title, poster, views FROM contents ORDER BY views DESC LIMIT 8');
$lastUsers = q_all('SELECT id, name, email, role, created_at, avatar_color FROM users ORDER BY created_at DESC LIMIT 6');

admin_header('Dashboard', 'dashboard');
?>
<?php if (!tmdb_configured()): ?>
  <div class="alert alert-info">Configure a chave da API do TMDB para importar filmes, séries e animes automaticamente. <a href="<?= e(url('admin/configuracoes.php#tmdb')) ?>">Configurar agora ›</a></div>
<?php endif; ?>

<div class="stats">
  <a class="stat" href="<?= e(url('admin/conteudos.php?tipo=movie')) ?>"><span class="stat-ico"><?= icon('film') ?></span><b><?= (int)$stats['movies'] ?></b><small>Filmes</small></a>
  <a class="stat" href="<?= e(url('admin/conteudos.php?tipo=series')) ?>"><span class="stat-ico"><?= icon('series') ?></span><b><?= (int)$stats['series'] ?></b><small>Séries</small></a>
  <a class="stat" href="<?= e(url('admin/conteudos.php?tipo=anime')) ?>"><span class="stat-ico"><?= icon('anime') ?></span><b><?= (int)$stats['animes'] ?></b><small>Animes</small></a>
  <div class="stat"><span class="stat-ico"><?= icon('play') ?></span><b><?= $episodes ?></b><small>Episódios</small></div>
  <a class="stat" href="<?= e(url('admin/canais.php')) ?>"><span class="stat-ico"><?= icon('live') ?></span><b><?= $channels ?></b><small>Canais de TV</small></a>
  <a class="stat" href="<?= e(url('admin/usuarios.php')) ?>"><span class="stat-ico"><?= icon('users') ?></span><b><?= $users ?></b><small>Usuários <em>+<?= $newUsers ?> na semana</em></small></a>
  <div class="stat"><span class="stat-ico"><?= icon('eye') ?></span><b><?= number_format((int)$stats['views'], 0, ',', '.') ?></b><small>Visualizações</small></div>
  <a class="stat<?= $reports ? ' stat-alert' : '' ?>" href="<?= e(url('admin/reportes.php')) ?>"><span class="stat-ico"><?= icon('flag') ?></span><b><?= $reports ?></b><small>Reportes abertos</small></a>
</div>

<div class="cols">
  <section class="panel">
    <div class="panel-head"><h2>Status do site</h2></div>
    <div class="status-list">
      <div class="status-item">
        <div><b>Modo manutenção</b><small><?= setting('maintenance') === '1' ? 'Visitantes veem a página de manutenção.' : 'O site está no ar para todos.' ?></small></div>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="maintenance">
          <button class="switch<?= setting('maintenance') === '1' ? ' on' : '' ?>" aria-label="Alternar manutenção"><span></span></button>
        </form>
      </div>
      <div class="status-item"><div><b>API de players</b><small><?= $apiOn ? 'Todos os títulos ganham player automaticamente.' : 'Configure o link da sua API de players.' ?></small></div><a class="pill <?= $apiOn ? 'pill-ok' : 'pill-warn' ?>" href="<?= e(url('admin/configuracoes.php#players')) ?>"><?= $apiOn ? 'Ativa' : 'Configurar' ?></a></div>
      <div class="status-item"><div><b>API do TMDB</b><small><?= tmdb_configured() ? 'Configurada' : 'Não configurada' ?></small></div><span class="pill <?= tmdb_configured() ? 'pill-ok' : 'pill-warn' ?>"><?= tmdb_configured() ? 'OK' : 'Pendente' ?></span></div>
      <div class="status-item"><div><b>Filmes sem player</b><small>Títulos que ainda não têm onde assistir</small></div><a class="pill <?= $movieNoPlayer ? 'pill-warn' : 'pill-ok' ?>" href="<?= e(url('admin/conteudos.php?tipo=movie&sem_player=1')) ?>"><?= $movieNoPlayer ?></a></div>
      <div class="status-item"><div><b>Episódios sem player</b><small>Episódios sem nenhum player disponível</small></div><span class="pill <?= $epNoPlayer ? 'pill-warn' : 'pill-ok' ?>"><?= $epNoPlayer ?></span></div>
      <div class="status-item"><div><b>Rascunhos</b><small>Conteúdos ocultos do site</small></div><a class="pill" href="<?= e(url('admin/conteudos.php?status=draft')) ?>"><?= (int)$stats['drafts'] ?></a></div>
    </div>
    <div class="quick">
      <a class="btn btn-red" href="<?= e(url('admin/importar.php')) ?>"><?= icon('download') ?> Importar do TMDB</a>
      <a class="btn" href="<?= e(url('admin/canais.php?novo=1')) ?>"><?= icon('live') ?> Novo canal</a>
    </div>
  </section>

  <section class="panel">
    <div class="panel-head"><h2>Adicionados recentemente</h2><a href="<?= e(url('admin/conteudos.php')) ?>">Ver todos</a></div>
    <ul class="mini-list">
      <?php foreach ($recent as $r): ?>
        <li><img src="<?= e(img($r['poster'], 'w92')) ?>" alt=""><div><a href="<?= e(url('admin/conteudo.php?id=' . $r['id'])) ?>"><?= e($r['title']) ?></a><small><?= e(type_label($r['type'])) ?><?= $r['year'] ? ' • ' . (int)$r['year'] : '' ?> • <?= e(time_ago($r['created_at'])) ?></small></div><?php if ($r['status'] === 'draft'): ?><span class="pill">Rascunho</span><?php endif; ?></li>
      <?php endforeach; ?>
      <?php if (!$recent): ?><li class="muted">Nenhum conteúdo ainda.</li><?php endif; ?>
    </ul>
  </section>
</div>

<div class="cols">
  <section class="panel">
    <div class="panel-head"><h2>Mais assistidos</h2></div>
    <ul class="mini-list">
      <?php foreach ($top as $r): ?>
        <li><img src="<?= e(img($r['poster'], 'w92')) ?>" alt=""><div><a href="<?= e(url('admin/conteudo.php?id=' . $r['id'])) ?>"><?= e($r['title']) ?></a><small><?= e(type_label($r['type'])) ?></small></div><span class="pill"><?= icon('eye') ?> <?= number_format((int)$r['views'], 0, ',', '.') ?></span></li>
      <?php endforeach; ?>
    </ul>
  </section>
  <section class="panel">
    <div class="panel-head"><h2>Novos usuários</h2><a href="<?= e(url('admin/usuarios.php')) ?>">Ver todos</a></div>
    <ul class="mini-list">
      <?php foreach ($lastUsers as $u): ?>
        <li><span class="avatar" style="background:<?= e($u['avatar_color']) ?>"><?= e(mb_strtoupper(mb_substr($u['name'], 0, 1))) ?></span><div><a href="<?= e(url('admin/usuarios.php?id=' . $u['id'])) ?>"><?= e($u['name']) ?></a><small><?= e($u['email']) ?> • <?= e(time_ago($u['created_at'])) ?></small></div><?php if ($u['role'] === 'admin'): ?><span class="pill pill-red">Admin</span><?php endif; ?></li>
      <?php endforeach; ?>
    </ul>
  </section>
</div>
<?php admin_footer(); ?>
