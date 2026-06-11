<?php
require dirname(__DIR__) . '/inc/bootstrap.php';
require ROOT . '/inc/rating.php';

$id = (int)($_GET['id'] ?? 0);
$t = null;
$games = [];
$seatsByGame = [];

if ($id && db_ready()) {
    $st = db()->prepare('SELECT * FROM tournaments WHERE id = ?');
    $st->execute([$id]);
    $t = $st->fetch() ?: null;
    if ($t) {
        $st = db()->prepare("SELECT g.*, jp.nickname AS judge_nick FROM games g
            LEFT JOIN players jp ON jp.id = g.judge_player_id
            WHERE g.tournament_id = ? ORDER BY g.table_no, g.game_no");
        $st->execute([$id]);
        $games = $st->fetchAll();
        if ($games) {
            $ids = array_column($games, 'id');
            $in = implode(',', array_fill(0, count($ids), '?'));
            $st = db()->prepare("SELECT gs.*, p.nickname FROM game_seats gs
                JOIN players p ON p.id = gs.player_id
                WHERE gs.game_id IN ($in) ORDER BY gs.game_id, gs.seat");
            $st->execute($ids);
            foreach ($st->fetchAll() as $s) {
                $seatsByGame[(int)$s['game_id']][] = $s;
            }
        }
    }
}

$roleLabel = ['civ' => 'Мирный', 'maf' => 'Мафия', 'sheriff' => 'Шериф', 'don' => 'Дон'];
$winLabel = ['red' => 'красные', 'black' => 'чёрные', 'draw' => 'ничья'];

page_head($t ? $t['title'] : 'Турнир не найден', 'tournaments');

if (!$t) {
    empty_state('Турнир не найден', 'Возможно, ссылка устарела.');
    page_foot();
    exit;
}

echo '<h1>' . esc($t['title']) . '</h1>';
echo '<p style="color:var(--tx2);margin-top:-6px;">Столов: ' . (int)$t['tables_count']
    . ' · игр: ' . count($games) . '</p>';

// Итоговая таблица турнира
$standing = [];
foreach ($games as $g) {
    $seats = $seatsByGame[(int)$g['id']] ?? [];
    $totals = game_display_totals($g, $seats);
    foreach ($seats as $s) {
        $pid = (int)$s['player_id'];
        $standing[$pid] = $standing[$pid] ?? ['nick' => $s['nickname'], 'games' => 0, 'sum' => 0.0];
        $standing[$pid]['games']++;
        $standing[$pid]['sum'] += $totals[(int)$s['seat']]['total'] ?? 0;
    }
}
uasort($standing, fn($a, $b) => $b['sum'] <=> $a['sum']);

if ($standing) {
    echo '<div class="card"><h2 style="margin-top:0;">Итоговая таблица</h2><table class="tbl">';
    echo '<tr><th>#</th><th>Игрок</th><th class="num">Игр</th><th class="num">Σ</th></tr>';
    $pos = 0;
    foreach ($standing as $pid => $row) {
        $pos++;
        echo '<tr><td>' . $pos . '</td>'
            . '<td><a href="/player.php?id=' . $pid . '" style="color:var(--tx);">' . esc($row['nick']) . '</a></td>'
            . '<td class="num">' . $row['games'] . '</td>'
            . '<td class="num"><b>' . number_format($row['sum'], 2) . '</b></td></tr>';
    }
    echo '</table></div>';
}

$byTable = [];
foreach ($games as $g) {
    $byTable[(int)$g['table_no']][] = $g;
}
$multi = count($byTable) > 1;
ksort($byTable);

if ($multi) {
    echo '<div class="tables-grid" style="grid-template-columns:repeat(' . count($byTable) . ',minmax(0,1fr));">';
}
foreach ($byTable as $tableNo => $tGames) {
    echo $multi ? '<div class="table-col">' : '';
    if ($multi) {
        echo '<h2 style="margin:4px 0 8px;">Стол ' . $tableNo . '</h2>';
    }
    foreach ($tGames as $g) {
        $seats = $seatsByGame[(int)$g['id']] ?? [];
        $totals = game_display_totals($g, $seats);
        echo '<div class="card' . ($multi ? ' card-compact' : '') . '">';
        echo '<div class="section-head"><h2 style="margin:0;font-size:15px;">Игра ' . (int)$g['game_no'] . '</h2>';
        if ($g['winner']) {
            echo '<span class="tag ' . ($g['winner'] === 'red' ? 'tag-open' : ($g['winner'] === 'draw' ? 'tag-ok' : '')) . '">'
                . esc($winLabel[$g['winner']]) . '</span>';
        }
        echo '</div>';
        if (!$multi && $g['judge_nick']) {
            echo '<p style="color:var(--tx2);font-size:13px;margin:2px 0 6px;">судья: ' . esc($g['judge_nick']) . '</p>';
        }
        echo '<div style="overflow-x:auto;"><table class="tbl"' . ($multi ? ' style="font-size:12.5px;"' : '') . '>';
        if ($multi) {
            echo '<tr><th>#</th><th>Игрок</th><th>Роль</th><th class="num">Итог</th></tr>';
        } else {
            echo '<tr><th>#</th><th>Игрок</th><th>Роль</th><th class="num">+</th><th class="num">−</th><th class="num">Итог</th></tr>';
        }
        foreach ($seats as $s) {
            $tt = $totals[(int)$s['seat']] ?? ['total' => 0, 'is_pu' => false];
            echo '<tr><td>' . (int)$s['seat'] . '</td>'
                . '<td><a href="/player.php?id=' . (int)$s['player_id'] . '" style="color:var(--tx);">' . esc($s['nickname']) . '</a>'
                . ($tt['is_pu'] ? ' <span class="tag">ПУ</span>' : '') . '</td>'
                . '<td>' . $roleLabel[$s['role']] . '</td>';
            if (!$multi) {
                echo '<td class="num">' . ((float)$s['plus'] ? number_format((float)$s['plus'], 1) : '') . '</td>'
                    . '<td class="num">' . ((float)$s['minus'] ? number_format((float)$s['minus'], 1) : '') . '</td>';
            }
            echo '<td class="num"><b>' . number_format($tt['total'], 2) . '</b></td></tr>';
        }
        echo '</table></div></div>';
    }
    echo $multi ? '</div>' : '';
}
if ($multi) {
    echo '</div>';
}
page_foot();
