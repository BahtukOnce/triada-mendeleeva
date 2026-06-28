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
        ['files' => ['7459'], 'title' => 'Межвузовский дружественный турнир', 'kind' => 'tour'],
        ['files' => ['6447'], 'title' => 'Турнир в честь 80 лет Победы в ВОВ', 'kind' => 'tour'],
        ['files' => ['1072'], 'title' => 'Halloween cup', 'kind' => 'tour'],
        ['files' => ['letkubok'], 'title' => 'I Летний кубок Менделеева', 'kind' => 'tour'],
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
        $old = $pdo->query('SELECT id FROM ratings WHERE is_frozen = 1')->fetchAll(PDO::FETCH_COLUMN);
        if ($old) {
            $in = implode(',', array_map('intval', $old));
            $pdo->exec("DELETE FROM rating_cache WHERE rating_id IN ($in)");
            $pdo->exec("DELETE FROM ratings WHERE id IN ($in)");
        }
        $insRating = $pdo->prepare('INSERT INTO ratings (title, is_main, is_active, is_frozen) VALUES (?, 0, 1, 1)');
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
            $insRating->execute([$src['title']]);
            $rid = (int)$pdo->lastInsertId();
            $n = 0;
            foreach ($merged as $name => $row) {
                $rc = legacy_to_cache($row, $kind);
                if ($rc['games'] <= 0) {
                    continue;
                }
                $insRC->execute([
                    $rid, $pidOf((string)$name), $rc['games'], $rc['sum_total'], $rc['sum_plus'],
                    $rc['avg_total'], $rc['club_score'], $rc['pu_count'],
                    $rc['lh_sum'], $rc['dop_sum'], $rc['ci_sum'],
                    $rc['w_civ'], $rc['g_civ'], $rc['w_maf'], $rc['g_maf'],
                    $rc['w_sher'], $rc['g_sher'], $rc['w_don'], $rc['g_don'], $rc['club_score'],
                ]);
                $n++;
            }
            $log[] = "✓ {$src['title']}: рейтинг #$rid, игроков $n";
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
