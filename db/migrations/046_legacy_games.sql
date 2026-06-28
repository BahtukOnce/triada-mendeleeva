-- Исторические поигровые данные (с mafiauniverse) для расчёта ELO.
-- Хранятся ОТДЕЛЬНО от games/game_seats: участвуют только в elo_recompute(),
-- но НЕ в рейтингах, протоколах, рекордах и профилях (основной рейтинг — актуальный).
CREATE TABLE IF NOT EXISTS legacy_games (
    id INT AUTO_INCREMENT PRIMARY KEY,
    season VARCHAR(24) NOT NULL,
    gdate DATE NOT NULL,
    seq INT NOT NULL DEFAULT 0,
    winner ENUM('red','black') NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS legacy_game_seats (
    game_id INT NOT NULL,
    player_id INT NOT NULL,
    role ENUM('civ','maf','sheriff','don') NOT NULL DEFAULT 'civ',
    PRIMARY KEY (game_id, player_id),
    KEY idx_lgs_game (game_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
