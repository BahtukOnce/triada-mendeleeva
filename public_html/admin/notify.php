<?php
require dirname(__DIR__, 2) . '/inc/bootstrap.php';
$u = require_role('admin');
require ROOT . '/inc/bot_lib.php';

$recipients = 0;
try {
    $recipients = (int)db()->query('SELECT COUNT(*) FROM players WHERE tg_user_id IS NOT NULL')->fetchColumn();
} catch (Throwable $e) {
}

$botReady = bot_token() !== '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $text = trim((string)($_POST['text'] ?? ''));
    if (!$botReady) {
        flash_set('err', 'Бот не настроен: задайте bot_token в config.php на сервере.');
    } elseif ($text === '') {
        flash_set('err', 'Введите текст рассылки.');
    } else {
        set_time_limit(0);
        $res = bot_broadcast($text);
        log_action((int)$u['id'], 'bot_broadcast', ['recipients' => $res['recipients'], 'sent' => $res['sent']]);
        flash_set('ok', "Отправлено: {$res['sent']} из {$res['recipients']}" . ($res['failed'] ? ", не доставлено: {$res['failed']}" : ''));
    }
    redirect('/admin/notify.php');
}

$open = null;
try {
    $open = db()->query("SELECT * FROM game_days WHERE status = 'reg_open' AND date >= CURDATE() ORDER BY date LIMIT 1")->fetch() ?: null;
} catch (Throwable $e) {
}

$prefill = '';
if ($open) {
    $prefill = "📣 Открыта запись на игровой вечер!\n\n" . $open['title']
        . "\n🗓 " . bot_date((string)$open['date'])
        . ($open['location'] ? "\n📍 " . $open['location'] : '')
        . "\n\nЗаписаться можно прямо в боте: «📅 Запись на игру».";
}

page_head('Админка — Telegram-рассылка', '');
echo '<p><a href="/admin/">← Админка</a></p><h1>Telegram-рассылка</h1>';

if (!$botReady) {
    echo '<div class="card card-accent"><p style="margin:0;">⚠ Бот ещё не настроен: задайте <code>bot_token</code> и <code>bot_secret</code> '
        . 'в <code>config.php</code> на сервере и поставьте вебхук через <code>/setup_webhook.php?key=…&amp;go=1</code>.</p></div>';
}

echo '<div class="card"><p style="margin-top:0;color:var(--tx2);">Сообщение получат все игроки, привязавшие Telegram к боту: '
    . '<b>' . $recipients . '</b>. Поддерживается HTML-разметка Telegram (&lt;b&gt;, &lt;i&gt;, &lt;a href&gt;).</p>';
echo '<form method="post" action="/admin/notify.php">' . csrf_field();
echo '<div class="field"><textarea name="text" id="bc-text" rows="8" placeholder="Текст анонса или напоминания...">' . esc($prefill) . '</textarea></div>';
echo '<button class="btn" type="submit" onclick="return confirm(\'Отправить сообщение всем привязанным участникам?\');"'
    . ($recipients ? '' : ' disabled') . '>Разослать всем (' . $recipients . ')</button>';
if ($open) {
    echo ' <span style="color:var(--tx3);font-size:13px;margin-left:8px;">Подставлен анонс ближайшего вечера — отредактируйте при необходимости.</span>';
}
echo '</form></div>';
page_foot();
