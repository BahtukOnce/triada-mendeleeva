<?php
/**
 * Разовая настройка webhook Telegram-бота. Защищено deploy_secret.
 *   Поставить:  https://triada-mendeleeva.ru/setup_webhook.php?key=<deploy_secret>&go=1
 *   Снять:      https://triada-mendeleeva.ru/setup_webhook.php?key=<deploy_secret>&delete=1
 *   Статус:     https://triada-mendeleeva.ru/setup_webhook.php?key=<deploy_secret>
 * Токен и секрет берутся из config.php (bot_token, bot_secret). URL бота — base_url + /bot.php.
 */
declare(strict_types=1);

define('ROOT', dirname(__DIR__));
$cfgFile = ROOT . '/config.php';
if (!is_file($cfgFile)) {
    http_response_code(503);
    exit('config missing');
}
$GLOBALS['cfg'] = require $cfgFile;
require ROOT . '/inc/bot_lib.php';

header('Content-Type: text/plain; charset=utf-8');

$key = (string)($_REQUEST['key'] ?? '');
if ($key === '' || !hash_equals((string)($GLOBALS['cfg']['deploy_secret'] ?? ''), $key)) {
    http_response_code(403);
    exit('forbidden: добавь ?key=<deploy_secret>');
}

if (bot_token() === '') {
    exit('bot_token не задан в config.php');
}

$base   = rtrim((string)($GLOBALS['cfg']['base_url'] ?? ''), '/');
$botUrl = $base . '/bot.php';
$secret = (string)($GLOBALS['cfg']['bot_secret'] ?? '');

if (isset($_GET['delete'])) {
    echo "deleteWebhook -> ";
    print_r(bot_api('deleteWebhook', ['drop_pending_updates' => 'true']));
    exit;
}

if (isset($_GET['go'])) {
    $params = [
        'url'             => $botUrl,
        'allowed_updates' => json_encode(['message', 'callback_query']),
        'drop_pending_updates' => 'true',
    ];
    if ($secret !== '') {
        $params['secret_token'] = $secret;
    }
    echo "setWebhook ($botUrl) -> ";
    print_r(bot_api('setWebhook', $params));
    echo "\n\n";
}

echo "Бот: ";
print_r(bot_api('getMe'));
echo "\nТекущий статус webhook:\n";
print_r(bot_api('getWebhookInfo'));
