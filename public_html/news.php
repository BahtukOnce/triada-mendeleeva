<?php
require dirname(__DIR__) . '/inc/bootstrap.php';

$dbok = db_ready();
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id && $dbok) {
    $st = db()->prepare('SELECT n.*, u.nickname AS author FROM news n
        LEFT JOIN users u ON u.id = n.author_id
        WHERE n.id = ? AND n.published_at IS NOT NULL');
    $st->execute([$id]);
    $item = $st->fetch();
    if (!$item) {
        http_response_code(404);
    }
    page_head($item ? $item['title'] : 'Новость не найдена', 'news');
    if ($item) {
        echo '<h1>' . esc($item['title']) . '</h1>';
        echo '<p style="color:var(--tx2);font-size:13px;">'
            . esc(date('d.m.Y', strtotime($item['published_at'])))
            . ($item['author'] ? ' · ' . esc($item['author']) : '') . '</p>';
        echo '<div class="card" style="line-height:1.7;">' . nl2br(esc($item['body'] ?? '')) . '</div>';
        echo '<p><a href="/news.php">← Все новости</a></p>';
    } else {
        empty_state('Новость не найдена', 'Возможно, она была удалена.');
    }
    page_foot();
    exit;
}

$list = [];
if ($dbok) {
    $list = db()->query('SELECT id, title, published_at FROM news
        WHERE published_at IS NOT NULL
        ORDER BY pinned DESC, published_at DESC LIMIT 50')->fetchAll();
}

page_head('Новости', 'news');
echo '<h1>Новости</h1>';

if ($list) {
    echo '<div class="card">';
    $first = true;
    foreach ($list as $n) {
        echo '<div class="news-item' . ($first ? ' first' : '') . '">';
        echo '<div class="ttl"><a href="/news.php?id=' . (int)$n['id'] . '" style="color:var(--tx);">' . esc($n['title']) . '</a></div>';
        echo '<div class="dt">' . esc(date('d.m.Y', strtotime($n['published_at']))) . '</div>';
        echo '</div>';
        $first = false;
    }
    echo '</div>';
} else {
    empty_state('Новостей пока нет', 'Анонсы вечеров, итоги турниров и объявления клуба будут появляться здесь.');
}
page_foot();
