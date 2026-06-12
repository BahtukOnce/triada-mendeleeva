<?php
declare(strict_types=1);

// Общая библиотека Telegram-бота «Триада Менделеева».
// Используется вебхуком public_html/bot.php и эндпоинтом public_html/api/bot_notify.php.
// Предполагает, что вызывающий уже подключил config (в $GLOBALS['cfg']) и inc/db.php.

// ── Telegram API ──────────────────────────────────────────
function bot_token(): string
{
    return (string)($GLOBALS['cfg']['bot_token'] ?? '');
}

function bot_api(string $method, array $params = []): ?array
{
    $token = bot_token();
    if ($token === '') {
        return null;
    }
    $ch = curl_init('https://api.telegram.org/bot' . $token . '/' . $method);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $params,
    ]);
    $r = curl_exec($ch);
    curl_close($ch);
    return $r ? json_decode($r, true) : null;
}

function bot_send($chatId, string $text, ?string $markup = null): ?array
{
    $p = [
        'chat_id'                  => $chatId,
        'text'                     => $text,
        'parse_mode'               => 'HTML',
        'disable_web_page_preview' => true,
    ];
    if ($markup !== null) {
        $p['reply_markup'] = $markup;
    }
    return bot_api('sendMessage', $p);
}

// ── Утилиты ───────────────────────────────────────────────
function bot_esc(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function bot_num($v): string
{
    $f = (float)$v;
    if (abs($f - round($f)) < 0.001) {
        return (string)(int)round($f);
    }
    return number_format($f, 2, '.', '');
}

function bot_norm(string $s): string
{
    // эмодзи в нике игнорируем при сравнении (ник всегда чистый)
    $s = (string)preg_replace('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}\x{2190}-\x{21FF}\x{FE00}-\x{FE0F}\x{200D}\x{20E3}]/u', '', $s);
    return (string)preg_replace('/\s+/', ' ', mb_strtolower(trim($s)));
}

function bot_same_name(string $a, string $b): bool
{
    return bot_norm($a) === bot_norm($b);
}

function bot_setting(string $k, $default = '')
{
    try {
        $st = db()->prepare('SELECT v FROM settings WHERE k = ?');
        $st->execute([$k]);
        $v = $st->fetchColumn();
        return $v === false ? $default : $v;
    } catch (Throwable $e) {
        return $default;
    }
}

function bot_date(string $ymd): string
{
    $t = strtotime($ymd);
    if (!$t) {
        return $ymd;
    }
    $months = [1 => 'января', 'февраля', 'марта', 'апреля', 'мая', 'июня',
        'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];
    return (int)date('j', $t) . ' ' . ($months[(int)date('n', $t)] ?? '') . ' ' . date('Y', $t);
}

// ── Поиск игрока по нику ──────────────────────────────────
function bot_find_player(array $players, string $q): ?array
{
    $qn = bot_norm($q);
    if ($qn === '') {
        return null;
    }
    foreach ($players as $p) {
        if (bot_norm($p['name']) === $qn) {
            return $p;
        }
    }
    foreach ($players as $p) {
        if (str_starts_with(bot_norm($p['name']), $qn)) {
            return $p;
        }
    }
    foreach ($players as $p) {
        if (str_contains(bot_norm($p['name']), $qn)) {
            return $p;
        }
    }
    $best = null;
    $bestD = PHP_INT_MAX;
    foreach ($players as $p) {
        $d = levenshtein($qn, bot_norm($p['name']));
        if ($d < $bestD) {
            $bestD = $d;
            $best = $p;
        }
    }
    return ($best && $bestD <= 2) ? $best : null;
}

function bot_suggest_names(array $players, string $q): string
{
    $qn = bot_norm($q);
    $cand = [];
    foreach ($players as $p) {
        $cand[] = [levenshtein($qn, bot_norm($p['name'])), $p['name']];
    }
    usort($cand, fn($a, $b) => $a[0] <=> $b[0]);
    $out = array_map(fn($c) => bot_esc((string)$c[1]), array_slice($cand, 0, 3));
    return implode(', ', $out);
}

// Все игроки клуба (для матчинга при регистрации — даже без сыгранных игр)
function bot_all_players(): array
{
    $rows = db()->query('SELECT id, nickname FROM players ORDER BY nickname')->fetchAll();
    return array_map(fn($r) => ['name' => $r['nickname'], 'player_id' => (int)$r['id']], $rows);
}

// ── Привязка tg → игрок ───────────────────────────────────
function bot_player_by_tg(int $tgId): ?array
{
    $st = db()->prepare('SELECT * FROM players WHERE tg_user_id = ? LIMIT 1');
    $st->execute([$tgId]);
    return $st->fetch() ?: null;
}

function bot_link(int $tgId, ?array $from, int $playerId): void
{
    $un = $from['username'] ?? null;
    // снять этот tg с других игроков (уникальный ключ)
    db()->prepare('UPDATE players SET tg_user_id = NULL, tg_username = NULL, tg_linked_at = NULL
        WHERE tg_user_id = ? AND id <> ?')->execute([$tgId, $playerId]);
    db()->prepare('UPDATE players SET tg_user_id = ?, tg_username = ?, tg_linked_at = NOW()
        WHERE id = ?')->execute([$tgId, $un, $playerId]);
    // синхронизировать с аккаунтом, если игрок к нему привязан
    $st = db()->prepare('SELECT user_id FROM players WHERE id = ?');
    $st->execute([$playerId]);
    $uid = (int)($st->fetchColumn() ?: 0);
    if ($uid) {
        db()->prepare('UPDATE users SET tg_user_id = NULL, tg_username = NULL, tg_linked_at = NULL
            WHERE tg_user_id = ? AND id <> ?')->execute([$tgId, $uid]);
        db()->prepare('UPDATE users SET tg_user_id = ?, tg_username = ?, tg_linked_at = NOW()
            WHERE id = ?')->execute([$tgId, $un, $uid]);
    }
}

function bot_unlink(int $tgId): void
{
    db()->prepare('UPDATE players SET tg_user_id = NULL, tg_username = NULL, tg_linked_at = NULL
        WHERE tg_user_id = ?')->execute([$tgId]);
    db()->prepare('UPDATE users SET tg_user_id = NULL, tg_username = NULL, tg_linked_at = NULL
        WHERE tg_user_id = ?')->execute([$tgId]);
}

// обновить username у уже привязанного (без смены игрока)
function bot_touch(int $tgId, ?array $from): void
{
    if (!$from || empty($from['username'])) {
        return;
    }
    db()->prepare('UPDATE players SET tg_username = ? WHERE tg_user_id = ? AND (tg_username IS NULL OR tg_username <> ?)')
        ->execute([$from['username'], $tgId, $from['username']]);
}

function bot_is_admin(int $tgId): bool
{
    $p = bot_player_by_tg($tgId);
    if (!$p) {
        return false;
    }
    $owner = (string)($GLOBALS['cfg']['owner_nickname'] ?? '');
    if ($owner !== '' && bot_same_name((string)$p['nickname'], $owner)) {
        return true;
    }
    if (!empty($p['user_id'])) {
        $st = db()->prepare('SELECT role FROM users WHERE id = ?');
        $st->execute([(int)$p['user_id']]);
        if (in_array((string)$st->fetchColumn(), ['admin', 'owner'], true)) {
            return true;
        }
    }
    return false;
}

// ── Данные статистики (схема как у старого бота) ──────────
function bot_stats_data(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $pdo = db();
    $mainId = (int)($pdo->query('SELECT id FROM ratings WHERE is_main = 1 LIMIT 1')->fetchColumn() ?: 0);
    $players = [];
    if ($mainId) {
        $st = $pdo->prepare("SELECT rc.*, p.nickname, p.banned_at, p.id AS pid, p.elo
            FROM rating_cache rc JOIN players p ON p.id = rc.player_id
            WHERE rc.rating_id = ?
            ORDER BY (rc.club_score IS NULL), rc.club_score DESC, rc.sum_total DESC");
        $st->execute([$mainId]);
        $rank = 0;
        foreach ($st->fetchAll() as $r) {
            $rank++;
            $games = (int)$r['games'];
            $wins = (int)$r['w_civ'] + (int)$r['w_maf'] + (int)$r['w_sher'] + (int)$r['w_don'];
            $rating = $r['club_score'] !== null ? (float)$r['club_score'] : (float)$r['sum_total'];
            $players[] = [
                'name'      => (string)$r['nickname'],
                'player_id' => (int)$r['pid'],
                'rank'      => $rank,
                'rating'    => round($rating, 2),
                'elo'       => (int)round((float)$r['elo']),
                'ci'        => round((float)$r['ci_sum'], 2),
                'dopy'      => round((float)$r['dop_sum'], 1),
                'minus'     => round((float)$r['minus_sum'], 1),
                'pu'        => (int)$r['pu_count'],
                'lh'        => round((float)$r['lh_sum'], 1),
                'allowed'   => $r['banned_at'] ? 'забанен' : 'да',
                'overall'   => ['wins' => $wins, 'games' => $games, 'pct' => $games ? (int)round($wins / $games * 100) : 0],
                'roles'     => [
                    'mir'  => ['wins' => (int)$r['w_civ'], 'games' => (int)$r['g_civ']],
                    'maf'  => ['wins' => (int)$r['w_maf'], 'games' => (int)$r['g_maf']],
                    'sher' => ['wins' => (int)$r['w_sher'], 'games' => (int)$r['g_sher']],
                    'don'  => ['wins' => (int)$r['w_don'], 'games' => (int)$r['g_don']],
                ],
            ];
        }
    }
    $cache = [
        'ok'          => true,
        'players'     => $players,
        'judges'      => bot_judges(),
        'nominations' => bot_nominations($players),
    ];
    return $cache;
}

// Судьи клуба: привязанные к судейскому аккаунту + те, кто реально судил игры
function bot_judges(): array
{
    $names = db()->query("SELECT DISTINCT p.nickname FROM players p
        JOIN users u ON u.id = p.user_id
        WHERE u.is_judge = 1 OR u.role IN ('admin','owner')")->fetchAll(PDO::FETCH_COLUMN);
    $played = db()->query("SELECT DISTINCT p.nickname FROM games g
        JOIN players p ON p.id = g.judge_player_id
        WHERE g.judge_player_id IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
    $names = array_values(array_unique(array_merge($names, $played)));
    sort($names, SORT_NATURAL | SORT_FLAG_CASE);
    return $names;
}

// Номинации — те же формулы, что на странице рейтинга
function bot_nominations(array $players): array
{
    $minG = (int)(bot_setting('min_games_nomination', '15') ?: 15);
    $cands = array_values(array_filter($players, fn($r) => $r['overall']['games'] >= $minG));
    $bestBy = function (array $cands, callable $w, callable $g, int $min) {
        $best = null;
        $bw = -1;
        foreach ($cands as $r) {
            $gg = $g($r);
            if ($gg < $min) {
                continue;
            }
            $wr = $w($r) / $gg;
            if ($wr > $bw + 1e-9 || (abs($wr - $bw) < 1e-9 && $best && $gg > $g($best))) {
                $bw = $wr;
                $best = $r;
            }
        }
        return $best;
    };
    $noms = [];
    $mvp = $cands[0] ?? null; // массив уже отсортирован по рангу
    if ($mvp) {
        $noms[] = ['title' => 'MVP клуба', 'name' => $mvp['name']];
    }
    $don = $bestBy($cands, fn($r) => $r['roles']['don']['wins'], fn($r) => $r['roles']['don']['games'], 4);
    if ($don) {
        $noms[] = ['title' => 'Лучший дон', 'name' => $don['name']];
    }
    $sher = $bestBy($cands, fn($r) => $r['roles']['sher']['wins'], fn($r) => $r['roles']['sher']['games'], 4);
    if ($sher) {
        $noms[] = ['title' => 'Лучший шериф', 'name' => $sher['name']];
    }
    $red = $bestBy($cands, fn($r) => $r['roles']['mir']['wins'] + $r['roles']['sher']['wins'],
        fn($r) => $r['roles']['mir']['games'] + $r['roles']['sher']['games'], 10);
    if ($red) {
        $noms[] = ['title' => 'Лучший красный', 'name' => $red['name']];
    }
    $black = $bestBy($cands, fn($r) => $r['roles']['maf']['wins'] + $r['roles']['don']['wins'],
        fn($r) => $r['roles']['maf']['games'] + $r['roles']['don']['games'], 8);
    if ($black) {
        $noms[] = ['title' => 'Лучший чёрный', 'name' => $black['name']];
    }
    return $noms;
}

// ── Запись на игровой день ────────────────────────────────
function bot_open_day(): ?array
{
    $st = db()->query("SELECT * FROM game_days WHERE status = 'reg_open' AND date >= CURDATE() ORDER BY date LIMIT 1");
    return $st->fetch() ?: null;
}

function bot_day_by_id(int $dayId): ?array
{
    $st = db()->prepare("SELECT * FROM game_days WHERE id = ? LIMIT 1");
    $st->execute([$dayId]);
    return $st->fetch() ?: null;
}

function bot_day_is_registered(int $dayId, int $playerId): bool
{
    $st = db()->prepare('SELECT 1 FROM day_registrations WHERE day_id = ? AND player_id = ? AND cancelled_at IS NULL');
    $st->execute([$dayId, $playerId]);
    return (bool)$st->fetchColumn();
}

function bot_day_register(int $dayId, int $playerId): void
{
    db()->prepare("INSERT INTO day_registrations (day_id, player_id, source) VALUES (?,?,'telegram')
        ON DUPLICATE KEY UPDATE cancelled_at = NULL, source = 'telegram'")->execute([$dayId, $playerId]);
}

function bot_day_cancel(int $dayId, int $playerId): void
{
    db()->prepare('UPDATE day_registrations SET cancelled_at = NOW() WHERE day_id = ? AND player_id = ?')
        ->execute([$dayId, $playerId]);
}

function bot_day_count(int $dayId): int
{
    $st = db()->prepare('SELECT COUNT(*) FROM day_registrations WHERE day_id = ? AND cancelled_at IS NULL');
    $st->execute([$dayId]);
    return (int)$st->fetchColumn();
}

// ── Рассылка ──────────────────────────────────────────────
function bot_recipients(): array
{
    return array_map('intval',
        db()->query('SELECT tg_user_id FROM players WHERE tg_user_id IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN));
}

function bot_broadcast(string $text): array
{
    $sent = 0;
    $failed = 0;
    foreach (bot_recipients() as $tg) {
        $r = bot_send($tg, $text);
        if ($r && !empty($r['ok'])) {
            $sent++;
        } else {
            $failed++;
        }
        usleep(40000); // ~25 сообщений/сек — в пределах лимита Telegram
    }
    return ['recipients' => $sent + $failed, 'sent' => $sent, 'failed' => $failed];
}
