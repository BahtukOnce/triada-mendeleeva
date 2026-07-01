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
    // Управление турниром (скрытие таблицы, пересадка) — админ или НАЗНАЧЕННЫЙ на турнир судья
    if (in_array($act, ['toggle_standings', 'reseat'], true)) {
        $tm = db()->prepare('SELECT main_judge_player_id, table_judges FROM tournaments WHERE id = ?');
        $tm->execute([$id]);
        if (!tournament_can_manage($cu, $tm->fetch() ?: null)) {
            flash_set('err', 'Управлять турниром может админ или назначенный на него судья');
            redirect('/tournament.php?id=' . $id);
        }
    }
    // Скрыть/открыть итоговую таблицу для игроков
    if ($act === 'toggle_standings') {
        $cur = (int)(db()->query('SELECT standings_hidden FROM tournaments WHERE id = ' . $id)->fetchColumn() ?: 0);
        db()->prepare('UPDATE tournaments SET standings_hidden = ? WHERE id = ?')->execute([$cur ? 0 : 1, $id]);
        flash_set('ok', $cur ? 'Таблица снова видна игрокам' : 'Таблица скрыта от игроков');
    }
    // Пересадка КОНКРЕТНОЙ несыгранной игры случайным образом (ручная, только по кнопке)
    if ($act === 'reseat') {
        $gid = (int)($_POST['game_id'] ?? 0);
        $gq = db()->prepare("SELECT id FROM games WHERE id = ? AND tournament_id = ? AND status = 'draft'");
        $gq->execute([$gid, $id]);
        if ($gq->fetchColumn()) {
            $sq = db()->prepare('SELECT player_id FROM game_seats WHERE game_id = ? ORDER BY seat');
            $sq->execute([$gid]);
            $pids = array_map('intval', $sq->fetchAll(PDO::FETCH_COLUMN));
            if ($pids) {
                shuffle($pids);
                $pdo = db();
                $pdo->beginTransaction();
                $pdo->prepare('DELETE FROM game_seats WHERE game_id = ?')->execute([$gid]);
                $insR = $pdo->prepare("INSERT INTO game_seats (game_id, seat, player_id, role) VALUES (?,?,?,'civ')");
                foreach ($pids as $i => $pid) {
                    $insR->execute([$gid, $i + 1, $pid]);
                }
                $pdo->commit();
                flash_set('ok', 'Игра пересажена случайным образом');
            }
        } else {
            flash_set('err', 'Пересадить можно только несыгранную игру этого турнира');
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
    if ($t && !empty($t['legacy_rating_id'])) {
        // исторический турнир без сыгранных игр — открываем его итоговую таблицу
        header('Location: /rating.php?r=' . (int)$t['legacy_rating_id'], true, 302);
        exit;
    }
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

// ── Главный судья — отдельной плашкой в самом верху страницы ──
$mjId = (int)($t['main_judge_player_id'] ?? 0);
if ($mjId) {
    $mjq = db()->prepare('SELECT nickname, avatar, elo,
        (SELECT COUNT(*) FROM games gg WHERE gg.judge_player_id = players.id) AS judged
        FROM players WHERE id = ?');
    $mjq->execute([$mjId]);
    $mj = $mjq->fetch();
    if ($mj) {
        $sub = [];
        if ($mj['elo'] !== null) {
            $sub[] = 'ЭЛО ' . (int)round((float)$mj['elo']);
        }
        if ((int)($mj['judged'] ?? 0) > 0) {
            $sub[] = '<a href="/my_judged.php?id=' . $mjId . '" style="color:var(--ac);">судил игр: ' . (int)$mj['judged'] . '</a>';
        }
        echo '<div class="judge-hero" style="margin:6px 0 16px;">'
            . avatar_html(['nickname' => $mj['nickname'], 'avatar' => $mj['avatar']], 54)
            . '<div><div class="jh-label">⚖ Главный судья</div>'
            . '<a class="jh-name" href="/player.php?id=' . $mjId . '">' . esc($mj['nickname']) . '</a>'
            . ($sub ? '<div class="jh-sub">' . implode(' · ', $sub) . '</div>' : '')
            . '</div></div>';
    }
}

// ── Итоговая таблица — НАВЕРХУ, считается вживую по уже СЫГРАННЫМ играм ──
// Черновики (созданная рассадка без результата, status≠finished) в таблицу не идут.
// ELO в таблице — на момент турнира (входной ELO участника), а не текущий.
$finishedGames = array_values(array_filter($games, fn($g) => ($g['status'] ?? '') === 'finished'));
$enterElo = event_entry_elo(array_column($finishedGames, 'id'));
if ($enterElo) {
    foreach ($seatsByGame as &$seatsRef) {
        foreach ($seatsRef as &$s) {
            $pid = (int)$s['player_id'];
            if (isset($enterElo[$pid])) {
                $s['elo'] = $enterElo[$pid];
            }
        }
        unset($s);
    }
    unset($seatsRef);
}
$standing = standings_from_games($finishedGames, $seatsByGame);
$canManageT = tournament_can_manage(current_user(), $t); // админ/владелец или назначенный на турнир судья
$standingsHidden = (int)($t['standings_hidden'] ?? 0);
$resultsHidden = $standingsHidden && !$canManageT; // скрытый режим: игроки не видят ни таблицу, ни итоги игр
if ($standing && (!$standingsHidden || $canManageT)) {
    echo '<div class="card" style="overflow-x:auto;">';
    echo '<div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:10px;">';
    echo '<h2 style="margin:0;">Итоговая таблица' . ($standingsHidden ? ' <span style="font-size:13px;font-weight:600;color:#ff8c2a;">· скрыта от игроков</span>' : '') . '</h2>';
    if ($canManageT) {
        echo '<form method="post" action="/tournament.php?id=' . $id . '" style="margin:0;">' . csrf_field()
            . '<input type="hidden" name="act" value="toggle_standings">'
            . '<button class="btn btn-ghost" style="padding:5px 12px;font-size:13px;" type="submit">'
            . ($standingsHidden ? '👁 Открыть игрокам' : '🙈 Скрыть от игроков') . '</button></form>';
    }
    echo '</div>';
    echo '<table class="tbl sortable rating-tbl" style="font-size:13px;">';
    echo '<thead>'
        . '<tr class="rt-groups"><th colspan="2"></th><th class="c-elo"></th>'
        . '<th colspan="10">Баллы и суммы</th><th class="c-cards-first" colspan="5">По картам</th></tr>'
        . '<tr>'
        . '<th data-type="num">#</th><th>Игрок</th><th class="num c-elo" data-type="num" title="ELO на момент турнира">ELO</th>'
        . '<th class="num" data-type="num">Σ</th><th class="num" data-type="num">~Σ</th>'
        . '<th class="num" data-type="num">Σ+</th><th class="num" data-type="num">Игр</th><th class="num" data-type="num">ПУ</th><th class="num" data-type="num">ЛХ</th>'
        . '<th class="num" data-type="num">Допы</th><th class="num c-club" data-type="num">ср.доп</th><th class="num" data-type="num">−</th><th class="num" data-type="num">Ci</th>'
        . '<th class="c-cards c-cards-first" data-type="num">Общ</th><th class="c-cards" data-type="num">Мир</th>'
        . '<th class="c-cards" data-type="num">Маф</th><th class="c-cards" data-type="num">Шер</th><th class="c-cards" data-type="num">Дон</th>'
        . '</tr></thead><tbody>';
    $pos = 0;
    foreach ($standing as $row) {
        $pos++;
        $w = (int)$row['w_civ'] + (int)$row['w_maf'] + (int)$row['w_sher'] + (int)$row['w_don'];
        $avgDop = (int)$row['games'] ? (float)$row['dop_sum'] / (int)$row['games'] : 0;
        echo '<tr data-games="' . (int)$row['games'] . '"' . ($pos <= 3 ? ' class="rt-' . $pos . '"' : '') . '>';
        echo '<td data-sort="' . $pos . '">' . ($pos <= 3 ? '<span style="font-size:15px;">' . rank_medal($pos) . '</span>' : $pos) . '</td>';
        echo '<td><a class="rt-player" href="/player.php?id=' . (int)$row['pid'] . '" style="color:var(--tx);">'
            . avatar_html(['nickname' => $row['nick'], 'avatar' => $row['avatar']], 26, 'margin-right:8px;')
            . '<span>' . esc($row['nick']) . casper_ghost($row['nick']) . '</span></a></td>';
        echo '<td class="num c-elo" data-sort="' . (float)$row['elo'] . '"><b>' . number_format((float)$row['elo'], 0, '.', '') . '</b></td>';
        echo '<td class="num" data-sort="' . (float)$row['sum'] . '">' . number_format((float)$row['sum'], 2) . '</td>';
        echo '<td class="num" data-sort="' . (float)$row['avg_total'] . '">' . number_format((float)$row['avg_total'], 2) . '</td>';
        echo '<td class="num" data-sort="' . (float)$row['sum_plus'] . '">' . number_format((float)$row['sum_plus'], 2) . '</td>';
        echo '<td class="num" data-sort="' . (int)$row['games'] . '">' . (int)$row['games'] . '</td>';
        echo '<td class="num" data-sort="' . (int)$row['pu_count'] . '">' . (int)$row['pu_count'] . '</td>';
        echo '<td class="num" data-sort="' . (float)$row['lh_sum'] . '">' . number_format((float)$row['lh_sum'], 1) . '</td>';
        echo '<td class="num" data-sort="' . (float)$row['dop_sum'] . '">' . number_format((float)$row['dop_sum'], 1) . '</td>';
        echo '<td class="num c-club" data-sort="' . round($avgDop, 3) . '"><b>' . number_format($avgDop, 2) . '</b></td>';
        echo '<td class="num" data-sort="' . (float)$row['minus_sum'] . '">' . number_format((float)$row['minus_sum'], 1) . '</td>';
        echo '<td class="num" data-sort="' . (float)$row['ci_sum'] . '">' . number_format((float)$row['ci_sum'], 2) . '</td>';
        echo str_replace('c-cards"', 'c-cards c-cards-first"', wr_cell($w, (int)$row['games'], (float)$row['dop_sum']));
        echo wr_cell((int)$row['w_civ'], (int)$row['g_civ']);
        echo wr_cell((int)$row['w_maf'], (int)$row['g_maf']);
        echo wr_cell((int)$row['w_sher'], (int)$row['g_sher']);
        echo wr_cell((int)$row['w_don'], (int)$row['g_don']);
        echo '</tr>';
    }
    echo '</tbody></table>';
    echo '<p style="color:var(--tx2);font-size:12.5px;margin:8px 2px 0;">Σ — сумма итогов; Σ+ — допы + ЛХ + Ci; ~Σ — средний балл; ПУ — первоубиенный; ЛХ — лучший ход; Ci — компенсации. Клик по заголовку — сортировка.</p>';
    echo '</div>';
} elseif ($standing && $standingsHidden && !$canManageT) {
    echo '<div class="card"><p style="margin:0;color:var(--tx2);">📊 Итоговая таблица временно скрыта судьёй — будет открыта позже.</p></div>';
}
$participants = [];
foreach ($seatsByGame as $seats) {
    foreach ($seats as $s) {
        $participants[(int)$s['player_id']] = 1;
    }
}
// дата и место выводятся блоками в инфо-строке ниже

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
// ── Инфо-строка: статы + главный судья + дресс-код в одну строку ──
$plural = function (int $n, string $a, string $b, string $c): string {
    $d = $n % 10; $h = $n % 100;
    if ($d === 1 && $h !== 11) { return $a; }
    if ($d >= 2 && $d <= 4 && ($h < 10 || $h >= 20)) { return $b; }
    return $c;
};
$monthsRu = [1 => 'января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];
$ruDate = function ($d) use ($monthsRu): string {
    $ts = strtotime((string)$d);
    return (int)date('j', $ts) . ' ' . ($monthsRu[(int)date('n', $ts)] ?? '') . ' ' . date('Y', $ts);
};
$nT = (int)$t['tables_count'];
$nG = count($games);
$nP = count($participants);
if ($nP === 0) { // предстоящий турнир — берём подтверждённый состав
    try {
        $cq = db()->prepare("SELECT COUNT(*) FROM tournament_participants WHERE tournament_id = ? AND state = 'confirmed'");
        $cq->execute([$id]);
        $nP = (int)$cq->fetchColumn();
    } catch (Throwable $e) {
    }
}
// Когда турнир идёт/сверяется/завершён — в шапке оставляем только главного судью
$isRunning = in_array((string)($t['status'] ?? ''), ['live', 'review', 'finished'], true);
$blocks = [];
if (!$isRunning) {
    $blocks[] = '<div class="t-stats">'
        . '<div><b>' . $nT . '</b><span>' . $plural($nT, 'стол', 'стола', 'столов') . '</span></div>'
        . '<div><b>' . $nG . '</b><span>' . $plural($nG, 'игра', 'игры', 'игр') . '</span></div>'
        . '<div><b>' . $nP . '</b><span>' . $plural($nP, 'участник', 'участника', 'участников') . '</span></div></div>';
}
if (!$isRunning && !empty($t['date_from'])) {
    $dStr = $ruDate($t['date_from']);
    if (!empty($t['date_to']) && $t['date_to'] !== $t['date_from']) {
        $dStr = (int)date('j', strtotime((string)$t['date_from'])) . '–' . $ruDate($t['date_to']);
    }
    $blocks[] = '<div class="info-block"><span class="ib-ic">📅</span><div style="min-width:0;"><div class="ib-label">Дата</div><div class="ib-val">' . esc($dStr) . '</div></div></div>';
}
if (!$isRunning && !empty($t['location'])) {
    $blocks[] = '<div class="info-block"><span class="ib-ic">📍</span><div style="min-width:0;"><div class="ib-label">Место</div><div class="ib-val">' . esc((string)$t['location']) . '</div></div></div>';
}
// главный судья теперь рисуется отдельной плашкой в самом верху (см. выше)
if (!$isRunning && !empty($t['dress_code'])) {
    $blocks[] = '<div class="info-block accent"><span class="ib-ic">👔</span>'
        . '<div style="min-width:0;"><div class="ib-label">Дресс-код</div>'
        . '<div class="ib-val">' . esc($t['dress_code']) . '</div></div></div>';
}
if ($blocks) {
    echo '<div class="t-inforow">' . implode('', $blocks) . '</div>';
}

// судьи столов 2+ (до начала турнира; когда идёт+ — оставляем только главного судью)
if (!$isRunning && $mainJudgeId && isset($judgeMap[$mainJudgeId])) {
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

if (user_can_judge(current_user())) {
    echo '<p style="margin:0 0 12px;">';
    if ($canManageT) {
        echo '<a class="btn" href="/admin/tournaments.php?edit=' . $id . '">Редактировать турнир</a> ';
    }
    echo '<a class="btn btn-ghost" href="/admin/tournaments.php">Все турниры / создать</a></p>';
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

// Игры/винрейт/средний доп по ролям — за ТЕКУЩИЙ сезон, из game_seats (вечера + турниры),
// чтобы попадали и те, кто играл только турниры (нет строки в rating_cache).
[$seasonStart, $seasonEnd] = current_season_bounds();
$dopByRole = [];
$seasonAgg = []; // player_id => ['games'=>, 'wins'=>]
if ($rConfirmed) {
    $pids = array_map(fn($r) => (int)$r['player_id'], $rConfirmed);
    $in2 = implode(',', array_fill(0, count($pids), '?'));
    $dq = db()->prepare("SELECT gs.player_id, gs.role, AVG(gs.plus) AS avg_dop, COUNT(*) AS g,
            SUM(CASE WHEN (g.winner='red' AND gs.role IN ('civ','sheriff'))
                      OR (g.winner='black' AND gs.role IN ('maf','don')) THEN 1 ELSE 0 END) AS w
        FROM game_seats gs JOIN games g ON g.id = gs.game_id
        LEFT JOIN game_days d ON d.id = g.day_id
        LEFT JOIN tournaments t ON t.id = g.tournament_id
        WHERE gs.player_id IN ($in2) AND g.status = 'finished' AND g.winner IS NOT NULL
          AND COALESCE(d.date, t.date_from) BETWEEN ? AND ?
        GROUP BY gs.player_id, gs.role");
    $dq->execute(array_merge($pids, [$seasonStart, $seasonEnd]));
    foreach ($dq->fetchAll() as $row) {
        $pid0 = (int)$row['player_id'];
        $dopByRole[$pid0][$row['role']] = ['avg' => (float)$row['avg_dop'], 'g' => (int)$row['g']];
        $seasonAgg[$pid0]['games'] = ($seasonAgg[$pid0]['games'] ?? 0) + (int)$row['g'];
        $seasonAgg[$pid0]['wins'] = ($seasonAgg[$pid0]['wins'] ?? 0) + (int)$row['w'];
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

// Карточка «Участники» нужна до начала турнира; когда идёт/сверяется/завершён — прячем
if (!$isRunning && ($rosterRows || $regOpen)) {
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
            $out = '';
            foreach (['civ', 'maf', 'sheriff', 'don'] as $rk) {
                $d = $dopByRole[$pid][$rk] ?? null;
                $out .= ($d && $d['g'] > 0)
                    ? '<span title="' . esc($roleLabel[$rk]) . '" style="color:var(--tx);">' . number_format($d['avg'], 2, '.', '') . '</span>'
                    : '<span title="' . esc($roleLabel[$rk]) . '" style="color:var(--tx3);">·</span>';
            }
            return '<span style="display:inline-grid;grid-template-columns:repeat(4,42px);gap:4px;text-align:center;font-variant-numeric:tabular-nums;font-size:12.5px;">' . $out . '</span>';
        };
        echo '<div style="overflow-x:auto;"><table class="tbl tp-tbl"><tr>'
            . '<th class="num">#</th><th class="tp-name-col">Игрок</th><th class="num">ELO</th><th class="num">Игр</th><th class="num">Винрейт</th>'
            . '<th>Любимая карта</th>'
            . '<th style="text-align:center;">Ср. доп по ролям<br><span style="font-weight:400;font-size:10px;display:inline-grid;grid-template-columns:repeat(4,42px);gap:4px;text-align:center;">'
            . '<span style="color:#e8332a;">мир</span><span style="color:var(--tx3);">маф</span><span style="color:#e6b13a;">шер</span><span style="color:var(--tx3);">дон</span></span></th>'
            . '<th class="num">Клубный рейтинг</th></tr>';
        $pos = 0;
        foreach ($rConfirmed as $r) {
            $pos++;
            $pid0 = (int)$r['player_id'];
            $g = (int)($seasonAgg[$pid0]['games'] ?? 0);
            $wr = $g > 0 ? (round((int)($seasonAgg[$pid0]['wins'] ?? 0) / $g * 100) . '%') : '—';
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
                . '<td style="white-space:nowrap;text-align:center;">' . $roleDopCell((int)$r['player_id']) . '</td>'
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

// Номинации турнира (итоговая таблица отрисована выше, у шапки; $standing уже посчитан)
if ($standing && !$resultsHidden) {
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
            $dop = (float)$s['plus']; // номинации — по сумме ДОПОВ (плюсов), без минусов
            $roleNet[$pid]['all'] += $dop;
            $roleNet[$pid]['gall']++;
            $rl = $s['role'];
            if (isset($roleNet[$pid][$rl])) {
                $roleNet[$pid][$rl] += $dop;
                $roleNet[$pid]['g' . $rl]++;
            }
        }
    }
    // Топ-3 по номинации: только сыгравшие роль и с ненулевой суммой допов (нули не показываем)
    $topN = function (string $key, string $gkey) use ($roleNet): array {
        $rows = array_filter($roleNet, fn($r) => $r[$gkey] >= 1 && (float)$r[$key] > 0);
        usort($rows, fn($a, $b) => [(float)$b[$key], $b[$gkey]] <=> [(float)$a[$key], $a[$gkey]]);
        return array_slice(array_values($rows), 0, 3);
    };
    $plg = function (int $n): string {
        $w = ($n % 10 === 1 && $n % 100 !== 11) ? 'игра'
            : ((in_array($n % 10, [2, 3, 4], true) && !in_array($n % 100, [12, 13, 14], true)) ? 'игры' : 'игр');
        return $n . ' ' . $w;
    };
    // Строка призёра (2-е/3-е место) — компактно под крупным первым
    $runner = function (array $r, int $pos, string $key, string $gkey) use ($plg): string {
        $val = (float)$r[$key];
        return '<div style="display:flex;align-items:center;gap:8px;padding:6px 2px 0;border-top:1px solid var(--bd);margin-top:8px;">'
            . '<span style="width:16px;text-align:center;color:var(--tx3);font-weight:800;">' . $pos . '</span>'
            . avatar_html(['nickname' => $r['nick'], 'avatar' => $r['avatar']], 28, '')
            . '<a href="/player.php?id=' . (int)$r['pid'] . '" style="flex:1;color:var(--tx);font-weight:600;text-decoration:none;">' . esc($r['nick']) . '</a>'
            . '<span style="color:var(--tx3);font-size:12px;white-space:nowrap;">' . $plg((int)$r[$gkey]) . '</span>'
            . '<b style="color:var(--ok);min-width:44px;text-align:right;">+' . number_format($val, 1) . '</b>'
            . '</div>';
    };
    $nomCard = function (string $title, array $rows, string $key, string $gkey, string $sub, string $brd) use ($plg, $runner): string {
        if (!$rows) {
            return '<div class="card mate-card"><div class="mate-ttl">' . $title . '</div>'
                . '<p style="color:var(--tx2);margin:0;">Нет отличившихся.</p></div>';
        }
        $r = $rows[0];
        $val = (float)$r[$key];
        $h = '<div class="card mate-card" style="border-color:' . $brd . ';">'
            . '<div class="mate-ttl">' . $title . '</div><div class="mate-body">'
            . avatar_html(['nickname' => $r['nick'], 'avatar' => $r['avatar']], 52, 'background:var(--acsf);color:var(--ac);')
            . '<div class="mate-info"><a class="mate-name" href="/player.php?id=' . (int)$r['pid'] . '">' . esc($r['nick']) . '</a>'
            . '<div class="mate-sub">' . $sub . ' · ' . $plg((int)$r[$gkey]) . '</div></div>'
            . '<div class="mate-wr" style="color:var(--ok);">+' . number_format($val, 1) . '<span>допов</span></div>'
            . '</div>';
        for ($i = 1; $i < count($rows); $i++) {
            $h .= $runner($rows[$i], $i + 1, $key, $gkey);
        }
        return $h . '</div>';
    };
    echo '<h2 style="margin:16px 0 4px;">Номинации турнира</h2>';
    echo '<p style="color:var(--tx2);font-size:13px;margin:0 0 10px;">по сумме допов за турнир (только плюсы, без минусов)</p>';
    echo '<div class="noms-grid">';
    echo $nomCard('🏆 MVP турнира', $topN('all', 'gall'), 'all', 'gall', 'весь турнир', 'rgba(232,184,48,0.5)');
    echo $nomCard('🔴 Лучший красный', $topN('civ', 'gciv'), 'civ', 'gciv', 'мирным', 'rgba(232,51,42,0.45)');
    echo $nomCard('⭐ Лучший шериф', $topN('sheriff', 'gsheriff'), 'sheriff', 'gsheriff', 'шерифом', 'rgba(230,177,58,0.45)');
    echo $nomCard('⚫ Лучший чёрный', $topN('maf', 'gmaf'), 'maf', 'gmaf', 'мафией', 'rgba(140,140,150,0.5)');
    echo $nomCard('🎩 Лучший дон', $topN('don', 'gdon'), 'don', 'gdon', 'доном', 'rgba(90,90,100,0.6)');
    echo '</div>';

    // итоговая таблица перенесена в начало страницы (отрисована сразу после заголовка)
}

$tablePlaces = [];
if (!empty($t['table_places'])) {
    $decTP = json_decode((string)$t['table_places'], true);
    if (is_array($decTP)) {
        $tablePlaces = $decTP;
    }
}

// дистанция турнира для Ci в карточках игр — как в итоговой таблице (игры/красные-ПУ этого турнира)
$tDistGames = [];
$tDistPu = [];
foreach ($finishedGames as $fg) {
    foreach ($seatsByGame[(int)$fg['id']] ?? [] as $s) {
        $pid = (int)$s['player_id'];
        $tDistGames[$pid] = ($tDistGames[$pid] ?? 0) + 1;
        if ((int)$fg['first_killed_seat'] === (int)$s['seat'] && in_array($s['role'], ROLE_RED, true)) {
            $tDistPu[$pid] = ($tDistPu[$pid] ?? 0) + 1;
        }
    }
}
$tDistTotals = ['games' => $tDistGames, 'pu' => $tDistPu];
$byTable = [];
foreach ($games as $g) {
    $byTable[(int)$g['table_no']][] = $g;
}
$multi = count($byTable) > 1;
ksort($byTable);

// Подсказка судье, если есть несыгранные игры (рассадка создана, ждём результатов)
$draftLeft = count(array_filter($games, fn($g) => ($g['status'] ?? '') !== 'finished'));
if ($canManageT && $draftLeft > 0) {
    echo '<div class="card" style="border-color:#2fbf57;background:rgba(47,191,87,.07);margin-bottom:14px;">'
        . '<b>🎯 Идёт ввод результатов.</b> Ниже рассадка по играм — у каждой нажми <b>«Внести результат»</b> '
        . '(проставь роли и победителя). Итоговая таблица наверху пересчитывается сразу. Осталось внести: ' . $draftLeft . '.</div>';
}

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
        $isFin = ($g['status'] ?? '') === 'finished';
        $reveal = $isFin && !$resultsHidden; // в скрытом режиме игроки не видят итогов игр
        $totals = $reveal ? game_display_totals($g, $seats, $tDistTotals) : [];
        $winCss = '';
        if ($reveal && $g['winner'] === 'red') { $winCss = 'border-color:rgba(232,51,42,.6);background:rgba(232,51,42,.07);'; }
        elseif ($reveal && $g['winner'] === 'black') { $winCss = 'border-color:rgba(130,130,145,.55);background:rgba(8,8,12,.55);'; }
        elseif ($reveal && $g['winner'] === 'draw') { $winCss = 'border-color:rgba(150,150,160,.55);background:rgba(140,140,150,.08);'; }
        echo '<div class="card' . ($multi ? ' card-compact' : '') . '" id="game-' . (int)$g['id'] . '" style="' . $winCss . '">';
        echo '<div class="section-head"><h2 style="margin:0;font-size:15px;">Игра ' . (int)$g['game_no'] . '</h2>';
        if (!$g['winner'] && !$isFin) {
            echo '<span class="tag" style="opacity:.7;">ждёт результата</span>';
        }
        if ($canManageT) {
            echo ' <a class="btn btn-ghost" style="padding:3px 9px;font-size:12px;" href="/admin/tournament_protocol.php?game=' . (int)$g['id'] . '">'
                . ($isFin ? 'Изменить' : 'Внести результат') . '</a>';
            if (!$isFin) {
                echo ' <form method="post" action="/tournament.php?id=' . $id . '" style="display:inline;" onsubmit="return confirm(\'Пересадить эту игру случайным образом?\');">' . csrf_field()
                    . '<input type="hidden" name="act" value="reseat"><input type="hidden" name="game_id" value="' . (int)$g['id'] . '">'
                    . '<button class="btn btn-ghost" style="padding:3px 9px;font-size:12px;" type="submit">🎲 Пересадить</button></form>';
            }
        }
        echo '</div>';
        if (!$multi && $g['judge_nick']) {
            echo '<p style="color:var(--tx2);font-size:13px;margin:2px 0 6px;">судья: ' . esc($g['judge_nick']) . '</p>';
        }
        echo '<div style="overflow-x:auto;"><table class="tbl" style="width:auto;' . ($multi ? 'font-size:12.5px;' : '') . '">';
        if (!$reveal) {
            // черновик или скрытый режим — показываем только рассадку (без ролей/итогов)
            echo '<tr><th>#</th><th>Игрок</th></tr>';
            foreach ($seats as $s) {
                echo '<tr><td>' . (int)$s['seat'] . '</td>'
                    . '<td><a href="/player.php?id=' . (int)$s['player_id'] . '" style="color:var(--tx);">' . esc($s['nickname']) . '</a></td></tr>';
            }
            echo '</table></div></div>';
            continue;
        }
        echo '<tr><th style="width:42px">#</th><th>Игрок</th><th style="width:140px">Роль</th>'
            . '<th class="num" style="width:82px">+</th><th class="num" style="width:82px">−</th>'
            . '<th class="num" style="width:82px">ЛХ</th><th class="num" style="width:82px">Ci</th>'
            . '<th class="num" style="width:90px">Итог</th></tr>';
        foreach ($seats as $s) {
            $tt = $totals[(int)$s['seat']] ?? ['total' => 0, 'is_pu' => false];
            echo '<tr><td>' . (int)$s['seat'] . '</td>'
                . '<td><a href="/player.php?id=' . (int)$s['player_id'] . '" style="color:var(--tx);">' . esc($s['nickname']) . '</a>'
                . ($tt['is_pu'] ? ' <span class="tag">ПУ</span>' : '')
                . '</td>'
                . '<td>' . role_dot($s['role']) . $roleLabel[$s['role']] . '</td>';
            echo '<td class="num">' . ((float)$s['plus'] ? number_format((float)$s['plus'], 1) : '') . '</td>'
                . '<td class="num">' . ((float)$s['minus'] ? number_format((float)$s['minus'], 1) : '') . '</td>';
            echo '<td class="num" style="color:var(--ok);">' . (!empty($tt['lh']) ? '+' . number_format((float)$tt['lh'], 1) : '') . '</td>';
            echo '<td class="num">' . ((float)($tt['ci'] ?? 0) ? number_format((float)$tt['ci'], 2) : '') . '</td>';
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
