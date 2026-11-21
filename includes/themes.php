<?php
/**
 * Temas de época (Natal etc.). Liga/desliga no painel › Temas.
 * Modo: off (desligado), on (ligado) ou auto (liga sozinho entre duas datas todo ano).
 */

function theme_defs(): array
{
    return [
        'natal' => [
            'name' => 'Natal',
            'emoji' => '🎄',
            'desc' => 'Neve caindo, luzinhas piscando no topo e gorro de Papai Noel no logo.',
            'from' => '12-01', // 1º de dezembro
            'to' => '01-06',   // até o Dia de Reis
            'opts' => [
                'snow' => ['Neve caindo', 'Flocos leves pela tela (não cai em cima do player). Mais leve no celular.', '1'],
                'lights' => ['Luzinhas no topo', 'Pisca-pisca colorido embaixo do menu.', '1'],
                'hat' => ['Gorro no logo', 'Gorro de Papai Noel em cima da pipoca do logo.', '1'],
                'toggle' => ['Botão para o visitante desligar a neve', 'Um floquinho no canto da tela para quem não quiser ver a neve.', '1'],
            ],
        ],
    ];
}

function theme_mode(string $key): string
{
    $m = setting('theme_' . $key . '_mode', 'off');
    return in_array($m, ['off', 'on', 'auto'], true) ? $m : 'off';
}

function theme_dates(string $key): array
{
    $d = theme_defs()[$key];
    $from = setting('theme_' . $key . '_from', $d['from']);
    $to = setting('theme_' . $key . '_to', $d['to']);
    $ok = fn($v) => preg_match('/^(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])$/', $v);
    return [$ok($from) ? $from : $d['from'], $ok($to) ? $to : $d['to']];
}

function theme_in_dates(string $key, ?int $ts = null): bool
{
    [$from, $to] = theme_dates($key);
    $md = date('m-d', $ts ?? time());
    // Período que passa da virada do ano (ex.: 01/12 até 06/01)
    return $from <= $to ? ($md >= $from && $md <= $to) : ($md >= $from || $md <= $to);
}

function theme_opts(string $key): array
{
    $d = theme_defs()[$key];
    $saved = json_decode((string)setting('theme_' . $key . '_opts', ''), true) ?: [];
    $out = [];
    foreach ($d['opts'] as $k => [, , $def]) $out[$k] = ($saved[$k] ?? $def) === '1' || ($saved[$k] ?? $def) === true;
    return $out;
}

/** Tema que está valendo agora ('' = nenhum). Admin pode testar com ?tema=natal sem ligar para todos. */
function theme_active(): string
{
    static $active = null;
    if ($active !== null) return $active;
    $active = '';
    $preview = (string)($_GET['tema'] ?? '');
    if ($preview !== '' && isset(theme_defs()[$preview]) && function_exists('is_admin') && is_admin()) return $active = $preview;
    foreach (array_keys(theme_defs()) as $k) {
        $m = theme_mode($k);
        if ($m === 'on' || ($m === 'auto' && theme_in_dates($k))) return $active = $k;
    }
    return $active;
}

/** Classes para o <body> */
function theme_body_class(): string
{
    $t = theme_active();
    if ($t === '') return '';
    $cls = ['theme-' . $t];
    foreach (theme_opts($t) as $k => $on) if ($on) $cls[] = 't-' . $k;
    return implode(' ', $cls);
}

/** CSS do tema (vai dentro do <head>) */
function theme_head(): string
{
    $t = theme_active();
    if ($t === '') return '';
    $css = inline_css('css/theme-' . $t . '.css');
    return $css !== '' ? '<style>' . $css . '</style>' : '<link rel="stylesheet" href="' . e(asset('css/theme-' . $t . '.css')) . '">';
}

/** Enfeites do topo (logo do header já está aberto) */
function theme_header_extras(): string
{
    $t = theme_active();
    if ($t !== 'natal') return '';
    $o = theme_opts('natal');
    return $o['lights'] ? '<div class="xmas-lights" aria-hidden="true"><i></i><i></i></div>' : '';
}

/** Script do tema (vai no fim da página) */
function theme_footer(): string
{
    $t = theme_active();
    if ($t !== 'natal') return '';
    $o = theme_opts('natal');
    if (!$o['snow']) return '';
    return '<script src="' . e(asset('js/theme-natal.js')) . '" defer data-toggle="' . ($o['toggle'] ? '1' : '0') . '"></script>';
}
