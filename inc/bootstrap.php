<?php
declare(strict_types=1);

mb_internal_encoding('UTF-8');
date_default_timezone_set('Europe/Moscow');

define('ROOT', dirname(__DIR__));

$configFile = ROOT . '/config.php';
if (!is_file($configFile)) {
    require ROOT . '/inc/setup_required.php';
    exit;
}
$GLOBALS['cfg'] = require $configFile;

session_name('triada_sess');
// На Beget TLS обрывается на прокси, поэтому $_SERVER['HTTPS'] часто пуст —
// тогда флаг Secure не ставился и куку сессии можно было перехватить по HTTP.
// Дополнительно смотрим заголовки прокси (X-Forwarded-Proto / -SSL) и порт 443.
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
    || (strtolower((string)($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '')) === 'on')
    || ((string)($_SERVER['SERVER_PORT'] ?? '') === '443');
session_set_cookie_params([
    'lifetime' => 60 * 60 * 24 * 30,
    'path'     => '/',
    'secure'   => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

require ROOT . '/inc/helpers.php';

// ── Логирование ошибок ───────────────────────────────────
// Пишем непойманные исключения и фатальные ошибки в storage/error.log
// (вне public_html — недоступно из веба). Просмотр: /admin/errors.php
function app_log_error(string $kind, string $msg, string $file, int $line, string $trace = ''): void
{
    $dir = ROOT . '/storage';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $where = ($_SERVER['REQUEST_METHOD'] ?? 'CLI') . ' ' . ($_SERVER['REQUEST_URI'] ?? '-');
    $entry = '[' . date('Y-m-d H:i:s') . "] $kind: $msg in $file:$line | $where\n"
        . ($trace !== '' ? $trace . "\n" : '') . "\n";
    @file_put_contents($dir . '/error.log', $entry, FILE_APPEND | LOCK_EX);
}

set_exception_handler(function (Throwable $e): void {
    app_log_error(get_class($e), $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString());
    if (!headers_sent()) {
        http_response_code(500);
    }
    if (cfg('env', 'test') !== 'prod') {
        echo '<pre style="white-space:pre-wrap;padding:16px;">' . htmlspecialchars((string)$e, ENT_QUOTES) . '</pre>';
    } else {
        echo 'Что-то пошло не так. Мы уже зафиксировали ошибку и скоро починим.';
    }
});

register_shutdown_function(function (): void {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        app_log_error('FATAL', (string)$err['message'], (string)$err['file'], (int)$err['line']);
    }
});

require ROOT . '/inc/db.php';
require ROOT . '/inc/auth.php';
require ROOT . '/inc/layout.php';
