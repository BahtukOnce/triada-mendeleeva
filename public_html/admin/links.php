<?php
require dirname(__DIR__, 2) . '/inc/bootstrap.php';
$u = require_role('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id = (int)($_POST['req_id'] ?? 0);
    $action = (string)($_POST['do'] ?? '');
    $st = db()->prepare("SELECT * FROM link_requests WHERE id = ? AND status = 'pending'");
    $st->execute([$id]);
    $req = $st->fetch();
    if ($req && in_array($action, ['approve', 'reject'], true)) {
        if ($action === 'approve') {
            db()->prepare('UPDATE players SET user_id = ? WHERE id = ? AND user_id IS NULL')
                ->execute([(int)$req['user_id'], (int)$req['player_id']]);
            db()->prepare("UPDATE link_requests SET status = 'approved', decided_at = NOW(), decided_by = ? WHERE id = ?")
                ->execute([(int)$u['id'], $id]);
            flash_set('ok', 'Привязка подтверждена');
        } else {
            db()->prepare("UPDATE link_requests SET status = 'rejected', decided_at = NOW(), decided_by = ? WHERE id = ?")
                ->execute([(int)$u['id'], $id]);
            flash_set('ok', 'Заявка отклонена');
        }
        log_action((int)$u['id'], 'link_' . $action, ['request_id' => $id]);
    }
    redirect('/admin/links.php');
}

$list = db_ready() ? db()->query("SELECT lr.*, us.nickname AS user_nick, p.nickname AS player_nick, p.avatar,
        (SELECT COUNT(*) FROM game_seats gs WHERE gs.player_id = p.id) AS games
    FROM link_requests lr
    JOIN users us ON us.id = lr.user_id
    JOIN players p ON p.id = lr.player_id
    WHERE lr.status = 'pending' ORDER BY lr.created_at")->fetchAll() : [];

page_head('Админка — привязки', '');
echo '<p><a href="/admin/">← Админка</a></p><h1>Заявки на привязку ника</h1>';

if ($list) {
    echo '<div class="card"><table class="tbl">';
    echo '<tr><th>Аккаунт</th><th>→ Игрок</th><th class="num">Игр в истории</th><th>Когда</th><th></th></tr>';
    foreach ($list as $r) {
        echo '<tr><td>' . esc($r['user_nick']) . '</td>'
            . '<td><span style="display:inline-flex;align-items:center;gap:8px;">'
            . avatar_html(['nickname' => $r['player_nick'], 'avatar' => $r['avatar']], 26)
            . '<b>' . esc($r['player_nick']) . '</b></span></td>';
        echo '<td class="num">' . (int)$r['games'] . '</td>';
        echo '<td>' . date('d.m.Y H:i', strtotime($r['created_at'])) . '</td><td>';
        foreach ([['approve', 'Подтвердить', 'btn'], ['reject', 'Отклонить', 'btn btn-ghost']] as [$do, $lbl, $cls]) {
            echo '<form method="post" action="/admin/links.php" style="display:inline;">' . csrf_field();
            echo '<input type="hidden" name="req_id" value="' . (int)$r['id'] . '"><input type="hidden" name="do" value="' . $do . '">';
            echo '<button class="' . $cls . '" style="padding:5px 12px;font-size:12.5px;" type="submit">' . $lbl . '</button></form> ';
        }
        echo '</td></tr>';
    }
    echo '</table></div>';
} else {
    empty_state('Ожидающих заявок нет', 'Когда игрок попросит привязать ник к аккаунту, заявка появится здесь.');
}
page_foot();
