<?php
/**
 * Importação automática — chamado pelo Cron da Hostinger.
 * Comando:  php /caminho/do/site/cron.php
 * Ou URL:   https://seusite.com/cron.php?key=SUA_CHAVE
 */
$cli = PHP_SAPI === 'cli';
if ($cli) {
    $_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $_SERVER['SCRIPT_NAME'] = '/cron.php';
}
require __DIR__ . '/includes/bootstrap.php';

if (!$cli) {
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Robots-Tag: noindex');
    $key = (string)setting('auto_cron_key');
    if ($key === '' || !hash_equals($key, (string)($_GET['key'] ?? ''))) {
        http_response_code(403);
        exit('Chave inválida.');
    }
}
$md = mixdrop_check(40);
if ($md) echo "Envios MixDrop atualizados: $md\n";
$tv = tv_refresh_due();
if ($tv) echo "Listas de TV atualizadas: $tv\n";
if (setting('auto_enabled') !== '1') {
    exit("Importação automática desligada no painel.\n");
}
$interval = max(5, (int)setting('auto_interval', '30')) * 60;
if ((int)setting('auto_last_run', '0') > time() - $interval + 30) {
    exit("Ainda não deu o intervalo configurado.\n");
}
$r = run_autoimport();
echo "Importados: {$r['imported']} | Séries atualizadas: {$r['updated']} | Já existiam: {$r['skipped']} | Erros: {$r['errors']}\n";
