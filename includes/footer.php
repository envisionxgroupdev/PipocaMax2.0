<?php $active = $active ?? ''; ?>
<?= ad_slot('footer') ?>
</main>
<footer class="footer">
  <div class="container">
    <?= logo_html('logo logo-sm') ?>
    <nav class="footer-links">
      <a href="<?= e(page_url('filmes')) ?>">Filmes</a>
      <a href="<?= e(page_url('series')) ?>">Séries</a>
      <a href="<?= e(page_url('animes')) ?>">Animes</a>
      <a href="<?= e(page_url('tv')) ?>">TV Online</a>
      <?php if (!current_user()): ?><a href="<?= e(page_url('cadastro')) ?>">Criar conta</a><?php endif; ?>
    </nav>
    <p class="footer-text"><?= e(setting('footer_text', '© ' . site_name())) ?></p>
  </div>
</footer>

<nav class="bottomnav" aria-label="Navegação">
  <a href="<?= e(url('')) ?>" class="<?= $active === 'home' ? 'active' : '' ?>"><?= icon('home') ?><span>Início</span></a>
  <a href="<?= e(page_url('filmes')) ?>" class="<?= $active === 'movie' ? 'active' : '' ?>"><?= icon('film') ?><span>Filmes</span></a>
  <a href="<?= e(page_url('series')) ?>" class="<?= $active === 'series' ? 'active' : '' ?>"><?= icon('series') ?><span>Séries</span></a>
  <a href="<?= e(page_url('animes')) ?>" class="<?= $active === 'anime' ? 'active' : '' ?>"><?= icon('anime') ?><span>Animes</span></a>
  <a href="<?= e(page_url('tv')) ?>" class="<?= $active === 'tv' ? 'active' : '' ?>"><?= icon('live') ?><span>TV</span></a>
</nav>

<script>window.PM = { base: <?= json_encode(BASE_URL) ?>, csrf: <?= json_encode(csrf_token()) ?>, logged: <?= current_user() ? 'true' : 'false' ?>, vjs: <?= json_encode(vjs_config(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?> };</script>
<script src="<?= e(asset('js/app.js')) ?>" defer></script>
<?= custom_code('footer') ?>
</body>
</html>
