<?php
require __DIR__ . '/_init.php';

$aba = in_str('aba', 'buscar');
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
  <a class="<?= $aba === 'buscar' ? 'active' : '' ?>" href="<?= e(url('admin/importar.php')) ?>"><?= icon('search') ?> Buscar e escolher</a>
  <a class="<?= $aba === 'massa' ? 'active' : '' ?>" href="<?= e(url('admin/importar.php?aba=massa')) ?>"><?= icon('download') ?> Em massa (lista de nomes)</a>
  <a class="<?= $aba === 'listas' ? 'active' : '' ?>" href="<?= e(url('admin/importar.php?aba=listas')) ?>"><?= icon('film') ?> Listas inteiras do TMDB</a>
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

<?php elseif ($aba === 'listas'): ?>
<section class="panel">
  <div class="panel-head"><h2>Importar listas inteiras</h2></div>
  <p class="muted">Importa os títulos de uma lista do TMDB, página por página. Cada página tem 20 títulos (3 páginas = 60 títulos). Os que você já tem são pulados. Use <b>"por ano"</b> para montar o catálogo com títulos mais antigos.</p>
  <div class="form-row">
    <select id="list-name">
      <optgroup label="Por ano (mais populares do ano)">
        <option value="year_movie" data-type="movie" data-year>Filmes de um ano</option>
        <option value="year_tv" data-type="series" data-year>Séries de um ano</option>
        <option value="year_anime" data-type="anime" data-year>Animes de um ano</option>
      </optgroup>
      <optgroup label="Listas do momento">
      <?php foreach ($lists as $k => [$l, $t]): ?><option value="<?= $k ?>" data-type="<?= $t ?>"><?= e($l) ?></option><?php endforeach; ?>
      </optgroup>
    </select>
    <span class="list-year"><span class="muted">ano</span> <input type="number" id="list-year" value="<?= date('Y') - 1 ?>" min="1900" max="<?= date('Y') + 1 ?>" class="w-year"></span>
    <span class="muted">da página</span>
    <input type="number" id="list-from" value="1" min="1" max="500" class="w-num">
    <span class="muted">até</span>
    <input type="number" id="list-to" value="3" min="1" max="500" class="w-num">
    <label class="check"><input type="checkbox" id="list-release"> Marcar como lançamento</label>
    <button class="btn btn-red" id="list-start"><?= icon('download') ?> Importar lista</button>
  </div>
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
