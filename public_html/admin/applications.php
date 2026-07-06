<?php
// Заявки на вступление в клуб — админы и руководитель (в бот заявка уходит руководителю).
require dirname(__DIR__, 2) . '/inc/bootstrap.php';
$u = require_role('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id = (int)($_POST['id'] ?? 0);
    $form = (string)($_POST['form'] ?? '');

    $st = db()->prepare('SELECT * FROM club_applications WHERE id = ?');
    $st->execute([$id]);
    $app = $st->fetch();
    if (!$app) {
        flash_set('err', 'Заявка не найдена');
        redirect('/admin/applications.php');
    }

    if ($form === 'update') {
        $state = (string)($_POST['state'] ?? 'new');
        $state = in_array($state, ['new', 'approved', 'rejected'], true) ? $state : 'new';
        db()->prepare('UPDATE club_applications SET state = ?, admin_note = ?, processed_by = ?, processed_at = NOW() WHERE id = ?')
            ->execute([$state, trim((string)($_POST['admin_note'] ?? '')) ?: null, (int)$u['id'], $id]);
        log_action((int)$u['id'], 'application_update', ['id' => $id, 'state' => $state]);
        flash_set('ok', 'Заявка обновлена');
        redirect('/admin/applications.php');
    }

    if ($form === 'delete') {
        db()->prepare('DELETE FROM club_applications WHERE id = ?')->execute([$id]);
        log_action((int)$u['id'], 'application_delete', ['id' => $id]);
        flash_set('ok', 'Заявка удалена');
        redirect('/admin/applications.php');
    }

    if ($form === 'make_player') {
        $nick = nickname_clean((string)$app['nickname']);
        if ($nick === '') {
            flash_set('err', 'Пустой ник — исправьте заявку');
            redirect('/admin/applications.php');
        }
        // Ник уже есть? Не создаём дубль (слияние — только вручную и осознанно).
        $c = db()->prepare('SELECT id FROM players WHERE LOWER(nickname) = LOWER(?)');
        $c->execute([$nick]);
        $existing = (int)($c->fetchColumn() ?: 0);
        if ($existing) {
            flash_set('err', 'Игрок с ником «' . $nick . '» уже есть (id ' . $existing . '). Создавать дубль нельзя — при необходимости свяжите вручную.');
            redirect('/admin/applications.php');
        }
        $isRhtu = $app['applicant_status'] !== 'Гость (не из РХТУ)' ? 1 : 0;
        db()->prepare('INSERT INTO players (nickname, real_name, tg, faculty, study_group, birth_date, status, is_rhtu, joined_at)
            VALUES (?,?,?,?,?,?,?,?, CURDATE())')
            ->execute([
                $nick, $app['full_name'] ?: null, $app['tg_username'] ? '@' . ltrim((string)$app['tg_username'], '@') : null,
                $app['faculty'] ?: null, $app['study_group'] ?: null, $app['birth_date'] ?: null,
                $app['applicant_status'] ?: null, $isRhtu,
            ]);
        $pid = (int)db()->lastInsertId();
        db()->prepare('UPDATE club_applications SET state = \'approved\', player_id = ?, processed_by = ?, processed_at = NOW() WHERE id = ?')
            ->execute([$pid, (int)$u['id'], $id]);
        log_action((int)$u['id'], 'application_make_player', ['id' => $id, 'player_id' => $pid, 'nick' => $nick]);
        flash_set('ok', 'Игрок «' . $nick . '» создан. Заявка помечена принятой.');
        redirect('/player.php?id=' . $pid);
    }
    redirect('/admin/applications.php');
}

$list = db()->query("SELECT * FROM club_applications
    ORDER BY FIELD(state,'new','approved','rejected'), created_at DESC LIMIT 300")->fetchAll();
$newCount = 0;
foreach ($list as $a) {
    if ($a['state'] === 'new') {
        $newCount++;
    }
}
$stateLabel = ['new' => 'новая', 'approved' => 'принята', 'rejected' => 'отклонена'];
$stateTag = ['new' => 'tag-open', 'approved' => 'tag-ok', 'rejected' => ''];

page_head('Админка — заявки в клуб', '');
echo '<p><a href="/admin/">← Админка</a></p><h1>Заявки на вступление' . ($newCount ? ' <span class="tag tag-open">новых: ' . $newCount . '</span>' : '') . '</h1>';
echo '<p style="color:var(--tx2);font-size:14px;margin-top:-6px;">Анкеты новых жителей с формы <a href="/join.php">«Вступить в клуб»</a>. Можно принять и сразу создать игрока, отклонить или оставить заметку.</p>';

if (!$list) {
    empty_state('Заявок пока нет', 'Когда кто-то заполнит форму вступления, анкета появится здесь.');
    page_foot();
    exit;
}

$row = function (string $lbl, ?string $val): string {
    if ($val === null || $val === '') {
        return '';
    }
    return '<div style="display:flex;gap:8px;padding:3px 0;"><span style="color:var(--tx2);min-width:130px;flex:none;font-size:13px;">' . $lbl . '</span><span>' . esc($val) . '</span></div>';
};

foreach ($list as $a) {
    $tg = '@' . ltrim((string)$a['tg_username'], '@');
    echo '<div class="card">';
    echo '<div style="display:flex;justify-content:space-between;gap:10px;align-items:center;margin-bottom:8px;flex-wrap:wrap;">';
    echo '<div><b style="font-size:17px;">' . esc($a['nickname']) . '</b> <span style="color:var(--tx2);">· ' . esc($a['full_name']) . '</span>'
        . ($a['player_id'] ? ' <a class="tag tag-ok" href="/player.php?id=' . (int)$a['player_id'] . '">игрок создан</a>' : '') . '</div>';
    echo '<span class="tag ' . $stateTag[$a['state']] . '">' . $stateLabel[$a['state']] . ' · ' . date('d.m.Y H:i', strtotime($a['created_at'])) . '</span>';
    echo '</div>';

    echo '<div style="margin-bottom:10px;">';
    echo $row('Telegram:', $tg);
    echo $row('Статус:', $a['applicant_status']);
    echo $row('Факультет:', $a['faculty'] . ($a['study_group'] ? ' · группа ' . $a['study_group'] : ''));
    echo $row('Опыт игры:', $a['experience']);
    echo $row('Как узнал(а):', $a['source'] === 'Другое' ? ('Другое — ' . ($a['source_other'] ?: '—')) : $a['source']);
    echo $row('Дата рождения:', $a['birth_date'] ? date('d.m.Y', strtotime((string)$a['birth_date'])) : '');
    echo '</div>';

    // Действия
    echo '<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;border-top:1px solid var(--bd);padding-top:10px;">';
    echo '<form method="post" action="/admin/applications.php" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;flex:1;min-width:260px;">' . csrf_field();
    echo '<input type="hidden" name="form" value="update"><input type="hidden" name="id" value="' . (int)$a['id'] . '">';
    echo '<select name="state" style="background:var(--sf2);color:var(--tx);border:1px solid var(--bd);border-radius:7px;padding:6px 10px;">';
    foreach ($stateLabel as $sk => $sl) {
        echo '<option value="' . $sk . '"' . ($a['state'] === $sk ? ' selected' : '') . '>' . $sl . '</option>';
    }
    echo '</select>';
    echo '<input type="text" name="admin_note" placeholder="заметка (необязательно)" value="' . esc((string)$a['admin_note']) . '" style="flex:1;min-width:160px;background:var(--sf2);color:var(--tx);border:1px solid var(--bd);border-radius:7px;padding:6px 10px;">';
    echo '<button class="btn" style="padding:6px 14px;font-size:13px;" type="submit">Сохранить</button>';
    echo '</form>';
    if (!$a['player_id']) {
        echo '<form method="post" action="/admin/applications.php" onsubmit="return confirm(\'Создать игрока «' . esc(addslashes((string)$a['nickname'])) . '» из этой заявки?\');">' . csrf_field()
            . '<input type="hidden" name="form" value="make_player"><input type="hidden" name="id" value="' . (int)$a['id'] . '">'
            . '<button class="btn btn-ghost" style="padding:6px 12px;font-size:13px;color:var(--ok);" type="submit">✓ Принять и создать игрока</button></form>';
    }
    echo '<form method="post" action="/admin/applications.php" onsubmit="return confirm(\'Удалить заявку?\');">' . csrf_field()
        . '<input type="hidden" name="form" value="delete"><input type="hidden" name="id" value="' . (int)$a['id'] . '">'
        . '<button class="btn btn-ghost" style="padding:6px 10px;font-size:12px;color:var(--ac);" type="submit">Удалить</button></form>';
    echo '</div>';
    echo '</div>';
}
page_foot();
