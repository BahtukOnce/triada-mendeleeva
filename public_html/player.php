<?php
require dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/player_stats.php';

$id = (int)($_GET['id'] ?? 0);

$nick = null;
if ($id && db_ready()) {
    $st = db()->prepare('SELECT nickname FROM players WHERE id = ?');
    $st->execute([$id]);
    $nick = $st->fetchColumn() ?: null;
}

page_head($nick !== null ? (string)$nick : 'Игрок не найден', 'players');

if ($nick === null) {
    empty_state('Игрок не найден', 'Возможно, ссылка устарела.');
    page_foot();
    exit;
}

render_player_stats($id, false);

page_foot();
