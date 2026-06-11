<?php
// Привязка Telegram к аккаунту по коду. Вызывает бот (на том же сервере).
// POST/GET: key=<deploy_secret>, code=<код из кабинета>, tg_id=<telegram user id>, username=<@ник>
declare(strict_types=1);

define('ROOT', dirname(__DIR__, 2));

$cfgFile = ROOT . '/config.php';
if (!is_file($cfgFile)) {
    http_response_code(503);
    exit('config missing');
}
$GLOBALS['cfg'] = require $cfgFile;

$key = (string)($_REQUEST['key'] ?? '');
if ($key === '' || !hash_equals((string)$GLOBALS['cfg']['deploy_secret'], $key)) {
    http_response_code(403);
    exit('forbidden');
}

header('Content-Type: application/json; charset=utf-8');
require ROOT . '/inc/db.php';

$code = strtoupper(trim((string)($_REQUEST['code'] ?? '')));
$tgId = (int)($_REQUEST['tg_id'] ?? 0);
$username = trim((string)($_REQUEST['username'] ?? '')) ?: null;

function out(array $d): void
{
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($code === '' || $tgId <= 0) {
    out(['ok' => false, 'error' => 'нужны code и tg_id']);
}

try {
    $st = db()->prepare('SELECT u.id, u.nickname FROM tg_link_codes c JOIN users u ON u.id = c.user_id WHERE c.code = ?');
    $st->execute([$code]);
    $u = $st->fetch();
    if (!$u) {
        out(['ok' => false, 'error' => 'код не найден или устарел']);
    }
    // этот Telegram уже привязан к другому аккаунту?
    $chk = db()->prepare('SELECT id FROM users WHERE tg_user_id = ? AND id <> ?');
    $chk->execute([$tgId, (int)$u['id']]);
    if ($chk->fetch()) {
        out(['ok' => false, 'error' => 'этот Telegram уже привязан к другому аккаунту']);
    }
    db()->prepare('UPDATE users SET tg_user_id = ?, tg_username = ?, tg_linked_at = NOW() WHERE id = ?')
        ->execute([$tgId, $username, (int)$u['id']]);
    db()->prepare('DELETE FROM tg_link_codes WHERE code = ?')->execute([$code]);
    out(['ok' => true, 'nickname' => $u['nickname']]);
} catch (Throwable $e) {
    http_response_code(500);
    out(['ok' => false, 'error' => $e->getMessage()]);
}
