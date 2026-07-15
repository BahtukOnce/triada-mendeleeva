<?php
require dirname(__DIR__, 2) . '/inc/bootstrap.php';
require_once ROOT . '/inc/bot_lib.php';
$u = require_judge();

// Просмотр и «Вести игры» — судьям; управление вечерами — по таблице прав
$canManageDays = user_perm($u, 'manage_days');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $form = (string)($_POST['form'] ?? '');
    if (!$canManageDays) {
        flash_set('err', 'Управление вечерами вам не разрешено (таблица прав)');
        redirect('/admin/days.php');
    }

    if ($form === 'create') {
        $date = (string)($_POST['date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            flash_set('err', 'Укажите дату');
            redirect('/admin/days.php');
        }
        $title = trim((string)($_POST['title'] ?? ''));
        if ($title === '') {
            $months = [1 => 'января', 'февраля', 'марта', 'апреля', 'мая', 'июня',
                'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];
            $ts = strtotime($date);
            $title = (int)date('j', $ts) . ' ' . $months[(int)date('n', $ts)];
        }
        db()->prepare("INSERT INTO game_days (date, title, location, status) VALUES (?,?,?, 'draft')")
            ->execute([$date, $title, trim((string)($_POST['location'] ?? '')) ?: null]);
        log_action((int)$u['id'], 'day_create', ['date' => $date]);
        flash_set('ok', 'Вечер создан (черновик). Откройте запись, когда будете готовы.');
        redirect('/admin/days.php');
    }

    if ($form === 'status') {
        $id = (int)($_POST['day_id'] ?? 0);
        $to = (string)($_POST['to'] ?? '');
        if (in_array($to, ['draft', 'reg_open', 'reg_closed', 'live', 'finished'], true)) {
            $cs = db()->prepare('SELECT status FROM game_days WHERE id = ?');
            $cs->execute([$id]);
            $prev = (string)($cs->fetchColumn() ?: '');
            db()->prepare('UPDATE game_days SET status = ? WHERE id = ?')->execute([$to, $id]);
            log_action((int)$u['id'], 'day_status', ['day_id' => $id, 'to' => $to]);
            // авто-уведомления в Telegram только при реальной смене статуса
            // (анонс открытия записи НЕ автоматический — кнопка «📣 Разослать анонс»)
            $note = '';
            if ($prev !== $to) {
                try {
                    $dtt = db()->prepare('SELECT title FROM game_days WHERE id = ?');
                    $dtt->execute([$id]);
                    $dtitle = (string)($dtt->fetchColumn() ?: 'вечер');
                    if ($to === 'finished') {
                        $n = bot_notify_day_results($id);
                        $pq = db()->prepare('SELECT DISTINCT gs.player_id FROM game_seats gs JOIN games g ON g.id = gs.game_id WHERE g.day_id = ?');
                        $pq->execute([$id]);
                        foreach ($pq->fetchAll(PDO::FETCH_COLUMN) as $pid) {
                            app_notify_player((int)$pid, '🎲 Итоги вечера «' . $dtitle . '» готовы — смотри свою статистику', '/my_games.php');
                        }
                        $note = $n ? " · итоги отправлены ($n)" : '';
                    }
                } catch (Throwable $e) {
                }
            }
            flash_set('ok', 'Статус обновлён' . $note);
        }
        redirect('/admin/days.php');
    }

    // Ручная рассылка анонса записи: в личку бота всем привязанным + колокольчик на сайте
    if ($form === 'announce') {
        $id = (int)($_POST['day_id'] ?? 0);
        $cs = db()->prepare('SELECT title, status FROM game_days WHERE id = ?');
        $cs->execute([$id]);
        $day = $cs->fetch();
        if ($day && $day['status'] === 'reg_open') {
            $n = 0;
            try {
                $n = bot_notify_day_open($id);
                app_notify_all_members('📅 Открыта запись на вечер «' . (string)$day['title'] . '» — записывайся!', '/days.php');
            } catch (Throwable $e) {
            }
            log_action((int)$u['id'], 'day_announce', ['day_id' => $id, 'sent' => $n]);
            flash_set('ok', $n ? "Анонс отправлен в Telegram ($n получателей) + уведомления на сайте" : 'Анонс: в Telegram никто не получил (нет привязанных с уведомлениями), уведомления на сайте отправлены');
        } else {
            flash_set('err', 'Анонс можно разослать только при открытой записи');
        }
        redirect('/admin/days.php');
    }
    redirect('/admin/days.php');
}

$list = db_ready() ? db()->query('SELECT d.*,
        (SELECT COUNT(*) FROM day_registrations r WHERE r.day_id = d.id AND r.cancelled_at IS NULL) AS regs,
        (SELECT COUNT(*) FROM games g WHERE g.day_id = d.id) AS games
    FROM game_days d ORDER BY d.date DESC LIMIT 60')->fetchAll() : [];

$statusLabel = ['draft' => 'черновик', 'reg_open' => 'запись открыта', 'reg_closed' => 'запись закрыта',
    'live' => 'идёт', 'finished' => 'завершён'];
$nextStatus = [
    'draft' => [['reg_open', 'Открыть запись']],
    'reg_open' => [['reg_closed', 'Закрыть запись']],
    'reg_closed' => [['reg_open', 'Снова открыть'], ['finished', 'Завершить']],
    'live' => [['finished', 'Завершить']],
    'finished' => [],
];

page_head('Админка — вечера', '');
echo '<p><a href="/admin/">← Админка</a></p><h1>Игровые вечера</h1>';

if ($canManageDays) {
    echo '<div class="card"><h2 style="margin-top:0;">Создать вечер</h2>';
    echo '<form method="post" action="/admin/days.php">' . csrf_field() . '<input type="hidden" name="form" value="create">';
    echo '<div style="display:flex;gap:10px;flex-wrap:wrap;align-items:end;">';
    echo '<div class="field" style="margin:0;"><label>Дата</label><input type="date" name="date" required value="' . date('Y-m-d') . '"></div>';
    echo '<div class="field" style="margin:0;"><label>Название (авто, если пусто)</label><input type="text" name="title" placeholder="14 июня"></div>';
    echo '<div class="field" style="margin:0;min-width:160px;"><label>Место</label>'
        . '<select name="location" style="background:var(--sf2);color:var(--tx);border:1px solid var(--bd);border-radius:8px;padding:10px 12px;width:100%;">'
        . '<option value="Тушино">Тушино</option><option value="Миусы">Миусы</option></select></div>';
    echo '<button class="btn" type="submit">Создать</button></div></form></div>';
}

if ($list) {
    echo '<div class="card" style="overflow-x:auto;"><table class="tbl">';
    echo '<tr><th>Дата</th><th>Вечер</th><th>Статус</th><th class="num">Записей</th><th class="num">Игр</th><th>Действия</th></tr>';
    foreach ($list as $d) {
        echo '<tr><td>' . date('d.m.Y', strtotime($d['date'])) . '</td>';
        echo '<td><a href="/day.php?id=' . (int)$d['id'] . '">' . esc($d['title']) . '</a></td>';
        echo '<td><span class="tag ' . ($d['status'] === 'reg_open' ? 'tag-open' : '') . '">' . $statusLabel[$d['status']] . '</span></td>';
        echo '<td class="num">' . (int)$d['regs'] . '</td><td class="num">' . (int)$d['games'] . '</td><td>';
        if (in_array($d['status'], ['reg_closed', 'live', 'finished'], true)) {
            echo '<a class="btn" style="padding:4px 12px;font-size:12px;" href="/admin/protocol.php?day=' . (int)$d['id'] . '">Вести игры</a> ';
        }
        foreach ($canManageDays ? $nextStatus[$d['status']] : [] as [$to, $lbl]) {
            echo '<form method="post" action="/admin/days.php" style="display:inline;">' . csrf_field();
            echo '<input type="hidden" name="form" value="status"><input type="hidden" name="day_id" value="' . (int)$d['id'] . '">';
            echo '<input type="hidden" name="to" value="' . $to . '">';
            echo '<button class="btn btn-ghost" style="padding:4px 10px;font-size:12px;" type="submit">' . $lbl . '</button></form> ';
        }
        if ($d['status'] === 'reg_open' && $canManageDays) {
            echo '<form method="post" action="/admin/days.php" style="display:inline;" onsubmit="return confirm(\'Разослать анонс записи всем привязанным к боту?\');">' . csrf_field();
            echo '<input type="hidden" name="form" value="announce"><input type="hidden" name="day_id" value="' . (int)$d['id'] . '">';
            echo '<button class="btn btn-ghost" style="padding:4px 10px;font-size:12px;" type="submit">📣 Разослать анонс</button></form> ';
        }
        echo '</td></tr>';
    }
    echo '</table></div>';
}
page_foot();
