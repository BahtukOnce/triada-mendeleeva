<?php
declare(strict_types=1);

// Динамический ELO. Старт 1000 у каждого. Команда красных vs чёрных,
// каждый игрок обновляется против среднего ELO соперников, с поправкой на вклад
// (его «+»/«−» в игре относительно средней по команде). Полный пересчёт по
// всем завершённым играм в хронологическом порядке.

const ELO_START = 1000.0;
const ELO_K = 310.0;         // размах ходов
const ELO_DIV = 2500.0;      // ширина логистики (команда против команды)
const ELO_LOSS_MULT = 0.65;  // проигрыш мягче победы: меньше боли за игру + шкала растёт (больше игроков с высоким ELO)
const ELO_INDIV_W = 0.07;    // вес личного ELO (мягкий): кто ниже среднего стола — больше за победу и меньше теряет
const ELO_REL_SCALE = 600.0; // на сколько ELO от среднего стола даёт полную поправку
const ELO_FLOOR = 100.0;     // нижний предел

function elo_recompute(): void
{
    $pdo = db();
    $pdo->exec('UPDATE players SET elo = ' . ELO_START);
    $pdo->exec('DELETE FROM elo_history');

    $games = $pdo->query("SELECT g.id, g.winner, COALESCE(d.date, t.date_from) AS gdate
        FROM games g
        LEFT JOIN game_days d ON d.id = g.day_id
        LEFT JOIN tournaments t ON t.id = g.tournament_id
        WHERE g.status = 'finished' AND g.winner IS NOT NULL
        ORDER BY COALESCE(d.date, t.date_from) IS NULL, COALESCE(d.date, t.date_from), g.id")->fetchAll();
    if (!$games) {
        return;
    }

    $ids = array_column($games, 'id');
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("SELECT game_id, player_id, role, plus, minus FROM game_seats WHERE game_id IN ($in)");
    $st->execute($ids);
    $seatsByGame = [];
    foreach ($st->fetchAll() as $s) {
        $seatsByGame[(int)$s['game_id']][] = $s;
    }

    $elo = []; // player_id => current elo
    $get = function (int $pid) use (&$elo) {
        return $elo[$pid] ?? ELO_START;
    };
    $hist = $pdo->prepare('INSERT INTO elo_history (player_id, game_id, gdate, elo_after, delta) VALUES (?,?,?,?,?)');

    foreach ($games as $g) {
        $seats = $seatsByGame[(int)$g['id']] ?? [];
        if (count($seats) < 4) {
            continue;
        }
        $red = [];
        $black = [];
        foreach ($seats as $s) {
            $pid = (int)$s['player_id'];
            $score = (float)$s['plus'] - (float)$s['minus'];
            $entry = ['pid' => $pid, 'elo' => $get($pid), 'score' => $score];
            if (in_array($s['role'], ['civ', 'sheriff'], true)) {
                $red[] = $entry;
            } else {
                $black[] = $entry;
            }
        }
        if (!$red || !$black) {
            continue;
        }
        $avg = fn($t) => array_sum(array_column($t, 'elo')) / count($t);
        $avgRed = $avg($red);
        $avgBlack = $avg($black);
        $scoreRed = $g['winner'] === 'red' ? 1.0 : ($g['winner'] === 'black' ? 0.0 : 0.5);
        $meanScore = fn($t) => array_sum(array_column($t, 'score')) / count($t);

        // Командная дельта: красные получают +X, чёрные −X
        $expRed = 1.0 / (1.0 + pow(10, ($avgBlack - $avgRed) / ELO_DIV));
        $deltaRedTeam = ELO_K * ($scoreRed - $expRed);

        // Средний ELO стола — точка отсчёта для индивидуальной поправки
        $allSeats = array_merge($red, $black);
        $tableEloMean = array_sum(array_column($allSeats, 'elo')) / count($allSeats);

        // Распределение дельты команды: вклад за игру × индивидуальный ELO (сумма по команде = teamDelta → zero-sum)
        $distribute = function (array $team, float $teamDelta, float $teamMean) use (&$elo, $get, $hist, $g, $tableEloMean) {
            $sgn = $teamDelta >= 0 ? 1.0 : -1.0;
            $factors = [];
            foreach ($team as $p) {
                // вклад за игру (допы/минусы относительно команды)
                $contrib = max(0.25, 1.0 + 0.4 * $sgn * max(-1.0, min(1.0, $p['score'] - $teamMean)));
                // личный ELO относительно среднего ELO стола: ниже среднего → победа ценнее, поражение мягче
                $rel = max(-1.0, min(1.0, ($p['elo'] - $tableEloMean) / ELO_REL_SCALE));
                $eloF = max(0.3, 1.0 - $sgn * ELO_INDIV_W * $rel);
                $factors[] = $contrib * $eloF;
            }
            $fsum = array_sum($factors) ?: count($team);
            foreach ($team as $i => $p) {
                $delta = $teamDelta * $factors[$i] / $fsum;
                if ($delta < 0) {
                    $delta *= ELO_LOSS_MULT; // проигрыш мягче
                }
                $cur = $get($p['pid']);
                $newElo = max(ELO_FLOOR, $cur + $delta);
                $elo[$p['pid']] = $newElo;
                $hist->execute([$p['pid'], (int)$g['id'], $g['gdate'], round($newElo, 1), round($newElo - $cur, 1)]);
            }
        };
        $distribute($red, $deltaRedTeam, $meanScore($red));
        $distribute($black, -$deltaRedTeam, $meanScore($black));
    }

    $upd = $pdo->prepare('UPDATE players SET elo = ? WHERE id = ?');
    foreach ($elo as $pid => $val) {
        $upd->execute([round($val, 1), $pid]);
    }
}
