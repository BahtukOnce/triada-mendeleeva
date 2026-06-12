<?php
require dirname(__DIR__, 2) . '/inc/bootstrap.php';
$u = require_role('admin');

$counts = ['pending' => 0, 'days_open' => 0, 'players' => 0, 'news' => 0];
$online = [];
if (db_ready()) {
    $counts['pending'] = (int)db()->query("SELECT COUNT(*) FROM link_requests WHERE status = 'pending'")->fetchColumn();
    $counts['days_open'] = (int)db()->query("SELECT COUNT(*) FROM game_days WHERE status = 'reg_open'")->fetchColumn();
    $counts['players'] = (int)db()->query('SELECT COUNT(*) FROM players')->fetchColumn();
    $counts['news'] = (int)db()->query('SELECT COUNT(*) FROM news')->fetchColumn();
    try {
        $online = db()->query("SELECT us.nickname, us.last_seen, p.id AS player_id, p.avatar, p.nickname AS pnick
            FROM users us LEFT JOIN players p ON p.user_id = us.id
            WHERE us.last_seen IS NOT NULL AND us.last_seen > NOW() - INTERVAL 5 MINUTE
            ORDER BY us.last_seen DESC LIMIT 40")->fetchAll();
    } catch (Throwable $e) {
    }
}

page_head('Админка', '');
echo '<h1>Админка</h1>';

$items = [
    ['/admin/days.php', 'Игровые вечера', 'создание, запись, статусы'],
    ['/admin/tournaments.php', 'Турниры', 'создание, лого, статусы'],
    ['/admin/links.php', 'Заявки на привязку', $counts['pending'] ? '⚠ ожидают: ' . $counts['pending'] : 'нет ожидающих'],
    ['/admin/users.php', 'Пользователи и роли', 'сброс паролей, роли'],
    ['/admin/suggestions.php', 'Предложения', 'идеи от участников'],
    ['/admin/merge.php', 'Слияние ников', 'дубли игроков'],
    ['/admin/news.php', 'Новости', 'публикации: ' . $counts['news']],
    ['/admin/rules.php', 'Правила и тексты', 'правила, «О клубе», бот'],
    ['/admin/notify.php', 'Telegram-рассылка', 'анонсы и напоминания'],
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

echo '<div class="card"><div class="section-head"><h2 style="margin:0;">Сейчас в сети (' . count($online) . ')</h2>'
    . '<a class="more" href="/admin/users.php">все пользователи →</a></div>';
if ($online) {
    echo '<div class="admin-list" style="margin-top:10px;">';
    foreach ($online as $o) {
        $name = $o['pnick'] ?: $o['nickname'];
        $nameHtml = $o['player_id']
            ? '<a href="/player.php?id=' . (int)$o['player_id'] . '" style="color:var(--tx);">' . esc($name) . '</a>'
            : esc($name);
        echo '<div class="admin-item">'
            . avatar_html(['nickname' => $name, 'avatar' => $o['avatar']], 28)
            . '<div><div class="nm">' . $nameHtml . '</div>'
            . '<div class="rl" style="color:var(--ok);">в сети</div></div></div>';
    }
    echo '</div>';
} else {
    echo '<p style="color:var(--tx2);font-size:14px;margin:8px 0 0;">Сейчас никого нет в сети.</p>';
}
echo '</div>';

echo '<p style="color:var(--tx2);font-size:13px;">Протокол игр с таймером, управление игроками и ролями — этап 4.</p>';
page_foot();
