<?php
require dirname(__DIR__) . '/inc/bootstrap.php';

$id = (int)($_GET['id'] ?? 0);
$player = null;
$stats = null;
$history = [];
$rank = null;

if ($id && db_ready()) {
    $st = db()->prepare('SELECT p.*, u.role AS user_role FROM players p
        LEFT JOIN users u ON u.id = p.user_id WHERE p.id = ?');
    $st->execute([$id]);
    $player = $st->fetch() ?: null;
    if ($player) {
        $mainId = (int)db()->query('SELECT id FROM ratings WHERE is_main = 1 LIMIT 1')->fetchColumn();
        if ($mainId) {
            $st = db()->prepare('SELECT * FROM rating_cache WHERE rating_id = ? AND player_id = ?');
            $st->execute([$mainId, $id]);
            $stats = $st->fetch() ?: null;
            if ($stats) {
                $st = db()->prepare('SELECT COUNT(*) + 1 FROM rating_cache
                    WHERE rating_id = ? AND club_score > (SELECT club_score FROM rating_cache WHERE rating_id = ? AND player_id = ?)');
                $st->execute([$mainId, $mainId, $id]);
                $rank = (int)$st->fetchColumn();
            }
        }
        $st = db()->prepare("SELECT gs.role, gs.plus, gs.minus, gs.fouls, gs.tech_fouls,
                g.id AS game_id, g.game_no, g.winner, g.first_killed_seat, gs.seat,
                g.context, d.id AS day_id, d.title AS day_title, d.date AS day_date,
                t.id AS t_id, t.title AS t_title
            FROM game_seats gs
            JOIN games g ON g.id = gs.game_id
            LEFT JOIN game_days d ON d.id = g.day_id
            LEFT JOIN tournaments t ON t.id = g.tournament_id
            WHERE gs.player_id = ? AND g.status = 'finished'
            ORDER BY COALESCE(d.date, t.date_from) DESC, g.id DESC
            LIMIT 300");
        $st->execute([$id]);
        $history = $st->fetchAll();
    }
}

$roleLabel = ['civ' => 'Мирный', 'maf' => 'Мафия', 'sheriff' => 'Шериф', 'don' => 'Дон'];

page_head($player ? $player['nickname'] : 'Игрок не найден', 'players');

if (!$player) {
    empty_state('Игрок не найден', 'Возможно, ссылка устарела.');
    page_foot();
    exit;
}

$me = current_user();
$canSeePrivate = $me && (role_level($me['role']) >= 3 || ($player['user_id'] && (int)$player['user_id'] === (int)$me['id']));
$letter = mb_strtoupper(mb_substr($player['nickname'], 0, 1));

$roleLbl = ['civ' => 'Мирный', 'sheriff' => 'Шериф', 'maf' => 'Мафия', 'don' => 'Дон'];
echo '<div style="display:flex;align-items:center;gap:14px;margin:20px 0 4px;">';
echo avatar_html($player, 54, 'background:var(--acsf);color:var(--ac);font-size:22px;');
echo '<div><h1 style="margin:0;">' . esc($player['nickname']) . '</h1>';
$sub = [];
if ($player['user_role']) {
    $sub[] = role_label($player['user_role']);
}
if (!empty($player['fav_role'])) {
    $sub[] = 'любит играть: ' . $roleLbl[$player['fav_role']];
}
if (!empty($player['is_rhtu'])) {
    $sub[] = 'студент РХТУ';
}
if ($player['birth_date']) {
    $sub[] = 'день рождения: ' . date('d.m', strtotime($player['birth_date']));
}
if ($sub) {
    echo '<div style="color:var(--tx2);font-size:13px;">' . esc(implode(' · ', $sub)) . '</div>';
}
echo '</div></div>';

if ($canSeePrivate) {
    $priv = array_filter([
        $player['real_name'],
        $player['faculty'] ? 'факультет ' . $player['faculty'] : null,
        $player['study_group'] ? 'группа ' . $player['study_group'] : null,
        $player['tg'] ? 'TG: ' . $player['tg'] : null,
        $player['birth_date'] ? 'ДР: ' . date('d.m.Y', strtotime($player['birth_date'])) : null,
    ]);
    if ($priv) {
        echo '<p style="color:var(--tx2);font-size:13px;">' . esc(implode(' · ', $priv))
            . ' <span class="tag">видно только админам и владельцу</span></p>';
    }
}

if ($stats) {
    echo '<div class="grid-stats">';
    echo '<div class="stat"><div class="lbl">место в рейтинге</div><div class="val">' . ($rank ?: '—') . '</div></div>';
    echo '<div class="stat"><div class="lbl">игр</div><div class="val">' . (int)$stats['games'] . '</div></div>';
    echo '<div class="stat"><div class="lbl">Σ</div><div class="val">' . number_format((float)$stats['sum_total'], 2) . '</div></div>';
    echo '<div class="stat"><div class="lbl">~Σ (средний)</div><div class="val">'
        . ($stats['avg_total'] !== null ? number_format((float)$stats['avg_total'], 2) : '—') . '</div></div>';
    echo '</div>';

    echo '<div class="grid-2"><div class="card">';
    echo '<h2 style="margin-top:0;">По ролям</h2><table class="tbl">';
    echo '<tr><th>Роль</th><th class="num">Игр</th><th class="num">Побед</th><th class="num">Винрейт</th></tr>';
    foreach ([['Мирный', 'civ'], ['Мафия', 'maf'], ['Шериф', 'sher'], ['Дон', 'don']] as [$lbl, $k]) {
        $gms = (int)$stats['g_' . $k];
        $w = (int)$stats['w_' . $k];
        echo '<tr><td>' . $lbl . '</td><td class="num">' . $gms . '</td><td class="num">' . $w . '</td>'
            . '<td class="num">' . ($gms ? round($w / $gms * 100) . ' %' : '—') . '</td></tr>';
    }
    $totW = $stats['w_civ'] + $stats['w_maf'] + $stats['w_sher'] + $stats['w_don'];
    echo '<tr><td><b>Всего</b></td><td class="num"><b>' . (int)$stats['games'] . '</b></td><td class="num"><b>' . $totW . '</b></td>'
        . '<td class="num"><b>' . ((int)$stats['games'] ? round($totW / $stats['games'] * 100) . ' %' : '—') . '</b></td></tr>';
    echo '</table></div>';

    echo '<div class="card"><h2 style="margin-top:0;">Показатели</h2><table class="tbl">';
    foreach ([
        ['Σ+ (допы + ЛХ + Ci)', number_format((float)$stats['sum_plus'], 2)],
        ['ПУ (первоубиенный)', (int)$stats['pu_count']],
        ['ЛХ (бонусы)', number_format((float)$stats['lh_sum'], 1)],
        ['Допы', number_format((float)$stats['dop_sum'], 1)],
        ['Минусы и штрафы', number_format((float)$stats['minus_sum'], 1)],
        ['Ci (компенсации)', number_format((float)$stats['ci_sum'], 2)],
    ] as [$lbl, $val]) {
        echo '<tr><td style="color:var(--tx2);">' . $lbl . '</td><td class="num">' . $val . '</td></tr>';
    }
    echo '</table></div></div>';
} else {
    echo '<p style="color:var(--tx2);">Сыгранных игр в основном рейтинге пока нет.</p>';
}

if ($history) {
    echo '<div class="card"><h2 style="margin-top:0;">История игр (' . count($history) . ')</h2>';
    echo '<div style="overflow-x:auto;"><table class="tbl">';
    echo '<tr><th>Дата</th><th>Где</th><th>Роль</th><th>Результат</th></tr>';
    foreach ($history as $h) {
        $isDay = $h['context'] === 'day';
        $where = $isDay
            ? '<a href="/day.php?id=' . (int)$h['day_id'] . '">' . esc($h['day_title']) . '</a>'
            : '<a href="/tournament.php?id=' . (int)$h['t_id'] . '">' . esc($h['t_title']) . '</a>';
        $won = ($h['winner'] === 'red' && in_array($h['role'], ['civ', 'sheriff'], true))
            || ($h['winner'] === 'black' && in_array($h['role'], ['maf', 'don'], true));
        $res = $h['winner'] === 'draw' ? '<span class="tag">ничья</span>'
            : ($won ? '<span class="tag tag-ok">победа</span>' : '<span class="tag">поражение</span>');
        $date = $h['day_date'] ? date('d.m.Y', strtotime($h['day_date'])) : '';
        echo '<tr><td>' . $date . '</td><td>' . $where . ' · игра ' . (int)$h['game_no'] . '</td>'
            . '<td>' . $roleLabel[$h['role']] . ((int)$h['first_killed_seat'] === (int)$h['seat'] ? ' <span class="tag">ПУ</span>' : '') . '</td>'
            . '<td>' . $res . '</td></tr>';
    }
    echo '</table></div></div>';
}
page_foot();
