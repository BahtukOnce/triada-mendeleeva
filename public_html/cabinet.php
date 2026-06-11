<?php
require dirname(__DIR__) . '/inc/bootstrap.php';

$u = require_login();
$player = current_player();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $form = (string)($_POST['form'] ?? '');

    if ($form === 'password') {
        $old = (string)($_POST['old_password'] ?? '');
        $new1 = (string)($_POST['new_password'] ?? '');
        $new2 = (string)($_POST['new_password2'] ?? '');
        if (!password_verify($old, $u['password_hash'])) {
            flash_set('err', 'Текущий пароль неверный');
        } elseif (mb_strlen($new1) < 6) {
            flash_set('err', 'Новый пароль — минимум 6 символов');
        } elseif ($new1 !== $new2) {
            flash_set('err', 'Новые пароли не совпадают');
        } else {
            db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                ->execute([password_hash($new1, PASSWORD_DEFAULT), $u['id']]);
            log_action((int)$u['id'], 'password_change');
            flash_set('ok', 'Пароль обновлён');
        }
        redirect('/cabinet.php');
    }

    if ($form === 'link') {
        if ($player) {
            flash_set('err', 'Ник уже привязан');
            redirect('/cabinet.php');
        }
        $nick = trim((string)($_POST['nick'] ?? ''));
        if ($nick === '') {
            flash_set('err', 'Укажите ник');
            redirect('/cabinet.php');
        }
        $st = db()->prepare('SELECT * FROM players WHERE LOWER(nickname) = LOWER(?)');
        $st->execute([$nick]);
        $found = $st->fetch();
        if ($found && $found['user_id']) {
            flash_set('err', 'Этот игрок уже привязан к другому аккаунту — обратитесь к админу');
        } elseif ($found) {
            $st = db()->prepare("SELECT id FROM link_requests WHERE user_id = ? AND status = 'pending'");
            $st->execute([(int)$u['id']]);
            if ($st->fetch()) {
                flash_set('err', 'Заявка уже отправлена, ждёт подтверждения админа');
            } else {
                db()->prepare('INSERT INTO link_requests (user_id, player_id) VALUES (?,?)')
                    ->execute([(int)$u['id'], (int)$found['id']]);
                log_action((int)$u['id'], 'link_request', ['player_id' => (int)$found['id']]);
                flash_set('ok', 'Заявка на привязку к игроку «' . $found['nickname'] . '» отправлена — подтвердит админ');
            }
        } else {
            db()->prepare('INSERT INTO players (nickname, user_id) VALUES (?,?)')
                ->execute([$nick, (int)$u['id']]);
            log_action((int)$u['id'], 'player_created_self', ['nickname' => $nick]);
            flash_set('ok', 'Профиль игрока создан и привязан');
        }
        redirect('/cabinet.php');
    }

    if ($form === 'profile' && $player) {
        $birth = trim((string)($_POST['birth_date'] ?? ''));
        $birthVal = preg_match('/^\d{4}-\d{2}-\d{2}$/', $birth) ? $birth : null;
        db()->prepare('UPDATE players SET real_name = ?, tg = ?, vk = ?, faculty = ?, study_group = ?, birth_date = ?
            WHERE id = ?')->execute([
            trim((string)($_POST['real_name'] ?? '')) ?: null,
            trim((string)($_POST['tg'] ?? '')) ?: null,
            trim((string)($_POST['vk'] ?? '')) ?: null,
            trim((string)($_POST['faculty'] ?? '')) ?: null,
            trim((string)($_POST['study_group'] ?? '')) ?: null,
            $birthVal,
            (int)$player['id'],
        ]);
        if (!empty($_FILES['avatar']['name'])) {
            $res = save_image_upload($_FILES['avatar'], 'avatars', 'p' . (int)$player['id'], 512);
            if (is_string($res) && str_starts_with($res, '/uploads/')) {
                db()->prepare('UPDATE players SET avatar = ? WHERE id = ?')->execute([$res, (int)$player['id']]);
            } else {
                flash_set('err', 'Аватар: ' . $res);
                redirect('/cabinet.php');
            }
        }
        log_action((int)$u['id'], 'profile_update');
        flash_set('ok', 'Профиль сохранён');
        redirect('/cabinet.php');
    }

    if ($form === 'day_reg' && $player) {
        $dayId = (int)($_POST['day_id'] ?? 0);
        $st = db()->prepare("SELECT * FROM game_days WHERE id = ? AND status = 'reg_open'");
        $st->execute([$dayId]);
        if (!$st->fetch()) {
            flash_set('err', 'Запись на этот вечер закрыта');
            redirect('/cabinet.php');
        }
        $tf = trim((string)($_POST['time_from'] ?? ''));
        $tt = trim((string)($_POST['time_to'] ?? ''));
        $tf = preg_match('/^\d{2}:\d{2}$/', $tf) ? $tf : null;
        $tt = preg_match('/^\d{2}:\d{2}$/', $tt) ? $tt : null;
        db()->prepare('INSERT INTO day_registrations (day_id, player_id, time_from, time_to, comment)
            VALUES (?,?,?,?,?)
            ON DUPLICATE KEY UPDATE time_from = VALUES(time_from), time_to = VALUES(time_to),
                comment = VALUES(comment), cancelled_at = NULL')
            ->execute([$dayId, (int)$player['id'], $tf, $tt, trim((string)($_POST['comment'] ?? '')) ?: null]);
        log_action((int)$u['id'], 'day_register', ['day_id' => $dayId]);
        flash_set('ok', 'Вы записаны на вечер!');
        redirect('/day.php?id=' . $dayId);
    }

    if ($form === 'day_cancel' && $player) {
        $dayId = (int)($_POST['day_id'] ?? 0);
        db()->prepare('UPDATE day_registrations SET cancelled_at = NOW() WHERE day_id = ? AND player_id = ?')
            ->execute([$dayId, (int)$player['id']]);
        log_action((int)$u['id'], 'day_cancel', ['day_id' => $dayId]);
        flash_set('ok', 'Запись отменена');
        redirect('/cabinet.php');
    }

    redirect('/cabinet.php');
}

// Ближайший вечер с открытой записью
$openDay = null;
$myReg = null;
$pendingLink = null;
if (db_ready()) {
    $openDay = db()->query("SELECT * FROM game_days WHERE status = 'reg_open' AND date >= CURDATE() - INTERVAL 1 DAY
        ORDER BY date LIMIT 1")->fetch() ?: null;
    if ($openDay && $player) {
        $st = db()->prepare('SELECT * FROM day_registrations WHERE day_id = ? AND player_id = ? AND cancelled_at IS NULL');
        $st->execute([(int)$openDay['id'], (int)$player['id']]);
        $myReg = $st->fetch() ?: null;
    }
    if (!$player) {
        $st = db()->prepare("SELECT lr.*, p.nickname FROM link_requests lr
            JOIN players p ON p.id = lr.player_id WHERE lr.user_id = ? AND lr.status = 'pending'");
        $st->execute([(int)$u['id']]);
        $pendingLink = $st->fetch() ?: null;
    }
}

page_head('Личный кабинет', '');
echo '<h1>Личный кабинет</h1>';

// ── Привязка ника ──
if (!$player) {
    echo '<div class="card card-accent">';
    if ($pendingLink) {
        echo '<p style="margin:0;">Заявка на привязку к игроку <b>' . esc($pendingLink['nickname'])
            . '</b> ждёт подтверждения админа.</p>';
    } else {
        echo '<h2 style="margin-top:0;">Привяжите игровой ник</h2>';
        echo '<p style="color:var(--tx2);font-size:14px;">Если вы уже играли в клубе — укажите свой ник, и после
            подтверждения админом вся ваша статистика появится в профиле. Если вы новичок — просто укажите ник,
            под которым будете играть.</p>';
        echo '<form method="post" action="/cabinet.php" style="display:flex;gap:8px;max-width:420px;">' . csrf_field();
        echo '<input type="hidden" name="form" value="link">';
        echo '<input type="text" name="nick" placeholder="игровой ник" required style="flex:1;background:var(--sf2);color:var(--tx);border:1px solid var(--bd);border-radius:8px;padding:10px 12px;">';
        echo '<button class="btn" type="submit">Привязать</button></form>';
    }
    echo '</div>';
}

// ── Запись на вечер ──
if ($openDay && $player) {
    echo '<div class="card card-accent">';
    echo '<div class="section-head"><h2 style="margin:0;">Запись: ' . esc($openDay['title'])
        . ' · ' . date('d.m.Y', strtotime($openDay['date'])) . '</h2><span class="tag tag-open">запись открыта</span></div>';
    if ($myReg) {
        echo '<p style="color:var(--ok);margin:10px 0 8px;">Вы записаны'
            . ($myReg['time_from'] ? ' (с ' . substr($myReg['time_from'], 0, 5) . ' до ' . substr((string)$myReg['time_to'], 0, 5) . ')' : '') . '.</p>';
        echo '<form method="post" action="/cabinet.php">' . csrf_field();
        echo '<input type="hidden" name="form" value="day_cancel"><input type="hidden" name="day_id" value="' . (int)$openDay['id'] . '">';
        echo '<button class="btn btn-ghost" type="submit">Отменить запись</button></form>';
    } else {
        echo '<form method="post" action="/cabinet.php">' . csrf_field();
        echo '<input type="hidden" name="form" value="day_reg"><input type="hidden" name="day_id" value="' . (int)$openDay['id'] . '">';
        echo '<div style="display:flex;gap:10px;flex-wrap:wrap;align-items:end;">';
        echo '<div class="field" style="margin:0;"><label>Могу с</label><input type="time" name="time_from"></div>';
        echo '<div class="field" style="margin:0;"><label>до</label><input type="time" name="time_to"></div>';
        echo '<div class="field" style="margin:0;flex:1;min-width:160px;"><label>Комментарий</label><input type="text" name="comment" placeholder="необязательно"></div>';
        echo '<button class="btn" type="submit">Записаться</button>';
        echo '</div></form>';
    }
    echo '</div>';
}

echo '<div class="grid-2">';

// ── Профиль ──
echo '<div class="card"><h2 style="margin-top:0;">Профиль</h2>';
if ($player) {
    echo '<div style="display:flex;align-items:center;gap:12px;margin-bottom:12px;">' . avatar_html($player, 56);
    echo '<div><b>' . esc($player['nickname']) . '</b><div style="font-size:12px;color:var(--tx2);">'
        . esc(role_label($u['role'])) . ' · <a href="/player.php?id=' . (int)$player['id'] . '">публичный профиль</a></div></div></div>';
    echo '<form method="post" action="/cabinet.php" enctype="multipart/form-data">' . csrf_field();
    echo '<input type="hidden" name="form" value="profile">';
    echo '<div class="field"><label>Аватар (JPG/PNG, до 10 МБ)</label><input type="file" name="avatar" accept="image/*"></div>';
    echo '<div class="field"><label>ФИО</label><input type="text" name="real_name" value="' . esc($player['real_name']) . '"></div>';
    echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">';
    echo '<div class="field"><label>Telegram</label><input type="text" name="tg" value="' . esc($player['tg']) . '"></div>';
    echo '<div class="field"><label>VK</label><input type="text" name="vk" value="' . esc($player['vk']) . '"></div>';
    echo '<div class="field"><label>Факультет</label><input type="text" name="faculty" value="' . esc($player['faculty']) . '"></div>';
    echo '<div class="field"><label>Группа</label><input type="text" name="study_group" value="' . esc($player['study_group']) . '"></div>';
    echo '</div>';
    echo '<div class="field"><label>Дата рождения</label><input type="date" name="birth_date" value="' . esc($player['birth_date']) . '"></div>';
    echo '<button class="btn" type="submit">Сохранить профиль</button></form>';
} else {
    echo '<p style="color:var(--tx2);">Анкета станет доступна после привязки ника.</p>';
    echo '<table class="tbl"><tr><td style="color:var(--tx2);width:40%;">Ник аккаунта</td><td>' . esc($u['nickname']) . '</td></tr>';
    echo '<tr><td style="color:var(--tx2);">Роль</td><td>' . esc(role_label($u['role'])) . '</td></tr></table>';
}
echo '</div>';

// ── Пароль ──
echo '<div class="card"><h2 style="margin-top:0;">Сменить пароль</h2>';
echo '<form method="post" action="/cabinet.php">' . csrf_field();
echo '<input type="hidden" name="form" value="password">';
echo '<div class="field"><label>Текущий пароль</label><input type="password" name="old_password" required autocomplete="current-password"></div>';
echo '<div class="field"><label>Новый пароль</label><input type="password" name="new_password" required autocomplete="new-password"></div>';
echo '<div class="field"><label>Новый пароль ещё раз</label><input type="password" name="new_password2" required autocomplete="new-password"></div>';
echo '<button class="btn" type="submit">Сохранить</button></form></div>';

echo '</div>';
page_foot();
