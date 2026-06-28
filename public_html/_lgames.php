<?php
// ВРЕМЕННЫЙ эндпоинт: приём поигровых данных Летнего кубка (POST ?id=letkubok)
// и переимпорт турниров + пересчёт ELO (GET ?run=tours). Удаляется после.
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
    echo implode("\n", legacy_tour_import_run()) . "\n";
    foreach (db()->query("SELECT t.title, COUNT(g.id) games, t.date_from
        FROM tournaments t LEFT JOIN games g ON g.tournament_id = t.id
        WHERE t.legacy_rating_id IS NULL GROUP BY t.id HAVING games > 0 ORDER BY t.date_from") as $r) {
        echo "  {$r['title']}: игр {$r['games']}, дата {$r['date_from']}\n";
    }
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
