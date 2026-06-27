<?php
declare(strict_types=1);

function cfg(string $key, $default = null)
{
    return $GLOBALS['cfg'][$key] ?? $default;
}

function esc(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

// Диапазоны эмодзи/пиктограмм/модификаторов (для очистки ников и «висюлек»)
const EMOJI_RANGES = '\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}\x{2190}-\x{21FF}'
    . '\x{FE00}-\x{FE0F}\x{200D}\x{20E3}\x{2122}\x{2139}\x{24C2}\x{3030}\x{303D}\x{3297}\x{3299}';

// Ник без эмодзи (игровая идентичность всегда чистая, напр. «НЕ_ЛИС»)
function nickname_clean(string $s): string
{
    $s = (string)preg_replace('/[' . EMOJI_RANGES . ']/u', '', $s);
    return trim((string)preg_replace('/\s+/u', ' ', $s));
}

// Один эмодзи-«висюлька» (ровно один кластер: база + модификаторы/ZWJ-сцепки)
function flair_clean(string $s): string
{
    preg_match_all('/[' . EMOJI_RANGES . '\x{1F3FB}-\x{1F3FF}]/u', $s, $m);
    $chars = $m[0] ?? [];
    if (!$chars) {
        return '';
    }
    $out = $chars[0];
    $n = count($chars);
    for ($i = 1; $i < $n; $i++) {
        $code = mb_ord($chars[$i]);
        $isMod = ($code >= 0xFE00 && $code <= 0xFE0F) || $code === 0x200D
            || ($code >= 0x1F3FB && $code <= 0x1F3FF) || $code === 0x20E3;
        $prevZwj = mb_ord(mb_substr($out, -1)) === 0x200D;
        if ($isMod || $prevZwj) {
            $out .= $chars[$i];
        } else {
            break;
        }
    }
    return $out;
}

// Имя игрока для публичного показа: чистый ник + опциональная эмодзи-«висюлька»
function player_label(?array $p): string
{
    $n = esc($p['nickname'] ?? '');
    $f = trim((string)($p['flair'] ?? ''));
    return $f !== '' ? $n . ' <span class="flair">' . esc($f) . '</span>' : $n;
}

// Цветная точка роли (мирный/шериф/мафия/дон) — единый код цвета по сайту
// Палитра ролей: мирный — красный, шериф — жёлтый, мафия — тёмно-серый, дон — чёрный.
function role_color(string $role): string
{
    return ['civ' => '#e8332a', 'sheriff' => '#e6b13a', 'maf' => '#50505a', 'don' => '#0e0e12'][$role] ?? '#888';
}

function role_dot(string $role): string
{
    return '<span class="hist-dot" style="background:' . role_color($role) . ';"></span>';
}

// Медаль за место (1–3) или само место
function rank_medal(int $pos): string
{
    return $pos === 1 ? '🥇' : ($pos === 2 ? '🥈' : ($pos === 3 ? '🥉' : (string)$pos));
}

// Рекорды клуба: массив [иконка, название, строка-игрока, значение, тип]
function club_records(): array
{
    if (!db_ready()) {
        return [];
    }
    $mainId = (int)db()->query('SELECT id FROM ratings WHERE is_main = 1 LIMIT 1')->fetchColumn();
    if (!$mainId) {
        return [];
    }
    $rows = db()->query("SELECT rc.*, p.nickname, p.avatar, p.flair, p.elo, p.id AS pid
        FROM rating_cache rc JOIN players p ON p.id = rc.player_id WHERE rc.rating_id = $mainId")->fetchAll();
    if (!$rows) {
        return [];
    }
    $topElo = db()->query('SELECT nickname, avatar, flair, elo, id AS pid FROM players ORDER BY elo DESC LIMIT 1')->fetch();
    $wins = fn($r) => (int)$r['w_civ'] + (int)$r['w_maf'] + (int)$r['w_sher'] + (int)$r['w_don'];
    $leader = function (array $rows, callable $metric, int $minGames = 0) {
        $best = null; $bestV = -INF;
        foreach ($rows as $r) {
            if ((int)$r['games'] < $minGames) {
                continue;
            }
            $v = $metric($r);
            if ($v > $bestV) { $bestV = $v; $best = $r; }
        }
        return $best ? [$best, $bestV] : null;
    };
    $recs = [];
    if ($topElo) {
        $recs[] = ['👑', 'Высший ELO', $topElo, (float)$topElo['elo'], 'int'];
    }
    $add = function (string $ic, string $title, ?array $res, string $type) use (&$recs) {
        if ($res) {
            $recs[] = [$ic, $title, $res[0], $res[1], $type];
        }
    };
    $add('💯', 'Высший клубный счёт', $leader($rows, fn($r) => (float)$r['club_score']), 'f2');
    $add('🏆', 'Лучший винрейт (от 30 игр)', $leader($rows, fn($r) => $r['games'] ? $wins($r) / $r['games'] : 0, 30), 'pct');
    $add('🎮', 'Больше всех игр', $leader($rows, fn($r) => (int)$r['games']), 'int');
    $add('➕', 'Больше всех допов', $leader($rows, fn($r) => (float)$r['dop_sum']), 'f1');
    $add('🔪', 'Больше всех ПУ', $leader($rows, fn($r) => (int)$r['pu_count']), 'int');
    $add('🌟', 'Больше всех ЛХ', $leader($rows, fn($r) => (float)$r['lh_sum']), 'f1');
    $add('📊', 'Высший средний (~Σ) — от 10 игр', $leader($rows, fn($r) => (float)$r['avg_total'], 10), 'f2');
    $add('🥇', 'Больше всех MVP вечеров', $leader($rows, fn($r) => (int)($r['mvp_evenings'] ?? 0)), 'int');

    // Рекорды по «одной игре» / прочее — отдельными запросами; игрок берётся по id.
    $plById = function (int $pid): ?array {
        $st = db()->prepare('SELECT nickname, avatar, flair, elo, id AS pid FROM players WHERE id = ?');
        $st->execute([$pid]);
        return $st->fetch() ?: null;
    };
    $single = function (string $ic, string $title, string $sql, string $type) use (&$recs, $plById): void {
        try {
            $r = db()->query($sql)->fetch();
            if ($r && (float)$r['v'] > 0) {
                $pl = $plById((int)$r['pid']);
                if ($pl) {
                    $recs[] = [$ic, $title, $pl, $type === 'int' ? (int)$r['v'] : (float)$r['v'], $type];
                }
            }
        } catch (Throwable $e) {
        }
    };
    $single('📈', 'Макс. ELO за игру', "SELECT player_id pid, MAX(delta) v FROM elo_history GROUP BY player_id ORDER BY v DESC LIMIT 1", 'plus');
    $single('💰', 'Макс. доп за игру', "SELECT gs.player_id pid, MAX(gs.plus) v FROM game_seats gs JOIN games g ON g.id = gs.game_id WHERE g.status = 'finished' GROUP BY gs.player_id ORDER BY v DESC LIMIT 1", 'f1');
    $single('⚖️', 'Больше всех судил', "SELECT judge_player_id pid, COUNT(*) v FROM games WHERE status = 'finished' AND judge_player_id IS NOT NULL GROUP BY judge_player_id ORDER BY v DESC LIMIT 1", 'int');
    // анти-рекорды
    $add('🎲', 'Больше всех техфолов', $leader($rows, fn($r) => (int)($r['tech_count'] ?? 0)), 'int');
    $add('➖', 'Больше всех минусов', $leader($rows, fn($r) => (float)$r['minus_sum']), 'f1');
    return $recs;
}

function records_fmt($v, string $type): string
{
    return match ($type) {
        'pct' => round($v * 100) . '%',
        'int' => (string)(int)$v,
        'plus' => '+' . round((float)$v),
        'f1' => number_format((float)$v, 1),
        'f2' => number_format((float)$v, 2),
        default => (string)$v,
    };
}

// Каталог достижений: ключ => [иконка, название, описание, группа]. Условия — в профиле.
function achievements_catalog(): array
{
    return [
        'debut'    => ['🎬', 'Дебют', 'Первая игра', 'Игры'],
        'ten'      => ['🎯', 'Десятка', '10 игр сыграно', 'Игры'],
        'veteran'  => ['🏛', 'Ветеран', '100 игр сыграно', 'Игры'],
        'streak3'  => ['🔥', 'На кураже', '3 победы подряд', 'Серии'],
        'streak5'  => ['⚡', 'Неудержимый', '5 побед подряд', 'Серии'],
        'streak8'  => ['💥', 'Беспощадный', '8 побед подряд', 'Серии'],
        'streak10' => ['🌟', 'Непобедимый', '10 побед подряд', 'Серии'],
        'black3'   => ['🌘', 'Тёмная сторона', '3 чёрные роли подряд', 'Серии'],
        'black5'   => ['🌑', 'Власть тьмы', '5 чёрных ролей подряд', 'Серии'],
        'black7'   => ['🦇', 'Дитя ночи', '7 чёрных ролей подряд', 'Серии'],
        'redw3'    => ['❤️', 'Красный заряд', '3 победы красными подряд', 'Серии'],
        'red3'     => ['🚩', 'Красная машина', '5 побед красными подряд', 'Серии'],
        'redw7'    => ['🌋', 'Красная стихия', '7 побед красными подряд', 'Серии'],
        'elo1100'  => ['✨', 'Любитель', 'ELO 1100+', 'ELO'],
        'elo1300'  => ['⚔️', 'Знаток', 'ELO 1300+', 'ELO'],
        'elo1500'  => ['💎', 'Эксперт', 'ELO 1500+', 'ELO'],
        'elo1700'  => ['👑', 'Мастер', 'ELO 1700+', 'ELO'],
        'elo1900'  => ['🏅', 'Чемпион', 'ELO 1900+', 'ELO'],
        'elo2100'  => ['🏆', 'Легенда', 'ELO 2100+', 'ELO'],
        'eloday'   => ['📈', 'Прорыв вечера', '+150 ELO за вечер', 'ELO'],
        'dop30'    => ['➕', 'Щедрый на допы', '30+ допов всего', 'Мастерство'],
        'fatgame'  => ['💰', 'Жирная игра', '1+ доп за одну игру', 'Мастерство'],
        'triple'   => ['🎖', 'Тройка в ЛХ', 'Лучший ход 3 из 3', 'Мастерство'],
        'don'      => ['😈', 'Дон-мастер', '60%+ за дона (от 4 игр)', 'Мастерство'],
        'danger'   => ['🎯', 'Самый опасный', '5+ раз первоубиенный (вас вычисляют первым)', 'Мастерство'],
        'tour_win'  => ['🥇', 'Победитель турнира', 'Выиграл турнир', 'Турниры'],
        'tour_win3' => ['🏆', 'Триумфатор', 'Выиграл 3 турнира', 'Турниры'],
        'antilh'    => ['🙈', 'Слепой ход', 'Чаще всех бил в ЛХ мимо: три мирных, ни одного чёрного', 'Особые', true],
    ];
}

// Кто уже получил каждое достижение: ключ => [ник, ...]. Считается по всему клубу.
function achievement_earners(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $out = array_fill_keys(array_keys(achievements_catalog()), []);
    if (!db_ready()) {
        return $out;
    }
    try {
        $mainId = (int)db()->query('SELECT id FROM ratings WHERE is_main = 1 LIMIT 1')->fetchColumn();
        // агрегаты из rating_cache + игроки
        $rc = [];
        if ($mainId) {
            $stRc = db()->prepare("SELECT rc.*, p.nickname, p.elo FROM rating_cache rc JOIN players p ON p.id = rc.player_id WHERE rc.rating_id = ?");
            $stRc->execute([$mainId]);
            foreach ($stRc as $r) {
                $rc[(int)$r['player_id']] = $r;
            }
        }
        // глобальные игры (для серий) — по хронологии
        $byPlayer = [];
        $q = db()->query("SELECT gs.player_id, gs.role, gs.plus, g.winner
            FROM game_seats gs JOIN games g ON g.id = gs.game_id
            WHERE g.status = 'finished' AND g.winner IS NOT NULL
            ORDER BY gs.player_id, COALESCE((SELECT date FROM game_days WHERE id=g.day_id),(SELECT date_from FROM tournaments WHERE id=g.tournament_id)), g.id");
        foreach ($q as $row) {
            $byPlayer[(int)$row['player_id']][] = $row;
        }
        // ELO за вечер
        $eloDay = [];
        foreach (db()->query("SELECT player_id, MAX(s) m FROM (SELECT player_id, gdate, SUM(delta) s FROM elo_history GROUP BY player_id, gdate) t GROUP BY player_id") as $r) {
            $eloDay[(int)$r['player_id']] = (float)$r['m'];
        }
        // Пиковый ELO — ачивки уровней не исчезают, даже если ELO потом упал
        $peakElo = [];
        foreach (db()->query("SELECT player_id, MAX(elo_after) m FROM elo_history GROUP BY player_id") as $r) {
            $peakElo[(int)$r['player_id']] = (float)$r['m'];
        }
        // тройка в ЛХ
        $triples = [];
        foreach (db()->query("SELECT DISTINCT me.player_id FROM games g
            JOIN game_seats me ON me.game_id=g.id AND me.seat=g.first_killed_seat AND me.role IN ('civ','sheriff')
            WHERE g.status='finished' AND g.bm_seat1 BETWEEN 1 AND 10 AND g.bm_seat2 BETWEEN 1 AND 10 AND g.bm_seat3 BETWEEN 1 AND 10
            AND (SELECT COUNT(*) FROM game_seats s WHERE s.game_id=g.id AND s.seat IN (g.bm_seat1,g.bm_seat2,g.bm_seat3) AND s.role IN ('maf','don'))=3") as $r) {
            $triples[(int)$r['player_id']] = true;
        }

        // Ники и аватары для всех игроков (не только из кэша рейтинга), иначе попадает «#id»
        $nickOf = [];
        $avaOf = [];
        $flairOf = [];
        foreach (db()->query('SELECT id, nickname, avatar, flair FROM players') as $p) {
            $pid0 = (int)$p['id'];
            $nickOf[$pid0] = $p['nickname'];
            $avaOf[$pid0] = (!empty($p['avatar']) && is_file(ROOT . '/public_html' . $p['avatar'])) ? $p['avatar'] : '';
            $flairOf[$pid0] = (string)($p['flair'] ?? '');
        }
        $allPids = array_unique(array_merge(array_keys($rc), array_keys($byPlayer)));

        foreach ($allPids as $pid) {
            $r = $rc[$pid] ?? null;
            $games = $r ? (int)$r['games'] : count($byPlayer[$pid] ?? []);
            $elo = $r ? (float)$r['elo'] : 1000;
            $peak = max($elo, $peakElo[$pid] ?? 0); // для ачивок уровней — пиковый ELO
            $nick = $nickOf[$pid] ?? ('#' . $pid);
            // серии
            $maxW = 0; $w = 0; $blk = 0; $bsr = 0; $redW = 0; $rwsr = 0; $maxPlus = 0.0;
            foreach (($byPlayer[$pid] ?? []) as $g) {
                $maxPlus = max($maxPlus, (float)$g['plus']);
                $isBlack = in_array($g['role'], ['maf', 'don'], true);
                $won = ($g['winner'] === 'red' && !$isBlack) || ($g['winner'] === 'black' && $isBlack);
                if ($won) { $w++; $maxW = max($maxW, $w); } else { $w = 0; }
                if ($isBlack) { $bsr++; $blk = max($blk, $bsr); } else { $bsr = 0; }
                if (!$isBlack && $g['winner'] === 'red') { $rwsr++; $redW = max($redW, $rwsr); } else { $rwsr = 0; }
            }
            $puPct = $games ? ((int)($r['pu_count'] ?? 0)) / $games * 100 : 100;
            $donWr = ($r && (int)$r['g_don'] >= 4) ? (int)$r['w_don'] / (int)$r['g_don'] * 100 : 0;
            $cond = [
                'debut' => $games >= 1, 'ten' => $games >= 10, 'veteran' => $games >= 100,
                'streak3' => $maxW >= 3, 'streak5' => $maxW >= 5, 'streak8' => $maxW >= 8, 'streak10' => $maxW >= 10,
                'black3' => $blk >= 3, 'black5' => $blk >= 5, 'black7' => $blk >= 7,
                'redw3' => $redW >= 3, 'red3' => $redW >= 5, 'redw7' => $redW >= 7,
                'elo1100' => $peak >= 1100, 'elo1300' => $peak >= 1300, 'elo1500' => $peak >= 1500,
                'elo1700' => $peak >= 1700, 'elo1900' => $peak >= 1900, 'elo2100' => $peak >= 2100,
                'eloday' => ($eloDay[$pid] ?? 0) >= 150,
                'dop30' => $r && (float)$r['dop_sum'] >= 30, 'fatgame' => $maxPlus >= 1.0,
                'triple' => isset($triples[$pid]),
                'don' => $donWr >= 60, 'danger' => ((int)($r['pu_count'] ?? 0)) >= 5,
            ];
            foreach ($cond as $k => $ok) {
                if ($ok) {
                    $out[$k][] = [$pid, $nick, $avaOf[$pid] ?? '', $flairOf[$pid] ?? ''];
                }
            }
        }

        // Анти-ачивка «Антиснайпер»: больше всех ЛХ мимо (3 мирных, ни одного чёрного)
        $wl = db()->query("SELECT me.player_id pid, COUNT(*) c FROM games g
            JOIN game_seats me ON me.game_id = g.id AND me.seat = g.first_killed_seat AND me.role IN ('civ','sheriff')
            WHERE g.status = 'finished' AND g.bm_seat1 BETWEEN 1 AND 10 AND g.bm_seat2 BETWEEN 1 AND 10 AND g.bm_seat3 BETWEEN 1 AND 10
              AND (SELECT COUNT(*) FROM game_seats s WHERE s.game_id = g.id AND s.seat IN (g.bm_seat1, g.bm_seat2, g.bm_seat3) AND s.role IN ('maf','don')) = 0
            GROUP BY me.player_id")->fetchAll();
        $maxWl = 0;
        foreach ($wl as $row) {
            $maxWl = max($maxWl, (int)$row['c']);
        }
        if ($maxWl > 0) {
            foreach ($wl as $row) {
                if ((int)$row['c'] === $maxWl) {
                    $p0 = (int)$row['pid'];
                    $out['antilh'][] = [$p0, $nickOf[$p0] ?? ('#' . $p0), $avaOf[$p0] ?? '', $flairOf[$p0] ?? ''];
                }
            }
        }

        // Победители турниров: #1 итоговой таблицы каждого завершённого турнира
        require_once ROOT . '/inc/rating.php';
        $tourWins = [];
        foreach (db()->query("SELECT id FROM tournaments WHERE status = 'finished'")->fetchAll(PDO::FETCH_COLUMN) as $tid) {
            $gq = db()->prepare("SELECT * FROM games WHERE tournament_id = ? AND status = 'finished'");
            $gq->execute([(int)$tid]);
            $gms = $gq->fetchAll();
            if (!$gms) {
                continue;
            }
            $gids = array_column($gms, 'id');
            $inG = implode(',', array_fill(0, count($gids), '?'));
            $sq = db()->prepare("SELECT gs.*, p.nickname, p.avatar, p.elo FROM game_seats gs JOIN players p ON p.id = gs.player_id WHERE gs.game_id IN ($inG)");
            $sq->execute($gids);
            $sbg = [];
            foreach ($sq->fetchAll() as $s) {
                $sbg[(int)$s['game_id']][] = $s;
            }
            $stand = standings_from_games($gms, $sbg);
            $winPid = $stand ? (int)array_key_first($stand) : 0;
            if ($winPid) {
                $tourWins[$winPid] = ($tourWins[$winPid] ?? 0) + 1;
            }
        }
        foreach ($tourWins as $p0 => $cnt) {
            $entry = [$p0, $nickOf[$p0] ?? ('#' . $p0), $avaOf[$p0] ?? '', $flairOf[$p0] ?? ''];
            $out['tour_win'][] = $entry;
            if ($cnt >= 3) {
                $out['tour_win3'][] = $entry;
            }
        }
    } catch (Throwable $e) {
    }
    $cache = $out;
    return $out;
}

// Лесенка уровней по ELO (единый источник для профиля и графиков)
function elo_tiers(): array
{
    return [
        [800, 'Новичок'],
        [1100, 'Любитель'],
        [1300, 'Знаток'],
        [1500, 'Эксперт'],
        [1700, 'Мастер'],
        [1900, 'Чемпион'],
        [2100, 'Легенда'],
    ];
}

// Индекс для подсветки упоминаний игроков в тексте: [regex, map(нижний_ник => id)].
function player_mention_index(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $map = [];
    $names = [];
    if (db_ready()) {
        try {
            foreach (db()->query('SELECT id, nickname FROM players') as $p) {
                $nick = trim((string)$p['nickname']);
                if (mb_strlen($nick) < 4) {
                    continue; // слишком короткие ники не трогаем (шум)
                }
                $low = mb_strtolower($nick);
                if (isset($map[$low])) {
                    continue;
                }
                $map[$low] = (int)$p['id'];
                $names[] = $nick;
            }
        } catch (Throwable $e) {
        }
    }
    usort($names, fn($a, $b) => mb_strlen($b) <=> mb_strlen($a)); // длинные ники — раньше
    $regex = '';
    if ($names) {
        $alt = implode('|', array_map(fn($n) => preg_quote($n, '~'), $names));
        $regex = '~(?<![\p{L}\p{N}_])(' . $alt . ')(?![\p{L}\p{N}_])~u';
    }
    $cache = [$regex, $map];
    return $cache;
}

// Встроенный плеер из ссылки (VK Video / YouTube) или null, если не видео.
function media_embed(string $url): ?string
{
    if (preg_match('~(?:vkvideo\.ru|vk\.com)/video(-?\d+)_(\d+)~', $url, $m)) {
        // Встраивание VK-плеера часто даёт чёрный экран — даём надёжную ссылку-кнопку.
        $watch = 'https://vk.com/video' . $m[1] . '_' . $m[2];
        return '<a class="post-video-link" href="' . esc($watch) . '" target="_blank" rel="noopener">Смотреть видео во ВКонтакте</a>';
    }
    if (preg_match('~(?:youtu\.be/|youtube\.com/(?:watch\?v=|embed/|shorts/|live/))([A-Za-z0-9_-]{6,})~', $url, $m)) {
        $src = 'https://www.youtube.com/embed/' . $m[1];
        return '<span class="post-video"><iframe src="' . esc($src)
            . '" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen loading="lazy"></iframe></span>';
    }
    return null;
}

// Эмодзи → картинки Telegram (Apple-стиль). Имя файла = hex UTF-8 байтов без VS16.
// Если картинка не загрузится — JS заменит её на системный символ (alt).
function tg_emojify(string $html): string
{
    static $re = null;
    if ($re === null) {
        $base = '\x{1F300}-\x{1FAFF}\x{1F000}-\x{1F0FF}\x{1F1E6}-\x{1F1FF}'
            . '\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}\x{2300}-\x{23FF}'
            . '\x{2122}\x{2139}\x{2194}-\x{21AA}\x{24C2}\x{2934}\x{2935}';
        // одна эмодзи = база + её модификаторы (VS16, тон кожи, кейкап);
        // в одну картинку склеиваем только ZWJ-последовательности, а не соседние эмодзи
        $one = '[' . $base . '][\x{FE0F}\x{1F3FB}-\x{1F3FF}\x{20E3}]*';
        $re = '/' . $one . '(?:\x{200D}' . $one . ')*/u';
    }
    return (string)preg_replace_callback($re, function ($m) {
        $seq = $m[0];
        $clean = str_replace("\xEF\xB8\x8F", '', $seq); // VS16 (U+FE0F) Telegram в имени файла не использует
        if ($clean === '') {
            return $seq;
        }
        return '<img class="tg-e" src="//telegram.org/img/emoji/40/' . strtoupper(bin2hex($clean))
            . '.png" alt="' . htmlspecialchars($seq, ENT_QUOTES, 'UTF-8') . '" loading="lazy">';
    }, $html);
}

// Рендер текста поста: экранирование + кликабельные ссылки/видео + ссылки на игроков + переносы строк.
function render_post_body(?string $raw): string
{
    $raw = (string)$raw;
    if ($raw === '') {
        return '';
    }
    [$regex, $map] = player_mention_index();
    $parts = preg_split('~(https?://[^\s<]+)~u', $raw, -1, PREG_SPLIT_DELIM_CAPTURE);
    $out = '';
    foreach ($parts as $i => $part) {
        if ($i % 2 === 1) {
            $embed = media_embed($part);
            if ($embed !== null) {
                $out .= $embed;
            } else {
                $u = rtrim($part, '.,;:!?）)]」»');
                $tail = substr($part, strlen($u));
                $disp = mb_strimwidth($u, 0, 56, '…');
                $out .= '<a href="' . esc($u) . '" target="_blank" rel="noopener nofollow">' . esc($disp) . '</a>' . esc($tail);
            }
        } else {
            $esc = esc($part);
            if ($regex !== '') {
                $esc = preg_replace_callback($regex, function ($m) use ($map) {
                    $id = $map[mb_strtolower($m[1])] ?? 0;
                    return $id ? '<a class="pl-mention" href="/player.php?id=' . $id . '">' . $m[1] . '</a>' : $m[1];
                }, $esc);
            }
            $out .= tg_emojify($esc);
        }
    }
    return nl2br($out);
}

// ── Уведомления на сайте (колокольчик) ───────────────────
function app_notify(int $userId, string $text, ?string $link = null): void
{
    if ($userId <= 0) {
        return;
    }
    try {
        db()->prepare('INSERT INTO notifications (user_id, text, link) VALUES (?,?,?)')
            ->execute([$userId, mb_substr($text, 0, 500), $link]);
    } catch (Throwable $e) {
    }
}

// Уведомить игрока (если у него есть аккаунт на сайте)
function app_notify_player(int $playerId, string $text, ?string $link = null): void
{
    try {
        $st = db()->prepare('SELECT user_id FROM players WHERE id = ? AND user_id IS NOT NULL');
        $st->execute([$playerId]);
        $uid = (int)($st->fetchColumn() ?: 0);
        if ($uid) {
            app_notify($uid, $text, $link);
        }
    } catch (Throwable $e) {
    }
}

// Уведомить всех участников клуба (у кого есть аккаунт и привязан игрок)
function app_notify_all_members(string $text, ?string $link = null): void
{
    try {
        db()->prepare('INSERT INTO notifications (user_id, text, link)
            SELECT DISTINCT p.user_id, ?, ? FROM players p WHERE p.user_id IS NOT NULL')
            ->execute([mb_substr($text, 0, 500), $link]);
    } catch (Throwable $e) {
    }
}

function app_notify_unread(int $userId): int
{
    if ($userId <= 0) {
        return 0;
    }
    try {
        $st = db()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
        $st->execute([$userId]);
        return (int)$st->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

// Доступные реакции на новости (фиксированный набор — валидируется на сервере).
function news_react_emojis(): array
{
    return ['👍', '🔥', '❤️', '😁', '🎉', '👏'];
}

// Счётчики реакций поста и текущая реакция пользователя: [ [emoji=>count], myEmoji|null ].
function news_reaction_data(int $newsId, ?int $userId): array
{
    $counts = [];
    $mine = null;
    if (!db_ready() || $newsId <= 0) {
        return [$counts, $mine];
    }
    try {
        $st = db()->prepare('SELECT emoji, COUNT(*) c FROM news_reactions WHERE news_id = ? GROUP BY emoji');
        $st->execute([$newsId]);
        foreach ($st->fetchAll() as $r) {
            $counts[$r['emoji']] = (int)$r['c'];
        }
        if ($userId) {
            $st = db()->prepare('SELECT emoji FROM news_reactions WHERE news_id = ? AND user_id = ?');
            $st->execute([$newsId, $userId]);
            $mine = $st->fetchColumn() ?: null;
        }
    } catch (Throwable $e) {
    }
    return [$counts, $mine];
}

function elo_tier_name(float $elo): string
{
    $name = 'Новичок';
    foreach (elo_tiers() as [$th, $n]) {
        if ($elo >= $th) {
            $name = $n;
        }
    }
    return $name;
}

function elo_tier_ladder(float $elo): string
{
    $tiers = elo_tiers();
    $curIdx = 0;
    foreach ($tiers as $i => [$th]) {
        if ($elo >= $th) {
            $curIdx = $i;
        }
    }
    $h = '<div class="tier-ladder">';
    foreach ($tiers as $i => [$th, $n]) {
        $cls = $i < $curIdx ? 'passed' : ($i === $curIdx ? 'current' : 'future');
        $h .= '<span class="tier-step ' . $cls . '"><b>' . esc($n) . '</b><i>'
            . ($th > 0 ? $th . '+' : '0') . '</i></span>';
    }
    return $h . '</div>';
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . esc(csrf_token()) . '">';
}

function csrf_check(): void
{
    $sent = (string)($_POST['csrf'] ?? '');
    $have = (string)($_SESSION['csrf'] ?? '');
    if ($have === '' || !hash_equals($have, $sent)) {
        http_response_code(403);
        exit('Сессия устарела — вернитесь назад и обновите страницу.');
    }
}

function flash_set(string $type, string $msg): void
{
    $_SESSION['flash'][] = ['t' => $type, 'm' => $msg];
}

function flash_pull(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

function redirect(string $to): void
{
    header('Location: ' . $to);
    exit;
}

function client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '';
}

// Кол-во требующих внимания админа: новые предложения + заявки на привязку
function admin_alerts(): int
{
    static $n = -1;
    if ($n < 0) {
        $n = 0;
        if (db_ready()) {
            try {
                $n = (int)db()->query("SELECT
                    (SELECT COUNT(*) FROM suggestions WHERE status = 'new')
                    + (SELECT COUNT(*) FROM link_requests WHERE status = 'pending')")->fetchColumn();
            } catch (Throwable $e) {
                $n = 0;
            }
        }
    }
    return $n;
}

// Игрок, привязанный к текущему пользователю (с автопривязкой по совпадающему нику)
function current_player(): ?array
{
    static $p = false;
    if ($p === false) {
        $p = null;
        $u = current_user();
        if ($u && db_ready()) {
            $st = db()->prepare('SELECT * FROM players WHERE user_id = ?');
            $st->execute([(int)$u['id']]);
            $p = $st->fetch() ?: null;
            if (!$p) {
                $p = ensure_player_link($u);
            }
        }
    }
    return $p;
}

function avatar_html(?array $player, int $size = 30, string $style = ''): string
{
    $letter = mb_strtoupper(mb_substr($player['nickname'] ?? '?', 0, 1));
    $fs = (int)round($size * 0.4);
    if (!empty($player['avatar']) && is_file(ROOT . '/public_html' . $player['avatar'])) {
        return '<img src="' . esc($player['avatar']) . '" alt="" style="width:' . $size . 'px;height:' . $size
            . 'px;border-radius:50%;object-fit:cover;flex:none;vertical-align:middle;' . $style . '">';
    }
    return '<span class="avatar-circle" style="width:' . $size . 'px;height:' . $size . 'px;font-size:' . $fs . 'px;'
        . 'vertical-align:middle;' . $style . '">' . esc($letter) . '</span>';
}

function player_id_by_nick(string $nick): ?int
{
    $nick = trim($nick);
    if ($nick === '') {
        return null;
    }
    $st = db()->prepare('SELECT id FROM players WHERE LOWER(nickname) = LOWER(?) LIMIT 1');
    $st->execute([$nick]);
    $id = $st->fetchColumn();
    return $id !== false ? (int)$id : null;
}

function setting(string $key, string $default = ''): string
{
    try {
        $st = db()->prepare('SELECT v FROM settings WHERE k = ?');
        $st->execute([$key]);
        $v = $st->fetchColumn();
        return $v !== false && $v !== null ? (string)$v : $default;
    } catch (Throwable $e) {
        return $default;
    }
}

function setting_set(string $key, string $value): void
{
    db()->prepare('INSERT INTO settings (k, v) VALUES (?,?) ON DUPLICATE KEY UPDATE v = VALUES(v)')
        ->execute([$key, $value]);
}

// Сохранение загруженной картинки. С GD — пережатие в JPEG до $maxSide px;
// без GD — сохраняем оригинал как есть. Возвращает web-путь или строку-ошибку.
function save_image_upload(array $file, string $subdir, string $name, int $maxSide = 512)
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return 'Файл не загрузился (код ' . ($file['error'] ?? '?') . ')';
    }
    if (($file['size'] ?? 0) > 15 * 1024 * 1024) {
        return 'Файл больше 15 МБ';
    }
    $info = @getimagesize($file['tmp_name']);
    if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
        return 'Нужна картинка JPG, PNG или WebP';
    }
    $dir = ROOT . '/public_html/uploads/' . $subdir;
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
        return 'Не удалось создать папку загрузок (' . $subdir . ')';
    }
    $extByType = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];

    // Резерв: сохранить оригинал (если GD недоступен или обработка не удалась)
    $saveRaw = function () use ($file, $dir, $name, $subdir, $extByType, $info) {
        $ext = $extByType[$info[2]];
        $path = $dir . '/' . $name . '.' . $ext;
        if (is_uploaded_file($file['tmp_name'])) {
            if (!@move_uploaded_file($file['tmp_name'], $path) && !@copy($file['tmp_name'], $path)) {
                return 'Не удалось сохранить файл';
            }
        } elseif (!@copy($file['tmp_name'], $path)) {
            return 'Не удалось сохранить файл';
        }
        return '/uploads/' . $subdir . '/' . $name . '.' . $ext;
    };

    if (!function_exists('imagecreatetruecolor')) {
        return $saveRaw();
    }
    $img = match ($info[2]) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($file['tmp_name']),
        IMAGETYPE_PNG => @imagecreatefrompng($file['tmp_name']),
        IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($file['tmp_name']) : false,
        default => false,
    };
    if (!$img) {
        return $saveRaw();
    }
    $w = imagesx($img);
    $h = imagesy($img);
    $scale = min(1, $maxSide / max($w, $h));
    $nw = max(1, (int)round($w * $scale));
    $nh = max(1, (int)round($h * $scale));
    $dst = imagecreatetruecolor($nw, $nh);
    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
    imagecopyresampled($dst, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
    imagedestroy($img);
    $path = $dir . '/' . $name . '.jpg';
    $ok = @imagejpeg($dst, $path, 85);
    imagedestroy($dst);
    if (!$ok) {
        return $saveRaw();
    }
    return '/uploads/' . $subdir . '/' . $name . '.jpg';
}

function log_action(?int $userId, string $action, array $details = []): void
{
    try {
        db()->prepare('INSERT INTO logs (user_id, action, details, ip) VALUES (?,?,?,?)')
            ->execute([
                $userId,
                $action,
                json_encode($details, JSON_UNESCAPED_UNICODE),
                client_ip(),
            ]);
    } catch (Throwable $e) {
        // лог не должен ронять запрос
    }
}
