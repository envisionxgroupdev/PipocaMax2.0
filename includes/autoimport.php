<?php
/**
 * Importação automática: publica títulos do TMDB sozinho, em lotes.
 * Chamado pelo cron.php (Cron da Hostinger) ou pelo modo "visitas".
 */

function auto_sources(): array
{
    // chave => [rótulo, tipo no site]
    return [
        'now_playing' => ['Filmes nos cinemas (lançamentos)', 'movie'],
        'upcoming' => ['Filmes que vão estrear', 'movie'],
        'trending_movies' => ['Filmes em alta na semana', 'movie'],
        'popular_movies' => ['Filmes populares', 'movie'],
        'top_rated_movies' => ['Filmes mais bem avaliados', 'movie'],
        'on_air' => ['Séries no ar (com episódios novos)', 'series'],
        'trending_tv' => ['Séries em alta na semana', 'series'],
        'popular_tv' => ['Séries populares', 'series'],
        'top_rated_tv' => ['Séries mais bem avaliadas', 'series'],
        'anime' => ['Animes (séries) populares', 'anime'],
        'anime_movies' => ['Filmes de anime', 'movie'],
        'year_movie' => ['Filmes por ano (catálogo antigo)', 'movie'],
        'year_tv' => ['Séries por ano (catálogo antigo)', 'series'],
        'year_anime' => ['Animes por ano (catálogo antigo)', 'anime'],
    ];
}

function auto_log(string $msg, string $type = 'info'): void
{
    $log = json_decode((string)setting('auto_log', '[]'), true) ?: [];
    array_unshift($log, ['t' => date('Y-m-d H:i:s'), 'type' => $type, 'msg' => $msg]);
    set_setting('auto_log', json_encode(array_slice($log, 0, 60), JSON_UNESCAPED_UNICODE));
}

/**
 * Roda um lote. $limit = quantos títulos novos no máximo; $maxSeconds = tempo máximo.
 * Retorna resumo ['imported' => n, 'updated' => n, 'skipped' => n, 'errors' => n].
 */
function run_autoimport(?int $limit = null, int $maxSeconds = 50, bool $force = false): array
{
    $sum = ['imported' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0, 'titles' => []];
    if (!tmdb_configured()) {
        auto_log('API do TMDB não configurada.', 'error');
        return $sum;
    }
    // Trava: evita duas execuções ao mesmo tempo
    $lock = (int)setting('auto_lock', '0');
    if (!$force && $lock > time() - 300) {
        return $sum;
    }
    set_setting('auto_lock', (string)time());
    @set_time_limit($maxSeconds + 30);
    $start = time();
    $limit = $limit ?? max(1, min(50, (int)setting('auto_per_run', '10')));
    $sources = array_values(array_filter(explode(',', (string)setting('auto_sources', 'now_playing,on_air,anime'))));
    $all = auto_sources();
    $sources = array_values(array_filter($sources, fn($s) => isset($all[$s])));
    $cursor = json_decode((string)setting('auto_cursor', '{}'), true) ?: [];
    $status = setting('auto_status', 'published') === 'draft' ? 'draft' : 'published';
    $releaseDays = (int)setting('auto_release_days', '60');
    // Títulos que deram erro ficam de fora por 24h (não travam a fila)
    $failed = array_filter(json_decode((string)setting('auto_failed', '{}'), true) ?: [], fn($t) => $t > time() - 86400);
    $errMsgs = [];

    try {
        // 1) Títulos novos, revezando entre as fontes escolhidas
        $si = (int)($cursor['_src'] ?? 0);
        $emptyRounds = 0;
        while ($sources && $sum['imported'] < $limit && time() - $start < $maxSeconds && $emptyRounds < count($sources) * 3) {
            $src = $sources[$si % count($sources)];
            $si++;
            $byYear = str_starts_with($src, 'year_');
            if ($byYear) {
                // Percorre os anos do mais novo para o mais antigo, os mais populares primeiro
                [$yFrom, $yTo] = auto_year_range();
                $yc = $cursor[$src] ?? [];
                $year = (int)($yc['y'] ?? $yTo);
                if ($year > $yTo || $year < $yFrom) $year = $yTo;
                $page = max(1, (int)($yc['p'] ?? 1));
                $list = tmdb_discover_year(['year_movie' => 'movie', 'year_tv' => 'tv', 'year_anime' => 'anime'][$src], $year, $page);
            } else {
                $page = max(1, (int)($cursor[$src] ?? 1));
                $list = tmdb_list($src, $page);
            }
            $found = 0;
            foreach ($list['results'] as $r) {
                if ($sum['imported'] >= $limit || time() - $start >= $maxSeconds) break;
                $type = $all[$src][1];
                if ($type !== 'movie' && !empty($r['is_anime'])) $type = 'anime';
                $fk = $type . ':' . $r['tmdb_id'];
                if (isset($failed[$fk])) { continue; }
                $exists = q_val('SELECT 1 FROM contents WHERE tmdb_id = ? AND ' . ($type === 'movie' ? "type = 'movie'" : "type <> 'movie'"), [$r['tmdb_id']]);
                if ($exists) { $sum['skipped']++; continue; }
                try {
                    $isRelease = false;
                    if ($releaseDays > 0 && !empty($r['date'])) {
                        $isRelease = strtotime($r['date']) >= strtotime("-$releaseDays days");
                    }
                    if (in_array($src, ['now_playing', 'upcoming'], true)) $isRelease = true;
                    $id = tmdb_import((int)$r['tmdb_id'], $type, true, ['is_release' => $isRelease, 'status' => $status]);
                    $sum['imported']++;
                    $found++;
                    $sum['titles'][] = $r['title'];
                } catch (Throwable $e) {
                    $sum['errors']++;
                    $failed[$fk] = time();
                    $errMsgs[] = '"' . $r['title'] . '" (' . $e->getMessage() . ')';
                }
            }
            // Página toda já existia: avança para a próxima; chegou no fim: volta ao começo
            if ($found === 0 || count($list['results']) === 0) {
                if ($byYear) {
                    $maxPages = min((int)$list['total_pages'], max(1, (int)setting('auto_year_pages', '10')));
                    if ($page >= $maxPages) {
                        $year--;
                        $page = 1;
                        if ($year < $yFrom) $year = $yTo; // terminou: recomeça do ano mais novo
                    } else {
                        $page++;
                    }
                    $cursor[$src] = ['y' => $year, 'p' => $page];
                } else {
                    $cursor[$src] = $page >= min(500, (int)$list['total_pages']) ? 1 : $page + 1;
                }
                $emptyRounds++;
            }
        }
        $cursor['_src'] = $si % max(1, count($sources));

        // 2) Atualiza séries/animes que ainda estão no ar (episódios novos)
        if (setting('auto_update_series', '1') === '1' && time() - $start < $maxSeconds) {
            $series = q_all("SELECT id, tmdb_id, type, title FROM contents WHERE type <> 'movie' AND tmdb_id IS NOT NULL
                             AND updated_at < (NOW() - INTERVAL 1 DAY) ORDER BY updated_at ASC LIMIT 3");
            foreach ($series as $s) {
                if (time() - $start >= $maxSeconds) break;
                try {
                    $before = (int)q_val('SELECT COUNT(*) FROM episodes WHERE content_id = ?', [$s['id']]);
                    tmdb_import((int)$s['tmdb_id'], $s['type'], true);
                    q('UPDATE contents SET updated_at = NOW() WHERE id = ?', [$s['id']]);
                    $after = (int)q_val('SELECT COUNT(*) FROM episodes WHERE content_id = ?', [$s['id']]);
                    if ($after > $before) {
                        $sum['updated']++;
                        auto_log('"' . $s['title'] . '" ganhou ' . ($after - $before) . ' episódio(s) novo(s).', 'ok');
                    }
                } catch (Throwable $e) {
                    // marca como verificada para não travar a fila das outras séries
                    q('UPDATE contents SET updated_at = NOW() WHERE id = ?', [$s['id']]);
                }
            }
        }
    } catch (Throwable $e) {
        $sum['errors']++;
        auto_log('Falha na importação automática: ' . $e->getMessage(), 'error');
    } finally {
        set_setting('auto_cursor', json_encode($cursor));
        set_setting('auto_failed', json_encode($failed));
        if ($errMsgs) auto_log('Não consegui importar: ' . implode('; ', array_slice($errMsgs, 0, 5)) . '. Vou tentar de novo amanhã.', 'error');
        set_setting('auto_lock', '0');
        set_setting('auto_last_run', (string)time());
    }

    if ($sum['imported'] || $sum['updated']) {
        auto_log($sum['imported'] . ' título(s) novo(s)' . ($sum['titles'] ? ': ' . implode(', ', array_slice($sum['titles'], 0, 8)) . (count($sum['titles']) > 8 ? '…' : '') : '') . ($sum['updated'] ? ' • ' . $sum['updated'] . ' série(s) com episódios novos' : ''), 'ok');
    } elseif (!$sum['errors']) {
        auto_log('Nada novo para importar desta vez.', 'info');
    }
    return $sum;
}

function auto_year_range(): array
{
    $to = (int)setting('auto_year_to', date('Y'));
    $from = (int)setting('auto_year_from', '1990');
    $to = max(1900, min((int)date('Y') + 1, $to ?: (int)date('Y')));
    $from = max(1900, min($to, $from ?: 1990));
    return [$from, $to];
}

/** Em que ano/página cada lista "por ano" está agora */
function auto_year_progress(): array
{
    $cursor = json_decode((string)setting('auto_cursor', '{}'), true) ?: [];
    [$f, $t] = auto_year_range();
    $out = [];
    foreach (['year_movie', 'year_tv', 'year_anime'] as $k) {
        $out[$k] = (int)($cursor[$k]['y'] ?? $t);
    }
    return $out;
}

/** Modo "visitas": roda um lote pequeno depois de entregar a página ao visitante */
function auto_maybe_run_on_visit(): void
{
    if (setting('auto_enabled') !== '1' || setting('auto_mode', 'cron') !== 'visits' || setting('maintenance') === '1') return;
    $interval = max(5, (int)setting('auto_interval', '30')) * 60;
    if ((int)setting('auto_last_run', '0') > time() - $interval) return;
    if (!function_exists('fastcgi_finish_request') && !function_exists('litespeed_finish_request')) return;
    register_shutdown_function(function () {
        if (function_exists('litespeed_finish_request')) litespeed_finish_request();
        else fastcgi_finish_request();
        ignore_user_abort(true);
        run_autoimport(min(5, (int)setting('auto_per_run', '10')), 25);
    });
}
