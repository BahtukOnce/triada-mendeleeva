<?php
declare(strict_types=1);

// Динамический ELO. Старт 1000 у каждого. Команда красных vs чёрных,
// каждый игрок обновляется против среднего ELO соперников, с поправкой на вклад
// (его «+»/«−» в игре относительно средней по команде). Полный пересчёт по
// всем завершённым играм в хронологическом порядке.

const ELO_START = 1000.0;
const ELO_K = 310.0;            // размах командной дельты (масштаб шкалы)
const ELO_DIV = 2500.0;         // ширина логистики команда-vs-команда — держит масштаб
const ELO_DIV_INDIV = 900.0;    // логистика для деления внутри команды: баланс «различие vs сжатие шкалы»
const ELO_SURPRISE_BASE = 0.35; // база доли, чтобы у фаворита тоже что-то капало
const ELO_LOSS_MULT = 0.65;     // проигрыш мягче победы
const ELO_FLOOR = 100.0;        // нижний предел

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

        // Командная дельта (масштаб): красные +X, чёрные −X — по среднему ELO команд
        $expRed = 1.0 / (1.0 + pow(10, ($avgBlack - $avgRed) / ELO_DIV));
        $deltaRedTeam = ELO_K * ($scoreRed - $expRed);

        // Деление дельты внутри команды: по ЛИЧНОЙ неожиданности (личный ELO vs соперники, узкая логистика)
        // × вклад за игру. Андердог-победа / фаворит-проигрыш → больше; сумма по команде = teamDelta.
        $distribute = function (array $team, float $teamDelta, float $oppAvg, float $teamResult, float $teamMean) use (&$elo, $get, $hist, $g) {
            $weights = [];
            foreach ($team as $p) {
                $exp = 1.0 / (1.0 + pow(10, ($oppAvg - $p['elo']) / ELO_DIV_INDIV));
                $surprise = ELO_SURPRISE_BASE + abs($teamResult - $exp);
                $contrib = max(0.4, 1.0 + 0.25 * ($p['score'] - $teamMean));
                $weights[] = $surprise * $contrib;
            }
            $wsum = array_sum($weights) ?: count($team);
            foreach ($team as $i => $p) {
                $delta = $teamDelta * $weights[$i] / $wsum;
                if ($delta < 0) {
                    $delta *= ELO_LOSS_MULT; // проигрыш мягче
                }
                $cur = $get($p['pid']);
                $newElo = max(ELO_FLOOR, $cur + $delta);
                $elo[$p['pid']] = $newElo;
                $hist->execute([$p['pid'], (int)$g['id'], $g['gdate'], round($newElo, 1), round($newElo - $cur, 1)]);
            }
        };
        $distribute($red, $deltaRedTeam, $avgBlack, $scoreRed, $meanScore($red));
        $distribute($black, -$deltaRedTeam, $avgRed, 1.0 - $scoreRed, $meanScore($black));
    }

    $upd = $pdo->prepare('UPDATE players SET elo = ? WHERE id = ?');
    foreach ($elo as $pid => $val) {
        $upd->execute([round($val, 1), $pid]);
    }
}
