<?php
require __DIR__ . '/_init.php';

$aba = in_str('aba', 'falta');
if ($aba === 'listas') $aba = 'falta';
$q = in_str('q');
$kind = in_str('kind', 'movie') === 'tv' ? 'tv' : 'movie';
$list = in_str('lista', 'now_playing');
$page = max(1, in_int('p', 1));
$results = [];
$totalPages = 1;
$error = '';

$lists = [
    'now_playing' => ['Filmes nos cinemas', 'movie'],
    'upcoming' => ['Filmes em breve', 'movie'],
    'popular_movies' => ['Filmes populares', 'movie'],
    'on_air' => ['Séries no ar', 'series'],
    'popular_tv' => ['Séries populares', 'series'],
    'anime' => ['Animes populares', 'anime'],
];
if (!isset($lists[$list])) $list = 'now_playing';

if (tmdb_configured() && $aba === 'buscar') {
    try {
        $r = $q !== '' ? tmdb_search($kind, $q, $page) : tmdb_list($list, $page);
        $results = $r['results'];
        $totalPages = $r['total_pages'];
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$existing = [];
if ($results) {
    $ids = array_column($results, 'tmdb_id');
    $in = implode(',', array_fill(0, count($ids), '?'));
    foreach (q_all("SELECT id, tmdb_id, type FROM contents WHERE tmdb_id IN ($in)", $ids) as $r) {
        $existing[$r['tmdb_id'] . '-' . ($r['type'] === 'movie' ? 'movie' : 'tv')] = $r['id'];
    }
}
$qs = http_build_query(array_filter(['q' => $q, 'kind' => $q ? $kind : null, 'lista' => $q ? null : $list]));
$self = url('admin/importar.php') . ($qs ? '?' . $qs : '');

admin_header('Importar do TMDB', 'importar');
?>
<?php if (!tmdb_configured()): ?>
  <div class="panel center">
    <h2>Configure a API do TMDB</h2>
    <p class="muted">Para buscar e importar títulos automaticamente (pôster, sinopse, temporadas e episódios), cadastre sua chave da API do TMDB.</p>
    <a class="btn btn-red" href="<?= e(url('admin/configuracoes.php#tmdb')) ?>">Ir para Configurações</a>
  </div>
<?php else: ?>

<div class="tabs mb big-tabs">
  <a class="<?= $aba === 'falta' ? 'active' : '' ?>" href="<?= e(url('admin/importar.php')) ?>"><?= icon('download') ?> Importar o que falta</a>
  <a class="<?= $aba === 'buscar' ? 'active' : '' ?>" href="<?= e(url('admin/importar.php?aba=buscar')) ?>"><?= icon('search') ?> Buscar e escolher</a>
  <a class="<?= $aba === 'massa' ? 'active' : '' ?>" href="<?= e(url('admin/importar.php?aba=massa')) ?>"><?= icon('download') ?> Em massa (lista de nomes)</a>
</div>

<?php if ($aba === 'massa'): ?>
<section class="panel">
  <div class="panel-head"><h2>Importação em massa</h2></div>
  <p class="muted">Cole um título por linha: pode ser o <b>nome</b> (com o ano, se quiser acertar o filme certo), o <b>ID do TMDB</b> ou o <b>link do TMDB</b>. Dá para colar 50, 100 ou mais de uma vez.</p>
  <div class="form-grid">
    <label class="field span-2"><span>Títulos (um por linha)</span>
      <textarea id="bulk-items" rows="12" placeholder="Duna: Parte Dois 2024&#10;Oppenheimer&#10;693134&#10;https://www.themoviedb.org/movie/872585"></textarea>
    </label>
    <div>
      <label class="field"><span>Tipo</span>
        <select id="bulk-type"><option value="movie">Filmes</option><option value="series">Séries</option><option value="anime">Animes</option></select>
      </label>
      <div class="opt-list">
        <label class="check"><input type="checkbox" id="bulk-release"> Marcar como lançamento</label>
        <label class="check"><input type="checkbox" id="bulk-featured"> Colocar em destaque</label>
        <label class="check"><input type="checkbox" id="bulk-draft"> Importar como rascunho</label>
        <label class="check"><input type="checkbox" id="bulk-update"> Atualizar os que já existem</label>
      </div>
      <p class="muted small" id="bulk-count">0 títulos</p>
      <button class="btn btn-red btn-lg btn-block" id="bulk-start"><?= icon('download') ?> Importar todos</button>
    </div>
  </div>
</section>

<?php elseif ($aba === 'falta'):
  $counts = [];
  foreach (q_all('SELECT type, COUNT(*) n FROM contents GROUP BY type') as $c) $counts[$c['type']] = (int)$c['n'];
  $types = ['movie' => 'Filmes', 'series' => 'Séries', 'anime' => 'Animes'];
  $srcData = [];
  foreach ($types as $t => $_) {
      foreach (import_sources($t) as $k => [$label]) {
          $cur = json_decode((string)setting('imp_cursor_' . $t . '_' . $k, ''), true);
          $where = '';
          if (is_array($cur) && $k !== 'one_year') {
              $where = !empty($cur['done']) ? 'chegou ao fim da lista' : ($k === 'all_years' ? 'parou em ' . (int)$cur['year'] . ', página ' . (int)$cur['page'] : 'parou na página ' . (int)$cur['page']);
          }
          $srcData[$t][] = ['k' => $k, 'l' => $label, 'w' => $where];
      }
  }
?>
<section class="panel">
  <div class="panel-head"><h2>Importar o que falta</h2></div>
  <p class="muted">Escolha o tipo e quantos títulos <b>novos</b> quer. O sistema procura sozinho no TMDB, ano por ano e página por página, <b>pula o que já está no site</b> e continua de onde parou na próxima vez.</p>
  <div class="imp-stats">
    <?php foreach ($types as $t => $l): ?><div><b><?= number_format($counts[$t] ?? 0, 0, ',', '.') ?></b><span><?= $l ?> no site</span></div><?php endforeach; ?>
  </div>
  <div class="imp-grid">
    <label class="field"><span>O que importar</span>
      <select id="nf-type"><?php foreach ($types as $t => $l): ?><option value="<?= $t ?>"><?= $l ?></option><?php endforeach; ?></select></label>
    <label class="field"><span>De onde</span><select id="nf-src"></select><small class="muted" id="nf-where"></small></label>
    <label class="field" id="nf-year-f" hidden><span>Ano</span><input type="number" id="nf-year" value="<?= date('Y') ?>" min="1950" max="<?= date('Y') + 1 ?>"></label>
    <label class="field"><span>Quantos novos</span>
      <select id="nf-want"><?php foreach ([10, 20, 50, 100, 200] as $n): ?><option <?= $n === 50 ? 'selected' : '' ?>><?= $n ?></option><?php endforeach; ?></select></label>
  </div>
  <div class="form-row">
    <label class="check"><input type="checkbox" id="nf-release"> Marcar como lançamento</label>
    <label class="check"><input type="checkbox" id="nf-restart"> Começar do início (ignorar onde parou)</label>
    <span class="grow"></span>
    <button class="btn btn-red btn-lg" id="nf-start"><?= icon('download') ?> Procurar e importar</button>
  </div>
  <p class="muted small" id="nf-status"></p>
  <script type="application/json" id="nf-data"><?= json_encode($srcData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?></script>
</section>

<?php else: ?>
<form class="filterbar big" method="get">
  <select name="kind">
    <option value="movie" <?= $kind === 'movie' ? 'selected' : '' ?>>Filmes</option>
    <option value="tv" <?= $kind === 'tv' ? 'selected' : '' ?>>Séries / Animes</option>
  </select>
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="Busque no TMDB pelo nome… (ex: Duna, One Piece, The Last of Us)" autofocus>
  <button class="btn btn-red"><?= icon('search') ?> Buscar</button>
</form>

<div class="tabs mb">
  <?php foreach ($lists as $k => [$l]): ?>
    <a class="<?= !$q && $list === $k ? 'active' : '' ?>" href="<?= e(url('admin/importar.php?lista=' . $k)) ?>"><?= e($l) ?></a>
  <?php endforeach; ?>
</div>

<?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

<?php if ($results): ?>
  <div class="import-bar">
    <label class="check"><input type="checkbox" data-check-all="sel"> Selecionar todos desta página</label>
    <label class="check"><input type="checkbox" id="sel-release"> Marcar como lançamento</label>
    <label class="check"><input type="checkbox" id="sel-featured"> Colocar em destaque</label>
    <button class="btn btn-red" id="sel-start"><?= icon('download') ?> Importar selecionados</button>
  </div>

  <div class="tmdb-grid">
    <?php foreach ($results as $r):
        $isTv = $q !== '' ? $kind === 'tv' : ($r['kind'] ?? 'movie') === 'tv';
        $has = $existing[$r['tmdb_id'] . '-' . ($isTv ? 'tv' : 'movie')] ?? null;
        $defType = $isTv ? (!empty($r['is_anime']) ? 'anime' : 'series') : 'movie'; ?>
      <div class="tmdb-item<?= $has ? ' has' : '' ?>" data-tmdb="<?= (int)$r['tmdb_id'] ?>">
        <label class="tmdb-poster">
          <img loading="lazy" src="<?= e(img($r['poster'], 'w185')) ?>" alt="">
          <?php if (!$has): ?><input type="checkbox" name="sel[]" value="<?= (int)$r['tmdb_id'] ?>" class="tmdb-check"><?php endif; ?>
          <?php if ($has): ?><span class="tmdb-has">No site</span><?php endif; ?>
          <?php if ($r['rating']): ?><span class="tmdb-rating">★ <?= e($r['rating']) ?></span><?php endif; ?>
        </label>
        <div class="tmdb-info">
          <b title="<?= e($r['title']) ?>"><?= e($r['title']) ?></b>
          <small><?= e($r['year'] ?: '—') ?> • ID <?= (int)$r['tmdb_id'] ?></small>
          <?php if ($isTv): ?>
            <select class="sm" data-type>
              <option value="series" <?= $defType === 'series' ? 'selected' : '' ?>>Série</option>
              <option value="anime" <?= $defType === 'anime' ? 'selected' : '' ?>>Anime</option>
            </select>
          <?php else: ?>
            <input type="hidden" data-type value="movie">
          <?php endif; ?>
          <?php if ($has): ?>
            <a class="btn btn-sm" href="<?= e(url('admin/conteudo.php?id=' . $has)) ?>">Editar</a>
          <?php else: ?>
            <button class="btn btn-sm btn-red" data-import-one>Importar</button>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
  <?= paginate($totalPages * 20, 20, $page, $self) ?>
<?php elseif (!$error): ?>
  <div class="panel center muted">Nenhum resultado. Tente outro nome.</div>
<?php endif; ?>
<?php endif; ?>

<!-- Fila de importação -->
<div class="modal-q" id="queue" hidden>
  <div class="queue-box">
    <div class="queue-head">
      <h2 id="q-title">Importando…</h2>
      <span class="muted" id="q-status"></span>
    </div>
    <div class="progress"><span id="q-bar"></span></div>
    <div class="queue-stats">
      <span><b id="q-ok">0</b> importados</span>
      <span><b id="q-skip">0</b> já existiam</span>
      <span class="err"><b id="q-err">0</b> com erro</span>
    </div>
    <ul class="queue-log" id="q-log"></ul>
    <div class="btn-row">
      <button class="btn" id="q-stop">Parar</button>
      <a class="btn btn-red" id="q-done" href="<?= e(url('admin/conteudos.php')) ?>" hidden>Ver conteúdos</a>
      <button class="btn" id="q-close" hidden>Fechar</button>
    </div>
    <p class="muted small">Pode deixar esta janela aberta enquanto importa. Séries e animes demoram um pouco mais porque vêm com todos os episódios.</p>
  </div>
</div>
<?php endif; ?>
<?php admin_footer(); ?>
