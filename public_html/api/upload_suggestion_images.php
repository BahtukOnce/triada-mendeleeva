<?php
// Загрузка картинки для предложения (вставка скриншота Ctrl+V на /suggest.php).
// Только для вошедших пользователей, CSRF обязателен. Возвращает JSON {ok, url|error}.
require dirname(__DIR__, 2) . '/inc/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$u = current_user();
if (!$u) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Требуется вход']);
    exit;
}
csrf_check();

if (empty($_FILES['image']) || !is_array($_FILES['image'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Файл не получен']);
    exit;
}

$base = 's' . (int)$u['id'] . '_' . time() . '_' . random_int(1000, 9999);
$res = save_image_upload($_FILES['image'], 'suggestions', $base, 1600);

if (!is_string($res) || strncmp($res, '/uploads/', 9) !== 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => is_string($res) && $res !== '' ? $res : 'Не удалось сохранить изображение']);
    exit;
}

echo json_encode(['ok' => true, 'url' => $res], JSON_UNESCAPED_UNICODE);
