<?php
/**
 * Импорт новостей из ПУБЛИЧНОГО Telegram-канала через веб-превью t.me/s/<канал>.
 * Не требует бота, прав админа в канале и тестовых постов — читает уже опубликованное.
 *
 * Защищено deploy_secret.
 *   Бэкофилл (история):   https://triada-mendeleeva.ru/import_news.php?key=<deploy_secret>&pages=20
 *   Свежие (для крона):   https://triada-mendeleeva.ru/import_news.php?key=<deploy_secret>
 *   Другой канал:         ...&channel=<username>
 *   CLI (крон Beget):     php public_html/import_news.php <deploy_secret> [pages]
 *
 * Дедупликация — по уникальному news.tg_msg_id (миграция 017): повторный запуск
 * не плодит дубли, а обновляет текст/дату изменённых постов.
 */
declare(strict_types=1);

$cli = (PHP_SAPI === 'cli');

if ($cli) {
    // Крон: лёгкая загрузка без сессии, доступ только по deploy_secret.
    define('ROOT', dirname(__DIR__));
    $cfgFile = ROOT . '/config.php';
    if (!is_file($cfgFile)) {
        exit("config missing\n");
    }
    $GLOBALS['cfg'] = require $cfgFile;
    require ROOT . '/inc/db.php';
    $deploySecret = (string)($GLOBALS['cfg']['deploy_secret'] ?? '');
    $cfgChannel   = (string)($GLOBALS['cfg']['news_channel_id'] ?? '');
} else {
    require dirname(__DIR__) . '/inc/bootstrap.php';
    header('Content-Type: text/plain; charset=utf-8');
    $deploySecret = (string)cfg('deploy_secret', '');
    $cfgChannel   = (string)cfg('news_channel_id', '');
}

// Доступ:
//   CLI/крон на сервере — доверенный контекст, ключ не нужен (запустить может лишь тот, у кого SSH/cron).
//   Веб — только администратор сайта (по сессии) ИЛИ ключ deploy_secret.
if (!$cli) {
    $key   = (string)($_REQUEST['key'] ?? '');
    $keyOk = ($key !== '' && $deploySecret !== '' && hash_equals($deploySecret, $key));
    $u     = current_user();
    $isAdmin = $u && role_level($u['role']) >= 3;
    if (!$keyOk && !$isAdmin) {
        http_response_code(403);
        exit("Доступ запрещён. Залогинься администратором на сайте — либо добавь ?key=<deploy_secret>.\n");
    }
}

if ($cfgChannel === '') {
    $cfgChannel = 'triada_mendeleeva';
}
$channel = ltrim((string)($_GET['channel'] ?? $cfgChannel), '@');
if ($channel === '') {
    $channel = 'triada_mendeleeva';
}
// CLI: число страниц — первым аргументом (по умолчанию 1). Веб: ?pages= (по умолчанию 20).
$pages = $cli ? (int)($argv[1] ?? 1) : (int)($_GET['pages'] ?? 20);
$pages = max(1, min(60, $pages));

function tg_fetch(string $url): string
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 25,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; TriadaNewsImporter/1.0)',
            CURLOPT_HTTPHEADER     => ['Accept-Language: ru,en;q=0.8'],
        ]);
        $out = curl_exec($ch);
        curl_close($ch);
        return is_string($out) ? $out : '';
    }
    return (string)@file_get_contents($url);
}

function node_inner_html(DOMNode $node): string
{
    $html = '';
    foreach ($node->childNodes as $c) {
        $html .= $node->ownerDocument->saveHTML($c);
    }
    return $html;
}

$stmt = db()->prepare('INSERT INTO news (title, body, published_at, tg_msg_id)
        VALUES (?,?,?,?)
        ON DUPLICATE KEY UPDATE title = VALUES(title), body = VALUES(body), published_at = VALUES(published_at)');

$seen = 0; $withText = 0; $before = 0;
echo "Канал: @$channel · страниц к разбору: $pages\n";

for ($p = 0; $p < $pages; $p++) {
    $url = 'https://t.me/s/' . rawurlencode($channel) . ($before ? ('?before=' . $before) : '');
    $html = tg_fetch($url);
    if ($html === '') {
        echo "  страница " . ($p + 1) . ": не удалось загрузить ($url)\n";
        break;
    }
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    $xp = new DOMXPath($doc);
    $msgs = $xp->query("//div[@data-post]");
    if (!$msgs || $msgs->length === 0) {
        echo "  страница " . ($p + 1) . ": постов не найдено (возможно, канал приватный или имя неверное)\n";
        break;
    }

    $minId = PHP_INT_MAX;
    $pageText = 0;
    foreach ($msgs as $node) {
        /** @var DOMElement $node */
        $dp = $node->getAttribute('data-post'); // напр. triada_mendeleeva/718
        $slash = strrpos($dp, '/');
        $msgId = $slash === false ? 0 : (int)substr($dp, $slash + 1);
        if ($msgId <= 0) {
            continue;
        }
        $minId = min($minId, $msgId);
        $seen++;

        $tnode = $xp->query(".//div[contains(concat(' ', normalize-space(@class), ' '), ' tgme_widget_message_text ')]", $node)->item(0);
        if (!$tnode) {
            continue; // пост без текста (только фото/видео) — пропускаем
        }
        $raw = node_inner_html($tnode);
        $raw = preg_replace('/<br\s*\/?>/i', "\n", $raw);
        $text = trim(html_entity_decode(strip_tags((string)$raw), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($text === '') {
            continue;
        }
        $dt = $xp->query(".//time[@datetime]", $node)->item(0);
        $ts = ($dt && $dt->getAttribute('datetime'))
            ? date('Y-m-d H:i:s', strtotime($dt->getAttribute('datetime')))
            : date('Y-m-d H:i:s');
        $firstLine = trim((string)strtok($text, "\n"));
        $title = mb_substr($firstLine !== '' ? $firstLine : $text, 0, 200);

        $stmt->execute([$title, $text, $ts, $msgId]);
        $withText++;
        $pageText++;
    }
    echo "  страница " . ($p + 1) . ": постов " . $msgs->length . ", с текстом $pageText, minId=$minId\n";

    if ($minId === PHP_INT_MAX || $minId <= 1) {
        break; // дальше некуда листать
    }
    $before = $minId;
}

echo "\nГотово. Обработано постов: $seen · импортировано/обновлено с текстом: $withText\n";
echo "Открой /news.php — записи должны появиться (сортировка по дате поста).\n";
