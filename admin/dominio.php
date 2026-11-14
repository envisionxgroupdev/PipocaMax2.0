<?php
require __DIR__ . '/_init.php';

$host = domain_current_host();
$httpsNow = domain_request_https();
if (is_post()) {
    csrf_check();
    $action = in_str('action');
    try {
        switch ($action) {
            case 'save':
                $new = domain_clean(in_str('domain_main'));
                if (in_str('domain_main') !== '' && $new === '') throw new RuntimeException('Domínio inválido. Digite só o domínio, ex.: pipocamax.com');
                $prev = domain_clean((string)setting('domain_main'));
                $old = array_values(array_unique(array_filter(array_map('domain_clean', preg_split('/\R/', (string)($_POST['domain_old'] ?? ''))), fn($d) => $d !== '' && $d !== $new)));
                // o domínio que estava ativo vira "antigo" automaticamente
                if ($prev !== '' && $prev !== $new && setting('domain_active') === '1' && !in_array($prev, $old, true)) $old[] = $prev;
                set_setting('domain_old', implode("\n", $old));
                set_setting('domain_https', isset($_POST['domain_https']) ? '1' : '0');
                if ($new !== $prev) {
                    set_setting('domain_main', $new);
                    // ativa direto só se você já está usando o domínio novo (prova que ele abre este site)
                    set_setting('domain_active', $new !== '' && $host === $new ? '1' : '0');
                }
                flash('success', $new === '' ? 'Domínio oficial removido: o site usa o endereço por onde for acessado.' : (setting('domain_active') === '1' ? "Salvo. O domínio oficial é $new." : "Salvo. Agora abra o painel pelo domínio novo para ativar (passo 2)."));
                break;
            case 'activate':
                $main = domain_clean((string)setting('domain_main'));
                if ($main === '' || $host !== $main) throw new RuntimeException("Para ativar, abra este painel pelo endereço https://$main/admin/dominio.php");
                if (setting('domain_https', '1') === '1' && !$httpsNow) throw new RuntimeException('O HTTPS ainda não está funcionando neste domínio. Ative o SSL na Hostinger, ou desligue "Forçar HTTPS" e ative de novo.');
                set_setting('domain_active', '1');
                cache_bump();
                flash('success', "Pronto! $main é o domínio oficial. Os outros endereços agora redirecionam para ele (301).");
                break;
            case 'deactivate':
                set_setting('domain_active', '0');
                cache_bump();
                flash('success', 'Redirecionamento desligado. O site responde em qualquer domínio que aponte para ele.');
                break;
            case 'links_check':
            case 'links_apply':
                $old = domain_clean(in_str('old')); $new = domain_clean(in_str('new'));
                if ($old === '' || $new === '' || $old === $new) throw new RuntimeException('Informe o domínio antigo e o novo (diferentes).');
                $res = domain_replace_links($old, $new, $action === 'links_apply');
                $total = array_sum(array_column($res, 'n'));
                $_SESSION['domain_links'] = ['old' => $old, 'new' => $new, 'res' => $res, 'applied' => $action === 'links_apply'];
                flash($total ? 'success' : 'info', $action === 'links_apply' ? "Links trocados: $total registro(s) agora usam $new." : ($total ? "Encontrei $total registro(s) com $old. Confira e clique em Trocar." : "Nenhum link com $old salvo no banco."));
                redirect('admin/dominio.php#links');
        }
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }
    redirect('admin/dominio.php');
}

$main = domain_clean((string)setting('domain_main'));
$active = domain_main() !== '';
$killed = is_file(DOMAIN_KILL_FILE);
$olds = domain_old_list();
$links = $_SESSION['domain_links'] ?? null;
unset($_SESSION['domain_links']);
$suggestOld = $olds[0] ?? ($main !== '' && $host !== $main ? $host : '');
admin_header('Domínio', 'dominio');
?>
<section class="panel dom-status">
  <div class="dom-cards">
    <div><small>Você está usando agora</small><b><?= $httpsNow ? 'https' : 'http' ?>://<?= e($host) ?></b></div>
    <div><small>Domínio oficial</small><b><?= $main !== '' ? e(domain_scheme() . '://' . $main) : '— (usa o endereço acessado)' ?></b>
      <?php if ($main !== ''): ?><span class="pill <?= $active ? 'pill-ok' : 'pill-warn' ?>"><?= $active ? 'ativo' : ($killed ? 'desligado pelo arquivo de emergência' : 'aguardando ativação') ?></span><?php endif; ?></div>
    <div><small>Domínios antigos redirecionando</small><b><?= $active ? count($olds) : 0 ?></b></div>
  </div>
  <p class="muted small">Com o domínio oficial ativo, todo o site (links, sitemap, Google, compartilhamentos) usa só ele, e qualquer outro endereço que aponte para a hospedagem — o antigo, com ou sem www, sem HTTPS — vai para o oficial com redirecionamento 301, que passa o posicionamento do Google para o domínio novo.</p>
</section>

<div class="cols dom-cols">
<div class="stack">
  <form method="post" class="panel stack">
    <?= csrf_field() ?><input type="hidden" name="action" value="save">
    <h2><span class="dom-step">1</span> Domínio oficial</h2>
    <label class="field"><span>Domínio novo (sem https://)</span><input name="domain_main" value="<?= e($main) ?>" placeholder="pipocamax.com" autocomplete="off"></label>
    <label class="toggle-row"><div><b>Forçar HTTPS (cadeado)</b><small>Recomendado. Deixe ligado depois que o SSL do domínio estiver ativo na Hostinger.</small></div><input type="checkbox" name="domain_https" value="1" <?= setting('domain_https', '1') === '1' ? 'checked' : '' ?>><span class="switch-ui"></span></label>
    <label class="field"><span>Domínios antigos (um por linha)</span><textarea name="domain_old" rows="3" placeholder="pipocamax.im"><?= e(implode("\n", $olds)) ?></textarea><small class="muted">Quando você trocar, o domínio que estava ativo entra aqui sozinho.</small></label>
    <button class="btn btn-red"><?= icon('check') ?> Salvar</button>
  </form>

  <section class="panel stack" id="ativar">
    <h2><span class="dom-step">2</span> Ativar com segurança</h2>
    <?php if ($main === ''): ?>
      <p class="muted">Salve o domínio oficial no passo 1.</p>
    <?php elseif ($active): ?>
      <p><?= icon('check') ?> <b><?= e($main) ?></b> está ativo. Tudo redireciona para <b><?= e(domain_scheme() . '://' . $main) ?></b>.</p>
      <form method="post" data-confirm="Desligar o redirecionamento para o domínio oficial?"><?= csrf_field() ?><input type="hidden" name="action" value="deactivate"><button class="btn">Desligar redirecionamento</button></form>
    <?php else: ?>
      <p class="muted">Para não correr o risco de o site sair do ar, ele só ativa depois de provar que <b><?= e($main) ?></b> já abre este site.</p>
      <div class="dom-test" data-domain-test data-url="<?= e((setting('domain_https', '1') === '1' ? 'https' : 'http') . '://' . $main . BASE_URL . '/api.php?action=domain_ping') ?>" data-token="<?= e(domain_token()) ?>">
        <button type="button" class="btn"><?= icon('search') ?> Testar o domínio novo</button><span class="muted small" data-out></span>
      </div>
      <?php if ($host === $main): ?>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="activate"><button class="btn btn-red btn-lg"><?= icon('check') ?> Ativar <?= e($main) ?> como oficial</button></form>
      <?php else: ?>
        <p>Quando o teste der certo, abra o painel <b>pelo domínio novo</b> e clique em Ativar:</p>
        <a class="btn btn-red" href="<?= e((setting('domain_https', '1') === '1' ? 'https' : 'http') . '://' . $main . BASE_URL . '/admin/dominio.php#ativar') ?>" target="_blank" rel="noopener"><?= icon('external') ?> Abrir o painel em <?= e($main) ?></a>
        <p class="muted small">Você vai precisar entrar de novo (o login é separado por domínio).</p>
      <?php endif; ?>
    <?php endif; ?>
    <details class="box-details"><summary>Emergência: o site parou de abrir depois da troca</summary>
      <div style="padding:12px 16px" class="muted small">Na Hostinger, abra <b>Gerenciador de Arquivos › public_html › includes</b> e crie um arquivo vazio chamado <code>dominio-desligado.txt</code>. O redirecionamento desliga na hora e o site volta a abrir em qualquer domínio. Depois corrija aqui e apague o arquivo.</div>
    </details>
  </section>

  <form method="post" class="panel stack" id="links">
    <?= csrf_field() ?>
    <h2><span class="dom-step">3</span> Trocar links salvos no banco</h2>
    <p class="muted small">Troca o domínio antigo pelo novo em links e imagens que ficaram gravados (players, imagens enviadas, notificações, configurações). Links de outros sites não são tocados.</p>
    <div class="cols">
      <label class="field"><span>De (antigo)</span><input name="old" value="<?= e($links['old'] ?? $suggestOld) ?>" placeholder="pipocamax.im"></label>
      <label class="field"><span>Para (novo)</span><input name="new" value="<?= e($links['new'] ?? $main) ?>" placeholder="pipocamax.com"></label>
    </div>
    <?php if ($links && $links['res']): ?>
      <ul class="seo-checks"><?php foreach ($links['res'] as $r): ?><li class="<?= $links['applied'] ? 'ok' : 'no' ?>"><span><?= $links['applied'] ? icon('check') : '!' ?></span><?= e($r['label']) ?>: <b><?= (int)$r['n'] ?></b></li><?php endforeach; ?></ul>
    <?php endif; ?>
    <div class="btn-row">
      <button class="btn" name="action" value="links_check"><?= icon('search') ?> Verificar</button>
      <button class="btn btn-red" name="action" value="links_apply" data-confirm-click="Trocar os links do domínio antigo pelo novo no banco?"><?= icon('check') ?> Trocar</button>
    </div>
  </form>
</div>

<section class="panel" id="lista">
  <h2><span class="dom-step">4</span> Lista da troca de domínio</h2>
  <p class="muted small">O que fazer fora do site. Marque conforme for fazendo (fica salvo neste navegador).</p>
  <ol class="dom-list" data-dom-list>
    <?php foreach ([
      ['Registrar o domínio novo e apontar para a Hostinger', 'Na Hostinger: <b>Sites › seu site › Domínios</b> e adicione o domínio novo ao mesmo site (ou troque o domínio principal do site).'],
      ['Ativar o SSL (cadeado) do domínio novo', 'Hostinger › <b>Segurança › SSL</b>. Pode levar até algumas horas.'],
      ['Manter o domínio antigo apontando para a hospedagem', 'Não cancele o antigo: deixe por <b>pelo menos 6 a 12 meses</b> para os redirecionamentos 301 passarem os visitantes e o Google para o novo.'],
      ['Testar e ativar aqui (passos 1 e 2)', 'O teste confirma que o domínio novo já abre este site antes de ativar.'],
      ['Trocar os links salvos (passo 3)', 'Clique em Verificar e depois em Trocar.'],
      ['Google Search Console: verificar o domínio novo', 'Adicione o domínio novo como propriedade e cole o código em <a href="' . e(url('admin/seo.php#buscadores')) . '">SEO › Google, Bing e Analytics</a>.'],
      ['Google Search Console: "Mudança de endereço"', 'Na propriedade <b>antiga</b>: Configurações › <b>Mudança de endereço</b> › escolha o domínio novo. É o que avisa o Google oficialmente.'],
      ['Enviar o sitemap novo', 'Em Sitemaps do Google, Bing e Yandex. O link está em <a href="' . e(url('admin/seo.php#sitemap')) . '">SEO › Sitemap</a>.'],
      ['Bing Webmaster: "Mudança de site"', 'Bing Webmaster Tools › Configurar › <b>Mudança de site</b>.'],
      ['Google Analytics: atualizar o endereço', 'Administrador › Fluxos de dados › edite o URL do site.'],
      ['CAPTCHA: incluir o domínio novo', 'Se usa CAPTCHA (<a href="' . e(url('admin/seguranca.php')) . '">Segurança</a>), adicione o domínio novo no painel do Turnstile/reCAPTCHA/hCaptcha.'],
      ['Cloudflare (se usar)', 'Adicione o domínio novo no Cloudflare e aponte os servidores DNS.'],
      ['Divulgação', 'Atualize o link nas redes sociais, grupos, bio e no código de anúncios.'],
    ] as $i => [$t, $d]): ?>
      <li><label><input type="checkbox" data-k="<?= $i ?>"><span><b><?= e($t) ?></b><small><?= $d ?></small></span></label></li>
    <?php endforeach; ?>
  </ol>
  <button type="button" class="btn btn-sm" data-dom-reset>Desmarcar tudo</button>
</section>
</div>
<?php admin_footer();
