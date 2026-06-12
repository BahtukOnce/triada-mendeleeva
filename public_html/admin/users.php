<?php
require dirname(__DIR__, 2) . '/inc/bootstrap.php';
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
        $alphabet = 'abcdefghkmnpqrstuvwxyz23456789';
        $temp = '';
        for ($i = 0; $i < 8; $i++) {
            $temp .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($temp, PASSWORD_DEFAULT), $targetId]);
        log_action((int)$u['id'], 'admin_password_reset', ['user_id' => $targetId]);
        flash_set('ok', 'Новый пароль для «' . $target['nickname'] . '»: ' . $temp . ' — передайте игроку, пусть сменит в кабинете.');
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
                header('Content-Type: application/json');
                echo json_encode(['ok' => true, 'on' => (bool)$val]);
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
        if (!$isOwner) {
            flash_set('err', 'Менять роли админ/глава может только глава клуба');
            redirect('/admin/users.php');
        }
        $newRole = (string)($_POST['role'] ?? '');
        if (!in_array($newRole, ['player', 'admin', 'owner'], true)) {
            flash_set('err', 'Неизвестная роль');
            redirect('/admin/users.php');
        }
        if ($targetId === (int)$u['id']) {
            flash_set('err', 'Нельзя менять собственную роль');
            redirect('/admin/users.php');
        }
        if ($newRole === 'owner') {
            // Передача главенства: текущий глава становится админом
            db()->prepare("UPDATE users SET role = 'admin' WHERE id = ?")->execute([(int)$u['id']]);
            db()->prepare("UPDATE users SET role = 'owner' WHERE id = ?")->execute([$targetId]);
            log_action((int)$u['id'], 'owner_transfer', ['to' => $targetId]);
            flash_set('ok', 'Главой клуба назначен «' . $target['nickname'] . '». Вы теперь админ.');
        } else {
            db()->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([$newRole, $targetId]);
            log_action((int)$u['id'], 'role_change', ['user_id' => $targetId, 'role' => $newRole]);
            flash_set('ok', 'Роль «' . $target['nickname'] . '» → ' . role_label($newRole));
        }
        redirect('/admin/users.php');
    }

    if ($form === 'delete_user') {
        if (!$isOwner) {
            flash_set('err', 'Удалять аккаунты может только глава клуба');
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
$sql = 'SELECT us.*, p.id AS player_id, p.nickname AS player_nick, p.real_name,
        (us.last_seen IS NOT NULL AND us.last_seen > NOW() - INTERVAL 5 MINUTE) AS online
    FROM users us
    LEFT JOIN players p ON p.user_id = us.id';
if ($onlyTg) {
    $sql .= ' WHERE us.tg_user_id IS NOT NULL';
}
$params = [];
if ($q !== '') {
    $sql .= ' WHERE us.nickname LIKE ?';
    $params[] = '%' . $q . '%';
}
$sql .= ' ORDER BY FIELD(us.role, \'owner\',\'admin\',\'judge\',\'player\'), us.nickname LIMIT 200';
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
if (!$isOwner) {
    echo '<p style="color:var(--tx2);font-size:13px;">Сброс пароля доступен админам. Менять роли может только глава клуба.</p>';
}

echo '<div style="display:flex;gap:10px;align-items:center;margin-bottom:14px;flex-wrap:wrap;">';
echo '<form method="get" action="/admin/users.php" style="max-width:300px;flex:1;min-width:200px;">';
echo '<div class="field" style="margin:0;"><input type="search" name="q" placeholder="Поиск по нику аккаунта" value="' . esc($q) . '"></div></form>';
echo '<a class="tag ' . ($onlyTg ? 'tag-open' : '') . '" href="/admin/users.php' . ($onlyTg ? '' : '?tg=1') . '">'
    . ($onlyTg ? 'показать всех' : 'только с Telegram') . '</a>';
echo '</div>';

$roles = ['player' => 'игрок', 'admin' => 'админ', 'owner' => 'руководитель'];

function cap_btn(int $rid, string $cap, bool $on, string $label): string
{
    $h = csrf_field() . '<input type="hidden" name="form" value="cap"><input type="hidden" name="user_id" value="' . $rid . '">'
        . '<input type="hidden" name="cap" value="' . $cap . '"><input type="hidden" name="val" value="' . ($on ? '0' : '1') . '">';
    $cls = $on ? 'tag-open' : '';
    return '<form method="post" action="/admin/users.php" class="cap-form" style="display:inline;">' . $h
        . '<button class="tag ' . $cls . '" style="cursor:pointer;border:none;" type="submit" data-label="' . esc($label) . '">'
        . ($on ? '✓ ' : '+ ') . $label . '</button></form>';
}

echo '<div class="card" style="overflow-x:auto;"><table class="tbl">';
echo '<tr><th>Аккаунт</th><th>Имя</th><th>Telegram</th><th>Статус</th><th>Роли</th><th></th></tr>';
foreach ($list as $row) {
    $rid = (int)$row['id'];
    $isAdminRow = $row['role'] === 'owner' || $row['role'] === 'admin';
    echo '<tr><td>' . ($row['online']
        ? '<span title="в сети" style="display:inline-block;width:8px;height:8px;border-radius:50%;background:var(--ok);margin-right:7px;vertical-align:1px;"></span>'
        : '') . esc($row['nickname']) . '</td>';
    $nameShown = $row['real_name'] ? esc($row['real_name']) : '<span style="color:var(--tx3);">не указано</span>';
    echo '<td>' . ($row['player_id']
        ? '<a href="/player.php?id=' . (int)$row['player_id'] . '" style="color:var(--tx);">' . $nameShown . '</a>'
        : '<span style="color:var(--tx2);">—</span>') . '</td>';
    echo '<td>' . ($row['tg_user_id']
        ? '<span class="tag tag-ok">' . ($row['tg_username'] ? '@' . esc($row['tg_username']) : 'привязан') . '</span>'
        : '<span style="color:var(--tx3);">—</span>') . '</td>';
    // Статус
    $badges = user_role_badges($row);
    echo '<td>';
    foreach ($badges as $bi => $b) {
        $red = in_array($b, ['руководитель', 'админ'], true);
        echo '<span class="tag" style="margin-right:4px;' . ($red ? 'color:var(--ac);' : '') . '">' . $b . '</span>';
    }
    echo '</td>';
    // Управление ролями
    echo '<td style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;">';
    if (!$isAdminRow) {
        echo cap_btn($rid, 'judge', !empty($row['is_judge']), 'судья');
        echo cap_btn($rid, 'photo', !empty($row['is_photographer']), 'фотограф');
    }
    if ($isOwner && $rid !== (int)$u['id']) {
        echo '<form method="post" action="/admin/users.php" style="display:inline;">' . csrf_field();
        echo '<input type="hidden" name="form" value="role"><input type="hidden" name="user_id" value="' . $rid . '">';
        echo '<select name="role" onchange="if(confirm(\'Сменить роль?\'))this.form.submit();" style="background:var(--sf2);color:var(--tx);border:1px solid var(--bd);border-radius:6px;padding:4px 8px;font-size:12px;">';
        foreach ($roles as $rk => $rl) {
            echo '<option value="' . $rk . '"' . ($row['role'] === $rk ? ' selected' : '') . '>' . $rl . '</option>';
        }
        echo '</select></form>';
    }
    echo '</td>';
    echo '<td><form method="post" action="/admin/users.php" style="display:inline;" onsubmit="return confirm(\'Сбросить пароль ' . esc($row['nickname']) . '?\');">' . csrf_field();
    echo '<input type="hidden" name="form" value="reset"><input type="hidden" name="user_id" value="' . $rid . '">';
    echo '<button class="btn btn-ghost" style="padding:4px 10px;font-size:12px;" type="submit">Сбросить пароль</button></form>';
    if ($isOwner && $rid !== (int)$u['id']) {
        echo ' <form method="post" action="/admin/users.php" style="display:inline;" onsubmit="return confirm(\'Удалить аккаунт ' . esc($row['nickname']) . '? Игрок и статистика останутся — удалится только вход.\');">' . csrf_field()
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
    . 'var on=d.on;btn.classList.toggle("tag-open",on);btn.textContent=(on?"✓ ":"+ ")+label;valInp.value=on?"0":"1";})'
    . '.catch(function(){btn.disabled=false;});});});})();</script>';

page_foot();
