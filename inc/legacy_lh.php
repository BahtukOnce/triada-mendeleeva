<?php
declare(strict_types=1);
// Дособирание истории: первоубиенный и лучший ход у легаси-игр (сезоны 2022/23–2024/25).
//
// Импорт с mafiauniverse принёс только ник, место, роль и итог. ПУ и ЛХ там в протоколе есть,
// но в импорт не попали — поэтому у исторических игр не считались ЛХ, Ci, «точность ЛХ», часть
// ачивок и рекордов. Недостающее снято с mafiauniverse в db/legacy_lh.txt (см. шапку файла).
//
// Сопоставление — по СОСТАВУ СТОЛА, а не по дате: дата у источника и у нас могла разойтись
// (855 залит одной датой, у ночных игр она зависит от часового пояса). Порядок попыток:
//   1) те же ники на тех же местах;
//   2) те же ники, но посадка другая — места ЛХ переносим через ники;
//   3) пересечение не меньше 8 ников (в импорте мог потеряться «Пустой слот»), лучший кандидат.
// Неоднозначное не трогаем — оно уходит в отчёт.
//
// Баллы: у них итог игрока УЖЕ включает бонус за ЛХ, и импорт положил его в «допы». Наш движок
// начислит ЛХ сам (0.1 / 0.3 / 0.6), поэтому их бонус из допов вычитаем — иначе задвоится.

require_once __DIR__ . '/import.php';   // nick_key()

// Разбор db/legacy_lh.txt
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
        $bm = array_values(array_unique(array_filter(array_map('intval', explode(',', $p[4])), fn($n) => $n >= 1 && $n <= 10)));
        $nicks = array_map('trim', explode(';', $p[6]));
        $seatKeys = [];
        foreach ($nicks as $i => $n) {
            if ($n !== '') {
                $seatKeys[$i + 1] = nick_key($n);
            }
        }
        $out[] = [
            'src' => $p[0],
            'date' => $p[1],
            'gno' => (int)$p[2],
            'pu' => (int)$p[3],
            'bm' => array_slice($bm, 0, 3),
            'bonus' => $p[5] !== '' ? (float)$p[5] : 0.0,
            'nick' => $nicks[(int)$p[3] - 1] ?? '',
            'keys' => $seatKeys,
        ];
    }
    return $out;
}

/**
 * Сопоставляет строки с нашими играми и (при $apply) проставляет ПУ и ЛХ.
 * Возвращает ['ok' => [...], 'skip' => [...], 'applied' => n, 'total' => n].
 */
function legacy_lh_run(bool $apply): array
{
    $rows = legacy_lh_rows();
    $report = ['ok' => [], 'skip' => [], 'applied' => 0, 'total' => count($rows)];
    if (!$rows) {
        return $report;
    }
    $pdo = db();

    // Легаси-игры: вечера с проставленным сезоном — это и есть маркер импорта истории
    $games = [];
    $q = $pdo->query("SELECT g.id, g.game_no, d.date
        FROM games g JOIN game_days d ON d.id = g.day_id
        WHERE d.season IS NOT NULL AND g.status = 'finished'");
    foreach ($q->fetchAll() as $g) {
        $games[(int)$g['id']] = ['id' => (int)$g['id'], 'game_no' => (int)$g['game_no'], 'date' => (string)$g['date'], 'seats' => []];
    }
    $sq = $pdo->query("SELECT gs.game_id, gs.seat, gs.plus, p.nickname
        FROM game_seats gs
        JOIN games g ON g.id = gs.game_id
        JOIN game_days d ON d.id = g.day_id
        JOIN players p ON p.id = gs.player_id
        WHERE d.season IS NOT NULL AND g.status = 'finished'");
    foreach ($sq->fetchAll() as $s) {
        $gid = (int)$s['game_id'];
        if (isset($games[$gid])) {
            $games[$gid]['seats'][(int)$s['seat']] = ['key' => nick_key((string)$s['nickname']), 'plus' => (float)$s['plus']];
        }
    }
    // Индексы: точный состав по местам и состав без учёта посадки
    $exact = [];
    $bySet = [];
    foreach ($games as $gid => $g) {
        ksort($g['seats']);
        $keys = array_map(fn($s) => $s['key'], $g['seats']);
        $exact[implode(';', $keys)][] = $gid;
        $sorted = $keys;
        sort($sorted);
        $bySet[implode(';', $sorted)][] = $gid;
    }

    $updGame = $pdo->prepare('UPDATE games SET first_killed_seat = ?, bm_seat1 = ?, bm_seat2 = ?, bm_seat3 = ? WHERE id = ?');
    $updSeat = $pdo->prepare('UPDATE game_seats SET plus = ? WHERE game_id = ? AND seat = ?');
    $taken = [];   // одна наша игра — одна строка источника

    foreach ($rows as $r) {
        $recKeys = $r['keys'];
        ksort($recKeys);
        $way = '';
        $cands = [];

        $exactKey = implode(';', $recKeys);
        if (!empty($exact[$exactKey])) {
            $cands = $exact[$exactKey];
            $way = 'состав и посадка';
        }
        if (!$cands) {
            $sorted = array_values($recKeys);
            sort($sorted);
            if (!empty($bySet[implode(';', $sorted)])) {
                $cands = $bySet[implode(';', $sorted)];
                $way = 'состав, другая посадка';
            }
        }
        if (!$cands) {
            // Лучшее пересечение ников (в импорте мог потеряться пустой слот)
            $best = [];
            $bestN = 0;
            foreach ($games as $gid => $g) {
                $common = count(array_intersect($recKeys, array_map(fn($s) => $s['key'], $g['seats'])));
                if ($common > $bestN) {
                    $bestN = $common;
                    $best = [$gid];
                } elseif ($common === $bestN && $common > 0) {
                    $best[] = $gid;
                }
            }
            if ($bestN >= 8) {
                $cands = $best;
                $way = 'пересечение ' . $bestN . ' ников';
            }
        }
        $cands = array_values(array_filter($cands, fn($gid) => !isset($taken[$gid])));
        if (!$cands) {
            $report['skip'][] = $r + ['why' => 'не нашёл такой стол среди исторических игр'];
            continue;
        }
        if (count($cands) > 1) {
            $sameDate = array_values(array_filter($cands, fn($gid) => $games[$gid]['date'] === $r['date']));
            if (count($sameDate) === 1) {
                $cands = $sameDate;
            } else {
                $report['skip'][] = $r + ['why' => 'подходит несколько игр (' . count($cands) . ') с тем же составом'];
                continue;
            }
        }
        $gid = (int)$cands[0];
        $g = $games[$gid];
        // Места ЛХ переносим через ники: у нас посадка может отличаться от источника
        $seatOfKey = [];
        foreach ($g['seats'] as $seat => $s) {
            $seatOfKey[$s['key']] = (int)$seat;
        }
        $puKey = $recKeys[$r['pu']] ?? null;
        $ourPu = $puKey !== null ? ($seatOfKey[$puKey] ?? 0) : 0;
        if (!$ourPu) {
            $report['skip'][] = $r + ['why' => 'первоубиенного «' . $r['nick'] . '» нет в нашем составе'];
            continue;
        }
        $ourBm = [];
        foreach ($r['bm'] as $s) {
            $k = $recKeys[$s] ?? null;
            $ourBm[] = $k !== null ? ($seatOfKey[$k] ?? 0) : 0;
        }
        $ourBm = array_slice(array_values(array_filter($ourBm)), 0, 3);
        $bm = array_pad($ourBm, 3, 0);
        $taken[$gid] = true;

        $plusWas = $g['seats'][$ourPu]['plus'] ?? 0.0;
        $plusNow = round(max(0.0, $plusWas - $r['bonus']), 2);
        if ($apply) {
            $updGame->execute([$ourPu, $bm[0] ?: null, $bm[1] ?: null, $bm[2] ?: null, $gid]);
            if ($r['bonus'] > 0) {
                $updSeat->execute([$plusNow, $gid, $ourPu]);
            }
            $report['applied']++;
        }
        $report['ok'][] = $r + [
            'game_id' => $gid,
            'game_no' => $g['game_no'],
            'our_date' => $g['date'],
            'our_pu' => $ourPu,
            'our_bm' => $ourBm,
            'way' => $way,
            'plus_was' => $plusWas,
            'plus_now' => $plusNow,
        ];
    }
    return $report;
}
