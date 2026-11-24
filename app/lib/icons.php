<?php
declare(strict_types=1);

/**
 * Собственный набор двухтонных иконок панели (сетка 48×48).
 * Каждая иконка: [заливка-подложка, контур]. Контур рисуется градиентом оттенка, подложка — тем же оттенком с прозрачностью.
 */
const ICONS = [
    'gauge' => ['<path d="M9 31a15 15 0 1 1 30 0z"/>', '<path d="M9 31a15 15 0 1 1 30 0"/><path d="M24 31l7-10"/><path d="M6 37h36"/>'],
    'sites' => ['<rect x="6" y="9" width="36" height="30" rx="7"/>', '<rect x="6" y="9" width="36" height="30" rx="7"/><path d="M6 18h36"/><path d="M13 25h13M13 31h8"/><rect x="30" y="24" width="6" height="8" rx="2"/>'],
    'newsite' => ['<rect x="6" y="9" width="36" height="30" rx="7"/>', '<rect x="6" y="9" width="36" height="30" rx="7"/><path d="M6 18h36"/><path d="M24 23v11M18.5 28.5h11"/>'],
    'shield' => ['<path d="M24 6l15 5v12c0 9-7 16-15 19-8-3-15-10-15-19V11z"/>', '<path d="M24 6l15 5v12c0 9-7 16-15 19-8-3-15-10-15-19V11z"/><path d="M17 24l5 5 9-10"/>'],
    'lock' => ['<rect x="10" y="21" width="28" height="20" rx="6"/>', '<rect x="10" y="21" width="28" height="20" rx="6"/><path d="M16 21v-5a8 8 0 0 1 16 0v5"/><path d="M24 29v5"/>'],
    'db' => ['<path d="M10 12v24c0 2.8 6.3 5 14 5s14-2.2 14-5V12c0 2.8-6.3 5-14 5s-14-2.2-14-5z"/>', '<ellipse cx="24" cy="12" rx="14" ry="5"/><path d="M10 12v24c0 2.8 6.3 5 14 5s14-2.2 14-5V12"/><path d="M10 24c0 2.8 6.3 5 14 5s14-2.2 14-5"/>'],
    'dump' => ['<path d="M9 30v6a4 4 0 0 0 4 4h22a4 4 0 0 0 4-4v-6z"/>', '<path d="M24 8v19"/><path d="M16.5 20l7.5 7.5 7.5-7.5"/><path d="M9 30v6a4 4 0 0 0 4 4h22a4 4 0 0 0 4-4v-6"/>'],
    'services' => ['<circle cx="31" cy="13" r="4.5"/><circle cx="17" cy="24" r="4.5"/><circle cx="28" cy="35" r="4.5"/>', '<path d="M8 13h18.5M35.5 13H40M8 24h4.5M21.5 24H40M8 35h15.5M32.5 35H40"/><circle cx="31" cy="13" r="4.5"/><circle cx="17" cy="24" r="4.5"/><circle cx="28" cy="35" r="4.5"/>'],
    'server' => ['<rect x="7" y="8" width="34" height="13" rx="5"/><rect x="7" y="27" width="34" height="13" rx="5"/>', '<rect x="7" y="8" width="34" height="13" rx="5"/><rect x="7" y="27" width="34" height="13" rx="5"/><path d="M14 14.5h.1M14 33.5h.1"/><path d="M22 14.5h12M22 33.5h12"/>'],
    'chart' => ['<path d="M14 32l7-9 6 5 10-14v24H14z"/>', '<path d="M9 8v28a3 3 0 0 0 3 3h28"/><path d="M14 32l7-9 6 5 10-14"/>'],
    'update' => ['<circle cx="24" cy="24" r="9"/>', '<path d="M38.5 20A15 15 0 0 0 11 17"/><path d="M10 8v9h9"/><path d="M9.5 28A15 15 0 0 0 37 31"/><path d="M38 40v-9h-9"/>'],
    'help' => ['<circle cx="24" cy="24" r="17"/>', '<circle cx="24" cy="24" r="17"/><path d="M19 19.5a5 5 0 1 1 7.3 4.4c-1.6.9-2.3 1.9-2.3 3.6"/><path d="M24 33.5h.1"/>'],
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
        $h .= '<linearGradient id="ig-' . $k . '" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="' . $c[0] . '"/><stop offset="1" stop-color="' . $c[1] . '"/></linearGradient>';
    }
    return $h . '</defs></svg>';
}

function icon(string $name, string $hue = 'teal', int $size = 44): string
{
    $i = ICONS[$name] ?? ICONS['help'];
    $c = ICON_HUES[$hue] ?? ICON_HUES['teal'];
    return '<svg class="ico" width="' . $size . '" height="' . $size . '" viewBox="0 0 48 48" aria-hidden="true">'
        . '<g fill="' . $c[1] . '" fill-opacity="0.14" stroke="none">' . $i[0] . '</g>'
        . '<g fill="none" stroke="url(#ig-' . $hue . ')" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round">' . $i[1] . '</g></svg>';
}
