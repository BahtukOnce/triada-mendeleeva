<?php
require dirname(__DIR__, 2) . '/inc/bootstrap.php';
$me = require_role('admin'); // админ (3) и руководитель/owner (4)

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    $pid = (int)($_POST['player_id'] ?? 0);
    if ($pid > 0 && $action === 'ban') {
        $reason = mb_substr(trim((string)($_POST['reason'] ?? '')), 0, 255);
        db()->prepare('UPDATE players SET banned_at = NOW(), ban_reason = ?, banned_by = ? WHERE id = ?')
            ->execute([$reason !== '' ? $reason : null, (int)$me['id'], $pid]);
        log_action((int)$me['id'], 'player_ban', ['player_id' => $pid, 'reason' => $reason]);
        flash_set('ok', 'Игрок забанен');
    } elseif ($pid > 0 && $action === 'unban') {
        db()->prepare('UPDATE players SET banned_at = NULL, ban_reason = NULL, banned_by = NULL WHERE id = ?')
            ->execute([$pid]);
        log_action((int)$me['id'], 'player_unban', ['player_id' => $pid]);
        flash_set('ok', 'Бан снят');
    }
    redirect('/admin/banlist.php' . (!empty($_POST['q']) ? '?q=' . urlencode((string)$_POST['q']) : ''));
}

$q = trim((string)($_GET['q'] ?? ''));
$found = [];
if ($q !== '') {
    $st = db()->prepare('SELECT id, nickname, avatar FROM players WHERE banned_at IS NULL AND nickname LIKE ? ORDER BY nickname LIMIT 20');
    $st->execute(['%' . like_escape($q) . '%']);
    $found = $st->fetchAll();
}
$banned = db()->query('SELECT p.id, p.nickname, p.avatar, p.banned_at, p.ban_reason, u.nickname AS by_nick
    FROM players p LEFT JOIN users u ON u.id = p.banned_by
    WHERE p.banned_at IS NOT NULL ORDER BY p.banned_at DESC')->fetchAll();

$inp = 'background:var(--sf2);color:var(--tx);border:1px solid var(--bd);border-radius:8px;padding:8px 10px;';

page_head('Бан-лист', '');
echo '<p><a href="/admin/">← Админка</a></p><h1>Бан-лист</h1>';
echo '<p style="color:var(--tx2);font-size:13px;margin-top:-6px;">Доступно администраторам и руководителю. Забаненные скрываются из списка игроков.</p>';

echo '<div class="card"><h2 style="margin-top:0;">Забанить игрока</h2>';
echo '<form method="get" action="/admin/banlist.php" style="max-width:340px;margin-bottom:10px;">';
echo '<div class="field" style="margin:0;"><input type="search" name="q" placeholder="Поиск по нику" value="' . esc($q) . '" autocomplete="off"></div>';
echo '</form>';
if ($q !== '') {
    if ($found) {
        echo '<div class="admin-list">';
        foreach ($found as $f) {
            echo '<form method="post" class="admin-item" style="gap:10px;align-items:center;flex-wrap:wrap;">' . csrf_field()
                . '<input type="hidden" name="action" value="ban"><input type="hidden" name="player_id" value="' . (int)$f['id'] . '">'
                . '<input type="hidden" name="q" value="' . esc($q) . '">'
                . avatar_html($f, 30)
                . '<div style="flex:1;min-width:110px;"><a href="/player.php?id=' . (int)$f['id'] . '" style="color:var(--tx);">' . esc($f['nickname']) . '</a></div>'
                . '<input type="text" name="reason" placeholder="причина (необязательно)" style="flex:2;min-width:150px;' . $inp . '">'
                . '<button class="btn btn-ghost" style="color:var(--ac);" type="submit" onclick="return confirm(\'Забанить ' . esc(addslashes($f['nickname'])) . '?\');">Забанить</button>'
                . '</form>';
        }
        echo '</div>';
    } else {
        echo '<p style="color:var(--tx2);">Никого не нашлось среди незабаненных.</p>';
    }
}
echo '</div>';

echo '<div class="card"><h2 style="margin-top:0;">Забанены (' . count($banned) . ')</h2>';
if ($banned) {
    echo '<div class="admin-list">';
    foreach ($banned as $b) {
        $when = $b['banned_at'] ? date('d.m.Y', strtotime($b['banned_at'])) : '';
        $reason = $b['ban_reason'] ? esc($b['ban_reason']) : '<span style="color:var(--tx3);">без причины</span>';
        $by = $b['by_nick'] ? ' · ' . esc($b['by_nick']) : '';
        echo '<div class="admin-item" style="gap:10px;align-items:center;">'
            . avatar_html($b, 30)
            . '<div style="flex:1;min-width:120px;"><div class="nm"><a href="/player.php?id=' . (int)$b['id'] . '" style="color:var(--tx);">' . esc($b['nickname']) . '</a></div>'
            . '<div class="rl" style="color:var(--tx2);font-size:12px;">' . $reason . ' <span style="color:var(--tx3);">· ' . esc($when) . $by . '</span></div></div>'
            . '<form method="post">' . csrf_field()
            . '<input type="hidden" name="action" value="unban"><input type="hidden" name="player_id" value="' . (int)$b['id'] . '">'
            . '<button class="btn btn-ghost" type="submit">Снять бан</button></form>'
            . '</div>';
    }
    echo '</div>';
} else {
    echo '<p style="color:var(--tx2);">Забаненных нет.</p>';
}
echo '</div>';
page_foot();
