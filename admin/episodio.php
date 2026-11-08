<?php
require __DIR__ . '/_init.php';

$id = in_int('id');
$ep = q_one('SELECT e.*, s.season_number, c.title AS show_title, c.id AS cid, c.type FROM episodes e
             JOIN seasons s ON s.id = e.season_id JOIN contents c ON c.id = e.content_id WHERE e.id = ?', [$id]);
if (!$ep) {
    flash('error', 'Episódio não encontrado.');
    redirect('admin/conteudos.php');
}

if (is_post() && in_str('action') === 'md_send') {
    csrf_check();
    try {
        mixdrop_send(in_str('md_url'), (int)$ep['cid'], $id, in_str('md_audio'));
        flash('success', 'Vídeo enviado para o MixDrop. O player aparece sozinho quando ficar pronto.');
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }
    redirect('admin/episodio.php?id=' . $id . '#players');
}
if (is_post()) {
    csrf_check();
    $num = max(1, in_int('episode_number', (int)$ep['episode_number']));
    try {
        q('UPDATE episodes SET episode_number = ?, name = ?, overview = ?, still = ?, air_date = ?, runtime = ? WHERE id = ?', [
            $num,
            in_str('name') ?: 'Episódio ' . $num,
            in_str('overview') ?: null,
            clean_url(in_str('still'), true) ?: null,
            preg_match('/^\d{4}-\d{2}-\d{2}$/', in_str('air_date')) ? in_str('air_date') : null,
            in_int('runtime') ?: null,
            $id,
        ]);
        flash('success', 'Episódio salvo.');
    } catch (PDOException $e) {
        flash('error', 'Já existe um episódio com esse número nesta temporada.');
    }
    redirect('admin/episodio.php?id=' . $id);
}

$players = q_all('SELECT * FROM players WHERE episode_id = ? ORDER BY sort, id', [$id]);
$prev = q_val('SELECT e.id FROM episodes e JOIN seasons s ON s.id = e.season_id WHERE e.content_id = ? AND (s.season_number < ? OR (s.season_number = ? AND e.episode_number < ?)) ORDER BY s.season_number DESC, e.episode_number DESC LIMIT 1',
    [$ep['cid'], $ep['season_number'], $ep['season_number'], $ep['episode_number']]);
$next = q_val('SELECT e.id FROM episodes e JOIN seasons s ON s.id = e.season_id WHERE e.content_id = ? AND (s.season_number > ? OR (s.season_number = ? AND e.episode_number > ?)) ORDER BY s.season_number, e.episode_number LIMIT 1',
    [$ep['cid'], $ep['season_number'], $ep['season_number'], $ep['episode_number']]);
$self = url('admin/episodio.php?id=' . $id);

admin_header('T' . $ep['season_number'] . ':E' . $ep['episode_number'] . ' — ' . $ep['show_title'], 'conteudos');
?>
<div class="toolbar">
  <a class="back" href="<?= e(url('admin/conteudo.php?id=' . $ep['cid'] . '#t' . $ep['season_id'])) ?>"><?= icon('arrow-left') ?> <?= e($ep['show_title']) ?> • Temporada <?= (int)$ep['season_number'] ?></a>
  <div class="toolbar-right">
    <?php if ($prev): ?><a class="btn btn-sm" href="<?= e(url('admin/episodio.php?id=' . $prev)) ?>">‹ Anterior</a><?php endif; ?>
    <?php if ($next): ?><a class="btn btn-sm" href="<?= e(url('admin/episodio.php?id=' . $next)) ?>">Próximo ›</a><?php endif; ?>
  </div>
</div>

<section class="panel" id="players">
  <div class="panel-head"><h2>Players do episódio</h2><a class="btn btn-sm" target="_blank" href="<?= e(url('titulo.php?id=' . $ep['cid'] . '#ep-' . $id)) ?>"><?= icon('eye') ?> Ver no site</a></div>
  <?php mixdrop_box('episode', $id, 'players'); ?>
  <?php players_editor($players, 'episode_id', $id, $self . '#players'); ?>
</section>

<section class="panel">
  <div class="panel-head"><h2>Informações</h2></div>
  <form method="post" class="form-grid">
    <?= csrf_field() ?>
    <label class="field"><span>Número</span><input type="number" min="1" name="episode_number" value="<?= (int)$ep['episode_number'] ?>"></label>
    <label class="field span-2"><span>Nome</span><input name="name" value="<?= e($ep['name']) ?>"></label>
    <label class="field span-3"><span>Sinopse</span><textarea name="overview" rows="3"><?= e($ep['overview']) ?></textarea></label>
    <label class="field"><span>Imagem (URL ou caminho TMDB)</span><input name="still" value="<?= e($ep['still']) ?>" data-preview="pv-still"></label>
    <label class="field"><span>Data de exibição</span><input type="date" name="air_date" value="<?= e($ep['air_date']) ?>"></label>
    <label class="field"><span>Duração (min)</span><input name="runtime" value="<?= e($ep['runtime']) ?>"></label>
    <div class="previews span-3"><img id="pv-still" class="wide" src="<?= e(img($ep['still'], 'w300')) ?>" alt=""></div>
    <div class="span-3"><button class="btn btn-red">Salvar episódio</button></div>
  </form>
</section>
<?php admin_footer(); ?>
