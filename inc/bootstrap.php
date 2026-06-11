<?php
declare(strict_types=1);

mb_internal_encoding('UTF-8');
date_default_timezone_set('Europe/Moscow');

define('ROOT', dirname(__DIR__));

$configFile = ROOT . '/config.php';
if (!is_file($configFile)) {
    require ROOT . '/inc/setup_required.php';
    exit;
}
$GLOBALS['cfg'] = require $configFile;

session_name('triada_sess');
session_set_cookie_params([
    'lifetime' => 60 * 60 * 24 * 30,
    'path'     => '/',
    'secure'   => !empty($_SERVER['HTTPS']),
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

require ROOT . '/inc/helpers.php';
require ROOT . '/inc/db.php';
require ROOT . '/inc/auth.php';
require ROOT . '/inc/layout.php';
