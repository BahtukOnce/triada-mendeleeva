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

    if ($form === 'approve') {
        $nick = nickname_clean((string)$app['nickname']);
        if ($nick === '') {
            flash_set('err', 'Пустой ник — исправьте заявку');
            redirect('/admin/applications.php');
        }
        // Игрок с таким ником уже есть? С аккаунтом — активация не нужна. Без аккаунта —
        // привязываемся к нему («своя история»). Иначе создаём нового игрока.
        $c = db()->prepare('SELECT id, user_id FROM players WHERE LOWER(nickname) = LOWER(?)');
        $c->execute([$nick]);
        $ex = $c->fetch();
        if ($ex && !empty($ex['user_id'])) {
            flash_set('err', 'У ника «' . $nick . '» уже есть аккаунт — активация не требуется.');
            redirect('/admin/applications.php');
        }
        if ($ex) {
            $pid = (int)$ex['id']; // существующий игрок (история) — аккаунт привяжется к нему
        } else {
            $isRhtu = $app['applicant_status'] !== 'Гость (не из РХТУ)' ? 1 : 0;
            db()->prepare('INSERT INTO players (nickname, real_name, tg, faculty, study_group, birth_date, status, is_rhtu, joined_at)
                VALUES (?,?,?,?,?,?,?,?, CURDATE())')
                ->execute([
                    $nick, $app['full_name'] ?: null, $app['tg_username'] ? '@' . ltrim((string)$app['tg_username'], '@') : null,
                    $app['faculty'] ?: null, $app['study_group'] ?: null, $app['birth_date'] ?: null,
                    $app['applicant_status'] ?: null, $isRhtu,
                ]);
            $pid = (int)db()->lastInsertId();
        }
        $token = bin2hex(random_bytes(20));
        db()->prepare('UPDATE club_applications SET state = \'approved\', player_id = ?, activation_token = ?, activated_at = NULL, processed_by = ?, processed_at = NOW() WHERE id = ?')
            ->execute([$pid, $token, (int)$u['id'], $id]);
        log_action((int)$u['id'], 'application_approve', ['id' => $id, 'player_id' => $pid, 'nick' => $nick, 'existing' => (bool)$ex]);
        flash_set('ok', 'Заявка принята. Отправьте новичку ссылку активации из карточки — по ней он задаст пароль и войдёт.');
        redirect('/admin/applications.php');
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
echo '<p style="color:var(--tx2);font-size:14px;margin-top:-6px;">Анкеты новых жителей с формы <a href="/join.php">«Вступить в клуб»</a>. «Принять» → создаётся игрок и ссылка активации: отправьте её новичку, он задаст пароль и войдёт.</p>';

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
    $srcTxt = (string)$a['source'];
    if (!empty($a['source_other'])) {
        $srcTxt = str_contains($srcTxt, 'Другое')
            ? str_replace('Другое', 'Другое (' . $a['source_other'] . ')', $srcTxt)
            : trim($srcTxt . ' · ' . $a['source_other']);
    }
    echo $row('Как узнал(а):', $srcTxt);
    echo $row('Дата рождения:', $a['birth_date'] ? date('d.m.Y', strtotime((string)$a['birth_date'])) : '');
    echo '</div>';

    // Ссылка активации (после принятия) — отправить новичку, чтобы задал пароль
    if ($a['state'] === 'approved' && !empty($a['activation_token']) && empty($a['activated_at'])) {
        $actLink = rtrim((string)cfg('base_url', 'https://triada-mendeleeva.ru'), '/') . '/activate.php?token=' . $a['activation_token'];
        echo '<div style="background:var(--sf2);border:1px solid var(--bd);border-radius:9px;padding:10px 12px;margin-bottom:10px;">'
            . '<div style="font-size:13px;color:var(--tx2);margin-bottom:6px;">🔗 Ссылка активации — отправьте новичку (по ней он задаст пароль и войдёт):</div>'
            . '<input readonly value="' . esc($actLink) . '" onclick="this.select();try{document.execCommand(\'copy\');}catch(e){}" '
            . 'title="кликните, чтобы выделить и скопировать" '
            . 'style="width:100%;box-sizing:border-box;background:var(--sf);color:var(--tx);border:1px solid var(--bd);border-radius:7px;padding:8px 10px;font-size:12.5px;cursor:pointer;">'
            . '</div>';
    } elseif (!empty($a['activated_at'])) {
        echo '<div style="font-size:13px;color:var(--ok);margin-bottom:10px;">✓ Аккаунт активирован ' . date('d.m.Y', strtotime((string)$a['activated_at'])) . '</div>';
    }

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
    if ($a['state'] !== 'approved') {
        echo '<form method="post" action="/admin/applications.php" onsubmit="return confirm(\'Принять заявку «' . esc(addslashes((string)$a['nickname'])) . '»? Будет создан игрок и ссылка активации аккаунта.\');">' . csrf_field()
            . '<input type="hidden" name="form" value="approve"><input type="hidden" name="id" value="' . (int)$a['id'] . '">'
            . '<button class="btn btn-ghost" style="padding:6px 12px;font-size:13px;color:var(--ok);" type="submit">✓ Принять заявку</button></form>';
    }
    echo '<form method="post" action="/admin/applications.php" onsubmit="return confirm(\'Удалить заявку?\');">' . csrf_field()
        . '<input type="hidden" name="form" value="delete"><input type="hidden" name="id" value="' . (int)$a['id'] . '">'
        . '<button class="btn btn-ghost" style="padding:6px 10px;font-size:12px;color:var(--ac);" type="submit">Удалить</button></form>';
    echo '</div>';
    echo '</div>';
}
page_foot();
