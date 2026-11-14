<?php
/**
 * Domínio principal (Painel › Domínio): endereço oficial do site, HTTPS,
 * redirecionamento 301 de domínios antigos e troca de links salvos no banco.
 *
 * Segurança contra ficar trancado fora: o redirecionamento só liga quando você
 * abre o painel PELO domínio novo e confirma; e pode ser desligado criando o
 * arquivo includes/dominio-desligado.txt pelo Gerenciador de Arquivos da Hostinger.
 */

const DOMAIN_KILL_FILE = __DIR__ . '/dominio-desligado.txt';

function domain_clean(string $d): string
{
    $d = strtolower(trim($d));
    $d = preg_replace('~^[a-z]+://~', '', $d);
    $d = preg_replace('~[/?#].*$~', '', $d);
    $d = rtrim($d, '.');
    return preg_match('/^(?=.{3,253}$)([a-z0-9](?:[a-z0-9\-]{0,61}[a-z0-9])?\.)+[a-z0-9\-]{2,63}$/', $d) ? $d : '';
}

function domain_current_host(): string
{
    return strtolower(preg_replace('/[^a-zA-Z0-9.\-]/', '', explode(':', (string)($_SERVER['HTTP_HOST'] ?? ''))[0]));
}

function domain_request_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
        || strtolower((string)($_SERVER['HTTP_CF_VISITOR'] ?? '')) === '{"scheme":"https"}'
        || (($_SERVER['SERVER_PORT'] ?? '') == 443);
}

/** Domínio oficial ativo (ou '' se ainda não ativado) */
function domain_main(): string
{
    if (is_file(DOMAIN_KILL_FILE)) return '';
    return setting('domain_active') === '1' ? domain_clean((string)setting('domain_main')) : '';
}

function domain_scheme(): string
{
    if (domain_main() !== '') return setting('domain_https', '1') === '1' ? 'https' : 'http';
    return domain_request_https() ? 'https' : 'http';
}

/** Domínios antigos (um por linha) */
function domain_old_list(): array
{
    return array_values(array_filter(array_map('domain_clean', preg_split('/\R/', (string)setting('domain_old')))));
}

/** Redireciona (301) para o domínio oficial e para HTTPS. Chamado no início de cada página. */
function domain_enforce(): void
{
    if (PHP_SAPI === 'cli' || headers_sent()) return;
    $main = domain_main();
    if ($main === '') return;
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method !== 'GET' && $method !== 'HEAD') return;          // nunca interrompe um formulário
    $uri = (string)($_SERVER['REQUEST_URI'] ?? '/');
    if (preg_match('~/cron\.php~', $uri)) return;                 // tarefas agendadas seguem funcionando
    $host = domain_current_host();
    $wantHttps = setting('domain_https', '1') === '1';
    $wrongHost = $host !== '' && $host !== $main;
    $wrongScheme = $wantHttps && !domain_request_https();
    if (!$wrongHost && !$wrongScheme) return;
    if (!str_starts_with($uri, '/')) $uri = '/' . $uri;
    header('Location: ' . ($wantHttps ? 'https' : 'http') . '://' . $main . $uri, true, 301);
    header('Cache-Control: max-age=3600');
    exit;
}

/** Código único deste site (para testar se o domínio novo abre este mesmo site) */
function domain_token(): string
{
    $t = (string)setting('domain_token');
    if ($t === '') { $t = bin2hex(random_bytes(12)); set_setting('domain_token', $t); }
    return $t;
}

/** Onde procurar links com o domínio antigo: [tabela, coluna, descrição] */
function domain_link_columns(): array
{
    return [
        ['settings', 'value', 'Configurações (imagens, players, API, anúncios...)'],
        ['players', 'url', 'Players de filmes e episódios'],
        ['contents', 'poster', 'Pôsteres'],
        ['contents', 'backdrop', 'Imagens de fundo'],
        ['contents', 'logo', 'Logos dos títulos'],
        ['contents', 'overview', 'Sinopses'],
        ['channels', 'url', 'Canais de TV (links)'],
        ['channels', 'logo', 'Canais de TV (logos)'],
        ['notifications', 'link', 'Notificações (links)'],
        ['notifications', 'image', 'Notificações (imagens)'],
    ];
}

/** Conta (ou troca) links //antigo → //novo nas colunas acima */
function domain_replace_links(string $old, string $new, bool $apply): array
{
    $out = [];
    foreach (domain_link_columns() as [$t, $col, $label]) {
        try {
            $n = (int)q_val("SELECT COUNT(*) FROM `$t` WHERE `$col` LIKE ? OR `$col` LIKE ? OR `$col` LIKE ? OR `$col` LIKE ?",
                ['%//' . $old . '/%', '%//' . $old, '%//www.' . $old . '/%', '%//www.' . $old]);
        } catch (Throwable $e) {
            continue; // tabela não existe nesta instalação
        }
        if ($n && $apply) {
            if ($t === 'settings') {
                // não mexe no próprio cadastro de domínios
                q("UPDATE settings SET value = REPLACE(REPLACE(value, ?, ?), ?, ?) WHERE `key` NOT IN ('domain_main', 'domain_old', 'domain_pending')",
                    ['//www.' . $old, '//' . $new, '//' . $old, '//' . $new]);
            } else {
                q("UPDATE `$t` SET `$col` = REPLACE(REPLACE(`$col`, ?, ?), ?, ?)", ['//www.' . $old, '//' . $new, '//' . $old, '//' . $new]);
            }
        }
        if ($n) $out[] = ['label' => $label, 'n' => $n];
    }
    if ($apply) cache_bump();
    return $out;
}
