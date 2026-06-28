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
