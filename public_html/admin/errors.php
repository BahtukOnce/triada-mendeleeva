<?php
require dirname(__DIR__, 2) . '/inc/bootstrap.php';
$u = require_role('admin');

$logFile = ROOT . '/storage/error.log';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (($_POST['form'] ?? '') === 'clear' && is_file($logFile)) {
        @file_put_contents($logFile, '');
        setting_set('errors_checkpoint', ''); // лог пуст — чекпоинт не нужен
        flash_set('ok', 'Лог очищен');
    }
    // Чекпоинт: всё, что в логе на текущий момент, помечается «разобрано».
    // Новые ошибки после чекпоинта снова попадут в раздел «Новые».
    if (($_POST['form'] ?? '') === 'checkpoint') {
        setting_set('errors_checkpoint', date('Y-m-d H:i:s'));
        log_action((int)$u['id'], 'errors_checkpoint');
        flash_set('ok', 'Отмечено: всё до этого момента — разобрано');
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

// Разбить на записи (разделитель — пустая строка), свежие сверху; поделить чекпоинтом
$entries = array_values(array_filter(array_map('trim', preg_split('/\n\s*\n/', $tail))));
$entries = array_reverse($entries);
$checkpoint = (string)setting('errors_checkpoint', '');
$fresh = [];
$resolved = [];
foreach (array_slice($entries, 0, 200) as $e) {
    // метка времени записи: «[YYYY-mm-dd HH:ii:ss] …»
    $ts = preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]/', $e, $m) ? $m[1] : '';
    if ($checkpoint !== '' && $ts !== '' && $ts <= $checkpoint) {
        $resolved[] = $e;
    } else {
        $fresh[] = $e;
    }
}

$renderEntry = function (string $e, bool $dim = false): string {
    $head = trim((string)strtok($e, "\n"));
    $rest = trim((string)substr($e, strlen($head)));
    return '<details style="background:var(--sf2);border-radius:9px;padding:10px 12px;' . ($dim ? 'opacity:.55;' : '') . '">'
        . '<summary style="cursor:pointer;font-size:13px;color:var(--tx);word-break:break-word;">'
        . ($dim ? '✓ ' : '') . esc($head) . '</summary>'
        . ($rest !== '' ? '<pre style="white-space:pre-wrap;font-size:12px;color:var(--tx2);margin:8px 0 0;overflow-x:auto;">' . esc($rest) . '</pre>' : '')
        . '</details>';
};

page_head('Админка — ошибки', '');
echo '<p><a href="/admin/">← Админка</a></p><h1>Лог ошибок</h1>';

echo '<div class="card"><p style="margin-top:0;color:var(--tx2);">Непойманные исключения и фатальные ошибки. Файл: <code>storage/error.log</code> (вне публичного доступа). '
    . 'Кнопка «✓ Разобрано» ставит чекпоинт: всё текущее уходит вниз в «разобранные», а новые ошибки снова появятся сверху.</p>';

if ($entries) {
    echo '<div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px;">';
    if ($fresh) {
        echo '<form method="post" action="/admin/errors.php">' . csrf_field()
            . '<input type="hidden" name="form" value="checkpoint">'
            . '<button class="btn" type="submit">✓ Разобрано — отметить чекпоинтом</button></form>';
    }
    echo '<form method="post" action="/admin/errors.php" onsubmit="return confirm(\'Очистить лог ошибок целиком?\');">' . csrf_field()
        . '<input type="hidden" name="form" value="clear"><button class="btn btn-ghost" type="submit" style="color:var(--ac);">Очистить лог</button></form>';
    echo '</div>';

    if ($fresh) {
        echo '<h2 style="margin:0 0 10px;font-size:15px;">Новые (' . count($fresh) . ')</h2>';
        echo '<div style="display:flex;flex-direction:column;gap:10px;">';
        foreach ($fresh as $e) {
            echo $renderEntry($e);
        }
        echo '</div>';
    } else {
        echo '<p style="color:var(--ok);margin:0;">Новых ошибок нет — всё разобрано ✓'
            . ($checkpoint !== '' ? ' <span style="color:var(--tx3);font-size:12.5px;">(чекпоинт: ' . esc($checkpoint) . ')</span>' : '') . '</p>';
    }

    if ($resolved) {
        echo '<details style="margin-top:16px;"><summary style="cursor:pointer;color:var(--tx2);font-size:13.5px;">Разобранные (' . count($resolved) . ')'
            . ($checkpoint !== '' ? ' · чекпоинт ' . esc($checkpoint) : '') . '</summary>';
        echo '<div style="display:flex;flex-direction:column;gap:10px;margin-top:10px;">';
        foreach ($resolved as $e) {
            echo $renderEntry($e, true);
        }
        echo '</div></details>';
    }
} else {
    echo '<p style="color:var(--tx3);margin:0;">Ошибок пока нет — отлично. 🎉</p>';
}
echo '</div>';
page_foot();
