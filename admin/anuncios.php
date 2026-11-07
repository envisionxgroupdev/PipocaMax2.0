<?php
require __DIR__ . '/_init.php';

if (is_post()) {
    csrf_check();
    foreach (['head', 'body', 'footer'] as $w) {
        set_setting('code_' . $w, (string)($_POST['code_' . $w] ?? ''));
        set_setting('code_' . $w . '_on', isset($_POST['code_' . $w . '_on']) ? '1' : '0');
    }
    foreach (array_keys(ad_slots()) as $k) {
        set_setting('ad_' . $k, (string)($_POST['ad_' . $k] ?? ''));
        set_setting('ad_' . $k . '_on', isset($_POST['ad_' . $k . '_on']) ? '1' : '0');
        $dev = in_str('ad_' . $k . '_dev');
        set_setting('ad_' . $k . '_dev', in_array($dev, ['all', 'desktop', 'mobile'], true) ? $dev : 'all');
    }
    set_setting('ads_hide_admin', isset($_POST['ads_hide_admin']) ? '1' : '0');
    set_setting('code_skip_admin', isset($_POST['code_skip_admin']) ? '1' : '0');
    flash('success', 'Anúncios e códigos salvos.');
    redirect('admin/anuncios.php');
}

$activeAds = 0;
foreach (array_keys(ad_slots()) as $k) if (setting('ad_' . $k . '_on') === '1' && trim((string)setting('ad_' . $k)) !== '') $activeAds++;

admin_header('Anúncios e códigos', 'anuncios');
?>
<form method="post" class="ads-page">
  <?= csrf_field() ?>

  <section class="panel">
    <div class="panel-head"><h2><?= icon('database') ?> Códigos do site</h2><span class="muted small">Analytics, AdSense, pixel, verificações, chat…</span></div>
    <p class="muted small">Cole aqui códigos que precisam ir em todas as páginas do site. Eles não aparecem no painel administrativo.</p>
    <?php foreach (['head' => ['Dentro do &lt;head&gt;', 'Para códigos que pedem "cole antes de &lt;/head&gt;": AdSense, Google Analytics, Meta Pixel, verificação de domínio.'],
                    'body' => ['Logo após abrir o &lt;body&gt;', 'Para códigos que pedem "cole logo depois de &lt;body&gt;", como o Google Tag Manager (noscript).'],
                    'footer' => ['Antes de fechar o &lt;/body&gt; (rodapé)', 'Para scripts que devem carregar por último: pop-under, chat, banners flutuantes.']] as $w => [$label, $help]): ?>
      <div class="code-box">
        <div class="code-head">
          <div><b><?= $label ?></b><small><?= $help ?></small></div>
          <label class="mini-toggle"><input type="checkbox" name="code_<?= $w ?>_on" value="1" <?= setting('code_' . $w . '_on', '1') === '1' ? 'checked' : '' ?>><span class="switch-ui"></span></label>
        </div>
        <textarea name="code_<?= $w ?>" rows="5" spellcheck="false" placeholder="<script>…</script>"><?= e(setting('code_' . $w)) ?></textarea>
      </div>
    <?php endforeach; ?>
    <label class="check"><input type="checkbox" name="code_skip_admin" value="1" <?= setting('code_skip_admin') === '1' ? 'checked' : '' ?>> Não carregar esses códigos quando eu (administrador) estiver navegando no site</label>
  </section>

  <section class="panel">
    <div class="panel-head"><h2><?= icon('grid') ?> Espaços de anúncio</h2><span class="pill <?= $activeAds ? 'pill-ok' : '' ?>"><?= $activeAds ?> ativo(s)</span></div>
    <p class="muted small">Cole o código do banner (HTML ou script da sua rede de anúncios) no espaço onde quer que ele apareça. Ligue a chave para ativar.</p>
    <div class="slots">
      <?php foreach (ad_slots() as $k => [$label, $help]): $on = setting('ad_' . $k . '_on') === '1'; $dev = setting('ad_' . $k . '_dev', 'all'); ?>
        <div class="slot<?= $on ? ' on' : '' ?>">
          <div class="code-head">
            <div><b><?= e($label) ?></b><small><?= e($help) ?></small></div>
            <label class="mini-toggle"><input type="checkbox" name="ad_<?= $k ?>_on" value="1" <?= $on ? 'checked' : '' ?>><span class="switch-ui"></span></label>
          </div>
          <textarea name="ad_<?= $k ?>" rows="4" spellcheck="false" placeholder="Código do anúncio…"><?= e(setting('ad_' . $k)) ?></textarea>
          <div class="seg small">
            <?php foreach (['all' => 'PC e celular', 'desktop' => 'Só PC', 'mobile' => 'Só celular'] as $dk => $dl): ?>
              <label><input type="radio" name="ad_<?= $k ?>_dev" value="<?= $dk ?>" <?= $dev === $dk ? 'checked' : '' ?>><span><?= $dl ?></span></label>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <label class="check" style="margin-top:14px"><input type="checkbox" name="ads_hide_admin" value="1" <?= setting('ads_hide_admin', '1') === '1' ? 'checked' : '' ?>> Esconder os anúncios quando eu (administrador) estiver logado <small class="muted">(evita cliques inválidos)</small></label>
  </section>

  <div class="save-bar"><button class="btn btn-red btn-lg">Salvar anúncios e códigos</button></div>
</form>
<?php admin_footer(); ?>
