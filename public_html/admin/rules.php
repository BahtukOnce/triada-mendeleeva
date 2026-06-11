<?php
require dirname(__DIR__, 2) . '/inc/bootstrap.php';
$u = require_role('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    setting_set('rules_text', trim((string)($_POST['rules_text'] ?? '')));
    setting_set('about_text', trim((string)($_POST['about_text'] ?? '')));
    setting_set('bot_username', ltrim(trim((string)($_POST['bot_username'] ?? '')), '@'));
    log_action((int)$u['id'], 'rules_update');
    flash_set('ok', 'Сохранено');
    redirect('/admin/rules.php');
}

page_head('Админка — правила и тексты', '');
echo '<p><a href="/admin/">← Админка</a></p><h1>Правила и тексты</h1>';
echo '<form method="post" action="/admin/rules.php">' . csrf_field();
echo '<div class="card"><h2 style="margin-top:0;">Текст страницы «Правила»</h2>';
echo '<div class="field"><textarea name="rules_text" rows="16" placeholder="Правила клуба...">'
    . esc(setting('rules_text')) . '</textarea></div></div>';
echo '<div class="card"><h2 style="margin-top:0;">Блок «О клубе» на главной</h2>';
echo '<div class="field"><textarea name="about_text" rows="5" placeholder="Пара абзацев о клубе...">'
    . esc(setting('about_text')) . '</textarea></div></div>';
echo '<div class="card"><h2 style="margin-top:0;">Telegram-бот</h2>';
echo '<div class="field"><label>Username бота (без @) — для кнопки привязки в кабинете</label>'
    . '<input type="text" name="bot_username" value="' . esc(setting('bot_username')) . '" placeholder="triada_bot"></div></div>';
echo '<button class="btn" type="submit">Сохранить</button></form>';
page_foot();
