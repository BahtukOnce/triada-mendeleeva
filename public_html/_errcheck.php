<?php
declare(strict_types=1);
// ВРЕМЕННЫЙ диагностический эндпоинт: чтение хвоста error.log и установка чекпоинта.
// Аутентификация: HMAC-SHA256 тела запроса ключом deploy_secret (X-Hub-Signature-256),
// как у deploy.php — секрет по сети не передаётся. Удаляется после проверки.
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
if (trim($raw) === 'checkpoint') {
    setting_set('errors_checkpoint', date('Y-m-d H:i:s'));
    exit("checkpoint set: " . date('Y-m-d H:i:s') . "\n");
}
$f = ROOT . '/storage/error.log';
if (!is_file($f)) {
    echo "нет файла storage/error.log\n";
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
