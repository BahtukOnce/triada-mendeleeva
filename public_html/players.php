<?php
require dirname(__DIR__) . '/inc/bootstrap.php';

$q = trim((string)($_GET['q'] ?? ''));
$list = [];

if (db_ready()) {
    if ($q !== '') {
        $st = db()->prepare('SELECT id, nickname, avatar FROM players
            WHERE banned_at IS NULL AND nickname LIKE ? ORDER BY nickname LIMIT 100');
        $st->execute(['%' . $q . '%']);
        $list = $st->fetchAll();
    } else {
        $list = db()->query('SELECT id, nickname, avatar FROM players
            WHERE banned_at IS NULL ORDER BY nickname LIMIT 200')->fetchAll();
    }
}

page_head('Игроки', 'players');
echo '<h1>Игроки</h1>';

echo '<form method="get" action="/players.php" style="max-width:340px;margin-bottom:14px;">';
echo '<div class="field" style="margin:0;"><input type="search" name="q" placeholder="Поиск по нику" value="' . esc($q) . '"></div>';
echo '</form>';

if ($list) {
    echo '<div class="card"><table class="tbl"><tr><th>Игрок</th></tr>';
    foreach ($list as $p) {
        $letter = mb_strtoupper(mb_substr($p['nickname'], 0, 1));
        echo '<tr><td><span class="avatar-circle" style="margin-right:8px;">' . esc($letter) . '</span>'
            . esc($p['nickname']) . '</td></tr>';
    }
    echo '</table></div>';
    echo '<p style="color:var(--tx2);font-size:13px;">Профили со статистикой по ролям и историей игр появятся на этапе 2.</p>';
} elseif ($q !== '') {
    empty_state('Никого не нашлось', 'Попробуйте изменить запрос.');
} else {
    empty_state('Список игроков пока пуст', 'После переноса истории здесь будут все игроки клуба с профилями, статистикой по ролям и историей игр (этап 2).');
}
page_foot();
