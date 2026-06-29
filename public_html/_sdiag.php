<?php
// ВРЕМЕННЫЙ диагностический эндпоинт (только чтение). Удаляется после использования.
require dirname(__DIR__) . '/inc/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
if (($_GET['t'] ?? '') !== 'lgm_5kQ2x9aZ') { http_response_code(403); echo '{"e":"forbidden"}'; exit; }

$out = [];
$out['today'] = date('Y-m-d');
$out['ratings'] = db()->query("SELECT id,title,is_main,is_active,is_frozen FROM ratings ORDER BY is_main DESC, id DESC")->fetchAll(PDO::FETCH_ASSOC);

// найти игрока по нику (s1lence или любой через ?nick=)
$nick = $_GET['nick'] ?? 's1lence';
$st = db()->prepare("SELECT id,nickname,elo,user_id,banned_at FROM players WHERE nickname = ? OR nickname LIKE ?");
$st->execute([$nick, '%'.$nick.'%']);
$players = $st->fetchAll(PDO::FETCH_ASSOC);
$out['players_matched'] = $players;

foreach ($players as $p) {
    $pid = (int)$p['id'];
    $info = ['id'=>$pid,'nick'=>$p['nickname'],'elo'=>$p['elo']];
    // rating_cache по каждому рейтингу
    $st = db()->prepare("SELECT rc.rating_id, r.title, rc.games, rc.sum_total, rc.club_score
        FROM rating_cache rc JOIN ratings r ON r.id=rc.rating_id WHERE rc.player_id=?");
    $st->execute([$pid]);
    $info['rating_cache'] = $st->fetchAll(PDO::FETCH_ASSOC);
    // все партии игрока: по контексту, сезону, диапазон дат
    $st = db()->prepare("SELECT g.context,
            COALESCE(d.season,'(нет)') AS season,
            COUNT(*) AS cnt,
            MIN(COALESCE(d.date,t.date_from)) AS first_date,
            MAX(COALESCE(d.date,t.date_from)) AS last_date
        FROM game_seats gs
        JOIN games g ON g.id=gs.game_id
        LEFT JOIN game_days d ON d.id=g.day_id
        LEFT JOIN tournaments t ON t.id=g.tournament_id
        WHERE gs.player_id=? AND g.status='finished'
        GROUP BY g.context, COALESCE(d.season,'(нет)')
        ORDER BY first_date");
    $st->execute([$pid]);
    $info['games_breakdown'] = $st->fetchAll(PDO::FETCH_ASSOC);
    // партии в текущем сезоне (2025-09-01 .. 2026-08-31)
    $st = db()->prepare("SELECT COUNT(*) FROM game_seats gs JOIN games g ON g.id=gs.game_id
        LEFT JOIN game_days d ON d.id=g.day_id
        LEFT JOIN tournaments t ON t.id=g.tournament_id
        WHERE gs.player_id=? AND g.status='finished'
          AND COALESCE(d.date,t.date_from) BETWEEN '2025-09-01' AND '2026-08-31'");
    $st->execute([$pid]);
    $info['current_season_games'] = (int)$st->fetchColumn();
    $out['detail'][] = $info;
}

// где s1lence — участник (roster) и где у него турнирные партии
foreach ($players as $p) {
    $pid = (int)$p['id'];
    $st = db()->prepare("SELECT t.id, t.title, t.date_from, tp.state,
            (SELECT COUNT(*) FROM game_seats gs JOIN games g ON g.id=gs.game_id
             WHERE g.tournament_id=t.id AND gs.player_id=? AND g.status='finished') AS my_games
        FROM tournament_participants tp JOIN tournaments t ON t.id=tp.tournament_id
        WHERE tp.player_id=?");
    $st->execute([$pid, $pid]);
    $out['tournaments_as_participant'][$p['nickname']] = $st->fetchAll(PDO::FETCH_ASSOC);
    // турниры, где есть партии (даже без roster)
    $st = db()->prepare("SELECT DISTINCT g.tournament_id, t.title FROM game_seats gs
        JOIN games g ON g.id=gs.game_id JOIN tournaments t ON t.id=g.tournament_id
        WHERE gs.player_id=? AND g.tournament_id IS NOT NULL");
    $st->execute([$pid]);
    $out['tournaments_with_games'][$p['nickname']] = $st->fetchAll(PDO::FETCH_ASSOC);
}

// общая картина: дни Google-таблицы — есть ли у них season и какие даты
$out['day_seasons'] = db()->query("SELECT COALESCE(season,'(нет/основной)') AS season, COUNT(*) AS days,
        MIN(date) AS first_date, MAX(date) AS last_date
    FROM game_days GROUP BY season ORDER BY first_date")->fetchAll(PDO::FETCH_ASSOC);

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
