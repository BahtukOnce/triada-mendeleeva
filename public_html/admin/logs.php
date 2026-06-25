<?php
require dirname(__DIR__, 2) . '/inc/bootstrap.php';
$u = require_role('admin');

$list = db_ready() ? db()->query('SELECT l.*, us.nickname FROM logs l
    LEFT JOIN users us ON us.id = l.user_id
    ORDER BY l.id DESC LIMIT 200')->fetchAll() : [];

$actionLabel = [
    'register' => 'Регистрация', 'login' => 'Вход', 'logout' => 'Выход',
    'password_change' => 'Смена пароля', 'admin_password_reset' => 'Сброс пароля (админом)',
    'profile_update' => 'Изменён профиль', 'player_autolink' => 'Автопривязка ника',
    'player_created_self' => 'Создан профиль игрока', 'player_rename' => 'Переименован ник',
    'players_merge' => 'Слияние ников', 'link_request' => 'Заявка на привязку',
    'link_approve' => 'Привязка подтверждена', 'link_reject' => 'Привязка отклонена',
    'tg_unlink' => 'Отвязан Telegram', 'tg_link' => 'Привязан Telegram',
    'avatar_cropped' => 'Обрезан аватар', 'avatars_upload' => 'Загружен аватар',
    'day_create' => 'Создан игровой вечер', 'day_status' => 'Изменён статус вечера',
    'day_register' => 'Запись на вечер', 'day_cancel' => 'Отмена записи',
    'game_save' => 'Сохранена игра (протокол)', 'game_delete' => 'Удалена игра',
    'tournament_save' => 'Сохранён турнир', 'tournament_delete' => 'Удалён турнир',
    'news_save' => 'Сохранена новость', 'news_delete' => 'Удалена новость',
    'album_create' => 'Создан фотоальбом', 'photos_upload' => 'Загружены фото',
    'suggestion_add' => 'Новое предложение', 'suggestion_update' => 'Обновлено предложение',
    'suggestion_delete' => 'Удалено предложение', 'cap_change' => 'Изменены права (судья/фотограф)',
    'role_change' => 'Изменена роль', 'owner_transfer' => 'Передано главенство',
    'rules_update' => 'Правка текстов и правил', 'bot_broadcast' => 'Telegram-рассылка',
];

page_head('Админка — логи', '');
echo '<p><a href="/admin/">← Админка</a></p><h1>Логи действий</h1>';

if ($list) {
    echo '<div class="card" style="overflow-x:auto;"><table class="tbl" style="font-size:13px;">';
    echo '<tr><th>Когда</th><th>Кто</th><th>Действие</th><th>Детали</th><th>IP</th></tr>';
    foreach ($list as $l) {
        $act = $actionLabel[$l['action']] ?? mb_strtoupper(mb_substr($l['action'], 0, 1)) . str_replace('_', ' ', mb_substr($l['action'], 1));
        $det = '';
        $arr = null;
        $raw = (string)$l['details'];
        if ($raw !== '' && $raw !== '[]' && $raw !== 'null') {
            $arr = json_decode($raw, true);
            if (is_array($arr) && $arr) {
                $parts = [];
                foreach ($arr as $k => $v) {
                    $parts[] = $k . ': ' . (is_scalar($v) ? (string)$v : json_encode($v, JSON_UNESCAPED_UNICODE));
                }
                $det = implode(', ', $parts);
            } else {
                $det = $raw;
            }
        }
        // «Кто»: если пользователь сайта не привязан (напр. привязка через Telegram-бота),
        // но в деталях есть игрок — показываем его.
        if (!empty($l['nickname'])) {
            $whoHtml = esc($l['nickname']);
        } elseif (is_array($arr) && !empty($arr['player'])) {
            $hint = (($arr['via'] ?? '') === 'bot') ? ' · через бота' : '';
            $whoHtml = esc($arr['player']) . '<span style="color:var(--tx3);font-size:11px;">' . esc($hint) . '</span>';
        } else {
            $whoHtml = '<span style="color:var(--tx3);">—</span>';
        }
        echo '<tr><td style="white-space:nowrap;">' . date('d.m H:i:s', strtotime($l['created_at'])) . '</td>';
        echo '<td>' . $whoHtml . '</td>';
        echo '<td><b>' . esc($act) . '</b></td>';
        echo '<td style="color:var(--tx2);max-width:340px;overflow-wrap:anywhere;">' . ($det !== '' ? esc($det) : '<span style="color:var(--tx3);">—</span>') . '</td>';
        echo '<td style="color:var(--tx2);">' . esc((string)$l['ip']) . '</td></tr>';
    }
    echo '</table></div>';
} else {
    empty_state('Логов пока нет', 'Здесь фиксируются входы, правки и админ-действия.');
}
page_foot();
