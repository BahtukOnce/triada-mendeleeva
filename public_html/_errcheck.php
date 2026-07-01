<?php
declare(strict_types=1);
// ВРЕМЕННЫЙ диагностический эндпоинт — хвост storage/error.log.
// Аутентификация: HMAC-SHA256 тела запроса ключом deploy_secret (заголовок
// X-Hub-Signature-256), как у deploy.php — сам секрет по сети не передаётся.
// Удаляется сразу после проверки.
require dirname(__DIR__) . '/inc/bootstrap.php';
$secret = (string)(cfg('deploy_secret') ?? '');
$raw = (string)file_get_contents('php://input');
$sig = (string)($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '');
$expected = 'sha256=' . hash_hmac('sha256', $raw, $secret);
if ($secret === '' || !hash_equals($expected, $sig)) {
    http_response_code(403);
    exit('forbidden');
}
header('Content-Type: text/plain; charset=utf-8');
$f = ROOT . '/storage/error.log';
if (!is_file($f)) {
    echo "нет файла storage/error.log — фатальных ошибок не логировалось\n";
    exit;
}
$size = filesize($f);
echo "size={$size} bytes\n===== tail =====\n";
$fp = fopen($f, 'rb');
$max = 60 * 1024;
if ($size > $max) {
    fseek($fp, -$max, SEEK_END);
    fgets($fp);
}
echo stream_get_contents($fp);
fclose($fp);
