<?php
declare(strict_types=1);

// Движок рейтинга — формулы из клубной Google-таблицы.
// Победа: red = мирные+шериф, black = мафия+дон, draw = ничья.

const ROLE_RED = ['civ', 'sheriff'];
const ROLE_BLACK = ['maf', 'don'];

// Бонус ЛХ по трём названным местам: сколько из них оказались чёрными → 0.1 / 0.3 / 0.6.
// Возвращает −1, если ЛХ не заполнен (ни одного места).
function bm_bonus_for(array $seats, int $s1, int $s2, int $s3): float
{
    $rolesBySeat = [];
    foreach ($seats as $s) {
        $rolesBySeat[(int)$s['seat']] = $s['role'];
    }
    // только различные места 1..10 (одно место, названное дважды, не считается дважды)
    $given = array_values(array_unique(array_filter([$s1, $s2, $s3], fn($n) => $n >= 1 && $n <= 10)));
    if (!$given) {
        return -1.0; // ЛХ не заполнен
    }
    $hits = 0;
    foreach ($given as $seatNo) {
        if (in_array($rolesBySeat[$seatNo] ?? '', ROLE_BLACK, true)) {
            $hits++;
        }
    }
    return [0 => 0.0, 1 => 0.1, 2 => 0.3, 3 => 0.6][$hits] ?? 0.6;
}

// ЛХ первоубиенного (bm_seat1..3) — обёртка над bm_bonus_for.
function bm_bonus_for_game(array $seats, array $game): float
{
    return bm_bonus_for($seats, (int)($game['bm_seat1'] ?? 0), (int)($game['bm_seat2'] ?? 0), (int)($game['bm_seat3'] ?? 0));
}

// Ci — компенсация первоубиенному (мирный/шериф, при заполненном ЛХ с бонусом > 0).
// Официальная формула 8.6.1:  Ci = i·0.4 / B при i ≤ B;  Ci = 0.4 при i > B.
//   i ($puTotal) — сколько раз игрок был первоубиен ночью за красного/шерифа на дистанции;
//   B — 40% сыгранных на дистанции игр ($gamesTotal), округлённое, но не менее 4.
// ($winner в формуле не участвует — оставлен в сигнатуре для совместимости вызовов.)
function ci_value(string $role, ?string $winner, int $puTotal, int $gamesTotal, float $bmBonus): float
{
    if (!in_array($role, ROLE_RED, true) || $bmBonus <= 0 || $gamesTotal <= 0) {
        return 0.0;
    }
    $i = (float)$puTotal;
    $B = max(4, (int)round($gamesTotal * 0.4));
    return $i <= $B ? ($i * 0.4) / $B : 0.4;
}

// Итог игрока за игру (без Ci и ЛХ — они передаются отдельно)
function seat_total(array $seat, ?string $winner, bool $isPu, float $bmBonus, float $ci): float
{
    $role = $seat['role'];
    $plus = (float)$seat['plus'];
    $minus = (float)$seat['minus'];
    $total = 0.0;

    if ($winner === null) {
        // Победитель не заполнен: без победного балла и ЛХ
        $total = $plus - $minus + $ci;
    } elseif ($winner === 'draw') {
        $total = $ci + $plus - $minus;
        if ($isPu && in_array($role, ROLE_RED, true) && $bmBonus > 0) {
            $total += $bmBonus; // ЛХ только красным/шерифу (чёрные с ЛХ ничего не получают)
        }
    } else {
        $winTeam = $winner === 'black' ? ROLE_BLACK : ROLE_RED;
        $total = in_array($role, $winTeam, true) ? 1.0 + $plus - $minus : $plus - $minus;
        if ($isPu && in_array($role, ROLE_RED, true) && $bmBonus > 0) {
            $total += $bmBonus;
        }
        $total += $ci;
    }

    if ((int)$seat['fouls'] >= 4) {
        $total -= 0.6;
    }
    $total -= 0.3 * (int)$seat['tech_fouls'];
    $total -= 0.6 * (int)($seat['big_tech'] ?? 0); // большой тех.фол: −0.6 каждый (макс 2)
    $rem = (int)($seat['removal'] ?? 0);
    if ($rem === 1) {
        $total -= 0.6; // удаление
    } elseif ($rem === 2) {
        $total -= 1.2; // удаление на критический круг
    }
    return $total;
}

// Все завершённые игры рейтинга с местами (дни из rating_days)
function rating_games(int $ratingId): array
{
    $st = db()->prepare("SELECT g.* FROM games g
        JOIN rating_days rd ON rd.day_id = g.day_id
        WHERE rd.rating_id = ? AND g.context = 'day' AND g.status = 'finished'
        ORDER BY g.day_id, g.table_no, g.game_no");
    $st->execute([$ratingId]);
    $games = $st->fetchAll();
    if (!$games) {
        return [[], []];
    }
    $ids = array_column($games, 'id');
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = db()->prepare("SELECT * FROM game_seats WHERE game_id IN ($in) ORDER BY game_id, seat");
    $st->execute($ids);
    $seatsByGame = [];
    foreach ($st->fetchAll() as $s) {
        $seatsByGame[(int)$s['game_id']][] = $s;
    }
    return [$games, $seatsByGame];
}

// Полный пересчёт кэша рейтинга
function rating_recompute(int $ratingId): void
{
    [$games, $seatsByGame] = rating_games($ratingId);

    // Проход 1: тотальные счётчики игр и ПУ (для «плывущего» Ci)
    $gamesTotal = [];
    $puTotal = [];
    foreach ($games as $g) {
        $seats = $seatsByGame[(int)$g['id']] ?? [];
        foreach ($seats as $s) {
            $pid = (int)$s['player_id'];
            $gamesTotal[$pid] = ($gamesTotal[$pid] ?? 0) + 1;
            // i для Ci — первоубиенные ночью только за красного/шерифа (по формуле 8.6.1)
            if ((int)$g['first_killed_seat'] === (int)$s['seat'] && in_array($s['role'], ROLE_RED, true)) {
                $puTotal[$pid] = ($puTotal[$pid] ?? 0) + 1;
            }
        }
    }

    // Проход 2: суммы
    $agg = [];
    $eveSum = []; // day_id => pid => сумма итогов за вечер (для MVP вечера)
    $blank = [
        'games' => 0, 'sum_total' => 0.0, 'sum_plus' => 0.0, 'pu_count' => 0,
        'lh_sum' => 0.0, 'dop_sum' => 0.0, 'minus_sum' => 0.0, 'ci_sum' => 0.0,
        'tech_count' => 0,
        'w_civ' => 0, 'g_civ' => 0, 'w_maf' => 0, 'g_maf' => 0,
        'w_sher' => 0, 'g_sher' => 0, 'w_don' => 0, 'g_don' => 0,
        'dop_civ' => 0.0, 'dop_maf' => 0.0, 'dop_sher' => 0.0, 'dop_don' => 0.0,
    ];
    $roleKey = ['civ' => 'civ', 'maf' => 'maf', 'sheriff' => 'sher', 'don' => 'don'];

    foreach ($games as $g) {
        $seats = $seatsByGame[(int)$g['id']] ?? [];
        $winner = $g['winner'];
        // Два независимых ЛХ: первоубиенного ночью (bm_seat) и заголосованного на 0-м круге (vote0_bm).
        $bonusPu = max(0.0, bm_bonus_for($seats, (int)($g['bm_seat1'] ?? 0), (int)($g['bm_seat2'] ?? 0), (int)($g['bm_seat3'] ?? 0)));
        $bonusV0 = max(0.0, bm_bonus_for($seats, (int)($g['vote0_bm1'] ?? 0), (int)($g['vote0_bm2'] ?? 0), (int)($g['vote0_bm3'] ?? 0)));
        $puSeat = (int)$g['first_killed_seat'];
        $v0Seat = (int)($g['vote0_seat'] ?? 0);
        foreach ($seats as $s) {
            $pid = (int)$s['player_id'];
            $seatNo = (int)$s['seat'];
            $agg[$pid] = $agg[$pid] ?? $blank;
            $a = &$agg[$pid];
            $isRed = in_array($s['role'], ROLE_RED, true);
            $isPu = $puSeat > 0 && $seatNo === $puSeat;   // ночной первоубиен — для Ci
            $isV0 = $v0Seat > 0 && $seatNo === $v0Seat;   // заголосован на 0-м круге
            $isLhMaker = $isPu || $isV0;                  // делал ЛХ (любое из двух событий)
            $seatBonus = $isPu ? $bonusPu : ($isV0 ? $bonusV0 : 0.0);
            $ci = $isPu
                ? ci_value($s['role'], $winner, $puTotal[$pid] ?? 0, $gamesTotal[$pid] ?? 0, $bonusPu)
                : 0.0;
            $total = seat_total($s, $winner, $isLhMaker, $seatBonus, $ci);
            $dayId = (int)$g['day_id'];
            $eveSum[$dayId][$pid] = ($eveSum[$dayId][$pid] ?? 0.0) + $total;

            $a['games']++;
            $a['sum_total'] += $total;
            $a['dop_sum'] += (float)$s['plus'];
            $a['minus_sum'] += (float)$s['minus']
                + ((int)$s['fouls'] >= 4 ? 0.6 : 0)
                + 0.3 * (int)$s['tech_fouls']
                + 0.6 * (int)($s['big_tech'] ?? 0)
                + ((int)($s['removal'] ?? 0) === 1 ? 0.6 : ((int)($s['removal'] ?? 0) === 2 ? 1.2 : 0));
            $a['tech_count'] += (int)$s['tech_fouls'];
            $a['ci_sum'] += $ci;
            if ($isPu && $isRed) {
                $a['pu_count']++; // ПУ-счётчик = i для Ci (первоубиен ночью за красного/шерифа)
            }
            // ЛХ начисляется только красным/шерифу (чёрные с ЛХ ничего не получают)
            if ($isLhMaker && $isRed && $seatBonus > 0) {
                $a['lh_sum'] += $seatBonus;
            }
            $rk = $roleKey[$s['role']];
            $a['g_' . $rk]++;
            $a['dop_' . $rk] += (float)$s['plus']; // доп-баллы в разрезе роли (тай-брейк по картам)
            if ($winner === 'red' && in_array($s['role'], ROLE_RED, true)) {
                $a['w_' . $rk]++;
            } elseif ($winner === 'black' && in_array($s['role'], ROLE_BLACK, true)) {
                $a['w_' . $rk]++;
            }
            unset($a);
        }
    }

    // MVP вечера: в каждом игровом вечере — игрок(и) с максимальной суммой итогов
    $mvp = [];
    foreach ($eveSum as $players) {
        if (!$players) {
            continue;
        }
        $max = max($players);
        foreach ($players as $pid => $sum) {
            if ($sum >= $max - 1e-9) {
                $mvp[$pid] = ($mvp[$pid] ?? 0) + 1;
            }
        }
    }

    $minGames = 0;
    try {
        $st = db()->prepare('SELECT v FROM settings WHERE k = ?');
        $st->execute(['min_games_avg']);
        $minGames = (int)($st->fetchColumn() ?: 0);
    } catch (Throwable $e) {
    }

    $pdo = db();
    $ownTx = !$pdo->inTransaction();
    if ($ownTx) {
        $pdo->beginTransaction();
    }
    // пиковый клубный счёт переживает пересчёт (только растёт)
    $oldPeaks = [];
    try {
        $pq = $pdo->prepare('SELECT player_id, peak_club FROM rating_cache WHERE rating_id = ?');
        $pq->execute([$ratingId]);
        foreach ($pq->fetchAll() as $pr) {
            $oldPeaks[(int)$pr['player_id']] = (float)$pr['peak_club'];
        }
    } catch (Throwable $e) {
    }
    $pdo->prepare('DELETE FROM rating_cache WHERE rating_id = ?')->execute([$ratingId]);
    $ins = $pdo->prepare('INSERT INTO rating_cache
        (rating_id, player_id, games, sum_total, sum_plus, avg_total, club_score, pu_count,
         lh_sum, dop_sum, minus_sum, ci_sum, tech_count,
         w_civ, g_civ, w_maf, g_maf, w_sher, g_sher, w_don, g_don, mvp_evenings,
         dop_civ, dop_maf, dop_sher, dop_don, peak_club)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    foreach ($agg as $pid => $a) {
        $a['sum_plus'] = $a['dop_sum'] + $a['lh_sum'] + $a['ci_sum'];
        $avg = ($a['games'] > 0 && $a['games'] >= $minGames) ? $a['sum_total'] / $a['games'] : null;
        $club = $avg !== null ? $avg * $a['sum_total'] : null;
        $peakClub = max((float)($oldPeaks[$pid] ?? 0), $club !== null ? (float)$club : 0.0);
        $ins->execute([
            $ratingId, $pid, $a['games'],
            round($a['sum_total'], 2), round($a['sum_plus'], 2),
            $avg !== null ? round($avg, 4) : null,
            $club !== null ? round($club, 4) : null,
            $a['pu_count'], round($a['lh_sum'], 1), round($a['dop_sum'], 1),
            round($a['minus_sum'], 1), round($a['ci_sum'], 2), $a['tech_count'],
            $a['w_civ'], $a['g_civ'], $a['w_maf'], $a['g_maf'],
            $a['w_sher'], $a['g_sher'], $a['w_don'], $a['g_don'],
            $mvp[$pid] ?? 0,
            round($a['dop_civ'], 1), round($a['dop_maf'], 1), round($a['dop_sher'], 1), round($a['dop_don'], 1),
            $peakClub > 0 ? round($peakClub, 4) : null,
        ]);
    }
    if ($ownTx) {
        $pdo->commit();
    }
}

function rating_recompute_all(): void
{
    foreach (db()->query('SELECT id FROM ratings WHERE is_active = 1 AND is_frozen = 0')->fetchAll() as $r) {
        rating_recompute((int)$r['id']);
    }
}

// Пересчёт рейтинга + ELO под именованной блокировкой (GET_LOCK): одновременные
// сохранения нескольких судей (по столам турнира) не сталкиваются — пересчёты
// выполняются строго по очереди, без гонок и затирания данных.
function recompute_all_locked(): void
{
    $pdo = db();
    $got = false;
    try {
        // Ждём блокировку (до 2×20с). Если так и не получили — не оставляем рейтинг
        // устаревшим: пересчитываем без блокировки, но фиксируем это в лог для диагностики.
        for ($attempt = 0; $attempt < 2 && !$got; $attempt++) {
            $got = ((int)$pdo->query("SELECT GET_LOCK('triada_recompute', 20)")->fetchColumn()) === 1;
        }
        if (!$got && function_exists('app_log_error')) {
            app_log_error('WARN', 'recompute_all_locked: GET_LOCK не получен — пересчёт идёт без блокировки', __FILE__, __LINE__);
        }
        rating_recompute_all();
        if (is_file(ROOT . '/inc/elo.php')) {
            require_once ROOT . '/inc/elo.php';
            elo_recompute();
        }
    } finally {
        if ($got) {
            try {
                $pdo->query("SELECT RELEASE_LOCK('triada_recompute')");
            } catch (Throwable $e) {
            }
        }
    }
}

// Итоги по местам для отображения протокола одной игры.
// Использует тотальные счётчики основного рейтинга для Ci (как в таблице).
function game_display_totals(array $game, array $seats, ?array $distTotals = null): array
{
    static $mainTotals = null;
    if ($mainTotals === null) {
        $mainTotals = ['pu' => [], 'games' => []];
        try {
            $main = db()->query('SELECT id FROM ratings WHERE is_main = 1 LIMIT 1')->fetchColumn();
            if ($main) {
                $st = db()->prepare('SELECT player_id, games, pu_count FROM rating_cache WHERE rating_id = ?');
                $st->execute([(int)$main]);
                foreach ($st->fetchAll() as $row) {
                    $mainTotals['games'][(int)$row['player_id']] = (int)$row['games'];
                    $mainTotals['pu'][(int)$row['player_id']] = (int)$row['pu_count'];
                }
            }
        } catch (Throwable $e) {
        }
    }
    // дистанция для Ci: переданная (турнир — свои игры) либо основной рейтинг (вечера/одиночная игра)
    $totals = $distTotals ?? $mainTotals;
    $bonusPu = max(0.0, bm_bonus_for($seats, (int)($game['bm_seat1'] ?? 0), (int)($game['bm_seat2'] ?? 0), (int)($game['bm_seat3'] ?? 0)));
    $bonusV0 = max(0.0, bm_bonus_for($seats, (int)($game['vote0_bm1'] ?? 0), (int)($game['vote0_bm2'] ?? 0), (int)($game['vote0_bm3'] ?? 0)));
    $puSeat = (int)$game['first_killed_seat'];
    $v0Seat = (int)($game['vote0_seat'] ?? 0);
    $out = [];
    foreach ($seats as $s) {
        $pid = (int)$s['player_id'];
        $seatNo = (int)$s['seat'];
        $isRed = in_array($s['role'], ROLE_RED, true);
        $isPu = $puSeat > 0 && $seatNo === $puSeat;
        $isV0 = $v0Seat > 0 && $seatNo === $v0Seat;
        $isLhMaker = $isPu || $isV0;
        $seatBonus = $isPu ? $bonusPu : ($isV0 ? $bonusV0 : 0.0);
        $ci = $isPu
            ? ci_value($s['role'], $game['winner'], $totals['pu'][$pid] ?? 0, $totals['games'][$pid] ?? 0, $bonusPu)
            : 0.0;
        $out[$seatNo] = [
            'total' => seat_total($s, $game['winner'], $isLhMaker, $seatBonus, $ci),
            'ci' => $ci,
            'is_pu' => $isPu,
            'lh' => ($isLhMaker && $isRed && $seatBonus > 0) ? $seatBonus : 0.0,
        ];
    }
    return $out;
}

// Ячейка винрейта (процент + w/g, цвет по проценту). Общая для рейтинга и турниров.
// $tie — вторичный ключ сортировки (доп-баллы за роль): при равном % выше тот, у кого больше допов.
function wr_cell(int $w, int $g, float $tie = 0.0): string
{
    if (!$g) {
        return '<td class="num c-cards" data-sort="-1"><div style="text-align:center;color:var(--tx3);">—</div></td>';
    }
    $pct = round($w / $g * 100);
    $sort = (int)$pct * 1000000 + min((int)round(max(0.0, $tie) * 100), 999999);
    $col = $pct >= 60 ? 'var(--ok)' : ($pct < 42 ? 'var(--ac)' : 'var(--tx)');
    return '<td class="num c-cards" data-sort="' . $sort . '"><div style="white-space:nowrap;line-height:1.15;text-align:center;">'
        . '<span style="color:' . $col . ';font-weight:600;">' . $pct . '%</span>'
        . '<div style="font-size:11px;color:var(--tx2);">' . $w . '/' . $g . '</div></div></td>';
}

// Полный агрегат по произвольному набору игр (итоговая таблица турнира и т.п.).
// Итог/Ci берутся через game_display_totals (как в протоколе и основном рейтинге).
// Порог минимума игр НЕ применяется — ~Σ/~Σ×Σ считаются всем (у турниров мало игр).
function standings_from_games(array $games, array $seatsByGame): array
{
    $roleKey = ['civ' => 'civ', 'maf' => 'maf', 'sheriff' => 'sher', 'don' => 'don'];
    $rows = [];
    // дистанция турнира для Ci (формула 8.6.1): игры и красные/шериф-ПУ в пределах турнира
    $distGames = [];
    $distPu = [];
    foreach ($games as $g) {
        foreach ($seatsByGame[(int)$g['id']] ?? [] as $s) {
            $pid = (int)$s['player_id'];
            $distGames[$pid] = ($distGames[$pid] ?? 0) + 1;
            if ((int)$g['first_killed_seat'] === (int)$s['seat'] && in_array($s['role'], ROLE_RED, true)) {
                $distPu[$pid] = ($distPu[$pid] ?? 0) + 1;
            }
        }
    }
    $distTotals = ['games' => $distGames, 'pu' => $distPu];
    foreach ($games as $g) {
        $seats = $seatsByGame[(int)$g['id']] ?? [];
        $totals = game_display_totals($g, $seats, $distTotals);
        $winner = $g['winner'];
        foreach ($seats as $s) {
            $pid = (int)$s['player_id'];
            if (!isset($rows[$pid])) {
                $rows[$pid] = [
                    'pid' => $pid, 'nick' => $s['nickname'], 'avatar' => $s['avatar'],
                    'elo' => $s['elo'] ?? 1000,
                    'games' => 0, 'sum' => 0.0, 'sum_plus' => 0.0, 'plus' => 0.0,
                    'pu_count' => 0, 'lh_sum' => 0.0, 'dop_sum' => 0.0, 'minus_sum' => 0.0, 'ci_sum' => 0.0,
                    'w_civ' => 0, 'g_civ' => 0, 'w_maf' => 0, 'g_maf' => 0,
                    'w_sher' => 0, 'g_sher' => 0, 'w_don' => 0, 'g_don' => 0,
                ];
            }
            $r = &$rows[$pid];
            $tt = $totals[(int)$s['seat']] ?? ['total' => 0.0, 'ci' => 0.0, 'is_pu' => false];
            $r['games']++;
            $r['sum'] += (float)$tt['total'];
            $r['dop_sum'] += (float)$s['plus'];
            $r['plus'] += (float)$s['plus'];
            $r['minus_sum'] += (float)$s['minus'] + ((int)$s['fouls'] >= 4 ? 0.6 : 0) + 0.3 * (int)$s['tech_fouls'] + 0.6 * (int)($s['big_tech'] ?? 0)
                + ((int)($s['removal'] ?? 0) === 1 ? 0.6 : ((int)($s['removal'] ?? 0) === 2 ? 1.2 : 0));
            $r['ci_sum'] += (float)$tt['ci'];
            if (!empty($tt['is_pu']) && in_array($s['role'], ROLE_RED, true)) {
                $r['pu_count']++;
            }
            $r['lh_sum'] += (float)($tt['lh'] ?? 0); // ЛХ уже начислен только красным/шерифу
            $rk = $roleKey[$s['role']] ?? null;
            if ($rk) {
                $r['g_' . $rk]++;
                if (($winner === 'red' && in_array($s['role'], ROLE_RED, true))
                    || ($winner === 'black' && in_array($s['role'], ROLE_BLACK, true))) {
                    $r['w_' . $rk]++;
                }
            }
            unset($r);
        }
    }
    foreach ($rows as &$r) {
        $r['sum_plus'] = $r['dop_sum'] + $r['lh_sum'] + $r['ci_sum'];
        $r['avg_total'] = $r['games'] > 0 ? $r['sum'] / $r['games'] : 0.0;
        $r['club_score'] = $r['avg_total'] * $r['sum'];
    }
    unset($r);
    // Рейтинг турнира — строго по Σ (сумме баллов за все игры); тай-брейк по Σ+ (бонусным баллам)
    uasort($rows, fn($a, $b) => [round($b['sum'], 2), round($b['sum_plus'], 2)] <=> [round($a['sum'], 2), round($a['sum_plus'], 2)]);
    return $rows;
}

// ELO каждого игрока «на момент мероприятия» (турнира/вечера) — его входной ELO
// ДО первой по хронологии игры из набора $gameIds. Берётся из elo_history
// (elo_after − delta той игры, что шла первой). Внутри одного дня/турнира хронология
// = порядок game_id (так считает elo_recompute), поэтому первая игра = MIN(game_id).
// Нужно, чтобы итоговые таблицы турниров/вечеров показывали ELO на тот момент,
// а не текущий. Возвращает [player_id => elo_before].
function event_entry_elo(array $gameIds): array
{
    $ids = array_values(array_filter(array_map('intval', $gameIds)));
    if (!$ids) {
        return [];
    }
    $in = implode(',', array_fill(0, count($ids), '?'));
    $out = [];
    try {
        $st = db()->prepare("SELECT eh.player_id, (eh.elo_after - eh.delta) AS elo_before
            FROM elo_history eh
            JOIN (SELECT player_id, MIN(game_id) AS first_g FROM elo_history WHERE game_id IN ($in) GROUP BY player_id) f
              ON f.player_id = eh.player_id AND f.first_g = eh.game_id");
        $st->execute($ids);
        foreach ($st->fetchAll() as $r) {
            $out[(int)$r['player_id']] = round((float)$r['elo_before'], 1);
        }
    } catch (Throwable $e) {
    }
    return $out;
}
