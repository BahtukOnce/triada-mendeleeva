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

    // ── Опрос «Когда играем?» ──
    if ($form === 'poll_create') {
        $open = db()->query("SELECT id FROM day_polls WHERE status = 'open' LIMIT 1")->fetchColumn();
        if ($open) {
            flash_set('err', 'Уже есть открытый опрос — сначала закройте его');
            redirect('/admin/days.php');
        }
        $dates = [];
        foreach ((array)($_POST['poll_dates'] ?? []) as $d) {
            $d = trim((string)$d);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
                $dates[$d] = 1; // дедуп
            }
        }
        $dates = array_keys($dates);
        sort($dates);
        if (count($dates) < 2) {
            flash_set('err', 'Нужно минимум два варианта дат');
            redirect('/admin/days.php');
        }
        db()->prepare("INSERT INTO day_polls (title) VALUES ('Когда играем?')")->execute();
        $pollId = (int)db()->lastInsertId();
        $ins = db()->prepare('INSERT INTO day_poll_options (poll_id, date) VALUES (?,?)');
        foreach ($dates as $d) {
            $ins->execute([$pollId, $d]);
        }
        log_action((int)$u['id'], 'day_poll_create', ['poll_id' => $pollId, 'dates' => $dates]);
        flash_set('ok', 'Опрос создан (' . count($dates) . ' вариантов). Теперь «📣 Разослать в бота».');
        redirect('/admin/days.php');
    }

    if ($form === 'poll_broadcast') {
        $pollId = (int)($_POST['poll_id'] ?? 0);
        $n = 0;
        try {
            $n = bot_broadcast_day_poll($pollId);
        } catch (Throwable $e) {
        }
        log_action((int)$u['id'], 'day_poll_broadcast', ['poll_id' => $pollId, 'sent' => $n]);
        flash_set($n ? 'ok' : 'err', $n ? "Опрос разослан в бота ($n получателей)" : 'Разослать не вышло (нет получателей или опрос закрыт)');
        redirect('/admin/days.php');
    }

    if ($form === 'poll_close') {
        $pollId = (int)($_POST['poll_id'] ?? 0);
        db()->prepare("UPDATE day_polls SET status = 'closed', closed_at = NOW() WHERE id = ?")->execute([$pollId]);
        log_action((int)$u['id'], 'day_poll_close', ['poll_id' => $pollId]);
        flash_set('ok', 'Опрос закрыт. Создайте вечер на выбранный день и откройте запись.');
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
    // ?date=YYYY-MM-DD — префилл из свода опроса («Вечер на этот день»)
    $prefillDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['date'] ?? '')) ? (string)$_GET['date'] : date('Y-m-d');
    echo '<div class="card"' . (isset($_GET['date']) ? ' style="border-color:var(--ok);"' : '') . '><h2 style="margin-top:0;">Создать вечер</h2>';
    echo '<form method="post" action="/admin/days.php">' . csrf_field() . '<input type="hidden" name="form" value="create">';
    echo '<div style="display:flex;gap:10px;flex-wrap:wrap;align-items:end;">';
    echo '<div class="field" style="margin:0;"><label>Дата</label><input type="date" name="date" required value="' . esc($prefillDate) . '"></div>';
    echo '<div class="field" style="margin:0;"><label>Название (авто, если пусто)</label><input type="text" name="title" placeholder="14 июня"></div>';
    echo '<div class="field" style="margin:0;min-width:160px;"><label>Место</label>'
        . '<select name="location" style="background:var(--sf2);color:var(--tx);border:1px solid var(--bd);border-radius:8px;padding:10px 12px;width:100%;">'
        . '<option value="Тушино">Тушино</option><option value="Миусы">Миусы</option></select></div>';
    echo '<button class="btn" type="submit">Создать</button></div></form></div>';

    // ── Опрос «Когда играем?»: создать → разослать в бота → свод → вечер из победившего дня ──
    echo '<div class="card"><h2 style="margin-top:0;">🗳 Опрос «Когда играем?»</h2>';
    $poll = null;
    try {
        $poll = day_poll_active();
    } catch (Throwable $e) {
    }
    if ($poll) {
        // ники проголосовавших по вариантам
        $votersByOpt = [];
        try {
            $optIds = array_column($poll['options'], 'id');
            if ($optIds) {
                $inP = implode(',', array_fill(0, count($optIds), '?'));
                $vq = db()->prepare("SELECT v.option_id, p.nickname FROM day_poll_votes v
                    JOIN players p ON p.id = v.player_id WHERE v.option_id IN ($inP) ORDER BY v.id");
                $vq->execute($optIds);
                foreach ($vq->fetchAll() as $vr) {
                    $votersByOpt[(int)$vr['option_id']][] = (string)$vr['nickname'];
                }
            }
        } catch (Throwable $e) {
        }
        $maxV = 0;
        foreach ($poll['options'] as $o) {
            $maxV = max($maxV, (int)$o['votes']);
        }
        echo '<p style="color:var(--tx2);font-size:13px;margin:-4px 0 10px;">Опрос открыт с ' . esc(date('d.m.Y', strtotime($poll['created_at']))) . '. Голосуют в боте («📅 Запись на игру» или /kogda) и на странице вечеров.</p>';
        echo '<table class="tbl"><tr><th>День</th><th class="num">Голосов</th><th>Кто может</th><th></th></tr>';
        foreach ($poll['options'] as $o) {
            $isTop = $maxV > 0 && (int)$o['votes'] === $maxV;
            $names = $votersByOpt[(int)$o['id']] ?? [];
            echo '<tr' . ($isTop ? ' style="background:var(--oksf);"' : '') . '>'
                . '<td><b>' . day_poll_weekday((string)$o['date']) . ' ' . esc(date('d.m', strtotime((string)$o['date']))) . '</b>' . ($isTop ? ' 👑' : '') . '</td>'
                . '<td class="num"><b>' . (int)$o['votes'] . '</b></td>'
                . '<td style="white-space:normal;font-size:12.5px;color:var(--tx2);">' . esc(implode(', ', array_slice($names, 0, 25))) . (count($names) > 25 ? '…' : '') . '</td>'
                . '<td><a class="btn btn-ghost" style="padding:4px 10px;font-size:12px;" href="/admin/days.php?date=' . esc((string)$o['date']) . '">Вечер на этот день</a></td></tr>';
        }
        echo '</table>';
        echo '<div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:12px;">';
        echo '<form method="post" action="/admin/days.php" onsubmit="return confirm(\'Разослать опрос всем привязанным к боту?\');">' . csrf_field()
            . '<input type="hidden" name="form" value="poll_broadcast"><input type="hidden" name="poll_id" value="' . (int)$poll['id'] . '">'
            . '<button class="btn" type="submit">📣 Разослать в бота</button></form>';
        echo '<form method="post" action="/admin/days.php" onsubmit="return confirm(\'Закрыть опрос? Голосование остановится.\');">' . csrf_field()
            . '<input type="hidden" name="form" value="poll_close"><input type="hidden" name="poll_id" value="' . (int)$poll['id'] . '">'
            . '<button class="btn btn-ghost" type="submit">Закрыть опрос</button></form>';
        echo '</div>';

        // История голосов: кто проголосовал / передумал и когда (журнал, ничего не удаляется)
        try {
            $lg = db()->prepare("SELECT l.action, l.created_at, o.date AS opt_date, p.nickname
                FROM day_poll_log l
                JOIN day_poll_options o ON o.id = l.option_id
                JOIN players p ON p.id = l.player_id
                WHERE l.poll_id = ? ORDER BY l.id DESC LIMIT 150");
            $lg->execute([(int)$poll['id']]);
            $logRows = $lg->fetchAll();
            if ($logRows) {
                // кто хоть раз передумывал — пометим в шапке
                $unvoters = [];
                foreach ($logRows as $lr) {
                    if ($lr['action'] === 'unvote') {
                        $unvoters[(string)$lr['nickname']] = 1;
                    }
                }
                echo '<details style="margin-top:14px;"><summary style="cursor:pointer;color:var(--tx2);font-size:13.5px;">'
                    . '🕓 История голосов (' . count($logRows) . ')'
                    . ($unvoters ? ' · передумывали: ' . esc(implode(', ', array_slice(array_keys($unvoters), 0, 10))) : '')
                    . '</summary>';
                echo '<table class="tbl" style="margin-top:10px;"><tr><th>Когда</th><th>Игрок</th><th>Действие</th></tr>';
                foreach ($logRows as $lr) {
                    $dayLbl = day_poll_weekday((string)$lr['opt_date']) . ' ' . date('d.m', strtotime((string)$lr['opt_date']));
                    echo '<tr><td style="white-space:nowrap;color:var(--tx2);">' . esc(date('d.m H:i', strtotime((string)$lr['created_at']))) . '</td>'
                        . '<td>' . esc((string)$lr['nickname']) . '</td>'
                        . '<td>' . ($lr['action'] === 'vote'
                            ? '<span style="color:var(--ok);">✅ за ' . $dayLbl . '</span>'
                            : '<span style="color:var(--ac);">↩️ передумал: снял голос с ' . $dayLbl . '</span>') . '</td></tr>';
                }
                echo '</table></details>';
            }
        } catch (Throwable $e) {
        }
    } else {
        echo '<p style="color:var(--tx2);font-size:13px;margin:-4px 0 10px;">Предложите игрокам выбрать день: укажите варианты дат (мин. 2), затем разошлите опрос в бота. Из победившего дня создадите вечер кнопкой из свода.</p>';
        echo '<form method="post" action="/admin/days.php">' . csrf_field() . '<input type="hidden" name="form" value="poll_create">';
        echo '<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:end;">';
        $nextMon = date('Y-m-d', strtotime('next monday'));
        for ($i = 0; $i < 5; $i++) {
            $suggest = $i < 2 ? date('Y-m-d', strtotime($nextMon . ' +' . ($i * 2 + 2) . ' day')) : '';
            echo '<div class="field" style="margin:0;"><label>' . ($i === 0 ? 'Вариант 1' : 'Вариант ' . ($i + 1) . ($i >= 2 ? ' (необяз.)' : '')) . '</label>'
                . '<input type="date" name="poll_dates[]"' . ($i < 2 ? ' required' : '') . ($suggest ? ' value="' . $suggest . '"' : '') . '></div>';
        }
        echo '<button class="btn" type="submit">Создать опрос</button></div></form>';
    }
    echo '</div>';
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
