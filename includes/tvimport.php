<?php
/**
 * Listas de canais por link (M3U/M3U8 ou JSON), com atualização automática.
 */

function tv_sources(): array
{
    return json_decode((string)setting('tv_sources', '[]'), true) ?: [];
}

function tv_sources_save(array $list): void
{
    set_setting('tv_sources', json_encode(array_values($list), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function tv_http_get(string $url): string
{
    if (!function_exists('curl_init')) {
        $body = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 25, 'header' => "User-Agent: Mozilla/5.0\r\n"]]));
        if ($body === false) throw new RuntimeException('Não consegui baixar a lista.');
        return $body;
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => 25, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
        CURLOPT_ENCODING => '', CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; PipocaMax)',
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($body === false) throw new RuntimeException('Não consegui baixar a lista: ' . $err);
    if ($code >= 400) throw new RuntimeException('O link da lista respondeu com erro ' . $code . '.');
    return (string)$body;
}

/** Lê M3U ou JSON e devolve [['name','logo','group','url'], ...] */
function tv_parse(string $body): array
{
    $body = preg_replace('/^\xEF\xBB\xBF/', '', $body);
    $items = [];
    if (stripos(ltrim($body), '#EXTM3U') === 0 || stripos($body, '#EXTINF') !== false) {
        $meta = null;
        foreach (preg_split('/\r\n|\r|\n/', $body) as $line) {
            $line = trim($line);
            if (stripos($line, '#EXTINF') === 0) {
                $meta = ['name' => trim(substr($line, strrpos($line, ',') + 1)), 'logo' => '', 'group' => ''];
                if (preg_match('/tvg-name="([^"]*)"/i', $line, $m) && $meta['name'] === '') $meta['name'] = $m[1];
                if (preg_match('/tvg-logo="([^"]*)"/i', $line, $m)) $meta['logo'] = $m[1];
                if (preg_match('/group-title="([^"]*)"/i', $line, $m)) $meta['group'] = $m[1];
            } elseif ($line !== '' && $line[0] !== '#' && $meta) {
                $items[] = $meta + ['url' => $line];
                $meta = null;
            }
        }
        return $items;
    }
    $data = json_decode($body, true);
    if (!is_array($data)) throw new RuntimeException('Formato não reconhecido: a lista precisa ser M3U ou JSON.');
    // aceita {channels:[...]}, {data:[...]}, {items:[...]} ou uma lista direta
    foreach (['channels', 'data', 'items', 'canais', 'result', 'results'] as $k) {
        if (isset($data[$k]) && is_array($data[$k])) { $data = $data[$k]; break; }
    }
    $pick = function (array $r, array $keys) {
        foreach ($keys as $k) if (isset($r[$k]) && is_scalar($r[$k]) && (string)$r[$k] !== '') return (string)$r[$k];
        return '';
    };
    foreach ($data as $r) {
        if (!is_array($r)) continue;
        $items[] = [
            'name' => $pick($r, ['name', 'title', 'nome', 'channel', 'canal']),
            'logo' => $pick($r, ['logo', 'icon', 'image', 'imagem', 'thumbnail', 'tvg_logo']),
            'group' => $pick($r, ['category', 'categoria', 'group', 'grupo', 'genre', 'genero']),
            'url' => $pick($r, ['url', 'stream', 'stream_url', 'link', 'embed', 'src', 'm3u8', 'file']),
        ];
    }
    return $items;
}

/** Importa/atualiza uma lista. Retorna ['added','updated','removed','total'] */
function tv_import_source(array $src): array
{
    $items = tv_parse(tv_http_get($src['url']));
    $res = ['added' => 0, 'updated' => 0, 'removed' => 0, 'total' => 0];
    $filter = array_filter(array_map('trim', explode(',', mb_strtolower((string)($src['groups'] ?? '')))));
    $sid = (string)$src['id'];
    $seen = [];
    $pdo = db();
    $pdo->beginTransaction();
    try {
        foreach ($items as $it) {
            $url = clean_url($it['url']);
            if ($url === '' || trim($it['name']) === '') continue;
            if ($filter && !in_array(mb_strtolower(trim($it['group'])), $filter, true)) continue;
            $key = md5(mb_strtolower(trim($it['name'])) . '|' . mb_strtolower(trim($it['group'])));
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $kind = player_kind_from_url($url);
            if ($kind === 'embed' && preg_match('#\.m3u8?(\?|$)|/hls/|\.ts(\?|$)#i', $url)) $kind = 'hls';
            $logo = clean_url((string)$it['logo'], true) ?: null;
            $cat = mb_substr(trim($it['group']), 0, 80) ?: null;
            $ex = q_one('SELECT id FROM channels WHERE source = ? AND ext_key = ?', [$sid, $key]);
            if ($ex) {
                q('UPDATE channels SET url = ?, kind = ?, logo = COALESCE(?, logo), category = ? WHERE id = ?', [$url, $kind, $logo, $cat, $ex['id']]);
                $res['updated']++;
            } else {
                q('INSERT INTO channels (name, logo, category, url, kind, source, ext_key, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                    [mb_substr(trim($it['name']), 0, 120), $logo, $cat, $url, $kind, $sid, $key, !empty($src['inactive']) ? 'inactive' : 'active']);
                $res['added']++;
            }
        }
        $res['total'] = count($seen);
        // Sincronizar: remove os canais desta lista que não vieram mais
        if (!empty($src['sync']) && $res['total'] > 0) {
            $rows = q_all('SELECT id, ext_key FROM channels WHERE source = ?', [$sid]);
            foreach ($rows as $r) {
                if (!isset($seen[$r['ext_key']])) { q('DELETE FROM channels WHERE id = ?', [$r['id']]); $res['removed']++; }
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return $res;
}

/** Atualiza as listas cujo prazo venceu (chamado pelo Cron e pelo modo visitas) */
function tv_refresh_due(int $maxSeconds = 40): int
{
    $start = time();
    $list = tv_sources();
    $n = 0;
    foreach ($list as $i => $src) {
        if (time() - $start > $maxSeconds) break;
        $h = (int)($src['every'] ?? 0);
        if ($h <= 0 || (int)($src['last'] ?? 0) > time() - $h * 3600) continue;
        try {
            $r = tv_import_source($src);
            $list[$i]['last'] = time();
            $list[$i]['count'] = $r['total'];
            $list[$i]['error'] = '';
            $n++;
        } catch (Throwable $e) {
            $list[$i]['last'] = time();
            $list[$i]['error'] = $e->getMessage();
        }
    }
    if ($n || $list !== tv_sources()) tv_sources_save($list);
    return $n;
}
