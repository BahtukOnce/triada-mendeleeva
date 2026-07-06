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
    $counts['sugg_new'] = (int)db()->query("SELECT COUNT(*) FROM suggestions WHERE status = 'new'")->fetchColumn();
    $counts['banned'] = (int)db()->query('SELECT COUNT(*) FROM players WHERE banned_at IS NOT NULL')->fetchColumn();
    try {
        $counts['app_new'] = (int)db()->query("SELECT COUNT(*) FROM club_applications WHERE state = 'new'")->fetchColumn();
    } catch (Throwable $e) {
        $counts['app_new'] = 0;
    }
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

// [href, заголовок, подсказка, alert?] — alert подсвечивает плитку
$groups = [
    'Игры и турниры' => [
        ['/admin/days.php', 'Игровые вечера', $counts['days_open'] ? $counts['days_open'] . ' с открытой записью' : 'создание, запись, статусы', $counts['days_open'] > 0],
        ['/admin/tournaments.php', 'Турниры', 'создание, лого, статусы', false],
        ['/admin/merge.php', 'Игроки: слияние и ники', 'дубли, переименование', false],
    ],
    'Люди' => [
        ['/admin/users.php', 'Пользователи и роли', 'роли, Telegram, в сети, пароли', false],
        ['/admin/links.php', 'Заявки на привязку', $counts['pending'] ? '⚠ ожидают: ' . $counts['pending'] : 'нет ожидающих', $counts['pending'] > 0],
        ['/admin/suggestions.php', 'Предложения', $counts['sugg_new'] ? '⚠ новых: ' . $counts['sugg_new'] : 'идеи от участников', $counts['sugg_new'] > 0],
        ['/admin/banlist.php', 'Бан-лист', !empty($counts['banned']) ? 'забанено: ' . $counts['banned'] : 'бан и разбан игроков', false],
    ],
    'Контент' => [
        ['/admin/news.php', 'Новости', 'публикаций: ' . $counts['news'], false],
        ['/admin/albums.php', 'Фотоальбомы', 'загрузка фото и видео', false],
        ['/admin/rules.php', 'Правила и тексты', 'правила, «О клубе», бот', false],
        ['/admin/notify.php', 'Telegram-рассылка', 'анонсы и напоминания', false],
    ],
    'Система' => [
        ['/admin/logs.php', 'Логи', 'действия пользователей', false],
        ['/admin/errors.php', 'Ошибки', 'технический лог ошибок', false],
    ],
];
// Заявки на вступление видит и обрабатывает только руководитель
if ($u['role'] === 'owner') {
    $an = (int)($counts['app_new'] ?? 0);
    array_unshift($groups['Люди'], ['/admin/applications.php', 'Заявки в клуб',
        $an ? '⚠ новых: ' . $an : 'вступление новых жителей', $an > 0]);
}

foreach ($groups as $gname => $gitems) {
    echo '<h2 style="margin:18px 0 8px;">' . $gname . '</h2>';
    echo '<div class="grid-stats" style="grid-template-columns:repeat(auto-fill,minmax(220px,1fr));">';
    foreach ($gitems as [$href, $title, $hint, $alert]) {
        echo '<a class="stat' . ($alert ? ' stat-alert' : '') . '" href="' . $href . '" style="color:var(--tx);">'
            . '<div class="val" style="font-size:16px;">' . $title . '</div>'
            . '<div class="lbl"' . ($alert ? ' style="color:var(--ac);"' : '') . '>' . esc($hint) . '</div></a>';
    }
    echo '</div>';
}

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
