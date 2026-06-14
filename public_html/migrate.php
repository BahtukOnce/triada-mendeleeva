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

    // Разовая чистка существующих ников от эмодзи: ?cleannicks=1
    // Эмодзи уходит в «висюльку» (flair), если она пуста. Коллизии (когда чистый
    // ник уже занят другим игроком) пропускаются — их нужно слить вручную.
    if (!empty($_GET['cleannicks'])) {
        require_once ROOT . '/inc/helpers.php';
        $players = db()->query('SELECT id, nickname, flair FROM players')->fetchAll();
        $byNick = [];
        foreach ($players as $p) {
            $byNick[mb_strtolower($p['nickname'])][] = (int)$p['id'];
        }
        $changed = 0; $skipped = [];
        foreach ($players as $p) {
            $clean = nickname_clean((string)$p['nickname']);
            if ($clean === '' || $clean === $p['nickname']) {
                continue;
            }
            $low = mb_strtolower($clean);
            $collide = false;
            foreach ($byNick[$low] ?? [] as $oid) {
                if ($oid !== (int)$p['id']) { $collide = true; break; }
            }
            if ($collide) { $skipped[] = $p['nickname']; continue; }
            $emoji = flair_clean((string)$p['nickname']);
            $flair = ($p['flair'] === null || $p['flair'] === '') ? ($emoji ?: null) : $p['flair'];
            db()->prepare('UPDATE players SET nickname = ?, flair = ? WHERE id = ?')->execute([$clean, $flair, (int)$p['id']]);
            $byNick[$low][] = (int)$p['id'];
            $changed++;
        }
        echo 'ники очищены от эмодзи: ' . $changed . "\n";
        if ($skipped) {
            echo 'пропущены (коллизия — слейте вручную в Админка→Слияние): ' . implode(', ', $skipped) . "\n";
        }

        // Ник аккаунта (users) — показывается в шапке и блоке «Администрация»,
        // и по нему идёт вход. Чистим теми же правилами, коллизии пропускаем.
        $users = db()->query('SELECT id, nickname FROM users')->fetchAll();
        $uByNick = [];
        foreach ($users as $usr) {
            $uByNick[mb_strtolower($usr['nickname'])][] = (int)$usr['id'];
        }
        $uChanged = 0; $uSkipped = [];
        foreach ($users as $usr) {
            $clean = nickname_clean((string)$usr['nickname']);
            if ($clean === '' || $clean === $usr['nickname']) {
                continue;
            }
            $low = mb_strtolower($clean);
            $collide = false;
            foreach ($uByNick[$low] ?? [] as $oid) {
                if ($oid !== (int)$usr['id']) { $collide = true; break; }
            }
            if ($collide) { $uSkipped[] = $usr['nickname']; continue; }
            db()->prepare('UPDATE users SET nickname = ? WHERE id = ?')->execute([$clean, (int)$usr['id']]);
            $uByNick[$low][] = (int)$usr['id'];
            $uChanged++;
        }
        echo 'ники аккаунтов очищены: ' . $uChanged . "\n";
        if ($uSkipped) {
            echo 'аккаунты пропущены (коллизия): ' . implode(', ', $uSkipped) . "\n";
        }
    }

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
