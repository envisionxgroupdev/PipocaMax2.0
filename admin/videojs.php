<?php
require __DIR__ . '/_init.php';

if (is_post()) {
    csrf_check();
    $d = vjs_defaults();
    $c = [];
    foreach (['rates', 'resume', 'next', 'doubletap', 'pip', 'autoplay', 'poster'] as $k) $c[$k] = !empty($_POST[$k]) ? 1 : 0;
    $color = in_str('color');
    $c['color'] = preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? strtolower($color) : $d['color'];
    $c['skip'] = in_array(in_int('skip'), [0, 5, 10, 15, 30], true) ? in_int('skip') : 10;
    $c['next_seconds'] = max(3, min(30, in_int('next_seconds', 8)));
    $c['wm_text'] = mb_substr(in_str('wm_text'), 0, 40);
    $c['wm_img'] = clean_url(in_str('wm_img'), true);
    $c['wm_pos'] = in_array(in_str('wm_pos'), ['tr', 'tl', 'br', 'bl'], true) ? in_str('wm_pos') : 'tr';
    $c['wm_opacity'] = max(10, min(100, in_int('wm_opacity', 60)));
    $c['error_msg'] = mb_substr(in_str('error_msg'), 0, 160);
    set_setting('vjs_config', json_encode($c, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    flash('success', 'Configurações do player salvas.');
    redirect('admin/videojs.php');
}

$cfg = vjs_config();
$nUse = (int)q_val("SELECT COUNT(*) FROM players WHERE kind = 'videojs'") + (int)q_val("SELECT COUNT(*) FROM channels WHERE kind = 'videojs'");
$tog = function (string $k, string $title, string $help) use ($cfg) {
    echo '<label class="toggle-row"><div><b>' . e($title) . '</b><small>' . e($help) . '</small></div><input type="checkbox" name="' . $k . '" value="1"' . (!empty($cfg[$k]) ? ' checked' : '') . '><span class="switch-ui"></span></label>';
};
admin_header('Player próprio', 'videojs');
?>
<section class="panel">
  <div class="panel-head"><h2><?= icon('play') ?> Player próprio (Video.js)</h2></div>
  <p class="muted">Este é o seu player, feito com o <a href="https://videojs.org" target="_blank" rel="noopener">Video.js</a> e com a cara do Pipoca Max. Ele toca links diretos de vídeo (<code>.mp4</code>) e streaming (<code>.m3u8</code>).
    <b>Os players que você já tem não mudam.</b> Para usar este, adicione um player e escolha o tipo <b>"Player próprio (Video.js)"</b> — em filmes, episódios ou canais de TV.</p>
  <p class="muted small">Players usando o player próprio agora: <b><?= $nUse ?></b></p>
</section>

<div class="cols vjs-cols">
<form method="post" class="panel stack">
  <?= csrf_field() ?>
  <h3 class="sub-title">Aparência</h3>
  <div class="cols">
    <label class="field"><span>Cor principal</span><input type="color" name="color" value="<?= e($cfg['color']) ?>" data-vjs-live></label>
    <label class="field"><span>Botões de avançar/voltar</span>
      <select name="skip" data-vjs-live><?php foreach ([0 => 'Não mostrar', 5 => '5 segundos', 10 => '10 segundos', 15 => '15 segundos', 30 => '30 segundos'] as $v => $l): ?><option value="<?= $v ?>" <?= (int)$cfg['skip'] === $v ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></label>
  </div>
  <?php $tog('poster', 'Mostrar a capa do filme antes de tocar', 'Usa a imagem de fundo do título como capa do player.'); ?>

  <h3 class="sub-title">Funções</h3>
  <?php
  $tog('autoplay', 'Começar a tocar sozinho', 'Ao clicar no player, o vídeo já começa (alguns celulares podem pedir um toque a mais).');
  $tog('resume', 'Continuar de onde parou', 'Guarda no navegador onde a pessoa parou e volta ali na próxima vez.');
  $tog('next', 'Próximo episódio automático', 'Ao acabar um episódio, mostra a contagem e passa para o próximo.');
  ?>
  <label class="field"><span>Segundos até o próximo episódio</span><input type="number" name="next_seconds" min="3" max="30" value="<?= (int)$cfg['next_seconds'] ?>" class="w-num"></label>
  <?php
  $tog('rates', 'Velocidade (0,5x a 2x)', 'Botão para assistir mais rápido ou mais devagar.');
  $tog('pip', 'Picture-in-Picture', 'Botão para assistir numa janelinha enquanto usa outras coisas.');
  $tog('doubletap', 'Dois toques no celular', 'Dois toques no lado direito avançam, no lado esquerdo voltam.');
  ?>

  <h3 class="sub-title">Marca d'água</h3>
  <div class="cols">
    <label class="field"><span>Texto</span><input name="wm_text" value="<?= e($cfg['wm_text']) ?>" maxlength="40" placeholder="Ex: PIPOCA MAX" data-vjs-live></label>
    <label class="field"><span>ou imagem (logo .png)</span><input name="wm_img" value="<?= e($cfg['wm_img']) ?>" placeholder="https://…/logo.png" data-vjs-live></label>
  </div>
  <div class="cols">
    <label class="field"><span>Posição</span><select name="wm_pos" data-vjs-live><?php foreach (['tr' => 'Canto superior direito', 'tl' => 'Canto superior esquerdo', 'br' => 'Canto inferior direito', 'bl' => 'Canto inferior esquerdo'] as $v => $l): ?><option value="<?= $v ?>" <?= $cfg['wm_pos'] === $v ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></label>
    <label class="field"><span>Transparência (<span data-op><?= (int)$cfg['wm_opacity'] ?></span>%)</span><input type="range" name="wm_opacity" min="10" max="100" step="5" value="<?= (int)$cfg['wm_opacity'] ?>" data-vjs-live></label>
  </div>

  <h3 class="sub-title">Mensagem de erro</h3>
  <label class="field"><span>Quando o vídeo não carregar</span><input name="error_msg" value="<?= e($cfg['error_msg']) ?>" maxlength="160" placeholder="Não foi possível carregar este vídeo. Tente outro player."></label>

  <button class="btn btn-red btn-lg"><?= icon('check') ?> Salvar</button>
</form>

<section class="panel">
  <div class="panel-head"><h2>Testar o player</h2></div>
  <p class="muted small">Cole um link <code>.mp4</code> ou <code>.m3u8</code> para ver como fica. A prévia usa as opções ao lado, mesmo antes de salvar.</p>
  <div class="form-row">
    <input id="vjs-test-url" class="grow" placeholder="https://…/video.mp4">
    <button type="button" class="btn btn-red" id="vjs-test"><?= icon('play') ?> Testar</button>
  </div>
  <div class="player vjs-preview" data-player-box id="vjs-box"><div class="player-msg">A prévia aparece aqui.</div></div>
</section>
</div>
<script>window.PM = window.PM || {}; PM.base = <?= json_encode(BASE_URL) ?>; PM.vjs = <?= json_encode($cfg, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;</script>
<script src="<?= e(url('assets/js/pmplayer.js')) ?>?v=<?= e($cfg['v']) ?>" defer></script>
<?php admin_footer();
