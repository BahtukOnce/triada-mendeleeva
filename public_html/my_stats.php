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
echo '<div class="grid-stats gs-compact">';
echo '<div class="stat"><div class="lbl">игр</div><div class="val">' . $games . '</div></div>';
echo '<div class="stat"><div class="lbl">винрейт</div><div class="val">' . $wr($wins, $decided) . '</div></div>';
echo '<div class="stat"><div class="lbl">побед</div><div class="val" style="color:var(--ok);">' . $wins . '</div></div>';
echo '<div class="stat"><div class="lbl">поражений</div><div class="val">' . $losses . '</div></div>';
echo '<div class="stat"><div class="lbl">ничьих</div><div class="val">' . $draws . '</div></div>';
echo '<div class="stat"><div class="lbl">ПУ</div><div class="val">' . $puCount . '</div></div>';
echo '</div>';

$jst = db()->prepare("SELECT COUNT(*) FROM games WHERE judge_player_id = ? AND status = 'finished'");
$jst->execute([$pid]);
$judged = (int)$jst->fetchColumn();
if ($judged > 0) {
    echo '<a class="card card-link" href="/my_judged.php" style="display:flex;align-items:center;gap:12px;">'
        . '<span class="tag tag-open" style="font-size:14px;">судья</span>'
        . '<span>Вы отсудили <b>' . $judged . '</b> ' . ($judged % 10 === 1 && $judged % 100 !== 11 ? 'игру' : 'игр')
        . '. <span style="color:var(--ac);">посмотреть, какие →</span></span></a>';
}

// ── Данные для графиков ──
$roleWrData = [];
$roleGamesData = [];
$roleWinsData = [];
foreach (['civ', 'sheriff', 'maf', 'don'] as $rk) {
    [$gg, $ww] = $byRole[$rk];
    $roleWrData[] = $gg ? round($ww / $gg * 100) : 0;
    $roleGamesData[] = $gg;
    $roleWinsData[] = $ww;
}
$roleDist = [$byRole['civ'][0], $byRole['sheriff'][0], $byRole['maf'][0], $byRole['don'][0]];
$resultsData = [$wins, $losses, $draws];
$eh = db()->prepare('SELECT elo_after, gdate FROM elo_history WHERE player_id = ? ORDER BY id');
$eh->execute([$pid]);
$eloSeries = [1000.0];
$eloDates = ['старт'];
foreach ($eh->fetchAll() as $r) {
    $eloSeries[] = round((float)$r['elo_after'], 1);
    $eloDates[] = $r['gdate'] ? date('d.m.y', strtotime($r['gdate'])) : '';
}
$myElo = end($eloSeries) ?: 1000;

$chartData = json_encode([
    'roleWr' => $roleWrData,
    'roleGames' => $roleGamesData,
    'roleWins' => $roleWinsData,
    'roleDist' => $roleDist,
    'results' => $resultsData,
    'elo' => $eloSeries,
    'eloDates' => $eloDates,
], JSON_UNESCAPED_UNICODE);

echo '<h2 style="margin-top:14px;">Графики</h2>';
echo '<div class="grid-2">';
echo '<div class="card"><h2 style="margin-top:0;font-size:15px;">Динамика ELO · сейчас ' . number_format((float)$myElo, 0, '.', '')
    . ' <span style="color:var(--ac);font-size:13px;">· ' . esc(elo_tier_name((float)$myElo)) . '</span></h2>'
    . '<div style="position:relative;height:220px;"><canvas id="ch-elo"></canvas></div></div>';
echo '<div class="card"><h2 style="margin-top:0;font-size:15px;">Винрейт по ролям</h2>'
    . '<div style="position:relative;height:220px;"><canvas id="ch-rolewr"></canvas></div></div>';
echo '<div class="card"><h2 style="margin-top:0;font-size:15px;">Сколько играли за роль</h2>'
    . '<div style="position:relative;height:220px;"><canvas id="ch-roledist"></canvas></div></div>';
echo '<div class="card"><h2 style="margin-top:0;font-size:15px;">Исходы игр</h2>'
    . '<div style="position:relative;height:220px;"><canvas id="ch-results"></canvas></div></div>';
echo '</div>';
?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/chartjs-plugin-datalabels/2.2.0/chartjs-plugin-datalabels.min.js"></script>
<script>
(function () {
  var D = <?= $chartData ?>;
  if (typeof Chart === 'undefined') return;
  if (window.ChartDataLabels) Chart.register(window.ChartDataLabels);
  Chart.defaults.set('plugins.datalabels', { display: false });
  var pctLabel = { display: true, color: '#fff', font: { weight: '600', size: 11 },
    formatter: function (v, ctx) { var s = ctx.dataset.data.reduce(function (a, b) { return a + (+b || 0); }, 0); return s && v ? Math.round(v / s * 100) + '%' : ''; } };
  var grid = 'rgba(255,255,255,0.08)', tx = '#9c9ca6';
  Chart.defaults.color = tx;
  Chart.defaults.font.family = "system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif";
  var red = '#e8332a', roleColors = ['#e8332a', '#e6b13a', '#50505a', '#0e0e12'];
  var roleLabels = ['Мирный', 'Шериф', 'Мафия', 'Дон'];

  // Зоны уровней по ELO (градиент)
  var TIERS = [{ v: 1000, n: 'Новичок', col: '120,124,140', a: 0.06 },
    { v: 1100, n: 'Игрок', col: '70,120,210', a: 0.08 },
    { v: 1500, n: 'Сильный', col: '220,170,60', a: 0.10 },
    { v: 2000, n: 'Эксперт', col: '235,120,40', a: 0.13 },
    { v: 2500, n: 'Мастер', col: '232,51,42', a: 0.19 },
    { v: 3500, n: 'Легенда', col: '232,51,42', a: 0.34 }];
  function tierColor(v) { var T = TIERS[0]; for (var i = 0; i < TIERS.length; i++) { if (v >= TIERS[i].v) T = TIERS[i]; } return 'rgba(' + T.col + ',' + T.a + ')'; }
  function tierName(v) { var n = TIERS[0].n; for (var i = 0; i < TIERS.length; i++) { if (v >= TIERS[i].v) n = TIERS[i].n; } return n; }
  var tierBands = { id: 'tierBands', beforeDatasetsDraw: function (ch) {
    var ya = ch.scales.y, ar = ch.chartArea; if (!ya || !ar) return;
    var c = ch.ctx, h = ar.bottom - ar.top; if (h <= 0) return;
    var g = c.createLinearGradient(0, ar.top, 0, ar.bottom), added = {};
    function stop(off, col) { off = Math.max(0, Math.min(1, off)); var k = off.toFixed(4); if (added[k]) return; added[k] = 1; g.addColorStop(off, col); }
    stop(0, tierColor(ya.max));
    TIERS.forEach(function (T) { if (T.v > ya.min && T.v < ya.max) stop((ya.getPixelForValue(T.v) - ar.top) / h, tierColor(T.v)); });
    stop(1, tierColor(ya.min));
    c.save();
    c.fillStyle = g; c.fillRect(ar.left, ar.top, ar.right - ar.left, h);
    c.textAlign = 'right'; c.font = '600 10px system-ui';
    for (var i = 0; i < TIERS.length; i++) {
      var T = TIERS[i], nextV = (i + 1 < TIERS.length) ? TIERS[i + 1].v : ya.max;
      var bTop = ya.getPixelForValue(Math.min(nextV, ya.max)), bBot = ya.getPixelForValue(Math.max(T.v, ya.min));
      if (bBot <= ar.top || bTop >= ar.bottom) continue;
      var py = ya.getPixelForValue(T.v);
      if (T.v > ya.min && py > ar.top && py < ar.bottom) { c.strokeStyle = 'rgba(255,255,255,0.08)'; c.lineWidth = 1;
        c.beginPath(); c.moveTo(ar.left, Math.round(py) + 0.5); c.lineTo(ar.right, Math.round(py) + 0.5); c.stroke(); }
      var top = Math.max(bTop, ar.top);
      if (Math.min(bBot, ar.bottom) - top > 15) { c.fillStyle = 'rgba(255,255,255,0.55)'; c.fillText(T.n, ar.right - 6, top + 12); }
    }
    c.restore();
  } };

  var eloMax = Math.max.apply(null, D.elo), eloNextTier = null;
  for (var ti = 0; ti < TIERS.length; ti++) { if (TIERS[ti].v > eloMax) { eloNextTier = TIERS[ti].v; break; } }
  var eloSMax = (eloNextTier || eloMax) + 150;

  new Chart(document.getElementById('ch-elo'), {
    type: 'line',
    data: { labels: D.eloDates,
      datasets: [{ data: D.elo, borderColor: red, backgroundColor: 'rgba(232,51,42,0.12)',
        fill: true, tension: 0.25, pointRadius: 0, pointHoverRadius: 5, pointHoverBackgroundColor: red, pointHoverBorderColor: '#fff', pointHoverBorderWidth: 2, borderWidth: 2 }] },
    options: { interaction: { intersect: false, mode: 'index', axis: 'x' },
      plugins: { legend: { display: false },
        tooltip: { animation: false, callbacks: { title: function (items) { return items && items[0] ? items[0].label : ''; },
          label: function (c) { return 'ELO ' + Math.round(c.parsed.y) + ' · ' + tierName(c.parsed.y); } } } },
      scales: { x: { display: true, grid: { display: false }, ticks: { color: tx, font: { size: 10 }, maxTicksLimit: 6, autoSkip: true, maxRotation: 0 } }, y: { suggestedMin: 1000, suggestedMax: eloSMax, grid: { color: grid } } },
      maintainAspectRatio: false },
    plugins: [tierBands]
  });

  new Chart(document.getElementById('ch-rolewr'), {
    type: 'bar',
    data: { labels: roleLabels, datasets: [{ data: D.roleWr, backgroundColor: roleColors, borderRadius: 6 }] },
    options: { plugins: { legend: { display: false }, tooltip: { callbacks: { label: function (c) {
            var i = c.dataIndex;
            return ['Винрейт: ' + c.parsed.y + '%', 'Побед: ' + D.roleWins[i] + ' из ' + D.roleGames[i]];
          } } } },
      scales: { y: { beginAtZero: true, max: 100, grid: { color: grid }, ticks: { callback: function (v) { return v + '%'; } } }, x: { grid: { display: false } } },
      maintainAspectRatio: false }
  });

  new Chart(document.getElementById('ch-roledist'), {
    type: 'doughnut',
    data: { labels: roleLabels, datasets: [{ data: D.roleDist, backgroundColor: roleColors, borderColor: '#17171c', borderWidth: 2 }] },
    options: { plugins: { legend: { position: 'bottom' }, datalabels: pctLabel }, maintainAspectRatio: false }
  });

  new Chart(document.getElementById('ch-results'), {
    type: 'doughnut',
    data: { labels: ['Победы', 'Поражения', 'Ничьи'],
      datasets: [{ data: D.results, backgroundColor: ['#2fa45c', red, '#888'], borderWidth: 0 }] },
    options: { plugins: { legend: { position: 'bottom' }, datalabels: pctLabel }, maintainAspectRatio: false }
  });
})();
</script>
<?php

// По команде (цвету) + Первоубиенный/ЛХ — в две колонки
echo '<div class="grid-2">';
echo '<div class="card"><h2 style="margin-top:0;">По команде (цвету)</h2>';
echo '<table class="tbl"><tr><th>Команда</th><th class="num">Игр</th><th class="num">Побед</th><th class="num">Винрейт</th></tr>';
echo '<tr><td>🔴 Красные (мир+шериф)</td><td class="num">' . $byColor['red'][0] . '</td><td class="num">' . $byColor['red'][1] . '</td><td class="num">' . $wr($byColor['red'][1], $byColor['red'][0]) . '</td></tr>';
echo '<tr><td>⚫ Чёрные (мафия+дон)</td><td class="num">' . $byColor['black'][0] . '</td><td class="num">' . $byColor['black'][1] . '</td><td class="num">' . $wr($byColor['black'][1], $byColor['black'][0]) . '</td></tr>';
echo '</table>';
echo '<p style="font-size:12.5px;color:var(--tx2);margin:10px 0 0;">Чёрных игр у вас ' . $byColor['black'][0]
    . ' из ' . $games . ' (' . ($games ? round($byColor['black'][0] / $games * 100) : 0) . '%).</p>';
echo '</div>';

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
echo '</div>';

// Напарники в одном цвете: лучший (больше всего совместных побед) и неудачный (низкий винрейт от 5 игр)
$bestMate = null;
foreach ($teammates as $opid => $m) {
    if ($bestMate === null || $m['wins'] > $bestMate['wins']
        || ($m['wins'] === $bestMate['wins'] && $m['games'] > $bestMate['games'])) {
        $bestMate = $m + ['id' => $opid];
    }
}
$worstMate = null;
$minTogether = 5;
foreach ($teammates as $opid => $m) {
    if ($m['games'] < $minTogether || ($bestMate && $opid === $bestMate['id'])) {
        continue;
    }
    $wrM = $m['wins'] / $m['games'];
    if ($worstMate === null || $wrM < $worstMate['wr']
        || (abs($wrM - $worstMate['wr']) < 1e-9 && $m['games'] > $worstMate['games'])) {
        $worstMate = $m + ['id' => $opid, 'wr' => $wrM];
    }
}

$mateCard = function (?array $m, string $title, string $cardCls, string $avaStyle) use ($wr) {
    if (!$m || ($m['games'] ?? 0) < 1) {
        return '<div class="card"><h2 style="margin-top:0;">' . $title . '</h2>'
            . '<p style="color:var(--tx2);margin:0;">Пока мало совместных игр для расчёта.</p></div>';
    }
    return '<div class="card ' . $cardCls . '" style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;">'
        . avatar_html(['nickname' => $m['nick']], 44, $avaStyle)
        . '<div><div style="font-size:13px;color:var(--tx2);">' . $title . '</div>'
        . '<div style="font-size:17px;font-weight:600;"><a href="/player.php?id=' . (int)$m['id'] . '" style="color:var(--tx);">'
        . esc($m['nick']) . '</a></div>'
        . '<div style="font-size:13px;color:var(--tx2);">' . $m['wins'] . ' побед в ' . $m['games']
        . ' играх вместе · винрейт ' . $wr($m['wins'], $m['games']) . '</div></div></div>';
};

echo '<div class="grid-2eq">';
echo $mateCard(($bestMate && $bestMate['wins'] > 0) ? $bestMate : null, 'Лучший напарник в одном цвете', 'card-accent', 'background:var(--acsf);color:var(--ac);');
echo $mateCard($worstMate, 'Неудачный напарник в одном цвете', '', 'background:var(--sf2);color:var(--tx2);');
echo '</div>';

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
    echo '<table class="tbl"><tr><th>Игрок</th><th class="num">Обыграли</th><th class="num">Игр против</th><th class="num">%</th></tr>';
    foreach ($top as $opid => $m) {
        echo '<tr><td><a href="/player.php?id=' . $opid . '" style="color:var(--tx);">' . esc($m['nick']) . '</a></td>'
            . '<td class="num"><b>' . $m['beat'] . '</b></td><td class="num">' . $m['games'] . '</td>'
            . '<td class="num" style="color:var(--ok);">' . $wr($m['beat'], $m['games']) . '</td></tr>';
    }
    echo '</table>';
} else {
    echo '<p style="color:var(--tx2);">Нет данных.</p>';
}
echo '</div>';

echo '<div class="card"><h2 style="margin-top:0;">Кому чаще всего проигрывали</h2>';
$topL = array_slice(array_filter($lostTo, fn($m) => $m['lost'] > 0), 0, 12, true);
if ($topL) {
    echo '<table class="tbl"><tr><th>Игрок</th><th class="num">Проиграли</th><th class="num">Игр против</th><th class="num">%</th></tr>';
    foreach ($topL as $opid => $m) {
        echo '<tr><td><a href="/player.php?id=' . $opid . '" style="color:var(--tx);">' . esc($m['nick']) . '</a></td>'
            . '<td class="num"><b style="color:var(--ac);">' . $m['lost'] . '</b></td><td class="num">' . $m['games'] . '</td>'
            . '<td class="num" style="color:var(--ac);">' . $wr($m['lost'], $m['games']) . '</td></tr>';
    }
    echo '</table>';
} else {
    echo '<p style="color:var(--tx2);">Нет данных.</p>';
}
echo '</div></div>';

page_foot();
