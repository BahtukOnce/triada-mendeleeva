<?php
require dirname(__DIR__) . '/inc/bootstrap.php';
require ROOT . '/inc/rating.php';

$id = (int)($_GET['id'] ?? 0);
$day = null;
$games = [];
$seatsByGame = [];

if ($id && db_ready()) {
    $st = db()->prepare('SELECT * FROM game_days WHERE id = ?');
    $st->execute([$id]);
    $day = $st->fetch() ?: null;
    if ($day) {
        $st = db()->prepare("SELECT g.*, jp.nickname AS judge_nick, jp.id AS judge_id
            FROM games g LEFT JOIN players jp ON jp.id = g.judge_player_id
            WHERE g.day_id = ? ORDER BY g.table_no, g.game_no");
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
$winLabel = ['red' => 'Победа красных', 'black' => 'Победа чёрных', 'draw' => 'Ничья'];

page_head($day ? ('Вечер ' . $day['title']) : 'Вечер не найден', 'days');

if (!$day) {
    empty_state('Вечер не найден', 'Возможно, ссылка устарела.');
    echo '<p style="text-align:center;"><a href="/days.php">← Все вечера</a></p>';
    page_foot();
    exit;
}

echo '<h1>' . esc($day['title']) . ' · ' . esc(date('d.m.Y', strtotime($day['date']))) . '</h1>';
echo '<p style="color:var(--tx2);margin-top:-6px;">Игр сыграно: ' . count($games)
    . ($day['location'] ? ' · ' . esc($day['location']) : '') . ' · <a href="/days.php">все вечера</a></p>';

foreach ($games as $g) {
    $seats = $seatsByGame[(int)$g['id']] ?? [];
    $totals = game_display_totals($g, $seats);
    $winTag = $g['winner'] === 'red' ? 'tag-open' : ($g['winner'] === 'black' ? '' : 'tag-ok');

    echo '<div class="card">';
    echo '<div class="section-head"><h2 style="margin:0;">Игра ' . (int)$g['game_no'] . '</h2>';
    echo '<span>';
    if ($g['judge_nick']) {
        echo '<span style="color:var(--tx2);font-size:13px;margin-right:10px;">судья: '
            . '<a href="/player.php?id=' . (int)$g['judge_id'] . '">' . esc($g['judge_nick']) . '</a></span>';
    }
    if ($g['winner']) {
        echo '<span class="tag ' . $winTag . '">' . esc($winLabel[$g['winner']]) . '</span>';
    }
    echo '</span></div>';

    echo '<div style="overflow-x:auto;"><table class="tbl">';
    echo '<tr><th>#</th><th>Игрок</th><th>Роль</th><th class="num">Фолы</th><th class="num">Техи</th>'
        . '<th class="num">+</th><th class="num">−</th><th class="num">Ci</th><th class="num">Итог</th></tr>';
    foreach ($seats as $s) {
        $t = $totals[(int)$s['seat']] ?? ['total' => 0, 'ci' => 0, 'is_pu' => false];
        $isBlack = in_array($s['role'], ['maf', 'don'], true);
        echo '<tr>';
        echo '<td>' . (int)$s['seat'] . '</td>';
        echo '<td><a href="/player.php?id=' . (int)$s['player_id'] . '" style="color:var(--tx);">'
            . esc($s['nickname']) . '</a>' . ($t['is_pu'] ? ' <span class="tag" title="Первоубиенный">ПУ</span>' : '') . '</td>';
        echo '<td>' . ($isBlack ? '<b>' . $roleLabel[$s['role']] . '</b>' : $roleLabel[$s['role']]) . '</td>';
        echo '<td class="num">' . ((int)$s['fouls'] ?: '') . '</td>';
        echo '<td class="num">' . ((int)$s['tech_fouls'] ?: '') . '</td>';
        echo '<td class="num" style="color:var(--ok);">' . ((float)$s['plus'] ? number_format((float)$s['plus'], 1) : '') . '</td>';
        echo '<td class="num" style="color:var(--ac);">' . ((float)$s['minus'] ? number_format((float)$s['minus'], 1) : '') . '</td>';
        echo '<td class="num">' . ($t['ci'] > 0 ? number_format($t['ci'], 2) : '') . '</td>';
        echo '<td class="num"><b>' . number_format($t['total'], 2) . '</b></td>';
        echo '</tr>';
    }
    echo '</table></div>';

    $meta = [];
    if ($g['first_killed_seat']) {
        $meta[] = 'ПУ: место ' . (int)$g['first_killed_seat'];
    }
    $bm = array_filter([(int)$g['bm_seat1'], (int)$g['bm_seat2'], (int)$g['bm_seat3']]);
    if ($bm) {
        $meta[] = 'Лучший ход: ' . implode(', ', $bm);
    }
    if ($g['comment']) {
        $meta[] = esc($g['comment']);
    }
    if ($meta) {
        echo '<p style="color:var(--tx2);font-size:13px;margin:10px 0 0;">' . implode(' · ', $meta) . '</p>';
    }
    echo '</div>';
}

if (!$games) {
    empty_state('Протоколов пока нет', 'Игры этого вечера ещё не записаны.');
}
page_foot();
