<?php
require __DIR__ . '/_init.php';

if (setting('auto_cron_key') === '') {
    set_setting('auto_cron_key', bin2hex(random_bytes(16)));
}

if (is_post()) {
    csrf_check();
    $action = in_str('action');
    if ($action === 'save') {
        $types = array_values(array_intersect(['movie', 'series', 'anime'], (array)($_POST['types'] ?? [])));
        $withReleases = isset($_POST['releases']);
        $src = [];
        if (in_array('movie', $types, true)) $src[] = 'year_movie';
        if (in_array('series', $types, true)) $src[] = 'year_tv';
        if (in_array('anime', $types, true)) $src[] = 'year_anime';
        if ($withReleases && in_array('movie', $types, true)) $src[] = 'now_playing';
        if ($withReleases && in_array('series', $types, true)) $src[] = 'on_air';
        set_setting('auto_enabled', isset($_POST['enabled']) ? '1' : '0');
        set_setting('auto_types', implode(',', $types));
        set_setting('auto_with_releases', $withReleases ? '1' : '0');
        set_setting('auto_sources', implode(',', $src));
        set_setting('auto_per_run', (string)max(1, min(50, in_int('per_run', 10))));
        set_setting('auto_interval', (string)max(5, min(1440, in_int('interval', 30))));
        set_setting('auto_status', in_str('status') === 'draft' ? 'draft' : 'published');
        set_setting('auto_release_days', (string)max(0, min(365, in_int('release_days', 60))));
        set_setting('auto_update_series', isset($_POST['update_series']) ? '1' : '0');
        $yf = max(1900, min((int)date('Y'), in_int('year_from', 1990)));
        $yt = max($yf, min((int)date('Y') + 1, in_int('year_to', (int)date('Y'))));
        if ((int)setting('auto_year_from') !== $yf || (int)setting('auto_year_to') !== $yt) {
            $c = json_decode((string)setting('auto_cursor', '{}'), true) ?: [];
            unset($c['year_movie'], $c['year_tv'], $c['year_anime']);
            set_setting('auto_cursor', json_encode($c));
        }
        set_setting('auto_year_from', (string)$yf);
        set_setting('auto_year_to', (string)$yt);
        set_setting('auto_year_pages', (string)max(1, min(50, in_int('year_pages', 10))));
        set_setting('auto_mode', in_str('mode') === 'visits' ? 'visits' : 'cron');
        flash('success', setting('auto_enabled') === '1' ? 'Importação automática ligada.' : 'Configurações salvas (importação automática desligada).');
    } elseif ($action === 'run') {
        $r = run_autoimport(null, 50, true);
        flash($r['errors'] && !$r['imported'] ? 'error' : 'success', "Rodou agora: {$r['imported']} título(s) novo(s), {$r['updated']} série(s) com episódios novos" . ($r['errors'] ? ", {$r['errors']} erro(s)" : '') . '.');
    } elseif ($action === 'newkey') {
        set_setting('auto_cron_key', bin2hex(random_bytes(16)));
        flash('success', 'Chave nova gerada. Atualize o link no Cron da Hostinger.');
    } elseif ($action === 'clearlog') {
        set_setting('auto_log', '[]');
    } elseif ($action === 'restart') {
        set_setting('auto_cursor', '{}');
        flash('success', 'As listas vão recomeçar da primeira página.');
    }
    redirect('admin/automatico.php');
}

$enabled = setting('auto_enabled') === '1';
$chosen = array_filter(explode(',', (string)setting('auto_sources', 'now_playing,on_air,anime')));
$perRun = (int)setting('auto_per_run', '10');
$interval = (int)setting('auto_interval', '30');
$runsPerDay = (int)floor(1440 / max(5, $interval));
$last = (int)setting('auto_last_run', '0');
$log = json_decode((string)setting('auto_log', '[]'), true) ?: [];
$cronUrl = site_url(url('cron.php?key=' . setting('auto_cron_key')));
$cronCmd = '/usr/bin/php ' . PM_ROOT . '/cron.php';
$mode = setting('auto_mode', 'cron');
$cronMin = $interval < 60 ? "*/$interval * * * *" : '0 */' . max(1, intdiv($interval, 60)) . ' * * *';

admin_header('Importação automática', 'auto');
?>
<?php if (!tmdb_configured()): ?>
  <div class="alert alert-error">Configure primeiro a chave da API do TMDB em <a href="<?= e(url('admin/configuracoes.php#tmdb')) ?>">Configurações</a>.</div>
<?php endif; ?>

<div class="auto-status <?= $enabled ? 'on' : 'off' ?>">
  <div class="auto-dot"></div>
  <div>
    <b><?= $enabled ? 'Ligada — o site está publicando sozinho' : 'Desligada' ?></b>
    <small><?= $enabled ? "Até $perRun título(s) a cada $interval min • cerca de " . number_format($perRun * $runsPerDay, 0, ',', '.') . ' por dia, até acabar o que é novo' : 'Ligue abaixo para o site começar a publicar sozinho.' ?>
      <?= $last ? ' • Última vez: ' . e(time_ago(date('Y-m-d H:i:s', $last))) : '' ?></small>
  </div>
  <button type="button" class="btn btn-red" id="auto-run" data-per="<?= $perRun ?>" <?= tmdb_configured() ? '' : 'disabled' ?>><?= icon('download') ?> Rodar agora</button>
</div>

<div class="cols">
<section class="panel">
  <div class="panel-head"><h2>O que publicar</h2></div>
  <?php
    $types = array_filter(explode(',', (string)setting('auto_types', '')));
    if (!$types) { // compatibilidade com a versão anterior
        if (array_intersect(['year_movie', 'now_playing', 'popular_movies', 'trending_movies', 'top_rated_movies', 'upcoming'], $chosen)) $types[] = 'movie';
        if (array_intersect(['year_tv', 'on_air', 'popular_tv', 'trending_tv', 'top_rated_tv'], $chosen)) $types[] = 'series';
        if (array_intersect(['year_anime', 'anime', 'anime_movies'], $chosen)) $types[] = 'anime';
    }
    [$yFrom, $yTo] = auto_year_range();
    $yp = auto_year_progress();
    $pages = (int)setting('auto_year_pages', '10');
  ?>
  <form method="post" id="auto-form">
    <?= csrf_field() ?><input type="hidden" name="action" value="save">
    <label class="toggle-row"><div><b>Importação automática</b><small>O site publica sozinho, sem repetir o que você já tem.</small></div><input type="checkbox" name="enabled" value="1" <?= $enabled ? 'checked' : '' ?>><span class="switch-ui"></span></label>

    <div class="step-label"><span>1</span> O que importar</div>
    <div class="type-cards">
      <?php foreach (['movie' => ['Filmes', 'film'], 'series' => ['Séries', 'series'], 'anime' => ['Animes', 'anime']] as $k => [$l, $ic]): ?>
        <label class="type-card"><input type="checkbox" name="types[]" value="<?= $k ?>" <?= in_array($k, $types, true) ? 'checked' : '' ?>>
          <?= icon($ic, 'ico tc-ico') ?><b><?= $l ?></b>
          <small><?= $enabled && in_array($k, $types, true) ? 'agora em ' . (int)$yp[['movie' => 'year_movie', 'series' => 'year_tv', 'anime' => 'year_anime'][$k]] : '&nbsp;' ?></small>
        </label>
      <?php endforeach; ?>
    </div>

    <div class="step-label"><span>2</span> De quais anos</div>
    <div class="year-pick">
      <div class="year-inputs">
        <label>De <input type="number" name="year_from" id="yf" min="1900" max="<?= date('Y') ?>" value="<?= $yFrom ?>" class="w-year"></label>
        <label>até <input type="number" name="year_to" id="yt" min="1900" max="<?= date('Y') + 1 ?>" value="<?= $yTo ?>" class="w-year"></label>
      </div>
      <div class="year-chips">
        <button type="button" class="chip-btn" data-y="<?= date('Y') - 4 ?>-<?= date('Y') ?>">Últimos 5 anos</button>
        <button type="button" class="chip-btn" data-y="2010-2019">Anos 2010</button>
        <button type="button" class="chip-btn" data-y="2000-2009">Anos 2000</button>
        <button type="button" class="chip-btn" data-y="1990-1999">Anos 90</button>
        <button type="button" class="chip-btn" data-y="1980-<?= date('Y') ?>">Tudo (1980 até hoje)</button>
      </div>
      <p class="muted small">Começa pelo ano mais novo e vai descendo, sempre pelos títulos mais populares de cada ano.</p>
    </div>

    <div class="step-label"><span>3</span> Quantos títulos de cada ano</div>
    <div class="seg">
      <?php foreach ([5 => '100', 10 => '200', 25 => '500', 50 => '1.000'] as $pg => $l): ?>
        <label><input type="radio" name="year_pages" value="<?= $pg ?>" <?= $pages === $pg ? 'checked' : '' ?>><span><?= $l ?></span></label>
      <?php endforeach; ?>
    </div>

    <label class="toggle-row" style="margin-top:18px"><div><b>Também pegar os lançamentos do momento</b><small>Filmes nos cinemas e séries no ar. Costumam ter menos players disponíveis.</small></div><input type="checkbox" name="releases" value="1" <?= setting('auto_with_releases') === '1' ? 'checked' : '' ?>><span class="switch-ui"></span></label>
    <label class="toggle-row"><div><b>Atualizar séries e animes</b><small>Adiciona os episódios novos das séries que já estão no site.</small></div><input type="checkbox" name="update_series" value="1" <?= setting('auto_update_series', '1') === '1' ? 'checked' : '' ?>><span class="switch-ui"></span></label>

    <details class="box-details"><summary><?= icon('settings') ?> Mais opções</summary>
      <div style="padding:14px 16px">
        <div class="form-grid">
          <label class="field"><span>Títulos novos por vez</span><input type="number" name="per_run" min="1" max="50" value="<?= $perRun ?>"></label>
          <label class="field"><span>Rodar a cada (minutos)</span><input type="number" name="interval" min="5" max="1440" value="<?= $interval ?>"></label>
          <label class="field"><span>Publicar como</span><select name="status"><option value="published">Publicado</option><option value="draft" <?= setting('auto_status') === 'draft' ? 'selected' : '' ?>>Rascunho (eu reviso antes)</option></select></label>
          <label class="field"><span>Marcar como lançamento se saiu há menos de (dias)</span><input type="number" name="release_days" min="0" max="365" value="<?= (int)setting('auto_release_days', '60') ?>"></label>
        </div>
        <p class="muted small" style="margin:14px 0 8px">Como o site vai acordar sozinho:</p>
        <label class="source wide"><input type="radio" name="mode" value="cron" <?= $mode === 'cron' ? 'checked' : '' ?>><span><b>Cron da Hostinger (recomendado)</b><small>Roda no horário certo, mesmo sem visitas.</small></span></label>
        <label class="source wide"><input type="radio" name="mode" value="visits" <?= $mode === 'visits' ? 'checked' : '' ?>><span><b>Pelas visitas</b><small>Sem configurar nada, mas só roda quando alguém abre o site.</small></span></label>
      </div>
    </details>

    <button class="btn btn-red btn-lg" style="margin-top:14px">Salvar</button>
  </form>
</section>

<div>
<section class="panel">
  <div class="panel-head"><h2>Configurar o Cron na Hostinger</h2><span class="pill <?= $mode === 'cron' ? 'pill-red' : '' ?>">uma vez só</span></div>
  <ol class="howto">
    <li>No hPanel, entre no site e vá em <b>Avançado › Cron Jobs</b>.</li>
    <li>Escolha <b>Personalizado</b> e cole este comando:
      <div class="copy"><code id="cmd"><?= e($cronCmd) ?></code><button type="button" class="btn btn-sm" data-copy="cmd">Copiar</button></div>
    </li>
    <li>Em "frequência", use <b>a cada <?= $interval ?> minutos</b>. Se pedir no formato cron, cole:
      <div class="copy"><code id="freq"><?= e($cronMin) ?></code><button type="button" class="btn btn-sm" data-copy="freq">Copiar</button></div>
    </li>
    <li>Salve. Pronto! A data da “última vez” lá em cima vai começar a mudar sozinha.</li>
  </ol>
  <details class="box-details"><summary>Se o comando não funcionar, use o link</summary>
    <div style="padding:12px 16px">
      <p class="muted small">No Cron, escolha a opção de URL (ou use o comando <code>wget -q -O /dev/null "LINK"</code>) com este link secreto:</p>
      <div class="copy"><code id="url"><?= e($cronUrl) ?></code><button type="button" class="btn btn-sm" data-copy="url">Copiar</button></div>
      <form method="post" data-confirm="Gerar uma chave nova? O link antigo para de funcionar."><?= csrf_field() ?><input type="hidden" name="action" value="newkey"><button class="link-danger">Gerar chave nova</button></form>
    </div>
  </details>
</section>

<section class="panel">
  <div class="panel-head"><h2>Últimas execuções</h2>
    <?php if ($log): ?><form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="clearlog"><button class="link-danger">Limpar</button></form><?php endif; ?></div>
  <ul class="auto-log">
    <?php foreach (array_slice($log, 0, 25) as $l): ?>
      <li class="<?= e($l['type']) ?>"><small><?= e(date('d/m H:i', strtotime($l['t']))) ?></small><span><?= e($l['msg']) ?></span></li>
    <?php endforeach; ?>
    <?php if (!$log): ?><li class="muted">Ainda não rodou nenhuma vez.</li><?php endif; ?>
  </ul>
  <form method="post" class="inline" data-confirm="Recomeçar todas as listas da primeira página?"><?= csrf_field() ?><input type="hidden" name="action" value="restart"><button class="link-danger" style="color:var(--muted)">Recomeçar listas do início</button></form>
</section>
</div>
</div>
<div class="modal-q" id="queue" hidden>
  <div class="queue-box">
    <div class="queue-head"><h2 id="q-title">Rodando a importação…</h2><span class="muted" id="q-status"></span></div>
    <div class="progress"><span id="q-bar"></span></div>
    <div class="queue-stats">
      <span><b id="q-ok">0</b> importados</span>
      <span><b id="q-skip">0</b> séries atualizadas</span>
      <span class="err"><b id="q-err">0</b> com erro</span>
    </div>
    <ul class="queue-log" id="q-log"></ul>
    <div class="btn-row">
      <button class="btn" id="q-stop">Parar</button>
      <button class="btn btn-red" id="q-close" hidden>Fechar</button>
    </div>
    <p class="muted small">Importa um título por vez para não sobrecarregar a hospedagem. Pode deixar aberto.</p>
  </div>
</div>
<?php admin_footer(); ?>
