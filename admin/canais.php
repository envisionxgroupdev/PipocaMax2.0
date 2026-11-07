<?php
require __DIR__ . '/_init.php';

if (is_post()) {
    csrf_check();
    $action = in_str('action');
    $id = in_int('id');
    if (in_array($action, ['tv_add', 'tv_run', 'tv_del', 'tv_edit'], true)) {
        @set_time_limit(120);
        $list = tv_sources();
        $sid = in_str('sid');
        try {
            if ($action === 'tv_add' || $action === 'tv_edit') {
                $urlv = clean_url(in_str('url'));
                if ($urlv === '') throw new RuntimeException('Informe o link da lista (começando com http:// ou https://).');
                $src = [
                    'id' => $action === 'tv_edit' && $sid !== '' ? $sid : 'l' . substr(bin2hex(random_bytes(4)), 0, 7),
                    'name' => mb_substr(in_str('name') ?: 'Lista de canais', 0, 60),
                    'url' => $urlv,
                    'every' => max(0, min(168, in_int('every', 24))),
                    'sync' => isset($_POST['sync']),
                    'groups' => mb_substr(in_str('groups'), 0, 500),
                    'last' => 0, 'count' => 0, 'error' => '',
                ];
                $found = false;
                foreach ($list as $i => $s) if ($s['id'] === $src['id']) { $list[$i] = $src; $found = true; }
                if (!$found) $list[] = $src;
                tv_sources_save($list);
                redirect('admin/canais.php?importar=' . $src['id'] . '#listas'); // a importação roda em partes
            } elseif ($action === 'tv_run') {
                foreach ($list as $i => $s) {
                    if ($s['id'] !== $sid) continue;
                    try {
                        $r = tv_import_source($s);
                        $list[$i]['last'] = time(); $list[$i]['count'] = $r['total']; $list[$i]['error'] = '';
                        flash('success', "Lista atualizada: {$r['added']} novo(s), {$r['updated']} atualizado(s)" . ($r['removed'] ? ", {$r['removed']} removido(s)" : '') . '.');
                    } catch (Throwable $e) {
                        $list[$i]['error'] = $e->getMessage();
                        throw $e;
                    } finally {
                        tv_sources_save($list);
                    }
                }
            } elseif ($action === 'tv_del') {
                $list = array_values(array_filter($list, fn($s) => $s['id'] !== $sid));
                tv_sources_save($list);
                if (isset($_POST['del_channels'])) {
                    q('DELETE FROM channels WHERE source = ?', [$sid]);
                    flash('success', 'Lista e seus canais removidos.');
                } else {
                    q('UPDATE channels SET source = NULL WHERE source = ?', [$sid]);
                    flash('success', 'Lista removida (os canais continuam no site).');
                }
            }
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('admin/canais.php#listas');
    }
    if ($action === 'save') {
        $name = mb_substr(in_str('name'), 0, 120);
        $u = clean_url(in_str('url'));
        if ($name === '' || $u === '') {
            flash('error', 'Nome e URL do canal são obrigatórios.');
            redirect('admin/canais.php?' . ($id ? 'id=' . $id : 'novo=1'));
        }
        $kind = in_str('kind');
        if (!in_array($kind, ['embed', 'video', 'hls'], true)) $kind = player_kind_from_url($u);
        $data = [$name, clean_url(in_str('logo'), true) ?: null, mb_substr(in_str('category'), 0, 80) ?: null, $u, $kind, in_str('status') === 'inactive' ? 'inactive' : 'active', in_int('sort')];
        if ($id) {
            q('UPDATE channels SET name = ?, logo = ?, category = ?, url = ?, kind = ?, status = ?, sort = ? WHERE id = ?', array_merge($data, [$id]));
            if (!q_val("SELECT 1 FROM channels WHERE id = ? AND slug IS NOT NULL AND slug <> ''", [$id])) q('UPDATE channels SET slug = ? WHERE id = ?', [unique_channel_slug($name, $id), $id]);
        } else {
            q('INSERT INTO channels (name, logo, category, url, kind, status, sort, slug) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', array_merge($data, [unique_channel_slug($name)]));
        }
        flash('success', 'Canal salvo.');
    } elseif ($action === 'delete') {
        q('DELETE FROM channels WHERE id = ?', [$id]);
        flash('success', 'Canal excluído.');
    } elseif ($action === 'toggle') {
        q("UPDATE channels SET status = IF(status = 'active', 'inactive', 'active') WHERE id = ?", [$id]);
    } elseif ($action === 'm3u') {
        $text = (string)($_POST['m3u'] ?? '');
        $lines = preg_split('/\r\n|\r|\n/', $text);
        $n = 0;
        $meta = null;
        foreach ($lines as $line) {
            $line = trim($line);
            if (str_starts_with($line, '#EXTINF')) {
                $meta = ['name' => trim(substr($line, strrpos($line, ',') + 1)), 'logo' => null, 'group' => null];
                if (preg_match('/tvg-logo="([^"]*)"/', $line, $m)) $meta['logo'] = $m[1];
                if (preg_match('/group-title="([^"]*)"/', $line, $m)) $meta['group'] = $m[1];
            } elseif ($line !== '' && $line[0] !== '#' && $meta && clean_url($line) !== '') {
                q('INSERT INTO channels (slug, name, logo, category, url, kind) VALUES (?, ?, ?, ?, ?, ?)',
                    [unique_channel_slug($meta['name'] ?: 'canal'), mb_substr($meta['name'] ?: 'Canal', 0, 120), clean_url((string)$meta['logo'], true) ?: null, $meta['group'] ? mb_substr($meta['group'], 0, 80) : null, $line, player_kind_from_url($line) === 'embed' ? 'hls' : player_kind_from_url($line)]);
                $n++;
                $meta = null;
            }
        }
        flash($n ? 'success' : 'error', $n ? "$n canal(is) importado(s) da lista M3U." : 'Nenhum canal encontrado na lista. Confira o formato M3U.');
    }
    redirect('admin/canais.php');
}

$edit = null;
if (in_int('id')) $edit = q_one('SELECT * FROM channels WHERE id = ?', [in_int('id')]);
$showForm = $edit || in_str('novo') === '1';
$cat = in_str('categoria');
$busca = in_str('q');
$w = ['1=1']; $wp = [];
if ($cat !== '') { $w[] = 'category = ?'; $wp[] = $cat; }
if ($busca !== '') { $w[] = '(name LIKE ? OR category LIKE ?)'; array_push($wp, "%$busca%", "%$busca%"); }
$wsql = implode(' AND ', $w);
$perPage = 50;
$page = max(1, in_int('p', 1));
$totalCh = (int)q_val("SELECT COUNT(*) FROM channels WHERE $wsql", $wp);
$channels = q_all("SELECT * FROM channels WHERE $wsql ORDER BY sort, name LIMIT $perPage OFFSET " . (($page - 1) * $perPage), $wp);
$selfCh = url('admin/canais.php') . '?' . http_build_query(array_filter(['categoria' => $cat, 'q' => $busca]));
$cats = q_all("SELECT category, COUNT(*) n FROM channels WHERE category IS NOT NULL AND category <> '' GROUP BY category ORDER BY n DESC LIMIT 30");
usort($cats, fn($a, $b) => strcmp($a['category'], $b['category']));

admin_header('TV Online', 'canais');
?>
<div class="toolbar">
  <div class="tabs">
    <a class="<?= $cat === '' ? 'active' : '' ?>" href="<?= e(url('admin/canais.php')) ?>">Todos</a>
    <?php foreach ($cats as $c): ?><a class="<?= $cat === $c['category'] ? 'active' : '' ?>" href="<?= e(url('admin/canais.php?categoria=' . urlencode($c['category']))) ?>"><?= e($c['category']) ?> <small><?= (int)$c['n'] ?></small></a><?php endforeach; ?>
  </div>
  <div class="toolbar-right">
    <a class="btn btn-red" href="<?= e(url('admin/canais.php?novo=1')) ?>"><?= icon('plus') ?> Novo canal</a>
  </div>
</div>

<?php if ($showForm): $ch = $edit ?? ['id' => 0, 'name' => '', 'logo' => '', 'category' => '', 'url' => '', 'kind' => 'hls', 'status' => 'active', 'sort' => 0]; ?>
<section class="panel">
  <div class="panel-head"><h2><?= $edit ? 'Editar canal' : 'Novo canal' ?></h2><a href="<?= e(url('admin/canais.php')) ?>">Cancelar</a></div>
  <form method="post" class="form-grid">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)$ch['id'] ?>">
    <label class="field"><span>Nome *</span><input name="name" value="<?= e($ch['name']) ?>" required></label>
    <label class="field"><span>Categoria</span><input name="category" value="<?= e($ch['category']) ?>" list="cats" placeholder="Abertos, Esportes, Filmes…"></label>
    <label class="field"><span>Ordem</span><input type="number" name="sort" value="<?= (int)$ch['sort'] ?>"></label>
    <label class="field span-2"><span>URL da transmissão *</span><input name="url" value="<?= e($ch['url']) ?>" required placeholder="https://…/canal.m3u8 ou link de embed"></label>
    <label class="field"><span>Tipo</span><select name="kind">
      <option value="auto">Detectar</option>
      <?php foreach (['hls' => 'HLS (.m3u8)', 'embed' => 'Embed (iframe)', 'video' => 'Vídeo direto'] as $k => $l): ?><option value="<?= $k ?>" <?= $edit && $ch['kind'] === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?>
    </select></label>
    <label class="field span-2"><span>Logo (URL da imagem)</span><input name="logo" value="<?= e($ch['logo']) ?>" data-preview="pv-logo"></label>
    <label class="field"><span>Status</span><select name="status"><option value="active">Ativo</option><option value="inactive" <?= $ch['status'] === 'inactive' ? 'selected' : '' ?>>Inativo</option></select></label>
    <div class="previews span-3"><img id="pv-logo" class="logo-pv" src="<?= e(img($ch['logo'])) ?>" alt=""></div>
    <div class="span-3"><button class="btn btn-red">Salvar canal</button></div>
    <datalist id="cats"><?php foreach ($cats as $c): ?><option><?= e($c['category']) ?></option><?php endforeach; ?></datalist>
  </form>
</section>
<?php endif; ?>

<section class="panel" id="listas">
  <div class="panel-head"><h2><?= icon('live') ?> Listas de canais por link (API)</h2><span class="muted small">M3U, M3U8 ou JSON</span></div>
  <?php $sources = tv_sources(); ?>
  <?php if ($sources): ?>
    <div class="tv-sources">
      <?php foreach ($sources as $s): $nCh = (int)q_val('SELECT COUNT(*) FROM channels WHERE source = ?', [$s['id']]); ?>
        <div class="tv-src<?= !empty($s['error']) ? ' err' : '' ?>">
          <div class="tv-src-info">
            <b><?= e($s['name']) ?></b>
            <small class="ellipsis"><?= e($s['url']) ?></small>
            <small><?= $nCh ?> canal(is) no site • <?= (int)$s['every'] ? 'atualiza a cada ' . (int)$s['every'] . 'h' : 'atualização manual' ?><?= !empty($s['sync']) ? ' • sincronizada' : '' ?><?= !empty($s['last']) ? ' • última: ' . e(time_ago(date('Y-m-d H:i:s', (int)$s['last']))) : '' ?></small>
            <?php if (!empty($s['error'])): ?><small class="tv-err">⚠ <?= e($s['error']) ?></small><?php endif; ?>
          </div>
          <div class="tv-src-actions">
            <button type="button" class="btn btn-sm" data-tv-run="<?= e($s['id']) ?>" data-tv-name="<?= e($s['name']) ?>"><?= icon('download') ?> Atualizar agora</button>
            <form method="post" class="inline" data-confirm="Remover esta lista? Marque a opção se quiser apagar também os canais dela."><?= csrf_field() ?><input type="hidden" name="action" value="tv_del"><input type="hidden" name="sid" value="<?= e($s['id']) ?>">
              <label class="check small"><input type="checkbox" name="del_channels" value="1"> apagar canais</label>
              <button class="btn-ghost danger" title="Remover lista"><?= icon('trash') ?></button>
            </form>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <details class="box-details" <?= $sources ? '' : 'open' ?>>
    <summary><?= icon('plus') ?> Adicionar lista por link</summary>
    <form method="post" class="form-grid" style="padding:14px 16px">
      <?= csrf_field() ?><input type="hidden" name="action" value="tv_add">
      <label class="field"><span>Nome da lista</span><input name="name" placeholder="Ex: Canais abertos"></label>
      <label class="field span-2"><span>Link da lista (M3U, M3U8 ou JSON)</span><input name="url" required placeholder="https://…/lista.m3u"></label>
      <label class="field"><span>Atualizar automaticamente</span>
        <select name="every"><option value="0">Não (só manual)</option><option value="6">A cada 6 horas</option><option value="12">A cada 12 horas</option><option value="24" selected>1 vez por dia</option><option value="72">A cada 3 dias</option><option value="168">1 vez por semana</option></select>
      </label>
      <label class="field span-2"><span>Só estas categorias (opcional, separadas por vírgula)</span><input name="groups" placeholder="Ex: Notícias, Esportes, Filmes"></label>
      <div class="span-3">
        <label class="check"><input type="checkbox" name="sync" value="1" checked> Manter sincronizada: quando um canal sair da lista, ele sai do site também</label>
      </div>
      <div class="span-3"><button class="btn btn-red"><?= icon('download') ?> Adicionar e importar</button>
        <span class="muted small">A atualização automática usa o mesmo Cron da Importação automática (ou as visitas ao site).</span></div>
    </form>
  </details>
</section>

<form class="filterbar" method="get">
  <input type="hidden" name="categoria" value="<?= e($cat) ?>">
  <input type="search" name="q" value="<?= e($busca) ?>" placeholder="Buscar canal pelo nome ou categoria">
  <button class="btn">Buscar</button>
  <span class="muted"><?= $totalCh ?> canal(is)</span>
</form>
<div class="table-wrap">
<table class="table">
  <thead><tr><th>Canal</th><th>Categoria</th><th>Tipo</th><th>Views</th><th>Ativo</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($channels as $ch): ?>
    <tr>
      <td><div class="title-cell"><span class="ch-logo"><?php if ($ch['logo']): ?><img src="<?= e(img($ch['logo'])) ?>" alt="<?= e(mb_substr($ch['name'], 0, 2)) ?>" onerror="this.parentNode.textContent=this.alt"><?php else: ?><?= e(mb_substr($ch['name'], 0, 2)) ?><?php endif; ?></span><div><a href="<?= e(url('admin/canais.php?id=' . $ch['id'])) ?>"><b><?= e($ch['name']) ?></b></a><small class="ellipsis"><?= e($ch['url']) ?></small></div></div></td>
      <td><?= e($ch['category'] ?: '—') ?></td>
      <td><span class="pill"><?= e(strtoupper($ch['kind'])) ?></span></td>
      <td><?= number_format((int)$ch['views'], 0, ',', '.') ?></td>
      <td><form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$ch['id'] ?>"><button class="switch sm<?= $ch['status'] === 'active' ? ' on' : '' ?>"><span></span></button></form></td>
      <td class="actions">
        <a class="btn-ghost" target="_blank" href="<?= e(url('canal.php?id=' . $ch['id'])) ?>" title="Assistir"><?= icon('eye') ?></a>
        <a class="btn-ghost" href="<?= e(url('admin/canais.php?id=' . $ch['id'])) ?>" title="Editar"><?= icon('edit') ?></a>
        <?= delete_button('', ['action' => 'delete', 'id' => $ch['id']], 'Excluir', 'Excluir o canal ' . $ch['name'] . '?') ?>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$channels): ?><tr><td colspan="6" class="empty-cell">Nenhum canal cadastrado.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
<?= paginate($totalCh, $perPage, $page, $selfCh) ?>

<details class="panel box-details">
  <summary><?= icon('download') ?> Importar canais de uma lista M3U</summary>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="m3u">
    <p class="muted small">Cole o conteúdo de uma lista .m3u (com linhas #EXTINF). Nome, logo (tvg-logo) e categoria (group-title) são lidos automaticamente.</p>
    <textarea name="m3u" rows="8" placeholder="#EXTM3U&#10;#EXTINF:-1 tvg-logo=&quot;https://…/logo.png&quot; group-title=&quot;Notícias&quot;,Canal Exemplo&#10;https://…/canal.m3u8"></textarea>
    <button class="btn btn-red">Importar lista</button>
  </form>
</details>
<div class="modal-q" id="tvq" hidden data-autostart="<?= e(in_str('importar')) ?>">
  <div class="queue-box">
    <div class="queue-head"><h2 id="tvq-title">Importando canais…</h2><span class="muted" id="tvq-status"></span></div>
    <div class="progress"><span id="tvq-bar"></span></div>
    <div class="queue-stats"><span><b id="tvq-add">0</b> novos</span><span><b id="tvq-upd">0</b> atualizados</span><span><b id="tvq-rem">0</b> removidos</span></div>
    <p class="muted small" id="tvq-msg">Baixando a lista…</p>
    <div class="btn-row"><button class="btn btn-red" id="tvq-close" hidden>Fechar</button></div>
  </div>
</div>
<?php admin_footer(); ?>
