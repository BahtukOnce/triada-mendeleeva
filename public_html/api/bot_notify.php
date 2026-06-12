<?php
// Рассылка сообщения всем привязанным к боту игрокам.
// GET/POST: key=<deploy_secret>, text=<сообщение, HTML-разметка Telegram>
declare(strict_types=1);

define('ROOT', dirname(__DIR__, 2));
$cfgFile = ROOT . '/config.php';
if (!is_file($cfgFile)) {
    http_response_code(503);
    exit('config missing');
}
$GLOBALS['cfg'] = require $cfgFile;

$key = (string)($_REQUEST['key'] ?? '');
if ($key === '' || !hash_equals((string)($GLOBALS['cfg']['deploy_secret'] ?? ''), $key)) {
    http_response_code(403);
    exit('forbidden');
}

require ROOT . '/inc/db.php';
require ROOT . '/inc/bot_lib.php';
header('Content-Type: application/json; charset=utf-8');

if (bot_token() === '') {
    echo json_encode(['ok' => false, 'error' => 'bot_token не задан в config.php'], JSON_UNESCAPED_UNICODE);
    exit;
}

$text = trim((string)($_REQUEST['text'] ?? ''));
if ($text === '') {
    echo json_encode(['ok' => false, 'error' => 'пустой text'], JSON_UNESCAPED_UNICODE);
    exit;
}

set_time_limit(0);
$res = bot_broadcast($text);
echo json_encode(['ok' => true] + $res, JSON_UNESCAPED_UNICODE);
