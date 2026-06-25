<?php
require dirname(__DIR__) . '/inc/bootstrap.php';

$list = [];
if (db_ready()) {
    $list = db()->query('SELECT t.*,
            (SELECT COUNT(*) FROM tournament_regs r WHERE r.tournament_id = t.id) AS regs_cnt
        FROM tournaments t
        ORDER BY t.date_from DESC, t.id DESC LIMIT 50')->fetchAll();
}

$statusLabel = [
    'draft' => 'черновик', 'announced' => 'анонсирован', 'reg_open' => 'регистрация открыта',
    'live' => 'идёт сейчас', 'finished' => 'завершён',
];

page_head('Турниры', 'tournaments');
echo '<h1>Турниры</h1>';

if (user_can_judge(current_user())) {
    echo '<p style="margin:-6px 0 14px;"><a class="btn" href="/admin/tournaments.php">+ Создать турнир / управлять</a></p>';
}

if ($list) {
    foreach ($list as $t) {
        $tag = $t['status'] === 'reg_open' ? 'tag-open' : ($t['status'] === 'finished' ? '' : 'tag-ok');
        echo '<a class="card card-link t-card" href="/tournament.php?id=' . (int)$t['id'] . '">';
        if (!empty($t['logo'])) {
            echo '<span class="t-logo"><img src="' . esc($t['logo']) . '" alt=""></span>';
        }
        echo '<div class="t-info">';
        echo '<div class="t-head"><h2>' . esc($t['title']) . '</h2>';
        echo '<span class="tag ' . $tag . '">' . esc($statusLabel[$t['status']] ?? $t['status']) . '</span></div>';
        $dates = $t['date_from'] ? date('d.m.Y', strtotime($t['date_from'])) : '';
        if ($t['date_to'] && $t['date_to'] !== $t['date_from']) {
            $dates .= ' — ' . date('d.m.Y', strtotime($t['date_to']));
        }
        echo '<p class="t-meta">'
            . esc($dates)
            . ($t['location'] ? ' · ' . esc($t['location']) : '')
            . ' · столов: ' . (int)$t['tables_count']
            . ' · участников: ' . (int)$t['regs_cnt'] . '</p>';
        echo '</div></a>';
    }
} else {
    empty_state('Турниров пока нет', '«Точка кипения», «Турнир победы», кубки РХТУ — вся турнирная история переедет сюда на этапе 2, а новые турниры будут анонсироваться здесь.');
}
page_foot();
