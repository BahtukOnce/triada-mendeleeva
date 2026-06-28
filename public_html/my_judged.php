<?php
require dirname(__DIR__) . '/inc/bootstrap.php';

// ?id=X — публичный просмотр судейств игрока; без id — свои судейства (нужен вход).
$viewId = (int)($_GET['id'] ?? 0);
if ($viewId > 0) {
    $pl = db()->prepare('SELECT id, nickname FROM players WHERE id = ?');
    $pl->execute([$viewId]);
    $player = $pl->fetch() ?: null;
    $own = false;
} else {
    $u = require_login();
    $player = current_player();
    $own = true;
}

$nick = (string)($player['nickname'] ?? '');
page_head($own ? 'Мои судейства' : ('Судейства — ' . $nick), '');
echo '<p><a href="' . ($own ? '/my_stats.php' : '/player.php?id=' . $viewId) . '">← '
    . ($own ? 'Моя статистика' : esc($nick)) . '</a></p>';
echo '<h1>' . ($own ? 'Игры, которые я отсудил' : 'Игры, которые отсудил ' . esc($nick)) . '</h1>';

if (!$player) {
    empty_state('Игрок не найден', '');
    page_foot();
    exit;
}
$pid = (int)$player['id'];

$st = db()->prepare("SELECT g.id, g.game_no, g.winner, g.context,
        d.id AS day_id, d.title AS day_title, d.date AS day_date,
        t.id AS t_id, t.title AS t_title, t.date_from AS t_date
    FROM games g
    LEFT JOIN game_days d ON d.id = g.day_id
    LEFT JOIN tournaments t ON t.id = g.tournament_id
    WHERE g.judge_player_id = ? AND g.status = 'finished'
    ORDER BY COALESCE(d.date, t.date_from) DESC, g.id DESC");
$st->execute([$pid]);
$games = $st->fetchAll();

if (!$games) {
    empty_state('Судейств пока нет', $own
        ? 'Здесь появятся игры, которые вы провели как судья.'
        : 'У игрока пока нет проведённых игр.');
    page_foot();
    exit;
}

$winLabel = ['red' => 'Победа красных', 'black' => 'Победа чёрных', 'draw' => 'Ничья'];

// Игроки по играм (для мини-состава)
$ids = array_column($games, 'id');
$in = implode(',', array_fill(0, count($ids), '?'));
$seatsByGame = [];
$ss = db()->prepare("SELECT gs.game_id, gs.seat, gs.role, p.id AS pid, p.nickname FROM game_seats gs
    JOIN players p ON p.id = gs.player_id WHERE gs.game_id IN ($in) ORDER BY gs.game_id, gs.seat");
$ss->execute($ids);
foreach ($ss->fetchAll() as $s) {
    $seatsByGame[(int)$s['game_id']][] = $s;
}

echo '<p style="color:var(--tx2);margin-top:-6px;">Всего отсужено: ' . count($games) . '</p>';

// Группировка по событию
$groups = [];
foreach ($games as $g) {
    $isDay = $g['context'] === 'day';
    $key = $isDay ? 'd' . $g['day_id'] : 't' . $g['t_id'];
    if (!isset($groups[$key])) {
        $groups[$key] = [
            'is_day' => $isDay,
            'id' => $isDay ? (int)$g['day_id'] : (int)$g['t_id'],
            'title' => $isDay ? $g['day_title'] : $g['t_title'],
            'date' => $isDay ? $g['day_date'] : $g['t_date'],
            'games' => [],
        ];
    }
    $groups[$key]['games'][] = $g;
}

foreach ($groups as $grp) {
    $link = ($grp['is_day'] ? '/day.php?id=' : '/tournament.php?id=') . $grp['id'];
    $dateStr = $grp['date'] ? date('d.m.Y', strtotime($grp['date'])) : '';
    echo '<div class="card"><div class="section-head"><h2 style="margin:0;font-size:16px;"><a href="' . $link . '" style="color:var(--tx);">'
        . esc($grp['title']) . '</a></h2>';
    echo '<span style="font-size:12.5px;color:var(--tx2);">' . esc($dateStr) . ' · отсужено: ' . count($grp['games']) . '</span></div>';
    echo '<table class="tbl" style="margin-top:8px;"><tr><th>Игра</th><th>Результат</th><th>Состав</th></tr>';
    foreach ($grp['games'] as $g) {
        $winTag = $g['winner'] === 'red' ? 'tag-red' : ($g['winner'] === 'black' ? 'tag-black' : 'tag-draw');
        $names = [];
        foreach (($seatsByGame[(int)$g['id']] ?? []) as $s) {
            $names[] = role_dot($s['role']) . '<a href="/player.php?id=' . (int)$s['pid'] . '" style="color:var(--tx2);">' . esc($s['nickname']) . '</a>';
        }
        $gameLink = $link . '#game-' . (int)$g['id'];
        echo '<tr><td><a href="' . $gameLink . '">игра ' . (int)$g['game_no'] . '</a></td>'
            . '<td>' . ($g['winner'] ? '<span class="tag ' . $winTag . '">' . esc($winLabel[$g['winner']]) . '</span>' : '—') . '</td>'
            . '<td style="font-size:12px;color:var(--tx2);overflow-wrap:anywhere;">' . implode(' · ', $names) . '</td></tr>';
    }
    echo '</table></div>';
}
page_foot();
