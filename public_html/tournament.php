<?php
require dirname(__DIR__) . '/inc/bootstrap.php';
require ROOT . '/inc/rating.php';

$id = (int)($_GET['id'] ?? 0);

// Самозапись игрока на открытый турнир (до любого вывода страницы)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $id) {
    csrf_check();
    $cu = current_user();
    $act = (string)($_POST['act'] ?? '');
    if ($cu && in_array($act, ['join', 'leave'], true)) {
        $ps = db()->prepare('SELECT id FROM players WHERE user_id = ? LIMIT 1');
        $ps->execute([(int)$cu['id']]);
        $myPid = (int)($ps->fetchColumn() ?: 0);
        $ts = db()->prepare('SELECT status, reg_mode FROM tournaments WHERE id = ?');
        $ts->execute([$id]);
        $trow = $ts->fetch();
        if ($myPid && $trow && ($trow['reg_mode'] ?? 'open') === 'open' && $trow['status'] === 'reg_open') {
            if ($act === 'join') {
                db()->prepare("INSERT INTO tournament_participants (tournament_id, player_id, state, source) VALUES (?,?,'confirmed','self')
                    ON DUPLICATE KEY UPDATE state='confirmed'")->execute([$id, $myPid]);
                flash_set('ok', 'Ты записан на турнир!');
            } else {
                db()->prepare('DELETE FROM tournament_participants WHERE tournament_id=? AND player_id=?')->execute([$id, $myPid]);
                flash_set('ok', 'Запись отменена');
            }
        } else {
            flash_set('err', 'Запись на этот турнир сейчас недоступна');
        }
    }
    redirect('/tournament.php?id=' . $id);
}

$t = null;
$games = [];
$seatsByGame = [];

if ($id && db_ready()) {
    $st = db()->prepare('SELECT * FROM tournaments WHERE id = ?');
    $st->execute([$id]);
    $t = $st->fetch() ?: null;
    if ($t) {
        $st = db()->prepare("SELECT g.*, jp.nickname AS judge_nick FROM games g
            LEFT JOIN players jp ON jp.id = g.judge_player_id
            WHERE g.tournament_id = ? ORDER BY g.table_no, g.game_no");
        $st->execute([$id]);
        $games = $st->fetchAll();
        if ($games) {
            $ids = array_column($games, 'id');
            $in = implode(',', array_fill(0, count($ids), '?'));
            $st = db()->prepare("SELECT gs.*, p.nickname, p.avatar, p.elo FROM game_seats gs
                JOIN players p ON p.id = gs.player_id
                WHERE gs.game_id IN ($in) ORDER BY gs.game_id, gs.seat");
            $st->execute($ids);
            foreach ($st->fetchAll() as $s) {
                $seatsByGame[(int)$s['game_id']][] = $s;
            }
        }
    }
}

$roleLabel = ['civ' => 'Мирный', 'maf' => 'Мафия', 'sheriff' => 'Шериф', 'don' => 'Дон'];
$winLabel = ['red' => 'красные', 'black' => 'чёрные', 'draw' => 'ничья'];

$meta = [];
if ($t) {
    $parts = [];
    if (!empty($t['date_from'])) {
        $parts[] = date('d.m.Y', strtotime((string)$t['date_from']));
    }
    if (!empty($t['location'])) {
        $parts[] = (string)$t['location'];
    }
    $meta = [
        'url'         => 'tournament.php?id=' . $id,
        'description' => 'Турнир клуба «Триада Менделеева»' . ($parts ? ' · ' . implode(' · ', $parts) : ''),
    ];
    if (!empty($t['logo'])) {
        $meta['image'] = (string)$t['logo'];
    }
}

page_head($t ? $t['title'] : 'Турнир не найден', 'tournaments', $meta);

if (!$t) {
    empty_state('Турнир не найден', 'Возможно, ссылка устарела.');
    page_foot();
    exit;
}

if (!empty($t['logo'])) {
    echo '<div style="display:flex;align-items:center;gap:16px;">'
        . '<img src="' . esc($t['logo']) . '" alt="" style="width:96px;height:96px;object-fit:cover;border-radius:50%;border:2px solid var(--bd);flex:none;">'
        . '<h1 style="margin:0;">' . esc($t['title']) . '</h1></div>';
} else {
    echo '<h1>' . esc($t['title']) . '</h1>';
}
$participants = [];
foreach ($seatsByGame as $seats) {
    foreach ($seats as $s) {
        $participants[(int)$s['player_id']] = 1;
    }
}
echo '<p style="color:var(--tx2);margin-top:6px;">Столов: ' . (int)$t['tables_count']
    . ' · игр: ' . count($games)
    . ' · участников: ' . count($participants)
    . ($t['date_from'] ? ' · ' . esc(date('d.m.Y', strtotime($t['date_from']))) : '')
    . ($t['location'] ? ' · ' . esc($t['location']) : '') . '</p>';

// Судьи турнира: главный (по умолчанию за столом 1) + по столам
$mainJudgeId = (int)($t['main_judge_player_id'] ?? 0);
$tableJudges = [];
if (!empty($t['table_judges'])) {
    $decj = json_decode((string)$t['table_judges'], true);
    if (is_array($decj)) {
        $tableJudges = $decj;
    }
}
$judgeMap = [];
$judgeIds = array_values(array_filter(array_map('intval', array_merge([$mainJudgeId], $tableJudges))));
if ($judgeIds) {
    $in = implode(',', array_fill(0, count($judgeIds), '?'));
    $jq = db()->prepare("SELECT id, nickname, avatar, elo,
        (SELECT COUNT(*) FROM games g WHERE g.judge_player_id = players.id) AS judged
        FROM players WHERE id IN ($in)");
    $jq->execute($judgeIds);
    foreach ($jq->fetchAll() as $jr) {
        $judgeMap[(int)$jr['id']] = $jr;
    }
}
$judgeCell = function (int $pid) use ($judgeMap): string {
    $j = $judgeMap[$pid] ?? null;
    if (!$j) {
        return '';
    }
    return '<a href="/player.php?id=' . $pid . '" style="display:inline-flex;align-items:center;gap:6px;color:var(--tx);">'
        . avatar_html(['nickname' => $j['nickname'], 'avatar' => $j['avatar']], 22) . '<span>' . esc($j['nickname']) . '</span></a>';
};
$tableJudgeId = function (int $tableNo) use ($tableJudges, $mainJudgeId): int {
    $jid = (int)($tableJudges[$tableNo - 1] ?? 0);
    if ($jid === 0 && $tableNo === 1) {
        $jid = $mainJudgeId; // главный судья по умолчанию за первым столом
    }
    return $jid;
};
if ($mainJudgeId && isset($judgeMap[$mainJudgeId])) {
    $mj = $judgeMap[$mainJudgeId];
    $jhSub = [];
    if ($mj['elo'] !== null) {
        $jhSub[] = 'ELO ' . (int)round((float)$mj['elo']);
    }
    if ((int)($mj['judged'] ?? 0) > 0) {
        $jhSub[] = 'судил игр: ' . (int)$mj['judged'];
    }
    echo '<div class="judge-hero">'
        . avatar_html(['nickname' => $mj['nickname'], 'avatar' => $mj['avatar']], 54)
        . '<div><div class="jh-label">⚖ Главный судья</div>'
        . '<a class="jh-name" href="/player.php?id=' . $mainJudgeId . '">' . esc($mj['nickname']) . '</a>'
        . ($jhSub ? '<div class="jh-sub">' . implode(' · ', $jhSub) . '</div>' : '')
        . '</div></div>';
    $otherJ = [];
    for ($tn = 2; $tn <= (int)$t['tables_count']; $tn++) {
        $jid = $tableJudgeId($tn);
        if ($jid && isset($judgeMap[$jid])) {
            $otherJ[] = [$tn, $judgeMap[$jid]];
        }
    }
    if ($otherJ) {
        echo '<div class="judge-tables">';
        foreach ($otherJ as [$tn, $j]) {
            echo '<a class="jt" href="/player.php?id=' . (int)$j['id'] . '"><span class="jt-no">Стол ' . $tn . '</span> '
                . avatar_html(['nickname' => $j['nickname'], 'avatar' => $j['avatar']], 22) . '<span>' . esc($j['nickname']) . '</span></a>';
        }
        echo '</div>';
    }
}

if (!empty($t['description'])) {
    echo '<p style="color:var(--tx2);max-width:680px;line-height:1.6;">' . nl2br(esc($t['description'])) . '</p>';
}
if (!empty($t['dress_code'])) {
    echo '<p style="margin:8px 0 12px;display:flex;align-items:center;gap:8px;flex-wrap:wrap;">'
        . '<span class="tag tag-ok">Дресс-код</span> <span style="color:var(--tx);">' . esc($t['dress_code']) . '</span></p>';
}
if (user_can_judge(current_user())) {
    echo '<p style="margin:0 0 12px;"><a class="btn" href="/admin/tournaments.php?edit=' . $id . '">Редактировать турнир</a> '
        . '<a class="btn btn-ghost" href="/admin/tournaments.php">Все турниры / создать</a></p>';
}

// ── Состав участников (заявка/приглашения) ──
$mainRid = (int)(db()->query('SELECT id FROM ratings WHERE is_main = 1 LIMIT 1')->fetchColumn() ?: 0);
$rq = db()->prepare("SELECT tp.player_id, tp.state, p.nickname, p.avatar, p.elo, p.fav_role,
        rc.games, (COALESCE(rc.w_civ,0)+COALESCE(rc.w_maf,0)+COALESCE(rc.w_sher,0)+COALESCE(rc.w_don,0)) AS wins,
        rc.dop_sum, rc.minus_sum, rc.club_score
    FROM tournament_participants tp
    JOIN players p ON p.id = tp.player_id
    LEFT JOIN rating_cache rc ON rc.player_id = tp.player_id AND rc.rating_id = ?
    WHERE tp.tournament_id = ? AND tp.state IN ('confirmed','invited')");
$rq->execute([$mainRid, $id]);
$rosterRows = $rq->fetchAll();
$rConfirmed = array_values(array_filter($rosterRows, fn($r) => $r['state'] === 'confirmed'));
$rInvited = array_values(array_filter($rosterRows, fn($r) => $r['state'] === 'invited'));
usort($rConfirmed, fn($a, $b) => (float)$b['elo'] <=> (float)$a['elo']); // сильнейшие выше
usort($rInvited, fn($a, $b) => strcmp((string)$a['nickname'], (string)$b['nickname']));
$avgElo = $rConfirmed ? (int)round(array_sum(array_map(fn($r) => (float)$r['elo'], $rConfirmed)) / count($rConfirmed)) : 0;

// средний доп по ролям (из game_seats) для подтверждённых участников
$dopByRole = [];
if ($rConfirmed) {
    $pids = array_map(fn($r) => (int)$r['player_id'], $rConfirmed);
    $in2 = implode(',', array_fill(0, count($pids), '?'));
    $dq = db()->prepare("SELECT gs.player_id, gs.role, AVG(gs.plus) AS avg_dop, COUNT(*) AS g
        FROM game_seats gs JOIN games g ON g.id = gs.game_id
        WHERE gs.player_id IN ($in2) AND g.status = 'finished'
        GROUP BY gs.player_id, gs.role");
    $dq->execute($pids);
    foreach ($dq->fetchAll() as $row) {
        $dopByRole[(int)$row['player_id']][$row['role']] = ['avg' => (float)$row['avg_dop'], 'g' => (int)$row['g']];
    }
}

$cu = current_user();
$myPid = 0;
if ($cu) {
    $ps = db()->prepare('SELECT id FROM players WHERE user_id = ? LIMIT 1');
    $ps->execute([(int)$cu['id']]);
    $myPid = (int)($ps->fetchColumn() ?: 0);
}
$iAmIn = false;
foreach ($rConfirmed as $r) {
    if ((int)$r['player_id'] === $myPid && $myPid) { $iAmIn = true; break; }
}
$regOpen = ($t['reg_mode'] ?? 'open') === 'open' && ($t['status'] ?? '') === 'reg_open';

if ($rosterRows || $regOpen) {
    echo '<div class="card"><h2 style="margin-top:0;display:flex;align-items:center;gap:10px;flex-wrap:wrap;">Участники'
        . ($rConfirmed ? ' <span style="color:var(--tx3);font-weight:400;font-size:15px;">(' . count($rConfirmed) . ')</span>' : '')
        . ($avgElo ? ' <span style="background:var(--acsf);color:var(--ac);font-size:13px;font-weight:700;padding:4px 11px;border-radius:20px;">средний ELO ' . $avgElo . '</span>' : '')
        . '</h2>';
    if ($regOpen && $myPid) {
        $act = $iAmIn ? 'leave' : 'join';
        $lbl = $iAmIn ? 'Отменить запись' : 'Записаться на турнир';
        echo '<form method="post" action="/tournament.php?id=' . $id . '" style="margin-bottom:14px;">' . csrf_field()
            . '<input type="hidden" name="act" value="' . $act . '">'
            . '<button class="' . ($iAmIn ? 'btn btn-ghost' : 'btn') . '" type="submit">' . $lbl . '</button></form>';
    } elseif ($regOpen && !$cu) {
        echo '<p style="color:var(--tx3);"><a href="/login.php">Войди</a>, чтобы записаться на турнир.</p>';
    }
    if ($rConfirmed) {
        $fmt1 = fn($v) => rtrim(rtrim(number_format((float)$v, 1, '.', ''), '0'), '.');
        $roleDopCell = function (int $pid) use ($dopByRole, $roleLabel) {
            $out = [];
            foreach (['civ', 'maf', 'sheriff', 'don'] as $rk) {
                $d = $dopByRole[$pid][$rk] ?? null;
                $out[] = ($d && $d['g'] > 0)
                    ? '<span title="' . esc($roleLabel[$rk]) . '" style="color:' . role_color($rk) . ';font-weight:600;">' . number_format($d['avg'], 2, '.', '') . '</span>'
                    : '<span title="' . esc($roleLabel[$rk]) . '" style="color:var(--tx3);">·</span>';
            }
            return implode(' ', $out);
        };
        echo '<div style="overflow-x:auto;"><table class="tbl tp-tbl"><tr>'
            . '<th class="num">#</th><th class="tp-name-col">Игрок</th><th class="num">ELO</th><th class="num">Игр</th><th class="num">Винрейт</th>'
            . '<th>Любимая карта</th><th>Ср. доп по ролям <span style="color:var(--tx3);font-weight:400;font-size:11px;">мир/маф/шер/дон</span></th><th class="num">Клубный счёт</th></tr>';
        $pos = 0;
        foreach ($rConfirmed as $r) {
            $pos++;
            $g = (int)$r['games'];
            $wr = $g > 0 ? (round((int)$r['wins'] / $g * 100) . '%') : '—';
            $fav = (string)($r['fav_role'] ?? '');
            $favCell = $fav !== ''
                ? '<span style="display:inline-flex;align-items:center;gap:6px;white-space:nowrap;"><span style="width:9px;height:9px;border-radius:50%;background:' . role_color($fav) . ';flex:none;"></span>' . esc($roleLabel[$fav] ?? $fav) . '</span>'
                : '<span style="color:var(--tx3);">—</span>';
            $score = $r['club_score'] !== null ? $fmt1($r['club_score']) : '—';
            $mine = ($myPid && (int)$r['player_id'] === $myPid);
            echo '<tr' . ($mine ? ' style="background:var(--acsf);"' : '') . '><td class="num" style="color:var(--tx3);">' . $pos . '</td>'
                . '<td><a href="/player.php?id=' . (int)$r['player_id'] . '" style="display:inline-flex;align-items:center;gap:9px;color:var(--tx);">'
                . avatar_html(['nickname' => $r['nickname'], 'avatar' => $r['avatar']], 30) . '<b>' . esc($r['nickname']) . '</b></a></td>'
                . '<td class="num" style="color:var(--ac);font-weight:700;">' . (int)round((float)$r['elo']) . '</td>'
                . '<td class="num">' . ($g ?: '—') . '</td>'
                . '<td class="num">' . $wr . '</td>'
                . '<td>' . $favCell . '</td>'
                . '<td style="white-space:nowrap;">' . $roleDopCell((int)$r['player_id']) . '</td>'
                . '<td class="num">' . $score . '</td></tr>';
        }
        echo '</table></div>';
    }
    if ($rInvited) {
        echo '<p style="color:var(--tx3);font-size:13px;margin:14px 0 6px;">Приглашены, ждём ответа:</p><div style="display:flex;flex-wrap:wrap;gap:8px;">';
        foreach ($rInvited as $r) {
            echo '<span style="display:inline-flex;align-items:center;gap:6px;opacity:.55;font-size:13px;">'
                . avatar_html(['nickname' => $r['nickname'], 'avatar' => $r['avatar']], 20) . esc($r['nickname']) . '</span>';
        }
        echo '</div>';
    }
    if (!$rConfirmed && !$rInvited) {
        echo '<p style="color:var(--tx3);margin:0;">Пока никто не записан.</p>';
    }
    echo '</div>';
}

// Итоговая таблица турнира — полный агрегат (как в общем рейтинге)
$standing = standings_from_games($games, $seatsByGame);

if ($standing) {
    // ── Номинации турнира: всё по сумме допов (плюсы − минусы) ──
    $roleNet = [];
    foreach ($seatsByGame as $seats) {
        foreach ($seats as $s) {
            $pid = (int)$s['player_id'];
            if (!isset($roleNet[$pid])) {
                $roleNet[$pid] = ['pid' => $pid, 'nick' => $s['nickname'], 'avatar' => $s['avatar'],
                    'all' => 0.0, 'civ' => 0.0, 'sheriff' => 0.0, 'maf' => 0.0, 'don' => 0.0,
                    'gall' => 0, 'gciv' => 0, 'gsheriff' => 0, 'gmaf' => 0, 'gdon' => 0];
            }
            $net = (float)$s['plus'] - (float)$s['minus'];
            $roleNet[$pid]['all'] += $net;
            $roleNet[$pid]['gall']++;
            $rl = $s['role'];
            if (isset($roleNet[$pid][$rl])) {
                $roleNet[$pid][$rl] += $net;
                $roleNet[$pid]['g' . $rl]++;
            }
        }
    }
    $topBy = function (string $key, string $gkey) use ($roleNet) {
        $best = null;
        foreach ($roleNet as $r) {
            if ($r[$gkey] < 1) { continue; }
            if ($best === null || $r[$key] > $best[$key]
                || ($r[$key] === $best[$key] && $r[$gkey] > $best[$gkey])) {
                $best = $r;
            }
        }
        return $best;
    };
    $plg = function (int $n): string {
        $w = ($n % 10 === 1 && $n % 100 !== 11) ? 'игра'
            : ((in_array($n % 10, [2, 3, 4], true) && !in_array($n % 100, [12, 13, 14], true)) ? 'игры' : 'игр');
        return $n . ' ' . $w;
    };
    $nomCard = function (string $title, ?array $r, string $key, string $gkey, string $sub, string $brd) use ($plg): string {
        if (!$r) {
            return '<div class="card mate-card"><div class="mate-ttl">' . $title . '</div>'
                . '<p style="color:var(--tx2);margin:0;">Нет данных.</p></div>';
        }
        $val = (float)$r[$key];
        $col = $val > 0 ? 'var(--ok)' : ($val < 0 ? 'var(--ac)' : 'var(--tx2)');
        return '<div class="card mate-card" style="border-color:' . $brd . ';">'
            . '<div class="mate-ttl">' . $title . '</div><div class="mate-body">'
            . avatar_html(['nickname' => $r['nick'], 'avatar' => $r['avatar']], 52, 'background:var(--acsf);color:var(--ac);')
            . '<div class="mate-info"><a class="mate-name" href="/player.php?id=' . (int)$r['pid'] . '">' . esc($r['nick']) . '</a>'
            . '<div class="mate-sub">' . $sub . ' · ' . $plg((int)$r[$gkey]) . '</div></div>'
            . '<div class="mate-wr" style="color:' . $col . ';">' . ($val > 0 ? '+' : '') . number_format($val, 1) . '<span>допов</span></div>'
            . '</div></div>';
    };
    echo '<h2 style="margin:16px 0 4px;">Номинации турнира</h2>';
    echo '<p style="color:var(--tx2);font-size:13px;margin:0 0 10px;">всё по сумме допов за турнир (плюсы − минусы)</p>';
    echo '<div class="noms-grid">';
    echo $nomCard('🏆 МВП турнира', $topBy('all', 'gall'), 'all', 'gall', 'весь турнир', 'rgba(232,184,48,0.5)');
    echo $nomCard('🔴 Лучший красный', $topBy('civ', 'gciv'), 'civ', 'gciv', 'мирным', 'rgba(232,51,42,0.45)');
    echo $nomCard('⭐ Лучший шериф', $topBy('sheriff', 'gsheriff'), 'sheriff', 'gsheriff', 'шерифом', 'rgba(230,177,58,0.45)');
    echo $nomCard('⚫ Лучший чёрный', $topBy('maf', 'gmaf'), 'maf', 'gmaf', 'мафией', 'rgba(140,140,150,0.5)');
    echo $nomCard('🎩 Лучший дон', $topBy('don', 'gdon'), 'don', 'gdon', 'доном', 'rgba(90,90,100,0.6)');
    echo '</div>';

    echo '<div class="card" style="overflow-x:auto;"><h2 style="margin-top:0;">Итоговая таблица</h2>';
    echo '<table class="tbl rating-tbl" style="font-size:13px;">';
    echo '<thead>'
        . '<tr class="rt-groups"><th colspan="2"></th><th class="c-elo">ELO</th>'
        . '<th colspan="10">Баллы и суммы</th><th class="c-cards-first" colspan="5">По картам</th></tr>'
        . '<tr>'
        . '<th>#</th><th>Игрок</th><th class="num c-elo">ELO</th>'
        . '<th class="num">~Σ</th><th class="num">Σ</th>'
        . '<th class="num">Σ+</th><th class="num">Игр</th><th class="num">ПУ</th><th class="num">ЛХ</th>'
        . '<th class="num">Допы</th><th class="num c-club">ср.доп</th><th class="num">−</th><th class="num">Ci</th>'
        . '<th class="c-cards c-cards-first">Общ</th><th class="c-cards">Мир</th>'
        . '<th class="c-cards">Маф</th><th class="c-cards">Шер</th><th class="c-cards">Дон</th>'
        . '</tr></thead><tbody>';
    $pos = 0;
    foreach ($standing as $row) {
        $pos++;
        $w = (int)$row['w_civ'] + (int)$row['w_maf'] + (int)$row['w_sher'] + (int)$row['w_don'];
        $avgDop = (int)$row['games'] ? (float)$row['dop_sum'] / (int)$row['games'] : 0;
        echo '<tr' . ($pos <= 3 ? ' class="rt-' . $pos . '"' : '') . '>';
        echo '<td>' . ($pos <= 3 ? '<span style="font-size:15px;">' . rank_medal($pos) . '</span>' : $pos) . '</td>';
        echo '<td><a class="rt-player" href="/player.php?id=' . (int)$row['pid'] . '" style="color:var(--tx);">'
            . avatar_html(['nickname' => $row['nick'], 'avatar' => $row['avatar']], 26, 'margin-right:8px;')
            . '<span>' . esc($row['nick']) . '</span></a></td>';
        echo '<td class="num c-elo"><b>' . number_format((float)$row['elo'], 0, '.', '') . '</b></td>';
        echo '<td class="num">' . number_format((float)$row['avg_total'], 2) . '</td>';
        echo '<td class="num">' . number_format((float)$row['sum'], 2) . '</td>';
        echo '<td class="num">' . number_format((float)$row['sum_plus'], 2) . '</td>';
        echo '<td class="num">' . (int)$row['games'] . '</td>';
        echo '<td class="num">' . (int)$row['pu_count'] . '</td>';
        echo '<td class="num">' . number_format((float)$row['lh_sum'], 1) . '</td>';
        echo '<td class="num">' . number_format((float)$row['dop_sum'], 1) . '</td>';
        echo '<td class="num c-club"><b>' . number_format($avgDop, 2) . '</b></td>';
        echo '<td class="num">' . number_format((float)$row['minus_sum'], 1) . '</td>';
        echo '<td class="num">' . number_format((float)$row['ci_sum'], 2) . '</td>';
        echo str_replace('c-cards"', 'c-cards c-cards-first"', wr_cell($w, (int)$row['games']));
        echo wr_cell((int)$row['w_civ'], (int)$row['g_civ']);
        echo wr_cell((int)$row['w_maf'], (int)$row['g_maf']);
        echo wr_cell((int)$row['w_sher'], (int)$row['g_sher']);
        echo wr_cell((int)$row['w_don'], (int)$row['g_don']);
        echo '</tr>';
    }
    echo '</tbody></table></div>';
}

$tablePlaces = [];
if (!empty($t['table_places'])) {
    $decTP = json_decode((string)$t['table_places'], true);
    if (is_array($decTP)) {
        $tablePlaces = $decTP;
    }
}

$byTable = [];
foreach ($games as $g) {
    $byTable[(int)$g['table_no']][] = $g;
}
$multi = count($byTable) > 1;
ksort($byTable);

if ($multi) {
    echo '<div class="tables-grid" style="grid-template-columns:repeat(' . count($byTable) . ',minmax(0,1fr));">';
}
foreach ($byTable as $tableNo => $tGames) {
    echo $multi ? '<div class="table-col">' : '';
    if ($multi) {
        $plc = trim((string)($tablePlaces[$tableNo - 1] ?? ''));
        $jid = $tableJudgeId($tableNo);
        $jname = ($jid && isset($judgeMap[$jid])) ? $judgeMap[$jid]['nickname'] : '';
        echo '<h2 style="margin:4px 0 8px;">Стол ' . $tableNo
            . ($plc !== '' ? ' <span style="color:var(--tx2);font-size:14px;font-weight:400;">· ' . esc($plc) . '</span>' : '')
            . ($jname !== '' ? ' <span style="color:var(--tx2);font-size:13px;font-weight:400;">· судья: ' . esc($jname) . '</span>' : '') . '</h2>';
    }
    foreach ($tGames as $g) {
        $seats = $seatsByGame[(int)$g['id']] ?? [];
        $totals = game_display_totals($g, $seats);
        echo '<div class="card' . ($multi ? ' card-compact' : '') . '">';
        echo '<div class="section-head"><h2 style="margin:0;font-size:15px;">Игра ' . (int)$g['game_no'] . '</h2>';
        if ($g['winner']) {
            echo '<span class="tag ' . ($g['winner'] === 'red' ? 'tag-red' : ($g['winner'] === 'draw' ? 'tag-draw' : 'tag-black')) . '">'
                . esc($winLabel[$g['winner']]) . '</span>';
        }
        echo '</div>';
        if (!$multi && $g['judge_nick']) {
            echo '<p style="color:var(--tx2);font-size:13px;margin:2px 0 6px;">судья: ' . esc($g['judge_nick']) . '</p>';
        }
        echo '<div style="overflow-x:auto;"><table class="tbl"' . ($multi ? ' style="font-size:12.5px;"' : '') . '>';
        if ($multi) {
            echo '<tr><th>#</th><th>Игрок</th><th>Роль</th><th class="num">Итог</th></tr>';
        } else {
            echo '<tr><th>#</th><th>Игрок</th><th>Роль</th><th class="num">+</th><th class="num">−</th><th class="num">Итог</th></tr>';
        }
        foreach ($seats as $s) {
            $tt = $totals[(int)$s['seat']] ?? ['total' => 0, 'is_pu' => false];
            echo '<tr><td>' . (int)$s['seat'] . '</td>'
                . '<td><a href="/player.php?id=' . (int)$s['player_id'] . '" style="color:var(--tx);">' . esc($s['nickname']) . '</a>'
                . ($tt['is_pu'] ? ' <span class="tag">ПУ</span>' : '') . '</td>'
                . '<td>' . role_dot($s['role']) . $roleLabel[$s['role']] . '</td>';
            if (!$multi) {
                echo '<td class="num">' . ((float)$s['plus'] ? number_format((float)$s['plus'], 1) : '') . '</td>'
                    . '<td class="num">' . ((float)$s['minus'] ? number_format((float)$s['minus'], 1) : '') . '</td>';
            }
            echo '<td class="num"><b>' . number_format($tt['total'], 2) . '</b></td></tr>';
        }
        echo '</table></div></div>';
    }
    echo $multi ? '</div>' : '';
}
if ($multi) {
    echo '</div>';
}
page_foot();
