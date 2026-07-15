<?php
require dirname(__DIR__, 2) . '/inc/bootstrap.php';
require_once ROOT . '/inc/bot_lib.php';
$u = require_role('admin');
$isOwner = $u['role'] === 'owner';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $form = (string)($_POST['form'] ?? '');
    $targetId = (int)($_POST['user_id'] ?? 0);

    $st = db()->prepare('SELECT * FROM users WHERE id = ?');
    $st->execute([$targetId]);
    $target = $st->fetch();
    if (!$target) {
        flash_set('err', 'Пользователь не найден');
        redirect('/admin/users.php');
    }

    if ($form === 'reset') {
        // Неприкосновенность верхушки: пароль зама/руководителя сбрасывает только руководитель —
        // иначе админ сбросит пароль владельца и (без Telegram) увидит его в открытом виде.
        if (!$isOwner && in_array($target['role'], ['deputy', 'owner'], true)) {
            flash_set('err', 'Пароль зама и руководителя сбрасывает только руководитель');
            redirect('/admin/users.php');
        }
        $alphabet = 'abcdefghkmnpqrstuvwxyz23456789';
        $temp = '';
        for ($i = 0; $i < 8; $i++) {
            $temp .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($temp, PASSWORD_DEFAULT), $targetId]);

        // Если у игрока привязан Telegram — отправляем новый пароль ему в личку бота
        $tgId = (int)($target['tg_user_id'] ?? 0);
        $sent = false;
        if ($tgId && bot_token() !== '') {
            $res = bot_send($tgId,
                "🔐 Администратор сбросил твой пароль на сайте «Триада Менделеева».\n\n"
                . "Новый пароль: <code>" . esc($temp) . "</code>\n\n"
                . "Зайди на сайт и смени его в Личном кабинете.");
            $sent = is_array($res) && !empty($res['ok']);
        }
        log_action((int)$u['id'], 'admin_password_reset', ['user_id' => $targetId, 'via' => $sent ? 'telegram' : 'manual']);
        if ($sent) {
            flash_set('ok', 'Новый пароль отправлен «' . $target['nickname'] . '» в личку Telegram.');
        } else {
            $hint = $tgId ? ' (отправить в Telegram не удалось — передайте вручную)' : ' (Telegram не привязан — передайте вручную)';
            flash_set('ok', 'Новый пароль для «' . $target['nickname'] . '»: ' . $temp . $hint . '. Пусть сменит в кабинете.');
        }
        redirect('/admin/users.php');
    }

    if ($form === 'cap') {
        $cap = (string)($_POST['cap'] ?? '');
        $val = !empty($_POST['val']) ? 1 : 0;
        $col = $cap === 'judge' ? 'is_judge' : ($cap === 'photo' ? 'is_photographer' : null);
        $isAjax = !empty($_POST['ajax']);
        if ($col) {
            db()->prepare("UPDATE users SET $col = ? WHERE id = ?")->execute([$val, $targetId]);
            log_action((int)$u['id'], 'cap_change', ['user_id' => $targetId, 'cap' => $cap, 'val' => $val]);
            if ($isAjax) {
                $target[$col] = $val;
                header('Content-Type: application/json');
                echo json_encode(['ok' => true, 'on' => (bool)$val, 'status' => status_html($target)]);
                exit;
            }
            flash_set('ok', ($cap === 'judge' ? 'Судья' : 'Фотограф') . ($val ? ' назначен' : ' снят') . ': ' . $target['nickname']);
        }
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false]);
            exit;
        }
        redirect('/admin/users.php');
    }

    if ($form === 'role') {
        // Админ может назначать и снимать админов (игрок ↔ админ). Верхушку
        // (зам, руководитель) назначает и снимает только руководитель —
        // иначе админ сделает себя владельцем или разжалует настоящего.
        $newRole = (string)($_POST['role'] ?? '');
        if (!in_array($newRole, ['player', 'admin', 'deputy', 'owner'], true)) {
            flash_set('err', 'Неизвестная роль');
            redirect('/admin/users.php');
        }
        $topInvolved = in_array($newRole, ['deputy', 'owner'], true)
            || in_array($target['role'], ['deputy', 'owner'], true);
        if (!$isOwner && $topInvolved) {
            flash_set('err', 'Роли «зам руководителя» и «руководитель» меняет только руководитель');
            redirect('/admin/users.php');
        }
        if ($target['role'] === 'owner' && $newRole !== 'owner') {
            $owners = (int)db()->query("SELECT COUNT(*) FROM users WHERE role = 'owner'")->fetchColumn();
            if ($owners <= 1) {
                flash_set('err', 'Нельзя снять последнего руководителя — сначала назначьте ещё одного');
                redirect('/admin/users.php');
            }
        }
        db()->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([$newRole, $targetId]);
        log_action((int)$u['id'], 'role_change', ['user_id' => $targetId, 'role' => $newRole]);
        flash_set('ok', 'Роль «' . $target['nickname'] . '» → ' . role_label($newRole));
        redirect('/admin/users.php');
    }

    if ($form === 'delete_user') {
        // Аккаунты зама/руководителя удаляет только руководитель; остальные — любой админ.
        $targetTop = in_array($target['role'], ['deputy', 'owner'], true);
        if (!$isOwner && $targetTop) {
            flash_set('err', 'Аккаунты зама и руководителя удаляет только руководитель');
            redirect('/admin/users.php');
        }
        if ($targetId === (int)$u['id']) {
            flash_set('err', 'Нельзя удалить собственный аккаунт');
            redirect('/admin/users.php');
        }
        try {
            db()->prepare('UPDATE players SET user_id = NULL WHERE user_id = ?')->execute([$targetId]); // игрок и статистика остаются
            db()->prepare('DELETE FROM users WHERE id = ?')->execute([$targetId]);
            log_action((int)$u['id'], 'user_delete', ['nick' => $target['nickname']]);
            flash_set('ok', 'Аккаунт «' . $target['nickname'] . '» удалён (игрок и статистика сохранены)');
        } catch (Throwable $e) {
            flash_set('err', 'Не удалось удалить аккаунт: ' . $e->getMessage());
        }
        redirect('/admin/users.php');
    }
    redirect('/admin/users.php');
}

$q = trim((string)($_GET['q'] ?? ''));
$onlyTg = !empty($_GET['tg']);
$sql = 'SELECT us.*, p.id AS player_id, p.nickname AS player_nick, p.real_name, p.avatar,
        (us.last_seen IS NOT NULL AND us.last_seen > NOW() - INTERVAL 5 MINUTE) AS online
    FROM users us
    LEFT JOIN players p ON p.user_id = us.id';
if ($onlyTg) {
    $sql .= ' WHERE us.tg_user_id IS NOT NULL';
}
$params = [];
if ($q !== '') {
    $sql .= ' WHERE us.nickname LIKE ?';
    $params[] = '%' . like_escape($q) . '%';
}
$sql .= ' ORDER BY FIELD(us.role, \'owner\',\'deputy\',\'admin\',\'judge\',\'player\'), us.nickname LIMIT 200';
$st = db()->prepare($sql);
$st->execute($params);
$list = $st->fetchAll();

$cnt = db()->query("SELECT COUNT(*) total,
        SUM(tg_user_id IS NOT NULL) tg,
        SUM(last_seen IS NOT NULL AND last_seen > NOW() - INTERVAL 5 MINUTE) online
    FROM users")->fetch();

page_head('Админка — пользователи', '');
echo '<p><a href="/admin/">← Админка</a></p><h1>Пользователи и роли</h1>';
echo '<div class="grid-stats" style="grid-template-columns:repeat(3,minmax(0,1fr));margin-bottom:14px;">';
echo '<div class="stat"><div class="lbl">зарегистрировано</div><div class="val">' . (int)$cnt['total'] . '</div></div>';
echo '<div class="stat"><div class="lbl">привязали Telegram</div><div class="val">' . (int)$cnt['tg'] . '</div></div>';
echo '<div class="stat"><div class="lbl">сейчас в сети</div><div class="val" style="color:var(--ok);">' . (int)$cnt['online'] . '</div></div>';
echo '</div>';
echo '<p style="color:var(--tx2);font-size:13px;margin-top:-4px;">Роли и права (судья/фотограф) назначают админы. Замами и руководителями управляет только руководитель; последнего руководителя снять нельзя. При удалении аккаунта игрок и статистика сохраняются.</p>';

// ── Таблица прав ролей ──
$chk = '<span style="color:var(--ok);font-weight:700;">✓</span>';
$no = '<span style="color:var(--tx3);">—</span>';
echo '<details style="margin:0 0 14px;"><summary style="cursor:pointer;color:var(--ac);font-size:14px;">📋 Кто что может — таблица прав ролей</summary>';
echo '<div class="card" style="overflow-x:auto;margin-top:10px;max-width:860px;"><table class="tbl" style="table-layout:fixed;">';
$cc = ' style="text-align:center;width:96px;"'; // роли: равные колонки, галочки по центру
echo '<tr><th>Право</th><th' . $cc . '>Судья</th><th' . $cc . '>Админ</th><th' . $cc . '>Зам</th><th' . $cc . '>Руководитель</th></tr>';
foreach ([
    ['Вести протоколы вечеров и турниров', 1, 1, 1, 1],
    ['Создавать вечера и турниры, анонсы, рассылки', 0, 1, 1, 1],
    ['Новости, бан-лист, слияние ников, заявки на привязку', 0, 1, 1, 1],
    ['Назначать судей и фотографов', 0, 1, 1, 1],
    ['Принимать заявки в клуб (в админке)', 0, 1, 1, 1],
    ['Назначать и снимать админов', 0, 1, 1, 1],
    ['Сброс пароля и удаление аккаунтов игроков и админов', 0, 1, 1, 1],
    ['Бот-уведомления о новых заявках в клуб', 0, 0, 1, 1],
    ['Назначать и снимать замов и руководителей', 0, 0, 0, 1],
    ['Сброс пароля и удаление аккаунтов замов и руководителей', 0, 0, 0, 1],
] as [$right, $j, $a, $d, $o]) {
    echo '<tr><td style="white-space:normal;">' . $right . '</td>'
        . '<td style="text-align:center;">' . ($j ? $chk : $no) . '</td>'
        . '<td style="text-align:center;">' . ($a ? $chk : $no) . '</td>'
        . '<td style="text-align:center;">' . ($d ? $chk : $no) . '</td>'
        . '<td style="text-align:center;">' . ($o ? $chk : $no) . '</td></tr>';
}
echo '</table><p style="color:var(--tx3);font-size:12px;margin:8px 0 0;">Последний руководитель неснимаем. Судья и фотограф — флаги поверх роли «игрок»; судья видит только судейские страницы. Контакт клуба на страницах ошибок — руководитель.</p></div></details>';

echo '<div style="display:flex;gap:10px;align-items:center;margin-bottom:14px;flex-wrap:wrap;">';
echo '<form method="get" action="/admin/users.php" style="max-width:300px;flex:1;min-width:200px;">';
echo '<div class="field" style="margin:0;"><input type="search" id="user-search" autocomplete="off" name="q" placeholder="Поиск по нику аккаунта" value="' . esc($q) . '"></div></form>';
echo '<a class="tag ' . ($onlyTg ? 'tag-open' : '') . '" href="/admin/users.php' . ($onlyTg ? '' : '?tg=1') . '">'
    . ($onlyTg ? 'показать всех' : 'только с Telegram') . '</a>';
echo '</div>';

$roles = ['player' => 'игрок', 'admin' => 'админ', 'deputy' => 'зам руководителя', 'owner' => 'руководитель'];

function cap_btn(int $rid, string $cap, bool $on, string $label): string
{
    $h = csrf_field() . '<input type="hidden" name="form" value="cap"><input type="hidden" name="user_id" value="' . $rid . '">'
        . '<input type="hidden" name="cap" value="' . $cap . '"><input type="hidden" name="val" value="' . ($on ? '0' : '1') . '">';
    $cls = $on ? 'tag-open' : '';
    return '<form method="post" action="/admin/users.php" class="cap-form" style="display:inline;">' . $h
        . '<button class="tag ' . $cls . '" style="cursor:pointer;border:none;" type="submit" data-label="' . esc($label) . '">'
        . ($on ? '✓ ' : '+ ') . $label . '</button></form>';
}

function status_html(array $row): string
{
    $h = '';
    foreach (user_role_badges($row) as $b) {
        $red = in_array($b, ['руководитель', 'зам руководителя', 'админ'], true);
        $h .= '<span class="tag" style="margin-right:4px;' . ($red ? 'color:var(--ac);' : '') . '">' . $b . '</span>';
    }
    return $h;
}

echo '<div class="card" style="overflow-x:auto;"><table class="tbl">';
echo '<tr><th>Аккаунт</th><th>Имя</th><th>Telegram</th><th>Статус</th><th>Роли</th><th></th></tr>';
foreach ($list as $row) {
    $rid = (int)$row['id'];
    $isAdminRow = in_array($row['role'], ['owner', 'deputy', 'admin'], true);
    $isTopRow = in_array($row['role'], ['owner', 'deputy'], true); // верхушка: трогает только руководитель
    $searchText = mb_strtolower(trim($row['nickname'] . ' ' . ($row['player_nick'] ?? '') . ' ' . ($row['real_name'] ?? '') . ' ' . ($row['tg_username'] ?? '')));
    echo '<tr data-search="' . esc($searchText) . '"><td><div style="display:flex;align-items:center;gap:8px;">' . ($row['online']
        ? '<span title="в сети" style="flex:none;width:8px;height:8px;border-radius:50%;background:var(--ok);"></span>'
        : '')
        . avatar_html(['nickname' => $row['player_nick'] ?: $row['nickname'], 'avatar' => $row['avatar']], 28)
        . '<span>' . esc($row['nickname']) . '</span></div></td>';
    $nameShown = $row['real_name'] ? esc($row['real_name']) : '<span style="color:var(--tx3);">не указано</span>';
    echo '<td>' . ($row['player_id']
        ? '<a href="/player.php?id=' . (int)$row['player_id'] . '" style="color:var(--tx);">' . $nameShown . '</a>'
        : '<span style="color:var(--tx2);">—</span>') . '</td>';
    echo '<td>' . ($row['tg_user_id']
        ? '<span class="tag tag-ok">' . ($row['tg_username'] ? '@' . esc($row['tg_username']) : 'привязан') . '</span>'
        : '<span style="color:var(--tx3);">—</span>') . '</td>';
    // Статус (напр. «судья + игрок»)
    echo '<td class="us-status" data-uid="' . $rid . '">' . status_html($row) . '</td>';
    // Управление ролями
    echo '<td style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;">';
    if (!$isAdminRow) {
        echo cap_btn($rid, 'judge', !empty($row['is_judge']), 'судья');
        echo cap_btn($rid, 'photo', !empty($row['is_photographer']), 'фотограф');
    }
    $canEditRole = $isOwner || !$isTopRow; // роли верхушки (зам/руководитель) меняет только руководитель
    {
        echo '<form method="post" action="/admin/users.php" style="display:inline;">' . csrf_field();
        echo '<input type="hidden" name="form" value="role"><input type="hidden" name="user_id" value="' . $rid . '">';
        echo '<select name="role" ' . ($canEditRole ? 'onchange="if(confirm(\'Сменить роль?\'))this.form.submit();"' : 'disabled title="только руководитель"')
            . ' style="background:var(--sf2);color:var(--tx);border:1px solid var(--bd);border-radius:6px;padding:4px 8px;font-size:12px;">';
        foreach ($roles as $rk => $rl) {
            if (in_array($rk, ['deputy', 'owner'], true) && !$isOwner && $row['role'] !== $rk) {
                continue; // верхушку назначает только руководитель
            }
            echo '<option value="' . $rk . '"' . ($row['role'] === $rk ? ' selected' : '') . '>' . $rl . '</option>';
        }
        echo '</select></form>';
    }
    echo '</td>';
    $hasTg = !empty($row['tg_user_id']);
    $canReset = $isOwner || !$isTopRow; // пароль зама/руководителя сбрасывает только руководитель
    $resetLabel = $hasTg ? 'Сбросить → в Telegram' : 'Сбросить пароль';
    $resetConfirm = $hasTg
        ? 'Сбросить пароль ' . esc(addslashes($row['nickname'])) . '? Новый пароль придёт ему в личку бота.'
        : 'Сбросить пароль ' . esc(addslashes($row['nickname'])) . '? Telegram не привязан — пароль покажется тебе.';
    echo '<td>';
    if ($canReset) {
        echo '<form method="post" action="/admin/users.php" style="display:inline;" onsubmit="return confirm(\'' . $resetConfirm . '\');">' . csrf_field();
        echo '<input type="hidden" name="form" value="reset"><input type="hidden" name="user_id" value="' . $rid . '">';
        echo '<button class="btn btn-ghost" style="padding:4px 10px;font-size:12px;" type="submit">' . esc($resetLabel) . '</button></form>';
    }
    $canDelete = $rid !== (int)$u['id'] && ($isOwner || !$isTopRow);
    if ($canDelete) {
        echo ' <form method="post" action="/admin/users.php" style="display:inline;" onsubmit="return confirm(\'Удалить аккаунт ' . esc(addslashes($row['nickname'])) . '? Игрок и статистика останутся — удалится только вход.\');">' . csrf_field()
            . '<input type="hidden" name="form" value="delete_user"><input type="hidden" name="user_id" value="' . $rid . '">'
            . '<button class="btn btn-ghost" style="padding:4px 10px;font-size:12px;color:var(--ac);" type="submit">Удалить</button></form>';
    }
    echo '</td>';
    echo '</tr>';
}
echo '</table></div>';

// Назначение судьи/фотографа без перезагрузки страницы
echo '<script>(function(){'
    . 'document.querySelectorAll(".cap-form").forEach(function(f){'
    . 'f.addEventListener("submit",function(ev){ev.preventDefault();'
    . 'var btn=f.querySelector("button"),valInp=f.querySelector("input[name=val]"),label=btn.getAttribute("data-label");'
    . 'var fd=new FormData(f);fd.append("ajax","1");btn.disabled=true;'
    . 'fetch("/admin/users.php",{method:"POST",body:fd,headers:{"X-Requested-With":"XMLHttpRequest"}})'
    . '.then(function(r){return r.json();}).then(function(d){btn.disabled=false;if(!d.ok){return;}'
    . 'var on=d.on;btn.classList.toggle("tag-open",on);btn.textContent=(on?"✓ ":"+ ")+label;valInp.value=on?"0":"1";'
    . 'var tr=f.closest("tr");if(tr&&d.status!==undefined){var st=tr.querySelector(".us-status");if(st){st.innerHTML=d.status;}}})'
    . '.catch(function(){btn.disabled=false;});});});})();</script>';

// Живой поиск: фильтрация строк по мере набора (без перезагрузки)
echo '<script>(function(){'
    . 'var inp=document.getElementById("user-search");if(!inp)return;'
    . 'var rows=document.querySelectorAll("table.tbl tr[data-search]");'
    . 'function apply(){var q=inp.value.trim().toLowerCase();rows.forEach(function(r){'
    . 'r.style.display=(!q||r.getAttribute("data-search").indexOf(q)!==-1)?"":"none";});}'
    . 'inp.addEventListener("input",apply);'
    . 'var form=inp.closest("form");if(form)form.addEventListener("submit",function(e){e.preventDefault();});'
    . 'apply();})();</script>';

page_foot();
