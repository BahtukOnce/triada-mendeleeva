<?php
require dirname(__DIR__, 2) . '/inc/bootstrap.php';
require_once ROOT . '/inc/day_card.php';
require_once ROOT . '/inc/bot_lib.php';   // bot_date() — дата на карточке такая же, как в рассылке
// Карточка вечера картинкой прямо в браузере — чтобы смотреть её вид, не гоняя рассылку бота.
// Только судьям и админам. ?day=<id> — вечер (по умолчанию последний сыгранный),
// ?player=<id> — чей результат (по умолчанию свой игровой ник).
$u = require_judge();

$dayId = (int)($_GET['day'] ?? 0);
if (!$dayId) {
    $dayId = (int)db()->query("SELECT g.day_id FROM games g WHERE g.day_id IS NOT NULL AND g.status = 'finished'
        ORDER BY g.id DESC LIMIT 1")->fetchColumn();
}
$st = db()->prepare('SELECT * FROM game_days WHERE id = ?');
$st->execute([$dayId]);
$day = $st->fetch();

$pid = (int)($_GET['player'] ?? 0);
if (!$pid) {
    $mp = current_player();
    $pid = $mp ? (int)$mp['id'] : 0;
}

$fail = function (string $msg): void {
    header('Content-Type: text/plain; charset=utf-8');
    http_response_code(404);
    echo $msg;
    exit;
};
if (!$day) {
    $fail('Вечер не найден');
}
if (!$pid) {
    $fail('Сначала привяжите игровой ник в личном кабинете — карточка рисуется по вашему результату');
}
if (!day_card_available()) {
    $fail('На сервере нет GD или шрифтов — карточку рисовать нечем');
}

// Те же данные, что кладёт в карточку рассылка итогов (inc/bot_lib.php)
$q = db()->prepare("SELECT COUNT(*) games, SUM(eh.delta) net, p.elo cur, MAX(eh.elo_after) day_peak,
        (SELECT MAX(e2.elo_after) FROM elo_history e2 WHERE e2.player_id = eh.player_id) all_peak
    FROM elo_history eh
    JOIN games g ON g.id = eh.game_id
    JOIN players p ON p.id = eh.player_id
    WHERE g.day_id = ? AND eh.player_id = ?
    GROUP BY eh.player_id, p.elo");
$q->execute([$dayId, $pid]);
$row = $q->fetch();
if (!$row) {
    $fail('В этом вечере у игрока нет сыгранных игр');
}
$w = db()->prepare("SELECT SUM((g.winner = 'red' AND gs.role IN ('civ','sheriff'))
        OR (g.winner = 'black' AND gs.role IN ('maf','don'))) w
    FROM game_seats gs JOIN games g ON g.id = gs.game_id
    WHERE g.day_id = ? AND gs.player_id = ? AND g.status = 'finished'");
$w->execute([$dayId, $pid]);
$pl = db()->prepare('SELECT nickname, avatar, flair FROM players WHERE id = ?');
$pl->execute([$pid]);
$p = $pl->fetch() ?: ['nickname' => '?', 'avatar' => null, 'flair' => ''];
$net = (float)$row['net'];
// Место в клубном рейтинге — как в бейдже профиля (по club_score)
$rank = 0;
$mainId = (int)db()->query('SELECT id FROM ratings WHERE is_main = 1 LIMIT 1')->fetchColumn();
if ($mainId) {
    $rq = db()->prepare('SELECT COUNT(*) + 1 FROM rating_cache
        WHERE rating_id = ? AND club_score > (SELECT club_score FROM rating_cache WHERE rating_id = ? AND player_id = ?)');
    $rq->execute([$mainId, $mainId, $pid]);
    $has = db()->prepare('SELECT 1 FROM rating_cache WHERE rating_id = ? AND player_id = ?');
    $has->execute([$mainId, $pid]);
    $rank = $has->fetchColumn() ? (int)$rq->fetchColumn() : 0;
}

$png = day_card_png([
    'nickname' => (string)$p['nickname'],
    'flair' => (string)($p['flair'] ?? ''),
    'avatar' => $p['avatar'] ?? null,
    'day_title' => (string)$day['title'],
    'day_date' => bot_date((string)$day['date']),
    'games' => (int)$row['games'],
    'wins' => (int)$w->fetchColumn(),
    'net' => $net,
    'elo' => (float)$row['cur'],
    'rank' => $rank,
    'record' => ((float)$row['day_peak'] >= (float)$row['all_peak'] - 0.05) && $net > 0,
    'top' => false,
]);
if ($png === null) {
    $fail('Не удалось нарисовать карточку');
}
header('Content-Type: image/png');
header('Cache-Control: no-store');
readfile($png);
@unlink($png);
