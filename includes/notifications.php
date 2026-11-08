<?php
/**
 * Sininho de notificações (avisos do administrador).
 * audience: all (todos) | users (só quem tem conta) | guests (só visitantes)
 */

function notif_list(bool $logged, int $limit = 20): array
{
    $aud = $logged ? 'users' : 'guests';
    return cache_remember('notif_' . $aud, 300, function () use ($aud, $limit) {
        try {
            return q_all("SELECT id, title, message, link, image, created_at FROM notifications
                WHERE active = 1 AND audience IN ('all', ?) AND (expires_at IS NULL OR expires_at > NOW())
                ORDER BY id DESC LIMIT " . (int)$limit, [$aud]);
        } catch (Throwable $e) {
            return [];
        }
    });
}

function notif_create(array $d): int
{
    $aud = in_array($d['audience'] ?? 'all', ['all', 'users', 'guests'], true) ? $d['audience'] : 'all';
    $days = (int)($d['days'] ?? 0);
    q('INSERT INTO notifications (title, message, link, image, content_id, audience, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?)', [
        mb_substr(trim((string)$d['title']), 0, 150),
        trim((string)($d['message'] ?? '')) ?: null,
        clean_url((string)($d['link'] ?? '')) ?: null,
        clean_url((string)($d['image'] ?? '')) ?: null,
        ($d['content_id'] ?? null) ?: null,
        $aud,
        $days > 0 ? date('Y-m-d H:i:s', time() + $days * 86400) : null,
    ]);
    cache_bump();
    return (int)db()->lastInsertId();
}

/** Sininho do topo do site */
function notif_bell(?array $user): string
{
    $list = notif_list((bool)$user);
    $seen = $user ? (int)($user['notif_seen'] ?? 0) : -1; // -1 = visitante (usa o navegador)
    ob_start(); ?>
    <div class="bell" data-seen="<?= $seen ?>" data-last="<?= $list ? (int)$list[0]['id'] : 0 ?>">
      <button type="button" class="bell-btn" aria-label="Notificações"><?= icon('bell') ?><span class="bell-count" hidden></span></button>
      <div class="bell-drop" role="menu">
        <div class="bell-head">Notificações</div>
        <?php if (!$list): ?>
          <p class="bell-empty">Nenhuma notificação por enquanto.</p>
        <?php else: ?>
          <ul class="bell-list">
            <?php foreach ($list as $n): $tag = $n['link'] ? 'a' : 'div'; ?>
              <li data-id="<?= (int)$n['id'] ?>"><<?= $tag ?> class="bell-item"<?= $n['link'] ? ' href="' . e($n['link']) . '"' : '' ?>>
                <?php if ($n['image']): ?><img src="<?= e($n['image']) ?>" alt="" loading="lazy"><?php else: ?><span class="bell-ico"><?= icon('bell') ?></span><?php endif; ?>
                <span class="bell-txt"><b><?= e($n['title']) ?></b><?php if ($n['message']): ?><span><?= nl2br(e($n['message'])) ?></span><?php endif; ?><small><?= e(time_ago($n['created_at'])) ?></small></span>
              </<?= $tag ?>></li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    </div>
    <?php return ob_get_clean();
}
