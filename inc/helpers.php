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
    $add('📊', 'Высший средний (~Σ)', $leader($rows, fn($r) => (float)$r['avg_total']), 'f2');
    return $recs;
}

function records_fmt($v, string $type): string
{
    return match ($type) {
        'pct' => round($v * 100) . '%',
        'int' => (string)(int)$v,
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
        'black5'   => ['🌑', 'Власть тьмы', '5 чёрных ролей подряд', 'Серии'],
        'red3'     => ['🚩', 'Красная машина', '3 победы красными подряд', 'Серии'],
        'elo1100'  => ['✨', 'Любитель', 'ELO 1100+', 'ELO'],
        'elo1500'  => ['⚔️', 'Боец', 'ELO 1500+', 'ELO'],
        'elo1900'  => ['💎', 'Эксперт', 'ELO 1900+', 'ELO'],
        'elo2200'  => ['👑', 'Мастер', 'ELO 2200+', 'ELO'],
        'elo2500'  => ['🏆', 'Легенда', 'ELO 2500+', 'ELO'],
        'eloday'   => ['📈', 'Прорыв вечера', '+150 ELO за вечер', 'ELO'],
        'dop30'    => ['➕', 'Щедрый на допы', '30+ допов всего', 'Мастерство'],
        'fatgame'  => ['💰', 'Жирная игра', '1.5+ допа за одну игру', 'Мастерство'],
        'triple'   => ['🎖', 'Тройка в ЛХ', 'Лучший ход 3 из 3', 'Мастерство'],
        'don'      => ['😈', 'Дон-мастер', '60%+ за дона (от 4 игр)', 'Мастерство'],
        'survivor' => ['🩸', 'Живучий', 'ПУ менее 20% игр (от 20)', 'Мастерство'],
    ];
}

// Кто уже получил каждое достижение: ключ => [ник, ...]. Считается по всему клубу.
function achievement_earners(): array
{
    $out = array_fill_keys(array_keys(achievements_catalog()), []);
    if (!db_ready()) {
        return $out;
    }
    try {
        $mainId = (int)db()->query('SELECT id FROM ratings WHERE is_main = 1 LIMIT 1')->fetchColumn();
        // агрегаты из rating_cache + игроки
        $rc = [];
        if ($mainId) {
            foreach (db()->query("SELECT rc.*, p.nickname, p.elo FROM rating_cache rc JOIN players p ON p.id = rc.player_id WHERE rc.rating_id = $mainId") as $r) {
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
        foreach (db()->query('SELECT id, nickname, avatar FROM players') as $p) {
            $pid0 = (int)$p['id'];
            $nickOf[$pid0] = $p['nickname'];
            $avaOf[$pid0] = (!empty($p['avatar']) && is_file(ROOT . '/public_html' . $p['avatar'])) ? $p['avatar'] : '';
        }
        $allPids = array_unique(array_merge(array_keys($rc), array_keys($byPlayer)));

        foreach ($allPids as $pid) {
            $r = $rc[$pid] ?? null;
            $games = $r ? (int)$r['games'] : count($byPlayer[$pid] ?? []);
            $elo = $r ? (float)$r['elo'] : 1000;
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
                'streak3' => $maxW >= 3, 'streak5' => $maxW >= 5, 'black5' => $blk >= 5, 'red3' => $redW >= 3,
                'elo1100' => $elo >= 1100, 'elo1500' => $elo >= 1500, 'elo1900' => $elo >= 1900,
                'elo2200' => $elo >= 2200, 'elo2500' => $elo >= 2500,
                'eloday' => ($eloDay[$pid] ?? 0) >= 150,
                'dop30' => $r && (float)$r['dop_sum'] >= 30, 'fatgame' => $maxPlus >= 1.5,
                'triple' => isset($triples[$pid]),
                'don' => $donWr >= 60, 'survivor' => $games >= 20 && $puPct < 20,
            ];
            foreach ($cond as $k => $ok) {
                if ($ok) {
                    $out[$k][] = [$pid, $nick, $avaOf[$pid] ?? ''];
                }
            }
        }
    } catch (Throwable $e) {
    }
    return $out;
}

// Лесенка уровней по ELO (единый источник для профиля и графиков)
function elo_tiers(): array
{
    return [
        [600, 'Новичок'],
        [1100, 'Любитель'],
        [1500, 'Боец'],
        [1900, 'Эксперт'],
        [2200, 'Мастер'],
        [2500, 'Легенда'],
    ];
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
