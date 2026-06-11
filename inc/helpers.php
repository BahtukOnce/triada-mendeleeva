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
