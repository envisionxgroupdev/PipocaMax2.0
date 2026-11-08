<?php
/**
 * Inicialização do painel administrativo.
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once PM_ROOT . '/includes/icons.php';
require_admin();
if (is_post()) cache_bump(); // qualquer alteração no painel renova o cache do site
header('Cache-Control: no-store, no-cache, must-revalidate');

function admin_header(string $title, string $active = ''): void
{
    $u = current_user();
    $openReports = (int)q_val("SELECT COUNT(*) FROM reports WHERE status = 'open'");
    $menu = [
        ['dashboard', 'index.php', 'Dashboard', 'grid'],
        ['conteudos', 'conteudos.php', 'Conteúdos', 'film'],
        ['importar', 'importar.php', 'Importar do TMDB', 'download'],
        ['auto', 'automatico.php', 'Importação automática', 'wrench'],
        ['canais', 'canais.php', 'TV Online', 'live'],
        ['anuncios', 'anuncios.php', 'Anúncios e códigos', 'grid'],
        ['notificacoes', 'notificacoes.php', 'Notificações', 'bell'],
        ['mixdrop', 'mixdrop.php', 'MixDrop', 'external', mixdrop_pending()],
        ['usuarios', 'usuarios.php', 'Usuários', 'users'],
        ['reportes', 'reportes.php', 'Reportes', 'flag', $openReports],
        ['config', 'configuracoes.php', 'Configurações', 'settings'],
    ];
    ?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= e($title) ?> • Painel <?= e(site_name()) ?></title>
<link rel="icon" href="<?= e(asset('img/favicon.svg')) ?>" type="image/svg+xml">
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet" media="print" onload="this.media='all'">
<link rel="stylesheet" href="<?= e(asset('css/admin.css')) ?>">
</head>
<body>
<aside class="side" id="side">
  <div class="side-head"><?= logo_html() ?><span class="side-badge">ADMIN</span></div>
  <nav class="side-nav">
    <?php foreach ($menu as $m): ?>
      <a href="<?= e(url('admin/' . $m[1])) ?>" class="<?= $active === $m[0] ? 'active' : '' ?>"><?= icon($m[3]) ?><span><?= e($m[2]) ?></span><?php if (!empty($m[4])): ?><b class="count"><?= (int)$m[4] ?></b><?php endif; ?></a>
    <?php endforeach; ?>
  </nav>
  <div class="side-foot">
    <a href="<?= e(url('index.php')) ?>" target="_blank"><?= icon('external') ?><span>Ver o site</span></a>
    <a href="<?= e(url('logout.php?t=' . csrf_token())) ?>"><?= icon('logout') ?><span>Sair</span></a>
  </div>
</aside>
<div class="side-overlay" id="side-overlay"></div>
<div class="wrap">
  <header class="top">
    <button class="burger" id="burger" aria-label="Menu"><?= icon('menu') ?></button>
    <h1><?= e($title) ?></h1>
    <div class="top-right">
      <?php if (setting('maintenance') === '1'): ?><a class="pill pill-warn" href="<?= e(url('admin/configuracoes.php#manutencao')) ?>">Manutenção ativa</a><?php endif; ?>
      <span class="who"><span class="avatar" style="background:<?= e($u['avatar_color']) ?>"><?= e(mb_strtoupper(mb_substr($u['name'], 0, 1))) ?></span><span class="who-name"><?= e($u['name']) ?></span></span>
    </div>
  </header>
  <main class="content">
    <?= flashes() ?>
<?php
}

function admin_footer(): void
{
    $sq = season_queue_count();
    ?>
  </main>
</div>
<?php if ($sq > 0): ?>
<div class="sq-bar" id="sq-bar" data-left="<?= $sq ?>"><span class="sq-spin"></span><span id="sq-text">Baixando episódios de séries longas: <?= $sq ?> temporada(s) na fila…</span></div>
<?php endif; ?>
<script>window.PM = { base: <?= json_encode(BASE_URL) ?>, csrf: <?= json_encode(csrf_token()) ?> };</script>
<script src="<?= e(asset('js/admin.js')) ?>"></script>
</body>
</html>
<?php
}

/** Caixa "Enviar para o MixDrop" + envios deste filme/episódio */
function mixdrop_box(string $owner, int $ownerId, string $anchor): void
{
    $jobs = q_all('SELECT * FROM mixdrop_jobs WHERE ' . ($owner === 'episode' ? 'episode_id' : 'content_id') . ' = ?' . ($owner === 'episode' ? '' : ' AND episode_id IS NULL') . ' ORDER BY id DESC LIMIT 5', [$ownerId]);
    $pending = count(array_filter($jobs, fn($j) => in_array($j['status'], ['tosend', 'queued', 'downloading', 'converting'], true)));
    $labels = ['tosend' => 'aguardando envio', 'queued' => 'na fila do MixDrop', 'downloading' => 'baixando', 'converting' => 'convertendo', 'done' => 'pronto ✓ (player criado)', 'error' => 'erro'];
    ?>
    <div class="md-box" data-md-pending="<?= $pending ?>">
      <b><?= icon('external') ?> Enviar vídeo para o MixDrop</b>
      <?php if (!mixdrop_configured()): ?>
        <p class="muted small">Configure sua conta em <a href="<?= e(url('admin/mixdrop.php')) ?>">MixDrop</a> para enviar vídeos por link.</p>
      <?php else: ?>
        <p class="muted small">Cole o link direto do vídeo (.mp4). O MixDrop baixa de lá e o player aparece aqui sozinho quando ficar pronto.</p>
        <form method="post" class="form-row">
          <?= csrf_field() ?><input type="hidden" name="action" value="md_send"><input type="hidden" name="anchor" value="<?= e($anchor) ?>">
          <input name="md_url" class="grow" placeholder="https://…/video.mp4" required>
          <input name="md_audio" value="<?= e(setting('mixdrop_audio')) ?>" placeholder="Áudio" list="audios" style="width:130px">
          <button class="btn btn-sm btn-red"><?= icon('download') ?> Enviar</button>
        </form>
      <?php endif; ?>
      <?php if ($jobs): ?>
        <ul class="md-jobs">
          <?php foreach ($jobs as $j): ?>
            <li><span class="pill <?= $j['status'] === 'done' ? 'pill-ok' : ($j['status'] === 'error' ? 'pill-red' : 'pill-warn') ?>"><?= e($labels[$j['status']] ?? $j['status']) ?><?= $j['status'] === 'downloading' ? ' ' . (int)$j['progress'] . '%' : '' ?></span><small class="muted ellipsis"><?= e($j['source_url']) ?></small><?php if ($j['error'] && $j['status'] === 'error'): ?><small class="tv-err"><?= e($j['error']) ?></small><?php endif; ?></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
    <?php
}

/** Formulário de confirmação de exclusão (botão) */
function delete_button(string $action, array $fields, string $label = 'Excluir', string $confirm = 'Tem certeza que deseja excluir?'): string
{
    $html = '<form method="post" action="' . e($action) . '" class="inline" data-confirm="' . e($confirm) . '">' . csrf_field();
    foreach ($fields as $k => $v) {
        $html .= '<input type="hidden" name="' . e($k) . '" value="' . e($v) . '">';
    }
    return $html . '<button class="btn-ghost danger" title="' . e($label) . '">' . icon('trash') . '</button></form>';
}

/** Editor de players (filmes e episódios) */
function players_editor(array $players, string $owner, int $ownerId, string $back): void
{
    ?>
    <div class="players">
      <?php if (!$players): ?><p class="muted">Nenhum player cadastrado ainda.</p><?php endif; ?>
      <?php foreach ($players as $i => $p): ?>
        <form class="player-row" method="post" action="<?= e(url('admin/players.php')) ?>">
          <?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
          <input type="hidden" name="back" value="<?= e($back) ?>">
          <span class="drag"><?= $i + 1 ?></span>
          <input name="name" value="<?= e($p['name']) ?>" placeholder="Nome" class="w-name" required>
          <input name="url" value="<?= e($p['url']) ?>" placeholder="URL do player / embed / .mp4 / .m3u8" class="w-url" required>
          <select name="kind" class="w-kind">
            <?php foreach (['embed' => 'Embed (iframe)', 'video' => 'Vídeo (MP4)', 'hls' => 'HLS (.m3u8)'] as $k => $l): ?>
              <option value="<?= $k ?>" <?= $p['kind'] === $k ? 'selected' : '' ?>><?= $l ?></option>
            <?php endforeach; ?>
          </select>
          <input name="audio" value="<?= e($p['audio']) ?>" placeholder="Áudio" class="w-small" list="audios">
          <input name="quality" value="<?= e($p['quality']) ?>" placeholder="Qualidade" class="w-small" list="qualities">
          <div class="row-actions">
            <button name="action" value="up" class="btn-ghost" title="Subir" <?= $i === 0 ? 'disabled' : '' ?>>↑</button>
            <button name="action" value="down" class="btn-ghost" title="Descer" <?= $i === count($players) - 1 ? 'disabled' : '' ?>>↓</button>
            <a class="btn-ghost" href="<?= e($p['url']) ?>" target="_blank" rel="noopener" title="Testar"><?= icon('external') ?></a>
            <button name="action" value="save" class="btn btn-sm">Salvar</button>
            <button name="action" value="delete" class="btn-ghost danger" title="Excluir" data-confirm-click="Excluir este player?"><?= icon('trash') ?></button>
          </div>
        </form>
      <?php endforeach; ?>

      <form class="player-row new" method="post" action="<?= e(url('admin/players.php')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="<?= e($owner) ?>" value="<?= $ownerId ?>">
        <input type="hidden" name="back" value="<?= e($back) ?>">
        <span class="drag">+</span>
        <input name="name" placeholder="Nome (ex: Player 1)" class="w-name" value="Player <?= count($players) + 1 ?>" required>
        <input name="url" placeholder="Cole aqui a URL do player" class="w-url" required>
        <select name="kind" class="w-kind">
          <option value="auto">Detectar tipo</option>
          <option value="embed">Embed (iframe)</option>
          <option value="video">Vídeo (MP4)</option>
          <option value="hls">HLS (.m3u8)</option>
        </select>
        <input name="audio" placeholder="Áudio" class="w-small" list="audios">
        <input name="quality" placeholder="Qualidade" class="w-small" list="qualities">
        <div class="row-actions"><button name="action" value="add" class="btn btn-sm btn-red"><?= icon('plus') ?> Adicionar</button></div>
      </form>
      <datalist id="audios"><option>Dublado</option><option>Legendado</option><option>Dual Áudio</option><option>Nacional</option></datalist>
      <datalist id="qualities"><option>4K</option><option>FHD</option><option>HD</option><option>SD</option><option>CAM</option></datalist>
    </div>
    <?php
}
