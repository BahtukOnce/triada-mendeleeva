<?php
declare(strict_types=1);
// ВРЕМЕННЫЙ превью шэрибл-карточки (HMAC-заголовок как у deploy.php). Удаляется после проверки.
require dirname(__DIR__) . '/inc/bootstrap.php';
require_once ROOT . '/inc/day_card.php';
$secret = (string)(cfg('deploy_secret') ?? '');
$raw = (string)file_get_contents('php://input');
$sig = (string)($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '');
$expected = 'sha256=' . hash_hmac('sha256', $raw, $secret);
if ($secret === '' || !hash_equals($expected, $sig)) {
    http_response_code(403);
    exit('forbidden');
}
if (!day_card_available()) {
    header('Content-Type: text/plain; charset=utf-8');
    exit("GD/шрифт недоступны: gd=" . (function_exists('imagecreatetruecolor') ? 'да' : 'НЕТ')
        . " ttf=" . (function_exists('imagettftext') ? 'да' : 'НЕТ')
        . " font=" . (is_file(day_card_font()) ? 'да' : 'НЕТ') . "\n");
}
$png = day_card_png([
    'nickname' => 'Бант.',
    'avatar' => null,
    'day_title' => '11 июля',
    'day_date' => '11 июля',
    'games' => 4,
    'wins' => 3,
    'roles' => ['Мирный' => 2, 'Мафия' => 1, 'Шериф' => 1],
    'net' => 34.5,
    'elo' => 2618,
    'record' => true,
    'top' => true,
]);
if ($png === null) {
    header('Content-Type: text/plain; charset=utf-8');
    exit("генерация вернула null\n");
}
header('Content-Type: image/png');
readfile($png);
@unlink($png);
