<?php
require dirname(__DIR__) . '/inc/bootstrap.php';
require_login(); // фото видно только участникам клуба

$albums = [];
if (db_ready()) {
    $albums = db()->query('SELECT a.*,
            (SELECT COUNT(*) FROM photos p WHERE p.album_id = a.id) AS photos_cnt
        FROM albums a ORDER BY a.created_at DESC LIMIT 50')->fetchAll();
}

page_head('Фото', 'photos');
echo '<h1>Фото</h1>';

if ($albums) {
    foreach ($albums as $a) {
        echo '<div class="card"><div class="section-head">';
        echo '<h2 style="margin:0;">' . esc($a['title']) . '</h2>';
        echo '<span class="tag">' . (int)$a['photos_cnt'] . ' фото</span>';
        echo '</div></div>';
    }
} else {
    empty_state('Альбомов пока нет', 'Фотографии с игровых вечеров и турниров будут собираться в альбомы, привязанные к событиям. Загрузка появится на этапе 3.');
}
page_foot();
