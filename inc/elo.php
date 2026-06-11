<?php
declare(strict_types=1);

// Динамический ELO. Старт 1000 у каждого. Команда красных vs чёрных,
// каждый игрок обновляется против среднего ELO соперников, с поправкой на вклад
// (его «+»/«−» в игре относительно средней по команде). Полный пересчёт по
// всем завершённым играм в хронологическом порядке.

const ELO_START = 1000.0;
const ELO_K = 24.0;

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
        $mRed = $meanScore($red);
        $mBlack = $meanScore($black);

        $apply = function (array $team, float $oppAvg, float $teamScoreResult, float $teamMeanScore) use (&$elo, $get, $hist, $g) {
            foreach ($team as $p) {
                $exp = 1.0 / (1.0 + pow(10, ($oppAvg - $p['elo']) / 400.0));
                // вклад: ±0.5 от базового в зависимости от перформанса в игре
                $contrib = 1.0 + 0.5 * max(-1.0, min(1.0, $p['score'] - $teamMeanScore));
                $delta = ELO_K * ($teamScoreResult - $exp) * $contrib;
                $newElo = $get($p['pid']) + $delta;
                $elo[$p['pid']] = $newElo;
                $hist->execute([$p['pid'], (int)$g['id'], $g['gdate'], round($newElo, 1), round($delta, 1)]);
            }
        };
        $apply($red, $avgBlack, $scoreRed, $mRed);
        $apply($black, $avgRed, 1.0 - $scoreRed, $mBlack);
    }

    $upd = $pdo->prepare('UPDATE players SET elo = ? WHERE id = ?');
    foreach ($elo as $pid => $val) {
        $upd->execute([round($val, 1), $pid]);
    }
}
