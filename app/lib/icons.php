<?php
declare(strict_types=1);

/**
 * Собственный набор двухтонных иконок панели (сетка 48×48).
 * Каждая иконка: [заливка-подложка, контур]. Контур рисуется градиентом оттенка, подложка — тем же оттенком с прозрачностью.
 */
const ICONS = [
    'home' => ['<path d="M7 22L24 8l17 14v15a4 4 0 0 1-4 4H11a4 4 0 0 1-4-4z"/>', '<path d="M7 22L24 8l17 14v15a4 4 0 0 1-4 4H11a4 4 0 0 1-4-4z"/><path d="M19 41V29h10v12"/>'],
    'gauge' => ['<circle cx="24" cy="26" r="16"/>', '<path d="M12.7 37.300a16 16 0 1 1 22.600 0"/><path d="M24 26l7-8"/><path d="M24 26h.1"/>'],
    'sites' => ['<rect x="6" y="8" width="36" height="32" rx="7"/>', '<rect x="6" y="8" width="36" height="32" rx="7"/><path d="M6 17h36"/><path d="M12 12.500h.1M17 12.500h.1"/><rect x="12" y="23" width="11" height="11" rx="3"/><path d="M29 25h7M29 32h5"/>'],
    'newsite' => ['<rect x="6" y="8" width="36" height="32" rx="7"/>', '<rect x="6" y="8" width="36" height="32" rx="7"/><path d="M6 17h36"/><path d="M12 12.500h.1M17 12.500h.1"/><path d="M24 22.500v12M18 28.500h12"/>'],
    'shield' => ['<path d="M24 6l15 5v12c0 9-7 16-15 19-8-3-15-10-15-19V11z"/>', '<path d="M24 6l15 5v12c0 9-7 16-15 19-8-3-15-10-15-19V11z"/><path d="M17 24l5 5 9-10"/>'],
    'lock' => ['<rect x="9" y="20" width="30" height="22" rx="7"/>', '<rect x="9" y="20" width="30" height="22" rx="7"/><path d="M16 20v-5a8 8 0 0 1 16 0v5"/><circle cx="24" cy="29.500" r="2.500"/><path d="M24 32v4"/>'],
    'db' => ['<path d="M10 12v24c0 2.800 6.300 5 14 5s14-2.200 14-5V12c0 2.800-6.300 5-14 5s-14-2.200-14-5z"/>', '<ellipse cx="24" cy="12" rx="14" ry="5"/><path d="M10 12v24c0 2.800 6.300 5 14 5s14-2.200 14-5V12"/><path d="M10 24c0 2.800 6.300 5 14 5s14-2.200 14-5"/>'],
    'dump' => ['<rect x="7" y="7" width="34" height="34" rx="9"/>', '<rect x="7" y="7" width="34" height="34" rx="9"/><path d="M24 14v14"/><path d="M17.500 22l6.500 6.500 6.500-6.500"/><path d="M16 34.500h16"/>'],
    'backup' => ['<rect x="6" y="9" width="36" height="9" rx="3.500"/><path d="M9 18h30v18a4 4 0 0 1-4 4H13a4 4 0 0 1-4-4z"/>', '<rect x="6" y="9" width="36" height="9" rx="3.500"/><path d="M9 18v18a4 4 0 0 0 4 4h22a4 4 0 0 0 4-4V18"/><path d="M19.500 26h9"/>'],
    'services' => ['<circle cx="31" cy="13" r="4.500"/><circle cx="17" cy="24" r="4.500"/><circle cx="28" cy="35" r="4.500"/>', '<path d="M8 13h18.500M35.500 13H40M8 24h4.500M21.500 24H40M8 35h15.500M32.500 35H40"/><circle cx="31" cy="13" r="4.500"/><circle cx="17" cy="24" r="4.500"/><circle cx="28" cy="35" r="4.500"/>'],
    'server' => ['<rect x="7" y="8" width="34" height="13" rx="5"/><rect x="7" y="27" width="34" height="13" rx="5"/>', '<rect x="7" y="8" width="34" height="13" rx="5"/><rect x="7" y="27" width="34" height="13" rx="5"/><path d="M14 14.500h.1M14 33.500h.1"/><path d="M22 14.500h12M22 33.500h12"/>'],
    'chart' => ['<path d="M14 31l7-9 6 5 10-13v21H14z"/>', '<path d="M9 8v24a3 3 0 0 0 3 3h28"/><path d="M14 31l7-9 6 5 10-13"/>'],
    'update' => ['<circle cx="24" cy="24" r="15"/>', '<path d="M39 24a15 15 0 0 1-27.500 8.300"/><path d="M9 24a15 15 0 0 1 27.500-8.300"/><path d="M37 7.500v8.500h-8.500"/><path d="M11 40.500V32h8.500"/>'],
    'cron' => ['<circle cx="24" cy="24" r="17"/>', '<circle cx="24" cy="24" r="17"/><path d="M24 13v11l7 5"/>'],
    'ftp' => ['<path d="M6 14a4 4 0 0 1 4-4h8l4 5h16a4 4 0 0 1 4 4v15a4 4 0 0 1-4 4H10a4 4 0 0 1-4-4z"/>', '<path d="M6 14a4 4 0 0 1 4-4h8l4 5h16a4 4 0 0 1 4 4v15a4 4 0 0 1-4 4H10a4 4 0 0 1-4-4z"/><path d="M24 33.500V22.500"/><path d="M19 27.500l5-5 5 5"/>'],
    'mail' => ['<rect x="6" y="10" width="36" height="28" rx="7"/>', '<rect x="6" y="10" width="36" height="28" rx="7"/><path d="M8.500 15l15.500 12 15.500-12"/>'],
    'dns' => ['<circle cx="24" cy="24" r="17"/>', '<circle cx="24" cy="24" r="17"/><ellipse cx="24" cy="24" rx="7.500" ry="17"/><path d="M7 24h34"/>'],
    'exit' => ['', '<path d="M20 8h-7a5 5 0 0 0-5 5v22a5 5 0 0 0 5 5h7"/><path d="M19 24h21M32 16l8 8-8 8"/>'],
    'help' => ['<circle cx="24" cy="24" r="17"/>', '<circle cx="24" cy="24" r="17"/><path d="M19 19.500a5 5 0 1 1 7.300 4.400c-1.600.900-2.300 1.900-2.300 3.600"/><path d="M24 33.500h.1"/>'],
];

const ICON_HUES = [
    'teal' => ['#2dd4bf', '#0b7a70'], 'blue' => ['#60a5fa', '#2a55d6'], 'violet' => ['#a78bfa', '#6238d8'],
    'orange' => ['#fdba74', '#e2620f'], 'rose' => ['#fda4b8', '#d92c62'], 'green' => ['#6ee7a0', '#16894a'],
    'amber' => ['#fcd34d', '#c97a05'], 'sky' => ['#67d5f5', '#0b7fb0'],
];

/** Градиенты для контуров, выводятся один раз на странице. */
function icon_defs(): string
{
    $h = '<svg width="0" height="0" style="position:absolute" aria-hidden="true"><defs>';
    foreach (ICON_HUES as $k => $c) {
        $h .= '<linearGradient id="ig-' . $k . '" gradientUnits="userSpaceOnUse" x1="4" y1="4" x2="44" y2="44"><stop offset="0" stop-color="' . $c[0] . '"/><stop offset="1" stop-color="' . $c[1] . '"/></linearGradient>';
    }
    return $h . '</defs></svg>';
}

function icon(string $name, string $hue = 'teal', int $size = 44): string
{
    $i = ICONS[$name] ?? ICONS['help'];
    $c = ICON_HUES[$hue] ?? ICON_HUES['teal'];
    return '<svg class="ico" width="' . $size . '" height="' . $size . '" viewBox="0 0 48 48" aria-hidden="true">'
        . '<g fill="' . $c[1] . '" fill-opacity="0.14" stroke="none">' . $i[0] . '</g>'
        . '<g fill="none" stroke="url(#ig-' . $hue . ')" stroke-width="' . ($size < 30 ? '3.2' : '2.6') . '" stroke-linecap="round" stroke-linejoin="round">' . $i[1] . '</g></svg>';
}
