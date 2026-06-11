<?php
require dirname(__DIR__) . '/inc/bootstrap.php';

$q = trim((string)($_GET['q'] ?? ''));
$list = [];

if (db_ready()) {
    $mainId = (int)db()->query('SELECT id FROM ratings WHERE is_main = 1 LIMIT 1')->fetchColumn();
    $sql = 'SELECT p.id, p.nickname, p.avatar, p.fav_role, rc.games
        FROM players p
        LEFT JOIN rating_cache rc ON rc.player_id = p.id AND rc.rating_id = ?
        WHERE p.banned_at IS NULL';
    $params = [$mainId];
    if ($q !== '') {
        $sql .= ' AND p.nickname LIKE ?';
        $params[] = '%' . $q . '%';
    }
    $sql .= ' ORDER BY p.nickname LIMIT 400';
    $st = db()->prepare($sql);
    $st->execute($params);
    $list = $st->fetchAll();
}

page_head('Игроки', 'players');
echo '<h1>Игроки</h1>';

echo '<form method="get" action="/players.php" style="max-width:340px;margin-bottom:14px;">';
echo '<div class="field" style="margin:0;"><input type="search" name="q" placeholder="Поиск по нику" value="' . esc($q) . '"></div>';
echo '</form>';

if ($list) {
    $roleLbl = ['civ' => 'Мирный', 'sheriff' => 'Шериф', 'maf' => 'Мафия', 'don' => 'Дон'];
    echo '<p style="color:var(--tx2);font-size:13px;margin:0 0 8px;">Всего игроков: ' . count($list) . '</p>';
    echo '<div class="card" style="overflow-x:auto;"><table class="tbl row-link">';
    echo '<tr><th>Игрок</th><th class="num">Игр</th><th>Любимая роль</th></tr>';
    foreach ($list as $p) {
        echo '<tr data-href="/player.php?id=' . (int)$p['id'] . '"><td>'
            . avatar_html(['nickname' => $p['nickname'], 'avatar' => $p['avatar']], 26, 'margin-right:8px;')
            . '<span style="vertical-align:middle;">' . esc($p['nickname']) . '</span></td>';
        echo '<td class="num">' . ($p['games'] !== null ? (int)$p['games'] : '—') . '</td>';
        echo '<td style="color:var(--tx2);">' . ($p['fav_role'] ? esc($roleLbl[$p['fav_role']]) : '—') . '</td></tr>';
    }
    echo '</table></div>';
} elseif ($q !== '') {
    empty_state('Никого не нашлось', 'Попробуйте изменить запрос.');
} else {
    empty_state('Список игроков пока пуст', 'Появится после переноса истории.');
}
page_foot();
