-- Опрос «Когда играем?»: варианты-даты на неделю, мультивыбор игроков.
-- Из победившего дня руководство создаёт игровой вечер (обычный flow записи/анонса).
CREATE TABLE IF NOT EXISTS day_polls (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(200) NOT NULL DEFAULT 'Когда играем?',
    status ENUM('open','closed') NOT NULL DEFAULT 'open',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    closed_at TIMESTAMP NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS day_poll_options (
    id INT AUTO_INCREMENT PRIMARY KEY,
    poll_id INT NOT NULL,
    date DATE NOT NULL,
    CONSTRAINT fk_dpo_poll FOREIGN KEY (poll_id) REFERENCES day_polls(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS day_poll_votes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    option_id INT NOT NULL,
    player_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_opt_player (option_id, player_id),
    CONSTRAINT fk_dpv_opt FOREIGN KEY (option_id) REFERENCES day_poll_options(id) ON DELETE CASCADE,
    CONSTRAINT fk_dpv_player FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
