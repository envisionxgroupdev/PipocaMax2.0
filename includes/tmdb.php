<?php
/**
 * Integração com a API do TMDB (The Movie Database).
 * Aceita a "Chave da API" (v3) ou o "Token de leitura" (v4, começa com eyJ).
 */

function tmdb_configured(): bool
{
    return trim((string)setting('tmdb_api_key')) !== '';
}

function tmdb_request(string $path, array $params = []): array
{
    $key = trim((string)setting('tmdb_api_key'));
    if ($key === '') {
        throw new RuntimeException('Configure a chave da API do TMDB em Configurações.');
    }
    $params['language'] = $params['language'] ?? (setting('tmdb_language', 'pt-BR') ?: 'pt-BR');
    $headers = ['Accept: application/json'];
    if (str_starts_with($key, 'eyJ')) {
        $headers[] = 'Authorization: Bearer ' . $key;
    } else {
        $params['api_key'] = $key;
    }
    $url = 'https://api.themoviedb.org/3/' . ltrim($path, '/') . '?' . http_build_query($params);

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false) {
            throw new RuntimeException('Falha ao conectar no TMDB: ' . $err);
        }
    } else {
        $ctx = stream_context_create(['http' => ['header' => implode("\r\n", $headers), 'timeout' => 20, 'ignore_errors' => true]]);
        $body = @file_get_contents($url, false, $ctx);
        $code = 200;
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
            $code = (int)$m[1];
        }
        if ($body === false) {
            throw new RuntimeException('Falha ao conectar no TMDB.');
        }
    }
    $data = json_decode($body, true);
    if ($code === 401) {
        throw new RuntimeException('Chave do TMDB inválida. Confira em Configurações.');
    }
    if ($code === 404) {
        throw new RuntimeException('Título não encontrado no TMDB.');
    }
    if ($code >= 400 || !is_array($data)) {
        throw new RuntimeException('Erro do TMDB (' . $code . '): ' . ($data['status_message'] ?? 'resposta inválida'));
    }
    return $data;
}

/** Busca no TMDB. $kind = movie | tv */
function tmdb_search(string $kind, string $query, int $page = 1): array
{
    $kind = $kind === 'tv' ? 'tv' : 'movie';
    $data = tmdb_request('search/' . $kind, ['query' => $query, 'page' => $page, 'include_adult' => 'false']);
    $out = [];
    foreach ($data['results'] ?? [] as $r) {
        $date = $r['release_date'] ?? $r['first_air_date'] ?? '';
        $out[] = [
            'tmdb_id' => (int)$r['id'],
            'title' => $r['title'] ?? $r['name'] ?? '',
            'original_title' => $r['original_title'] ?? $r['original_name'] ?? '',
            'year' => $date ? substr($date, 0, 4) : '',
            'poster' => $r['poster_path'] ?? null,
            'overview' => $r['overview'] ?? '',
            'rating' => isset($r['vote_average']) ? round((float)$r['vote_average'], 1) : null,
            'is_anime' => $kind === 'tv' && in_array(16, $r['genre_ids'] ?? [], true) && ($r['original_language'] ?? '') === 'ja',
        ];
    }
    return ['results' => $out, 'total_pages' => (int)($data['total_pages'] ?? 1)];
}

/** Lista "em alta"/"lançamentos" do TMDB para importação rápida */
function tmdb_list(string $list, int $page = 1): array
{
    $paths = [
        'now_playing' => ['movie/now_playing', 'movie'],
        'popular_movies' => ['movie/popular', 'movie'],
        'upcoming' => ['movie/upcoming', 'movie'],
        'popular_tv' => ['tv/popular', 'tv'],
        'on_air' => ['tv/on_the_air', 'tv'],
        'anime' => ['discover/tv', 'tv'],
        'top_rated_movies' => ['movie/top_rated', 'movie'],
        'trending_movies' => ['trending/movie/week', 'movie'],
        'top_rated_tv' => ['tv/top_rated', 'tv'],
        'trending_tv' => ['trending/tv/week', 'tv'],
        'anime_movies' => ['discover/movie', 'movie'],
    ];
    [$path, $kind] = $paths[$list] ?? $paths['now_playing'];
    $params = ['page' => $page, 'region' => 'BR'];
    if ($list === 'anime_movies') {
        $params += ['with_genres' => '16', 'with_original_language' => 'ja', 'sort_by' => 'popularity.desc'];
    }
    if ($list === 'anime') {
        $params += ['with_genres' => '16', 'with_original_language' => 'ja', 'sort_by' => 'popularity.desc'];
    }
    $data = tmdb_request($path, $params);
    $out = [];
    foreach ($data['results'] ?? [] as $r) {
        $date = $r['release_date'] ?? $r['first_air_date'] ?? '';
        $out[] = [
            'tmdb_id' => (int)$r['id'],
            'kind' => $kind,
            'title' => $r['title'] ?? $r['name'] ?? '',
            'year' => $date ? substr($date, 0, 4) : '',
            'poster' => $r['poster_path'] ?? null,
            'rating' => isset($r['vote_average']) ? round((float)$r['vote_average'], 1) : null,
            'is_anime' => $list === 'anime',
            'date' => $date,
        ];
    }
    return ['results' => $out, 'total_pages' => min(500, (int)($data['total_pages'] ?? 1))];
}

/**
 * Títulos mais populares de um ano. $what: movie | tv | anime | anime_movie
 */
function tmdb_discover_year(string $what, int $year, int $page = 1): array
{
    $isTv = in_array($what, ['tv', 'anime'], true);
    $params = ['page' => $page, 'sort_by' => 'popularity.desc', 'include_adult' => 'false', 'vote_count.gte' => 20];
    if ($isTv) {
        $params['first_air_date_year'] = $year;
    } else {
        $params['primary_release_year'] = $year;
    }
    if ($what === 'anime' || $what === 'anime_movie') {
        $params += ['with_genres' => '16', 'with_original_language' => 'ja'];
        $params['vote_count.gte'] = 5;
    } elseif ($what === 'tv') {
        $params['without_genres'] = '10767,10763,10764'; // sem talk show, jornal e reality
    }
    $data = tmdb_request('discover/' . ($isTv ? 'tv' : 'movie'), $params);
    $out = [];
    foreach ($data['results'] ?? [] as $r) {
        $date = $r['release_date'] ?? $r['first_air_date'] ?? '';
        $out[] = [
            'tmdb_id' => (int)$r['id'],
            'kind' => $isTv ? 'tv' : 'movie',
            'title' => $r['title'] ?? $r['name'] ?? '',
            'year' => $date ? substr($date, 0, 4) : '',
            'poster' => $r['poster_path'] ?? null,
            'rating' => isset($r['vote_average']) ? round((float)$r['vote_average'], 1) : null,
            'is_anime' => $what === 'anime',
            'date' => $date,
        ];
    }
    return ['results' => $out, 'total_pages' => min(500, (int)($data['total_pages'] ?? 1))];
}

/**
 * Importa (ou atualiza) um título do TMDB.
 * $type: movie | series | anime. Retorna o id local.
 */
function tmdb_import(int $tmdbId, string $type, bool $withSeasons = true, array $extra = []): int
{
    $kind = $type === 'movie' ? 'movie' : 'tv';
    $append = $kind === 'movie' ? 'videos,external_ids,release_dates,images' : 'videos,external_ids,content_ratings,images';
    $d = tmdb_request($kind . '/' . $tmdbId, [
        'append_to_response' => $append,
        'include_image_language' => 'pt,en,null',
        'include_video_language' => 'pt,en',
    ]);

    $title = $d['title'] ?? $d['name'] ?? 'Sem título';
    $date = $d['release_date'] ?? $d['first_air_date'] ?? null;
    $runtime = $kind === 'movie' ? ($d['runtime'] ?? null) : ($d['episode_run_time'][0] ?? null);
    $genres = implode(', ', array_column($d['genres'] ?? [], 'name'));

    // Trailer do YouTube (prioriza português)
    $trailer = null;
    $videos = $d['videos']['results'] ?? [];
    usort($videos, fn($a, $b) => (($b['iso_639_1'] ?? '') === 'pt') <=> (($a['iso_639_1'] ?? '') === 'pt'));
    foreach ($videos as $v) {
        if (($v['site'] ?? '') === 'YouTube' && in_array($v['type'] ?? '', ['Trailer', 'Teaser'], true)) {
            $trailer = $v['key'];
            break;
        }
    }

    // Classificação indicativa (Brasil)
    $age = null;
    if ($kind === 'movie') {
        foreach ($d['release_dates']['results'] ?? [] as $r) {
            if ($r['iso_3166_1'] === 'BR') {
                foreach ($r['release_dates'] as $rd) {
                    if (!empty($rd['certification'])) {
                        $age = $rd['certification'];
                        break 2;
                    }
                }
            }
        }
    } else {
        foreach ($d['content_ratings']['results'] ?? [] as $r) {
            if ($r['iso_3166_1'] === 'BR' && !empty($r['rating'])) {
                $age = $r['rating'];
                break;
            }
        }
    }

    // Logo (imagem do título)
    $logo = null;
    foreach (['pt', 'en', null] as $lang) {
        foreach ($d['images']['logos'] ?? [] as $l) {
            if (($l['iso_639_1'] ?? null) === $lang) {
                $logo = $l['file_path'];
                break 2;
            }
        }
    }

    $imdb = $d['imdb_id'] ?? ($d['external_ids']['imdb_id'] ?? null);

    $row = [
        'tmdb_id' => $tmdbId,
        'imdb_id' => $imdb,
        'type' => $type,
        'title' => $title,
        'original_title' => $d['original_title'] ?? $d['original_name'] ?? null,
        'slug' => slugify($title),
        'overview' => $d['overview'] ?? null,
        'poster' => $d['poster_path'] ?? null,
        'backdrop' => $d['backdrop_path'] ?? null,
        'logo' => $logo,
        'year' => $date ? (int)substr($date, 0, 4) : null,
        'release_date' => $date ?: null,
        'rating' => isset($d['vote_average']) ? round((float)$d['vote_average'], 1) : null,
        'runtime' => $runtime,
        'genres' => $genres,
        'age_rating' => $age,
        'trailer' => $trailer,
    ];

    $existing = q_one('SELECT id FROM contents WHERE tmdb_id = ? AND type = ?', [$tmdbId, $type]);
    if ($existing) {
        $id = (int)$existing['id'];
        $sets = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($row)));
        q("UPDATE contents SET $sets WHERE id = ?", array_merge(array_values($row), [$id]));
    } else {
        $row += [
            'is_release' => !empty($extra['is_release']) ? 1 : 0,
            'featured' => !empty($extra['featured']) ? 1 : 0,
            'status' => $extra['status'] ?? 'published',
        ];
        $cols = implode(', ', array_map(fn($k) => "`$k`", array_keys($row)));
        $marks = implode(', ', array_fill(0, count($row), '?'));
        q("INSERT INTO contents ($cols) VALUES ($marks)", array_values($row));
        $id = (int)db()->lastInsertId();
    }

    if ($kind === 'tv' && $withSeasons) {
        $nums = [];
        foreach ($d['seasons'] ?? [] as $s) {
            if ((int)$s['season_number'] === 0 && empty($extra['include_specials'])) {
                continue; // pula "Especiais"
            }
            $nums[] = (int)$s['season_number'];
        }
        // Busca até 20 temporadas por pedido ao TMDB (muito mais rápido em séries longas)
        foreach (array_chunk($nums, 20) as $chunk) {
            $batch = tmdb_request("tv/$tmdbId", ['append_to_response' => implode(',', array_map(fn($n) => "season/$n", $chunk))]);
            foreach ($chunk as $n) {
                $sd = $batch["season/$n"] ?? null;
                if (is_array($sd)) {
                    tmdb_save_season($id, $n, $sd);
                } else {
                    tmdb_import_season($id, $tmdbId, $n);
                }
            }
        }
    }
    return $id;
}

/** Importa uma temporada com todos os episódios */
function tmdb_import_season(int $contentId, int $tmdbId, int $seasonNumber): int
{
    return tmdb_save_season($contentId, $seasonNumber, tmdb_request("tv/$tmdbId/season/$seasonNumber"));
}

/** Grava uma temporada (dados já baixados do TMDB) numa única transação */
function tmdb_save_season(int $contentId, int $seasonNumber, array $s): int
{
    $pdo = db();
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
    q(
        'INSERT INTO seasons (content_id, season_number, name, overview, poster) VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE name = VALUES(name), overview = VALUES(overview), poster = VALUES(poster)',
        [$contentId, $seasonNumber, $s['name'] ?? ('Temporada ' . $seasonNumber), $s['overview'] ?? null, $s['poster_path'] ?? null]
    );
    $seasonId = (int)q_val('SELECT id FROM seasons WHERE content_id = ? AND season_number = ?', [$contentId, $seasonNumber]);

    foreach ($s['episodes'] ?? [] as $ep) {
        $num = (int)$ep['episode_number'];
        $exists = q_val('SELECT id FROM episodes WHERE season_id = ? AND episode_number = ?', [$seasonId, $num]);
        $vals = [
            $ep['name'] ?? ('Episódio ' . $num),
            $ep['overview'] ?? null,
            $ep['still_path'] ?? null,
            !empty($ep['air_date']) ? $ep['air_date'] : null,
            $ep['runtime'] ?? null,
        ];
        if ($exists) {
            q('UPDATE episodes SET name = ?, overview = ?, still = ?, air_date = ?, runtime = ? WHERE id = ?', array_merge($vals, [(int)$exists]));
        } else {
            q(
                'INSERT INTO episodes (name, overview, still, air_date, runtime, season_id, content_id, episode_number) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                array_merge($vals, [$seasonId, $contentId, $num])
            );
        }
    }
    if ($own) $pdo->commit();
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return $seasonId;
}

/** Cria os players padrão (Configurações > Players automáticos) para um filme */
function apply_movie_templates(int $contentId): int
{
    $tpls = parse_player_templates((string)setting('movie_player_templates'));
    if (!$tpls) {
        return 0;
    }
    $c = q_one('SELECT * FROM contents WHERE id = ?', [$contentId]);
    if (!$c) return 0;
    $vars = ['tmdb_id' => $c['tmdb_id'], 'imdb_id' => $c['imdb_id'], 'id' => $c['id'], 'slug' => $c['slug']];
    $n = 0;
    foreach ($tpls as $i => $t) {
        $u = fill_player_template($t['url'], $vars);
        q('INSERT INTO players (content_id, name, url, kind, audio, quality, sort) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$contentId, $t['name'], $u, player_kind_from_url($u), $t['audio'], $t['quality'], $i]);
        $n++;
    }
    return $n;
}

/** Cria os players padrão para um episódio */
function apply_episode_templates(int $episodeId, ?string $templates = null): int
{
    $tpls = parse_player_templates($templates ?? (string)setting('episode_player_templates'));
    if (!$tpls) {
        return 0;
    }
    $ep = q_one('SELECT e.*, s.season_number, c.tmdb_id, c.imdb_id, c.slug FROM episodes e
                 JOIN seasons s ON s.id = e.season_id JOIN contents c ON c.id = e.content_id WHERE e.id = ?', [$episodeId]);
    if (!$ep) return 0;
    $vars = [
        'tmdb_id' => $ep['tmdb_id'], 'imdb_id' => $ep['imdb_id'], 'slug' => $ep['slug'],
        'temporada' => $ep['season_number'], 'episodio' => $ep['episode_number'],
        'temporada2' => str_pad((string)$ep['season_number'], 2, '0', STR_PAD_LEFT),
        'episodio2' => str_pad((string)$ep['episode_number'], 2, '0', STR_PAD_LEFT),
    ];
    $n = 0;
    foreach ($tpls as $i => $t) {
        $u = fill_player_template($t['url'], $vars);
        q('INSERT INTO players (episode_id, name, url, kind, audio, quality, sort) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$episodeId, $t['name'], $u, player_kind_from_url($u), $t['audio'], $t['quality'], $i]);
        $n++;
    }
    return $n;
}
