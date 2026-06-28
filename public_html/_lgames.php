<?php
// ВРЕМЕННЫЙ эндпоинт: переимпорт сезонов (?run=days) и турниров (?run=tours)
// + пересчёт ELO. Удаляется после использования.
declare(strict_types=1);
require dirname(__DIR__) . '/inc/bootstrap.php';
header('Content-Type: text/plain; charset=utf-8');
if (!hash_equals('lgm_5kQ2x9aZ', (string)($_GET['t'] ?? ''))) {
    http_response_code(403);
    exit('forbidden');
}
require_once ROOT . '/inc/legacy_import.php';
$what = (string)($_GET['run'] ?? '');
if ($what === 'days') {
    echo implode("\n", legacy_days_import_run()) . "\n";
    foreach (db()->query("SELECT d.season, COUNT(DISTINCT d.id) days, COUNT(DISTINCT g.id) games,
        MIN(d.date) mn, MAX(d.date) mx FROM game_days d JOIN games g ON g.day_id=d.id
        WHERE d.season IS NOT NULL GROUP BY d.season ORDER BY d.season") as $r) {
        echo "  {$r['season']}: дней {$r['days']}, игр {$r['games']}, {$r['mn']}…{$r['mx']}\n";
    }
    exit;
}
if ($what === 'tours') {
    echo implode("\n", legacy_tour_import_run()) . "\n";
    foreach (db()->query("SELECT t.id, t.title, t.date_from FROM tournaments t
        WHERE t.legacy_rating_id IS NULL AND t.title LIKE '%Летний кубок%' ORDER BY t.id DESC LIMIT 1") as $r) {
        echo "  #{$r['id']} {$r['title']}: дата {$r['date_from']}\n";
    }
    exit;
}
exit('ok');
