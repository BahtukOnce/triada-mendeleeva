<?php
declare(strict_types=1);
/**
 * Триада Менделеева — Telegram-бот статистики и записи на игру (webhook).
 * Регистрация по нику обязательна (привязка tg → игрок в БД). Навигация — inline-кнопки.
 * Токен и секрет вебхука берутся из config.php (вне git). Вебхук ставится через setup_webhook.php.
 */

define('ROOT', dirname(__DIR__));
$cfgFile = ROOT . '/config.php';
if (!is_file($cfgFile)) {
    http_response_code(503);
    exit('config missing');
}
$GLOBALS['cfg'] = require $cfgFile;
require ROOT . '/inc/db.php';
require ROOT . '/inc/bot_lib.php';

// ── Проверка секрета вебхука ──────────────────────────────
$secret = (string)($GLOBALS['cfg']['bot_secret'] ?? '');
$hdr = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
if ($secret !== '' && !hash_equals($secret, (string)$hdr)) {
    http_response_code(403);
    exit('forbidden');
}

$raw = file_get_contents('php://input');
$update = json_decode((string)$raw, true);
if (!$update) {
    http_response_code(200);
    exit('ok');
}

if (isset($update['callback_query'])) {
    try {
        handle_callback($update['callback_query']);
    } catch (Throwable $e) {
    }
    http_response_code(200);
    exit('ok');
}

// Пост новостного канала → раздел «Новости»
if (isset($update['channel_post']) || isset($update['edited_channel_post'])) {
    try {
        bot_news_from_channel($update['channel_post'] ?? $update['edited_channel_post']);
    } catch (Throwable $e) {
    }
    http_response_code(200);
    exit('ok');
}

$msg = $update['message'] ?? $update['edited_message'] ?? null;
if (!$msg || !isset($msg['chat']['id'])) {
    http_response_code(200);
    exit('ok');
}

$chatId = $msg['chat']['id'];
$userId = (int)($msg['from']['id'] ?? $chatId);
$from   = $msg['from'] ?? null;
$text   = trim((string)($msg['text'] ?? ''));

try {
    handle_message($chatId, $userId, $text, $from);
} catch (Throwable $e) {
    reply($chatId, '⚠ Не получилось получить данные. Попробуй позже.');
}

http_response_code(200);
echo 'ok';

// Импорт поста новостного канала в раздел «Новости» (по @username или id из config)
function bot_news_from_channel(array $post): void
{
    // Канал берётся из config (news_channel_id), по умолчанию — публичный @triada_mendeleeva
    $want = ltrim((string)($GLOBALS['cfg']['news_channel_id'] ?? 'triada_mendeleeva'), '@');
    if ($want === '') {
        return; // автоимпорт выключен (явно пустой в конфиге)
    }
    $chatId   = (string)($post['chat']['id'] ?? '');
    $chatUser = ltrim((string)($post['chat']['username'] ?? ''), '@');
    if ($want !== $chatId && strcasecmp($want, $chatUser) !== 0) {
        return; // пост из другого чата
    }
    $text  = trim((string)($post['text'] ?? ($post['caption'] ?? '')));
    $msgId = (int)($post['message_id'] ?? 0);
    if ($text === '' || !$msgId) {
        return; // нет текста или id — пропускаем
    }
    $firstLine = trim((string)strtok($text, "\n"));
    $title = mb_substr($firstLine !== '' ? $firstLine : $text, 0, 200);
    $ts = isset($post['date']) ? date('Y-m-d H:i:s', (int)$post['date']) : date('Y-m-d H:i:s');
    db()->prepare('INSERT INTO news (title, body, published_at, tg_msg_id)
            VALUES (?,?,?,?)
            ON DUPLICATE KEY UPDATE title = VALUES(title), body = VALUES(body)')
        ->execute([$title, $text, $ts, $msgId]);
}

// ============================================================
//                      СООБЩЕНИЯ
// ============================================================
function handle_message($chatId, int $userId, string $text, ?array $from): void
{
    bot_touch($userId, $from);
    $registered = bot_player_by_tg($userId) !== null;

    $parts = preg_split('/\s+/', $text, 2);
    $cmd   = preg_replace('/@.*/', '', mb_strtolower($parts[0] ?? ''));
    $arg   = isset($parts[1]) ? trim($parts[1]) : '';
    $isCmd = ($text !== '' && $text[0] === '/');

    if (!$registered) {
        if ($cmd === '/start' || $cmd === '/help') {
            reply($chatId, welcome_text());
            return;
        }
        if ($isCmd && $cmd !== '/reg' && $cmd !== '/register') {
            reply($chatId, "🔒 Чтобы пользоваться ботом, сначала привяжись.\nВведи свой ник, как он записан в таблице рейтинга — просто отправь его сообщением.");
            return;
        }
        $nick = ($cmd === '/reg' || $cmd === '/register') ? $arg : $text;
        do_register($chatId, $userId, $nick, $from);
        return;
    }

    switch ($cmd) {
        case '/start':
        case '/help':
            send_menu($chatId, $userId, help_text());
            return;
        case '/me':
        case '/my':
            send_menu($chatId, $userId, me_text($userId));
            return;
        case '/day':
        case '/zapis':
            [$t, $m] = day_view($userId);
            send($chatId, $t, $m);
            return;
        case '/top':
            send_menu($chatId, $userId, top_text($arg !== '' ? (int)$arg : 10));
            return;
        case '/nom':
        case '/noms':
            send_menu($chatId, $userId, nominations_text());
            return;
        case '/judges':
        case '/sud':
            [$jt, $jm] = judges_view();
            send($chatId, $jt, $jm);
            return;
        case '/stats':
        case '/stat':
            send_menu($chatId, $userId, $arg === '' ? 'Укажи имя: <code>/stats Бант.</code>' : stats_text($arg));
            return;
        case '/reg':
        case '/register':
            do_register($chatId, $userId, $arg, $from);
            return;
        case '/unreg':
            bot_unlink($userId);
            reply($chatId, "Привязка снята. Введи свой ник (как в таблице), чтобы снова пользоваться ботом.");
            return;
    }

    if ($isCmd) {
        send_menu($chatId, $userId, 'Неизвестная команда. ' . help_text());
        return;
    }

    send_menu($chatId, $userId, stats_text($text));
}

// ============================================================
//                      INLINE-КНОПКИ
// ============================================================
function handle_callback(array $cb): void
{
    $cbId   = $cb['id'] ?? '';
    $data   = $cb['data'] ?? '';
    $userId = (int)($cb['from']['id'] ?? 0);
    $chatId = $cb['message']['chat']['id'] ?? null;
    $msgId  = $cb['message']['message_id'] ?? null;

    answer_cb($cbId);
    if ($chatId === null || $msgId === null) {
        return;
    }
    bot_touch($userId, $cb['from'] ?? null);

    if (!bot_player_by_tg($userId)) {
        edit_msg($chatId, $msgId, "Сначала привяжи свой ник — введи его, как в таблице рейтинга, одним сообщением.");
        return;
    }

    if ($data === 'noop') {
        return;
    }

    // Топ с пагинацией
    if ($data === 'top' || str_starts_with($data, 'top:')) {
        $offset = ($data === 'top') ? 0 : (int)substr($data, 4);
        [$t, $m] = top_view($offset);
        edit_text($chatId, $msgId, $t, $m);
        return;
    }

    // Карточка судьи
    if (str_starts_with($data, 'jc:')) {
        $idx = (int)substr($data, 3);
        $judges = bot_stats_data()['judges'] ?? [];
        $name = $judges[$idx] ?? '';
        edit_menu($chatId, $msgId, $userId, $name !== '' ? stats_text($name) : 'Судья не найден.');
        return;
    }

    // Запись/отписка на игровой день
    if (str_starts_with($data, 'day_reg:') || str_starts_with($data, 'day_cancel:')) {
        $p = bot_player_by_tg($userId);
        $dayId = (int)substr($data, (int)strpos($data, ':') + 1);
        $day = bot_day_by_id($dayId);
        if (!$p || !$day || $day['status'] !== 'reg_open') {
            [$t, $m] = day_view($userId);
            edit_text($chatId, $msgId, $t, $m);
            return;
        }
        if (str_starts_with($data, 'day_reg:')) {
            bot_day_register($dayId, (int)$p['id']);
        } else {
            bot_day_cancel($dayId, (int)$p['id']);
        }
        [$t, $m] = day_view($userId);
        edit_text($chatId, $msgId, $t, $m);
        return;
    }

    // Отвязать ник (только админ)
    if (str_starts_with($data, 'unbind:')) {
        if (bot_is_admin($userId)) {
            bot_unlink((int)substr($data, 7));
            [$t, $m] = admin_users_view();
            edit_text($chatId, $msgId, "✅ Ник отвязан.\n\n" . $t, $m);
        } else {
            edit_menu($chatId, $msgId, $userId, help_text());
        }
        return;
    }

    switch ($data) {
        case 'me':
            edit_menu($chatId, $msgId, $userId, me_text($userId));
            break;
        case 'day':
            [$t, $m] = day_view($userId);
            edit_text($chatId, $msgId, $t, $m);
            break;
        case 'nom':
            edit_menu($chatId, $msgId, $userId, nominations_text());
            break;
        case 'find':
            edit_menu($chatId, $msgId, $userId, "🔍 Пришли имя игрока одним сообщением — верну его карточку.");
            break;
        case 'judges':
            [$jt, $jm] = judges_view();
            edit_text($chatId, $msgId, $jt, $jm);
            break;
        case 'admin':
            if (bot_is_admin($userId)) {
                $m = json_encode(['inline_keyboard' => [
                    [['text' => '👥 Участники бота', 'callback_data' => 'admin_users']],
                    [['text' => '◀ Меню', 'callback_data' => 'menu']],
                ]], JSON_UNESCAPED_UNICODE);
                edit_text($chatId, $msgId, "🛠 <b>Админ-панель</b>\nГлавный админ: " . bot_esc((string)($GLOBALS['cfg']['owner_nickname'] ?? '')), $m);
            } else {
                edit_menu($chatId, $msgId, $userId, help_text());
            }
            break;
        case 'admin_users':
            if (bot_is_admin($userId)) {
                [$t, $m] = admin_users_view();
                edit_text($chatId, $msgId, $t, $m);
            } else {
                edit_menu($chatId, $msgId, $userId, help_text());
            }
            break;
        case 'menu':
        default:
            edit_menu($chatId, $msgId, $userId, help_text());
    }
}

// ============================================================
//                      РЕГИСТРАЦИЯ
// ============================================================
function do_register($chatId, int $userId, string $nick, ?array $from): void
{
    $nick = trim($nick);
    if ($nick === '') {
        reply($chatId, "Введи свой ник, как он записан в таблице рейтинга — просто отправь его сообщением (например: <code>Бант.</code>).");
        return;
    }
    $all = bot_all_players();
    $matched = bot_find_player($all, $nick);
    if (!$matched) {
        $sug = bot_suggest_names($all, $nick);
        reply($chatId, "🔍 Ник «" . bot_esc($nick) . "» не найден в базе клуба."
            . ($sug ? "\nМожет быть: " . $sug : "")
            . "\n\nПроверь написание или попроси администратора добавить тебя в клуб.");
        return;
    }
    bot_link($userId, $from, (int)$matched['player_id']);
    send_menu($chatId, $userId, "✅ Готово! Ты привязан к игроку <b>" . bot_esc($matched['name']) . "</b>.\nВыбирай кнопкой 👇");
}

// ============================================================
//                      АДМИН
// ============================================================
function admin_users_view(): array
{
    $rows = db()->query("SELECT tg_user_id, nickname, tg_username FROM players
        WHERE tg_user_id IS NOT NULL ORDER BY tg_linked_at DESC")->fetchAll();
    $total = count($rows);
    $t = "👥 <b>Участники бота</b> ($total)\n\n";
    $btns = [];
    if ($total === 0) {
        $t .= "Пока никто не привязан.";
    } else {
        $i = 0;
        foreach ($rows as $r) {
            $nick = (string)$r['nickname'];
            $tg = $r['tg_username'] ? '@' . $r['tg_username'] : '—';
            $t .= "• <b>" . bot_esc($nick) . "</b> — " . bot_esc($tg) . "\n";
            $btns[] = [['text' => "❌ Отвязать: " . $nick, 'callback_data' => 'unbind:' . (int)$r['tg_user_id']]];
            if (++$i >= 40) {
                $t .= "\n…показаны первые 40.";
                break;
            }
        }
    }
    $btns[] = [['text' => '◀ Назад', 'callback_data' => 'admin']];
    return [$t, json_encode(['inline_keyboard' => $btns], JSON_UNESCAPED_UNICODE)];
}

// ============================================================
//                      ТЕКСТЫ
// ============================================================
function welcome_text(): string
{
    return "🎭 <b>Триада Менделеева</b>\n\n"
        . "Чтобы пользоваться ботом, привяжи свой <b>ник</b> — так, как он записан в таблице рейтинга.\n"
        . "Просто отправь его одним сообщением (например: <code>Бант.</code>).";
}

function help_text(): string
{
    return "🎭 <b>Триада Менделеева</b>\n\n"
        . "Выбирай кнопкой ниже 👇\n"
        . "• <b>📊 Моя статистика</b> — твоя карточка\n"
        . "• <b>📅 Запись на игру</b> — записаться на ближайший вечер\n"
        . "• <b>🏆 Топ</b> — рейтинг (листай кнопками)\n"
        . "• <b>🎖 Номинации</b> — текущие номинации\n"
        . "• <b>⚖ Судьи</b> — судьи клуба (нажми — увидишь статистику)\n"
        . "• <b>🔍 Найти игрока</b> — затем пришли имя";
}

function stats_text(string $query): string
{
    $data = bot_stats_data();
    $p = bot_find_player($data['players'] ?? [], $query);
    if (!$p) {
        $sug = bot_suggest_names($data['players'] ?? [], $query);
        $t = "🔍 Игрок «" . bot_esc($query) . "» не найден.";
        if ($sug) {
            $t .= "\nМожет быть: " . $sug;
        }
        return $t;
    }
    return render_card($p, $data);
}

function me_text(int $userId): string
{
    $player = bot_player_by_tg($userId);
    if (!$player) {
        return "Ты ещё не привязан.\nВведи свой ник, как в таблице рейтинга — просто отправь его сообщением.";
    }
    $data = bot_stats_data();
    $p = bot_find_player($data['players'] ?? [], (string)$player['nickname']);
    if (!$p) {
        return "Твой ник: <b>" . bot_esc((string)$player['nickname']) . "</b>.\nВ рейтинге его пока нет — статистика появится после сыгранных игр.";
    }
    return render_card($p, $data);
}

function judges_view(): array
{
    $j = bot_stats_data()['judges'] ?? [];
    if (!$j) {
        return ["⚖ <b>Судьи клуба</b>\n\nСписок пока пуст.",
            json_encode(['inline_keyboard' => [[['text' => '◀ Меню', 'callback_data' => 'menu']]]], JSON_UNESCAPED_UNICODE)];
    }
    $t = "⚖ <b>Судьи клуба</b>\n\nНажми на судью — покажу его статистику:";
    $rows = [];
    $i = 0;
    foreach ($j as $name) {
        $rows[] = [['text' => (string)$name, 'callback_data' => 'jc:' . $i]];
        $i++;
    }
    $rows[] = [['text' => '◀ Меню', 'callback_data' => 'menu']];
    return [$t, json_encode(['inline_keyboard' => $rows], JSON_UNESCAPED_UNICODE)];
}

function render_card(array $p, array $data): string
{
    $name = bot_esc($p['name']);
    $rank = (int)$p['rank'];
    $medal = $rank === 1 ? '🥇' : ($rank === 2 ? '🥈' : ($rank === 3 ? '🥉' : '🎏'));

    $ov = $p['overall'] ?? ['wins' => 0, 'games' => 0, 'pct' => 0];
    $games = (int)$ov['games'];
    $avgDop = $games ? $p['dopy'] / $games : 0;
    $avgMin = $games ? $p['minus'] / $games : 0;

    $t  = "$medal <b>$name</b> — #$rank в рейтинге\n";
    $t .= "Клубный счёт: <b>" . bot_num($p['rating']) . "</b>  ·  ELO: <b>" . bot_num($p['elo']) . "</b>\n";
    $t .= "Ci: " . bot_num($p['ci']) . "\n";
    if (($p['allowed'] ?? '') !== '' && mb_strtolower((string)$p['allowed']) !== 'да') {
        $t .= "🚫 Допуск к играм: " . bot_esc((string)$p['allowed']) . "\n";
    }
    $t .= "\n🎮 Игр: <b>$games</b>  ·  Побед: <b>{$ov['wins']}</b> (" . (int)$ov['pct'] . "%)\n";
    $t .= "➕ Допы: <b>" . bot_num($p['dopy']) . "</b> (сред. " . bot_num($avgDop) . ")\n";
    $t .= "➖ Минуса: <b>" . bot_num($p['minus']) . "</b> (сред. " . bot_num($avgMin) . ")\n";
    $t .= "🔪 ПУ: " . bot_num($p['pu']) . "  ·  🌟 ЛХ: " . bot_num($p['lh']) . "\n";

    $t .= "\n🎭 <b>По ролям</b>\n";
    $t .= role_line('😐 Мирный', $p['roles']['mir']);
    $t .= role_line('🔪 Мафия', $p['roles']['maf']);
    $t .= role_line('🌟 Шериф', $p['roles']['sher']);
    $t .= role_line('😈 Дон', $p['roles']['don']);

    $awards = [];
    if ($rank <= 3) {
        $awards[] = "$medal $rank-е место в рейтинге";
    }
    foreach (($data['nominations'] ?? []) as $nom) {
        if (bot_same_name((string)$nom['name'], (string)$p['name'])) {
            $awards[] = '🏆 ' . bot_esc((string)$nom['title']);
        }
    }
    foreach (($data['judges'] ?? []) as $jn) {
        if (bot_same_name((string)$jn, (string)$p['name'])) {
            $awards[] = '⚖ Судья клуба';
            break;
        }
    }
    $best = best_role($p['roles']);
    if ($best) {
        $awards[] = "👑 Коронная роль: $best";
    }
    if ($awards) {
        $t .= "\n🏅 <b>Награды</b>\n• " . implode("\n• ", $awards) . "\n";
    }
    return $t;
}

function role_line(string $label, array $f): string
{
    $g = (int)$f['games'];
    if ($g === 0) {
        return "$label: —\n";
    }
    return "$label: <b>{$f['wins']}/{$g}</b> (" . pct_role($f) . "%)\n";
}

function best_role(array $roles): string
{
    $names = ['mir' => 'Мирный', 'maf' => 'Мафия', 'sher' => 'Шериф', 'don' => 'Дон'];
    $best = null;
    $bestPct = -1;
    foreach ($roles as $k => $f) {
        if ((int)$f['games'] < 3) {
            continue;
        }
        $rp = pct_role($f);
        if ($rp > $bestPct) {
            $bestPct = $rp;
            $best = $names[$k] ?? '';
        }
    }
    return $best ? "$best ({$bestPct}%)" : '';
}

function pct_role(array $f): int
{
    $g = (int)($f['games'] ?? 0);
    $w = (int)($f['wins'] ?? 0);
    return $g > 0 ? (int)round($w / $g * 100) : 0;
}

function top_text(int $n): string
{
    $n = max(1, min(30, $n));
    $players = bot_stats_data()['players'] ?? [];
    $t = "🏆 <b>Топ-$n рейтинга</b>\n\n";
    foreach (array_slice($players, 0, $n) as $p) {
        $r = (int)$p['rank'];
        $mark = $r === 1 ? '🥇' : ($r === 2 ? '🥈' : ($r === 3 ? '🥉' : "  $r."));
        $t .= sprintf("%s <b>%s</b> — %s\n", $mark, bot_esc($p['name']), bot_num($p['rating']));
    }
    return $t;
}

function top_view(int $offset): array
{
    $players = bot_stats_data()['players'] ?? [];
    $total = count($players);
    if ($total === 0) {
        return ["Рейтинг пуст.", menu_markup()];
    }
    $offset = max(0, min($offset, $total - 1));
    $offset = intdiv($offset, 10) * 10;
    $from = $offset + 1;
    $to = min($offset + 10, $total);

    $t = "🏆 <b>Рейтинг</b> ($from–$to из $total)\n\n";
    foreach (array_slice($players, $offset, 10) as $p) {
        $r = (int)$p['rank'];
        $mark = $r === 1 ? '🥇' : ($r === 2 ? '🥈' : ($r === 3 ? '🥉' : sprintf('%2d.', $r)));
        $t .= sprintf("%s <b>%s</b> — %s\n", $mark, bot_esc($p['name']), bot_num($p['rating']));
    }

    $nav = [];
    if ($offset > 0) {
        $nav[] = ['text' => '◀', 'callback_data' => 'top:' . ($offset - 10)];
    }
    $nav[] = ['text' => "$from–$to", 'callback_data' => 'noop'];
    if ($offset + 10 < $total) {
        $nav[] = ['text' => '▶', 'callback_data' => 'top:' . ($offset + 10)];
    }
    $markup = json_encode([
        'inline_keyboard' => [
            $nav,
            [['text' => '◀ Меню', 'callback_data' => 'menu']],
        ],
    ], JSON_UNESCAPED_UNICODE);
    return [$t, $markup];
}

function nominations_text(): string
{
    $noms = bot_stats_data()['nominations'] ?? [];
    if (!$noms) {
        return 'Номинации пока пусты.';
    }
    $t = "🎖 <b>Текущие номинации</b>\n\n";
    foreach ($noms as $n) {
        $t .= "• <b>" . bot_esc((string)$n['title']) . "</b>: " . bot_esc((string)$n['name']) . "\n";
    }
    return $t;
}

// ── Запись на игру ────────────────────────────────────────
function day_view(int $userId): array
{
    $p = bot_player_by_tg($userId);
    $day = bot_open_day();
    if (!$day) {
        return ["📅 <b>Запись на игру</b>\n\nСейчас открытой записи нет. Анонс ближайшего вечера придёт сюда.",
            menu_markup($userId)];
    }
    $reg = $p ? bot_day_is_registered((int)$day['id'], (int)$p['id']) : false;
    $cnt = bot_day_count((int)$day['id']);
    $t = "📅 <b>Запись на игру</b>\n\n"
        . "<b>" . bot_esc((string)$day['title']) . "</b>\n"
        . "🗓 " . bot_date((string)$day['date']) . "\n"
        . ($day['location'] ? "📍 " . bot_esc((string)$day['location']) . "\n" : "")
        . "👥 Записалось: <b>$cnt</b>\n\n"
        . ($reg ? "✅ Ты записан." : "Ты ещё не записан на этот вечер.");
    $btn = $reg
        ? ['text' => '❌ Отписаться', 'callback_data' => 'day_cancel:' . (int)$day['id']]
        : ['text' => '✅ Записаться', 'callback_data' => 'day_reg:' . (int)$day['id']];
    $markup = json_encode(['inline_keyboard' => [
        [$btn],
        [['text' => '◀ Меню', 'callback_data' => 'menu']],
    ]], JSON_UNESCAPED_UNICODE);
    return [$t, $markup];
}

// ============================================================
//                      TELEGRAM-ОБЁРТКИ
// ============================================================
function menu_markup(int $userId = 0): string
{
    $rows = [
        [['text' => '📊 Моя статистика', 'callback_data' => 'me']],
        [['text' => '📅 Запись на игру', 'callback_data' => 'day']],
        [['text' => '🏆 Топ', 'callback_data' => 'top'], ['text' => '🎖 Номинации', 'callback_data' => 'nom']],
        [['text' => '⚖ Судьи', 'callback_data' => 'judges'], ['text' => '🔍 Найти игрока', 'callback_data' => 'find']],
    ];
    if ($userId && bot_is_admin($userId)) {
        $rows[] = [['text' => '🛠 Админка', 'callback_data' => 'admin']];
    }
    return json_encode(['inline_keyboard' => $rows], JSON_UNESCAPED_UNICODE);
}

function reply($chatId, string $text): void
{
    bot_send($chatId, $text, null);
}

function send($chatId, string $text, ?string $markup = null): void
{
    bot_send($chatId, $text, $markup);
}

function send_menu($chatId, int $userId, string $text): void
{
    bot_send($chatId, $text, menu_markup($userId));
}

function edit_text($chatId, $msgId, string $text, ?string $markup = null): void
{
    $params = [
        'chat_id'                  => $chatId,
        'message_id'               => $msgId,
        'text'                     => $text,
        'parse_mode'               => 'HTML',
        'disable_web_page_preview' => true,
    ];
    if ($markup !== null) {
        $params['reply_markup'] = $markup;
    }
    bot_api('editMessageText', $params);
}

function edit_menu($chatId, $msgId, int $userId, string $text): void
{
    edit_text($chatId, $msgId, $text, menu_markup($userId));
}

function edit_msg($chatId, $msgId, string $text): void
{
    edit_text($chatId, $msgId, $text, null);
}

function answer_cb(string $cbId): void
{
    if ($cbId === '') {
        return;
    }
    bot_api('answerCallbackQuery', ['callback_query_id' => $cbId]);
}
