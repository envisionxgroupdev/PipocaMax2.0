<?php
/** Endpoints AJAX do painel (busca no TMDB e importação em fila) */
require __DIR__ . '/_init.php';
@set_time_limit(300);

$action = in_str('action');

try {
    switch ($action) {
        case 'ping':
            // Teste de diagnóstico: só confirma que o envio chegou
            json_out(['ok' => true, 'method' => $_SERVER['REQUEST_METHOD'] ?? '', 'bytes' => strlen((string)file_get_contents('php://input')), 'php' => PHP_VERSION]);

        case 'auto_step':
            // "Rodar agora": importa 1 título por pedido (não estoura o tempo da hospedagem)
            if (!is_post()) json_out(['error' => 'Método inválido'], 405);
            csrf_check();
            $GLOBALS['PM_AUTO_QUIET'] = true;
            $r = run_autoimport(1, 20, true, in_int('series', 0));
            json_out(['ok' => true] + $r);

        case 'auto_log':
            if (!is_post()) json_out(['error' => 'Método inválido'], 405);
            csrf_check();
            $msg = mb_substr(in_str('msg'), 0, 900);
            if ($msg !== '') auto_log($msg, in_str('type') === 'error' ? 'error' : 'ok');
            json_out(['ok' => true]);

        case 'search':
            $q = in_str('q');
            $kind = in_str('kind') === 'tv' ? 'tv' : 'movie';
            if ($q === '') json_out(['results' => []]);
            if (preg_match('~themoviedb\.org/(movie|tv)/(\d+)~', $q, $m) || preg_match('~^(\d+)$~', $q, $m)) {
                $tid = (int)end($m);
                $d = tmdb_request($kind . '/' . $tid);
                $date = $d['release_date'] ?? $d['first_air_date'] ?? '';
                $res = [['tmdb_id' => $tid, 'title' => $d['title'] ?? $d['name'] ?? '', 'year' => substr($date, 0, 4), 'poster' => $d['poster_path'] ?? null, 'rating' => round((float)($d['vote_average'] ?? 0), 1)]];
            } else {
                $res = tmdb_search($kind, $q)['results'];
            }
            $ids = array_column($res, 'tmdb_id');
            $have = [];
            if ($ids) {
                $in = implode(',', array_fill(0, count($ids), '?'));
                $typeCond = $kind === 'movie' ? "type = 'movie'" : "type <> 'movie'";
                foreach (q_all("SELECT id, tmdb_id FROM contents WHERE $typeCond AND tmdb_id IN ($in)", $ids) as $r) $have[$r['tmdb_id']] = (int)$r['id'];
            }
            json_out(['results' => array_map(fn($r) => $r + ['poster_url' => img($r['poster'], 'w185'), 'local_id' => $have[$r['tmdb_id']] ?? null], array_slice($res, 0, 18))]);

        case 'list':
            // IDs de uma página de lista do TMDB (para importação em massa)
            $ln = in_str('list');
            if (in_array($ln, ['year_movie', 'year_tv', 'year_anime'], true)) {
                $r = tmdb_discover_year(['year_movie' => 'movie', 'year_tv' => 'tv', 'year_anime' => 'anime'][$ln], in_int('year', (int)date('Y')), max(1, in_int('page', 1)));
            } else {
                $r = tmdb_list($ln, max(1, in_int('page', 1)));
            }
            json_out(['results' => $r['results'], 'total_pages' => $r['total_pages']]);

        case 'import_one':
            if (!is_post()) json_out(['error' => 'Método inválido'], 405);
            csrf_check();
            $type = in_str('type');
            if (!in_array($type, ['movie', 'series', 'anime'], true)) $type = 'movie';
            $kind = $type === 'movie' ? 'movie' : 'tv';
            $item = in_str('item');
            $skip = in_str('skip_existing', '1') === '1';

            // Aceita: ID do TMDB, link do TMDB, ou nome (com ano opcional: "Duna 2021")
            $tid = 0;
            if (preg_match('~themoviedb\.org/(movie|tv)/(\d+)~', $item, $m)) {
                $tid = (int)$m[2];
                $kind = $m[1];
                if ($kind === 'movie') $type = 'movie';
                elseif ($type === 'movie') $type = 'series';
            } elseif (preg_match('~^\d+$~', $item)) {
                $tid = (int)$item;
            } else {
                $name = $item;
                $year = '';
                if (preg_match('~^(.*?)[\s(]+((?:19|20)\d{2})\)?$~', $item, $m)) {
                    $name = trim($m[1]);
                    $year = $m[2];
                }
                $res = tmdb_search($kind, $name)['results'];
                if ($year) {
                    $match = array_values(array_filter($res, fn($r) => $r['year'] === $year));
                    if ($match) $res = $match;
                }
                if (!$res) json_out(['ok' => false, 'item' => $item, 'error' => 'Não encontrado no TMDB']);
                $tid = $res[0]['tmdb_id'];
            }

            $existing = q_one('SELECT id, title FROM contents WHERE tmdb_id = ? AND type = ?', [$tid, $type]);
            if ($existing && $skip) {
                json_out(['ok' => true, 'skipped' => true, 'item' => $item, 'id' => (int)$existing['id'], 'title' => $existing['title']]);
            }
            $id = tmdb_import($tid, $type, true, [
                'is_release' => in_str('is_release') === '1',
                'featured' => in_str('featured') === '1',
                'status' => in_str('draft') === '1' ? 'draft' : 'published',
            ]);
            $c = q_one('SELECT id, title, year, poster FROM contents WHERE id = ?', [$id]);
            $eps = $type === 'movie' ? 0 : (int)q_val('SELECT COUNT(*) FROM episodes WHERE content_id = ?', [$id]);
            json_out(['ok' => true, 'item' => $item, 'id' => $id, 'title' => $c['title'], 'year' => $c['year'], 'poster' => img($c['poster'], 'w92'), 'episodes' => $eps, 'updated' => (bool)$existing]);

        default:
            json_out(['error' => 'Ação inválida'], 400);
    }
} catch (Throwable $e) {
    json_out(['ok' => false, 'item' => $_POST['item'] ?? '', 'error' => $e->getMessage()]);
}
