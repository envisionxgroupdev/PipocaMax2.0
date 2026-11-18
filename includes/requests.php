<?php
/**
 * Pedidos de filmes, séries e animes.
 *  - O usuário (com conta) busca no TMDB e pede; se outro já pediu, soma o voto ("Eu também quero").
 *  - Quando o título entra no site (importado pelo painel, pela importação automática ou na hora pelo pedido),
 *    o pedido vira "Adicionado" e cada pessoa que pediu recebe aviso no sininho.
 */

const REQ_DAILY_LIMIT = 10; // pedidos + votos por pessoa a cada 24 h

function req_type_name(string $type): string
{
    return ['movie' => 'Filme', 'series' => 'Série', 'anime' => 'Anime'][$type] ?? 'Título';
}

/** Busca no TMDB (filmes e séries juntos) e marca o que já está no site ou já foi pedido */
function req_search(string $term, ?int $uid): array
{
    $term = trim(mb_substr($term, 0, 80));
    if (mb_strlen($term) < 2 || !tmdb_configured()) return [];
    $res = [];
    foreach (['movie', 'tv'] as $kind) {
        try {
            foreach (array_slice(tmdb_search($kind, $term)['results'], 0, 10) as $r) {
                if (!$r['tmdb_id'] || $r['title'] === '') continue;
                $type = $kind === 'movie' ? 'movie' : ($r['is_anime'] ? 'anime' : 'series');
                $res[] = $r + ['media' => $kind, 'type' => $type];
            }
        } catch (Throwable $e) {
            // TMDB fora do ar: devolve o que tiver
        }
    }
    if (!$res) return [];
    // Mais relevantes primeiro: nome exato, depois com capa e ano mais recente
    $low = mb_strtolower($term);
    usort($res, function ($a, $b) use ($low) {
        $ea = mb_strtolower($a['title']) === $low ? 1 : 0;
        $eb = mb_strtolower($b['title']) === $low ? 1 : 0;
        if ($ea !== $eb) return $eb <=> $ea;
        $pa = $a['poster'] ? 1 : 0;
        $pb = $b['poster'] ? 1 : 0;
        if ($pa !== $pb) return $pb <=> $pa;
        return (int)$b['year'] <=> (int)$a['year'];
    });
    $res = array_slice($res, 0, 12);

    $out = [];
    foreach ($res as $r) {
        $types = $r['media'] === 'movie' ? ['movie'] : ['series', 'anime'];
        $have = q_one("SELECT id, type, slug, title FROM contents WHERE tmdb_id = ? AND type IN ('" . implode("','", $types) . "') AND status = 'published' LIMIT 1", [$r['tmdb_id']]);
        $req = q_one('SELECT id, votes, status FROM requests WHERE tmdb_id = ? AND media = ?', [$r['tmdb_id'], $r['media']]);
        $voted = $req && $uid ? (bool)q_val('SELECT 1 FROM request_votes WHERE request_id = ? AND user_id = ?', [$req['id'], $uid]) : false;
        $out[] = [
            'tmdb_id' => $r['tmdb_id'],
            'media' => $r['media'],
            'type' => $r['type'],
            'type_name' => req_type_name($r['type']),
            'title' => $r['title'],
            'year' => $r['year'],
            'poster' => img($r['poster'], 'w185'),
            'url' => $have ? content_url($have) : null,
            'req_id' => $req ? (int)$req['id'] : null,
            'votes' => $req ? (int)$req['votes'] : 0,
            'status' => $req['status'] ?? null,
            'voted' => $voted,
        ];
    }
    return $out;
}

/** Quantos pedidos/votos a pessoa já fez nas últimas 24 h */
function req_used_today(int $uid): int
{
    return (int)q_val('SELECT COUNT(*) FROM request_votes WHERE user_id = ? AND created_at > ?', [$uid, date('Y-m-d H:i:s', time() - 86400)]);
}

/** Faz um pedido novo (ou soma o voto se já existir). Devolve o pedido. */
function req_add(int $uid, int $tmdbId, string $media): array
{
    $media = $media === 'tv' ? 'tv' : 'movie';
    if ($tmdbId <= 0) throw new RuntimeException('Título inválido.');
    $req = q_one('SELECT * FROM requests WHERE tmdb_id = ? AND media = ?', [$tmdbId, $media]);
    if (!$req) {
        // Confere os dados direto no TMDB (não confia no que veio do navegador)
        $d = tmdb_request($media . '/' . $tmdbId, []);
        if (empty($d['id'])) throw new RuntimeException('Não encontrei esse título.');
        $date = $d['release_date'] ?? $d['first_air_date'] ?? '';
        $isAnime = $media === 'tv' && in_array(16, array_column($d['genres'] ?? [], 'id'), true) && ($d['original_language'] ?? '') === 'ja';
        $type = $media === 'movie' ? 'movie' : ($isAnime ? 'anime' : 'series');
        // Já está no site?
        $types = $media === 'movie' ? "'movie'" : "'series','anime'";
        if ($have = q_one("SELECT id, type, slug FROM contents WHERE tmdb_id = ? AND type IN ($types) AND status = 'published' LIMIT 1", [$tmdbId])) {
            return ['already' => true, 'url' => content_url($have)];
        }
        if (req_used_today($uid) >= REQ_DAILY_LIMIT) throw new RuntimeException('Você já fez ' . REQ_DAILY_LIMIT . ' pedidos hoje. Volte amanhã!');
        q('INSERT INTO requests (tmdb_id, media, type, title, original_title, year, poster, votes, status, user_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?)', [
            $tmdbId, $media, $type,
            mb_substr($d['title'] ?? $d['name'] ?? 'Sem título', 0, 255),
            mb_substr($d['original_title'] ?? $d['original_name'] ?? '', 0, 255) ?: null,
            $date ? (int)substr($date, 0, 4) : null,
            $d['poster_path'] ?? null,
            'pending', $uid, date('Y-m-d H:i:s'), date('Y-m-d H:i:s'),
        ]);
        $req = q_one('SELECT * FROM requests WHERE tmdb_id = ? AND media = ?', [$tmdbId, $media]);
    }
    if ($req['status'] === 'added') {
        $c = $req['content_id'] ? q_one('SELECT id, type, slug FROM contents WHERE id = ?', [$req['content_id']]) : null;
        return ['already' => true, 'url' => $c ? content_url($c) : null];
    }
    req_vote($uid, (int)$req['id'], true);
    return q_one('SELECT id, votes, status FROM requests WHERE id = ?', [$req['id']]) + ['voted' => true];
}

/** Liga/desliga o voto da pessoa num pedido */
function req_vote(int $uid, int $rid, bool $on): array
{
    $req = q_one('SELECT id, status FROM requests WHERE id = ?', [$rid]);
    if (!$req) throw new RuntimeException('Pedido não encontrado.');
    $has = (bool)q_val('SELECT 1 FROM request_votes WHERE request_id = ? AND user_id = ?', [$rid, $uid]);
    if ($on && !$has) {
        if ($req['status'] !== 'pending') throw new RuntimeException('Esse pedido já foi respondido.');
        if (req_used_today($uid) >= REQ_DAILY_LIMIT) throw new RuntimeException('Você já fez ' . REQ_DAILY_LIMIT . ' pedidos hoje. Volte amanhã!');
        q('INSERT INTO request_votes (request_id, user_id, created_at) VALUES (?, ?, ?)', [$rid, $uid, date('Y-m-d H:i:s')]);
    } elseif (!$on && $has) {
        q('DELETE FROM request_votes WHERE request_id = ? AND user_id = ?', [$rid, $uid]);
    }
    $votes = (int)q_val('SELECT COUNT(*) FROM request_votes WHERE request_id = ?', [$rid]);
    q('UPDATE requests SET votes = ?, updated_at = ? WHERE id = ?', [$votes, date('Y-m-d H:i:s'), $rid]);
    // Ninguém mais quer e ainda não foi respondido: some da lista
    if ($votes === 0 && $req['status'] === 'pending') q('DELETE FROM requests WHERE id = ?', [$rid]);
    return ['votes' => $votes, 'voted' => $on];
}

/** Marca o pedido como atendido e avisa quem pediu (chamado quando um título entra no site) */
function req_fulfill(int $rid, int $contentId): void
{
    $req = q_one('SELECT * FROM requests WHERE id = ?', [$rid]);
    $c = q_one("SELECT id, type, slug, title, poster, backdrop FROM contents WHERE id = ? AND status = 'published'", [$contentId]);
    if (!$req || !$c || $req['status'] === 'added') return;
    q('UPDATE requests SET status = ?, content_id = ?, note = NULL, updated_at = ?, done_at = ? WHERE id = ?',
        ['added', $contentId, date('Y-m-d H:i:s'), date('Y-m-d H:i:s'), $rid]);
    $voters = q_all('SELECT user_id FROM request_votes WHERE request_id = ?', [$rid]);
    foreach ($voters as $v) {
        notif_create([
            'title' => 'Seu pedido chegou: ' . $c['title'],
            'message' => [
                'movie' => 'O filme que você pediu já está no site. Bom filme! 🍿',
                'series' => 'A série que você pediu já está no site. Boa maratona! 🍿',
                'anime' => 'O anime que você pediu já está no site. Boa maratona! 🍿',
            ][$c['type']] ?? 'O título que você pediu já está no site. Aproveite! 🍿',
            'link' => site_url(content_url($c)),
            'image' => $c['backdrop'] ? img($c['backdrop'], 'w300') : ($c['poster'] ? img($c['poster'], 'w185') : ''),
            'content_id' => $contentId,
            'audience' => 'users',
            'user_id' => (int)$v['user_id'],
            'days' => 30,
        ]);
    }
}

/** Depois de importar um título: atende o pedido dele, se houver */
function req_on_imported(int $tmdbId, string $type, int $contentId): void
{
    try {
        $media = $type === 'movie' ? 'movie' : 'tv';
        $rid = q_val("SELECT id FROM requests WHERE tmdb_id = ? AND media = ? AND status <> 'added'", [$tmdbId, $media]);
        if ($rid) req_fulfill((int)$rid, $contentId);
    } catch (Throwable $e) {
        // tabela ainda não existe (antes da migração): ignora
    }
}

/** Confere pedidos pendentes que já estão no site (títulos adicionados por outro caminho) */
function req_sync(): int
{
    $n = 0;
    try {
        $rows = q_all("SELECT r.id, c.id AS cid FROM requests r JOIN contents c ON c.tmdb_id = r.tmdb_id AND c.status = 'published'
            AND ((r.media = 'movie' AND c.type = 'movie') OR (r.media = 'tv' AND c.type IN ('series', 'anime')))
            WHERE r.status <> 'added' LIMIT 50");
        foreach ($rows as $r) { req_fulfill((int)$r['id'], (int)$r['cid']); $n++; }
    } catch (Throwable $e) {
    }
    return $n;
}

function req_pending_count(): int
{
    try { return (int)q_val("SELECT COUNT(*) FROM requests WHERE status = 'pending'"); } catch (Throwable $e) { return 0; }
}
