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
        $raw = trim((string)($_POST['nick'] ?? ''));
        $flair = flair_clean($raw);       // эмодзи уходят в «висюльку»
        $nick = nickname_clean($raw);     // сам ник — без эмодзи
        if ($nick === '') {
            flash_set('err', 'Укажите ник (буквами, без эмодзи)');
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
            db()->prepare('INSERT INTO players (nickname, user_id, flair) VALUES (?,?,?)')
                ->execute([$nick, (int)$u['id'], $flair ?: null]);
            log_action((int)$u['id'], 'player_created_self', ['nickname' => $nick]);
            flash_set('ok', 'Профиль игрока создан и привязан');
        }
        redirect('/cabinet.php');
    }

    if ($form === 'profile' && $player) {
        $birth = trim((string)($_POST['birth_date'] ?? ''));
        $birthVal = preg_match('/^\d{4}-\d{2}-\d{2}$/', $birth) ? $birth : null;
        $fav = (string)($_POST['fav_role'] ?? '');
        $favVal = in_array($fav, ['civ', 'maf', 'sheriff', 'don'], true) ? $fav : null;
        $rhtu = !empty($_POST['is_rhtu']) ? 1 : 0;
        db()->prepare('UPDATE players SET real_name = ?, tg = ?, vk = ?, faculty = ?, study_group = ?, birth_date = ?, fav_role = ?, is_rhtu = ?, flair = ?
            WHERE id = ?')->execute([
            trim((string)($_POST['real_name'] ?? '')) ?: null,
            trim((string)($_POST['tg'] ?? '')) ?: null,
            trim((string)($_POST['vk'] ?? '')) ?: null,
            $rhtu ? (trim((string)($_POST['faculty'] ?? '')) ?: null) : null,
            $rhtu ? (trim((string)($_POST['study_group'] ?? '')) ?: null) : null,
            $birthVal,
            $favVal,
            $rhtu,
            flair_clean((string)($_POST['flair'] ?? '')) ?: null,
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

    if ($form === 'avatars_upload' && $player) {
        $files = $_FILES['avatars'] ?? null;
        $added = 0;
        if ($files && is_array($files['name'])) {
            $n = count($files['name']);
            for ($i = 0; $i < $n && $added < 12; $i++) {
                if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                    continue;
                }
                $one = ['name' => $files['name'][$i], 'tmp_name' => $files['tmp_name'][$i],
                    'error' => $files['error'][$i], 'size' => $files['size'][$i]];
                $base = 'p' . (int)$player['id'] . '_' . time() . '_' . $i;
                $full = save_image_upload($one, 'avatars', $base, 512);
                if (!is_string($full) || !str_starts_with($full, '/uploads/')) {
                    continue;
                }
                $thumb = save_image_upload($one, 'avatars/thumbs', $base, 160);
                db()->prepare('INSERT INTO player_avatars (player_id, file, thumb) VALUES (?,?,?)')
                    ->execute([(int)$player['id'], $full, is_string($thumb) ? $thumb : $full]);
                $added++;
            }
        }
        if (empty($player['avatar'])) {
            $f = db()->prepare('SELECT file FROM player_avatars WHERE player_id = ? ORDER BY id LIMIT 1');
            $f->execute([(int)$player['id']]);
            $file = $f->fetchColumn();
            if ($file) {
                db()->prepare('UPDATE players SET avatar = ? WHERE id = ?')->execute([$file, (int)$player['id']]);
            }
        }
        log_action((int)$u['id'], 'avatars_upload', ['count' => $added]);
        flash_set($added ? 'ok' : 'err', $added ? "Загружено в галерею: $added" : 'Не удалось загрузить (нужны картинки)');
        redirect('/cabinet.php');
    }

    if ($form === 'avatar_cropped' && $player) {
        $data = (string)($_POST['image'] ?? '');
        if (preg_match('#^data:image/(jpeg|png);base64,#', $data)) {
            $bin = base64_decode(substr($data, strpos($data, ',') + 1), true);
            if ($bin !== false && strlen($bin) > 100 && strlen($bin) < 8 * 1024 * 1024) {
                $dir = ROOT . '/public_html/uploads/avatars';
                if (!is_dir($dir)) {
                    @mkdir($dir, 0775, true);
                }
                $base = 'p' . (int)$player['id'] . '_' . time();
                $rel = '/uploads/avatars/' . $base . '.jpg';
                if (@file_put_contents($dir . '/' . $base . '.jpg', $bin) !== false) {
                    db()->prepare('INSERT INTO player_avatars (player_id, file, thumb) VALUES (?,?,?)')
                        ->execute([(int)$player['id'], $rel, $rel]);
                    if (empty($player['avatar'])) {
                        db()->prepare('UPDATE players SET avatar = ? WHERE id = ?')->execute([$rel, (int)$player['id']]);
                    }
                    log_action((int)$u['id'], 'avatar_cropped');
                    flash_set('ok', 'Аватар добавлен');
                } else {
                    flash_set('err', 'Не удалось сохранить аватар');
                }
            } else {
                flash_set('err', 'Картинка не распозналась');
            }
        }
        redirect('/cabinet.php');
    }

    if ($form === 'avatar_set' && $player) {
        $file = (string)($_POST['file'] ?? '');
        $chk = db()->prepare('SELECT id FROM player_avatars WHERE player_id = ? AND file = ?');
        $chk->execute([(int)$player['id'], $file]);
        if ($chk->fetch()) {
            db()->prepare('UPDATE players SET avatar = ? WHERE id = ?')->execute([$file, (int)$player['id']]);
            flash_set('ok', 'Аватар выбран основным');
        }
        redirect('/cabinet.php');
    }

    if ($form === 'avatar_delete' && $player) {
        $id = (int)($_POST['avatar_id'] ?? 0);
        $row = db()->prepare('SELECT * FROM player_avatars WHERE id = ? AND player_id = ?');
        $row->execute([$id, (int)$player['id']]);
        $av = $row->fetch();
        if ($av) {
            @unlink(ROOT . '/public_html' . $av['file']);
            if ($av['thumb']) {
                @unlink(ROOT . '/public_html' . $av['thumb']);
            }
            db()->prepare('DELETE FROM player_avatars WHERE id = ?')->execute([$id]);
            if (($player['avatar'] ?? '') === $av['file']) {
                db()->prepare('UPDATE players SET avatar = NULL WHERE id = ?')->execute([(int)$player['id']]);
            }
            flash_set('ok', 'Удалено из галереи');
        }
        redirect('/cabinet.php');
    }

    if ($form === 'tg_unlink') {
        db()->prepare('UPDATE users SET tg_user_id = NULL, tg_username = NULL, tg_linked_at = NULL WHERE id = ?')
            ->execute([(int)$u['id']]);
        log_action((int)$u['id'], 'tg_unlink');
        flash_set('ok', 'Telegram отвязан');
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

// ── Галерея аватаров ──
if ($player) {
    $av = db()->prepare('SELECT * FROM player_avatars WHERE player_id = ? ORDER BY id DESC');
    $av->execute([(int)$player['id']]);
    $avatars = $av->fetchAll();
    echo '<div class="card"><h2 style="margin-top:0;">Аватары · галерея</h2>';
    echo '<p style="color:var(--tx2);font-size:13px;margin-top:0;">Загрузите фото — откроется редактор обрезки (квадрат). Выбранная аватарка показывается рядом с ником. Галерея пригодится для турниров.</p>';
    echo '<div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:14px;">';
    echo '<input type="file" id="ava-file" accept="image/*" style="display:none;">';
    echo '<button type="button" class="btn" id="ava-pick">Загрузить фото с обрезкой</button>';
    echo '</div>';
    echo '<form method="post" action="/cabinet.php" id="ava-crop-form" style="display:none;">' . csrf_field()
        . '<input type="hidden" name="form" value="avatar_cropped"><input type="hidden" name="image" id="ava-image-data"></form>';
    if ($avatars) {
        echo '<div class="avatar-gallery">';
        foreach ($avatars as $a) {
            $isMain = ($player['avatar'] ?? '') === $a['file'];
            echo '<div class="ava-item' . ($isMain ? ' ava-main' : '') . '">';
            echo '<img src="' . esc($a['thumb'] ?: $a['file']) . '" alt="">';
            if ($isMain) {
                echo '<span class="ava-badge">основная</span>';
            }
            echo '<div class="ava-actions">';
            if (!$isMain) {
                echo '<form method="post" action="/cabinet.php">' . csrf_field()
                    . '<input type="hidden" name="form" value="avatar_set"><input type="hidden" name="file" value="' . esc($a['file']) . '">'
                    . '<button type="submit">Основной</button></form>';
            }
            echo '<form method="post" action="/cabinet.php" onsubmit="return confirm(\'Удалить аватар?\');">' . csrf_field()
                . '<input type="hidden" name="form" value="avatar_delete"><input type="hidden" name="avatar_id" value="' . (int)$a['id'] . '">'
                . '<button type="submit" class="ava-del">Удалить</button></form>';
            echo '</div></div>';
        }
        echo '</div>';
    } else {
        echo '<p style="color:var(--tx3);font-size:13px;">Пока пусто — загрузите первую картинку.</p>';
    }
    echo '</div>';
}

// ── Telegram-бот ──
$tg = db()->prepare('SELECT tg_user_id, tg_username FROM users WHERE id = ?');
$tg->execute([(int)$u['id']]);
$tgrow = $tg->fetch();
$botUser = setting('bot_username');
echo '<div class="card"><h2 style="margin-top:0;">Telegram-бот</h2>';
if ($tgrow && $tgrow['tg_user_id']) {
    echo '<p style="color:var(--ok);margin:0 0 12px;">✓ Telegram привязан'
        . ($tgrow['tg_username'] ? ' (@' . esc($tgrow['tg_username']) . ')' : '')
        . '. Вы будете получать анонсы и голосовалки по игровым дням.</p>';
    echo '<form method="post" action="/cabinet.php">' . csrf_field()
        . '<input type="hidden" name="form" value="tg_unlink"><button class="btn btn-ghost" type="submit">Отвязать Telegram</button></form>';
} else {
    $cs = db()->prepare('SELECT code FROM tg_link_codes WHERE user_id = ?');
    $cs->execute([(int)$u['id']]);
    echo '<p style="color:var(--tx2);margin-top:0;">Привяжите Telegram, чтобы участвовать в голосовалках на игровой день и получать уведомления. Откройте бота, нажмите «Старт» — он спросит ваш игровой ник.</p>';
    if ($botUser) {
        echo '<a class="btn" href="https://t.me/' . esc($botUser) . '?start=link" target="_blank" rel="noopener">Открыть бота и привязать</a>';
    } else {
        echo '<p style="margin-bottom:0;color:var(--tx3);">Бот ещё не настроен. Username указывается в Админка → Тексты.</p>';
    }
}
echo '</div>';

echo '<div class="grid-2">';

// ── Профиль ──
echo '<div class="card"><h2 style="margin-top:0;">Профиль</h2>';
if ($player) {
    echo '<div style="display:flex;align-items:center;gap:12px;margin-bottom:12px;">' . avatar_html($player, 56);
    echo '<div><b>' . player_label($player) . '</b><div style="font-size:12px;color:var(--tx2);">'
        . esc(role_label($u['role'])) . ' · <a href="/player.php?id=' . (int)$player['id'] . '">публичный профиль</a></div></div></div>';
    echo '<form method="post" action="/cabinet.php">' . csrf_field();
    echo '<input type="hidden" name="form" value="profile">';
    echo '<div class="field"><label>ФИО <span style="color:var(--tx3);font-weight:400;">— часто нужно для пропусков в университет</span></label>'
        . '<input type="text" name="real_name" value="' . esc($player['real_name']) . '"></div>';
    echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">';
    echo '<div class="field"><label>Telegram</label><input type="text" name="tg" value="' . esc($player['tg']) . '"></div>';
    echo '<div class="field"><label>VK</label><input type="text" name="vk" value="' . esc($player['vk']) . '"></div>';
    echo '</div>';
    $rhtu = !empty($player['is_rhtu']);
    echo '<label style="display:flex;align-items:center;gap:8px;font-size:14px;margin:4px 0 10px;cursor:pointer;">'
        . '<input type="checkbox" name="is_rhtu" id="rhtu-check" value="1" ' . ($rhtu ? 'checked' : '') . '> Учусь в РХТУ</label>';
    echo '<div id="rhtu-fields" style="display:' . ($rhtu ? 'grid' : 'none') . ';grid-template-columns:1fr 1fr;gap:10px;">';
    echo '<div class="field"><label>Факультет</label><input type="text" name="faculty" value="' . esc($player['faculty']) . '"></div>';
    echo '<div class="field"><label>Группа</label><input type="text" name="study_group" value="' . esc($player['study_group']) . '"></div>';
    echo '</div>';
    echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">';
    echo '<div class="field"><label>Дата рождения</label><input type="date" name="birth_date" value="' . esc($player['birth_date']) . '"></div>';
    $favOpts = ['' => '— не выбрана —', 'civ' => 'Мирный', 'sheriff' => 'Шериф', 'maf' => 'Мафия', 'don' => 'Дон'];
    echo '<div class="field"><label>Любимая роль</label><select name="fav_role" style="width:100%;background:var(--sf2);color:var(--tx);border:1px solid var(--bd);border-radius:8px;padding:10px 12px;">';
    foreach ($favOpts as $fk => $fl) {
        echo '<option value="' . $fk . '" ' . (($player['fav_role'] ?? '') === $fk ? 'selected' : '') . '>' . $fl . '</option>';
    }
    echo '</select></div></div>';
    echo '<div class="field"><label>Эмодзи-«висюлька» (необязательно — показывается рядом с ником; в играх и рейтинге ник остаётся чистым)</label>'
        . '<input type="text" id="flair-input" name="flair" maxlength="32" value="' . esc($player['flair'] ?? '') . '" placeholder="например 🦊" style="width:160px;">';
    $emojiList = ['🦊', '🐺', '🐻', '🦁', '🐯', '🐱', '🐶', '🐼', '🦄', '🐲', '🦅', '🦉', '🐢', '🐍', '🦂', '🐙', '🦈', '🐳',
        '🔥', '⭐', '🌟', '💫', '⚡', '💀', '☠️', '🎭', '🃏', '👑', '💎', '🏆', '🥇', '🌈', '🍀', '🌙', '❤️', '🖤', '💛',
        '😎', '🤡', '👻', '🤖', '🦹', '🕵️', '🎯', '🎲', '♠️', '♦️'];
    echo '<div class="emoji-pick">';
    foreach ($emojiList as $e) {
        echo '<button type="button" data-e="' . $e . '" title="добавить">' . $e . '</button>';
    }
    echo '<button type="button" id="flair-clear" title="очистить">✕</button>';
    echo '</div></div>';
    echo '<script>(function(){var inp=document.getElementById("flair-input");if(!inp)return;'
        . 'document.querySelectorAll(".emoji-pick button[data-e]").forEach(function(b){'
        . 'b.addEventListener("click",function(){var e=b.getAttribute("data-e");if((inp.value||"").length<12){inp.value=(inp.value||"")+e;}});});'
        . 'var c=document.getElementById("flair-clear");if(c){c.addEventListener("click",function(){inp.value="";});}})();</script>';
    echo '<button class="btn" type="submit">Сохранить профиль</button></form>';
    echo '<script>(function(){var c=document.getElementById("rhtu-check"),f=document.getElementById("rhtu-fields");'
        . 'if(c&&f)c.addEventListener("change",function(){f.style.display=c.checked?"grid":"none";});})();</script>';
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

// ── Модалка обрезки аватара (Cropper.js) ──
if ($player) {
    ?>
<link href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.1/cropper.min.css" rel="stylesheet">
<div id="crop-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.82);z-index:1000;align-items:center;justify-content:center;padding:16px;">
  <div style="background:var(--sf);border:1px solid var(--bd);border-radius:14px;padding:18px;max-width:520px;width:100%;">
    <h3 style="margin:0 0 12px;">Обрежьте фото</h3>
    <div style="max-height:60vh;overflow:hidden;"><img id="crop-img" style="max-width:100%;display:block;"></div>
    <div style="display:flex;gap:10px;margin-top:14px;justify-content:flex-end;">
      <button type="button" class="btn btn-ghost" id="crop-cancel">Отмена</button>
      <button type="button" class="btn" id="crop-ok">Обрезать и загрузить</button>
    </div>
  </div>
</div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.1/cropper.min.js"></script>
<script>
(function () {
  var fileInput = document.getElementById('ava-file'), pick = document.getElementById('ava-pick'),
      modal = document.getElementById('crop-modal'), img = document.getElementById('crop-img'), cropper = null;
  if (!pick) return;
  pick.addEventListener('click', function () { fileInput.click(); });
  fileInput.addEventListener('change', function () {
    var f = fileInput.files[0]; if (!f) return;
    var rd = new FileReader();
    rd.onload = function (e) {
      img.src = e.target.result; modal.style.display = 'flex';
      if (cropper) cropper.destroy();
      cropper = new Cropper(img, { aspectRatio: 1, viewMode: 1, autoCropArea: 1, background: false });
    };
    rd.readAsDataURL(f);
  });
  document.getElementById('crop-cancel').addEventListener('click', function () {
    modal.style.display = 'none'; if (cropper) { cropper.destroy(); cropper = null; } fileInput.value = '';
  });
  document.getElementById('crop-ok').addEventListener('click', function () {
    if (!cropper) return;
    var canvas = cropper.getCroppedCanvas({ width: 512, height: 512, imageSmoothingQuality: 'high' });
    document.getElementById('ava-image-data').value = canvas.toDataURL('image/jpeg', 0.85);
    document.getElementById('ava-crop-form').submit();
  });
})();
</script>
    <?php
}
page_foot();
