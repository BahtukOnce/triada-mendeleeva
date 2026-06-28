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
        ['files' => ['7459'], 'title' => 'Межвузовский дружественный турнир', 'kind' => 'tour', 'date' => '2025-03-01'],
        ['files' => ['6447'], 'title' => 'Турнир в честь 80 лет Победы в ВОВ', 'kind' => 'tour', 'date' => '2025-05-09'],
        ['files' => ['1072'], 'title' => 'Halloween cup', 'kind' => 'tour', 'date' => '2022-10-31'],
        ['files' => ['letkubok'], 'title' => 'I Летний кубок Менделеева', 'kind' => 'tour', 'date' => '2023-06-01'],
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

// ── Исторические ПОИГРОВЫЕ данные (для расчёта ELO) ───────────────────────────
// Снятые с mafiauniverse игры (storage/legacy/games_<id>.json) → legacy_games.
// Файлы и даты: даты синтетические, лишь бы порядок был верным и раньше текущих игр.
function legacy_games_sources(): array
{
    return [
        ['file' => '855',  'season' => 'Сезон 2022/2023', 'date' => '2022-12-01'],
        ['file' => '893',  'season' => 'Сезон 2023/2024', 'date' => '2023-11-01'],
        ['file' => '1355', 'season' => 'Сезон 2023/2024', 'date' => '2024-03-01'],
        ['file' => '3557', 'season' => 'Сезон 2024/2025', 'date' => '2024-12-01'],
    ];
}

function legacy_games_import_run(): array
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

    $roleMap = ['civ' => 'civ', 'maf' => 'maf', 'don' => 'don', 'sheriff' => 'sheriff'];
    $totalG = 0;
    $totalS = 0;

    $pdo->beginTransaction();
    try {
        $pdo->exec('DELETE FROM legacy_game_seats');
        $pdo->exec('DELETE FROM legacy_games');
        $insG = $pdo->prepare('INSERT INTO legacy_games (season, gdate, seq, winner) VALUES (?,?,?,?)');
        $insS = $pdo->prepare('INSERT INTO legacy_game_seats (game_id, player_id, role) VALUES (?,?,?)');

        foreach (legacy_games_sources() as $src) {
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
            usort($games, fn($a, $b) => ((int)($a['gno'] ?? 0)) <=> ((int)($b['gno'] ?? 0)));
            $cnt = 0;
            foreach ($games as $g) {
                $winner = (($g['winner'] ?? '') === 'red' || ($g['winner'] ?? '') === 'black') ? $g['winner'] : null;
                if (!$winner) {
                    continue;
                }
                $seen = [];
                $seats = [];
                foreach (($g['players'] ?? []) as $p) {
                    $name = trim((string)($p['name'] ?? ''));
                    if ($name === '' || isset($skip[$name])) {
                        continue;
                    }
                    $role = $roleMap[$p['role'] ?? 'civ'] ?? 'civ';
                    $pid = $pidOf($name);
                    if (isset($seen[$pid])) {
                        continue;
                    }
                    $seen[$pid] = 1;
                    $seats[] = [$pid, $role];
                }
                if (count($seats) < 4) {
                    continue;
                }
                $insG->execute([$src['season'], $src['date'], (int)($g['gno'] ?? ($cnt + 1)), $winner]);
                $gid = (int)$pdo->lastInsertId();
                foreach ($seats as $st) {
                    $insS->execute([$gid, $st[0], $st[1]]);
                    $totalS++;
                }
                $totalG++;
                $cnt++;
            }
            $log[] = "✓ {$src['file']} ({$src['season']}): игр $cnt";
        }
        $pdo->commit();
        $log[] = "ИТОГО исторических: игр $totalG, мест $totalS";
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $log[] = 'ОШИБКА: ' . $e->getMessage();
        return $log;
    }

    // Пересчёт ELO по всей истории (текущие + исторические игры)
    try {
        require_once ROOT . '/inc/elo.php';
        elo_recompute();
        $log[] = 'ELO пересчитан по всей истории';
    } catch (Throwable $e) {
        $log[] = 'ELO ошибка: ' . $e->getMessage();
    }
    return $log;
}
