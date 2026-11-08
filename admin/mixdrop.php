<?php
require __DIR__ . '/_init.php';

if (is_post()) {
    csrf_check();
    $action = in_str('action');
    try {
        if ($action === 'config') {
            set_setting('mixdrop_email', trim(in_str('email')));
            if (in_str('key') !== '' && !str_contains(in_str('key'), '•')) set_setting('mixdrop_key', trim(in_str('key')));
            $api = clean_url(in_str('api')) ?: 'https://api.mixdrop.ag';
            set_setting('mixdrop_api', rtrim($api, '/'));
            set_setting('mixdrop_player_name', mb_substr(in_str('player_name'), 0, 40) ?: 'MixDrop');
            set_setting('mixdrop_audio', mb_substr(in_str('audio'), 0, 30));
            if (in_str('test') === '1') {
                mixdrop_api('folderlist');
                flash('success', 'Conectado ao MixDrop com sucesso!');
            } else {
                flash('success', 'Configurações do MixDrop salvas.');
            }
        } elseif ($action === 'retry') {
            $j = q_one('SELECT * FROM mixdrop_jobs WHERE id = ?', [in_int('id')]);
            if ($j) {
                q('DELETE FROM mixdrop_jobs WHERE id = ?', [$j['id']]);
                mixdrop_send($j['source_url'], $j['content_id'] ? (int)$j['content_id'] : null, $j['episode_id'] ? (int)$j['episode_id'] : null, (string)$j['audio']);
                flash('success', 'Envio refeito.');
            }
        } elseif ($action === 'delete') {
            q('DELETE FROM mixdrop_jobs WHERE id = ?', [in_int('id')]);
        } elseif ($action === 'clear_done') {
            q("DELETE FROM mixdrop_jobs WHERE status = 'done'");
            flash('success', 'Envios concluídos removidos da lista (os players continuam nos títulos).');
        } elseif ($action === 'check') {
            $n = mixdrop_check(40);
            flash('success', $n ? "$n envio(s) atualizado(s)." : 'Nada mudou desde a última conferência.');
        }
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }
    redirect('admin/mixdrop.php');
}

$st = in_str('st');
$where = '1=1';
$params = [];
if (in_array($st, ['pending', 'done', 'error'], true)) {
    $where = ['pending' => "j.status IN ('tosend','queued','downloading','converting')", 'done' => "j.status = 'done'", 'error' => "j.status = 'error'"][$st];
}
$jobs = q_all("SELECT j.*, c.title, c.type, e.episode_number, s.season_number
               FROM mixdrop_jobs j
               LEFT JOIN contents c ON c.id = j.content_id
               LEFT JOIN episodes e ON e.id = j.episode_id
               LEFT JOIN seasons s ON s.id = e.season_id
               WHERE $where ORDER BY j.id DESC LIMIT 200", $params);
$counts = q_one("SELECT SUM(status IN ('tosend','queued','downloading','converting')) p, SUM(status = 'done') d, SUM(status = 'error') e FROM mixdrop_jobs") ?: [];
$labels = ['tosend' => ['Aguardando envio', ''], 'queued' => ['Na fila do MixDrop', ''], 'downloading' => ['Baixando', 'pill-warn'], 'converting' => ['Convertendo', 'pill-warn'], 'done' => ['Pronto ✓', 'pill-ok'], 'error' => ['Erro', 'pill-red']];

admin_header('MixDrop', 'mixdrop');
?>
<section class="panel">
  <div class="panel-head"><h2><?= icon('download') ?> Conta do MixDrop</h2><span class="pill <?= mixdrop_configured() ? 'pill-ok' : 'pill-warn' ?>"><?= mixdrop_configured() ? 'Configurado' : 'Não configurado' ?></span></div>
  <p class="muted small">Pegue o e-mail e a chave na página <b>API</b> da sua conta MixDrop. Os vídeos são enviados para a pasta “<?= e(site_name()) ?>” e o player é criado sozinho no filme ou episódio quando o vídeo fica pronto.</p>
  <form method="post" class="form-grid">
    <?= csrf_field() ?><input type="hidden" name="action" value="config">
    <label class="field"><span>E-mail da API</span><input name="email" value="<?= e(setting('mixdrop_email')) ?>" autocomplete="off"></label>
    <label class="field"><span>Chave da API</span><input name="key" value="<?= setting('mixdrop_key') ? '••••••••' . e(substr((string)setting('mixdrop_key'), -4)) : '' ?>" autocomplete="off" placeholder="Cole a chave"></label>
    <label class="field"><span>Endereço da API</span><input name="api" value="<?= e(setting('mixdrop_api', 'https://api.mixdrop.ag')) ?>"><small class="muted">Só troque se o MixDrop mudar de domínio.</small></label>
    <label class="field"><span>Nome do player no site</span><input name="player_name" value="<?= e(setting('mixdrop_player_name', 'MixDrop')) ?>"></label>
    <label class="field"><span>Áudio padrão</span><input name="audio" value="<?= e(setting('mixdrop_audio')) ?>" list="md-audios" placeholder="Dublado, Legendado…"></label>
    <div class="btn-row" style="align-self:end"><button class="btn btn-red">Salvar</button><button class="btn" name="test" value="1"><?= icon('check') ?> Salvar e testar</button></div>
    <datalist id="md-audios"><option>Dublado</option><option>Legendado</option><option>Dual Áudio</option></datalist>
  </form>
</section>

<section class="panel" id="envios" data-md-pending="<?= (int)($counts['p'] ?? 0) ?>">
  <div class="panel-head"><h2>Envios</h2>
    <div class="btn-row" style="margin:0">
      <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="check"><button class="btn btn-sm"><?= icon('download') ?> Conferir agora</button></form>
      <?php if (!empty($counts['d'])): ?><form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="clear_done"><button class="btn btn-sm">Limpar concluídos</button></form><?php endif; ?>
    </div>
  </div>
  <p class="muted small">Para enviar, abra um <b>filme</b> ou <b>episódio</b> em Conteúdos e use “Enviar para o MixDrop”. Para séries inteiras, use o envio em massa na página da série. Esta lista se atualiza sozinha enquanto estiver aberta.</p>
  <div class="tabs mb">
    <a class="<?= $st === '' ? 'active' : '' ?>" href="<?= e(url('admin/mixdrop.php')) ?>">Todos</a>
    <a class="<?= $st === 'pending' ? 'active' : '' ?>" href="<?= e(url('admin/mixdrop.php?st=pending')) ?>">Em andamento <small><?= (int)($counts['p'] ?? 0) ?></small></a>
    <a class="<?= $st === 'done' ? 'active' : '' ?>" href="<?= e(url('admin/mixdrop.php?st=done')) ?>">Prontos <small><?= (int)($counts['d'] ?? 0) ?></small></a>
    <a class="<?= $st === 'error' ? 'active' : '' ?>" href="<?= e(url('admin/mixdrop.php?st=error')) ?>">Com erro <small><?= (int)($counts['e'] ?? 0) ?></small></a>
  </div>
  <div class="table-wrap">
  <table class="table">
    <thead><tr><th>Título</th><th>Situação</th><th>Link de origem</th><th>Enviado</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($jobs as $j): [$lb, $cls] = $labels[$j['status']] ?? [$j['status'], '']; ?>
      <tr>
        <td><?php if ($j['title']): ?><a href="<?= e(url($j['episode_id'] ? 'admin/episodio.php?id=' . $j['episode_id'] : 'admin/conteudo.php?id=' . $j['content_id'] . '#players')) ?>"><b><?= e($j['title']) ?></b></a><small class="block muted"><?= $j['episode_id'] ? 'T' . (int)$j['season_number'] . ':E' . (int)$j['episode_number'] : e(type_label((string)$j['type'])) ?></small><?php else: ?><span class="muted">(removido)</span><?php endif; ?></td>
        <td>
          <span class="pill <?= $cls ?>"><?= e($lb) ?><?= $j['status'] === 'downloading' ? ' ' . (int)$j['progress'] . '%' : '' ?></span>
          <?php if ($j['status'] === 'downloading'): ?><div class="mini-progress"><span style="width:<?= (int)$j['progress'] ?>%"></span></div><?php endif; ?>
          <?php if ($j['error']): ?><small class="block <?= $j['status'] === 'error' ? 'tv-err' : 'muted' ?>"><?= e($j['error']) ?></small><?php endif; ?>
        </td>
        <td><small class="ellipsis block"><?= e($j['source_url']) ?></small></td>
        <td class="muted"><?= e(time_ago($j['created_at'])) ?></td>
        <td class="actions">
          <?php if ($j['status'] === 'done' && $j['embed_url']): ?><a class="btn-ghost" href="<?= e($j['embed_url']) ?>" target="_blank" rel="noopener" title="Abrir player"><?= icon('eye') ?></a><?php endif; ?>
          <?php if ($j['status'] === 'error'): ?><form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="retry"><input type="hidden" name="id" value="<?= (int)$j['id'] ?>"><button class="btn btn-sm">Tentar de novo</button></form><?php endif; ?>
          <?= delete_button('', ['action' => 'delete', 'id' => $j['id']], 'Remover da lista', 'Remover este envio da lista? (o vídeo no MixDrop e o player não são apagados)') ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$jobs): ?><tr><td colspan="5" class="empty-cell">Nenhum envio ainda.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</section>
<?php admin_footer(); ?>
