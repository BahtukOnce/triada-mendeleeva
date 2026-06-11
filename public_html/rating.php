<?php
require dirname(__DIR__) . '/inc/bootstrap.php';

$ratings = [];
$current = null;
$rows = [];

if (db_ready()) {
    $ratings = db()->query('SELECT * FROM ratings WHERE is_active = 1 ORDER BY is_main DESC, id DESC')->fetchAll();
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
        $st = db()->prepare('SELECT rc.*, p.nickname FROM rating_cache rc
            JOIN players p ON p.id = rc.player_id
            WHERE rc.rating_id = ?
            ORDER BY (rc.club_score IS NULL), rc.club_score DESC, rc.sum_total DESC
            LIMIT 200');
        $st->execute([$current['id']]);
        $rows = $st->fetchAll();
    }
}

page_head('Рейтинг', 'rating');
echo '<h1>Рейтинг</h1>';

if (count($ratings) > 1) {
    echo '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:6px;">';
    foreach ($ratings as $r) {
        $on = $current && (int)$r['id'] === (int)$current['id'];
        echo '<a class="tag ' . ($on ? 'tag-open' : '') . '" href="/rating.php?r=' . (int)$r['id'] . '">' . esc($r['title']) . '</a>';
    }
    echo '</div>';
}

if ($rows) {
    echo '<div class="card" style="overflow-x:auto;"><table class="tbl">';
    echo '<tr><th>#</th><th>Игрок</th><th class="num">~Σ×Σ</th><th class="num">Σ</th><th class="num">Σ+</th>'
        . '<th class="num">Игр</th><th class="num">ПУ</th><th class="num">ЛХ</th><th class="num">Допы</th><th class="num">−</th><th class="num">Ci</th></tr>';
    $pos = 0;
    foreach ($rows as $row) {
        $pos++;
        echo '<tr><td>' . $pos . '</td><td>' . esc($row['nickname']) . '</td>'
            . '<td class="num">' . ($row['club_score'] !== null ? number_format((float)$row['club_score'], 2, '.', ' ') : '—') . '</td>'
            . '<td class="num">' . number_format((float)$row['sum_total'], 2, '.', ' ') . '</td>'
            . '<td class="num">' . number_format((float)$row['sum_plus'], 2, '.', ' ') . '</td>'
            . '<td class="num">' . (int)$row['games'] . '</td>'
            . '<td class="num">' . (int)$row['pu_count'] . '</td>'
            . '<td class="num">' . number_format((float)$row['lh_sum'], 1, '.', ' ') . '</td>'
            . '<td class="num">' . number_format((float)$row['dop_sum'], 1, '.', ' ') . '</td>'
            . '<td class="num">' . number_format((float)$row['minus_sum'], 1, '.', ' ') . '</td>'
            . '<td class="num">' . number_format((float)$row['ci_sum'], 2, '.', ' ') . '</td></tr>';
    }
    echo '</table></div>';
} else {
    empty_state('Рейтинг пока пуст', 'После переноса истории (этап 2) здесь будет таблица с Σ, Σ+, ПУ, ЛХ, допами, Ci и статистикой по ролям — как в клубной таблице, с сортировкой «по принципу клуба».');
}
page_foot();
