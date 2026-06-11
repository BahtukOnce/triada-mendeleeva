<?php
require dirname(__DIR__) . '/inc/bootstrap.php';

$list = [];
if (db_ready()) {
    $list = db()->query('SELECT d.*,
            (SELECT COUNT(*) FROM games g WHERE g.day_id = d.id) AS games_cnt,
            (SELECT COUNT(*) FROM day_registrations r WHERE r.day_id = d.id AND r.cancelled_at IS NULL) AS regs_cnt
        FROM game_days d
        ORDER BY d.date DESC LIMIT 100')->fetchAll();
}

$statusLabel = [
    'draft' => 'черновик', 'reg_open' => 'запись открыта', 'reg_closed' => 'запись закрыта',
    'live' => 'идёт сейчас', 'finished' => 'завершён',
];

page_head('Игровые вечера', 'days');
echo '<h1>Игровые вечера</h1>';

if ($list) {
    echo '<div class="card"><table class="tbl">';
    echo '<tr><th>Дата</th><th>Вечер</th><th>Статус</th><th class="num">Игр</th><th class="num">Записалось</th></tr>';
    foreach ($list as $d) {
        $tag = $d['status'] === 'reg_open' ? 'tag-open' : ($d['status'] === 'finished' ? '' : 'tag-ok');
        echo '<tr>';
        echo '<td>' . esc(date('d.m.Y', strtotime($d['date']))) . '</td>';
        echo '<td>' . esc($d['title']) . '</td>';
        echo '<td><span class="tag ' . $tag . '">' . esc($statusLabel[$d['status']] ?? $d['status']) . '</span></td>';
        echo '<td class="num">' . (int)$d['games_cnt'] . '</td>';
        echo '<td class="num">' . (int)$d['regs_cnt'] . '</td>';
        echo '</tr>';
    }
    echo '</table></div>';
    echo '<p style="color:var(--tx2);font-size:13px;">Страницы вечеров с полными протоколами игр появятся на этапе 2.</p>';
} else {
    empty_state('Архив вечеров пока пуст', 'После переноса истории из таблиц здесь будут все игровые вечера клуба с протоколами каждой игры (этап 2).');
}
page_foot();
