<?php
require __DIR__ . '/_init.php';

$fields = function (): array {
    $pos = in_str('position'); $style = in_str('style'); $aud = in_str('audience');
    $dt = function (string $k): ?string { $v = in_str($k); return $v !== '' && strtotime($v) ? date('Y-m-d H:i:s', strtotime($v)) : null; };
    $pages = array_values(array_intersect(array_keys(ann_pages()), (array)($_POST['pages'] ?? [])));
    $btnUrl = in_str('btn_url');
    if ($btnUrl !== '' && !preg_match('~^(https?://|/)~i', $btnUrl)) $btnUrl = 'https://' . $btnUrl;
    return [
        'title' => mb_substr(in_str('title'), 0, 120),
        'message' => mb_substr(trim((string)($_POST['message'] ?? '')), 0, 600),
        'btn_text' => mb_substr(in_str('btn_text'), 0, 40),
        'btn_url' => mb_substr($btnUrl, 0, 500),
        'new_tab' => isset($_POST['new_tab']) ? 1 : 0,
        'image' => clean_url(in_str('image')) ?: null,
        'style' => isset(ann_styles()[$style]) ? $style : 'red',
        'position' => isset(ann_positions()[$pos]) ? $pos : 'top',
        'audience' => in_array($aud, ['all', 'users', 'guests'], true) ? $aud : 'all',
        'pages' => count($pages) === count(ann_pages()) ? '' : implode(',', $pages),
        'dismissible' => isset($_POST['dismissible']) ? 1 : 0,
        'freq' => in_array(in_str('freq'), ['once', 'session', 'always'], true) ? in_str('freq') : 'once',
        'delay_sec' => max(0, min(60, in_int('delay_sec', 2))),
        'starts_at' => $dt('starts_at'),
        'ends_at' => $dt('ends_at'),
        'active' => isset($_POST['active']) ? 1 : 0,
    ];
};

if (is_post()) {
    csrf_check();
    $action = in_str('action');
    $id = in_int('id');
    try {
        if ($action === 'save') {
            $d = $fields();
            if ($d['message'] === '' && $d['title'] === '') throw new RuntimeException('Escreva o texto do aviso.');
            if ($d['ends_at'] && $d['starts_at'] && $d['ends_at'] <= $d['starts_at']) throw new RuntimeException('O fim precisa ser depois do início.');
            if (in_str('pages_any') !== '1' && $d['pages'] === '' && empty($_POST['pages'])) throw new RuntimeException('Escolha pelo menos uma página (ou "Todas as páginas").');
            if (in_str('pages_any') === '1') $d['pages'] = '';
            if ($id) {
                $set = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($d)));
                q("UPDATE announcements SET $set WHERE id = ?", [...array_values($d), $id]);
                flash('success', 'Aviso salvo.');
            } else {
                $d['sort'] = (int)q_val('SELECT COALESCE(MIN(sort), 0) - 1 FROM announcements');
                q('INSERT INTO announcements (`' . implode('`, `', array_keys($d)) . '`) VALUES (' . implode(', ', array_fill(0, count($d), '?')) . ')', array_values($d));
                flash('success', $d['active'] ? 'Aviso publicado! Já aparece no site.' : 'Aviso salvo (desligado).');
            }
        }
        if ($action === 'toggle') { q('UPDATE announcements SET active = 1 - active WHERE id = ?', [$id]); }
        if ($action === 'delete') { q('DELETE FROM announcements WHERE id = ?', [$id]); flash('success', 'Aviso apagado.'); }
        if ($action === 'dup') {
            $a = q_one('SELECT * FROM announcements WHERE id = ?', [$id]);
            if ($a) {
                unset($a['id'], $a['created_at']); $a['active'] = 0; $a['views'] = 0; $a['clicks'] = 0; $a['title'] = mb_substr('(cópia) ' . $a['title'], 0, 120);
                q('INSERT INTO announcements (`' . implode('`, `', array_keys($a)) . '`) VALUES (' . implode(', ', array_fill(0, count($a), '?')) . ')', array_values($a));
                flash('success', 'Aviso duplicado (desligado).');
            }
        }
        if ($action === 'up' || $action === 'down') {
            $ids = array_map('intval', array_column(q_all('SELECT id FROM announcements ORDER BY sort, id DESC'), 'id'));
            $i = array_search($id, $ids, true);
            $j = $action === 'up' ? $i - 1 : $i + 1;
            if ($i !== false && isset($ids[$j])) { [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]]; foreach ($ids as $n => $aid) q('UPDATE announcements SET sort = ? WHERE id = ?', [$n, $aid]); }
        }
        cache_bump();
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
        if ($action === 'save') redirect('admin/avisos.php' . ($id ? '?edit=' . $id : '?novo=1'));
    }
    redirect('admin/avisos.php');
}

$editId = in_int('edit');
$a = $editId ? q_one('SELECT * FROM announcements WHERE id = ?', [$editId]) : null;
$isForm = $a || in_int('novo');
$list = q_all('SELECT * FROM announcements ORDER BY sort, id DESC');
$defaults = ['id' => 0, 'title' => '', 'message' => '', 'btn_text' => '', 'btn_url' => '', 'new_tab' => 0, 'image' => '', 'style' => 'red', 'position' => 'top', 'audience' => 'all', 'pages' => '', 'dismissible' => 1, 'freq' => 'once', 'delay_sec' => 2, 'starts_at' => null, 'ends_at' => null, 'active' => 1];
$a = $a ?: $defaults;
$status = function (array $r): array {
    if (!$r['active']) return ['Desligado', ''];
    if ($r['starts_at'] && strtotime($r['starts_at']) > time()) return ['Agendado', 'pill-warn'];
    if ($r['ends_at'] && strtotime($r['ends_at']) <= time()) return ['Encerrado', ''];
    return ['No ar', 'pill-ok'];
};
admin_header('Avisos', 'avisos');
?>
<?php if (!$isForm): ?>
<section class="panel">
  <div class="panel-head"><h2><?= icon('flag') ?> Avisos do site</h2><a class="btn btn-red" href="<?= e(url('admin/avisos.php?novo=1')) ?>"><?= icon('plus') ?> Novo aviso</a></div>
  <p class="muted">Banners que aparecem para quem visita o site: uma <b>faixa no topo</b>, uma <b>barra no rodapé</b> ou uma <b>janela no meio da tela</b>. Dá para agendar início e fim, escolher para quem e em quais páginas. Aparece um de cada tipo por vez — o primeiro da lista que estiver no ar.</p>
  <?php if (!$list): ?>
    <div class="empty-ann">
      <p class="muted">Nenhum aviso ainda. Exemplos do que dá para fazer:</p>
      <ul class="muted small"><li>"🎬 Lançamento: Moana 2 já está no ar! [Assistir agora]"</li><li>"⚠️ Manutenção hoje às 23h — o site pode ficar fora por 10 minutos."</li><li>Janela de boas-vindas: "Crie sua conta grátis e salve seus favoritos" só para quem não tem conta.</li></ul>
    </div>
  <?php else: ?>
    <div class="ann-list">
      <?php foreach ($list as $i => $r): [$st, $cls] = $status($r); $ctr = $r['views'] ? round($r['clicks'] / $r['views'] * 100, 1) : 0; ?>
        <div class="ann-item">
          <div class="ann-mini ann-<?= e($r['style']) ?>"><?= e(['top' => '▀', 'bottom' => '▄', 'popup' => '▣'][$r['position']] ?? '') ?></div>
          <div class="ann-item-txt">
            <b><?= e($r['title'] ?: mb_strimwidth($r['message'], 0, 70, '…')) ?></b>
            <small><?= e(ann_positions()[$r['position']] ?? '') ?> • <?= e(['all' => 'todos', 'users' => 'só registrados', 'guests' => 'só visitantes'][$r['audience']] ?? '') ?> • <?= $r['pages'] === '' ? 'todas as páginas' : e(implode(', ', array_map(fn($p) => explode(' (', ann_pages()[$p] ?? $p)[0], explode(',', $r['pages'])))) ?>
              <?php if ($r['starts_at'] || $r['ends_at']): ?> • <?= $r['starts_at'] ? 'de ' . e(date('d/m H:i', strtotime($r['starts_at']))) : '' ?> <?= $r['ends_at'] ? 'até ' . e(date('d/m H:i', strtotime($r['ends_at']))) : '' ?><?php endif; ?></small>
            <small class="muted"><?= number_format((int)$r['views'], 0, ',', '.') ?> visualizações<?= $r['btn_text'] ? ' • ' . number_format((int)$r['clicks'], 0, ',', '.') . ' cliques (' . str_replace('.', ',', (string)$ctr) . '%)' : '' ?></small>
          </div>
          <span class="pill <?= $cls ?>"><?= $st ?></span>
          <div class="ann-acts">
            <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button name="action" value="up" class="btn-ghost" title="Subir" <?= $i === 0 ? 'disabled' : '' ?>>↑</button><button name="action" value="down" class="btn-ghost" title="Descer" <?= $i === count($list) - 1 ? 'disabled' : '' ?>>↓</button></form>
            <a class="btn btn-sm" href="<?= e(url('admin/avisos.php?edit=' . $r['id'])) ?>">Editar</a>
            <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button name="action" value="toggle" class="btn btn-sm"><?= $r['active'] ? 'Desligar' : 'Ligar' ?></button></form>
            <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button name="action" value="dup" class="btn-ghost" title="Duplicar"><?= icon('plus') ?></button></form>
            <form method="post" data-confirm="Apagar este aviso?"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button name="action" value="delete" class="btn-ghost danger" title="Apagar"><?= icon('trash') ?></button></form>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>
<?php else: $pg = array_filter(explode(',', (string)$a['pages'])); ?>
<form method="post" class="ann-form" id="ann-form">
  <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
  <div class="ann-preview-wrap panel">
    <div class="panel-head"><h2>Prévia</h2><a class="btn btn-sm" href="<?= e(url('admin/avisos.php')) ?>">← Voltar</a></div>
    <div class="ann-stage" id="ann-stage"><div class="ann-stage-site"><div class="ann-stage-top"></div><div class="ann-stage-nav">PIPOCA MAX</div><div class="ann-stage-body"></div><div class="ann-stage-bottom"></div></div></div>
  </div>
  <div class="cols">
    <section class="panel stack">
      <h3 class="sub-title">Conteúdo</h3>
      <label class="field"><span>Título (opcional, em negrito)</span><input name="title" value="<?= e($a['title']) ?>" maxlength="120" placeholder="Ex: Lançamento!" data-ann-live></label>
      <label class="field"><span>Texto do aviso *</span><textarea name="message" rows="3" maxlength="600" placeholder="Ex: Moana 2 já está disponível dublado e legendado." data-ann-live><?= e($a['message']) ?></textarea></label>
      <div class="cols">
        <label class="field"><span>Texto do botão (opcional)</span><input name="btn_text" value="<?= e($a['btn_text']) ?>" maxlength="40" placeholder="Assistir agora" data-ann-live></label>
        <label class="field"><span>Link do botão</span><input name="btn_url" value="<?= e($a['btn_url']) ?>" placeholder="https://… ou /filme/moana-2" data-ann-live></label>
      </div>
      <label class="check"><input type="checkbox" name="new_tab" value="1" <?= $a['new_tab'] ? 'checked' : '' ?>> Abrir o link em outra aba</label>
      <label class="field" data-only="popup"><span>Imagem da janela (opcional)</span><input name="image" value="<?= e($a['image']) ?>" placeholder="https://…/banner.jpg" data-ann-live></label>
    </section>
    <section class="panel stack">
      <h3 class="sub-title">Aparência</h3>
      <div class="ann-pos-pick">
        <?php foreach (ann_positions() as $k => $l): ?>
          <label><input type="radio" name="position" value="<?= $k ?>" <?= $a['position'] === $k ? 'checked' : '' ?> data-ann-live><span><i class="pp pp-<?= $k ?>"></i><?= e(explode(' (', $l)[0]) ?></span></label>
        <?php endforeach; ?>
      </div>
      <div class="ann-colors">
        <?php foreach (ann_styles() as $k => $l): ?>
          <label title="<?= e($l) ?>"><input type="radio" name="style" value="<?= $k ?>" <?= $a['style'] === $k ? 'checked' : '' ?> data-ann-live><span class="ann-sw ann-<?= $k ?>"></span><small><?= e(explode(' (', $l)[0]) ?></small></label>
        <?php endforeach; ?>
      </div>
      <label class="toggle-row"><div><b>Pode fechar (X)</b><small>A pessoa fecha e não vê mais este aviso (se você editar o texto, ele volta).</small></div><input type="checkbox" name="dismissible" value="1" <?= $a['dismissible'] ? 'checked' : '' ?> data-ann-live><span class="switch-ui"></span></label>
      <div class="cols" data-only="popup">
        <label class="field"><span>Mostrar a janela</span><select name="freq"><option value="once" <?= $a['freq'] === 'once' ? 'selected' : '' ?>>Só uma vez por pessoa</option><option value="session" <?= $a['freq'] === 'session' ? 'selected' : '' ?>>Uma vez por visita</option><option value="always" <?= $a['freq'] === 'always' ? 'selected' : '' ?>>Em toda página</option></select></label>
        <label class="field"><span>Abrir depois de (segundos)</span><input type="number" name="delay_sec" min="0" max="60" value="<?= (int)$a['delay_sec'] ?>"></label>
      </div>

      <h3 class="sub-title">Onde e quando</h3>
      <label class="field"><span>Para quem</span><select name="audience"><option value="all" <?= $a['audience'] === 'all' ? 'selected' : '' ?>>Todos</option><option value="guests" <?= $a['audience'] === 'guests' ? 'selected' : '' ?>>Só visitantes (sem conta)</option><option value="users" <?= $a['audience'] === 'users' ? 'selected' : '' ?>>Só usuários registrados</option></select></label>
      <div class="ann-pages-field"><span class="ann-label">Em quais páginas</span>
        <label class="ann-chk"><input type="checkbox" name="pages_any" value="1" <?= !$pg ? 'checked' : '' ?> data-pages-any> <b>Todas as páginas</b></label>
        <div class="ann-pages" <?= !$pg ? 'hidden' : '' ?>>
          <?php foreach (ann_pages() as $k => $l): ?><label class="ann-chk"><input type="checkbox" name="pages[]" value="<?= $k ?>" <?= in_array($k, $pg, true) ? 'checked' : '' ?>> <?= e($l) ?></label><?php endforeach; ?>
        </div>
      </div>
      <div class="cols">
        <label class="field"><span>Começa em (opcional)</span><input type="datetime-local" name="starts_at" value="<?= $a['starts_at'] ? e(date('Y-m-d\TH:i', strtotime($a['starts_at']))) : '' ?>"></label>
        <label class="field"><span>Termina em (opcional)</span><input type="datetime-local" name="ends_at" value="<?= $a['ends_at'] ? e(date('Y-m-d\TH:i', strtotime($a['ends_at']))) : '' ?>"></label>
      </div>
      <p class="muted small">Horário do servidor agora: <?= e(date('d/m/Y H:i')) ?>.</p>
      <label class="toggle-row"><div><b>Aviso ligado</b><small>Desligue para guardar o aviso sem mostrar.</small></div><input type="checkbox" name="active" value="1" <?= $a['active'] ? 'checked' : '' ?>><span class="switch-ui"></span></label>
    </section>
  </div>
  <div class="seo-save"><button class="btn btn-red btn-lg"><?= icon('check') ?> <?= $a['id'] ? 'Salvar aviso' : 'Publicar aviso' ?></button> <a class="btn btn-lg" href="<?= e(url('admin/avisos.php')) ?>">Cancelar</a></div>
</form>
<?php endif; ?>
<?php admin_footer();
