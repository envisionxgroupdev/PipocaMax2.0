<?php
require __DIR__ . '/_init.php';
@set_time_limit(0);

$id = in_int('id');
$novo = in_str('novo');
$c = $id ? q_one('SELECT * FROM contents WHERE id = ?', [$id]) : null;
if ($id && !$c) {
    flash('error', 'Conteúdo não encontrado.');
    redirect('admin/conteudos.php');
}
$self = url('admin/conteudo.php?id=' . $id);

function youtube_key(string $v): ?string
{
    $v = trim($v);
    if ($v === '') return null;
    if (preg_match('~(?:v=|youtu\.be/|embed/|shorts/)([A-Za-z0-9_-]{6,})~', $v, $m)) return $m[1];
    return preg_match('~^[A-Za-z0-9_-]{6,}$~', $v) ? $v : null;
}

if (is_post()) {
    csrf_check();
    $action = in_str('action');
    try {
        switch ($action) {
            case 'save':
                $type = in_str('type');
                if (!in_array($type, ['movie', 'series', 'anime'], true)) $type = 'movie';
                $title = in_str('title');
                if ($title === '') throw new RuntimeException('O título é obrigatório.');
                $data = [
                    'type' => $type,
                    'title' => mb_substr($title, 0, 255),
                    'original_title' => in_str('original_title') ?: null,
                    'slug' => slugify($title),
                    'tmdb_id' => in_int('tmdb_id') ?: null,
                    'imdb_id' => in_str('imdb_id') ?: null,
                    'overview' => in_str('overview') ?: null,
                    'poster' => clean_url(in_str('poster'), true) ?: null,
                    'backdrop' => clean_url(in_str('backdrop'), true) ?: null,
                    'logo' => clean_url(in_str('logo'), true) ?: null,
                    'year' => in_int('year') ?: null,
                    'release_date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', in_str('release_date')) ? in_str('release_date') : null,
                    'rating' => in_str('rating') !== '' ? min(10, max(0, (float)str_replace(',', '.', in_str('rating')))) : null,
                    'runtime' => in_int('runtime') ?: null,
                    'genres' => in_str('genres') ?: null,
                    'age_rating' => in_str('age_rating') ?: null,
                    'trailer' => youtube_key(in_str('trailer')),
                    'is_release' => isset($_POST['is_release']) ? 1 : 0,
                    'featured' => isset($_POST['featured']) ? 1 : 0,
                    'status' => in_str('status') === 'draft' ? 'draft' : 'published',
                ];
                if ($c) {
                    $sets = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($data)));
                    q("UPDATE contents SET $sets WHERE id = ?", array_merge(array_values($data), [$id]));
                    flash('success', 'Alterações salvas.');
                } else {
                    $cols = implode(', ', array_map(fn($k) => "`$k`", array_keys($data)));
                    q("INSERT INTO contents ($cols) VALUES (" . implode(', ', array_fill(0, count($data), '?')) . ')', array_values($data));
                    $id = (int)db()->lastInsertId();
                    flash('success', 'Conteúdo criado. Agora adicione os players' . ($type === 'movie' ? '' : ', temporadas e episódios') . '.');
                }
                redirect('admin/conteudo.php?id=' . $id);

            case 'sync':
                if (!$c || !$c['tmdb_id']) throw new RuntimeException('Esse título não tem ID do TMDB.');
                tmdb_import((int)$c['tmdb_id'], $c['type'], true);
                flash('success', 'Dados atualizados do TMDB' . ($c['type'] === 'movie' ? '.' : ' (novas temporadas e episódios incluídos).'));
                break;

            case 'fetch_tmdb':
                // Puxa (ou atualiza) os dados do TMDB pelo ID informado
                $tid = in_int('tmdb_id');
                if (!$tid && preg_match('~themoviedb\.org/(?:movie|tv)/(\d+)~', in_str('tmdb_id'), $mm)) $tid = (int)$mm[1];
                if (!$tid) throw new RuntimeException('Informe o ID do TMDB (ou cole o link do título no TMDB).');
                $ftype = in_str('type');
                if (!in_array($ftype, ['movie', 'series', 'anime'], true)) $ftype = $c['type'] ?? 'movie';
                if ($c && ((int)$c['tmdb_id'] !== $tid || $c['type'] !== $ftype)) {
                    q('UPDATE contents SET tmdb_id = ?, type = ? WHERE id = ?', [$tid, $ftype, $id]);
                }
                $newId = tmdb_import($tid, $ftype, true, [
                    'is_release' => isset($_POST['is_release']),
                    'featured' => isset($_POST['featured']),
                ]);
                flash('success', 'Dados puxados do TMDB com sucesso.');
                redirect('admin/conteudo.php?id=' . $newId);

            case 'bulk_players':
                $tpl = (string)($_POST['templates'] ?? '');
                if (!parse_player_templates($tpl)) throw new RuntimeException('Escreva pelo menos um modelo no formato Nome|URL.');
                $mode = in_str('mode');
                $seasonId = in_int('season_id');
                $sql = 'SELECT e.id FROM episodes e WHERE e.content_id = ?';
                $params = [$id];
                if ($seasonId) { $sql .= ' AND e.season_id = ?'; $params[] = $seasonId; }
                $eps = array_column(q_all($sql, $params), 'id');
                $count = 0;
                foreach ($eps as $eid) {
                    $has = (int)q_val('SELECT COUNT(*) FROM players WHERE episode_id = ?', [$eid]);
                    if ($mode === 'replace') {
                        q('DELETE FROM players WHERE episode_id = ?', [$eid]);
                    } elseif ($mode === 'empty' && $has) {
                        continue;
                    }
                    $count += apply_episode_templates((int)$eid, $tpl);
                }
                flash('success', "$count player(s) criado(s) em " . count($eps) . ' episódio(s) verificado(s).');
                break;

            case 'clear_players':
                q('DELETE p FROM players p JOIN episodes e ON e.id = p.episode_id WHERE e.content_id = ?', [$id]);
                flash('success', 'Todos os players dos episódios foram removidos.');
                break;

            case 'add_season':
                $num = max(0, in_int('season_number'));
                q('INSERT INTO seasons (content_id, season_number, name) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE name = VALUES(name)',
                    [$id, $num, in_str('name') ?: ($num === 0 ? 'Especiais' : 'Temporada ' . $num)]);
                flash('success', 'Temporada salva.');
                break;

            case 'import_season':
                if (!$c['tmdb_id']) throw new RuntimeException('Sem ID do TMDB.');
                tmdb_import_season($id, (int)$c['tmdb_id'], in_int('season_number'));
                flash('success', 'Temporada importada do TMDB.');
                break;

            case 'delete_season':
                q('DELETE FROM seasons WHERE id = ? AND content_id = ?', [in_int('season_id'), $id]);
                flash('success', 'Temporada excluída.');
                break;

            case 'add_episode':
                $sid = in_int('season_id');
                if (!q_val('SELECT 1 FROM seasons WHERE id = ? AND content_id = ?', [$sid, $id])) throw new RuntimeException('Temporada inválida.');
                $num = in_int('episode_number');
                if ($num < 1) $num = (int)q_val('SELECT COALESCE(MAX(episode_number), 0) + 1 FROM episodes WHERE season_id = ?', [$sid]);
                q('INSERT INTO episodes (season_id, content_id, episode_number, name) VALUES (?, ?, ?, ?)',
                    [$sid, $id, $num, in_str('name') ?: 'Episódio ' . $num]);
                $eid = (int)db()->lastInsertId();
                $urlv = clean_url(in_str('url'));
                if ($urlv !== '') {
                    q('INSERT INTO players (episode_id, name, url, kind) VALUES (?, ?, ?, ?)', [$eid, 'Player 1', $urlv, player_kind_from_url($urlv)]);
                }
                flash('success', 'Episódio ' . $num . ' adicionado.');
                break;

            case 'delete_episode':
                q('DELETE FROM episodes WHERE id = ? AND content_id = ?', [in_int('episode_id'), $id]);
                flash('success', 'Episódio excluído.');
                break;

            case 'delete':
                q('DELETE FROM contents WHERE id = ?', [$id]);
                flash('success', 'Conteúdo excluído.');
                redirect('admin/conteudos.php');
        }
    } catch (PDOException $e) {
        flash('error', str_contains($e->getMessage(), 'Duplicate') ? 'Já existe um item com esse número.' : 'Erro no banco: ' . $e->getMessage());
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }
    redirect(in_str('back') ? safe_back(in_str('back')) : 'admin/conteudo.php?id=' . $id . (in_str('anchor') ? '#' . preg_replace('/[^a-z0-9-]/', '', in_str('anchor')) : ''));
}

// ---------- Exibição ----------
$isNew = !$c;
$type = $c['type'] ?? (in_array($novo, ['movie', 'series', 'anime'], true) ? $novo : 'movie');
$c = $c ?? ['title' => '', 'original_title' => '', 'tmdb_id' => '', 'imdb_id' => '', 'overview' => '', 'poster' => '', 'backdrop' => '', 'logo' => '',
    'year' => '', 'release_date' => '', 'rating' => '', 'runtime' => '', 'genres' => '', 'age_rating' => '', 'trailer' => '',
    'is_release' => 0, 'featured' => 0, 'status' => 'published', 'type' => $type];

$players = !$isNew && $type === 'movie' ? q_all('SELECT * FROM players WHERE content_id = ? ORDER BY sort, id', [$id]) : [];
$seasons = [];
$episodes = [];
if (!$isNew && $type !== 'movie') {
    $seasons = q_all('SELECT * FROM seasons WHERE content_id = ? ORDER BY season_number', [$id]);
    foreach (q_all('SELECT e.*, (SELECT COUNT(*) FROM players p WHERE p.episode_id = e.id) AS np FROM episodes e WHERE e.content_id = ? ORDER BY episode_number', [$id]) as $ep) {
        $episodes[(int)$ep['season_id']][] = $ep;
    }
}

admin_header($isNew ? 'Novo ' . mb_strtolower(type_label($type)) : $c['title'], 'conteudos');
?>
<a class="back" href="<?= e(url('admin/conteudos.php?tipo=' . $type)) ?>"><?= icon('arrow-left') ?> Voltar para <?= e(type_label($type, true)) ?></a>

<?php if (!$isNew): ?>
<div class="content-head">
  <img src="<?= e(img($c['poster'], 'w185')) ?>" alt="">
  <div>
    <h2><?= e($c['title']) ?> <?php if ($c['year']): ?><small>(<?= (int)$c['year'] ?>)</small><?php endif; ?></h2>
    <p class="muted"><?= e(type_label($type)) ?><?= $c['tmdb_id'] ? ' • TMDB ' . (int)$c['tmdb_id'] : '' ?> • <?= number_format((int)$c['views'], 0, ',', '.') ?> visualizações</p>
    <div class="btn-row">
      <a class="btn" href="<?= e(content_url($c)) ?>" target="_blank"><?= icon('eye') ?> Ver no site</a>
      <?php if ($c['tmdb_id'] && tmdb_configured()): ?>
        <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="sync"><button class="btn" data-loading="Atualizando…"><?= icon('download') ?> Atualizar do TMDB</button></form>
      <?php endif; ?>
      <form method="post" class="inline" data-confirm="Excluir este título com todos os players e episódios?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><button class="btn btn-danger"><?= icon('trash') ?> Excluir</button></form>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if (!$isNew && $type === 'movie'): ?>
<section class="panel" id="players">
  <div class="panel-head">
    <h2>Players do filme</h2>
  </div>
  <?php $apiP = api_players_movie($c); ?>
  <div class="api-box">
    <div class="api-box-head"><b><?= icon('live') ?> Players da sua API</b><span class="pill <?= $apiP ? 'pill-ok' : 'pill-warn' ?>"><?= $apiP ? count($apiP) . ' automático(s)' : 'Não configurado' ?></span></div>
    <?php if ($apiP): ?>
      <ul class="api-list"><?php foreach ($apiP as $ap): ?><li><b><?= e($ap['name']) ?></b> <a href="<?= e($ap['url']) ?>" target="_blank" rel="noopener"><?= e($ap['url']) ?></a></li><?php endforeach; ?></ul>
      <p class="muted small">Aparecem sozinhos no site, montados com o ID do TMDB. Não precisa cadastrar nada.</p>
    <?php else: ?>
      <p class="muted small"><?= api_has_movie_players() ? 'Este título não tem ID do TMDB/IMDb, por isso a API não consegue montar o link.' : 'Configure o link da sua API em <a href="' . e(url('admin/configuracoes.php#players')) . '">Configurações › API de players</a> e todos os filmes ganham player automaticamente.' ?></p>
    <?php endif; ?>
  </div>
  <h3 class="sub-title">Players extras (opcional)</h3>
  <p class="muted small">Adicione aqui outros servidores só para este filme. Aceita link de embed (iframe), vídeo .mp4 ou transmissão .m3u8.</p>
  <?php players_editor($players, 'content_id', $id, $self . '#players'); ?>
</section>
<?php endif; ?>

<?php if (!$isNew && $type !== 'movie'): ?>
<section class="panel" id="temporadas">
  <div class="panel-head">
    <h2>Temporadas e episódios</h2>
    <span class="muted"><?= count($seasons) ?> temporada(s) • <?= array_sum(array_map('count', $episodes)) ?> episódio(s)</span>
  </div>

  <?php $apiEp = $seasons ? api_players_episode($c, (int)$seasons[0]['season_number'], 1) : []; ?>
  <div class="api-box">
    <div class="api-box-head"><b><?= icon('live') ?> Players da sua API</b><span class="pill <?= $apiEp ? 'pill-ok' : 'pill-warn' ?>"><?= $apiEp ? 'Ativo em todos os episódios' : 'Não configurado' ?></span></div>
    <?php if ($apiEp && !preg_match('/\{(temporada2?)\}/', (string)setting('episode_player_templates'))): ?>
      <div class="alert alert-error" style="margin:8px 0 0">O link da API de episódios está sem <b>temporada</b> e <b>episódio</b>, então todos os episódios abrem o mesmo vídeo. Corrija em <a href="<?= e(url('admin/configuracoes.php#players')) ?>">Configurações › API de players</a>.</div>
    <?php endif; ?>
    <?php if ($apiEp): ?>
      <p class="muted small">Todos os episódios já têm player automático. Exemplo (T<?= (int)$seasons[0]['season_number'] ?>:E1): <a href="<?= e($apiEp[0]['url']) ?>" target="_blank" rel="noopener"><?= e($apiEp[0]['url']) ?></a></p>
    <?php elseif (!api_has_episode_players()): ?>
      <p class="muted small">Configure o link da sua API em <a href="<?= e(url('admin/configuracoes.php#players')) ?>">Configurações › API de players</a> e todos os episódios ganham player automaticamente.</p>
    <?php else: ?>
      <p class="muted small">Este título não tem ID do TMDB/IMDb, por isso a API não consegue montar o link.</p>
    <?php endif; ?>
  </div>

  <details class="box-details" id="massa">
    <summary><?= icon('wrench') ?> Adicionar servidores extras em massa (opcional)</summary>
    <form method="post" class="bulk-form">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="bulk_players">
      <input type="hidden" name="anchor" value="temporadas">
      <p class="muted small">Um player por linha no formato <code>Nome|URL|Áudio|Qualidade</code>. Variáveis: <code>{tmdb_id}</code> <code>{imdb_id}</code> <code>{temporada}</code> <code>{episodio}</code> <code>{temporada2}</code> <code>{episodio2}</code> (com zero à esquerda).</p>
      <textarea name="templates" rows="4" placeholder="Servidor extra|https://outroplayer.com/serie/{tmdb_id}/{temporada}/{episodio}|Dublado|HD"></textarea>
      <div class="form-row">
        <select name="season_id"><option value="0">Todas as temporadas</option><?php foreach ($seasons as $s): ?><option value="<?= (int)$s['id'] ?>">Temporada <?= (int)$s['season_number'] ?></option><?php endforeach; ?></select>
        <select name="mode"><option value="empty">Só episódios sem player</option><option value="append">Adicionar em todos</option><option value="replace">Substituir players existentes</option></select>
        <button class="btn btn-red">Gerar players</button>
      </div>
    </form>
  </details>

  <?php foreach ($seasons as $s): $eps = $episodes[(int)$s['id']] ?? []; $withP = count(array_filter($eps, fn($e) => $e['np'] > 0)); ?>
  <details class="season" id="t<?= (int)$s['id'] ?>" <?= count($seasons) === 1 ? 'open' : '' ?>>
    <summary>
      <b><?= e($s['season_number'] == 0 ? 'Especiais' : 'Temporada ' . $s['season_number']) ?></b>
      <span class="muted"><?= e($s['name']) ?></span>
      <span class="pill <?= $eps && $withP === count($eps) ? 'pill-ok' : ($withP ? '' : 'pill-warn') ?>"><?= $withP ?>/<?= count($eps) ?> com player</span>
    </summary>
    <div class="season-body">
      <table class="table compact">
        <thead><tr><th>#</th><th>Episódio</th><th>Data</th><th>Players</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($eps as $ep): ?>
          <tr>
            <td><?= (int)$ep['episode_number'] ?></td>
            <td><a href="<?= e(url('admin/episodio.php?id=' . $ep['id'])) ?>"><?= e($ep['name']) ?></a></td>
            <td class="muted"><?= $ep['air_date'] ? e(date('d/m/Y', strtotime($ep['air_date']))) : '—' ?></td>
            <td><span class="pill <?= $ep['np'] ? 'pill-ok' : 'pill-warn' ?>"><?= (int)$ep['np'] ?></span></td>
            <td class="actions">
              <a class="btn-ghost" href="<?= e(url('admin/episodio.php?id=' . $ep['id'])) ?>" title="Editar e players"><?= icon('edit') ?></a>
              <?= delete_button('', ['action' => 'delete_episode', 'episode_id' => $ep['id'], 'anchor' => 't' . $s['id']], 'Excluir', 'Excluir o episódio ' . $ep['episode_number'] . '?') ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$eps): ?><tr><td colspan="5" class="empty-cell">Nenhum episódio.</td></tr><?php endif; ?>
        </tbody>
      </table>
      <form method="post" class="form-row add-ep">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add_episode"><input type="hidden" name="season_id" value="<?= (int)$s['id'] ?>"><input type="hidden" name="anchor" value="t<?= (int)$s['id'] ?>">
        <input type="number" name="episode_number" placeholder="Nº" min="1" class="w-num">
        <input name="name" placeholder="Nome do episódio (opcional)">
        <input name="url" placeholder="URL do player (opcional)" class="grow">
        <button class="btn btn-sm"><?= icon('plus') ?> Episódio</button>
      </form>
      <div class="btn-row">
        <?php if ($c['tmdb_id'] && tmdb_configured()): ?>
          <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="import_season"><input type="hidden" name="season_number" value="<?= (int)$s['season_number'] ?>"><input type="hidden" name="anchor" value="t<?= (int)$s['id'] ?>"><button class="btn btn-sm" data-loading="Importando…"><?= icon('download') ?> Atualizar episódios do TMDB</button></form>
        <?php endif; ?>
        <form method="post" class="inline" data-confirm="Excluir a temporada inteira com todos os episódios e players?"><?= csrf_field() ?><input type="hidden" name="action" value="delete_season"><input type="hidden" name="season_id" value="<?= (int)$s['id'] ?>"><input type="hidden" name="anchor" value="temporadas"><button class="btn btn-sm btn-danger"><?= icon('trash') ?> Excluir temporada</button></form>
      </div>
    </div>
  </details>
  <?php endforeach; ?>

  <form method="post" class="form-row add-season">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="add_season"><input type="hidden" name="anchor" value="temporadas">
    <input type="number" name="season_number" placeholder="Nº" min="0" value="<?= count($seasons) + 1 ?>" class="w-num" required>
    <input name="name" placeholder="Nome (opcional)">
    <button class="btn btn-sm"><?= icon('plus') ?> Adicionar temporada</button>
    <?php if ($c['tmdb_id'] && tmdb_configured()): ?><span class="muted small">ou use “Atualizar do TMDB” no topo para puxar todas.</span><?php endif; ?>
  </form>
  <?php if ($episodes): ?>
  <form method="post" class="inline right" data-confirm="Remover TODOS os players de TODOS os episódios desta série?"><?= csrf_field() ?><input type="hidden" name="action" value="clear_players"><input type="hidden" name="anchor" value="temporadas"><button class="link-danger">Remover todos os players dos episódios</button></form>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($isNew && tmdb_configured()): ?>
<section class="panel tmdb-quick">
  <div class="panel-head"><h2><?= icon('download') ?> Puxar dados do TMDB</h2><span class="muted small">Digite o nome e escolha o título: pôster, sinopse, trailer<?= $type !== 'movie' ? ', temporadas e episódios' : '' ?> vêm sozinhos.</span></div>
  <div class="filterbar big" data-tmdb-search data-kind="<?= $type === 'movie' ? 'movie' : 'tv' ?>">
    <input type="search" placeholder="Nome do <?= e(mb_strtolower(type_label($type))) ?>… (ou cole o ID / link do TMDB)" data-q autofocus>
    <button type="button" class="btn btn-red" data-go><?= icon('search') ?> Buscar</button>
  </div>
  <div class="tmdb-grid small" data-results></div>
  <form method="post" data-fetch-form hidden>
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="fetch_tmdb">
    <input type="hidden" name="type" value="<?= e($type) ?>">
    <input type="hidden" name="tmdb_id" value="">
  </form>
  <div class="checks">
    <label class="check"><input type="checkbox" data-flag="is_release"> Marcar como lançamento</label>
    <label class="check"><input type="checkbox" data-flag="featured"> Colocar em destaque</label>
  </div>
</section>
<?php endif; ?>

<section class="panel" id="dados">
  <div class="panel-head"><h2><?= $isNew ? 'Dados do título' : 'Editar informações' ?></h2></div>
  <form method="post" class="form-grid">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <label class="field span-2"><span>Título *</span><input name="title" value="<?= e($c['title']) ?>" required></label>
    <label class="field"><span>Tipo</span>
      <select name="type"><?php foreach (['movie', 'series', 'anime'] as $t): ?><option value="<?= $t ?>" <?= $type === $t ? 'selected' : '' ?>><?= e(type_label($t)) ?></option><?php endforeach; ?></select>
    </label>
    <label class="field"><span>Título original</span><input name="original_title" value="<?= e($c['original_title']) ?>"></label>
    <label class="field"><span>ID do TMDB</span><span class="input-btn"><input name="tmdb_id" value="<?= e($c['tmdb_id']) ?>" placeholder="ID ou link do TMDB"><?php if (tmdb_configured()): ?><button class="btn btn-red btn-sm" name="action" value="fetch_tmdb" formnovalidate data-loading="Puxando dados do TMDB…" title="Preenche tudo automaticamente"><?= icon('download') ?> Puxar dados</button><?php endif; ?></span></label>
    <label class="field"><span>ID do IMDb</span><input name="imdb_id" value="<?= e($c['imdb_id']) ?>" placeholder="tt1234567"></label>
    <label class="field span-3"><span>Sinopse</span><textarea name="overview" rows="4"><?= e($c['overview']) ?></textarea></label>
    <label class="field"><span>Pôster (URL ou caminho TMDB)</span><input name="poster" value="<?= e($c['poster']) ?>" data-preview="pv-poster"></label>
    <label class="field"><span>Fundo / backdrop</span><input name="backdrop" value="<?= e($c['backdrop']) ?>" data-preview="pv-backdrop"></label>
    <label class="field"><span>Logo do título (opcional)</span><input name="logo" value="<?= e($c['logo']) ?>"></label>
    <div class="previews span-3">
      <img id="pv-poster" src="<?= e(img($c['poster'], 'w185')) ?>" alt="">
      <img id="pv-backdrop" class="wide" src="<?= e(img($c['backdrop'], 'w500')) ?>" alt="">
    </div>
    <label class="field"><span>Ano</span><input name="year" value="<?= e($c['year']) ?>" inputmode="numeric"></label>
    <label class="field"><span>Data de lançamento</span><input type="date" name="release_date" value="<?= e($c['release_date']) ?>"></label>
    <label class="field"><span>Nota (0–10)</span><input name="rating" value="<?= e($c['rating']) ?>"></label>
    <label class="field"><span>Duração (min)</span><input name="runtime" value="<?= e($c['runtime']) ?>" inputmode="numeric"></label>
    <label class="field"><span>Gêneros (separados por vírgula)</span><input name="genres" value="<?= e($c['genres']) ?>"></label>
    <label class="field"><span>Classificação</span><input name="age_rating" value="<?= e($c['age_rating']) ?>" placeholder="L, 10, 12, 14, 16, 18"></label>
    <label class="field span-2"><span>Trailer (link do YouTube)</span><input name="trailer" value="<?= $c['trailer'] ? e('https://youtu.be/' . $c['trailer']) : '' ?>"></label>
    <label class="field"><span>Status</span><select name="status"><option value="published" <?= $c['status'] === 'published' ? 'selected' : '' ?>>Publicado</option><option value="draft" <?= $c['status'] === 'draft' ? 'selected' : '' ?>>Rascunho (oculto)</option></select></label>
    <div class="span-3 checks">
      <label class="check"><input type="checkbox" name="is_release" value="1" <?= $c['is_release'] ? 'checked' : '' ?>> Mostrar em <b>Lançamentos</b></label>
      <label class="check"><input type="checkbox" name="featured" value="1" <?= $c['featured'] ? 'checked' : '' ?>> Mostrar no <b>destaque</b> do início</label>
    </div>
    <div class="span-3"><button class="btn btn-red btn-lg"><?= $isNew ? 'Criar título' : 'Salvar alterações' ?></button></div>
  </form>
</section>
<?php admin_footer(); ?>
