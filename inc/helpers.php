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

// Диапазоны эмодзи/пиктограмм/модификаторов (для очистки ников и «висюлек»)
const EMOJI_RANGES = '\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}\x{2190}-\x{21FF}'
    . '\x{FE00}-\x{FE0F}\x{200D}\x{20E3}\x{2122}\x{2139}\x{24C2}\x{3030}\x{303D}\x{3297}\x{3299}';

// Ник без эмодзи (игровая идентичность всегда чистая, напр. «НЕ_ЛИС»)
function nickname_clean(string $s): string
{
    $s = (string)preg_replace('/[' . EMOJI_RANGES . ']/u', '', $s);
    return trim((string)preg_replace('/\s+/u', ' ', $s));
}

// Извлечь эмодзи из строки (для авто-«висюльки» при вводе ника со смайлом)
function flair_clean(string $s): string
{
    preg_match_all('/[' . EMOJI_RANGES . ']/u', $s, $m);
    $e = implode('', $m[0] ?? []);
    return mb_substr($e, 0, 16);
}

// Имя игрока для публичного показа: чистый ник + опциональная эмодзи-«висюлька»
function player_label(?array $p): string
{
    $n = esc($p['nickname'] ?? '');
    $f = trim((string)($p['flair'] ?? ''));
    return $f !== '' ? $n . ' <span class="flair">' . esc($f) . '</span>' : $n;
}

// Цветная точка роли (мирный/шериф/мафия/дон) — единый код цвета по сайту
// Палитра ролей — красно-чёрная: красная команда (мирный/шериф) красными
// оттенками, чёрная (мафия/дон) — тёмными.
function role_color(string $role): string
{
    return ['civ' => '#e8332a', 'sheriff' => '#f4938b', 'maf' => '#3f3f4a', 'don' => '#73737e'][$role] ?? '#888';
}

function role_dot(string $role): string
{
    return '<span class="hist-dot" style="background:' . role_color($role) . ';"></span>';
}

// Медаль за место (1–3) или само место
function rank_medal(int $pos): string
{
    return $pos === 1 ? '🥇' : ($pos === 2 ? '🥈' : ($pos === 3 ? '🥉' : (string)$pos));
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

// Игрок, привязанный к текущему пользователю (с автопривязкой по совпадающему нику)
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
            if (!$p) {
                $p = ensure_player_link($u);
            }
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
            . 'px;border-radius:50%;object-fit:cover;flex:none;vertical-align:middle;' . $style . '">';
    }
    return '<span class="avatar-circle" style="width:' . $size . 'px;height:' . $size . 'px;font-size:' . $fs . 'px;'
        . 'vertical-align:middle;' . $style . '">' . esc($letter) . '</span>';
}

function player_id_by_nick(string $nick): ?int
{
    $nick = trim($nick);
    if ($nick === '') {
        return null;
    }
    $st = db()->prepare('SELECT id FROM players WHERE LOWER(nickname) = LOWER(?) LIMIT 1');
    $st->execute([$nick]);
    $id = $st->fetchColumn();
    return $id !== false ? (int)$id : null;
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

// Сохранение загруженной картинки. С GD — пережатие в JPEG до $maxSide px;
// без GD — сохраняем оригинал как есть. Возвращает web-путь или строку-ошибку.
function save_image_upload(array $file, string $subdir, string $name, int $maxSide = 512)
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return 'Файл не загрузился (код ' . ($file['error'] ?? '?') . ')';
    }
    if (($file['size'] ?? 0) > 15 * 1024 * 1024) {
        return 'Файл больше 15 МБ';
    }
    $info = @getimagesize($file['tmp_name']);
    if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
        return 'Нужна картинка JPG, PNG или WebP';
    }
    $dir = ROOT . '/public_html/uploads/' . $subdir;
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
        return 'Не удалось создать папку загрузок (' . $subdir . ')';
    }
    $extByType = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];

    // Резерв: сохранить оригинал (если GD недоступен или обработка не удалась)
    $saveRaw = function () use ($file, $dir, $name, $subdir, $extByType, $info) {
        $ext = $extByType[$info[2]];
        $path = $dir . '/' . $name . '.' . $ext;
        if (is_uploaded_file($file['tmp_name'])) {
            if (!@move_uploaded_file($file['tmp_name'], $path) && !@copy($file['tmp_name'], $path)) {
                return 'Не удалось сохранить файл';
            }
        } elseif (!@copy($file['tmp_name'], $path)) {
            return 'Не удалось сохранить файл';
        }
        return '/uploads/' . $subdir . '/' . $name . '.' . $ext;
    };

    if (!function_exists('imagecreatetruecolor')) {
        return $saveRaw();
    }
    $img = match ($info[2]) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($file['tmp_name']),
        IMAGETYPE_PNG => @imagecreatefrompng($file['tmp_name']),
        IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($file['tmp_name']) : false,
        default => false,
    };
    if (!$img) {
        return $saveRaw();
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
    $path = $dir . '/' . $name . '.jpg';
    $ok = @imagejpeg($dst, $path, 85);
    imagedestroy($dst);
    if (!$ok) {
        return $saveRaw();
    }
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
