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

        // Места столов: позиционный массив длиной tables_count (индекс = стол − 1)
        $tp = (array)($_POST['table_places'] ?? []);
        $places = [];
        for ($i = 0; $i < $tables; $i++) {
            $places[] = trim((string)($tp[$i] ?? ''));
        }
        $placesJson = array_filter($places) ? json_encode($places, JSON_UNESCAPED_UNICODE) : null;

        // Судьи: главный + по столам (позиционный массив id, индекс = стол − 1)
        $mainJudge = (int)($_POST['main_judge'] ?? 0) ?: null;
        $tj = (array)($_POST['table_judges'] ?? []);
        $judges = [];
        for ($i = 0; $i < $tables; $i++) {
            $judges[] = (int)($tj[$i] ?? 0) ?: null;
        }
        $judgesJson = array_filter($judges) ? json_encode($judges) : null;

        if ($id) {
            db()->prepare('UPDATE tournaments SET title=?, date_from=?, date_to=?, location=?, description=?, status=?, tables_count=?, table_places=?, main_judge_player_id=?, table_judges=? WHERE id=?')
                ->execute([$title, $df, $dt, $loc, $desc, $status, $tables, $placesJson, $mainJudge, $judgesJson, $id]);
        } else {
            db()->prepare('INSERT INTO tournaments (title, date_from, date_to, location, description, status, tables_count, table_places, main_judge_player_id, table_judges) VALUES (?,?,?,?,?,?,?,?,?,?)')
                ->execute([$title, $df, $dt, $loc, $desc, $status, $tables, $placesJson, $mainJudge, $judgesJson]);
            $id = (int)db()->lastInsertId();
        }
        $cropped = (string)($_POST['logo_cropped'] ?? '');
        if (preg_match('#^data:image/(jpeg|png);base64,#', $cropped)) {
            // Логотип из мини-редактора (обрезка под круг)
            $bin = base64_decode(substr($cropped, strpos($cropped, ',') + 1), true);
            if ($bin !== false && strlen($bin) > 100 && strlen($bin) < 8 * 1024 * 1024) {
                $dir = ROOT . '/public_html/uploads/tournaments';
                if (!is_dir($dir)) {
                    @mkdir($dir, 0775, true);
                }
                $base = 't' . $id . '_' . time();
                $rel = '/uploads/tournaments/' . $base . '.jpg';
                if (@file_put_contents($dir . '/' . $base . '.jpg', $bin) !== false) {
                    db()->prepare('UPDATE tournaments SET logo = ? WHERE id = ?')->execute([$rel, $id]);
                } else {
                    flash_set('err', 'Лого: не удалось сохранить');
                }
            } else {
                flash_set('err', 'Лого: картинка не распозналась');
            }
        } elseif (!empty($_FILES['logo']['name'])) {
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

// Места столов: по одному полю на стол (по числу «Столов»)
$tplaces = [];
if (!empty($edit['table_places'])) {
    $dec = json_decode((string)$edit['table_places'], true);
    if (is_array($dec)) {
        $tplaces = $dec;
    }
}
$tcount = max(1, (int)($edit['tables_count'] ?? 1));
echo '<div class="field"><label>Места столов</label>';
echo '<div style="display:grid;grid-template-columns:repeat(2,1fr);gap:8px;">';
for ($i = 0; $i < $tcount; $i++) {
    echo '<input type="text" name="table_places[]" placeholder="Стол ' . ($i + 1) . ' — место" value="' . esc($tplaces[$i] ?? '') . '">';
}
echo '</div>';
echo '<p style="color:var(--tx3);font-size:12px;margin:6px 0 0;">Полей столько же, сколько «Столов». Изменишь число — сохрани и снова открой турнир, поля обновятся.</p></div>';

// Судьи турнира: главный + по столам
$tjudges = [];
if (!empty($edit['table_judges'])) {
    $decj = json_decode((string)$edit['table_judges'], true);
    if (is_array($decj)) {
        $tjudges = $decj;
    }
}
$allPlayers = db_ready() ? db()->query('SELECT id, nickname FROM players ORDER BY nickname')->fetchAll() : [];
$judgeSelect = function (string $name, int $sel) use ($allPlayers): string {
    $h = '<select name="' . $name . '"><option value="0">— не назначен —</option>';
    foreach ($allPlayers as $p) {
        $h .= '<option value="' . (int)$p['id'] . '"' . ((int)$p['id'] === $sel ? ' selected' : '') . '>' . esc($p['nickname']) . '</option>';
    }
    return $h . '</select>';
};
echo '<div class="field"><label>Главный судья <span style="color:var(--tx3);font-weight:400;">(по умолчанию судит стол 1)</span></label>'
    . $judgeSelect('main_judge', (int)($edit['main_judge_player_id'] ?? 0)) . '</div>';
echo '<div class="field"><label>Судьи столов</label>';
echo '<div style="display:grid;grid-template-columns:repeat(2,1fr);gap:10px;">';
for ($i = 0; $i < $tcount; $i++) {
    echo '<div><div style="font-size:12px;color:var(--tx2);margin-bottom:3px;">Стол ' . ($i + 1) . ($i === 0 ? ' (главный)' : '') . '</div>'
        . $judgeSelect('table_judges[]', (int)($tjudges[$i] ?? 0)) . '</div>';
}
echo '</div>';
echo '<p style="color:var(--tx3);font-size:12px;margin:6px 0 0;">Стол 1 по умолчанию судит главный судья — можно оставить «не назначен».</p></div>';

echo '<div class="field"><label>Статус</label><select name="status">';
foreach ($statusLabel as $sk => $sl) {
    echo '<option value="' . $sk . '" ' . (($edit['status'] ?? 'draft') === $sk ? 'selected' : '') . '>' . $sl . '</option>';
}
echo '</select></div>';
echo '<div class="field"><label>Описание</label><textarea name="description" rows="3">' . esc($edit['description'] ?? '') . '</textarea></div>';
echo '<div class="field"><label>Логотип турнира (PNG/JPG) — откроется мини-редактор, кадрируй под круг</label>';
$curLogo = !empty($edit['logo']) ? esc($edit['logo']) : '';
echo '<div style="display:flex;align-items:center;gap:14px;">';
echo '<img id="logo-preview" src="' . $curLogo . '" alt="" style="width:84px;height:84px;border-radius:50%;object-fit:cover;border:2px solid var(--bd);background:var(--sf2);' . ($curLogo === '' ? 'display:none;' : '') . '">';
echo '<div style="flex:1;min-width:0;">';
echo '<input type="file" name="logo" id="logo-file" accept="image/*">';
echo '<input type="hidden" name="logo_cropped" id="logo-cropped">';
echo '<div id="logo-hint" style="color:var(--tx3);font-size:12px;margin-top:6px;">Выбери файл — откроется обрезка, увидишь, что именно загрузилось.</div>';
echo '</div></div></div>';
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
?>
<link href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.1/cropper.min.css" rel="stylesheet">
<div id="crop-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.82);z-index:1000;align-items:center;justify-content:center;padding:16px;">
  <div style="background:var(--sf);border:1px solid var(--bd);border-radius:14px;padding:18px;max-width:520px;width:100%;">
    <h3 style="margin:0 0 12px;">Логотип турнира — кадрируй под круг</h3>
    <div style="max-height:60vh;overflow:hidden;"><img id="crop-img" style="max-width:100%;display:block;"></div>
    <div style="display:flex;gap:10px;margin-top:14px;justify-content:flex-end;">
      <button type="button" class="btn btn-ghost" id="crop-cancel">Отмена</button>
      <button type="button" class="btn" id="crop-ok">Применить</button>
    </div>
  </div>
</div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.1/cropper.min.js"></script>
<script>
(function () {
  var fileInput = document.getElementById('logo-file'),
      modal = document.getElementById('crop-modal'),
      img = document.getElementById('crop-img'), cropper = null;
  if (!fileInput || !modal) return;
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
    var data = canvas.toDataURL('image/jpeg', 0.9);
    document.getElementById('logo-cropped').value = data;
    var pv = document.getElementById('logo-preview'); if (pv) { pv.src = data; pv.style.display = ''; }
    var h = document.getElementById('logo-hint'); if (h) { h.textContent = 'Обрезано ✓ — нажми «Сохранить».'; }
    modal.style.display = 'none'; if (cropper) { cropper.destroy(); cropper = null; }
  });
})();
</script>
<?php
page_foot();
