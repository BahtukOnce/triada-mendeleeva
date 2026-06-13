<?php
require dirname(__DIR__) . '/inc/bootstrap.php';

$canEdit = user_can_judge(current_user());

// Создание вечера прямо во вкладке «Игры» (судья/админ)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'create_day') {
    $u = require_judge();
    csrf_check();
    $date = (string)($_POST['date'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        flash_set('err', 'Укажите дату');
        redirect('/days.php');
    }
    $title = trim((string)($_POST['title'] ?? ''));
    if ($title === '') {
        $months = [1 => 'января', 'февраля', 'марта', 'апреля', 'мая', 'июня',
            'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];
        $ts = strtotime($date);
        $title = (int)date('j', $ts) . ' ' . $months[(int)date('n', $ts)];
    }
    $loc = in_array($_POST['location'] ?? '', ['Тушино', 'Миусы', 'Другое'], true) ? $_POST['location'] : null;
    db()->prepare("INSERT INTO game_days (date, title, location, status) VALUES (?,?,?, 'reg_open')")
        ->execute([$date, $title, $loc]);
    $newId = (int)db()->lastInsertId();
    log_action((int)$u['id'], 'day_create', ['date' => $date]);
    flash_set('ok', 'Вечер создан. Запись открыта — можно вести игры.');
    redirect('/day.php?id=' . $newId);
}

$list = [];
if (db_ready()) {
    $list = db()->query('SELECT d.*,
            (SELECT COUNT(*) FROM games g WHERE g.day_id = d.id) AS games_cnt
        FROM game_days d
        ORDER BY d.date DESC LIMIT 100')->fetchAll();
}

$statusLabel = [
    'draft' => 'черновик', 'reg_open' => 'запись открыта', 'reg_closed' => 'запись закрыта',
    'live' => 'идёт сейчас', 'finished' => 'завершён',
];

page_head('Игровые вечера', 'days');
echo '<h1>Игровые вечера</h1>';

if ($canEdit) {
    echo '<div class="card"><h2 style="margin-top:0;">Создать вечер</h2>';
    echo '<form method="post" action="/days.php">' . csrf_field() . '<input type="hidden" name="form" value="create_day">';
    echo '<div style="display:flex;gap:10px;flex-wrap:wrap;align-items:end;">';
    echo '<div class="field" style="margin:0;"><label>Дата</label><input type="date" name="date" required value="' . date('Y-m-d') . '"></div>';
    echo '<div class="field" style="margin:0;"><label>Название (авто)</label><input type="text" name="title" placeholder="' . (int)date('j') . ' ' . ['', 'января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'][(int)date('n')] . '"></div>';
    echo '<div class="field" style="margin:0;min-width:140px;"><label>Локация</label>'
        . '<select name="location" style="background:var(--sf2);color:var(--tx);border:1px solid var(--bd);border-radius:8px;padding:10px 12px;width:100%;">'
        . '<option value="Тушино">Тушино</option><option value="Миусы">Миусы</option><option value="Другое">Другое</option></select></div>';
    echo '<button class="btn" type="submit">Создать и вести</button>';
    echo '</div></form></div>';
}

if ($list) {
    echo '<div class="days-grid">';
    foreach ($list as $d) {
        echo '<a class="day-card" href="/day.php?id=' . (int)$d['id'] . '">';
        echo '<div class="day-card-top"><span class="day-title">' . esc($d['title']) . '</span>';
        if ($d['status'] === 'reg_open') {
            echo '<span class="tag tag-open">запись</span>';
        }
        echo '</div>';
        echo '<div class="day-date">' . esc(date('d.m.Y', strtotime($d['date']))) . '</div>';
        echo '<div class="day-meta">';
        echo '<span>' . ($d['location'] ? esc($d['location']) : '<span style="color:var(--tx3);">без локации</span>') . '</span>';
        echo '<span class="day-games"><b>' . (int)$d['games_cnt'] . '</b> игр</span>';
        echo '</div></a>';
    }
    echo '</div>';
} else {
    empty_state('Архив вечеров пока пуст', 'Здесь будут все игровые вечера клуба с протоколами игр.');
}
page_foot();
