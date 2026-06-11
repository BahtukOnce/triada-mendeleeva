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

$canEdit = user_can_judge(current_user());

echo '<h1>' . esc($day['title']) . ' · ' . esc(date('d.m.Y', strtotime($day['date']))) . '</h1>';
echo '<p style="color:var(--tx2);margin-top:-6px;">Игр сыграно: ' . count($games)
    . ($day['location'] ? ' · ' . esc($day['location']) : '') . '</p>';
if ($canEdit) {
    echo '<p style="margin:0 0 12px;"><a class="btn" href="/admin/protocol.php?day=' . $id . '">Вести / редактировать игры</a></p>';
}

if (in_array($day['status'], ['reg_open', 'reg_closed'], true)) {
    $st = db()->prepare('SELECT r.*, p.nickname, p.avatar, p.id AS pid FROM day_registrations r
        JOIN players p ON p.id = r.player_id
        WHERE r.day_id = ? AND r.cancelled_at IS NULL ORDER BY r.created_at');
    $st->execute([$id]);
    $regs = $st->fetchAll();
    echo '<div class="card card-accent">';
    echo '<div class="section-head"><h2 style="margin:0;">Записавшиеся (' . count($regs) . ')</h2>';
    echo '<span class="tag ' . ($day['status'] === 'reg_open' ? 'tag-open' : '') . '">'
        . ($day['status'] === 'reg_open' ? 'запись открыта' : 'запись закрыта') . '</span></div>';
    if ($regs) {
        echo '<div class="admin-list" style="margin-top:10px;">';
        foreach ($regs as $r) {
            $time = $r['time_from'] ? substr($r['time_from'], 0, 5) . '–' . substr((string)$r['time_to'], 0, 5) : '';
            echo '<div class="admin-item">' . avatar_html(['nickname' => $r['nickname'], 'avatar' => $r['avatar']], 28);
            echo '<div><div class="nm"><a href="/player.php?id=' . (int)$r['pid'] . '" style="color:var(--tx);">'
                . esc($r['nickname']) . '</a></div>'
                . ($time ? '<div class="rl">' . $time . '</div>' : '') . '</div></div>';
        }
        echo '</div>';
    } else {
        echo '<p style="color:var(--tx2);font-size:14px;margin:8px 0 0;">Пока никто не записался — будьте первым!</p>';
    }
    if ($day['status'] === 'reg_open') {
        echo '<p style="margin:12px 0 0;"><a class="btn" href="/cabinet.php">Записаться</a></p>';
    }
    echo '</div>';
}

// ── Рейтинг вечера ──
if ($games) {
    $standing = [];
    foreach ($games as $g) {
        $seats = $seatsByGame[(int)$g['id']] ?? [];
        $tt = game_display_totals($g, $seats);
        foreach ($seats as $s) {
            $pid = (int)$s['player_id'];
            $standing[$pid] = $standing[$pid] ?? ['nick' => $s['nickname'], 'games' => 0, 'sum' => 0.0];
            $standing[$pid]['games']++;
            $standing[$pid]['sum'] += $tt[(int)$s['seat']]['total'] ?? 0;
        }
    }
    uasort($standing, fn($a, $b) => $b['sum'] <=> $a['sum']);
    echo '<div class="card"><h2 style="margin-top:0;">Рейтинг вечера</h2>';
    echo '<table class="tbl sortable"><thead><tr><th data-type="num">#</th><th>Игрок</th>'
        . '<th class="num" data-type="num">Игр</th><th class="num" data-type="num">Σ за вечер</th></tr></thead><tbody>';
    $pos = 0;
    foreach ($standing as $pid => $row) {
        $pos++;
        echo '<tr><td data-sort="' . $pos . '">' . $pos . '</td>'
            . '<td><a href="/player.php?id=' . $pid . '" style="color:var(--tx);">' . esc($row['nick']) . '</a></td>'
            . '<td class="num" data-sort="' . $row['games'] . '">' . $row['games'] . '</td>'
            . '<td class="num" data-sort="' . round($row['sum'], 2) . '"><b>' . number_format($row['sum'], 2) . '</b></td></tr>';
    }
    echo '</tbody></table></div>';
}

// ── Игры вечера (сеткой) ──
if ($games) {
    echo '<h2>Игры вечера</h2>';
    echo '<div class="tables-grid" style="grid-template-columns:repeat(auto-fit,minmax(330px,1fr));">';
    foreach ($games as $g) {
        $seats = $seatsByGame[(int)$g['id']] ?? [];
        $totals = game_display_totals($g, $seats);
        $winTag = $g['winner'] === 'red' ? 'tag-open' : ($g['winner'] === 'black' ? '' : 'tag-ok');

        echo '<div class="card card-compact">';
        echo '<div class="section-head"><h2 style="margin:0;font-size:15px;">Игра ' . (int)$g['game_no'] . '</h2><span>';
        if ($g['winner']) {
            echo '<span class="tag ' . $winTag . '">' . esc($winLabel[$g['winner']]) . '</span>';
        }
        if ($canEdit) {
            echo ' <a class="tag" href="/admin/protocol.php?day=' . $id . '&game=' . (int)$g['id'] . '">изменить</a>';
        }
        echo '</span></div>';
        if ($g['judge_nick']) {
            echo '<p style="color:var(--tx2);font-size:12px;margin:2px 0 6px;">судья: '
                . '<a href="/player.php?id=' . (int)$g['judge_id'] . '">' . esc($g['judge_nick']) . '</a></p>';
        }
        echo '<table class="tbl" style="font-size:12.5px;">';
        echo '<tr><th>#</th><th>Игрок</th><th>Роль</th><th class="num">Итог</th></tr>';
        foreach ($seats as $s) {
            $t = $totals[(int)$s['seat']] ?? ['total' => 0, 'is_pu' => false];
            $isBlack = in_array($s['role'], ['maf', 'don'], true);
            echo '<tr><td>' . (int)$s['seat'] . '</td>'
                . '<td><a href="/player.php?id=' . (int)$s['player_id'] . '" style="color:var(--tx);">' . esc($s['nickname']) . '</a>'
                . ($t['is_pu'] ? ' <span class="tag">ПУ</span>' : '') . '</td>'
                . '<td>' . ($isBlack ? '<b>' . $roleLabel[$s['role']] . '</b>' : $roleLabel[$s['role']]) . '</td>'
                . '<td class="num"><b>' . number_format($t['total'], 2) . '</b></td></tr>';
        }
        echo '</table>';
        $bm = array_filter([(int)$g['bm_seat1'], (int)$g['bm_seat2'], (int)$g['bm_seat3']]);
        $meta = [];
        if ($g['first_killed_seat']) {
            $meta[] = 'ПУ: ' . (int)$g['first_killed_seat'];
        }
        if ($bm) {
            $meta[] = 'ЛХ: ' . implode(', ', $bm);
        }
        if ($meta) {
            echo '<p style="color:var(--tx2);font-size:12px;margin:8px 0 0;">' . implode(' · ', $meta) . '</p>';
        }
        echo '</div>';
    }
    echo '</div>';
} else {
    empty_state('Протоколов пока нет', 'Игры этого вечера ещё не записаны.'
        . ($canEdit ? ' Нажмите «Вести / редактировать игры», чтобы добавить.' : ''));
}
page_foot();
