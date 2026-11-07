<?php
/**
 * Página de catálogo reutilizável.
 * Defina $catType ('movie' | 'series' | 'anime' | 'release') antes de incluir.
 */
require_once __DIR__ . '/bootstrap.php';
check_maintenance();

$catType = $catType ?? 'movie';
$titles = [
    'movie' => ['Filmes', 'movie'],
    'series' => ['Séries', 'series'],
    'anime' => ['Animes', 'anime'],
    'release' => ['Lançamentos', 'home'],
];
[$heading, $active] = $titles[$catType];

$where = ["status = 'published'"];
$params = [];
if ($catType === 'release') {
    $where[] = 'is_release = 1';
} else {
    $where[] = 'type = ?';
    $params[] = $catType;
}

// Gêneros disponíveis
$genreRows = q_all('SELECT genres FROM contents WHERE ' . implode(' AND ', $where) . " AND genres IS NOT NULL AND genres <> ''", $params);
$genreList = [];
foreach ($genreRows as $g) {
    foreach (explode(',', $g['genres']) as $x) {
        $x = trim($x);
        if ($x !== '') $genreList[$x] = true;
    }
}
$genreList = array_keys($genreList);
sort($genreList, SORT_LOCALE_STRING);

$genre = in_str('genero');
$year = in_int('ano');
$sort = in_str('ordem', 'recentes');

if ($genre !== '') {
    $where[] = 'genres LIKE ?';
    $params[] = '%' . $genre . '%';
}
if ($year > 0) {
    $where[] = 'year = ?';
    $params[] = $year;
}
$orders = [
    'recentes' => 'created_at DESC',
    'lancamento' => 'COALESCE(release_date, created_at) DESC',
    'populares' => 'views DESC',
    'nota' => 'rating DESC',
    'az' => 'title ASC',
];
$order = $orders[$sort] ?? $orders['recentes'];

$perPage = max(12, (int)setting('items_per_page', 30));
$page = max(1, in_int('p', 1));
$whereSql = implode(' AND ', $where);
$total = (int)q_val("SELECT COUNT(*) FROM contents WHERE $whereSql", $params);
$items = q_all(
    "SELECT id, type, title, slug, poster, year, rating, is_release FROM contents WHERE $whereSql ORDER BY $order LIMIT $perPage OFFSET " . (($page - 1) * $perPage),
    $params
);
$years = q_all('SELECT DISTINCT year FROM contents WHERE year IS NOT NULL AND status = \'published\' ORDER BY year DESC');

$qs = http_build_query(array_filter(['genero' => $genre, 'ano' => $year ?: null, 'ordem' => $sort !== 'recentes' ? $sort : null]));
$pageUrl = strtok($_SERVER['REQUEST_URI'], '?') . ($qs ? '?' . $qs : '');

$pageTitle = $heading . ($page > 1 ? ' — página ' . $page : '');
$pageNames = ['movie' => 'filmes', 'series' => 'series', 'anime' => 'animes', 'release' => 'lancamentos'];
$canonical = site_url(page_url($pageNames[$catType], $page > 1 ? 'p=' . $page : ''));
$metaDescription = [
    'movie' => 'Catálogo de filmes do ' . site_name() . ': lançamentos, ação, comédia, drama, terror e muito mais.',
    'series' => 'Séries no ' . site_name() . ': todas as temporadas e episódios em um só lugar.',
    'anime' => 'Animes no ' . site_name() . ': temporadas e episódios dos seus animes favoritos.',
    'release' => 'Lançamentos do ' . site_name() . ': os filmes, séries e animes mais recentes.',
][$catType];
$noindex = ($genre !== '' || $year > 0 || $sort !== 'recentes');
require __DIR__ . '/header.php';
?>
<div class="container page">
  <?= ad_slot('list_top') ?>
  <div class="page-head">
    <h1><?= e($heading) ?> <small><?= $total ?> título<?= $total === 1 ? '' : 's' ?></small></h1>
    <form class="filters" method="get">
      <select name="genero" onchange="this.form.submit()">
        <option value="">Todos os gêneros</option>
        <?php foreach ($genreList as $g): ?><option <?= $g === $genre ? 'selected' : '' ?>><?= e($g) ?></option><?php endforeach; ?>
      </select>
      <select name="ano" onchange="this.form.submit()">
        <option value="">Ano</option>
        <?php foreach ($years as $y): ?><option value="<?= (int)$y['year'] ?>" <?= (int)$y['year'] === $year ? 'selected' : '' ?>><?= (int)$y['year'] ?></option><?php endforeach; ?>
      </select>
      <select name="ordem" onchange="this.form.submit()">
        <option value="recentes" <?= $sort === 'recentes' ? 'selected' : '' ?>>Adicionados recentemente</option>
        <option value="lancamento" <?= $sort === 'lancamento' ? 'selected' : '' ?>>Data de lançamento</option>
        <option value="populares" <?= $sort === 'populares' ? 'selected' : '' ?>>Mais assistidos</option>
        <option value="nota" <?= $sort === 'nota' ? 'selected' : '' ?>>Melhor avaliados</option>
        <option value="az" <?= $sort === 'az' ? 'selected' : '' ?>>A–Z</option>
      </select>
    </form>
  </div>

  <?php if ($items): ?>
    <div class="grid">
      <?php foreach ($items as $c) echo render_card($c); ?>
    </div>
    <?= paginate($total, $perPage, $page, $pageUrl) ?>
  <?php else: ?>
    <div class="empty"><?= icon('film', 'ico ico-xl') ?><p>Nenhum título encontrado por aqui ainda.</p></div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/footer.php'; ?>
