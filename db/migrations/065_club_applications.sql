-- Заявки на вступление в клуб (замена Google-формы «Регистрация нового жителя клуба»).
-- Приходят руководителю (owner): уведомление на сайте (колокольчик) + в Telegram-бот.
CREATE TABLE IF NOT EXISTS club_applications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    full_name        VARCHAR(150) NOT NULL,      -- ФИО
    nickname         VARCHAR(60)  NOT NULL,      -- игровой ник
    applicant_status VARCHAR(40)  NOT NULL,      -- студент/выпускник/сотрудник/гость
    faculty          VARCHAR(40)  NOT NULL,      -- факультет (или «Другое»)
    study_group      VARCHAR(50)  NULL,          -- учебная группа (при наличии)
    experience       VARCHAR(255) NOT NULL,      -- опыт игры
    source           VARCHAR(255) NOT NULL,      -- как узнали про клуб
    source_other     VARCHAR(255) NULL,          -- текст для варианта «Другое»
    tg_username      VARCHAR(100) NOT NULL,      -- ник в Telegram
    birth_date       DATE NULL,                  -- дата рождения (для поздравлений)
    state            ENUM('new','approved','rejected') NOT NULL DEFAULT 'new',
    admin_note       VARCHAR(500) NULL,          -- заметка руководителя
    player_id        INT NULL,                   -- если из заявки создан игрок
    ip               VARCHAR(45) NULL,           -- для анти-спама (лимит по IP)
    processed_by     INT NULL,
    processed_at     TIMESTAMP NULL,
    created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_app_state (state, id),
    KEY idx_app_ip (ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
