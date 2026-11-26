<?php
require __DIR__ . '/_init.php';

if (is_post()) {
    csrf_check();
    $u = trim(in_str('telegram_url'));
    if ($u !== '' && preg_match('~^@?([A-Za-z0-9_]{4,})$~', $u, $m)) $u = 'https://t.me/' . $m[1]; // aceita só "@PipocaMax"
    if ($u !== '' && !preg_match('~^https://(t\.me|telegram\.me)/[A-Za-z0-9_+/-]+$~', $u)) {
        flash('error', 'Link inválido. Use o link do grupo, por exemplo https://t.me/PipocaMax');
        redirect('admin/telegram.php');
    }
    set_setting('telegram_url', $u);
    foreach (array_keys(tg_places()) as $p) set_setting('telegram_' . $p, isset($_POST['place_' . $p]) ? '1' : '0');
    foreach (['title', 'sub', 'btn'] as $k) set_setting('telegram_' . $k, mb_substr(trim(in_str('telegram_' . $k)), 0, $k === 'sub' ? 160 : 60));
    flash('success', 'Telegram salvo.');
    redirect('admin/telegram.php');
}

admin_header('Telegram', 'telegram');
?>
<form method="post" class="panel">
  <?= csrf_field() ?>
  <p class="muted">Convites para o seu grupo do Telegram espalhados pelo site, do jeito que os sites grandes fazem. Deixe o link vazio para tirar tudo.</p>
  <label class="field" style="max-width:520px"><span>Link do grupo</span>
    <input type="text" name="telegram_url" value="<?= e(setting('telegram_url', TG_DEFAULT_URL)) ?>" placeholder="https://t.me/PipocaMax">
  </label>
  <?php if (tg_url()): ?><p class="small"><a href="<?= e(tg_url()) ?>" target="_blank" rel="noopener"><?= icon('external') ?> Testar o link</a></p><?php endif; ?>

  <h3 class="sub-title">Onde aparece</h3>
  <div class="rb-list">
    <?php foreach (tg_places() as $p => [$lbl, $sub, $def]): ?>
      <label class="toggle-row rb-row">
        <div><b><?= e($lbl) ?></b><small><?= e($sub) ?></small></div>
        <input type="checkbox" name="place_<?= $p ?>" value="1" <?= setting('telegram_' . $p, $def) === '1' ? 'checked' : '' ?>><span class="switch-ui"></span>
      </label>
    <?php endforeach; ?>
  </div>

  <h3 class="sub-title">Texto da faixa da página inicial</h3>
  <div class="form-grid" style="grid-template-columns:repeat(auto-fit,minmax(220px,1fr))">
    <label class="field"><span>Título</span><input type="text" name="telegram_title" maxlength="60" value="<?= e(setting('telegram_title', '')) ?>" placeholder="<?= e('Entre no nosso grupo do Telegram') ?>"></label>
    <label class="field"><span>Texto do botão</span><input type="text" name="telegram_btn" maxlength="60" value="<?= e(setting('telegram_btn', '')) ?>" placeholder="Entrar no grupo"></label>
  </div>
  <label class="field"><span>Frase</span><input type="text" name="telegram_sub" maxlength="160" value="<?= e(setting('telegram_sub', '')) ?>" placeholder="Lançamentos, novidades e avisos do site em primeira mão. É grátis!"></label>
  <p class="muted small">Deixe vazio para usar o texto padrão (o que aparece em cinza).</p>
  <div class="seo-save"><button class="btn btn-red btn-lg"><?= icon('check') ?> Salvar</button></div>
</form>
<?php admin_footer(); ?>
