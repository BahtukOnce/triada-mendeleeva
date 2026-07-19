-- «Запомнить меня»: постоянный вход без повторной авторизации.
-- В куке — случайный токен, в базе — только его SHA-256 (утечка базы не даёт вход).
-- Живёт долго (продлевается при использовании); «Выйти» удаляет строку.
CREATE TABLE IF NOT EXISTS remember_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token_hash CHAR(64) NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_used_at DATETIME NULL,
    ua VARCHAR(255) NULL,
    ip VARCHAR(45) NULL,
    KEY idx_rt_user (user_id),
    KEY idx_rt_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
