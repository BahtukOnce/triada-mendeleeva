<?php
// Карта сайта (sitemap.xml через rewrite) — список всех публичных страниц для
// поисковиков. Отдаётся только на боевом контуре; на тесте — пусто (сайт закрыт).
declare(strict_types=1);

define('ROOT', dirname(__DIR__));
$cfg = is_file(ROOT . '/config.php') ? require ROOT . '/config.php' : ['env' => 'test'];
$GLOBALS['cfg'] = $cfg;

header('Content-Type: application/xml; charset=utf-8');
$base = rtrim((string)($cfg['base_url'] ?? 'https://triada-mendeleeva.ru'), '/');

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

if (($cfg['env'] ?? 'test') !== 'prod') {
    echo '</urlset>';
    exit;
}

require ROOT . '/inc/db.php';

$out = static function (string $loc, ?string $lastmod = null, string $freq = '', string $prio = ''): void {
    global $base;
    echo '  <url><loc>' . htmlspecialchars($base . $loc, ENT_XML1) . '</loc>';
    if ($lastmod) {
        $ts = strtotime($lastmod);
        if ($ts) {
            echo '<lastmod>' . date('Y-m-d', $ts) . '</lastmod>';
        }
    }
    if ($freq !== '') {
        echo '<changefreq>' . $freq . '</changefreq>';
    }
    if ($prio !== '') {
        echo '<priority>' . $prio . '</priority>';
    }
    echo "</url>\n";
};

// Статичные разделы
$out('/', null, 'daily', '1.0');
$out('/news.php', null, 'daily', '0.9');
$out('/days.php', null, 'weekly', '0.8');
$out('/tournaments.php', null, 'weekly', '0.8');
$out('/rating.php', null, 'weekly', '0.9');
$out('/players.php', null, 'weekly', '0.7');
$out('/join.php', null, 'monthly', '0.8');
$out('/versus.php', null, 'monthly', '0.5');

if (db_ready()) {
    try {
        // Новости
        foreach (db()->query("SELECT id, published_at FROM news WHERE published_at IS NOT NULL ORDER BY published_at DESC LIMIT 1000")->fetchAll() as $r) {
            $out('/news.php?id=' . (int)$r['id'], (string)$r['published_at'], 'monthly', '0.6');
        }
        // Профили игроков (не забаненные, с играми или аккаунтом)
        foreach (db()->query("SELECT p.id, p.updated_at FROM players p
            WHERE p.banned_at IS NULL
              AND (EXISTS (SELECT 1 FROM game_seats gs WHERE gs.player_id = p.id) OR p.user_id IS NOT NULL)
            ORDER BY p.id LIMIT 5000")->fetchAll() as $r) {
            $out('/player.php?id=' . (int)$r['id'], (string)($r['updated_at'] ?? ''), 'weekly', '0.6');
        }
        // Турниры (кроме черновиков)
        foreach (db()->query("SELECT id, date_from FROM tournaments WHERE status <> 'draft' ORDER BY id LIMIT 2000")->fetchAll() as $r) {
            $out('/tournament.php?id=' . (int)$r['id'], (string)($r['date_from'] ?? ''), 'monthly', '0.7');
        }
        // Игровые вечера (кроме черновиков)
        foreach (db()->query("SELECT id, date FROM game_days WHERE status <> 'draft' ORDER BY id LIMIT 5000")->fetchAll() as $r) {
            $out('/day.php?id=' . (int)$r['id'], (string)($r['date'] ?? ''), 'monthly', '0.6');
        }
    } catch (Throwable $e) {
        // частичный sitemap лучше пустого — молча пропускаем недоступное
    }
}

echo '</urlset>';
