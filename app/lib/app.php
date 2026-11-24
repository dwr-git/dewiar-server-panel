<?php
declare(strict_types=1);

define('DSP_VERSION', trim((string) @file_get_contents(__DIR__ . '/../VERSION')) ?: (trim((string) @file_get_contents(__DIR__ . '/../../VERSION')) ?: 'dev'));

function cfg(): array
{
    static $c = null;
    if ($c === null) {
        $path = getenv('DEWIAR_CONFIG') ?: '/etc/dewiar-panel/config.php';
        if (!is_file($path)) {
            http_response_code(500);
            exit('Нет файла конфигурации. Запустите install.sh');
        }
        $c = require $path;
        $c += [
            'data_dir' => '/var/lib/dewiar-panel',
            'helper_cmd' => ['sudo', '-n', '/usr/local/sbin/dewiar-helper'],
        ];
    }
    return $c;
}

function e($s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Вызов привилегированного помощника. Возвращает [код, вывод]. */
function helper(array $args, ?string $stdin = null): array
{
    $cmd = array_merge(cfg()['helper_cmd'], $args);
    $in = $stdin === null ? ['file', '/dev/null', 'r'] : ['pipe', 'r'];
    $p = @proc_open($cmd, [0 => $in, 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($p)) {
        return [127, 'Не удалось запустить помощник панели'];
    }
    if ($stdin !== null) {
        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);
    }
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $rc = proc_close($p);
    return [$rc, trim($out . ($err !== '' ? "\n" . $err : ''))];
}

function helper_json(array $args): array
{
    [$rc, $out] = helper($args);
    $data = json_decode($out, true);
    if ($rc !== 0 || !is_array($data)) {
        return ['error' => $out !== '' ? $out : 'Помощник вернул пустой ответ'];
    }
    return ['data' => $data];
}

/** Ответ помощника с кешем на $ttl секунд (для тяжёлых проверок на главной). */
function helper_json_cached(array $args, int $ttl): array
{
    $file = cfg()['data_dir'] . '/cache-' . preg_replace('/[^a-z0-9]+/', '-', implode('-', $args)) . '.json';
    if (is_file($file) && time() - filemtime($file) < $ttl) {
        $d = json_decode((string) file_get_contents($file), true);
        if (is_array($d)) {
            return ['data' => $d];
        }
    }
    $res = helper_json($args);
    if (isset($res['data'])) {
        @file_put_contents($file, json_encode($res['data']), LOCK_EX);
    }
    return $res;
}

function cache_clear(): void
{
    foreach (glob(cfg()['data_dir'] . '/cache-*.json') ?: [] as $f) {
        @unlink($f);
    }
}

/** Сколько компонентов (nginx, Apache, PHP) требуют внимания: [критично, предупреждение]. */
function security_counts(array $d): array
{
    $bad = 0;
    $warn = 0;
    if ($n = $d['nginx'] ?? null) {
        $v = $n['version'];
        $old = version_compare($v, '1.9.5', '>=') && (version_compare($v, '1.30.7', '<') || (version_compare($v, '1.31.0', '>=') && version_compare($v, '1.31.9', '<')));
        if ($old && !$n['backport']) {
            $n['http2'] ? $bad++ : $warn++;
        }
    }
    if ($a = $d['apache'] ?? null) {
        if (version_compare($a['version'], '2.4.55', '>=') && version_compare($a['version'], '2.4.71', '<') && !$a['backport']) {
            $a['http2'] ? $bad++ : $warn++;
        }
    }
    $fixed = ['8.2' => '8.2.36', '8.3' => '8.3.37', '8.4' => '8.4.28', '8.5' => '8.5.13'];
    foreach ($d['php'] ?? [] as $p) {
        $br = implode('.', array_slice(explode('.', $p['version']), 0, 2));
        if (!$p['backport'] && version_compare($br, '8.6', '<') && !(isset($fixed[$br]) && version_compare($p['version'], $fixed[$br], '>='))) {
            $bad++;
        }
    }
    return [$bad, $warn];
}

/** Передаёт вывод помощника прямо в ответ (для скачивания файлов). */
function helper_stream(array $args): void
{
    $cmd = array_merge(cfg()['helper_cmd'], $args);
    $p = @proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    if (is_resource($p)) {
        fpassthru($pipes[1]);
        fclose($pipes[1]);
        proc_close($p);
    }
}

/* ---------- Сессия и вход ---------- */

function session_boot(): void
{
    $dir = cfg()['data_dir'] . '/sessions';
    if (is_dir($dir) && is_writable($dir)) {
        session_save_path($dir);
    }
    session_name('dewiarsid');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Strict']);
    session_start();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(24));
    }
}

function logged_in(): bool
{
    return !empty($_SESSION['user']);
}

function csrf_ok(): bool
{
    $t = $_SERVER['HTTP_X_CSRF'] ?? ($_POST['csrf'] ?? '');
    return is_string($t) && $t !== '' && hash_equals($_SESSION['csrf'] ?? '', $t);
}

function throttle_file(): string
{
    return cfg()['data_dir'] . '/login-throttle.json';
}

function throttle_wait(): int
{
    $d = json_decode((string) @file_get_contents(throttle_file()), true) ?: [];
    return max(0, (int) ($d['until'] ?? 0) - time());
}

function throttle_fail(): void
{
    $d = json_decode((string) @file_get_contents(throttle_file()), true) ?: [];
    $fails = (int) ($d['fails'] ?? 0) + 1;
    $d = ['fails' => $fails, 'until' => $fails >= 5 ? time() + 300 : 0];
    if ($fails >= 5) {
        $d['fails'] = 0;
    }
    @file_put_contents(throttle_file(), json_encode($d), LOCK_EX);
}

function try_login(string $login, string $pass): ?string
{
    $wait = throttle_wait();
    if ($wait > 0) {
        return 'Слишком много попыток. Повторите через ' . (int) ceil($wait / 60) . ' мин.';
    }
    $c = cfg();
    $okUser = hash_equals((string) ($c['login'] ?? ''), $login);
    $okPass = password_verify($pass, (string) ($c['pass_hash'] ?? ''));
    if ($okUser && $okPass) {
        session_regenerate_id(true);
        $_SESSION['user'] = $login;
        $_SESSION['csrf'] = bin2hex(random_bytes(24));
        @unlink(throttle_file());
        return null;
    }
    throttle_fail();
    sleep(1);
    return 'Неверный логин или пароль';
}

/* ---------- Сведения о системе ---------- */

function sys_stats(): array
{
    $mem = [];
    foreach (@file('/proc/meminfo') ?: [] as $line) {
        if (preg_match('/^(\w+):\s+(\d+)/', $line, $m)) {
            $mem[$m[1]] = (int) $m[2] * 1024;
        }
    }
    $total = $mem['MemTotal'] ?? 0;
    $avail = $mem['MemAvailable'] ?? 0;
    $up = (int) floatval((string) @file_get_contents('/proc/uptime'));
    $load = function_exists('sys_getloadavg') ? (sys_getloadavg() ?: [0, 0, 0]) : [0, 0, 0];
    $cpus = max(1, preg_match_all('/^processor\s*:/m', (string) @file_get_contents('/proc/cpuinfo')));
    $dt = (float) @disk_total_space('/');
    $df = (float) @disk_free_space('/');
    return [
        'host' => gethostname() ?: 'server',
        'load' => $load, 'cpus' => $cpus,
        'mem_total' => $total, 'mem_used' => max(0, $total - $avail),
        'disk_total' => $dt, 'disk_used' => max(0, $dt - $df),
        'uptime' => $up,
    ];
}

function fmt_bytes(float $b): string
{
    $u = ['Б', 'КБ', 'МБ', 'ГБ', 'ТБ'];
    $i = 0;
    while ($b >= 1024 && $i < 4) {
        $b /= 1024;
        $i++;
    }
    return number_format($b, $i >= 3 ? 1 : 0, ',', ' ') . ' ' . $u[$i];
}

function fmt_uptime(int $s): string
{
    $d = intdiv($s, 86400);
    $h = intdiv($s % 86400, 3600);
    return $d > 0 ? "$d дн. $h ч" : $h . ' ч ' . intdiv($s % 3600, 60) . ' мин';
}

/** Состояние сертификата сайта: [класс, подпись, дней]. */
function cert_state(array $site): array
{
    $ci = $site['cert_info'] ?? null;
    if (!$ci) {
        return ['none', $site['ssl'] ? 'Файл сертификата не найден' : 'SSL не настроен', null];
    }
    $days = (int) floor(($ci['not_after'] - time()) / 86400);
    if ($days < 0) {
        return ['bad', 'Истёк ' . date('d.m.Y', $ci['not_after']), $days];
    }
    if ($days <= 7) {
        return ['bad', "Истекает через $days дн.", $days];
    }
    if ($days < 30) {
        return ['warn', "Осталось $days дн.", $days];
    }
    return ['ok', "Действует, $days дн.", $days];
}

function backend_label(?string $b): string
{
    if (!$b) {
        return 'Статический сайт';
    }
    if (preg_match('/php[-_]?(\d)\.?(\d)/i', $b, $m)) {
        return 'PHP ' . $m[1] . '.' . $m[2];
    }
    if (preg_match('/^https?:/i', $b)) {
        return 'Прокси на ' . preg_replace('~^https?://~i', '', $b);
    }
    return $b;
}
