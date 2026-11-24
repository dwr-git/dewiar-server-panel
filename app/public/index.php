<?php
declare(strict_types=1);

require __DIR__ . '/../lib/app.php';
require __DIR__ . '/../lib/charts.php';
require __DIR__ . '/../lib/icons.php';

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; frame-ancestors 'none'");

session_boot();
$page = (string) ($_GET['p'] ?? 'overview');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

/* ---------- Вход и выход ---------- */

if ($page === 'login') {
    $error = null;
    if ($method === 'POST') {
        $error = csrf_ok() ? try_login((string) ($_POST['login'] ?? ''), (string) ($_POST['password'] ?? '')) : 'Сессия устарела, обновите страницу';
        if ($error === null) {
            header('Location: ?p=overview');
            exit;
        }
    }
    render_login($error);
    exit;
}
if (!logged_in()) {
    if ($page === 'api') {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'log' => 'Требуется вход']);
        exit;
    }
    header('Location: ?p=login');
    exit;
}
if ($page === 'logout' && $method === 'POST' && csrf_ok()) {
    $_SESSION = [];
    session_destroy();
    header('Location: ?p=login');
    exit;
}

/* ---------- Действия ---------- */

if ($page === 'api') {
    header('Content-Type: application/json; charset=utf-8');
    if ($method !== 'POST' || !csrf_ok()) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'log' => 'Запрос отклонён: обновите страницу и повторите']);
        exit;
    }
    @set_time_limit(600);
    $action = (string) ($_POST['action'] ?? '');
    $arg = (string) ($_POST['arg'] ?? '');
    session_write_close();
    $map = [
        'ssl_issue' => ['ssl-issue', $arg],
        'ssl_force' => ['ssl-issue', $arg, 'force'],
        'ssl_dns_start' => ['ssl-dns-start', $arg],
        'ssl_dns_finish' => ['ssl-dns-finish', $arg],
        'service_restart' => ['service-restart', $arg],
        'service_reload' => ['service-reload', $arg],
        'reboot' => ['reboot'],
        'db_dump' => ['db-dump', $arg],
        'dump_delete' => ['dump-delete', $arg],
        'site_delete' => ['site-delete', $arg],
        'backup_start' => ['backup-start'],
        'backup_delete' => ['backup-delete', $arg],
        'db_restore' => ['db-restore', $arg],
        'cron_delete' => ['cron-delete', $arg],
        'ftp_delete' => ['ftp-delete', $arg],
        'mail_flush' => ['mail-flush'],
        'mail_clear_frozen' => ['mail-clear-frozen'],
        'panel_update' => ['panel-update'],
        'sec_refresh' => ['sec-refresh'],
        'sec_upgrade' => ['sec-upgrade'],
        'sec_http2_off' => ['sec-http2-off'],
        'sec_apache_http2_off' => ['sec-apache-http2-off'],
    ];
    if (isset($map[$action])) {
        [$rc, $out] = helper($map[$action]);
        cache_clear();
    } else {
        $rc = 1;
        $out = 'Неизвестное действие';
    }
    echo json_encode(['ok' => $rc === 0, 'log' => $out], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

/* ---------- Страницы ---------- */

switch ($page) {
    case 'domains':
        page_domains();
        break;
    case 'newsite':
        page_newsite();
        break;
    case 'config':
        page_site((string) ($_GET['d'] ?? ''));
        break;
    case 'databases':
        page_databases();
        break;
    case 'download':
        download_dump((string) ($_GET['f'] ?? ''));
        break;
    case 'services':
        page_services();
        break;
    case 'security':
        page_security();
        break;
    case 'backups':
        page_backups();
        break;
    case 'bdownload':
        download_backup((string) ($_GET['id'] ?? ''), (string) ($_GET['f'] ?? ''));
        break;
    case 'cron':
        page_cron();
        break;
    case 'ftp':
        page_ftp();
        break;
    case 'mail':
        page_mail();
        break;
    case 'dns':
        page_dns();
        break;
    case 'help':
        page_help();
        break;
    default:
        page_overview();
}

function render_login(?string $error): void
{
    ?><!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Вход — Dewiar Server Panel</title>
<link rel="stylesheet" href="/assets/style.css">
</head>
<body class="login-body">
<form class="login" method="post" action="?p=login" autocomplete="off">
  <div class="brand"><span class="brand-mark">D</span> Dewiar Server Panel</div>
  <?php if ($error): ?><p class="alert"><?= e($error) ?></p><?php endif; ?>
  <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
  <label>Логин<input name="login" required autofocus></label>
  <label>Пароль<input name="password" type="password" required></label>
  <button class="btn primary" type="submit">Войти</button>
</form>
</body>
</html><?php
}

function layout(string $title, string $active, string $body, string $intro = ''): void
{
    $groups = [
        '' => ['overview' => ['Главная', 'home', 'teal']],
        'Сайты' => ['domains' => ['Сайты и SSL', 'sites', 'blue'], 'dns' => ['DNS', 'dns', 'sky'], 'ftp' => ['FTP', 'ftp', 'amber']],
        'Данные' => ['databases' => ['Базы данных', 'db', 'violet'], 'backups' => ['Резервные копии', 'backup', 'green']],
        'Сервер' => ['services' => ['Службы', 'services', 'orange'], 'cron' => ['Планировщик', 'cron', 'blue'], 'mail' => ['Почта', 'mail', 'violet'], 'security' => ['Безопасность', 'shield', 'rose']],
        'Помощь' => ['help' => ['Справка', 'help', 'sky']],
    ];
    ?><!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf" content="<?= e($_SESSION['csrf']) ?>">
<title><?= e($title) ?> — Dewiar Server Panel</title>
<link rel="stylesheet" href="/assets/style.css">
</head>
<body>
<?= icon_defs() ?>
<aside class="side">
  <div class="brand"><span class="brand-mark">D</span> Dewiar Panel</div>
  <nav>
    <?php foreach ($groups as $g => $items): ?>
      <?php if ($g !== ''): ?><div class="nav-group"><?= e($g) ?></div><?php endif; ?>
      <?php foreach ($items as $k => $n): ?>
        <a href="?p=<?= $k ?>"<?= $k === $active ? ' class="on" aria-current="page"' : '' ?>><?= icon($n[1], $n[2], 22) ?><?= e($n[0]) ?></a>
      <?php endforeach; ?>
    <?php endforeach; ?>
  </nav>
  <form method="post" action="?p=logout" class="user">
    <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
    <span class="avatar" aria-hidden="true"><?= e(mb_strtoupper(mb_substr((string) $_SESSION['user'], 0, 1))) ?></span>
    <span class="user-name"><?= e($_SESSION['user']) ?><small>версия <?= DSP_VERSION ?></small></span>
    <button class="btn icon-btn" type="submit" title="Выйти" aria-label="Выйти"><?= icon('exit', 'rose', 20) ?></button>
  </form>
</aside>
<main>
  <h1><?= e($title) ?></h1>
  <?php if ($intro !== ''): ?><p class="intro"><?= $intro ?></p><?php endif; ?>
  <?= $body ?>
</main>
<dialog id="dlg">
  <h2 id="dlg-title"></h2>
  <p id="dlg-text"></p>
  <pre id="dlg-log" hidden></pre>
  <div class="dlg-actions">
    <button class="btn" id="dlg-cancel" type="button">Отмена</button>
    <button class="btn primary" id="dlg-ok" type="button">Выполнить</button>
  </div>
</dialog>
<script src="/assets/app.js"></script>
</body>
</html><?php
}

function action_btn(string $cls, string $action, string $arg, string $label, string $title, string $text, string $next = ''): string
{
    return '<button class="btn ' . $cls . '" data-action="' . e($action) . '" data-arg="' . e($arg) . '" data-title="' . e($title)
        . '" data-text="' . e($text) . '"' . ($next !== '' ? ' data-next="' . e($next) . '"' : '') . '>' . e($label) . '</button>';
}

/* ---------- Обзор ---------- */

function page_overview(): void
{
    $ranges = ['1h' => [3600, '1 час'], '24h' => [86400, '24 часа'], '7d' => [604800, '7 дней']];
    $rk = isset($ranges[$_GET['r'] ?? '']) ? (string) $_GET['r'] : '24h';
    $range = $ranges[$rk][0];
    $s = sys_stats();
    $rows = metrics_load($range);
    $last = $rows ? end($rows) : null;
    $cpuNow = ($last && time() - $last[0] < 180) ? $last[1] / 100 : min(1, $s['load'][0] / $s['cpus']);
    $sites = helper_json(['sites']);
    $services = helper_json(['services']);

    $sec = helper_json_cached(['security'], 600);
    $certUrgent = 0;
    foreach ($sites['data'] ?? [] as $site) {
        $certUrgent += in_array(cert_state($site)[0], ['bad', 'warn'], true) ? 1 : 0;
    }
    [$secBad, $secWarn] = isset($sec['data']) ? security_counts($sec['data']) : [0, 0];
    $upd = count($sec['data']['updates']['web'] ?? []);
    $down = 0;
    foreach ($services['data'] ?? [] as $sv) {
        $down += $sv['active'] === 'active' ? 0 : 1;
    }
    $h = (int) date('G');
    $hello = $h < 5 ? 'Доброй ночи' : ($h < 12 ? 'Доброе утро' : ($h < 18 ? 'Добрый день' : 'Добрый вечер'));
    $tiles = [
        ['?p=domains', 'sites', 'blue', 'Сайты', 'домены и настройки', count($sites['data'] ?? []), ''],
        ['?p=domains', 'lock', 'green', 'Сертификаты', 'SSL и продление', $certUrgent, 'требуют внимания'],
        ['?p=newsite', 'newsite', 'teal', 'Новый сайт', 'PHP, статика, прокси', 0, ''],
        ['?p=databases', 'db', 'violet', 'Базы данных', 'список и размеры', 0, ''],
        ['?p=databases', 'dump', 'sky', 'Дампы', 'копии баз', 0, ''],
        ['?p=backups', 'backup', 'green', 'Резервные копии', 'по расписанию', 0, ''],
        ['?p=dns', 'dns', 'sky', 'DNS', 'записи доменов', 0, ''],
        ['?p=ftp', 'ftp', 'amber', 'FTP', 'доступ к файлам', 0, ''],
        ['?p=mail', 'mail', 'violet', 'Почта', 'очередь и ящики', 0, ''],
        ['?p=cron', 'cron', 'blue', 'Планировщик', 'задания cron', 0, ''],
        ['?p=services', 'services', 'orange', 'Службы', 'перезапуск и статус', $down, 'остановлено'],
        ['?p=services', 'server', 'amber', 'Сервер', 'перезагрузка', 0, ''],
        ['?p=security', 'shield', 'rose', 'Безопасность', 'уязвимости', $secBad + $secWarn, 'требуют внимания'],
        ['?p=security', 'update', 'blue', 'Обновления', 'nginx, Apache, PHP', $upd, 'доступно'],
        ['#charts', 'chart', 'teal', 'Графики', 'нагрузка за период', 0, ''],
        ['?p=help', 'help', 'sky', 'Справка', 'как всё работает', 0, ''],
    ];

    ob_start();
    $pu = helper_json_cached(['panel-update-check'], 21600);
    if (!empty($pu['data']['latest']) && version_compare($pu['data']['latest'], DSP_VERSION, '>')) {
        echo '<p class="banner">Доступна новая версия панели: <b>' . e($pu['data']['latest']) . '</b>. <a href="?p=security">Перейти к обновлению</a></p>';
    }
    echo '<p class="today">' . e($s['host']) . ' · работает ' . e(fmt_uptime($s['uptime'])) . '</p>';
    echo '<div class="tiles">';
    foreach ($tiles as $i => $t) {
        $alert = $t[6] !== '' && $t[5] > 0;
        echo '<a class="tile" href="' . $t[0] . '"><span class="tile-ico">' . icon($t[1], $t[2], 52)
            . ($alert ? '<b class="tile-badge" title="' . e($t[6]) . '">' . (int) $t[5] . '</b>' : '') . '</span>'
            . '<span class="tile-name">' . e($t[3]) . '</span><span class="tile-sub">'
            . e($alert ? $t[5] . ' ' . $t[6] : ($i === 0 ? 'всего: ' . $t[5] : $t[4])) . '</span></a>';
    }
    echo '</div>';

    echo '<div class="stats">';
    echo gauge('Процессор', (float) $cpuNow, 'нагрузка ' . number_format((float) $s['load'][0], 2, ',', '') . ' / ' . $s['cpus'] . ' ядер');
    echo gauge('Память', $s['mem_total'] ? $s['mem_used'] / $s['mem_total'] : 0, fmt_bytes((float) $s['mem_used']) . ' из ' . fmt_bytes((float) $s['mem_total']));
    echo gauge('Диск /', $s['disk_total'] ? $s['disk_used'] / $s['disk_total'] : 0, fmt_bytes($s['disk_used']) . ' из ' . fmt_bytes($s['disk_total']));
    echo '</div>';

    echo '<div class="toolbar" id="charts"><h2 class="grow">Нагрузка за период</h2><div class="seg" role="group" aria-label="Период">';
    foreach ($ranges as $k => $r) {
        echo '<a href="?p=overview&amp;r=' . $k . '"' . ($k === $rk ? ' class="on" aria-current="true"' : '') . '>' . $r[1] . '</a>';
    }
    echo '</div></div><div class="charts">';
    echo chart_card('Процессор', $rows, [['col' => 1, 'label' => 'Процессор', 'color' => CHART_C1]], 'pct', $range);
    echo chart_card('Память', $rows, [['col' => 2, 'label' => 'Память', 'color' => CHART_C1]], 'pct', $range);
    echo chart_card('Средняя нагрузка', $rows, [['col' => 3, 'label' => 'Нагрузка', 'color' => CHART_C1]], 'num', $range);
    echo chart_card('Сеть', $rows, [['col' => 4, 'label' => 'Входящий', 'color' => CHART_C1], ['col' => 5, 'label' => 'Исходящий', 'color' => CHART_C2]], 'rate', $range);
    echo '</div><div class="two">';

    echo '<section class="panel"><h2>Сертификаты</h2>';
    if (isset($sites['error'])) {
        echo '<p class="alert">' . nl2br(e($sites['error'])) . '</p>';
    } else {
        $cnt = ['ok' => 0, 'warn' => 0, 'bad' => 0, 'none' => 0];
        $urgent = [];
        foreach ($sites['data'] as $site) {
            $st = cert_state($site);
            $cnt[$st[0]]++;
            if ($st[0] === 'bad' || $st[0] === 'warn') {
                $urgent[] = [$site['domain'], $st];
            }
        }
        $total = max(1, count($sites['data']));
        $names = ['ok' => 'В порядке', 'warn' => 'Скоро истекают', 'bad' => 'Срочно', 'none' => 'Без SSL'];
        echo '<div class="dist" role="img" aria-label="Распределение сертификатов по состоянию">';
        foreach ($cnt as $k => $n) {
            if ($n > 0) {
                echo '<i class="' . $k . '" style="flex:' . $n . '" title="' . $names[$k] . ': ' . $n . '"></i>';
            }
        }
        echo '</div><div class="legend">';
        foreach ($cnt as $k => $n) {
            echo '<span><i class="sw ' . $k . '"></i>' . $names[$k] . ': <b>' . $n . '</b></span>';
        }
        echo '</div>';
        if ($urgent) {
            echo '<ul class="list">';
            foreach ($urgent as $u) {
                echo '<li><span>' . e($u[0]) . '</span><span class="badge ' . $u[1][0] . '">' . e($u[1][1]) . '</span></li>';
            }
            echo '</ul><p><a class="btn primary" href="?p=domains">Перейти к продлению</a></p>';
        }
    }
    echo '</section>';

    echo '<section class="panel"><h2>Службы</h2>';
    if (isset($services['error'])) {
        echo '<p class="alert">' . nl2br(e($services['error'])) . '</p>';
    } else {
        echo '<ul class="list">';
        foreach ($services['data'] as $sv) {
            $on = $sv['active'] === 'active';
            echo '<li><span>' . e(preg_replace('/\.service$/', '', $sv['unit'])) . '</span><span class="badge '
                . ($on ? 'ok' : 'bad') . '">' . ($on ? 'Работает' : 'Остановлена') . '</span></li>';
        }
        echo '</ul>';
    }
    echo '</section></div>';
    layout($hello . ', ' . $_SESSION['user'], 'overview', (string) ob_get_clean());
}

/* ---------- Сайты и SSL ---------- */

function page_domains(): void
{
    $sites = helper_json(['sites']);
    ob_start();
    if (isset($sites['error'])) {
        echo '<p class="alert">' . nl2br(e($sites['error'])) . '</p>';
        layout('Сайты и SSL', 'domains', (string) ob_get_clean());
        return;
    }
    $list = $sites['data'];
    usort($list, function ($a, $b) {
        $da = cert_state($a)[2];
        $db = cert_state($b)[2];
        return ($da ?? 99999) <=> ($db ?? 99999) ?: strcmp($a['domain'], $b['domain']);
    });
    echo '<div class="toolbar"><input id="q" type="search" placeholder="Поиск по домену" aria-label="Поиск по домену">'
        . '<span class="muted grow">Всего: ' . count($list) . '</span><a class="btn primary" href="?p=newsite">Новый сайт</a></div>';
    echo '<div class="cards">';
    foreach ($list as $site) {
        $st = cert_state($site);
        $d = $site['domain'];
        $aliases = array_values(array_diff($site['names'], [$d]));
        $ci = $site['cert_info'] ?? null;
        echo '<article class="card" data-names="' . e(implode(' ', $site['names'])) . '">';
        echo '<header><h3><a href="https://' . e($d) . '" target="_blank" rel="noopener">' . e($d)
            . '</a></h3><span class="badge ' . $st[0] . '">' . e($st[1]) . '</span></header>';
        echo '<div class="chips">';
        if (!empty($site['managed'])) {
            echo '<span class="own">Создан в панели</span>';
        }
        foreach (array_slice($aliases, 0, 4) as $al) {
            echo '<span>' . e($al) . '</span>';
        }
        if (count($aliases) > 4) {
            echo '<span>+' . (count($aliases) - 4) . '</span>';
        }
        echo '</div><dl>';
        if ($ci) {
            echo '<dt>Сертификат</dt><dd>до ' . date('d.m.Y', (int) $ci['not_after']) . ($ci['issuer'] ? ', ' . e($ci['issuer']) : '') . '</dd>';
        }
        echo '<dt>Обработчик</dt><dd>' . e(!empty($site['php_ver']) ? 'PHP ' . $site['php_ver'] : backend_label($site['backend'])) . '</dd>';
        echo '<dt>Каталог</dt><dd class="mono">' . e($site['root'] ?? '—') . '</dd></dl>';

        if (!empty($site['dns_pending'])) {
            echo '<div class="dns"><b>Шаг 2. Добавьте TXT-записи у регистратора домена</b>';
            foreach ($site['dns_pending'] as $r) {
                echo '<div class="rec"><span>Имя</span><code>' . e($r['name']) . '</code><span>Значение</span><code>' . e($r['value']) . '</code></div>';
            }
            echo '<p class="muted">Нажмите на значение, чтобы выделить его целиком. Если регистратор сам дописывает домен, в поле «Имя» вводите только часть до домена, например <code>_acme-challenge</code>. '
                . 'Две записи с одним именем нужны обе. После сохранения подождите 5–30 минут.</p></div>';
        }
        echo '<footer>';
        if (!empty($site['wildcard'])) {
            if (!empty($site['dns_pending'])) {
                echo action_btn('primary', 'ssl_dns_finish', $d, 'Записи добавлены — выпустить', 'Выпустить wildcard-сертификат',
                    'Панель проверит, что TXT-записи видны в DNS, и только после этого запросит сертификат для ' . $d . '.');
                echo action_btn('', 'ssl_dns_start', $d, 'Получить новые записи', 'Получить новые TXT-записи',
                    'Прежние значения перестанут действовать. Используйте, если задание устарело или записи были введены с ошибкой.');
            } elseif ($site['cert']) {
                echo action_btn('primary', 'ssl_dns_start', $d, 'Wildcard SSL: получить TXT-записи', 'Wildcard-сертификат, шаг 1',
                    'Let\'s Encrypt выдаст TXT-записи для ' . $d . '. Их нужно добавить у регистратора домена, затем подтвердить в панели.');
            }
        } elseif ($site['cert'] || !empty($site['managed'])) {
            echo action_btn('primary', 'ssl_issue', $d, $site['cert'] ? 'Выпустить / продлить SSL' : 'Включить SSL', 'Сертификат для ' . $d,
                'Будет запрошен сертификат Let\'s Encrypt для ' . $d . ' и установлен в nginx. Обычно это занимает до минуты.');
            if ($site['cert']) {
                echo action_btn('', 'ssl_force', $d, 'Перевыпустить', 'Перевыпустить принудительно',
                    'Сертификат для ' . $d . ' будет выпущен заново, даже если текущий ещё действует. Лимит Let\'s Encrypt: не больше 5 одинаковых сертификатов в неделю.');
            }
        }
        echo '<a class="btn" href="?p=config&amp;d=' . e(rawurlencode($d)) . '">Настройки</a>';
        echo '</footer></article>';
    }
    echo '</div>';
    layout('Сайты и SSL', 'domains', (string) ob_get_clean(), 'Все сайты, найденные в настройках nginx. Первыми идут домены, у которых сертификат истекает раньше. <a href="?p=help#ssl">Как работает продление</a> · <a href="?p=help#wildcard">Wildcard-сертификаты</a>');
}

function page_newsite(): void
{
    $result = null;
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        if (!csrf_ok()) {
            $result = [1, 'Сессия устарела, обновите страницу'];
        } else {
            $result = helper(['site-create'], (string) json_encode([
                'domain' => trim((string) ($_POST['domain'] ?? '')), 'www' => !empty($_POST['www']),
                'type' => (string) ($_POST['type'] ?? ''), 'php_ver' => (string) ($_POST['php_ver'] ?? ''),
                'proxy_url' => trim((string) ($_POST['proxy_url'] ?? '')),
            ]));
        }
    }
    $opt = helper_json(['site-options']);
    ob_start();
    echo '<p><a class="btn" href="?p=domains">← К сайтам</a></p>';
    if ($result) {
        echo '<section class="panel"><p class="' . ($result[0] === 0 ? 'ok-msg' : 'alert') . '">' . ($result[0] === 0 ? 'Сайт создан.' : 'Сайт не создан.') . '</p><pre class="code">' . e($result[1]) . '</pre></section>';
    }
    if (isset($opt['error'])) {
        echo '<p class="alert">' . nl2br(e($opt['error'])) . '</p>';
    } elseif (!$opt['data']['conf_dir']) {
        echo '<p class="alert">В nginx.conf не подключён каталог для дополнительных сайтов. Добавьте в блок http строку <code>include /etc/nginx/conf.d/*.conf;</code>, создайте каталог и обновите страницу.</p>';
    } else {
        $php = $opt['data']['php'];
        $type = (string) ($_POST['type'] ?? ($php ? 'php' : 'static'));
        echo '<form class="panel form" method="post" action="?p=newsite"><input type="hidden" name="csrf" value="' . e($_SESSION['csrf']) . '">';
        echo '<label>Домен<input name="domain" required placeholder="example.com" value="' . e($result && $result[0] !== 0 ? ($_POST['domain'] ?? '') : '') . '"></label>';
        echo '<label class="check"><input type="checkbox" name="www" value="1" checked> Добавить имя с www</label>';
        echo '<fieldset><legend>Тип сайта</legend>';
        echo '<label class="check"><input type="radio" name="type" value="php"' . ($type === 'php' ? ' checked' : '') . ($php ? '' : ' disabled') . '> PHP-сайт'
            . ($php ? '' : ' <span class="muted">(службы PHP-FPM на сервере не найдены)</span>') . '</label>';
        echo '<label class="check"><input type="radio" name="type" value="static"' . ($type === 'static' ? ' checked' : '') . '> Статический сайт (HTML, CSS, JS)</label>';
        echo '<label class="check"><input type="radio" name="type" value="proxy"' . ($type === 'proxy' ? ' checked' : '') . '> Прокси на приложение (Node.js, Python и другие)</label></fieldset>';
        if ($php) {
            echo '<label>Версия PHP<select name="php_ver">';
            foreach ($php as $v) {
                echo '<option>' . e($v['ver']) . '</option>';
            }
            echo '</select><span class="muted">Используется только для PHP-сайта. Версию можно сменить позже.</span></label>';
        }
        echo '<label>Адрес приложения<input name="proxy_url" placeholder="http://127.0.0.1:3000"><span class="muted">Только для прокси.</span></label>';
        echo '<p class="muted">Конфигурация появится в <span class="mono">' . e($opt['data']['conf_dir']) . '</span>, файлы сайта в <span class="mono">/var/www/dewiar/домен/public</span>. Сайт создаётся без SSL, сертификат включается кнопкой в карточке, когда домен направлен на сервер.</p>';
        echo '<button class="btn primary" type="submit">Создать сайт</button></form>';
    }
    layout('Новый сайт', 'domains', (string) ob_get_clean(), 'Панель создаст каталог, отдельного пользователя, настройки nginx и, для PHP-сайта, отдельный пул PHP-FPM. Существующие сайты не затрагиваются.');
}

function page_site(string $domain): void
{
    $result = null;
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        if (!csrf_ok()) {
            $result = [1, 'Сессия устарела, обновите страницу'];
        } elseif (($_POST['op'] ?? '') === 'php') {
            $result = helper(['site-php', $domain, (string) ($_POST['php_ver'] ?? '')]);
        } elseif (($_POST['op'] ?? '') === 'save') {
            $result = helper(['config-save', $domain, (string) ($_POST['file'] ?? '')], (string) ($_POST['content'] ?? ''));
        }
    }
    $res = helper_json(['site-config', $domain]);
    $sites = helper_json(['sites']);
    $site = null;
    foreach ($sites['data'] ?? [] as $s) {
        if ($s['domain'] === $domain) {
            $site = $s;
        }
    }
    ob_start();
    echo '<p><a class="btn" href="?p=domains">← К сайтам</a></p>';
    if ($result) {
        echo '<section class="panel"><p class="' . ($result[0] === 0 ? 'ok-msg' : 'alert') . '">' . ($result[0] === 0 ? 'Выполнено.' : 'Не выполнено.') . '</p><pre class="code">' . e($result[1]) . '</pre></section>';
    }
    if (isset($res['error']) || !$site) {
        echo '<p class="alert">' . nl2br(e($res['error'] ?? 'Сайт не найден')) . '</p>';
        layout('Сайт ' . $domain, 'domains', (string) ob_get_clean());
        return;
    }
    if (!empty($site['managed'])) {
        echo '<section class="panel"><h2>Управление сайтом</h2>';
        if ($site['type'] === 'php') {
            $opt = helper_json(['site-options']);
            echo '<form method="post" action="?p=config&amp;d=' . e(rawurlencode($domain)) . '" class="inline"><input type="hidden" name="csrf" value="' . e($_SESSION['csrf']) . '">'
                . '<input type="hidden" name="op" value="php"><label>Версия PHP <select name="php_ver">';
            foreach ($opt['data']['php'] ?? [] as $v) {
                echo '<option' . ($v['ver'] === $site['php_ver'] ? ' selected' : '') . '>' . e($v['ver']) . '</option>';
            }
            echo '</select></label><button class="btn primary" type="submit">Сменить версию</button></form>';
        }
        echo '<p>' . action_btn('danger', 'site_delete', $domain, 'Удалить сайт', 'Удалить сайт ' . $domain,
            'Сайт будет отключён и убран из nginx. Файлы сайта останутся на сервере, их можно удалить вручную.', '?p=domains') . '</p></section>';
    } else {
        echo '<p class="muted">Этот сайт создан не в панели. Версия PHP и другие параметры меняются в его конфигурации ниже.</p>';
    }
    foreach ($res['data'] as $f) {
        echo '<form class="panel" method="post" action="?p=config&amp;d=' . e(rawurlencode($domain)) . '"><h2>' . ($f['kind'] === 'nginx' ? 'nginx' : 'Apache')
            . ' <span class="mono muted">' . e($f['file']) . '</span></h2><input type="hidden" name="csrf" value="' . e($_SESSION['csrf']) . '">'
            . '<input type="hidden" name="op" value="save"><input type="hidden" name="file" value="' . e($f['file']) . '">'
            . '<textarea name="content" class="editor" spellcheck="false" rows="' . min(34, max(8, substr_count($f['content'], "\n") + 2)) . '" aria-label="Содержимое файла">' . e($f['content']) . '</textarea>'
            . '<div class="form-foot"><span class="muted">Перед применением настройки проверяются. При ошибке прежний файл возвращается автоматически.</span>'
            . '<button class="btn primary" type="submit">Сохранить и применить</button></div></form>';
    }
    layout('Сайт ' . $domain, 'domains', (string) ob_get_clean(), 'Настройки веб-сервера для этого сайта. <a href="?p=help#editor">Как работает редактор и откат</a>');
}

/* ---------- Базы данных ---------- */

function page_databases(): void
{
    $dbs = helper_json(['databases']);
    $dumps = helper_json(['dumps']);
    ob_start();
    echo '<section class="panel"><h2>Базы данных</h2>';
    if (isset($dbs['error'])) {
        echo '<p class="alert">' . nl2br(e($dbs['error'])) . '</p>';
    } elseif (!$dbs['data']) {
        echo '<p class="muted">Пользовательских баз данных нет.</p>';
    } else {
        $max = max(1.0, max(array_column($dbs['data'], 'size')));
        echo '<table><thead><tr><th>База</th><th>Таблиц</th><th>Размер</th><th></th></tr></thead><tbody>';
        foreach ($dbs['data'] as $db) {
            echo '<tr><td><b>' . e($db['name']) . '</b></td><td>' . (int) $db['tables'] . '</td><td><div class="sizebar"><i style="width:' . max(2, (int) round($db['size'] / $max * 100)) . '%"></i></div>' . e(fmt_bytes((float) $db['size'])) . '</td>'
                . '<td class="right">' . action_btn('primary', 'db_dump', $db['name'], 'Сделать дамп', 'Дамп базы ' . $db['name'],
                    'Будет создана сжатая копия базы ' . $db['name'] . '. Сайт при этом продолжает работать. Для больших баз это может занять несколько минут.') . '</td></tr>';
        }
        echo '</tbody></table>';
    }
    echo '</section><section class="panel"><h2>Сохранённые дампы</h2>';
    if (isset($dumps['error'])) {
        echo '<p class="alert">' . nl2br(e($dumps['error'])) . '</p>';
    } elseif (!$dumps['data']) {
        echo '<p class="muted">Дампов пока нет.</p>';
    } else {
        echo '<table><thead><tr><th>Файл</th><th>Создан</th><th>Размер</th><th></th></tr></thead><tbody>';
        foreach ($dumps['data'] as $d) {
            echo '<tr><td class="mono">' . e($d['file']) . '</td><td>' . date('d.m.Y H:i', (int) $d['time']) . '</td><td>' . e(fmt_bytes((float) $d['size'])) . '</td>'
                . '<td class="right"><a class="btn" href="?p=download&amp;f=' . e(rawurlencode($d['file'])) . '">Скачать</a> '
                . action_btn('', 'db_restore', 'dump:' . $d['file'], 'Восстановить', 'Восстановить базу из дампа',
                    'Содержимое базы будет заменено данными из файла ' . $d['file'] . '. Текущее состояние перед этим сохранится отдельным дампом.') . ' '
                . action_btn('', 'dump_delete', $d['file'], 'Удалить', 'Удалить дамп', 'Файл ' . $d['file'] . ' будет удалён с сервера. Восстановить его будет нельзя.') . '</td></tr>';
        }
        echo '</tbody></table>';
    }
    echo '</section>';
    layout('Базы данных', 'databases', (string) ob_get_clean(), 'Дамп — это полная копия базы в одном сжатом файле. Сделайте его перед обновлением сайта или переносом. <a href="?p=help#db">Как восстановить базу из дампа</a>');
}

function download_dump(string $file): void
{
    $dumps = helper_json(['dumps']);
    foreach ($dumps['data'] ?? [] as $d) {
        if ($d['file'] === $file) {
            session_write_close();
            @set_time_limit(0);
            header('Content-Type: application/gzip');
            header('Content-Length: ' . (int) $d['size']);
            header('Content-Disposition: attachment; filename="' . $d['file'] . '"');
            helper_stream(['dump-read', $file]);
            return;
        }
    }
    http_response_code(404);
    layout('Файл не найден', 'databases', '<p class="alert">Такого дампа на сервере нет.</p><p><a class="btn" href="?p=databases">К базам данных</a></p>');
}

/* ---------- Службы ---------- */

function page_services(): void
{
    $services = helper_json(['services']);
    ob_start();
    if (isset($services['error'])) {
        echo '<p class="alert">' . nl2br(e($services['error'])) . '</p>';
    } else {
        echo '<section class="panel"><table><thead><tr><th>Служба</th><th>Состояние</th><th>Автозапуск</th><th></th></tr></thead><tbody>';
        foreach ($services['data'] as $sv) {
            $on = $sv['active'] === 'active';
            $name = (string) preg_replace('/\.service$/', '', $sv['unit']);
            echo '<tr><td><b>' . e($name) . '</b></td><td><span class="badge ' . ($on ? 'ok' : 'bad') . '">'
                . ($on ? 'Работает' : 'Остановлена') . '</span></td><td>' . ($sv['enabled'] === 'enabled' ? 'Включён' : e($sv['enabled'])) . '</td><td class="right">';
            if (preg_match('/^(nginx|apache2|httpd|(fp2-)?php[0-9.]*-?fpm)$/', $name)) {
                echo action_btn('', 'service_reload', $sv['unit'], 'Перечитать конфиг', 'Перечитать конфигурацию ' . $name,
                    'Служба ' . $name . ' применит новые настройки без остановки. Сайты не прерываются.') . ' ';
            }
            echo action_btn('', 'service_restart', $sv['unit'], 'Перезапустить', 'Перезапустить ' . $name,
                'Служба ' . $name . ' будет перезапущена. Для nginx сначала проверяется конфигурация.') . '</td></tr>';
        }
        echo '</tbody></table></section>';
    }
    echo '<section class="panel"><h2>Сервер</h2><p class="muted">Полная перезагрузка сервера. Все сайты будут недоступны 1–3 минуты.</p>'
        . action_btn('danger', 'reboot', '', 'Перезагрузить сервер', 'Перезагрузить сервер',
            'Сервер будет перезагружен целиком. Сайты, почта и панель будут недоступны 1–3 минуты.') . '</section>';
    layout('Службы и сервер', 'services', (string) ob_get_clean(), '«Перечитать конфиг» применяет новые настройки без остановки сайтов. «Перезапустить» останавливает службу на несколько секунд. <a href="?p=help#services">Когда что выбирать</a>');
}

/* ---------- Справка ---------- */

function page_help(): void
{
    ob_start();
    ?>
<nav class="toc">
  <a href="#overview">Обзор и графики</a><a href="#ssl">Сайты и SSL</a><a href="#wildcard">Wildcard-сертификаты</a><a href="#newsite">Новый сайт</a>
  <a href="#editor">Редактор настроек</a><a href="#db">Базы данных</a><a href="#backups">Резервные копии</a><a href="#cron">Планировщик</a><a href="#ftp">FTP</a><a href="#mail">Почта</a><a href="#dnshelp">DNS</a><a href="#services">Службы и сервер</a><a href="#vuln">Уязвимости и обновления</a><a href="#security">Доступ к панели</a><a href="#trouble">Если что-то не работает</a>
</nav>

<section class="panel help" id="overview"><h2>Обзор и графики</h2>
<ul>
  <li><b>Кольца</b> показывают, какая часть процессора, памяти и диска занята сейчас. Состояние подписано словами: «В норме» до 75%, «Высоко» до 90%, «Критично» выше.</li>
  <li><b>Графики</b> строятся по замерам раз в минуту, история хранится 7 дней. Период переключается кнопками «1 час», «24 часа», «7 дней». Наведите курсор на график, чтобы увидеть значение в конкретный момент.</li>
  <li><b>Средняя нагрузка</b> сравнивается с числом ядер. Если она долго выше числа ядер, серверу не хватает мощности.</li>
  <li><b>Сертификаты</b>: полоса показывает, сколько доменов в каждом состоянии. В списке ниже только те, которым осталось меньше 30 дней.</li>
</ul></section>

<section class="panel help" id="ssl"><h2>Сайты и SSL</h2>
<p>Панель читает действующие настройки nginx и показывает каждый сайт отдельной карточкой, включая сайты, созданные ещё в FastPanel.</p>
<h3>Что означают метки</h3>
<ul>
  <li><span class="badge ok">Действует</span> до конца срока 30 дней и больше, делать ничего не нужно.</li>
  <li><span class="badge warn">Осталось N дн.</span> меньше 30 дней. Если домен уже продлевался через панель обычным способом, он обновится сам.</li>
  <li><span class="badge bad">Истекает / Истёк</span> осталось 7 дней или меньше. Продлите вручную.</li>
  <li><span class="badge none">SSL не настроен</span> сайт работает только по HTTP.</li>
</ul>
<h3>Кнопки</h3>
<ul>
  <li><b>Выпустить / продлить SSL</b> запрашивает бесплатный сертификат Let's Encrypt на 90 дней и ставит его на место текущего.</li>
  <li><b>Перевыпустить</b> запрашивает новый сертификат в любом случае. Лимит Let's Encrypt: не больше 5 одинаковых сертификатов в неделю.</li>
  <li><b>Включить SSL</b> есть у сайтов, созданных в панели: выпускает сертификат и включает HTTPS с перенаправлением.</li>
  <li><b>Настройки</b> открывает страницу сайта с редактором конфигурации.</li>
</ul>
<h3>Автопродление</h3>
<p>Обычный сертификат достаточно один раз продлить кнопкой. После этого сервер сам обновляет его примерно за 30 дней до конца срока. Wildcard-сертификаты сами не продлеваются, см. следующий раздел.</p>
<h3>Как вернуть прежний сертификат</h3>
<p>Перед первой заменой панель сохраняет исходные файлы рядом, с окончанием <span class="mono">.before-dewiar</span>. Скопируйте их обратно под прежним именем и нажмите «Перечитать конфиг» у nginx.</p></section>

<section class="panel help" id="wildcard"><h2>Wildcard-сертификаты</h2>
<p>Сертификат для маски вида <span class="mono">*.domain.com</span> покрывает все поддомены. Let's Encrypt выдаёт его только после проверки через TXT-записи в DNS. Если у сайта в nginx есть имя с маской, в карточке вместо обычной кнопки появляется «Wildcard SSL».</p>
<ol>
  <li>Нажмите <b>«Wildcard SSL: получить TXT-записи»</b>. В карточке появятся имя и значение записей.</li>
  <li>Откройте настройки домена у регистратора и добавьте TXT-записи. Обычно их две с одинаковым именем <span class="mono">_acme-challenge</span> и разными значениями, нужны обе.</li>
  <li>Подождите 5–30 минут, пока записи разойдутся по DNS.</li>
  <li>Нажмите <b>«Записи добавлены — выпустить»</b>. Панель сначала сама проверит, что записи видны, и только потом обратится в Let's Encrypt.</li>
</ol>
<ul>
  <li>Если записи ещё не видны, панель скажет, каких не хватает. Лимиты Let's Encrypt при этом не расходуются.</li>
  <li>После выпуска TXT-записи можно удалить.</li>
  <li><b>Такой сертификат не продлевается сам.</b> Каждые 90 дней оба шага нужно повторять, значения записей каждый раз новые. Панель покажет предупреждение за 30 дней.</li>
</ul></section>

<section class="panel help" id="newsite"><h2>Новый сайт</h2>
<p>Кнопка «Новый сайт» на странице «Сайты и SSL». Панель создаёт каталог, отдельного пользователя и настройки nginx.</p>
<ul>
  <li><b>PHP-сайт</b> получает собственный пул PHP-FPM выбранной версии. Сайты изолированы друг от друга.</li>
  <li><b>Статический сайт</b> отдаёт готовые файлы.</li>
  <li><b>Прокси</b> передаёт запросы приложению, которое слушает указанный адрес.</li>
  <li>Файлы загружайте в <span class="mono">/var/www/dewiar/домен/public</span>.</li>
  <li>Версия PHP у таких сайтов меняется на странице «Настройки» одной кнопкой. У сайтов из FastPanel она меняется в конфигурации.</li>
  <li>При включении SSL и смене типа панель переписывает конфигурацию созданного ею сайта. Ручные правки в этом файле при этом теряются.</li>
  <li>«Удалить сайт» отключает сайт и убирает его из nginx. Файлы остаются на сервере.</li>
</ul></section>

<section class="panel help" id="editor"><h2>Редактор настроек</h2>
<p>На странице сайта показаны его файлы конфигурации nginx и Apache. Их можно менять прямо в панели.</p>
<ul>
  <li>При нажатии «Сохранить и применить» панель записывает файл и проверяет настройки веб-сервера.</li>
  <li>Если проверка не прошла, прежний файл возвращается автоматически, сайты продолжают работать. Текст ошибки показывается на странице.</li>
  <li>Каждая прежняя версия файла сохраняется в <span class="mono">/var/backups/dewiar-panel/configs</span>.</li>
</ul></section>

<section class="panel help" id="db"><h2>Базы данных</h2>
<ul>
  <li><b>Сделать дамп</b> создаёт полную копию базы. Сайт при этом продолжает работать.</li>
  <li>Дампы хранятся на сервере в <span class="mono">/var/backups/dewiar-panel</span>. Скачивайте важные копии к себе.</li>
  <li>Старые дампы панель сама не удаляет. Следите за местом на диске.</li>
</ul>
<h3>Как восстановить базу из дампа</h3>
<p>Нажмите «Восстановить» напротив дампа. Содержимое базы заменяется данными из файла. Перед этим панель сама сохраняет текущее состояние базы отдельным дампом, так что действие можно отменить.</p></section>

<section class="panel help" id="backups"><h2>Резервные копии</h2>
<ul>
  <li>Включите расписание, выберите час запуска и срок хранения. Копия создаётся раз в сутки: архив файлов каждого сайта и дамп каждой базы.</li>
  <li>«Создать копию сейчас» запускает создание в фоне. Для больших сайтов это занимает несколько минут, обновите страницу позже.</li>
  <li>Копии хранятся на этом же сервере в <span class="mono">/var/backups/dewiar-panel/auto</span>. <b>Если откажет диск, пропадут и они.</b> Важные копии скачивайте к себе.</li>
  <li>Базу можно восстановить кнопкой. Файлы сайта восстанавливаются вручную: скачайте архив и распакуйте его в каталог сайта.</li>
  <li>Копии старше срока хранения удаляются автоматически. Если на диске меньше 5% места, новая копия не создаётся.</li>
</ul></section>

<section class="panel help" id="cron"><h2>Планировщик</h2>
<ul>
  <li>Задание состоит из расписания, пользователя и команды. Расписание записывается пятью полями cron: минута, час, день месяца, месяц, день недели.</li>
  <li>Примеры: <span class="mono">*/5 * * * *</span> каждые 5 минут, <span class="mono">0 3 * * *</span> каждый день в 03:00, <span class="mono">0 9 * * 1</span> по понедельникам в 09:00.</li>
  <li>Запускайте задания сайта от имени владельца его файлов, а не от root.</li>
  <li>Задания, созданные не в панели (в том числе оставшиеся от FastPanel), показаны списком только для просмотра.</li>
</ul></section>

<section class="panel help" id="ftp"><h2>FTP</h2>
<ul>
  <li>Аккаунт привязывается к одному сайту и видит только его каталог.</li>
  <li>Поддерживается сервер ProFTPD. Аккаунты, созданные раньше в FastPanel, продолжают работать, но в этом списке не показываются.</li>
  <li>Файлы, загруженные по FTP, получают того же владельца, что и остальные файлы сайта.</li>
  <li>FTP передаёт пароль открытым текстом. Если ваш клиент умеет, включайте в нём шифрование (FTPS).</li>
</ul></section>

<section class="panel help" id="mail"><h2>Почта</h2>
<ul>
  <li>Страница показывает почтовый сервер, очередь писем и список ящиков.</li>
  <li><b>Очередь</b> — письма, которые сервер ещё не смог отправить. Несколько писем в очереди — норма. Сотни писем означают проблему с доставкой или рассылку спама со взломанного сайта.</li>
  <li>«Отправить очередь» запускает новую попытку доставки. «Удалить замороженные» убирает письма, которые сервер отчаялся доставить.</li>
  <li>Создание и удаление ящиков из панели пока недоступно. Существующие ящики работают как раньше.</li>
</ul></section>

<section class="panel help" id="dnshelp"><h2>DNS</h2>
<ul>
  <li>Страница показывает, как ваши домены видны из интернета: адрес, серверы имён, почтовые записи.</li>
  <li>Метка «Другой адрес» означает, что домен указывает не туда же, куда большинство ваших доменов. Сертификат для такого домена не выпустится.</li>
  <li>SPF и DMARC нужны, чтобы письма с домена не попадали в спам. «Нет» означает, что запись стоит добавить у регистратора.</li>
  <li>Сами записи меняются у регистратора домена. Панель их только проверяет. Данные обновляются раз в 15 минут или кнопкой.</li>
</ul></section>

<section class="panel help" id="services"><h2>Службы и сервер</h2>
<ul>
  <li><b>Перечитать конфиг</b> применяет изменённые настройки без остановки.</li>
  <li><b>Перезапустить</b> останавливает и запускает службу заново. Сайты недоступны несколько секунд.</li>
  <li><b>Перезагрузить сервер</b> перезагружает всю машину на 1–3 минуты.</li>
</ul>
<p>Перед действиями с nginx и Apache панель проверяет их настройки. Если в них ошибка, действие отменяется.</p></section>

<section class="panel help" id="vuln"><h2>Уязвимости и обновления</h2>
<p>Страница «Безопасность» показывает версии nginx, Apache и PHP, сверяет их с исправлениями от ноября 2026 года и показывает обновления, доступные в репозиториях сервера.</p>
<ul>
  <li><b>Исправлена</b>: версия не ниже исправленной, либо исправление упомянуто в журнале изменений пакета. Дистрибутивы часто переносят исправления, не меняя номер версии.</li>
  <li><b>Уязвима</b>: версия старая и включена функция, через которую работает атака. Обновите пакет или примените защитную меру.</li>
  <li><b>Риск снижен</b>: версия старая, но опасная функция выключена.</li>
  <li><b>Выключить HTTP/2</b> убирает HTTP/2 из всех конфигов nginx. Сайты продолжают работать по HTTP/1.1, чуть медленнее на страницах с большим числом файлов. Прежние файлы сохраняются, при ошибке всё возвращается.</li>
  <li><b>Установить обновления</b> обновляет только пакеты nginx, Apache, PHP и OpenSSL. Ваши файлы конфигурации сохраняются. Перед обновлением сделайте дампы баз.</li>
  <li>Если пакет установлен из репозитория FastPanel, обновлений для него может больше не быть. Тогда остаются защитные меры и переход на пакеты из репозитория системы.</li>
  <li>Список известных уязвимостей зашит в версию панели и обновляется вместе с ней. Проверка доступных обновлений работает всегда.</li>
  <li><b>Обновление панели</b>: когда выходит новая версия, на главной появляется сообщение, а здесь кнопка «Обновить панель». Сайты и настройки при обновлении не затрагиваются. Из терминала то же делает команда <span class="mono">dewiar-panel update</span>.</li>
</ul></section>

<section class="panel help" id="security"><h2>Доступ к панели</h2>
<ul>
  <li>Панель работает по HTTP и рассчитана на доступ из локальной сети. Не открывайте её порт в интернет.</li>
  <li>После 5 неверных паролей вход блокируется на 5 минут.</li>
  <li>Сменить логин, пароль, адрес или порт: по SSH выполните <span class="mono">dewiar-panel password</span>.</li>
</ul></section>

<section class="panel help" id="trouble"><h2>Если что-то не работает</h2>
<ul>
  <li><b>Панель не открывается.</b> По SSH: <span class="mono">systemctl status dewiar-panel</span>, журнал: <span class="mono">journalctl -u dewiar-panel -n 50</span>.</li>
  <li><b>Сертификат не выпускается.</b> Причина написана в журнале после нажатия кнопки. Чаще всего домен не указывает на сервер или закрыт порт 80.</li>
  <li><b>Графики пустые.</b> Проверьте таймер: <span class="mono">systemctl status dewiar-metrics.timer</span>.</li>
  <li><b>Копии не создаются.</b> Проверьте таймер: <span class="mono">systemctl status dewiar-backup.timer</span>, журнал: <span class="mono">journalctl -u dewiar-backup -n 50</span>.</li>
  <li><b>Не видны базы данных.</b> Пользователь root должен входить в MySQL без пароля или через файл <span class="mono">/root/.my.cnf</span>.</li>
  <li><b>Сертификат вернулся к старому.</b> Возможно, его перезаписала служба FastPanel. Остановите её.</li>
</ul>
<p class="muted">Версия панели: <?= DSP_VERSION ?>. Установка и обновление описаны в файле Ustanovka.pdf в репозитории проекта.</p></section>
    <?php
    layout('Справка', 'help', (string) ob_get_clean(), 'Как устроена панель и что делает каждая кнопка.');
}

/* ---------- Безопасность ---------- */

function sec_card(string $name, string $version, string $cls, string $state, array $lines, string $buttons = ''): string
{
    $h = '<article class="card"><header><h3>' . e($name) . ' <span class="mono muted">' . e($version) . '</span></h3><span class="badge ' . $cls . '">' . e($state) . '</span></header><ul class="plain">';
    foreach ($lines as $l) {
        $h .= '<li>' . $l . '</li>';
    }
    return $h . '</ul>' . ($buttons !== '' ? '<footer>' . $buttons . '</footer>' : '') . '</article>';
}

function origin_line(array $c): string
{
    if (empty($c['pkg'])) {
        return 'Установлен не из пакета: обновляется вручную.';
    }
    $o = (string) ($c['origin'] ?? '');
    $warn = stripos($o, 'fastpanel') !== false ? ' <b>Это репозиторий FastPanel: обновлений в нём может больше не быть.</b>' : '';
    return 'Пакет <span class="mono">' . e($c['pkg']) . '</span>, источник: ' . e($o !== '' ? $o : 'не определён') . '.' . $warn;
}

function page_security(): void
{
    $res = helper_json(['security']);
    ob_start();
    if (isset($res['error'])) {
        echo '<p class="alert">' . nl2br(e($res['error'])) . '</p>';
        layout('Безопасность', 'security', (string) ob_get_clean());
        return;
    }
    $d = $res['data'];
    echo '<div class="cards">';

    if ($n = $d['nginx']) {
        $v = $n['version'];
        $old = version_compare($v, '1.9.5', '>=') && (version_compare($v, '1.30.7', '<') || (version_compare($v, '1.31.0', '>=') && version_compare($v, '1.31.9', '<')));
        $lines = ['Уязвимости от 18.11.2026 (NGINX2Root, главная — CVE-2026-123007 в HTTP/2). Исправлены в 1.30.7 и 1.31.9.', origin_line($n)];
        $btn = '';
        if (!$old || $n['backport']) {
            $st = ['ok', 'Исправлена'];
            $lines[] = $n['backport'] ? 'Исправление перенесено в пакет дистрибутива (есть в журнале изменений).' : 'Версия не ниже исправленной.';
        } elseif ($n['http2']) {
            $st = ['bad', 'Уязвима'];
            $lines[] = '<b>HTTP/2 включён</b>: уязвимость доступна из интернета без авторизации.';
            $btn = action_btn('primary', 'sec_http2_off', '', 'Выключить HTTP/2', 'Выключить HTTP/2 в nginx',
                'HTTP/2 будет убран из всех конфигов nginx. Сайты продолжат работать по HTTP/1.1. Прежние файлы сохраняются, при ошибке изменения отменяются.');
        } else {
            $st = ['warn', 'Риск снижен'];
            $lines[] = 'Версия старая, но HTTP/2 выключен: главная уязвимость не срабатывает. Обновите nginx при первой возможности.';
        }
        if ($n['keep_conn']) {
            $lines[] = 'В конфигурации включён <span class="mono">fastcgi_keep_conn on</span>: это условие для атаки на PHP-FPM (CVE-2026-129289). Уберите его в редакторе конфигурации.';
        }
        echo sec_card('nginx', $v, $st[0], $st[1], $lines, $btn);
    }

    if ($a = $d['apache']) {
        $v = $a['version'];
        $old = version_compare($v, '2.4.55', '>=') && version_compare($v, '2.4.71', '<');
        $lines = ['Уязвимость CVE-2026-119618 в модуле HTTP/2, исправлена в 2.4.71 от 11.11.2026. Сообщалось об атаках.', origin_line($a)];
        $btn = '';
        if (!$old || $a['backport']) {
            $st = ['ok', 'Исправлена'];
            $lines[] = $a['backport'] ? 'Исправление перенесено в пакет дистрибутива (есть в журнале изменений).' : 'Версия вне уязвимого диапазона 2.4.55–2.4.70.';
        } elseif ($a['http2']) {
            $st = ['bad', 'Уязвима'];
            $lines[] = '<b>Модуль HTTP/2 загружен.</b> Если Apache стоит за nginx и слушает только локальный адрес, снаружи он недоступен, но модуль лучше отключить.';
            $btn = action_btn('primary', 'sec_apache_http2_off', '', 'Отключить модуль HTTP/2', 'Отключить HTTP/2 в Apache',
                'Модуль http2 будет отключён, Apache перезапущен. Сайты за nginx этого не заметят.');
        } else {
            $st = ['warn', 'Риск снижен'];
            $lines[] = 'Версия старая, но модуль HTTP/2 не загружен: уязвимость не срабатывает.';
        }
        echo sec_card('Apache', $v, $st[0], $st[1], $lines, $btn);
    }

    $fixed = ['8.2' => '8.2.36', '8.3' => '8.3.37', '8.4' => '8.4.28', '8.5' => '8.5.13'];
    foreach ($d['php'] as $p) {
        $v = $p['version'];
        $br = implode('.', array_slice(explode('.', $v), 0, 2));
        $lines = ['Выпуск исправлений от 19.11.2026: 8.2.36, 8.3.37, 8.4.28, 8.5.13. Критические ошибки в сессиях и PHP-FPM.', origin_line($p)];
        if ($p['backport'] || version_compare($br, '8.6', '>=') || (isset($fixed[$br]) && version_compare($v, $fixed[$br], '>='))) {
            $st = ['ok', 'Исправлена'];
            $lines[] = $p['backport'] ? 'Исправление перенесено в пакет дистрибутива (есть в журнале изменений).' : 'Версия не ниже исправленной.';
        } elseif (!isset($fixed[$br])) {
            $st = ['bad', 'Не поддерживается'];
            $lines[] = '<b>Ветка ' . e($br) . ' снята с поддержки</b>: разработчики PHP исправлений для неё не выпускают. Переводите сайты на 8.3 или новее.';
        } else {
            $st = ['bad', 'Уязвима'];
            $lines[] = 'Нужна версия ' . $fixed[$br] . ' или новее.';
        }
        if ($st[0] !== 'ok' && $p['upload_progress']) {
            $lines[] = 'Включён <span class="mono">session.upload_progress.enabled</span>, через него работает одна из атак на сессии. Выключите в php.ini, если сайты его не используют.';
        }
        echo sec_card('PHP' . ($p['unit'] ? ' FPM' : ''), $v, $st[0], $st[1], $lines);
    }
    echo '</div>';

    $pu = helper_json_cached(['panel-update-check'], 21600);
    $latest = $pu['data']['latest'] ?? null;
    echo '<section class="panel" style="margin-top:26px"><h2>Обновление панели</h2>';
    if (!$latest) {
        echo '<p>Установлена версия <b>' . e(DSP_VERSION) . '</b>. <span class="muted">Узнать последнюю версию не удалось: нет доступа к github.com.</span></p>';
    } elseif (version_compare($latest, DSP_VERSION, '>')) {
        echo '<p>Установлена версия <b>' . e(DSP_VERSION) . '</b>, доступна <b>' . e($latest) . '</b> <span class="badge warn">Есть обновление</span></p><p>'
            . action_btn('primary', 'panel_update', '', 'Обновить панель', 'Обновить панель до ' . $latest,
                'Панель загрузит новую версию и перезапустится. Сайты при этом не затрагиваются. Логин, пароль и все настройки сохраняются. Через минуту обновите страницу.') . '</p>';
    } else {
        echo '<p>Установлена версия <b>' . e(DSP_VERSION) . '</b> <span class="badge ok">Последняя</span></p>';
    }
    echo '<p class="muted">То же самое из терминала: <span class="mono">dewiar-panel update</span></p></section>';

    echo '<section class="panel"><h2>Обновления пакетов</h2>';
    if (!$d['updates']) {
        echo '<p class="muted">На этом сервере нет apt. Обновления проверяются по SSH средствами системы (yum или dnf).</p>';
    } else {
        $u = $d['updates'];
        echo '<p>Доступно обновлений в системе: <b>' . (int) $u['total'] . '</b>, из них для веб-сервера и PHP: <b>' . count($u['web']) . '</b>. '
            . '<span class="muted">Списки пакетов обновлялись ' . ($u['checked'] ? date('d.m.Y H:i', (int) $u['checked']) : 'неизвестно когда') . '.</span></p>';
        if ($u['web']) {
            echo '<table><thead><tr><th>Пакет</th><th>Установлена</th><th>Доступна</th></tr></thead><tbody>';
            foreach ($u['web'] as $w) {
                echo '<tr><td><b>' . e($w['pkg']) . '</b></td><td class="mono">' . e($w['old']) . '</td><td class="mono">' . e($w['new']) . '</td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '<p style="margin-top:16px">' . action_btn('', 'sec_refresh', '', 'Проверить обновления', 'Проверить обновления',
            'Сервер заново загрузит списки пакетов из репозиториев. Ничего не устанавливается.');
        if ($u['web']) {
            echo ' ' . action_btn('primary', 'sec_upgrade', '', 'Установить обновления веб-сервера и PHP', 'Установить обновления',
                'Будут обновлены только пакеты nginx, Apache, PHP и OpenSSL из таблицы. Файлы конфигурации сохраняются. Службы перезапустятся, сайты могут быть недоступны несколько секунд. Перед обновлением сделайте дампы баз.');
        }
        echo '</p>';
    }
    echo '</section><p class="muted">Сведения об уязвимостях актуальны на 24.11.2026 и обновляются вместе с панелью.</p>';
    layout('Безопасность', 'security', (string) ob_get_clean(), 'Версии nginx, Apache и PHP, сверка с ноябрьскими исправлениями 2026 года и доступные обновления. <a href="?p=help#vuln">Что означают состояния и кнопки</a>');
}

/* ---------- Общая обработка форм ---------- */

function form_result(?array $result): string
{
    if (!$result) {
        return '';
    }
    cache_clear();
    return '<section class="panel"><p class="' . ($result[0] === 0 ? 'ok-msg' : 'alert') . '">' . ($result[0] === 0 ? 'Выполнено.' : 'Не выполнено.') . '</p><pre class="code">' . e($result[1]) . '</pre></section>';
}

function form_post(): ?string
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        return null;
    }
    return csrf_ok() ? (string) ($_POST['op'] ?? '') : '!csrf';
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e($_SESSION['csrf']) . '">';
}

/* ---------- Резервные копии ---------- */

function page_backups(): void
{
    $result = null;
    $op = form_post();
    if ($op === '!csrf') {
        $result = [1, 'Сессия устарела, обновите страницу'];
    } elseif ($op === 'config') {
        $result = helper(['backup-config'], (string) json_encode(['enabled' => !empty($_POST['enabled']), 'hour' => (int) ($_POST['hour'] ?? 3),
            'keep_days' => (int) ($_POST['keep_days'] ?? 7), 'files' => !empty($_POST['files']), 'db' => !empty($_POST['db'])]));
    }
    $res = helper_json(['backups']);
    ob_start();
    echo form_result($result);
    if (isset($res['error'])) {
        echo '<p class="alert">' . nl2br(e($res['error'])) . '</p>';
        layout('Резервные копии', 'backups', (string) ob_get_clean());
        return;
    }
    $c = $res['data']['cfg'];
    echo '<form class="panel form" method="post" action="?p=backups">' . csrf_field() . '<input type="hidden" name="op" value="config"><h2>Расписание</h2>';
    echo '<label class="check"><input type="checkbox" name="enabled" value="1"' . ($c['enabled'] ? ' checked' : '') . '> Создавать копию каждый день</label>';
    echo '<div class="inline"><label>Час запуска <select name="hour">';
    for ($h = 0; $h < 24; $h++) {
        echo '<option value="' . $h . '"' . ($h === (int) $c['hour'] ? ' selected' : '') . '>' . sprintf('%02d:10', $h) . '</option>';
    }
    echo '</select></label><label>Хранить, дней <input type="number" name="keep_days" min="1" max="90" value="' . (int) $c['keep_days'] . '" style="width:90px"></label></div>';
    echo '<label class="check"><input type="checkbox" name="files" value="1"' . ($c['files'] ? ' checked' : '') . '> Файлы сайтов</label>';
    echo '<label class="check"><input type="checkbox" name="db" value="1"' . ($c['db'] ? ' checked' : '') . '> Базы данных</label>';
    echo '<div class="form-foot"><span class="muted">Свободно на диске: ' . e(fmt_bytes((float) $res['data']['free'])) . '</span><button class="btn primary" type="submit">Сохранить расписание</button></div></form>';

    echo '<div class="toolbar"><h2 class="grow">Готовые копии</h2>'
        . ($res['data']['running'] ? '<span class="badge warn">Копия создаётся</span>' : action_btn('primary', 'backup_start', '', 'Создать копию сейчас', 'Создать резервную копию',
            'Копия файлов сайтов и баз будет создаваться в фоне. Для больших сайтов это занимает несколько минут.')) . '</div>';
    if (!$res['data']['sets']) {
        echo '<p class="muted">Копий пока нет.</p>';
    }
    foreach ($res['data']['sets'] as $set) {
        $state = !$set['done'] ? ['warn', 'Создаётся'] : ($set['errors'] ? ['bad', 'С ошибками: ' . $set['errors']] : ['ok', 'Готова']);
        echo '<details class="panel"><summary><b>' . date('d.m.Y H:i', (int) $set['time']) . '</b> <span class="muted">' . count($set['files']) . ' файлов, ' . e(fmt_bytes((float) $set['size']))
            . '</span> <span class="badge ' . $state[0] . '">' . e($state[1]) . '</span></summary><table><tbody>';
        foreach ($set['files'] as $f) {
            $isDb = strpos($f['name'], 'db-') === 0;
            echo '<tr><td>' . ($isDb ? 'База' : 'Сайт') . '</td><td class="mono">' . e($f['name']) . '</td><td>' . e(fmt_bytes((float) $f['size'])) . '</td><td class="right">'
                . '<a class="btn" href="?p=bdownload&amp;id=' . e($set['id']) . '&amp;f=' . e(rawurlencode($f['name'])) . '">Скачать</a>'
                . ($isDb ? ' ' . action_btn('', 'db_restore', 'auto:' . $set['id'] . '/' . $f['name'], 'Восстановить', 'Восстановить базу из копии',
                    'Содержимое базы будет заменено данными из копии от ' . date('d.m.Y H:i', (int) $set['time']) . '. Текущее состояние перед этим сохранится отдельным дампом.') : '') . '</td></tr>';
        }
        echo '</tbody></table><p style="margin-top:14px">' . action_btn('danger', 'backup_delete', $set['id'], 'Удалить копию', 'Удалить резервную копию',
            'Копия от ' . date('d.m.Y H:i', (int) $set['time']) . ' будет удалена с сервера. Восстановить её будет нельзя.') . '</p></details>';
    }
    layout('Резервные копии', 'backups', (string) ob_get_clean(), 'Ежедневные копии файлов сайтов и баз данных. Копии лежат на этом же сервере, важные скачивайте к себе. <a href="?p=help#backups">Как это работает</a>');
}

function download_backup(string $id, string $file): void
{
    $res = helper_json(['backups']);
    foreach ($res['data']['sets'] ?? [] as $set) {
        foreach ($set['id'] === $id ? $set['files'] : [] as $f) {
            if ($f['name'] === $file) {
                session_write_close();
                @set_time_limit(0);
                header('Content-Type: application/gzip');
                header('Content-Length: ' . (int) $f['size']);
                header('Content-Disposition: attachment; filename="' . $id . '-' . $file . '"');
                helper_stream(['backup-read', $id, $file]);
                return;
            }
        }
    }
    http_response_code(404);
    layout('Файл не найден', 'backups', '<p class="alert">Такого файла в резервных копиях нет.</p>');
}

/* ---------- Планировщик ---------- */

function page_cron(): void
{
    $result = null;
    $op = form_post();
    if ($op === '!csrf') {
        $result = [1, 'Сессия устарела, обновите страницу'];
    } elseif ($op === 'add') {
        $result = helper(['cron-add'], (string) json_encode(['schedule' => (string) ($_POST['schedule'] ?? ''), 'user' => (string) ($_POST['user'] ?? ''),
            'command' => (string) ($_POST['command'] ?? ''), 'comment' => (string) ($_POST['comment'] ?? '')]));
    }
    $res = helper_json(['cron-list']);
    ob_start();
    echo form_result($result);
    if (isset($res['error'])) {
        echo '<p class="alert">' . nl2br(e($res['error'])) . '</p>';
        layout('Планировщик', 'cron', (string) ob_get_clean());
        return;
    }
    $keep = $result && $result[0] !== 0;
    echo '<form class="panel form wide" method="post" action="?p=cron">' . csrf_field() . '<input type="hidden" name="op" value="add"><h2>Новое задание</h2>';
    echo '<div class="inline"><label>Расписание<input name="schedule" id="cron-schedule" required placeholder="*/5 * * * *" class="mono" value="' . e($keep ? ($_POST['schedule'] ?? '') : '') . '"></label>'
        . '<label>Готовые варианты<select data-fill="cron-schedule"><option value="">выбрать…</option><option value="*/5 * * * *">каждые 5 минут</option><option value="0 * * * *">каждый час</option>'
        . '<option value="0 3 * * *">каждый день в 03:00</option><option value="0 9 * * 1">по понедельникам в 09:00</option><option value="0 0 1 * *">первого числа месяца</option></select></label>'
        . '<label>От имени<select name="user">';
    foreach ($res['data']['users'] as $u) {
        echo '<option>' . e($u) . '</option>';
    }
    echo '</select></label></div>';
    echo '<label>Команда<input name="command" required class="mono" placeholder="/usr/bin/php /var/www/site/cron.php" value="' . e($keep ? ($_POST['command'] ?? '') : '') . '"></label>';
    echo '<label>Пометка для себя<input name="comment" maxlength="120" placeholder="Например: рассылка писем" value="' . e($keep ? ($_POST['comment'] ?? '') : '') . '"></label>';
    echo '<div class="form-foot"><span class="muted">Пять полей: минута, час, день месяца, месяц, день недели.</span><button class="btn primary" type="submit">Добавить задание</button></div></form>';

    echo '<section class="panel"><h2>Задания панели</h2>';
    if (!$res['data']['own']) {
        echo '<p class="muted">Заданий пока нет.</p>';
    } else {
        echo '<table><thead><tr><th>Расписание</th><th>От имени</th><th>Команда</th><th></th></tr></thead><tbody>';
        foreach ($res['data']['own'] as $j) {
            echo '<tr><td class="mono">' . e($j['schedule']) . '</td><td>' . e($j['user']) . '</td><td><span class="mono">' . e($j['command']) . '</span>'
                . ($j['comment'] !== '' ? '<div class="muted">' . e($j['comment']) . '</div>' : '') . '</td><td class="right">'
                . action_btn('', 'cron_delete', $j['id'], 'Удалить', 'Удалить задание', 'Задание «' . $j['command'] . '» больше не будет запускаться.') . '</td></tr>';
        }
        echo '</tbody></table>';
    }
    echo '</section>';
    if ($res['data']['other']) {
        echo '<details class="panel"><summary><b>Другие задания на сервере</b> <span class="muted">' . count($res['data']['other']) . ', только просмотр</span></summary><table><tbody>';
        foreach ($res['data']['other'] as $o) {
            echo '<tr><td class="mono muted">' . e($o['source']) . '</td><td class="mono">' . e($o['line']) . '</td></tr>';
        }
        echo '</tbody></table></details>';
    }
    layout('Планировщик', 'cron', (string) ob_get_clean(), 'Задания, которые сервер выполняет по расписанию: обновление данных, рассылки, очистка. <a href="?p=help#cron">Как записывать расписание</a>');
}

/* ---------- FTP ---------- */

function page_ftp(): void
{
    $result = null;
    $op = form_post();
    if ($op === '!csrf') {
        $result = [1, 'Сессия устарела, обновите страницу'];
    } elseif ($op === 'add') {
        $result = helper(['ftp-add'], (string) json_encode(['login' => (string) ($_POST['login'] ?? ''), 'password' => (string) ($_POST['password'] ?? ''), 'domain' => (string) ($_POST['domain'] ?? '')]));
    } elseif ($op === 'passwd') {
        $result = helper(['ftp-passwd'], (string) json_encode(['login' => (string) ($_POST['login'] ?? ''), 'password' => (string) ($_POST['password'] ?? '')]));
    }
    $res = helper_json(['ftp-info']);
    ob_start();
    echo form_result($result);
    if (isset($res['error'])) {
        echo '<p class="alert">' . nl2br(e($res['error'])) . '</p>';
        layout('FTP', 'ftp', (string) ob_get_clean());
        return;
    }
    $d = $res['data'];
    if ($d['server'] !== 'proftpd') {
        echo '<section class="panel"><p>' . ($d['server'] ? 'На сервере установлен FTP-сервер <b>' . e($d['server']) . '</b>. Управление аккаунтами из панели пока поддерживается только для ProFTPD.'
            : 'FTP-сервер на этом сервере не найден. Файлы можно загружать по SFTP, он работает через SSH и не требует настройки.') . '</p></section>';
    } elseif (!$d['confd']) {
        echo '<p class="alert">В настройках ProFTPD не подключён каталог /etc/proftpd/conf.d. Добавьте в proftpd.conf строку <code>Include /etc/proftpd/conf.d/</code> и обновите страницу.</p>';
    } else {
        echo '<form class="panel form" method="post" action="?p=ftp" autocomplete="off">' . csrf_field() . '<input type="hidden" name="op" value="add"><h2>Новый аккаунт</h2>';
        echo '<label>Логин<input name="login" required pattern="[a-z][a-z0-9_\-]{2,31}" placeholder="site_ftp"></label>';
        echo '<label>Пароль<input name="password" type="password" required minlength="10" autocomplete="new-password"><span class="muted">Не короче 10 символов.</span></label>';
        echo '<label>Сайт<select name="domain">';
        foreach ($d['sites'] as $s) {
            echo '<option value="' . e($s['domain']) . '">' . e($s['domain']) . '</option>';
        }
        echo '</select><span class="muted">Аккаунт будет видеть только каталог этого сайта.</span></label><button class="btn primary" type="submit">Создать аккаунт</button></form>';
        echo '<section class="panel"><h2>Аккаунты панели</h2>';
        if (!$d['accounts']) {
            echo '<p class="muted">Аккаунтов пока нет.</p>';
        } else {
            echo '<table><thead><tr><th>Логин</th><th>Каталог</th><th>Новый пароль</th><th></th></tr></thead><tbody>';
            foreach ($d['accounts'] as $a) {
                echo '<tr><td><b>' . e($a['login']) . '</b></td><td class="mono">' . e($a['home']) . '</td><td><form method="post" action="?p=ftp" class="inline" style="margin:0" autocomplete="off">' . csrf_field()
                    . '<input type="hidden" name="op" value="passwd"><input type="hidden" name="login" value="' . e($a['login']) . '"><input name="password" type="password" minlength="10" required aria-label="Новый пароль" autocomplete="new-password" style="width:170px">'
                    . '<button class="btn" type="submit">Сменить</button></form></td><td class="right">'
                    . action_btn('', 'ftp_delete', $a['login'], 'Удалить', 'Удалить FTP-аккаунт', 'Аккаунт ' . $a['login'] . ' потеряет доступ. Файлы сайта не удаляются.') . '</td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '</section>';
    }
    layout('FTP', 'ftp', (string) ob_get_clean(), 'Доступ к файлам сайта по FTP. Каждый аккаунт привязан к одному сайту. <a href="?p=help#ftp">Подробнее</a>');
}

/* ---------- Почта ---------- */

function page_mail(): void
{
    $res = helper_json(['mail-info']);
    ob_start();
    if (isset($res['error'])) {
        echo '<p class="alert">' . nl2br(e($res['error'])) . '</p>';
        layout('Почта', 'mail', (string) ob_get_clean());
        return;
    }
    $d = $res['data'];
    if (!$d['mta']) {
        echo '<section class="panel"><p>Почтовый сервер (Exim или Postfix) на этом сервере не найден.</p></section>';
        layout('Почта', 'mail', (string) ob_get_clean());
        return;
    }
    $q = (int) $d['queue'];
    $qs = $q >= 200 ? ['bad', 'Очередь переполнена'] : ($q >= 30 ? ['warn', 'Очередь растёт'] : ['ok', 'В норме']);
    echo '<div class="stats"><div class="stat"><div class="stat-label">Почтовый сервер</div><div class="stat-value">' . ($d['mta'] === 'exim' ? 'Exim' : 'Postfix') . ($d['dovecot'] ? ' + Dovecot' : '') . '</div></div>'
        . '<div class="stat"><div class="stat-label">Писем в очереди</div><div class="stat-value">' . $q . '</div><span class="badge ' . $qs[0] . '">' . $qs[1] . '</span></div>'
        . '<div class="stat"><div class="stat-label">Замороженных</div><div class="stat-value">' . (int) $d['frozen'] . '</div><div class="muted">сервер не смог их доставить</div></div>'
        . '<div class="stat"><div class="stat-label">Почтовых ящиков</div><div class="stat-value">' . ($d['mailboxes'] === null ? '—' : count($d['mailboxes'])) . '</div></div></div>';
    echo '<section class="panel"><h2>Очередь писем</h2>';
    if ($q === 0) {
        echo '<p class="muted">Очередь пуста, все письма отправлены.</p>';
    } else {
        echo '<pre class="code">' . e(implode("\n", $d['lines'])) . '</pre><p style="margin-top:16px">'
            . action_btn('primary', 'mail_flush', '', 'Отправить очередь', 'Отправить очередь', 'Сервер заново попробует доставить все письма из очереди.');
        if ($d['frozen'] > 0) {
            echo ' ' . action_btn('', 'mail_clear_frozen', '', 'Удалить замороженные', 'Удалить замороженные письма', 'Письма, которые сервер не смог доставить (' . (int) $d['frozen'] . '), будут удалены из очереди без возможности восстановления.');
        }
        echo '</p>';
    }
    echo '</section><section class="panel"><h2>Почтовые ящики</h2>';
    if ($d['mailboxes'] === null) {
        echo '<p class="muted">Список ящиков получить не удалось: Dovecot не установлен или не отдаёт список пользователей.</p>';
    } elseif (!$d['mailboxes']) {
        echo '<p class="muted">Ящиков нет.</p>';
    } else {
        echo '<div class="chips big">';
        foreach ($d['mailboxes'] as $m) {
            echo '<span>' . e($m) . '</span>';
        }
        echo '</div>';
    }
    echo '<p class="muted" style="margin-top:14px">Создание, удаление ящиков и смена паролей появятся в следующих версиях. Существующие ящики работают как раньше.</p></section>';
    layout('Почта', 'mail', (string) ob_get_clean(), 'Состояние почтового сервера, очередь писем и список ящиков. Записи SPF и DMARC проверяются на странице <a href="?p=dns">DNS</a>. <a href="?p=help#mail">Подробнее</a>');
}

/* ---------- DNS ---------- */

function dns_lookup(string $domain): array
{
    $pick = function (int $type, string $key) use ($domain): array {
        $out = [];
        foreach (@dns_get_record($domain, $type) ?: [] as $r) {
            if (isset($r[$key])) {
                $out[] = (string) $r[$key];
            }
        }
        sort($out);
        return $out;
    };
    $spf = false;
    foreach ($pick(DNS_TXT, 'txt') as $t) {
        $spf = $spf || stripos($t, 'v=spf1') === 0;
    }
    $dmarc = false;
    foreach (@dns_get_record('_dmarc.' . $domain, DNS_TXT) ?: [] as $r) {
        $dmarc = $dmarc || stripos((string) ($r['txt'] ?? ''), 'v=DMARC1') === 0;
    }
    return ['a' => $pick(DNS_A, 'ip'), 'ns' => $pick(DNS_NS, 'target'), 'mx' => $pick(DNS_MX, 'target'), 'spf' => $spf, 'dmarc' => $dmarc];
}

function page_dns(): void
{
    $sites = helper_json(['sites']);
    ob_start();
    if (isset($sites['error'])) {
        echo '<p class="alert">' . nl2br(e($sites['error'])) . '</p>';
        layout('DNS', 'dns', (string) ob_get_clean());
        return;
    }
    $file = cfg()['data_dir'] . '/cache-dns.json';
    if (isset($_GET['refresh'])) {
        @unlink($file);
    }
    $data = (is_file($file) && time() - filemtime($file) < 900) ? json_decode((string) file_get_contents($file), true) : null;
    if (!is_array($data)) {
        @set_time_limit(180);
        session_write_close();
        $data = [];
        foreach ($sites['data'] as $s) {
            $data[$s['domain']] = dns_lookup($s['domain']);
        }
        @file_put_contents($file, json_encode($data), LOCK_EX);
    }
    $count = [];
    foreach ($data as $r) {
        foreach ($r['a'] as $ip) {
            $count[$ip] = ($count[$ip] ?? 0) + 1;
        }
    }
    arsort($count);
    $main = (string) (array_key_first($count) ?? '');
    echo '<div class="toolbar"><span class="muted grow">Проверено: ' . date('d.m.Y H:i', is_file($file) ? (int) filemtime($file) : time())
        . ($main !== '' ? '. Основной адрес ваших доменов: <b>' . e($main) . '</b>' : '') . '</span><a class="btn" href="?p=dns&amp;refresh=1">Проверить заново</a></div>';
    echo '<section class="panel"><table><thead><tr><th>Домен</th><th>Адрес (A)</th><th>Серверы имён</th><th>Почта (MX)</th><th>SPF</th><th>DMARC</th></tr></thead><tbody>';
    foreach ($data as $domain => $r) {
        if (!$r['a']) {
            $st = '<span class="badge bad">Не найден</span>';
        } elseif ($main !== '' && !in_array($main, $r['a'], true)) {
            $st = '<span class="mono">' . e(implode(', ', $r['a'])) . '</span> <span class="badge warn">Другой адрес</span>';
        } else {
            $st = '<span class="mono">' . e(implode(', ', $r['a'])) . '</span>';
        }
        $yn = function (bool $v, bool $need): string {
            return $v ? '<span class="badge ok">Есть</span>' : ($need ? '<span class="badge warn">Нет</span>' : '<span class="muted">—</span>');
        };
        echo '<tr><td><b>' . e($domain) . '</b></td><td>' . $st . '</td><td class="mono">' . e(implode(', ', array_slice($r['ns'], 0, 2)) ?: '—') . '</td><td class="mono">'
            . e(implode(', ', array_slice($r['mx'], 0, 2)) ?: '—') . '</td><td>' . $yn($r['spf'], (bool) $r['mx']) . '</td><td>' . $yn($r['dmarc'], (bool) $r['mx']) . '</td></tr>';
    }
    echo '</tbody></table></section>';
    layout('DNS', 'dns', (string) ob_get_clean(), 'Как ваши домены видны из интернета. Сами записи меняются у регистратора домена, панель их проверяет. <a href="?p=help#dnshelp">Что означают столбцы</a>');
}
