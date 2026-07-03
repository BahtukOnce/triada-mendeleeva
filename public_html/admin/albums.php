<?php
require dirname(__DIR__, 2) . '/inc/bootstrap.php';
$u = require_photo();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $form = (string)($_POST['form'] ?? '');

    if ($form === 'create') {
        $title = trim((string)($_POST['title'] ?? ''));
        if ($title === '') {
            flash_set('err', 'Название пустое');
            redirect('/admin/albums.php');
        }
        $dayId = (int)($_POST['day_id'] ?? 0) ?: null;
        $tId = (int)($_POST['tournament_id'] ?? 0) ?: null;
        db()->prepare('INSERT INTO albums (title, day_id, tournament_id, created_by) VALUES (?,?,?,?)')
            ->execute([$title, $dayId, $tId, (int)$u['id']]);
        log_action((int)$u['id'], 'album_create', ['title' => $title]);
        flash_set('ok', 'Альбом создан — загрузите фото');
        redirect('/admin/albums.php?album=' . (int)db()->lastInsertId());
    }

    if ($form === 'upload') {
        $albumId = (int)($_POST['album_id'] ?? 0);
        $count = 0;
        $files = $_FILES['photos'] ?? null;
        if ($files && is_array($files['name'])) {
            $n = count($files['name']);
            for ($i = 0; $i < $n && $count < 60; $i++) {
                $one = [
                    'name' => $files['name'][$i], 'tmp_name' => $files['tmp_name'][$i],
                    'error' => $files['error'][$i], 'size' => $files['size'][$i],
                ];
                if ($one['error'] !== UPLOAD_ERR_OK) {
                    continue;
                }
                $base = 'a' . $albumId . '_' . time() . '_' . $i;
                $ext = strtolower(pathinfo($one['name'], PATHINFO_EXTENSION));
                if (in_array($ext, ['mp4', 'webm', 'mov', 'm4v'], true)) {
                    // Видео — сохраняем как есть
                    if ($one['size'] > 200 * 1024 * 1024) {
                        continue;
                    }
                    $dir = ROOT . '/public_html/uploads/photos';
                    if (!is_dir($dir)) {
                        @mkdir($dir, 0755, true);
                    }
                    uploads_harden();
                    $rel = '/uploads/photos/' . $base . '.' . ($ext === 'mov' ? 'mp4' : $ext);
                    if (!move_uploaded_file($one['tmp_name'], ROOT . '/public_html' . $rel)) {
                        continue;
                    }
                    db()->prepare('INSERT INTO photos (album_id, file, thumb, kind, uploaded_by) VALUES (?,?,?,?,?)')
                        ->execute([$albumId, $rel, null, 'video', (int)$u['id']]);
                    $count++;
                    continue;
                }
                $full = save_image_upload($one, 'photos', $base, 1920);
                if (!is_string($full) || !str_starts_with($full, '/uploads/')) {
                    continue;
                }
                $thumb = save_image_upload($one, 'photos/thumbs', $base, 420);
                db()->prepare('INSERT INTO photos (album_id, file, thumb, kind, uploaded_by) VALUES (?,?,?,?,?)')
                    ->execute([$albumId, $full, is_string($thumb) ? $thumb : $full, 'image', (int)$u['id']]);
                $count++;
            }
        }
        $cover = db()->prepare('SELECT id FROM photos WHERE album_id = ? ORDER BY id LIMIT 1');
        $cover->execute([$albumId]);
        $cid = (int)$cover->fetchColumn();
        if ($cid) {
            db()->prepare('UPDATE albums SET cover_photo_id = COALESCE(cover_photo_id, ?) WHERE id = ?')
                ->execute([$cid, $albumId]);
        }
        log_action((int)$u['id'], 'photos_upload', ['album_id' => $albumId, 'count' => $count]);
        flash_set('ok', "Загружено в альбом: $count");
        redirect('/admin/albums.php?album=' . $albumId);
    }
    redirect('/admin/albums.php');
}

$days = db_ready() ? db()->query('SELECT id, title, date FROM game_days ORDER BY date DESC LIMIT 40')->fetchAll() : [];
$tours = db_ready() ? db()->query('SELECT id, title FROM tournaments ORDER BY id DESC LIMIT 20')->fetchAll() : [];
$albums = db_ready() ? db()->query('SELECT a.*, (SELECT COUNT(*) FROM photos p WHERE p.album_id = a.id) AS cnt
    FROM albums a ORDER BY a.created_at DESC')->fetchAll() : [];
$cur = (int)($_GET['album'] ?? 0);

page_head('Админка — фотоальбомы', '');
echo '<p><a href="/admin/">← Админка</a></p><h1>Фотоальбомы</h1>';

echo '<div class="card"><h2 style="margin-top:0;">Создать альбом</h2>';
echo '<form method="post" action="/admin/albums.php">' . csrf_field() . '<input type="hidden" name="form" value="create">';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap;align-items:end;">';
echo '<div class="field" style="margin:0;flex:1;min-width:180px;"><label>Название</label><input type="text" name="title" required placeholder="Вечер 14 июня"></div>';
echo '<div class="field" style="margin:0;"><label>Привязать к вечеру</label><select name="day_id"><option value="0">—</option>';
foreach ($days as $d) {
    echo '<option value="' . (int)$d['id'] . '">' . esc($d['title'] . ' (' . date('d.m.y', strtotime($d['date'])) . ')') . '</option>';
}
echo '</select></div>';
echo '<div class="field" style="margin:0;"><label>или к турниру</label><select name="tournament_id"><option value="0">—</option>';
foreach ($tours as $t) {
    echo '<option value="' . (int)$t['id'] . '">' . esc($t['title']) . '</option>';
}
echo '</select></div>';
echo '<button class="btn" type="submit">Создать</button></div></form></div>';

foreach ($albums as $a) {
    echo '<div class="card">';
    echo '<div class="section-head"><h2 style="margin:0;">' . esc($a['title']) . '</h2><span class="tag">' . (int)$a['cnt'] . ' фото</span></div>';
    echo '<form method="post" action="/admin/albums.php" enctype="multipart/form-data" style="margin-top:10px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;">' . csrf_field();
    echo '<input type="hidden" name="form" value="upload"><input type="hidden" name="album_id" value="' . (int)$a['id'] . '">';
    echo '<input type="file" name="photos[]" multiple accept="image/*,video/*" required>';
    echo '<button class="btn" type="submit">Загрузить фото / видео</button>';
    echo '<a href="/album.php?id=' . (int)$a['id'] . '" style="font-size:13px;">смотреть альбом →</a></form>';
    echo '</div>';
}
page_foot();
