<?php
// Аварийный сброс пароля: /reset.php?key=<deploy_secret>&nick=<ник>
// Выдаёт временный пароль; если ник = owner_nickname из конфига — назначает роль главы.
declare(strict_types=1);

define('ROOT', dirname(__DIR__));

$cfgFile = ROOT . '/config.php';
if (!is_file($cfgFile)) {
    http_response_code(503);
    exit('config.php missing');
}
$GLOBALS['cfg'] = require $cfgFile;

$key = (string)($_GET['key'] ?? '');
if ($key === '' || !hash_equals((string)$GLOBALS['cfg']['deploy_secret'], $key)) {
    http_response_code(403);
    exit('forbidden');
}

header('Content-Type: text/plain; charset=utf-8');
require ROOT . '/inc/db.php';

$nick = trim((string)($_GET['nick'] ?? ''));
if ($nick === '') {
    exit("укажите ?nick=\n");
}

$st = db()->prepare('SELECT * FROM users WHERE nickname = ?');
$st->execute([$nick]);
$u = $st->fetch();
if (!$u) {
    exit("пользователь «{$nick}» не найден\n");
}

$temp = '';
$alphabet = 'abcdefghkmnpqrstuvwxyz23456789';
for ($i = 0; $i < 10; $i++) {
    $temp .= $alphabet[random_int(0, strlen($alphabet) - 1)];
}

db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
    ->execute([password_hash($temp, PASSWORD_DEFAULT), (int)$u['id']]);

$role = $u['role'];
$ownerNick = (string)($GLOBALS['cfg']['owner_nickname'] ?? '');
if ($ownerNick !== '' && mb_strtolower($nick) === mb_strtolower($ownerNick) && $u['role'] !== 'owner') {
    db()->prepare("UPDATE users SET role = 'owner' WHERE id = ?")->execute([(int)$u['id']]);
    $role = 'owner (назначен)';
}

try {
    db()->prepare('INSERT INTO logs (user_id, action, details, ip) VALUES (?,?,?,?)')
        ->execute([(int)$u['id'], 'password_reset_emergency', json_encode(['nick' => $nick], JSON_UNESCAPED_UNICODE), $_SERVER['REMOTE_ADDR'] ?? '']);
} catch (Throwable $e) {
}

echo "ник: {$nick}\nвременный пароль: {$temp}\nроль: {$role}\nСмените пароль в личном кабинете сразу после входа.\n";
