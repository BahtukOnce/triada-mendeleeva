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
$logFile = ROOT . '/import.log';

if ($mode === 'log') {
    echo is_file($logFile) ? file_get_contents($logFile) : 'лога нет';
    exit;
}

file_put_contents($logFile, date('H:i:s') . " start mode=$mode\n");
register_shutdown_function(function () use ($logFile) {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        file_put_contents($logFile, date('H:i:s') . " FATAL: {$e['message']} @ {$e['file']}:{$e['line']}"
            . ' mem=' . round(memory_get_peak_usage(true) / 1048576) . "M\n", FILE_APPEND);
    } else {
        file_put_contents($logFile, date('H:i:s') . ' shutdown ok, peak mem='
            . round(memory_get_peak_usage(true) / 1048576) . "M\n", FILE_APPEND);
    }
});

try {
    $log = run_import($mode === 'run', function (string $msg) use ($logFile) {
        file_put_contents($logFile, date('H:i:s') . ' ' . $msg . "\n", FILE_APPEND);
    });
    echo implode("\n", $log) . "\n";
} catch (Throwable $e) {
    http_response_code(500);
    $err = 'ОШИБКА: ' . $e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine();
    file_put_contents($logFile, $err . "\n", FILE_APPEND);
    echo $err . "\n";
}
