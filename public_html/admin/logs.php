<?php
require dirname(__DIR__, 2) . '/inc/bootstrap.php';
$u = require_role('admin');

$list = db_ready() ? db()->query('SELECT l.*, us.nickname FROM logs l
    LEFT JOIN users us ON us.id = l.user_id
    ORDER BY l.id DESC LIMIT 200')->fetchAll() : [];

page_head('Админка — логи', '');
echo '<p><a href="/admin/">← Админка</a></p><h1>Логи действий</h1>';

if ($list) {
    echo '<div class="card" style="overflow-x:auto;"><table class="tbl" style="font-size:13px;">';
    echo '<tr><th>Когда</th><th>Кто</th><th>Действие</th><th>Детали</th><th>IP</th></tr>';
    foreach ($list as $l) {
        echo '<tr><td>' . date('d.m H:i:s', strtotime($l['created_at'])) . '</td>';
        echo '<td>' . esc($l['nickname'] ?? '—') . '</td>';
        echo '<td>' . esc($l['action']) . '</td>';
        echo '<td style="color:var(--tx2);max-width:340px;overflow-wrap:anywhere;">' . esc((string)$l['details']) . '</td>';
        echo '<td style="color:var(--tx2);">' . esc((string)$l['ip']) . '</td></tr>';
    }
    echo '</table></div>';
} else {
    empty_state('Логов пока нет', 'Здесь фиксируются входы, правки и админ-действия.');
}
page_foot();
