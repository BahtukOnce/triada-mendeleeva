<?php
// ВРЕМЕННЫЙ read-only: структура застейдженных данных. Удаляется после.
declare(strict_types=1);
require dirname(__DIR__) . '/inc/bootstrap.php';
header('Content-Type: text/plain; charset=utf-8');
if (!hash_equals('lgm_5kQ2x9aZ', (string)($_GET['t'] ?? ''))) {
    http_response_code(403);
    exit('forbidden');
}
$dir = ROOT . '/storage/legacy';
foreach (['855', '893', '1355', '3557', 'letkubok'] as $f) {
    $file = "$dir/games_$f.json";
    if (!is_file($file)) {
        echo "$f: НЕТ ФАЙЛА\n";
        continue;
    }
    $data = json_decode((string)file_get_contents($file), true);
    echo "$f: игр=" . (is_array($data) ? count($data) : '?');
    if (is_array($data) && $data) {
        $g0 = $data[0];
        echo " keys=" . implode(',', array_keys($g0)) . " gno=" . ($g0['gno'] ?? '-') . " date=" . ($g0['date'] ?? '-') . " players=" . count($g0['players'] ?? []);
    }
    echo "\n";
}
$file = "$dir/games_855.json";
if (is_file($file)) {
    $data = json_decode((string)file_get_contents($file), true);
    usort($data, fn($a, $b) => ((int)($a['gno'] ?? 0)) <=> ((int)($b['gno'] ?? 0)));
    echo "\n855 (по gno):\n";
    foreach ($data as $g) {
        echo "  gno=" . ($g['gno'] ?? '-') . " date=" . ($g['date'] ?? '-') . " time=" . ($g['time'] ?? '-') . " win=" . ($g['winner'] ?? '-') . " p1=" . ($g['players'][0]['name'] ?? '-') . " (мест " . count($g['players'] ?? []) . ")\n";
    }
}
