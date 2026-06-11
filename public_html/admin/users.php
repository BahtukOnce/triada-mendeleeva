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

    if ($form === 'role') {
        if (!$isOwner) {
            flash_set('err', 'Менять роли может только глава клуба');
            redirect('/admin/users.php');
        }
        $newRole = (string)($_POST['role'] ?? '');
        if (!in_array($newRole, ['player', 'judge', 'admin', 'owner'], true)) {
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
    redirect('/admin/users.php');
}

$q = trim((string)($_GET['q'] ?? ''));
$onlyTg = !empty($_GET['tg']);
$sql = 'SELECT us.*, p.id AS player_id, p.nickname AS player_nick FROM users us
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

page_head('Админка — пользователи', '');
echo '<p><a href="/admin/">← Админка</a></p><h1>Пользователи и роли</h1>';
if (!$isOwner) {
    echo '<p style="color:var(--tx2);font-size:13px;">Сброс пароля доступен админам. Менять роли может только глава клуба.</p>';
}

echo '<div style="display:flex;gap:10px;align-items:center;margin-bottom:14px;flex-wrap:wrap;">';
echo '<form method="get" action="/admin/users.php" style="max-width:300px;flex:1;min-width:200px;">';
echo '<div class="field" style="margin:0;"><input type="search" name="q" placeholder="Поиск по нику аккаунта" value="' . esc($q) . '"></div></form>';
echo '<a class="tag ' . ($onlyTg ? 'tag-open' : '') . '" href="/admin/users.php' . ($onlyTg ? '' : '?tg=1') . '">'
    . ($onlyTg ? 'показать всех' : 'только с Telegram') . '</a>';
echo '</div>';

$roles = ['player' => 'игрок', 'judge' => 'судья', 'admin' => 'админ', 'owner' => 'глава'];
echo '<div class="card" style="overflow-x:auto;"><table class="tbl">';
echo '<tr><th>Аккаунт</th><th>Игрок</th><th>Telegram</th><th>Роль</th><th>Действия</th></tr>';
foreach ($list as $row) {
    $rid = (int)$row['id'];
    echo '<tr><td>' . esc($row['nickname']) . '</td>';
    echo '<td>' . ($row['player_id']
        ? '<a href="/player.php?id=' . (int)$row['player_id'] . '">' . esc($row['player_nick']) . '</a>'
        : '<span style="color:var(--tx2);">—</span>') . '</td>';
    echo '<td>' . ($row['tg_user_id']
        ? '<span class="tag tag-ok">' . ($row['tg_username'] ? '@' . esc($row['tg_username']) : 'привязан') . '</span>'
        : '<span style="color:var(--tx3);">—</span>') . '</td>';
    echo '<td>' . ($row['role'] === 'owner' || $row['role'] === 'admin'
        ? '<span class="tag" style="color:var(--ac);">' . $roles[$row['role']] . '</span>'
        : $roles[$row['role']]) . '</td>';
    echo '<td style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;">';

    echo '<form method="post" action="/admin/users.php" style="display:inline;" onsubmit="return confirm(\'Сбросить пароль ' . esc($row['nickname']) . '?\');">' . csrf_field();
    echo '<input type="hidden" name="form" value="reset"><input type="hidden" name="user_id" value="' . $rid . '">';
    echo '<button class="btn btn-ghost" style="padding:4px 10px;font-size:12px;" type="submit">Сбросить пароль</button></form>';

    if ($isOwner && $rid !== (int)$u['id']) {
        echo '<form method="post" action="/admin/users.php" style="display:inline;">' . csrf_field();
        echo '<input type="hidden" name="form" value="role"><input type="hidden" name="user_id" value="' . $rid . '">';
        echo '<select name="role" onchange="if(confirm(\'Сменить роль?\'))this.form.submit();" style="background:var(--sf2);color:var(--tx);border:1px solid var(--bd);border-radius:6px;padding:4px 8px;font-size:12px;">';
        foreach ($roles as $rk => $rl) {
            echo '<option value="' . $rk . '"' . ($row['role'] === $rk ? ' selected' : '') . '>' . $rl . '</option>';
        }
        echo '</select></form>';
    }
    echo '</td></tr>';
}
echo '</table></div>';
page_foot();
