<?php
// Поставить/сменить/снять реакцию на новость. Только для вошедших. Возвращает JSON.
require dirname(__DIR__) . '/inc/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$u = current_user();
if (!$u) {
    http_response_code(403);
    echo json_encode(['error' => 'auth']);
    exit;
}
csrf_check(); // 403 + текст, если токен не сошёлся

$newsId = (int)($_POST['news_id'] ?? 0);
$emoji = (string)($_POST['emoji'] ?? '');
if ($newsId <= 0 || !in_array($emoji, news_react_emojis(), true)) {
    http_response_code(400);
    echo json_encode(['error' => 'bad']);
    exit;
}

$ok = db()->prepare('SELECT 1 FROM news WHERE id = ? AND published_at IS NOT NULL');
$ok->execute([$newsId]);
if (!$ok->fetchColumn()) {
    http_response_code(404);
    echo json_encode(['error' => 'not_found']);
    exit;
}

$uid = (int)$u['id'];
$st = db()->prepare('SELECT emoji FROM news_reactions WHERE news_id = ? AND user_id = ?');
$st->execute([$newsId, $uid]);
$cur = $st->fetchColumn() ?: null;

if ($cur === $emoji) {
    // повторный тап по своей реакции — снять
    db()->prepare('DELETE FROM news_reactions WHERE news_id = ? AND user_id = ?')->execute([$newsId, $uid]);
} else {
    db()->prepare('INSERT INTO news_reactions (news_id, user_id, emoji) VALUES (?,?,?)
        ON DUPLICATE KEY UPDATE emoji = VALUES(emoji), created_at = CURRENT_TIMESTAMP')
        ->execute([$newsId, $uid, $emoji]);
}

[$counts, $mine] = news_reaction_data($newsId, $uid);
echo json_encode(['counts' => $counts, 'mine' => $mine], JSON_UNESCAPED_UNICODE);
