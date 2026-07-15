<?php
require dirname(__DIR__) . '/inc/bootstrap.php';
require_once ROOT . '/inc/bot_lib.php'; // опрос «Когда играем?» (day_poll_*)

$canEdit = user_can_judge(current_user());

// Голос в опросе «Когда играем?» (тоггл; нужен привязанный ник)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'poll_vote') {
    require_login();
    csrf_check();
    $pl = current_player();
    if (!$pl) {
        flash_set('err', 'Сначала привяжите игровой ник в личном кабинете');
        redirect('/cabinet.php');
    }
    $optId = (int)($_POST['option_id'] ?? 0);
    $poll = day_poll_active();
    $ok = false;
    if ($poll) {
        foreach ($poll['options'] as $po) {
            if ((int)$po['id'] === $optId) {
                $ok = true;
                break;
            }
        }
    }
    if ($ok) {
        $now = day_poll_vote_toggle($optId, (int)$pl['id']);
        flash_set('ok', $now ? 'Голос учтён!' : 'Голос снят.');
    } else {
        flash_set('err', 'Опрос уже закрыт');
    }
    redirect('/days.php#poll');
}

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

// Фильтр по сезонам: «текущий» (season IS NULL) по умолчанию, исторические — по метке
$list = [];
$seasons = [];
$season = isset($_GET['season']) ? (string)$_GET['season'] : 'cur';
if (db_ready()) {
    $seasons = db()->query("SELECT DISTINCT season FROM game_days WHERE season IS NOT NULL ORDER BY season DESC")
        ->fetchAll(PDO::FETCH_COLUMN);
    $conds = [];
    $params = [];
    if ($season === 'all') {
        // без фильтра по сезону
    } elseif ($season !== 'cur' && in_array($season, $seasons, true)) {
        $conds[] = 'd.season = ?';
        $params[] = $season;
    } else {
        $season = 'cur';
        $conds[] = 'd.season IS NULL';
    }
    if (!$canEdit) {
        $conds[] = "d.status <> 'draft'"; // черновики видят только судьи/админы
    }
    $where = $conds ? 'WHERE ' . implode(' AND ', $conds) : '';
    $st = db()->prepare('SELECT d.*,
            (SELECT COUNT(*) FROM games g WHERE g.day_id = d.id) AS games_cnt
        FROM game_days d
        ' . $where . '
        ORDER BY d.date DESC LIMIT 200');
    $st->execute($params);
    $list = $st->fetchAll();
}

$statusLabel = [
    'draft' => 'черновик', 'reg_open' => 'запись открыта', 'reg_closed' => 'запись закрыта',
    'live' => 'идёт сейчас', 'finished' => 'завершён',
];

page_head('Игровые вечера', 'days');
echo '<h1>Игровые вечера</h1>';

// ── Опрос «Когда играем?»: мультивыбор дней (тоггл кнопками) ──
try {
    $activePoll = day_poll_active();
} catch (Throwable $e) {
    $activePoll = null;
}
if ($activePoll) {
    $mePl = current_player();
    $myVotes = [];
    if ($mePl && $activePoll['options']) {
        $oIds = array_column($activePoll['options'], 'id');
        $inO = implode(',', array_fill(0, count($oIds), '?'));
        $mv = db()->prepare("SELECT option_id FROM day_poll_votes WHERE player_id = ? AND option_id IN ($inO)");
        $mv->execute(array_merge([(int)$mePl['id']], $oIds));
        $myVotes = array_map('intval', $mv->fetchAll(PDO::FETCH_COLUMN));
    }
    echo '<div class="card card-accent" id="poll"><h2 style="margin-top:0;">🗳 ' . esc((string)$activePoll['title']) . '</h2>';
    echo '<p style="color:var(--tx2);font-size:13.5px;margin:-4px 0 12px;">Отметь все дни, в которые сможешь прийти — можно несколько. Повторное нажатие снимает голос.</p>';
    echo '<div style="display:flex;gap:9px;flex-wrap:wrap;">';
    foreach ($activePoll['options'] as $o) {
        $sel = in_array((int)$o['id'], $myVotes, true);
        $label = day_poll_weekday((string)$o['date']) . ' ' . date('d.m', strtotime((string)$o['date']));
        if ($mePl) {
            echo '<form method="post" action="/days.php" style="display:inline;">' . csrf_field()
                . '<input type="hidden" name="form" value="poll_vote"><input type="hidden" name="option_id" value="' . (int)$o['id'] . '">'
                . '<button class="btn ' . ($sel ? '' : 'btn-ghost') . '" type="submit" style="min-width:118px;">'
                . ($sel ? '✅ ' : '') . $label . ' <span style="opacity:.75;font-size:12px;">· ' . (int)$o['votes'] . '</span></button></form>';
        } else {
            echo '<span class="btn btn-ghost" style="min-width:118px;opacity:.7;cursor:default;">' . $label
                . ' <span style="opacity:.75;font-size:12px;">· ' . (int)$o['votes'] . '</span></span>';
        }
    }
    echo '</div>';
    if (!$mePl) {
        echo '<p style="color:var(--tx2);font-size:12.5px;margin:10px 0 0;">'
            . (current_user()
                ? 'Чтобы голосовать, <a href="/cabinet.php">привяжите игровой ник</a>.'
                : '<a href="/login.php">Войдите</a>, чтобы проголосовать — или отметьтесь в нашем Telegram-боте.')
            . '</p>';
    }
    echo '</div>';
}

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

if ($seasons) {
    echo '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px;">';
    $tabs = [['cur', 'Текущий сезон']];
    foreach ($seasons as $s) {
        $tabs[] = [$s, $s];
    }
    $tabs[] = ['all', 'Все вечера'];
    foreach ($tabs as [$key, $label]) {
        $on = $season === $key;
        echo '<a class="tag ' . ($on ? 'tag-open' : '') . '" href="/days.php?season=' . urlencode($key) . '">' . esc($label) . '</a>';
    }
    echo '</div>';
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
        if (!empty($d['season'])) {
            echo '<div style="font-size:11px;color:var(--tx3);margin-top:2px;">' . esc($d['season']) . '</div>';
        }
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
