<?php
require dirname(__DIR__) . '/inc/bootstrap.php';
require ROOT . '/inc/rating.php';

$u = require_login();
$player = current_player();

page_head('Мои игры', '');
echo '<h1>Мои игры</h1>';

if (!$player) {
    empty_state('Ник ещё не привязан',
        'Чтобы видеть свои игры и статистику, привяжите игровой ник в личном кабинете.');
    echo '<p style="text-align:center;"><a class="btn" href="/cabinet.php">В личный кабинет</a></p>';
    page_foot();
    exit;
}
$pid = (int)$player['id'];
$elo = (int)round((float)($player['elo'] ?? 1000));

// Место и агрегаты по основному рейтингу
$mainId = (int)db()->query('SELECT id FROM ratings WHERE is_main = 1 LIMIT 1')->fetchColumn();
$stats = null;
$rank = null;
if ($mainId) {
    $st = db()->prepare('SELECT * FROM rating_cache WHERE rating_id = ? AND player_id = ?');
    $st->execute([$mainId, $pid]);
    $stats = $st->fetch() ?: null;
    if ($stats) {
        $st = db()->prepare('SELECT COUNT(*) + 1 FROM rating_cache
            WHERE rating_id = ? AND club_score > (SELECT club_score FROM rating_cache WHERE rating_id = ? AND player_id = ?)');
        $st->execute([$mainId, $mainId, $pid]);
        $rank = (int)$st->fetchColumn();
    }
}

echo '<div class="grid-stats">';
echo '<div class="stat"><div class="lbl">место в рейтинге</div><div class="val">' . ($rank ?: '—') . '</div></div>';
echo '<div class="stat"><div class="lbl">ELO</div><div class="val">' . $elo . '</div></div>';
echo '<div class="stat"><div class="lbl">игр (рейтинг)</div><div class="val">' . ($stats ? (int)$stats['games'] : 0) . '</div></div>';
echo '<div class="stat"><div class="lbl">Σ</div><div class="val">' . ($stats ? number_format((float)$stats['sum_total'], 2) : '0') . '</div></div>';
echo '</div>';

// Полные игры игрока (с данными для протокола)
$st = db()->prepare("SELECT g.*, COALESCE(d.date, t.date_from) AS gdate,
        d.id AS day_id, d.title AS day_title, t.id AS t_id, t.title AS t_title
    FROM game_seats gs
    JOIN games g ON g.id = gs.game_id
    LEFT JOIN game_days d ON d.id = g.day_id
    LEFT JOIN tournaments t ON t.id = g.tournament_id
    WHERE gs.player_id = ? AND g.status = 'finished'
    ORDER BY COALESCE(d.date, t.date_from) DESC, g.id DESC");
$st->execute([$pid]);
$myGames = $st->fetchAll();

if (!$myGames) {
    empty_state('Игр пока нет', 'Как только вы сыграете, игры появятся здесь.');
    page_foot();
    exit;
}

$gids = array_column($myGames, 'id');
$in = implode(',', array_fill(0, count($gids), '?'));
$seatsByGame = [];
$st = db()->prepare("SELECT gs.*, p.nickname, p.avatar, p.flair FROM game_seats gs
    JOIN players p ON p.id = gs.player_id WHERE gs.game_id IN ($in) ORDER BY gs.game_id, gs.seat");
$st->execute($gids);
foreach ($st->fetchAll() as $s) {
    $seatsByGame[(int)$s['game_id']][] = $s;
}
$eloDelta = [];
$st = db()->prepare("SELECT game_id, delta FROM elo_history WHERE player_id = ? AND game_id IN ($in)");
$st->execute(array_merge([$pid], $gids));
foreach ($st->fetchAll() as $r) {
    $eloDelta[(int)$r['game_id']] = (float)$r['delta'];
}

$roleLabel = ['civ' => 'Мирный', 'maf' => 'Мафия', 'sheriff' => 'Шериф', 'don' => 'Дон'];
$winLabel = ['red' => 'Победа красных', 'black' => 'Победа чёрных', 'draw' => 'Ничья'];

$eloChip = function (?float $d): string {
    if ($d === null) {
        return '';
    }
    $col = $d >= 0 ? 'var(--ok)' : 'var(--ac)';
    $sign = $d >= 0 ? '+' : '';
    return '<span style="color:' . $col . ';font-weight:650;font-variant-numeric:tabular-nums;">'
        . $sign . number_format($d, 1) . ' ELO</span>';
};

// Дистанция Ci для турнирных игр считается по ВСЕМ играм турнира (как в итоговой
// таблице турнира). Без этого game_display_totals берёт дистанцию основного рейтинга
// (вечеров), и «Итог» здесь расходится со страницей турнира.
$tourDistCache = [];
$tournamentDist = function (int $tid) use (&$tourDistCache): array {
    if (isset($tourDistCache[$tid])) {
        return $tourDistCache[$tid];
    }
    $dist = ['games' => [], 'pu' => []];
    $gq = db()->prepare("SELECT id, first_killed_seat FROM games
        WHERE tournament_id = ? AND status = 'finished' AND winner IS NOT NULL");
    $gq->execute([$tid]);
    $tgames = $gq->fetchAll();
    if ($tgames) {
        $fk = [];
        foreach ($tgames as $gg) {
            $fk[(int)$gg['id']] = (int)$gg['first_killed_seat'];
        }
        $tids = array_keys($fk);
        $tin = implode(',', array_fill(0, count($tids), '?'));
        $sq = db()->prepare("SELECT game_id, player_id, seat, role FROM game_seats WHERE game_id IN ($tin)");
        $sq->execute($tids);
        foreach ($sq->fetchAll() as $row) {
            $rpid = (int)$row['player_id'];
            $dist['games'][$rpid] = ($dist['games'][$rpid] ?? 0) + 1;
            if ($fk[(int)$row['game_id']] === (int)$row['seat'] && in_array($row['role'], ROLE_RED, true)) {
                $dist['pu'][$rpid] = ($dist['pu'][$rpid] ?? 0) + 1;
            }
        }
    }
    return $tourDistCache[$tid] = $dist;
};

// Группировка по событию
$groups = [];
foreach ($myGames as $g) {
    $isDay = $g['context'] === 'day';
    $key = $isDay ? 'd' . $g['day_id'] : 't' . $g['t_id'];
    if (!isset($groups[$key])) {
        $groups[$key] = [
            'is_day' => $isDay,
            'id' => $isDay ? (int)$g['day_id'] : (int)$g['t_id'],
            'title' => $isDay ? $g['day_title'] : $g['t_title'],
            'date' => $g['gdate'],
            'games' => [],
        ];
    }
    $groups[$key]['games'][] = $g;
}

foreach ($groups as $grp) {
    $link = ($grp['is_day'] ? '/day.php?id=' : '/tournament.php?id=') . $grp['id'];
    $dateStr = $grp['date'] ? date('d.m.Y', strtotime($grp['date'])) : '';
    $wins = 0;
    $evDelta = 0.0;
    foreach ($grp['games'] as $g) {
        foreach (($seatsByGame[(int)$g['id']] ?? []) as $s) {
            if ((int)$s['player_id'] !== $pid) {
                continue;
            }
            $isRed = in_array($s['role'], ['civ', 'sheriff'], true);
            if (($g['winner'] === 'red' && $isRed) || ($g['winner'] === 'black' && !$isRed)) {
                $wins++;
            }
        }
        $evDelta += $eloDelta[(int)$g['id']] ?? 0;
    }

    echo '<div class="card">';
    echo '<div class="section-head"><h2 style="margin:0;font-size:16px;"><a href="' . $link . '" style="color:var(--tx);">'
        . esc($grp['title']) . '</a></h2>';
    echo '<span style="font-size:12.5px;color:var(--tx2);">' . esc($dateStr)
        . ' · ' . ($grp['is_day'] ? 'вечер' : 'турнир') . ' · игр: ' . count($grp['games'])
        . ', побед: ' . $wins . ' · ' . $eloChip($evDelta) . '</span></div>';

    echo '<div class="tables-grid" style="grid-template-columns:repeat(auto-fit,minmax(320px,1fr));margin-top:10px;">';
    foreach ($grp['games'] as $g) {
        $seats = $seatsByGame[(int)$g['id']] ?? [];
        $dist = $g['context'] === 'day' ? null : $tournamentDist((int)$g['tournament_id']);
        $totals = game_display_totals($g, $seats, $dist);
        $winTag = $g['winner'] === 'red' ? 'tag-red' : ($g['winner'] === 'black' ? 'tag-black' : 'tag-draw');
        $d = $eloDelta[(int)$g['id']] ?? null;

        echo '<div class="card card-compact">';
        echo '<div class="section-head" style="gap:8px;"><h2 style="margin:0;font-size:15px;">Игра ' . (int)$g['game_no'] . '</h2><span>';
        if ($g['winner']) {
            echo '<span class="tag ' . $winTag . '">' . esc($winLabel[$g['winner']]) . '</span> ';
        }
        echo $eloChip($d) . '</span></div>';
        echo '<table class="tbl" style="font-size:12.5px;margin-top:6px;">';
        echo '<tr><th>#</th><th>Игрок</th><th>Роль</th><th class="num">Итог</th></tr>';
        foreach ($seats as $s) {
            $isMe = (int)$s['player_id'] === $pid;
            $t = $totals[(int)$s['seat']] ?? ['total' => 0, 'is_pu' => false];
            $nameCell = $isMe
                ? '<b>' . player_label($s) . '</b>'
                : '<a href="/player.php?id=' . (int)$s['player_id'] . '" style="color:var(--tx);">' . player_label($s) . '</a>';
            echo '<tr' . ($isMe ? ' style="background:var(--acsf);"' : '') . '>'
                . '<td>' . (int)$s['seat'] . '</td>'
                . '<td>' . $nameCell . ($t['is_pu'] ? ' <span class="tag">ПУ</span>' : '') . '</td>'
                . '<td>' . role_dot($s['role']) . $roleLabel[$s['role']] . '</td>'
                . '<td class="num"><b>' . number_format($t['total'], 2) . '</b></td></tr>';
        }
        echo '</table></div>';
    }
    echo '</div></div>';
}
page_foot();
