<?php
declare(strict_types=1);

const CHART_C1 = '#00897b';
const CHART_C2 = '#c2570c';

/** Строки метрик за последние $range секунд: [ts, cpu, mem, load, rx, tx]. */
function metrics_load(int $range): array
{
    $file = cfg()['data_dir'] . '/metrics.csv';
    $from = time() - $range;
    $rows = [];
    foreach (@file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $c = explode(',', $line);
        if (count($c) >= 6 && (int) $c[0] >= $from) {
            $rows[] = array_map('floatval', $c);
        }
    }
    return $rows;
}

/** Усредняет значения по $n равным отрезкам времени; пустые отрезки дают null. */
function bucketize(array $rows, int $from, int $to, int $n, array $cols): array
{
    $step = max(1, ($to - $from) / $n);
    $sum = [];
    $cnt = [];
    foreach ($rows as $r) {
        $b = (int) floor(($r[0] - $from) / $step);
        if ($b < 0 || $b >= $n) {
            continue;
        }
        $cnt[$b] = ($cnt[$b] ?? 0) + 1;
        foreach ($cols as $c) {
            $sum[$b][$c] = ($sum[$b][$c] ?? 0) + $r[$c];
        }
    }
    $out = [];
    for ($b = 0; $b < $n; $b++) {
        if (empty($cnt[$b])) {
            continue;
        }
        $p = [(int) round($from + ($b + 0.5) * $step)];
        foreach ($cols as $c) {
            $p[] = round($sum[$b][$c] / $cnt[$b], 2);
        }
        $out[] = $p;
    }
    return $out;
}

function fmt_rate(float $b): string
{
    $u = ['Б/с', 'КБ/с', 'МБ/с', 'ГБ/с'];
    $i = 0;
    while ($b >= 1024 && $i < 3) {
        $b /= 1024;
        $i++;
    }
    return number_format($b, ($i > 0 && $b < 10) ? 1 : 0, ',', ' ') . ' ' . $u[$i];
}

function fmt_unit(float $v, string $unit): string
{
    if ($unit === 'pct') {
        return number_format($v, 0, ',', '') . '%';
    }
    if ($unit === 'rate') {
        return fmt_rate($v);
    }
    return number_format($v, $v < 10 ? 1 : 0, ',', '');
}

function nice_max(float $m, string $unit): float
{
    if ($unit === 'pct') {
        return 100.0;
    }
    if ($unit === 'rate') {
        $m = max($m, 1024.0);
        $k = pow(1024, floor(log($m, 1024)));
        foreach ([1, 2, 5, 10, 20, 50, 100, 200, 500, 1024] as $s) {
            if ($s * $k >= $m) {
                return $s * $k;
            }
        }
    }
    $m = max($m, 1.0);
    $k = pow(10, floor(log10($m)));
    foreach ([1, 2, 5, 10] as $s) {
        if ($s * $k >= $m) {
            return $s * $k;
        }
    }
    return $m;
}

/**
 * Карточка с графиком.
 * $series: [['col' => индекс в строке метрик, 'label' => подпись, 'color' => цвет], ...]
 */
function chart_card(string $title, array $rows, array $series, string $unit, int $range): string
{
    $to = time();
    $from = $to - $range;
    $cols = array_column($series, 'col');
    $pts = bucketize($rows, $from, $to, 120, $cols);
    $h = '<section class="panel chart"><header><h2>' . e($title) . '</h2>';
    if ($pts) {
        $last = end($pts);
        $now = [];
        foreach ($series as $i => $s) {
            $now[] = (count($series) > 1 ? $s['label'] . ' ' : '') . fmt_unit((float) $last[$i + 1], $unit);
        }
        $h .= '<span class="chart-now">сейчас: ' . e(implode(' · ', $now)) . '</span>';
    }
    $h .= '</header>';
    if (count($series) > 1) {
        $h .= '<div class="legend">';
        foreach ($series as $s) {
            $h .= '<span><i style="background:' . $s['color'] . '"></i>' . e($s['label']) . '</span>';
        }
        $h .= '</div>';
    }
    if (count($pts) < 2) {
        return $h . '<p class="muted chart-empty">Данные накапливаются. График появится через несколько минут после установки.</p></section>';
    }

    $W = 640;
    $H = 190;
    $x0 = 52;
    $x1 = $W - 10;
    $y0 = 12;
    $y1 = $H - 26;
    $max = 0.0;
    foreach ($pts as $p) {
        for ($i = 1; $i < count($p); $i++) {
            $max = max($max, $p[$i]);
        }
    }
    $max = nice_max($max, $unit);
    $X = function ($t) use ($from, $to, $x0, $x1) {
        return round($x0 + ($t - $from) / ($to - $from) * ($x1 - $x0), 1);
    };
    $Y = function ($v) use ($max, $y0, $y1) {
        return round($y1 - min(1, $v / $max) * ($y1 - $y0), 1);
    };
    $gap = ($to - $from) / 120 * 2.5; // разрыв линии, если данных не было
    $svg = '';
    foreach ([0, 0.5, 1] as $f) {
        $y = $Y($max * $f);
        $svg .= '<line class="grid" x1="' . $x0 . '" x2="' . $x1 . '" y1="' . $y . '" y2="' . $y . '"/>'
            . '<text class="tick" x="' . ($x0 - 8) . '" y="' . ($y + 4) . '" text-anchor="end">' . e(fmt_unit($max * $f, $unit)) . '</text>';
    }
    $fmt = $range > 86400 ? 'd.m' : 'H:i';
    foreach ([[0, 'start'], [0.5, 'middle'], [1, 'end']] as $t) {
        $ts = (int) ($from + $t[0] * ($to - $from));
        $svg .= '<text class="tick" x="' . $X($ts) . '" y="' . ($H - 6) . '" text-anchor="' . $t[1] . '">' . date($fmt, $ts) . '</text>';
    }
    foreach ($series as $i => $s) {
        $segs = [];
        $cur = [];
        $prev = null;
        foreach ($pts as $p) {
            if ($prev !== null && $p[0] - $prev > $gap && $cur) {
                $segs[] = $cur;
                $cur = [];
            }
            $cur[] = [$X($p[0]), $Y($p[$i + 1])];
            $prev = $p[0];
        }
        if ($cur) {
            $segs[] = $cur;
        }
        foreach ($segs as $seg) {
            $d = '';
            foreach ($seg as $k => $q) {
                $d .= ($k ? 'L' : 'M') . $q[0] . ' ' . $q[1];
            }
            if (count($series) === 1 && count($seg) > 1) {
                $svg .= '<path d="' . $d . 'L' . end($seg)[0] . ' ' . $y1 . 'L' . $seg[0][0] . ' ' . $y1 . 'Z" fill="' . $s['color'] . '" opacity="0.12"/>';
            }
            $svg .= '<path d="' . $d . '" fill="none" stroke="' . $s['color'] . '" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/>';
        }
    }
    $svg .= '<line class="guide" x1="0" x2="0" y1="' . $y0 . '" y2="' . $y1 . '" visibility="hidden"/>';
    $geom = ['w' => $W, 'x0' => $x0, 'x1' => $x1, 'from' => $from, 'to' => $to, 'long' => $range > 86400];
    return $h . '<div class="chart-wrap"><svg class="plot" viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="' . e($title) . '"'
        . ' data-unit="' . $unit . '" data-g="' . e(json_encode($geom)) . '" data-pts="' . e(json_encode($pts)) . '"'
        . ' data-labels="' . e(json_encode(array_column($series, 'label'), JSON_UNESCAPED_UNICODE)) . '">' . $svg . '</svg>'
        . '<div class="tip" hidden></div></div></section>';
}

/** Кольцевой индикатор: доля 0..1, подпись и значение текстом. */
function gauge(string $label, float $ratio, string $value, string $note = ''): string
{
    $ratio = max(0.0, min(1.0, $ratio));
    $pct = (int) round($ratio * 100);
    $cls = $pct >= 90 ? 'bad' : ($pct >= 75 ? 'warn' : 'ok');
    $state = $pct >= 90 ? 'Критично' : ($pct >= 75 ? 'Высоко' : 'В норме');
    $r = 42;
    $len = 2 * M_PI * $r;
    return '<div class="stat gauge"><svg viewBox="0 0 110 110" aria-hidden="true"><circle class="track" cx="55" cy="55" r="' . $r . '"/>'
        . '<circle class="arc ' . $cls . '" cx="55" cy="55" r="' . $r . '" stroke-dasharray="' . round($len * $ratio, 1) . ' ' . round($len, 1) . '" transform="rotate(-90 55 55)"/>'
        . '<text x="55" y="61" text-anchor="middle">' . $pct . '%</text></svg>'
        . '<div><div class="stat-label">' . e($label) . '</div><div class="stat-value">' . e($value) . '</div>'
        . '<span class="badge ' . $cls . '">' . $state . '</span>' . ($note !== '' ? '<div class="muted">' . e($note) . '</div>' : '') . '</div></div>';
}
