<?php
/**
 * Dewiar Server Panel — привилегированный помощник.
 * Запускается от root через sudo. Принимает только команды из списка ниже,
 * все аргументы проверяются. Веб-часть панели сама root-прав не имеет.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}
if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
    fwrite(STDERR, "Нужны права root\n");
    exit(1);
}
putenv('PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin');
putenv('HOME=/root'); // чтобы клиент MySQL нашёл /root/.my.cnf

const NGINX_BIN  = 'nginx';
const ACME       = '/root/.acme.sh/acme.sh';
const ACME_HOME  = '/root/.acme.sh';
const DOMAIN_RE  = '/^(\*\.)?([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{1,62}$/i';
const META_DIR   = '/etc/dewiar-panel/sites';
const SSL_DIR    = '/etc/dewiar-panel/ssl';
const SOCK_DIR   = '/var/lib/dewiar-sockets';
const DNS_STATE  = '/root/.acme.sh/dewiar-dns';
const CONF_BAK   = '/var/backups/dewiar-panel/configs';
const SITES_ROOT = '/var/www/dewiar';
const DNS_MANUAL = '--yes-I-know-dns-manual-mode-enough-go-ahead-please';
const SERVICE_RE = '/^(nginx|apache2|httpd|mariadb|mysql|mysqld|exim4|postfix|dovecot|proftpd|vsftpd|pure-ftpd|named|bind9|fail2ban|cron|crond|redis-server|redis|memcached|(fp2-)?php[0-9.]*-?fpm)\.service$/';

function run(array $cmd, ?string &$out = null): int
{
    $p = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes);
    if (!is_resource($p)) {
        $out = 'Не удалось запустить: ' . $cmd[0];
        return 127;
    }
    $out = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    return proc_close($p);
}

function fail(string $msg, int $code = 1): void
{
    echo $msg, "\n";
    exit($code);
}

/* ---------- Разбор конфигурации nginx ---------- */

function tokenize(string $s): array
{
    $t = [];
    $n = strlen($s);
    $i = 0;
    $cur = '';
    $has = false;
    $flush = function () use (&$t, &$cur, &$has) {
        if ($has) {
            $t[] = ['w', $cur];
            $cur = '';
            $has = false;
        }
    };
    while ($i < $n) {
        $c = $s[$i];
        if ($c === '#' && !$has) {
            while ($i < $n && $s[$i] !== "\n") {
                $i++;
            }
            continue;
        }
        if ($c === '"' || $c === "'") {
            $q = $c;
            $i++;
            $has = true;
            while ($i < $n && $s[$i] !== $q) {
                if ($s[$i] === '\\' && $i + 1 < $n) {
                    $i++;
                }
                $cur .= $s[$i];
                $i++;
            }
            $i++;
            continue;
        }
        if ($c === '$' && $i + 1 < $n && $s[$i + 1] === '{') {
            $has = true;
            while ($i < $n && $s[$i] !== '}') {
                $cur .= $s[$i];
                $i++;
            }
            $cur .= '}';
            $i++;
            continue;
        }
        if ($c === ' ' || $c === "\t" || $c === "\n" || $c === "\r") {
            $flush();
            $i++;
            continue;
        }
        if ($c === '{' || $c === '}' || $c === ';') {
            $flush();
            $t[] = [$c, $c];
            $i++;
            continue;
        }
        if ($c === '\\' && $i + 1 < $n) {
            $cur .= $s[$i + 1];
            $has = true;
            $i += 2;
            continue;
        }
        $cur .= $c;
        $has = true;
        $i++;
    }
    $flush();
    return $t;
}

function parse_nodes(array $t, int &$i): array
{
    $nodes = [];
    $words = [];
    $n = count($t);
    while ($i < $n) {
        $type = $t[$i][0];
        $val = $t[$i][1];
        $i++;
        if ($type === 'w') {
            $words[] = $val;
        } elseif ($type === ';') {
            if ($words) {
                $name = array_shift($words);
                $nodes[] = ['n' => $name, 'a' => $words, 'c' => null];
            }
            $words = [];
        } elseif ($type === '{') {
            $name = $words ? array_shift($words) : '';
            $args = $words;
            $words = [];
            $nodes[] = ['n' => $name, 'a' => $args, 'c' => parse_nodes($t, $i)];
        } else { // }
            return $nodes;
        }
    }
    return $nodes;
}

function find_servers(array $nodes, string $file, array &$acc): void
{
    foreach ($nodes as $node) {
        if ($node['c'] === null) {
            continue;
        }
        if ($node['n'] === 'server') {
            $acc[] = [$file, $node['c']];
        } elseif ($node['n'] !== 'stream' && $node['n'] !== 'mail') {
            find_servers($node['c'], $file, $acc);
        }
    }
}

function first_deep(array $nodes, string $name): ?string
{
    foreach ($nodes as $node) {
        if ($node['n'] === $name && $node['c'] === null && isset($node['a'][0])) {
            return $node['a'][0];
        }
        if ($node['c'] !== null) {
            $r = first_deep($node['c'], $name);
            if ($r !== null) {
                return $r;
            }
        }
    }
    return null;
}

function cert_info(?string $path): ?array
{
    if ($path === null || !is_file($path)) {
        return null;
    }
    if (function_exists('openssl_x509_parse')) {
        $x = @openssl_x509_parse((string) @file_get_contents($path));
        if (is_array($x) && isset($x['validTo_time_t'])) {
            $issuer = $x['issuer']['O'] ?? ($x['issuer']['CN'] ?? '');
            if (is_array($issuer)) {
                $issuer = implode(', ', $issuer);
            }
            $cn = $x['subject']['CN'] ?? '';
            return [
                'not_after' => (int) $x['validTo_time_t'],
                'issuer' => (string) $issuer,
                'cn' => is_array($cn) ? implode(', ', $cn) : (string) $cn,
                'san' => (string) ($x['extensions']['subjectAltName'] ?? ''),
            ];
        }
    }
    if (run(['openssl', 'x509', '-noout', '-enddate', '-issuer', '-in', $path], $out) === 0
        && preg_match('/notAfter=(.+)/', (string) $out, $m)) {
        preg_match('/issuer=(.+)/', (string) $out, $mi);
        return ['not_after' => (int) strtotime(trim($m[1])), 'issuer' => trim($mi[1] ?? ''), 'cn' => '', 'san' => ''];
    }
    return null;
}

function load_sites(bool $withCerts = true): array
{
    $rc = run([NGINX_BIN, '-T'], $dump);
    if ($dump === null || strpos($dump, '# configuration file ') === false) {
        fail("Не удалось прочитать конфигурацию nginx (код $rc):\n" . substr((string) $dump, 0, 2000));
    }
    $parts = preg_split('/^# configuration file (.+):$/m', $dump, -1, PREG_SPLIT_DELIM_CAPTURE);
    $servers = [];
    $GLOBALS['NGX_RAW'] = $dump;
    $GLOBALS['NGX'] = [];
    for ($k = 1; $k + 1 < count($parts); $k += 2) {
        $i = 0;
        $nodes = parse_nodes(tokenize($parts[$k + 1]), $i);
        $GLOBALS['NGX'][trim($parts[$k])] = $nodes;
        find_servers($nodes, trim($parts[$k]), $servers);
    }

    $sites = [];
    foreach ($servers as $srv) {
        $file = $srv[0];
        $names = [];
        $ssl = false;
        $root = null;
        $cert = null;
        $key = null;
        $listens = [];
        foreach ($srv[1] as $d) {
            if ($d['c'] !== null) {
                continue;
            }
            if ($d['n'] === 'server_name') {
                foreach ($d['a'] as $nm) {
                    $nm = strtolower($nm);
                    if (preg_match(DOMAIN_RE, $nm)) {
                        $names[] = $nm;
                    }
                }
            } elseif ($d['n'] === 'listen') {
                if (in_array('ssl', $d['a'], true)) {
                    $ssl = true;
                }
                $listens[] = [$d['a'][0] ?? '', in_array('ssl', $d['a'], true)];
            } elseif ($d['n'] === 'root' && isset($d['a'][0])) {
                $root = $d['a'][0];
            } elseif ($d['n'] === 'ssl_certificate' && isset($d['a'][0])) {
                $cert = $d['a'][0];
            } elseif ($d['n'] === 'ssl_certificate_key' && isset($d['a'][0])) {
                $key = $d['a'][0];
            }
        }
        if (!$names) {
            continue;
        }
        if ($root === null) {
            $root = first_deep($srv[1], 'root');
        }
        $backend = first_deep($srv[1], 'fastcgi_pass') ?? first_deep($srv[1], 'proxy_pass');
        $primary = $names[0];
        foreach ($names as $nm) { // основное имя — без www и без маски
            if ($nm[0] !== '*' && strpos($nm, 'www.') !== 0) {
                $primary = $nm;
                break;
            }
        }
        if (!isset($sites[$primary])) {
            $sites[$primary] = ['domain' => $primary, 'names' => [], 'ssl' => false, 'root' => null,
                'cert' => null, 'key' => null, 'backend' => null, 'files' => [], 'listens' => []];
        }
        $s = &$sites[$primary];
        $s['names'] = array_values(array_unique(array_merge($s['names'], $names)));
        $s['ssl'] = $s['ssl'] || $ssl || $cert !== null;
        $s['root'] = $s['root'] ?? $root;
        $s['cert'] = $s['cert'] ?? $cert;
        $s['key'] = $s['key'] ?? $key;
        $s['backend'] = $s['backend'] ?? $backend;
        $s['listens'] = array_merge($s['listens'], $listens);
        if (!in_array($file, $s['files'], true)) {
            $s['files'][] = $file;
        }
        unset($s);
    }
    if ($withCerts) {
        foreach ($sites as &$s) {
            $s['cert_info'] = cert_info($s['cert']);
            $s['wildcard'] = (bool) array_filter($s['names'], function ($n) {
                return $n[0] === '*';
            });
            $meta = site_meta($s['domain']);
            $s['managed'] = $meta !== null;
            $s['type'] = $meta['type'] ?? null;
            $s['php_ver'] = $meta['php_ver'] ?? null;
            $st = @json_decode((string) @file_get_contents(DNS_STATE . '/' . $s['domain'] . '.json'), true);
            $s['dns_pending'] = is_array($st) ? $st['records'] : null;
            unset($s['listens']);
        }
        unset($s);
    }
    ksort($sites);
    return $sites;
}

/* ---------- Службы ---------- */

function list_services(): array
{
    run(['systemctl', 'list-unit-files', '--type=service', '--no-legend', '--no-pager'], $out);
    $res = [];
    foreach (explode("\n", (string) $out) as $line) {
        $cols = preg_split('/\s+/', trim($line));
        $unit = $cols[0] ?? '';
        if ($unit === '' || !preg_match(SERVICE_RE, $unit)) {
            continue;
        }
        run(['systemctl', 'is-active', $unit], $active);
        $res[] = ['unit' => $unit, 'active' => trim((string) $active), 'enabled' => $cols[1] ?? ''];
    }
    return $res;
}

function service_restart(string $unit): void
{
    if (!preg_match(SERVICE_RE, $unit)) {
        fail('Эта служба не входит в список разрешённых');
    }
    if ($unit === 'nginx.service') {
        if (run([NGINX_BIN, '-t'], $out) !== 0) {
            fail("Конфигурация nginx содержит ошибку, перезапуск отменён:\n$out");
        }
    }
    $rc = run(['systemctl', 'restart', $unit], $out);
    echo trim((string) $out), "\n";
    run(['systemctl', 'is-active', $unit], $active);
    echo "Состояние $unit: ", trim((string) $active), "\n";
    exit($rc === 0 ? 0 : 1);
}

/* ---------- SSL ---------- */

function install_cert(string $primary, string $cert, string $key): void
{
    foreach ([$cert, $key] as $f) { // сохраняем исходные файлы один раз
        if (is_file($f) && !is_file($f . '.before-dewiar')) {
            @copy($f, $f . '.before-dewiar');
            @chmod($f . '.before-dewiar', 0600);
        }
    }
    $rc = run([ACME, '--home', ACME_HOME, '--install-cert', '-d', $primary,
        '--fullchain-file', $cert, '--key-file', $key,
        '--reloadcmd', 'nginx -t && (systemctl reload nginx || service nginx reload)'], $out);
    echo $out, "\n";
    if ($rc !== 0) {
        fail('Сертификат получен, но установить его в nginx не удалось.');
    }
}

function ssl_site(string $domain): array
{
    $domain = strtolower($domain);
    if (!preg_match(DOMAIN_RE, $domain)) {
        fail('Некорректное имя домена');
    }
    if (!is_executable(ACME)) {
        fail('acme.sh не установлен. Запустите install.sh ещё раз.');
    }
    $sites = load_sites(false);
    if (!isset($sites[$domain])) {
        fail('Домен не найден в конфигурации nginx');
    }
    return $sites[$domain];
}

function ssl_issue(string $domain, bool $force): void
{
    $s = ssl_site($domain);
    $domain = $s['domain'];
    $meta = site_meta($domain);
    $enable = false;
    foreach ($s['names'] as $nm) {
        if ($nm[0] === '*') {
            fail("У сайта есть имя с маской ($nm). Такой сертификат выпускается через DNS: кнопка «Wildcard SSL» в карточке домена.");
        }
    }
    if (!$s['cert'] || !$s['key']) {
        if (!$meta) {
            fail('У этого сайта в nginx не настроен SSL (нет ssl_certificate). Добавьте его в редакторе конфигурации.');
        }
        if (!is_dir(SSL_DIR)) {
            mkdir(SSL_DIR, 0700, true);
        }
        $s['cert'] = SSL_DIR . "/$domain.crt";
        $s['key'] = SSL_DIR . "/$domain.key";
        $enable = true;
    }
    if (!$s['root'] || !is_dir($s['root'])) {
        fail('Не найден каталог сайта (root): ' . ($s['root'] ?? '—'));
    }
    $names = [];
    foreach ($s['names'] as $nm) {
        if (!@dns_get_record($nm, DNS_A) && !@dns_get_record($nm, DNS_AAAA) && gethostbyname($nm) === $nm) {
            echo "Пропущено $nm: имя не найдено в DNS\n";
            continue;
        }
        $names[] = $nm;
    }
    if (!$names) {
        fail('Не осталось имён для выпуска сертификата');
    }
    usort($names, function ($a, $b) use ($domain) {
        return ($a === $domain ? 0 : 1) <=> ($b === $domain ? 0 : 1);
    });
    echo 'Выпуск сертификата для: ', implode(', ', $names), "\n";

    $cmd = [ACME, '--home', ACME_HOME, '--issue', '--server', 'letsencrypt', '-w', $s['root']];
    foreach ($names as $nm) {
        $cmd[] = '-d';
        $cmd[] = $nm;
    }
    if ($force) {
        $cmd[] = '--force';
    }
    $rc = run($cmd, $out);
    echo $out, "\n";
    if ($rc !== 0 && $rc !== 2) { // 2 = сертификат ещё свежий, обновление не требуется
        fail('Let\'s Encrypt не выдал сертификат (код ' . $rc . '). Причина — в журнале выше.');
    }
    install_cert($names[0], $s['cert'], $s['key']);
    if ($enable) {
        $meta['ssl'] = true;
        write_site($meta);
        echo "HTTPS для сайта включён, с HTTP настроено перенаправление.\n";
    }
    $ci = cert_info($s['cert']);
    if ($ci) {
        echo 'Готово. Сертификат действует до ', date('d.m.Y', $ci['not_after']), ". Автопродление включено.\n";
    }
    exit(0);
}

/* ---------- Wildcard-сертификаты: проверка через TXT-записи в DNS ---------- */

function dns_names(array $s): array
{
    $bases = [];
    foreach ($s['names'] as $nm) {
        if ($nm[0] === '*') {
            $bases[] = substr($nm, 2);
        }
    }
    $out = [];
    foreach ($bases as $b) {
        $out[] = $b;
        $out[] = '*.' . $b;
    }
    foreach ($s['names'] as $nm) {
        if ($nm[0] === '*') {
            continue;
        }
        $covered = false;
        foreach ($bases as $b) {
            $tail = '.' . $b;
            if ($nm === $b || (substr($nm, -strlen($tail)) === $tail && strpos(substr($nm, 0, -strlen($tail)), '.') === false)) {
                $covered = true;
            }
        }
        if (!$covered) {
            $out[] = $nm;
        }
    }
    $out = array_values(array_unique($out));
    $d = $s['domain'];
    usort($out, function ($a, $b) use ($d) {
        return ($a === $d ? 0 : 1) <=> ($b === $d ? 0 : 1);
    });
    return $out;
}

function ssl_dns_start(string $domain): void
{
    $s = ssl_site($domain);
    if (!$s['cert'] || !$s['key']) {
        fail('У этого сайта в nginx не настроен SSL (нет ssl_certificate).');
    }
    $names = dns_names($s);
    if (!array_filter($names, function ($n) {
        return $n[0] === '*';
    })) {
        fail('У сайта нет имён с маской, используйте обычную кнопку выпуска.');
    }
    $cmd = [ACME, '--home', ACME_HOME, '--issue', '--server', 'letsencrypt', '--dns', DNS_MANUAL, '--force'];
    foreach ($names as $nm) {
        $cmd[] = '-d';
        $cmd[] = $nm;
    }
    run($cmd, $out);
    if (!preg_match_all("/Domain: '([^']+)'.*?TXT value: '([^']+)'/s", (string) $out, $m, PREG_SET_ORDER)) {
        fail("Let's Encrypt не выдал задания для проверки:\n$out");
    }
    $records = [];
    foreach ($m as $r) {
        $records[] = ['name' => $r[1], 'value' => $r[2]];
    }
    if (!is_dir(DNS_STATE)) {
        mkdir(DNS_STATE, 0700, true);
    }
    file_put_contents(DNS_STATE . '/' . $s['domain'] . '.json', json_encode(['primary' => $names[0], 'names' => $names, 'records' => $records, 'time' => time()]));
    echo 'Сертификат будет выпущен для: ', implode(', ', $names), "\n\n";
    echo "Шаг 1 выполнен. Добавьте у регистратора домена TXT-записи:\n\n";
    foreach ($records as $r) {
        echo '  Имя:      ', $r['name'], "\n  Значение: ", $r['value'], "\n\n";
    }
    echo "Если записей с одним именем две, нужны обе одновременно.\n";
    echo "После сохранения подождите 5–30 минут и нажмите в карточке домена «Записи добавлены — выпустить».\n";
    exit(0);
}

function txt_values(string $name): array
{
    $vals = [];
    foreach (@dns_get_record($name, DNS_TXT) ?: [] as $r) {
        $vals[] = (string) ($r['txt'] ?? '');
    }
    $dig = which(['dig']);
    if ($dig) {
        foreach (['1.1.1.1', '8.8.8.8'] as $ns) {
            run([$dig, '+short', '+time=3', '+tries=1', 'TXT', $name, '@' . $ns], $o);
            foreach (explode("\n", (string) $o) as $line) {
                $line = trim(str_replace('"', '', $line));
                if ($line !== '' && $line[0] !== ';') {
                    $vals[] = $line;
                }
            }
        }
    }
    return array_unique($vals);
}

function ssl_dns_finish(string $domain): void
{
    $s = ssl_site($domain);
    $file = DNS_STATE . '/' . $s['domain'] . '.json';
    $st = @json_decode((string) @file_get_contents($file), true);
    if (!is_array($st)) {
        fail('Сначала выполните шаг 1: «Wildcard SSL: получить TXT-записи».');
    }
    $missing = [];
    foreach ($st['records'] as $r) {
        if (!in_array($r['value'], txt_values($r['name']), true)) {
            $missing[] = $r;
        }
    }
    if ($missing) {
        echo "Эти TXT-записи пока не видны в DNS:\n\n";
        foreach ($missing as $r) {
            echo '  Имя:      ', $r['name'], "\n  Значение: ", $r['value'], "\n\n";
        }
        fail("Проверьте, что записи сохранены у регистратора, подождите 10–30 минут и повторите.\nЗапрос в Let's Encrypt не отправлялся, лимиты не расходуются.");
    }
    echo "TXT-записи найдены, запрашиваю сертификат…\n";
    $rc = run([ACME, '--home', ACME_HOME, '--renew', '-d', $st['primary'], DNS_MANUAL, '--force'], $out);
    echo $out, "\n";
    if ($rc !== 0) {
        fail('Let\'s Encrypt не подтвердил записи (код ' . $rc . '). Причина — в журнале выше. Если задания устарели, начните с шага 1.');
    }
    install_cert($st['primary'], $s['cert'], $s['key']);
    @unlink($file);
    $ci = cert_info($s['cert']);
    echo 'Готово. Сертификат действует до ', $ci ? date('d.m.Y', $ci['not_after']) : '—', ".\n";
    echo "TXT-записи у регистратора теперь можно удалить.\n";
    echo "Важно: такой сертификат не продлевается сам. Перед окончанием срока повторите оба шага.\n";
    exit(0);
}

/* ---------- Сайты, созданные панелью ---------- */

function site_meta(string $domain): ?array
{
    $m = @json_decode((string) @file_get_contents(META_DIR . "/$domain.json"), true);
    return is_array($m) ? $m : null;
}

function nginx_test_reload(): ?string
{
    if (run([NGINX_BIN, '-t'], $out) !== 0) {
        return (string) $out;
    }
    if (run(['systemctl', 'reload', 'nginx'], $out) !== 0) {
        return (string) $out;
    }
    return null;
}

/** Сведения, нужные для создания сайта: куда класть конфиг, какие есть версии PHP. */
function site_options(): array
{
    $sites = load_sites(false);
    $files = array_keys($GLOBALS['NGX']);
    $main = $files[0] ?? '/etc/nginx/nginx.conf';
    $user = null;
    $includes = [];
    foreach ($GLOBALS['NGX'][$main] ?? [] as $n) {
        if ($n['n'] === 'user' && $n['c'] === null) {
            $user = $n['a'][0] ?? null;
        }
        if ($n['n'] === 'http' && $n['c'] !== null) {
            foreach ($n['c'] as $h) {
                if ($h['n'] === 'include' && $h['c'] === null && isset($h['a'][0])) {
                    $pat = $h['a'][0];
                    $includes[] = $pat[0] === '/' ? $pat : dirname($main) . '/' . $pat;
                }
            }
        }
    }
    $confDir = null;
    foreach ($includes as $pat) {
        if (preg_match('~^(/[^*?]+)/\*(\.conf)?$~', $pat, $m) && stripos($pat, 'fastpanel') === false
            && stripos($pat, 'modules') === false && is_dir($m[1])) {
            if ($confDir === null || substr($m[1], -7) === '/conf.d') {
                $confDir = $m[1];
            }
        }
    }
    $cnt80 = [];
    $cnt443 = [];
    foreach ($sites as $s) {
        foreach ($s['listens'] as $l) {
            if ($l[1]) {
                $cnt443[$l[0]] = ($cnt443[$l[0]] ?? 0) + 1;
            } elseif (preg_match('/(^|:)80$/', $l[0])) {
                $cnt80[$l[0]] = ($cnt80[$l[0]] ?? 0) + 1;
            }
        }
    }
    arsort($cnt80);
    arsort($cnt443);
    $l80 = (string) (array_key_first($cnt80) ?? '80');
    $l443 = (string) (array_key_first($cnt443) ?? '443');
    if ($user === null || !function_exists('posix_getpwnam') || !posix_getpwnam($user)) {
        $user = (function_exists('posix_getpwnam') && posix_getpwnam('www-data')) ? 'www-data' : 'nginx';
    }
    return ['conf_dir' => $confDir, 'web_user' => $user, 'listen80' => $l80, 'listen443' => $l443,
        'php' => php_versions(), 'domains' => array_keys($sites)];
}

function php_versions(): array
{
    run(['systemctl', 'list-unit-files', '--type=service', '--no-legend', '--no-pager'], $out);
    $res = [];
    foreach (explode("\n", (string) $out) as $line) {
        $unit = preg_split('/\s+/', trim($line))[0] ?? '';
        if (!preg_match('/^(fp2-)?php(\d)\.?(\d)-fpm\.service$/', $unit, $m)) {
            continue;
        }
        run(['systemctl', 'show', '-p', 'ExecStart', '--value', $unit], $es);
        if (!preg_match('/path=(\S+)/', (string) $es, $mb) || !is_executable($mb[1])) {
            continue;
        }
        $conf = null;
        if (preg_match('/(?:--fpm-config|-y)[ =](\S+)/', (string) $es, $mc)) {
            $conf = $mc[1];
        } else {
            run([$mb[1], '-t'], $t);
            if (preg_match('/configuration file (\S+) test/', (string) $t, $mt)) {
                $conf = $mt[1];
            }
        }
        if (!$conf || !preg_match('/^\s*include\s*=\s*(\/\S+)\/\*\.conf\s*$/m', (string) @file_get_contents($conf), $mi) || !is_dir($mi[1])) {
            continue;
        }
        $res[$m[2] . '.' . $m[3]] = ['ver' => $m[2] . '.' . $m[3], 'unit' => $unit, 'bin' => $mb[1], 'pool_dir' => $mi[1]];
    }
    krsort($res, SORT_NATURAL);
    return array_values($res);
}

function gen_site_conf(array $m): string
{
    $names = $m['domain'] . ($m['www'] ? ' www.' . $m['domain'] : '');
    $log = "    access_log /var/log/nginx/dewiar-{$m['domain']}.access.log;\n    error_log /var/log/nginx/dewiar-{$m['domain']}.error.log;\n";
    $acme = "    location ^~ /.well-known/acme-challenge/ { root {$m['root']}; default_type text/plain; }\n";
    if ($m['type'] === 'php') {
        $body = "    index index.php index.html;\n    location / { try_files \$uri \$uri/ /index.php?\$args; }\n"
            . "    location ~ \\.php\$ {\n        try_files \$uri =404;\n        include fastcgi_params;\n"
            . "        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;\n        fastcgi_pass unix:{$m['sock']};\n    }\n";
    } elseif ($m['type'] === 'proxy') {
        $body = "    location / {\n        proxy_pass {$m['proxy_url']};\n        proxy_set_header Host \$host;\n"
            . "        proxy_set_header X-Real-IP \$remote_addr;\n        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;\n"
            . "        proxy_set_header X-Forwarded-Proto \$scheme;\n    }\n";
    } else {
        $body = "    index index.html;\n    location / { try_files \$uri \$uri/ =404; }\n";
    }
    $body .= "    location ~ /\\.(?!well-known) { deny all; }\n";
    $head = "# Создано Dewiar Server Panel. Сайт: {$m['domain']}\n";
    if (empty($m['ssl'])) {
        return $head . "server {\n    listen {$m['listen80']};\n    server_name $names;\n    root {$m['root']};\n$log$acme$body}\n";
    }
    return $head . "server {\n    listen {$m['listen80']};\n    server_name $names;\n$acme    location / { return 301 https://\$host\$request_uri; }\n}\n"
        . "server {\n    listen {$m['listen443']} ssl;\n    server_name $names;\n    root {$m['root']};\n"
        . "    ssl_certificate " . SSL_DIR . "/{$m['domain']}.crt;\n    ssl_certificate_key " . SSL_DIR . "/{$m['domain']}.key;\n$log$body}\n";
}

/** Записывает конфиг сайта, проверяет nginx и откатывает при ошибке. */
function write_site(array $m): void
{
    $old = is_file($m['conf']) ? file_get_contents($m['conf']) : null;
    file_put_contents($m['conf'], gen_site_conf($m));
    $err = nginx_test_reload();
    if ($err !== null) {
        if ($old === null) {
            unlink($m['conf']);
        } else {
            file_put_contents($m['conf'], $old);
        }
        fail("nginx не принял новую конфигурацию, изменения отменены:\n$err");
    }
    if (!is_dir(META_DIR)) {
        mkdir(META_DIR, 0700, true);
    }
    file_put_contents(META_DIR . "/{$m['domain']}.json", json_encode($m, JSON_UNESCAPED_SLASHES));
}

function write_pool(array $m, array $php, string $webUser): void
{
    $name = 'dewiar-' . str_replace('.', '_', $m['domain']);
    $file = $php['pool_dir'] . "/dewiar-{$m['domain']}.conf";
    file_put_contents($file, "; Создано Dewiar Server Panel\n[$name]\nuser = {$m['user']}\ngroup = {$m['user']}\n"
        . "listen = {$m['sock']}\nlisten.owner = $webUser\nlisten.group = $webUser\nlisten.mode = 0660\n"
        . "pm = ondemand\npm.max_children = 10\npm.process_idle_timeout = 30s\n"
        . "php_admin_value[open_basedir] = {$m['home']}:/tmp\nphp_admin_value[upload_tmp_dir] = /tmp\n");
    if (run([$php['bin'], '-t'], $out) !== 0) {
        unlink($file);
        fail("PHP-FPM {$php['ver']} не принял настройки пула:\n$out");
    }
    run(['systemctl', 'reload', $php['unit']], $out) === 0 || run(['systemctl', 'restart', $php['unit']], $out);
}

function find_php(string $ver): array
{
    foreach (php_versions() as $p) {
        if ($p['ver'] === $ver) {
            return $p;
        }
    }
    fail("PHP-FPM версии $ver на сервере не найден");
}

function site_create(): void
{
    $in = json_decode((string) stream_get_contents(STDIN), true);
    $domain = strtolower(trim((string) ($in['domain'] ?? '')));
    $type = (string) ($in['type'] ?? '');
    if (!preg_match(DOMAIN_RE, $domain) || $domain[0] === '*') {
        fail('Некорректное имя домена. Пример: example.com');
    }
    if (!in_array($type, ['static', 'php', 'proxy'], true)) {
        fail('Не выбран тип сайта');
    }
    $opt = site_options();
    if (!$opt['conf_dir']) {
        fail("В nginx.conf не найден каталог для дополнительных сайтов.\nДобавьте в блок http строку: include /etc/nginx/conf.d/*.conf; и создайте этот каталог.");
    }
    $www = !empty($in['www']);
    foreach ($opt['domains'] as $d) {
        if ($d === $domain || ($www && $d === "www.$domain")) {
            fail('Такой домен уже есть на сервере');
        }
    }
    $home = SITES_ROOT . "/$domain";
    $m = ['domain' => $domain, 'www' => $www, 'type' => $type, 'ssl' => false,
        'home' => $home, 'root' => "$home/public", 'user' => 'dw_' . substr(md5($domain), 0, 10),
        'conf' => $opt['conf_dir'] . "/dewiar-$domain.conf", 'listen80' => $opt['listen80'], 'listen443' => $opt['listen443'],
        'sock' => SOCK_DIR . "/$domain.sock", 'php_ver' => null, 'proxy_url' => null];
    if ($type === 'proxy') {
        $m['proxy_url'] = (string) ($in['proxy_url'] ?? '');
        if (!preg_match('~^https?://[a-z0-9.\-]+(:\d{1,5})?$~i', $m['proxy_url'])) {
            fail('Адрес приложения должен выглядеть так: http://127.0.0.1:3000');
        }
    }
    $php = null;
    if ($type === 'php') {
        $php = find_php((string) ($in['php_ver'] ?? ''));
        $m['php_ver'] = $php['ver'];
    }
    if (run(['id', $m['user']], $o) !== 0 && run(['useradd', '--system', '--home-dir', $home, '--shell', '/usr/sbin/nologin', $m['user']], $o) !== 0) {
        fail("Не удалось создать пользователя сайта:\n$o");
    }
    if (!is_dir($m['root'])) {
        mkdir($m['root'], 0755, true);
        file_put_contents($m['root'] . '/index.html', "<!doctype html><meta charset=\"utf-8\"><title>$domain</title><h1>$domain</h1><p>Сайт создан в Dewiar Server Panel. Загрузите файлы в каталог public.</p>\n");
    }
    run(['chown', '-R', $m['user'] . ':' . $m['user'], $home], $o);
    chmod($home, 0755);
    echo "Каталог сайта: {$m['root']} (владелец {$m['user']})\n";
    if ($php) {
        if (!is_dir(SOCK_DIR)) {
            mkdir(SOCK_DIR, 0755, true);
        }
        write_pool($m, $php, $opt['web_user']);
        echo "PHP {$php['ver']}: создан отдельный пул для сайта\n";
    }
    write_site($m);
    echo "Конфигурация nginx: {$m['conf']}\n";
    echo "Сайт создан и работает по HTTP. Направьте домен на сервер и нажмите «Выпустить / продлить SSL» в его карточке.\n";
    exit(0);
}

function site_php(string $domain, string $ver): void
{
    $m = site_meta(strtolower($domain));
    if (!$m || $m['type'] !== 'php') {
        fail('Смена версии PHP кнопкой доступна для PHP-сайтов, созданных в панели. Для остальных измените конфигурацию в редакторе.');
    }
    $new = find_php($ver);
    if ($m['php_ver'] === $new['ver']) {
        fail('Сайт уже работает на PHP ' . $ver);
    }
    $old = null;
    foreach (php_versions() as $p) {
        if ($p['ver'] === $m['php_ver']) {
            $old = $p;
        }
    }
    $opt = site_options();
    if ($old) {
        @unlink($old['pool_dir'] . "/dewiar-{$m['domain']}.conf");
        run(['systemctl', 'reload', $old['unit']], $o) === 0 || run(['systemctl', 'restart', $old['unit']], $o);
    }
    write_pool($m, $new, $opt['web_user']);
    $m['php_ver'] = $new['ver'];
    file_put_contents(META_DIR . "/{$m['domain']}.json", json_encode($m, JSON_UNESCAPED_SLASHES));
    echo "Сайт {$m['domain']} переключён на PHP {$new['ver']}.\n";
    exit(0);
}

function site_delete(string $domain): void
{
    $m = site_meta(strtolower($domain));
    if (!$m) {
        fail('Удалять кнопкой можно только сайты, созданные в панели.');
    }
    $conf = (string) @file_get_contents($m['conf']);
    @unlink($m['conf']);
    $err = nginx_test_reload();
    if ($err !== null) {
        file_put_contents($m['conf'], $conf);
        fail("nginx не принял конфигурацию без этого сайта, удаление отменено:\n$err");
    }
    foreach (php_versions() as $p) {
        $pool = $p['pool_dir'] . "/dewiar-{$m['domain']}.conf";
        if (is_file($pool)) {
            unlink($pool);
            run(['systemctl', 'reload', $p['unit']], $o) === 0 || run(['systemctl', 'restart', $p['unit']], $o);
        }
    }
    unlink(META_DIR . "/{$m['domain']}.json");
    echo "Сайт {$m['domain']} отключён и убран из nginx.\n";
    echo "Файлы сайта сохранены в {$m['home']}. Удалите их вручную, если они больше не нужны.\n";
    exit(0);
}

/* ---------- Сохранение конфигурации с проверкой и откатом ---------- */

function config_save(string $domain, string $path): void
{
    $new = (string) stream_get_contents(STDIN);
    if ($new === '' || strlen($new) > 524288) {
        fail('Пустой или слишком большой файл (предел 512 КБ)');
    }
    $new = str_replace("\r\n", "\n", $new);
    $kind = null;
    foreach (site_config($domain) as $f) {
        if ($f['file'] === $path) {
            $kind = $f['kind'];
        }
    }
    if ($kind === null) {
        fail('Этот файл не относится к выбранному сайту');
    }
    $old = (string) file_get_contents($path);
    if ($old === $new) {
        fail('Изменений нет');
    }
    if (!is_dir(CONF_BAK)) {
        mkdir(CONF_BAK, 0700, true);
    }
    $bak = CONF_BAK . '/' . trim(str_replace('/', '_', $path), '_') . '.' . date('Ymd-His');
    file_put_contents($bak, $old);
    file_put_contents($path, $new);
    if ($kind === 'nginx') {
        $err = nginx_test_reload();
    } else {
        $ctl = which(['apache2ctl', 'apachectl']);
        $err = ($ctl && run([$ctl, '-t'], $out) !== 0) ? (string) $out : null;
        if ($err === null && run(['systemctl', 'reload', 'apache2'], $out) !== 0 && run(['systemctl', 'reload', 'httpd'], $out) !== 0) {
            $err = (string) $out;
        }
    }
    if ($err !== null) {
        file_put_contents($path, $old);
        if ($kind === 'nginx') {
            nginx_test_reload();
        }
        fail("Проверка не пройдена, прежний файл возвращён, сайты работают как раньше:\n$err");
    }
    echo "Сохранено и применено. Прежняя версия файла: $bak\n";
    exit(0);
}

/* ---------- Перезагрузка конфигурации и сервера ---------- */

function service_reload(string $unit): void
{
    if (!preg_match('/^(nginx|apache2|httpd|(fp2-)?php[0-9.]*-?fpm)\.service$/', $unit)) {
        fail('Для этой службы доступен только перезапуск');
    }
    if ($unit === 'nginx.service' && run([NGINX_BIN, '-t'], $out) !== 0) {
        fail("Конфигурация nginx содержит ошибку, действие отменено:\n$out");
    }
    if ($unit === 'apache2.service' || $unit === 'httpd.service') {
        $ctl = which(['apache2ctl', 'apachectl']);
        if ($ctl && run([$ctl, '-t'], $out) !== 0) {
            fail("Конфигурация Apache содержит ошибку, действие отменено:\n$out");
        }
    }
    $rc = run(['systemctl', 'reload', $unit], $out);
    echo trim((string) $out), "\n", $rc === 0 ? "Конфигурация $unit перечитана без остановки службы.\n" : "Не удалось перечитать конфигурацию.\n";
    exit($rc === 0 ? 0 : 1);
}

function which(array $names): ?string
{
    foreach ($names as $n) {
        foreach (explode(':', (string) getenv('PATH')) as $dir) {
            if ($dir !== '' && is_executable("$dir/$n")) {
                return "$dir/$n";
            }
        }
    }
    return null;
}

/* ---------- Базы данных ---------- */

const DUMP_DIR = '/var/backups/dewiar-panel';
const DB_RE    = '/^[A-Za-z0-9_\-]{1,64}$/';
const DUMP_RE  = '/^[A-Za-z0-9_\-]{1,64}-\d{8}-\d{6}\.sql\.gz$/';

function list_databases(): array
{
    $bin = which(['mariadb', 'mysql']);
    if (!$bin) {
        fail('Клиент MySQL/MariaDB не найден на сервере');
    }
    $sql = 'SELECT s.schema_name, COALESCE(SUM(t.data_length + t.index_length), 0), COUNT(t.table_name) '
        . 'FROM information_schema.schemata s LEFT JOIN information_schema.tables t ON t.table_schema = s.schema_name '
        . 'GROUP BY s.schema_name ORDER BY s.schema_name';
    if (run([$bin, '-N', '-B', '-e', $sql], $out) !== 0) {
        fail("Не удалось подключиться к MySQL от имени root. Проверьте /root/.my.cnf.\n" . trim((string) $out));
    }
    $res = [];
    foreach (explode("\n", (string) $out) as $line) {
        $c = explode("\t", $line);
        if (count($c) !== 3 || in_array($c[0], ['information_schema', 'performance_schema', 'mysql', 'sys'], true)) {
            continue;
        }
        $res[] = ['name' => $c[0], 'size' => (float) $c[1], 'tables' => (int) $c[2]];
    }
    return $res;
}

function db_dump(string $name): void
{
    if (!preg_match(DB_RE, $name) || !in_array($name, array_column(list_databases(), 'name'), true)) {
        fail('База данных не найдена');
    }
    $bin = which(['mariadb-dump', 'mysqldump']);
    if (!$bin) {
        fail('Программа mysqldump не найдена на сервере');
    }
    if (!is_dir(DUMP_DIR)) {
        mkdir(DUMP_DIR, 0700, true);
    }
    $path = DUMP_DIR . '/' . $name . '-' . date('Ymd-His') . '.sql';
    $p = proc_open([$bin, '--single-transaction', '--quick', '--routines', '--triggers', '--events', $name],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', $path, 'w'], 2 => ['pipe', 'w']], $pipes);
    $err = is_resource($p) ? (string) stream_get_contents($pipes[2]) : 'не удалось запустить';
    $rc = is_resource($p) ? proc_close($p) : 127;
    if ($rc !== 0) {
        @unlink($path);
        fail("Дамп не создан:\n" . trim($err));
    }
    if (run(['gzip', '-f', $path], $out) !== 0) {
        @unlink($path);
        fail("Не удалось сжать дамп:\n$out");
    }
    chmod($path . '.gz', 0600);
    echo 'Дамп создан: ', basename($path), '.gz (', number_format(filesize($path . '.gz') / 1048576, 2, ',', ' '), " МБ)\n";
    echo 'Хранится на сервере в ', DUMP_DIR, ", скачать можно со страницы «Базы данных».\n";
    exit(0);
}

function list_dumps(): array
{
    $res = [];
    foreach (is_dir(DUMP_DIR) ? scandir(DUMP_DIR) : [] as $f) {
        if (preg_match(DUMP_RE, $f)) {
            $res[] = ['file' => $f, 'size' => filesize(DUMP_DIR . "/$f"), 'time' => filemtime(DUMP_DIR . "/$f")];
        }
    }
    usort($res, function ($a, $b) {
        return $b['time'] <=> $a['time'];
    });
    return $res;
}

function dump_path(string $file): string
{
    if (!preg_match(DUMP_RE, $file) || !is_file(DUMP_DIR . "/$file")) {
        fail('Файл дампа не найден');
    }
    return DUMP_DIR . "/$file";
}

/* ---------- Просмотр конфигурации сайта ---------- */

const APACHE_DIRS = ['/etc/apache2', '/etc/httpd'];

function site_config(string $domain): array
{
    $domain = strtolower($domain);
    $sites = preg_match(DOMAIN_RE, $domain) ? load_sites(false) : [];
    if (!isset($sites[$domain])) {
        fail('Домен не найден в конфигурации nginx');
    }
    $res = [];
    foreach ($sites[$domain]['files'] as $f) {
        $res[] = ['kind' => 'nginx', 'file' => $f, 'content' => (string) @file_get_contents($f)];
    }
    $seen = [];
    $re = '/^\s*Server(Name|Alias)\s[^\n]*(?<![\w.-])' . preg_quote($domain, '/') . '(?![\w.-])/mi';
    foreach (APACHE_DIRS as $dir) {
        if (!is_dir($dir)) {
            continue;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS));
        foreach ($it as $file) {
            $real = realpath((string) $file);
            if (!$real || isset($seen[$real]) || substr($real, -5) !== '.conf' || filesize($real) > 524288) {
                continue;
            }
            $seen[$real] = true;
            $content = (string) @file_get_contents($real);
            if (preg_match($re, $content)) {
                $res[] = ['kind' => 'apache', 'file' => $real, 'content' => $content];
            }
        }
    }
    return $res;
}

/* ---------- Безопасность: версии, обновления, защитные меры ---------- */

function pkg_of(?string $bin): ?string
{
    $real = $bin ? realpath($bin) : false;
    if (!$real || !which(['dpkg']) || run(['dpkg', '-S', $real], $o) !== 0) {
        return null;
    }
    return trim(explode(':', (string) $o)[0]) ?: null;
}

function pkg_origin(?string $pkg): ?string
{
    if (!$pkg || run(['apt-cache', 'policy', $pkg], $o) !== 0) {
        return null;
    }
    if (preg_match('/\*\*\*[^\n]*\n\s+\d+\s+(\S+)/', (string) $o, $m)) {
        return $m[1] === '/var/lib/dpkg/status' ? 'нет в подключённых репозиториях' : (string) (parse_url($m[1], PHP_URL_HOST) ?: $m[1]);
    }
    return null;
}

/** Упомянуто ли исправление уязвимости в журнале изменений пакета (так дистрибутивы отмечают перенесённые исправления). */
function changelog_has(?string $pkg, string $cve): bool
{
    foreach ($pkg ? (glob("/usr/share/doc/$pkg/changelog*") ?: []) : [] as $f) {
        if (substr($f, -3) === '.gz') {
            run(['zcat', $f], $c);
        } else {
            $c = (string) @file_get_contents($f);
        }
        if (strpos((string) $c, $cve) !== false) {
            return true;
        }
    }
    return false;
}

function security_report(): array
{
    load_sites(false);
    $raw = (string) $GLOBALS['NGX_RAW'];
    $r = ['nginx' => null, 'apache' => null, 'php' => [], 'updates' => null];

    run([NGINX_BIN, '-v'], $o);
    if (preg_match('~nginx/([\d.]+)~', (string) $o, $m)) {
        $pkg = pkg_of(which([NGINX_BIN]));
        $r['nginx'] = ['version' => $m[1], 'pkg' => $pkg, 'origin' => pkg_origin($pkg),
            'http2' => (bool) preg_match('/^\s*listen\s[^;#]*\shttp2\b|^\s*http2\s+on\s*;/m', $raw),
            'keep_conn' => (bool) preg_match('/^\s*fastcgi_keep_conn\s+on\s*;/m', $raw),
            'backport' => changelog_has($pkg, 'CVE-2026-123007')];
    }

    $ab = which(['apache2', 'httpd']);
    if ($ab && run([$ab, '-v'], $o) === 0 && preg_match('~Apache/([\d.]+)~', (string) $o, $m)) {
        $pkg = pkg_of($ab);
        $ctl = which(['apache2ctl', 'apachectl']);
        $mods = '';
        if ($ctl) {
            run([$ctl, '-M'], $mods);
        }
        $r['apache'] = ['version' => $m[1], 'pkg' => $pkg, 'origin' => pkg_origin($pkg),
            'http2' => strpos((string) $mods, 'http2_module') !== false,
            'can_dismod' => which(['a2dismod']) !== null,
            'backport' => changelog_has($pkg, 'CVE-2026-119618')];
    }

    $bins = [];
    foreach (php_versions() as $p) {
        $bins[$p['bin']] = $p['unit'];
    }
    foreach (array_merge(glob('/opt/php*/bin/php') ?: [], array_filter([which(['php'])])) as $b) {
        $bins += [$b => null];
    }
    $seen = [];
    foreach ($bins as $bin => $unit) {
        run([$bin, '-v'], $o);
        if (!preg_match('/PHP (\d+\.\d+\.\d+)/', (string) $o, $m) || isset($seen[$m[1] . ($unit ? 'f' : 'c')])) {
            continue;
        }
        $seen[$m[1] . ($unit ? 'f' : 'c')] = true;
        run([$bin, '-i'], $info);
        $pkg = pkg_of($bin);
        $r['php'][] = ['version' => $m[1], 'unit' => $unit, 'bin' => $bin, 'pkg' => $pkg, 'origin' => pkg_origin($pkg),
            'upload_progress' => (bool) preg_match('/session\.upload_progress\.enabled => On/i', (string) $info),
            'backport' => changelog_has($pkg, 'CVE-2026-129289')];
    }

    if (which(['apt'])) {
        run(['apt', 'list', '--upgradable'], $o);
        $all = [];
        foreach (explode("\n", (string) $o) as $line) {
            if (preg_match('~^([a-z0-9.+\-]+)/\S+\s+(\S+)\s+\S+\s+\[upgradable from: ([^\]]+)\]~', $line, $m)) {
                $all[] = ['pkg' => $m[1], 'new' => $m[2], 'old' => $m[3]];
            }
        }
        $web = array_values(array_filter($all, function ($u) {
            return (bool) preg_match('/^(nginx|apache2|libapache2|php|fp2-|libnginx|openssl|libssl)/', $u['pkg']);
        }));
        $stamp = @filemtime('/var/lib/apt/periodic/update-success-stamp') ?: (@filemtime('/var/lib/apt/lists') ?: 0);
        $r['updates'] = ['total' => count($all), 'web' => $web, 'checked' => $stamp];
    }
    return $r;
}

function sec_refresh(): void
{
    if (!which(['apt-get'])) {
        fail('На этом сервере нет apt. Обновления проверяются средствами системы (yum или dnf) по SSH.');
    }
    $rc = run(['apt-get', 'update'], $out);
    echo trim((string) $out), "\n";
    exit($rc === 0 ? 0 : 1);
}

function sec_upgrade(): void
{
    $rep = security_report();
    $pkgs = array_column($rep['updates']['web'] ?? [], 'pkg');
    if (!$pkgs) {
        fail('Для nginx, Apache и PHP обновлений в репозиториях нет. Нажмите «Проверить обновления» и повторите.');
    }
    putenv('DEBIAN_FRONTEND=noninteractive');
    echo 'Обновляются пакеты: ', implode(', ', $pkgs), "\n\n";
    $rc = run(array_merge(['apt-get', 'install', '--only-upgrade', '-y', '-o', 'Dpkg::Options::=--force-confold', '-o', 'Dpkg::Options::=--force-confdef'], $pkgs), $out);
    echo trim((string) $out), "\n";
    if ($rc !== 0) {
        fail('Обновление завершилось с ошибкой, подробности выше.');
    }
    $err = nginx_test_reload();
    echo $err === null ? "\nПакеты обновлены, nginx проверен и работает.\n" : "\nПакеты обновлены, но nginx сообщает об ошибке конфигурации:\n$err\n";
    exit(0);
}

/** Защитная мера: выключить HTTP/2 во всех конфигах nginx (с копиями и откатом). */
function sec_http2_off(): void
{
    load_sites(false);
    $changed = [];
    foreach (array_keys($GLOBALS['NGX']) as $file) {
        $old = (string) @file_get_contents($file);
        $new = preg_replace('/^(\s*listen\s[^;#]*?)\s+http2\b/m', '$1', $old);
        $new = preg_replace('/^(\s*)http2\s+on\s*;/m', '$1http2 off;', (string) $new);
        if ($new !== null && $new !== $old && is_writable($file)) {
            $changed[$file] = $old;
        }
        if (isset($changed[$file])) {
            if (!is_dir(CONF_BAK)) {
                mkdir(CONF_BAK, 0700, true);
            }
            file_put_contents(CONF_BAK . '/' . trim(str_replace('/', '_', $file), '_') . '.' . date('Ymd-His'), $old);
            file_put_contents($file, $new);
        }
    }
    if (!$changed) {
        fail('HTTP/2 в конфигурации nginx не найден, менять нечего.');
    }
    $err = nginx_test_reload();
    if ($err !== null) {
        foreach ($changed as $file => $old) {
            file_put_contents($file, $old);
        }
        nginx_test_reload();
        fail("nginx не принял изменения, все файлы возвращены:\n$err");
    }
    echo 'HTTP/2 выключен в файлах: ', count($changed), ". Сайты работают по HTTP/1.1.\n";
    echo 'Прежние версии файлов сохранены в ', CONF_BAK, ".\n";
    echo "После обновления nginx HTTP/2 можно вернуть в редакторе конфигурации.\n";
    exit(0);
}

function sec_apache_http2_off(): void
{
    if (!which(['a2dismod'])) {
        fail('На этом сервере модуль отключается вручную: закомментируйте строку LoadModule http2_module и перезапустите Apache.');
    }
    run(['a2dismod', '-f', 'http2'], $out);
    echo trim((string) $out), "\n";
    $ctl = which(['apache2ctl', 'apachectl']);
    if ($ctl && run([$ctl, '-t'], $t) !== 0) {
        run(['a2enmod', 'http2'], $o);
        fail("Apache не принял конфигурацию без модуля, модуль возвращён:\n$t");
    }
    $rc = run(['systemctl', 'restart', 'apache2'], $out);
    echo $rc === 0 ? "Модуль HTTP/2 в Apache отключён, служба перезапущена.\n" : "Не удалось перезапустить Apache:\n$out\n";
    exit($rc === 0 ? 0 : 1);
}

/* ---------- Резервные копии по расписанию ---------- */

const AUTO_DIR   = '/var/backups/dewiar-panel/auto';
const BACKUP_CFG = '/etc/dewiar-panel/backup.json';
const UNIT_DIR   = '/etc/systemd/system';
const SET_RE     = '/^\d{8}-\d{4}$/';
const BFILE_RE   = '/^(site|db)-[A-Za-z0-9_.\-]{1,100}\.(tar|sql)\.gz$/';

function backup_cfg(): array
{
    $c = json_decode((string) @file_get_contents(BACKUP_CFG), true);
    return (is_array($c) ? $c : []) + ['enabled' => false, 'hour' => 3, 'keep_days' => 7, 'files' => true, 'db' => true];
}

function backup_config_save(): void
{
    $in = json_decode((string) stream_get_contents(STDIN), true) ?: [];
    $c = ['enabled' => !empty($in['enabled']), 'hour' => max(0, min(23, (int) ($in['hour'] ?? 3))),
        'keep_days' => max(1, min(90, (int) ($in['keep_days'] ?? 7))), 'files' => !empty($in['files']), 'db' => !empty($in['db'])];
    if (!$c['files'] && !$c['db']) {
        fail('Выберите, что сохранять: файлы сайтов, базы данных или и то и другое.');
    }
    file_put_contents(BACKUP_CFG, json_encode($c));
    file_put_contents(UNIT_DIR . '/dewiar-backup.timer', "[Unit]\nDescription=Dewiar Server Panel: резервные копии по расписанию\n\n[Timer]\nOnCalendar=*-*-* "
        . sprintf('%02d', $c['hour']) . ":10:00\nPersistent=true\n\n[Install]\nWantedBy=timers.target\n");
    run(['systemctl', 'daemon-reload'], $o);
    run(['systemctl', $c['enabled'] ? 'enable' : 'disable', '--now', 'dewiar-backup.timer'], $o);
    echo $c['enabled'] ? 'Расписание включено: каждый день в ' . sprintf('%02d', $c['hour']) . ":10, копии хранятся {$c['keep_days']} дн.\n" : "Расписание выключено. Готовые копии сохранены.\n";
    exit(0);
}

function mysqldump_to(string $name, string $path, ?string &$err): bool
{
    $bin = which(['mariadb-dump', 'mysqldump']);
    if (!$bin) {
        $err = 'mysqldump не найден';
        return false;
    }
    $p = proc_open([$bin, '--single-transaction', '--quick', '--routines', '--triggers', '--events', $name],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', $path, 'w'], 2 => ['pipe', 'w']], $pipes);
    $err = is_resource($p) ? trim((string) stream_get_contents($pipes[2])) : 'не удалось запустить';
    if ((is_resource($p) ? proc_close($p) : 127) !== 0 || run(['gzip', '-f', $path], $o) !== 0) {
        @unlink($path);
        return false;
    }
    chmod($path . '.gz', 0600);
    return true;
}

function db_names_soft(): array
{
    $bin = which(['mariadb', 'mysql']);
    if (!$bin || run([$bin, '-N', '-B', '-e', 'SHOW DATABASES'], $out) !== 0) {
        return [];
    }
    return array_values(array_filter(array_map('trim', explode("\n", (string) $out)), function ($n) {
        return preg_match(DB_RE, $n) && !in_array($n, ['information_schema', 'performance_schema', 'mysql', 'sys'], true);
    }));
}

function backup_run(): void
{
    $c = backup_cfg();
    if (!is_dir(AUTO_DIR)) {
        mkdir(AUTO_DIR, 0700, true);
    }
    $total = (float) disk_total_space(AUTO_DIR);
    if ($total > 0 && disk_free_space(AUTO_DIR) / $total < 0.05) {
        fail('На диске меньше 5% свободного места, копия не создана. Удалите старые копии или освободите место.');
    }
    $dir = AUTO_DIR . '/' . date('Ymd-Hi');
    @mkdir($dir, 0700);
    $log = ['Начало: ' . date('d.m.Y H:i:s')];
    if ($c['files']) {
        $seen = [];
        foreach (load_sites(false) as $s) {
            $root = $s['root'] ? realpath($s['root']) : false;
            if (!$root || !is_dir($root) || substr_count($root, '/') < 2 || isset($seen[$root])) {
                continue;
            }
            $seen[$root] = true;
            $out = "$dir/site-{$s['domain']}.tar.gz";
            $rc = run(['tar', '-czf', $out, '--warning=no-file-changed', '-C', dirname($root), basename($root)], $o);
            if ($rc > 1) {
                @unlink($out);
                $log[] = "ОШИБКА  сайт {$s['domain']}: " . trim((string) $o);
            } else {
                chmod($out, 0600);
                $log[] = "готово  сайт {$s['domain']} (" . number_format(filesize($out) / 1048576, 1, ',', ' ') . ' МБ)';
            }
        }
    }
    if ($c['db']) {
        foreach (db_names_soft() as $name) {
            $log[] = mysqldump_to($name, "$dir/db-$name.sql", $err)
                ? "готово  база $name (" . number_format(filesize("$dir/db-$name.sql.gz") / 1048576, 1, ',', ' ') . ' МБ)'
                : "ОШИБКА  база $name: $err";
        }
    }
    $limit = time() - $c['keep_days'] * 86400;
    foreach (scandir(AUTO_DIR) as $d) {
        if (preg_match(SET_RE, $d) && filemtime(AUTO_DIR . "/$d") < $limit) {
            run(['rm', '-rf', AUTO_DIR . "/$d"], $o);
            $log[] = "удалена старая копия $d";
        }
    }
    $log[] = 'Конец: ' . date('d.m.Y H:i:s');
    file_put_contents("$dir/run.log", implode("\n", $log) . "\n");
    echo implode("\n", $log), "\n";
    exit(0);
}

function backups_list(): array
{
    $sets = [];
    foreach (is_dir(AUTO_DIR) ? scandir(AUTO_DIR) : [] as $d) {
        if (!preg_match(SET_RE, $d)) {
            continue;
        }
        $files = [];
        $size = 0;
        foreach (scandir(AUTO_DIR . "/$d") as $f) {
            if (preg_match(BFILE_RE, $f)) {
                $sz = filesize(AUTO_DIR . "/$d/$f");
                $files[] = ['name' => $f, 'size' => $sz];
                $size += $sz;
            }
        }
        $log = (string) @file_get_contents(AUTO_DIR . "/$d/run.log");
        $sets[] = ['id' => $d, 'time' => filemtime(AUTO_DIR . "/$d"), 'size' => $size, 'files' => $files,
            'errors' => substr_count($log, 'ОШИБКА'), 'done' => strpos($log, 'Конец:') !== false];
    }
    usort($sets, function ($a, $b) {
        return strcmp($b['id'], $a['id']);
    });
    run(['systemctl', 'is-active', 'dewiar-backup.service'], $act);
    return ['cfg' => backup_cfg(), 'sets' => $sets, 'running' => trim((string) $act) === 'activating',
        'free' => (float) @disk_free_space(is_dir(AUTO_DIR) ? AUTO_DIR : '/')];
}

function backup_path(string $id, string $file): string
{
    if (!preg_match(SET_RE, $id) || !preg_match(BFILE_RE, $file) || !is_file(AUTO_DIR . "/$id/$file")) {
        fail('Файл резервной копии не найден');
    }
    return AUTO_DIR . "/$id/$file";
}

/** Восстановление базы из дампа. Источник: "dump:ФАЙЛ" (ручной дамп) или "auto:НАБОР/ФАЙЛ". */
function db_restore(string $spec): void
{
    if (preg_match('~^dump:([^/]+)$~', $spec, $m) && preg_match('/^(.+)-\d{8}-\d{6}\.sql\.gz$/', $m[1], $n)) {
        $path = dump_path($m[1]);
        $db = $n[1];
    } elseif (preg_match('~^auto:([^/]+)/(db-(.+)\.sql\.gz)$~', $spec, $m)) {
        $path = backup_path($m[1], $m[2]);
        $db = $m[3];
    } else {
        fail('Неизвестный источник для восстановления');
    }
    if (!preg_match(DB_RE, $db) || !in_array($db, db_names_soft(), true)) {
        fail("База $db на сервере не найдена. Создайте пустую базу с этим именем и повторите.");
    }
    if (!is_dir(DUMP_DIR)) {
        mkdir(DUMP_DIR, 0700, true);
    }
    $pre = DUMP_DIR . "/$db-" . date('Ymd-His') . '.sql';
    if (!mysqldump_to($db, $pre, $err)) {
        fail("Не удалось сохранить текущее состояние базы, восстановление отменено:\n$err");
    }
    echo 'Текущее состояние сохранено: ', basename($pre), ".gz\n";
    $rc = run(['bash', '-c', 'set -o pipefail; gzip -dc "$1" | "$2" "$3"', 'restore', $path, (string) which(['mariadb', 'mysql']), $db], $out);
    if ($rc !== 0) {
        fail("Восстановление завершилось с ошибкой:\n$out\nВернуть прежнее состояние можно из дампа " . basename($pre) . '.gz');
    }
    echo "База $db восстановлена из копии.\n";
    exit(0);
}

/* ---------- Планировщик (cron) ---------- */

const CRON_FILE = '/etc/cron.d/dewiar-panel';
const CRON_DIRS = ['/etc/cron.d', '/var/spool/cron/crontabs', '/var/spool/cron'];

function cron_read(): array
{
    $jobs = [];
    $meta = null;
    foreach (@file(CRON_FILE, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if (preg_match('/^# dewiar:([a-f0-9]{8}):(.*)$/', $line, $m)) {
            $meta = [$m[1], (string) base64_decode($m[2])];
        } elseif ($meta && preg_match('/^(\S+\s+\S+\s+\S+\s+\S+\s+\S+|@\w+)\s+(\S+)\s+(.+)$/', $line, $m)) {
            $jobs[] = ['id' => $meta[0], 'comment' => $meta[1], 'schedule' => $m[1], 'user' => $m[2], 'command' => $m[3]];
            $meta = null;
        }
    }
    return $jobs;
}

function cron_write(array $jobs): void
{
    $t = "# Dewiar Server Panel. Файл управляется панелью, вручную не редактируйте.\nSHELL=/bin/sh\nPATH=/usr/local/sbin:/usr/local/bin:/sbin:/bin:/usr/sbin:/usr/bin\n";
    foreach ($jobs as $j) {
        $t .= '# dewiar:' . $j['id'] . ':' . base64_encode($j['comment']) . "\n{$j['schedule']} {$j['user']} {$j['command']}\n";
    }
    file_put_contents(CRON_FILE, $t);
    chmod(CRON_FILE, 0644);
}

function cron_list(): array
{
    $other = [];
    $files = is_file('/etc/crontab') ? ['/etc/crontab'] : [];
    foreach (CRON_DIRS as $d) {
        foreach (is_dir($d) ? scandir($d) : [] as $f) {
            if ($f[0] !== '.' && is_file("$d/$f") && "$d/$f" !== CRON_FILE) {
                $files[] = "$d/$f";
            }
        }
    }
    foreach (array_unique($files) as $f) {
        foreach (@file($f, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '' && $line[0] !== '#' && !preg_match('/^[A-Z_]+\s*=/', $line)) {
                $other[] = ['source' => $f, 'line' => $line];
            }
        }
    }
    $users = ['root'];
    foreach (@file('/etc/passwd', FILE_IGNORE_NEW_LINES) ?: [] as $l) {
        $c = explode(':', $l);
        if (count($c) >= 7 && (int) $c[2] >= 1000 && $c[0] !== 'nobody') {
            $users[] = $c[0];
        }
    }
    foreach (['www-data', 'nginx'] as $u) {
        if (function_exists('posix_getpwnam') && posix_getpwnam($u)) {
            $users[] = $u;
        }
    }
    return ['own' => cron_read(), 'other' => array_slice($other, 0, 300), 'users' => array_values(array_unique($users))];
}

function cron_add(): void
{
    $in = json_decode((string) stream_get_contents(STDIN), true) ?: [];
    $sch = trim((string) ($in['schedule'] ?? ''));
    $user = trim((string) ($in['user'] ?? ''));
    $cmd = trim((string) ($in['command'] ?? ''));
    $comment = trim(preg_replace('/[\r\n]+/', ' ', (string) ($in['comment'] ?? '')));
    if (!preg_match('/^(@(hourly|daily|weekly|monthly|reboot)|([0-9*\/,\-]+\s+){4}[0-9*\/,\-]+)$/', $sch)) {
        fail('Расписание должно состоять из пяти полей cron, например: */5 * * * *  или @daily');
    }
    if (!preg_match('/^[a-z_][a-z0-9_\-]{0,31}$/', $user) || !function_exists('posix_getpwnam') || !posix_getpwnam($user)) {
        fail('Пользователь не найден на сервере');
    }
    if ($cmd === '' || strlen($cmd) > 1000 || preg_match('/[\r\n\0]/', $cmd)) {
        fail('Команда должна быть в одну строку, не длиннее 1000 символов');
    }
    $jobs = cron_read();
    $jobs[] = ['id' => bin2hex(random_bytes(4)), 'comment' => mb_substr($comment, 0, 120), 'schedule' => preg_replace('/\s+/', ' ', $sch), 'user' => $user, 'command' => $cmd];
    cron_write($jobs);
    echo "Задание добавлено. Cron подхватит его в течение минуты.\n";
    exit(0);
}

function cron_delete(string $id): void
{
    $jobs = cron_read();
    $left = array_values(array_filter($jobs, function ($j) use ($id) {
        return $j['id'] !== $id;
    }));
    if (count($left) === count($jobs)) {
        fail('Задание не найдено');
    }
    cron_write($left);
    echo "Задание удалено.\n";
    exit(0);
}

/* ---------- FTP-аккаунты (ProFTPD, файл виртуальных пользователей) ---------- */

const FTP_PASSWD = '/etc/dewiar-panel/ftpd.passwd';
const FTP_CONF   = '/etc/proftpd/proftpd.conf';
const FTP_SNIP   = '/etc/proftpd/conf.d/dewiar-panel.conf';

function pftp_read(): array
{
    $acc = [];
    foreach (@file(FTP_PASSWD, FILE_IGNORE_NEW_LINES) ?: [] as $l) {
        $c = explode(':', $l);
        if (count($c) >= 7) {
            $acc[$c[0]] = $c;
        }
    }
    return $acc;
}

function pftp_write(array $acc): void
{
    $t = '';
    foreach ($acc as $c) {
        $t .= implode(':', $c) . "\n";
    }
    file_put_contents(FTP_PASSWD, $t);
    chmod(FTP_PASSWD, 0600);
}

function pftp_info(): array
{
    $server = which(['proftpd']) ? 'proftpd' : (which(['vsftpd']) ? 'vsftpd' : (which(['pure-ftpd']) ? 'pure-ftpd' : null));
    $conf = (string) @file_get_contents(FTP_CONF);
    $acc = [];
    foreach (pftp_read() as $c) {
        $acc[] = ['login' => $c[0], 'home' => $c[5]];
    }
    $sites = [];
    foreach (load_sites(false) as $s) {
        if ($s['root'] && is_dir($s['root'])) {
            $sites[] = ['domain' => $s['domain'], 'root' => $s['root']];
        }
    }
    return ['server' => $server, 'confd' => (bool) preg_match('~^\s*Include\s+' . preg_quote(dirname(FTP_SNIP), '~') . '~mi', $conf),
        'accounts' => $acc, 'sites' => $sites];
}

function pftp_apply(): void
{
    if (!is_file(FTP_SNIP)) {
        file_put_contents(FTP_SNIP, "# Dewiar Server Panel: FTP-аккаунты, созданные в панели\nAuthUserFile " . FTP_PASSWD . "\nDefaultRoot ~\n");
        if (run(['proftpd', '-t'], $out) !== 0) {
            unlink(FTP_SNIP);
            fail("ProFTPD не принял настройки, изменения отменены:\n$out");
        }
    }
    run(['systemctl', 'reload', 'proftpd'], $o) === 0 || run(['systemctl', 'restart', 'proftpd'], $o);
}

function pftp_check_pass(string $pass): string
{
    if (strlen($pass) < 10 || strlen($pass) > 100 || preg_match('/[\r\n:]/', $pass)) {
        fail('Пароль должен быть от 10 символов и не содержать двоеточий');
    }
    return crypt($pass, '$6$' . substr(strtr(base64_encode(random_bytes(12)), '+', '.'), 0, 16) . '$');
}

function pftp_add(): void
{
    $in = json_decode((string) stream_get_contents(STDIN), true) ?: [];
    $info = pftp_info();
    if ($info['server'] !== 'proftpd' || !$info['confd']) {
        fail('Создание аккаунтов поддерживается для ProFTPD с подключённым каталогом ' . dirname(FTP_SNIP) . '.');
    }
    $login = strtolower(trim((string) ($in['login'] ?? '')));
    if (!preg_match('/^[a-z][a-z0-9_\-]{2,31}$/', $login)) {
        fail('Логин: латинские буквы, цифры, дефис и подчёркивание, от 3 до 32 символов, начинается с буквы');
    }
    $acc = pftp_read();
    if (isset($acc[$login]) || (function_exists('posix_getpwnam') && posix_getpwnam($login))) {
        fail('Такой логин уже занят');
    }
    $root = null;
    foreach ($info['sites'] as $s) {
        if ($s['domain'] === ($in['domain'] ?? '')) {
            $root = realpath($s['root']);
        }
    }
    if (!$root) {
        fail('Выберите сайт из списка');
    }
    $uid = fileowner($root);
    $gid = filegroup($root);
    if ($uid === 0) {
        fail('Каталог сайта принадлежит root. FTP-доступ к такому каталогу не выдаётся: смените владельца каталога.');
    }
    $hash = pftp_check_pass((string) ($in['password'] ?? ''));
    $shells = array_filter(array_map('trim', @file('/etc/shells') ?: []), function ($l) {
        return $l !== '' && $l[0] === '/';
    });
    $acc[$login] = [$login, $hash, $uid, $gid, '', $root, in_array('/bin/bash', $shells, true) ? '/bin/bash' : (reset($shells) ?: '/bin/sh')];
    pftp_write($acc);
    pftp_apply();
    echo "FTP-аккаунт $login создан. Доступ ограничен каталогом $root.\n";
    exit(0);
}

function pftp_passwd(): void
{
    $in = json_decode((string) stream_get_contents(STDIN), true) ?: [];
    $acc = pftp_read();
    $login = (string) ($in['login'] ?? '');
    if (!isset($acc[$login])) {
        fail('Аккаунт не найден');
    }
    $acc[$login][1] = pftp_check_pass((string) ($in['password'] ?? ''));
    pftp_write($acc);
    echo "Пароль для $login изменён.\n";
    exit(0);
}

function pftp_delete(string $login): void
{
    $acc = pftp_read();
    if (!isset($acc[$login])) {
        fail('Аккаунт не найден');
    }
    unset($acc[$login]);
    pftp_write($acc);
    echo "FTP-аккаунт $login удалён. Файлы сайта не затронуты.\n";
    exit(0);
}

/* ---------- Почта: состояние, очередь, список ящиков ---------- */

function mail_info(): array
{
    $exim = which(['exim4', 'exim']);
    $r = ['mta' => $exim ? 'exim' : (which(['postqueue']) ? 'postfix' : null), 'queue' => 0, 'frozen' => 0, 'lines' => [], 'mailboxes' => null, 'dovecot' => which(['doveadm']) !== null];
    if ($exim) {
        run([$exim, '-bpc'], $o);
        $r['queue'] = (int) trim((string) $o);
        if ($r['queue'] > 0) {
            run([$exim, '-bp'], $o);
            $r['frozen'] = substr_count((string) $o, '*** frozen ***');
            $r['lines'] = array_slice(array_values(array_filter(explode("\n", (string) $o), 'strlen')), 0, 80);
        }
    } elseif ($r['mta'] === 'postfix') {
        run(['postqueue', '-p'], $o);
        $lines = array_values(array_filter(explode("\n", (string) $o), 'strlen'));
        $r['queue'] = preg_match('/in (\d+) Request/', (string) end($lines), $m) ? (int) $m[1] : 0;
        $r['lines'] = array_slice($lines, 0, 80);
    }
    if ($r['dovecot'] && run(['doveadm', 'user', '*'], $o) === 0) {
        $r['mailboxes'] = array_slice(array_values(array_filter(array_map('trim', explode("\n", (string) $o)), function ($l) {
            return (bool) preg_match('/^[^\s@]+@[^\s@]+$/', $l);
        })), 0, 500);
    }
    return $r;
}

function mail_flush(): void
{
    $exim = which(['exim4', 'exim']);
    $rc = $exim ? run([$exim, '-qff'], $out) : run(['postqueue', '-f'], $out);
    echo trim((string) $out), "\nЗапущена повторная отправка писем из очереди.\n";
    exit($rc === 0 ? 0 : 1);
}

function mail_clear_frozen(): void
{
    $exim = which(['exim4', 'exim']);
    if (!$exim || !which(['exiqgrep'])) {
        fail('Удаление замороженных писем доступно только для Exim.');
    }
    run(['exiqgrep', '-z', '-i'], $o);
    $ids = array_values(array_filter(array_map('trim', explode("\n", (string) $o)), function ($i) {
        return (bool) preg_match('/^[A-Za-z0-9\-]{16,30}$/', $i);
    }));
    if (!$ids) {
        fail('Замороженных писем в очереди нет.');
    }
    foreach (array_chunk($ids, 200) as $chunk) {
        run(array_merge([$exim, '-Mrm'], $chunk), $o);
    }
    echo 'Удалено замороженных писем: ', count($ids), ".\n";
    exit(0);
}

/* ---------- Точка входа ---------- */

$cmd = $argv[1] ?? '';
switch ($cmd) {
    case 'sites':
        echo json_encode(array_values(load_sites()), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        break;
    case 'services':
        echo json_encode(list_services());
        break;
    case 'service-restart':
        service_restart((string) ($argv[2] ?? ''));
        break;
    case 'ssl-issue':
        ssl_issue((string) ($argv[2] ?? ''), ($argv[3] ?? '') === 'force');
        break;
    case 'ssl-dns-start':
        ssl_dns_start((string) ($argv[2] ?? ''));
        break;
    case 'ssl-dns-finish':
        ssl_dns_finish((string) ($argv[2] ?? ''));
        break;
    case 'site-options':
        $o = site_options();
        unset($o['domains']);
        echo json_encode($o, JSON_UNESCAPED_SLASHES);
        break;
    case 'site-create':
        site_create();
        break;
    case 'site-php':
        site_php((string) ($argv[2] ?? ''), (string) ($argv[3] ?? ''));
        break;
    case 'site-delete':
        site_delete((string) ($argv[2] ?? ''));
        break;
    case 'config-save':
        config_save((string) ($argv[2] ?? ''), (string) ($argv[3] ?? ''));
        break;
    case 'security':
        echo json_encode(security_report(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        break;
    case 'sec-refresh':
        sec_refresh();
        break;
    case 'sec-upgrade':
        sec_upgrade();
        break;
    case 'sec-http2-off':
        sec_http2_off();
        break;
    case 'sec-apache-http2-off':
        sec_apache_http2_off();
        break;
    case 'backup-config':
        backup_config_save();
        break;
    case 'backup-run':
        backup_run();
        break;
    case 'backup-start':
        run(['systemctl', 'start', '--no-block', 'dewiar-backup.service'], $o);
        echo "Создание копии запущено в фоне. Обновите страницу через несколько минут.\n", $o;
        break;
    case 'backups':
        echo json_encode(backups_list(), JSON_UNESCAPED_UNICODE);
        break;
    case 'backup-read':
        readfile(backup_path((string) ($argv[2] ?? ''), (string) ($argv[3] ?? '')));
        break;
    case 'backup-delete':
        if (!preg_match(SET_RE, (string) ($argv[2] ?? '')) || !is_dir(AUTO_DIR . '/' . $argv[2])) {
            fail('Копия не найдена');
        }
        run(['rm', '-rf', AUTO_DIR . '/' . $argv[2]], $o);
        echo "Копия удалена.\n";
        break;
    case 'db-restore':
        db_restore((string) ($argv[2] ?? ''));
        break;
    case 'cron-list':
        echo json_encode(cron_list(), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        break;
    case 'cron-add':
        cron_add();
        break;
    case 'cron-delete':
        cron_delete((string) ($argv[2] ?? ''));
        break;
    case 'ftp-info':
        echo json_encode(pftp_info(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        break;
    case 'ftp-add':
        pftp_add();
        break;
    case 'ftp-passwd':
        pftp_passwd();
        break;
    case 'ftp-delete':
        pftp_delete((string) ($argv[2] ?? ''));
        break;
    case 'mail-info':
        echo json_encode(mail_info(), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        break;
    case 'mail-flush':
        mail_flush();
        break;
    case 'mail-clear-frozen':
        mail_clear_frozen();
        break;
    case 'panel-update-check':
        $cur = trim((string) @file_get_contents('/opt/dewiar-panel/VERSION'));
        run(['curl', '-fsSL', '-m', '8', 'https://raw.githubusercontent.com/dwr-git/dewiar-server-panel/main/VERSION'], $o);
        $latest = trim((string) $o);
        echo json_encode(['current' => $cur, 'latest' => preg_match('/^\d+\.\d+\.\d+$/', $latest) ? $latest : null]);
        break;
    case 'panel-update':
        if (!is_executable('/usr/local/sbin/dewiar-panel')) {
            fail('Команда dewiar-panel не найдена. Обновите панель вручную по инструкции.');
        }
        $rc = run(['systemd-run', '--no-block', '--collect', '--unit', 'dewiar-panel-update-' . time(), '/usr/local/sbin/dewiar-panel', 'update'], $o);
        echo $rc === 0 ? "Обновление запущено. Панель перезапустится сама, обновите страницу через минуту.\n" : "Не удалось запустить обновление:\n$o\n";
        exit($rc === 0 ? 0 : 1);
    case 'service-reload':
        service_reload((string) ($argv[2] ?? ''));
        break;
    case 'reboot':
        echo "Сервер уходит на перезагрузку. Панель будет недоступна 1–3 минуты.\n";
        run(['systemctl', 'reboot'], $out);
        echo $out;
        break;
    case 'databases':
        echo json_encode(list_databases());
        break;
    case 'db-dump':
        db_dump((string) ($argv[2] ?? ''));
        break;
    case 'dumps':
        echo json_encode(list_dumps());
        break;
    case 'dump-read':
        readfile(dump_path((string) ($argv[2] ?? '')));
        break;
    case 'dump-delete':
        unlink(dump_path((string) ($argv[2] ?? '')));
        echo "Дамп удалён.\n";
        break;
    case 'site-config':
        echo json_encode(site_config((string) ($argv[2] ?? '')), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        break;
    default:
        fail('Неизвестная команда');
}
