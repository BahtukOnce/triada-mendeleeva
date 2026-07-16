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

// Фото с подписью (multipart через CURLFile — bot_api передаёт массив как есть)
function bot_send_photo($chatId, string $file, string $caption, ?string $markup = null): ?array
{
    $p = [
        'chat_id'    => $chatId,
        'photo'      => new CURLFile($file, 'image/png', 'card.png'),
        'caption'    => $caption,
        'parse_mode' => 'HTML',
    ];
    if ($markup !== null) {
        $p['reply_markup'] = $markup;
    }
    return bot_api('sendPhoto', $p);
}

// ── Утилиты ───────────────────────────────────────────────
function bot_esc(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

// Вшить скрытые ссылки Telegram (text_link) прямо в текст как markdown [текст](url),
// чтобы на сайте они рендерились гиперссылками по тексту. offset/length у Telegram —
// в кодовых единицах UTF-16, поэтому режем через UTF-16LE (эмодзи считаются как 2).
function tg_entities_md(string $text, $entities): string
{
    if (!is_array($entities) || $entities === []) {
        return $text;
    }
    $links = [];
    foreach ($entities as $e) {
        if (($e['type'] ?? '') === 'text_link' && !empty($e['url'])) {
            $links[] = ['off' => (int)($e['offset'] ?? 0), 'len' => (int)($e['length'] ?? 0), 'url' => (string)$e['url']];
        }
    }
    if (!$links) {
        return $text;
    }
    usort($links, fn($a, $b) => $a['off'] <=> $b['off']);
    $u16 = mb_convert_encoding($text, 'UTF-16LE', 'UTF-8');
    $slice = function (int $start, ?int $length) use ($u16): string {
        $bytes = $length === null ? substr($u16, $start * 2) : substr($u16, $start * 2, $length * 2);
        return mb_convert_encoding($bytes === false ? '' : $bytes, 'UTF-8', 'UTF-16LE');
    };
    $out = '';
    $cursor = 0;
    foreach ($links as $l) {
        if ($l['off'] < $cursor || $l['len'] <= 0) {
            continue; // перекрытие или пустой — пропускаем
        }
        $out .= $slice($cursor, $l['off'] - $cursor);
        $disp = $slice($l['off'], $l['len']);
        $out .= '[' . $disp . '](' . $l['url'] . ')';
        $cursor = $l['off'] + $l['len'];
    }
    $out .= $slice($cursor, null);
    return $out;
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
        if (in_array((string)$st->fetchColumn(), ['admin', 'deputy', 'owner'], true)) {
            return true;
        }
    }
    return false;
}

// ── Аккаунт на сайте и сброс пароля через бота ────────────
// Аккаунт сайта (логин = ник), к которому привязан игрок. null — если аккаунта нет.
function bot_site_account(array $player): ?array
{
    $uid = (int)($player['user_id'] ?? 0);
    if ($uid < 1) {
        return null;
    }
    $st = db()->prepare('SELECT id, nickname FROM users WHERE id = ? LIMIT 1');
    $st->execute([$uid]);
    return $st->fetch() ?: null;
}

// Сбросить пароль аккаунта на временный (8 симв.), вернуть его. Безопасно: вызывается
// только для аккаунта привязанного к боту игрока — личность подтверждает Telegram.
function bot_reset_password(int $userId): string
{
    $alphabet = 'abcdefghkmnpqrstuvwxyz23456789';
    $temp = '';
    for ($i = 0; $i < 8; $i++) {
        $temp .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
        ->execute([password_hash($temp, PASSWORD_DEFAULT), $userId]);
    try {
        db()->prepare('INSERT INTO logs (user_id, action, details, ip) VALUES (?,?,?,NULL)')
            ->execute([$userId, 'password_reset_bot', json_encode(['via' => 'bot'], JSON_UNESCAPED_UNICODE)]);
    } catch (Throwable $e) {
    }
    return $temp;
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
        WHERE u.is_judge = 1 OR u.role IN ('admin','deputy','owner')")->fetchAll(PDO::FETCH_COLUMN);
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
    $red = $bestBy($cands, fn($r) => $r['roles']['mir']['wins'], fn($r) => $r['roles']['mir']['games'], 10);
    if ($red) {
        $noms[] = ['title' => 'Лучший красный', 'name' => $red['name']];
    }
    $black = $bestBy($cands, fn($r) => $r['roles']['maf']['wins'], fn($r) => $r['roles']['maf']['games'], 8);
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

// Ники записавшихся на вечер (в порядке записи)
function bot_day_names(int $dayId): array
{
    $st = db()->prepare('SELECT p.nickname FROM day_registrations r
        JOIN players p ON p.id = r.player_id
        WHERE r.day_id = ? AND r.cancelled_at IS NULL ORDER BY r.id');
    $st->execute([$dayId]);
    return array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN));
}

// ── Опрос «Когда играем?»: выбор игрового дня недели (мультивыбор) ──
function day_poll_active(): ?array
{
    $p = db()->query("SELECT * FROM day_polls WHERE status = 'open' ORDER BY id DESC LIMIT 1")->fetch();
    if (!$p) {
        return null;
    }
    $o = db()->prepare('SELECT o.id, o.date, COUNT(v.id) AS votes
        FROM day_poll_options o LEFT JOIN day_poll_votes v ON v.option_id = o.id
        WHERE o.poll_id = ? GROUP BY o.id, o.date ORDER BY o.date');
    $o->execute([(int)$p['id']]);
    $p['options'] = $o->fetchAll();
    return $p;
}

// Тоггл голоса; вернуть true, если после операции голос стоит.
// Каждое действие пишется в журнал day_poll_log (кто/когда проголосовал или передумал).
function day_poll_vote_toggle(int $optionId, int $playerId): bool
{
    $pq = db()->prepare('SELECT poll_id FROM day_poll_options WHERE id = ?');
    $pq->execute([$optionId]);
    $pollId = (int)($pq->fetchColumn() ?: 0);
    $log = function (string $action) use ($pollId, $optionId, $playerId): void {
        try {
            db()->prepare('INSERT INTO day_poll_log (poll_id, option_id, player_id, action) VALUES (?,?,?,?)')
                ->execute([$pollId, $optionId, $playerId, $action]);
        } catch (Throwable $e) {
        }
    };
    $st = db()->prepare('SELECT id FROM day_poll_votes WHERE option_id = ? AND player_id = ?');
    $st->execute([$optionId, $playerId]);
    $id = $st->fetchColumn();
    if ($id) {
        db()->prepare('DELETE FROM day_poll_votes WHERE id = ?')->execute([(int)$id]);
        $log('unvote');
        return false;
    }
    db()->prepare('INSERT IGNORE INTO day_poll_votes (option_id, player_id) VALUES (?,?)')
        ->execute([$optionId, $playerId]);
    $log('vote');
    return true;
}

function day_poll_weekday(string $ymd): string
{
    $wd = ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'];
    $t = strtotime($ymd);
    return $t ? $wd[(int)date('N', $t) - 1] : '';
}

// Текст + inline-клавиатура опроса для бота ($playerId — чьи галочки показать)
function day_poll_view(array $poll, int $playerId): array
{
    $mine = [];
    if ($playerId > 0 && $poll['options']) {
        $ids = array_column($poll['options'], 'id');
        $in = implode(',', array_fill(0, count($ids), '?'));
        $q = db()->prepare("SELECT option_id FROM day_poll_votes WHERE player_id = ? AND option_id IN ($in)");
        $q->execute(array_merge([$playerId], $ids));
        $mine = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
    }
    $t = "🗳 <b>" . bot_esc((string)$poll['title']) . "</b>\n\n"
        . "Отметьте все дни, в которые сможете прийти поиграть — можно несколько. "
        . "Повторное нажатие снимает галочку.";
    $rows = [];
    foreach ($poll['options'] as $o) {
        $sel = in_array((int)$o['id'], $mine, true);
        $label = ($sel ? '✅ ' : '') . day_poll_weekday((string)$o['date']) . ' ' . date('d.m', strtotime((string)$o['date']))
            . ' · ' . (int)$o['votes'];
        $rows[] = [['text' => $label, 'callback_data' => 'dpoll:' . (int)$o['id']]];
    }
    $rows[] = [['text' => '◀ Меню', 'callback_data' => 'menu']];
    return [$t, json_encode(['inline_keyboard' => $rows], JSON_UNESCAPED_UNICODE)];
}

// Разослать опрос всем подписанным (личка бота). Возвращает число доставленных.
function bot_broadcast_day_poll(int $pollId): int
{
    $p = db()->prepare('SELECT * FROM day_polls WHERE id = ?');
    $p->execute([$pollId]);
    $poll = $p->fetch();
    if (!$poll || $poll['status'] !== 'open') {
        return 0;
    }
    $o = db()->prepare('SELECT o.id, o.date, COUNT(v.id) AS votes
        FROM day_poll_options o LEFT JOIN day_poll_votes v ON v.option_id = o.id
        WHERE o.poll_id = ? GROUP BY o.id, o.date ORDER BY o.date');
    $o->execute([$pollId]);
    $poll['options'] = $o->fetchAll();
    $sent = 0;
    foreach (bot_recipients() as $tg) {
        $pl = bot_player_by_tg((int)$tg);
        [$t, $m] = day_poll_view($poll, $pl ? (int)$pl['id'] : 0);
        $r = bot_send((int)$tg, $t, $m);
        if ($r && !empty($r['ok'])) {
            $sent++;
        }
        usleep(40000);
    }
    return $sent;
}

// ── «Готовый стол»: правило клуба — вечер состоится, если 12+ человек
//    одновременно доступны 4+ часа подряд (запись без времени = весь вечер) ──
const DAY_TABLE_NEED = 12;
const DAY_TABLE_MIN_HOURS = 4;

// Активные записи вечера с интервалами доступности
function bot_day_regs(int $dayId): array
{
    $st = db()->prepare('SELECT p.nickname, r.time_from, r.time_to FROM day_registrations r
        JOIN players p ON p.id = r.player_id
        WHERE r.day_id = ? AND r.cancelled_at IS NULL ORDER BY r.id');
    $st->execute([$dayId]);
    return $st->fetchAll();
}

// Самое длинное окно, где одновременно доступно >= $need игроков.
// null-времена = «весь вечер» (00:00–23:59). Возвращает from/to «H:MM», minutes, ok.
function day_table_window(array $regs, int $need = DAY_TABLE_NEED, int $minHours = DAY_TABLE_MIN_HOURS): array
{
    $toMin = function (?string $t, int $def): int {
        if (!$t || !preg_match('/^(\d{1,2}):(\d{2})/', (string)$t, $m)) {
            return $def;
        }
        return min(1439, max(0, (int)$m[1] * 60 + (int)$m[2]));
    };
    $ivals = [];
    foreach ($regs as $r) {
        $f = $toMin($r['time_from'] ?? null, 0);
        $t = $toMin($r['time_to'] ?? null, 1439);
        if ($t > $f) {
            $ivals[] = [$f, $t];
        }
    }
    $res = ['total' => count($ivals), 'need' => $need, 'from' => null, 'to' => null,
        'minutes' => 0, 'ok' => false, 'all_evening' => false];
    if (count($ivals) < $need) {
        return $res;
    }
    $bounds = [];
    foreach ($ivals as [$f, $t]) {
        $bounds[$f] = 1;
        $bounds[$t] = 1;
    }
    $bounds = array_keys($bounds);
    sort($bounds);
    // склеиваем подряд идущие сегменты, где стол набирается, ищем самое длинное окно
    $bestF = null; $bestT = null; $runF = null; $runT = null;
    for ($i = 0, $n = count($bounds) - 1; $i < $n; $i++) {
        $a = $bounds[$i];
        $b = $bounds[$i + 1];
        $cnt = 0;
        foreach ($ivals as [$f, $t]) {
            if ($f <= $a && $t >= $b) {
                $cnt++;
            }
        }
        if ($cnt >= $need) {
            $runF = $runF ?? $a;
            $runT = $b;
            if ($bestF === null || ($runT - $runF) > ($bestT - $bestF)) {
                $bestF = $runF;
                $bestT = $runT;
            }
        } else {
            $runF = null;
            $runT = null;
        }
    }
    if ($bestF !== null) {
        $fmt = fn(int $m): string => sprintf('%d:%02d', intdiv($m, 60), $m % 60);
        $res['from'] = $fmt($bestF);
        $res['to'] = $fmt($bestT);
        $res['minutes'] = $bestT - $bestF;
        $res['ok'] = ($bestT - $bestF) >= $minHours * 60;
        $res['all_evening'] = ($bestF === 0 && $bestT === 1439);
    }
    return $res;
}

// Строка-вердикт о «готовом столе» (без HTML — годится и для бота, и для сайта)
function day_table_verdict(int $dayId): string
{
    $w = day_table_window(bot_day_regs($dayId));
    if ($w['total'] === 0) {
        return '';
    }
    if ($w['from'] === null) {
        return '🎲 Стол ' . $w['need'] . '+: пока не набирается (записались ' . $w['total'] . ')';
    }
    $span = $w['all_evening'] ? 'весь вечер' : ($w['from'] . '–' . $w['to']);
    $hrs = rtrim(rtrim(number_format($w['minutes'] / 60, 1, '.', ''), '0'), '.');
    return $w['ok']
        ? '🎲 Стол ' . $w['need'] . '+ собирается: ' . $span . ' (' . $hrs . ' ч) — вечер состоится ✅'
        : '🎲 Стол ' . $w['need'] . '+: ' . $span . ' (' . $hrs . ' ч) — для вечера нужно ' . DAY_TABLE_MIN_HOURS . '+ часа ⏳';
}

// «Стол собрался»: при первом достижении 12+/4ч — разовое уведомление записавшимся.
// Флаг table_ready_at ставится атомарно (WHERE IS NULL) — двойной рассылки при гонке не будет.
function bot_check_table_ready(int $dayId): void
{
    $st = db()->prepare('SELECT id, title, date, location, status, table_ready_at FROM game_days WHERE id = ?');
    $st->execute([$dayId]);
    $day = $st->fetch();
    if (!$day || $day['table_ready_at'] !== null || !in_array($day['status'], ['reg_open', 'reg_closed'], true)) {
        return;
    }
    $w = day_table_window(bot_day_regs($dayId));
    if (!$w['ok']) {
        return;
    }
    $upd = db()->prepare('UPDATE game_days SET table_ready_at = NOW() WHERE id = ? AND table_ready_at IS NULL');
    $upd->execute([$dayId]);
    if ($upd->rowCount() === 0) {
        return; // параллельный запрос уже разослал
    }
    $span = $w['all_evening'] ? 'весь вечер' : ($w['from'] . '–' . $w['to']);
    $txt = "🎲 <b>Стол собрался — вечер состоится!</b>\n\n"
        . "<b>" . bot_esc((string)$day['title']) . "</b>\n"
        . "🗓 " . bot_date((string)$day['date']) . "\n"
        . ($day['location'] ? "📍 " . bot_esc((string)$day['location']) . "\n" : "")
        . "👥 " . DAY_TABLE_NEED . "+ игроков одновременно: " . $span . "\n\nДо встречи за столом!";
    $pl = db()->prepare('SELECT player_id FROM day_registrations WHERE day_id = ? AND cancelled_at IS NULL');
    $pl->execute([$dayId]);
    foreach ($pl->fetchAll(PDO::FETCH_COLUMN) as $pid) {
        bot_notify_player((int)$pid, $txt);
        usleep(40000);
    }
}

// Уведомить админов/руководителей (привязанных к боту) о голосе/переголосе записи
function bot_notify_admins_day_vote(int $dayId, string $nick, string $action, ?string $tf = null, ?string $tt = null): void
{
    $st = db()->prepare('SELECT title FROM game_days WHERE id = ?');
    $st->execute([$dayId]);
    $title = (string)($st->fetchColumn() ?: 'вечер');
    $when = ($tf || $tt)
        ? substr((string)$tf, 0, 5) . '–' . substr((string)$tt, 0, 5)
        : 'весь вечер';
    [$icon, $verb] = match ($action) {
        'cancel' => ['❌', 'отписался(-ась)'],
        'time'   => ['⏰', 'уточнил(а) время: ' . $when],
        default  => ['✅', 'записался(-ась) · ' . $when],
    };
    $txt = "🗳 <b>Запись: «" . bot_esc($title) . "»</b>\n"
        . $icon . ' <b>' . bot_esc($nick) . '</b> ' . $verb
        . "\n👥 Сейчас: <b>" . bot_day_count($dayId) . "</b>";
    $vd = day_table_verdict($dayId);
    if ($vd !== '') {
        $txt .= "\n" . $vd;
    }
    $q = db()->query("SELECT p.tg_user_id FROM players p JOIN users u ON u.id = p.user_id
        WHERE u.role IN ('admin','deputy','owner') AND p.tg_user_id IS NOT NULL");
    foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $tg) {
        bot_send($tg, $txt, null);
        usleep(30000);
    }
    // Заодно: если после этого голоса стол впервые собрался — разовое уведомление записавшимся
    bot_check_table_ready($dayId);
}

// ── Уведомления: вкл/выкл (по умолчанию включены) ─────────
function bot_notify_enabled(int $tgId): bool
{
    try {
        $st = db()->prepare('SELECT notify_enabled FROM players WHERE tg_user_id = ? LIMIT 1');
        $st->execute([$tgId]);
        $v = $st->fetchColumn();
        return $v === false ? true : (bool)(int)$v; // нет записи → считаем включёнными
    } catch (Throwable $e) {
        return true;
    }
}

function bot_set_notify(int $tgId, bool $on): void
{
    db()->prepare('UPDATE players SET notify_enabled = ? WHERE tg_user_id = ?')->execute([$on ? 1 : 0, $tgId]);
}

// Отправить уведомление игроку по player_id, уважая его настройку и привязку
function bot_notify_player(int $playerId, string $text, ?string $markup = null): bool
{
    $st = db()->prepare('SELECT tg_user_id FROM players WHERE id = ? AND tg_user_id IS NOT NULL AND notify_enabled = 1');
    $st->execute([$playerId]);
    $tg = $st->fetchColumn();
    if (!$tg) {
        return false;
    }
    $r = bot_send((int)$tg, $text, $markup);
    return $r && !empty($r['ok']);
}

// ── Рассылка ──────────────────────────────────────────────
// $includeMuted = true → включая отключивших уведомления (важные объявления)
function bot_recipients(bool $includeMuted = false): array
{
    $sql = 'SELECT tg_user_id FROM players WHERE tg_user_id IS NOT NULL'
        . ($includeMuted ? '' : ' AND notify_enabled = 1');
    return array_map('intval', db()->query($sql)->fetchAll(PDO::FETCH_COLUMN));
}

function bot_broadcast(string $text, bool $important = false): array
{
    $sent = 0;
    $failed = 0;
    foreach (bot_recipients($important) as $tg) {
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

// ── Авто-уведомления о вечерах и результатах ──────────────
// Открылась запись на вечер → анонс всем подписанным игрокам (с кнопкой записи).
function bot_notify_day_open(int $dayId): int
{
    $st = db()->prepare('SELECT * FROM game_days WHERE id = ?');
    $st->execute([$dayId]);
    $day = $st->fetch();
    if (!$day || $day['status'] !== 'reg_open') {
        return 0;
    }
    $text = "📅 <b>Открыта запись на игровой вечер!</b>\n\n"
        . "<b>" . bot_esc((string)$day['title']) . "</b>\n"
        . "🗓 " . bot_date((string)$day['date']) . "\n"
        . ($day['location'] ? "📍 " . bot_esc((string)$day['location']) . "\n" : "")
        . "\nНажмите «Записаться» 👇 или откройте /day.";
    $markup = json_encode(['inline_keyboard' => [
        [['text' => '✅ Записаться', 'callback_data' => 'day_reg:' . (int)$day['id']]],
    ]], JSON_UNESCAPED_UNICODE);
    $sent = 0;
    foreach (bot_recipients() as $tg) {
        $r = bot_send($tg, $text, $markup);
        if ($r && !empty($r['ok'])) {
            $sent++;
        }
        usleep(40000);
    }
    return $sent;
}

// Вечер завершён → каждому участнику личный итог: сыграно игр + изменение ELO (+ рекорд).
function bot_notify_day_results(int $dayId): int
{
    $st = db()->prepare('SELECT * FROM game_days WHERE id = ?');
    $st->execute([$dayId]);
    $day = $st->fetch();
    if (!$day) {
        return 0;
    }
    $q = db()->prepare('SELECT eh.player_id, COUNT(*) AS games, SUM(eh.delta) AS net,
            p.elo AS cur, MAX(eh.elo_after) AS day_peak,
            (SELECT MAX(e2.elo_after) FROM elo_history e2 WHERE e2.player_id = eh.player_id) AS all_peak
        FROM elo_history eh
        JOIN games g ON g.id = eh.game_id
        JOIN players p ON p.id = eh.player_id
        WHERE g.day_id = ?
        GROUP BY eh.player_id, p.elo');
    $q->execute([$dayId]);
    $rows = $q->fetchAll();
    // Роли и победы каждого за вечер + лучший ELO вечера (для шэрибл-карточки)
    $roleRu = ['civ' => 'Мирный', 'sheriff' => 'Шериф', 'maf' => 'Мафия', 'don' => 'Дон'];
    $rolesBy = [];
    $winsBy = [];
    try {
        $rq = db()->prepare("SELECT gs.player_id, gs.role, COUNT(*) c,
                SUM(CASE WHEN (g.winner = 'red' AND gs.role IN ('civ','sheriff'))
                          OR (g.winner = 'black' AND gs.role IN ('maf','don')) THEN 1 ELSE 0 END) w
            FROM game_seats gs JOIN games g ON g.id = gs.game_id
            WHERE g.day_id = ? AND g.status = 'finished'
            GROUP BY gs.player_id, gs.role");
        $rq->execute([$dayId]);
        foreach ($rq->fetchAll() as $rr) {
            $pid0 = (int)$rr['player_id'];
            $rolesBy[$pid0][$roleRu[$rr['role']] ?? $rr['role']] = (int)$rr['c'];
            $winsBy[$pid0] = ($winsBy[$pid0] ?? 0) + (int)$rr['w'];
        }
    } catch (Throwable $e) {
    }
    $bestPid = 0;
    $bestNet = null;
    foreach ($rows as $r) {
        if ($bestNet === null || (float)$r['net'] > $bestNet) {
            $bestNet = (float)$r['net'];
            $bestPid = (int)$r['player_id'];
        }
    }
    require_once __DIR__ . '/day_card.php';
    $avaSt = db()->prepare('SELECT nickname, avatar FROM players WHERE id = ?');
    $sent = 0;
    foreach ($rows as $r) {
        $pid = (int)$r['player_id'];
        $net = (float)$r['net'];
        $netStr = ($net > 0 ? '+' : ($net < 0 ? '−' : '±')) . bot_num(abs($net));
        $emoji = $net > 0 ? '📈' : ($net < 0 ? '📉' : '➖');
        $record = ((float)$r['day_peak'] >= (float)$r['all_peak'] - 0.05) && $net > 0;
        $isTop = $pid === $bestPid && $net > 0;
        $text = "🎲 <b>Итоги вечера</b>\n"
            . "<b>" . bot_esc((string)$day['title']) . "</b> · " . bot_date((string)$day['date']) . "\n\n"
            . "Сыграно игр: <b>" . (int)$r['games'] . "</b>\n"
            . "$emoji ELO: <b>" . bot_num((float)$r['cur']) . "</b> (за вечер $netStr)\n"
            . ($record ? "🏆 Новый личный рекорд ELO!\n" : "")
            . ($isTop ? "🔥 Лучший ELO вечера!\n" : "")
            . "\nПодробная статистика — /me";
        // Личка (уважает mute): фото-карточка с подписью; при сбое — обычный текст
        $tgSt = db()->prepare('SELECT tg_user_id FROM players WHERE id = ? AND tg_user_id IS NOT NULL AND notify_enabled = 1');
        $tgSt->execute([$pid]);
        $tg = $tgSt->fetchColumn();
        if (!$tg) {
            continue;
        }
        $okSent = false;
        try {
            $avaSt->execute([$pid]);
            $pl = $avaSt->fetch() ?: ['nickname' => '?', 'avatar' => null];
            $card = day_card_png([
                'nickname' => (string)$pl['nickname'],
                'avatar' => $pl['avatar'] ?? null,
                'day_title' => (string)$day['title'],
                'day_date' => bot_date((string)$day['date']),
                'games' => (int)$r['games'],
                'wins' => (int)($winsBy[$pid] ?? 0),
                'roles' => $rolesBy[$pid] ?? [],
                'net' => $net,
                'elo' => (float)$r['cur'],
                'record' => $record,
                'top' => $isTop,
            ]);
            if ($card !== null) {
                $resp = bot_send_photo((int)$tg, $card, $text);
                $okSent = $resp && !empty($resp['ok']);
                @unlink($card);
            }
        } catch (Throwable $e) {
        }
        if (!$okSent) {
            $resp = bot_send((int)$tg, $text);
            $okSent = $resp && !empty($resp['ok']);
        }
        if ($okSent) {
            $sent++;
        }
        usleep(40000);
    }
    return $sent;
}

// ── Приглашение на турнир (личное, с кнопками Приду/Не смогу) ──
function bot_tournament_invite(int $tid, int $playerId): bool
{
    $st = db()->prepare('SELECT tg_user_id FROM players WHERE id = ? AND tg_user_id IS NOT NULL');
    $st->execute([$playerId]);
    $tg = $st->fetchColumn();
    if (!$tg) {
        return false;
    }
    $t = db()->prepare('SELECT title, date_from, location, tables_count, dress_code FROM tournaments WHERE id = ?');
    $t->execute([$tid]);
    $tr = $t->fetch();
    if (!$tr) {
        return false;
    }
    $cc = db()->prepare("SELECT COUNT(*) FROM tournament_participants WHERE tournament_id = ? AND state = 'confirmed'");
    $cc->execute([$tid]);
    $confirmed = (int)$cc->fetchColumn();
    $base = rtrim((string)($GLOBALS['cfg']['base_url'] ?? 'https://triada-mendeleeva.ru'), '/');
    $url = $base . '/tournament.php?id=' . $tid;
    $text = "🎟 <b>Приглашение на турнир</b>\n\n"
        . "<b>" . bot_esc((string)$tr['title']) . "</b>\n"
        . ($tr['date_from'] ? "🗓 " . bot_date((string)$tr['date_from']) . "\n" : "")
        . ($tr['location'] ? "📍 " . bot_esc((string)$tr['location']) . "\n" : "")
        . ($tr['tables_count'] ? "🎲 Столов: " . (int)$tr['tables_count'] . "\n" : "")
        . (!empty($tr['dress_code']) ? "👔 Дресс-код: " . bot_esc((string)$tr['dress_code']) . "\n" : "")
        . ($confirmed > 0 ? "👥 Уже в составе: " . $confirmed . "\n" : "")
        . "\n🔗 <a href=\"" . $url . "\">Страница турнира</a>\n"
        . "\nСможете прийти?";
    $markup = json_encode(['inline_keyboard' => [
        [
            ['text' => '✅ Приду', 'callback_data' => 'tinv_yes:' . $tid],
            ['text' => '❌ Не смогу', 'callback_data' => 'tinv_no:' . $tid],
        ],
        [['text' => '🔗 Страница турнира', 'url' => $url]],
    ]], JSON_UNESCAPED_UNICODE);
    $r = bot_send((int)$tg, $text, $markup);
    $ok = $r && !empty($r['ok']);
    if ($ok) {
        db()->prepare('UPDATE tournament_participants SET notified = 1 WHERE tournament_id = ? AND player_id = ?')
            ->execute([$tid, $playerId]);
    }
    return $ok;
}

// Дослать игроку все неотправленные приглашения на турниры (после привязки к боту)
function bot_deliver_pending_invites(int $playerId): void
{
    try {
        $st = db()->prepare("SELECT tp.tournament_id FROM tournament_participants tp
            JOIN tournaments t ON t.id = tp.tournament_id
            WHERE tp.player_id = ? AND tp.state = 'invited' AND tp.notified = 0 AND t.status <> 'finished'");
        $st->execute([$playerId]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $tid) {
            bot_tournament_invite((int)$tid, $playerId); // отметит notified=1 при успешной отправке
        }
    } catch (Throwable $e) {
    }
}

// Уведомить судью о назначении на турнир (это назначение, без кнопок)
function bot_tournament_judge_notify(int $tid, int $playerId, int $tableNo): bool
{
    $st = db()->prepare('SELECT tg_user_id FROM players WHERE id = ? AND tg_user_id IS NOT NULL');
    $st->execute([$playerId]);
    $tg = $st->fetchColumn();
    if (!$tg) {
        return false;
    }
    $t = db()->prepare('SELECT title, date_from, location, tables_count, dress_code FROM tournaments WHERE id = ?');
    $t->execute([$tid]);
    $tr = $t->fetch();
    if (!$tr) {
        return false;
    }
    $role = $tableNo === 1 ? 'главный судья (стол 1)' : ('судья стола ' . $tableNo);
    $text = "⚖ <b>Вас назначили судьёй на турнир</b>\n\n"
        . "<b>" . bot_esc((string)$tr['title']) . "</b>\n"
        . "Роль: <b>" . $role . "</b>\n"
        . ($tr['date_from'] ? "🗓 " . bot_date((string)$tr['date_from']) . "\n" : "")
        . ($tr['location'] ? "📍 " . bot_esc((string)$tr['location']) . "\n" : "")
        . ($tr['tables_count'] ? "🎲 Столов: " . (int)$tr['tables_count'] . "\n" : "")
        . (!empty($tr['dress_code']) ? "👔 Дресс-код: " . bot_esc((string)$tr['dress_code']) . "\n" : "");
    $base = rtrim((string)($GLOBALS['cfg']['base_url'] ?? 'https://triada-mendeleeva.ru'), '/');
    $url = $base . '/tournament.php?id=' . $tid;
    $text .= "\n🔗 <a href=\"" . $url . "\">Страница турнира</a>";
    $markup = json_encode(['inline_keyboard' => [
        [['text' => '🔗 Страница турнира', 'url' => $url]],
    ]], JSON_UNESCAPED_UNICODE);
    $r = bot_send((int)$tg, $text, $markup);
    return $r && !empty($r['ok']);
}
