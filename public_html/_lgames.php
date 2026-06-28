<?php
// ВРЕМЕННЫЙ эндпоинт: приём поигровых данных с mafiauniverse (POST ?id=<файл>)
// и запуск импорта вечеров + пересчёта ELO (GET ?run=1). Удаляется после использования.
declare(strict_types=1);
require dirname(__DIR__) . '/inc/bootstrap.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    exit;
}

if (!hash_equals('lgm_5kQ2x9aZ', (string)($_GET['t'] ?? ''))) {
    http_response_code(403);
    exit('forbidden');
}

header('Content-Type: text/plain; charset=utf-8');

if (!empty($_GET['run'])) {
    require_once ROOT . '/inc/legacy_import.php';
    echo implode("\n", legacy_days_import_run()) . "\n";
    foreach (db()->query("SELECT season, COUNT(DISTINCT d.id) days, COUNT(g.id) games
        FROM game_days d LEFT JOIN games g ON g.day_id = d.id
        WHERE d.season IS NOT NULL GROUP BY season ORDER BY season") as $r) {
        echo "  {$r['season']}: {$r['days']} вечеров, {$r['games']} игр\n";
    }
    echo 'игроков с ELO≠1000: ' . (int)db()->query('SELECT COUNT(*) FROM players WHERE ROUND(elo) <> 1000')->fetchColumn() . "\n";
    echo 'игровых вечеров всего: ' . (int)db()->query('SELECT COUNT(*) FROM game_days')->fetchColumn() . "\n";
    exit;
}

$id = preg_replace('/[^a-z0-9_]/i', '', (string)($_GET['id'] ?? ''));
if ($id === '') {
    http_response_code(400);
    exit('no id');
}
$dir = ROOT . '/storage/legacy';
if (!is_dir($dir)) {
    mkdir($dir, 0775, true);
}
$body = (string)file_get_contents('php://input');
$data = json_decode($body, true);
if (!is_array($data)) {
    http_response_code(400);
    exit('bad json');
}
file_put_contents("$dir/games_$id.json", $body);
echo 'ok ' . $id . ' bytes=' . strlen($body) . ' games=' . count($data);
