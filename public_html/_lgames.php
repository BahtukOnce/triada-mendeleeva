<?php
// ВРЕМЕННЫЙ эндпоинт: переимпорт турниров + пересчёт ELO (GET ?run=tours).
// Удаляется после использования.
declare(strict_types=1);
require dirname(__DIR__) . '/inc/bootstrap.php';
header('Content-Type: text/plain; charset=utf-8');
if (!hash_equals('lgm_5kQ2x9aZ', (string)($_GET['t'] ?? ''))) {
    http_response_code(403);
    exit('forbidden');
}
if (!empty($_GET['migrate'])) {
    $pdo = db();
    $done = $pdo->query('SELECT id FROM _migrations')->fetchAll(PDO::FETCH_COLUMN);
    echo "050 в _migrations: " . (in_array('050_seat_score_precision.sql', $done, true) ? 'ДА' : 'НЕТ') . "\n";
    try {
        $pdo->exec('ALTER TABLE game_seats MODIFY plus DECIMAL(5,2) NOT NULL DEFAULT 0, MODIFY minus DECIMAL(5,2) NOT NULL DEFAULT 0');
        echo "ALTER выполнен успешно\n";
        $pdo->prepare('INSERT IGNORE INTO _migrations (id) VALUES (?)')->execute(['050_seat_score_precision.sql']);
    } catch (Throwable $e) {
        echo "ALTER ОШИБКА: " . $e->getMessage() . "\n";
    }
    foreach ($pdo->query("SELECT COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_NAME='game_seats' AND COLUMN_NAME IN ('plus','minus')") as $r) {
        echo "  {$r['COLUMN_NAME']}: {$r['COLUMN_TYPE']}\n";
    }
    exit;
}
if (!empty($_GET['run'])) {
    require_once ROOT . '/inc/legacy_import.php';
    echo implode("\n", legacy_tour_import_run()) . "\n";
    foreach (db()->query("SELECT t.id, t.title, COUNT(DISTINCT gs.player_id) players, COUNT(DISTINCT g.id) games
        FROM tournaments t LEFT JOIN games g ON g.tournament_id = t.id
        LEFT JOIN game_seats gs ON gs.game_id = g.id
        WHERE t.legacy_rating_id IS NULL GROUP BY t.id HAVING games > 0 ORDER BY t.date_from") as $r) {
        echo "  #{$r['id']} {$r['title']}: игр {$r['games']}, игроков {$r['players']}\n";
    }
    exit;
}
exit('ok');
