<?php
// Сбор метрик раз в минуту (запускается таймером systemd от пользователя панели).
declare(strict_types=1);
require __DIR__ . '/app.php';

$dir = cfg()['data_dir'];
$prevFile = "$dir/metrics.prev";
$csv = "$dir/metrics.csv";

$stat = preg_split('/\s+/', trim((string) strtok((string) file_get_contents('/proc/stat'), "\n")));
$vals = array_map('floatval', array_slice($stat, 1, 8)); // user nice system idle iowait irq softirq steal
$total = array_sum($vals);
$idle = ($vals[3] ?? 0) + ($vals[4] ?? 0);

$rx = 0.0;
$tx = 0.0;
foreach (@file('/proc/net/dev') ?: [] as $line) {
    if (strpos($line, ':') === false) {
        continue;
    }
    [$if, $rest] = explode(':', $line, 2);
    if (trim($if) === 'lo') {
        continue;
    }
    $c = preg_split('/\s+/', trim($rest));
    $rx += (float) $c[0];
    $tx += (float) ($c[8] ?? 0);
}

$now = time();
$prev = json_decode((string) @file_get_contents($prevFile), true);
file_put_contents($prevFile, json_encode(['t' => $now, 'total' => $total, 'idle' => $idle, 'rx' => $rx, 'tx' => $tx]));
if (!is_array($prev) || $now - $prev['t'] <= 0 || $now - $prev['t'] > 300) {
    exit(0);
}
$dt = $now - $prev['t'];
$dTotal = $total - $prev['total'];
$cpu = $dTotal > 0 ? 100 * (1 - ($idle - $prev['idle']) / $dTotal) : 0;
$s = sys_stats();
$mem = $s['mem_total'] ? 100 * $s['mem_used'] / $s['mem_total'] : 0;

$row = [$now, round(max(0, min(100, $cpu)), 1), round($mem, 1), round((float) $s['load'][0], 2),
    round(max(0, $rx - $prev['rx']) / $dt), round(max(0, $tx - $prev['tx']) / $dt)];
file_put_contents($csv, implode(',', $row) . "\n", FILE_APPEND | LOCK_EX);

if (filesize($csv) > 600000) { // храним около 7 суток
    $lines = file($csv);
    file_put_contents($csv, implode('', array_slice($lines, -10080)), LOCK_EX);
}
