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

// $games — строки games с judge_nick; $actions($g) — HTML в шапку карточки после тега победы
// («изменить», «удалить»); $activeId — игра, которую сейчас правят (карточка подсвечена).
function day_games_grid(array $games, array $seatsByGame, int $mePid = 0, ?callable $actions = null, int $activeId = 0): void
{
    $roleLabel = ['civ' => 'Мирный', 'maf' => 'Мафия', 'sheriff' => 'Шериф', 'don' => 'Дон'];
    $winLabel = ['red' => 'Победа красных', 'black' => 'Победа чёрных', 'draw' => 'Ничья'];

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

    echo '<div class="tables-grid grid-equal" style="grid-template-columns:repeat(auto-fit,minmax(330px,1fr));">';
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
                . penalty_badges($s) . '</td>'
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
        echo '</div>';
    }
    echo '</div>';
}
