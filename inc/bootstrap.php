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

// Стилизованная страница ошибки — самодостаточная (без БД/шаблонов, работает даже когда
// сломано пол-сайта): встроенные стили, тёмная тема, контакт руководителя в Telegram.
/**
 * Telegram текущего руководителя клуба (owner) — «подтягивается сам» из привязанного
 * игрока. Используется в страницах ошибок и контактах. Обёрнуто в try/catch: если упала
 * как раз БД, страница ошибки всё равно нарисуется (fallback на config → дефолт).
 */
function club_contact_tg(): string
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $tg = '';
    try {
        if (function_exists('db_ready') && db_ready()) {
            $st = db()->query("SELECT p.tg FROM users u JOIN players p ON p.user_id = u.id
                WHERE u.role = 'owner' AND p.tg IS NOT NULL AND TRIM(p.tg) <> ''
                ORDER BY u.id LIMIT 1");
            $tg = (string)($st->fetchColumn() ?: '');
        }
    } catch (Throwable $e) {
        // БД недоступна (возможно, это и есть причина ошибки) — молча падаем на fallback
    }
    if ($tg === '') {
        $tg = (string)($GLOBALS['cfg']['contact_tg'] ?? 'triada_mendeleeva');
    }
    return $cached = ltrim(trim($tg), '@');
}

function render_error_page(int $code, string $title, string $msg): void
{
    if (!empty($GLOBALS['err_page_rendered'])) {
        return; // не рисуем дважды (исключение + shutdown)
    }
    $GLOBALS['err_page_rendered'] = true;
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: text/html; charset=utf-8');
    }
    $tg = club_contact_tg();
    $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $e($title) . '</title>'
        . '<div style="position:fixed;inset:0;z-index:2147483000;background:#0e0e11;color:#e9e9ee;'
        . 'font:16px/1.5 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;display:flex;align-items:center;'
        . 'justify-content:center;padding:22px;box-sizing:border-box;">'
        . '<div style="max-width:460px;width:100%;text-align:center;background:#17171c;border:1px solid #2b2b33;'
        . 'border-radius:18px;padding:36px 26px;box-shadow:0 12px 40px rgba(0,0,0,.5);">'
        . '<div style="font-size:56px;line-height:1;margin-bottom:14px;">🌙</div>'
        . '<div style="font-size:23px;font-weight:800;margin-bottom:10px;">' . $e($title) . '</div>'
        . '<div style="color:#9c9ca6;font-size:15px;margin-bottom:22px;">' . $e($msg) . '</div>'
        . '<div style="color:#9c9ca6;font-size:14px;margin-bottom:14px;">Что-то срочное или не работает?<br>Напишите руководителю клуба в Telegram:</div>'
        . '<a href="https://t.me/' . $e($tg) . '" target="_blank" rel="noopener" '
        . 'style="display:inline-block;background:#e8332a;color:#fff;text-decoration:none;font-weight:700;'
        . 'padding:12px 24px;border-radius:11px;font-size:15px;">✈️ Написать @' . $e($tg) . '</a>'
        . '<div style="margin-top:20px;"><a href="/" style="color:#8a8a93;text-decoration:none;font-size:14px;">← Вернуться на главную</a></div>'
        . '<div style="margin-top:24px;color:#5a5a63;font-size:12px;">Триада Менделеева · клуб спортивной мафии</div>'
        . '</div></div>';
}

set_exception_handler(function (Throwable $e): void {
    app_log_error(get_class($e), $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString());
    if (cfg('env', 'test') !== 'prod') {
        if (!headers_sent()) {
            http_response_code(500);
        }
        echo '<pre style="white-space:pre-wrap;padding:16px;">' . htmlspecialchars((string)$e, ENT_QUOTES) . '</pre>';
    } else {
        render_error_page(500, 'Что-то пошло не так', 'Мы уже зафиксировали ошибку и скоро её починим.');
    }
});

register_shutdown_function(function (): void {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        app_log_error('FATAL', (string)$err['message'], (string)$err['file'], (int)$err['line']);
        if (cfg('env', 'test') === 'prod') {
            render_error_page(500, 'Что-то пошло не так', 'Мы уже зафиксировали ошибку и скоро её починим.');
        }
    }
});

require ROOT . '/inc/db.php';
require ROOT . '/inc/auth.php';
require ROOT . '/inc/layout.php';
