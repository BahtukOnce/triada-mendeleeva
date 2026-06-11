<?php
declare(strict_types=1);

// Одноразовый импорт истории из двух Google-таблиц клуба (xlsx-экспорт по публичным ссылкам).

const IMPORT_MAIN_XLSX = 'https://docs.google.com/spreadsheets/d/1W3c9Tf4c07L7ha8UXL-clbZnOvqz0TXYRGVtjeMBV_o/export?format=xlsx';
const IMPORT_TECH_XLSX = 'https://docs.google.com/spreadsheets/d/1bOqXG_ag71nPwWezC25K2xc8Tt8LGZNMG592SQsSK4g/export?format=xlsx';

// Листы основной книги, которые не являются игровыми/турнирными
const IMPORT_SERVICE_SHEETS = ['Рейтинг', 'Экзамены', 'Предупреждения', 'Шаблон', 'Технический лист'];

// Ручные слияния ников: нормализованный вариант => канонический нормализованный
const NICK_MERGES = [
    'не_лиса' => 'не_лис',
    'rainbow aka радуга' => 'rainbow',
    'нелис' => 'не_лис',
];

const MONTHS_RU = [
    'января' => 1, 'февраля' => 2, 'марта' => 3, 'апреля' => 4, 'мая' => 5, 'июня' => 6,
    'июля' => 7, 'августа' => 8, 'сентября' => 9, 'октября' => 10, 'ноября' => 11, 'декабря' => 12,
];
// Учебный сезон: сентябрь–декабрь = первый год, январь–август = второй
const SEASON_YEAR_FIRST = 2025;
const SEASON_YEAR_SECOND = 2026;

function nick_key(string $n): string
{
    $n = mb_strtolower(trim($n));
    $n = (string)preg_replace('/\s+/u', ' ', $n);
    $n = (string)preg_replace('/[^\p{L}\p{N}_ ]/u', '', $n);
    $n = trim($n);
    return NICK_MERGES[$n] ?? $n;
}

function import_download(string $url, string $tmpName): string
{
    $path = sys_get_temp_dir() . '/' . $tmpName;
    $ctx = stream_context_create(['http' => [
        'timeout' => 120,
        'follow_location' => 1,
        'header' => "User-Agent: Mozilla/5.0 (triada-import)\r\n",
    ]]);
    $lastErr = '';
    for ($try = 1; $try <= 4; $try++) {
        $data = @file_get_contents($url, false, $ctx);
        if ($data !== false && strlen($data) > 1000 && str_starts_with($data, "PK\x03\x04")) {
            file_put_contents($path, $data);
            return $path;
        }
        // Google вернул не-xlsx (интерстишл/лимит) — пауза и повтор
        $lastErr = $data === false ? 'нет ответа' : 'не xlsx (' . strlen((string)$data) . ' байт)';
        sleep(3 * $try);
    }
    throw new RuntimeException("download failed ($lastErr): $url");
}

function excel_to_date(string $v): ?string
{
    $v = trim($v);
    if ($v === '') {
        return null;
    }
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $v, $m)) {
        return "$m[1]-$m[2]-$m[3]";
    }
    if (is_numeric($v)) {
        $ts = (int)round(((float)$v - 25569) * 86400);
        if ($ts > 0) {
            return gmdate('Y-m-d', $ts);
        }
    }
    return null;
}

function parse_day_sheet_name(string $name): ?string
{
    if (!preg_match('/^(\d{1,2})\s+([а-яё]+)$/iu', trim($name), $m)) {
        return null;
    }
    $month = MONTHS_RU[mb_strtolower($m[2])] ?? null;
    if (!$month) {
        return null;
    }
    $year = $month >= 9 ? SEASON_YEAR_FIRST : SEASON_YEAR_SECOND;
    return sprintf('%04d-%02d-%02d', $year, $month, (int)$m[1]);
}

function parse_tournament_sheet_name(string $name): array
{
    $table = 1;
    $title = trim($name);
    if (preg_match('/^(.*?)[\s.,]*(?:(\d+)\s*стол|стол\s*(\d+))\s*$/iu', $title, $m)) {
        $title = trim($m[1], " .,\t");
        $table = (int)($m[2] ?: $m[3]);
        if ($table < 1) {
            $table = 1;
        }
    }
    return [$title, $table];
}

// Разбор листа с играми (дневного или турнирного): блоки по 15 строк
function parse_games_sheet(array $sheet, array &$warnings = [], string $ctx = ''): array
{
    $games = [];
    $maxRow = $sheet ? max(array_keys($sheet)) : 0;
    for ($b = 2; $b + 13 <= $maxRow + 14; $b += 15) {
        $judge = xc($sheet, $b, 4);
        $seats = [];
        for ($i = 0; $i < 10; $i++) {
            $r = $b + 2 + $i;
            $nick = xc($sheet, $r, 3);
            if ($nick === '') {
                continue;
            }
            $fouls = 0;
            for ($c = 4; $c <= 7; $c++) {
                if (xc($sheet, $r, $c) === 'TRUE') {
                    $fouls++;
                }
            }
            $tech = 0;
            for ($c = 8; $c <= 9; $c++) {
                if (xc($sheet, $r, $c) === 'TRUE') {
                    $tech++;
                }
            }
            $roleRaw = mb_strtolower(xc($sheet, $r, 10));
            $role = ['мирный' => 'civ', 'мафия' => 'maf', 'шериф' => 'sheriff', 'дон' => 'don'][$roleRaw] ?? 'civ';
            $seats[] = [
                'seat' => $i + 1,
                'nick' => $nick,
                'role' => $role,
                'fouls' => $fouls,
                'tech_fouls' => $tech,
                'plus' => (float)str_replace(',', '.', xc($sheet, $r, 11) ?: '0'),
                'minus' => (float)str_replace(',', '.', xc($sheet, $r, 12) ?: '0'),
                'protocols' => xc($sheet, $r, 15) ?: null,
                'opinion' => xc($sheet, $r, 16) ?: null,
            ];
        }
        if (!$seats) {
            if ($b > 2) {
                break;
            }
            continue;
        }
        $puRaw = xc($sheet, $b + 12, 3);
        $pu = is_numeric($puRaw) ? (int)(float)$puRaw : null;
        if ($pu === null && $puRaw !== '' && $puRaw !== '-' && mb_strtolower($puRaw) !== 'промах') {
            $warnings[] = "$ctx, игра " . (count($games) + 1) . ": ПУ нечисловой: «{$puRaw}»";
        }
        if ($pu !== null && ($pu < 1 || $pu > 10)) {
            $pu = null;
        }
        $bm = [];
        foreach ([11, 12, 13] as $c) {
            $v = xc($sheet, $b + 12, $c);
            $bm[] = is_numeric($v) ? (int)(float)$v : null;
        }
        $winRawOrig = xc($sheet, $b + 13, 3);
        $winRaw = mb_strtolower($winRawOrig);
        $winner = ['чёрные' => 'black', 'черные' => 'black', 'красные' => 'red', 'ничья' => 'draw'][$winRaw] ?? null;
        if ($winner === null && $winRawOrig !== '') {
            $warnings[] = "$ctx, игра " . (count($games) + 1) . ': победитель не распознан: «' . $winRawOrig . '»';
        }
        if ($winRaw === 'черные') {
            $warnings[] = "$ctx, игра " . (count($games) + 1)
                . ': «Черные» без ё — формула клубной таблицы в этой игре отдавала победу красным';
        }

        $games[] = [
            'no' => count($games) + 1,
            'judge' => $judge,
            'seats' => $seats,
            'pu' => $pu,
            'bm' => $bm,
            'winner' => $winner,
        ];
    }
    return $games;
}

// Эталонные значения с листа «Рейтинг» для сверки: [ник => [club, sum, plus, pu, lh, dop, minus, ci]]
function parse_reference_rating(array $sheet): array
{
    $ref = [];
    for ($r = 5; $r <= 200; $r++) {
        $nick = xc($sheet, $r, 6);
        if ($nick === '') {
            continue;
        }
        $ref[nick_key($nick)] = [
            'nick' => $nick,
            'club' => (float)xc($sheet, $r, 7),
            'sum' => (float)xc($sheet, $r, 8),
            'plus' => (float)xc($sheet, $r, 9),
            'pu' => (float)xc($sheet, $r, 10),
            'lh' => (float)xc($sheet, $r, 11),
            'dop' => (float)xc($sheet, $r, 12),
            'minus' => (float)xc($sheet, $r, 13),
            'ci' => (float)xc($sheet, $r, 14),
        ];
    }
    return $ref;
}

function run_import(bool $write, ?callable $progress = null): array
{
    $log = [];
    $note = function (string $msg) use (&$log, $progress) {
        $log[] = $msg;
        if ($progress) {
            $progress($msg);
        }
    };
    $t0 = microtime(true);

    $mainPath = import_download(IMPORT_MAIN_XLSX, 'triada_main.xlsx');
    $note('скачана основная книга: ' . round(filesize($mainPath) / 1024) . ' КБ');
    $techPath = import_download(IMPORT_TECH_XLSX, 'triada_tech.xlsx');
    $note('скачана техническая книга: ' . round(filesize($techPath) / 1024) . ' КБ');
    $main = xlsx_load($mainPath, fn($n) => $n === 'Рейтинг' || !in_array($n, IMPORT_SERVICE_SHEETS, true));
    $tech = xlsx_load($techPath, fn($n) => $n === 'Регистрация в клуб');
    $note('листов основной книги: ' . count($main));
    // Классификация листов
    $days = [];
    $tournaments = [];
    $warnings = [];
    foreach ($main as $name => $sheet) {
        if ($name === 'Рейтинг') {
            continue;
        }
        $date = parse_day_sheet_name($name);
        if ($date !== null) {
            $days[$name] = ['date' => $date, 'games' => parse_games_sheet($sheet, $warnings, $name)];
        } else {
            [$title, $table] = parse_tournament_sheet_name($name);
            $tournaments[$title][$table] = parse_games_sheet($sheet, $warnings, $name);
        }
    }
    ksort($days);
    foreach ($warnings as $w) {
        $note('⚠ ' . $w);
    }

    // Какие дни включены в эталонный лист «Рейтинг» (чекбоксы W/X)
    if (isset($main['Рейтинг'])) {
        $refDays = [];
        for ($r = 5; $r <= 60; $r++) {
            $dn = xc($main['Рейтинг'], $r, 24);
            if ($dn !== '' && isset($days[$dn])) {
                if (xc($main['Рейтинг'], $r, 23) === 'TRUE') {
                    $refDays[] = $dn;
                }
            }
        }
        if ($refDays) {
            $extra = array_diff(array_keys($days), $refDays);
            if ($extra) {
                $note('⚠ дни вне эталона листа «Рейтинг» (учтены у нас, но не в таблице): ' . implode(', ', $extra));
            }
        }
    }
    $gamesCount = 0;
    foreach ($days as $d) {
        $gamesCount += count($d['games']);
    }
    $note('игровых дней: ' . count($days) . ', игр в днях: ' . $gamesCount);
    $tCount = 0;
    foreach ($tournaments as $tt) {
        foreach ($tt as $g) {
            $tCount += count($g);
        }
    }
    $note('турниров: ' . count($tournaments) . ', игр в турнирах: ' . $tCount);
    // Анкеты
    $profiles = [];
    $reg = $tech['Регистрация в клуб'] ?? [];
    $maxR = $reg ? max(array_keys($reg)) : 0;
    for ($r = 2; $r <= $maxR; $r++) {
        $nick = xc($reg, $r, 3);
        if ($nick === '') {
            continue;
        }
        $key = nick_key($nick);
        $profiles[$key] = [
            'real_name' => xc($reg, $r, 2) ?: null,
            'status' => xc($reg, $r, 4) ?: null,
            'faculty' => xc($reg, $r, 5) ?: null,
            'study_group' => xc($reg, $r, 6) ?: null,
            'tg' => xc($reg, $r, 8) ?: null,
            'birth_date' => excel_to_date(xc($reg, $r, 10)),
            'joined_at' => excel_to_date(xc($reg, $r, 1)),
        ];
    }
    $note('анкет (уникальных ников): ' . count($profiles));
    // Сбор всех ников из игр
    $nicks = []; // key => display
    $collect = function (array $games) use (&$nicks) {
        foreach ($games as $g) {
            if ($g['judge'] !== '') {
                $k = nick_key($g['judge']);
                $nicks[$k] = $nicks[$k] ?? $g['judge'];
            }
            foreach ($g['seats'] as $s) {
                $k = nick_key($s['nick']);
                $nicks[$k] = $nicks[$k] ?? $s['nick'];
            }
        }
    };
    foreach ($days as $d) {
        $collect($d['games']);
    }
    foreach ($tournaments as $tt) {
        foreach ($tt as $g) {
            $collect($g);
        }
    }
    $note('уникальных ников в играх: ' . count($nicks));
    // Похожие ники (для ручной проверки)
    $keys = array_keys($nicks);
    $similar = [];
    for ($i = 0; $i < count($keys); $i++) {
        for ($j = $i + 1; $j < count($keys); $j++) {
            $a = $keys[$i];
            $b = $keys[$j];
            $lev = levenshtein($a, $b);
            if ($lev > 0 && ($lev <= 1 || str_starts_with($a, $b) || str_starts_with($b, $a))) {
                $similar[] = "«{$nicks[$a]}» ~ «{$nicks[$b]}»";
            }
        }
    }
    if ($similar) {
        $note('ПОХОЖИЕ НИКИ (проверить, не дубли ли): ' . implode('; ', $similar));
    }

    if (!$write) {
        $note('режим dry — в базу ничего не записано');
        return $log;
    }

    // ── Запись в БД ──────────────────────────────────────────
    $pdo = db();
    $pdo->beginTransaction();
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach (['game_seats', 'games', 'rating_days', 'day_registrations', 'game_days', 'tournament_regs', 'tournaments', 'rating_cache'] as $tbl) {
        $pdo->exec("DELETE FROM $tbl");
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    $note('старые игровые данные очищены');
    // Игроки: существующие по ключу
    $playerId = [];
    foreach ($pdo->query('SELECT id, nickname FROM players')->fetchAll() as $p) {
        $playerId[nick_key($p['nickname'])] = (int)$p['id'];
    }
    // ON DUPLICATE: разные написания, совпадающие по MySQL-коллации (ё=е и т.п.),
    // схлопываются в одного игрока.
    // Создаём ТОЛЬКО игроков, участвовавших в играх (включая судивших) — $nicks.
    // Анкеты лишь обогащают существующих, новых не плодят.
    $insP = $pdo->prepare('INSERT INTO players (nickname) VALUES (?)
        ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)');
    $created = 0;
    foreach ($nicks as $k => $display) {
        if (!isset($playerId[$k])) {
            $insP->execute([$display]);
            $playerId[$k] = (int)$pdo->lastInsertId();
            if ($insP->rowCount() === 1) {
                $created++;
            }
        }
    }
    $note("игроков из игр создано: $created");
    // Профили из анкет (заполняем пустые поля)
    $updP = $pdo->prepare('UPDATE players SET
        real_name = COALESCE(real_name, ?), status = COALESCE(status, ?),
        faculty = COALESCE(faculty, ?), study_group = COALESCE(study_group, ?),
        tg = COALESCE(tg, ?), birth_date = COALESCE(birth_date, ?), joined_at = COALESCE(joined_at, ?)
        WHERE id = ?');
    $filled = 0;
    foreach ($profiles as $k => $pr) {
        if (isset($playerId[$k])) {
            $updP->execute([
                $pr['real_name'], $pr['status'], $pr['faculty'], $pr['study_group'],
                $pr['tg'], $pr['birth_date'], $pr['joined_at'], $playerId[$k],
            ]);
            $filled++;
        }
    }
    $note("профилей заполнено из анкет: $filled");
    $mainRatingId = (int)$pdo->query('SELECT id FROM ratings WHERE is_main = 1 LIMIT 1')->fetchColumn();

    $insDay = $pdo->prepare("INSERT INTO game_days (date, title, status) VALUES (?,?, 'finished')");
    $insRD = $pdo->prepare('INSERT INTO rating_days (rating_id, day_id) VALUES (?,?)');
    $insG = $pdo->prepare("INSERT INTO games
        (context, day_id, tournament_id, table_no, game_no, judge_player_id, winner,
         first_killed_seat, bm_seat1, bm_seat2, bm_seat3, status, finished_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?, 'finished', NOW())");
    $insS = $pdo->prepare('INSERT INTO game_seats
        (game_id, seat, player_id, role, fouls, tech_fouls, plus, minus, protocols, opinion)
        VALUES (?,?,?,?,?,?,?,?,?,?)');

    $writeGames = function (array $games, ?int $dayId, ?int $tournamentId, int $tableNo) use ($insG, $insS, $playerId, $pdo) {
        foreach ($games as $g) {
            $judgeId = $g['judge'] !== '' ? ($playerId[nick_key($g['judge'])] ?? null) : null;
            $insG->execute([
                $dayId !== null ? 'day' : 'tournament', $dayId, $tournamentId, $tableNo, $g['no'],
                $judgeId, $g['winner'], $g['pu'], $g['bm'][0], $g['bm'][1], $g['bm'][2],
            ]);
            $gid = (int)$pdo->lastInsertId();
            foreach ($g['seats'] as $s) {
                $insS->execute([
                    $gid, $s['seat'], $playerId[nick_key($s['nick'])], $s['role'],
                    $s['fouls'], $s['tech_fouls'], $s['plus'], $s['minus'],
                    $s['protocols'], $s['opinion'],
                ]);
            }
        }
    };

    foreach ($days as $name => $d) {
        $insDay->execute([$d['date'], $name]);
        $dayId = (int)$pdo->lastInsertId();
        if ($mainRatingId) {
            $insRD->execute([$mainRatingId, $dayId]);
        }
        $writeGames($d['games'], $dayId, null, 1);
    }
    $note('дни и игры записаны');
    $insT = $pdo->prepare("INSERT INTO tournaments (title, date_from, status, tables_count) VALUES (?,?, 'finished', ?)");
    foreach ($tournaments as $title => $tables) {
        $year = preg_match('/(20\d\d)/', $title, $m) ? $m[1] : null;
        $insT->execute([$title, $year ? "$year-01-01" : null, max(array_keys($tables))]);
        $tid = (int)$pdo->lastInsertId();
        foreach ($tables as $tableNo => $games) {
            $writeGames($games, null, $tid, (int)$tableNo);
        }
    }
    $note('турниры записаны');

    // Чистка: на сайте остаются только игроки из игр (сыгравшие или судившие).
    // Привязанные к аккаунту (user_id) не трогаем.
    $removed = $pdo->exec("DELETE FROM players
        WHERE user_id IS NULL
          AND id NOT IN (SELECT DISTINCT player_id FROM game_seats)
          AND id NOT IN (SELECT DISTINCT judge_player_id FROM games WHERE judge_player_id IS NOT NULL)");
    $note("удалено игроков без игр: " . (int)$removed);
    $note('игроков на сайте: ' . (int)$pdo->query('SELECT COUNT(*) FROM players')->fetchColumn());
    $pdo->commit();

    rating_recompute_all();
    $note('рейтинг пересчитан');
    if (function_exists('elo_recompute')) {
        elo_recompute();
        $note('ELO пересчитан');
    }
    // ── Сверка с листом «Рейтинг» ────────────────────────────
    if (isset($main['Рейтинг']) && $mainRatingId) {
        $ref = parse_reference_rating($main['Рейтинг']);
        $st = $pdo->prepare('SELECT rc.*, p.nickname FROM rating_cache rc
            JOIN players p ON p.id = rc.player_id WHERE rc.rating_id = ?');
        $st->execute([$mainRatingId]);
        $mismatches = [];
        $matched = 0;
        foreach ($st->fetchAll() as $row) {
            $k = nick_key($row['nickname']);
            if (!isset($ref[$k])) {
                continue;
            }
            $r = $ref[$k];
            $dSum = abs((float)$row['sum_total'] - $r['sum']);
            $dPlus = abs((float)$row['sum_plus'] - $r['plus']);
            if ($dSum > 0.05 || $dPlus > 0.05) {
                $mismatches[] = sprintf(
                    '%s: Σ наш %.2f / таблица %.2f (Δ%.2f); Σ+ наш %.2f / %.2f; ПУ %d/%d; ЛХ %.1f/%.1f; Допы %.1f/%.1f; − %.1f/%.1f; Ci %.2f/%.2f',
                    $row['nickname'], $row['sum_total'], $r['sum'], $dSum,
                    $row['sum_plus'], $r['plus'],
                    $row['pu_count'], (int)$r['pu'], $row['lh_sum'], $r['lh'],
                    $row['dop_sum'], $r['dop'], $row['minus_sum'], $r['minus'],
                    $row['ci_sum'], $r['ci']
                );
            } else {
                $matched++;
            }
        }
        $note("СВЕРКА: совпало игроков: $matched, расхождений: " . count($mismatches));
        foreach (array_slice($mismatches, 0, 40) as $mm) {
            $note('  ✗ ' . $mm);
        }
    }

    $note('готово за ' . round(microtime(true) - $t0, 1) . ' с');
    return $log;
}
