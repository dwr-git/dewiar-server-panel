<?php
// Маршрутизатор встроенного сервера PHP: статика из /assets, всё остальное — в index.php
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if (strpos($path, '/assets/') === 0 && strpos($path, '..') === false && is_file(__DIR__ . $path)) {
    return false;
}
require __DIR__ . '/index.php';
