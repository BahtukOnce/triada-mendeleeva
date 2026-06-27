<?php
require dirname(__DIR__) . '/inc/bootstrap.php';
require ROOT . '/inc/rating.php';

$id = (int)($_GET['id'] ?? 0);

// Запись/отмена прямо со страницы вечера
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['form'] ?? '', ['day_reg', 'day_cancel'], true)) {
    $u = require_login();
    csrf_check();
    $player = current_player();
    if (!$player) {
        flash_set('err', 'Сначала привяжите игровой ник в личном кабинете');
        redirect('/cabinet.php');
    }
    if ($_POST['form'] === 'day_reg') {
        $st = db()->prepare("SELECT id FROM game_days WHERE id = ? AND status = 'reg_open'");
        $st->execute([$id]);
        if ($st->fetch()) {
            $tf = preg_match('/^\d{2}:\d{2}$/', (string)($_POST['time_from'] ?? '')) ? $_POST['time_from'] : null;
            $tt = preg_match('/^\d{2}:\d{2}$/', (string)($_POST['time_to'] ?? '')) ? $_POST['time_to'] : null;
            db()->prepare('INSERT INTO day_registrations (day_id, player_id, time_from, time_to, comment)
                VALUES (?,?,?,?,?)
                ON DUPLICATE KEY UPDATE time_from = VALUES(time_from), time_to = VALUES(time_to),
                    comment = VALUES(comment), cancelled_at = NULL')
                ->execute([$id, (int)$player['id'], $tf, $tt, trim((string)($_POST['comment'] ?? '')) ?: null]);
            log_action((int)$u['id'], 'day_register', ['day_id' => $id]);
            flash_set('ok', 'Вы записаны!');
        } else {
            flash_set('err', 'Запись на этот вечер закрыта');
        }
    } else {
        db()->prepare('UPDATE day_registrations SET cancelled_at = NOW() WHERE day_id = ? AND player_id = ?')
            ->execute([$id, (int)$player['id']]);
        log_action((int)$u['id'], 'day_cancel', ['day_id' => $id]);
        flash_set('ok', 'Запись отменена');
    }
    redirect('/day.php?id=' . $id);
}
$day = null;
$games = [];
$seatsByGame = [];

if ($id && db_ready()) {
    $st = db()->prepare('SELECT * FROM game_days WHERE id = ?');
    $st->execute([$id]);
    $day = $st->fetch() ?: null;
    if ($day) {
        $st = db()->prepare("SELECT g.*, jp.nickname AS judge_nick, jp.id AS judge_id
            FROM games g LEFT JOIN players jp ON jp.id = g.judge_player_id
            WHERE g.day_id = ? ORDER BY g.table_no, g.game_no");
        $st->execute([$id]);
        $games = $st->fetchAll();
        if ($games) {
            $ids = array_column($games, 'id');
            $in = implode(',', array_fill(0, count($ids), '?'));
            $st = db()->prepare("SELECT gs.*, p.nickname, p.avatar, p.flair, p.elo FROM game_seats gs
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
$winLabel = ['red' => 'Победа красных', 'black' => 'Победа чёрных', 'draw' => 'Ничья'];

page_head($day ? ('Вечер ' . $day['title']) : 'Вечер не найден', 'days');

if (!$day) {
    empty_state('Вечер не найден', 'Возможно, ссылка устарела.');
    echo '<p style="text-align:center;"><a href="/days.php">← Все вечера</a></p>';
    page_foot();
    exit;
}

$canEdit = user_can_judge(current_user());

echo '<h1>' . esc($day['title']) . ' · ' . esc(date('d.m.Y', strtotime($day['date']))) . '</h1>';
echo '<p style="color:var(--tx2);margin-top:-6px;">Игр сыграно: ' . count($games)
    . ($day['location'] ? ' · ' . esc($day['location']) : '') . '</p>';
if ($canEdit) {
    echo '<p style="margin:0 0 12px;"><a class="btn" href="/admin/protocol.php?day=' . $id . '">Вести / редактировать игры</a></p>';
}

if (in_array($day['status'], ['reg_open', 'reg_closed'], true)) {
    $st = db()->prepare('SELECT r.*, p.nickname, p.avatar, p.flair, p.id AS pid FROM day_registrations r
        JOIN players p ON p.id = r.player_id
        WHERE r.day_id = ? AND r.cancelled_at IS NULL ORDER BY r.created_at');
    $st->execute([$id]);
    $regs = $st->fetchAll();
    echo '<div class="card card-accent">';
    echo '<div class="section-head"><h2 style="margin:0;">Записавшиеся (' . count($regs) . ')</h2>';
    echo '<span class="tag ' . ($day['status'] === 'reg_open' ? 'tag-open' : '') . '">'
        . ($day['status'] === 'reg_open' ? 'запись открыта' : 'запись закрыта') . '</span></div>';
    if ($regs) {
        echo '<div class="admin-list" style="margin-top:10px;">';
        foreach ($regs as $r) {
            $time = $r['time_from'] ? substr($r['time_from'], 0, 5) . '–' . substr((string)$r['time_to'], 0, 5) : '';
            echo '<div class="admin-item">' . avatar_html(['nickname' => $r['nickname'], 'avatar' => $r['avatar']], 28);
            echo '<div><div class="nm"><a href="/player.php?id=' . (int)$r['pid'] . '" style="color:var(--tx);">'
                . esc($r['nickname']) . '</a>'
                . (!empty($r['flair']) ? ' <span class="flair">' . esc($r['flair']) . '</span>' : '') . '</div>'
                . ($time ? '<div class="rl">' . $time . '</div>' : '') . '</div></div>';
        }
        echo '</div>';
    } else {
        echo '<p style="color:var(--tx2);font-size:14px;margin:8px 0 0;">Пока никто не записался — будьте первым!</p>';
    }
    if ($day['status'] === 'reg_open') {
        $me = current_user();
        $myPlayer = current_player();
        $myReg = null;
        if ($myPlayer) {
            foreach ($regs as $r) {
                if ((int)$r['pid'] === (int)$myPlayer['id']) {
                    $myReg = $r;
                }
            }
        }
        echo '<div style="margin:14px 0 0;border-top:1px solid var(--bd);padding-top:12px;">';
        if (!$me) {
            echo '<a class="btn" href="/login.php">Войти и записаться</a>';
        } elseif (!$myPlayer) {
            echo '<a class="btn" href="/cabinet.php">Привязать ник и записаться</a>';
        } elseif ($myReg) {
            echo '<form method="post" action="/day.php?id=' . $id . '" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">' . csrf_field();
            echo '<input type="hidden" name="form" value="day_cancel">';
            echo '<span style="color:var(--ok);">✓ Вы записаны'
                . ($myReg['time_from'] ? ' (' . substr($myReg['time_from'], 0, 5) . '–' . substr((string)$myReg['time_to'], 0, 5) . ')' : '') . '</span>';
            echo '<button class="btn btn-ghost" type="submit">Отменить запись</button></form>';
        } else {
            echo '<form method="post" action="/day.php?id=' . $id . '">' . csrf_field();
            echo '<input type="hidden" name="form" value="day_reg">';
            echo '<div style="display:flex;gap:10px;flex-wrap:wrap;align-items:end;">';
            echo '<div class="field" style="margin:0;"><label>Могу с</label><input type="time" name="time_from"></div>';
            echo '<div class="field" style="margin:0;"><label>до</label><input type="time" name="time_to"></div>';
            echo '<div class="field" style="margin:0;flex:1;min-width:150px;"><label>Комментарий</label><input type="text" name="comment" placeholder="необязательно"></div>';
            echo '<button class="btn" type="submit">Записаться</button></div></form>';
        }
        echo '</div>';
    }
    echo '</div>';
}

// ── Рейтинг вечера ──
if ($games) {
    $standing = [];
    foreach ($games as $g) {
        $seats = $seatsByGame[(int)$g['id']] ?? [];
        $tt = game_display_totals($g, $seats);
        foreach ($seats as $s) {
            $pid = (int)$s['player_id'];
            $standing[$pid] = $standing[$pid] ?? ['nick' => $s['nickname'], 'avatar' => $s['avatar'], 'flair' => $s['flair'] ?? '', 'elo' => $s['elo'], 'games' => 0, 'sum' => 0.0];
            $standing[$pid]['games']++;
            $standing[$pid]['sum'] += $tt[(int)$s['seat']]['total'] ?? 0;
        }
    }
    uasort($standing, fn($a, $b) => $b['sum'] <=> $a['sum']);
    // изменение ELO за вечер (сумма дельт по играм этого дня)
    $eloDayDelta = [];
    try {
        $gidsD = array_column($games, 'id');
        $inD = implode(',', array_fill(0, count($gidsD), '?'));
        $stD = db()->prepare("SELECT player_id, SUM(delta) AS d FROM elo_history WHERE game_id IN ($inD) GROUP BY player_id");
        $stD->execute($gidsD);
        foreach ($stD->fetchAll() as $er) {
            $eloDayDelta[(int)$er['player_id']] = (float)$er['d'];
        }
    } catch (Throwable $e) {
    }
    $eloDeltaFmt = function (?float $d): string {
        if ($d === null) {
            return '<span style="color:var(--tx3);">—</span>';
        }
        $r = (int)round($d);
        if ($r === 0) {
            return '<span style="color:var(--tx3);">±0</span>';
        }
        return '<span style="color:' . ($r > 0 ? 'var(--ok)' : 'var(--ac)') . ';font-weight:600;">' . ($r > 0 ? '+' : '−') . abs($r) . '</span>';
    };
    echo '<div class="card"><h2 style="margin-top:0;">Рейтинг вечера</h2>';
    echo '<table class="tbl sortable"><thead><tr><th data-type="num">#</th><th>Игрок</th>'
        . '<th class="num" data-type="num">Игр</th><th class="num" data-type="num">Σ за вечер</th>'
        . '<th class="num" data-type="num">ELO</th><th class="num" data-type="num">ЭЛО за вечер</th></tr></thead><tbody>';
    $pos = 0;
    foreach ($standing as $pid => $row) {
        $pos++;
        echo '<tr' . ($pos <= 3 ? ' class="rt-top"' : '') . '>'
            . '<td data-sort="' . $pos . '">' . ($pos <= 3 ? '<span style="font-size:15px;">' . rank_medal($pos) . '</span>' : $pos) . '</td>'
            . '<td><a href="/player.php?id=' . $pid . '" style="color:var(--tx);">'
            . avatar_html(['nickname' => $row['nick'], 'avatar' => $row['avatar']], 24, 'margin-right:7px;')
            . '<span style="vertical-align:middle;">' . esc($row['nick'])
            . (!empty($row['flair']) ? ' <span class="flair">' . esc($row['flair']) . '</span>' : '') . '</span></a></td>'
            . '<td class="num" data-sort="' . $row['games'] . '">' . $row['games'] . '</td>'
            . '<td class="num" data-sort="' . round($row['sum'], 2) . '"><b>' . number_format($row['sum'], 2) . '</b></td>'
            . '<td class="num" data-sort="' . (float)$row['elo'] . '">' . number_format((float)$row['elo'], 0, '.', '') . '</td>'
            . '<td class="num" data-sort="' . round($eloDayDelta[$pid] ?? 0, 1) . '">' . $eloDeltaFmt($eloDayDelta[$pid] ?? null) . '</td></tr>';
    }
    echo '</tbody></table></div>';
}

// ── Игры вечера (сеткой) ──
if ($games) {
    // ELO-дельты по играм (+ ELO до игры для среднего по столу)
    $eloDelta = [];
    $eloBefore = [];
    try {
        $gids = array_column($games, 'id');
        $in = implode(',', array_fill(0, count($gids), '?'));
        $st = db()->prepare("SELECT game_id, player_id, delta, elo_after FROM elo_history WHERE game_id IN ($in)");
        $st->execute($gids);
        foreach ($st->fetchAll() as $row) {
            $eloDelta[(int)$row['game_id']][(int)$row['player_id']] = (float)$row['delta'];
            $eloBefore[(int)$row['game_id']][(int)$row['player_id']] = (float)$row['elo_after'] - (float)$row['delta'];
        }
    } catch (Throwable $e) {
    }

    echo '<h2>Игры вечера</h2>';
    echo '<div class="tables-grid" style="grid-template-columns:repeat(auto-fit,minmax(330px,1fr));">';
    foreach ($games as $g) {
        $seats = $seatsByGame[(int)$g['id']] ?? [];
        $totals = game_display_totals($g, $seats);
        $winTag = $g['winner'] === 'red' ? 'tag-red' : ($g['winner'] === 'black' ? 'tag-black' : 'tag-draw');

        echo '<div class="card card-compact" id="game-' . (int)$g['id'] . '">';
        echo '<div class="section-head"><h2 style="margin:0;font-size:15px;">Игра ' . (int)$g['game_no'] . '</h2><span>';
        if ($g['winner']) {
            echo '<span class="tag ' . $winTag . '">' . esc($winLabel[$g['winner']]) . '</span>';
        }
        if ($canEdit) {
            echo ' <a class="tag" href="/admin/protocol.php?day=' . $id . '&game=' . (int)$g['id'] . '">изменить</a>';
        }
        echo '</span></div>';
        if ($g['judge_nick']) {
            echo '<p style="color:var(--tx2);font-size:12px;margin:2px 0 6px;">судья: '
                . '<a href="/player.php?id=' . (int)$g['judge_id'] . '">' . esc($g['judge_nick']) . '</a></p>';
        }
        $tblElos = [];
        foreach ($seats as $s) {
            $eb = $eloBefore[(int)$g['id']][(int)$s['player_id']] ?? null;
            if ($eb !== null) {
                $tblElos[] = $eb;
            }
        }
        if ($tblElos) {
            echo '<p style="color:var(--tx2);font-size:12px;margin:2px 0 6px;">средний ELO стола: '
                . '<b style="color:var(--tx);">' . number_format(array_sum($tblElos) / count($tblElos), 0, '.', '') . '</b></p>';
        }
        echo '<table class="tbl" style="font-size:12.5px;">';
        echo '<tr><th>#</th><th>Игрок</th><th>Роль</th><th class="num">Итог</th><th class="num">ELO</th></tr>';
        foreach ($seats as $s) {
            $t = $totals[(int)$s['seat']] ?? ['total' => 0, 'is_pu' => false];
            $isBlack = in_array($s['role'], ['maf', 'don'], true);
            $ed = $eloDelta[(int)$g['id']][(int)$s['player_id']] ?? null;
            $edHtml = '';
            if ($ed !== null) {
                $edHtml = $ed >= 0
                    ? '<span style="color:var(--ok);">+' . number_format($ed, 1) . '</span>'
                    : '<span style="color:var(--ac);">' . number_format($ed, 1) . '</span>';
            }
            echo '<tr><td>' . (int)$s['seat'] . '</td>'
                . '<td><a href="/player.php?id=' . (int)$s['player_id'] . '" style="color:var(--tx);">' . esc($s['nickname']) . '</a>'
                . (!empty($s['flair']) ? ' <span class="flair">' . esc($s['flair']) . '</span>' : '')
                . ($t['is_pu'] ? ' <span class="tag">ПУ</span>' : '') . '</td>'
                . '<td style="white-space:nowrap;">' . role_dot($s['role']) . ($isBlack ? '<b>' . $roleLabel[$s['role']] . '</b>' : $roleLabel[$s['role']]) . '</td>'
                . '<td class="num"><b>' . number_format($t['total'], 2) . '</b></td>'
                . '<td class="num" style="font-size:11.5px;">' . $edHtml . '</td></tr>';
        }
        echo '</table>';
        $bm = array_filter([(int)$g['bm_seat1'], (int)$g['bm_seat2'], (int)$g['bm_seat3']]);
        $meta = [];
        if ($g['first_killed_seat']) {
            $meta[] = 'ПУ: ' . (int)$g['first_killed_seat'];
        }
        if ($bm) {
            $meta[] = 'ЛХ: ' . implode(', ', $bm);
        }
        if ($meta) {
            echo '<p style="color:var(--tx2);font-size:12px;margin:8px 0 0;">' . implode(' · ', $meta) . '</p>';
        }
        echo '</div>';
    }
    echo '</div>';
} else {
    empty_state('Протоколов пока нет', 'Игры этого вечера ещё не записаны.'
        . ($canEdit ? ' Нажмите «Вести / редактировать игры», чтобы добавить.' : ''));
}
page_foot();
