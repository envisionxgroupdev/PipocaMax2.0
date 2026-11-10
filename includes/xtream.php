<?php
/**
 * Integração Xtream Codes com o player próprio Video.js.
 * As credenciais são cifradas em repouso; a sincronização associa apenas IDs TMDB exatos.
 */
function xtream_crypto_key(): string
{
    $cfg = $GLOBALS['CONFIG'] ?? [];
    $db = is_array($cfg['db'] ?? null) ? $cfg['db'] : [];
    return hash('sha256', (string)($db['pass'] ?? '') . '|' . (string)($db['name'] ?? '') . '|' . BASE_URL . '|pipocamax-xtream-v1', true);
}

function xtream_encrypt_password(string $password): string
{
    if (!function_exists('openssl_encrypt')) throw new RuntimeException('A extensão OpenSSL é necessária para guardar a senha com segurança.');
    $iv = random_bytes(12); $tag = '';
    $cipher = openssl_encrypt($password, 'aes-256-gcm', xtream_crypto_key(), OPENSSL_RAW_DATA, $iv, $tag);
    if ($cipher === false) throw new RuntimeException('Não foi possível proteger a senha Xtream.');
    return base64_encode($iv . $tag . $cipher);
}

function xtream_password(): string
{
    $raw = base64_decode((string)setting('xtream_password_enc'), true);
    if ($raw === false || strlen($raw) < 29 || !function_exists('openssl_decrypt')) return '';
    $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', xtream_crypto_key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    return is_string($plain) ? $plain : '';
}

function xtream_server(): string
{
    $server = trim((string)setting('xtream_server'));
    return rtrim($server, '/');
}

function xtream_validate_server(string $server): string
{
    $server = trim($server);
    if (!preg_match('~^https?://~i', $server)) $server = 'http://' . $server;
    $p = parse_url($server);
    if (!$p || empty($p['host']) || !in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true) || isset($p['user']) || isset($p['pass']) || isset($p['query']) || isset($p['fragment'])) {
        throw new RuntimeException('Informe o endereço base do servidor Xtream, por exemplo https://servidor.com:8080.');
    }
    $host = trim((string)$p['host'], '[]');
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        if (!filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) throw new RuntimeException('O endereço do servidor precisa ser público.');
    } else {
        $ip = gethostbyname($host);
        if ($ip === $host || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            throw new RuntimeException('O domínio não resolveu para um endereço público válido.');
        }
    }
    return rtrim($server, '/');
}

function xtream_api_request(array $params = []): array
{
    if (!function_exists('curl_init')) throw new RuntimeException('A extensão cURL precisa estar ativada na hospedagem.');
    $server = xtream_validate_server(xtream_server());
    $user = trim((string)setting('xtream_username'));
    $pass = xtream_password();
    if ($user === '' || $pass === '') throw new RuntimeException('Configure o usuário e a senha Xtream no painel.');
    $query = array_merge(['username' => $user, 'password' => $pass], $params);
    $url = $server . '/player_api.php?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 35,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'User-Agent: PipocaMax-Xtream/1.0'],
    ]);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    if (!is_string($body)) throw new RuntimeException('Falha ao conectar ao servidor Xtream: ' . $error);
    if ($status < 200 || $status >= 300) throw new RuntimeException('O servidor Xtream respondeu HTTP ' . $status . '.');
    $data = json_decode($body, true);
    if (!is_array($data)) throw new RuntimeException('A resposta do servidor não é um JSON válido.');
    return $data;
}

function xtream_test_connection(): array
{
    $data = xtream_api_request();
    $info = $data['user_info'] ?? [];
    if (!is_array($info) || (string)($info['auth'] ?? '0') !== '1') throw new RuntimeException('O servidor respondeu, mas não autenticou esta conta. Confira usuário e senha.');
    return [
        'status' => (string)($info['status'] ?? 'Conectado'),
        'expires' => (string)($info['exp_date'] ?? ''),
        'max_connections' => (int)($info['max_connections'] ?? 0),
    ];
}

function xtream_stream_url(string $type, int $streamId, string $extension = 'mp4'): string
{
    if ($streamId < 1 || !in_array($type, ['movie', 'series'], true)) return '';
    $extension = strtolower(preg_replace('/[^a-z0-9]/i', '', $extension));
    if (!in_array($extension, ['mp4', 'mkv', 'avi', 'mov', 'ts', 'm3u8'], true)) $extension = 'mp4';
    $server = xtream_validate_server(xtream_server());
    $user = rawurlencode((string)setting('xtream_username'));
    $pass = rawurlencode(xtream_password());
    return $server . '/' . $type . '/' . $user . '/' . $pass . '/' . $streamId . '.' . $extension;
}

function xtream_mapping(): array
{
    $map = json_decode((string)setting('xtream_mapping', ''), true);
    return is_array($map) ? $map : [];
}

/** Associa o catálogo já existente do PipocaMax por TMDB ID exato; não cria títulos duplicados. */
function xtream_sync_catalog(): array
{
    @set_time_limit(180);
    $vod = xtream_api_request(['action' => 'get_vod_streams']);
    $series = xtream_api_request(['action' => 'get_series']);
    if (!is_array($vod) || !is_array($series)) throw new RuntimeException('O provedor não retornou o catálogo VOD/séries no formato esperado.');
    $vodByTmdb = []; $seriesByTmdb = [];
    foreach ($vod as $item) {
        $tmdb = (int)($item['tmdb'] ?? $item['tmdb_id'] ?? 0);
        $id = (int)($item['stream_id'] ?? 0);
        if ($tmdb > 0 && $id > 0 && !isset($vodByTmdb[$tmdb])) $vodByTmdb[$tmdb] = $item;
    }
    foreach ($series as $item) {
        $tmdb = (int)($item['tmdb'] ?? $item['tmdb_id'] ?? 0);
        $id = (int)($item['series_id'] ?? 0);
        if ($tmdb > 0 && $id > 0 && !isset($seriesByTmdb[$tmdb])) $seriesByTmdb[$tmdb] = $item;
    }

    $map = []; $movies = 0; $shows = 0; $episodes = 0;
    $contents = q_all("SELECT id, type, tmdb_id FROM contents WHERE status = 'published' AND tmdb_id IS NOT NULL AND tmdb_id > 0");
    foreach ($contents as $content) {
        $cid = (int)$content['id']; $tmdb = (int)$content['tmdb_id'];
        if ($content['type'] === 'movie' && isset($vodByTmdb[$tmdb])) {
            $item = $vodByTmdb[$tmdb];
            $map['m:' . $cid] = ['id' => (int)$item['stream_id'], 'ext' => (string)($item['container_extension'] ?? 'mp4')];
            $movies++;
            continue;
        }
        if ($content['type'] === 'movie' || !isset($seriesByTmdb[$tmdb])) continue;
        $seriesItem = $seriesByTmdb[$tmdb]; $seriesId = (int)$seriesItem['series_id'];
        $map['s:' . $cid] = ['id' => $seriesId];
        $shows++;
        $info = xtream_api_request(['action' => 'get_series_info', 'series_id' => $seriesId]);
        $providerEpisodes = $info['episodes'] ?? [];
        if (!is_array($providerEpisodes)) continue;
        $localEpisodes = q_all('SELECT e.id, e.episode_number, s.season_number FROM episodes e JOIN seasons s ON s.id = e.season_id WHERE e.content_id = ?', [$cid]);
        $byNumber = [];
        foreach ($localEpisodes as $ep) $byNumber[(int)$ep['season_number'] . ':' . (int)$ep['episode_number']] = (int)$ep['id'];
        foreach ($providerEpisodes as $seasonKey => $seasonList) {
            if (!is_array($seasonList)) continue;
            foreach ($seasonList as $ep) {
                if (!is_array($ep)) continue;
                $seasonNo = (int)($ep['season'] ?? $ep['season_num'] ?? $seasonKey);
                $episodeNo = (int)($ep['episode_num'] ?? $ep['episode'] ?? 0);
                $streamId = (int)($ep['id'] ?? $ep['stream_id'] ?? 0);
                $localId = $byNumber[$seasonNo . ':' . $episodeNo] ?? 0;
                if ($localId && $streamId) {
                    $map['e:' . $localId] = ['id' => $streamId, 'ext' => (string)($ep['container_extension'] ?? 'mp4')];
                    $episodes++;
                }
            }
        }
    }
    set_setting('xtream_mapping', json_encode($map, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    set_setting('xtream_last_sync', date('Y-m-d H:i:s'));
    return ['movies' => $movies, 'series' => $shows, 'episodes' => $episodes, 'total' => count($map)];
}

function xtream_player(array $content, ?int $episodeId = null): ?array
{
    if (setting('xtream_on', '0') !== '1') return null;
    $cid = (int)($content['content_id'] ?? ($content['id'] ?? 0));
    $map = xtream_mapping();
    $key = $episodeId ? 'e:' . $episodeId : 'm:' . $cid;
    $entry = $map[$key] ?? null;
    if (!is_array($entry) || empty($entry['id'])) return null;
    return [
        'id' => -200, 'name' => setting('xtream_player_name') ?: 'Xtream Codes',
        'kind' => 'videojs', 'audio' => '', 'quality' => '',
        'url' => site_url(url('api.php')) . '?action=xtream_src&c=' . $cid . ($episodeId ? '&e=' . $episodeId : ''),
        'resolve' => 1, 'test' => false,
    ];
}

function xtream_resolve_stream(int $contentId, int $episodeId = 0): string
{
    if (setting('xtream_on', '0') !== '1') return '';
    $map = xtream_mapping();
    $entry = $map[$episodeId ? 'e:' . $episodeId : 'm:' . $contentId] ?? null;
    if (!is_array($entry) || empty($entry['id'])) return '';
    return xtream_stream_url($episodeId ? 'series' : 'movie', (int)$entry['id'], (string)($entry['ext'] ?? 'mp4'));
}
