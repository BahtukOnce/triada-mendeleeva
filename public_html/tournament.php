<?php
require dirname(__DIR__) . '/inc/bootstrap.php';
require ROOT . '/inc/rating.php';

$id = (int)($_GET['id'] ?? 0);
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

page_head($t ? $t['title'] : 'Турнир не найден', 'tournaments');

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
echo '<p style="color:var(--tx2);margin-top:6px;">Столов: ' . (int)$t['tables_count']
    . ' · игр: ' . count($games)
    . ($t['date_from'] ? ' · ' . esc(date('d.m.Y', strtotime($t['date_from']))) : '')
    . ($t['location'] ? ' · ' . esc($t['location']) : '') . '</p>';
if (!empty($t['description'])) {
    echo '<p style="color:var(--tx2);max-width:680px;line-height:1.6;">' . nl2br(esc($t['description'])) . '</p>';
}
if (user_can_judge(current_user())) {
    echo '<p style="margin:0 0 12px;"><a class="btn" href="/admin/tournaments.php?edit=' . $id . '">Редактировать турнир</a> '
        . '<a class="btn btn-ghost" href="/admin/tournaments.php">Все турниры / создать</a></p>';
}

// Итоговая таблица турнира — полный агрегат (как в общем рейтинге)
$standing = standings_from_games($games, $seatsByGame);

if ($standing) {
    // ── Номинации турнира: считаются по допам за карту ──
    $mvp = reset($standing); // $standing уже отсортирован по Σ
    $byPlus = $standing;
    uasort($byPlus, fn($a, $b) => $b['plus'] <=> $a['plus']);
    $topPlus = reset($byPlus);
    $byAvg = array_filter($standing, fn($r) => $r['games'] >= 3);
    uasort($byAvg, fn($a, $b) => ($b['plus'] / max(1, $b['games'])) <=> ($a['plus'] / max(1, $a['games'])));
    $topAvg = reset($byAvg);
    $nomCard = function (string $title, array $r, string $meta): string {
        return '<div class="nom-card"><div class="nom-title">' . $title . '</div>'
            . '<a class="nom-player" href="/player.php?id=' . (int)$r['pid'] . '">'
            . avatar_html(['nickname' => $r['nick'], 'avatar' => $r['avatar']], 34)
            . '<span>' . esc($r['nick']) . '</span></a>'
            . '<div class="nom-meta">' . $meta . '</div></div>';
    };
    echo '<div class="noms-grid">';
    if ($mvp) {
        echo $nomCard('🏆 MVP турнира', $mvp, 'Σ ' . number_format($mvp['sum'], 2) . ' · ' . (int)$mvp['games'] . ' игр');
    }
    if ($topPlus && $topPlus['plus'] > 0) {
        echo $nomCard('➕ Король допов', $topPlus, number_format($topPlus['plus'], 1) . ' допов за турнир');
    }
    if ($topAvg && $topAvg['plus'] > 0) {
        echo $nomCard('🎯 Допы за карту', $topAvg, number_format($topAvg['plus'] / $topAvg['games'], 2) . ' доп/игра');
    }
    echo '</div>';

    echo '<div class="card" style="overflow-x:auto;"><h2 style="margin-top:0;">Итоговая таблица</h2>';
    echo '<table class="tbl rating-tbl" style="font-size:13px;">';
    echo '<thead>'
        . '<tr class="rt-groups"><th colspan="2"></th><th class="c-elo">ELO</th>'
        . '<th colspan="11">Баллы и суммы</th><th class="c-cards-first" colspan="5">По картам</th></tr>'
        . '<tr>'
        . '<th>#</th><th>Игрок</th><th class="num c-elo">ELO</th>'
        . '<th class="num c-club">~Σ×Σ</th><th class="num">~Σ</th><th class="num">Σ</th>'
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
        echo '<td class="num c-club"><b>' . number_format((float)$row['club_score'], 2) . '</b></td>';
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
        echo '<h2 style="margin:4px 0 8px;">Стол ' . $tableNo
            . ($plc !== '' ? ' <span style="color:var(--tx2);font-size:14px;font-weight:400;">· ' . esc($plc) . '</span>' : '') . '</h2>';
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
