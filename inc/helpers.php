<?php
declare(strict_types=1);

function cfg(string $key, $default = null)
{
    return $GLOBALS['cfg'][$key] ?? $default;
}

function esc(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . esc(csrf_token()) . '">';
}

function csrf_check(): void
{
    $sent = (string)($_POST['csrf'] ?? '');
    $have = (string)($_SESSION['csrf'] ?? '');
    if ($have === '' || !hash_equals($have, $sent)) {
        http_response_code(403);
        exit('Сессия устарела — вернитесь назад и обновите страницу.');
    }
}

function flash_set(string $type, string $msg): void
{
    $_SESSION['flash'][] = ['t' => $type, 'm' => $msg];
}

function flash_pull(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

function redirect(string $to): void
{
    header('Location: ' . $to);
    exit;
}

function client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '';
}

// Игрок, привязанный к текущему пользователю
function current_player(): ?array
{
    static $p = false;
    if ($p === false) {
        $p = null;
        $u = current_user();
        if ($u && db_ready()) {
            $st = db()->prepare('SELECT * FROM players WHERE user_id = ?');
            $st->execute([(int)$u['id']]);
            $p = $st->fetch() ?: null;
        }
    }
    return $p;
}

function avatar_html(?array $player, int $size = 30, string $style = ''): string
{
    $letter = mb_strtoupper(mb_substr($player['nickname'] ?? '?', 0, 1));
    $fs = (int)round($size * 0.4);
    if (!empty($player['avatar']) && is_file(ROOT . '/public_html' . $player['avatar'])) {
        return '<img src="' . esc($player['avatar']) . '" alt="" style="width:' . $size . 'px;height:' . $size
            . 'px;border-radius:50%;object-fit:cover;flex:none;' . $style . '">';
    }
    return '<span class="avatar-circle" style="width:' . $size . 'px;height:' . $size . 'px;font-size:' . $fs . 'px;'
        . $style . '">' . esc($letter) . '</span>';
}

function setting(string $key, string $default = ''): string
{
    try {
        $st = db()->prepare('SELECT v FROM settings WHERE k = ?');
        $st->execute([$key]);
        $v = $st->fetchColumn();
        return $v !== false && $v !== null ? (string)$v : $default;
    } catch (Throwable $e) {
        return $default;
    }
}

function setting_set(string $key, string $value): void
{
    db()->prepare('INSERT INTO settings (k, v) VALUES (?,?) ON DUPLICATE KEY UPDATE v = VALUES(v)')
        ->execute([$key, $value]);
}

// Сохранение загруженной картинки: пережатие в JPEG, максимум $maxSide px.
// Возвращает web-путь или строку-ошибку.
function save_image_upload(array $file, string $subdir, string $name, int $maxSide = 512)
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return 'Файл не загрузился';
    }
    if ($file['size'] > 10 * 1024 * 1024) {
        return 'Файл больше 10 МБ';
    }
    $info = @getimagesize($file['tmp_name']);
    if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
        return 'Нужна картинка JPG, PNG или WebP';
    }
    $img = match ($info[2]) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($file['tmp_name']),
        IMAGETYPE_PNG => @imagecreatefrompng($file['tmp_name']),
        IMAGETYPE_WEBP => @imagecreatefromwebp($file['tmp_name']),
    };
    if (!$img) {
        return 'Не удалось прочитать картинку';
    }
    $w = imagesx($img);
    $h = imagesy($img);
    $scale = min(1, $maxSide / max($w, $h));
    $nw = max(1, (int)round($w * $scale));
    $nh = max(1, (int)round($h * $scale));
    $dst = imagecreatetruecolor($nw, $nh);
    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
    imagecopyresampled($dst, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
    imagedestroy($img);

    $dir = ROOT . '/public_html/uploads/' . $subdir;
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        imagedestroy($dst);
        return 'Не удалось создать папку загрузок';
    }
    $path = $dir . '/' . $name . '.jpg';
    imagejpeg($dst, $path, 85);
    imagedestroy($dst);
    return '/uploads/' . $subdir . '/' . $name . '.jpg';
}

function log_action(?int $userId, string $action, array $details = []): void
{
    try {
        db()->prepare('INSERT INTO logs (user_id, action, details, ip) VALUES (?,?,?,?)')
            ->execute([
                $userId,
                $action,
                json_encode($details, JSON_UNESCAPED_UNICODE),
                client_ip(),
            ]);
    } catch (Throwable $e) {
        // лог не должен ронять запрос
    }
}
