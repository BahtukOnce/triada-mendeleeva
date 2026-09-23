<?php
require dirname(__DIR__) . '/inc/bootstrap.php';
$u = current_user();
if (!$u) {
    redirect('/login.php');
}
$uid = (int)$u['id'];

$rows = [];
if (db_ready()) {
    $st = db()->prepare('SELECT * FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT 100');
    $st->execute([$uid]);
    $rows = $st->fetchAll();
    // отметить все прочитанными при заходе
    db()->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0')->execute([$uid]);
}

page_head('Уведомления', '');
echo '<h1>Уведомления</h1>';

if ($rows) {
    // Карточки во всю ширину страницы (просьба руководителя): при max-width 680px на мониторе
    // они выглядели зажатыми. Дата ушла вправо — иначе широкая строка кажется полупустой.
    echo '<div class="ntf-list">';
    foreach ($rows as $n) {
        $fresh = (int)$n['is_read'] === 0;
        $time = date('d.m.Y H:i', strtotime((string)$n['created_at']));
        $cls = 'card ntf' . ($fresh ? ' ntf-new' : '');
        $body = '<div class="ntf-text">' . nl2br(esc((string)$n['text'])) . '</div>'
            . '<div class="ntf-time">' . $time . '</div>';
        if (!empty($n['link'])) {
            echo '<a href="' . esc((string)$n['link']) . '" class="' . $cls . '">' . $body . '</a>';
        } else {
            echo '<div class="' . $cls . '">' . $body . '</div>';
        }
    }
    echo '</div>';
} else {
    empty_state('Уведомлений пока нет', 'Здесь появятся оповещения о турнирах, игровых вечерах и результатах.');
}
page_foot();
