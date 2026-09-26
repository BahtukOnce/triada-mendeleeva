<?php
// Карточки игр вечера сеткой. Общие для страницы вечера (/day.php) и протокола судьи
// (/admin/protocol.php): руководитель попросил, чтобы в протоколе игры были такими же плашками,
// как на странице вечера, — одна функция не даёт двум видам разойтись.
// Нужен inc/rating.php (game_display_totals).

// Места игр со строками игроков: game_id => [места по порядку].
function day_games_seats(array $gameIds): array
{
    $out = [];
    if (!$gameIds) {
        return $out;
    }
    $in = implode(',', array_fill(0, count($gameIds), '?'));
    $st = db()->prepare("SELECT gs.*, p.nickname, p.avatar, p.flair, p.elo FROM game_seats gs
        JOIN players p ON p.id = gs.player_id
        WHERE gs.game_id IN ($in) ORDER BY gs.game_id, gs.seat");
    $st->execute(array_values($gameIds));
    foreach ($st->fetchAll() as $s) {
        $out[(int)$s['game_id']][] = $s;
    }
    return $out;
}

// Черновики протокола вечера (protocol_drafts, миграция 084) — игры с ошибками или отложенные.
// Пока таблицы нет — пусто.
function day_drafts(int $dayId): array
{
    try {
        $st = db()->prepare('SELECT d.*, u.nickname AS by_nick FROM protocol_drafts d
            LEFT JOIN users u ON u.id = d.updated_by
            WHERE d.day_id = ? ORDER BY d.updated_at DESC, d.id DESC');
        $st->execute([$dayId]);
        return $st->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

// Карточка черновика — в той же сетке, что и игры (просьба руководителя: черновик должен быть
// виден рядом с играми). Стол, роли и итог — из сырых полей формы; итог предварительный, без Ci,
// как в живом подсчёте протокола. $gameNos — id игры => номер (для «правки игры N»).
function day_draft_card(array $d, array $gameNos, string $actionsHtml = '', bool $active = false): string
{
    $roleLabel = ['civ' => 'Мирный', 'maf' => 'Мафия', 'sheriff' => 'Шериф', 'don' => 'Дон'];
    $winLabel = ['red' => 'Победа красных', 'black' => 'Победа чёрных', 'draw' => 'Ничья'];
    $data = json_decode((string)$d['data'], true);
    $data = is_array($data) ? $data : [];
    $winner = in_array($data['winner'] ?? '', ['red', 'black', 'draw'], true) ? (string)$data['winner'] : null;
    $pu = (int)($data['pu'] ?? 0);

    $seats = [];
    for ($i = 1; $i <= 10; $i++) {
        $nick = trim((string)($data["nick$i"] ?? ''));
        if ($nick === '') {
            continue;
        }
        $role = (string)($data["role$i"] ?? 'civ');
        $seats[$i] = [
            'seat' => $i,
            'nick' => $nick,
            'role' => isset($roleLabel[$role]) ? $role : 'civ',
            'fouls' => (int)($data["fouls$i"] ?? 0),
            'tech_fouls' => (int)($data["tech$i"] ?? 0),
            'big_tech' => (int)($data["bigtech$i"] ?? 0),
            'removal' => (int)($data["removal$i"] ?? 0),
            'plus' => min(9.9, max(0, (float)str_replace(',', '.', (string)($data["plus$i"] ?? '0')))),
            'minus' => min(9.9, max(0, (float)str_replace(',', '.', (string)($data["minus$i"] ?? '0')))),
            'calls' => seat_calls_parse((string)($data["calls$i"] ?? ''), $i),
        ];
    }
    // Известные ники — ссылкой на профиль, новые (заведутся при сохранении игры) — серым.
    $known = [];
    if ($seats) {
        $lower = array_values(array_unique(array_map(fn($s) => mb_strtolower($s['nick']), $seats)));
        $st = db()->prepare('SELECT id, nickname, flair FROM players WHERE LOWER(nickname) IN ('
            . implode(',', array_fill(0, count($lower), 'LOWER(?)')) . ')');
        $st->execute($lower);
        foreach ($st->fetchAll() as $p) {
            $known[mb_strtolower((string)$p['nickname'])] = $p;
        }
    }
    $bmBonus = bm_bonus_for(array_values($seats), (int)($data['bm1'] ?? 0), (int)($data['bm2'] ?? 0), (int)($data['bm3'] ?? 0));
    $judge = null;
    if ((int)($data['judge'] ?? 0) > 0) {
        $jq = db()->prepare('SELECT id, nickname FROM players WHERE id = ?');
        $jq->execute([(int)$data['judge']]);
        $judge = $jq->fetch() ?: null;
    }

    $gid = (int)($d['game_id'] ?? 0);
    $h = '<div class="card card-compact draft-game' . ($active ? ' game-on' : '') . '" id="draft-' . (int)$d['id'] . '">';
    $h .= '<div class="section-head"><h2 style="margin:0;font-size:15px;">📝 Черновик</h2><span>';
    if ($winner) {
        $h .= '<span class="tag ' . ($winner === 'red' ? 'tag-red' : ($winner === 'black' ? 'tag-black' : 'tag-draw')) . '">' . esc($winLabel[$winner]) . '</span>';
    }
    $h .= ($actionsHtml !== '' ? ' ' . $actionsHtml : '') . '</span></div>';
    $h .= '<p style="color:var(--tx2);font-size:12px;margin:2px 0 6px;">'
        . (isset($gameNos[$gid]) ? 'правка игры ' . $gameNos[$gid] : 'новая игра') . ' · <b style="color:#f2c75c;">в рейтинг не попала</b>'
        . ' · ' . (!empty($d['by_nick']) ? esc($d['by_nick']) . ', ' : '') . date('d.m H:i', strtotime((string)$d['updated_at'])) . '</p>';
    if ($judge) {
        $h .= '<p style="color:var(--tx2);font-size:12px;margin:2px 0 6px;">судья: '
            . '<a href="/player.php?id=' . (int)$judge['id'] . '">' . esc($judge['nickname']) . '</a></p>';
    }
    if ($seats) {
        $h .= '<table class="tbl" style="font-size:12.5px;">';
        $h .= '<tr><th>#</th><th>Игрок</th><th>Роль</th><th class="num" title="Предварительный итог, без Ci">Итог</th></tr>';
        $draftRoles = array_map(fn($s) => $s['role'], $seats);   // место => роль, для версий игроков
        foreach ($seats as $i => $s) {
            $p = $known[mb_strtolower($s['nick'])] ?? null;
            $isBlack = in_array($s['role'], ['maf', 'don'], true);
            $total = seat_total($s, $winner, $pu === $i, $bmBonus, 0.0);
            $h .= '<tr><td>' . $i . '</td><td>'
                . ($p
                    ? '<a href="/player.php?id=' . (int)$p['id'] . '" style="' . me_nick_style(false) . '">' . esc($p['nickname']) . '</a>'
                        . (!empty($p['flair']) ? ' <span class="flair">' . esc($p['flair']) . '</span>' : '')
                    : '<span style="color:var(--tx3);" title="Нет на платформе — заведётся при сохранении игры">' . esc($s['nick']) . '</span>')
                . ($pu === $i ? ' <span class="tag">ПУ</span>' : '')
                . penalty_badges($s)
                . (($callsHtml = seat_calls_chips($s['calls'], $draftRoles)) !== '' ? ' ' . $callsHtml : '')
                . '</td>'
                . '<td style="white-space:nowrap;">' . role_dot($s['role']) . ($isBlack ? '<b>' . $roleLabel[$s['role']] . '</b>' : $roleLabel[$s['role']]) . '</td>'
                . '<td class="num"><b>' . number_format($total, 2) . '</b></td></tr>';
        }
        $h .= '</table>';
    } else {
        $h .= '<p style="color:var(--tx3);font-size:12.5px;margin:6px 0;">За столом пока никого.</p>';
    }
    $meta = [];
    if ($pu) {
        $rbs = array_map(fn($s) => $s['role'], $seats);
        $lhPu = lh_seats_colored($rbs, (int)($data['bm1'] ?? 0), (int)($data['bm2'] ?? 0), (int)($data['bm3'] ?? 0));
        $meta[] = 'ПУ: место ' . $pu;
        $meta[] = 'ЛХ ПУ: ' . ($lhPu !== '' ? $lhPu : lh_miss_chip());
    }
    if ($meta) {
        $h .= '<p style="color:var(--tx2);font-size:12px;margin:8px 0 0;line-height:2;">' . implode(' &nbsp;·&nbsp; ', $meta) . '</p>';
    }
    $h .= game_votes_html(isset($data['votes']) ? (string)$data['votes'] : null);   // голосование по кругам
    if (trim((string)$d['errors']) !== '') {
        $h .= '<p class="draft-err">⚠ Поправить: ' . esc((string)$d['errors']) . '</p>';
    }
    return $h . '</div>';
}

// $games — строки games с judge_nick; $actions($g) — HTML в шапку карточки после тега победы
// («изменить», «удалить»); $activeId — игра, которую сейчас правят (карточка подсвечена);
// $tail — готовые карточки в конец сетки (черновики, day_draft_card).
function day_games_grid(array $games, array $seatsByGame, int $mePid = 0, ?callable $actions = null, int $activeId = 0, string $tail = ''): void
{
    $roleLabel = ['civ' => 'Мирный', 'maf' => 'Мафия', 'sheriff' => 'Шериф', 'don' => 'Дон'];
    $winLabel = ['red' => 'Победа красных', 'black' => 'Победа чёрных', 'draw' => 'Ничья'];

    // ELO-дельты по играм (+ ELO до игры для среднего по столу)
    $eloDelta = [];
    $eloBefore = [];
    $gids = array_column($games, 'id');
    try {
        if ($gids) {
            $in = implode(',', array_fill(0, count($gids), '?'));
            $st = db()->prepare("SELECT game_id, player_id, delta, elo_after FROM elo_history WHERE game_id IN ($in)");
            $st->execute($gids);
            foreach ($st->fetchAll() as $row) {
                $eloDelta[(int)$row['game_id']][(int)$row['player_id']] = (float)$row['delta'];
                $eloBefore[(int)$row['game_id']][(int)$row['player_id']] = (float)$row['elo_after'] - (float)$row['delta'];
            }
        }
    } catch (Throwable $e) {
    }

    echo '<div class="tables-grid grid-equal games-grid">';
    foreach ($games as $g) {
        $seats = $seatsByGame[(int)$g['id']] ?? [];
        $totals = game_display_totals($g, $seats);
        $winTag = $g['winner'] === 'red' ? 'tag-red' : ($g['winner'] === 'black' ? 'tag-black' : 'tag-draw');

        echo '<div class="card card-compact' . ($activeId && (int)$g['id'] === $activeId ? ' game-on' : '') . '" id="game-' . (int)$g['id'] . '">';
        echo '<div class="section-head"><h2 style="margin:0;font-size:15px;">Игра ' . (int)$g['game_no'] . '</h2><span>';
        if ($g['winner']) {
            echo '<span class="tag ' . $winTag . '">' . esc($winLabel[$g['winner']]) . '</span>';
        }
        if ($actions) {
            echo ' ' . $actions($g);
        }
        echo '</span></div>';
        if ($g['judge_nick']) {
            echo '<p style="color:var(--tx2);font-size:12px;margin:2px 0 6px;">судья: '
                . '<a href="/player.php?id=' . (int)$g['judge_player_id'] . '">' . esc($g['judge_nick']) . '</a></p>';
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
        // Роли по местам — для отметки верных и ошибочных мест в версиях игроков
        $rolesBySeat = [];
        foreach ($seats as $s0) {
            $rolesBySeat[(int)$s0['seat']] = (string)$s0['role'];
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
            $isMe = $mePid && (int)$s['player_id'] === $mePid;
            echo '<tr' . ($isMe ? ' style="' . me_row_style() . '"' : '') . '><td>' . (int)$s['seat'] . '</td>'
                . '<td><a href="/player.php?id=' . (int)$s['player_id'] . '" style="' . me_nick_style($isMe) . '">' . esc($s['nickname']) . '</a>'
                . (!empty($s['flair']) ? ' <span class="flair">' . esc($s['flair']) . '</span>' : '')   // эмодзи — здесь, один раз
                . ($t['is_pu'] ? ' <span class="tag">ПУ</span>' : '')
                . (!empty($t['ci_half']) ? ' <span class="tag" title="Компенсация ПУ урезана вдвое — команда первоубиенного победила">Ci ×½</span>' : '')
                . penalty_badges($s)
                . (($callsHtml = seat_calls_chips($s['calls'] ?? null, $rolesBySeat)) !== '' ? ' ' . $callsHtml : '')
                . '</td>'
                . '<td style="white-space:nowrap;">' . role_dot($s['role']) . ($isBlack ? '<b>' . $roleLabel[$s['role']] . '</b>' : $roleLabel[$s['role']]) . '</td>'
                . '<td class="num"><b>' . number_format($t['total'], 2) . '</b></td>'
                . '<td class="num" style="font-size:11.5px;">' . $edHtml . '</td></tr>';
        }
        echo '</table>';
        $rbs = [];
        foreach ($seats as $s2) {
            $rbs[(int)$s2['seat']] = $s2['role'];
        }
        $meta = [];
        if ($g['first_killed_seat']) {
            $meta[] = 'ПУ: место ' . (int)$g['first_killed_seat'];
        }
        // Пустой ЛХ — это промах, а не «нет данных»: показываем явно, чтобы строки
        // под играми были одинаковыми (см. lh_miss_chip).
        $lhPu = lh_seats_colored($rbs, (int)$g['bm_seat1'], (int)$g['bm_seat2'], (int)$g['bm_seat3']);
        if ($g['first_killed_seat']) {
            $meta[] = 'ЛХ ПУ: ' . ($lhPu !== '' ? $lhPu : lh_miss_chip());
        } else {
            $meta[] = 'ЛХ: ' . lh_miss_chip('Первоубиенный не отмечен — лучшего хода в игре не было');
        }
        $lhV0 = lh_seats_colored($rbs, (int)($g['vote0_bm1'] ?? 0), (int)($g['vote0_bm2'] ?? 0), (int)($g['vote0_bm3'] ?? 0));
        if (!empty($g['vote0_seat'])) {
            $meta[] = 'ЛХ заголос.: ' . ($lhV0 !== '' ? $lhV0 : lh_miss_chip());
        }
        if ($meta) {
            echo '<p style="color:var(--tx2);font-size:12px;margin:8px 0 0;line-height:2;">' . implode(' &nbsp;·&nbsp; ', $meta) . '</p>';
        }
        echo game_votes_html($g['votes'] ?? null);   // голосование по кругам
        echo '</div>';
    }
    echo $tail . '</div>';
}
