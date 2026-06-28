<?php
// ВРЕМЕННЫЙ приёмник скрейпа исторических рейтингов с mafiauniverse.
// Кладёт присланный JSON в storage/legacy/<src>.json. Гейт — одноразовый токен.
// УДАЛИТЬ после импорта.
require dirname(__DIR__, 2) . '/inc/bootstrap.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

const LEGACY_TOKEN = 'lg_b9f24e7a1c83d6';
if (!hash_equals(LEGACY_TOKEN, (string)($_GET['t'] ?? ''))) {
    http_response_code(403);
    exit('forbidden');
}
if (($_GET['apply'] ?? '') === '1') {
    require_once ROOT . '/inc/legacy_import.php';
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['log' => legacy_import_run()], JSON_UNESCAPED_UNICODE);
    exit;
}
$src = preg_replace('/[^a-z0-9_]/i', '', (string)($_GET['src'] ?? ''));
if ($src === '') {
    http_response_code(400);
    exit('no src');
}
$body = file_get_contents('php://input');
if (strlen($body) < 2 || strlen($body) > 4_000_000) {
    http_response_code(400);
    exit('bad body');
}
$dec = json_decode($body, true);
if (!is_array($dec)) {
    http_response_code(400);
    exit('bad json');
}
$dir = ROOT . '/storage/legacy';
if (!is_dir($dir)) {
    @mkdir($dir, 0775, true);
}
file_put_contents("$dir/$src.json", $body);

header('Content-Type: application/json');
echo json_encode(['ok' => true, 'src' => $src, 'bytes' => strlen($body), 'players' => count($dec['players'] ?? $dec)]);
