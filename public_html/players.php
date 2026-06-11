<?php
require dirname(__DIR__) . '/inc/bootstrap.php';

$q = trim((string)($_GET['q'] ?? ''));
$list = [];

if (db_ready()) {
    $mainId = (int)db()->query('SELECT id FROM ratings WHERE is_main = 1 LIMIT 1')->fetchColumn();
    $sql = 'SELECT p.id, p.nickname, rc.games, rc.sum_total, rc.avg_total,
            (rc.w_civ + rc.w_maf + rc.w_sher + rc.w_don) AS wins
        FROM players p
        LEFT JOIN rating_cache rc ON rc.player_id = p.id AND rc.rating_id = ?
        WHERE p.banned_at IS NULL';
    $params = [$mainId];
    if ($q !== '') {
        $sql .= ' AND p.nickname LIKE ?';
        $params[] = '%' . $q . '%';
    }
    $sql .= ' ORDER BY (rc.games IS NULL), rc.games DESC, p.nickname LIMIT 300';
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
    echo '<div class="card" style="overflow-x:auto;"><table class="tbl">';
    echo '<tr><th>Игрок</th><th class="num">Игр</th><th class="num">Побед</th><th class="num">Σ</th><th class="num">~Σ</th></tr>';
    foreach ($list as $p) {
        $letter = mb_strtoupper(mb_substr($p['nickname'], 0, 1));
        echo '<tr><td><a href="/player.php?id=' . (int)$p['id'] . '" style="color:var(--tx);">'
            . '<span class="avatar-circle" style="margin-right:8px;">' . esc($letter) . '</span>'
            . esc($p['nickname']) . '</a></td>';
        echo '<td class="num">' . ($p['games'] !== null ? (int)$p['games'] : '—') . '</td>';
        echo '<td class="num">' . ($p['wins'] !== null ? (int)$p['wins'] : '—') . '</td>';
        echo '<td class="num">' . ($p['sum_total'] !== null ? number_format((float)$p['sum_total'], 2) : '—') . '</td>';
        echo '<td class="num">' . ($p['avg_total'] !== null ? number_format((float)$p['avg_total'], 2) : '—') . '</td></tr>';
    }
    echo '</table></div>';
} elseif ($q !== '') {
    empty_state('Никого не нашлось', 'Попробуйте изменить запрос.');
} else {
    empty_state('Список игроков пока пуст', 'Появится после переноса истории.');
}
page_foot();
