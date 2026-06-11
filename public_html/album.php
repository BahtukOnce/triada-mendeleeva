<?php
require dirname(__DIR__) . '/inc/bootstrap.php';

$id = (int)($_GET['id'] ?? 0);
$album = null;
$photos = [];

if ($id && db_ready()) {
    $st = db()->prepare('SELECT a.*, d.title AS day_title, d.id AS dlink, t.title AS t_title, t.id AS tlink
        FROM albums a
        LEFT JOIN game_days d ON d.id = a.day_id
        LEFT JOIN tournaments t ON t.id = a.tournament_id
        WHERE a.id = ?');
    $st->execute([$id]);
    $album = $st->fetch() ?: null;
    if ($album) {
        $st = db()->prepare('SELECT * FROM photos WHERE album_id = ? ORDER BY sort, id');
        $st->execute([$id]);
        $photos = $st->fetchAll();
    }
}

page_head($album ? $album['title'] : 'Альбом не найден', 'photos');

if (!$album) {
    empty_state('Альбом не найден', 'Возможно, ссылка устарела.');
    page_foot();
    exit;
}

echo '<h1>' . esc($album['title']) . '</h1>';
$link = '';
if ($album['dlink']) {
    $link = '<a href="/day.php?id=' . (int)$album['dlink'] . '">вечер: ' . esc($album['day_title']) . '</a> · ';
} elseif ($album['tlink']) {
    $link = '<a href="/tournament.php?id=' . (int)$album['tlink'] . '">турнир: ' . esc($album['t_title']) . '</a> · ';
}
echo '<p style="color:var(--tx2);margin-top:-6px;">' . $link . count($photos) . ' фото · <a href="/photos.php">все альбомы</a></p>';

if ($photos) {
    echo '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:10px;">';
    foreach ($photos as $p) {
        echo '<a href="' . esc($p['file']) . '" target="_blank" rel="noopener">'
            . '<img src="' . esc($p['thumb'] ?: $p['file']) . '" alt="" loading="lazy" '
            . 'style="width:100%;aspect-ratio:4/3;object-fit:cover;border-radius:10px;border:1px solid var(--bd);"></a>';
    }
    echo '</div>';
} else {
    empty_state('В альбоме пока пусто', 'Фотографии скоро загрузят.');
}
page_foot();
