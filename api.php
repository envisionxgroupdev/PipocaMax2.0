<?php
/**
 * Endpoints AJAX do site público.
 */
require __DIR__ . '/includes/bootstrap.php';

$action = in_str('action');

if (setting('maintenance') === '1' && !is_admin()) {
    json_out(['error' => 'Site em manutenção.'], 503);
}

switch ($action) {
    case 'episode':
        if (setting('require_login') === '1' && !current_user()) {
            json_out(['error' => 'Entre na sua conta para assistir.', 'login' => true], 401);
        }
        $epId = in_int('id');
        $ep = q_one(
            "SELECT e.id, e.episode_number, e.name, s.season_number, c.id AS content_id, c.tmdb_id, c.imdb_id, c.slug
             FROM episodes e JOIN seasons s ON s.id = e.season_id JOIN contents c ON c.id = e.content_id
             WHERE e.id = ? AND (c.status = 'published' OR ? = 1)",
            [$epId, is_admin() ? 1 : 0]
        );
        if (!$ep) {
            json_out(['error' => 'Episódio não encontrado.'], 404);
        }
        $players = array_merge(
            array_filter([vjs_api_player($ep, (int)$ep['season_number'], (int)$ep['episode_number'], (int)$ep['id'])]),
            api_players_episode($ep, (int)$ep['season_number'], (int)$ep['episode_number']),
            q_all('SELECT * FROM players WHERE episode_id = ? ORDER BY sort, id', [$epId])
        );
        json_out([
            'id' => (int)$ep['id'],
            'season' => (int)$ep['season_number'],
            'episode' => (int)$ep['episode_number'],
            'name' => $ep['name'],
            'players' => players_for_front($players),
        ]);

    case 'xtream_src':
        if (setting('require_login') === '1' && !current_user()) json_out(['error' => 'Entre na sua conta para assistir.'], 401);
        $cid = in_int('c'); $eid = in_int('e');
        $content = q_one("SELECT id, type FROM contents WHERE id = ? AND (status = 'published' OR ? = 1)", [$cid, is_admin() ? 1 : 0]);
        if (!$content) json_out(['error' => 'Título não encontrado.'], 404);
        if ($eid && !q_val('SELECT 1 FROM episodes WHERE id = ? AND content_id = ?', [$eid, $cid])) json_out(['error' => 'Episódio não encontrado.'], 404);
        $streamUrl = xtream_resolve_stream($cid, $eid);
        if ($streamUrl === '') json_out(['error' => 'Este conteúdo ainda não foi associado ao catálogo Xtream.'], 404);
        json_out(['url' => $streamUrl]);

    case 'domain_ping':
        // Usado pelo Painel › Domínio para provar que o domínio novo abre ESTE site
        header('Content-Type: application/javascript; charset=utf-8');
        header('Cache-Control: no-store');
        header('Access-Control-Allow-Origin: *');
        echo 'window.__pmDomainPing&&window.__pmDomainPing(' . json_encode(domain_token()) . ',' . json_encode(domain_request_https()) . ');';
        exit;

    case 'vjs_src':
        // Busca na sua API o link do vídeo (.mp4/.m3u8) do player próprio
        if (setting('require_login') === '1' && !current_user()) json_out(['error' => 'Entre na sua conta para assistir.'], 401);
        $cid = in_int('c'); $eid = in_int('e');
        $c = q_one("SELECT id, tmdb_id, imdb_id, slug FROM contents WHERE id = ? AND (status = 'published' OR ? = 1)", [$cid, is_admin() ? 1 : 0]);
        if (!$c) json_out(['error' => 'Título não encontrado.'], 404);
        $season = $episode = null;
        if ($eid) {
            $e = q_one('SELECT e.episode_number, s.season_number FROM episodes e JOIN seasons s ON s.id = e.season_id WHERE e.id = ? AND e.content_id = ?', [$eid, $cid]);
            if (!$e) json_out(['error' => 'Episódio não encontrado.'], 404);
            $season = (int)$e['season_number']; $episode = (int)$e['episode_number'];
        }
        $apiUrl = vjs_api_url($c, $season, $episode);
        if (!$apiUrl) json_out(['error' => 'Vídeo indisponível neste player.'], 404);
        $src = cache_remember('vjs_' . md5($apiUrl), 300, function () use ($apiUrl) {
            try { return vjs_api_extract(tv_http_get($apiUrl), trim((string)setting('vjs_api_field'))) ?? ''; }
            catch (Throwable $e) { return ''; }
        });
        if ($src === '') json_out(['error' => 'Vídeo indisponível neste player. Tente outro.'], 404);
        json_out(['url' => $src]);

    case 'notif_seen':
        if (!is_post()) json_out(['error' => 'Método inválido'], 405);
        csrf_check();
        if ($u = current_user()) {
            $nid = in_int('id');
            q('UPDATE users SET notif_seen = ? WHERE id = ? AND notif_seen < ?', [$nid, $u['id'], $nid]);
        }
        json_out(['ok' => true]);

    case 'favorite':
        if (!is_post()) json_out(['error' => 'Método inválido'], 405);
        csrf_check();
        $u = current_user();
        if (!$u) {
            json_out(['error' => 'Entre na sua conta para usar a Minha Lista.', 'login' => true], 401);
        }
        $cid = in_int('id');
        if (!q_val('SELECT 1 FROM contents WHERE id = ?', [$cid])) {
            json_out(['error' => 'Título não encontrado.'], 404);
        }
        if (q_val('SELECT 1 FROM favorites WHERE user_id = ? AND content_id = ?', [$u['id'], $cid])) {
            q('DELETE FROM favorites WHERE user_id = ? AND content_id = ?', [$u['id'], $cid]);
            json_out(['on' => false]);
        }
        q('INSERT INTO favorites (user_id, content_id) VALUES (?, ?)', [$u['id'], $cid]);
        json_out(['on' => true]);

    case 'report':
        if (!is_post()) json_out(['error' => 'Método inválido'], 405);
        csrf_check();
        $last = $_SESSION['last_report'] ?? 0;
        if (time() - $last < 30) {
            json_out(['error' => 'Aguarde alguns segundos antes de enviar outro aviso.'], 429);
        }
        $msg = mb_substr(trim(in_str('message') . (in_str('details') !== '' ? ' — ' . in_str('details') : '')), 0, 500);
        if ($msg === '') json_out(['error' => 'Descreva o problema.'], 422);
        $cid = in_int('content_id') ?: null;
        $eid = in_int('episode_id') ?: null;
        $chid = in_int('channel_id') ?: null;
        q('INSERT INTO reports (content_id, episode_id, channel_id, user_id, message) VALUES (?, ?, ?, ?, ?)',
            [$cid, $eid, $chid, current_user()['id'] ?? null, $msg]);
        $_SESSION['last_report'] = time();
        json_out(['ok' => true]);

    case 'ping':
        json_out(['ok' => true, 'method' => $_SERVER['REQUEST_METHOD'] ?? '']);

    case 'suggest':
        $term = in_str('q');
        if (mb_strlen($term) < 2) json_out(['results' => []]);
        $rows = q_all(
            "SELECT id, type, title, slug, poster, year FROM contents WHERE status = 'published' AND (title LIKE ? OR original_title LIKE ?) ORDER BY views DESC LIMIT 8",
            ['%' . $term . '%', '%' . $term . '%']
        );
        json_out(['results' => array_map(fn($r) => [
            'title' => $r['title'],
            'type' => type_label($r['type']),
            'year' => $r['year'],
            'poster' => img($r['poster'], 'w92'),
            'url' => content_url($r),
        ], $rows)]);

    default:
        json_out(['error' => 'Ação inválida'], 400);
}
