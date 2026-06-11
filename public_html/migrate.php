<?php
// Ручной запуск миграций: /migrate.php?key=<deploy_secret>
declare(strict_types=1);

define('ROOT', dirname(__DIR__));

$cfgFile = ROOT . '/config.php';
if (!is_file($cfgFile)) {
    http_response_code(503);
    exit('config.php missing');
}
$GLOBALS['cfg'] = require $cfgFile;

$key = (string)($_GET['key'] ?? '');
if ($key === '' || !hash_equals((string)$GLOBALS['cfg']['deploy_secret'], $key)) {
    http_response_code(403);
    exit('forbidden');
}

header('Content-Type: text/plain; charset=utf-8');
require ROOT . '/inc/db.php';

try {
    $log = run_migrations();
    echo "ok\n" . implode("\n", $log) . "\n";
    echo 'db: ' . $GLOBALS['cfg']['db']['name'] . "\n";
    echo 'applied: ' . implode(', ', db()->query('SELECT id FROM _migrations ORDER BY id')->fetchAll(PDO::FETCH_COLUMN)) . "\n";
    echo 'ratings rows: ' . (int)db()->query('SELECT COUNT(*) FROM ratings')->fetchColumn() . "\n";

    // Расчёт ELO: при первом запуске или принудительно ?elo=1
    if (is_file(ROOT . '/inc/elo.php')) {
        $hasElo = (int)db()->query('SELECT COUNT(*) FROM elo_history')->fetchColumn();
        $hasGames = (int)db()->query("SELECT COUNT(*) FROM games WHERE status='finished' AND winner IS NOT NULL")->fetchColumn();
        if (($hasElo === 0 || !empty($_GET['elo'])) && $hasGames > 0) {
            require ROOT . '/inc/elo.php';
            elo_recompute();
            echo 'ELO рассчитан для ' . $hasGames . " игр\n";
        }
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo 'error: ' . $e->getMessage() . "\n";
}
