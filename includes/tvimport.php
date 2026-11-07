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
        CURLOPT_NOPROGRESS => false,
        CURLOPT_PROGRESSFUNCTION => fn($c, $dt, $d) => $d > 40 * 1024 * 1024 ? 1 : 0, // máx. 40 MB
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($body === false) throw new RuntimeException(str_contains($err, 'aborted') ? 'A lista é grande demais (mais de 40 MB).' : 'Não consegui baixar a lista: ' . $err);
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
    if (!is_array($data)) {
        $start = mb_strtolower(substr(ltrim($body), 0, 200));
        if (str_contains($start, '<html') || str_contains($start, '<!doctype')) throw new RuntimeException('O link abriu uma página de site (HTML), não uma lista de canais. Use o link direto da lista M3U ou JSON.');
        if (ltrim($body) !== '' && in_array(ltrim($body)[0], ['{', '['], true)) throw new RuntimeException('O JSON da lista está com defeito (' . json_last_error_msg() . ').');
        throw new RuntimeException('Formato não reconhecido: a lista precisa ser M3U ou JSON.');
    }
    $items = tv_json_walk($data, '');
    if (!$items) {
        $keys = tv_json_keys($data);
        throw new RuntimeException('O JSON foi lido, mas não achei canais com nome e link.' . ($keys ? ' Campos encontrados: ' . implode(', ', array_slice($keys, 0, 15)) . '.' : ''));
    }
    return $items;
}

/** Procura canais em qualquer parte do JSON (listas, grupos por categoria, várias camadas) */
function tv_json_walk($node, string $group, int $depth = 0): array
{
    static $nameKeys = ['name', 'nome', 'title', 'titulo', 'channel', 'canal', 'channel_name', 'label', 'tvg_name', 'tvg-name'];
    static $urlKeys = ['url', 'stream', 'stream_url', 'streamurl', 'streamUrl', 'link', 'embed', 'embed_url', 'src', 'source', 'm3u8', 'hls', 'file', 'playback_url', 'play_url', 'video', 'video_url', 'href', 'iframe'];
    static $logoKeys = ['logo', 'icon', 'image', 'imagem', 'img', 'thumbnail', 'thumb', 'poster', 'tvg_logo', 'tvg-logo', 'logo_url', 'cover'];
    static $groupKeys = ['category', 'categoria', 'group', 'grupo', 'genre', 'genero', 'group_title', 'group-title', 'category_name'];
    if ($depth > 8 || !is_array($node)) return [];
    $pick = function (array $r, array $keys) {
        foreach ($keys as $k) {
            if (isset($r[$k]) && is_scalar($r[$k]) && trim((string)$r[$k]) !== '') return trim((string)$r[$k]);
            if (isset($r[$k]) && is_array($r[$k])) { // ex.: "stream": {"url": "..."} ou "logo": {"src": "..."}
                foreach (['url', 'src', 'href', 'link', 'name'] as $sub) if (isset($r[$k][$sub]) && is_scalar($r[$k][$sub])) return trim((string)$r[$k][$sub]);
            }
        }
        return '';
    };
    $out = [];
    $isList = array_keys($node) === range(0, count($node) - 1);
    if (!$isList) {
        $url = $pick($node, $urlKeys);
        $name = $pick($node, $nameKeys);
        if ($url !== '' && preg_match('#^https?://#i', $url) && $name !== '') {
            return [[
                'name' => $name,
                'logo' => $pick($node, $logoKeys),
                'group' => $pick($node, $groupKeys) ?: $group,
                'url' => $url,
            ]];
        }
        // objeto de grupo: {"name":"Esportes","channels":[...]} ou {"Esportes":[...], "Notícias":[...]}
        $g = $pick($node, array_merge($groupKeys, ['name', 'nome', 'title']));
        foreach ($node as $k => $v) {
            if (!is_array($v)) continue;
            $childGroup = $group;
            if (is_string($k) && !in_array(mb_strtolower($k), ['channels', 'canais', 'data', 'items', 'result', 'results', 'list', 'lista', 'streams', 'playlist', 'response'], true) && !is_numeric($k)) {
                $childGroup = $k;
            } elseif ($g !== '') {
                $childGroup = $g;
            }
            $out = array_merge($out, tv_json_walk($v, $childGroup, $depth + 1));
        }
        return $out;
    }
    foreach ($node as $v) {
        if (is_string($v) && preg_match('#^https?://#i', $v)) {
            // lista só de links
            $n = urldecode(pathinfo((string)parse_url($v, PHP_URL_PATH), PATHINFO_FILENAME));
            $out[] = ['name' => $n ?: 'Canal', 'logo' => '', 'group' => $group, 'url' => $v];
        } elseif (is_array($v)) {
            $out = array_merge($out, tv_json_walk($v, $group, $depth + 1));
        }
    }
    return $out;
}

/** Lista os nomes de campos do JSON (para mensagens de erro) */
function tv_json_keys($node, int $depth = 0, array &$acc = []): array
{
    if ($depth > 4 || !is_array($node)) return array_keys($acc);
    foreach ($node as $k => $v) {
        if (is_string($k)) $acc[$k] = true;
        if (is_array($v)) tv_json_keys($v, $depth + 1, $acc);
        if (count($acc) > 30) break;
    }
    return array_keys($acc);
}

/** Normaliza os itens da lista: filtra categorias, remove repetidos e inválidos */
function tv_normalize(array $src, array $items): array
{
    $filter = array_filter(array_map('trim', explode(',', mb_strtolower((string)($src['groups'] ?? '')))));
    $rows = [];
    foreach ($items as $it) {
        $url = clean_url((string)($it['url'] ?? ''));
        $name = trim((string)($it['name'] ?? ''));
        // limpa marcações comuns de listas: "(720p)", "[Not 24/7]", "[Geo-blocked]"
        $name = trim(preg_replace(['/\s*\(\d{3,4}p\)/i', '/\s*\[[^\]]*\]/'], '', $name)) ?: $name;
        if ($url === '' || $name === '') continue;
        $group = trim((string)($it['group'] ?? ''));
        if ($filter && !in_array(mb_strtolower($group), $filter, true)) continue;
        $key = md5(mb_strtolower($name) . '|' . mb_strtolower($group));
        if (isset($rows[$key])) continue;
        $kind = player_kind_from_url($url);
        if ($kind === 'embed' && preg_match('#\.m3u8?(\?|$)|/hls/|\.ts(\?|$)#i', $url)) $kind = 'hls';
        $rows[$key] = ['k' => $key, 'n' => mb_substr($name, 0, 120), 'l' => clean_url((string)($it['logo'] ?? ''), true), 'g' => mb_substr($group, 0, 80), 'u' => $url, 't' => $kind];
    }
    return array_values($rows);
}

/** Grava um pedaço de canais (em transação) */
function tv_upsert(string $sid, array $rows, bool $inactive = false): array
{
    $res = ['added' => 0, 'updated' => 0];
    $pdo = db();
    $pdo->beginTransaction();
    try {
        foreach ($rows as $r) {
            $ex = q_one('SELECT id FROM channels WHERE source = ? AND ext_key = ?', [$sid, $r['k']]);
            if ($ex) {
                q('UPDATE channels SET url = ?, kind = ?, logo = COALESCE(?, logo), category = ? WHERE id = ?', [$r['u'], $r['t'], $r['l'] ?: null, $r['g'] ?: null, $ex['id']]);
                $res['updated']++;
            } else {
                q('INSERT INTO channels (name, logo, category, url, kind, source, ext_key, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                    [$r['n'], $r['l'] ?: null, $r['g'] ?: null, $r['u'], $r['t'], $sid, $r['k'], $inactive ? 'inactive' : 'active']);
                $res['added']++;
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return $res;
}

/** Remove os canais da lista que não vieram mais */
function tv_sync(string $sid, array $keys): int
{
    if (!$keys) return 0;
    $keep = array_flip($keys);
    $n = 0;
    foreach (q_all('SELECT id, ext_key FROM channels WHERE source = ?', [$sid]) as $r) {
        if (!isset($keep[$r['ext_key']])) { q('DELETE FROM channels WHERE id = ?', [$r['id']]); $n++; }
    }
    return $n;
}

function tv_cache_file(string $sid): string
{
    $dir = PM_ROOT . '/includes/logs';
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    return $dir . '/tvq_' . preg_replace('/[^a-z0-9]/', '', $sid) . '.json';
}

function tv_find_source(string $sid): ?array
{
    foreach (tv_sources() as $s) if ($s['id'] === $sid) return $s;
    return null;
}

function tv_update_source(string $sid, array $changes): void
{
    $list = tv_sources();
    foreach ($list as $i => $s) if ($s['id'] === $sid) $list[$i] = array_merge($s, $changes);
    tv_sources_save($list);
}

/** Importa/atualiza uma lista inteira de uma vez (usado pelo Cron, que não tem limite de tempo) */
function tv_import_source(array $src): array
{
    $rows = tv_normalize($src, tv_parse(tv_http_get($src['url'])));
    $res = ['added' => 0, 'updated' => 0, 'removed' => 0, 'total' => count($rows)];
    foreach (array_chunk($rows, 300) as $chunk) {
        $r = tv_upsert((string)$src['id'], $chunk, !empty($src['inactive']));
        $res['added'] += $r['added'];
        $res['updated'] += $r['updated'];
    }
    if (!empty($src['sync']) && $rows) $res['removed'] = tv_sync((string)$src['id'], array_column($rows, 'k'));
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
