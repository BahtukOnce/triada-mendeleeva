<?php
// ВРЕМЕННЫЙ диагностический эндпоинт (только чтение). Удаляется после использования.
require dirname(__DIR__) . '/inc/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
if (($_GET['t'] ?? '') !== 'lgm_5kQ2x9aZ') { http_response_code(403); echo '{"e":"forbidden"}'; exit; }

$out = [];
$rows = db()->query("SELECT id, title, status, tables_count, rounds FROM tournaments ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    $tid = (int)$r['id'];
    $gc = db()->prepare("SELECT COUNT(*) total, SUM(status='finished') fin, SUM(status='draft') draft FROM games WHERE tournament_id=?");
    $gc->execute([$tid]);
    $g = $gc->fetch();
    $pc = db()->prepare("SELECT COUNT(*) FROM tournament_participants WHERE tournament_id=? AND state='confirmed'");
    $pc->execute([$tid]);
    $out[] = [
        'id' => $tid, 'title' => $r['title'], 'status' => $r['status'],
        'tables' => (int)$r['tables_count'], 'rounds' => (int)$r['rounds'],
        'games_total' => (int)$g['total'], 'games_finished' => (int)$g['fin'], 'games_draft' => (int)$g['draft'],
        'confirmed' => (int)$pc->fetchColumn(),
    ];
}
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
