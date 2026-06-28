<?php
// ВРЕМЕННЫЙ диагностический эндпоинт (только чтение). Удаляется после проверки.
declare(strict_types=1);
require dirname(__DIR__) . '/inc/bootstrap.php';
header('Content-Type: text/plain; charset=utf-8');
if (!hash_equals('lgm_5kQ2x9aZ', (string)($_GET['t'] ?? ''))) {
    http_response_code(403);
    exit('forbidden');
}
$pdo = db();
$q = fn(string $sql) => $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

echo "=== ОБЩЕЕ ===\n";
foreach ($q("SELECT context, COUNT(*) n FROM games GROUP BY context") as $r) {
    echo "  games context={$r['context']}: {$r['n']}\n";
}
echo "  game_seats всего: " . $pdo->query("SELECT COUNT(*) FROM game_seats")->fetchColumn() . "\n";
echo "  игроков с играми: " . $pdo->query("SELECT COUNT(DISTINCT player_id) FROM game_seats")->fetchColumn() . "\n";
echo "  elo_history точек: " . $pdo->query("SELECT COUNT(*) FROM elo_history")->fetchColumn() . "\n";

echo "\n=== СЕЗОНЫ (game_days.season) ===\n";
foreach ($q("SELECT d.season, COUNT(DISTINCT d.id) days, COUNT(DISTINCT g.id) games, COUNT(DISTINCT gs.player_id) players
    FROM game_days d JOIN games g ON g.day_id=d.id JOIN game_seats gs ON gs.game_id=g.id
    WHERE d.season IS NOT NULL GROUP BY d.season ORDER BY d.season") as $r) {
    echo "  {$r['season']}: дней {$r['days']}, игр {$r['games']}, игроков {$r['players']}\n";
}

echo "\n=== ТУРНИРЫ ===\n";
foreach ($q("SELECT t.id, t.title, t.date_from, COUNT(DISTINCT g.id) games, COUNT(DISTINCT gs.player_id) players, COUNT(gs.id) seats
    FROM tournaments t LEFT JOIN games g ON g.tournament_id=t.id LEFT JOIN game_seats gs ON gs.game_id=g.id
    GROUP BY t.id HAVING games>0 ORDER BY t.date_from") as $r) {
    echo "  #{$r['id']} {$r['title']} ({$r['date_from']}): игр {$r['games']}, игроков {$r['players']}, мест {$r['seats']}\n";
}

echo "\n=== АНОМАЛИИ ===\n";
$bad = $q("SELECT g.id, g.context, COUNT(gs.id) seats FROM games g LEFT JOIN game_seats gs ON gs.game_id=g.id GROUP BY g.id HAVING seats <> 10");
echo "  игр с числом мест != 10: " . count($bad) . "\n";
foreach (array_slice($bad, 0, 25) as $r) {
    echo "    game #{$r['id']} ({$r['context']}): {$r['seats']} мест\n";
}
echo "  игр без победителя (winner NULL/пусто): " . $pdo->query("SELECT COUNT(*) FROM games WHERE winner IS NULL OR winner=''")->fetchColumn() . "\n";

$rolebad = $q("SELECT game_id, SUM(role='don') d, SUM(role='maf') m, SUM(role='sheriff') s, SUM(role='civ') c, COUNT(*) tot
    FROM game_seats GROUP BY game_id HAVING NOT (d=1 AND m=2 AND s=1 AND c=6)");
echo "  игр с нестандартным раскладом ролей (не 1дон/2маф/1шер/6мир): " . count($rolebad) . "\n";
foreach (array_slice($rolebad, 0, 20) as $r) {
    echo "    game #{$r['game_id']}: дон={$r['d']} маф={$r['m']} шер={$r['s']} мир={$r['c']} всего={$r['tot']}\n";
}

$dup = $q("SELECT game_id, player_id, COUNT(*) c FROM game_seats GROUP BY game_id, player_id HAVING c>1");
echo "  дублей игрока в одной игре: " . count($dup) . "\n";
foreach (array_slice($dup, 0, 15) as $r) {
    echo "    game #{$r['game_id']} player #{$r['player_id']}: x{$r['c']}\n";
}

echo "  ТОП-15 мест по plus (контроль двойной победы — допы должны быть < ~0.8):\n";
foreach ($q("SELECT gs.game_id, gs.plus, gs.role, g.winner, g.context, p.nickname
    FROM game_seats gs JOIN games g ON g.id=gs.game_id JOIN players p ON p.id=gs.player_id
    ORDER BY gs.plus DESC LIMIT 15") as $r) {
    echo "    game #{$r['game_id']} ({$r['context']}, win={$r['winner']}) {$r['nickname']} [{$r['role']}]: plus={$r['plus']}\n";
}

echo "\n=== ЛЕТНИЙ КУБОК (детально) ===\n";
$cupId = (int)$pdo->query("SELECT id FROM tournaments WHERE title LIKE '%Летний кубок%' ORDER BY id DESC LIMIT 1")->fetchColumn();
if ($cupId) {
    foreach ($q("SELECT g.id, g.game_no, g.winner FROM games g WHERE g.tournament_id=$cupId ORDER BY g.game_no") as $g) {
        echo "  игра {$g['game_no']} (#{$g['id']}): победитель {$g['winner']}\n";
    }
    echo "  --- победы по игрокам (сверка со стандингом таблицы) ---\n";
    foreach ($q("SELECT p.nickname, COUNT(*) games,
        SUM(((g.winner='red') AND gs.role IN ('civ','sheriff')) OR ((g.winner='black') AND gs.role IN ('maf','don'))) wins
        FROM game_seats gs JOIN games g ON g.id=gs.game_id JOIN players p ON p.id=gs.player_id
        WHERE g.tournament_id=$cupId GROUP BY p.id ORDER BY wins DESC, p.nickname") as $r) {
        echo "    {$r['nickname']}: игр {$r['games']}, побед {$r['wins']}\n";
    }
}
if ($cupId) {
    echo "  --- сумма очков кубка по игрокам (победы*1 + сумма допов; сверка с тотал-столбцом) ---\n";
    foreach ($q("SELECT p.nickname,
        SUM(((g.winner='red') AND gs.role IN ('civ','sheriff')) OR ((g.winner='black') AND gs.role IN ('maf','don'))) wins,
        ROUND(SUM(gs.plus - gs.minus),2) dop
        FROM game_seats gs JOIN games g ON g.id=gs.game_id JOIN players p ON p.id=gs.player_id
        WHERE g.tournament_id=$cupId GROUP BY p.id ORDER BY (SUM(((g.winner='red') AND gs.role IN ('civ','sheriff')) OR ((g.winner='black') AND gs.role IN ('maf','don'))) + SUM(gs.plus-gs.minus)) DESC") as $r) {
        $tot = round($r['wins'] + $r['dop'], 2);
        echo "    {$r['nickname']}: победы {$r['wins']} + допы {$r['dop']} = ИТОГО {$tot}\n";
    }
}

echo "\n=== ДЕВЯТИИГРОВЫЕ ПАРТИИ (#1455, #1456) ===\n";
foreach ([1455, 1456] as $gid) {
    $info = $q("SELECT g.id, g.winner, d.date, d.season, d.title FROM games g LEFT JOIN game_days d ON d.id=g.day_id WHERE g.id=$gid");
    if ($info) {
        $i = $info[0];
        echo "  game #{$gid}: дата {$i['date']}, сезон " . ($i['season'] ?? '—') . ", вечер «{$i['title']}», победитель {$i['winner']}\n";
        foreach ($q("SELECT gs.seat, gs.role, p.nickname, gs.plus, gs.minus FROM game_seats gs JOIN players p ON p.id=gs.player_id WHERE gs.game_id=$gid ORDER BY gs.seat") as $s) {
            echo "      место {$s['seat']}: {$s['nickname']} [{$s['role']}] +{$s['plus']}/-{$s['minus']}\n";
        }
    }
}

echo "\n=== ТИП КОЛОНКИ + КЛЕЙ В КУБКЕ ===\n";
foreach ($q("SELECT COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_NAME='game_seats' AND COLUMN_NAME IN ('plus','minus')") as $r) {
    echo "  {$r['COLUMN_NAME']}: {$r['COLUMN_TYPE']}\n";
}
if ($cupId) {
    foreach ($q("SELECT g.game_no, gs.role, gs.plus, gs.minus FROM game_seats gs JOIN games g ON g.id=gs.game_id JOIN players p ON p.id=gs.player_id
        WHERE g.tournament_id=$cupId AND p.nickname='Клей' ORDER BY g.game_no") as $r) {
        echo "    Клей игра {$r['game_no']} [{$r['role']}]: plus={$r['plus']} minus={$r['minus']}\n";
    }
}
echo "\nГОТОВО\n";
