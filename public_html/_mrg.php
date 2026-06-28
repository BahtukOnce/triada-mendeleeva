<?php
// ВРЕМЕННЫЙ: предпросмотр и выполнение 2 одобренных владельцем слияний. Удаляется после.
declare(strict_types=1);
require dirname(__DIR__) . '/inc/bootstrap.php';
require_once ROOT . '/inc/import.php';
require_once ROOT . '/inc/rating.php';
require_once ROOT . '/inc/elo.php';
header('Content-Type: text/plain; charset=utf-8');
if (!hash_equals('lgm_5kQ2x9aZ', (string)($_GET['t'] ?? ''))) {
    http_response_code(403);
    exit('forbidden');
}
$rawKey = function ($n): string {
    $n = mb_strtolower(trim((string)$n));
    $n = (string)preg_replace('/\s+/u', ' ', $n);
    $n = (string)preg_replace('/[^\p{L}\p{N}_ ]/u', '', $n);
    $n = trim($n);
    return NICK_MERGES[$n] ?? $n;
};
$pairs = [['Математик', 'Дэко'], ['Маргуша', 'Маргуха']];

$all = db()->query("SELECT p.id, p.nickname, p.user_id, COUNT(gs.id) games
    FROM players p LEFT JOIN game_seats gs ON gs.player_id = p.id GROUP BY p.id")->fetchAll();
$byKey = [];
foreach ($all as $p) {
    $byKey[$rawKey($p['nickname'])][] = $p;
}
$pick = function (string $nick) use ($byKey, $rawKey) {
    $grp = $byKey[$rawKey($nick)] ?? [];
    usort($grp, fn($a, $b) => (int)$b['games'] <=> (int)$a['games']);
    return $grp;
};

$what = (string)($_GET['run'] ?? 'preview');

if ($what === 'preview') {
    foreach ($pairs as [$s, $d]) {
        echo "=== $s → $d ===\n";
        echo "  источник '$s':\n";
        foreach ($pick($s) as $p) {
            echo "    #{$p['id']} «{$p['nickname']}» игр {$p['games']}" . ($p['user_id'] ? ' (аккаунт)' : '') . "\n";
        }
        echo "  цель '$d':\n";
        $dg = $pick($d);
        if (!$dg) {
            echo "    — НЕ НАЙДЕНА —\n";
        }
        foreach ($dg as $p) {
            echo "    #{$p['id']} «{$p['nickname']}» игр {$p['games']}" . ($p['user_id'] ? ' (аккаунт)' : '') . "\n";
        }
    }
    exit;
}

if ($what === 'do') {
    $pdo = db();
    $report = [];
    foreach ($pairs as [$sN, $dN]) {
        $sg = $pick($sN);
        $dg = $pick($dN);
        if (!$sg || !$dg) {
            $report[] = "ПРОПУСК $sN → $dN: " . (!$sg ? "нет источника" : "нет цели");
            continue;
        }
        $dst = $dg[0]; // цель с наибольшим числом игр
        foreach ($sg as $src) {
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
            // постоянный алиас
            $ak = nick_key((string)$src['nickname']);
            $ck = nick_key((string)$dst['nickname']);
            if ($ak !== '' && $ck !== '' && $ak !== $ck) {
                db()->prepare('INSERT INTO nick_aliases (alias_key, canonical_key) VALUES (?,?) ON DUPLICATE KEY UPDATE canonical_key=VALUES(canonical_key)')->execute([$ak, $ck]);
                db()->prepare('UPDATE nick_aliases SET canonical_key=? WHERE canonical_key=?')->execute([$ck, $ak]);
            }
            $report[] = "{$src['nickname']} (#$sid) → {$dst['nickname']} (#$did)";
        }
    }
    rating_recompute_all();
    elo_recompute();
    echo "слито: " . count($report) . "\n";
    foreach ($report as $m) {
        echo "  $m\n";
    }
    exit;
}
exit('ok');
