<?php
require dirname(__DIR__) . '/inc/bootstrap.php';

page_head('Зал славы', 'records');
echo '<h1>Зал славы клуба</h1>';
echo '<p style="margin-top:-6px;"><a class="btn btn-ghost" href="/vs.php">⚔ Очная ставка — сравнить двух игроков</a></p>';

if (!db_ready()) {
    empty_state('Нет данных', 'Появится после переноса истории.');
    page_foot();
    exit;
}

$records = club_records();
if (!$records) {
    empty_state('Рекордов пока нет', 'Таблица появится после первых игр.');
    page_foot();
    exit;
}

echo '<div class="records-grid">';
foreach ($records as [$ic, $title, $list, $type]) {
    echo '<div class="rec-card"><div class="rec-head"><span class="rec-ic">' . $ic . '</span><span class="rec-title">' . esc($title) . '</span></div><div class="rec-rows">';
    $rank = 0;
    foreach ($list as $item) {
        $rank++;
        $row = $item['row'];
        $medal = $rank === 1 ? '🥇' : ($rank === 2 ? '🥈' : '🥉');
        echo '<a class="rec-row" href="/player.php?id=' . (int)$row['pid'] . '">'
            . '<span class="rec-rank">' . $medal . '</span>' . avatar_html($row, 24)
            . '<span class="rec-name">' . player_label($row) . '</span>'
            . '<span class="rec-v">' . esc(records_fmt($item['val'], $type)) . '</span></a>';
    }
    echo '</div></div>';
}
echo '</div>';

// ── Лучшие дуэты клуба: пары одного цвета с лучшим винрейтом (от 6 совместных игр) ──
try {
    // Период дуэтов выбирается явно, и сезоны берём из реальных данных — а не список
    // «всё время / текущий»: за всю историю верх навсегда занимают старожилы.
    // Сезон = 1 сентября — 31 августа (та же формула, что в профиле игрока).
    $dExpr      = "COALESCE(d.date, t.date_from)";
    $startY     = "(YEAR($dExpr) - (MONTH($dExpr) < 9))";
    $seasonExpr = "CONCAT('Сезон ', $startY, '/', $startY + 1)";
    $duoSeasons = [];
    foreach (db()->query("SELECT $seasonExpr s FROM games g
        LEFT JOIN game_days d ON d.id = g.day_id
        LEFT JOIN tournaments t ON t.id = g.tournament_id
        WHERE g.status = 'finished' AND g.winner IN ('red','black') AND $dExpr IS NOT NULL
        GROUP BY s ORDER BY s DESC")->fetchAll() as $rs) {
        $duoSeasons[] = (string)$rs['s'];
    }
    $duoSel = (string)($_GET['duo'] ?? 'all');
    if ($duoSel !== 'all' && !in_array($duoSel, $duoSeasons, true)) {
        $duoSel = 'all';                       // чужой параметр в адресе — молча игнорируем
    }
    $duoSql = "SELECT gs.game_id, gs.player_id, gs.role, g.winner
        FROM game_seats gs
        JOIN games g ON g.id = gs.game_id
        LEFT JOIN game_days d ON d.id = g.day_id
        LEFT JOIN tournaments t ON t.id = g.tournament_id
        WHERE g.status = 'finished' AND g.winner IN ('red','black')";
    $duoArgs = [];
    if ($duoSel !== 'all') {
        $duoSql .= " AND $seasonExpr = ?";
        $duoArgs = [$duoSel];
    }
    $stP = db()->prepare($duoSql);
    $stP->execute($duoArgs);
    $rowsP = $stP->fetchAll();
    $byGame = [];
    foreach ($rowsP as $r) {
        $byGame[(int)$r['game_id']][] = $r;
    }
    $pairAgg = [];
    foreach ($byGame as $seats) {
        $winner = $seats[0]['winner'];
        $red = [];
        $blk = [];
        foreach ($seats as $s) {
            if (in_array($s['role'], ['civ', 'sheriff'], true)) {
                $red[] = (int)$s['player_id'];
            } else {
                $blk[] = (int)$s['player_id'];
            }
        }
        foreach ([['red', $red], ['black', $blk]] as [$team, $list]) {
            $won = $winner === $team ? 1 : 0;
            $n = count($list);
            for ($i = 0; $i < $n; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    $k = min($list[$i], $list[$j]) . '-' . max($list[$i], $list[$j]);
                    $pairAgg[$k]['g'] = ($pairAgg[$k]['g'] ?? 0) + 1;
                    $pairAgg[$k]['w'] = ($pairAgg[$k]['w'] ?? 0) + $won;
                }
            }
        }
    }
    // Ниже 5 совместных игр пару не показываем вообще: на 3-4 играх винрейт — это шум,
    // 100% у случайной пары вытеснит настоящие дуэты. Выше 5 порог поднимает читатель.
    $bestPairs = [];
    foreach ($pairAgg as $k => $v) {
        if ($v['g'] >= 5) {
            $bestPairs[] = ['k' => $k, 'g' => $v['g'], 'w' => $v['w'], 'wr' => $v['w'] / $v['g']];
        }
    }
    usort($bestPairs, fn($x, $y) => [$y['wr'], $y['g']] <=> [$x['wr'], $x['g']]);
    $bestPairs = array_slice($bestPairs, 0, 300);
    if ($bestPairs) {
        $pids = [];
        foreach ($bestPairs as $bp) {
            [$a, $b] = explode('-', $bp['k']);
            $pids[(int)$a] = 1;
            $pids[(int)$b] = 1;
        }
        $inP = implode(',', array_fill(0, count($pids), '?'));
        $pq = db()->prepare("SELECT id, nickname, avatar, flair, elo FROM players WHERE id IN ($inP)");
        $pq->execute(array_keys($pids));
        $plMap = [];
        foreach ($pq->fetchAll() as $pl) {
            $plMap[(int)$pl['id']] = $pl;
        }
        // Якорь — на заголовке, а не на строке настроек, плюс scroll-margin-top под
        // липкую шапку (70px): иначе после перезагрузки страница вставала так, что
        // заголовок уезжал под шапку и было непонятно, куда тебя перенесло.
        echo '<h2 id="duo" style="margin-top:18px;scroll-margin-top:86px;">🤝 Лучшие дуэты</h2>';
        echo '<p style="color:var(--tx2);font-size:13px;margin-top:-6px;">пары одного цвета с лучшим винрейтом вместе — клик по строке откроет «Дуэль»</p>';
        // Период и порог — одной строкой: это один набор настроек списка.
        echo '<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin:0 0 10px;">';
        echo '<a class="tag' . ($duoSel === 'all' ? ' tag-open' : '') . '" href="/records.php#duo">За всё время</a>';
        foreach ($duoSeasons as $sName) {
            echo '<a class="tag' . ($duoSel === $sName ? ' tag-open' : '') . '" href="/records.php?duo='
                . urlencode($sName) . '#duo">' . esc($sName) . '</a>';
        }
        echo '<span style="width:1px;height:20px;background:var(--bd);margin:0 3px;"></span>'
            . '<label for="duo-min" style="font-size:13px;color:var(--tx2);">от</label>'
            . '<input type="number" id="duo-min" min="5" max="60" step="1" value="5" '
            . 'style="width:64px;background:var(--sf2);color:var(--tx);border:1px solid var(--bd);border-radius:8px;padding:6px 9px;">'
            . '<span style="font-size:13px;color:var(--tx2);">совместных игр</span>'
            . '<span id="duo-none" style="font-size:12.5px;color:var(--tx3);display:none;">— таких пар нет, снизьте порог</span></div>';
        // Таблица в том же виде, что «Битва факультетов»: колонки читаются глазами,
        // а список-карточка либо резал ники в узкой сетке, либо растягивал строку.
        echo '<div class="card" style="overflow-x:auto;"><table class="tbl duo-tbl">';
        echo '<tr><th>#</th><th>Пара</th><th class="num">Игр вместе</th><th class="num">Побед</th>'
            . '<th class="num">Винрейт</th><th class="num">Ср. ELO</th></tr>';
        foreach ($bestPairs as $bp) {
            [$a, $b] = array_map('intval', explode('-', $bp['k']));
            $pa = $plMap[$a] ?? null;
            $pb = $plMap[$b] ?? null;
            if (!$pa || !$pb) {
                continue;
            }
            $wrP = round($bp['wr'] * 100);
            $eloP = (int)round(((float)($pa['elo'] ?? 1000) + (float)($pb['elo'] ?? 1000)) / 2);
            echo '<tr class="duo-row" data-g="' . (int)$bp['g'] . '" style="display:none;" '
                . 'data-href="/versus.php?a=' . $a . '&b=' . $b . '">'
                . '<td class="duo-rank"></td>'
                . '<td><span style="display:inline-flex;align-items:center;gap:7px;">'
                . avatar_html($pa, 22) . avatar_html($pb, 22)
                . '<b>' . esc($pa['nickname']) . ' + ' . esc($pb['nickname']) . '</b></span></td>'
                . '<td class="num">' . (int)$bp['g'] . '</td>'
                . '<td class="num">' . (int)$bp['w'] . '</td>'
                . '<td class="num"><b style="color:' . ($wrP >= 60 ? 'var(--ok)' : 'var(--ac)') . ';">' . $wrP . '%</b></td>'
                . '<td class="num">' . $eloP . '</td></tr>';
        }
        echo '</table></div>';
        ?>
<script>
(function () {
  var inp = document.getElementById('duo-min');
  if (!inp) return;
  var rows = [].slice.call(document.querySelectorAll('.duo-row'));
  var none = document.getElementById('duo-none');
  var medals = ['🥇', '🥈', '🥉'];
  function apply() {
    var min = parseInt(inp.value, 10);
    if (!min || min < 5) min = 5;   // ниже 5 совместных игр винрейт — шум
    var shown = 0;
    rows.forEach(function (r) {
      var ok = shown < 6 && parseInt(r.dataset.g, 10) >= min;
      r.style.display = ok ? '' : 'none';
      if (ok) {
        var c = r.querySelector('.duo-rank');
        if (c) c.textContent = medals[shown] || (shown + 1);
        shown++;
      }
    });
    if (none) none.style.display = shown ? 'none' : '';
  }
  inp.addEventListener('input', apply);
  apply();
})();
</script>
        <?php
    }
} catch (Throwable $e) {
}

// ── Битва факультетов РХТУ: командный зачёт по анкетам ──
try {
    // Считаем прямо по сыгранным играм, а не по rating_cache: кэш содержит только
    // вечера текущего сезона, поэтому выбрать период было невозможно в принципе.
    // Теперь период выбирается чипами (как у дуэтов), и учитываются также турниры.
    $facSel = (string)($_GET['fac'] ?? 'all');
    if ($facSel !== 'all' && !in_array($facSel, $duoSeasons ?? [], true)) {
        $facSel = 'all';
    }
    $fSql = "SELECT p.faculty, p.nickname, p.elo,
            COUNT(*) AS games,
            SUM(CASE WHEN (g.winner = 'red' AND gs.role IN ('civ','sheriff'))
                      OR (g.winner = 'black' AND gs.role IN ('maf','don')) THEN 1 ELSE 0 END) AS wins,
            COALESCE(SUM(gs.plus), 0) AS dops
        FROM players p
        JOIN game_seats gs ON gs.player_id = p.id
        JOIN games g ON g.id = gs.game_id
        LEFT JOIN game_days d ON d.id = g.day_id
        LEFT JOIN tournaments t ON t.id = g.tournament_id
        WHERE p.faculty IS NOT NULL AND TRIM(p.faculty) <> ''
          AND p.banned_at IS NULL
          AND g.status = 'finished' AND g.winner IN ('red','black')";
    $fArgs = [];
    if ($facSel !== 'all') {
        $fSql .= " AND $seasonExpr = ?";
        $fArgs = [$facSel];
    }
    $fSql .= " GROUP BY p.id, p.faculty, p.nickname, p.elo";
    $fq = db()->prepare($fSql);
    $fq->execute($fArgs);
    $fac = [];
    foreach ($fq->fetchAll() as $r) {
        $key = mb_strtoupper(trim((string)$r['faculty'])); // нормализация: ИМиХТ = имихт
        // «Другое» — это вариант из анкеты, а не факультет: в командном зачёте ему не место.
        if (in_array($key, ['ДРУГОЕ', 'НЕТ', 'НЕ УКАЗАН', '-', '—'], true)) {
            continue;
        }
        if (!isset($fac[$key])) {
            $fac[$key] = ['label' => trim((string)$r['faculty']), 'members' => 0, 'games' => 0,
                'wins' => 0, 'eloSum' => 0.0, 'eloN' => 0, 'dops' => 0.0, 'nicks' => []];
        }
        $fac[$key]['members']++;
        $fac[$key]['games'] += (int)($r['games'] ?? 0);
        $fac[$key]['wins'] += (int)$r['wins'];
        $fac[$key]['dops'] += (float)$r['dops'];
        $fac[$key]['nicks'][] = (string)$r['nickname'];
        if ((int)($r['games'] ?? 0) > 0) {
            $fac[$key]['eloSum'] += (float)$r['elo'];
            $fac[$key]['eloN']++;
        }
    }
    $fac = array_filter($fac, fn($f) => $f['games'] > 0);
    if (count($fac) >= 2) {
        uasort($fac, function ($a, $b) {
            $wa = $a['games'] >= 10 ? $a['wins'] / $a['games'] : -1; // <10 игр — вниз
            $wb = $b['games'] >= 10 ? $b['wins'] / $b['games'] : -1;
            return [$wb, $b['games']] <=> [$wa, $a['games']];
        });
        echo '<h2 id="fac" style="margin-top:18px;scroll-margin-top:86px;">🏛 Битва факультетов</h2>';
        echo '<p style="color:var(--tx2);font-size:13px;margin-top:-6px;">командный зачёт по анкетам игроков '
            . '(факультет — в личном кабинете); наведи на название — увидишь состав</p>';
        echo '<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin:0 0 10px;">'
            . '<a class="tag' . ($facSel === 'all' ? ' tag-open' : '') . '" href="/records.php#fac">За всё время</a>';
        foreach (($duoSeasons ?? []) as $sName) {
            echo '<a class="tag' . ($facSel === $sName ? ' tag-open' : '') . '" href="/records.php?fac='
                . urlencode($sName) . '#fac">' . esc($sName) . '</a>';
        }
        echo '</div>';
        echo '<div class="card" style="overflow-x:auto;"><table class="tbl fac-tbl">';
        echo '<tr><th>#</th><th>Факультет</th><th class="num">Игроков</th><th class="num">Игр</th><th class="num">Побед</th><th class="num">Винрейт</th><th class="num">Ср. ELO</th><th class="num">Допы</th></tr>';
        $posF = 0;
        foreach ($fac as $f) {
            $posF++;
            $wr = $f['games'] > 0 ? round($f['wins'] / $f['games'] * 100) : 0;
            $inRace = $f['games'] >= 10;
            $medalF = !$inRace ? '·' : ($posF === 1 ? '🥇' : ($posF === 2 ? '🥈' : ($posF === 3 ? '🥉' : $posF)));
            $wrCol = !$inRace ? 'var(--tx3)' : ($wr >= 52 ? 'var(--ok)' : ($wr <= 48 ? 'var(--ac)' : 'var(--tx)'));
            // Состав — подсказкой при наведении на название факультета.
            sort($f['nicks'], SORT_NATURAL | SORT_FLAG_CASE);
            $roster = implode(', ', array_slice($f['nicks'], 0, 40))
                . (count($f['nicks']) > 40 ? ' и ещё ' . (count($f['nicks']) - 40) : '');
            // «·» вместо места раньше не объясняло себя — подписываем прямо в строке.
            $outTag = $inRace ? '' : ' <span class="tag" style="font-size:10.5px;padding:1px 7px;'
                . 'vertical-align:middle;" title="Для места в зачёте нужно минимум 10 игр">вне зачёта</span>';
            echo '<tr' . ($inRace ? '' : ' style="opacity:.55;"') . '>'
                . '<td' . ($inRace ? '' : ' title="Вне зачёта: меньше 10 игр"') . '>' . $medalF . '</td>'
                . '<td title="' . esc($roster) . '"><b>' . esc($f['label']) . '</b>' . $outTag . '</td>'
                . '<td class="num">' . $f['members'] . '</td>'
                . '<td class="num">' . $f['games'] . '</td>'
                . '<td class="num">' . $f['wins'] . '</td>'
                . '<td class="num"><b style="color:' . $wrCol . ';">' . $wr . '%</b></td>'
                . '<td class="num">' . ($f['eloN'] ? number_format($f['eloSum'] / $f['eloN'], 0, '.', '') : '—') . '</td>'
                . '<td class="num">' . number_format($f['dops'], 1) . '</td></tr>';
        }
        echo '</table>';
        echo '<p style="color:var(--tx3);font-size:12px;margin:8px 0 0;">Не видишь свой факультет? Укажи его в <a href="/cabinet.php">личном кабинете</a> — и твои игры пойдут в зачёт.</p>';
        echo '</div>';
    }
} catch (Throwable $e) {
}

// ── Достижения (с теми, кто получил) ──
echo '<h2 style="margin-top:18px;">Достижения</h2>';
echo '<p style="color:var(--tx2);font-size:13px;margin-top:-6px;">Зелёная карточка — ачивку уже кто-то получил, серая — пока никто. Нажми на ачивку — увидишь всех, кто её получил.</p>';
$earners = achievement_earners();
$byGroup = [];
foreach (achievements_catalog() as $k => $info) {
    if (!empty($info[4])) { // скрытые ачивки в общем зале славы не показываем
        continue;
    }
    [$ic, $t, $d, $grp] = $info;
    $byGroup[$grp][$k] = [$ic, $t, $d];
}
echo '<div class="ach-wrap"><div class="ach-main">';
foreach ($byGroup as $grp => $items) {
    echo '<div style="font-size:11.5px;color:var(--tx2);text-transform:uppercase;letter-spacing:0.6px;margin:12px 0 6px;">' . esc($grp) . '</div>';
    echo '<div class="ach-grid">';
    foreach ($items as $k => [$ic, $t, $d]) {
        $who = $earners[$k] ?? [];
        $cnt = count($who);
        $whoJson = esc(json_encode(array_slice($who, 0, 200), JSON_UNESCAPED_UNICODE));
        echo '<div class="ach' . ($cnt > 0 ? ' ach-on' : '') . '" data-who="' . $whoJson . '" data-title="' . esc($t) . '">'
            . '<div class="ach-ic">' . $ic . '</div><div class="ach-t">' . esc($t) . '</div>'
            . '<div class="ach-d">' . esc($d) . '</div><div class="ach-cnt">' . $cnt . ' получ.</div></div>';
    }
    echo '</div>';
}
echo '</div>'; // .ach-main
echo '<aside class="ach-side" id="ach-side"><div class="ach-side-inner">'
    . '<div class="ach-side-empty"><span class="ach-side-ic">🏆</span><span>Наведи курсор на любую ачивку —<br>и здесь появятся все, кто её получил</span></div>'
    . '</div></aside>';
echo '</div>'; // .ach-wrap

// ── Активность по месяцам ──
$act = db()->query("SELECT DATE_FORMAT(COALESCE(d.date, t.date_from), '%Y-%m') ym, COUNT(*) c
    FROM games g
    LEFT JOIN game_days d ON d.id = g.day_id
    LEFT JOIN tournaments t ON t.id = g.tournament_id
    WHERE g.status = 'finished' AND COALESCE(d.date, t.date_from) IS NOT NULL
    GROUP BY ym ORDER BY ym")->fetchAll();

if ($act) {
    $months = ['01' => 'янв', '02' => 'фев', '03' => 'мар', '04' => 'апр', '05' => 'май', '06' => 'июн',
        '07' => 'июл', '08' => 'авг', '09' => 'сен', '10' => 'окт', '11' => 'ноя', '12' => 'дек'];
    $labels = []; $data = [];
    foreach ($act as $a) {
        [$y, $m] = explode('-', $a['ym']);
        $labels[] = ($months[$m] ?? $m) . ' ' . substr($y, 2);
        $data[] = (int)$a['c'];
    }
    $chartData = json_encode(['labels' => $labels, 'data' => $data], JSON_UNESCAPED_UNICODE);
    echo '<div class="card"><h2 style="margin-top:0;">Активность клуба по месяцам</h2>'
        . '<div style="position:relative;height:240px;"><canvas id="ch-act" role="img" aria-label="Игр по месяцам"></canvas></div></div>';
    echo '<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>';
    $js = <<<JS
<script>(function(){var D=$chartData;if(typeof Chart==='undefined')return;
Chart.defaults.color='#9c9ca6';Chart.defaults.font.family="system-ui,-apple-system,'Segoe UI',Roboto,sans-serif";
new Chart(document.getElementById('ch-act'),{type:'bar',
  data:{labels:D.labels,datasets:[{data:D.data,backgroundColor:'#e8332a',borderRadius:5}]},
  options:{plugins:{legend:{display:false},tooltip:{callbacks:{label:function(c){return c.parsed.y+' игр';}}}},
    scales:{x:{grid:{display:false}},y:{beginAtZero:true,grid:{color:'rgba(255,255,255,0.08)'}}},maintainAspectRatio:false}});
})();</script>
JS;
    echo $js;
}

page_foot();
