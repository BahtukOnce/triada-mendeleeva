<?php
$root = dirname(__DIR__);
$cfg = is_file($root . '/config.php') ? require $root . '/config.php' : ['env' => 'test'];
header('Content-Type: text/plain; charset=utf-8');
if (($cfg['env'] ?? 'test') === 'prod') {
    $base = rtrim((string)($cfg['base_url'] ?? 'https://triada-mendeleeva.ru'), '/');
    echo "User-agent: *\n";
    echo "Allow: /\n";
    // Служебные и приватные разделы — не тратить на них краулинг-бюджет
    echo "Disallow: /admin/\n";
    echo "Disallow: /api/\n";
    echo "Disallow: /cabinet.php\n";
    echo "Disallow: /login.php\n";
    echo "Disallow: /logout.php\n";
    echo "Disallow: /notifications.php\n";
    echo "Sitemap: $base/sitemap.xml\n";
} else {
    echo "User-agent: *\nDisallow: /\n";
}
