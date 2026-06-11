<?php
require dirname(__DIR__, 2) . '/inc/bootstrap.php';
$u = require_role('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id = (int)($_POST['id'] ?? 0);
    $form = (string)($_POST['form'] ?? '');
    if ($form === 'update') {
        $status = (string)($_POST['status'] ?? 'new');
        $status = in_array($status, ['new', 'planned', 'done', 'declined'], true) ? $status : 'new';
        db()->prepare('UPDATE suggestions SET status = ?, admin_note = ? WHERE id = ?')
            ->execute([$status, trim((string)($_POST['admin_note'] ?? '')) ?: null, $id]);
        log_action((int)$u['id'], 'suggestion_update', ['id' => $id, 'status' => $status]);
        flash_set('ok', 'Обновлено');
    } elseif ($form === 'delete') {
        db()->prepare('DELETE FROM suggestions WHERE id = ?')->execute([$id]);
        log_action((int)$u['id'], 'suggestion_delete', ['id' => $id]);
        flash_set('ok', 'Удалено');
    }
    redirect('/admin/suggestions.php');
}

$list = db_ready() ? db()->query('SELECT * FROM suggestions ORDER BY FIELD(status,\'new\',\'planned\',\'done\',\'declined\'), created_at DESC LIMIT 200')->fetchAll() : [];
$statusLabel = ['new' => 'на рассмотрении', 'planned' => 'в планах', 'done' => 'сделано', 'declined' => 'отклонено'];

page_head('Админка — предложения', '');
echo '<p><a href="/admin/">← Админка</a></p><h1>Предложения по сайту</h1>';

if (!$list) {
    empty_state('Предложений пока нет', 'Когда участники начнут присылать идеи, они появятся здесь.');
    page_foot();
    exit;
}

foreach ($list as $s) {
    echo '<div class="card">';
    echo '<div style="display:flex;justify-content:space-between;gap:10px;align-items:center;margin-bottom:6px;">';
    echo '<b>' . esc($s['nickname'] ?? '—') . '</b>';
    echo '<span style="font-size:12px;color:var(--tx2);">' . date('d.m.Y H:i', strtotime($s['created_at']))
        . ' · ' . $statusLabel[$s['status']] . '</span></div>';
    echo '<div style="margin-bottom:10px;">' . nl2br(esc($s['body'])) . '</div>';
    echo '<form method="post" action="/admin/suggestions.php" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">' . csrf_field();
    echo '<input type="hidden" name="form" value="update"><input type="hidden" name="id" value="' . (int)$s['id'] . '">';
    echo '<select name="status" style="background:var(--sf2);color:var(--tx);border:1px solid var(--bd);border-radius:7px;padding:6px 10px;">';
    foreach ($statusLabel as $sk => $sl) {
        echo '<option value="' . $sk . '" ' . ($s['status'] === $sk ? 'selected' : '') . '>' . $sl . '</option>';
    }
    echo '</select>';
    echo '<input type="text" name="admin_note" placeholder="ответ (необязательно)" value="' . esc($s['admin_note']) . '" style="flex:1;min-width:180px;background:var(--sf2);color:var(--tx);border:1px solid var(--bd);border-radius:7px;padding:6px 10px;">';
    echo '<button class="btn" style="padding:6px 14px;font-size:13px;" type="submit">Сохранить</button>';
    echo '</form>';
    echo '<form method="post" action="/admin/suggestions.php" style="display:inline;margin-top:6px;" onsubmit="return confirm(\'Удалить?\');">' . csrf_field();
    echo '<input type="hidden" name="form" value="delete"><input type="hidden" name="id" value="' . (int)$s['id'] . '">';
    echo '<button class="btn btn-ghost" style="padding:4px 10px;font-size:12px;color:var(--ac);" type="submit">Удалить</button></form>';
    echo '</div>';
}
page_foot();
