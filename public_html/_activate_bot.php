<?php
/**
 * РАЗОВЫЙ активатор Telegram-бота. Защищён deploy_secret.
 *   https://triada-mendeleeva.ru/_activate_bot.php?key=<deploy_secret>
 * Делает: берёт BOT_TOKEN/WEBHOOK_SECRET из архивного config бота, дописывает
 * ключи bot_token/bot_secret в живой config.php (с бэкапом), ставит вебхук,
 * пишет bot_username в settings. Токен/секрет в вывод НЕ печатаются.
 * После проверки файл нужно удалить.
 */
declare(strict_types=1);

define('ROOT', dirname(__DIR__));
$live = ROOT . '/config.php';
if (!is_file($live)) {
    http_response_code(503);
    exit('config missing');
}
$cfg = require $live;

$key = (string)($_REQUEST['key'] ?? '');
if ($key === '' || !hash_equals((string)($cfg['deploy_secret'] ?? ''), $key)) {
    http_response_code(403);
    exit('forbidden: добавь ?key=<deploy_secret>');
}
header('Content-Type: text/plain; charset=utf-8');

function tgc(string $token, string $method, array $params = []): ?array
{
    $ch = curl_init("https://api.telegram.org/bot$token/$method");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $params]);
    $r = curl_exec($ch);
    curl_close($ch);
    return $r ? json_decode($r, true) : null;
}

$token  = (string)($cfg['bot_token'] ?? '');
$secret = (string)($cfg['bot_secret'] ?? '');

// 1) если в живом config токена ещё нет — взять из архивного config бота
if ($token === '') {
    $arch = dirname(ROOT) . '/archive_old_site/public_html_20260611/config.php';
    if (!is_file($arch)) {
        exit("Архивный config бота не найден: $arch\nДобавьте 'bot_token' в config.php вручную.");
    }
    require $arch; // define('BOT_TOKEN',...), define('WEBHOOK_SECRET',...)
    $token = defined('BOT_TOKEN') ? (string)BOT_TOKEN : '';
    if ($secret === '') {
        $secret = defined('WEBHOOK_SECRET') && (string)WEBHOOK_SECRET !== '' ? (string)WEBHOOK_SECRET : bin2hex(random_bytes(16));
    }
    if ($token === '') {
        exit("BOT_TOKEN в архивном config пуст. Добавьте 'bot_token' в config.php вручную.");
    }
    $txt = (string)file_get_contents($live);
    if (strpos($txt, "'bot_token'") === false) {
        $pos = strrpos($txt, '];');
        if ($pos === false) {
            exit("Не нашёл закрывающую '];' в config.php — добавьте ключи вручную.");
        }
        $ins = "    'bot_token'  => " . var_export($token, true) . ",\n"
            . "    'bot_secret' => " . var_export($secret, true) . ",\n";
        copy($live, $live . '.bak');
        file_put_contents($live, substr($txt, 0, $pos) . $ins . substr($txt, $pos));
        echo "config: ключи bot_token/bot_secret добавлены (бэкап -> config.php.bak)\n";
    } else {
        echo "config: ключ bot_token уже присутствует\n";
    }
} else {
    echo "config: bot_token уже задан\n";
}

// 2) кто бот
$me = tgc($token, 'getMe');
if (!$me || empty($me['ok'])) {
    exit("getMe не удался — проверьте токен. Ответ: " . json_encode($me, JSON_UNESCAPED_UNICODE) . "\n");
}
$username = (string)($me['result']['username'] ?? '');
echo "bot: @$username (" . ($me['result']['first_name'] ?? '?') . ")\n";

// 3) вебхук
$base = rtrim((string)($cfg['base_url'] ?? 'https://triada-mendeleeva.ru'), '/');
$wh = tgc($token, 'setWebhook', [
    'url'                  => $base . '/bot.php',
    'secret_token'         => $secret,
    'allowed_updates'      => json_encode(['message', 'callback_query']),
    'drop_pending_updates' => 'true',
]);
echo "setWebhook -> " . json_encode($wh, JSON_UNESCAPED_UNICODE) . "\n";
$info = tgc($token, 'getWebhookInfo');
echo "webhook url: " . ($info['result']['url'] ?? '(none)') . "\n";
echo "pending: " . ($info['result']['pending_update_count'] ?? '?')
    . "  last_error: " . ($info['result']['last_error_message'] ?? '-') . "\n";

// 4) bot_username в settings (для кнопки в кабинете)
if ($username !== '') {
    try {
        require ROOT . '/inc/db.php';
        db()->prepare("INSERT INTO settings (k, v) VALUES ('bot_username', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)")
            ->execute([$username]);
        echo "settings.bot_username = $username\n";
    } catch (Throwable $e) {
        echo "settings warn: " . $e->getMessage() . "\n";
    }
}

echo "\nГОТОВО. Бот должен ожить. Удалите этот файл (_activate_bot.php) после проверки.\n";
