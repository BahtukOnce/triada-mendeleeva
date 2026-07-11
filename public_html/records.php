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
    $rowsP = db()->query("SELECT gs.game_id, gs.player_id, gs.role, g.winner
        FROM game_seats gs JOIN games g ON g.id = gs.game_id
        WHERE g.status = 'finished' AND g.winner IN ('red','black')")->fetchAll();
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
    $bestPairs = [];
    foreach ($pairAgg as $k => $v) {
        if ($v['g'] >= 6) {
            $bestPairs[] = ['k' => $k, 'g' => $v['g'], 'w' => $v['w'], 'wr' => $v['w'] / $v['g']];
        }
    }
    usort($bestPairs, fn($x, $y) => [$y['wr'], $y['g']] <=> [$x['wr'], $x['g']]);
    $bestPairs = array_slice($bestPairs, 0, 6);
    if ($bestPairs) {
        $pids = [];
        foreach ($bestPairs as $bp) {
            [$a, $b] = explode('-', $bp['k']);
            $pids[(int)$a] = 1;
            $pids[(int)$b] = 1;
        }
        $inP = implode(',', array_fill(0, count($pids), '?'));
        $pq = db()->prepare("SELECT id, nickname, avatar, flair FROM players WHERE id IN ($inP)");
        $pq->execute(array_keys($pids));
        $plMap = [];
        foreach ($pq->fetchAll() as $pl) {
            $plMap[(int)$pl['id']] = $pl;
        }
        echo '<h2 style="margin-top:18px;">🤝 Лучшие дуэты</h2>';
        echo '<p style="color:var(--tx2);font-size:13px;margin-top:-6px;">пары одного цвета с лучшим винрейтом вместе (от 6 совместных игр) — клик откроет «Дуэль»</p>';
        echo '<div class="records-grid"><div class="rec-card"><div class="rec-rows">';
        $rankP = 0;
        foreach ($bestPairs as $bp) {
            [$a, $b] = array_map('intval', explode('-', $bp['k']));
            $pa = $plMap[$a] ?? null;
            $pb = $plMap[$b] ?? null;
            if (!$pa || !$pb) {
                continue;
            }
            $rankP++;
            $medal = $rankP === 1 ? '🥇' : ($rankP === 2 ? '🥈' : ($rankP === 3 ? '🥉' : '·'));
            echo '<a class="rec-row" href="/versus.php?a=' . $a . '&b=' . $b . '">'
                . '<span class="rec-rank">' . $medal . '</span>'
                . avatar_html($pa, 24) . avatar_html($pb, 24)
                . '<span class="rec-name">' . esc($pa['nickname']) . ' + ' . esc($pb['nickname']) . '</span>'
                . '<span class="rec-v">' . round($bp['wr'] * 100) . '% · ' . $bp['g'] . ' игр</span></a>';
        }
        echo '</div></div></div>';
    }
} catch (Throwable $e) {
}

// ── Битва факультетов РХТУ: командный зачёт по анкетам ──
try {
    $mainIdF = (int)db()->query('SELECT id FROM ratings WHERE is_main = 1 LIMIT 1')->fetchColumn();
    $fq = db()->prepare("SELECT p.faculty, p.elo, rc.games,
            (COALESCE(rc.w_civ,0) + COALESCE(rc.w_maf,0) + COALESCE(rc.w_sher,0) + COALESCE(rc.w_don,0)) AS wins,
            COALESCE(rc.dop_sum, 0) AS dops
        FROM players p
        LEFT JOIN rating_cache rc ON rc.player_id = p.id AND rc.rating_id = ?
        WHERE p.faculty IS NOT NULL AND TRIM(p.faculty) <> ''");
    $fq->execute([$mainIdF]);
    $fac = [];
    foreach ($fq->fetchAll() as $r) {
        $key = mb_strtoupper(trim((string)$r['faculty'])); // нормализация: ИМиХТ = имихт
        if (!isset($fac[$key])) {
            $fac[$key] = ['label' => trim((string)$r['faculty']), 'members' => 0, 'games' => 0, 'wins' => 0, 'eloSum' => 0.0, 'eloN' => 0, 'dops' => 0.0];
        }
        $fac[$key]['members']++;
        $fac[$key]['games'] += (int)($r['games'] ?? 0);
        $fac[$key]['wins'] += (int)$r['wins'];
        $fac[$key]['dops'] += (float)$r['dops'];
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
        echo '<h2 style="margin-top:18px;">🏛 Битва факультетов</h2>';
        echo '<p style="color:var(--tx2);font-size:13px;margin-top:-6px;">командный зачёт по анкетам игроков (факультет — в личном кабинете); факультеты с <10 играми — вне зачёта</p>';
        echo '<div class="card" style="overflow-x:auto;"><table class="tbl">';
        echo '<tr><th>#</th><th>Факультет</th><th class="num">Игроков</th><th class="num">Игр</th><th class="num">Побед</th><th class="num">Винрейт</th><th class="num">Ср. ELO</th><th class="num">Допы</th></tr>';
        $posF = 0;
        foreach ($fac as $f) {
            $posF++;
            $wr = $f['games'] > 0 ? round($f['wins'] / $f['games'] * 100) : 0;
            $inRace = $f['games'] >= 10;
            $medalF = !$inRace ? '·' : ($posF === 1 ? '🥇' : ($posF === 2 ? '🥈' : ($posF === 3 ? '🥉' : $posF)));
            $wrCol = !$inRace ? 'var(--tx3)' : ($wr >= 52 ? 'var(--ok)' : ($wr <= 48 ? 'var(--ac)' : 'var(--tx)'));
            echo '<tr' . ($inRace ? '' : ' style="opacity:.55;"') . '>'
                . '<td>' . $medalF . '</td>'
                . '<td><b>' . esc($f['label']) . '</b></td>'
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
