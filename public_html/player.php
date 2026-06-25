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
$flairStr = trim((string)($player['flair'] ?? ''));
$flairHtml = '';
if ($flairStr !== '') {
    $cps = [];
    foreach (mb_str_split($flairStr) as $ch) {
        $cps[] = dechex(mb_ord($ch));
    }
    $flairHtml = ' <span class="flair flair-anim" data-cp="' . esc(implode('_', $cps)) . '">' . esc($flairStr) . '</span>';
}
echo '<div class="pf-id"><h1 class="pf-name">' . esc($player['nickname']) . $flairHtml . '</h1>';
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
    $formRows = []; // [роль, исход] от новых к старым
    foreach ($history as $h) {
        $foulsSum += (int)$h['fouls']; $techSum += (int)$h['tech_fouls'];
        if ($h['winner'] === 'draw') {
            $dr++; $resDesc[] = 'D'; $formRows[] = ['role' => $h['role'], 'res' => 'D'];
            continue;
        }
        $isRed = in_array($h['role'], ['civ', 'sheriff'], true);
        $won = ($h['winner'] === 'red' && $isRed) || ($h['winner'] === 'black' && !$isRed);
        if ($won) { $wn++; $isRed ? $winRed++ : $winBlk++; } else { $ls++; $isRed ? $lossRed++ : $lossBlk++; }
        $resDesc[] = $won ? 'W' : 'L';
        $formRows[] = ['role' => $h['role'], 'res' => $won ? 'W' : 'L'];
        $seat = (int)$h['seat'];
        if ($seat >= 1 && $seat <= 10) { $seatG[$seat]++; if ($won) { $seatW[$seat]++; } }
    }
    $eh = db()->prepare('SELECT eh.elo_after, eh.gdate, g.context, g.day_id, g.tournament_id
        FROM elo_history eh LEFT JOIN games g ON g.id = eh.game_id
        WHERE eh.player_id = ? ORDER BY eh.id');
    $eh->execute([$id]);
    $eloSeries = [1000.0];
    $eloDates = ['старт'];
    $eloLinks = ['']; // старт — без ссылки
    foreach ($eh->fetchAll() as $r) {
        $eloSeries[] = round((float)$r['elo_after'], 1);
        $eloDates[] = $r['gdate'] ? date('d.m.y', strtotime($r['gdate'])) : '';
        if ($r['context'] === 'day' && $r['day_id']) {
            $eloLinks[] = '/day.php?id=' . (int)$r['day_id'];
        } elseif ($r['context'] === 'tournament' && $r['tournament_id']) {
            $eloLinks[] = '/tournament.php?id=' . (int)$r['tournament_id'];
        } else {
            $eloLinks[] = '';
        }
    }
    $chartData = json_encode([
        'outcomes' => [$winRed, $winBlk, $lossRed, $lossBlk, $dr],
        'roleDist' => [(int)$stats['g_civ'], (int)$stats['g_sher'], (int)$stats['g_maf'], (int)$stats['g_don']],
        'elo' => $eloSeries,
        'eloDates' => $eloDates,
        'eloLinks' => $eloLinks,
    ], JSON_UNESCAPED_UNICODE);

    // ── Графики ──
    echo '<div class="grid-2eq">';
    echo '<div class="card"><h2 style="margin-top:0;font-size:15px;">Динамика ELO · сейчас ' . $elo
        . ' <span style="color:var(--ac);font-size:13px;">· ' . esc(elo_tier_name((float)$elo)) . '</span></h2>'
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
        ['🥇 MVP вечеров', (int)($stats['mvp_evenings'] ?? 0)],
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
    echo '<div class="card"><h2 style="margin-top:0;font-size:15px;">Сколько играли за роль</h2>'
        . '<div style="position:relative;height:210px;"><canvas id="ch-roledist"></canvas></div></div>';

    echo '<div class="card"><h2 style="margin-top:0;">По команде (цвету)</h2>';
    echo '<table class="tbl"><tr><th>Команда</th><th class="num">Игр</th><th class="num">Побед</th><th class="num">Винрейт</th></tr>';
    $teamRow = function (string $lbl, int $g, int $w): string {
        $wr = $g ? round($w / $g * 100) . '%' : '—';
        return '<tr><td>' . $lbl . '</td><td class="num">' . $g . '</td><td class="num">' . $w . '</td><td class="num"><b>' . $wr . '</b></td></tr>';
    };
    echo $teamRow('🔴 Красные (мир+шериф)', $redG, $redW);
    echo $teamRow('⚫ Чёрные (мафия+дон)', $blkG, $blkW);
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
    $form = array_reverse(array_slice($formRows, 0, 10)); // последние 10, новые справа
    $roleAb = ['civ' => 'мир', 'sheriff' => 'шер', 'maf' => 'маф', 'don' => 'дон'];

    echo '<div class="grid-2eq">';
    echo '<div class="card"><h2 style="margin-top:0;">Серии и форма</h2>';
    $stType = $curType === 'W' ? 'побед подряд' : ($curType === 'L' ? 'поражений подряд' : 'нет серии');
    $stColor = $curType === 'W' ? 'var(--ok)' : ($curType === 'L' ? 'var(--ac)' : 'var(--tx2)');
    echo '<div style="display:flex;gap:20px;flex-wrap:wrap;align-items:flex-end;">';
    echo '<div><div style="font-size:32px;font-weight:750;color:' . $stColor . ';line-height:1;">' . ($curStreak ?: '—') . '</div><div style="font-size:12px;color:var(--tx2);margin-top:3px;">' . $stType . '</div></div>';
    echo '<div style="color:var(--tx2);font-size:13px;line-height:1.7;">макс. побед подряд: <b style="color:var(--tx);">' . $maxW . '</b><br>макс. поражений подряд: <b style="color:var(--tx);">' . $maxL . '</b></div>';
    echo '</div>';
    echo '<div style="font-size:12px;color:var(--tx2);margin:14px 0 5px;">последние 10 игр (новые справа):</div><div style="display:flex;gap:5px;flex-wrap:wrap;">';
    foreach ($form as $fr) {
        $c = $fr['res'] === 'W' ? 'var(--ok)' : ($fr['res'] === 'L' ? 'var(--ac)' : 'var(--tx3)');
        echo '<span style="padding:3px 8px;border-radius:6px;background:' . $c . ';display:inline-flex;align-items:center;justify-content:center;font-size:11.5px;color:#fff;font-weight:600;">' . ($roleAb[$fr['role']] ?? '?') . '</span>';
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

    // ── Напарники и соперники ──
    $wr = fn(int $w, int $g): string => $g ? round($w / $g * 100) . '%' : '—';
    $teamOf = fn(string $r): string => in_array($r, ['civ', 'sheriff'], true) ? 'red' : 'black';
    $sbg = [];
    $gids = array_column($history, 'game_id');
    if ($gids) {
        $in = implode(',', array_fill(0, count($gids), '?'));
        $sq = db()->prepare("SELECT gs.game_id, gs.player_id, gs.role, p.nickname FROM game_seats gs JOIN players p ON p.id = gs.player_id WHERE gs.game_id IN ($in)");
        $sq->execute($gids);
        foreach ($sq->fetchAll() as $row) { $sbg[(int)$row['game_id']][] = $row; }
    }
    $teammates = []; $opponents = [];
    foreach ($history as $h) {
        $col = $teamOf($h['role']);
        $won = ($h['winner'] === 'red' && $col === 'red') || ($h['winner'] === 'black' && $col === 'black');
        $draw = $h['winner'] === 'draw';
        foreach ($sbg[(int)$h['game_id']] ?? [] as $o) {
            $opid = (int)$o['player_id']; if ($opid === $id) { continue; }
            $oc = $teamOf($o['role']);
            if ($oc === $col) {
                $teammates[$opid] = $teammates[$opid] ?? ['nick' => $o['nickname'], 'games' => 0, 'wins' => 0];
                $teammates[$opid]['games']++; if ($won) { $teammates[$opid]['wins']++; }
            } else {
                $opponents[$opid] = $opponents[$opid] ?? ['nick' => $o['nickname'], 'games' => 0, 'beat' => 0, 'lost' => 0];
                $opponents[$opid]['games']++;
                if ($won) { $opponents[$opid]['beat']++; } elseif (!$draw) { $opponents[$opid]['lost']++; }
            }
        }
    }
    $pmeta = [];
    foreach (db()->query('SELECT id, avatar, flair FROM players') as $pm) {
        $pmeta[(int)$pm['id']] = ['avatar' => $pm['avatar'] ?? '', 'flair' => (string)($pm['flair'] ?? '')];
    }
    $pcell = function (int $opid, string $nick) use ($pmeta) {
        $a = $pmeta[$opid] ?? ['avatar' => '', 'flair' => ''];
        return '<a href="/player.php?id=' . $opid . '" style="display:inline-flex;align-items:center;gap:8px;color:var(--tx);">'
            . avatar_html(['nickname' => $nick, 'avatar' => $a['avatar']], 24)
            . '<span>' . esc($nick) . ($a['flair'] !== '' ? ' <span class="flair">' . esc($a['flair']) . '</span>' : '') . '</span></a>';
    };
    if ($teammates || $opponents) {
        $bestMate = null;
        foreach ($teammates as $opid => $m) {
            if ($bestMate === null || $m['wins'] > $bestMate['wins'] || ($m['wins'] === $bestMate['wins'] && $m['games'] > $bestMate['games'])) {
                $bestMate = $m + ['id' => $opid];
            }
        }
        $worstMate = null;
        foreach ($teammates as $opid => $m) {
            if ($m['games'] < 5 || ($bestMate && $opid === $bestMate['id'])) { continue; }
            $wrM = $m['wins'] / $m['games'];
            if ($worstMate === null || $wrM < $worstMate['wr'] || (abs($wrM - $worstMate['wr']) < 1e-9 && $m['games'] > $worstMate['games'])) {
                $worstMate = $m + ['id' => $opid, 'wr' => $wrM];
            }
        }
        $mateCard = function (?array $m, string $title, string $icon, bool $good) use ($pmeta) {
            if (!$m || ($m['games'] ?? 0) < 1) {
                return '<div class="card mate-card"><div class="mate-ttl">' . $icon . ' ' . $title . '</div>'
                    . '<p style="color:var(--tx2);margin:0;">Пока мало совместных игр для расчёта.</p></div>';
            }
            $pid2 = (int)$m['id'];
            $a = $pmeta[$pid2] ?? ['avatar' => '', 'flair' => ''];
            $pct = $m['games'] ? round($m['wins'] / $m['games'] * 100) : 0;
            $avaStyle = $good ? 'background:var(--acsf);color:var(--ac);' : 'background:var(--sf2);color:var(--tx2);';
            $brd = $good ? 'rgba(47,164,92,0.45)' : 'rgba(232,51,42,0.45)';
            return '<div class="card mate-card" style="border-color:' . $brd . ';">'
                . '<div class="mate-ttl">' . $icon . ' ' . $title . '</div><div class="mate-body">'
                . avatar_html(['nickname' => $m['nick'], 'avatar' => $a['avatar']], 52, $avaStyle)
                . '<div class="mate-info"><a class="mate-name" href="/player.php?id=' . $pid2 . '">' . esc($m['nick'])
                . ($a['flair'] !== '' ? ' <span class="flair">' . esc($a['flair']) . '</span>' : '') . '</a>'
                . '<div class="mate-sub">' . $m['wins'] . ' побед в ' . $m['games'] . ' играх вместе</div></div>'
                . '<div class="mate-wr" style="color:' . ($good ? 'var(--ok)' : 'var(--ac)') . ';">' . $pct . '%<span>винрейт</span></div>'
                . '</div></div>';
        };
        echo '<div class="grid-2eq">';
        echo $mateCard(($bestMate && $bestMate['wins'] > 0) ? $bestMate : null, 'Лучший напарник в одном цвете', '🤝', true);
        echo $mateCard($worstMate, 'Неудачный напарник в одном цвете', '🥶', false);
        echo '</div>';

        uasort($teammates, fn($a, $b) => [$b['wins'], $b['games']] <=> [$a['wins'], $a['games']]);
        echo '<div class="grid-2"><div class="card"><h2 style="margin-top:0;">В одном цвете: больше всего побед</h2>';
        $topMates = array_slice($teammates, 0, 12, true);
        if ($topMates) {
            echo '<table class="tbl"><tr><th>Игрок</th><th class="num">Вместе</th><th class="num">Побед</th><th class="num">Винрейт</th></tr>';
            foreach ($topMates as $opid => $m) {
                echo '<tr><td>' . $pcell($opid, $m['nick']) . '</td><td class="num">' . $m['games'] . '</td><td class="num">' . $m['wins'] . '</td><td class="num">' . $wr($m['wins'], $m['games']) . '</td></tr>';
            }
            echo '</table>';
        } else { echo '<p style="color:var(--tx2);">Нет данных.</p>'; }
        echo '</div>';
        $chem = array_filter($teammates, fn($m) => $m['games'] >= 4);
        uasort($chem, fn($a, $b) => ($b['wins'] / $b['games']) <=> ($a['wins'] / $a['games']));
        $bestChem = array_slice($chem, 0, 8, true);
        echo '<div class="card"><h2 style="margin-top:0;">Лучшая «химия» <span style="font-size:12px;color:var(--tx2);">(от 4 игр вместе)</span></h2>';
        if ($bestChem) {
            echo '<table class="tbl"><tr><th>Игрок</th><th class="num">Вместе</th><th class="num">Винрейт</th></tr>';
            foreach ($bestChem as $opid => $m) {
                echo '<tr><td>' . $pcell($opid, $m['nick']) . '</td><td class="num">' . $m['games'] . '</td><td class="num">' . $wr($m['wins'], $m['games']) . '</td></tr>';
            }
            echo '</table>';
        } else { echo '<p style="color:var(--tx2);">Пока мало совместных игр для расчёта.</p>'; }
        echo '</div></div>';

        $beat = $opponents; uasort($beat, fn($a, $b) => [$b['beat'], $b['games']] <=> [$a['beat'], $a['games']]);
        $lostTo = $opponents; uasort($lostTo, fn($a, $b) => [$b['lost'], $b['games']] <=> [$a['lost'], $a['games']]);
        echo '<h2 style="margin-top:8px;">Разноцветы — против кого играли</h2><div class="grid-2">';
        echo '<div class="card"><h2 style="margin-top:0;">Кого чаще всего обыгрывали</h2>';
        $topb = array_slice(array_filter($beat, fn($m) => $m['beat'] > 0), 0, 12, true);
        if ($topb) {
            echo '<table class="tbl"><tr><th>Игрок</th><th class="num">Обыграли</th><th class="num">Игр против</th><th class="num">%</th></tr>';
            foreach ($topb as $opid => $m) {
                echo '<tr><td>' . $pcell($opid, $m['nick']) . '</td><td class="num"><b>' . $m['beat'] . '</b></td><td class="num">' . $m['games'] . '</td><td class="num" style="color:var(--ok);">' . $wr($m['beat'], $m['games']) . '</td></tr>';
            }
            echo '</table>';
        } else { echo '<p style="color:var(--tx2);">Нет данных.</p>'; }
        echo '</div>';
        echo '<div class="card"><h2 style="margin-top:0;">Кому чаще всего проигрывали</h2>';
        $topl = array_slice(array_filter($lostTo, fn($m) => $m['lost'] > 0), 0, 12, true);
        if ($topl) {
            echo '<table class="tbl"><tr><th>Игрок</th><th class="num">Проиграли</th><th class="num">Игр против</th><th class="num">%</th></tr>';
            foreach ($topl as $opid => $m) {
                echo '<tr><td>' . $pcell($opid, $m['nick']) . '</td><td class="num"><b style="color:var(--ac);">' . $m['lost'] . '</b></td><td class="num">' . $m['games'] . '</td><td class="num" style="color:var(--ac);">' . $wr($m['lost'], $m['games']) . '</td></tr>';
            }
            echo '</table>';
        } else { echo '<p style="color:var(--tx2);">Нет данных.</p>'; }
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
    // доп. серии и рекорды для ачивок
    $chrono = array_reverse($history); // старые → новые
    $blackStreak = 0; $bs = 0;
    $redWinStreak = 0; $rws = 0;
    $maxPlusGame = 0.0;
    foreach ($chrono as $h) {
        $maxPlusGame = max($maxPlusGame, (float)$h['plus']);
        $isBlack = in_array($h['role'], ['maf', 'don'], true);
        if ($isBlack) { $bs++; $blackStreak = max($blackStreak, $bs); } else { $bs = 0; }
        if (!$isBlack && $h['winner'] === 'red') { $rws++; $redWinStreak = max($redWinStreak, $rws); } else { $rws = 0; }
    }
    $maxEloDay = 0.0;
    try {
        $ed = db()->prepare('SELECT SUM(delta) s FROM elo_history WHERE player_id = ? GROUP BY gdate');
        $ed->execute([$id]);
        foreach ($ed->fetchAll() as $r) { $maxEloDay = max($maxEloDay, (float)$r['s']); }
    } catch (Throwable $e) {
    }
    $cond = [
        'debut' => $games >= 1, 'ten' => $games >= 10, 'veteran' => $games >= 100,
        'streak3' => $maxW >= 3, 'streak5' => $maxW >= 5, 'streak8' => $maxW >= 8, 'streak10' => $maxW >= 10,
        'black5' => $blackStreak >= 5, 'red3' => $redWinStreak >= 3,
        'elo1000' => $elo >= 1000, 'elo1250' => $elo >= 1250, 'elo1450' => $elo >= 1450, 'elo1650' => $elo >= 1650, 'elo1850' => $elo >= 1850,
        'eloday' => $maxEloDay >= 150,
        'dop30' => (float)$stats['dop_sum'] >= 30, 'fatgame' => $maxPlusGame >= 1.5,
        'triple' => $triples >= 1, 'don' => $donWr >= 60, 'danger' => (int)$stats['pu_count'] >= 5,
    ];
    $cat = achievements_catalog();
    $earners = achievement_earners();
    $earnedN = 0;
    foreach ($cat as $k => $c) {
        if (!empty($cond[$k])) {
            $earnedN++;
        }
    }
    echo '<div class="card"><div class="section-head"><h2 style="margin:0;">Достижения</h2>'
        . '<span style="font-size:13px;color:var(--tx2);">получено ' . $earnedN . ' из ' . count($cat) . '</span></div>';
    $byGroup = [];
    foreach ($cat as $k => [$ic, $t, $d, $grp]) {
        $byGroup[$grp][$k] = [$ic, $t, $d];
    }
    foreach ($byGroup as $grp => $items) {
        echo '<div style="font-size:11.5px;color:var(--tx2);text-transform:uppercase;letter-spacing:0.6px;margin:12px 0 6px;">' . esc($grp) . '</div>';
        echo '<div class="ach-grid">';
        foreach ($items as $k => [$ic, $t, $d]) {
            $ok = !empty($cond[$k]);
            $who = $earners[$k] ?? [];
            $cnt = count($who);
            $names = array_map(fn($e) => $e[1], $who);
            $tip = $cnt ? 'Получили (' . $cnt . '): ' . implode(', ', array_slice($names, 0, 40)) : 'Пока ни у кого';
            $whoJson = esc(json_encode(array_slice($who, 0, 200), JSON_UNESCAPED_UNICODE));
            echo '<div class="ach' . ($ok ? ' ach-on' : '') . '" data-who="' . $whoJson . '" title="' . esc($tip) . '">'
                . '<div class="ach-ic">' . $ic . '</div>'
                . '<div class="ach-t">' . esc($t) . '</div><div class="ach-d">' . esc($d) . '</div>'
                . '<div class="ach-cnt">' . ($ok ? '✓ ' : '') . $cnt . ' получ.</div></div>';
        }
        echo '</div>';
    }
    echo '</div>';

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
var TIERS=[{v:800,n:'Новичок',col:'120,132,124',a:0.10},
  {v:1100,n:'Любитель',col:'52,168,99',a:0.12},
  {v:1300,n:'Знаток',col:'40,165,170',a:0.13},
  {v:1500,n:'Эксперт',col:'64,120,224',a:0.15},
  {v:1700,n:'Мастер',col:'160,96,224',a:0.18},
  {v:1900,n:'Чемпион',col:'230,126,34',a:0.22},
  {v:2100,n:'Легенда',col:'232,184,48',a:0.26}];
function tierColor(v){ var T=TIERS[0]; for(var i=0;i<TIERS.length;i++){ if(v>=TIERS[i].v) T=TIERS[i]; } return 'rgba('+T.col+','+T.a+')'; }
function tierName(v){ var n=TIERS[0].n; for(var i=0;i<TIERS.length;i++){ if(v>=TIERS[i].v) n=TIERS[i].n; } return n; }
var tierBands={id:'tierBands',beforeDatasetsDraw:function(ch){
  var ya=ch.scales.y, ar=ch.chartArea; if(!ya||!ar) return;
  var c=ch.ctx, h=ar.bottom-ar.top; if(h<=0) return;
  var g=c.createLinearGradient(0,ar.top,0,ar.bottom), added={};
  function stop(off,col){ off=Math.max(0,Math.min(1,off)); var k=off.toFixed(4); if(added[k])return; added[k]=1; g.addColorStop(off,col); }
  stop(0, tierColor(ya.max));
  TIERS.forEach(function(T){ if(T.v>ya.min&&T.v<ya.max) stop((ya.getPixelForValue(T.v)-ar.top)/h, tierColor(T.v)); });
  stop(1, tierColor(ya.min));
  c.save();
  c.fillStyle=g; c.fillRect(ar.left,ar.top,ar.right-ar.left,h);
  c.textAlign='right'; c.font='600 10px system-ui';
  for(var i=0;i<TIERS.length;i++){
    var T=TIERS[i], nextV=(i+1<TIERS.length)?TIERS[i+1].v:ya.max;
    var bTop=ya.getPixelForValue(Math.min(nextV,ya.max)), bBot=ya.getPixelForValue(Math.max(T.v,ya.min));
    if(bBot<=ar.top||bTop>=ar.bottom) continue;
    var py=ya.getPixelForValue(T.v);
    if(T.v>ya.min&&py>ar.top&&py<ar.bottom){ c.strokeStyle='rgba(255,255,255,0.22)'; c.lineWidth=1;
      c.beginPath(); c.moveTo(ar.left,Math.round(py)+0.5); c.lineTo(ar.right,Math.round(py)+0.5); c.stroke(); }
    var top=Math.max(bTop,ar.top);
    if(Math.min(bBot,ar.bottom)-top>15){ c.fillStyle='rgba(255,255,255,0.55)'; c.fillText(T.n, ar.right-6, top+12); }
  }
  c.restore();
}};
var eloMax=Math.max.apply(null,D.elo), eloNextTier=null;
for(var ti=0;ti<TIERS.length;ti++){ if(TIERS[ti].v>eloMax){ eloNextTier=TIERS[ti].v; break; } }
var topTierV=TIERS[TIERS.length-1].v;
var eloSMax=eloNextTier?(eloNextTier+180):Math.max(eloMax+120,topTierV+260);
new Chart(document.getElementById('ch-elo'),{type:'line',
  data:{labels:D.eloDates,
    datasets:[{data:D.elo,borderColor:red,backgroundColor:'rgba(232,51,42,0.10)',fill:true,tension:0.25,pointRadius:0,pointHoverRadius:5,pointHoverBackgroundColor:red,pointHoverBorderColor:'#fff',pointHoverBorderWidth:2,borderWidth:2}]},
  options:{interaction:{intersect:false,mode:'index',axis:'x'},
    onClick:function(e,els){if(els&&els.length){var u=D.eloLinks[els[0].index];if(u){location.href=u;}}},
    onHover:function(e,els){if(e.native&&e.native.target){e.native.target.style.cursor=(els&&els.length&&D.eloLinks[els[0].index])?'pointer':'default';}},
    plugins:{legend:{display:false},tooltip:{animation:false,displayColors:false,callbacks:{title:function(items){return items&&items[0]?items[0].label:'';},
    label:function(c){var i=c.dataIndex,L=['ELO '+Math.round(c.parsed.y)+' · '+tierName(c.parsed.y)];
      if(i>0){var dl=Math.round(c.parsed.y-D.elo[i-1]);L.push((dl>0?'▲ +':(dl<0?'▼ ':'')) + dl + ' с прошлой игры');}else{L.push('старт');}return L;}}}},
    scales:{x:{display:true,grid:{display:false},ticks:{color:tx,font:{size:10},maxTicksLimit:6,autoSkip:true,maxRotation:0}},y:{min:800,max:eloSMax,grid:{display:false},afterBuildTicks:function(s){s.ticks=TIERS.filter(function(t){return t.v>=s.min&&t.v<=s.max;}).map(function(t){return {value:t.v};});}}},maintainAspectRatio:false},
  plugins:[tierBands]});
new Chart(document.getElementById('ch-results'),{type:'doughnut',
  data:{labels:['Победа красным','Победа чёрным','Поражение красным','Поражение чёрным','Ничья'],
    datasets:[{data:D.outcomes,backgroundColor:['#2fa45c','#1f7a45','#e8332a','#8c2420','#888'],borderWidth:0}]},
  options:{plugins:{legend:{position:'bottom',labels:{boxWidth:12,font:{size:11}}},datalabels:pctLabel},maintainAspectRatio:false}});
new Chart(document.getElementById('ch-roledist'),{type:'doughnut',
  data:{labels:['Мирный','Шериф','Мафия','Дон'],datasets:[{data:D.roleDist,backgroundColor:['#e8332a','#e6b13a','#50505a','#0e0e12'],borderColor:'#17171c',borderWidth:2}]},
  options:{plugins:{legend:{position:'bottom',labels:{boxWidth:12,font:{size:11}}},datalabels:pctLabel},maintainAspectRatio:false}});
})();</script>
JS;
    echo $js;
} else {
    echo '<p style="color:var(--tx2);">Сыгранных игр в основном рейтинге пока нет.</p>';
}

if ($history) {
    // дельта ELO по каждой игре
    $eloByGame = [];
    $edq = db()->prepare('SELECT game_id, delta FROM elo_history WHERE player_id = ? AND game_id IS NOT NULL');
    $edq->execute([$id]);
    foreach ($edq->fetchAll() as $r) { $eloByGame[(int)$r['game_id']] = (float)$r['delta']; }

    echo '<div class="card" style="overflow-x:auto;"><h2 style="margin-top:0;">История игр (' . count($history) . ')</h2>';
    echo '<table class="tbl">';
    echo '<tr><th>Дата</th><th>Где</th><th>Роль</th><th class="num">Допы</th><th class="num">Минусы</th><th class="num">Δ ELO</th><th>Результат</th></tr>';
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
        $plus = (float)$h['plus'];
        $minus = (float)$h['minus'] + ((int)$h['fouls'] >= 4 ? 0.6 : 0) + 0.3 * (int)$h['tech_fouls'];
        $dl = $eloByGame[(int)$h['game_id']] ?? null;
        $dlHtml = $dl === null ? '<span style="color:var(--tx3);">—</span>'
            : '<span style="color:' . ($dl > 0 ? 'var(--ok)' : ($dl < 0 ? 'var(--ac)' : 'var(--tx2)')) . ';">' . ($dl > 0 ? '+' : '') . number_format($dl, 1) . '</span>';
        echo '<tr><td>' . $date . '</td><td>' . $where . ' · игра ' . (int)$h['game_no'] . '</td>'
            . '<td>' . role_dot($h['role'])
            . $roleLabel[$h['role']] . ((int)$h['first_killed_seat'] === (int)$h['seat'] ? ' <span class="tag">ПУ</span>' : '') . '</td>'
            . '<td class="num">' . ($plus > 0 ? '+' . number_format($plus, 1) : '—') . '</td>'
            . '<td class="num">' . ($minus > 0 ? '−' . number_format($minus, 1) : '—') . '</td>'
            . '<td class="num">' . $dlHtml . '</td>'
            . '<td>' . $res . '</td></tr>';
    }
    echo '</table></div>';
}

// Живые стикеры-висюльки: анимация эмодзи (Google Noto), если доступна; иначе статичный эмодзи
echo '<script>(function(){var els=document.querySelectorAll(".flair-anim[data-cp]");if(!els.length){return;}'
    . 'var s=document.createElement("script");s.src="https://cdnjs.cloudflare.com/ajax/libs/lottie-web/5.12.2/lottie.min.js";'
    . 's.onload=function(){els.forEach(function(el){var cp=el.getAttribute("data-cp");'
    . 'fetch("https://fonts.gstatic.com/s/e/notoemoji/latest/"+cp+"/lottie.json").then(function(r){if(!r.ok){throw 0;}return r.json();})'
    . '.then(function(data){var box=document.createElement("span");box.style.cssText="display:inline-block;width:1.05em;height:1.05em;vertical-align:-0.18em;";'
    . 'el.textContent="";el.classList.add("is-lottie");el.appendChild(box);'
    . 'lottie.loadAnimation({container:box,renderer:"svg",loop:true,autoplay:true,animationData:data});}).catch(function(){});});};'
    . 'document.head.appendChild(s);})();</script>';

page_foot();
