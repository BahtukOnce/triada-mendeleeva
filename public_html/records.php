<?php
require dirname(__DIR__) . '/inc/bootstrap.php';

page_head('Зал славы', 'records');
echo '<h1>Зал славы клуба</h1>';
echo '<p style="margin-top:-6px;display:flex;gap:8px;flex-wrap:wrap;">'
    . '<a class="btn btn-ghost" href="/vs.php">⚔ Очная ставка — сравнить двух игроков</a>'
    . '<a class="btn btn-ghost" href="/rating.php#elo">❓ Как считается ELO</a></p>';

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

// ── Достижения (с теми, кто получил) ──
echo '<h2 style="margin-top:18px;">Достижения</h2>';
echo '<p style="color:var(--tx2);font-size:13px;margin-top:-6px;">Зелёная карточка — ачивку уже кто-то получил, серая — пока никто. Наведи курсор на ачивку — справа появятся все, кто её получил.</p>';
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
