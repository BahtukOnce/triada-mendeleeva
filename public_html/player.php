<?php
require dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/player_stats.php';

$id = (int)($_GET['id'] ?? 0);

$nick = null;
$meta = [];
if ($id && db_ready()) {
    $st = db()->prepare('SELECT nickname, avatar, elo FROM players WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    if ($row) {
        $nick = (string)$row['nickname'];
        $desc = 'Профиль игрока клуба «Триада Менделеева»';
        if ($row['elo'] !== null) {
            $desc .= ' · ELO ' . (int)$row['elo'];
        }
        $meta = [
            'og_type'     => 'profile',
            'url'         => 'player.php?id=' . $id,
            'description' => $desc,
        ];
        if (!empty($row['avatar'])) {
            $meta['image'] = (string)$row['avatar'];
        }
    }
}

page_head($nick !== null ? $nick : 'Игрок не найден', 'players', $meta);

if ($nick === null) {
    empty_state('Игрок не найден', 'Возможно, ссылка устарела.');
    page_foot();
    exit;
}

render_player_stats($id, false);

page_foot();
