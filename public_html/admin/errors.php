<?php
require dirname(__DIR__, 2) . '/inc/bootstrap.php';
$u = require_role('admin');

$logFile = ROOT . '/storage/error.log';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (($_POST['form'] ?? '') === 'clear' && is_file($logFile)) {
        @file_put_contents($logFile, '');
        flash_set('ok', 'Лог очищен');
    }
    redirect('/admin/errors.php');
}

// Последние ~64 КБ лога (свежие записи — внизу файла)
$tail = '';
if (is_file($logFile)) {
    $size = filesize($logFile);
    $fp = fopen($logFile, 'rb');
    if ($fp) {
        $max = 64 * 1024;
        if ($size > $max) {
            fseek($fp, -$max, SEEK_END);
            fgets($fp); // пропустить обрезанную строку
        }
        $tail = (string)stream_get_contents($fp);
        fclose($fp);
    }
}

// Разбить на записи (разделитель — пустая строка) и показать свежие сверху
$entries = array_values(array_filter(array_map('trim', preg_split('/\n\s*\n/', $tail))));
$entries = array_reverse($entries);

page_head('Админка — ошибки', '');
echo '<p><a href="/admin/">← Админка</a></p><h1>Лог ошибок</h1>';

echo '<div class="card"><p style="margin-top:0;color:var(--tx2);">Непойманные исключения и фатальные ошибки. Файл: <code>storage/error.log</code> (вне публичного доступа). Показаны последние записи.</p>';
if ($entries) {
    echo '<form method="post" action="/admin/errors.php" style="margin-bottom:14px;" onsubmit="return confirm(\'Очистить лог ошибок?\');">' . csrf_field()
        . '<input type="hidden" name="form" value="clear"><button class="btn btn-ghost" type="submit" style="color:var(--ac);">Очистить лог</button></form>';
    echo '<div style="display:flex;flex-direction:column;gap:10px;">';
    foreach (array_slice($entries, 0, 200) as $e) {
        $head = trim((string)strtok($e, "\n"));
        $rest = trim((string)substr($e, strlen($head)));
        echo '<details style="background:var(--sf2);border-radius:9px;padding:10px 12px;">'
            . '<summary style="cursor:pointer;font-size:13px;color:var(--tx);word-break:break-word;">' . esc($head) . '</summary>'
            . ($rest !== '' ? '<pre style="white-space:pre-wrap;font-size:12px;color:var(--tx2);margin:8px 0 0;overflow-x:auto;">' . esc($rest) . '</pre>' : '')
            . '</details>';
    }
    echo '</div>';
} else {
    echo '<p style="color:var(--tx3);margin:0;">Ошибок пока нет — отлично. 🎉</p>';
}
echo '</div>';
page_foot();
