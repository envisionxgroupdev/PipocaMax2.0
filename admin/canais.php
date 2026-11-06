<?php
require __DIR__ . '/_init.php';

if (is_post()) {
    csrf_check();
    $action = in_str('action');
    $id = in_int('id');
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
        } else {
            q('INSERT INTO channels (name, logo, category, url, kind, status, sort) VALUES (?, ?, ?, ?, ?, ?, ?)', $data);
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
                q('INSERT INTO channels (name, logo, category, url, kind) VALUES (?, ?, ?, ?, ?)',
                    [mb_substr($meta['name'] ?: 'Canal', 0, 120), clean_url((string)$meta['logo'], true) ?: null, $meta['group'] ? mb_substr($meta['group'], 0, 80) : null, $line, player_kind_from_url($line) === 'embed' ? 'hls' : player_kind_from_url($line)]);
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
$channels = $cat !== '' ? q_all('SELECT * FROM channels WHERE category = ? ORDER BY sort, name', [$cat]) : q_all('SELECT * FROM channels ORDER BY sort, name');
$cats = q_all("SELECT category, COUNT(*) n FROM channels WHERE category IS NOT NULL AND category <> '' GROUP BY category ORDER BY category");

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

<div class="table-wrap">
<table class="table">
  <thead><tr><th>Canal</th><th>Categoria</th><th>Tipo</th><th>Views</th><th>Ativo</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($channels as $ch): ?>
    <tr>
      <td><div class="title-cell"><span class="ch-logo"><?php if ($ch['logo']): ?><img src="<?= e(img($ch['logo'])) ?>" alt=""><?php else: ?><?= e(mb_substr($ch['name'], 0, 2)) ?><?php endif; ?></span><div><a href="<?= e(url('admin/canais.php?id=' . $ch['id'])) ?>"><b><?= e($ch['name']) ?></b></a><small class="ellipsis"><?= e($ch['url']) ?></small></div></div></td>
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

<details class="panel box-details">
  <summary><?= icon('download') ?> Importar canais de uma lista M3U</summary>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="m3u">
    <p class="muted small">Cole o conteúdo de uma lista .m3u (com linhas #EXTINF). Nome, logo (tvg-logo) e categoria (group-title) são lidos automaticamente.</p>
    <textarea name="m3u" rows="8" placeholder="#EXTM3U&#10;#EXTINF:-1 tvg-logo=&quot;https://…/logo.png&quot; group-title=&quot;Notícias&quot;,Canal Exemplo&#10;https://…/canal.m3u8"></textarea>
    <button class="btn btn-red">Importar lista</button>
  </form>
</details>
<?php admin_footer(); ?>
