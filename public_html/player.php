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

$roleLbl = ['civ' => 'Мирный', 'sheriff' => 'Шериф', 'maf' => 'Мафия', 'don' => 'Дон'];
$sub = [];
if ($player['user_role']) {
    $sub[] = role_label($player['user_role']);
}
if ($player['birth_date']) {
    $sub[] = 'день рождения: ' . date('d.m', strtotime($player['birth_date']));
}

$elo = (int)round((float)($player['elo'] ?? 1000));
$medal = $rank === 1 ? '🥇' : ($rank === 2 ? '🥈' : ($rank === 3 ? '🥉' : ''));

echo '<div class="pf-hero">';
echo '<div class="pf-ava">' . avatar_html($player, 64, 'background:var(--acsf);color:var(--ac);font-size:26px;') . '</div>';
echo '<div class="pf-id"><h1 class="pf-name">' . player_label($player) . '</h1>';
echo '<div class="pf-badges">';
if ($rank) {
    echo '<span class="rank-badge' . ($rank <= 3 ? ' r' . $rank : '') . '">'
        . ($medal ? $medal . ' #' . $rank : '#' . $rank) . ' в рейтинге</span>';
}
if (!empty($player['fav_role'])) {
    echo '<span class="tag">любимая роль: ' . esc($roleLbl[$player['fav_role']]) . '</span>';
}
if (!empty($player['is_rhtu'])) {
    echo '<span class="tag">студент РХТУ</span>';
}
echo '</div>';
if ($sub) {
    echo '<div class="pf-sub">' . esc(implode(' · ', $sub)) . '</div>';
}
echo '</div>';
echo '<div class="pf-elo"><div class="v">' . $elo . '</div><div class="l">ELO</div></div>';
echo '</div>';

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
    $games = (int)$stats['games'];
    $totW = (int)$stats['w_civ'] + (int)$stats['w_maf'] + (int)$stats['w_sher'] + (int)$stats['w_don'];
    $totWr = $games ? round($totW / $games * 100) : 0;

    // ── Метрики ──
    echo '<div class="pf-tiles">';
    echo '<div class="stat"><div class="lbl">винрейт</div><div class="val">' . $totWr . '%</div></div>';
    echo '<div class="stat"><div class="lbl">игр</div><div class="val">' . $games . '</div></div>';
    echo '<div class="stat"><div class="lbl">Σ</div><div class="val">' . number_format((float)$stats['sum_total'], 2) . '</div></div>';
    echo '<div class="stat"><div class="lbl">Σ+</div><div class="val">' . number_format((float)$stats['sum_plus'], 2) . '</div></div>';
    echo '<div class="stat"><div class="lbl">~Σ</div><div class="val">'
        . ($stats['avg_total'] !== null ? number_format((float)$stats['avg_total'], 2) : '—') . '</div></div>';
    echo '<div class="stat"><div class="lbl">~Σ×Σ</div><div class="val">'
        . ($stats['club_score'] !== null ? number_format((float)$stats['club_score'], 1) : '—') . '</div></div>';
    echo '</div>';

    // ── Данные графиков ──
    $roleOrder = [['civ', 'Мирный'], ['sher', 'Шериф'], ['maf', 'Мафия'], ['don', 'Дон']];
    $roleClr = ['civ' => '#3a7bd5', 'sher' => '#d5a23a', 'maf' => '#c0392b', 'don' => '#8c8c96'];
    $wn = 0; $ls = 0; $dr = 0;
    foreach ($history as $h) {
        if ($h['winner'] === 'draw') {
            $dr++;
            continue;
        }
        $won = ($h['winner'] === 'red' && in_array($h['role'], ['civ', 'sheriff'], true))
            || ($h['winner'] === 'black' && in_array($h['role'], ['maf', 'don'], true));
        $won ? $wn++ : $ls++;
    }
    $eh = db()->prepare('SELECT elo_after FROM elo_history WHERE player_id = ? ORDER BY id');
    $eh->execute([$id]);
    $eloSeries = array_map(fn($r) => round((float)$r['elo_after'], 1), $eh->fetchAll());
    array_unshift($eloSeries, 1000.0);
    $chartData = json_encode([
        'results' => [$wn, $ls, $dr],
        'elo' => $eloSeries,
    ], JSON_UNESCAPED_UNICODE);

    // ── Графики ──
    echo '<div class="grid-2">';
    echo '<div class="card"><h2 style="margin-top:0;font-size:15px;">Динамика ELO · сейчас ' . $elo . '</h2>'
        . '<div style="position:relative;height:210px;"><canvas id="ch-elo"></canvas></div></div>';
    echo '<div class="card"><h2 style="margin-top:0;font-size:15px;">Исходы игр</h2>'
        . '<div style="position:relative;height:210px;"><canvas id="ch-results"></canvas></div></div>';
    echo '</div>';

    // ── Винрейт по ролям (бары) + Показатели ──
    echo '<div class="grid-2">';
    echo '<div class="card"><h2 style="margin-top:0;">Винрейт по ролям</h2><div class="role-bars">';
    foreach ($roleOrder as [$rk, $rl]) {
        $g = (int)$stats['g_' . $rk];
        $w = (int)$stats['w_' . $rk];
        $pct = $g ? round($w / $g * 100) : 0;
        echo '<div class="role-bar"><span class="rb-name">' . $rl . '</span>'
            . '<span class="rb-track"><span class="rb-fill" style="width:' . ($g ? $pct : 0) . '%;background:' . $roleClr[$rk] . ';"></span></span>'
            . '<span class="rb-val">' . ($g ? '<b>' . $pct . '%</b> ' . $w . '/' . $g : '—') . '</span></div>';
    }
    echo '</div></div>';

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

    // ── Chart.js ──
    echo '<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>';
    echo '<script>(function(){var D=' . $chartData . ';if(typeof Chart==="undefined")return;'
        . 'var grid="rgba(255,255,255,0.08)",tx="#9c9ca6",red="#e8332a";'
        . 'Chart.defaults.color=tx;Chart.defaults.font.family="system-ui,-apple-system,\'Segoe UI\',Roboto,sans-serif";'
        . 'new Chart(document.getElementById("ch-elo"),{type:"line",data:{labels:D.elo.map(function(_,i){return i;}),'
        . 'datasets:[{data:D.elo,borderColor:red,backgroundColor:"rgba(232,51,42,0.12)",fill:true,tension:0.25,pointRadius:0,borderWidth:2}]},'
        . 'options:{plugins:{legend:{display:false}},scales:{x:{display:false},y:{grid:{color:grid}}},maintainAspectRatio:false}});'
        . 'new Chart(document.getElementById("ch-results"),{type:"doughnut",data:{labels:["Победы","Поражения","Ничьи"],'
        . 'datasets:[{data:D.results,backgroundColor:["#2fa45c",red,"#888"],borderWidth:0}]},'
        . 'options:{plugins:{legend:{position:"bottom"}},maintainAspectRatio:false}});'
        . '})();</script>';
} else {
    echo '<p style="color:var(--tx2);">Сыгранных игр в основном рейтинге пока нет.</p>';
}

if ($history) {
    $histClr = ['civ' => '#3a7bd5', 'sheriff' => '#d5a23a', 'maf' => '#c0392b', 'don' => '#8c8c96'];
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
            . '<td><span class="hist-dot" style="background:' . ($histClr[$h['role']] ?? '#888') . ';"></span>'
            . $roleLabel[$h['role']] . ((int)$h['first_killed_seat'] === (int)$h['seat'] ? ' <span class="tag">ПУ</span>' : '') . '</td>'
            . '<td>' . $res . '</td></tr>';
    }
    echo '</table></div></div>';
}
page_foot();
