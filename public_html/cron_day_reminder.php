<?php
/**
 * Напоминание записавшимся в день игры (личка бота, один раз на вечер).
 * Берёт вечера с датой «сегодня» и статусом reg_open/reg_closed, у которых
 * reminder_sent_at ещё пуст; шлёт каждому записавшемуся с привязанным TG
 * персональное напоминание (с его интервалом времени). Флаг ставится атомарно.
 *
 * Не отправляет раньше 10:00 (защита от крона, сработавшего сразу после полуночи).
 *
 * Защита (как у import_applications.php):
 *   Крон Beget (напр. в 11:00): php public_html/cron_day_reminder.php
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

if ((int)date('G') < 10) {
    $log('До 10:00 напоминания не шлём.');
    exit;
}

$days = db()->prepare("SELECT * FROM game_days
    WHERE date = CURDATE() AND status IN ('reg_open','reg_closed') AND reminder_sent_at IS NULL");
$days->execute();
$rows = $days->fetchAll();
if (!$rows) {
    $log('Сегодня напоминать не о чем.');
    exit;
}

$base = rtrim((string)($GLOBALS['cfg']['base_url'] ?? 'https://triada-mendeleeva.ru'), '/');
foreach ($rows as $day) {
    $dayId = (int)$day['id'];
    // Атомарный флаг: параллельный запуск крона не продублирует рассылку
    $upd = db()->prepare('UPDATE game_days SET reminder_sent_at = NOW() WHERE id = ? AND reminder_sent_at IS NULL');
    $upd->execute([$dayId]);
    if ($upd->rowCount() === 0) {
        continue;
    }
    $vd = day_table_verdict($dayId);
    $cnt = bot_day_count($dayId);
    $markup = json_encode(['inline_keyboard' => [
        [['text' => '📅 Страница вечера', 'url' => $base . '/day.php?id=' . $dayId]],
    ]], JSON_UNESCAPED_UNICODE);
    $regs = db()->prepare('SELECT r.player_id, r.time_from, r.time_to FROM day_registrations r
        WHERE r.day_id = ? AND r.cancelled_at IS NULL');
    $regs->execute([$dayId]);
    $sent = 0;
    foreach ($regs->fetchAll() as $r) {
        $when = ($r['time_from'] || $r['time_to'])
            ? 'Вы записаны на ' . substr((string)$r['time_from'], 0, 5) . '–' . substr((string)$r['time_to'], 0, 5) . '.'
            : 'Вы записаны на весь вечер.';
        $txt = "⏰ <b>Сегодня играем!</b>\n\n"
            . "<b>" . bot_esc((string)$day['title']) . "</b>\n"
            . ($day['location'] ? "📍 " . bot_esc((string)$day['location']) . "\n" : "")
            . "👥 Записались: <b>$cnt</b>\n"
            . ($vd !== '' ? $vd . "\n" : '')
            . "\n" . $when . " Если планы поменялись — отпишитесь в боте, не подводите стол 🙏";
        if (bot_notify_player((int)$r['player_id'], $txt, $markup)) {
            $sent++;
        }
        usleep(40000);
    }
    $log("Вечер «{$day['title']}» (#$dayId): напомнил $sent из " . $cnt . '.');
}
