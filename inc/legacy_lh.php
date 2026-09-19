<?php
declare(strict_types=1);
// Дособирание истории: первоубиенный и лучший ход у легаси-игр (сезоны 2022/23–2024/25).
//
// Импорт с mafiauniverse принёс только ник, место, роль и итог. ПУ и ЛХ там в протоколе есть,
// но в импорт не попали — поэтому у исторических игр не считались ЛХ, Ci, «точность ЛХ», часть
// ачивок и рекордов. Недостающее снято с mafiauniverse в db/legacy_lh.txt (см. шапку файла).
//
// Сопоставление: дата вечера + ник на месте первоубиенного. Если в один день ник совпал у
// нескольких игр — берём ту, чей номер совпадает с порядком игры в источнике. Ничего не трогаем
// там, где совпадение неоднозначно: такие строки уходят в отчёт.
//
// Баллы: у них итог игрока УЖЕ включает бонус за ЛХ, и импорт положил его в «допы». Наш движок
// начислит ЛХ сам (0.1 / 0.3 / 0.6), поэтому их бонус из допов вычитаем — иначе задвоится.

require_once __DIR__ . '/import.php';   // nick_key()

// Разбор db/legacy_lh.txt → [['src','date','gno','pu','bm'=>[..],'bonus','nick'], ...]
function legacy_lh_rows(): array
{
    $file = ROOT . '/db/legacy_lh.txt';
    if (!is_file($file)) {
        return [];
    }
    $out = [];
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        $p = explode('|', $line);
        if (count($p) < 7) {
            continue;
        }
        $bm = array_values(array_filter(array_map('intval', explode(',', $p[4])), fn($n) => $n >= 1 && $n <= 10));
        $out[] = [
            'src' => $p[0],
            'date' => $p[1],
            'gno' => (int)$p[2],
            'pu' => (int)$p[3],
            'bm' => array_slice(array_values(array_unique($bm)), 0, 3),
            'bonus' => $p[5] !== '' ? (float)$p[5] : 0.0,
            'nick' => trim($p[6]),
        ];
    }
    return $out;
}

/**
 * Сопоставляет строки с нашими играми и (при $apply) проставляет ПУ и ЛХ.
 * Возвращает отчёт: ['ok' => [...], 'skip' => [...], 'applied' => n].
 */
function legacy_lh_run(bool $apply): array
{
    $rows = legacy_lh_rows();
    $report = ['ok' => [], 'skip' => [], 'applied' => 0, 'total' => count($rows)];
    if (!$rows) {
        return $report;
    }
    $pdo = db();

    // Легаси-игры: только вечера с проставленным сезоном (это и есть маркер импорта).
    $gamesByDate = [];
    $q = $pdo->query("SELECT g.id, g.game_no, g.first_killed_seat, g.bm_seat1, d.date, d.id AS day_id
        FROM games g JOIN game_days d ON d.id = g.day_id
        WHERE d.season IS NOT NULL AND g.status = 'finished'
        ORDER BY d.date, g.game_no");
    foreach ($q->fetchAll() as $g) {
        $gamesByDate[(string)$g['date']][] = $g;
    }
    // Места этих игр: game_id => seat => [player_id, nick_key, plus]
    $seats = [];
    $sq = $pdo->query("SELECT gs.game_id, gs.seat, gs.player_id, gs.plus, p.nickname
        FROM game_seats gs
        JOIN games g ON g.id = gs.game_id
        JOIN game_days d ON d.id = g.day_id
        JOIN players p ON p.id = gs.player_id
        WHERE d.season IS NOT NULL AND g.status = 'finished'");
    foreach ($sq->fetchAll() as $s) {
        $seats[(int)$s['game_id']][(int)$s['seat']] = [
            'pid' => (int)$s['player_id'],
            'key' => nick_key((string)$s['nickname']),
            'nick' => (string)$s['nickname'],
            'plus' => (float)$s['plus'],
        ];
    }

    // Порядок строк внутри дня (по номеру игры в источнике) — для разрешения неоднозначностей
    $order = [];
    foreach ($rows as $i => $r) {
        $order[$r['date']][] = $i;
    }
    foreach ($order as $date => $idx) {
        usort($idx, fn($a, $b) => $rows[$a]['gno'] <=> $rows[$b]['gno']);
        $order[$date] = $idx;
    }

    $updGame = $pdo->prepare('UPDATE games SET first_killed_seat = ?, bm_seat1 = ?, bm_seat2 = ?, bm_seat3 = ? WHERE id = ?');
    $updSeat = $pdo->prepare('UPDATE game_seats SET plus = ? WHERE game_id = ? AND seat = ?');

    foreach ($rows as $i => $r) {
        $cands = $gamesByDate[$r['date']] ?? [];
        if (!$cands) {
            $report['skip'][] = $r + ['why' => 'нет вечера с такой датой'];
            continue;
        }
        $key = nick_key($r['nick']);
        $match = [];
        foreach ($cands as $g) {
            $s = $seats[(int)$g['id']][$r['pu']] ?? null;
            if ($s && $s['key'] === $key) {
                $match[] = $g;
            }
        }
        if (!$match) {
            $report['skip'][] = $r + ['why' => 'на месте ' . $r['pu'] . ' нет «' . $r['nick'] . '»'];
            continue;
        }
        if (count($match) > 1) {
            // Несколько игр дня с тем же ником на том же месте — берём по порядку
            $rank = array_search($i, $order[$r['date']], true);
            $pick = null;
            foreach ($match as $g) {
                if ((int)$g['game_no'] === $rank + 1) {
                    $pick = $g;
                }
            }
            if (!$pick) {
                $report['skip'][] = $r + ['why' => 'несколько подходящих игр (' . count($match) . ')'];
                continue;
            }
            $match = [$pick];
        }
        $g = $match[0];
        $gid = (int)$g['id'];
        $bm = array_pad($r['bm'], 3, 0);
        if ($apply) {
            $updGame->execute([$r['pu'], $bm[0] ?: null, $bm[1] ?: null, $bm[2] ?: null, $gid]);
            if ($r['bonus'] > 0) {
                $cur = $seats[$gid][$r['pu']]['plus'] ?? 0.0;
                $updSeat->execute([round(max(0.0, $cur - $r['bonus']), 2), $gid, $r['pu']]);
            }
            $report['applied']++;
        }
        $report['ok'][] = $r + [
            'game_id' => $gid,
            'game_no' => (int)$g['game_no'],
            'plus_was' => $seats[$gid][$r['pu']]['plus'] ?? 0.0,
            'plus_now' => round(max(0.0, ($seats[$gid][$r['pu']]['plus'] ?? 0.0) - $r['bonus']), 2),
        ];
    }
    return $report;
}
