<?php
require dirname(__DIR__, 2) . '/inc/bootstrap.php';
$u = require_role('admin');

$counts = ['pending' => 0, 'days_open' => 0, 'players' => 0, 'news' => 0];
if (db_ready()) {
    $counts['pending'] = (int)db()->query("SELECT COUNT(*) FROM link_requests WHERE status = 'pending'")->fetchColumn();
    $counts['days_open'] = (int)db()->query("SELECT COUNT(*) FROM game_days WHERE status = 'reg_open'")->fetchColumn();
    $counts['players'] = (int)db()->query('SELECT COUNT(*) FROM players')->fetchColumn();
    $counts['news'] = (int)db()->query('SELECT COUNT(*) FROM news')->fetchColumn();
}

page_head('Админка', '');
echo '<h1>Админка</h1>';

$items = [
    ['/admin/days.php', 'Игровые вечера', 'создание, запись, статусы'],
    ['/admin/tournaments.php', 'Турниры', 'создание, лого, статусы'],
    ['/admin/links.php', 'Заявки на привязку', $counts['pending'] ? '⚠ ожидают: ' . $counts['pending'] : 'нет ожидающих'],
    ['/admin/users.php', 'Пользователи и роли', 'сброс паролей, роли'],
    ['/admin/suggestions.php', 'Предложения', 'идеи от участников'],
    ['/admin/news.php', 'Новости', 'публикации: ' . $counts['news']],
    ['/admin/rules.php', 'Правила игры', 'текст страницы «Правила»'],
    ['/admin/albums.php', 'Фотоальбомы', 'загрузка фото'],
    ['/admin/logs.php', 'Логи', 'действия пользователей'],
];
echo '<div class="grid-stats" style="grid-template-columns:repeat(3,minmax(0,1fr));">';
foreach ($items as [$href, $title, $hint]) {
    echo '<a class="stat" href="' . $href . '" style="color:var(--tx);">'
        . '<div class="val" style="font-size:16px;">' . $title . '</div>'
        . '<div class="lbl">' . esc($hint) . '</div></a>';
}
echo '</div>';

echo '<p style="color:var(--tx2);font-size:13px;">Протокол игр с таймером, управление игроками и ролями — этап 4.</p>';
page_foot();
