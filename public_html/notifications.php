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
    echo '<div style="display:flex;flex-direction:column;gap:8px;max-width:680px;">';
    foreach ($rows as $n) {
        $fresh = (int)$n['is_read'] === 0;
        $time = date('d.m.Y H:i', strtotime((string)$n['created_at']));
        $body = '<div style="font-size:14px;line-height:1.5;color:var(--tx);">' . nl2br(esc((string)$n['text'])) . '</div>'
            . '<div style="font-size:12px;color:var(--tx3);margin-top:4px;">' . $time . '</div>';
        $style = 'margin:0;display:block;text-decoration:none;' . ($fresh ? 'border-color:rgba(232,51,42,.5);' : '');
        if (!empty($n['link'])) {
            echo '<a href="' . esc((string)$n['link']) . '" class="card" style="' . $style . '">' . $body . '</a>';
        } else {
            echo '<div class="card" style="' . $style . '">' . $body . '</div>';
        }
    }
    echo '</div>';
} else {
    empty_state('Уведомлений пока нет', 'Здесь появятся оповещения о турнирах, игровых вечерах и результатах.');
}
page_foot();
