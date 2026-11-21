<?php
require __DIR__ . '/_init.php';

$defs = theme_defs();

if (is_post()) {
    csrf_check();
    $key = in_str('theme');
    if (isset($defs[$key])) {
        $mode = in_str('mode');
        set_setting('theme_' . $key . '_mode', in_array($mode, ['off', 'on', 'auto'], true) ? $mode : 'off');
        $md = function (string $p) {
            $d = max(1, min(31, in_int($p . '_d')));
            $m = max(1, min(12, in_int($p . '_m')));
            return sprintf('%02d-%02d', $m, $d);
        };
        set_setting('theme_' . $key . '_from', $md('from'));
        set_setting('theme_' . $key . '_to', $md('to'));
        $opts = [];
        foreach (array_keys($defs[$key]['opts']) as $o) $opts[$o] = isset($_POST['opt_' . $o]) ? '1' : '0';
        set_setting('theme_' . $key . '_opts', json_encode($opts));
        // Só um tema ligado por vez
        if ($mode === 'on') {
            foreach (array_keys($defs) as $k) if ($k !== $key && theme_mode($k) === 'on') set_setting('theme_' . $k . '_mode', 'off');
        }
        if (function_exists('cache_bump')) cache_bump();
        flash('success', 'Tema ' . $defs[$key]['name'] . ' salvo.');
    }
    redirect('admin/temas.php');
}

$months = ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];
$active = theme_active();

admin_header('Temas', 'temas');
?>
<p class="muted">Enfeite o site em datas especiais. O tema muda só a aparência: nada do conteúdo ou dos players é alterado, e dá para desligar a qualquer momento.</p>

<?php foreach ($defs as $key => $d): $mode = theme_mode($key); [$from, $to] = theme_dates($key); $opts = theme_opts($key);
  [$fm, $fd] = array_map('intval', explode('-', $from)); [$tm, $td] = array_map('intval', explode('-', $to)); ?>
<form method="post" class="panel theme-box">
  <?= csrf_field() ?>
  <input type="hidden" name="theme" value="<?= e($key) ?>">
  <div class="theme-head">
    <span class="theme-emoji"><?= $d['emoji'] ?></span>
    <div>
      <h2><?= e($d['name']) ?>
        <?php if ($active === $key): ?><span class="pill pill-ok">No ar agora</span>
        <?php elseif ($mode === 'auto'): ?><span class="pill">Liga sozinho dia <?= $fd ?>/<?= $months[$fm - 1] ?></span>
        <?php else: ?><span class="pill">Desligado</span><?php endif; ?>
      </h2>
      <p class="muted small"><?= e($d['desc']) ?></p>
    </div>
    <a class="btn btn-sm" href="<?= e(url('?tema=' . $key)) ?>" target="_blank" rel="noopener"><?= icon('external') ?> Ver como fica</a>
  </div>

  <div class="theme-modes">
    <?php foreach (['off' => ['Desligado', 'O site fica normal.'], 'on' => ['Ligado', 'Fica no ar até você desligar.'], 'auto' => ['Automático', 'Liga e desliga sozinho nas datas abaixo, todo ano.']] as $m => [$lbl, $sub]): ?>
      <label class="theme-mode<?= $mode === $m ? ' on' : '' ?>">
        <input type="radio" name="mode" value="<?= $m ?>" <?= $mode === $m ? 'checked' : '' ?>>
        <b><?= $lbl ?></b><small><?= $sub ?></small>
      </label>
    <?php endforeach; ?>
  </div>

  <div class="theme-dates" data-theme-dates>
    <span>De</span>
    <select name="from_d" aria-label="Dia inicial"><?php for ($i = 1; $i <= 31; $i++): ?><option <?= $i === $fd ? 'selected' : '' ?>><?= $i ?></option><?php endfor; ?></select>
    <select name="from_m" aria-label="Mês inicial"><?php foreach ($months as $i => $mn): ?><option value="<?= $i + 1 ?>" <?= $i + 1 === $fm ? 'selected' : '' ?>><?= $mn ?></option><?php endforeach; ?></select>
    <span>até</span>
    <select name="to_d" aria-label="Dia final"><?php for ($i = 1; $i <= 31; $i++): ?><option <?= $i === $td ? 'selected' : '' ?>><?= $i ?></option><?php endfor; ?></select>
    <select name="to_m" aria-label="Mês final"><?php foreach ($months as $i => $mn): ?><option value="<?= $i + 1 ?>" <?= $i + 1 === $tm ? 'selected' : '' ?>><?= $mn ?></option><?php endforeach; ?></select>
  </div>

  <h3 class="sub-title">O que aparece</h3>
  <div class="rb-list">
    <?php foreach ($d['opts'] as $o => [$lbl, $sub]): ?>
      <label class="toggle-row rb-row">
        <div><b><?= e($lbl) ?></b><small><?= e($sub) ?></small></div>
        <input type="checkbox" name="opt_<?= $o ?>" value="1" <?= $opts[$o] ? 'checked' : '' ?>><span class="switch-ui"></span>
      </label>
    <?php endforeach; ?>
  </div>
  <p class="muted small">Para colocar uma mensagem tipo “Feliz Natal! 🎄”, use <a href="<?= e(url('admin/avisos.php')) ?>">Avisos (banners)</a>.</p>
  <div class="seo-save"><button class="btn btn-red btn-lg"><?= icon('check') ?> Salvar</button></div>
</form>
<?php endforeach; ?>
<?php admin_footer(); ?>
