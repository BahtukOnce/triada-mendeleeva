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

$mainId = (int)db()->query('SELECT id FROM ratings WHERE is_main = 1 LIMIT 1')->fetchColumn();
$rows = $mainId ? db()->query("SELECT rc.*, p.nickname, p.avatar, p.flair, p.elo, p.id AS pid
    FROM rating_cache rc JOIN players p ON p.id = rc.player_id WHERE rc.rating_id = $mainId")->fetchAll() : [];

if (!$rows) {
    empty_state('Рекордов пока нет', 'Таблица появится после первых игр.');
    page_foot();
    exit;
}

// топ ELO — по всем игрокам (включая турнирных)
$topEloRow = db()->query('SELECT nickname, avatar, flair, elo, id AS pid FROM players ORDER BY elo DESC LIMIT 1')->fetch();

$wins = fn($r) => (int)$r['w_civ'] + (int)$r['w_maf'] + (int)$r['w_sher'] + (int)$r['w_don'];
$leader = function (array $rows, callable $metric, int $minGames = 0) {
    $best = null; $bestV = -INF;
    foreach ($rows as $r) {
        if ((int)$r['games'] < $minGames) {
            continue;
        }
        $v = $metric($r);
        if ($v > $bestV) { $bestV = $v; $best = $r; }
    }
    return $best ? [$best, $bestV] : null;
};

$fmt = function ($v, string $type): string {
    return match ($type) {
        'pct' => round($v * 100) . '%',
        'int' => (string)(int)$v,
        'f1' => number_format((float)$v, 1),
        'f2' => number_format((float)$v, 2),
        default => (string)$v,
    };
};

$records = [];
if ($topEloRow) {
    $records[] = ['👑', 'Высший ELO', $topEloRow, (float)$topEloRow['elo'], 'int'];
}
$add = function (string $ic, string $title, ?array $res, string $type) use (&$records) {
    if ($res) {
        $records[] = [$ic, $title, $res[0], $res[1], $type];
    }
};
$add('💯', 'Высший клубный счёт', $leader($rows, fn($r) => (float)$r['club_score']), 'f2');
$add('🏆', 'Лучший винрейт (от 30 игр)', $leader($rows, fn($r) => $r['games'] ? $wins($r) / $r['games'] : 0, 30), 'pct');
$add('🎮', 'Больше всех игр', $leader($rows, fn($r) => (int)$r['games']), 'int');
$add('➕', 'Больше всех допов', $leader($rows, fn($r) => (float)$r['dop_sum']), 'f1');
$add('🔪', 'Больше всех ПУ', $leader($rows, fn($r) => (int)$r['pu_count']), 'int');
$add('🌟', 'Больше всех ЛХ', $leader($rows, fn($r) => (float)$r['lh_sum']), 'f1');
$add('📊', 'Высший средний (~Σ)', $leader($rows, fn($r) => (float)$r['avg_total']), 'f2');

echo '<div class="records-grid">';
foreach ($records as [$ic, $title, $row, $val, $type]) {
    echo '<a class="rec-card" href="/player.php?id=' . (int)$row['pid'] . '">';
    echo '<div class="rec-ic">' . $ic . '</div>';
    echo '<div class="rec-body"><div class="rec-title">' . esc($title) . '</div>';
    echo '<div class="rec-player">' . avatar_html($row, 30) . '<span>' . player_label($row) . '</span></div></div>';
    echo '<div class="rec-val">' . esc($fmt($val, $type)) . '</div>';
    echo '</a>';
}
echo '</div>';

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
