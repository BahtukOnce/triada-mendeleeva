<?php
require dirname(__DIR__) . '/inc/bootstrap.php';
require ROOT . '/inc/rating.php';
require_once ROOT . '/inc/day_games.php'; // карточки игр — общие с протоколом судьи
require_once ROOT . '/inc/bot_lib.php'; // «готовый стол» + уведомления админам о записи

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
            // Строгая проверка часов/минут: иначе «99:99» валит INSERT (MySQL TIME) в 500
            $tf = preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string)($_POST['time_from'] ?? '')) ? $_POST['time_from'] : null;
            $tt = preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string)($_POST['time_to'] ?? '')) ? $_POST['time_to'] : null;
            db()->prepare('INSERT INTO day_registrations (day_id, player_id, time_from, time_to, comment)
                VALUES (?,?,?,?,?)
                ON DUPLICATE KEY UPDATE time_from = VALUES(time_from), time_to = VALUES(time_to),
                    comment = VALUES(comment), cancelled_at = NULL')
                ->execute([$id, (int)$player['id'], $tf, $tt, trim((string)($_POST['comment'] ?? '')) ?: null]);
            log_action((int)$u['id'], 'day_register', ['day_id' => $id]);
            try {
                bot_notify_admins_day_vote($id, (string)$player['nickname'], 'reg', $tf, $tt);
            } catch (Throwable $e) {
            }
            flash_set('ok', 'Вы записаны!');
        } else {
            flash_set('err', 'Запись на этот вечер закрыта');
        }
    } else {
        db()->prepare('UPDATE day_registrations SET cancelled_at = NOW() WHERE day_id = ? AND player_id = ?')
            ->execute([$id, (int)$player['id']]);
        log_action((int)$u['id'], 'day_cancel', ['day_id' => $id]);
        try {
            bot_notify_admins_day_vote($id, (string)$player['nickname'], 'cancel');
        } catch (Throwable $e) {
        }
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
    // Черновик вечера виден по прямой ссылке только судьям/админам
    if ($day && ($day['status'] ?? '') === 'draft' && !user_can_judge(current_user())) {
        $day = null;
    }
    if ($day) {
        $st = db()->prepare("SELECT g.*, jp.nickname AS judge_nick, jp.id AS judge_id
            FROM games g LEFT JOIN players jp ON jp.id = g.judge_player_id
            WHERE g.day_id = ? ORDER BY g.table_no, g.game_no");
        $st->execute([$id]);
        $games = $st->fetchAll();
        $seatsByGame = day_games_seats(array_column($games, 'id'));
    }
}

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
    echo '<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">';
    echo '<span class="tag ' . ($day['status'] === 'reg_open' ? 'tag-open' : '') . '">'
        . ($day['status'] === 'reg_open' ? 'запись открыта' : 'запись закрыта') . '</span>';
    // Закрыть/открыть запись прямо со страницы вечера — тем, кому разрешено управлять вечерами.
    // Смена статуса — общая логика admin/days.php (form=status), назад она вернёт сюда.
    if (user_perm(current_user(), 'manage_days')) {
        $toSt = $day['status'] === 'reg_open' ? 'reg_closed' : 'reg_open';
        echo '<form method="post" action="/admin/days.php" style="margin:0;"'
            . ($toSt === 'reg_closed' ? ' onsubmit="return confirm(\'Закрыть запись на этот вечер? Новые игроки не смогут записаться — ни на сайте, ни в боте.\');"' : '') . '>'
            . csrf_field()
            . '<input type="hidden" name="form" value="status"><input type="hidden" name="day_id" value="' . $id . '">'
            . '<input type="hidden" name="to" value="' . $toSt . '"><input type="hidden" name="back" value="/day.php?id=' . $id . '">'
            . '<button class="btn btn-ghost" style="padding:5px 12px;font-size:13px;" type="submit">'
            . ($toSt === 'reg_closed' ? '🔒 Закрыть запись' : '🔓 Открыть запись снова') . '</button></form>';
    }
    echo '</div></div>';
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
    // «Готовый стол»: когда одновременно доступно 12+ игроков и хватает ли 4+ часов
    if (in_array($day['status'], ['reg_open', 'reg_closed'], true)) {
        $vd = day_table_verdict((int)$day['id']);
        if ($vd !== '') {
            echo '<p style="margin:10px 0 0;font-size:13.5px;color:var(--tx2);">' . esc($vd) . '</p>';
        }
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

// «Вы» для подсветки своей строки/места в рейтинге вечера и протоколах
$mp = current_player();
$mePid = $mp ? (int)$mp['id'] : 0;

// ── Рейтинг вечера ──
if ($games) {
    $standing = [];
    foreach ($games as $g) {
        $seats = $seatsByGame[(int)$g['id']] ?? [];
        $tt = game_display_totals($g, $seats);
        foreach ($seats as $s) {
            $pid = (int)$s['player_id'];
            $standing[$pid] = $standing[$pid] ?? ['nick' => $s['nickname'], 'avatar' => $s['avatar'], 'flair' => $s['flair'] ?? '', 'elo' => $s['elo'], 'games' => 0, 'sum' => 0.0, 'sum_plus' => 0.0,
                'g_civ' => 0, 'w_civ' => 0, 'd_civ' => 0.0, 'g_sheriff' => 0, 'w_sheriff' => 0, 'd_sheriff' => 0.0,
                'g_maf' => 0, 'w_maf' => 0, 'd_maf' => 0.0, 'g_don' => 0, 'w_don' => 0, 'd_don' => 0.0];
            $cell = $tt[(int)$s['seat']] ?? [];
            $standing[$pid]['games']++;
            $standing[$pid]['sum'] += $cell['total'] ?? 0;
            $standing[$pid]['sum_plus'] += (float)$s['plus'] + (float)($cell['lh'] ?? 0) + (float)($cell['ci'] ?? 0);
            // По ролям — для номинаций вечера
            $role = (string)$s['role'];
            if (isset($standing[$pid]['g_' . $role])) {
                $isRedSeat = in_array($role, ROLE_RED, true);
                $standing[$pid]['g_' . $role]++;
                if ($g['winner'] !== 'draw' && (($g['winner'] === 'red') === $isRedSeat)) {
                    $standing[$pid]['w_' . $role]++;
                }
                $standing[$pid]['d_' . $role] += (float)$s['plus'];
            }
        }
    }
    // Рейтинг вечера — по Σ; тай-брейк по Σ+ (бонусным баллам), с округлением для стабильности
    uasort($standing, fn($a, $b) => [round($b['sum'], 2), round($b['sum_plus'], 2)] <=> [round($a['sum'], 2), round($a['sum_plus'], 2)]);
    // ELO в таблице вечера — ПО ИТОГАМ вечера (после последней игры), а не входной:
    // у дебютанта входной равен стартовой 1000 и колонка выглядела незаполненной.
    // Рядом стоит «ELO за вечер» — вместе они дают полную картину.
    $enterElo = event_exit_elo(array_column($games, 'id'));
    foreach ($standing as $pid => &$rowE) {
        if (isset($enterElo[$pid])) {
            $rowE['elo'] = $enterElo[$pid];
        }
    }
    unset($rowE);
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
    // ── Номинации вечера: MVP и лучшие в ролях именно за этот день (просьба руководителя).
    // Правила те же, что у клубных: винрейт в роли, при равенстве — больше игр в роли,
    // затем больше допов за неё. Порог — одна игра: за вечер ролей у человека мало.
    $bestRole = function (string $rk) use ($standing) {
        $best = null;
        $bw = -1.0;
        foreach ($standing as $pid => $r) {
            $g = (int)$r['g_' . $rk];
            if ($g < 1) {
                continue;
            }
            $wr = (int)$r['w_' . $rk] / $g;
            $better = $wr > $bw + 1e-9;
            if (!$better && $best && abs($wr - $bw) < 1e-9) {
                $bg = (int)$standing[$best]['g_' . $rk];
                $better = $g > $bg
                    || ($g === $bg && (float)$r['d_' . $rk] > (float)$standing[$best]['d_' . $rk] + 1e-9);
            }
            if ($better) {
                $bw = $wr;
                $best = $pid;
            }
        }
        return $best === null ? null : [$best, $bw, (int)$standing[$best]['g_' . $rk]];
    };
    $mvpPid = array_key_first($standing);
    $dayNoms = [
        ['🥇 MVP вечера', $mvpPid === null ? null : [$mvpPid, null, (int)$standing[$mvpPid]['games']],
            $mvpPid === null ? '' : 'Σ ' . number_format((float)$standing[$mvpPid]['sum'], 2) . ' за вечер'],
        ['😈 Лучший дон', $bestRole('don'), 'за дона'],
        ['🌟 Лучший шериф', $bestRole('sheriff'), 'за шерифа'],
        ['🔴 Лучший красный', $bestRole('civ'), 'за мирного'],
        ['⚫ Лучший чёрный', $bestRole('maf'), 'за мафию'],
    ];
    $hasNoms = false;
    foreach ($dayNoms as $n) {
        if ($n[1]) {
            $hasNoms = true;
        }
    }
    if ($hasNoms) {
        echo '<h2 style="margin-bottom:10px;">Номинации вечера</h2>';
        echo '<div class="noms-grid">';
        foreach ($dayNoms as [$title, $data, $hint]) {
            if (!$data) {
                continue;
            }
            [$pid, $wr, $cnt] = $data;
            $row = $standing[$pid];
            echo '<div class="nom-card">';
            echo '<div class="nom-title">' . $title . '</div>';
            echo '<a class="nom-player" href="/player.php?id=' . (int)$pid . '">'
                . avatar_html(['nickname' => $row['nick'], 'avatar' => $row['avatar']], 34)
                . '<span>' . esc((string)$row['nick'])
                . ($row['flair'] !== '' ? ' <span class="flair">' . esc((string)$row['flair']) . '</span>' : '') . '</span></a>';
            echo '<div class="nom-meta">'
                . ($wr !== null ? round($wr * 100) . '% · ' . $hint . ' · ' . $cnt . ' ' . ru_plural($cnt, 'игра', 'игры', 'игр') : esc($hint))
                . '</div>';
            echo '</div>';
        }
        echo '</div>';
    }

    echo '<div class="card"><h2 style="margin-top:0;">Рейтинг вечера</h2>';
    echo '<table class="tbl sortable"><thead><tr><th data-type="num">#</th><th>Игрок</th>'
        . '<th class="num" data-type="num">Игр</th><th class="num" data-type="num">Σ за вечер</th>'
        . '<th class="num" data-type="num" title="ELO по итогам вечера — после последней сыгранной игры">ELO</th><th class="num" data-type="num">ELO за вечер</th></tr></thead><tbody>';
    $pos = 0;
    foreach ($standing as $pid => $row) {
        $pos++;
        $isMe = $mePid && (int)$pid === $mePid;
        echo '<tr' . ($pos <= 3 ? ' class="rt-top"' : '') . ($isMe ? ' style="' . me_row_style() . '"' : '') . '>'
            . '<td data-sort="' . $pos . '">' . ($pos <= 3 ? '<span style="font-size:15px;">' . rank_medal($pos) . '</span>' : $pos) . '</td>'
            . '<td><a href="/player.php?id=' . $pid . '" style="' . me_nick_style($isMe) . '">'
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
// Черновики протокола (игры с ошибками) — карточками в той же сетке, но только судьям: в рейтинг
// они не попали и сами по себе с ошибками, игрокам показывать их рано.
$drafts = $canEdit ? day_drafts($id) : [];
if ($games || $drafts) {
    $gameNos = array_column($games, 'game_no', 'id');
    $draftHtml = '';
    foreach ($drafts as $d) {
        $draftHtml .= day_draft_card($d, $gameNos,
            '<a class="tag" href="/admin/protocol.php?day=' . $id . '&draft=' . (int)$d['id'] . '">открыть</a>');
    }
    echo '<h2>Игры вечера</h2>';
    day_games_grid($games, $seatsByGame, $mePid, $canEdit
        ? fn(array $g) => '<a class="tag" href="/admin/protocol.php?day=' . $id . '&game=' . (int)$g['id'] . '">изменить</a>'
        : null, 0, $draftHtml);
} else {
    empty_state('Протоколов пока нет', 'Игры этого вечера ещё не записаны.'
        . ($canEdit ? ' Нажмите «Вести / редактировать игры», чтобы добавить.' : ''));
}
page_foot();
