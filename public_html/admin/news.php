<?php
require dirname(__DIR__, 2) . '/inc/bootstrap.php';
$u = require_role('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $form = (string)($_POST['form'] ?? '');
    $id = (int)($_POST['id'] ?? 0);

    if ($form === 'save') {
        $title = trim((string)($_POST['title'] ?? ''));
        $body = trim((string)($_POST['body'] ?? ''));
        $publish = !empty($_POST['publish']);
        $pinned = !empty($_POST['pinned']) ? 1 : 0;
        if ($title === '') {
            flash_set('err', 'Заголовок пуст');
            redirect('/admin/news.php' . ($id ? "?edit=$id" : ''));
        }
        if ($id) {
            db()->prepare('UPDATE news SET title = ?, body = ?, pinned = ?,
                published_at = IF(? AND published_at IS NULL, NOW(), IF(?, published_at, NULL)) WHERE id = ?')
                ->execute([$title, $body, $pinned, $publish ? 1 : 0, $publish ? 1 : 0, $id]);
        } else {
            db()->prepare('INSERT INTO news (title, body, author_id, pinned, published_at) VALUES (?,?,?,?,?)')
                ->execute([$title, $body, (int)$u['id'], $pinned, $publish ? date('Y-m-d H:i:s') : null]);
        }
        log_action((int)$u['id'], 'news_save', ['id' => $id]);
        flash_set('ok', 'Сохранено');
        redirect('/admin/news.php');
    }

    if ($form === 'delete' && $id) {
        db()->prepare('DELETE FROM news WHERE id = ?')->execute([$id]);
        log_action((int)$u['id'], 'news_delete', ['id' => $id]);
        flash_set('ok', 'Удалено');
        redirect('/admin/news.php');
    }
    redirect('/admin/news.php');
}

$editId = (int)($_GET['edit'] ?? 0);
$edit = null;
if ($editId && db_ready()) {
    $st = db()->prepare('SELECT * FROM news WHERE id = ?');
    $st->execute([$editId]);
    $edit = $st->fetch() ?: null;
}
$list = db_ready() ? db()->query('SELECT * FROM news ORDER BY created_at DESC LIMIT 100')->fetchAll() : [];

page_head('Админка — новости', '');
echo '<p><a href="/admin/">← Админка</a></p><h1>Новости</h1>';

$newsChan = (string)cfg('news_channel_id', 'triada_mendeleeva');
if ($newsChan === '') { $newsChan = 'triada_mendeleeva'; }
echo '<p style="margin:-6px 0 14px;"><a class="btn" href="/import_news.php?pages=30" target="_blank" rel="noopener">⬇ Импортировать из Telegram</a> '
    . '<span style="color:var(--tx2);font-size:13px;">читает посты канала @' . esc($newsChan) . ' и добавляет их сюда (можно запускать повторно — дубликатов не будет)</span></p>';

echo '<div class="card"><h2 style="margin-top:0;">' . ($edit ? 'Редактировать' : 'Новая новость') . '</h2>';
echo '<form method="post" action="/admin/news.php">' . csrf_field();
echo '<input type="hidden" name="form" value="save"><input type="hidden" name="id" value="' . (int)($edit['id'] ?? 0) . '">';
echo '<div class="field"><label>Заголовок</label><input type="text" name="title" required value="' . esc($edit['title'] ?? '') . '"></div>';
echo '<div class="field"><label>Текст</label><textarea name="body" rows="7">' . esc($edit['body'] ?? '') . '</textarea></div>';
$pub = $edit ? $edit['published_at'] !== null : true;
echo '<label style="font-size:14px;margin-right:14px;"><input type="checkbox" name="publish" ' . ($pub ? 'checked' : '') . '> опубликована</label>';
echo '<label style="font-size:14px;"><input type="checkbox" name="pinned" ' . (!empty($edit['pinned']) ? 'checked' : '') . '> закрепить</label>';
echo '<div style="margin-top:12px;"><button class="btn" type="submit">Сохранить</button>';
if ($edit) {
    echo ' <a class="btn btn-ghost" href="/admin/news.php">Отмена</a>';
}
echo '</div></form></div>';

if ($list) {
    echo '<div class="card"><table class="tbl"><tr><th>Заголовок</th><th>Статус</th><th>Создана</th><th></th></tr>';
    foreach ($list as $n) {
        echo '<tr><td>' . esc($n['title']) . ($n['pinned'] ? ' <span class="tag">закреп</span>' : '') . '</td>';
        echo '<td>' . ($n['published_at'] ? '<span class="tag tag-ok">опубликована</span>' : '<span class="tag">черновик</span>') . '</td>';
        echo '<td>' . date('d.m.Y', strtotime($n['created_at'])) . '</td><td>';
        echo '<a class="btn btn-ghost" style="padding:4px 10px;font-size:12px;" href="/admin/news.php?edit=' . (int)$n['id'] . '">Изменить</a> ';
        echo '<form method="post" action="/admin/news.php" style="display:inline;" onsubmit="return confirm(\'Удалить новость?\');">' . csrf_field();
        echo '<input type="hidden" name="form" value="delete"><input type="hidden" name="id" value="' . (int)$n['id'] . '">';
        echo '<button class="btn btn-ghost" style="padding:4px 10px;font-size:12px;color:var(--ac);" type="submit">Удалить</button></form>';
        echo '</td></tr>';
    }
    echo '</table></div>';
}
page_foot();
