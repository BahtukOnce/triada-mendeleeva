<?php
$root = dirname(__DIR__);
$cfg = is_file($root . '/config.php') ? require $root . '/config.php' : ['env' => 'test'];
header('Content-Type: text/plain; charset=utf-8');
if (($cfg['env'] ?? 'test') === 'prod') {
    echo "User-agent: *\nAllow: /\n";
} else {
    echo "User-agent: *\nDisallow: /\n";
}
