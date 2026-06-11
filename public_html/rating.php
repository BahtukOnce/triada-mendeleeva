<?php
require dirname(__DIR__) . '/inc/bootstrap.php';

$ratings = [];
$current = null;
$rows = [];
$sort = in_array($_GET['sort'] ?? '', ['club', 'avg', 'minus'], true) ? $_GET['sort'] : 'club';

if (db_ready()) {
    $ratings = db()->query('SELECT * FROM ratings WHERE is_active = 1 ORDER BY is_main DESC, id ASC')->fetchAll();
    $reqId = isset($_GET['r']) ? (int)$_GET['r'] : 0;
    foreach ($ratings as $r) {
        if ((int)$r['id'] === $reqId) {
            $current = $r;
        }
    }
    if (!$current && $ratings) {
        $current = $ratings[0];
    }
    if ($current) {
        $order = [
            'club' => '(rc.club_score IS NULL), rc.club_score DESC, rc.sum_total DESC',
            'avg' => '(rc.avg_total IS NULL), rc.avg_total DESC, rc.sum_total DESC',
            'minus' => 'rc.minus_sum ASC, rc.sum_total DESC',
        ][$sort];
        $st = db()->prepare("SELECT rc.*, p.nickname FROM rating_cache rc
            JOIN players p ON p.id = rc.player_id
            WHERE rc.rating_id = ? ORDER BY $order LIMIT 300");
        $st->execute([$current['id']]);
        $rows = $st->fetchAll();
    }
}

function wr(int $w, int $g): string
{
    if (!$g) {
        return '<span style="color:var(--tx3);">—</span>';
    }
    $pct = round($w / $g * 100);
    return '<div style="white-space:nowrap;line-height:1.15;">' . $pct . '%'
        . '<div style="font-size:11px;color:var(--tx2);">' . $w . '/' . $g . '</div></div>';
}

page_head('Рейтинг', 'rating');
echo '<h1>Рейтинг</h1>';

if (count($ratings) > 1) {
    echo '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:8px;">';
    foreach ($ratings as $r) {
        $on = $current && (int)$r['id'] === (int)$current['id'];
        echo '<a class="tag ' . ($on ? 'tag-open' : '') . '" href="/rating.php?r=' . (int)$r['id'] . '">' . esc($r['title']) . '</a>';
    }
    echo '</div>';
}

if ($rows) {
    $base = '/rating.php?r=' . (int)$current['id'] . '&sort=';
    echo '<p style="font-size:13px;color:var(--tx2);margin:4px 0 10px;">Сортировка: ';
    foreach ([['club', 'по принципу клуба (~Σ×Σ)'], ['avg', 'по среднему (~Σ)'], ['minus', 'по минусам']] as [$k, $lbl]) {
        $on = $sort === $k;
        echo '<a class="tag ' . ($on ? 'tag-open' : '') . '" style="margin-right:6px;" href="' . $base . $k . '">' . $lbl . '</a>';
    }
    echo '</p>';

    echo '<div class="card" style="overflow-x:auto;padding:8px 10px;"><table class="tbl" style="font-size:13px;">';
    echo '<tr><th>#</th><th>Игрок</th><th class="num">~Σ×Σ</th><th class="num">~Σ</th><th class="num">Σ</th>'
        . '<th class="num">Σ+</th><th class="num">Игр</th><th class="num">ПУ</th><th class="num">ЛХ</th>'
        . '<th class="num">Допы</th><th class="num">−</th><th class="num">Ci</th>'
        . '<th class="num">Общ</th><th class="num">Мир</th><th class="num">Маф</th><th class="num">Шер</th><th class="num">Дон</th></tr>';
    $pos = 0;
    foreach ($rows as $row) {
        $pos++;
        $w = $row['w_civ'] + $row['w_maf'] + $row['w_sher'] + $row['w_don'];
        echo '<tr>';
        echo '<td>' . $pos . '</td>';
        echo '<td><a href="/player.php?id=' . (int)$row['player_id'] . '" style="color:var(--tx);">' . esc($row['nickname']) . '</a></td>';
        echo '<td class="num"><b>' . ($row['club_score'] !== null ? number_format((float)$row['club_score'], 2) : '—') . '</b></td>';
        echo '<td class="num">' . ($row['avg_total'] !== null ? number_format((float)$row['avg_total'], 2) : '—') . '</td>';
        echo '<td class="num">' . number_format((float)$row['sum_total'], 2) . '</td>';
        echo '<td class="num">' . number_format((float)$row['sum_plus'], 2) . '</td>';
        echo '<td class="num">' . (int)$row['games'] . '</td>';
        echo '<td class="num">' . (int)$row['pu_count'] . '</td>';
        echo '<td class="num">' . number_format((float)$row['lh_sum'], 1) . '</td>';
        echo '<td class="num">' . number_format((float)$row['dop_sum'], 1) . '</td>';
        echo '<td class="num">' . number_format((float)$row['minus_sum'], 1) . '</td>';
        echo '<td class="num">' . number_format((float)$row['ci_sum'], 2) . '</td>';
        echo '<td class="num">' . wr((int)$w, (int)$row['games']) . '</td>';
        echo '<td class="num">' . wr((int)$row['w_civ'], (int)$row['g_civ']) . '</td>';
        echo '<td class="num">' . wr((int)$row['w_maf'], (int)$row['g_maf']) . '</td>';
        echo '<td class="num">' . wr((int)$row['w_sher'], (int)$row['g_sher']) . '</td>';
        echo '<td class="num">' . wr((int)$row['w_don'], (int)$row['g_don']) . '</td>';
        echo '</tr>';
    }
    echo '</table></div>';
    echo '<p style="color:var(--tx2);font-size:12.5px;">Σ — сумма итогов; Σ+ — допы + ЛХ + Ci; ~Σ — средний балл; '
        . '~Σ×Σ — сортировка «по принципу клуба»; ПУ — сколько раз первоубиенный; ЛХ — бонусы за лучший ход; Ci — компенсации.</p>';
} else {
    empty_state('Рейтинг пока пуст', 'Таблица появится после переноса истории игр.');
}
page_foot();
