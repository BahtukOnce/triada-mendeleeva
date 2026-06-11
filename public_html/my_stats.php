<?php
require dirname(__DIR__) . '/inc/bootstrap.php';

$u = require_login();
$player = current_player();

page_head('Моя статистика', '');
echo '<h1>Моя статистика</h1>';

if (!$player) {
    empty_state('Ник ещё не привязан', 'Привяжите игровой ник в личном кабинете, чтобы видеть статистику.');
    echo '<p style="text-align:center;"><a class="btn" href="/cabinet.php">В личный кабинет</a></p>';
    page_foot();
    exit;
}

$pid = (int)$player['id'];

$team = fn(string $role): string => in_array($role, ['civ', 'sheriff'], true) ? 'red' : 'black';

// Игры игрока
$st = db()->prepare("SELECT gs.seat, gs.role, gs.fouls, gs.tech_fouls, gs.plus, gs.minus,
        g.id AS game_id, g.winner, g.first_killed_seat, g.bm_seat1, g.bm_seat2, g.bm_seat3
    FROM game_seats gs JOIN games g ON g.id = gs.game_id
    WHERE gs.player_id = ? AND g.status = 'finished'");
$st->execute([$pid]);
$mine = $st->fetchAll();

if (!$mine) {
    empty_state('Игр пока нет', 'Статистика появится после первых сыгранных игр.');
    page_foot();
    exit;
}

$gameIds = array_column($mine, 'game_id');
$in = implode(',', array_fill(0, count($gameIds), '?'));
$st = db()->prepare("SELECT gs.game_id, gs.seat, gs.player_id, gs.role, p.nickname
    FROM game_seats gs JOIN players p ON p.id = gs.player_id WHERE gs.game_id IN ($in)");
$st->execute($gameIds);
$seatsByGame = [];
$roleBySeat = [];
foreach ($st->fetchAll() as $row) {
    $seatsByGame[(int)$row['game_id']][] = $row;
    $roleBySeat[(int)$row['game_id']][(int)$row['seat']] = $row['role'];
}

// Агрегаты
$games = 0; $wins = 0; $draws = 0;
$byRole = ['civ' => [0, 0], 'sheriff' => [0, 0], 'maf' => [0, 0], 'don' => [0, 0]];
$byColor = ['red' => [0, 0], 'black' => [0, 0]];
$puCount = 0; $puAsRed = 0; $lhEarned = 0; $lhSum = 0.0;
$foulsSum = 0; $techSum = 0; $plusSum = 0.0; $minusSum = 0.0;
$teammates = []; // pid => [nick, games, wins]
$opponents = []; // pid => [nick, games, beat]

foreach ($mine as $g) {
    $games++;
    $role = $g['role'];
    $col = $team($role);
    $won = ($g['winner'] === $col);
    if ($g['winner'] === 'draw') {
        $draws++;
    } elseif ($won) {
        $wins++;
    }
    $byRole[$role][0]++;
    if ($won) { $byRole[$role][1]++; }
    $byColor[$col][0]++;
    if ($won) { $byColor[$col][1]++; }

    $foulsSum += (int)$g['fouls'];
    $techSum += (int)$g['tech_fouls'];
    $plusSum += (float)$g['plus'];
    $minusSum += (float)$g['minus'];

    // ПУ
    if ((int)$g['first_killed_seat'] === (int)$g['seat']) {
        $puCount++;
        if ($col === 'red') {
            $puAsRed++;
            // бонус ЛХ
            $hits = 0; $given = 0;
            foreach (['bm_seat1', 'bm_seat2', 'bm_seat3'] as $bk) {
                $bs = (int)$g[$bk];
                if ($bs >= 1 && $bs <= 10) {
                    $given++;
                    if (in_array($roleBySeat[$g['game_id']][$bs] ?? '', ['maf', 'don'], true)) {
                        $hits++;
                    }
                }
            }
            if ($given > 0) {
                $bonus = [0 => 0, 1 => 0.1, 2 => 0.3, 3 => 0.6][$hits];
                if ($bonus > 0) { $lhEarned++; $lhSum += $bonus; }
            }
        }
    }

    // Партнёры по цвету / соперники
    foreach ($seatsByGame[$g['game_id']] as $other) {
        $opid = (int)$other['player_id'];
        if ($opid === $pid) { continue; }
        $oc = $team($other['role']);
        if ($oc === $col) {
            $teammates[$opid] = $teammates[$opid] ?? ['nick' => $other['nickname'], 'games' => 0, 'wins' => 0];
            $teammates[$opid]['games']++;
            if ($won) { $teammates[$opid]['wins']++; }
        } else {
            $opponents[$opid] = $opponents[$opid] ?? ['nick' => $other['nickname'], 'games' => 0, 'beat' => 0, 'lost' => 0];
            $opponents[$opid]['games']++;
            if ($won) {
                $opponents[$opid]['beat']++;
            } elseif ($g['winner'] !== 'draw') {
                $opponents[$opid]['lost']++;
            }
        }
    }
}

$decided = $games - $draws;
$wr = fn($w, $g) => $g ? round($w / $g * 100) . '%' : '—';

// ── Вывод ──
$losses = $decided - $wins;
echo '<div class="grid-stats">';
echo '<div class="stat"><div class="lbl">всего игр</div><div class="val">' . $games . '</div></div>';
echo '<div class="stat"><div class="lbl">винрейт</div><div class="val">' . $wr($wins, $decided) . '</div></div>';
echo '<div class="stat"><div class="lbl">лузрейт</div><div class="val">' . $wr($losses, $decided) . '</div></div>';
echo '<div class="stat"><div class="lbl">побед / пораж / ничьих</div><div class="val" style="font-size:18px;">'
    . $wins . ' / ' . $losses . ' / ' . $draws . '</div></div>';
echo '<div class="stat"><div class="lbl">ПУ (первоубит)</div><div class="val">' . $puCount . '</div></div>';
echo '</div>';

// По ролям
echo '<div class="grid-2"><div class="card"><h2 style="margin-top:0;">Винрейт по ролям</h2>';
echo '<table class="tbl"><tr><th>Роль</th><th class="num">Игр</th><th class="num">Побед</th><th class="num">Винрейт</th></tr>';
$roleLbl = ['civ' => 'Мирный', 'sheriff' => 'Шериф', 'maf' => 'Мафия', 'don' => 'Дон'];
foreach ($roleLbl as $rk => $rl) {
    [$gg, $ww] = $byRole[$rk];
    echo '<tr><td>' . $rl . '</td><td class="num">' . $gg . '</td><td class="num">' . $ww . '</td><td class="num">' . $wr($ww, $gg) . '</td></tr>';
}
echo '</table></div>';

// По цвету
echo '<div class="card"><h2 style="margin-top:0;">По команде (цвету)</h2>';
echo '<table class="tbl"><tr><th>Команда</th><th class="num">Игр</th><th class="num">Побед</th><th class="num">Винрейт</th></tr>';
echo '<tr><td>🔴 Красные (мир+шериф)</td><td class="num">' . $byColor['red'][0] . '</td><td class="num">' . $byColor['red'][1] . '</td><td class="num">' . $wr($byColor['red'][1], $byColor['red'][0]) . '</td></tr>';
echo '<tr><td>⚫ Чёрные (мафия+дон)</td><td class="num">' . $byColor['black'][0] . '</td><td class="num">' . $byColor['black'][1] . '</td><td class="num">' . $wr($byColor['black'][1], $byColor['black'][0]) . '</td></tr>';
echo '</table>';
echo '<p style="font-size:12.5px;color:var(--tx2);margin:10px 0 0;">Чёрных игр у вас ' . $byColor['black'][0]
    . ' из ' . $games . ' (' . ($games ? round($byColor['black'][0] / $games * 100) : 0) . '%).</p>';
echo '</div></div>';

// ПУ и лучший ход
echo '<div class="card"><h2 style="margin-top:0;">Первоубиенный и лучший ход</h2>';
echo '<table class="tbl">';
echo '<tr><td style="color:var(--tx2);">Раз был первоубиенным (ПУ)</td><td class="num">' . $puCount
    . ' (' . ($games ? round($puCount / $games * 100) : 0) . '% игр)</td></tr>';
echo '<tr><td style="color:var(--tx2);">Из них красным</td><td class="num">' . $puAsRed . '</td></tr>';
echo '<tr><td style="color:var(--tx2);">Удачный лучший ход (бонус)</td><td class="num">' . $lhEarned . ' раз</td></tr>';
echo '<tr><td style="color:var(--tx2);">Σ бонусов за ЛХ</td><td class="num">' . number_format($lhSum, 1) . '</td></tr>';
echo '<tr><td style="color:var(--tx2);">Допов всего / минусов</td><td class="num">' . number_format($plusSum, 1) . ' / ' . number_format($minusSum, 1) . '</td></tr>';
echo '<tr><td style="color:var(--tx2);">Фолов / техфолов</td><td class="num">' . $foulsSum . ' / ' . $techSum . '</td></tr>';
echo '</table></div>';

// Лучший напарник: больше всего совместных побед в одном цвете
$bestMate = null;
foreach ($teammates as $opid => $m) {
    if ($bestMate === null || $m['wins'] > $bestMate['wins']
        || ($m['wins'] === $bestMate['wins'] && $m['games'] > $bestMate['games'])) {
        $bestMate = $m + ['id' => $opid];
    }
}
if ($bestMate && $bestMate['wins'] > 0) {
    echo '<div class="card card-accent" style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;">';
    echo avatar_html(['nickname' => $bestMate['nick']], 44, 'background:var(--acsf);color:var(--ac);');
    echo '<div><div style="font-size:13px;color:var(--tx2);">Лучший напарник в одном цвете</div>'
        . '<div style="font-size:17px;font-weight:600;"><a href="/player.php?id=' . (int)$bestMate['id'] . '" style="color:var(--tx);">'
        . esc($bestMate['nick']) . '</a></div>'
        . '<div style="font-size:13px;color:var(--tx2);">' . $bestMate['wins'] . ' совместных побед в '
        . $bestMate['games'] . ' играх вместе · винрейт ' . $wr($bestMate['wins'], $bestMate['games']) . '</div></div>';
    echo '</div>';
}

// Химия с партнёрами по цвету — по числу совместных побед
uasort($teammates, fn($a, $b) => [$b['wins'], $b['games']] <=> [$a['wins'], $a['games']]);
$topMates = array_slice($teammates, 0, 12, true);
echo '<div class="grid-2"><div class="card"><h2 style="margin-top:0;">В одном цвете: больше всего побед</h2>';
if ($topMates) {
    echo '<table class="tbl"><tr><th>Игрок</th><th class="num">Вместе</th><th class="num">Побед</th><th class="num">Винрейт</th></tr>';
    foreach ($topMates as $opid => $m) {
        echo '<tr><td><a href="/player.php?id=' . $opid . '" style="color:var(--tx);">' . esc($m['nick']) . '</a></td>'
            . '<td class="num">' . $m['games'] . '</td><td class="num">' . $m['wins'] . '</td>'
            . '<td class="num">' . $wr($m['wins'], $m['games']) . '</td></tr>';
    }
    echo '</table>';
} else {
    echo '<p style="color:var(--tx2);">Нет данных.</p>';
}
echo '</div>';

// Лучшая химия (мин 4 игры вместе)
$chem = array_filter($teammates, fn($m) => $m['games'] >= 4);
uasort($chem, fn($a, $b) => ($b['wins'] / $b['games']) <=> ($a['wins'] / $a['games']));
$bestChem = array_slice($chem, 0, 8, true);
echo '<div class="card"><h2 style="margin-top:0;">Лучшая «химия» <span style="font-size:12px;color:var(--tx2);">(от 4 игр вместе)</span></h2>';
if ($bestChem) {
    echo '<table class="tbl"><tr><th>Игрок</th><th class="num">Вместе</th><th class="num">Винрейт</th></tr>';
    foreach ($bestChem as $opid => $m) {
        echo '<tr><td><a href="/player.php?id=' . $opid . '" style="color:var(--tx);">' . esc($m['nick']) . '</a></td>'
            . '<td class="num">' . $m['games'] . '</td><td class="num">' . $wr($m['wins'], $m['games']) . '</td></tr>';
    }
    echo '</table>';
} else {
    echo '<p style="color:var(--tx2);">Пока мало совместных игр для расчёта.</p>';
}
echo '</div></div>';

// ── Разноцветы: соперники ──
$beat = $opponents;
uasort($beat, fn($a, $b) => [$b['beat'], $b['games']] <=> [$a['beat'], $a['games']]);
$lostTo = $opponents;
uasort($lostTo, fn($a, $b) => [$b['lost'], $b['games']] <=> [$a['lost'], $a['games']]);

echo '<h2 style="margin-top:8px;">Разноцветы — против кого играли</h2>';
echo '<div class="grid-2">';

echo '<div class="card"><h2 style="margin-top:0;">Кого чаще всего обыгрывали</h2>';
$top = array_slice(array_filter($beat, fn($m) => $m['beat'] > 0), 0, 12, true);
if ($top) {
    echo '<table class="tbl"><tr><th>Игрок</th><th class="num">Обыграли</th><th class="num">Игр против</th></tr>';
    foreach ($top as $opid => $m) {
        echo '<tr><td><a href="/player.php?id=' . $opid . '" style="color:var(--tx);">' . esc($m['nick']) . '</a></td>'
            . '<td class="num"><b>' . $m['beat'] . '</b></td><td class="num">' . $m['games'] . '</td></tr>';
    }
    echo '</table>';
} else {
    echo '<p style="color:var(--tx2);">Нет данных.</p>';
}
echo '</div>';

echo '<div class="card"><h2 style="margin-top:0;">Кому чаще всего проигрывали</h2>';
$topL = array_slice(array_filter($lostTo, fn($m) => $m['lost'] > 0), 0, 12, true);
if ($topL) {
    echo '<table class="tbl"><tr><th>Игрок</th><th class="num">Проиграли</th><th class="num">Игр против</th></tr>';
    foreach ($topL as $opid => $m) {
        echo '<tr><td><a href="/player.php?id=' . $opid . '" style="color:var(--tx);">' . esc($m['nick']) . '</a></td>'
            . '<td class="num"><b style="color:var(--ac);">' . $m['lost'] . '</b></td><td class="num">' . $m['games'] . '</td></tr>';
    }
    echo '</table>';
} else {
    echo '<p style="color:var(--tx2);">Нет данных.</p>';
}
echo '</div></div>';

page_foot();
