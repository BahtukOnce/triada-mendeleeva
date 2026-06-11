<?php
require dirname(__DIR__) . '/inc/bootstrap.php';

$text = '';
if (db_ready()) {
    try {
        $st = db()->prepare('SELECT v FROM settings WHERE k = ?');
        $st->execute(['rules_text']);
        $text = (string)($st->fetchColumn() ?: '');
    } catch (Throwable $e) {
    }
}

page_head('Правила игры', 'rules');
echo '<h1>Правила игры</h1>';

if (trim($text) !== '') {
    echo '<div class="card" style="line-height:1.75;">' . nl2br(esc($text)) . '</div>';
} else {
    empty_state('Правила скоро появятся', 'Текст правил клуба добавляется администратором и будет опубликован на этой странице.');
}
page_foot();
