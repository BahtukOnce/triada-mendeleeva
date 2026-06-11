<?php
declare(strict_types=1);

// Привязать аккаунт к игроку с тем же ником (если игрок свободен). Возвращает игрока или null.
function ensure_player_link(array $user): ?array
{
    if (!db_ready()) {
        return null;
    }
    $st = db()->prepare('SELECT * FROM players WHERE user_id = ?');
    $st->execute([(int)$user['id']]);
    $p = $st->fetch();
    if ($p) {
        return $p;
    }
    $st = db()->prepare('SELECT * FROM players WHERE user_id IS NULL AND LOWER(nickname) = LOWER(?) LIMIT 1');
    $st->execute([$user['nickname']]);
    $p = $st->fetch();
    if ($p) {
        db()->prepare('UPDATE players SET user_id = ? WHERE id = ? AND user_id IS NULL')
            ->execute([(int)$user['id'], (int)$p['id']]);
        $p['user_id'] = (int)$user['id'];
        log_action((int)$user['id'], 'player_autolink', ['player_id' => (int)$p['id']]);
        return $p;
    }
    return null;
}

function current_user(): ?array
{
    static $user = false;
    if ($user === false) {
        $user = null;
        if (!empty($_SESSION['uid']) && db_ready()) {
            try {
                $st = db()->prepare('SELECT * FROM users WHERE id = ?');
                $st->execute([(int)$_SESSION['uid']]);
                $user = $st->fetch() ?: null;
            } catch (Throwable $e) {
                $user = null;
            }
        }
    }
    return $user;
}

function role_level(?string $role): int
{
    return ['player' => 1, 'judge' => 2, 'admin' => 3, 'owner' => 4][$role] ?? 0;
}

function role_label(?string $role): string
{
    return ['player' => 'игрок', 'judge' => 'судья', 'admin' => 'админ', 'owner' => 'глава клуба'][$role] ?? '';
}

function require_login(): array
{
    $u = current_user();
    if (!$u) {
        redirect('/login.php');
    }
    return $u;
}

function require_role(string $minRole): array
{
    $u = require_login();
    if (role_level($u['role']) < role_level($minRole)) {
        http_response_code(403);
        exit('Недостаточно прав.');
    }
    return $u;
}

// Капабилити: судья и фотограф — флаги поверх роли; админ/глава имеют всё
function user_can_judge(?array $u): bool
{
    return $u && (role_level($u['role']) >= 3 || !empty($u['is_judge']));
}

function user_can_photo(?array $u): bool
{
    return $u && (role_level($u['role']) >= 3 || !empty($u['is_photographer']));
}

function require_judge(): array
{
    $u = require_login();
    if (!user_can_judge($u)) {
        http_response_code(403);
        exit('Доступно судьям и администраторам.');
    }
    return $u;
}

function require_photo(): array
{
    $u = require_login();
    if (!user_can_photo($u)) {
        http_response_code(403);
        exit('Доступно фотографам и администраторам.');
    }
    return $u;
}

// Подписи ролей пользователя: для админов/главы — одна; для игрока — флаги
function user_role_badges(array $u): array
{
    if ($u['role'] === 'owner') {
        return ['глава клуба'];
    }
    if ($u['role'] === 'admin') {
        return ['админ'];
    }
    $b = [];
    if (!empty($u['is_judge'])) {
        $b[] = 'судья';
    }
    if (!empty($u['is_photographer'])) {
        $b[] = 'фотограф';
    }
    $b[] = 'игрок';
    return $b;
}

function valid_nickname(string $nick): ?string
{
    $nick = trim((string)preg_replace('/\s+/u', ' ', $nick));
    $len = mb_strlen($nick);
    if ($len < 2 || $len > 30) {
        return null;
    }
    if (preg_match('/[\x00-\x1F<>"\'\\\\\/]/u', $nick)) {
        return null;
    }
    return $nick;
}

function too_many_attempts(string $ip): bool
{
    $st = db()->prepare(
        'SELECT COUNT(*) FROM login_attempts
         WHERE ip = ? AND success = 0 AND created_at > NOW() - INTERVAL 10 MINUTE'
    );
    $st->execute([$ip]);
    return (int)$st->fetchColumn() >= 8;
}

function note_attempt(string $ip, string $nick, bool $ok): void
{
    try {
        db()->prepare('INSERT INTO login_attempts (ip, nickname, success) VALUES (?,?,?)')
            ->execute([$ip, mb_substr($nick, 0, 60), $ok ? 1 : 0]);
    } catch (Throwable $e) {
    }
}

// Возвращает массив пользователя или строку с ошибкой
function auth_register(string $nick, string $pass1, string $pass2)
{
    $nick = valid_nickname($nick);
    if ($nick === null) {
        return 'Ник: от 2 до 30 символов, без кавычек, слэшей и угловых скобок';
    }
    if (mb_strlen($pass1) < 6) {
        return 'Пароль — минимум 6 символов';
    }
    if ($pass1 !== $pass2) {
        return 'Пароли не совпадают';
    }
    $ip = client_ip();
    if (too_many_attempts($ip)) {
        return 'Слишком много попыток — подождите 10 минут';
    }

    $st = db()->prepare('SELECT id FROM users WHERE nickname = ?');
    $st->execute([$nick]);
    if ($st->fetch()) {
        note_attempt($ip, $nick, false);
        return 'Этот ник уже занят';
    }

    // Главой клуба становится ник из config['owner_nickname'] (пока главы нет);
    // если ник не задан в конфиге — главой становится первый зарегистрированный.
    $role = 'player';
    $hasOwner = (bool)db()->query("SELECT 1 FROM users WHERE role = 'owner' LIMIT 1")->fetchColumn();
    if (!$hasOwner) {
        $ownerNick = (string)(cfg('owner_nickname') ?? '');
        if ($ownerNick !== '') {
            if (mb_strtolower($nick) === mb_strtolower($ownerNick)) {
                $role = 'owner';
            }
        } elseif ((int)db()->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0) {
            $role = 'owner';
        }
    }

    $st = db()->prepare('INSERT INTO users (nickname, password_hash, role) VALUES (?,?,?)');
    $st->execute([$nick, password_hash($pass1, PASSWORD_DEFAULT), $role]);
    $id = (int)db()->lastInsertId();

    note_attempt($ip, $nick, true);
    log_action($id, 'register', ['role' => $role]);

    // Профиль игрока: тот же ник свободен → привязываем сразу;
    // ника нет в базе → создаём нового игрока; ник уже занят аккаунтом → без привязки
    $linked = 'new';
    try {
        $user = ['id' => $id, 'nickname' => $nick];
        $pl = ensure_player_link($user);
        if ($pl) {
            $linked = 'linked';
        } else {
            $st = db()->prepare('SELECT id FROM players WHERE LOWER(nickname) = LOWER(?)');
            $st->execute([$nick]);
            if (!$st->fetch()) {
                db()->prepare('INSERT INTO players (nickname, user_id) VALUES (?,?)')
                    ->execute([$nick, $id]);
            } else {
                $linked = 'taken';
            }
        }
    } catch (Throwable $e) {
    }

    session_regenerate_id(true);
    $_SESSION['uid'] = $id;
    return ['id' => $id, 'nickname' => $nick, 'role' => $role, 'linked' => $linked];
}

// Возвращает массив пользователя или строку с ошибкой
function auth_login(string $nick, string $pass)
{
    $nick = trim($nick);
    $ip = client_ip();
    if ($nick === '' || $pass === '') {
        return 'Заполните оба поля';
    }
    if (too_many_attempts($ip)) {
        return 'Слишком много попыток — подождите 10 минут';
    }

    $st = db()->prepare('SELECT * FROM users WHERE nickname = ?');
    $st->execute([$nick]);
    $u = $st->fetch();

    if (!$u || !password_verify($pass, $u['password_hash'])) {
        note_attempt($ip, $nick, false);
        return 'Неверный ник или пароль';
    }

    note_attempt($ip, $nick, true);
    db()->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute([$u['id']]);
    log_action((int)$u['id'], 'login');

    session_regenerate_id(true);
    $_SESSION['uid'] = (int)$u['id'];
    return $u;
}

function auth_logout(): void
{
    $u = current_user();
    if ($u) {
        log_action((int)$u['id'], 'logout');
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}
