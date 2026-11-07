<?php
require __DIR__ . '/_init.php';

if (setting('auto_cron_key') === '') {
    set_setting('auto_cron_key', bin2hex(random_bytes(16)));
}

if (is_post()) {
    csrf_check();
    $action = in_str('action');
    if ($action === 'save') {
        $src = array_values(array_intersect(array_keys(auto_sources()), (array)($_POST['sources'] ?? [])));
        set_setting('auto_enabled', isset($_POST['enabled']) ? '1' : '0');
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
  <form method="post" id="auto-form">
    <?= csrf_field() ?><input type="hidden" name="action" value="save">
    <label class="toggle-row"><div><b>Importação automática</b><small>Publica sozinho os títulos novos das listas escolhidas, sem repetir os que você já tem.</small></div><input type="checkbox" name="enabled" value="1" <?= $enabled ? 'checked' : '' ?>><span class="switch-ui"></span></label>

    <p class="muted small" style="margin:0 0 8px">Listas do TMDB que o site vai seguir:</p>
    <div class="source-grid">
      <?php foreach (auto_sources() as $k => [$label, $t]): ?>
        <label class="source"><input type="checkbox" name="sources[]" value="<?= $k ?>" <?= in_array($k, $chosen, true) ? 'checked' : '' ?>>
          <span><b><?= e($label) ?></b><small><?= e(type_label($t === 'movie' && $k === 'anime_movies' ? 'movie' : $t)) ?></small></span></label>
      <?php endforeach; ?>
    </div>

    <?php [$yFrom, $yTo] = auto_year_range(); $yp = auto_year_progress(); ?>
    <div class="year-box">
      <b><?= icon('film') ?> Listas "por ano"</b>
      <p class="muted small">Pega os títulos mais populares de cada ano, do ano mais novo para o mais antigo. Bom para montar o catálogo com filmes e séries que já têm mais players disponíveis.</p>
      <div class="form-row">
        <span>De</span><input type="number" name="year_from" min="1900" max="<?= date('Y') ?>" value="<?= $yFrom ?>" class="w-year">
        <span>até</span><input type="number" name="year_to" min="1900" max="<?= date('Y') + 1 ?>" value="<?= $yTo ?>" class="w-year">
        <span>•</span><input type="number" name="year_pages" min="1" max="50" value="<?= (int)setting('auto_year_pages', '10') ?>" class="w-num"><span class="muted small">páginas por ano (20 títulos cada)</span>
      </div>
      <?php if (array_intersect(['year_movie', 'year_tv', 'year_anime'], $chosen)): ?>
        <p class="small year-now">Agora em:
          <?php foreach (['year_movie' => 'Filmes', 'year_tv' => 'Séries', 'year_anime' => 'Animes'] as $k => $l) if (in_array($k, $chosen, true)) echo '<span class="pill">' . $l . ' ' . (int)$yp[$k] . '</span> '; ?>
        </p>
      <?php endif; ?>
    </div>

    <div class="form-grid" style="margin-top:16px">
      <label class="field"><span>Títulos novos por vez</span><input type="number" name="per_run" min="1" max="50" value="<?= $perRun ?>"></label>
      <label class="field"><span>Rodar a cada (minutos)</span><input type="number" name="interval" min="5" max="1440" value="<?= $interval ?>"></label>
      <label class="field"><span>Publicar como</span><select name="status"><option value="published">Publicado</option><option value="draft" <?= setting('auto_status') === 'draft' ? 'selected' : '' ?>>Rascunho (eu reviso antes)</option></select></label>
      <label class="field"><span>Marcar como lançamento se saiu há menos de (dias)</span><input type="number" name="release_days" min="0" max="365" value="<?= (int)setting('auto_release_days', '60') ?>"></label>
    </div>
    <label class="toggle-row" style="margin-top:10px"><div><b>Atualizar séries e animes</b><small>Confere as séries que já estão no site e adiciona os episódios novos que saírem.</small></div><input type="checkbox" name="update_series" value="1" <?= setting('auto_update_series', '1') === '1' ? 'checked' : '' ?>><span class="switch-ui"></span></label>

    <p class="muted small" style="margin:6px 0 8px">Como o site vai acordar sozinho:</p>
    <label class="source wide"><input type="radio" name="mode" value="cron" <?= $mode === 'cron' ? 'checked' : '' ?>><span><b>Cron da Hostinger (recomendado)</b><small>Roda no horário certo, mesmo sem ninguém visitando o site. Precisa configurar uma vez (veja ao lado).</small></span></label>
    <label class="source wide"><input type="radio" name="mode" value="visits" <?= $mode === 'visits' ? 'checked' : '' ?>><span><b>Pelas visitas</b><small>Não precisa configurar nada: roda um pouquinho quando alguém abre o site. Se ninguém visitar, não roda.</small></span></label>

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
