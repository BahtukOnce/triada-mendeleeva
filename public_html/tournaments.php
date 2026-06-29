<?php
require dirname(__DIR__) . '/inc/bootstrap.php';

$isJudge = user_can_judge(current_user());
$list = [];
if (db_ready()) {
    // Судьи видят турниры в любом статусе; остальные — без черновиков
    $where = $isJudge ? '' : "WHERE t.status <> 'draft'";
    $list = db()->query('SELECT t.*,
            (SELECT COUNT(*) FROM tournament_participants tp WHERE tp.tournament_id = t.id AND tp.state = \'confirmed\') AS roster_cnt,
            (SELECT COUNT(DISTINCT gs.player_id) FROM games g
                JOIN game_seats gs ON gs.game_id = g.id
                WHERE g.tournament_id = t.id) AS players_cnt,
            (SELECT COUNT(*) FROM rating_cache rc WHERE rc.rating_id = t.legacy_rating_id) AS legacy_cnt
        FROM tournaments t
        ' . $where . '
        ORDER BY t.date_from DESC, t.id DESC LIMIT 50')->fetchAll();
}

$statusLabel = [
    'draft' => 'черновик', 'announced' => 'анонсирован', 'reg_open' => 'регистрация открыта',
    'live' => 'идёт сейчас', 'review' => 'сверка результатов', 'finished' => 'завершён',
];

page_head('Турниры', 'tournaments');
echo '<h1>Турниры</h1>';

if ($isJudge) {
    echo '<p style="margin:-6px 0 14px;"><a class="btn" href="/admin/tournaments.php">+ Создать турнир / управлять</a></p>';
}

if ($list) {
    foreach ($list as $t) {
        $tag = $t['status'] === 'reg_open' ? 'tag-open' : ($t['status'] === 'finished' ? '' : 'tag-ok');
        // Черновик у судьи открывается сразу в редакторе
        $href = !empty($t['legacy_rating_id'])
            ? '/rating.php?r=' . (int)$t['legacy_rating_id']
            : (($t['status'] === 'draft' && $isJudge) ? '/admin/tournaments.php?edit=' . (int)$t['id'] : '/tournament.php?id=' . (int)$t['id']);
        echo '<a class="card card-link t-card" href="' . $href . '">';
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
        $participants = max((int)$t['players_cnt'], (int)$t['roster_cnt'], (int)($t['legacy_cnt'] ?? 0));
        echo '<p class="t-meta">'
            . esc($dates)
            . ($t['location'] ? ' · ' . esc($t['location']) : '')
            . ' · столов: ' . (int)$t['tables_count']
            . ' · участников: ' . $participants . '</p>';
        echo '</div></a>';
    }
} else {
    empty_state('Турниров пока нет', '«Точка кипения», «Турнир победы», кубки РХТУ — вся турнирная история переедет сюда на этапе 2, а новые турниры будут анонсироваться здесь.');
}
page_foot();
