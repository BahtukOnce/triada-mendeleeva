<?php
require dirname(__DIR__, 2) . '/inc/bootstrap.php';
$u = require_judge();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $form = (string)($_POST['form'] ?? '');
    $id = (int)($_POST['id'] ?? 0);

    if ($form === 'save') {
        $title = trim((string)($_POST['title'] ?? ''));
        if ($title === '') {
            flash_set('err', 'Название пустое');
            redirect('/admin/tournaments.php' . ($id ? '?edit=' . $id : ''));
        }
        $df = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['date_from'] ?? '')) ? $_POST['date_from'] : null;
        $dt = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['date_to'] ?? '')) ? $_POST['date_to'] : null;
        $loc = trim((string)($_POST['location'] ?? '')) ?: null;
        $desc = trim((string)($_POST['description'] ?? '')) ?: null;
        $status = in_array($_POST['status'] ?? '', ['draft', 'announced', 'reg_open', 'live', 'finished'], true) ? $_POST['status'] : 'draft';
        $tables = max(1, min(6, (int)($_POST['tables_count'] ?? 1)));

        if ($id) {
            db()->prepare('UPDATE tournaments SET title=?, date_from=?, date_to=?, location=?, description=?, status=?, tables_count=? WHERE id=?')
                ->execute([$title, $df, $dt, $loc, $desc, $status, $tables, $id]);
        } else {
            db()->prepare('INSERT INTO tournaments (title, date_from, date_to, location, description, status, tables_count) VALUES (?,?,?,?,?,?,?)')
                ->execute([$title, $df, $dt, $loc, $desc, $status, $tables]);
            $id = (int)db()->lastInsertId();
        }
        if (!empty($_FILES['logo']['name'])) {
            $res = save_image_upload($_FILES['logo'], 'tournaments', 't' . $id, 400);
            if (is_string($res) && str_starts_with($res, '/uploads/')) {
                db()->prepare('UPDATE tournaments SET logo = ? WHERE id = ?')->execute([$res, $id]);
            } else {
                flash_set('err', 'Лого: ' . $res);
            }
        }
        log_action((int)$u['id'], 'tournament_save', ['id' => $id]);
        flash_set('ok', 'Турнир сохранён');
        redirect('/admin/tournaments.php');
    }

    if ($form === 'delete' && $id) {
        db()->prepare('DELETE FROM tournaments WHERE id = ?')->execute([$id]);
        rating_recompute_all_safe();
        log_action((int)$u['id'], 'tournament_delete', ['id' => $id]);
        flash_set('ok', 'Турнир удалён');
        redirect('/admin/tournaments.php');
    }
    redirect('/admin/tournaments.php');
}

function rating_recompute_all_safe(): void
{
    require_once ROOT . '/inc/rating.php';
    rating_recompute_all();
}

$editId = (int)($_GET['edit'] ?? 0);
$edit = null;
if ($editId) {
    $st = db()->prepare('SELECT * FROM tournaments WHERE id = ?');
    $st->execute([$editId]);
    $edit = $st->fetch() ?: null;
}
$list = db_ready() ? db()->query('SELECT * FROM tournaments ORDER BY date_from DESC, id DESC')->fetchAll() : [];
$statusLabel = ['draft' => 'черновик', 'announced' => 'анонс', 'reg_open' => 'регистрация', 'live' => 'идёт', 'finished' => 'завершён'];

page_head('Админка — турниры', '');
echo '<p><a href="/admin/">← Админка</a></p><h1>Турниры</h1>';

echo '<div class="card"><h2 style="margin-top:0;">' . ($edit ? 'Редактировать: ' . esc($edit['title']) : 'Новый турнир') . '</h2>';
echo '<form method="post" action="/admin/tournaments.php" enctype="multipart/form-data">' . csrf_field();
echo '<input type="hidden" name="form" value="save"><input type="hidden" name="id" value="' . (int)($edit['id'] ?? 0) . '">';
echo '<div class="field"><label>Название</label><input type="text" name="title" required value="' . esc($edit['title'] ?? '') . '"></div>';
echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">';
echo '<div class="field"><label>Дата с</label><input type="date" name="date_from" value="' . esc($edit['date_from'] ?? '') . '"></div>';
echo '<div class="field"><label>Дата по</label><input type="date" name="date_to" value="' . esc($edit['date_to'] ?? '') . '"></div>';
echo '<div class="field"><label>Место</label><input type="text" name="location" value="' . esc($edit['location'] ?? '') . '"></div>';
echo '<div class="field"><label>Столов</label><input type="number" name="tables_count" min="1" max="6" value="' . (int)($edit['tables_count'] ?? 1) . '"></div>';
echo '</div>';
echo '<div class="field"><label>Статус</label><select name="status">';
foreach ($statusLabel as $sk => $sl) {
    echo '<option value="' . $sk . '" ' . (($edit['status'] ?? 'draft') === $sk ? 'selected' : '') . '>' . $sl . '</option>';
}
echo '</select></div>';
echo '<div class="field"><label>Описание</label><textarea name="description" rows="3">' . esc($edit['description'] ?? '') . '</textarea></div>';
echo '<div class="field"><label>Логотип турнира (PNG/JPG)</label>';
if (!empty($edit['logo'])) {
    echo '<div style="margin-bottom:6px;"><img src="' . esc($edit['logo']) . '" style="width:56px;height:56px;object-fit:contain;border-radius:8px;border:1px solid var(--bd);"></div>';
}
echo '<input type="file" name="logo" accept="image/*"></div>';
echo '<div style="display:flex;gap:10px;"><button class="btn" type="submit">Сохранить</button>';
if ($edit) {
    echo '<a class="btn btn-ghost" href="/admin/tournaments.php">Отмена</a>';
}
echo '</div></form></div>';

if ($list) {
    echo '<div class="card" style="overflow-x:auto;"><table class="tbl">';
    echo '<tr><th>Лого</th><th>Турнир</th><th>Статус</th><th class="num">Столов</th><th></th></tr>';
    foreach ($list as $t) {
        echo '<tr><td>' . (!empty($t['logo']) ? '<img src="' . esc($t['logo']) . '" style="width:32px;height:32px;object-fit:contain;border-radius:6px;">' : '—') . '</td>';
        echo '<td><a href="/tournament.php?id=' . (int)$t['id'] . '">' . esc($t['title']) . '</a></td>';
        echo '<td><span class="tag">' . ($statusLabel[$t['status']] ?? $t['status']) . '</span></td>';
        echo '<td class="num">' . (int)$t['tables_count'] . '</td>';
        echo '<td><a class="btn btn-ghost" style="padding:4px 10px;font-size:12px;" href="/admin/tournaments.php?edit=' . (int)$t['id'] . '">Изменить</a> ';
        echo '<form method="post" action="/admin/tournaments.php" style="display:inline;" onsubmit="return confirm(\'Удалить турнир и все его игры?\');">' . csrf_field();
        echo '<input type="hidden" name="form" value="delete"><input type="hidden" name="id" value="' . (int)$t['id'] . '">';
        echo '<button class="btn btn-ghost" style="padding:4px 10px;font-size:12px;color:var(--ac);" type="submit">Удалить</button></form></td></tr>';
    }
    echo '</table></div>';
}
page_foot();
