<?php
// Импорт исторических рейтингов/турниров с mafiauniverse (скрейп в storage/legacy/*.json).
// Каждый источник → отдельный «замороженный» рейтинг (is_frozen=1): не пересчитывается
// движком, числа зафиксированы. Игроки матчатся по нику (nick_key + NICK_MERGES).
declare(strict_types=1);

require_once __DIR__ . '/import.php'; // nick_key(), NICK_MERGES

function legacy_sources(): array
{
    return [
        ['files' => ['855'], 'title' => 'Сезон 2022/2023', 'kind' => 'season'],
        ['files' => ['893', '1355'], 'title' => 'Сезон 2023/2024', 'kind' => 'season'],
        ['files' => ['3557'], 'title' => 'Сезон 2024/2025', 'kind' => 'season'],
        // Все турниры (Межвуз/ВОВ/Halloween/Летний кубок) переносятся поигрово
        // через legacy_tour_import_run — здесь только сезоны.
    ];
}

// строка одного игрока из источника → поля rating_cache
function legacy_to_cache(array $row, string $kind): array
{
    if ($kind === 'season') {
        // [g,gc,wc,gs,ws,gm,wm,gd,wd,pu,total]
        $r = array_pad(array_values($row), 11, 0);
        [$g, $gc, $wc, $gs, $ws, $gm, $wm, $gd, $wd, $pu, $total] = $r;
        $g = (int)$g;
        $total = (float)$total;
        $avg = $g > 0 ? $total / $g : 0;
        return [
            'games' => $g, 'sum_total' => round($total, 2), 'sum_plus' => 0.0,
            'avg_total' => $g > 0 ? round($avg, 4) : null,
            'club_score' => $g > 0 ? round($avg * $total, 4) : null,
            'pu_count' => (int)$pu, 'lh_sum' => 0.0, 'dop_sum' => 0.0, 'ci_sum' => 0.0,
            'w_civ' => (int)$wc, 'g_civ' => (int)$gc, 'w_maf' => (int)$wm, 'g_maf' => (int)$gm,
            'w_sher' => (int)$ws, 'g_sher' => (int)$gs, 'w_don' => (int)$wd, 'g_don' => (int)$gd,
        ];
    }
    // турнир: {sum, lh, dop, ci, g}
    $g = (int)($row['g'] ?? 0);
    $sum = (float)($row['sum'] ?? 0);
    $lh = (float)($row['lh'] ?? 0);
    $dop = (float)($row['dop'] ?? 0);
    $ci = (float)($row['ci'] ?? 0);
    $avg = $g > 0 ? $sum / $g : 0;
    return [
        'games' => $g, 'sum_total' => round($sum, 2), 'sum_plus' => round($lh + $dop + $ci, 2),
        'avg_total' => $g > 0 ? round($avg, 4) : null,
        'club_score' => $g > 0 ? round($avg * $sum, 4) : null,
        'pu_count' => 0, 'lh_sum' => round($lh, 1), 'dop_sum' => round($dop, 1), 'ci_sum' => round($ci, 2),
        'w_civ' => 0, 'g_civ' => 0, 'w_maf' => 0, 'g_maf' => 0,
        'w_sher' => 0, 'g_sher' => 0, 'w_don' => 0, 'g_don' => 0,
    ];
}

// склейка двух строк одного игрока (для сезонов суммируем поэлементно)
function legacy_merge_season(array $a, array $b): array
{
    $a = array_values($a);
    $b = array_values($b);
    $out = [];
    for ($i = 0; $i < 11; $i++) {
        $out[$i] = ($a[$i] ?? 0) + ($b[$i] ?? 0);
    }
    return $out;
}

function legacy_import_run(): array
{
    $log = [];
    $pdo = db();
    $dir = ROOT . '/storage/legacy';
    $skip = ['Пустой слот' => 1, '' => 1];

    $byNick = [];
    foreach ($pdo->query('SELECT id, nickname FROM players') as $p) {
        $byNick[nick_key((string)$p['nickname'])] = (int)$p['id'];
    }
    $insPlayer = $pdo->prepare('INSERT INTO players (nickname) VALUES (?)');
    $findByNick = $pdo->prepare('SELECT id FROM players WHERE nickname = ? LIMIT 1');
    $pidOf = function (string $name) use (&$byNick, $insPlayer, $findByNick, $pdo): int {
        $k = nick_key($name);
        if (isset($byNick[$k])) {
            return $byNick[$k];
        }
        try {
            $insPlayer->execute([$name]);
            $id = (int)$pdo->lastInsertId();
        } catch (PDOException $e) {
            // коллизия UNIQUE (напр. ё/е: коллация БД считает их одинаковыми) — берём существующего
            $findByNick->execute([$name]);
            $id = (int)$findByNick->fetchColumn();
            if (!$id) {
                throw $e;
            }
        }
        $byNick[$k] = $id;
        return $id;
    };

    $pdo->beginTransaction();
    try {
        // сначала турнирные записи (ссылаются на frozen-рейтинг), затем сами рейтинги
        $pdo->exec('DELETE FROM tournaments WHERE legacy_rating_id IS NOT NULL');
        $old = $pdo->query('SELECT id FROM ratings WHERE is_frozen = 1')->fetchAll(PDO::FETCH_COLUMN);
        if ($old) {
            $in = implode(',', array_map('intval', $old));
            $pdo->exec("DELETE FROM rating_cache WHERE rating_id IN ($in)");
            $pdo->exec("DELETE FROM ratings WHERE id IN ($in)");
        }
        // сезоны — в переключателе рейтингов (is_active=1); турниры — скрыты оттуда
        // (is_active=0) и показываются в разделе «Турниры», доступ к таблице по /rating.php?r=ID
        $insRating = $pdo->prepare('INSERT INTO ratings (title, is_main, is_active, is_frozen) VALUES (?, 0, ?, 1)');
        $insTour = $pdo->prepare("INSERT INTO tournaments (title, date_from, status, tables_count, reg_mode, legacy_rating_id)
            VALUES (?, ?, 'finished', ?, 'open', ?)");
        $insRC = $pdo->prepare('INSERT INTO rating_cache
            (rating_id, player_id, games, sum_total, sum_plus, avg_total, club_score, pu_count,
             lh_sum, dop_sum, ci_sum, w_civ, g_civ, w_maf, g_maf, w_sher, g_sher, w_don, g_don, peak_club)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');

        foreach (legacy_sources() as $src) {
            $kind = $src['kind'];
            $merged = [];
            foreach ($src['files'] as $f) {
                $file = "$dir/$f.json";
                if (!is_file($file)) {
                    $log[] = "нет файла $f.json — пропуск";
                    continue;
                }
                $data = json_decode((string)file_get_contents($file), true);
                foreach (($data['players'] ?? []) as $name => $row) {
                    if (isset($skip[$name])) {
                        continue;
                    }
                    if (!isset($merged[$name])) {
                        $merged[$name] = $row;
                    } elseif ($kind === 'season') {
                        $merged[$name] = legacy_merge_season($merged[$name], $row);
                    }
                }
            }
            if (!$merged) {
                $log[] = "{$src['title']}: данных нет — пропуск";
                continue;
            }
            $insRating->execute([$src['title'], $kind === 'season' ? 1 : 0]);
            $rid = (int)$pdo->lastInsertId();
            // сгруппировать по player_id: разные ники одного игрока → один ряд
            $rawByPid = [];
            foreach ($merged as $name => $row) {
                $pid = $pidOf((string)$name);
                if (!isset($rawByPid[$pid])) {
                    $rawByPid[$pid] = $row;
                } elseif ($kind === 'season') {
                    $rawByPid[$pid] = legacy_merge_season($rawByPid[$pid], $row);
                } else {
                    foreach (['sum', 'lh', 'dop', 'ci', 'g', 'wins'] as $f) {
                        $rawByPid[$pid][$f] = ($rawByPid[$pid][$f] ?? 0) + ($row[$f] ?? 0);
                    }
                }
            }
            $n = 0;
            foreach ($rawByPid as $pid => $row) {
                $rc = legacy_to_cache($row, $kind);
                if ($rc['games'] <= 0) {
                    continue;
                }
                $insRC->execute([
                    $rid, $pid, $rc['games'], $rc['sum_total'], $rc['sum_plus'],
                    $rc['avg_total'], $rc['club_score'], $rc['pu_count'],
                    $rc['lh_sum'], $rc['dop_sum'], $rc['ci_sum'],
                    $rc['w_civ'], $rc['g_civ'], $rc['w_maf'], $rc['g_maf'],
                    $rc['w_sher'], $rc['g_sher'], $rc['w_don'], $rc['g_don'], $rc['club_score'],
                ]);
                $n++;
            }
            if ($kind === 'tour') {
                $insTour->execute([$src['title'], $src['date'] ?? null, max(1, (int)ceil($n / 10)), $rid]);
                $log[] = "✓ {$src['title']}: турнир + рейтинг #$rid, игроков $n";
            } else {
                $log[] = "✓ {$src['title']}: рейтинг #$rid, игроков $n";
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $log[] = 'ОШИБКА: ' . $e->getMessage();
    }
    return $log;
}

// ── Исторические ПОИГРОВЫЕ данные → настоящие игровые вечера ──────────────────
// Снятые с mafiauniverse игры (storage/legacy/games_<id>.json, с реальными датами)
// импортируются как обычные game_days/games/game_seats с пометкой сезона.
// Они НЕ привязаны к рейтингам (rating_days) → основной рейтинг не трогают,
// но идут в ELO, статистику, профили, рекорды и ачивки.
function legacy_days_sources(): array
{
    return [
        ['file' => '855',  'season' => 'Сезон 2022/2023'],
        ['file' => '893',  'season' => 'Сезон 2023/2024'],
        ['file' => '1355', 'season' => 'Сезон 2023/2024'],
        ['file' => '3557', 'season' => 'Сезон 2024/2025'],
    ];
}

function legacy_days_import_run(): array
{
    $log = [];
    $pdo = db();
    $dir = ROOT . '/storage/legacy';
    $skip = ['Пустой слот' => 1, '' => 1];

    $byNick = [];
    foreach ($pdo->query('SELECT id, nickname FROM players') as $p) {
        $byNick[nick_key((string)$p['nickname'])] = (int)$p['id'];
    }
    $insPlayer = $pdo->prepare('INSERT INTO players (nickname) VALUES (?)');
    $findByNick = $pdo->prepare('SELECT id FROM players WHERE nickname = ? LIMIT 1');
    $pidOf = function (string $name) use (&$byNick, $insPlayer, $findByNick, $pdo): int {
        $k = nick_key($name);
        if (isset($byNick[$k])) {
            return $byNick[$k];
        }
        try {
            $insPlayer->execute([$name]);
            $id = (int)$pdo->lastInsertId();
        } catch (PDOException $e) {
            $findByNick->execute([$name]);
            $id = (int)$findByNick->fetchColumn();
            if (!$id) {
                throw $e;
            }
        }
        $byNick[$k] = $id;
        return $id;
    };

    $monthsRu = [1 => 'января', 2 => 'февраля', 3 => 'марта', 4 => 'апреля', 5 => 'мая', 6 => 'июня',
        7 => 'июля', 8 => 'августа', 9 => 'сентября', 10 => 'октября', 11 => 'ноября', 12 => 'декабря'];
    $roleOk = ['civ' => 1, 'maf' => 1, 'don' => 1, 'sheriff' => 1];
    $totalDays = 0;
    $totalGames = 0;

    $pdo->beginTransaction();
    try {
        // снести предыдущий импорт истории (каскад удалит games + game_seats)
        $pdo->exec("DELETE FROM game_days WHERE season IS NOT NULL");

        $insDay = $pdo->prepare("INSERT INTO game_days (date, title, location, status, season) VALUES (?,?,NULL,'finished',?)");
        $insGame = $pdo->prepare("INSERT INTO games (context, day_id, table_no, game_no, judge_player_id, winner, status)
            VALUES ('day', ?, 1, ?, ?, ?, 'finished')");
        $insSeat = $pdo->prepare("INSERT INTO game_seats (game_id, seat, player_id, role, plus, minus) VALUES (?,?,?,?,?,?)");

        foreach (legacy_days_sources() as $src) {
            $file = "$dir/games_{$src['file']}.json";
            if (!is_file($file)) {
                $log[] = "нет файла games_{$src['file']}.json — пропуск";
                continue;
            }
            $games = json_decode((string)file_get_contents($file), true);
            if (!is_array($games)) {
                $log[] = "{$src['file']}: битый JSON — пропуск";
                continue;
            }
            // группируем по дате — день = игровой вечер
            $byDate = [];
            foreach ($games as $g) {
                $date = (string)($g['date'] ?? '');
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                    continue;
                }
                $byDate[$date][] = $g;
            }
            ksort($byDate);
            $cntDays = 0;
            $cntGames = 0;
            foreach ($byDate as $date => $dayGames) {
                usort($dayGames, function ($a, $b) {
                    return strcmp((string)($a['time'] ?? ''), (string)($b['time'] ?? ''))
                        ?: (((int)($a['gno'] ?? 0)) <=> ((int)($b['gno'] ?? 0)));
                });
                $ts = strtotime($date);
                $title = (int)date('j', $ts) . ' ' . $monthsRu[(int)date('n', $ts)];
                $insDay->execute([$date, $title, $src['season']]);
                $dayId = (int)$pdo->lastInsertId();
                $gameNo = 0;
                foreach ($dayGames as $g) {
                    $winner = (($g['winner'] ?? '') === 'red' || ($g['winner'] ?? '') === 'black') ? $g['winner'] : null;
                    if (!$winner) {
                        continue;
                    }
                    $jn = trim((string)($g['judge'] ?? ''));
                    $judgeId = ($jn !== '' && !isset($skip[$jn])) ? $pidOf($jn) : null;
                    $gameNo++;
                    $insGame->execute([$dayId, $gameNo, $judgeId, $winner]);
                    $gid = (int)$pdo->lastInsertId();
                    $usedSeats = [];
                    $seenPid = [];
                    $seatFallback = 0;
                    foreach (($g['players'] ?? []) as $p) {
                        $name = trim((string)($p['name'] ?? ''));
                        if ($name === '' || isset($skip[$name])) {
                            continue;
                        }
                        $pid = $pidOf($name);
                        if (isset($seenPid[$pid])) {
                            continue;
                        }
                        $seenPid[$pid] = 1;
                        $role = isset($roleOk[$p['role'] ?? '']) ? $p['role'] : 'civ';
                        $seat = (int)($p['seat'] ?? 0);
                        if ($seat < 1 || $seat > 10 || isset($usedSeats[$seat])) {
                            do {
                                $seat = ++$seatFallback;
                            } while (isset($usedSeats[$seat]));
                        }
                        $usedSeats[$seat] = 1;
                        // mafiauniverse total УЖЕ включает победный балл (+1 у победителей);
                        // сайт сам добавит 1 за победу (seat_total), поэтому храним только
                        // доп-часть: total минус победный балл.
                        $tot = (float)($p['total'] ?? 0);
                        $isWin = ($winner === 'red' && ($role === 'civ' || $role === 'sheriff'))
                            || ($winner === 'black' && ($role === 'maf' || $role === 'don'));
                        $net = $tot - ($isWin ? 1.0 : 0.0);
                        $insSeat->execute([$gid, $seat, $pid, $role, $net > 0 ? round($net, 1) : 0, $net < 0 ? round(-$net, 1) : 0]);
                    }
                    $cntGames++;
                }
                $cntDays++;
            }
            $totalDays += $cntDays;
            $totalGames += $cntGames;
            $log[] = "✓ {$src['file']} ({$src['season']}): вечеров $cntDays, игр $cntGames";
        }
        $pdo->commit();
        $log[] = "ИТОГО: вечеров $totalDays, игр $totalGames";
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $log[] = 'ОШИБКА: ' . $e->getMessage();
        return $log;
    }

    // Пересчёт ELO по всей истории (текущие + исторические игры в хронологии)
    try {
        require_once ROOT . '/inc/elo.php';
        elo_recompute();
        $log[] = 'ELO пересчитан по всей истории';
    } catch (Throwable $e) {
        $log[] = 'ELO ошибка: ' . $e->getMessage();
    }
    return $log;
}

// ── Исторические ТУРНИРЫ → настоящие турниры с играми ────────────────────────
// Поигровые данные турниров с mafiauniverse (GamesList) → реальные games
// (context='tournament') у записей `tournaments`. Идут в ELO, статистику, ачивки;
// страница турнира показывает реальные игры. Сезон определяется по дате (1 сентября).
function legacy_tour_sources(): array
{
    return [
        ['file' => '7459', 'title' => 'Межвузовский дружественный турнир'],
        ['file' => '6447', 'title' => 'Турнир в честь 80 лет Победы в ВОВ'],
        ['file' => '1072', 'title' => 'Halloween cup'],
        ['file' => 'letkubok', 'title' => 'I Летний кубок Менделеева'],
    ];
}

function legacy_tour_import_run(): array
{
    $log = [];
    $pdo = db();
    $dir = ROOT . '/storage/legacy';
    $skip = ['Пустой слот' => 1, '' => 1];

    $byNick = [];
    foreach ($pdo->query('SELECT id, nickname FROM players') as $p) {
        $byNick[nick_key((string)$p['nickname'])] = (int)$p['id'];
    }
    $insPlayer = $pdo->prepare('INSERT INTO players (nickname) VALUES (?)');
    $findByNick = $pdo->prepare('SELECT id FROM players WHERE nickname = ? LIMIT 1');
    $pidOf = function (string $name) use (&$byNick, $insPlayer, $findByNick, $pdo): int {
        $k = nick_key($name);
        if (isset($byNick[$k])) {
            return $byNick[$k];
        }
        try {
            $insPlayer->execute([$name]);
            $id = (int)$pdo->lastInsertId();
        } catch (PDOException $e) {
            $findByNick->execute([$name]);
            $id = (int)$findByNick->fetchColumn();
            if (!$id) {
                throw $e;
            }
        }
        $byNick[$k] = $id;
        return $id;
    };

    $roleOk = ['civ' => 1, 'maf' => 1, 'don' => 1, 'sheriff' => 1];
    $totalT = 0;
    $totalG = 0;

    $pdo->beginTransaction();
    try {
        $delTour = $pdo->prepare('DELETE FROM tournaments WHERE title = ?'); // каскад: games + game_seats
        $findFrozen = $pdo->prepare('SELECT id FROM ratings WHERE title = ? AND is_frozen = 1');
        $delRc = $pdo->prepare('DELETE FROM rating_cache WHERE rating_id = ?');
        $delRating = $pdo->prepare('DELETE FROM ratings WHERE id = ?');
        $insTour = $pdo->prepare("INSERT INTO tournaments (title, date_from, status, tables_count, reg_mode)
            VALUES (?, ?, 'finished', ?, 'open')");
        $insGame = $pdo->prepare("INSERT INTO games (context, tournament_id, table_no, game_no, judge_player_id, winner, status)
            VALUES ('tournament', ?, 1, ?, ?, ?, 'finished')");
        $insSeat = $pdo->prepare('INSERT INTO game_seats (game_id, seat, player_id, role, plus, minus) VALUES (?,?,?,?,?,?)');

        foreach (legacy_tour_sources() as $src) {
            $file = "$dir/games_{$src['file']}.json";
            if (!is_file($file)) {
                $log[] = "нет games_{$src['file']}.json — пропуск";
                continue;
            }
            $games = json_decode((string)file_get_contents($file), true);
            if (!is_array($games)) {
                $log[] = "{$src['file']}: битый JSON — пропуск";
                continue;
            }
            // снести старую версию турнира (агрегатный рейтинг + редирект-запись)
            $delTour->execute([$src['title']]);
            $findFrozen->execute([$src['title']]);
            foreach ($findFrozen->fetchAll(PDO::FETCH_COLUMN) as $frid) {
                $delRc->execute([(int)$frid]);
                $delRating->execute([(int)$frid]);
            }

            usort($games, fn($a, $b) => ((int)($a['gno'] ?? 0)) <=> ((int)($b['gno'] ?? 0)));
            $dates = array_filter(array_map(fn($g) => $g['date'] ?? null, $games));
            $tdate = $dates ? min($dates) : null;
            $uniq = [];
            foreach ($games as $g) {
                foreach (($g['players'] ?? []) as $p) {
                    $n = trim((string)($p['name'] ?? ''));
                    if ($n !== '' && !isset($skip[$n])) {
                        $uniq[nick_key($n)] = 1;
                    }
                }
            }
            $insTour->execute([$src['title'], $tdate, max(1, (int)ceil(count($uniq) / 10))]);
            $tid = (int)$pdo->lastInsertId();

            $gameNo = 0;
            $cnt = 0;
            foreach ($games as $g) {
                $winner = (($g['winner'] ?? '') === 'red' || ($g['winner'] ?? '') === 'black') ? $g['winner'] : null;
                if (!$winner) {
                    continue;
                }
                $jn = trim((string)($g['judge'] ?? ''));
                $judgeId = ($jn !== '' && !isset($skip[$jn])) ? $pidOf($jn) : null;
                $gameNo++;
                $insGame->execute([$tid, $gameNo, $judgeId, $winner]);
                $gid = (int)$pdo->lastInsertId();
                $usedSeats = [];
                $seenPid = [];
                $fb = 0;
                foreach (($g['players'] ?? []) as $p) {
                    $name = trim((string)($p['name'] ?? ''));
                    if ($name === '' || isset($skip[$name])) {
                        continue;
                    }
                    $pid = $pidOf($name);
                    if (isset($seenPid[$pid])) {
                        continue;
                    }
                    $seenPid[$pid] = 1;
                    $role = isset($roleOk[$p['role'] ?? '']) ? $p['role'] : 'civ';
                    $seat = (int)($p['seat'] ?? 0);
                    if ($seat < 1 || $seat > 10 || isset($usedSeats[$seat])) {
                        do {
                            $seat = ++$fb;
                        } while (isset($usedSeats[$seat]));
                    }
                    $usedSeats[$seat] = 1;
                    // mafiauniverse total включает победный балл — храним только доп-часть
                    $tot = (float)($p['total'] ?? 0);
                    $isWin = ($winner === 'red' && ($role === 'civ' || $role === 'sheriff'))
                        || ($winner === 'black' && ($role === 'maf' || $role === 'don'));
                    $net = $tot - ($isWin ? 1.0 : 0.0);
                    $insSeat->execute([$gid, $seat, $pid, $role, $net > 0 ? round($net, 1) : 0, $net < 0 ? round(-$net, 1) : 0]);
                }
                $cnt++;
            }
            $totalT++;
            $totalG += $cnt;
            $log[] = "✓ {$src['title']}: турнир #$tid, игр $cnt, дата " . ($tdate ?: '?');
        }
        $pdo->commit();
        $log[] = "ИТОГО турниров: $totalT, игр $totalG";
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $log[] = 'ОШИБКА: ' . $e->getMessage();
        return $log;
    }

    try {
        require_once ROOT . '/inc/elo.php';
        elo_recompute();
        $log[] = 'ELO пересчитан по всей истории';
    } catch (Throwable $e) {
        $log[] = 'ELO ошибка: ' . $e->getMessage();
    }
    return $log;
}
