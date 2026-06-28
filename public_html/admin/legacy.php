<?php
require dirname(__DIR__, 2) . '/inc/bootstrap.php';
$u = require_role('admin');
require_once ROOT . '/inc/legacy_import.php';

$log = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'import') {
        $log = legacy_import_run();
        flash_set('ok', 'Импорт выполнен');
    } elseif ($action === 'import_games') {
        $log = legacy_days_import_run();
        flash_set('ok', 'Игровые вечера импортированы, ELO пересчитан');
    } elseif ($action === 'clear') {
        $old = db()->query('SELECT id FROM ratings WHERE is_frozen = 1')->fetchAll(PDO::FETCH_COLUMN);
        if ($old) {
            $in = implode(',', array_map('intval', $old));
            db()->exec("DELETE FROM rating_cache WHERE rating_id IN ($in)");
            db()->exec("DELETE FROM ratings WHERE id IN ($in)");
        }
        flash_set('ok', 'Импортированные рейтинги удалены');
        redirect('/admin/legacy.php');
    }
}

$dir = ROOT . '/storage/legacy';
$files = is_dir($dir) ? array_map('basename', glob("$dir/*.json")) : [];
$frozen = db_ready() ? db()->query("SELECT r.id, r.title,
    (SELECT COUNT(*) FROM rating_cache rc WHERE rc.rating_id = r.id) c
    FROM ratings r WHERE r.is_frozen = 1 ORDER BY r.id")->fetchAll() : [];

page_head('Импорт истории', '');
echo '<p><a href="/admin/">← Админка</a></p><h1>Импорт исторических рейтингов</h1>';

echo '<div class="card"><h2 style="margin-top:0;">Загруженные данные (скрейп)</h2>';
echo $files
    ? '<p style="color:var(--tx2);">Файлы: ' . esc(implode(', ', $files)) . '</p>'
    : '<p style="color:var(--tx2);">Нет файлов в storage/legacy.</p>';
echo '<form method="post">' . csrf_field()
    . '<input type="hidden" name="action" value="import">'
    . '<button class="btn" type="submit">Импортировать всё</button></form>';
echo '<p style="color:var(--tx3);font-size:12px;margin:10px 0 0;">Импорт идемпотентен: пересоздаёт замороженные рейтинги заново, основной рейтинг не трогает.</p>';
echo '</div>';

echo '<div class="card"><h2 style="margin-top:0;">Поигровые данные → вечера + ELO</h2>';
echo '<p style="color:var(--tx2);">Загружает исторические игры (<code>games_*.json</code>) как настоящие игровые вечера с реальными датами и пересчитывает ELO по всей истории.</p>';
echo '<form method="post">' . csrf_field()
    . '<input type="hidden" name="action" value="import_games">'
    . '<button class="btn" type="submit">Импортировать вечера + пересчитать ELO</button></form>';
echo '<p style="color:var(--tx3);font-size:12px;margin:10px 0 0;">Исторические вечера помечены сезоном и не привязаны к рейтингам — основной рейтинг остаётся текущим; идут в статистику, профили, рекорды и ELO.</p>';
echo '</div>';

if ($frozen) {
    echo '<div class="card"><h2 style="margin-top:0;">Импортированные рейтинги</h2><table class="tbl"><tr><th>#</th><th>Название</th><th class="num">Игроков</th></tr>';
    foreach ($frozen as $f) {
        echo '<tr><td>' . (int)$f['id'] . '</td><td>' . esc($f['title']) . '</td><td class="num">' . (int)$f['c'] . '</td></tr>';
    }
    echo '</table>';
    echo '<form method="post" onsubmit="return confirm(\'Удалить все импортированные рейтинги?\');" style="margin-top:10px;">' . csrf_field()
        . '<input type="hidden" name="action" value="clear">'
        . '<button class="btn btn-ghost" style="color:var(--ac);" type="submit">Удалить импортированные</button></form>';
    echo '</div>';
}

if ($log) {
    echo '<div class="card"><h2 style="margin-top:0;">Лог</h2><pre style="white-space:pre-wrap;font-size:13px;margin:0;">' . esc(implode("\n", $log)) . '</pre></div>';
}
page_foot();
