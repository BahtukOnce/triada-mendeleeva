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
    echo '<span class="fav-chip"><span class="fdot" style="background:' . role_color($player['fav_role']) . ';"></span>любимая роль: ' . esc($roleLbl[$player['fav_role']]) . '</span>';
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
    $roleClr = ['civ' => '#e8332a', 'sher' => '#e6b13a', 'maf' => '#50505a', 'don' => '#0e0e12'];
    $wn = 0; $ls = 0; $dr = 0;
    $winRed = 0; $winBlk = 0; $lossRed = 0; $lossBlk = 0;
    $foulsSum = 0; $techSum = 0;
    $seatG = array_fill(1, 10, 0); $seatW = array_fill(1, 10, 0);
    $resDesc = []; // исходы от новых к старым: 'W' / 'L' / 'D'
    foreach ($history as $h) {
        $foulsSum += (int)$h['fouls']; $techSum += (int)$h['tech_fouls'];
        if ($h['winner'] === 'draw') {
            $dr++; $resDesc[] = 'D';
            continue;
        }
        $isRed = in_array($h['role'], ['civ', 'sheriff'], true);
        $won = ($h['winner'] === 'red' && $isRed) || ($h['winner'] === 'black' && !$isRed);
        if ($won) { $wn++; $isRed ? $winRed++ : $winBlk++; } else { $ls++; $isRed ? $lossRed++ : $lossBlk++; }
        $resDesc[] = $won ? 'W' : 'L';
        $seat = (int)$h['seat'];
        if ($seat >= 1 && $seat <= 10) { $seatG[$seat]++; if ($won) { $seatW[$seat]++; } }
    }
    $eh = db()->prepare('SELECT elo_after, gdate FROM elo_history WHERE player_id = ? ORDER BY id');
    $eh->execute([$id]);
    $eloSeries = [1000.0];
    $eloDates = ['старт'];
    foreach ($eh->fetchAll() as $r) {
        $eloSeries[] = round((float)$r['elo_after'], 1);
        $eloDates[] = $r['gdate'] ? date('d.m.y', strtotime($r['gdate'])) : '';
    }
    $chartData = json_encode([
        'outcomes' => [$winRed, $winBlk, $lossRed, $lossBlk, $dr],
        'elo' => $eloSeries,
        'eloDates' => $eloDates,
    ], JSON_UNESCAPED_UNICODE);

    // ── Графики ──
    echo '<div class="grid-2eq">';
    echo '<div class="card"><h2 style="margin-top:0;font-size:15px;">Динамика ELO · сейчас ' . $elo . '</h2>'
        . '<div style="position:relative;height:210px;"><canvas id="ch-elo"></canvas></div></div>';
    echo '<div class="card"><h2 style="margin-top:0;font-size:15px;">Исходы игр</h2>'
        . '<div style="position:relative;height:210px;"><canvas id="ch-results"></canvas></div></div>';
    echo '</div>';

    // ── Винрейт по ролям (бары) + Показатели ──
    echo '<div class="grid-2eq">';
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

    // ── Раскладка по ролям (%) + Команды ──
    $redG = (int)$stats['g_civ'] + (int)$stats['g_sher'];
    $redW = (int)$stats['w_civ'] + (int)$stats['w_sher'];
    $blkG = (int)$stats['g_maf'] + (int)$stats['g_don'];
    $blkW = (int)$stats['w_maf'] + (int)$stats['w_don'];

    echo '<div class="grid-2eq">';
    echo '<div class="card"><h2 style="margin-top:0;">Раскладка по ролям</h2>'
        . '<p style="color:var(--tx2);font-size:12.5px;margin:-4px 0 12px;">как часто играл за каждую роль</p><div class="role-bars">';
    foreach ($roleOrder as [$rk, $rl2]) {
        $g = (int)$stats['g_' . $rk];
        $pct = $games ? round($g / $games * 100) : 0;
        echo '<div class="role-bar"><span class="rb-name">' . $rl2 . '</span>'
            . '<span class="rb-track"><span class="rb-fill" style="width:' . $pct . '%;background:' . $roleClr[$rk] . ';"></span></span>'
            . '<span class="rb-val"><b>' . $pct . '%</b> ' . $g . '</span></div>';
    }
    echo '</div></div>';

    echo '<div class="card"><h2 style="margin-top:0;">Красные и чёрные</h2>';
    $tg = ($redG + $blkG) ?: 1;
    $rpp = round($redG / $tg * 100);
    echo '<div class="bal-bar"><span style="width:' . $rpp . '%;background:#c0392b;"></span><span style="width:' . (100 - $rpp) . '%;background:#33333c;"></span></div>';
    echo '<div class="bal-legend"><span><i style="background:#c0392b;"></i>Красные ' . $rpp . '%</span><span><i style="background:#33333c;"></i>Чёрные ' . (100 - $rpp) . '%</span></div>';
    echo '<table class="tbl" style="margin-top:10px;"><tr><th>Команда</th><th class="num">Игр</th><th class="num">Побед</th><th class="num">Винрейт</th></tr>';
    $teamRow = function (string $lbl, int $g, int $w): string {
        $wr = $g ? round($w / $g * 100) . '%' : '—';
        return '<tr><td>' . $lbl . '</td><td class="num">' . $g . '</td><td class="num">' . $w . '</td><td class="num"><b>' . $wr . '</b></td></tr>';
    };
    echo $teamRow('🔴 Красные', $redG, $redW);
    echo $teamRow('⚫ Чёрные', $blkG, $blkW);
    echo '</table></div></div>';

    // ── Серии и форма + Привычки ──
    $curStreak = 0; $curType = '';
    foreach ($resDesc as $r) {
        if ($r === 'D') { break; }
        if ($curType === '') { $curType = $r; $curStreak = 1; } elseif ($r === $curType) { $curStreak++; } else { break; }
    }
    $maxW = 0; $maxL = 0; $rw = 0; $rl = 0;
    foreach (array_reverse($resDesc) as $r) {
        if ($r === 'W') { $rw++; $rl = 0; } elseif ($r === 'L') { $rl++; $rw = 0; } else { $rw = 0; $rl = 0; }
        $maxW = max($maxW, $rw); $maxL = max($maxL, $rl);
    }
    $form = array_slice($resDesc, 0, 14);

    echo '<div class="grid-2eq">';
    echo '<div class="card"><h2 style="margin-top:0;">Серии и форма</h2>';
    $stType = $curType === 'W' ? 'побед подряд' : ($curType === 'L' ? 'поражений подряд' : 'нет серии');
    $stColor = $curType === 'W' ? 'var(--ok)' : ($curType === 'L' ? 'var(--ac)' : 'var(--tx2)');
    echo '<div style="display:flex;gap:20px;flex-wrap:wrap;align-items:flex-end;">';
    echo '<div><div style="font-size:32px;font-weight:750;color:' . $stColor . ';line-height:1;">' . ($curStreak ?: '—') . '</div><div style="font-size:12px;color:var(--tx2);margin-top:3px;">' . $stType . '</div></div>';
    echo '<div style="color:var(--tx2);font-size:13px;line-height:1.7;">макс. побед подряд: <b style="color:var(--tx);">' . $maxW . '</b><br>макс. поражений подряд: <b style="color:var(--tx);">' . $maxL . '</b></div>';
    echo '</div>';
    echo '<div style="font-size:12px;color:var(--tx2);margin:14px 0 5px;">последние игры (новые слева):</div><div style="display:flex;gap:5px;flex-wrap:wrap;">';
    foreach ($form as $r) {
        $c = $r === 'W' ? 'var(--ok)' : ($r === 'L' ? 'var(--ac)' : 'var(--tx3)');
        $sym = $r === 'W' ? '+' : ($r === 'L' ? '−' : '=');
        echo '<span style="width:22px;height:22px;border-radius:6px;background:' . $c . ';display:inline-flex;align-items:center;justify-content:center;font-size:13px;color:#fff;font-weight:700;">' . $sym . '</span>';
    }
    echo '</div></div>';

    echo '<div class="card"><h2 style="margin-top:0;">Привычки и особенности</h2><table class="tbl">';
    $puPct = $games ? round((int)$stats['pu_count'] / $games * 100) : 0;
    $avgScore = $games ? number_format($stats['sum_total'] / $games, 2) : '—';
    $avgFouls = $games ? number_format($foulsSum / $games, 2) : '—';
    $bestRole = null; $worstRole = null; $bestWr = -1; $worstWr = 101;
    foreach ($roleOrder as [$rk, $rl2]) {
        $g = (int)$stats['g_' . $rk];
        if ($g < 4) { continue; }
        $wrr = round((int)$stats['w_' . $rk] / $g * 100);
        if ($wrr > $bestWr) { $bestWr = $wrr; $bestRole = $rl2 . ' · ' . $wrr . '%'; }
        if ($wrr < $worstWr) { $worstWr = $wrr; $worstRole = $rl2 . ' · ' . $wrr . '%'; }
    }
    echo '<tr><td style="color:var(--tx2);">Среднее за игру</td><td class="num"><b>' . $avgScore . '</b></td></tr>';
    echo '<tr><td style="color:var(--tx2);">Был первоубиенным</td><td class="num">' . (int)$stats['pu_count'] . ' <span style="color:var(--tx2);">(' . $puPct . '%)</span></td></tr>';
    echo '<tr><td style="color:var(--tx2);">Средние фолы за игру</td><td class="num">' . $avgFouls . '</td></tr>';
    echo '<tr><td style="color:var(--tx2);">Техфолы всего</td><td class="num">' . $techSum . '</td></tr>';
    echo '<tr><td style="color:var(--tx2);">Коронная роль</td><td class="num"><b style="color:var(--ok);">' . ($bestRole ?: '—') . '</b></td></tr>';
    echo '<tr><td style="color:var(--tx2);">Тяжёлая роль</td><td class="num">' . ($worstRole ?: '—') . '</td></tr>';
    echo '</table></div></div>';

    // ── Счастливое место (винрейт по местам за столом) ──
    if (array_sum($seatG) > 0) {
        echo '<div class="card"><h2 style="margin-top:0;">Счастливое место</h2>'
            . '<p style="color:var(--tx2);font-size:12.5px;margin:-4px 0 14px;">винрейт в зависимости от места за столом (по решённым играм)</p>';
        echo '<div class="seat-grid">';
        for ($s = 1; $s <= 10; $s++) {
            $sg = $seatG[$s]; $sw = $seatW[$s]; $spct = $sg ? round($sw / $sg * 100) : 0;
            $col = $sg ? ($spct >= 60 ? 'var(--ok)' : ($spct < 42 ? 'var(--ac)' : 'var(--ac-h)')) : 'var(--sf2)';
            echo '<div class="seat-col"><div class="seat-bar-wrap"><div class="seat-bar" style="height:' . ($sg ? $spct : 0) . '%;background:' . $col . ';"></div></div>'
                . '<div class="seat-pct">' . ($sg ? $spct . '%' : '—') . '</div><div class="seat-no">' . $s . '</div></div>';
        }
        echo '</div></div>';
    }

    // ── Достижения (ачивки) ──
    $triples = 0;
    try {
        $tq = db()->prepare("SELECT COUNT(*) FROM games g
            JOIN game_seats me ON me.game_id = g.id AND me.player_id = ? AND me.seat = g.first_killed_seat AND me.role IN ('civ','sheriff')
            WHERE g.status = 'finished' AND g.bm_seat1 BETWEEN 1 AND 10 AND g.bm_seat2 BETWEEN 1 AND 10 AND g.bm_seat3 BETWEEN 1 AND 10
              AND (SELECT COUNT(*) FROM game_seats s WHERE s.game_id = g.id AND s.seat IN (g.bm_seat1, g.bm_seat2, g.bm_seat3) AND s.role IN ('maf','don')) = 3");
        $tq->execute([$id]);
        $triples = (int)$tq->fetchColumn();
    } catch (Throwable $e) {
    }
    $donWr = (int)$stats['g_don'] >= 4 ? round((int)$stats['w_don'] / (int)$stats['g_don'] * 100) : 0;
    $ach = [
        ['🎬', 'Дебют', 'Первая игра', $games >= 1],
        ['🎯', 'Десятка', '10 игр сыграно', $games >= 10],
        ['🏛', 'Ветеран', '100 игр сыграно', $games >= 100],
        ['🔥', 'На кураже', '3 победы подряд', $maxW >= 3],
        ['⚡', 'Неудержимый', '5 побед подряд', $maxW >= 5],
        ['⭐', 'Сильный', 'ELO 1500+', $elo >= 1500],
        ['💎', 'Эксперт', 'ELO 2000+', $elo >= 2000],
        ['👑', 'Мастер', 'ELO 2600+', $elo >= 2600],
        ['➕', 'Щедрый на допы', '30+ допов всего', (float)$stats['dop_sum'] >= 30],
        ['🎖', 'Тройка в ЛХ', 'Лучший ход 3 из 3', $triples >= 1],
        ['😈', 'Дон-мастер', '60%+ за дона (от 4 игр)', $donWr >= 60],
        ['🩸', 'Живучий', 'ПУ менее 20% игр (от 20)', $games >= 20 && $puPct < 20],
    ];
    $earnedN = count(array_filter($ach, fn($a) => $a[3]));
    echo '<div class="card"><div class="section-head"><h2 style="margin:0;">Достижения</h2>'
        . '<span style="font-size:13px;color:var(--tx2);">' . $earnedN . ' из ' . count($ach) . '</span></div>';
    echo '<div class="ach-grid">';
    foreach ($ach as [$ic, $t, $d, $ok]) {
        echo '<div class="ach' . ($ok ? ' ach-on' : '') . '"><div class="ach-ic">' . $ic . '</div>'
            . '<div class="ach-t">' . $t . '</div><div class="ach-d">' . $d . '</div></div>';
    }
    echo '</div></div>';

    // ── Chart.js: ELO с уровнями + исходы по командам ──
    echo '<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>';
    echo '<script src="https://cdnjs.cloudflare.com/ajax/libs/chartjs-plugin-datalabels/2.2.0/chartjs-plugin-datalabels.min.js"></script>';
    $js = <<<JS
<script>(function(){
var D = $chartData;
if (typeof Chart === 'undefined') return;
if (window.ChartDataLabels) Chart.register(window.ChartDataLabels);
Chart.defaults.set('plugins.datalabels', {display:false});
var pctLabel={display:true,color:'#fff',font:{weight:'600',size:11},formatter:function(v,ctx){var s=ctx.dataset.data.reduce(function(a,b){return a+(+b||0);},0);return s&&v?Math.round(v/s*100)+'%':'';}};
var grid='rgba(255,255,255,0.08)', tx='#9c9ca6', red='#e8332a';
Chart.defaults.color = tx;
Chart.defaults.font.family = "system-ui,-apple-system,'Segoe UI',Roboto,sans-serif";
function tierName(v){ return v>=2600?'Мастер':(v>=2000?'Сильный':(v>=1500?'Уверенный':(v>=1100?'Игрок':'Новичок'))); }
var TIERS=[{f:0,t:1100,n:'Новичок',c:'rgba(140,140,150,0.05)'},
  {f:1100,t:1500,n:'Игрок',c:'rgba(58,123,213,0.07)'},
  {f:1500,t:2000,n:'Уверенный',c:'rgba(213,162,58,0.07)'},
  {f:2000,t:2600,n:'Сильный',c:'rgba(232,51,42,0.08)'},
  {f:2600,t:99999,n:'Мастер',c:'rgba(232,51,42,0.14)'}];
var tierBands={id:'tierBands',beforeDatasetsDraw:function(ch){
  var ya=ch.scales.y, ar=ch.chartArea; if(!ya||!ar) return;
  var c=ch.ctx; c.save();
  TIERS.forEach(function(T){
    var y1=ya.getPixelForValue(Math.min(T.t,ya.max));
    var y2=ya.getPixelForValue(Math.max(T.f,ya.min));
    if(y2<=ar.top||y1>=ar.bottom) return;
    var top=Math.max(y1,ar.top), bot=Math.min(y2,ar.bottom);
    c.fillStyle=T.c; c.fillRect(ar.left,top,ar.right-ar.left,bot-top);
    if(bot-top>15){ c.fillStyle='rgba(255,255,255,0.32)'; c.font='10px system-ui'; c.textAlign='right';
      c.fillText(T.n, ar.right-6, top+12); }
  });
  c.restore();
}};
new Chart(document.getElementById('ch-elo'),{type:'line',
  data:{labels:D.eloDates,
    datasets:[{data:D.elo,borderColor:red,backgroundColor:'rgba(232,51,42,0.10)',fill:true,tension:0.25,pointRadius:0,borderWidth:2}]},
  options:{plugins:{legend:{display:false},tooltip:{callbacks:{title:function(items){return items&&items[0]?items[0].label:'';},
    label:function(c){return 'ELO '+Math.round(c.parsed.y)+' · '+tierName(c.parsed.y);}}}},
    scales:{x:{display:true,grid:{display:false},ticks:{color:tx,font:{size:10},maxTicksLimit:6,autoSkip:true,maxRotation:0}},y:{grid:{color:grid}}},maintainAspectRatio:false},
  plugins:[tierBands]});
new Chart(document.getElementById('ch-results'),{type:'doughnut',
  data:{labels:['Победа красным','Победа чёрным','Поражение красным','Поражение чёрным','Ничья'],
    datasets:[{data:D.outcomes,backgroundColor:['#2fa45c','#1f7a45','#e8332a','#8c2420','#888'],borderWidth:0}]},
  options:{plugins:{legend:{position:'bottom',labels:{boxWidth:12,font:{size:11}}},datalabels:pctLabel},maintainAspectRatio:false}});
})();</script>
JS;
    echo $js;
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
