<?php
// ВРЕМЕННЫЙ: посев алиасов ников из лога слияний + переприменение. Удаляется после.
declare(strict_types=1);
require dirname(__DIR__) . '/inc/bootstrap.php';
require_once ROOT . '/inc/import.php'; // NICK_MERGES, nick_key()
header('Content-Type: text/plain; charset=utf-8');
if (!hash_equals('lgm_5kQ2x9aZ', (string)($_GET['t'] ?? ''))) {
    http_response_code(403);
    exit('forbidden');
}

// «сырой» ключ: нормализация + хардкод NICK_MERGES, БЕЗ DB-алиасов (для посева)
$rawKey = function ($n): string {
    $n = mb_strtolower(trim((string)$n));
    $n = (string)preg_replace('/\s+/u', ' ', $n);
    $n = (string)preg_replace('/[^\p{L}\p{N}_ ]/u', '', $n);
    $n = trim($n);
    return NICK_MERGES[$n] ?? $n;
};
$what = (string)($_GET['run'] ?? '');

if ($what === 'seed') {
    $rows = db()->query("SELECT details FROM logs WHERE action = 'players_merge' ORDER BY id ASC")->fetchAll();
    $ins = db()->prepare('INSERT INTO nick_aliases (alias_key, canonical_key) VALUES (?,?) ON DUPLICATE KEY UPDATE canonical_key = VALUES(canonical_key)');
    $rep = db()->prepare('UPDATE nick_aliases SET canonical_key = ? WHERE canonical_key = ?');
    $n = 0;
    foreach ($rows as $r) {
        $d = json_decode((string)$r['details'], true);
        $ak = $rawKey($d['src'] ?? '');
        $ck = $rawKey($d['dst'] ?? '');
        if ($ak === '' || $ck === '' || $ak === $ck) {
            continue;
        }
        $ins->execute([$ak, $ck]);
        $rep->execute([$ck, $ak]);
        $n++;
    }
    echo "записей лога слияний обработано: $n\n\nалиасы (источник → канон):\n";
    foreach (db()->query("SELECT alias_key, canonical_key FROM nick_aliases ORDER BY canonical_key, alias_key") as $a) {
        echo "  {$a['alias_key']} → {$a['canonical_key']}\n";
    }
    exit;
}

if ($what === 'check') {
    // дубликаты, ещё НЕ схлопнутые: игрок с играми, чей ник — источник алиаса
    $aliasSet = array_flip(db()->query("SELECT alias_key FROM nick_aliases")->fetchAll(PDO::FETCH_COLUMN));
    $rows = db()->query("SELECT p.id, p.nickname, COUNT(gs.id) games FROM players p
        LEFT JOIN game_seats gs ON gs.player_id = p.id GROUP BY p.id")->fetchAll();
    echo "ещё не схлопнутые дубликаты (ник = источник алиаса, но игрок ещё существует):\n";
    $c = 0;
    foreach ($rows as $r) {
        if (isset($aliasSet[$rawKey($r['nickname'])])) {
            echo "  #{$r['id']} {$r['nickname']} — игр {$r['games']}\n";
            $c++;
        }
    }
    echo "ИТОГО: $c\n";
    exit;
}

if ($what === 'apply') {
    require_once ROOT . '/inc/legacy_import.php';
    db()->exec("DELETE FROM nick_aliases WHERE alias_key = canonical_key"); // убрать само-алиасы
    echo "=== ПЕРЕИМПОРТ С УЧЁТОМ АЛИАСОВ ===\n";
    echo implode("\n", legacy_days_import_run()) . "\n";
    echo implode("\n", legacy_tour_import_run()) . "\n";
    // удалить осиротевшие дубликаты: 0 игр, без аккаунта, ник = источник алиаса, нигде не используется
    $aliasSet = array_flip(db()->query("SELECT alias_key FROM nick_aliases")->fetchAll(PDO::FETCH_COLUMN));
    $cands = db()->query("SELECT id, nickname FROM players p WHERE p.user_id IS NULL
        AND NOT EXISTS (SELECT 1 FROM game_seats gs WHERE gs.player_id = p.id)
        AND NOT EXISTS (SELECT 1 FROM games g WHERE g.judge_player_id = p.id)
        AND NOT EXISTS (SELECT 1 FROM players o WHERE o.partner_player_id = p.id OR o.rival_player_id = p.id)
        AND NOT EXISTS (SELECT 1 FROM day_registrations dr WHERE dr.player_id = p.id)
        AND NOT EXISTS (SELECT 1 FROM tournament_regs tr WHERE tr.player_id = p.id)
        AND NOT EXISTS (SELECT 1 FROM player_avatars pa WHERE pa.player_id = p.id)")->fetchAll();
    $del = db()->prepare("DELETE FROM players WHERE id = ?");
    $deleted = [];
    foreach ($cands as $c) {
        if (isset($aliasSet[$rawKey($c['nickname'])])) {
            $del->execute([(int)$c['id']]);
            $deleted[] = (string)$c['nickname'];
        }
    }
    echo "\nудалено осиротевших дубликатов: " . count($deleted) . "\n";
    foreach ($deleted as $nk) {
        echo "  — $nk\n";
    }
    exit;
}
if ($what === 'merge') {
    require_once ROOT . '/inc/rating.php';
    require_once ROOT . '/inc/elo.php';
    $aliases = [];
    foreach (db()->query("SELECT alias_key, canonical_key FROM nick_aliases") as $a) {
        $aliases[(string)$a['alias_key']] = (string)$a['canonical_key'];
    }
    $resolve = function (string $k) use ($aliases): string {
        $i = 0;
        while (isset($aliases[$k]) && $aliases[$k] !== $k && $i++ < 12) {
            $k = $aliases[$k];
        }
        return $k;
    };
    $players = db()->query("SELECT id, nickname, user_id FROM players")->fetchAll();
    $byKey = [];
    foreach ($players as $p) {
        $byKey[$rawKey($p['nickname'])][] = $p;
    }
    $gamesOf = function (int $pid): int {
        return (int)db()->query("SELECT COUNT(*) FROM game_seats WHERE player_id = " . $pid)->fetchColumn();
    };
    $pdo = db();
    $report = [];
    foreach ($byKey as $k => $grp) {
        if (!isset($aliases[$k])) {
            continue; // не источник алиаса
        }
        $canon = $resolve($k);
        $dsts = $byKey[$canon] ?? [];
        if (!$dsts) {
            $report[] = "ПРОПУСК {$grp[0]['nickname']}: целевой игрок ($canon) не найден";
            continue;
        }
        $dst = null;
        $mx = -1;
        foreach ($dsts as $d) {
            $g = $gamesOf((int)$d['id']);
            if ($g > $mx) {
                $mx = $g;
                $dst = $d;
            }
        }
        foreach ($grp as $src) {
            if ((int)$src['id'] === (int)$dst['id']) {
                continue;
            }
            $sid = (int)$src['id'];
            $did = (int)$dst['id'];
            $pdo->beginTransaction();
            $pdo->prepare('UPDATE game_seats SET player_id=? WHERE player_id=?')->execute([$did, $sid]);
            $pdo->prepare('UPDATE games SET judge_player_id=? WHERE judge_player_id=?')->execute([$did, $sid]);
            $pdo->prepare('DELETE r1 FROM day_registrations r1 JOIN day_registrations r2 ON r2.day_id=r1.day_id AND r2.player_id=? WHERE r1.player_id=?')->execute([$did, $sid]);
            $pdo->prepare('UPDATE day_registrations SET player_id=? WHERE player_id=?')->execute([$did, $sid]);
            $pdo->prepare('DELETE r1 FROM tournament_regs r1 JOIN tournament_regs r2 ON r2.tournament_id=r1.tournament_id AND r2.player_id=? WHERE r1.player_id=?')->execute([$did, $sid]);
            $pdo->prepare('UPDATE tournament_regs SET player_id=? WHERE player_id=?')->execute([$did, $sid]);
            $pdo->prepare('UPDATE player_avatars SET player_id=? WHERE player_id=?')->execute([$did, $sid]);
            $pdo->prepare('UPDATE players SET partner_player_id=? WHERE partner_player_id=?')->execute([$did, $sid]);
            $pdo->prepare('UPDATE players SET rival_player_id=? WHERE rival_player_id=?')->execute([$did, $sid]);
            if ($src['user_id'] && !$dst['user_id']) {
                $pdo->prepare('UPDATE players SET user_id=NULL WHERE id=?')->execute([$sid]);
                $pdo->prepare('UPDATE players SET user_id=? WHERE id=?')->execute([(int)$src['user_id'], $did]);
            } elseif ($src['user_id']) {
                $pdo->prepare('UPDATE players SET user_id=NULL WHERE id=?')->execute([$sid]);
            }
            $pdo->prepare('DELETE FROM players WHERE id=?')->execute([$sid]);
            $pdo->commit();
            $report[] = "{$src['nickname']} → {$dst['nickname']}";
        }
    }
    rating_recompute_all();
    elo_recompute();
    echo "слито напрямую: " . count($report) . "\n";
    foreach ($report as $m) {
        echo "  $m\n";
    }
    exit;
}
exit('ok');
