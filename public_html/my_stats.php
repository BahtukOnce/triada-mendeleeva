<?php
require dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/player_stats.php';

$u = require_login();
$player = current_player();

page_head('Моя статистика', '');

if (!$player) {
    empty_state('Ник ещё не привязан', 'Привяжите игровой ник в личном кабинете, чтобы видеть статистику.');
    echo '<p style="text-align:center;"><a class="btn" href="/cabinet.php">В личный кабинет</a></p>';
    page_foot();
    exit;
}

// Та же статистика, что и в профиле игрока, но шапка ведёт в личный кабинет (own = true).
render_player_stats((int)$player['id'], true);

page_foot();
