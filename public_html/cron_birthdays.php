<?php
/**
 * Дни рождения: утренняя сводка руководителю и заму в личку бота.
 * Шлём один раз в сутки — кому сегодня, и кто будет через 3 дня (чтобы успеть
 * подготовиться). Повторный запуск в тот же день ничего не отправит: отметка
 * последней рассылки живёт в settings (bd_notified_on).
 *
 * Защита (как у cron_day_reminder.php):
 *   Крон Beget (напр. в 09:00): php public_html/cron_birthdays.php
 *   Веб-ручной:                 ?key=<deploy_secret> либо залогиненный админ.
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
        exit("Доступ запрещён. Залогинься администратором — либо добавь ?key=<deploy_secret>.\n");
    }
}

$log = function (string $m): void {
    echo $m . "\n";
};

$today = date('Y-m-d');
$force = isset($_REQUEST['force']);      // ручная перепроверка без ожидания следующего дня
if (!$force && setting('bd_notified_on', '') === $today) {
    $log('Сегодня уже отправляли (' . $today . '). Для повтора — &force=1.');
    exit;
}

// Именинники берутся так же, как в календаре /birthdays.php: только те, кто играл
// или имеет аккаунт, и не забанен — иначе в сводку полезут старые анкеты.
$pick = function (int $offsetDays) {
    $st = db()->prepare("SELECT p.nickname, p.tg
        FROM players p
        WHERE p.birth_date IS NOT NULL AND p.banned_at IS NULL
          AND (p.user_id IS NOT NULL OR EXISTS (SELECT 1 FROM game_seats gs WHERE gs.player_id = p.id))
          AND DATE_FORMAT(p.birth_date, '%m-%d') = DATE_FORMAT(CURDATE() + INTERVAL ? DAY, '%m-%d')
        ORDER BY p.nickname");
    $st->execute([$offsetDays]);
    return $st->fetchAll();
};

$todayList = $pick(0);
$soonList  = $pick(3);

if (!$todayList && !$soonList) {
    setting_set('bd_notified_on', $today);
    $log('Именинников нет ни сегодня, ни через 3 дня.');
    exit;
}

$line = function (array $r): string {
    $tg = trim((string)($r['tg'] ?? ''));
    return '• <b>' . bot_esc((string)$r['nickname']) . '</b>'
        . ($tg !== '' ? ' — ' . bot_esc('@' . ltrim($tg, '@')) : '');
};

$parts = [];
if ($todayList) {
    $parts[] = "🎂 <b>Сегодня день рождения</b>\n" . implode("\n", array_map($line, $todayList));
}
if ($soonList) {
    $parts[] = "📅 <b>Через 3 дня</b>\n" . implode("\n", array_map($line, $soonList));
}
$parts[] = 'Календарь: ' . rtrim((string)($GLOBALS['cfg']['base_url'] ?? 'https://triada-mendeleeva.ru'), '/') . '/birthdays.php';
$text = implode("\n\n", $parts);

$sent = 0;
try {
    if (bot_token() !== '') {
        // Руководитель получает всегда; зам и админ — по таблице прав в админке
        // (bd_bot_notify), чтобы руководитель раздавал доступ сам, без правок кода.
        $roles = ["'owner'"];
        if (perm_role_enabled('bd_bot_notify', 'deputy')) {
            $roles[] = "'deputy'";
        }
        if (perm_role_enabled('bd_bot_notify', 'admin')) {
            $roles[] = "'admin'";
        }
        $recip = db()->query("SELECT id, tg_user_id, role FROM users
            WHERE role IN (" . implode(',', $roles) . ") AND tg_user_id IS NOT NULL")->fetchAll();
        foreach ($recip as $r) {
            // Кнопка «переслать заму» — только руководителю: остальным пересылать некому.
            $kb = ((string)$r['role'] === 'owner') ? bot_forward_kb() : null;
            if (bot_send((int)$r['tg_user_id'], $text, $kb)) {
                $sent++;
            }
        }
    }
} catch (Throwable $e) {
    $log('Ошибка отправки: ' . $e->getMessage());
}

// Отметку ставим в любом случае, чтобы при недоступном боте не долбить каждый час.
setting_set('bd_notified_on', $today);
$log('Сегодня: ' . count($todayList) . ', через 3 дня: ' . count($soonList) . '. Отправлено адресатам: ' . $sent . '.');
