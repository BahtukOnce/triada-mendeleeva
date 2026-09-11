<?php
require dirname(__DIR__) . '/inc/bootstrap.php';

$isJudge = user_can_judge(current_user());
$list = [];
$pastSeasons = [];
$hasCur = false;
$season = (string)($_GET['season'] ?? '');
if (db_ready()) {
    // Сезон турнира — по дате начала (1 сент–31 авг), как вечера и профиль. Турнир без даты —
    // это готовящийся черновик, относим его к текущему сезону.
    $curLabel = current_season_bounds()[2];
    $seasonExpr = "IF(t.date_from IS NULL, " . db()->quote($curLabel) . ", CONCAT('Сезон ', (YEAR(t.date_from) - (MONTH(t.date_from) < 9)), '/', (YEAR(t.date_from) - (MONTH(t.date_from) < 9) + 1)))";
    // Судьи видят турниры в любом статусе; остальные — без черновиков
    $conds = $isJudge ? [] : ["t.status <> 'draft'"];
    $allSeasons = db()->query("SELECT DISTINCT $seasonExpr s FROM tournaments t"
        . ($conds ? ' WHERE ' . implode(' AND ', $conds) : '') . ' ORDER BY s DESC')->fetchAll(PDO::FETCH_COLUMN);
    $hasCur = in_array($curLabel, $allSeasons, true);
    $pastSeasons = array_values(array_filter($allSeasons, fn($s) => (string)$s !== $curLabel));
    // По умолчанию — текущий сезон; если турниров в нём ещё не было (начало сезона) — все,
    // чтобы список не «пропадал». Чужое значение в адресе молча сводим к тому же.
    if ($season !== 'cur' && $season !== 'all' && !in_array($season, $pastSeasons, true)) {
        $season = $hasCur ? 'cur' : 'all';
    }
    $params = [];
    if ($season === 'cur') {
        $conds[] = "$seasonExpr = ?";
        $params[] = $curLabel;
    } elseif ($season !== 'all') {
        $conds[] = "$seasonExpr = ?";
        $params[] = $season;
    }
    $st = db()->prepare('SELECT t.*,
            (SELECT COUNT(*) FROM tournament_participants tp WHERE tp.tournament_id = t.id AND tp.state = \'confirmed\') AS roster_cnt,
            (SELECT COUNT(DISTINCT gs.player_id) FROM games g
                JOIN game_seats gs ON gs.game_id = g.id
                WHERE g.tournament_id = t.id) AS players_cnt,
            (SELECT COUNT(*) FROM rating_cache rc WHERE rc.rating_id = t.legacy_rating_id) AS legacy_cnt
        FROM tournaments t
        ' . ($conds ? 'WHERE ' . implode(' AND ', $conds) : '') . '
        ORDER BY t.date_from DESC, t.id DESC LIMIT 100');
    $st->execute($params);
    $list = $st->fetchAll();
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

// Вкладки сезонов — как на «Игровых вечерах».
if ($hasCur || $pastSeasons) {
    echo '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px;">';
    $tabs = [['cur', 'Текущий сезон']];
    foreach ($pastSeasons as $s) {
        $tabs[] = [$s, $s];
    }
    $tabs[] = ['all', 'Все турниры'];
    foreach ($tabs as [$key, $label]) {
        echo '<a class="tag ' . ($season === $key ? 'tag-open' : '') . '" href="/tournaments.php?season=' . urlencode($key) . '">' . esc($label) . '</a>';
    }
    echo '</div>';
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
} elseif ($season === 'cur' && $pastSeasons) {
    empty_state('В этом сезоне турниров пока не было', 'Прошлые турниры — во вкладках выше. Новые анонсы появятся здесь.');
} else {
    empty_state('Турниров пока нет', 'Новые турниры будут анонсироваться здесь.');
}
page_foot();
