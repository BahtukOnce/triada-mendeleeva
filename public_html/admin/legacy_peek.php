<?php
require dirname(__DIR__, 2) . '/inc/bootstrap.php';
// Что лежит в снятых с mafiauniverse файлах (storage/legacy/*.json): какие поля есть у игры и
// у игрока. Нужно, чтобы понять, можно ли дособрать первоубиенного и лучший ход — импорт
// перенёс только ник/место/роль/итог, а ПУ и ЛХ на mafiauniverse в протоколе есть.
// Только админам; ничего не меняет — читает файлы и печатает структуру.
$u = require_role('admin');
page_head('Легаси-исходники', '');
echo '<h1>Что в снятых файлах mafiauniverse</h1>';

$dir = ROOT . '/storage/legacy';
$files = glob($dir . '/*.json') ?: [];
if (!$files) {
    empty_state('Файлов нет', 'Папка ' . esc($dir) . ' пуста — исходники не сохранились.');
    page_foot();
    exit;
}
foreach ($files as $f) {
    $raw = (string)@file_get_contents($f);
    $data = json_decode($raw, true);
    echo '<div class="card"><h2 style="margin-top:0;">' . esc(basename($f)) . ' <span style="color:var(--tx3);font-size:13px;font-weight:400;">· '
        . number_format(strlen($raw) / 1024, 0, '.', ' ') . ' КБ</span></h2>';
    if (!is_array($data)) {
        echo '<p style="color:var(--ac);">Не читается как JSON.</p></div>';
        continue;
    }
    // Верхний уровень: ключи (или первая запись списка)
    $top = array_slice(array_keys($data), 0, 12);
    echo '<p style="font-size:13px;color:var(--tx2);">Верхний уровень: <code>' . esc(implode(', ', array_map('strval', $top))) . '</code></p>';
    // Ищем первую «игру»: массив с ключом players
    $game = null;
    $walk = function ($node, int $depth = 0) use (&$walk, &$game) {
        if ($game !== null || $depth > 4 || !is_array($node)) {
            return;
        }
        if (isset($node['players']) && is_array($node['players'])) {
            $game = $node;
            return;
        }
        foreach ($node as $v) {
            $walk($v, $depth + 1);
        }
    };
    $walk($data);
    if ($game === null) {
        echo '<p style="color:var(--tx2);font-size:13px;">Игр с полем <code>players</code> не нашлось.</p></div>';
        continue;
    }
    $gameKeys = array_keys($game);
    $pl = $game['players'][0] ?? [];
    echo '<p style="font-size:13px;color:var(--tx2);">Поля игры: <code>' . esc(implode(', ', array_map('strval', $gameKeys))) . '</code><br>'
        . 'Поля игрока: <code>' . esc(implode(', ', array_map('strval', array_keys(is_array($pl) ? $pl : [])))) . '</code></p>';
    // Первая игра целиком — по ней видно, есть ли ПУ и ЛХ
    $sample = $game;
    $sample['players'] = array_slice($game['players'], 0, 10);
    echo '<pre style="background:var(--sf2);border:1px solid var(--bd);border-radius:8px;padding:12px;overflow:auto;max-height:420px;font-size:12px;">'
        . esc((string)json_encode($sample, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) . '</pre>';
    echo '</div>';
}
page_foot();
