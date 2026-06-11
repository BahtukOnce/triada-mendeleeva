CREATE TABLE IF NOT EXISTS suggestions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    nickname VARCHAR(60) NULL,
    body TEXT NOT NULL,
    status ENUM('new','planned','done','declined') NOT NULL DEFAULT 'new',
    admin_note VARCHAR(300) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_sugg_status (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
