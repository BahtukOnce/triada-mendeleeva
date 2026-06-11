<?php
require dirname(__DIR__) . '/inc/bootstrap.php';

$ratings = [];
$current = null;
$rows = [];

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
        // Рейтинг всегда по принципу клуба (~Σ×Σ); дальнейшая сортировка — кликом по колонке (JS)
        $st = db()->prepare("SELECT rc.*, p.nickname, p.avatar FROM rating_cache rc
            JOIN players p ON p.id = rc.player_id
            WHERE rc.rating_id = ?
            ORDER BY (rc.club_score IS NULL), rc.club_score DESC, rc.sum_total DESC LIMIT 300");
        $st->execute([$current['id']]);
        $rows = $st->fetchAll();
    }
}

// Винрейт: процент (для сортировки) + дробь
function wr_cell(int $w, int $g): string
{
    if (!$g) {
        return '<td class="num c-cards" data-sort="-1"><div style="text-align:center;color:var(--tx3);">—</div></td>';
    }
    $pct = round($w / $g * 100);
    return '<td class="num c-cards" data-sort="' . $pct . '"><div style="white-space:nowrap;line-height:1.15;text-align:center;">'
        . $pct . '%<div style="font-size:11px;color:var(--tx2);">' . $w . '/' . $g . '</div></div></td>';
}

page_head('Рейтинг', 'rating');
echo '<h1>Рейтинг</h1>';

if (count($ratings) > 1) {
    echo '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px;">';
    foreach ($ratings as $r) {
        $on = $current && (int)$r['id'] === (int)$current['id'];
        echo '<a class="tag ' . ($on ? 'tag-open' : '') . '" href="/rating.php?r=' . (int)$r['id'] . '">' . esc($r['title']) . '</a>';
    }
    echo '</div>';
}

if ($rows) {
    echo '<p style="color:var(--tx2);font-size:12.5px;margin:0 0 8px;">Рейтинг по принципу клуба (~Σ×Σ). '
        . 'Нажмите на заголовок колонки, чтобы отсортировать.</p>';

    echo '<div class="card" style="overflow-x:auto;padding:8px 10px;">';
    echo '<table class="tbl sortable rating-tbl" style="font-size:13px;">';
    echo '<thead><tr>'
        . '<th data-type="num">#</th><th>Игрок</th>'
        . '<th class="num" data-type="num">~Σ×Σ</th><th class="num" data-type="num">~Σ</th><th class="num" data-type="num">Σ</th>'
        . '<th class="num" data-type="num">Σ+</th><th class="num" data-type="num">Игр</th><th class="num" data-type="num">ПУ</th><th class="num" data-type="num">ЛХ</th>'
        . '<th class="num" data-type="num">Допы</th><th class="num" data-type="num">−</th><th class="num" data-type="num">Ci</th>'
        . '<th class="c-cards c-cards-first" data-type="num">Общ</th><th class="c-cards" data-type="num">Мир</th>'
        . '<th class="c-cards" data-type="num">Маф</th><th class="c-cards" data-type="num">Шер</th><th class="c-cards" data-type="num">Дон</th>'
        . '</tr></thead><tbody>';
    $pos = 0;
    foreach ($rows as $row) {
        $pos++;
        $w = $row['w_civ'] + $row['w_maf'] + $row['w_sher'] + $row['w_don'];
        echo '<tr>';
        echo '<td data-sort="' . $pos . '">' . $pos . '</td>';
        echo '<td><a class="rt-player" href="/player.php?id=' . (int)$row['player_id'] . '" style="color:var(--tx);">'
            . avatar_html(['nickname' => $row['nickname'], 'avatar' => $row['avatar']], 26, 'margin-right:8px;')
            . '<span>' . esc($row['nickname']) . '</span></a></td>';
        echo '<td class="num" data-sort="' . (float)$row['club_score'] . '"><b>' . ($row['club_score'] !== null ? number_format((float)$row['club_score'], 2) : '—') . '</b></td>';
        echo '<td class="num" data-sort="' . (float)$row['avg_total'] . '">' . ($row['avg_total'] !== null ? number_format((float)$row['avg_total'], 2) : '—') . '</td>';
        echo '<td class="num" data-sort="' . (float)$row['sum_total'] . '">' . number_format((float)$row['sum_total'], 2) . '</td>';
        echo '<td class="num" data-sort="' . (float)$row['sum_plus'] . '">' . number_format((float)$row['sum_plus'], 2) . '</td>';
        echo '<td class="num" data-sort="' . (int)$row['games'] . '">' . (int)$row['games'] . '</td>';
        echo '<td class="num" data-sort="' . (int)$row['pu_count'] . '">' . (int)$row['pu_count'] . '</td>';
        echo '<td class="num" data-sort="' . (float)$row['lh_sum'] . '">' . number_format((float)$row['lh_sum'], 1) . '</td>';
        echo '<td class="num" data-sort="' . (float)$row['dop_sum'] . '">' . number_format((float)$row['dop_sum'], 1) . '</td>';
        echo '<td class="num" data-sort="' . (float)$row['minus_sum'] . '">' . number_format((float)$row['minus_sum'], 1) . '</td>';
        echo '<td class="num" data-sort="' . (float)$row['ci_sum'] . '">' . number_format((float)$row['ci_sum'], 2) . '</td>';
        echo str_replace('c-cards"', 'c-cards c-cards-first"', wr_cell((int)$w, (int)$row['games']));
        echo wr_cell((int)$row['w_civ'], (int)$row['g_civ']);
        echo wr_cell((int)$row['w_maf'], (int)$row['g_maf']);
        echo wr_cell((int)$row['w_sher'], (int)$row['g_sher']);
        echo wr_cell((int)$row['w_don'], (int)$row['g_don']);
        echo '</tr>';
    }
    echo '</tbody></table></div>';
    echo '<p style="color:var(--tx2);font-size:12.5px;">Слева — клубный счёт и баллы; справа (выделено) — '
        . '<b style="color:var(--tx2);">статистика по картам</b>: винрейт общий и по ролям. '
        . 'Σ — сумма итогов; Σ+ — допы + ЛХ + Ci; ~Σ — средний балл; ПУ — первоубиенный; ЛХ — лучший ход; Ci — компенсации.</p>';
} else {
    empty_state('Рейтинг пока пуст', 'Таблица появится после переноса истории игр.');
}
page_foot();
