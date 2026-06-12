<?php
require dirname(__DIR__) . '/inc/bootstrap.php';

$q = trim((string)($_GET['q'] ?? ''));
$list = [];

if (db_ready()) {
    $mainId = (int)db()->query('SELECT id FROM ratings WHERE is_main = 1 LIMIT 1')->fetchColumn();
    // Игры и победы — ГЛОБАЛЬНО (дни + турниры), а не только основной рейтинг,
    // чтобы у турнирных игроков тоже были игры и винрейт.
    $sql = "SELECT p.id, p.nickname, p.avatar, p.fav_role, p.flair, p.elo,
            agg.games, agg.wins
        FROM players p
        LEFT JOIN (
            SELECT gs.player_id,
                COUNT(*) AS games,
                SUM(CASE WHEN (g.winner = 'red' AND gs.role IN ('civ','sheriff'))
                          OR (g.winner = 'black' AND gs.role IN ('maf','don')) THEN 1 ELSE 0 END) AS wins
            FROM game_seats gs JOIN games g ON g.id = gs.game_id
            WHERE g.status = 'finished'
            GROUP BY gs.player_id
        ) agg ON agg.player_id = p.id
        WHERE p.banned_at IS NULL";
    $params = [];
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
    echo '<p style="color:var(--tx2);font-size:13px;margin:0 0 10px;">Всего игроков: ' . count($list) . '</p>';
    echo '<div class="player-grid">';
    foreach ($list as $p) {
        $g = $p['games'] !== null ? (int)$p['games'] : 0;
        $w = (int)$p['wins'];
        $wr = $g ? round($w / $g * 100) : null;
        $elo = (int)round((float)($p['elo'] ?? 1000));
        echo '<a class="player-card" href="/player.php?id=' . (int)$p['id'] . '">';
        $favHtml = $p['fav_role']
            ? '<span class="fav-chip"><span class="fdot" style="background:' . role_color($p['fav_role'] === 'sheriff' ? 'sheriff' : $p['fav_role']) . ';"></span>' . esc($roleLbl[$p['fav_role']]) . '</span>'
            : '<span style="color:var(--tx3);font-size:11.5px;">роль не выбрана</span>';
        echo '<div class="pc-top">' . avatar_html(['nickname' => $p['nickname'], 'avatar' => $p['avatar']], 42)
            . '<div class="pc-name">' . player_label($p)
            . '<div class="pc-fav">' . $favHtml . '</div></div></div>';
        echo '<div class="pc-stats">'
            . '<div><b>' . $g . '</b><span>игр</span></div>'
            . '<div><b>' . ($wr !== null ? $wr . '%' : '—') . '</b><span>винрейт</span></div>'
            . '<div><b>' . $elo . '</b><span>ELO</span></div>'
            . '</div></a>';
    }
    echo '</div>';
} elseif ($q !== '') {
    empty_state('Никого не нашлось', 'Попробуйте изменить запрос.');
} else {
    empty_state('Список игроков пока пуст', 'Появится после переноса истории.');
}
page_foot();
