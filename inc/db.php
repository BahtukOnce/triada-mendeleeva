<?php
declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $c = $GLOBALS['cfg']['db'];
        $pdo = new PDO(
            "mysql:host={$c['host']};dbname={$c['name']};charset=utf8mb4",
            $c['user'],
            $c['pass'],
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
    }
    return $pdo;
}

// База доступна и проинициализирована миграциями
function db_ready(): bool
{
    static $ready = null;
    if ($ready === null) {
        try {
            db()->query('SELECT 1 FROM _migrations LIMIT 1');
            $ready = true;
        } catch (Throwable $e) {
            $ready = false;
        }
    }
    return $ready;
}

function run_migrations(): array
{
    $log = [];
    $pdo = db();
    $pdo->exec('CREATE TABLE IF NOT EXISTS _migrations (
        id VARCHAR(64) PRIMARY KEY,
        applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    $done = $pdo->query('SELECT id FROM _migrations')->fetchAll(PDO::FETCH_COLUMN);
    $files = glob(ROOT . '/db/migrations/*.sql');
    sort($files);
    foreach ($files as $f) {
        $id = basename($f);
        if (in_array($id, $done, true)) {
            continue;
        }
        $sql = (string)file_get_contents($f);
        $stmts = preg_split('/;\s*(?:\r?\n|$)/', $sql);
        foreach ($stmts as $stmt) {
            $stmt = trim($stmt);
            if ($stmt !== '' && !str_starts_with($stmt, '--')) {
                $pdo->exec($stmt);
            }
        }
        $pdo->prepare('INSERT INTO _migrations (id) VALUES (?)')->execute([$id]);
        $log[] = "applied: $id";
    }
    if (!$log) {
        $log[] = 'nothing to apply';
    }
    return $log;
}
