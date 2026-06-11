<?php
require dirname(__DIR__) . '/inc/bootstrap.php';

$u = require_login();
$player = current_player();

page_head('Мои игры', '');
echo '<h1>Мои игры</h1>';

if (!$player) {
    empty_state('Ник ещё не привязан',
        'Чтобы видеть свои игры и статистику, привяжите игровой ник в личном кабинете.');
    echo '<p style="text-align:center;"><a class="btn" href="/cabinet.php">В личный кабинет</a></p>';
    page_foot();
    exit;
}

// Статистика и место в рейтинге
$mainId = (int)db()->query('SELECT id FROM ratings WHERE is_main = 1 LIMIT 1')->fetchColumn();
$stats = null;
$rank = null;
if ($mainId) {
    $st = db()->prepare('SELECT * FROM rating_cache WHERE rating_id = ? AND player_id = ?');
    $st->execute([$mainId, (int)$player['id']]);
    $stats = $st->fetch() ?: null;
    if ($stats) {
        $st = db()->prepare('SELECT COUNT(*) + 1 FROM rating_cache
            WHERE rating_id = ? AND club_score > (SELECT club_score FROM rating_cache WHERE rating_id = ? AND player_id = ?)');
        $st->execute([$mainId, $mainId, (int)$player['id']]);
        $rank = (int)$st->fetchColumn();
    }
}

// Быстрые ссылки
echo '<div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px;">';
echo '<a class="btn btn-ghost" href="/player.php?id=' . (int)$player['id'] . '">Мой профиль</a>';
echo '<a class="btn btn-ghost" href="/rating.php">Общий рейтинг</a>';
echo '</div>';

if ($stats) {
    echo '<div class="grid-stats">';
    echo '<div class="stat"><div class="lbl">место в рейтинге</div><div class="val">' . ($rank ?: '—') . '</div></div>';
    echo '<div class="stat"><div class="lbl">игр</div><div class="val">' . (int)$stats['games'] . '</div></div>';
    echo '<div class="stat"><div class="lbl">Σ</div><div class="val">' . number_format((float)$stats['sum_total'], 2) . '</div></div>';
    echo '<div class="stat"><div class="lbl">~Σ</div><div class="val">'
        . ($stats['avg_total'] !== null ? number_format((float)$stats['avg_total'], 2) : '—') . '</div></div>';
    echo '</div>';
}

// История игр со ссылками на вечер/турнир
$st = db()->prepare("SELECT gs.role, g.game_no, g.winner, g.first_killed_seat, gs.seat,
        g.context, d.id AS day_id, d.title AS day_title, d.date AS day_date,
        t.id AS t_id, t.title AS t_title, t.date_from AS t_date
    FROM game_seats gs
    JOIN games g ON g.id = gs.game_id
    LEFT JOIN game_days d ON d.id = g.day_id
    LEFT JOIN tournaments t ON t.id = g.tournament_id
    WHERE gs.player_id = ? AND g.status = 'finished'
    ORDER BY COALESCE(d.date, t.date_from) DESC, g.id DESC");
$st->execute([(int)$player['id']]);
$history = $st->fetchAll();

$roleLabel = ['civ' => 'Мирный', 'maf' => 'Мафия', 'sheriff' => 'Шериф', 'don' => 'Дон'];

if (!$history) {
    empty_state('Игр пока нет', 'Как только вы сыграете, игры появятся здесь.');
    page_foot();
    exit;
}

// Группировка по событию
$groups = [];
foreach ($history as $h) {
    $key = ($h['context'] === 'day' ? 'd' . $h['day_id'] : 't' . $h['t_id']);
    if (!isset($groups[$key])) {
        $groups[$key] = [
            'is_day' => $h['context'] === 'day',
            'id' => $h['context'] === 'day' ? (int)$h['day_id'] : (int)$h['t_id'],
            'title' => $h['context'] === 'day' ? $h['day_title'] : $h['t_title'],
            'date' => $h['context'] === 'day' ? $h['day_date'] : $h['t_date'],
            'rows' => [],
        ];
    }
    $groups[$key]['rows'][] = $h;
}

foreach ($groups as $g) {
    $link = ($g['is_day'] ? '/day.php?id=' : '/tournament.php?id=') . $g['id'];
    $dateStr = $g['date'] ? date('d.m.Y', strtotime($g['date'])) : '';
    $wins = 0;
    foreach ($g['rows'] as $h) {
        $won = ($h['winner'] === 'red' && in_array($h['role'], ['civ', 'sheriff'], true))
            || ($h['winner'] === 'black' && in_array($h['role'], ['maf', 'don'], true));
        if ($won) {
            $wins++;
        }
    }
    echo '<div class="card">';
    echo '<div class="section-head"><h2 style="margin:0;font-size:16px;"><a href="' . $link . '" style="color:var(--tx);">'
        . esc($g['title']) . '</a></h2>';
    echo '<span style="font-size:12.5px;color:var(--tx2);">' . esc($dateStr)
        . ' · ' . ($g['is_day'] ? 'вечер' : 'турнир') . ' · игр: ' . count($g['rows']) . ', побед: ' . $wins . '</span></div>';
    echo '<table class="tbl" style="margin-top:8px;"><tr><th>Игра</th><th>Роль</th><th>Результат</th></tr>';
    foreach ($g['rows'] as $h) {
        $won = ($h['winner'] === 'red' && in_array($h['role'], ['civ', 'sheriff'], true))
            || ($h['winner'] === 'black' && in_array($h['role'], ['maf', 'don'], true));
        $res = $h['winner'] === 'draw' ? '<span class="tag">ничья</span>'
            : ($won ? '<span class="tag tag-ok">победа</span>' : '<span class="tag">поражение</span>');
        echo '<tr><td><a href="' . $link . '">игра ' . (int)$h['game_no'] . '</a></td>'
            . '<td>' . $roleLabel[$h['role']]
            . ((int)$h['first_killed_seat'] === (int)$h['seat'] ? ' <span class="tag">ПУ</span>' : '') . '</td>'
            . '<td>' . $res . '</td></tr>';
    }
    echo '</table></div>';
}
page_foot();
