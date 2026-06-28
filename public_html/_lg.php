<?php
// ВРЕМЕННЫЙ триггер переимпорта исторических рейтингов/турниров. Удаляется сразу после запуска.
declare(strict_types=1);
require dirname(__DIR__) . '/inc/bootstrap.php';

if (!hash_equals('lg_8f3kq9wzx274bvn6', (string)($_GET['t'] ?? ''))) {
    http_response_code(403);
    exit('forbidden');
}

header('Content-Type: text/plain; charset=utf-8');
require_once ROOT . '/inc/legacy_import.php';
echo implode("\n", legacy_import_run()) . "\n";

// контроль: турниры с привязкой к frozen-рейтингу
foreach (db()->query("SELECT t.id, t.title, t.legacy_rating_id,
        (SELECT COUNT(*) FROM rating_cache rc WHERE rc.rating_id = t.legacy_rating_id) AS cnt
    FROM tournaments t WHERE t.legacy_rating_id IS NOT NULL ORDER BY t.id") as $r) {
    echo "турнир #{$r['id']} «{$r['title']}» → рейтинг #{$r['legacy_rating_id']}, игроков {$r['cnt']}\n";
}
echo 'активных рейтингов (переключатель): '
    . (int)db()->query('SELECT COUNT(*) FROM ratings WHERE is_active = 1')->fetchColumn() . "\n";
