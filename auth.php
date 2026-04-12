<?php
/**
 * auth.php — Триада Менделеева
 * Авторизация администраторов
 * 
 * Загрузи этот файл в public_html рядом с index.html
 * Затем создай БД в Beget → MySQL и укажи данные ниже
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: https://triada-mendeleeva.ru');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Token');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200); exit;
}

// ╔══════════════════════════════════════════════╗
// ║  НАСТРОЙКИ — заполни после создания БД       ║
// ╚══════════════════════════════════════════════╝
define('DB_HOST', 'localhost');
define('DB_NAME', 'bahtukai_triada');  // имя БД из Beget → MySQL
define('DB_USER', 'bahtukai_triada');  // пользователь БД
define('DB_PASS', 'СЮДА_ПАРОЛЬ_БД');  // пароль БД

// ── Утилиты ───────────────────────────────────────────────────
function db() {
    static $pdo;
    if (!$pdo) {
        $pdo = new PDO(
            'mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4',
            DB_USER, DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
             PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
    }
    return $pdo;
}
function ok($data = [])  { echo json_encode(['status'=>'ok']  + $data); exit; }
function err($msg)       { echo json_encode(['status'=>'error','message'=>$msg]); exit; }

// ── Инициализация таблиц ──────────────────────────────────────
function initDB() {
    db()->exec("CREATE TABLE IF NOT EXISTS admins (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        username      VARCHAR(50) UNIQUE NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        nickname      VARCHAR(100) DEFAULT '',
        created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    db()->exec("CREATE TABLE IF NOT EXISTS sessions (
        token      CHAR(64) PRIMARY KEY,
        admin_id   INT NOT NULL,
        expires_at TIMESTAMP NOT NULL,
        FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

// ── Получить текущего администратора по токену ────────────────
function currentAdmin() {
    $token = $_SERVER['HTTP_X_TOKEN']
          ?? (json_decode(file_get_contents('php://input'), true)['token'] ?? '');
    if (!$token) return null;
    $st = db()->prepare("
        SELECT a.* FROM sessions s
        JOIN admins a ON a.id = s.admin_id
        WHERE s.token = ? AND s.expires_at > NOW()
    ");
    $st->execute([$token]);
    return $st->fetch() ?: null;
}

// ── Роутинг ───────────────────────────────────────────────────
$action = $_GET['action'] ?? '';
$body   = json_decode(file_get_contents('php://input'), true) ?? [];

try {
    initDB();

    switch ($action) {

        // Вход
        case 'login':
            $username = trim($body['username'] ?? '');
            $password = $body['password'] ?? '';
            if (!$username || !$password) err('Заполните все поля');

            $st = db()->prepare("SELECT * FROM admins WHERE username = ?");
            $st->execute([$username]);
            $admin = $st->fetch();

            if (!$admin || !password_verify($password, $admin['password_hash'])) {
                err('Неверный логин или пароль');
            }

            $token = bin2hex(random_bytes(32));
            db()->prepare("INSERT INTO sessions (token, admin_id, expires_at)
                           VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 30 DAY))")
               ->execute([$token, $admin['id']]);

            ok([
                'token'    => $token,
                'username' => $admin['username'],
                'nickname' => $admin['nickname'] ?: $admin['username'],
                'role'     => 'admin',
            ]);

        // Проверка токена
        case 'me':
            $admin = currentAdmin();
            if (!$admin) err('Не авторизован');
            ok([
                'username' => $admin['username'],
                'nickname' => $admin['nickname'] ?: $admin['username'],
                'role'     => 'admin',
                'id'       => $admin['id'],
            ]);

        // Выход
        case 'logout':
            $token = $_SERVER['HTTP_X_TOKEN'] ?? ($body['token'] ?? '');
            if ($token) db()->prepare("DELETE FROM sessions WHERE token=?")->execute([$token]);
            ok();

        // Создание первого/нового администратора (только через CLI или при пустой таблице)
        case 'createAdmin':
            $count = db()->query("SELECT COUNT(*) FROM admins")->fetchColumn();
            $secret = $body['secret'] ?? '';
            // Первый админ создаётся без секрета; последующие — только с токеном существующего
            if ($count > 0) {
                $admin = currentAdmin();
                if (!$admin) err('Нет доступа');
            }
            $username = trim($body['username'] ?? '');
            $password = $body['password'] ?? '';
            $nickname = trim($body['nickname'] ?? $username);
            if (strlen($username) < 3) err('Логин минимум 3 символа');
            if (strlen($password) < 6) err('Пароль минимум 6 символов');
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) err('Только латиница, цифры, _');
            try {
                db()->prepare("INSERT INTO admins (username, password_hash, nickname) VALUES (?,?,?)")
                   ->execute([$username, password_hash($password, PASSWORD_DEFAULT), $nickname]);
            } catch (PDOException $e) {
                err('Логин уже занят');
            }
            ok(['message' => 'Администратор создан!']);

        // Список администраторов
        case 'listAdmins':
            if (!currentAdmin()) err('Нет доступа');
            $rows = db()->query("SELECT id,username,nickname,created_at FROM admins ORDER BY id")
                        ->fetchAll();
            ok(['admins' => $rows]);

        // Удаление администратора
        case 'deleteAdmin':
            $me = currentAdmin();
            if (!$me) err('Нет доступа');
            $targetId = (int)($body['admin_id'] ?? 0);
            if ($targetId === (int)$me['id']) err('Нельзя удалить себя');
            db()->prepare("DELETE FROM admins WHERE id=?")->execute([$targetId]);
            ok();

        default:
            err('Неизвестное действие: '.$action);
    }

} catch (PDOException $e) {
    err('Ошибка БД: '.$e->getMessage());
} catch (Exception $e) {
    err('Ошибка: '.$e->getMessage());
}
