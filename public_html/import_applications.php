<?php
/**
 * Импорт заявок в клуб из старой Google-формы (её ответы лежат в Google-таблице).
 * Пока часть людей заполняет старую форму вместо /join.php — тянем новые строки сюда,
 * чтобы руководитель ловил уведомления (колокольчик + бот), как по обычной заявке.
 *
 * НЕ бэкофиллит историю: на первом запуске запоминает максимальную метку времени в
 * таблице (baseline) и импортирует только строки НОВЕЕ неё. Дедуп — по
 * club_applications.ext_ref = «gform:<метка времени>» (миграция 070).
 *
 * Защита (как у import_news.php):
 *   Крон Beget (раз в 6 часов):  php public_html/import_applications.php
 *   Веб-ручной:                  https://triada-mendeleeva.ru/import_applications.php?key=<deploy_secret>
 *   (или просто открыть залогинившись администратором сайта)
 */
declare(strict_types=1);
date_default_timezone_set('Europe/Moscow');

$cli = (PHP_SAPI === 'cli');
if ($cli) {
    define('ROOT', dirname(__DIR__));
    $cfgFile = ROOT . '/config.php';
    if (!is_file($cfgFile)) {
        exit("config missing\n");
    }
    $GLOBALS['cfg'] = require $cfgFile;
    require ROOT . '/inc/db.php';
    require ROOT . '/inc/helpers.php';
    require ROOT . '/inc/bot_lib.php';
} else {
    require dirname(__DIR__) . '/inc/bootstrap.php';
    require_once ROOT . '/inc/bot_lib.php';
    header('Content-Type: text/plain; charset=utf-8');
    $deploySecret = (string)cfg('deploy_secret', '');
    $key   = (string)($_REQUEST['key'] ?? '');
    $keyOk = ($key !== '' && $deploySecret !== '' && hash_equals($deploySecret, $key));
    $u     = current_user();
    $isAdmin = $u && role_level($u['role']) >= 3;
    if (!$keyOk && !$isAdmin) {
        http_response_code(403);
        exit("Доступ запрещён. Залогинься администратором на сайте — либо добавь ?key=<deploy_secret>.\n");
    }
}

$log = function (string $m): void {
    echo $m . "\n";
};

// Источник: CSV-экспорт Google-таблицы. По умолчанию — известная таблица клуба;
// поменять можно настройкой gform_apps_url (без правки кода).
$sheetUrl = setting('gform_apps_url', '');
if ($sheetUrl === '') {
    $sheetUrl = 'https://docs.google.com/spreadsheets/d/1bOqXG_ag71nPwWezC25K2xc8Tt8LGZNMG592SQsSK4g/export?format=csv';
}

// ── Скачиваем CSV (cURL — надёжнее, следует за редиректом Google) ──
$ch = curl_init($sheetUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS      => 5,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_USERAGENT      => 'TriadaBot/1.0',
]);
$csv  = curl_exec($ch);
$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if (!is_string($csv) || $code !== 200 || strlen($csv) < 20) {
    $log('Не удалось скачать таблицу (HTTP ' . $code . ').');
    exit;
}
$head = strtolower(substr($csv, 0, 60));
if (str_contains($head, '<html') || str_contains($head, '<!doctype')) {
    $log('Таблица недоступна публично (пришёл HTML/логин). Открой доступ «по ссылке — читатель».');
    exit;
}

// ── Парсим CSV (учитываем многострочные ячейки внутри кавычек) ──
$fh = fopen('php://temp', 'r+');
fwrite($fh, $csv);
rewind($fh);
$rows = [];
while (($r = fgetcsv($fh)) !== false) {
    $rows[] = $r;
}
fclose($fh);
if (count($rows) < 2) {
    $log('В таблице нет строк с данными.');
    exit;
}
array_shift($rows); // заголовок

// Метка времени формы → unix. Формат «DD.MM.YYYY H:MM:SS» (час без ведущего нуля).
$parseTs = function (string $s): ?int {
    $s = trim($s);
    if ($s === '') {
        return null;
    }
    $dt = DateTime::createFromFormat('d.m.Y G:i:s', $s) ?: DateTime::createFromFormat('d.m.Y H:i:s', $s);
    return $dt ? $dt->getTimestamp() : null;
};

// Все метки времени (для baseline и продвижения курсора)
$allTs = [];
foreach ($rows as $r) {
    $t = $parseTs((string)($r[0] ?? ''));
    if ($t !== null) {
        $allTs[] = $t;
    }
}
$maxTs = $allTs ? max($allTs) : 0;

$since = (int)setting('gform_apps_since', '0');
// Первый запуск: baseline = ПРЕДпоследняя метка, чтобы самая свежая заявка (единственная
// необработанная) прошла как новая и импортировалась. Дальше — только новее. Историю
// (всё до предпоследней метки) не трогаем. Не выходим — идём в цикл импорта.
if ($since === 0) {
    $distinct = array_values(array_unique($allTs));
    rsort($distinct);
    $since = isset($distinct[1]) ? (int)$distinct[1] : max(0, $maxTs - 1);
    $log('Первый запуск: базовая линия ' . date('d.m.Y H:i', $since) . ' — импортирую последнюю заявку и всё новее.');
}

$cut = fn($s, int $n): string => mb_substr(trim((string)$s), 0, $n);

$imported = 0;
$newSince = $since;
foreach ($rows as $r) {
    $ts = $parseTs((string)($r[0] ?? ''));
    if ($ts === null || $ts <= $since) {
        continue; // старое / до курсора
    }
    if ($ts > $newSince) {
        $newSince = $ts;
    }
    $extRef = 'gform:' . trim((string)$r[0]);
    $chk = db()->prepare('SELECT 1 FROM club_applications WHERE ext_ref = ? LIMIT 1');
    $chk->execute([$extRef]);
    if ($chk->fetchColumn()) {
        continue; // уже импортировано
    }

    $full = $cut($r[1] ?? '', 150);
    $nick = $cut(nickname_clean((string)($r[2] ?? '')), 60);
    if ($full === '' || $nick === '') {
        continue; // мусорная/пустая строка
    }
    $status  = $cut($r[3] ?? '', 40);
    $faculty = $cut($r[4] ?? '', 40);
    $group   = $cut($r[5] ?? '', 50);
    $exp     = $cut($r[6] ?? '', 255);
    $tg      = $cut(ltrim((string)($r[7] ?? ''), '@ '), 100);
    $src     = $cut($r[8] ?? '', 500);
    $bd = null;
    $bdRaw = trim((string)($r[9] ?? ''));
    if ($bdRaw !== '') {
        foreach (['d.m.Y', 'Y-m-d', 'd.m.y'] as $fmt) {
            $d = DateTime::createFromFormat($fmt, $bdRaw);
            if ($d) {
                $bd = $d->format('Y-m-d');
                break;
            }
        }
    }

    db()->prepare('INSERT INTO club_applications
        (full_name, nickname, applicant_status, faculty, study_group, experience, source, tg_username, birth_date, ext_ref)
        VALUES (?,?,?,?,?,?,?,?,?,?)')
        ->execute([
            $full, $nick, $status ?: 'не указан', $faculty ?: null, $group ?: null,
            $exp ?: 'не указан', $src ?: 'Google-форма', $tg ?: 'не указан', $bd, $extRef,
        ]);

    // Уведомления — как по обычной заявке: колокольчик админам+рук-лю, бот — рук-лю.
    app_notify_admins('🆕 Новая заявка в клуб (Google-форма): ' . $nick . ' (' . $full . ')', '/admin/applications.php');
    try {
        if (bot_token() !== '') {
            $botText = "🆕 <b>Новая заявка в клуб</b> (Google-форма)\n\n"
                . "👤 <b>" . bot_esc($full) . "</b>\n"
                . "🎭 Ник: <b>" . bot_esc($nick) . "</b>\n"
                . "📱 Telegram: " . bot_esc($tg !== '' ? '@' . $tg : '—') . "\n"
                . "🎓 " . bot_esc($status)
                . "\n\nОткрыть на сайте: " . rtrim((string)($GLOBALS['cfg']['base_url'] ?? 'https://triada-mendeleeva.ru'), '/') . '/admin/applications.php';
            // кому в бот уходят заявки: руководитель всегда; зам/админ — по таблице прав
            $notifyRoles = ["'owner'"];
            if (perm_role_enabled('app_bot_notify', 'deputy')) { $notifyRoles[] = "'deputy'"; }
            if (perm_role_enabled('app_bot_notify', 'admin')) { $notifyRoles[] = "'admin'"; }
            $recip = db()->query("SELECT tg_user_id FROM users WHERE role IN (" . implode(',', $notifyRoles) . ") AND tg_user_id IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($recip as $tgid) {
                bot_send((int)$tgid, $botText);
            }
        }
    } catch (Throwable $e) {
    }
    $imported++;
}
setting_set('gform_apps_since', (string)$newSince);
$log('Импортировано новых заявок из Google-формы: ' . $imported . '.');
