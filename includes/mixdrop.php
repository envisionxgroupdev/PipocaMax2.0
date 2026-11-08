<?php
/**
 * MixDrop: envio de vídeos por link (envio remoto) e criação automática do player.
 * Documentação: https://mixdrop.ag/api
 */

function mixdrop_configured(): bool
{
    return trim((string)setting('mixdrop_email')) !== '' && trim((string)setting('mixdrop_key')) !== '';
}

function mixdrop_api(string $action, array $params = []): array
{
    if (!mixdrop_configured()) throw new RuntimeException('Configure o e-mail e a chave da API do MixDrop.');
    $base = rtrim((string)setting('mixdrop_api', 'https://api.mixdrop.ag'), '/') ?: 'https://api.mixdrop.ag';
    $params = ['email' => trim((string)setting('mixdrop_email')), 'key' => trim((string)setting('mixdrop_key'))] + $params;
    $url = $base . '/' . $action . '?' . http_build_query($params);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_FOLLOWLOCATION => true, CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; PipocaMax)',
    ]);
    $body = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false) throw new RuntimeException('Não consegui falar com o MixDrop: ' . $err);
    $data = json_decode((string)$body, true);
    if (!is_array($data)) throw new RuntimeException('Resposta inesperada do MixDrop (código ' . $code . '). Confira o endereço da API em Configurações do MixDrop.');
    if (empty($data['success'])) {
        $msg = is_string($data['result'] ?? null) ? $data['result'] : ($data['msg'] ?? $data['message'] ?? 'pedido recusado');
        throw new RuntimeException('MixDrop: ' . $msg . (stripos((string)$msg, 'key') !== false || stripos((string)$msg, 'email') !== false ? ' (confira o e-mail e a chave da API)' : ''));
    }
    return $data['result'] ?? [];
}

/** Pasta "Pipoca Max" no MixDrop (criada uma vez) */
function mixdrop_folder(): ?int
{
    $id = (int)setting('mixdrop_folder', '0');
    if ($id > 0) return $id;
    try {
        $r = mixdrop_api('foldercreate', ['title' => site_name()]);
        $id = (int)($r['id'] ?? 0);
        if ($id > 0) set_setting('mixdrop_folder', (string)$id);
        return $id ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/** Nome do arquivo no MixDrop: "Título (2024)" ou "Série - T01E02" */
function mixdrop_title(?int $contentId, ?int $episodeId): string
{
    if ($episodeId) {
        $e = q_one('SELECT e.episode_number, s.season_number, c.title FROM episodes e JOIN seasons s ON s.id = e.season_id JOIN contents c ON c.id = e.content_id WHERE e.id = ?', [$episodeId]);
        if ($e) return sprintf('%s - T%02dE%02d', $e['title'], $e['season_number'], $e['episode_number']);
    }
    if ($contentId) {
        $c = q_one('SELECT title, year FROM contents WHERE id = ?', [$contentId]);
        if ($c) return $c['title'] . ($c['year'] ? ' (' . $c['year'] . ')' : '');
    }
    return 'video';
}

/** Cria um envio: o MixDrop baixa o vídeo do link. Retorna o id do envio. */
function mixdrop_send(string $sourceUrl, ?int $contentId, ?int $episodeId, string $audio = ''): int
{
    $src = clean_url($sourceUrl);
    if ($src === '') throw new RuntimeException('Link inválido: use um link direto começando com http:// ou https://');
    if (!$contentId && !$episodeId) throw new RuntimeException('Escolha o filme ou episódio do vídeo.');
    if ($episodeId && !$contentId) $contentId = (int)q_val('SELECT content_id FROM episodes WHERE id = ?', [$episodeId]) ?: null;
    $params = ['url' => $src, 'name' => mixdrop_title($contentId, $episodeId)];
    if ($folder = mixdrop_folder()) $params['folder'] = $folder;
    $r = mixdrop_api('remoteupload', $params);
    q('INSERT INTO mixdrop_jobs (content_id, episode_id, source_url, remote_id, fileref, embed_url, status, progress, audio) VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?)', [
        $contentId, $episodeId, $src, (string)($r['id'] ?? ''), (string)($r['fileref'] ?? ''), clean_url((string)($r['embedurl'] ?? '')), 'queued', mb_substr($audio, 0, 30),
    ]);
    return (int)db()->lastInsertId();
}

/** Coloca na fila para enviar depois (usado no envio em massa) */
function mixdrop_enqueue(string $sourceUrl, ?int $contentId, ?int $episodeId, string $audio = ''): int
{
    $src = clean_url($sourceUrl);
    if ($src === '') return 0;
    q("INSERT INTO mixdrop_jobs (content_id, episode_id, source_url, status, audio) VALUES (?, ?, ?, 'tosend', ?)", [$contentId, $episodeId, $src, mb_substr($audio, 0, 30)]);
    return (int)db()->lastInsertId();
}

/** Envia ao MixDrop os itens da fila "a enviar" (alguns por vez) */
function mixdrop_send_queued(int $max = 5): int
{
    $n = 0;
    foreach (q_all("SELECT * FROM mixdrop_jobs WHERE status = 'tosend' ORDER BY id ASC LIMIT " . max(1, $max)) as $j) {
        try {
            $params = ['url' => $j['source_url'], 'name' => mixdrop_title($j['content_id'] ? (int)$j['content_id'] : null, $j['episode_id'] ? (int)$j['episode_id'] : null)];
            if ($folder = mixdrop_folder()) $params['folder'] = $folder;
            $r = mixdrop_api('remoteupload', $params);
            q("UPDATE mixdrop_jobs SET status = 'queued', remote_id = ?, fileref = ?, embed_url = ?, error = NULL WHERE id = ?",
                [(string)($r['id'] ?? ''), (string)($r['fileref'] ?? ''), clean_url((string)($r['embedurl'] ?? '')), $j['id']]);
        } catch (Throwable $e) {
            q("UPDATE mixdrop_jobs SET status = 'error', error = ? WHERE id = ?", [mb_substr($e->getMessage(), 0, 250), $j['id']]);
        }
        $n++;
    }
    return $n;
}

/** Confere envios pendentes. Ao concluir, cria o player no filme/episódio. Retorna quantos mudaram. */
function mixdrop_check(int $max = 20): int
{
    if (!mixdrop_configured()) return 0;
    $sent = mixdrop_send_queued(5);
    $jobs = q_all("SELECT * FROM mixdrop_jobs WHERE status IN ('queued', 'downloading', 'converting') ORDER BY updated_at ASC LIMIT " . max(1, $max));
    $changed = 0;
    foreach ($jobs as $j) {
        try {
            $status = $j['status'];
            $progress = (int)$j['progress'];
            $fileref = $j['fileref'];
            $error = null;
            if ($status !== 'converting' && $j['remote_id'] !== '') {
                $r = mixdrop_api('remotestatus', ['id' => $j['remote_id']]);
                $st = mb_strtolower((string)($r['status'] ?? ''));
                $progress = (int)($r['progress'] ?? $progress);
                if (!empty($r['fileref'])) $fileref = (string)$r['fileref'];
                if (str_contains($st, 'error')) { $status = 'error'; $error = (string)($r['error'] ?? 'Erro ao baixar o vídeo do link.'); }
                elseif (preg_match('/complet|done|finish|success|^ok$/', $st) || ($progress >= 100 && !str_contains($st, 'queue'))) { $status = 'converting'; $progress = 100; }
                elseif (str_contains($st, 'download')) { $status = str_contains($st, 'queue') ? 'queued' : 'downloading'; }
            }
            if ($status === 'converting' && $fileref !== '') {
                $info = mixdrop_api('fileinfo2', ['ref' => [$fileref]]);
                $f = $info[$fileref] ?? (is_array($info) ? reset($info) : null);
                $fst = mb_strtolower((string)($f['status'] ?? ''));
                if ($fst === 'ok') {
                    $embed = clean_url((string)($f['embedurl'] ?? '')) ?: $j['embed_url'];
                    if ($embed === '') throw new RuntimeException('O MixDrop não informou o link do player.');
                    $pid = mixdrop_make_player($j, $embed);
                    q("UPDATE mixdrop_jobs SET status = 'done', progress = 100, fileref = ?, embed_url = ?, player_id = ?, error = NULL WHERE id = ?", [$fileref, $embed, $pid, $j['id']]);
                    if (function_exists('cache_bump')) cache_bump();
                    $changed++;
                    continue;
                }
                if ($fst === 'notfound' || !empty($f['deleted'])) { $status = 'error'; $error = 'O arquivo não foi encontrado no MixDrop (removido?).'; }
            }
            if ($status !== $j['status'] || $progress !== (int)$j['progress'] || $error) $changed++;
            q('UPDATE mixdrop_jobs SET status = ?, progress = ?, fileref = ?, error = ? WHERE id = ?', [$status, $progress, $fileref, $error, $j['id']]);
        } catch (Throwable $e) {
            // falha temporária: tenta de novo depois, sem perder o envio
            q('UPDATE mixdrop_jobs SET error = ? WHERE id = ?', [mb_substr($e->getMessage(), 0, 250), $j['id']]);
        }
    }
    return $changed + $sent;
}

function mixdrop_make_player(array $job, string $embed): int
{
    $name = setting('mixdrop_player_name', 'MixDrop') ?: 'MixDrop';
    if ($job['episode_id']) {
        $exists = q_val('SELECT id FROM players WHERE episode_id = ? AND url = ?', [$job['episode_id'], $embed]);
        if ($exists) return (int)$exists;
        $sort = (int)q_val('SELECT COALESCE(MAX(sort), -1) + 1 FROM players WHERE episode_id = ?', [$job['episode_id']]);
        q('INSERT INTO players (episode_id, name, url, kind, audio, sort) VALUES (?, ?, ?, ?, ?, ?)', [$job['episode_id'], $name, $embed, 'embed', $job['audio'] ?: null, $sort]);
    } else {
        $exists = q_val('SELECT id FROM players WHERE content_id = ? AND url = ?', [$job['content_id'], $embed]);
        if ($exists) return (int)$exists;
        $sort = (int)q_val('SELECT COALESCE(MAX(sort), -1) + 1 FROM players WHERE content_id = ?', [$job['content_id']]);
        q('INSERT INTO players (content_id, name, url, kind, audio, sort) VALUES (?, ?, ?, ?, ?, ?)', [$job['content_id'], $name, $embed, 'embed', $job['audio'] ?: null, $sort]);
    }
    return (int)db()->lastInsertId();
}

function mixdrop_pending(): int
{
    try {
        return (int)q_val("SELECT COUNT(*) FROM mixdrop_jobs WHERE status IN ('tosend', 'queued', 'downloading', 'converting')");
    } catch (Throwable $e) {
        return 0;
    }
}

/** Interpreta a lista em massa de uma série: "S01E02 link", "1x02 link", "T1E2 link" ou só "link" (em sequência) */
function mixdrop_parse_series_lines(string $text, int $startSeason, int $startEpisode): array
{
    $out = [];
    $s = max(0, $startSeason);
    $e = max(1, $startEpisode);
    foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (!preg_match('#(https?://\S+)#i', $line, $m)) continue;
        $url = $m[1];
        $prefix = trim(str_replace($url, '', $line));
        if (preg_match('/(?:s|t)\s*(\d{1,3})\s*[ex]\s*(\d{1,4})/i', $prefix, $mm) || preg_match('/(\d{1,3})\s*x\s*(\d{1,4})/i', $prefix, $mm)) {
            $s = (int)$mm[1];
            $e = (int)$mm[2];
        }
        $out[] = ['season' => $s, 'episode' => $e, 'url' => $url];
        $e++;
    }
    return $out;
}
