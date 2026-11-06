<?php
/**
 * Funções utilitárias do Pipoca Max.
 */

$GLOBALS['PM_SETTINGS'] = [];

function load_settings(): void
{
    $rows = q_all('SELECT `key`, `value` FROM settings');
    foreach ($rows as $r) {
        $GLOBALS['PM_SETTINGS'][$r['key']] = $r['value'];
    }
}

function setting(string $key, $default = '')
{
    return $GLOBALS['PM_SETTINGS'][$key] ?? $default;
}

function set_setting(string $key, $value): void
{
    q('INSERT INTO settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)', [$key, (string)$value]);
    $GLOBALS['PM_SETTINGS'][$key] = (string)$value;
}

function site_name(): string
{
    return setting('site_name', 'Pipoca Max') ?: 'Pipoca Max';
}

/** Escapa HTML */
function e($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function url(string $path = ''): string
{
    return BASE_URL . '/' . ltrim($path, '/');
}

function asset(string $path): string
{
    $file = PM_ROOT . '/assets/' . ltrim($path, '/');
    $v = is_file($file) ? filemtime($file) : PM_VERSION;
    return url('assets/' . ltrim($path, '/')) . '?v=' . $v;
}

function redirect(string $to): void
{
    if (!preg_match('#^https?://#', $to) && strpos($to, BASE_URL . '/') !== 0) {
        $to = url($to);
    }
    header('Location: ' . $to);
    exit;
}

/** Imagem: aceita caminho do TMDB (/abc.jpg) ou URL completa */
function img(?string $path, string $size = 'w342'): string
{
    if (!$path) {
        return asset('img/placeholder.svg');
    }
    if (preg_match('#^https?://#', $path)) {
        return $path;
    }
    if ($path[0] === '/') {
        return 'https://image.tmdb.org/t/p/' . $size . $path;
    }
    return url($path);
}

/** Aceita só links http(s). Com $allowPath, aceita também caminhos do TMDB (/abc.jpg) e locais. */
function clean_url(string $u, bool $allowPath = false): string
{
    $u = trim($u);
    if ($u === '') return '';
    if (preg_match('#^https?://[^\s<>"\']+$#i', $u)) return $u;
    if ($allowPath && preg_match('#^/?[A-Za-z0-9_\-./]+$#', $u) && strpos($u, '..') === false && !str_starts_with($u, '//')) return $u;
    return '';
}

/** Imagem com endereço completo (para redes sociais e Google) */
function abs_img(?string $path, string $size = 'w780'): ?string
{
    if (!$path) return null;
    $u = img($path, $size);
    return preg_match('#^https?://#', $u) ? $u : site_url($u);
}

function type_label(string $type, bool $plural = false): string
{
    $map = [
        'movie' => ['Filme', 'Filmes'],
        'series' => ['Série', 'Séries'],
        'anime' => ['Anime', 'Animes'],
    ];
    return $map[$type][$plural ? 1 : 0] ?? $type;
}

function type_page(string $type): string
{
    return ['movie' => 'filmes.php', 'series' => 'series.php', 'anime' => 'animes.php'][$type] ?? 'index.php';
}

/** URLs amigáveis (/filme/123-nome) — exige mod_rewrite (Apache/LiteSpeed) */
function pretty_urls(): bool
{
    return setting('pretty_urls', '1') === '1';
}

/** Endereço completo do site (https://seusite.com) */
function site_url(string $path = ''): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (($_SERVER['SERVER_PORT'] ?? '') == 443);
    $host = preg_replace('/[^a-zA-Z0-9.\-:]/', '', $_SERVER['HTTP_HOST'] ?? 'localhost');
    return ($https ? 'https' : 'http') . '://' . $host . ($path === '' ? BASE_URL . '/' : $path);
}

function content_url(array $c): string
{
    $slug = preg_replace('/[^a-z0-9-]/', '', (string)($c['slug'] ?? ''));
    if (pretty_urls()) {
        $prefix = ['movie' => 'filme', 'series' => 'serie', 'anime' => 'anime'][$c['type'] ?? 'movie'] ?? 'filme';
        return url($prefix . '/' . (int)$c['id'] . ($slug !== '' ? '-' . $slug : ''));
    }
    return url('titulo.php?id=' . (int)$c['id'] . ($slug !== '' ? '&t=' . $slug : ''));
}

function channel_url(array $ch): string
{
    if (pretty_urls()) {
        return url('canal/' . (int)$ch['id'] . '-' . slugify((string)$ch['name']));
    }
    return url('canal.php?id=' . (int)$ch['id']);
}

/** Páginas principais: page_url('filmes') -> /filmes ou /filmes.php */
function page_url(string $name, string $query = ''): string
{
    $u = pretty_urls() ? url($name) : url($name . '.php');
    return $u . ($query !== '' ? '?' . $query : '');
}

function slugify(string $text): string
{
    $text = trim($text);
    $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
    if ($t === false || $t === '') {
        $t = $text;
    }
    $t = strtolower($t);
    $t = preg_replace('/[^a-z0-9]+/', '-', $t);
    $t = trim($t, '-');
    return $t !== '' ? $t : 'titulo';
}

/* ---------- CSRF ---------- */
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): void
{
    $sent = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
        http_response_code(419);
        exit('Sessão expirada. Volte, atualize a página e tente novamente.');
    }
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

/* ---------- Mensagens flash ---------- */
function flash(string $type, string $msg): void
{
    $_SESSION['flash'][] = ['type' => $type, 'msg' => $msg];
}

function flashes(): string
{
    $out = '';
    foreach ($_SESSION['flash'] ?? [] as $f) {
        $out .= '<div class="alert alert-' . e($f['type']) . '">' . e($f['msg']) . '</div>';
    }
    unset($_SESSION['flash']);
    return $out;
}

/* ---------- Entrada ---------- */
function in_str(string $key, string $default = ''): string
{
    $v = $_POST[$key] ?? $_GET[$key] ?? $default;
    return is_string($v) ? trim($v) : $default;
}

function in_int(string $key, int $default = 0): int
{
    $v = $_POST[$key] ?? $_GET[$key] ?? $default;
    return is_numeric($v) ? (int)$v : $default;
}

/* ---------- Paginação ---------- */
function paginate(int $total, int $perPage, int $page, string $baseUrl): string
{
    $pages = (int)ceil($total / max(1, $perPage));
    if ($pages <= 1) {
        return '';
    }
    $sep = strpos($baseUrl, '?') === false ? '?' : '&';
    $html = '<nav class="pagination">';
    $start = max(1, $page - 2);
    $end = min($pages, $page + 2);
    if ($page > 1) {
        $html .= '<a href="' . e($baseUrl . $sep . 'p=' . ($page - 1)) . '">‹</a>';
    }
    if ($start > 1) {
        $html .= '<a href="' . e($baseUrl . $sep . 'p=1') . '">1</a>' . ($start > 2 ? '<span>…</span>' : '');
    }
    for ($i = $start; $i <= $end; $i++) {
        $html .= $i === $page
            ? '<span class="current">' . $i . '</span>'
            : '<a href="' . e($baseUrl . $sep . 'p=' . $i) . '">' . $i . '</a>';
    }
    if ($end < $pages) {
        $html .= ($end < $pages - 1 ? '<span>…</span>' : '') . '<a href="' . e($baseUrl . $sep . 'p=' . $pages) . '">' . $pages . '</a>';
    }
    if ($page < $pages) {
        $html .= '<a href="' . e($baseUrl . $sep . 'p=' . ($page + 1)) . '">›</a>';
    }
    return $html . '</nav>';
}

/* ---------- Manutenção ---------- */
function check_maintenance(): void
{
    if (setting('maintenance') === '1' && !is_admin()) {
        http_response_code(503);
        header('Retry-After: 3600');
        require PM_ROOT . '/manutencao.php';
        exit;
    }
}

/** Páginas públicas que exigem login quando a opção "exigir login" está ativa */
function check_require_login(): void
{
    if (setting('require_login') === '1' && !current_user()) {
        flash('info', 'Entre na sua conta para assistir.');
        redirect(page_url('login', 'voltar=' . urlencode($_SERVER['REQUEST_URI'] ?? '')));
    }
}

/* ---------- Players ---------- */
function player_kind_from_url(string $url): string
{
    $path = strtolower(parse_url($url, PHP_URL_PATH) ?? '');
    if (str_ends_with($path, '.m3u8')) {
        return 'hls';
    }
    if (preg_match('/\.(mp4|webm|ogg|mkv|mov)$/', $path)) {
        return 'video';
    }
    return 'embed';
}

/** Substitui variáveis de modelo de player */
function fill_player_template(string $tpl, array $vars): string
{
    $repl = [];
    foreach ($vars as $k => $v) {
        $repl['{' . $k . '}'] = (string)$v;
    }
    return strtr($tpl, $repl);
}

/** Lê modelos de player (uma linha por player: Nome|URL|Áudio|Qualidade) */
function parse_player_templates(string $text): array
{
    $out = [];
    foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        $parts = array_map('trim', explode('|', $line));
        if (count($parts) < 2 || $parts[1] === '') {
            continue;
        }
        $out[] = [
            'name' => $parts[0] ?: 'Player',
            'url' => $parts[1],
            'audio' => $parts[2] ?? '',
            'quality' => $parts[3] ?? '',
        ];
    }
    return $out;
}

/**
 * Players da API (gerados na hora a partir dos modelos de Configurações > API de players).
 * Não ficam gravados no banco: se a API mudar de endereço, basta trocar o modelo.
 */
function api_players(string $setting, array $vars): array
{
    $out = [];
    foreach (parse_player_templates((string)setting($setting)) as $i => $t) {
        // Pula o modelo se ele precisar de uma variável que o título não tem (ex.: sem IMDb)
        preg_match_all('/\{(\w+)\}/', $t['url'], $m);
        foreach ($m[1] as $var) {
            if (array_key_exists($var, $vars) && ($vars[$var] === null || $vars[$var] === '')) {
                continue 2;
            }
        }
        $u = fill_player_template($t['url'], $vars);
        $out[] = [
            'id' => -($i + 1),
            'name' => $t['name'],
            'url' => $u,
            'kind' => player_kind_from_url($u),
            'audio' => $t['audio'],
            'quality' => $t['quality'],
        ];
    }
    return $out;
}

function api_players_movie(array $c): array
{
    return api_players('movie_player_templates', [
        'tmdb_id' => $c['tmdb_id'] ?? '', 'imdb_id' => $c['imdb_id'] ?? '', 'id' => $c['id'] ?? '', 'slug' => $c['slug'] ?? '',
    ]);
}

function api_players_episode(array $c, int $season, int $episode): array
{
    return api_players('episode_player_templates', [
        'tmdb_id' => $c['tmdb_id'] ?? '', 'imdb_id' => $c['imdb_id'] ?? '', 'slug' => $c['slug'] ?? '',
        'temporada' => $season, 'episodio' => $episode,
        'temporada2' => str_pad((string)$season, 2, '0', STR_PAD_LEFT),
        'episodio2' => str_pad((string)$episode, 2, '0', STR_PAD_LEFT),
    ]);
}

function api_has_movie_players(): bool
{
    return (bool)parse_player_templates((string)setting('movie_player_templates'));
}

function api_has_episode_players(): bool
{
    return (bool)parse_player_templates((string)setting('episode_player_templates'));
}

/** Players públicos (sem expor o que não precisa) para o front */
function players_for_front(array $players): array
{
    $players = array_filter($players, fn($p) => clean_url((string)$p['url']) !== '');
    return array_values(array_map(fn($p) => [
        'id' => (int)$p['id'],
        'name' => $p['name'],
        'url' => $p['url'],
        'kind' => $p['kind'],
        'audio' => $p['audio'],
        'quality' => $p['quality'],
    ], $players));
}

function json_out($data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function time_ago(?string $dt): string
{
    if (!$dt) {
        return '—';
    }
    $diff = time() - strtotime($dt);
    if ($diff < 60) return 'agora';
    if ($diff < 3600) return floor($diff / 60) . ' min atrás';
    if ($diff < 86400) return floor($diff / 3600) . ' h atrás';
    if ($diff < 2592000) return floor($diff / 86400) . ' dias atrás';
    return date('d/m/Y', strtotime($dt));
}

function format_runtime(?int $min): string
{
    if (!$min) return '';
    $h = intdiv($min, 60);
    $m = $min % 60;
    return $h ? ($h . 'h ' . ($m ? $m . 'min' : '')) : $m . 'min';
}

/** Card de título usado nas fileiras e grades */
function render_card(array $c): string
{
    $badge = '';
    if (!empty($c['is_release'])) {
        $badge = '<span class="card-badge">Lançamento</span>';
    }
    $rating = $c['rating'] ? '<span class="card-rating">★ ' . e(number_format((float)$c['rating'], 1)) . '</span>' : '';
    return '<a class="card" href="' . e(content_url($c)) . '" title="' . e($c['title']) . '">'
        . '<div class="card-img"><img loading="lazy" src="' . e(img($c['poster'], 'w342')) . '" alt="' . e($c['title']) . '">' . $badge
        . '<div class="card-hover"><span class="play-ico"></span></div></div>'
        . '<div class="card-info"><h3>' . e($c['title']) . '</h3><p>' . e(type_label($c['type'])) . ($c['year'] ? ' • ' . (int)$c['year'] : '') . $rating . '</p></div>'
        . '</a>';
}

function render_row(string $title, array $items, ?string $more = null, string $id = ''): string
{
    if (!$items) {
        return '';
    }
    $html = '<section class="row"' . ($id ? ' id="' . e($id) . '"' : '') . '><div class="row-head"><h2>' . e($title) . '</h2>';
    if ($more) {
        $html .= '<a class="row-more" href="' . e($more) . '">Ver tudo ›</a>';
    }
    $html .= '</div><div class="row-wrap"><button class="row-nav prev" aria-label="Anterior">‹</button><div class="row-track">';
    foreach ($items as $c) {
        $html .= render_card($c);
    }
    $html .= '</div><button class="row-nav next" aria-label="Próximo">›</button></div></section>';
    return $html;
}

function render_channel_card(array $ch): string
{
    $logo = $ch['logo'] ? '<img loading="lazy" src="' . e(img($ch['logo'])) . '" alt="' . e($ch['name']) . '">' : '<span class="ch-initial">' . e(mb_substr($ch['name'], 0, 2)) . '</span>';
    return '<a class="channel" href="' . e(channel_url($ch)) . '"><div class="channel-logo">' . $logo
        . '<span class="live-dot">AO VIVO</span></div><h3>' . e($ch['name']) . '</h3>'
        . ($ch['category'] ? '<p>' . e($ch['category']) . '</p>' : '') . '</a>';
}
