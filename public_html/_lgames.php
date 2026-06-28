<?php
// ВРЕМЕННЫЙ эндпоинт: переимпорт истории с исправленными баллами + починка дат новостей.
declare(strict_types=1);
require dirname(__DIR__) . '/inc/bootstrap.php';

if (!hash_equals('lgm_5kQ2x9aZ', (string)($_GET['t'] ?? ''))) {
    http_response_code(403);
    exit('forbidden');
}
header('Content-Type: text/plain; charset=utf-8');

if (!empty($_GET['run'])) {
    require_once ROOT . '/inc/legacy_import.php';
    echo "== Вечера (сезоны) ==\n" . implode("\n", legacy_days_import_run()) . "\n\n";
    echo "== Турниры ==\n" . implode("\n", legacy_tour_import_run()) . "\n\n";
    // починка дат новостей (1970 → время создания)
    $fixed = db()->exec("UPDATE news SET published_at = created_at
        WHERE published_at IS NOT NULL AND published_at < '2005-01-01' AND created_at IS NOT NULL");
    echo "Новости: исправлено дат = " . (int)$fixed . "\n";
    foreach (db()->query("SELECT published_at FROM news WHERE published_at IS NOT NULL ORDER BY published_at DESC LIMIT 5") as $r) {
        echo "  " . $r['published_at'] . "\n";
    }
    exit;
}

http_response_code(400);
echo 'no run';
