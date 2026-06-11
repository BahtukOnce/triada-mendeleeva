<?php
declare(strict_types=1);

// Движок рейтинга — формулы из клубной Google-таблицы.
// Победа: red = мирные+шериф, black = мафия+дон, draw = ничья.

const ROLE_RED = ['civ', 'sheriff'];
const ROLE_BLACK = ['maf', 'don'];

// Бонус ЛХ: сколько из 3 кандидатов оказались мафией → 0.1 / 0.3 / 0.6
function bm_bonus_for_game(array $seats, array $game): float
{
    $rolesBySeat = [];
    foreach ($seats as $s) {
        $rolesBySeat[(int)$s['seat']] = $s['role'];
    }
    $hits = 0;
    $given = 0;
    foreach (['bm_seat1', 'bm_seat2', 'bm_seat3'] as $k) {
        $seatNo = (int)($game[$k] ?? 0);
        if ($seatNo >= 1 && $seatNo <= 10) {
            $given++;
            if (in_array($rolesBySeat[$seatNo] ?? '', ROLE_BLACK, true)) {
                $hits++;
            }
        }
    }
    if ($given === 0) {
        return -1.0; // ЛХ не заполнен
    }
    return [0 => 0.0, 1 => 0.1, 2 => 0.3, 3 => 0.6][$hits];
}

// Ci — компенсация первоубиенному (мирный/шериф, при заполненном ЛХ с бонусом > 0).
// puTotal/gamesTotal — «плывущие» тотальные счётчики игрока в рейтинге.
function ci_value(string $role, ?string $winner, int $puTotal, int $gamesTotal, float $bmBonus): float
{
    if (!in_array($role, ROLE_RED, true) || $bmBonus <= 0 || $gamesTotal <= 0) {
        return 0.0;
    }
    $g = $gamesTotal * 0.4;
    $p = (float)$puTotal;
    if ($g >= $p) {
        $base = $g > 0 ? ($p * 0.4) / $g : 0.4;
    } else {
        $base = 0.4;
    }
    if ($winner === 'red') {
        $base = $base / 2;
    }
    return min(0.4, $base);
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
        if ($isPu && $bmBonus > 0) {
            $total += $bmBonus; // при ничьей ПУ получает ЛХ независимо от роли
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
            if ((int)$g['first_killed_seat'] === (int)$s['seat']) {
                $puTotal[$pid] = ($puTotal[$pid] ?? 0) + 1;
            }
        }
    }

    // Проход 2: суммы
    $agg = [];
    $blank = [
        'games' => 0, 'sum_total' => 0.0, 'sum_plus' => 0.0, 'pu_count' => 0,
        'lh_sum' => 0.0, 'dop_sum' => 0.0, 'minus_sum' => 0.0, 'ci_sum' => 0.0,
        'w_civ' => 0, 'g_civ' => 0, 'w_maf' => 0, 'g_maf' => 0,
        'w_sher' => 0, 'g_sher' => 0, 'w_don' => 0, 'g_don' => 0,
    ];
    $roleKey = ['civ' => 'civ', 'maf' => 'maf', 'sheriff' => 'sher', 'don' => 'don'];

    foreach ($games as $g) {
        $seats = $seatsByGame[(int)$g['id']] ?? [];
        $winner = $g['winner'];
        $bm = bm_bonus_for_game($seats, $g);
        $bmBonus = max(0.0, $bm);
        foreach ($seats as $s) {
            $pid = (int)$s['player_id'];
            $agg[$pid] = $agg[$pid] ?? $blank;
            $a = &$agg[$pid];
            $isPu = (int)$g['first_killed_seat'] === (int)$s['seat'];
            $ci = $isPu
                ? ci_value($s['role'], $winner, $puTotal[$pid] ?? 0, $gamesTotal[$pid] ?? 0, $bmBonus)
                : 0.0;
            $total = seat_total($s, $winner, $isPu, $bmBonus, $ci);

            $a['games']++;
            $a['sum_total'] += $total;
            $a['dop_sum'] += (float)$s['plus'];
            $a['minus_sum'] += (float)$s['minus']
                + ((int)$s['fouls'] >= 4 ? 0.6 : 0)
                + 0.3 * (int)$s['tech_fouls'];
            $a['ci_sum'] += $ci;
            if ($isPu) {
                $a['pu_count']++;
                $gotLh = $winner === 'draw'
                    ? $bmBonus > 0
                    : ($bmBonus > 0 && in_array($s['role'], ROLE_RED, true));
                if ($gotLh) {
                    $a['lh_sum'] += $bmBonus;
                }
            }
            $rk = $roleKey[$s['role']];
            $a['g_' . $rk]++;
            if ($winner === 'red' && in_array($s['role'], ROLE_RED, true)) {
                $a['w_' . $rk]++;
            } elseif ($winner === 'black' && in_array($s['role'], ROLE_BLACK, true)) {
                $a['w_' . $rk]++;
            }
            unset($a);
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
    $pdo->prepare('DELETE FROM rating_cache WHERE rating_id = ?')->execute([$ratingId]);
    $ins = $pdo->prepare('INSERT INTO rating_cache
        (rating_id, player_id, games, sum_total, sum_plus, avg_total, club_score, pu_count,
         lh_sum, dop_sum, minus_sum, ci_sum,
         w_civ, g_civ, w_maf, g_maf, w_sher, g_sher, w_don, g_don)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    foreach ($agg as $pid => $a) {
        $a['sum_plus'] = $a['dop_sum'] + $a['lh_sum'] + $a['ci_sum'];
        $avg = ($a['games'] > 0 && $a['games'] >= $minGames) ? $a['sum_total'] / $a['games'] : null;
        $club = $avg !== null ? $avg * $a['sum_total'] : null;
        $ins->execute([
            $ratingId, $pid, $a['games'],
            round($a['sum_total'], 2), round($a['sum_plus'], 2),
            $avg !== null ? round($avg, 4) : null,
            $club !== null ? round($club, 4) : null,
            $a['pu_count'], round($a['lh_sum'], 1), round($a['dop_sum'], 1),
            round($a['minus_sum'], 1), round($a['ci_sum'], 2),
            $a['w_civ'], $a['g_civ'], $a['w_maf'], $a['g_maf'],
            $a['w_sher'], $a['g_sher'], $a['w_don'], $a['g_don'],
        ]);
    }
    if ($ownTx) {
        $pdo->commit();
    }
}

function rating_recompute_all(): void
{
    foreach (db()->query('SELECT id FROM ratings WHERE is_active = 1')->fetchAll() as $r) {
        rating_recompute((int)$r['id']);
    }
}

// Итоги по местам для отображения протокола одной игры.
// Использует тотальные счётчики основного рейтинга для Ci (как в таблице).
function game_display_totals(array $game, array $seats): array
{
    static $totals = null;
    if ($totals === null) {
        $totals = ['pu' => [], 'games' => []];
        try {
            $main = db()->query('SELECT id FROM ratings WHERE is_main = 1 LIMIT 1')->fetchColumn();
            if ($main) {
                $st = db()->prepare('SELECT player_id, games, pu_count FROM rating_cache WHERE rating_id = ?');
                $st->execute([(int)$main]);
                foreach ($st->fetchAll() as $row) {
                    $totals['games'][(int)$row['player_id']] = (int)$row['games'];
                    $totals['pu'][(int)$row['player_id']] = (int)$row['pu_count'];
                }
            }
        } catch (Throwable $e) {
        }
    }
    $bm = bm_bonus_for_game($seats, $game);
    $bmBonus = max(0.0, $bm);
    $out = [];
    foreach ($seats as $s) {
        $pid = (int)$s['player_id'];
        $isPu = (int)$game['first_killed_seat'] === (int)$s['seat'];
        $ci = $isPu
            ? ci_value($s['role'], $game['winner'], $totals['pu'][$pid] ?? 0, $totals['games'][$pid] ?? 0, $bmBonus)
            : 0.0;
        $out[(int)$s['seat']] = [
            'total' => seat_total($s, $game['winner'], $isPu, $bmBonus, $ci),
            'ci' => $ci,
            'is_pu' => $isPu,
        ];
    }
    return $out;
}
