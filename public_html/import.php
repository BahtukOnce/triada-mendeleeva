<?php
// Импорт истории из Google-таблиц: /import.php?key=<deploy_secret>&mode=dry|run
declare(strict_types=1);

define('ROOT', dirname(__DIR__));
set_time_limit(300);
ini_set('memory_limit', '512M');

$cfgFile = ROOT . '/config.php';
if (!is_file($cfgFile)) {
    http_response_code(503);
    exit('config.php missing');
}
$GLOBALS['cfg'] = require $cfgFile;

$key = (string)($_GET['key'] ?? '');
if ($key === '' || !hash_equals((string)$GLOBALS['cfg']['deploy_secret'], $key)) {
    http_response_code(403);
    exit('forbidden');
}

header('Content-Type: text/plain; charset=utf-8');
require ROOT . '/inc/db.php';
require ROOT . '/inc/xlsx.php';
require ROOT . '/inc/rating.php';
require ROOT . '/inc/import.php';

$mode = (string)($_GET['mode'] ?? 'dry');

try {
    $log = run_import($mode === 'run');
    echo implode("\n", $log) . "\n";
} catch (Throwable $e) {
    http_response_code(500);
    echo 'ОШИБКА: ' . $e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine() . "\n";
}
